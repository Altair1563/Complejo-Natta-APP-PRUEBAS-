<?php
/**
 * Bootstrap del panel admin: sesión, CSRF, configuración y PDO.
 */

if (!defined('NATTA_ROOT')) {
    define('NATTA_ROOT', dirname(__DIR__, 2));
}

require_once NATTA_ROOT . '/config/session.php';
secure_session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

define('_ACCESS', true);
require_once NATTA_ROOT . '/config/admin.php';
require_once NATTA_ROOT . '/config/db.php';
require_once NATTA_ROOT . '/config/app.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/admin_user.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/schema_install.php';
require_once __DIR__ . '/nav.php';

/** URL base del dashboard (mismo directorio que este script de entrada). */
$adminSelf = 'admin_dashboard.php';

/**
 * @param array<string, scalar|null> $params
 */
function admin_dashboard_url(array $params = []): string
{
    global $adminSelf;
    if ($params === []) {
        return $adminSelf;
    }
    return $adminSelf . '?' . http_build_query($params);
}

/**
 * @return PDO
 */
function admin_get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=3306;dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (Throwable $e) {
        error_log('Error de conexión en admin dashboard: ' . $e->getMessage());
        die('Error interno del servidor. Intente más tarde.');
    }
    admin_ensure_schema($pdo);
    return $pdo;
}

function admin_verify_csrf_post(): void
{
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(403);
        die('Token CSRF inválido');
    }
}
