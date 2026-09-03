<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);

// Verificar sesión de primer ingreso
if (!isset($_SESSION['primer_ingreso_dni']) || !isset($_SESSION['primer_ingreso_email'])) {
    header("Location: ../index.php");
    exit;
}

$dni = $_SESSION['primer_ingreso_dni'];
$email = $_SESSION['primer_ingreso_email'];
$csrf_token = $_POST['csrf_token'] ?? '';
$password = trim($_POST['password'] ?? '');
$confirm = trim($_POST['confirm'] ?? '');

// Validar token CSRF
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    $_SESSION['error_password'] = "Token de seguridad inválido. Recargue la página.";
    header("Location: establecer_password.php");
    exit;
}

// Validaciones
if (empty($password) || empty($confirm)) {
    $_SESSION['error_password'] = "Por favor, complete ambos campos.";
    header("Location: establecer_password.php");
    exit;
}
if (strlen($password) < 6) {
    $_SESSION['error_password'] = "La contraseña debe tener al menos 6 caracteres.";
    header("Location: establecer_password.php");
    exit;
}
if ($password !== $confirm) {
    $_SESSION['error_password'] = "Las contraseñas no coinciden.";
    header("Location: establecer_password.php");
    exit;
}

// Incluir configuración centralizada de base de datos
define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_audit.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en guardar_password: " . $conn->connect_error);
    $_SESSION['error_password'] = "Error de conexión. Intente más tarde.";
    header("Location: establecer_password.php");
    exit;
}

// Verificar que esta cuenta (DNI + email) no tenga ya una contraseña
$stmt = $conn->prepare("SELECT dni_alumno FROM usuarios WHERE dni_alumno = ? AND email = ?");
if (!$stmt) {
    error_log("Error prepare SELECT en guardar_password: " . $conn->error);
    $_SESSION['error_password'] = "Error interno del servidor.";
    header("Location: establecer_password.php");
    $conn->close();
    exit;
}
$stmt->bind_param("ss", $dni, $email);
if (!$stmt->execute()) {
    error_log("Error execute SELECT en guardar_password: " . $stmt->error);
    $_SESSION['error_password'] = "Error interno del servidor.";
    $stmt->close();
    $conn->close();
    header("Location: establecer_password.php");
    exit;
}
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $_SESSION['error_password'] = "Este email ya tiene una contraseña registrada para ese DNI.";
    $stmt->close();
    $conn->close();
    header("Location: establecer_password.php");
    exit;
}
$stmt->close();

// Hashear y guardar
$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO usuarios (dni_alumno, password_hash, email) VALUES (?, ?, ?)");
if (!$stmt) {
    error_log("Error prepare INSERT en guardar_password: " . $conn->error);
    $_SESSION['error_password'] = "Error interno del servidor.";
    $conn->close();
    header("Location: establecer_password.php");
    exit;
}
$stmt->bind_param("sss", $dni, $hash, $email);

if ($stmt->execute()) {
    app_audit_log($conn, 'password_creada', 'usuario', $dni, [], $dni, $email);

    // Limpiar sesión de primer ingreso
    unset($_SESSION['primer_ingreso_dni']);
    unset($_SESSION['primer_ingreso_email']);
    unset($_SESSION['primer_ingreso_familia']); // puede no existir, pero no importa

    $_SESSION['success_login'] = "Contraseña creada exitosamente. Ahora puedes iniciar sesión con tu email, DNI y nueva contraseña.";
    $stmt->close();
    $conn->close();
    header("Location: ../index.php");
    exit;
} else {
    error_log("Error al insertar usuario en guardar_password: " . $stmt->error);
    if ((int)$stmt->errno === 1062 || (int)$conn->errno === 1062) {
        $_SESSION['error_password'] = "No se pudo crear la cuenta porque ya existe una contraseña para ese DNI en la base de datos.";
    } else {
        $_SESSION['error_password'] = "Ocurrió un error al guardar la contraseña. Intente nuevamente.";
    }
    $stmt->close();
    $conn->close();
    header("Location: establecer_password.php");
    exit;
}
?>
