            <!-- ========== ACTUALIZACIONES (ARCHIVOS + IMPORTADORES) — solo superadmin ========== -->
            <div class="tab-pane fade <?= $active_tab === 'actualizaciones' ? 'show active' : '' ?>" id="actualizaciones" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">
                        <span>Archivos en el servidor</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Suba los CSV o Excel aquí; se guardan con nombres fijos en <code>config/imports/</code> (los scripts de importación ya usan esa ruta).</p>
                        <ul class="list-unstyled small mb-0">
                            <li class="mb-2"><strong>Excel cuotas (1 a 6 archivos)</strong></li>
                            <?php foreach ($admin_rpt_facing_bases as $rptBase): ?>
                            <li class="mb-1 ms-2"><code><?= htmlspecialchars($rptBase, ENT_QUOTES, 'UTF-8') ?>.xls</code> / <code>.xlsx</code>: <?= adminImportFormatFileInfo($importsDir . '/' . $rptBase . '.xls') ?> · <?= adminImportFormatFileInfo($importsDir . '/' . $rptBase . '.xlsx') ?></li>
                            <?php endforeach; ?>
                            <li class="mb-2 mt-2"><strong>email-padres.csv</strong>: <?= adminImportFormatFileInfo($admin_path_email_padres) ?></li>
                            <li class="mb-2"><strong>legajos.csv</strong>: <?= adminImportFormatFileInfo($admin_path_legajos) ?></li>
                            <li><strong>legajos-inactivos.csv</strong>: <?= adminImportFormatFileInfo($admin_path_legajos_inactivos) ?></li>
                        </ul>
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-header">Subir o reemplazar archivos</div>
                    <div class="card-body">
                        <form method="post" action="<?= htmlspecialchars(admin_page_url('actualizaciones'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="accion" value="subir_archivos_importacion">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="archivos_rptfacing">Excel cuotas (varios a la vez)</label>
                                    <input class="form-control" type="file" id="archivos_rptfacing" name="archivos_rptfacing[]" multiple accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                                    <div class="form-text">Seleccioná uno o más archivos en un solo paso. Cada uno debe llamarse exactamente <code>rptfacing.xls</code> o <code>.xlsx</code>, <code>rptfacing1</code> … <code>rptfacing5</code> (misma extensión). Así el sistema sabe en qué slot guardarlo.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="archivo_email_padres">email-padres.csv</label>
                                    <input class="form-control" type="file" id="archivo_email_padres" name="archivo_email_padres" accept=".csv,text/csv">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="archivo_legajos">legajos.csv</label>
                                    <input class="form-control" type="file" id="archivo_legajos" name="archivo_legajos" accept=".csv,text/csv">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="archivo_legajos_inactivos">legajos-inactivos.csv</label>
                                    <input class="form-control" type="file" id="archivo_legajos_inactivos" name="archivo_legajos_inactivos" accept=".csv,text/csv">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary mt-3">Guardar archivos seleccionados</button>
                        </form>
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-header">Ejecutar importaciones</div>
                    <div class="card-body">
                        <p class="text-muted small">Cada acción se abre en una <strong>nueva pestaña</strong> y requiere sesión de administrador. Revise el resultado en esa pestaña.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="post" action="../importador-excel/import_facturado-ingresado.php" target="_blank" class="d-inline" onsubmit="return confirm('Se vaciará la tabla cuotas y se importarán los Excel rptfacing del servidor. ¿Continuar?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-success">Importar facturado / ingresado</button>
                            </form>
                            <form method="post" action="../php/email_padres.php" target="_blank" class="d-inline" onsubmit="return confirm('Se vaciará la tabla email_familia y se importará email-padres.csv del servidor. ¿Continuar?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-primary">Importar emails padres</button>
                            </form>
                            <form method="post" action="../php/importar_legajos.php" target="_blank" class="d-inline" onsubmit="return confirm('Se vaciarán las tablas legajos y legajos_inactivos y se importarán los CSV del servidor. ¿Continuar?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-warning">Importar legajos</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
