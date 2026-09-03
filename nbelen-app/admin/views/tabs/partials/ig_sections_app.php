    <section class="ig-section" aria-labelledby="usuarios-heading">
        <h2 id="usuarios-heading" class="ig-section-title">Usuarios y aplicación</h2>
        <div class="ig-card">
            <div class="ig-card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="ig-kpi-label">Cuentas con contraseña</div>
                        <p class="fs-3 fw-bold mb-0"><?php echo (int)$cuentasUsuarios; ?></p>
                        <small class="text-muted">Tabla <code>usuarios</code></small>
                    </div>
                    <div class="col-md-6">
                        <div class="ig-kpi-label">Aceptaciones de política activas</div>
                        <?php if ($usuariosAppUso !== null): ?>
                            <p class="fs-3 fw-bold mb-0"><?php echo (int)$usuariosAppUso; ?></p>
                            <small class="text-muted">Combinaciones distintas DNI + email con estado activo en <code>privacy_policy_acceptances</code></small>
                        <?php else: ?>
                            <p class="text-warning mb-0">No disponible (revisar tabla o permisos).</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="ig-section" aria-labelledby="contratos-heading">
        <h2 id="contratos-heading" class="ig-section-title">Contratos</h2>
        <div class="ig-card">
            <div class="ig-card-header">Versión vigente</div>
            <div class="ig-card-body">
                <?php if ($contractVersion === ''): ?>
                    <p class="text-warning mb-0">No hay contrato activo en <code>contratos_instituciones</code>. No se calculan firmas ni pendientes.</p>
                <?php else: ?>
                    <p class="mb-3"><span class="ig-pill-muted">Versión</span> <strong class="ms-1"><?php echo $igH($contractVersion); ?></strong></p>
                    <div class="ig-stat-grid">
                        <div class="ig-stat-tile">
                            <div class="ig-kpi-label">Firmados (activos con aceptación vigente)</div>
                            <p class="ig-kpi-xl mb-0"><?php echo (int)$firmadosActivos; ?></p>
                        </div>
                        <div class="ig-stat-tile">
                            <div class="ig-kpi-label">Faltan firmar</div>
                            <p class="ig-kpi-xl mb-0"><?php echo (int)$faltanFirmar; ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="ig-section" aria-labelledby="emails-heading">
        <h2 id="emails-heading" class="ig-section-title">Correos electrónicos</h2>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="ig-card h-100">
                    <div class="ig-card-header">Emails distintos (complejo)</div>
                    <div class="ig-card-body">
                        <div class="ig-kpi-label">Direcciones únicas en todo el complejo</div>
                        <p class="ig-kpi-xl mb-2"><?php echo (int)$totalEmailsDistintosComplejo; ?></p>
                        <p class="small text-muted mb-0">Se unen en minúsculas los cinco campos de <code>email_familia</code> (padre, madre, trabajo, AFIP).</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="ig-card h-100">
                    <div class="ig-card-header">Emails distintos por escuela</div>
                    <div class="ig-card-body ig-card-body--flush">
                        <p class="small text-muted px-3 pt-2 mb-2">El mismo correo puede contar en más de una escuela si la familia tiene alumnos en varios establecimientos.</p>
                        <table class="ig-table mb-0">
                            <thead><tr><th>Escuela</th><th class="num">Distintos</th></tr></thead>
                            <tbody>
                            <?php foreach ($emailsPorEscuelaNum as $esc => $n): ?>
                                <tr><td><?php echo $igH($esc); ?></td><td class="num"><?php echo (int)$n; ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
