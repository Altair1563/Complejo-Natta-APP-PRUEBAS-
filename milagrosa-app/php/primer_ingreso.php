<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (isset($_SESSION['dni_alumno'])) {
    header("Location: ../home.php");
    exit;
}

// Generar token CSRF para formularios
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$error_msg = isset($_SESSION['error_primer_ingreso']) ? $_SESSION['error_primer_ingreso'] : '';
unset($_SESSION['error_primer_ingreso']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Primer Ingreso</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <link rel="stylesheet" href="../css/main.css">
</head>
<body class="cover" style="background-image: url(../assets/img/fondo-principal-app.webp);">
    <form action="validar_primer_ingreso.php" method="POST" autocomplete="off" class="full-box logInForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <p class="text-center text-muted"><i class="zmdi zmdi-account-circle zmdi-hc-5x"></i></p>
        <p class="text-center text-muted text-uppercase">Primer ingreso - Verifica tus datos</p>

        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger text-center"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="form-group label-floating">
            <label class="control-label" for="email">E-mail</label>
            <input class="form-control" id="email" type="email" name="email" required>
            <p class="help-block">Escribe el e-mail que tenemos registrado</p>
        </div>
        <div class="form-group label-floating">
            <label class="control-label" for="dni">DNI del alumno</label>
            <input class="form-control" id="dni" type="text" name="dni" required>
            <p class="help-block">Ingresa el DNI de tu hijo/a</p>
        </div>
        <div class="form-group text-center">
            <input type="submit" value="Verificar" class="btn btn-raised btn-danger">
        </div>
        <div class="text-center" style="margin-top: 10px;">
            <a href="../index.php" style="color: #fff; text-decoration: underline;">Volver al inicio de sesión</a>
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
                button: "Aceptar"
            });
        </script>
    <?php endif; ?>
</body>
</html>
