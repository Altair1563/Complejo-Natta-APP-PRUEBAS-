<?php
$cdH = static function ($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
};
$cdMoney = static function ($n) {
    return number_format(max(0, (float)$n), 2, ',', '.');
};

$cdLoadError = null;
$cdData = [];
$cdUltimaActualizacion = admin_ultima_actualizacion_rptfacing();

require_once __DIR__ . '/../../includes/cartas_doc_lib.php';
try {
    $cdData = admin_load_cartas_doc_data();
} catch (Throwable $e) {
    $cdLoadError = $e->getMessage();
}

if ($cdLoadError === null) {
    extract($cdData, EXTR_SKIP);
    $cdCsvQuery = array_filter([
        'tipo' => $tipoFiltro,
        'escuela' => $escuelaFiltro !== '' ? $escuelaFiltro : null,
        'buscar' => $buscar !== '' ? $buscar : null,
    ], static function ($v) {
        return $v !== null && $v !== '';
    });
    $cdCsvUrl = 'exportar_cartas_doc.php?' . http_build_query($cdCsvQuery);
    $cdXlsxUrl = 'exportar_cartas_doc_xlsx.php';
    $cdCotejoUrl = 'exportar_cartas_doc_cotejo.php';
    $cdCrearUrl = 'exportar_cartas_doc_crear.php?' . http_build_query(array_filter([
        'tipo' => 'cd',
        'escuela' => $escuelaFiltro !== '' ? $escuelaFiltro : null,
        'buscar' => $buscar !== '' ? $buscar : null,
    ], static function ($v) {
        return $v !== null && $v !== '';
    }));
    $cdFormAction = admin_page_url('cartas-doc');
}
?>
            <div class="tab-pane fade show active" id="cartas-doc" role="tabpanel">
<?php if ($cdLoadError !== null) { ?>
                <div class="alert alert-danger mt-3"><?= $cdH($cdLoadError) ?></div>
<?php } else { ?>
                <div class="listado-familias-admin cartas-doc-admin">
<div class="page-wrapper">
        <div class="page-header">
            <h1 class="page-title">Cartas Doc.</h1>
            <div class="page-subtitle">
                Sale de <strong>Legajos limpio prueba.xlsx</strong>. La deuda se completa con los legajos activos, por familia y legajo, y no incluye inactivos.
                Quien adeuda marzo, abril o mayo lleva carta documento. Quien no debe esos meses, y sí junio o julio, lleva notificación fehaciente.
                La deuda total del grupo es siempre hasta septiembre.
                Si filtrás por escuela, entra la familia completa. Los hermanos sin legajo en el Excel se traen de la base por número de familia, con su deuda de cuotas.
            </div>
            <div class="update-pill">
                <span class="update-dot"></span>
                <span>Última actualización: <?php echo $cdH($cdUltimaActualizacion); ?></span>
            </div>
        </div>

        <form method="get" class="filtros" action="<?= $cdH($cdFormAction) ?>">
            <input type="hidden" name="grupo" value="sistema">
            <input type="hidden" name="tab" value="cartas-doc">
            <div class="campo">
                <label for="tipo">Documento</label>
                <select name="tipo" id="tipo">
                    <option value="cd" <?php echo $tipoFiltro === 'cd' ? 'selected' : ''; ?>>Cartas documento (marzo, abril o mayo)</option>
                    <option value="nf" <?php echo $tipoFiltro === 'nf' ? 'selected' : ''; ?>>Notificaciones fehacientes (junio o julio)</option>
                    <option value="todas" <?php echo $tipoFiltro === 'todas' ? 'selected' : ''; ?>>CD y NF</option>
                </select>
            </div>

            <div class="campo">
                <label for="escuela">Escuela</label>
                <select name="escuela" id="escuela">
                    <option value="" <?php echo $escuelaFiltro === '' ? 'selected' : ''; ?>>Todas</option>
                    <?php foreach (['CJ', 'HV', 'JA', 'JN', 'SC', 'MB', 'ET', 'SU'] as $esc): ?>
                        <option value="<?php echo $esc; ?>" <?php echo $escuelaFiltro === $esc ? 'selected' : ''; ?>><?php echo $esc; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo">
                <label>Deuda Hasta</label>
                <div class="resumen-highlight" style="padding-top:8px;">SEPTIEMBRE</div>
            </div>

            <div class="campo">
                <label for="buscar">Buscar (familia, apellido, legajo, DNI, curso)</label>
                <input type="text" name="buscar" id="buscar"
                       value="<?php echo $cdH($buscar); ?>"
                       placeholder="Ej: 10134, MARTINEZ, 5ACJ">
            </div>

            <div class="campo">
                <button type="submit" class="btn btn-primary">Filtrar</button>
            </div>

            <div class="campo">
                <a class="btn btn-success" id="cdCsvExport" href="#">Descargar CSV</a>
            </div>
            <div class="campo">
                <a class="btn btn-success" id="cdXlsxExport" href="#">Descargar Excel con deuda</a>
            </div>
            <div class="campo">
                <a class="btn btn-primary" id="cdCotejoExport" href="#">COTEJAR Legajos</a>
            </div>
            <div class="campo" style="flex:1 1 100%;">
                <a class="btn btn-primary" id="cdCrearExport" href="#">Crear Cartas documento</a>
            </div>
        </form>

        <p class="page-subtitle">
            Deuda completada en <?php echo (int)$coincidencias; ?> de <?php echo (int)($coincidencias + $sinCoincidencia); ?> alumnos.
            Excel nuevo: <strong><?php echo $cdH($rutaExcelRelativa); ?></strong>
        </p>

        <div class="resumen-wrapper">
            <div class="resumen">
                <div class="resumen-text">
                    <?php if ($tipoFiltro === 'cd'): ?>
                        Cartas documento a enviar:
                    <?php elseif ($tipoFiltro === 'nf'): ?>
                        Notificaciones fehacientes a enviar:
                    <?php else: ?>
                        Documentos a enviar:
                    <?php endif; ?>
                    <span class="resumen-highlight"><?php echo (int)$totalFamilias; ?></span> familia(s),
                    <span class="resumen-highlight"><?php echo (int)$totalAlumnos; ?></span> alumno(s) activo(s).
                    Deuda Hasta <span class="resumen-highlight">SEPTIEMBRE</span>.
                </div>
                <div class="resumen-badges">
                    <?php if ($tipoFiltro !== 'nf'): ?>
                        <div class="badge-pill badge-danger">
                            CD: <?php echo (int)$totalFamiliasCd; ?> familias · <?php echo (int)$totalAlumnosCd; ?> alumnos · $<?php echo $cdH($cdMoney($montoCd)); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($tipoFiltro !== 'cd'): ?>
                        <div class="badge-pill badge-info">
                            NF: <?php echo (int)$totalFamiliasNf; ?> familias · <?php echo (int)$totalAlumnosNf; ?> alumnos · $<?php echo $cdH($cdMoney($montoNf)); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($totalFamilias === 0): ?>
            <div class="no-data">
                No hay familias activas para este listado. Probá otro documento, otra escuela o la búsqueda.
            </div>
        <?php else: ?>
            <?php foreach ($familiasFiltradas as $familia): ?>
                <?php
                    $claseFamilia = !empty($familia['tiene_cd']) ? 'familia-deudora' : 'familia-nf';
                ?>
                <div class="familia-card <?php echo $claseFamilia; ?>">
                    <div class="familia-header">
                        <div class="familia-header-left">
                            <?php
                                $cdTitulo = 'Grupo ' . (string)($familia['id_grupo'] ?? $familia['nro_familia']);
                                if (!empty($familia['colegio'])) {
                                    $cdTitulo .= ' · ' . (string)$familia['colegio'];
                                }
                                if (!empty($familia['tiene_cd']) && $tipoFiltro !== 'nf') {
                                    $cdTitulo .= ' CD DEUDA TOTAL ($ ' . $cdMoney($familia['deuda_cd']) . ')';
                                }
                                if (!empty($familia['tiene_nf']) && $tipoFiltro !== 'cd') {
                                    $cdTitulo .= ' NF DEUDA TOTAL ($ ' . $cdMoney($familia['deuda_nf']) . ')';
                                }
                            ?>
                            <span><?php echo $cdH($cdTitulo); ?></span>
                        </div>
                        <div style="text-align:right;">
                            <span class="detalle-mes">
                                <?php echo (int)count($familia['alumnos']); ?> alumno(s) en este envío
                                <?php if (!empty($familia['responsable'])): ?>
                                    <br>Responsable: <?php echo $cdH($familia['responsable']); ?>
                                <?php endif; ?>
                                <?php if (!empty($familia['domicilio'])): ?>
                                    <br><?php echo $cdH($familia['domicilio']); ?>
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
                                <th>Documento</th>
                                <th>Meses adeudados</th>
                                <th>Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($familia['alumnos'] as $alumno): ?>
                                <?php
                                    $mesesTxt = admin_cd_formato_meses_lista($alumno['meses_documento'] ?? []);
                                    $montoFila = !empty($alumno['cd']) || !empty($alumno['nf'])
                                        ? (float)($alumno['deuda_documento'] ?? 0)
                                        : (float)($alumno['deuda'] ?? 0);
                                    $tieneDeuda = $montoFila > 0.01;

                                    if (!empty($alumno['cd'])) {
                                        $doc = 'CD';
                                        $docClase = 'chip-cd';
                                        $rowClase = 'row-debe';
                                    } elseif (!empty($alumno['nf'])) {
                                        $doc = 'NF';
                                        $docClase = 'chip-nf';
                                        $rowClase = 'row-debe';
                                    } else {
                                        $doc = 'Hermano';
                                        $docClase = $tieneDeuda ? 'chip-deuda' : 'chip-al-dia';
                                        $rowClase = $tieneDeuda ? 'row-debe' : 'row-al-dia';
                                        if ($tieneDeuda) {
                                            $mesesTxt = admin_cd_formato_meses_lista($alumno['meses'] ?? []);
                                        }
                                    }
                                ?>
                                <tr class="<?php echo $cdH($rowClase); ?>">
                                    <td><?php echo $cdH($alumno['curso']); ?></td>
                                    <td><?php echo $cdH($alumno['alumno'] ?? ''); ?></td>
                                    <td><?php echo $cdH($alumno['nro_legajo']); ?></td>
                                    <td><?php echo $cdH($alumno['dni_alumno']); ?></td>
                                    <td>
                                        <span class="<?php echo $cdH($docClase); ?>"><?php echo $cdH($doc); ?></span>
                                    </td>
                                    <td><?php echo $mesesTxt !== '' ? $cdH($mesesTxt) : '—'; ?></td>
                                    <td>$<?php echo $cdH($cdMoney($montoFila)); ?></td>
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
                  const csv = document.getElementById('cdCsvExport');
                  if (csv) csv.setAttribute('href', <?= json_encode($cdCsvUrl, JSON_UNESCAPED_UNICODE) ?>);
                  const xlsx = document.getElementById('cdXlsxExport');
                  if (xlsx) xlsx.setAttribute('href', <?= json_encode($cdXlsxUrl, JSON_UNESCAPED_UNICODE) ?>);
                  const cotejo = document.getElementById('cdCotejoExport');
                  if (cotejo) cotejo.setAttribute('href', <?= json_encode($cdCotejoUrl, JSON_UNESCAPED_UNICODE) ?>);
                  const crear = document.getElementById('cdCrearExport');
                  if (crear) crear.setAttribute('href', <?= json_encode($cdCrearUrl, JSON_UNESCAPED_UNICODE) ?>);
                })();
                </script>
<?php } ?>
            </div>
