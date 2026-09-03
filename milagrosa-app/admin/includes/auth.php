<?php
/**
 * Login de administrador (usuarios en BD) y comprobación de sesión.
 */

$login_error = '';
$pdo = admin_get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $failKey = admin_login_fail_key($username);
    $lockedUntil = (int)($_SESSION[$failKey . '_locked'] ?? 0);

    if ($lockedUntil > time()) {
        $waitSeconds = $lockedUntil - time();
        $login_error = 'Acceso temporalmente bloqueado. Reintente en ' . $waitSeconds . ' segundos.';
    } elseif (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        $login_error = 'Solicitud inválida.';
    } elseif ($username === '' || $password === '') {
        $login_error = 'Usuario y contraseña son obligatorios.';
    } else {
        $user = admin_find_user_by_username($pdo, $username);
        if ($user && password_verify($password, $user['password_hash'])) {
            admin_set_session_user($user);
            unset($_SESSION[$failKey . '_count'], $_SESSION[$failKey . '_locked']);
            secure_session_regenerate();
            admin_touch_last_login($pdo, (int)$user['id']);
            admin_audit_log($pdo, 'login_ok', 'admin_user', (int)$user['id'], [
                'username' => $user['username'],
            ]);
            $destino = admin_can_access_dashboard() ? $adminSelf : admin_panel_home();
            header('Location: ' . $destino);
            exit;
        }

        $failCount = (int)($_SESSION[$failKey . '_count'] ?? 0) + 1;
        $_SESSION[$failKey . '_count'] = $failCount;
        if ($failCount >= 5) {
            $_SESSION[$failKey . '_locked'] = time() + 900;
            $_SESSION[$failKey . '_count'] = 0;
        }
        admin_audit_log($pdo, 'login_fail', 'admin_user', null, [
            'username' => $username,
        ]);
        $login_error = 'Usuario o contraseña incorrectos.';
    }
}

if (!admin_is_logged_in()) {
    require __DIR__ . '/../views/login.php';
    exit;
}

if (!admin_can_access_dashboard()) {
    header('Location: ' . admin_panel_home());
    exit;
}

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: text/html; charset=utf-8');
