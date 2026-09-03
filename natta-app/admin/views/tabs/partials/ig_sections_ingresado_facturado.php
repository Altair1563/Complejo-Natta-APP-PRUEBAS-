    <section class="ig-section ig-section--cutoff" aria-labelledby="corte-heading">
        <h2 id="corte-heading" class="ig-section-title">Mes de corte (cuotas y deudas)</h2>
        <p class="small text-muted mb-3">Solo afecta morosos, montos y deuda por escuela. La deuda incluye legajos inactivos (baja) con saldo pendiente en 2026.</p>
        <form method="get" class="ig-cutoff-form" action="<?= htmlspecialchars($igFormAction, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="grupo" value="<?= $igH($active_grupo ?? 'informacion') ?>">
            <input type="hidden" name="tab" value="<?= $igH($igActiveTab) ?>">
            <div>
                <label for="mes" class="form-label small fw-semibold text-muted mb-1">Mes lógico hasta</label>
                <select name="mes" id="mes" class="form-select form-select-lg" style="min-width: 220px;">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i === $mesSeleccionado ? 'selected' : ''; ?>>
                            <?php echo $i . ' — ' . $igH(admin_ig_nombreMesCuota($i)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-lg px-4">Actualizar vista</button>
        </form>
    </section>

    <section class="ig-section" aria-labelledby="morosos-heading">
        <h2 id="morosos-heading" class="ig-section-title">Morosos</h2>
        <div class="ig-stat-tile">
            <div class="ig-kpi-label">Deuda &gt; $0,01 hasta <?php echo $igH($nombreMesSeleccionado); ?> (incluye inactivos)</div>
            <p class="ig-kpi-xl mb-0"><?php echo (int)$morosos; ?> <span class="fs-5 fw-semibold text-muted">Alumnos</span></p>
            <p class="fs-4 fw-bold mb-0 mt-1"><?php echo $igH(number_format($pctMorosos, 2, ',', '.')); ?>%</p>
            <?php if (($morososInactivos ?? 0) > 0): ?>
                <p class="small text-muted mb-0 mt-3"><?php echo (int)$morososInactivos; ?> legajo(s) inactivo(s) con deuda por un total de $<?php echo $igH(fmt_money_ar($deudaLegajosInactivos ?? 0)); ?></p>
            <?php endif; ?>
            <p class="small text-muted mb-0 mt-3">Deuda total con inactivos: <strong>$<?php echo $igH(fmt_money_ar($deudaTotalComplejo)); ?></strong></p>
            <p class="small text-muted mb-0 mt-1">Deuda total sin inactivos (solo activos): <strong>$<?php echo $igH(fmt_money_ar($deudaTotalSinInactivos ?? 0)); ?></strong></p>
        </div>
    </section>

    <section class="ig-section" aria-labelledby="montos-heading">
        <h2 id="montos-heading" class="ig-section-title">Montos al corte</h2>
        <div class="ig-card">
            <div class="ig-card-header"><?php echo $igH($nombreMesSeleccionado); ?> — suma de cuotas hasta el corte en 2026 (activos + inactivos con cuotas cargadas)</div>
            <div class="ig-card-body">
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="ig-kpi-label">Deuda total (legajos con saldo &gt; $0,01)</div>
                        <div class="fs-4 fw-bold">$<?php echo $igH(fmt_money_ar($deudaTotalComplejo)); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="ig-kpi-label">Monto facturado en ventana</div>
                        <div class="fs-4 fw-bold">$<?php echo $igH(fmt_money_ar($montoFacturadoVentana)); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="ig-kpi-label">Monto ingresado en ventana</div>
                        <div class="fs-4 fw-bold">$<?php echo $igH(fmt_money_ar($montoIngresadoVentana)); ?></div>
                    </div>
                    <div class="col-12 pt-2 border-top">
                        <div class="ig-kpi-label">Cuotas abonadas</div>
                        <?php if ($pctCobradoVentana !== null): ?>
                            <p class="mb-0 fs-5 fw-semibold"><?php echo $igH(number_format((float)$pctCobradoVentana, 2, ',', '.')); ?>%
                                <span class="fs-6 fw-normal text-muted">(ingresado ÷ facturado hasta el corte)</span></p>
                        <?php else: ?>
                            <p class="text-muted mb-0">N/D — no hay monto facturado en la ventana.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="ig-section" aria-labelledby="deuda-esc-heading">
        <h2 id="deuda-esc-heading" class="ig-section-title">Ingresado - Facturado por escuela</h2>
        <div class="ig-card">
            <div class="ig-card-header">Hasta <?php echo $igH($nombreMesSeleccionado); ?></div>
            <div class="ig-card-body ig-card-body--flush">
                <table class="ig-table mb-0">
                    <thead><tr><th>Escuela</th><th class="num">Deuda</th><th class="num">Facturado</th><th class="num">Recaudado</th></tr></thead>
                    <tbody>
                    <?php
                    $escuelasFinanciero = array_unique(array_merge(
                        array_keys($deudaPorEscuela),
                        array_keys($facturadoPorEscuela ?? []),
                        array_keys($ingresadoPorEscuela ?? [])
                    ));
                    usort($escuelasFinanciero, 'strnatcasecmp');
                    foreach ($escuelasFinanciero as $esc):
                        $deudaEsc = (float)($deudaPorEscuela[$esc] ?? 0);
                        $factEsc = (float)($facturadoPorEscuela[$esc] ?? 0);
                        $ingEsc = (float)($ingresadoPorEscuela[$esc] ?? 0);
                        if ($deudaEsc <= 0.01 && $factEsc <= 0.01 && $ingEsc <= 0.01) {
                            continue;
                        }
                    ?>
                        <tr>
                            <td><?php echo $igH($esc); ?></td>
                            <td class="num">$<?php echo $igH(fmt_money_ar($deudaEsc)); ?></td>
                            <td class="num">$<?php echo $igH(fmt_money_ar($factEsc)); ?></td>
                            <td class="num">$<?php echo $igH(fmt_money_ar($ingEsc)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total complejo</th>
                            <th class="num">$<?php echo $igH(fmt_money_ar($deudaTotalComplejo)); ?></th>
                            <th class="num">$<?php echo $igH(fmt_money_ar($montoFacturadoVentana)); ?></th>
                            <th class="num">$<?php echo $igH(fmt_money_ar($montoIngresadoVentana)); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>
