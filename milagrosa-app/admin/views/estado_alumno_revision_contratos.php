<form method="get" class="filtros curso-form" id="cursoFormRevision">
    <input type="hidden" name="vista" value="revision_contratos">
    <input type="hidden" name="escuela" value="<?php echo estado_alumno_h($escuelaActiva); ?>">

    <div class="campo campo-buscar">
        <label for="buscar_revision" class="form-label">Buscar (apellido, nombre, legajo, DNI)</label>
        <input
            type="text"
            class="form-control"
            name="buscar"
            id="buscar_revision"
            value="<?php echo estado_alumno_h($buscarContratos); ?>"
            placeholder="Ej: GARCÍA, 10144/01, 45678901"
        >
    </div>

    <div class="campo">
        <button type="submit" class="btn btn-primary btn-consultar">
            <span class="spinner btn-spinner" aria-hidden="true"></span>
            <span class="btn-consultar-label">Cargar alumnos</span>
        </button>
    </div>
</form>

<?php if ($errorCargaRevision !== ''): ?>
    <div class="alert alert-danger"><?php echo estado_alumno_h($errorCargaRevision); ?></div>
<?php elseif ($totalAlumnosRevision === 0): ?>
    <div class="no-data">
        <?php if ($buscarContratos !== ''): ?>
            No se encontraron alumnos para la búsqueda en <?php echo estado_alumno_h($escuelas[$escuelaActiva]); ?>.
        <?php else: ?>
            No hay alumnos activos en <?php echo estado_alumno_h($escuelas[$escuelaActiva]); ?>.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="resumen-wrapper">
        <div class="resumen">
            <div class="resumen-text">
                <span class="resumen-highlight"><?php echo estado_alumno_h($escuelas[$escuelaActiva]); ?></span>.
                Alumnos activos: <span class="resumen-highlight"><?php echo $totalAlumnosRevision; ?></span>.
            </div>
            <div class="resumen-badges">
                <div class="badge-pill badge-info">Con datos firmados: <?php echo $totalFirmadosRevision; ?></div>
                <div class="badge-pill badge-success">Doc. recibida: <?php echo (int)$totalAprobadosRevision; ?></div>
                <div class="badge-pill badge-danger">Sin firma: <?php echo max(0, $totalAlumnosRevision - $totalFirmadosRevision); ?></div>
                <?php if (!empty($totalInfoErroneaRevision)): ?>
                    <div class="badge-pill badge-danger">Info. errónea: <?php echo (int)$totalInfoErroneaRevision; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="alumnos-card" data-curso="<?php echo estado_alumno_h($cursoSeleccionado); ?>" data-escuela="<?php echo estado_alumno_h($escuelaActiva); ?>">
        <div class="alumnos-card-header">
            <h2 class="alumnos-card-title">Revisión de datos del contrato</h2>
            <div class="alumnos-card-actions">
                <span class="estado-contrato-pill">Marcá Doc. recibida cuando la documentación haya sido presentada</span>
                <div class="alumnos-bulk-actions">
                    <button type="button" class="btn btn-success js-bulk-admin" data-admin-aprobado="1">
                        Marcar todo
                    </button>
                    <button type="button" class="btn btn-primary js-bulk-admin" data-admin-aprobado="0">
                        Desmarcar todo
                    </button>
                </div>
            </div>
        </div>

        <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th class="col-numero">Nº</th>
                    <th>Estado</th>
                    <th>Alumno (apellido y nombre)</th>
                    <?php if ($cursoSeleccionado === ''): ?>
                        <th>Curso</th>
                    <?php endif; ?>
                    <th>Nombre y apellido Firmante</th>
                    <th>DNI</th>
                    <th>Domicilio</th>
                    <th>Localidad</th>
                    <th>Doc. recibida</th>
                    <th class="text-center">Info. errónea</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alumnosRevision as $indiceAlumno => $alumno): ?>
                    <?php
                        $firmado = !empty($alumno['contrato_id']);
                        $tieneDatos = $firmado && trim((string)($alumno['firmante_nombre'] ?? '')) !== '';
                        $aprobado = ((int)($alumno['admin_aprobado'] ?? 0) === 1);
                        $infoErronea = $firmado && (int)($alumno['info_erronea'] ?? 0) === 1;
                        if ($infoErronea) {
                            $rowClass = 'row-info-erronea';
                        } elseif ($tieneDatos) {
                            $rowClass = 'row-aprobado';
                        } elseif ($firmado) {
                            $rowClass = 'row-pendiente-aprobacion';
                        } else {
                            $rowClass = '';
                        }
                        $alumnoNombre = trim(($alumno['apellido_alumno'] ?? '') . ', ' . ($alumno['nombre_alumno'] ?? ''), ', ');
                    ?>
                    <tr class="<?php echo estado_alumno_h($rowClass); ?>" data-student-dni="<?php echo estado_alumno_h($alumno['dni_alumno']); ?>">
                        <td class="col-numero"><?php echo (int)$indiceAlumno + 1; ?></td>
                        <td>
                            <?php if ($infoErronea): ?>
                                <span class="info-erronea-label">INFO. ERRONEA</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo estado_alumno_h($alumnoNombre); ?></td>
                        <?php if ($cursoSeleccionado === ''): ?>
                            <td><?php echo estado_alumno_h($alumno['curso'] ?? ''); ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if ($tieneDatos): ?>
                                <?php echo estado_alumno_h($alumno['firmante_nombre'] ?? ''); ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tieneDatos): ?>
                                <?php echo estado_alumno_h($alumno['firmante_dni'] ?? ''); ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tieneDatos): ?>
                                <?php echo estado_alumno_h($alumno['firmante_domicilio'] ?? ''); ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($tieneDatos): ?>
                                <?php echo estado_alumno_h($alumno['firmante_localidad'] ?? ''); ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input
                                type="checkbox"
                                class="admin-check js-admin-aprobado"
                                data-student-dni="<?php echo estado_alumno_h($alumno['dni_alumno']); ?>"
                                <?php echo $aprobado ? 'checked' : ''; ?>
                                aria-label="Documentación recibida para <?php echo estado_alumno_h($alumnoNombre); ?>"
                            >
                        </td>
                        <td class="text-center">
                            <input
                                type="checkbox"
                                class="admin-check js-info-erronea"
                                data-student-dni="<?php echo estado_alumno_h($alumno['dni_alumno']); ?>"
                                <?php echo $infoErronea ? 'checked' : ''; ?>
                                <?php echo $firmado ? '' : 'disabled'; ?>
                                aria-label="Marcar info errónea de firma para <?php echo estado_alumno_h($alumnoNombre); ?>"
                            >
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
