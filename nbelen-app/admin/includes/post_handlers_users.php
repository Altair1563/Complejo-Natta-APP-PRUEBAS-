<?php
/**
 * POST: alta/edición de usuarios administradores (solo superadmin).
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

$accionUsuario = $_POST['accion'] ?? '';
if (!in_array($accionUsuario, ['usuario_crear', 'usuario_actualizar', 'usuario_desactivar'], true)) {
    return;
}

admin_require_capability('usuarios');
admin_verify_csrf_post();
require_once __DIR__ . '/escuelas_catalog.php';
require_once NATTA_ROOT . '/config/tenant_helpers.php';

$me = admin_current_user();
$redirectTab = 'usuarios';
$tenantCfg = function_exists('tenant_config') ? tenant_config() : [];
$institucionUnica = !empty($tenantCfg['institucion_unica']);
$scopeInstitucion = strtoupper(trim((string)($tenantCfg['admin_scope_codigo'] ?? 'AL')));

if ($accionUsuario === 'usuario_crear') {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $nombre = normalizePlainText($_POST['nombre'] ?? '', 100);
    $rol = $_POST['rol'] ?? ADMIN_ROLE_ADMIN;
    $password = (string)($_POST['password'] ?? '');
    $escuelasCatalogo = admin_escuelas_catalog();
    $escuelaCodigo = admin_validar_escuela_codigo((string)($_POST['escuela_codigo'] ?? ''), $escuelasCatalogo);
    if ($institucionUnica && in_array($rol, [ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true)) {
        $escuelaCodigo = $scopeInstitucion;
    }

    if ($username === '' || !preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
        $msg = 'Usuario inválido (3–50 caracteres: letras, números, punto, guión).';
        $msgType = 'warning';
    } elseif ($nombre === '') {
        $msg = 'El nombre es obligatorio.';
        $msgType = 'warning';
    } elseif (!in_array($rol, [ADMIN_ROLE_SUPER, ADMIN_ROLE_ADMIN, ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true)) {
        $msg = 'Rol inválido.';
        $msgType = 'warning';
    } elseif (!$institucionUnica && in_array($rol, [ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true) && $escuelaCodigo === null) {
        $msg = $rol === ADMIN_ROLE_DIRECTIVO
            ? 'Debe seleccionar la escuela que supervisará el directivo.'
            : 'Debe seleccionar la escuela que administrará la secretaría.';
        $msgType = 'warning';
    } elseif (strlen($password) < 8) {
        $msg = 'La contraseña debe tener al menos 8 caracteres.';
        $msgType = 'warning';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $escuelaGuardar = in_array($rol, [ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true)
                ? $escuelaCodigo
                : null;
            $stmt = $pdo->prepare(
                'INSERT INTO admin_users (username, password_hash, nombre, rol, escuela_codigo, activo)
                 VALUES (?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([$username, $hash, $nombre, $rol, $escuelaGuardar]);
            $newId = (int)$pdo->lastInsertId();
            admin_audit_log($pdo, 'usuario_crear', 'admin_user', $newId, [
                'username' => $username,
                'rol' => $rol,
                'escuela_codigo' => $escuelaGuardar,
            ]);
            if ($rol === ADMIN_ROLE_SECRETARIA) {
                $msg = 'Usuario de secretaría creado. Acceso: admin/estado_alumno.php';
            } elseif ($rol === ADMIN_ROLE_DIRECTIVO) {
                $msg = 'Usuario directivo creado. Acceso: admin/estado_alumno.php (pestaña Control de Usuarios).';
            } else {
                $msg = 'Usuario creado correctamente.';
            }
            $msgType = 'success';
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                $msg = 'Ese nombre de usuario ya existe.';
                $msgType = 'warning';
            } else {
                error_log('usuario_crear: ' . $e->getMessage());
                $msg = 'Error al crear el usuario.';
                $msgType = 'danger';
            }
        }
    }

    admin_redirect_tab('usuarios', $msg, $msgType);
    exit;
}

if ($accionUsuario === 'usuario_actualizar') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $nombre = normalizePlainText($_POST['nombre'] ?? '', 100);
    $rol = $_POST['rol'] ?? '';
    $password = (string)($_POST['password'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;
    $escuelasCatalogo = admin_escuelas_catalog();
    $escuelaCodigo = admin_validar_escuela_codigo((string)($_POST['escuela_codigo'] ?? ''), $escuelasCatalogo);
    if ($institucionUnica && in_array($rol, [ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true)) {
        $escuelaCodigo = $scopeInstitucion;
    }

    if ($userId < 1 || $nombre === '') {
        $msg = 'Datos incompletos.';
        $msgType = 'warning';
    } elseif (!in_array($rol, [ADMIN_ROLE_SUPER, ADMIN_ROLE_ADMIN, ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true)) {
        $msg = 'Rol inválido.';
        $msgType = 'warning';
    } elseif (!$institucionUnica && in_array($rol, [ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true) && $escuelaCodigo === null) {
        $msg = $rol === ADMIN_ROLE_DIRECTIVO
            ? 'Debe seleccionar la escuela que supervisará el directivo.'
            : 'Debe seleccionar la escuela que administrará la secretaría.';
        $msgType = 'warning';
    } elseif ($userId === $me['id'] && $activo === 0) {
        $msg = 'No puede desactivar su propia cuenta.';
        $msgType = 'warning';
    } elseif ($userId === $me['id'] && $rol !== ADMIN_ROLE_SUPER) {
        $msg = 'No puede quitarse el rol de superadmin a usted mismo.';
        $msgType = 'warning';
    } else {
        try {
            $escuelaGuardar = in_array($rol, [ADMIN_ROLE_SECRETARIA, ADMIN_ROLE_DIRECTIVO], true)
                ? $escuelaCodigo
                : null;
            if ($password !== '') {
                if (strlen($password) < 8) {
                    $msg = 'La contraseña debe tener al menos 8 caracteres.';
                    $msgType = 'warning';
                    admin_redirect_tab('usuarios', $msg, $msgType);
                    exit;
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'UPDATE admin_users SET nombre = ?, rol = ?, escuela_codigo = ?, activo = ?, password_hash = ? WHERE id = ?'
                );
                $stmt->execute([$nombre, $rol, $escuelaGuardar, $activo, $hash, $userId]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE admin_users SET nombre = ?, rol = ?, escuela_codigo = ?, activo = ? WHERE id = ?'
                );
                $stmt->execute([$nombre, $rol, $escuelaGuardar, $activo, $userId]);
            }
            admin_audit_log($pdo, 'usuario_actualizar', 'admin_user', $userId, [
                'rol' => $rol,
                'escuela_codigo' => $escuelaGuardar,
                'activo' => $activo,
                'password_cambiada' => $password !== '',
            ]);
            $msg = 'Usuario actualizado.';
            $msgType = 'success';
        } catch (Throwable $e) {
            error_log('usuario_actualizar: ' . $e->getMessage());
            $msg = 'Error al actualizar el usuario.';
            $msgType = 'danger';
        }
    }

    admin_redirect_tab('usuarios', $msg, $msgType);
    exit;
}

if ($accionUsuario === 'usuario_desactivar') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId < 1) {
        $msg = 'Usuario inválido.';
        $msgType = 'warning';
    } elseif ($userId === $me['id']) {
        $msg = 'No puede desactivar su propia cuenta.';
        $msgType = 'warning';
    } else {
        $stmt = $pdo->prepare('UPDATE admin_users SET activo = 0 WHERE id = ?');
        $stmt->execute([$userId]);
        admin_audit_log($pdo, 'usuario_desactivar', 'admin_user', $userId, []);
        $msg = 'Usuario desactivado.';
        $msgType = 'success';
    }
    admin_redirect_tab('usuarios', $msg, $msgType);
    exit;
}
