<?php
/**
 * Solapa Estado de cuenta — consulta financiera de alumnos (secretaría).
 *
 * Variables esperadas: $escuelaActiva, $escuelas, $cursosEscuelaActiva, $filtrosCuenta,
 * $cuentasData, $cuentasCargadas, $ultimaActualizacion, $ambitoCuentasComplejo
 */
$h = 'estado_alumno_h';
$mesSeleccionado = (int)$filtrosCuenta['mes'];
$cursoFiltro = (string)$filtrosCuenta['curso'];
$buscar = (string)$filtrosCuenta['buscar'];
$estadoFiltro = (string)$filtrosCuenta['estado'];
$becaFiltro = (string)$filtrosCuenta['beca'];
$becaPctFiltro = (string)$filtrosCuenta['beca_pct'];
$alumnosCuenta = $cuentasData['alumnos'] ?? [];
$resumenCuenta = $cuentasData['resumen'] ?? ['total' => 0, 'al_dia' => 0, 'con_deuda' => 0, 'becados' => 0];
$nombreMesCuenta = (string)($cuentasData['nombre_mes'] ?? '');
$errorCuenta = (string)($cuentasData['error'] ?? '');
$mostrarColEscuela = !empty($ambitoCuentasComplejo) || !empty($cuentasData['ambito_complejo']);
$ambitoLabel = $mostrarColEscuela
    ? 'todo el complejo'
    : ($escuelas[$escuelaActiva] ?? $escuelaActiva);
?>
<form method="get" class="filtros cuentas-form" id="cuentasForm">
    <input type="hidden" name="vista" value="cuentas">
    <input type="hidden" name="escuela" value="<?php echo $h($escuelaActiva); ?>">

    <div class="cuentas-filtros-principales">
        <div class="campo">
            <label for="mes" class="form-label">Mes de corte <span class="text-danger">*</span></label>
            <select name="mes" id="mes" class="form-select" required>
                <option value="" <?php echo $mesSeleccionado < 1 ? 'selected' : ''; ?>>Seleccionar mes</option>
                <?php for ($i = 1; $i <= 12; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo $i === $mesSeleccionado ? 'selected' : ''; ?>>
                        <?php echo $i . ' - ' . $h(admin_cuota_nombre_mes($i)); ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="campo">
            <label for="curso_cuenta" class="form-label">Curso</label>
            <select name="curso" id="curso_cuenta" class="form-select">
                <option value="" <?php echo $cursoFiltro === '' ? 'selected' : ''; ?>>Todos los cursos</option>
                <?php foreach ($cursosEscuelaActiva as $curso): ?>
                    <?php $cursoValor = mb_strtoupper(trim((string)$curso), 'UTF-8'); ?>
                    <option value="<?php echo $h($cursoValor); ?>" <?php echo $cursoFiltro === $cursoValor ? 'selected' : ''; ?>>
                        <?php echo $h($cursoValor); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo">
            <label for="estado_cuenta" class="form-label">Estado de pago</label>
            <select name="estado" id="estado_cuenta" class="form-select">
                <option value="" <?php echo $estadoFiltro === '' ? 'selected' : ''; ?>>Todos</option>
                <option value="al_dia" <?php echo $estadoFiltro === 'al_dia' ? 'selected' : ''; ?>>Solo al día</option>
                <option value="con_deuda" <?php echo $estadoFiltro === 'con_deuda' ? 'selected' : ''; ?>>Solo con deuda</option>
            </select>
        </div>

        <div class="campo">
            <label for="beca" class="form-label">Beca</label>
            <select name="beca" id="beca" class="form-select">
                <option value="" <?php echo $becaFiltro === '' ? 'selected' : ''; ?>>Todas</option>
                <option value="con_beca" <?php echo $becaFiltro === 'con_beca' ? 'selected' : ''; ?>>Con beca</option>
                <option value="sin_beca" <?php echo $becaFiltro === 'sin_beca' ? 'selected' : ''; ?>>Sin beca</option>
            </select>
        </div>

        <div class="campo">
            <label for="beca_pct" class="form-label">Porcentaje de beca</label>
            <select name="beca_pct" id="beca_pct" class="form-select">
                <option value="" <?php echo $becaPctFiltro === '' ? 'selected' : ''; ?>>Cualquiera</option>
                <option value="25" <?php echo $becaPctFiltro === '25' ? 'selected' : ''; ?>>25%</option>
                <option value="50" <?php echo $becaPctFiltro === '50' ? 'selected' : ''; ?>>50%</option>
                <option value="75" <?php echo $becaPctFiltro === '75' ? 'selected' : ''; ?>>75%</option>
                <option value="100" <?php echo $becaPctFiltro === '100' ? 'selected' : ''; ?>>100%</option>
            </select>
        </div>
    </div>

    <div class="cuentas-filtros-buscar">
        <div class="campo campo-buscar">
            <label for="buscar" class="form-label">Buscar (apellido, nombre, legajo, DNI)</label>
            <input
                type="text"
                class="form-control"
                name="buscar"
                id="buscar"
                value="<?php echo $h($buscar); ?>"
                placeholder="Ej: GARCÍA, 10144/01, 45678901"
            >
        </div>

        <div class="campo campo-consultar">
            <button type="submit" class="btn btn-primary btn-consultar" id="btnConsultarCuentas">
                <span class="spinner btn-spinner" aria-hidden="true"></span>
                <span class="btn-consultar-label">Consultar</span>
            </button>
        </div>
    </div>
</form>

<?php if ($errorCuenta !== ''): ?>
    <div class="alert alert-danger"><?php echo $h($errorCuenta); ?></div>
<?php elseif (!$cuentasCargadas): ?>
    <div class="no-data">
        Seleccioná el <strong>mes de corte</strong> y presioná <strong>Consultar</strong>
        para ver el estado de cuenta de <?php echo $h($ambitoLabel); ?>.
        Curso, estado de pago, beca y búsqueda son opcionales.
    </div>
<?php elseif ((int)$resumenCuenta['total'] === 0): ?>
    <div class="no-data">
        No se encontraron alumnos para los filtros seleccionados. Probá ajustar el curso, la búsqueda o los filtros de deuda/beca.
    </div>
<?php else: ?>
    <div class="resumen-wrapper">
        <div class="resumen">
            <div class="resumen-text">
                Corte al mes de <span class="resumen-highlight"><?php echo $h($nombreMesCuenta); ?></span>
                · Ámbito: <span class="resumen-highlight"><?php echo $h($ambitoLabel); ?></span>.
                Alumnos mostrados: <span class="resumen-highlight"><?php echo (int)$resumenCuenta['total']; ?></span>.
                <?php if ($ultimaActualizacion !== ''): ?>
                    <span class="resumen-meta">Datos actualizados: <?php echo $h($ultimaActualizacion); ?></span>
                <?php endif; ?>
            </div>
            <div class="resumen-badges">
                <div class="badge-pill badge-success">Al día: <?php echo (int)$resumenCuenta['al_dia']; ?></div>
                <div class="badge-pill badge-danger">Con deuda: <?php echo (int)$resumenCuenta['con_deuda']; ?></div>
                <div class="badge-pill badge-info">Con beca: <?php echo (int)$resumenCuenta['becados']; ?></div>
            </div>
        </div>
    </div>

    <div class="alumnos-card cuentas-card">
        <div class="alumnos-card-header">
            <h2 class="alumnos-card-title">Estado de cuenta por alumno</h2>
            <span class="estado-contrato-pill">Los pagos pueden demorar 24–48 hs en reflejarse</span>
        </div>

        <div class="table-responsive">
        <table class="table table-striped table-sm mb-0 js-sortable-table">
            <thead>
                <tr>
                    <th class="col-numero">Nº</th>
                    <th>Apellido y nombre</th>
                    <th>Legajo</th>
                    <th>DNI</th>
                    <th>Curso</th>
                    <th>Estado</th>
                    <th data-sort-type="number">Deuda</th>
                    <th>Meses adeudados</th>
                    <th>Beca</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alumnosCuenta as $indice => $alumno): ?>
                    <?php
                        $alDia = !empty($alumno['al_dia']);
                        $deuda = (float)($alumno['deuda_hasta_mes'] ?? 0);
                        $pctBeca = (int)($alumno['porcentaje_descuento'] ?? 0);
                        $esInactivo = !empty($alumno['es_inactivo']);
                        $rowClass = $alDia ? 'row-al-dia' : 'row-debe';
                        if ($esInactivo) {
                            $rowClass = 'row-inactivo';
                        }
                        $pm = $alumno['primer_mes_impago'] ?? null;
                        $um = $alumno['ultimo_mes_impago'] ?? null;
                        $mesesTxt = admin_cuota_formato_meses_impagos(
                            $alumno['meses_impagos'] ?? [],
                            $pm,
                            $um
                        );
                    ?>
                    <tr class="<?php echo $h($rowClass); ?>">
                        <td class="col-numero"><?php echo (int)$indice + 1; ?></td>
                        <td><?php echo $h(trim(($alumno['apellido_alumno'] ?? '') . ', ' . ($alumno['nombre_alumno'] ?? ''), ', ')); ?></td>
                        <td>
                            <?php echo $h($alumno['nro_legajo'] ?? ''); ?>
                            <?php if ($esInactivo): ?>
                                <span class="chip-inactivo" style="margin-left:4px;">INACTIVO</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $h($alumno['dni_alumno'] ?? ''); ?></td>
                        <td>
                            <?php if ($esInactivo): ?>
                                <span class="chip-inactivo">INACTIVO / BAJA</span>
                            <?php else: ?>
                                <?php echo $h($alumno['curso'] ?? ''); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($alDia && $esInactivo): ?>
                                <span class="chip-al-dia">Inactivo al día</span>
                            <?php elseif ($alDia): ?>
                                <span class="chip-al-dia">Al día</span>
                            <?php elseif ($esInactivo): ?>
                                <span class="chip-deuda">Deudor (inactivo)</span>
                            <?php else: ?>
                                <span class="chip-deuda">Con deuda</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-monto" data-sort-value="<?php echo $alDia ? '0' : (string)$deuda; ?>">
                            <?php if ($alDia): ?>
                                <span class="monto-al-dia">—</span>
                            <?php else: ?>
                                <span class="monto-deuda">$ <?php echo $h(estado_alumno_fmt_monto($deuda)); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="col-meses">
                            <?php if (!$alDia && $mesesTxt !== ''): ?>
                                <?php echo $h($mesesTxt); ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($pctBeca > 0): ?>
                                <span class="chip-beca"><?php echo (int)$pctBeca; ?>%</span>
                            <?php else: ?>
                                <span class="text-muted">Sin beca</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
