<?php
$lfH = static function ($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
};
$lfFormAction = admin_page_url('listado-familias');
$lfLoadError = null;
$lfData = [];
$lfUltimaActualizacion = admin_ultima_actualizacion_rptfacing();

require_once __DIR__ . '/../../includes/listado_familias_data.php';
try {
    $lfData = admin_load_listado_familias_data();
} catch (Throwable $e) {
    $lfLoadError = $e->getMessage();
}

if ($lfLoadError === null) {
    extract($lfData, EXTR_SKIP);
    $lfCsvQuery = array_filter([
        'mes' => $mesSeleccionado,
        'estado' => $estadoFiltro !== '' ? $estadoFiltro : null,
        'escuela' => $escuelaFiltro !== '' ? $escuelaFiltro : null,
        'buscar' => $buscar !== '' ? $buscar : null,
        'lista_json' => $listaJsonRaw !== '' ? $listaJsonRaw : null,
    ], static function ($v) {
        return $v !== null && $v !== '';
    });
    $lfCsvUrl = 'exportar_listado_familias.php?' . http_build_query($lfCsvQuery);
}
?>
            <div class="tab-pane fade show active" id="listado-familias" role="tabpanel">
<?php if ($lfLoadError !== null) { ?>
                <div class="alert alert-danger mt-3"><?= $lfH($lfLoadError) ?></div>
<?php } else { ?>
                <div class="listado-familias-admin">
<div class="page-wrapper">
        <div class="page-header">
            <h1 class="page-title">Estados de cuenta por familia</h1>
            <div class="page-subtitle">Los pagos pueden demorar entre 24-48 hrs en verse reflejados en el sistema.</div>
            <div class="update-pill">
    <!-- LOADING -->
    <span id="loadingBox" style="display:inline-flex;align-items:center;gap:10px;">
        <span class="spinner"></span>
        <span>Cargando legajos, aguarde un momento...</span>
    </span>

    <!-- READY -->
    <span id="readyBox" style="display:none;align-items:center;gap:6px;">
        <span class="update-dot"></span>
        <span>Última actualización: <?php echo $lfH($lfUltimaActualizacion); ?></span>
    </span>
</div>
        </div>

        <form method="get" class="filtros" action="<?= htmlspecialchars($lfFormAction, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="grupo" value="<?= $lfH($active_grupo ?? 'sistema') ?>">
            <input type="hidden" name="tab" value="listado-familias">
            <div class="campo">
                <label for="mes">Mes de corte (cuota hasta la que controlo)</label>
                <select name="mes" id="mes">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i == $mesSeleccionado ? 'selected' : ''; ?>>
                            <?php echo $i . " - " . admin_lf_nombreMesCuota($i); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="campo">
                <label for="estado">Mostrar familias</label>
                <select name="estado" id="estado">
                    <option value="" <?php echo $estadoFiltro === '' ? 'selected' : ''; ?>>Todas</option>
                    <option value="al_dia" <?php echo $estadoFiltro === 'al_dia' ? 'selected' : ''; ?>>Solo al día</option>
                    <option value="con_deuda" <?php echo $estadoFiltro === 'con_deuda' ? 'selected' : ''; ?>>Solo con deuda</option>
                </select>
            </div>

            <div class="campo">
                <label for="escuela">Escuela</label>
                <select name="escuela" id="escuela">
                    <option value="" <?php echo $escuelaFiltro === '' ? 'selected' : ''; ?>>Todas</option>
                    <option value="CJ" <?php echo $escuelaFiltro === 'CJ' ? 'selected' : ''; ?>>CJ</option>
                    <option value="HV" <?php echo $escuelaFiltro === 'HV' ? 'selected' : ''; ?>>HV</option>
                    <option value="JA" <?php echo $escuelaFiltro === 'JA' ? 'selected' : ''; ?>>JA</option>
                    <option value="JN" <?php echo $escuelaFiltro === 'JN' ? 'selected' : ''; ?>>JN</option>
                    <option value="SC" <?php echo $escuelaFiltro === 'SC' ? 'selected' : ''; ?>>SC</option>
                    <option value="MB" <?php echo $escuelaFiltro === 'MB' ? 'selected' : ''; ?>>MB</option>
                    <option value="ET" <?php echo $escuelaFiltro === 'ET' ? 'selected' : ''; ?>>ET</option>
                    <option value="SU" <?php echo $escuelaFiltro === 'SU' ? 'selected' : ''; ?>>SU</option>
                </select>
            </div>

            <div class="campo">
                <label for="buscar">Buscar (apellido, nombre, curso, legajo, DNI)</label>
                <input type="text" name="buscar" id="buscar"
                       value="<?php echo $lfH($buscar); ?>"
                       placeholder="Ej: MARTINEZ, 10144/01, 5ACJ">
            </div>

            <div class="campo" style="flex:1 1 100%;">
                <label for="lista_json">Lista de familias/legajos (JSON o separados por coma)</label>
                <input type="text" name="lista_json" id="lista_json"
                       value="<?php echo $lfH($listaJsonRaw); ?>"
                       placeholder='Ej: Numero de familia(10134) o Numero de Legajo (10144/01,4099/01,53/01)'>
            </div>

            <div class="campo">
                <button type="submit" class="btn btn-primary">
                    Filtrar
                </button>
            </div>

            <div class="campo">
                <a class="btn btn-success" id="lfCsvExport" href="#">Descargar CSV</a>
            </div>
        </form>

        <div class="resumen-wrapper">
            <div class="resumen">
                <div class="resumen-text">
                    Listado al mes de
                    <span class="resumen-highlight"><?php echo $lfH($nombreMesSeleccionado); ?></span>.<br>
                    Familias mostradas:
                    <span class="resumen-highlight"><?php echo $totalFamilias; ?></span>
                    <?php if (!$hayFiltros && $totalFamilias == 30): ?>
                        <span style="font-size:0.8rem; color:#9ca3af;"> (mostrando primeras 30 familias)</span>
                    <?php endif; ?>
                </div>
                <div class="resumen-badges">
                    <div class="badge-pill badge-success">
                        Al día: <?php echo $totalFamiliasAlDia; ?>
                    </div>
                    <div class="badge-pill badge-danger">
                        Con deuda: <?php echo $totalFamiliasDeuda; ?>
                    </div>
                    <?php if ($filtrarPorJson): ?>
                        <div class="badge-pill badge-info">
                            Filtro JSON activo
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($totalFamilias === 0): ?>
            <div class="no-data">
                No se encontraron datos para los filtros seleccionados. Probá ajustando el mes de corte,
                la escuela o la búsqueda.
            </div>
        <?php else: ?>
            <?php foreach ($familiasFiltradas as $familia): ?>
                <?php
                    $tieneDeuda   = $familia['deuda_total'] > 0.01;
                    $claseFamilia = $tieneDeuda ? 'familia-deudora' : 'familia-al-dia';
                    $textoEstado  = $tieneDeuda ? 'CON DEUDA' : 'AL DÍA';
                    $claseEstado  = $tieneDeuda ? 'deuda' : 'al-dia';
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
                                        $desdeTxt = admin_lf_nombreMesCuota($primerMesFam);
                                        $hastaTxt = (!is_null($ultimoMesFam) && $ultimoMesFam != $primerMesFam)
                                            ? admin_lf_nombreMesCuota($ultimoMesFam)
                                            : null;
                                    ?>
                                    Debe desde
                                    <strong>
                                        <?php
                                            echo $desdeTxt;
                                            if ($hastaTxt !== null) {
                                                echo " - " . $hastaTxt;
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
                                    $pmA         = $alumno['primer_mes_impago'];
                                    $umA         = $alumno['ultimo_mes_impago'];

                                    $isMarcado  = in_array((string)$alumno['nro_legajo'], $legajosMarcados, true);
                                    $isInactivo = !empty($alumno['es_inactivo']);
                                    $isBaja     = in_array((string)$alumno['nro_legajo'], $legajosBaja, true);

                                    // Clase base
                                    $rowClase = ($deudaAlumno > 0.01) ? 'row-debe' : 'row-al-dia';

                                    // Prioridad visual
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
                                    <td><?php echo $lfH(($alumno['apellido_alumno'] ?? '') . ", " . ($alumno['nombre_alumno'] ?? '')); ?></td>
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
                                        <?php if (($isInactivo || $isBaja) && $deudaAlumno > 0.01): ?>
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
                                            $mesesTxtA = admin_cuota_formato_meses_impagos(
                                                $alumno['meses_impagos'] ?? [],
                                                $pmA,
                                                $umA
                                            );
                                            echo $mesesTxtA !== '' ? $lfH($mesesTxtA) : '-';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    
                </div>
                <script>
                (function () {
                  const csv = document.getElementById('lfCsvExport');
                  if (csv) csv.setAttribute('href', <?= json_encode($lfCsvUrl, JSON_UNESCAPED_UNICODE) ?>);
                  const loading = document.getElementById('loadingBox');
                  const ready = document.getElementById('readyBox');
                  if (!loading || !ready) return;
                  loading.style.display = 'inline-flex';
                  ready.style.display = 'none';
                  setTimeout(function () {
                    loading.style.display = 'none';
                    ready.style.display = 'inline-flex';
                  }, 3000);
                })();
                </script>
<?php } ?>
            </div>
