<?php
/**
 * Solapa Bolsa de trabajo — currículums enviados.
 *
 * Variables esperadas: $bolsaData, $bolsaBuscar, $bolsaAreaFiltro
 */
$h = 'estado_alumno_h';
$curriculums = $bolsaData['curriculums'] ?? [];
$totalBolsa = (int)($bolsaData['total'] ?? 0);
$errorBolsa = (string)($bolsaData['error'] ?? '');
$areasBolsa = estado_alumno_curriculum_areas_catalog();
?>
<form method="get" class="filtros bolsa-form" id="bolsaForm">
    <input type="hidden" name="vista" value="bolsa">
    <input type="hidden" name="escuela" value="<?php echo $h($escuelaActiva); ?>">

    <div class="campo">
        <label for="area_bolsa" class="form-label">Área</label>
        <select name="area_bolsa" id="area_bolsa" class="form-select">
            <option value="" <?php echo $bolsaAreaFiltro === '' ? 'selected' : ''; ?>>Todas las áreas</option>
            <?php foreach ($areasBolsa as $codigo => $etiqueta): ?>
                <option value="<?php echo $h($codigo); ?>" <?php echo $bolsaAreaFiltro === $codigo ? 'selected' : ''; ?>>
                    <?php echo $h($etiqueta); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo campo-buscar">
        <label for="buscar_bolsa" class="form-label">Buscar (nombre, email, teléfono)</label>
        <input
            type="text"
            class="form-control"
            name="buscar_bolsa"
            id="buscar_bolsa"
            value="<?php echo $h($bolsaBuscar); ?>"
            placeholder="Ej: García, ejemplo@mail.com"
        >
    </div>

    <div class="campo">
        <button type="submit" class="btn btn-primary btn-consultar">
            <span class="spinner btn-spinner" aria-hidden="true"></span>
            <span class="btn-consultar-label">Buscar</span>
        </button>
    </div>
</form>

<?php if ($errorBolsa !== ''): ?>
    <div class="alert alert-danger"><?php echo $h($errorBolsa); ?></div>
<?php elseif ($totalBolsa === 0): ?>
    <div class="no-data">
        <?php if ($bolsaBuscar !== '' || $bolsaAreaFiltro !== ''): ?>
            No se encontraron currículums para los filtros seleccionados.
        <?php else: ?>
            Todavía no hay currículums cargados en la bolsa de trabajo.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="resumen-wrapper">
        <div class="resumen">
            <div class="resumen-text">
                Currículums en bolsa de trabajo:
                <span class="resumen-highlight"><?php echo $totalBolsa; ?></span>
                <?php if ($bolsaAreaFiltro !== ''): ?>
                    <span class="resumen-meta">Área: <?php echo $h(curriculum_area_etiqueta($bolsaAreaFiltro)); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="alumnos-card bolsa-card">
        <div class="alumnos-card-header">
            <h2 class="alumnos-card-title">Postulantes</h2>
        </div>

        <div class="table-responsive">
        <table class="table table-striped table-sm mb-0 js-sortable-table">
            <thead>
                <tr>
                    <th class="col-numero">Nº</th>
                    <th>Nombre y apellido</th>
                    <th>Área</th>
                    <th>Email</th>
                    <th>Duplencias</th>
                    <th>Teléfono</th>
                    <th data-sort-type="text">Fecha de envío</th>
                    <th class="col-accion" data-sortable="false">CV</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($curriculums as $indice => $cv): ?>
                    <tr class="bolsa-row">
                        <td class="col-numero"><?php echo (int)$indice + 1; ?></td>
                        <td><strong><?php echo $h($cv['nombre_apellido'] ?? ''); ?></strong></td>
                        <td><span class="chip-area"><?php echo $h($cv['area_etiqueta'] ?? curriculum_area_etiqueta((string)($cv['area'] ?? 'otros'))); ?></span></td>
                        <td data-sort-value="<?php echo $h((string)($cv['email'] ?? '')); ?>">
                            <?php if (($cv['email'] ?? '') !== ''): ?>
                                <a class="bolsa-email" href="mailto:<?php echo $h($cv['email']); ?>"><?php echo $h($cv['email']); ?></a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-sort-value="<?php echo $h((string)($cv['duplencias'] ?? 'NO')); ?>">
                            <?php $duplencias = curriculum_normalizar_duplencias($cv['duplencias'] ?? 'NO'); ?>
                            <span class="chip-duplencias chip-duplencias--<?php echo strtolower($duplencias); ?>"><?php echo $h($duplencias); ?></span>
                        </td>
                        <td><?php echo ($cv['telefono'] ?? '') !== '' ? $h($cv['telefono']) : '—'; ?></td>
                        <td data-sort-value="<?php echo $h((string)($cv['fecha_subida'] ?? '')); ?>"><?php echo $h(estado_alumno_fmt_fecha($cv['fecha_subida'] ?? null)); ?></td>
                        <td class="col-accion">
                            <?php if (!empty($cv['cv_disponible'])): ?>
                                <a
                                    class="btn btn-success btn-sm"
                                    href="descargar_curriculum_cv.php?id=<?php echo (int)($cv['id'] ?? 0); ?>"
                                >Descargar CV</a>
                            <?php else: ?>
                                <span class="chip-sin-archivo" title="El archivo no está disponible en el servidor">No disponible</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
