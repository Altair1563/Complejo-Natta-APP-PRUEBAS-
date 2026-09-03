<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// Usar el autoload de Composer
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ============================================
//  CONEXIÓN A LA BASE DE DATOS (SEGURA)
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/smtp.php'; // Incluir configuración SMTP
require_once __DIR__ . '/../config/app_audit.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en procesar_olvide.php: " . $conn->connect_error);
    $_SESSION['error_olvide'] = "Error interno del servidor. Intente más tarde.";
    header("Location: olvide_password.php");
    exit;
}

$email = trim($_POST['email'] ?? '');
$dni = trim($_POST['dni'] ?? '');
$csrf_token = $_POST['csrf_token'] ?? '';
$ip = obtener_ip_cliente();

// Validar token CSRF
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    $_SESSION['error_olvide'] = "Token de seguridad inválido. Recargue la página.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}

if (empty($email) || empty($dni)) {
    $_SESSION['error_olvide'] = "Por favor, complete todos los campos.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}

// Rate limiting anti-abuso para endpoint de tokens (email + IP).
$email_key = construir_clave_rate_limit_reset_email($email);
$ip_key = construir_clave_rate_limit_reset_ip($ip);

$bloqueo_email = obtener_bloqueo_activo_reset($conn, $email_key);
$bloqueo_ip = obtener_bloqueo_activo_reset($conn, $ip_key);
if ($bloqueo_email || $bloqueo_ip) {
    $locked_until = $bloqueo_email ?: $bloqueo_ip;
    $minutos = max(1, (int)ceil((strtotime($locked_until) - time()) / 60));
    app_audit_log($conn, 'reset_blocked', 'password_reset', null, [
        'motivo' => 'rate_limit',
        'bloqueado_hasta' => $locked_until,
    ], $dni !== '' ? $dni : null, $email);
    registrar_evento_seguridad_reset(
        "RESET_BLOCKED",
        "Solicitud de reset bloqueada por rate limiting",
        $email,
        $ip,
        "WARN"
    );
    $_SESSION['error_olvide'] = "Demasiadas solicitudes. Intente nuevamente en $minutos minutos.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}

// Se registra toda solicitud para prevenir spam de tokens aun con credenciales válidas.
registrar_solicitud_reset($conn, $email_key, $ip, "email", $email);
registrar_solicitud_reset($conn, $ip_key, $ip, "ip", $email);

// Validar formato de email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error_olvide'] = "El formato del email no es válido.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}

// Validar DNI (solo números, 6-9 dígitos)
if (!preg_match('/^\d{6,9}$/', $dni)) {
    $_SESSION['error_olvide'] = "El DNI debe contener solo números (6 a 9 dígitos).";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}

// --- Verificar que el email y DNI correspondan a una misma familia ---
$query_familias = "SELECT nro_familia FROM email_familia WHERE ? IN (
    mail_padre, mail_padre_trabajo, mail_madre, mail_madre_trabajo, mail_resp_afip
)";
$stmt = $conn->prepare($query_familias);
if (!$stmt) {
    error_log("Error prepare familias: " . $conn->error);
    $_SESSION['error_olvide'] = "Error interno del servidor.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}
$stmt->bind_param("s", $email);
$stmt->execute();
$familias_result = $stmt->get_result();
$familias_del_email = [];
while ($row = $familias_result->fetch_assoc()) {
    $familias_del_email[] = $row['nro_familia'];
}
$stmt->close();

if (empty($familias_del_email)) {
    $_SESSION['error_olvide'] = "El email no está registrado en ninguna familia.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}

// Verificar que el DNI pertenezca a algún alumno de esas familias
$encontrado = false;
foreach ($familias_del_email as $nro_familia) {
    $stmt2 = $conn->prepare("SELECT nro_legajo FROM legajos WHERE dni_alumno = ? AND nro_familia = ? LIMIT 1");
    if (!$stmt2) {
        error_log("Error prepare legajos: " . $conn->error);
        continue;
    }
    $stmt2->bind_param("ss", $dni, $nro_familia);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    if ($result2->num_rows > 0) {
        $encontrado = true;
        $stmt2->close();
        break;
    }
    $stmt2->close();
}

if (!$encontrado) {
    $_SESSION['error_olvide'] = "El DNI no corresponde a ninguna familia asociada a este email.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}

// Verificar que esta cuenta (email + DNI) tenga una contraseña registrada
$stmt = $conn->prepare("SELECT dni_alumno FROM usuarios WHERE dni_alumno = ? AND email = ?");
if (!$stmt) {
    error_log("Error prepare usuarios: " . $conn->error);
    $_SESSION['error_olvide'] = "Error interno del servidor.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}
$stmt->bind_param("ss", $dni, $email);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $_SESSION['error_olvide'] = "Este email no tiene una contraseña registrada para ese DNI. Por favor, use 'Primera vez'.";
    $stmt->close();
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}
$stmt->close();

// Permitimos múltiples solicitudes para un mismo DNI porque cada email puede
// tener su propia contraseña (evita bloquear a otro responsable del mismo grupo).

// Generar token único
$token = bin2hex(random_bytes(32));

// Generar fechas
$created_at = date('Y-m-d H:i:s');
$expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

// Guardar token en la tabla password_resets
$stmt = $conn->prepare("INSERT INTO password_resets (dni_alumno, token, expires_at, created_at, used) VALUES (?, ?, ?, ?, 0)");
if (!$stmt) {
    // Si la columna 'used' no existe, intentar sin ella (compatibilidad)
    $stmt = $conn->prepare("INSERT INTO password_resets (dni_alumno, token, expires_at, created_at) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        error_log("Error prepare insert token: " . $conn->error);
        $_SESSION['error_olvide'] = "Error al generar el enlace. Intente nuevamente.";
        $conn->close();
        header("Location: olvide_password.php");
        exit;
    }
    $stmt->bind_param("ssss", $dni, $token, $expires_at, $created_at);
} else {
    $stmt->bind_param("ssss", $dni, $token, $expires_at, $created_at);
}

if (!$stmt->execute()) {
    error_log("Error al insertar token: " . $stmt->error);
    $_SESSION['error_olvide'] = "Error al generar el enlace. Intente nuevamente.";
    $stmt->close();
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}
$stmt->close();

// Enviar correo con el enlace
$reset_link = "https://complejonatta.com/natta-app/php/restablecer_password.php?token=" . urlencode($token) . "&email=" . urlencode($email);

$mail = new PHPMailer(true);
$mail->SMTPDebug = 0; // Desactivar depuración

try {
    // Configuración SMTP usando constantes desde smtp.php
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;
    // TLS: verificación de certificado del servidor (predeterminado de OpenSSL/PHPMailer).
    // No desactivar verify_peer; si falla la conexión, revisar CA del PHP del hosting (openssl.cafile).

    // Remitente y destinatario
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($email);

    // Contenido
    $mail->isHTML(true);
    $mail->Subject = 'Recuperación de contraseña - Complejo Natta';
    $mail->Body    = "
        <h2>Recuperación de contraseña</h2>
        <p>Hola,</p>
        <p>Has solicitado restablecer tu contraseña. Haz clic en el siguiente enlace para crear una nueva contraseña:</p>
        <p><a href='$reset_link'>$reset_link</a></p>
        <p><strong>Este enlace es válido por 1 hora.</strong></p>
        <p>Si no solicitaste esto, ignora este mensaje.</p>
        <br>
        <p>Saludos,<br>Complejo Natta</p>
    ";
    $mail->AltBody = "Para restablecer tu contraseña, visita: $reset_link . Válido por 1 hora.";

    $mail->send();
    app_audit_log($conn, 'reset_solicitud', 'password_reset', $dni, [
        'expires_at' => $expires_at,
    ], $dni, $email);
    $_SESSION['success_olvide'] = "Se ha enviado un enlace de recuperación a tu correo electrónico.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
} catch (Exception $e) {
    error_log("Error PHPMailer: " . $mail->ErrorInfo);
    $_SESSION['error_olvide'] = "Hubo un problema al enviar el correo. Por favor, inténtalo más tarde o contacta al administrador.";
    $conn->close();
    header("Location: olvide_password.php");
    exit;
}

function registrar_solicitud_reset($conn, $clave, $ip, $dimension, $email)
{
    $now = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("SELECT id, failed_attempts, locked_until, strike_count, last_attempt FROM login_attempts WHERE email = ?");
    if (!$stmt) {
        error_log("Error prepare registrar_solicitud_reset: " . $conn->error);
        return;
    }
    $stmt->bind_param("s", $clave);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $id = (int)$row['id'];
        $failed_attempts = (int)$row['failed_attempts'];
        $strike_count = (int)$row['strike_count'];
        $last_attempt = $row['last_attempt'] ?? null;

        // Reiniciar la progresión si hubo un periodo largo sin actividad.
        if ($last_attempt && strtotime($last_attempt) < strtotime('-6 hours')) {
            $failed_attempts = 0;
            $strike_count = 0;
        }

        $failed_attempts++;
        $locked_until = null;
        if ($strike_count === 0 && $failed_attempts >= 5) {
            $locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            $strike_count = 1;
            $failed_attempts = 0;
        } elseif ($strike_count === 1 && $failed_attempts >= 3) {
            $locked_until = date('Y-m-d H:i:s', strtotime('+60 minutes'));
            $strike_count = 2;
            $failed_attempts = 0;
        } elseif ($strike_count >= 2 && $failed_attempts >= 2) {
            $locked_until = date('Y-m-d H:i:s', strtotime('+180 minutes'));
            $strike_count++;
            $failed_attempts = 0;
        }

        $stmt_upd = $conn->prepare("UPDATE login_attempts SET failed_attempts = ?, last_attempt = ?, locked_until = ?, strike_count = ?, ip_address = ? WHERE id = ?");
        if ($stmt_upd) {
            $stmt_upd->bind_param("issisi", $failed_attempts, $now, $locked_until, $strike_count, $ip, $id);
            $stmt_upd->execute();
            $stmt_upd->close();
        } else {
            error_log("Error prepare update registrar_solicitud_reset: " . $conn->error);
        }

        if ($locked_until) {
            app_audit_log($conn, 'reset_rate_limit', 'password_reset', null, [
                'dimension' => $dimension,
                'bloqueado_hasta' => $locked_until,
            ], null, $email);
            registrar_evento_seguridad_reset(
                "RESET_RATE_LIMIT_LOCKED",
                "Lockout reset aplicado en dimension=$dimension hasta $locked_until",
                $email,
                $ip,
                "ALERT"
            );
        }
    } else {
        $failed_attempts = 1;
        $strike_count = 0;
        $locked_until = null;
        $stmt_ins = $conn->prepare("INSERT INTO login_attempts (email, ip_address, failed_attempts, last_attempt, locked_until, strike_count) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt_ins) {
            $stmt_ins->bind_param("ssissi", $clave, $ip, $failed_attempts, $now, $locked_until, $strike_count);
            $stmt_ins->execute();
            $stmt_ins->close();
        } else {
            error_log("Error prepare insert registrar_solicitud_reset: " . $conn->error);
        }
    }
    $stmt->close();
}

function obtener_bloqueo_activo_reset($conn, $clave)
{
    $stmt = $conn->prepare("SELECT locked_until FROM login_attempts WHERE email = ?");
    if (!$stmt) {
        error_log("Error prepare obtener_bloqueo_activo_reset: " . $conn->error);
        return null;
    }
    $stmt->bind_param("s", $clave);
    $stmt->execute();
    $result = $stmt->get_result();
    $attempt = $result->fetch_assoc();
    $stmt->close();

    if (!$attempt) {
        return null;
    }
    $locked_until = $attempt['locked_until'] ?? null;
    if ($locked_until && $locked_until > date('Y-m-d H:i:s')) {
        return $locked_until;
    }
    return null;
}

function construir_clave_rate_limit_reset_email($email)
{
    return "__reset_email__:" . substr(strtolower(trim($email)), 0, 170);
}

function construir_clave_rate_limit_reset_ip($ip)
{
    return "__reset_ip__:" . substr(trim($ip), 0, 175);
}

function obtener_ip_cliente()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($ip)) {
        return '0.0.0.0';
    }
    return substr($ip, 0, 45);
}

function registrar_evento_seguridad_reset($evento, $detalle, $email, $ip, $nivel = "WARN")
{
    $email_log = substr((string)$email, 0, 190);
    $ip_log = substr((string)$ip, 0, 45);
    error_log("[SECURITY][$nivel][$evento] $detalle | email=$email_log | ip=$ip_log");
}
?>
