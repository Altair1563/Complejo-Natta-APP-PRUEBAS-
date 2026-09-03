            <div class="tab-pane fade <?= $active_tab=='comunicados'?'show active':'' ?>" id="comunicados" role="tabpanel">
                <!-- ... (contenido de comunicados sin cambios) ... -->
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">✏️ Nuevo Comunicado Gral.</div>
                            <div class="card-body">
                                <form method="post" action="<?= htmlspecialchars(admin_page_url('comunicados'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="accion" value="comunicado_crear">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label for="titulo" class="form-label">Título</label>
                                            <input type="text" class="form-control" id="titulo" name="titulo" required>
                                        </div>
                                        <div class="col-12">
                                            <label for="contenido" class="form-label">Contenido</label>
                                            <textarea class="form-control" id="contenido" name="contenido" rows="4" required></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="pdf_adjunto" class="form-label">PDF oficial del comunicado</label>
                                            <input type="file" class="form-control" id="pdf_adjunto" name="pdf_adjunto" accept="application/pdf,.pdf">
                                            <div class="form-text">Opcional. Máximo 15 MB.</div>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">Publicar</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>📋 Comunicados Generales Publicados</span>
                            </div>
                            <div class="card-body">
                                <?php
                                $page_com = isset($_GET['page_com']) ? (int)$_GET['page_com'] : 1;
                                $limit = 10;
                                $offset = ($page_com - 1) * $limit;
                                $total_com = $pdo->query("SELECT COUNT(*) FROM comunicados")->fetchColumn();
                                $total_pages_com = ceil($total_com / $limit);
                                $coms = $pdo->prepare("SELECT id, titulo, fecha, activo, archivo_pdf FROM comunicados ORDER BY fecha DESC LIMIT $limit OFFSET $offset");
                                $coms->execute();
                                $comunicados = $coms->fetchAll();
                                ?>
                                <table class="table table-striped table-sm">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Título</th>
                                            <th>Fecha</th>
                                            <th>Activo</th>
                                            <th>PDF</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($comunicados as $c): ?>
                                        <tr id="row-comunicado-<?= $c['id'] ?>">
                                            <td><?= $c['id'] ?></td>
                                            <td><?php echo htmlspecialchars($c['titulo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?= htmlspecialchars($c['fecha'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= $c['activo'] ? 'Sí' : 'No' ?></td>
                                            <td><?= !empty($c['archivo_pdf']) ? 'Sí' : 'No' ?></td>
                                            <td class="table-actions">
                                                <button class="btn btn-sm btn-info btn-ver" data-bs-toggle="modal" data-bs-target="#modalVerComunicado" data-id="<?= $c['id'] ?>">Ver</button>
                                                <button class="btn btn-sm btn-danger btn-eliminar" data-tipo="comunicado" data-id="<?= $c['id'] ?>">Eliminar</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php if ($total_pages_com > 1): ?>
                                <nav>
                                    <ul class="pagination justify-content-center">
                                        <?php for ($i = 1; $i <= $total_pages_com; $i++): ?>
                                        <li class="page-item <?= $i == $page_com ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(admin_page_url('comunicados', ['page_com' => $i]), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
                                        </li>
                                        <?php endfor; ?>
                                    </ul>
                                </nav>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">👤 Comunicado Individual por Familia</div>
                            <div class="card-body">
                                <form method="post" action="<?= htmlspecialchars(admin_page_url('comunicados'), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="accion" value="comunicado_individual_crear">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label for="nro_familia" class="form-label">Número de familia</label>
                                            <input type="number" min="1" step="1" class="form-control" id="nro_familia" name="nro_familia" required>
                                        </div>
                                        <div class="col-md-9">
                                            <label for="titulo_individual" class="form-label">Título de comunicado</label>
                                            <input type="text" class="form-control" id="titulo_individual" name="titulo_individual" maxlength="120" required>
                                        </div>
                                        <div class="col-12">
                                            <label for="contenido_individual" class="form-label">Comunicado</label>
                                            <textarea class="form-control" id="contenido_individual" name="contenido_individual" rows="4" required></textarea>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <button type="submit" class="btn btn-primary">Enviar Comunicado Individual</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>📬 Comunicados Individuales Publicados</span>
                            </div>
                            <div class="card-body">
                                <?php
                                $page_notif = isset($_GET['page_notif']) ? (int)$_GET['page_notif'] : 1;
                                if ($page_notif < 1) {
                                    $page_notif = 1;
                                }
                                $limit_notif = 10;
                                $offset_notif = ($page_notif - 1) * $limit_notif;
                                $total_notif_stmt = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE mensaje LIKE ? OR mensaje LIKE ?");
                                $total_notif_stmt->execute(['[Individual] %', '📢 [Individual] %']);
                                $total_notif = (int)$total_notif_stmt->fetchColumn();
                                $total_pages_notif = (int)ceil($total_notif / $limit_notif);

                                $notif_stmt = $pdo->prepare("SELECT id, nro_familia, mensaje, fecha, leido FROM notificaciones WHERE mensaje LIKE ? OR mensaje LIKE ? ORDER BY fecha DESC LIMIT $limit_notif OFFSET $offset_notif");
                                $notif_stmt->execute(['[Individual] %', '📢 [Individual] %']);
                                $notificaciones_individuales = $notif_stmt->fetchAll();
                                ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-sm">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>N° Familia</th>
                                                <th>Mensaje</th>
                                                <th>Fecha</th>
                                                <th>Leído</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($notificaciones_individuales)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted">Sin comunicados individuales publicados.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($notificaciones_individuales as $n): ?>
                                                    <tr id="row-notificacion-<?= (int)$n['id'] ?>">
                                                        <td><?= (int)$n['id'] ?></td>
                                                        <td><?= (int)$n['nro_familia'] ?></td>
                                                        <td><?php echo htmlspecialchars((string)$n['mensaje'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?= htmlspecialchars((string)$n['fecha'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= ((int)$n['leido'] === 1) ? 'Sí' : 'No' ?></td>
                                                        <td class="table-actions">
                                                            <button class="btn btn-sm btn-danger btn-eliminar" data-tipo="notificacion" data-id="<?= (int)$n['id'] ?>">Eliminar</button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ($total_pages_notif > 1): ?>
                                    <nav>
                                        <ul class="pagination justify-content-center">
                                            <?php for ($i = 1; $i <= $total_pages_notif; $i++): ?>
                                                <li class="page-item <?= $i === $page_notif ? 'active' : '' ?>">
                                                    <a class="page-link" href="<?= htmlspecialchars(admin_page_url('comunicados', ['page_notif' => $i]), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
                                                </li>
                                            <?php endfor; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
