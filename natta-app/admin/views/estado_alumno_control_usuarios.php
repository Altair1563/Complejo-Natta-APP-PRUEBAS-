<?php
/** @var string $escuelaDirectivo */
/** @var string $nombreEscuela */
/** @var array $avance */
/** @var int $pctDoc */
/** @var int $pctFirmados */
/** @var list<array> $secretarias */
/** @var array<int, array> $resumenPorUser */
/** @var list<array> $cursosTrabajados */
/** @var list<array> $actividad */
?>
            <?php if ($escuelaDirectivo !== 'ALL'): ?>
                <?php if ($avance['error'] !== ''): ?>
                    <div class="alert alert-warning"><?php echo control_usuarios_h($avance['error']); ?></div>
                <?php else: ?>
                    <div class="resumen-wrapper">
                        <div class="resumen">
                            <div class="resumen-text">
                                Avance de revisión en
                                <span class="resumen-highlight"><?php echo control_usuarios_h($nombreEscuela); ?></span>.
                                Alumnos activos:
                                <span class="resumen-highlight"><?php echo (int)$avance['total']; ?></span>.
                                <span class="resumen-meta">
                                    Cumplimiento doc. recibida: <?php echo $pctDoc; ?>%
                                    · Contratos firmados: <?php echo $pctFirmados; ?>%
                                </span>
                            </div>
                            <div class="resumen-badges">
                                <div class="badge-pill badge-info">Firmados: <?php echo (int)$avance['firmados']; ?></div>
                                <div class="badge-pill badge-success">Doc. recibida: <?php echo (int)$avance['doc_recibida']; ?></div>
                                <div class="badge-pill badge-danger">Sin doc.: <?php echo (int)$avance['pendientes_doc']; ?></div>
                                <?php if ((int)$avance['info_erronea'] > 0): ?>
                                    <div class="badge-pill badge-danger">Info. errónea: <?php echo (int)$avance['info_erronea']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="alumnos-card mb-3">
                <div class="alumnos-card-header">
                    <h2 class="alumnos-card-title">Secretarías · últimos 14 días</h2>
                </div>
                <?php if ($secretarias === []): ?>
                    <div class="no-data">
                        No hay usuarios de secretaría activos<?php
                            echo $escuelaDirectivo !== 'ALL' ? ' para esta escuela.' : '.';
                        ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <?php if ($escuelaDirectivo === 'ALL'): ?>
                                        <th>Escuela</th>
                                    <?php endif; ?>
                                    <th>Estados de cuenta</th>
                                    <th>Revisión contratos</th>
                                    <th>Doc. recibida</th>
                                    <th>Info. errónea</th>
                                    <th>Último acceso</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($secretarias as $sec): ?>
                                    <?php
                                    $sid = (int)$sec['id'];
                                    $res = $resumenPorUser[$sid] ?? [
                                        'consultas_cuenta' => 0,
                                        'revisiones' => 0,
                                        'doc_recibida' => 0,
                                        'info_erronea' => 0,
                                    ];
                                    $escSec = (string)($sec['escuela_codigo'] ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo control_usuarios_h((string)$sec['nombre']); ?></strong>
                                            <div class="small text-muted"><?php echo control_usuarios_h((string)$sec['username']); ?></div>
                                        </td>
                                        <?php if ($escuelaDirectivo === 'ALL'): ?>
                                            <td><?php echo control_usuarios_h($escSec !== '' ? $escSec : '—'); ?></td>
                                        <?php endif; ?>
                                        <td>
                                            <span class="estado-contrato-pill <?php echo (int)$res['consultas_cuenta'] > 0 ? 'aprobado' : 'pendiente'; ?>">
                                                <?php echo (int)$res['consultas_cuenta']; ?> consultas
                                            </span>
                                        </td>
                                        <td>
                                            <span class="estado-contrato-pill <?php echo (int)$res['revisiones'] > 0 ? 'firmado' : 'pendiente'; ?>">
                                                <?php echo (int)$res['revisiones']; ?> revisiones
                                            </span>
                                        </td>
                                        <td>
                                            <span class="estado-contrato-pill <?php echo (int)$res['doc_recibida'] > 0 ? 'aprobado' : ''; ?>">
                                                <?php echo (int)$res['doc_recibida']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="estado-contrato-pill <?php echo (int)$res['info_erronea'] > 0 ? 'pendiente' : ''; ?>">
                                                <?php echo (int)$res['info_erronea']; ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted"><?php echo control_usuarios_h((string)($sec['ultimo_login'] ?? '—')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted px-3 py-2 mb-0">
                        Contadores de actividad registrada en el panel de secretaría durante los últimos 14 días.
                    </p>
                <?php endif; ?>
            </div>

            <div class="row g-3">
                <div class="col-lg-5">
                    <div class="alumnos-card h-100">
                        <div class="alumnos-card-header">
                            <h2 class="alumnos-card-title">Cursos con actividad reciente</h2>
                        </div>
                        <?php if ($cursosTrabajados === []): ?>
                            <div class="no-data">Todavía no hay cursos registrados en la actividad reciente.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Curso</th>
                                            <th>Acciones</th>
                                            <th>Secretaría</th>
                                            <th>Última</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cursosTrabajados as $cursoItem): ?>
                                            <tr>
                                                <td><strong><?php echo control_usuarios_h($cursoItem['curso']); ?></strong></td>
                                                <td>
                                                    <span class="badge-pill badge-info"><?php echo (int)$cursoItem['veces']; ?></span>
                                                </td>
                                                <td class="small"><?php echo control_usuarios_h(implode(', ', $cursoItem['usuarios'])); ?></td>
                                                <td class="small text-muted"><?php echo control_usuarios_h($cursoItem['ultima']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="alumnos-card h-100">
                        <div class="alumnos-card-header">
                            <h2 class="alumnos-card-title">Actividad reciente</h2>
                        </div>
                        <?php if ($actividad === []): ?>
                            <div class="no-data">
                                Sin actividad registrada aún. Las acciones de secretaría aparecerán acá a medida que trabajen en el panel.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive control-usuarios-actividad">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Usuario</th>
                                            <th>Acción</th>
                                            <th>Detalle</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($actividad as $item): ?>
                                            <?php
                                            $accion = (string)$item['accion'];
                                            $detalle = is_array($item['detalle'] ?? null) ? $item['detalle'] : [];
                                            $pillClass = '';
                                            if ($accion === 'doc_recibida' || $accion === 'bulk_doc_recibida') {
                                                $pillClass = !empty($detalle['valor']) ? 'aprobado' : '';
                                            } elseif ($accion === 'info_erronea') {
                                                $pillClass = !empty($detalle['valor']) ? 'pendiente' : '';
                                            } elseif ($accion === 'consulta_estado_cuenta' || $accion === 'revision_contratos') {
                                                $pillClass = 'firmado';
                                            }
                                            $texto = control_usuarios_texto_detalle($accion, $detalle);
                                            ?>
                                            <tr>
                                                <td class="small text-muted text-nowrap"><?php echo control_usuarios_h((string)$item['created_at']); ?></td>
                                                <td><?php echo control_usuarios_h((string)$item['usuario_nombre']); ?></td>
                                                <td>
                                                    <span class="estado-contrato-pill <?php echo $pillClass; ?>">
                                                        <?php echo control_usuarios_h(control_usuarios_label_accion($accion)); ?>
                                                    </span>
                                                </td>
                                                <td class="small"><?php echo $texto !== '' ? control_usuarios_h($texto) : '—'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
