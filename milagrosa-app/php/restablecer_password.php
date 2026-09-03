<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// Obtener token de la URL
$token = $_GET['token'] ?? '';
$email = trim($_GET['email'] ?? '');

if (empty($token) || empty($email)) {
    header("Location: ../index.php");
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: ../index.php");
    exit;
}

// ============================================
//  CONEXIÓN A LA BASE DE DATOS (SEGURA)
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_audit.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en restablecer_password.php: " . $conn->connect_error);
    $_SESSION['error_login'] = "Error interno del servidor. Intente más tarde.";
    header("Location: ../index.php");
    exit;
}

// --- Verificar si la columna 'used' existe en password_resets ---
$check_column = $conn->query("SHOW COLUMNS FROM password_resets LIKE 'used'");
$has_used = $check_column && $check_column->num_rows > 0;

// Consulta: obtener el token sin filtrar por fecha (la validación la haremos en PHP)
if ($has_used) {
    $sql = "SELECT dni_alumno, expires_at FROM password_resets WHERE token = ? AND used = 0";
} else {
    $sql = "SELECT dni_alumno, expires_at FROM password_resets WHERE token = ?";
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Error prepare en restablecer_password: " . $conn->error);
    $_SESSION['error_login'] = "Error interno del servidor.";
    $conn->close();
    header("Location: ../index.php");
    exit;
}
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Token no existe o ya usado
    app_audit_log($conn, 'reset_token_invalido', 'password_reset', null, [
        'motivo' => 'token_no_encontrado',
    ], null, $email);
    $_SESSION['error_login'] = "El enlace de recuperación no es válido.";
    $stmt->close();
    $conn->close();
    header("Location: ../index.php");
    exit;
}

$row = $result->fetch_assoc();
$dni_alumno = $row['dni_alumno'];
$expires_at = $row['expires_at'];
$stmt->close();

// --- Validar expiración con PHP ---
$now = new DateTime();
$expires = new DateTime($expires_at);
if ($now > $expires) {
    app_audit_log($conn, 'reset_token_expirado', 'password_reset', $dni_alumno, [
        'expires_at' => $expires_at,
    ], $dni_alumno, $email);
    $_SESSION['error_login'] = "El enlace de recuperación ha expirado.";
    $conn->close();
    header("Location: ../index.php");
    exit;
}

// Token válido: guardamos en sesión
$_SESSION['reset_token'] = $token;
$_SESSION['reset_dni'] = $dni_alumno;
$_SESSION['reset_email'] = $email;

// Generar token CSRF para el formulario
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Mensajes de error (para el formulario)
$error_msg = isset($_SESSION['error_reset']) ? $_SESSION['error_reset'] : '';
unset($_SESSION['error_reset']);

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Restablecer Contraseña</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <link rel="stylesheet" href="../css/main.css">
</head>
<body class="cover" style="background-image: url(../assets/img/fondo-principal-app.webp);">
    <form action="guardar_nueva_password.php" method="POST" autocomplete="off" class="full-box logInForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <p class="text-center text-muted"><i class="zmdi zmdi-account-circle zmdi-hc-5x"></i></p>
        <p class="text-center text-muted text-uppercase">Nueva contraseña</p>
        <p class="text-center text-muted">Para el DNI: <?php echo htmlspecialchars($dni_alumno, ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="text-center text-muted">Email: <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></p>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger text-center"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="form-group label-floating">
            <label class="control-label" for="password">Nueva contraseña</label>
            <input class="form-control" id="password" type="password" name="password" required minlength="6">
            <p class="help-block">Mínimo 6 caracteres</p>
        </div>
        <div class="form-group label-floating">
            <label class="control-label" for="confirm">Confirmar contraseña</label>
            <input class="form-control" id="confirm" type="password" name="confirm" required>
            <p class="help-block">Repite la contraseña</p>
        </div>
        <div class="form-group text-center">
            <input type="submit" value="Guardar nueva contraseña" class="btn btn-raised btn-danger">
        </div>
    </form>

    <script src="../js/jquery-3.1.1.min.js"></script>
    <script src="../js/bootstrap.min.js"></script>
    <script src="../js/material.min.js"></script>
    <script src="../js/ripples.min.js"></script>
    <script src="../js/sweetalert2.min.js"></script>
    <script src="../js/jquery.mCustomScrollbar.concat.min.js"></script>
    <script type="module" src="../frontend/js/index.js"></script>
    <script>$.material.init();</script>

    <?php if (!empty($error_msg)): ?>
        <script>
            swal({
                title: "Error",
                text: <?php echo json_encode($error_msg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                icon: "error",
                button: "Aceptar",
                timer: 6000
            });
        </script>
    <?php endif; ?>
</body>
</html>
