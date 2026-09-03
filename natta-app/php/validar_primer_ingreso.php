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
require_once __DIR__ . '/../config/app_audit.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en validar_primer_ingreso.php: " . $conn->connect_error);
    $_SESSION['error_primer_ingreso'] = "Error interno del servidor. Intente más tarde.";
    header("Location: primer_ingreso.php");
    exit;
}

$email = trim($_POST['email'] ?? '');
$dni = trim($_POST['dni'] ?? '');
$csrf_token = $_POST['csrf_token'] ?? '';

// Validar token CSRF
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    $_SESSION['error_primer_ingreso'] = "Token de seguridad inválido. Recargue la página.";
    $conn->close();
    header("Location: primer_ingreso.php");
    exit;
}

if (empty($email) || empty($dni)) {
    $_SESSION['error_primer_ingreso'] = "Por favor, complete todos los campos.";
    $conn->close();
    header("Location: primer_ingreso.php");
    exit;
}

// Validar formato de email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error_primer_ingreso'] = "El formato del email no es válido.";
    $conn->close();
    header("Location: primer_ingreso.php");
    exit;
}

// Validar DNI (solo números, 6-9 dígitos)
if (!preg_match('/^\d{6,9}$/', $dni)) {
    $_SESSION['error_primer_ingreso'] = "El DNI debe contener solo números (6 a 9 dígitos).";
    $conn->close();
    header("Location: primer_ingreso.php");
    exit;
}

// --- Verificar si este email + DNI ya tiene contraseña (evitar duplicados por cuenta) ---
$stmt = $conn->prepare("SELECT dni_alumno FROM usuarios WHERE dni_alumno = ? AND email = ?");
if (!$stmt) {
    error_log("Error prepare usuarios: " . $conn->error);
    $_SESSION['error_primer_ingreso'] = "Error interno del servidor.";
    $conn->close();
    header("Location: primer_ingreso.php");
    exit;
}
$stmt->bind_param("ss", $dni, $email);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    // Esta cuenta (email + DNI) ya tiene contraseña, redirigir a index con mensaje
    $_SESSION['error_login'] = "Este email ya tiene una contraseña para ese DNI. Por favor, inicie sesión.";
    $stmt->close();
    $conn->close();
    header("Location: ../index.php");
    exit;
}
$stmt->close();

// Buscar todas las familias del email
$query_familias = "SELECT nro_familia FROM email_familia WHERE ? IN (
    mail_padre, mail_padre_trabajo, mail_madre, mail_madre_trabajo, mail_resp_afip
)";
$stmt = $conn->prepare($query_familias);
if (!$stmt) {
    error_log("Error prepare email_familia: " . $conn->error);
    $_SESSION['error_primer_ingreso'] = "Error interno del servidor.";
    $conn->close();
    header("Location: primer_ingreso.php");
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
    $_SESSION['error_primer_ingreso'] = "El email no está registrado en ninguna familia.";
    $conn->close();
    header("Location: primer_ingreso.php");
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
    $_SESSION['error_primer_ingreso'] = "El DNI no corresponde a ninguna familia asociada a este email.";
    $conn->close();
    header("Location: primer_ingreso.php");
    exit;
}

// Guardamos en sesión los datos para la creación de contraseña
$_SESSION['primer_ingreso_dni'] = $dni;
$_SESSION['primer_ingreso_email'] = $email;
$_SESSION['primer_ingreso_familia'] = $nro_familia_correcto;

app_audit_log($conn, 'primer_ingreso_validado', 'sesion', null, [], $dni, $email, (string)$nro_familia_correcto);

$conn->close();
header("Location: establecer_password.php");
exit;
?>
