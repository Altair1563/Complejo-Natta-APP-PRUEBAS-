<?php
require_once __DIR__ . '/config/session.php';
secure_session_start();

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Si ya inició sesión, redirigir a home
if (isset($_SESSION['dni_alumno'])) {
    header("Location: home.php");
    exit;
}

// Pendiente aceptación de privacidad tras login válido
if (!empty($_SESSION['privacy_gate']) && is_array($_SESSION['privacy_gate'])) {
    header('Location: php/privacy_gate.php');
    exit;
}

// Generar token CSRF para formularios
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Capturar mensajes de error/éxito
$error_msg = isset($_SESSION['error_login']) ? $_SESSION['error_login'] : '';
unset($_SESSION['error_login']);
$success_msg = isset($_SESSION['success_login']) ? $_SESSION['success_login'] : '';
unset($_SESSION['success_login']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Iniciar Sesión</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <link rel="stylesheet" href="./css/main.css">
    <link rel="stylesheet" href="./css/pages/index.css">
</head>
<body class="cover" style="background-image: url(./assets/img/fondo-principal-app.webp);">
    <form action="php/login_padre.php" method="POST" autocomplete="off" class="full-box logInForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="login-brand text-center">
            <img src="./assets/img/LogoNbelen.jpg" alt="Nuestra Señora de Itati" class="login-logo">
        </div>
        <p class="text-center text-muted text-uppercase">Inicia sesión con tu cuenta</p>

        <!-- Mensajes -->
        <?php if (!empty($error_msg)): ?>
            <div id="error-message" class="alert alert-danger text-center"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (!empty($success_msg)): ?>
            <div id="success-message" class="alert alert-success text-center"><?php echo htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="form-group label-floating">
            <label class="control-label" for="email">E-mail</label>
            <input class="form-control" id="email" type="email" name="email" required>
            <p class="help-block">Escribe tu E-mail</p>
        </div>
        <div class="form-group label-floating">
            <label class="control-label" for="dni">DNI del alumno</label>
            <input class="form-control" id="dni" type="text" name="dni" required>
            <p class="help-block">DNI de tu hijo/a</p>
        </div>
        <div class="form-group label-floating">
            <label class="control-label" for="password">Contraseña</label>
            <input class="form-control" id="password" type="password" name="password" required>
            <p class="help-block">Escribe tu contraseña</p>
        </div>
        <div class="form-group text-center">
            <input type="submit" value="Iniciar sesión" class="btn btn-raised btn-danger">
        </div>
        <div class="text-center" style="margin-top: 10px;">
            <a href="php/primer_ingreso.php" style="color: #fff; text-decoration: underline;">¿Primera vez? Ingresa con tu DNI</a>
            &nbsp;|&nbsp;
            <a href="php/olvide_password.php" style="color: #fff; text-decoration: underline;">¿Olvidaste tu contraseña?</a>
        </div>
    </form>

    <!-- Scripts -->
    <script src="./js/jquery-3.1.1.min.js"></script>
    <script src="./js/bootstrap.min.js"></script>
    <script src="./js/material.min.js"></script>
    <script src="./js/ripples.min.js"></script>
    <script src="./js/sweetalert2.min.js"></script>
    <script src="./js/jquery.mCustomScrollbar.concat.min.js"></script>
    <script type="module" src="./frontend/js/index.js"></script>
    <script>
        $.material.init();

        // Función para ocultar mensajes después de 6 segundos
        setTimeout(function() {
            var errorMsg = document.getElementById('error-message');
            var successMsg = document.getElementById('success-message');
            if (errorMsg) {
                errorMsg.classList.add('fade-out');
                setTimeout(function() { errorMsg.style.display = 'none'; }, 1000); // después de la transición
            }
            if (successMsg) {
                successMsg.classList.add('fade-out');
                setTimeout(function() { successMsg.style.display = 'none'; }, 1000);
            }
        }, 6000);
    </script>

    <?php if (!empty($error_msg)): ?>
        <script>
            swal({
                title: "Error",
                text: <?php echo json_encode($error_msg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                icon: "error",
                button: "Aceptar",
                timer: 6000,
                closeOnClickOutside: true
            });
        </script>
    <?php endif; ?>
    <?php if (!empty($success_msg)): ?>
        <script>
            swal({
                title: "Éxito",
                text: <?php echo json_encode($success_msg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                icon: "success",
                button: "Aceptar",
                timer: 6000,
                closeOnClickOutside: true
            });
        </script>
    <?php endif; ?>
</body>
</html>