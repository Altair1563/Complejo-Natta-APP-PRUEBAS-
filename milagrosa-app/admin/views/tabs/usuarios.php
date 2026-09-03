<?php
require_once __DIR__ . '/../../includes/escuelas_catalog.php';
$escuelasUsuario = admin_escuelas_catalog();
$institucionUnica = function_exists('tenant_config') && !empty(tenant_config()['institucion_unica']);
$usuarios = [];
$usuariosDbError = '';

try {
    $usuarios = $pdo->query(
        'SELECT id, username, nombre, rol, escuela_codigo, activo, ultimo_login, created_at
         FROM admin_users ORDER BY activo DESC, username ASC'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('usuarios tab escuela_codigo: ' . $e->getMessage());
    try {
        $usuarios = $pdo->query(
            'SELECT id, username, nombre, rol, activo, ultimo_login, created_at
             FROM admin_users ORDER BY activo DESC, username ASC'
        )->fetchAll();
        foreach ($usuarios as $idx => $row) {
            $usuarios[$idx]['escuela_codigo'] = null;
        }
        $usuariosDbError = 'La base aún no tiene la columna escuela_codigo. Recargá el panel una vez para aplicar la migración automática.';
    } catch (PDOException $e2) {
        error_log('usuarios tab: ' . $e2->getMessage());
        $usuariosDbError = 'No se pudo cargar la lista de usuarios.';
    }
}
?>
            <div class="tab-pane fade show active" id="usuarios" role="tabpanel">
                <div class="row mt-3">
                    <?php if ($usuariosDbError !== ''): ?>
                        <div class="col-12">
                            <div class="alert alert-warning"><?= htmlspecialchars($usuariosDbError, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">Nuevo usuario</div>
                            <div class="card-body">
                                <form method="post" action="<?= htmlspecialchars(admin_page_url('usuarios'), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="accion" value="usuario_crear">
                                    <div class="mb-3">
                                        <label for="new_username" class="form-label">Usuario</label>
                                        <input type="text" class="form-control" id="new_username" name="username" required
                                               pattern="[a-z0-9._-]{3,50}" autocomplete="off">
                                        <div class="form-text">Minúsculas, números, punto, guión (3–50).</div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_nombre" class="form-label">Nombre visible</label>
                                        <input type="text" class="form-control" id="new_nombre" name="nombre" required maxlength="100">
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_rol" class="form-label">Rol</label>
                                        <select class="form-select js-rol-select" id="new_rol" name="rol">
                                            <option value="admin">Administración (operativo)</option>
                                            <option value="superadmin">Superadmin (completo)</option>
                                            <option value="secretaria">Secretaría (solo documentación)</option>
                                            <option value="directivo">Directivo (estado alumnos + control usuarios)</option>
                                        </select>
                                    </div>
                                    <?php if ($institucionUnica): ?>
                                        <div class="mb-3 js-institucion-field" hidden>
                                            <div class="alert alert-info py-2 mb-0">
                                                Acceso a toda Jardin de Infantes La Milagrosa: SCI, SRI, SVI y NUI.
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="mb-3 js-escuela-field" id="new_escuela_wrap" hidden>
                                            <label for="new_escuela_codigo" class="form-label">Escuela a administrar</label>
                                            <select class="form-select" id="new_escuela_codigo" name="escuela_codigo">
                                                <option value="">Seleccionar escuela</option>
                                                <?php foreach ($escuelasUsuario as $codigo => $nombreEscuela): ?>
                                                    <option value="<?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($nombreEscuela . ' (' . $codigo . ')', ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">Contraseña</label>
                                        <input type="password" class="form-control" id="new_password" name="password" required minlength="8" autocomplete="new-password">
                                    </div>
                                    <button type="submit" class="btn btn-primary">Crear usuario</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">Usuarios del sistema</div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-sm">
                                        <thead>
                                            <tr>
                                                <th>Usuario</th>
                                                <th>Nombre</th>
                                                <th>Rol</th>
                                                <th>Escuela</th>
                                                <th>Estado</th>
                                                <th>Último acceso</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($usuarios as $u): ?>
                                                <?php
                                                if (($u['rol'] ?? '') === 'superadmin') {
                                                    $badgeClass = 'bg-dark';
                                                } elseif (($u['rol'] ?? '') === 'secretaria') {
                                                    $badgeClass = 'bg-info text-dark';
                                                } elseif (($u['rol'] ?? '') === 'directivo') {
                                                    $badgeClass = 'bg-warning text-dark';
                                                } else {
                                                    $badgeClass = 'bg-primary';
                                                }
                                                ?>
                                                <tr>
                                                    <td><?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string)$u['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td>
                                                        <span class="badge <?= $badgeClass ?>">
                                                            <?= htmlspecialchars((string)$u['rol'], ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    </td>
                                                    <td class="small">
                                                        <?php if ($institucionUnica && in_array($u['rol'], ['secretaria', 'directivo'], true)): ?>
                                                            Toda Jardin de Infantes La Milagrosa
                                                        <?php elseif (in_array($u['rol'], ['secretaria', 'directivo'], true) && !empty($u['escuela_codigo'])): ?>
                                                            <?= htmlspecialchars(
                                                                ($escuelasUsuario[$u['escuela_codigo']] ?? $u['escuela_codigo']) . ' (' . $u['escuela_codigo'] . ')',
                                                                ENT_QUOTES,
                                                                'UTF-8'
                                                            ) ?>
                                                        <?php else: ?>
                                                            —
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= (int)$u['activo'] === 1
                                                            ? '<span class="badge bg-success">Activo</span>'
                                                            : '<span class="badge bg-secondary">Inactivo</span>' ?>
                                                    </td>
                                                    <td class="small"><?= htmlspecialchars((string)($u['ultimo_login'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                                data-bs-toggle="collapse"
                                                                data-bs-target="#edit-user-<?= (int)$u['id'] ?>">Editar</button>
                                                        <?php if ((int)$u['activo'] === 1 && (int)$u['id'] !== admin_current_user()['id']): ?>
                                                            <form method="post" class="d-inline" action="<?= htmlspecialchars(admin_page_url('usuarios'), ENT_QUOTES, 'UTF-8') ?>"
                                                                  onsubmit="return confirm('¿Desactivar este usuario?');">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="accion" value="usuario_desactivar">
                                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-danger">Desactivar</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <tr class="collapse" id="edit-user-<?= (int)$u['id'] ?>">
                                                    <td colspan="7">
                                                        <form method="post" class="row g-2 p-2 bg-light rounded" action="<?= htmlspecialchars(admin_page_url('usuarios'), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="accion" value="usuario_actualizar">
                                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                            <div class="col-md-3">
                                                                <label class="form-label">Nombre</label>
                                                                <input type="text" class="form-control form-control-sm" name="nombre"
                                                                       value="<?= htmlspecialchars((string)$u['nombre'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                            </div>
                                                            <div class="col-md-2">
                                                                <label class="form-label">Rol</label>
                                                                <select class="form-select form-select-sm js-rol-select" name="rol">
                                                                    <option value="admin" <?= $u['rol'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                                                    <option value="superadmin" <?= $u['rol'] === 'superadmin' ? 'selected' : '' ?>>superadmin</option>
                                                                    <option value="secretaria" <?= $u['rol'] === 'secretaria' ? 'selected' : '' ?>>secretaria</option>
                                                                    <option value="directivo" <?= $u['rol'] === 'directivo' ? 'selected' : '' ?>>directivo</option>
                                                                </select>
                                                            </div>
                                                            <?php if ($institucionUnica): ?>
                                                                <div class="col-md-3 js-institucion-field" <?= in_array($u['rol'], ['secretaria', 'directivo'], true) ? '' : 'hidden' ?>>
                                                                    <label class="form-label">Alcance</label>
                                                                    <div class="form-control form-control-sm bg-light">Toda Jardin de Infantes La Milagrosa</div>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="col-md-3 js-escuela-field" <?= in_array($u['rol'], ['secretaria', 'directivo'], true) ? '' : 'hidden' ?>>
                                                                    <label class="form-label">Escuela</label>
                                                                    <select class="form-select form-select-sm" name="escuela_codigo">
                                                                        <option value="">Seleccionar escuela</option>
                                                                        <?php foreach ($escuelasUsuario as $codigo => $nombreEscuela): ?>
                                                                            <option value="<?= htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') ?>"
                                                                                <?= ($u['escuela_codigo'] ?? '') === $codigo ? 'selected' : '' ?>>
                                                                                <?= htmlspecialchars($nombreEscuela . ' (' . $codigo . ')', ENT_QUOTES, 'UTF-8') ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                            <?php endif; ?>
                                                            <div class="col-md-2">
                                                                <label class="form-label">Nueva contraseña</label>
                                                                <input type="password" class="form-control form-control-sm" name="password"
                                                                       placeholder="Opcional" minlength="8" autocomplete="new-password">
                                                            </div>
                                                            <div class="col-md-2 d-flex align-items-end">
                                                                <div class="form-check">
                                                                    <input class="form-check-input" type="checkbox" name="activo" id="activo-<?= (int)$u['id'] ?>"
                                                                           <?= (int)$u['activo'] === 1 ? 'checked' : '' ?>>
                                                                    <label class="form-check-label" for="activo-<?= (int)$u['id'] ?>">Activo</label>
                                                                </div>
                                                            </div>
                                                            <div class="col-12">
                                                                <button type="submit" class="btn btn-sm btn-success">Guardar cambios</button>
                                                            </div>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="small text-muted mb-0">
                                    <strong>admin</strong>: comunicados, informes, emails, talones, sugerencias, auditoría del panel y auditoría de la app.
                                    <strong>superadmin</strong>: además actualizaciones, configuración y usuarios.
                                    <strong>secretaria</strong>: acceso a todos los cursos de Jardin de Infantes La Milagrosa desde <code>admin/estado_alumno.php</code>.
                                    <strong>directivo</strong>: mismo panel <code>admin/estado_alumno.php</code> que secretaría, con la pestaña adicional <strong>Control de Usuarios</strong> (las secretarías no la ven).
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                document.querySelectorAll('.js-rol-select').forEach(function (select) {
                    function syncEscuelaField() {
                        const form = select.closest('form');
                        if (!form) {
                            return;
                        }
                        const escuelaWrap = form.querySelector('.js-escuela-field');
                        const institucionWrap = form.querySelector('.js-institucion-field');
                        const needsEscuela = select.value === 'secretaria' || select.value === 'directivo';
                        if (escuelaWrap) {
                            escuelaWrap.hidden = !needsEscuela;
                            const escuelaSelect = escuelaWrap.querySelector('select[name="escuela_codigo"]');
                            if (escuelaSelect) {
                                escuelaSelect.required = needsEscuela;
                            }
                        }
                        if (institucionWrap) {
                            institucionWrap.hidden = !needsEscuela;
                        }
                    }
                    select.addEventListener('change', syncEscuelaField);
                    syncEscuelaField();
                });
                </script>
            </div>
