    <section class="ig-section" aria-labelledby="matricula-heading">
        <h2 id="matricula-heading" class="ig-section-title">Matrícula</h2>
        <div class="ig-card">
            <div class="ig-card-body">
                <div class="ig-kpi-label">Alumnos activos (tabla legajos)</div>
                <p class="ig-kpi-xl mb-0"><?php echo (int)$totalAlumnos; ?></p>
            </div>
        </div>
    </section>

    <section class="ig-section" aria-labelledby="por-escuela-heading">
        <h2 id="por-escuela-heading" class="ig-section-title">Alumnos por escuela</h2>
        <div class="ig-card">
            <div class="ig-card-header">Distribución</div>
            <div class="ig-card-body ig-card-body--flush">
                <table class="ig-table mb-0">
                    <thead><tr><th>Escuela</th><th class="num">Cantidad</th></tr></thead>
                    <tbody>
                    <?php foreach ($porEscuela as $esc => $cnt): ?>
                        <tr><td><?php echo $igH($esc); ?></td><td class="num"><?php echo (int)$cnt; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="ig-section" aria-labelledby="por-curso-heading">
        <h2 id="por-curso-heading" class="ig-section-title">Alumnos por curso</h2>
        <p class="small text-muted mb-2">Elegí una escuela (pestaña) para ver solo los cursos de ese establecimiento.</p>
        <div class="ig-card">
            <div class="ig-card-header">Cursos por escuela</div>
            <?php if (empty($porCursoPorEscuela)): ?>
                <div class="ig-card-body text-muted">No hay datos de cursos agrupados.</div>
            <?php else: ?>
                <ul class="nav nav-tabs ig-tabs-scroll" id="igCursosTabs" role="tablist">
                    <?php
                    $escTabIdx = 0;
                    foreach ($porCursoPorEscuela as $escLabel => $_cursos):
                        $tid = 'ig-esc-' . substr(md5((string)$escLabel), 0, 14);
                        $isFirst = $escTabIdx === 0;
                        $escTabIdx++;
                    ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $isFirst ? 'active' : ''; ?>" id="<?php echo $igH($tid); ?>-tab"
                                data-bs-toggle="tab" data-bs-target="#<?php echo $igH($tid); ?>" type="button" role="tab"
                                aria-controls="<?php echo $igH($tid); ?>" aria-selected="<?php echo $isFirst ? 'true' : 'false'; ?>">
                                <?php echo $igH($escLabel); ?>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="tab-content" id="igCursosTabContent">
                    <?php
                    $escTabIdx = 0;
                    foreach ($porCursoPorEscuela as $escLabel => $cursosMap):
                        $tid = 'ig-esc-' . substr(md5((string)$escLabel), 0, 14);
                        $isFirst = $escTabIdx === 0;
                        $escTabIdx++;
                    ?>
                        <div class="tab-pane fade ig-school-pane <?php echo $isFirst ? 'show active' : ''; ?>" id="<?php echo $igH($tid); ?>" role="tabpanel"
                            aria-labelledby="<?php echo $igH($tid); ?>-tab" tabindex="0">
                            <div class="ig-tab-pane-inner">
                                <table class="ig-table mb-0">
                                    <thead><tr><th>Curso</th><th class="num">Alumnos</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($cursosMap as $nomCurso => $cntCurso): ?>
                                        <tr><td><?php echo $igH($nomCurso); ?></td><td class="num"><?php echo (int)$cntCurso; ?></td></tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="ig-section" aria-labelledby="becas-heading">
        <h2 id="becas-heading" class="ig-section-title">Becas</h2>
        <div class="ig-card">
            <div class="ig-card-header">Uso de becas (equivalentes al 100%)</div>
            <div class="ig-card-body">
                <div class="ig-callout mb-3">
                    <strong>Qué medimos.</strong> La norma del 10% se aplica <strong>por escuela</strong> (código en el curso). Cada alumno con beca al 25%, 50%, 75% u 100% suma esa fracción a los <strong>equivalentes de beca al 100%</strong> (dos al 50% = 1 beca completa).
                    En la base, <code>porcentaje_descuento</code> es ese porcentaje; la columna “otorgadas” es la suma ÷ 100 por establecimiento.
                    <strong>No mostramos el % sobre matrícula total del complejo</strong>: un valor tipo 10,11% mezcla todas las escuelas y puede confundir cuando ya se superó el cupo en décimas; el control útil es <strong>fila por fila</strong> (equiv. vs cupo de cada escuela).
                    <ul>
                        <li><strong>Cupo teórico (10%)</strong> por escuela = 10% de los alumnos <em>de esa escuela</em>, con la misma regla de redondeo hacia arriba (múltiplo de 10 en alumnos → +1 beca; si no, <code>ceil</code> del 10%).</li>
                        <li><strong>% uso del cupo</strong> en cada fila = 100 × (equiv. otorgadas ÷ cupo teórico de esa escuela).</li>
                        <li><strong>Uso del cupo (complejo)</strong> en la barra inferior = total de equivalentes del complejo ÷ cupo calculado sobre <strong>toda</strong> la matrícula activa (referencia global).</li>
                        <li><strong>Alumnos con beca (conteo)</strong> = cantidad de alumnos con <code>porcentaje_descuento</code> &gt; 0 (métrica de “cabezas”, aparte).</li>
                    </ul>
                </div>

                <div class="ig-card border mb-3" style="box-shadow: none;">
                    <div class="ig-card-header">Becas por escuela</div>
                    <div class="ig-card-body ig-card-body--flush p-0">
                        <div class="table-responsive">
                            <table class="ig-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Escuela</th>
                                        <th class="num">Alumnos</th>
                                        <th class="num">Equiv. beca (100%) otorgadas</th>
                                        <th class="num">Cupo teórico (10%)</th>
                                        <th class="num">% uso cupo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $sumAlu = 0;
                                $sumEquiv = 0.0;
                                $sumCupoEsc = 0.0;
                                foreach ($porEscuela as $escNombre => $nAluEsc) {
                                    $sumAlu += (int)$nAluEsc;
                                    $eqEsc = (float)($equivBecasPorEscuela[$escNombre] ?? 0.0);
                                    $sumEquiv += $eqEsc;
                                    $cupoEsc = cupo_becas_al_100_redondeo_arriba((int)$nAluEsc);
                                    $sumCupoEsc += $cupoEsc;
                                    $pctEsc = $cupoEsc > 1e-9 ? round(100 * $eqEsc / $cupoEsc, 2) : 0.0;
                                ?>
                                    <tr>
                                        <td><?php echo $igH($escNombre); ?></td>
                                        <td class="num"><?php echo (int)$nAluEsc; ?></td>
                                        <td class="num"><?php echo $igH(fmt_equiv_beca_listado($eqEsc)); ?></td>
                                        <td class="num"><?php echo $igH(fmt_equiv_beca_listado($cupoEsc)); ?></td>
                                        <td class="num"><?php echo $igH(number_format($pctEsc, 2, ',', '.')); ?>%</td>
                                    </tr>
                                <?php } ?>
                                    <tr class="table-light fw-semibold">
                                        <td>Total</td>
                                        <td class="num"><?php echo (int)$sumAlu; ?></td>
                                        <td class="num"><?php echo $igH(fmt_equiv_beca_listado($sumEquiv)); ?></td>
                                        <td class="num"><?php echo $igH(fmt_equiv_beca_listado($sumCupoEsc)); ?></td>
                                        <td class="num text-muted">—</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-muted px-3 py-2 mb-0">La fila <strong>Total</strong> suma alumnos y equivalentes; la suma de cupos por escuela no tiene por qué coincidir con el cupo global del complejo (ese se calcula sobre el total único de alumnos).</p>
                    </div>
                </div>

                <div class="ig-metric-row">
                    <div class="ig-metric">
                        <div class="ig-metric-label">Equivalentes al 100% (complejo)</div>
                        <div class="ig-metric-value"><?php echo $igH(number_format($equivBecas100, 2, ',', '.')); ?></div>
                    </div>
                    <div class="ig-metric">
                        <div class="ig-metric-label">Cupo teórico (complejo, redondeo ↑)</div>
                        <div class="ig-metric-value"><?php echo $igH(number_format($cupoBecas100Teorico, 2, ',', '.')); ?></div>
                    </div>
                    <div class="ig-metric">
                        <div class="ig-metric-label">Uso del cupo (complejo)</div>
                        <div class="ig-metric-value <?php echo $cupoSobreTope ? 'text-danger' : ''; ?>"><?php echo $igH(number_format($pctUsoCupoDiezPorciento, 2, ',', '.')); ?>%</div>
                    </div>
                    <div class="ig-metric">
                        <div class="ig-metric-label">Alumnos con beca (conteo)</div>
                        <div class="ig-metric-value"><?php echo (int)$becados; ?> <span class="fs-6 fw-normal text-muted">(<?php echo $igH(number_format($pctCabezasBecadas, 2, ',', '.')); ?>% cabezas)</span></div>
                    </div>
                </div>

                <div class="ig-progress-wrap">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Uso respecto del cupo del 10% (referencia complejo)</span>
                        <span><?php echo $igH(number_format($pctUsoCupoDiezPorciento, 2, ',', '.')); ?>%</span>
                    </div>
                    <div class="ig-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $igH((string)(int)round($barCupoPct)); ?>">
                        <div class="ig-progress-bar <?php echo $cupoSobreTope ? 'ig-progress-bar--over' : ''; ?>" style="width: <?php echo $igH(number_format($barCupoPct, 2, '.', '')); ?>%;"></div>
                    </div>
                    <?php if ($cupoSobreTope): ?>
                        <p class="small text-danger mt-2 mb-0">El uso supera el 100% del cupo en equivalentes; la barra muestra el tope visual al 100%.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="ig-card mt-3">
            <div class="ig-card-header">Alumnos becados por nivel</div>
            <div class="ig-card-body ig-card-body--flush">
                <table class="ig-table mb-0">
                    <thead><tr><th>Nivel de beca</th><th class="num">Cantidad de alumnos</th></tr></thead>
                    <tbody>
                        <tr><td>25%</td><td class="num"><?php echo (int)$conteoBecaNivel[25]; ?></td></tr>
                        <tr><td>50%</td><td class="num"><?php echo (int)$conteoBecaNivel[50]; ?></td></tr>
                        <tr><td>75%</td><td class="num"><?php echo (int)$conteoBecaNivel[75]; ?></td></tr>
                        <tr><td>100%</td><td class="num"><?php echo (int)$conteoBecaNivel[100]; ?></td></tr>
                        <tr><td><em>Otro (&gt; 0, distinto de 25 / 50 / 75 / 100)</em></td><td class="num"><?php echo (int)$conteoBecaNivel['_otro']; ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
