<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// ============================================
//  CONEXIÓN A LA BASE DE DATOS (SEGURA)
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/privacy_policy.php';
require_once __DIR__ . '/../config/app_audit.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en login_padre.php: " . $conn->connect_error);
    $_SESSION['error_login'] = "Error interno del servidor. Intente más tarde.";
    header("Location: ../index.php");
    exit;
}
$conn->set_charset('utf8mb4');

$privacy_policy_active = null;
try {
    $privacy_policy_active = privacy_policy_get_active($conn);
} catch (Throwable $e) {
    error_log('login_padre.php privacy_policy_get_active: ' . $e->getMessage());
}

// Obtener datos del formulario
$email = trim($_POST['email'] ?? '');
$dni = trim($_POST['dni'] ?? '');
$pass = trim($_POST['password'] ?? '');
$csrf_token = $_POST['csrf_token'] ?? '';
$ip = obtener_ip_cliente();

// Validar token CSRF
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    $_SESSION['error_login'] = "Token de seguridad inválido. Recargue la página.";
    header("Location: ../index.php");
    $conn->close();
    exit;
}

// Validación básica
if (empty($email) || empty($dni) || empty($pass)) {
    $_SESSION['error_login'] = "Por favor, complete todos los campos.";
    header("Location: ../index.php");
    $conn->close();
    exit;
}

// --- Sistema de bloqueo por intentos (email + IP) ---
$email_key = normalizar_clave_rate_limit($email);
$ip_key = construir_clave_ip_login($ip);

$bloqueo_email = obtener_bloqueo_activo($conn, $email_key);
$bloqueo_ip = obtener_bloqueo_activo($conn, $ip_key);

if ($bloqueo_email || $bloqueo_ip) {
    $locked_until = $bloqueo_email ?: $bloqueo_ip;
    $minutos = max(1, (int)ceil((strtotime($locked_until) - time()) / 60));
    app_audit_log($conn, 'login_blocked', 'sesion', null, [
        'motivo' => 'rate_limit',
        'bloqueado_hasta' => $locked_until,
    ], $dni !== '' ? $dni : null, $email);
    registrar_evento_seguridad(
        "LOGIN_BLOCKED",
        "Intento de acceso bloqueado por rate limiting",
        $email,
        $ip,
        "WARN"
    );
    $_SESSION['error_login'] = "Demasiados intentos. Intente nuevamente en $minutos minutos.";
    $conn->close();
    header("Location: ../index.php");
    exit;
}

// --- Verificar que el email y DNI correspondan a la misma familia ---
// Buscar todas las familias del email
$query_familias = "SELECT nro_familia FROM email_familia WHERE ? IN (
    mail_padre, mail_padre_trabajo, mail_madre, mail_madre_trabajo, mail_resp_afip
)";
$stmt = $conn->prepare($query_familias);
if (!$stmt) {
    error_log("Error prepare familias: " . $conn->error);
    $_SESSION['error_login'] = "Error interno del servidor.";
    $conn->close();
    header("Location: ../index.php");
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
    // Email no registrado
    app_audit_log($conn, 'login_fail', 'sesion', null, ['motivo' => 'email_no_registrado'], $dni, $email);
    registrar_intento_fallido($conn, $email, $ip);
    $_SESSION['error_login'] = "Email o DNI incorrectos.";
    $conn->close();
    header("Location: ../index.php");
    exit;
}

// Verificar que el DNI pertenezca a algún alumno de esas familias
$encontrado = false;
$nro_familia_correcto = null;
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
        $nro_familia_correcto = $nro_familia;
        $stmt2->close();
        break;
    }
    $stmt2->close();
}

if (!$encontrado) {
    // DNI no corresponde a ninguna familia del email
    app_audit_log($conn, 'login_fail', 'sesion', null, ['motivo' => 'dni_no_coincide'], $dni, $email);
    registrar_intento_fallido($conn, $email, $ip);
    $_SESSION['error_login'] = "Email o DNI incorrectos.";
    $conn->close();
    header("Location: ../index.php");
    exit;
}

// --- Verificar la contraseña asociada a esta cuenta (email + DNI) ---
$stmt = $conn->prepare("SELECT password_hash FROM usuarios WHERE dni_alumno = ? AND email = ?");
if (!$stmt) {
    error_log("Error prepare usuarios: " . $conn->error);
    $_SESSION['error_login'] = "Error interno del servidor.";
    $conn->close();
    header("Location: ../index.php");
    exit;
}
$stmt->bind_param("ss", $dni, $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Esta cuenta no tiene contraseña registrada (primer ingreso pendiente)
    app_audit_log($conn, 'login_sin_password', 'sesion', null, ['motivo' => 'primer_ingreso_pendiente'], $dni, $email);
    registrar_intento_fallido($conn, $email, $ip);
    $_SESSION['error_login'] = "Debe crear una contraseña para este email. Use 'Primera vez'.";
    $stmt->close();
    $conn->close();
    header("Location: ../index.php");
    exit;
}

$user = $result->fetch_assoc();
$password_hash = $user['password_hash'];
$stmt->close();

if (password_verify($pass, $password_hash)) {
    // Contraseña correcta
    // Limpiar intentos fallidos
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ? OR email = ?");
    if ($stmt) {
        $stmt->bind_param("ss", $email_key, $ip_key);
        $stmt->execute();
        $stmt->close();
    }

    $needs_privacy_gate = false;
    if ($privacy_policy_active !== null) {
        try {
            $pv_check = (string)$privacy_policy_active['policy_version'];
            if (!privacy_policy_user_has_accepted($conn, $dni, $email, $pv_check)) {
                $needs_privacy_gate = true;
            }
        } catch (Throwable $e) {
            error_log('login_padre.php privacy gate check: ' . $e->getMessage());
        }
    }

    if ($needs_privacy_gate) {
        $_SESSION['privacy_gate'] = [
            'dni_alumno' => $dni,
            'email' => $email,
            'nro_familia' => (string)$nro_familia_correcto,
            'policy_version' => (string)$privacy_policy_active['policy_version'],
            'policy_hash' => (string)$privacy_policy_active['policy_hash'],
            'gate_started_at' => time(),
        ];
        app_audit_log($conn, 'login_credenciales_ok', 'sesion', null, [
            'motivo' => 'pendiente_privacy_gate',
            'policy_version' => (string)$privacy_policy_active['policy_version'],
        ], $dni, $email, (string)$nro_familia_correcto);
        secure_session_regenerate();
        $conn->close();
        header('Location: privacy_gate.php');
        exit;
    }

    if ($privacy_policy_active !== null) {
        try {
            $pv = (string)$privacy_policy_active['policy_version'];
            $ph = (string)$privacy_policy_active['policy_hash'];
            $accepted_at = date('Y-m-d H:i:s');
            $user_agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
            $nro_fam_str = (string)$nro_familia_correcto;
            if (!privacy_policy_try_record_acceptance(
                $conn,
                $dni,
                $email,
                $nro_fam_str,
                $pv,
                $ph,
                $accepted_at,
                $ip,
                $user_agent
            )) {
                error_log('login_padre.php: no se pudo registrar privacy_policy_acceptances para ' . $email);
            }
        } catch (Throwable $e) {
            error_log('login_padre.php privacy insert: ' . $e->getMessage());
        }
    }

    // Guardar en sesión
    $_SESSION['dni_alumno'] = $dni;
    $_SESSION['nro_familia'] = $nro_familia_correcto;
    $_SESSION['email'] = $email; // opcional
    app_stamp_family_session();

    // Regenerar ID de sesión por seguridad
    secure_session_regenerate();

    app_audit_log($conn, 'login_ok', 'sesion', null, [], $dni, $email, (string)$nro_familia_correcto);

    $conn->close();
    header("Location: ../home.php");
    exit;
} else {
    // Contraseña incorrecta
    app_audit_log($conn, 'login_fail', 'sesion', null, ['motivo' => 'password_incorrecta'], $dni, $email);
    registrar_intento_fallido($conn, $email, $ip);
    $_SESSION['error_login'] = "Email o DNI incorrectos.";
    $conn->close();
    header("Location: ../index.php");
    exit;
}

// ============================================
//  FUNCIÓN PARA REGISTRAR INTENTOS FALLIDOS
// ============================================
function registrar_intento_fallido($conn, $email, $ip)
{
    registrar_intento_fallido_clave($conn, normalizar_clave_rate_limit($email), $ip, "email", $email);
    registrar_intento_fallido_clave($conn, construir_clave_ip_login($ip), $ip, "ip", $email);
}

function registrar_intento_fallido_clave($conn, $clave, $ip, $dimension, $email)
{
    $now = date('Y-m-d H:i:s');

    $stmt = $conn->prepare("SELECT id, failed_attempts, locked_until, strike_count, last_attempt FROM login_attempts WHERE email = ?");
    if (!$stmt) {
        error_log("Error prepare en registrar_intento_fallido_clave: " . $conn->error);
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

        // Si no hubo actividad en el último día, reiniciamos progresión de strikes.
        if ($last_attempt && strtotime($last_attempt) < strtotime('-24 hours')) {
            $failed_attempts = 0;
            $strike_count = 0;
        }

        $failed_attempts++;
        $locked_until = null;
        if ($strike_count === 0 && $failed_attempts >= 6) {
            $locked_until = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            $strike_count = 1;
            $failed_attempts = 0;
        } elseif ($strike_count === 1 && $failed_attempts >= 3) {
            $locked_until = date('Y-m-d H:i:s', strtotime('+60 minutes'));
            $strike_count = 2;
            $failed_attempts = 0;
        } elseif ($strike_count >= 2 && $failed_attempts >= 1) {
            $locked_until = date('Y-m-d H:i:s', strtotime('+120 minutes'));
            $strike_count++;
            $failed_attempts = 0;
        }

        $stmt_upd = $conn->prepare("UPDATE login_attempts SET failed_attempts = ?, last_attempt = ?, locked_until = ?, strike_count = ?, ip_address = ? WHERE id = ?");
        if ($stmt_upd) {
            $stmt_upd->bind_param("issisi", $failed_attempts, $now, $locked_until, $strike_count, $ip, $id);
            $stmt_upd->execute();
            $stmt_upd->close();
        } else {
            error_log("Error prepare update login_attempts: " . $conn->error);
        }

        if ($locked_until) {
            app_audit_log($conn, 'login_rate_limit', 'sesion', null, [
                'dimension' => $dimension,
                'bloqueado_hasta' => $locked_until,
            ], null, $email);
            registrar_evento_seguridad(
                "LOGIN_RATE_LIMIT_LOCKED",
                "Lockout aplicado en dimension=$dimension hasta $locked_until",
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
            error_log("Error prepare insert login_attempts: " . $conn->error);
        }
    }
    $stmt->close();
}

function obtener_bloqueo_activo($conn, $clave)
{
    $stmt = $conn->prepare("SELECT locked_until FROM login_attempts WHERE email = ?");
    if (!$stmt) {
        error_log("Error prepare obtener_bloqueo_activo: " . $conn->error);
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

function normalizar_clave_rate_limit($email)
{
    $email_normalizado = strtolower(trim($email));
    return substr($email_normalizado, 0, 190);
}

function construir_clave_ip_login($ip)
{
    return "__login_ip__:" . substr(trim($ip), 0, 170);
}

function obtener_ip_cliente()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (empty($ip)) {
        return '0.0.0.0';
    }
    return substr($ip, 0, 45);
}

function registrar_evento_seguridad($evento, $detalle, $email, $ip, $nivel = "WARN")
{
    $email_log = substr((string)$email, 0, 190);
    $ip_log = substr((string)$ip, 0, 45);
    error_log("[SECURITY][$nivel][$evento] $detalle | email=$email_log | ip=$ip_log");
}
?>
