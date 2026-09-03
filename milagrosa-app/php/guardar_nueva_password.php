<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Verificar que tengamos los datos en sesión
if (!isset($_SESSION['reset_token']) || !isset($_SESSION['reset_dni']) || !isset($_SESSION['reset_email'])) {
    header("Location: ../index.php");
    exit;
}

$token = $_SESSION['reset_token'];
$dni_sesion = $_SESSION['reset_dni'];
$email_sesion = $_SESSION['reset_email'];
$csrf_token = $_POST['csrf_token'] ?? '';
$password = trim($_POST['password'] ?? '');
$confirm = trim($_POST['confirm'] ?? '');

// Validar token CSRF
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    $_SESSION['error_reset'] = "Token de seguridad inválido. Recargue la página.";
    header("Location: restablecer_password.php?token=" . urlencode($token));
    exit;
}

// Validaciones básicas
if (empty($password) || empty($confirm)) {
    $_SESSION['error_reset'] = "Por favor, complete ambos campos.";
    header("Location: restablecer_password.php?token=" . urlencode($token));
    exit;
}
if (strlen($password) < 6) {
    $_SESSION['error_reset'] = "La contraseña debe tener al menos 6 caracteres.";
    header("Location: restablecer_password.php?token=" . urlencode($token));
    exit;
}
if ($password !== $confirm) {
    $_SESSION['error_reset'] = "Las contraseñas no coinciden.";
    header("Location: restablecer_password.php?token=" . urlencode($token));
    exit;
}

// Incluir configuración centralizada de base de datos
define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_audit.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en guardar_nueva_password: " . $conn->connect_error);
    $_SESSION['error_reset'] = "Error de conexión. Intente más tarde.";
    header("Location: restablecer_password.php?token=" . urlencode($token));
    exit;
}

// --- Verificar si la columna 'used' existe en password_resets ---
$check_column = $conn->query("SHOW COLUMNS FROM password_resets LIKE 'used'");
$has_used = $check_column && $check_column->num_rows > 0;

// --- Obtener el token y verificar validez ---
if ($has_used) {
    $sql = "SELECT dni_alumno, expires_at FROM password_resets WHERE token = ? AND used = 0";
} else {
    $sql = "SELECT dni_alumno, expires_at FROM password_resets WHERE token = ?";
}
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Error prepare en guardar_nueva_password: " . $conn->error);
    $_SESSION['error_reset'] = "Error interno del servidor.";
    header("Location: restablecer_password.php?token=" . urlencode($token));
    $conn->close();
    exit;
}

$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    app_audit_log($conn, 'reset_token_invalido', 'password_reset', null, [
        'motivo' => 'token_no_encontrado',
    ], $dni_sesion, $email_sesion);
    $_SESSION['error_login'] = "El enlace de recuperación no es válido o ya fue utilizado.";
    unset($_SESSION['reset_token'], $_SESSION['reset_dni'], $_SESSION['reset_email']);
    header("Location: ../index.php");
    $stmt->close();
    $conn->close();
    exit;
}

$row = $result->fetch_assoc();
$dni_token = $row['dni_alumno'];
$expires_at = $row['expires_at'];
$stmt->close();

// Verificar que el DNI de sesión coincida con el del token
if ($dni_sesion !== $dni_token) {
    error_log("Discrepancia de DNI en reset: sesión=$dni_sesion, token=$dni_token");
    $_SESSION['error_login'] = "Error de validación. Intente nuevamente.";
    unset($_SESSION['reset_token'], $_SESSION['reset_dni'], $_SESSION['reset_email']);
    header("Location: ../index.php");
    $conn->close();
    exit;
}

// Verificar expiración con PHP
$now = new DateTime();
$expires = new DateTime($expires_at);
if ($now > $expires) {
    app_audit_log($conn, 'reset_token_expirado', 'password_reset', $dni_token, [
        'expires_at' => $expires_at,
    ], $dni_sesion, $email_sesion);
    $_SESSION['error_login'] = "El enlace ha expirado.";
    unset($_SESSION['reset_token'], $_SESSION['reset_dni'], $_SESSION['reset_email']);
    header("Location: ../index.php");
    $conn->close();
    exit;
}

// --- Todo válido: actualizar contraseña ---
$hash = password_hash($password, PASSWORD_DEFAULT);

// Iniciar transacción
$conn->begin_transaction();

try {
    // Actualizar usuarios
    $stmt = $conn->prepare("UPDATE usuarios SET password_hash = ? WHERE dni_alumno = ? AND email = ?");
    if (!$stmt) {
        throw new Exception("Error preparando update: " . $conn->error);
    }
    $stmt->bind_param("sss", $hash, $dni_sesion, $email_sesion);
    if (!$stmt->execute()) {
        throw new Exception("Error al actualizar la contraseña: " . $stmt->error);
    }
    if ($stmt->affected_rows === 0) {
        throw new Exception("No existe una cuenta con ese email y DNI para actualizar.");
    }
    $stmt->close();

    // Marcar token como usado (o eliminarlo)
    if ($has_used) {
        $stmt2 = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        if (!$stmt2) {
            throw new Exception("Error preparando update used: " . $conn->error);
        }
        $stmt2->bind_param("s", $token);
        if (!$stmt2->execute()) {
            throw new Exception("Error al marcar token como usado: " . $stmt2->error);
        }
        $stmt2->close();
    } else {
        // Si no hay columna used, eliminamos el token
        $stmt2 = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
        if (!$stmt2) {
            throw new Exception("Error preparando delete: " . $conn->error);
        }
        $stmt2->bind_param("s", $token);
        $stmt2->execute();
        $stmt2->close();
    }

    $conn->commit();

    app_audit_log($conn, 'password_reset_ok', 'password_reset', $dni_sesion, [], $dni_sesion, $email_sesion);

    // Limpiar sesión
    unset($_SESSION['reset_token'], $_SESSION['reset_dni'], $_SESSION['reset_email']);

    $_SESSION['success_login'] = "Contraseña actualizada correctamente. Ahora puedes iniciar sesión.";
    header("Location: ../index.php");
    exit;
} catch (Exception $e) {
    $conn->rollback();
    error_log("Error en guardar_nueva_password: " . $e->getMessage());
    $_SESSION['error_reset'] = "Ocurrió un error al guardar la contraseña. Intente nuevamente.";
    header("Location: restablecer_password.php?token=" . urlencode($token));
    $conn->close();
    exit;
}
?>
