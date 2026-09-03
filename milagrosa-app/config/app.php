<?php
/**
 * URL pública de la app (para QR y enlaces para compartir).
 * Apunta al login; home.php requiere sesión iniciada.
 */
if (!defined('APP_PUBLIC_URL')) {
    if (!defined('TENANT_BOOTSTRAP')) {
        define('TENANT_BOOTSTRAP', true);
    }
    require_once __DIR__ . '/tenant_helpers.php';
    $slug = preg_quote(tenant_slug(), '#');
    $fallback = (string)(tenant_config()['public_url_fallback'] ?? ('https://complejonatta.com/' . tenant_slug() . '/index.php'));

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $appRoot = '/' . tenant_slug();
        if (preg_match('#^(.*?/' . $slug . ')(?:/|$)#', $script, $m)) {
            $appRoot = $m[1];
        } elseif (preg_match('#^(.*)/[^/]+$#', $script, $m)) {
            $appRoot = $m[1];
        }
        define('APP_PUBLIC_URL', $scheme . '://' . $_SERVER['HTTP_HOST'] . $appRoot . '/index.php');
    } else {
        define('APP_PUBLIC_URL', $fallback);
    }
}

/**
 * @return array{active: bool, epoch: int}
 */
function app_maintenance_state(): array
{
    static $state = null;
    if ($state !== null) {
        return $state;
    }

    $state = ['active' => false, 'epoch' => 0];
    try {
        if (!defined('_ACCESS')) {
            define('_ACCESS', true);
        }
        require_once __DIR__ . '/db.php';
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
        $sql = "SELECT clave, valor FROM configuracion
                WHERE clave IN ('modo_mantenimiento', 'modo_mantenimiento_epoch')";
        $res = $conn->query($sql);
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $clave = (string)($row['clave'] ?? '');
                $valor = (string)($row['valor'] ?? '');
                if ($clave === 'modo_mantenimiento') {
                    $state['active'] = ($valor === '1');
                } elseif ($clave === 'modo_mantenimiento_epoch') {
                    $state['epoch'] = (int)$valor;
                }
            }
            $res->free();
        }
        $conn->close();
    } catch (Throwable $e) {
        error_log('app_maintenance_state: ' . $e->getMessage());
    }

    return $state;
}

function app_is_maintenance_mode(): bool
{
    return app_maintenance_state()['active'];
}

function app_get_maintenance_epoch(): int
{
    return app_maintenance_state()['epoch'];
}

function app_base_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }

    if (!function_exists('tenant_slug')) {
        require_once __DIR__ . '/tenant_helpers.php';
    }
    $slug = preg_quote(tenant_slug(), '#');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if (preg_match('#^(.*?/' . $slug . ')(?:/|$)#', $script, $m)) {
        $path = $m[1];
    } elseif (preg_match('#^(.*)/[^/]+$#', $script, $m)) {
        $path = $m[1];
    } else {
        $path = '';
    }

    return $path;
}

function app_maintenance_page_url(): string
{
    $base = app_base_path();
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = function_exists('is_https_request') && is_https_request() ? 'https' : 'http';
        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
                $scheme = 'https';
            }
        }
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $base . '/mantenimiento.php';
    }

    return $base . '/mantenimiento.php';
}

function app_is_maintenance_exempt_request(): bool
{
    if (app_is_admin_request()) {
        return true;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    return (bool)preg_match('#/mantenimiento\.php$#', $script);
}

function app_is_admin_request(): bool
{
    if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return true;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    return (bool)preg_match('#/admin(?:/|$)#', $script);
}

function app_has_active_family_session(): bool
{
    if (isset($_SESSION['dni_alumno'], $_SESSION['nro_familia'])) {
        return true;
    }

    $gate = $_SESSION['privacy_gate'] ?? null;
    return is_array($gate) && !empty($gate['dni_alumno']) && !empty($gate['email']) && !empty($gate['nro_familia']);
}

function app_stamp_family_session(): void
{
    $_SESSION['maintenance_epoch'] = app_get_maintenance_epoch();
}

function app_send_to_maintenance_page(): void
{
    if (!headers_sent()) {
        $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xhr === 'xmlhttprequest' || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'maintenance' => true,
                'message' => 'El sistema se encuentra en mantenimiento.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . app_maintenance_page_url());
        exit;
    }

    exit;
}

function app_family_session_is_invalidated(): bool
{
    return app_is_maintenance_mode() && app_has_active_family_session();
}
