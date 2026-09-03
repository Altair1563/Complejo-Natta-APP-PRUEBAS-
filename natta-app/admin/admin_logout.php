<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pdo = admin_get_pdo();
$redirectTo = 'admin_dashboard.php';

if (admin_is_logged_in()) {
    $u = admin_current_user();
    if (!admin_can_access_dashboard()) {
        $redirectTo = admin_panel_home();
    }
    admin_audit_log($pdo, 'logout', 'admin_user', $u['id'], ['username' => $u['username']]);
}

require_once NATTA_ROOT . '/config/session.php';
admin_clear_session_user();
destroy_session_fully();

header('Location: ' . $redirectTo);
exit;
