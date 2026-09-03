<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();

// ============================================
//  COMPROBACIÓN DE PRIMER INGRESO
// ============================================
if (!isset($_SESSION['primer_ingreso_dni'])) {
    header("Location: ../index.php");
    exit;
}

// Generar token CSRF para formularios
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$dni = $_SESSION['primer_ingreso_dni'];
$email = $_SESSION['primer_ingreso_email'] ?? '';
$error_msg = isset($_SESSION['error_password']) ? $_SESSION['error_password'] : '';
unset($_SESSION['error_password']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Establecer Contraseña</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <link rel="stylesheet" href="../css/main.css">
</head>
<body class="cover" style="background-image: url(../assets/img/fondo-principal-app.webp);">
    <form action="guardar_password.php" method="POST" autocomplete="off" class="full-box logInForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <p class="text-center text-muted"><i class="zmdi zmdi-account-circle zmdi-hc-5x"></i></p>
        <p class="text-center text-muted text-uppercase">Crea tu contraseña</p>
        <p class="text-center text-muted">Para el DNI: <strong><?php echo htmlspecialchars($dni, ENT_QUOTES, 'UTF-8'); ?></strong></p>
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
            <input type="submit" value="Guardar contraseña" class="btn btn-raised btn-danger">
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
            swal({ title: "Error", text: "<?php echo addslashes($error_msg); ?>", icon: "error", button: "Aceptar" });
        </script>
    <?php endif; ?>
</body>
</html>
