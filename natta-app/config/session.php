<?php
/**
 * Helpers centralizados para hardening de sesiones.
 */

if (!defined('APP_SESSION_NAME')) {
    define('APP_SESSION_NAME', 'NATTAAPPSESSID');
}
if (!defined('APP_SESSION_COOKIE_PATH')) {
    define('APP_SESSION_COOKIE_PATH', '/natta-app/');
}
if (!defined('APP_SESSION_SCOPE')) {
    define('APP_SESSION_SCOPE', 'natta');
}

if (!function_exists('is_https_request')) {
    function is_https_request(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return false;
    }
}

if (!function_exists('secure_session_start')) {
    function secure_session_start(): void
    {
        // Aplica cabeceras base en cada request que use este helper.
        apply_security_headers();

        $startedNow = false;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $isHttps = is_https_request();
            session_name(APP_SESSION_NAME);
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_secure', $isHttps ? '1' : '0');
            ini_set('session.cookie_samesite', 'Lax');

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => APP_SESSION_COOKIE_PATH,
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();
            $startedNow = true;
        }

        if ($startedNow) {
            apply_session_runtime_hardening();
        }

        apply_public_maintenance_lockout();
    }
}

if (!function_exists('apply_security_headers')) {
    function apply_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' https://cdn.jsdelivr.net data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
        if (is_https_request()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

if (!function_exists('secure_session_regenerate')) {
    function secure_session_regenerate(): void
    {
        secure_session_start();
        session_regenerate_id(true);
        $_SESSION['__session_last_regenerated'] = time();
        if (!isset($_SESSION['__session_created_at'])) {
            $_SESSION['__session_created_at'] = time();
        }
    }
}

if (!function_exists('destroy_session_fully')) {
    function destroy_session_fully(): void
    {
        secure_session_start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => !empty($params['secure']),
                'httponly' => !empty($params['httponly']),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }
}

if (!function_exists('session_is_authenticated')) {
    function session_is_authenticated(): bool
    {
        $isAdmin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
        $isFamilyUser = isset($_SESSION['dni_alumno'], $_SESSION['nro_familia']);
        return $isAdmin || $isFamilyUser;
    }
}

if (!function_exists('session_inactivity_timeout_seconds')) {
    function session_inactivity_timeout_seconds(): int
    {
        return 1800; // 30 minutos
    }
}

if (!function_exists('session_rotation_interval_seconds')) {
    function session_rotation_interval_seconds(): int
    {
        return 900; // 15 minutos
    }
}

if (!function_exists('reset_expired_authenticated_session')) {
    function reset_expired_authenticated_session(): void
    {
        $dni = isset($_SESSION['dni_alumno']) ? (string)$_SESSION['dni_alumno'] : null;
        $email = isset($_SESSION['email']) ? (string)$_SESSION['email'] : null;
        $nroFamilia = isset($_SESSION['nro_familia']) ? (string)$_SESSION['nro_familia'] : null;

        if ($dni !== null && $nroFamilia !== null) {
            try {
                if (!defined('_ACCESS')) {
                    define('_ACCESS', true);
                }
                require_once __DIR__ . '/db.php';
                require_once __DIR__ . '/app_audit.php';
                $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                if (!$conn->connect_error) {
                    $conn->set_charset('utf8mb4');
                    app_audit_log($conn, 'session_expired', 'sesion', null, [
                        'motivo' => 'inactividad',
                    ], $dni, $email, $nroFamilia);
                    $conn->close();
                }
            } catch (Throwable $e) {
                error_log('reset_expired_authenticated_session audit: ' . $e->getMessage());
            }
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => !empty($params['secure']),
                'httponly' => !empty($params['httponly']),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        session_start();
        $_SESSION['session_expired'] = 'inactivity';
    }
}

if (!function_exists('apply_session_runtime_hardening')) {
    function apply_session_runtime_hardening(): void
    {
        $now = time();

        $storedScope = $_SESSION['__app_scope'] ?? null;
        if (is_string($storedScope) && !hash_equals(APP_SESSION_SCOPE, $storedScope)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['__app_scope'] = APP_SESSION_SCOPE;

        if (!isset($_SESSION['__session_created_at'])) {
            $_SESSION['__session_created_at'] = $now;
        }
        if (!isset($_SESSION['__session_last_regenerated'])) {
            $_SESSION['__session_last_regenerated'] = $now;
        }
        if (!isset($_SESSION['__session_last_activity'])) {
            $_SESSION['__session_last_activity'] = $now;
        }

        if (!session_is_authenticated()) {
            return;
        }

        $lastActivity = (int)($_SESSION['__session_last_activity'] ?? $now);
        if (($now - $lastActivity) > session_inactivity_timeout_seconds()) {
            reset_expired_authenticated_session();
            return;
        }

        $lastRegenerated = (int)($_SESSION['__session_last_regenerated'] ?? $now);
        if (($now - $lastRegenerated) > session_rotation_interval_seconds()) {
            session_regenerate_id(true);
            $_SESSION['__session_last_regenerated'] = $now;
        }

        $_SESSION['__session_last_activity'] = $now;
    }
}

if (!function_exists('terminate_public_session_for_maintenance')) {
    function terminate_public_session_for_maintenance(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => !empty($params['secure']),
                'httponly' => !empty($params['httponly']),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

if (!function_exists('apply_public_maintenance_lockout')) {
    function apply_public_maintenance_lockout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        require_once __DIR__ . '/app.php';

        if (app_is_maintenance_exempt_request() || !app_is_maintenance_mode()) {
            return;
        }

        if (app_has_active_family_session()) {
            terminate_public_session_for_maintenance();
        }

        app_send_to_maintenance_page();
    }
}
