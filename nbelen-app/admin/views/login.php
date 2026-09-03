<?php
$loginLogoFile = function_exists('tenant_logo_file') ? tenant_logo_file() : 'LogoNbelen.jpg';
$loginLogoAlt = function_exists('tenant_name') ? tenant_name() : 'Nuestra Señora de Itati';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Administrador</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/pages/admin-login.css">
</head>
<body>
    <div class="login-box">
        <div class="login-brand text-center mb-3">
            <img src="../assets/img/<?= htmlspecialchars($loginLogoFile, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($loginLogoAlt, ENT_QUOTES, 'UTF-8') ?>"
                 class="login-logo">
        </div>
        <h3 class="text-center mb-4">🔐 Acceso Administrador</h3>
        <?php if (!empty($login_error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" action="<?= htmlspecialchars($adminSelf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
                <label for="username" class="form-label">Usuario</label>
                <input type="text" class="form-control" id="username" name="username" required autofocus
                       autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Contraseña</label>
                <input type="password" class="form-control" id="password" name="password" required
                       autocomplete="current-password">
            </div>
            <button type="submit" name="login" class="btn btn-primary w-100">Ingresar</button>
        </form>
    </div>
</body>
</html>
