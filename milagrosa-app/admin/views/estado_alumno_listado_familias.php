<?php
/**
 * Solapa Listado por Familias — consulta de estados de cuenta agrupados por familia.
 *
 * Variables esperadas: $listadoFamiliasData, $listadoFamiliasError, $listadoFamiliasCargado,
 * $escuelaActiva.
 */
$lfH = static function ($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
};

$lfError = (string)($listadoFamiliasError ?? '');
$lfCargado = !empty($listadoFamiliasCargado);
$lfData = is_array($listadoFamiliasData ?? null) ? $listadoFamiliasData : [];

if ($lfError === '' && $lfCargado && $lfData !== []) {
    extract($lfData, EXTR_SKIP);
    $lfCsvQuery = array_filter([
        'mes' => $mesSeleccionado ?? null,
        'estado' => (($estadoFiltro ?? '') !== '') ? $estadoFiltro : null,
        'escuela' => (
            ($escuelaActiva ?? '') !== '' && strtoupper((string)$escuelaActiva) !== 'ALL'
        ) ? $escuelaActiva : null,
        'buscar' => (($buscar ?? '') !== '') ? $buscar : null,
        'lista_json' => (($listaJsonRaw ?? '') !== '') ? $listaJsonRaw : null,
    ], static function ($v) {
        return $v !== null && $v !== '';
    });
    $lfCsvUrl = 'exportar_listado_familias.php?' . http_build_query($lfCsvQuery);
} else {
    $mesSeleccionado = (isset($_GET['mes']) && $_GET['mes'] !== '') ? (int)$_GET['mes'] : 0;
    if ($mesSeleccionado < 1 || $mesSeleccionado > 12) {
        $mesSeleccionado = 0;
    }
    $estadoFiltro = trim((string)($_GET['estado'] ?? ''));
    $buscar = trim((string)($_GET['buscar'] ?? ''));
    $listaJsonRaw = trim((string)($_GET['lista_json'] ?? ''));
    $nombreMesSeleccionado = '';
    $hayFiltros = false;
    $familiasFiltradas = [];
    $totalFamilias = 0;
    $totalFamiliasDeuda = 0;
    $totalFamiliasAlDia = 0;
    $filtrarPorJson = false;
    $legajosMarcados = [];
    $legajosBaja = [];
    $lfCsvUrl = 'exportar_listado_familias.php';
}

$nombreMesFn = static function (int $mes): string {
    if (function_exists('admin_lf_nombreMesCuota')) {
        return (string)admin_lf_nombreMesCuota($mes);
    }
    if (function_exists('admin_cuota_nombre_mes')) {
        return (string)admin_cuota_nombre_mes($mes);
    }

    return (string)$mes;
};
?>
<?php if ($lfError !== ''): ?>
    <div class="alert alert-danger mt-3"><?php echo $lfH($lfError); ?></div>
<?php else: ?>
<div class="listado-familias-admin">
    <div class="page-wrapper">
        <p class="page-subtitle mb-3">
            Buscá por legajo, DNI o apellido para ver el estado de cuenta de toda la familia.
            Los pagos pueden demorar entre 24-48 hrs en verse reflejados en el sistema.
        </p>

        <form method="get" class="filtros" id="listadoFamiliasForm" action="estado_alumno.php">
            <input type="hidden" name="vista" value="listado_familias">
            <input type="hidden" name="escuela" value="<?php echo $lfH($escuelaActiva === '' ? 'ALL' : $escuelaActiva); ?>">

            <div class="campo">
                <label for="mes_familias">Mes de corte (cuota hasta la que controlo) <span class="text-danger">*</span></label>
                <select name="mes" id="mes_familias" required>
                    <option value="" <?php echo $mesSeleccionado < 1 ? 'selected' : ''; ?>>Seleccionar mes</option>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i == $mesSeleccionado ? 'selected' : ''; ?>>
                            <?php echo $i . ' - ' . $lfH($nombreMesFn($i)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="campo">
                <label for="estado_familias">Mostrar familias</label>
                <select name="estado" id="estado_familias">
                    <option value="" <?php echo $estadoFiltro === '' ? 'selected' : ''; ?>>Todas</option>
                    <option value="al_dia" <?php echo $estadoFiltro === 'al_dia' ? 'selected' : ''; ?>>Solo al día</option>
                    <option value="con_deuda" <?php echo $estadoFiltro === 'con_deuda' ? 'selected' : ''; ?>>Solo con deuda</option>
                </select>
            </div>

            <div class="campo">
                <label for="buscar_familias">Buscar (apellido, nombre, curso, legajo, DNI)</label>
                <input type="text" name="buscar" id="buscar_familias"
                       value="<?php echo $lfH($buscar); ?>"
                       placeholder="Ej: MARTINEZ, 10144/01, 5ACJ">
            </div>

            <div class="campo" style="flex:1 1 100%;">
                <label for="lista_json_familias">Lista de familias/legajos (JSON o separados por coma)</label>
                <input type="text" name="lista_json" id="lista_json_familias"
                       value="<?php echo $lfH($listaJsonRaw); ?>"
                       placeholder="Ej: Numero de familia(10134) o Numero de Legajo (10144/01,4099/01,53/01)">
            </div>

            <div class="campo">
                <button type="submit" class="btn btn-primary btn-consultar">
                    <span class="spinner btn-spinner" aria-hidden="true"></span>
                    <span class="btn-consultar-label">Filtrar</span>
                </button>
            </div>

            <?php if ($lfCargado): ?>
            <div class="campo">
                <a class="btn btn-success" id="lfCsvExport" href="#">Descargar CSV</a>
            </div>
            <?php endif; ?>
        </form>

        <?php if (!$lfCargado): ?>
            <div class="no-data">
                Seleccioná el <strong>mes de corte</strong> y, si querés, búsqueda o lista de legajos/familias.
                Luego pulsá <strong>Filtrar</strong> para ver el estado de cuenta por familia.
            </div>
        <?php else: ?>
        <div class="resumen-wrapper">
            <div class="resumen">
                <div class="resumen-text">
                    Listado al mes de
                    <span class="resumen-highlight"><?php echo $lfH($nombreMesSeleccionado); ?></span>.<br>
                    Familias mostradas:
                    <span class="resumen-highlight"><?php echo (int)$totalFamilias; ?></span>
                    <?php if (!$hayFiltros && (int)$totalFamilias === 30): ?>
                        <span style="font-size:0.8rem; color:#9ca3af;"> (mostrando primeras 30 familias)</span>
                    <?php endif; ?>
                </div>
                <div class="resumen-badges">
                    <div class="badge-pill badge-success">
                        Al día: <?php echo (int)$totalFamiliasAlDia; ?>
                    </div>
                    <div class="badge-pill badge-danger">
                        Con deuda: <?php echo (int)$totalFamiliasDeuda; ?>
                    </div>
                    <?php if ($filtrarPorJson): ?>
                        <div class="badge-pill badge-info">
                            Filtro JSON activo
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ((int)$totalFamilias === 0): ?>
            <div class="no-data">
                No se encontraron datos para los filtros seleccionados. Probá ajustando el mes de corte,
                la escuela o la búsqueda por legajo/familia.
            </div>
        <?php else: ?>
            <?php foreach ($familiasFiltradas as $familia): ?>
                <?php
                    $tieneDeuda = $familia['deuda_total'] > 0.01;
                    $claseFamilia = $tieneDeuda ? 'familia-deudora' : 'familia-al-dia';
                    $textoEstado = $tieneDeuda ? 'CON DEUDA' : 'AL DÍA';
                    $claseEstado = $tieneDeuda ? 'deuda' : 'al-dia';
                    $primerMesFam = $familia['primer_mes_impago'];
                    $ultimoMesFam = $familia['ultimo_mes_impago'];
                ?>
                <div class="familia-card <?php echo $claseFamilia; ?>">
                    <div class="familia-header">
                        <div class="familia-header-left">
                            <span>Familia Nº <strong><?php echo $lfH($familia['nro_familia']); ?></strong></span>
                            <?php if ($tieneDeuda): ?>
                                <span class="chip-deuda" style="margin-left:8px;">Debe</span>
                            <?php else: ?>
                                <span class="chip-al-dia" style="margin-left:8px;">Al día</span>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:right;">
                            <span class="estado <?php echo $claseEstado; ?>">
                                <?php echo $textoEstado; ?> · Corte <?php echo $lfH($nombreMesSeleccionado); ?>
                            </span><br>
                            <span class="detalle-mes">
                                <?php if ($tieneDeuda && !is_null($primerMesFam)): ?>
                                    <?php
                                        $desdeTxt = $nombreMesFn((int)$primerMesFam);
                                        $hastaTxt = (!is_null($ultimoMesFam) && $ultimoMesFam != $primerMesFam)
                                            ? $nombreMesFn((int)$ultimoMesFam)
                                            : null;
                                    ?>
                                    Debe desde
                                    <strong>
                                        <?php
                                            echo $lfH($desdeTxt);
                                            if ($hastaTxt !== null) {
                                                echo ' - ' . $lfH($hastaTxt);
                                            }
                                        ?>
                                    </strong>
                                <?php else: ?>
                                    Sin deuda hasta el mes seleccionado
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Curso</th>
                                <th>Alumno</th>
                                <th>Nro. Legajo</th>
                                <th>DNI</th>
                                <th>Estado alumno</th>
                                <th>Deuda hasta <?php echo $lfH($nombreMesSeleccionado); ?></th>
                                <th>Meses adeudados</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($familia['alumnos'] as $alumno): ?>
                                <?php
                                    $deudaAlumno = $alumno['deuda_hasta_mes'];
                                    $pmA = $alumno['primer_mes_impago'];
                                    $umA = $alumno['ultimo_mes_impago'];
                                    $isMarcado = in_array((string)$alumno['nro_legajo'], $legajosMarcados, true);
                                    $isInactivo = !empty($alumno['es_inactivo']);
                                    $isBaja = in_array((string)$alumno['nro_legajo'], $legajosBaja, true);
                                    $rowClase = ($deudaAlumno > 0.01) ? 'row-debe' : 'row-al-dia';
                                    if ($isInactivo) {
                                        $rowClase = 'row-inactivo';
                                    } elseif ($isMarcado) {
                                        $rowClase = 'row-marcado';
                                    }
                                ?>
                                <tr class="<?php echo $rowClase; ?>">
                                    <td>
                                        <?php if ($isInactivo): ?>
                                            <span class="chip-inactivo">INACTIVO</span>
                                        <?php elseif ($isBaja): ?>
                                            <span style="color: red; font-weight: bold;">BAJA</span>
                                        <?php else: ?>
                                            <?php echo $lfH($alumno['curso'] ?? ''); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $lfH(($alumno['apellido_alumno'] ?? '') . ', ' . ($alumno['nombre_alumno'] ?? '')); ?></td>
                                    <td>
                                        <?php echo $lfH($alumno['nro_legajo'] ?? ''); ?>
                                        <?php if ($isInactivo): ?>
                                            <span class="chip-inactivo" style="margin-left:4px;">INACTIVO</span>
                                        <?php elseif ($isMarcado): ?>
                                            <span class="chip-marcado" style="margin-left:4px;">CD o Notif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $lfH($alumno['dni_alumno'] ?? ''); ?></td>
                                    <td>
                                        <?php if ($isInactivo && $deudaAlumno > 0.01): ?>
                                            <span class="estado-alumno-pill debe">MOROSO</span>
                                        <?php elseif ($isInactivo): ?>
                                            <span class="estado-alumno-pill al-dia">Inactivo</span>
                                        <?php elseif ($deudaAlumno > 0.01): ?>
                                            <span class="estado-alumno-pill debe">Debe</span>
                                        <?php else: ?>
                                            <span class="estado-alumno-pill al-dia">Al día</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        $<?php echo number_format(max(0, $deudaAlumno), 2, ',', '.'); ?>
                                    </td>
                                    <td>
                                        <?php
                                            if (!is_null($pmA)) {
                                                $desdeA = $nombreMesFn((int)$pmA);
                                                $hastaA = (!is_null($umA) && $umA != $pmA)
                                                    ? $nombreMesFn((int)$umA)
                                                    : null;
                                                echo $lfH($hastaA !== null ? ($desdeA . ' - ' . $hastaA) : $desdeA);
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php if ($lfCargado): ?>
<script>
(function () {
  var csv = document.getElementById('lfCsvExport');
  if (csv) csv.setAttribute('href', <?php echo json_encode($lfCsvUrl, JSON_UNESCAPED_UNICODE); ?>);
})();
</script>
<?php endif; ?>
<?php endif; ?>
