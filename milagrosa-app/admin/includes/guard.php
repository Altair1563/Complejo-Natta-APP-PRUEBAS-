<?php
/**
 * Comprobación de sesión admin para scripts fuera del dashboard.
 */

require_once dirname(__DIR__, 2) . '/config/session.php';

function admin_guard_json_or_die(): void
{
    secure_session_start();
    if (!function_exists('admin_is_logged_in')) {
        require_once __DIR__ . '/admin_user.php';
    }
    if (!admin_is_logged_in()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }
}

function admin_guard_or_die(): void
{
    secure_session_start();
    if (!function_exists('admin_is_logged_in')) {
        require_once __DIR__ . '/admin_user.php';
    }
    if (!admin_is_logged_in()) {
        http_response_code(403);
        exit('Acceso denegado');
    }
}

function admin_guard_superadmin_or_die(): void
{
    admin_guard_or_die();
    if (!admin_is_superadmin()) {
        http_response_code(403);
        exit('Acceso denegado');
    }
}
