<?php
$rcH = static function ($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
};

$rcSoloErroneos = isset($_GET['solo_erroneos']) && (string)$_GET['solo_erroneos'] === '1';
$rcCurso = mb_strtoupper(trim((string)($_GET['curso'] ?? '')), 'UTF-8');
$rcBuscar = trim((string)($_GET['buscar'] ?? ''));
$rcFormAction = admin_page_url('revision-contratos');
$rcLoadError = '';
$rcFilas = [];
$rcTotal = 0;
$rcTotalErroneos = 0;

require_once __DIR__ . '/../../includes/estado_alumno_lib.php';
require_once __DIR__ . '/../../includes/db_collate.php';

try {
    $connRc = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    admin_mysqli_apply_collation($connRc);
    estado_alumno_ensure_contratos_schema($connRc);
    $rcFilas = estado_alumno_cargar_revision_contratos_admin($connRc, $rcSoloErroneos, $rcCurso, $rcBuscar);
    $connRc->close();
} catch (Throwable $e) {
    error_log('admin revision-contratos: ' . $e->getMessage());
    $rcLoadError = 'No se pudieron cargar los contratos. Intente nuevamente.';
    $rcFilas = [];
}

$rcTotal = count($rcFilas);
foreach ($rcFilas as $filaRc) {
    if ((int)($filaRc['info_erronea'] ?? 0) === 1) {
        $rcTotalErroneos++;
    }
}
?>
            <div class="tab-pane fade show active" id="revision-contratos" role="tabpanel">
                <div class="card mt-3 revision-contratos-admin">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Revisión de contratos firmados</span>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-secondary">Total: <?= (int)$rcTotal ?></span>
                            <span class="badge bg-danger">Info. errónea: <?= (int)$rcTotalErroneos ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Misma información que en Estado Alumnos → Revisión contratos.
                            Solo desde esta solapa se pueden eliminar contratos marcados como info errónea para que la familia vuelva a firmar.
                        </p>

                        <form method="get" class="row g-3 filtros mb-3" action="<?= $rcH($rcFormAction) ?>">
                            <input type="hidden" name="grupo" value="revision-contratos">
                            <input type="hidden" name="tab" value="revision-contratos">
                            <div class="col-auto">
                                <input
                                    type="text"
                                    name="curso"
                                    class="form-control"
                                    placeholder="Curso (ej. 4AET)"
                                    value="<?= $rcH($rcCurso) ?>"
                                >
                            </div>
                            <div class="col-auto">
                                <input
                                    type="text"
                                    name="buscar"
                                    class="form-control"
                                    placeholder="Alumno, DNI, legajo o familia"
                                    value="<?= $rcH($rcBuscar) ?>"
                                >
                            </div>
                            <div class="col-auto d-flex align-items-center">
                                <div class="form-check">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="solo_erroneos"
                                        value="1"
                                        id="rc_solo_erroneos"
                                        <?= $rcSoloErroneos ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="rc_solo_erroneos">
                                        Solo contratos con info errónea
                                    </label>
                                </div>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Filtrar</button>
                                <a href="<?= $rcH(admin_page_url('revision-contratos')) ?>" class="btn btn-secondary">Limpiar</a>
                            </div>
                        </form>

                        <?php if ($rcLoadError !== ''): ?>
                            <div class="alert alert-danger"><?= $rcH($rcLoadError) ?></div>
                        <?php elseif ($rcTotal === 0): ?>
                            <div class="alert alert-info mb-0">
                                <?= $rcSoloErroneos
                                    ? 'No hay contratos marcados como info errónea con los filtros actuales.'
                                    : 'No hay contratos firmados activos con los filtros actuales.' ?>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Estado</th>
                                            <th>Curso</th>
                                            <th>Alumno</th>
                                            <th>Legajo</th>
                                            <th>Familia</th>
                                            <th>Firmante</th>
                                            <th>DNI</th>
                                            <th>Domicilio</th>
                                            <th>Localidad</th>
                                            <th>Versión</th>
                                            <th>Firmado</th>
                                            <th class="text-center">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rcFilas as $fila): ?>
                                            <?php
                                                $esErronea = (int)($fila['info_erronea'] ?? 0) === 1;
                                                $alumnoNom = trim(($fila['apellido_alumno'] ?? '') . ', ' . ($fila['nombre_alumno'] ?? ''), ', ');
                                                $rowClass = $esErronea ? 'table-danger' : '';
                                            ?>
                                            <tr class="<?= $rcH($rowClass) ?>">
                                                <td>
                                                    <?php if ($esErronea): ?>
                                                        <span class="rc-info-erronea-label">INFO. ERRONEA</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">OK</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= $rcH($fila['curso'] ?? '') ?></td>
                                                <td><?= $rcH($alumnoNom) ?></td>
                                                <td><?= $rcH($fila['nro_legajo'] ?? '') ?></td>
                                                <td><?= $rcH($fila['nro_familia'] ?? '') ?></td>
                                                <td><?= $rcH($fila['firmante_nombre'] ?: '—') ?></td>
                                                <td><?= $rcH($fila['firmante_dni'] ?: '—') ?></td>
                                                <td><?= $rcH($fila['firmante_domicilio'] ?: '—') ?></td>
                                                <td><?= $rcH($fila['firmante_localidad'] ?: '—') ?></td>
                                                <td><small><?= $rcH($fila['contract_version'] ?? '') ?></small></td>
                                                <td><small><?= $rcH($fila['accepted_at'] ?? '') ?></small></td>
                                                <td class="text-center">
                                                    <?php if ($esErronea): ?>
                                                        <form
                                                            method="post"
                                                            class="d-inline"
                                                            onsubmit="return confirm('¿Eliminar este contrato erróneo? La familia podrá volver a firmar.');"
                                                        >
                                                            <input type="hidden" name="csrf_token" value="<?= $rcH($csrf_token) ?>">
                                                            <input type="hidden" name="accion" value="eliminar_contrato_erroneo">
                                                            <input type="hidden" name="contrato_id" value="<?= (int)($fila['contrato_id'] ?? 0) ?>">
                                                            <input type="hidden" name="solo_erroneos" value="<?= $rcSoloErroneos ? '1' : '0' ?>">
                                                            <input type="hidden" name="curso" value="<?= $rcH($rcCurso) ?>">
                                                            <input type="hidden" name="buscar" value="<?= $rcH($rcBuscar) ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger">
                                                                Eliminar
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
