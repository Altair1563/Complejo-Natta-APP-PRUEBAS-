            <div class="tab-pane fade show active" id="comunicados-individuales" role="tabpanel">
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">👤 Comunicado Individual por Familia</div>
                            <div class="card-body">
                                <form method="post" action="<?= htmlspecialchars(admin_page_url('comunicados-individuales'), ENT_QUOTES, 'UTF-8') ?>">
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
                                                    <a class="page-link" href="<?= htmlspecialchars(admin_page_url('comunicados-individuales', ['page_notif' => $i]), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
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
