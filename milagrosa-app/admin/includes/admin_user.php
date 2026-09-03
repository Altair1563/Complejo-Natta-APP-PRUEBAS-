<?php
/**
 * Sesión de administrador, roles y permisos.
 */

const ADMIN_ROLE_SUPER = 'superadmin';
const ADMIN_ROLE_ADMIN = 'admin';
const ADMIN_ROLE_SECRETARIA = 'secretaria';
const ADMIN_ROLE_DIRECTIVO = 'directivo';

/** @var array<string, list<string>> */
const ADMIN_CAPABILITIES = [
    ADMIN_ROLE_SUPER => ['*'],
    ADMIN_ROLE_ADMIN => [
        'comunicados',
        'qr-app',
        'informes',
        'emails',
        'talones',
        'sugerencias',
        'auditoria',
        'auditoria-app',
    ],
    // Misma entrada que secretaría (estado_alumno.php); pestaña Control de Usuarios.
    ADMIN_ROLE_DIRECTIVO => [],
];

function admin_is_logged_in(): bool
{
    return !empty($_SESSION['admin_logged_in'])
        && !empty($_SESSION['admin_user_id']);
}

/**
 * @return array{id: int, username: string, nombre: string, rol: string, escuela_codigo: string}
 */
function admin_current_user(): array
{
    return [
        'id'              => (int)($_SESSION['admin_user_id'] ?? 0),
        'username'        => (string)($_SESSION['admin_username'] ?? ''),
        'nombre'          => (string)($_SESSION['admin_nombre'] ?? ''),
        'rol'             => (string)($_SESSION['admin_rol'] ?? ''),
        'escuela_codigo'  => (string)($_SESSION['admin_escuela_codigo'] ?? ''),
    ];
}

function admin_is_superadmin(): bool
{
    return ($_SESSION['admin_rol'] ?? '') === ADMIN_ROLE_SUPER;
}

function admin_is_secretaria(): bool
{
    return ($_SESSION['admin_rol'] ?? '') === ADMIN_ROLE_SECRETARIA;
}

function admin_is_directivo(): bool
{
    return ($_SESSION['admin_rol'] ?? '') === ADMIN_ROLE_DIRECTIVO;
}

/** Secretaría o directivo: panel estado_alumno con escuela asignada. */
function admin_is_rol_escuela(): bool
{
    return admin_is_secretaria() || admin_is_directivo();
}

function admin_escuela_codigo(): string
{
    return strtoupper(trim((string)($_SESSION['admin_escuela_codigo'] ?? '')));
}

/**
 * Destino post-login / logout según rol (panel dedicado o dashboard).
 */
function admin_panel_home(): string
{
    if (admin_is_secretaria() || admin_is_directivo()) {
        return 'estado_alumno.php';
    }

    return 'admin_dashboard.php';
}

function admin_can_access_dashboard(): bool
{
    $rol = (string)($_SESSION['admin_rol'] ?? '');
    return in_array($rol, [ADMIN_ROLE_SUPER, ADMIN_ROLE_ADMIN], true);
}

function admin_can_access_estado_alumno(): bool
{
    if (!admin_is_logged_in()) {
        return false;
    }

    return admin_can_access_estado_alumno_from_user([
        'rol' => (string)($_SESSION['admin_rol'] ?? ''),
        'escuela_codigo' => (string)($_SESSION['admin_escuela_codigo'] ?? ''),
    ]);
}

/**
 * @param array{rol: string, escuela_codigo?: ?string} $user
 */
function admin_can_access_estado_alumno_from_user(array $user): bool
{
    $rol = (string)($user['rol'] ?? '');
    if (!in_array($rol, [ADMIN_ROLE_SUPER, ADMIN_ROLE_ADMIN, ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true)) {
        return false;
    }
    if (in_array($rol, [ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true)) {
        return strtoupper(trim((string)($user['escuela_codigo'] ?? ''))) !== '';
    }

    return true;
}

function admin_can_access_control_usuarios(): bool
{
    if (!admin_is_logged_in()) {
        return false;
    }

    return admin_can_access_control_usuarios_from_user([
        'rol' => (string)($_SESSION['admin_rol'] ?? ''),
        'escuela_codigo' => (string)($_SESSION['admin_escuela_codigo'] ?? ''),
    ]);
}

/**
 * @param array{rol: string, escuela_codigo?: ?string} $user
 */
function admin_can_access_control_usuarios_from_user(array $user): bool
{
    $rol = (string)($user['rol'] ?? '');
    if (!in_array($rol, [ADMIN_ROLE_SUPER, ADMIN_ROLE_ADMIN, ADMIN_ROLE_DIRECTIVO], true)) {
        return false;
    }
    if ($rol === ADMIN_ROLE_DIRECTIVO) {
        return strtoupper(trim((string)($user['escuela_codigo'] ?? ''))) !== '';
    }

    return true;
}

function admin_can(string $capability): bool
{
    if (function_exists('admin_nav_capability')) {
        $capability = admin_nav_capability($capability);
    }

    $rol = (string)($_SESSION['admin_rol'] ?? '');
    if ($rol === ADMIN_ROLE_SUPER) {
        return true;
    }
    $allowed = ADMIN_CAPABILITIES[$rol] ?? [];
    return in_array($capability, $allowed, true);
}

function admin_require_capability(string $capability): void
{
    if (!admin_can($capability)) {
        http_response_code(403);
        die('No tiene permiso para esta acción.');
    }
}

function admin_login_fail_key(string $username): string
{
    return 'admin_fail_' . hash('sha256', strtolower(trim($username)));
}

/**
 * @return array{id: int, username: string, password_hash: string, nombre: string, rol: string, escuela_codigo: ?string}|null
 */
function admin_find_user_by_username(PDO $pdo, string $username): ?array
{
    $username = strtolower(trim($username));
    if ($username === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, nombre, rol, escuela_codigo
         FROM admin_users
         WHERE username = ? AND activo = 1
         LIMIT 1'
    );
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @param array{id: int, username: string, nombre: string, rol: string, escuela_codigo?: ?string} $user
 */
function admin_set_session_user(array $user): void
{
    $_SESSION['admin_user_id'] = (int)$user['id'];
    $_SESSION['admin_username'] = (string)$user['username'];
    $_SESSION['admin_nombre'] = (string)$user['nombre'];
    $_SESSION['admin_rol'] = (string)$user['rol'];
    $_SESSION['admin_escuela_codigo'] = strtoupper(trim((string)($user['escuela_codigo'] ?? '')));
    $_SESSION['admin_logged_in'] = true;
}

function admin_clear_session_user(): void
{
    unset(
        $_SESSION['admin_user_id'],
        $_SESSION['admin_username'],
        $_SESSION['admin_nombre'],
        $_SESSION['admin_rol'],
        $_SESSION['admin_escuela_codigo'],
        $_SESSION['admin_logged_in']
    );
}

function admin_touch_last_login(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('UPDATE admin_users SET ultimo_login = NOW() WHERE id = ?');
    $stmt->execute([$userId]);
}
