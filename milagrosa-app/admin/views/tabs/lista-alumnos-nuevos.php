<?php
require_once __DIR__ . '/../../includes/alta_alumnos_nuevos_lib.php';
$aanLista = admin_aan_fetch_todos($pdo);
$aanH = static function ($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
            <div class="tab-pane fade show active" id="lista-alumnos-nuevos" role="tabpanel">
                <div class="alumnos-nuevos-admin alumnos-nuevos-lista">
                    <div class="aan-lista-shell">
                        <h2 class="aan-lista-title">Lista completa de alumnos nuevos</h2>
                        <table class="table table-bordered align-middle">
                            <thead class="table-primary">
                                <tr>
                                    <th>ID</th>
                                    <th>Alumno</th>
                                    <th>Responsable 1</th>
                                    <th>Fecha registro</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($aanLista === []): ?>
                                    <tr><td colspan="4" class="text-muted text-center">No hay alumnos cargados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($aanLista as $r): ?>
                                        <tr>
                                            <td><strong><?= (int)$r['id'] ?></strong></td>
                                            <td>
                                                <div class="alumno-info">
                                                    <?= $aanH($r['apellido_alumno']) ?> <?= $aanH($r['nombre_alumno']) ?><br>
                                                    <small><b>DNI:</b> <?= $aanH($r['nro_documento_alumno']) ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="resp1-info">
                                                    <?= $aanH($r['nombre_madre']) ?> <?= $aanH($r['apellido_alumno']) ?><br>
                                                    <small><b>DNI:</b> <?= $aanH($r['nro_documento_madre']) ?> |
                                                    <b>Email:</b> <?= $aanH($r['email_madre']) ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="fecha-info">
                                                    <?php
                                                    $fecha = $r['fecha_registro'] ?? '';
                                                    echo $fecha !== ''
                                                        ? $aanH(date('d/m/Y H:i', strtotime((string)$fecha)))
                                                        : '—';
                                                    ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        <div class="text-center mt-4">
                            <a href="<?= $aanH(admin_page_url('alta-alumnos-nuevos')) ?>" class="btn btn-secondary">Volver al formulario de alta</a>
                        </div>
                    </div>
                </div>
            </div>
