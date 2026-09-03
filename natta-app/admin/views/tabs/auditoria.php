            <div class="tab-pane fade show active" id="auditoria" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Registro de auditoría</span>
                        <span class="badge bg-secondary">Solo lectura</span>
                    </div>
                    <div class="card-body">
                        <?php
                        $filtro_accion = trim((string)($_GET['filtro_accion'] ?? ''));
                        $filtro_desde = trim((string)($_GET['filtro_desde'] ?? ''));
                        $filtro_hasta = trim((string)($_GET['filtro_hasta'] ?? ''));
                        $where = [];
                        $params = [];

                        if ($filtro_accion !== '') {
                            $where[] = 'l.accion LIKE ?';
                            $params[] = '%' . $filtro_accion . '%';
                        }
                        if ($filtro_desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtro_desde)) {
                            $where[] = 'l.created_at >= ?';
                            $params[] = $filtro_desde . ' 00:00:00';
                        }
                        if ($filtro_hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtro_hasta)) {
                            $where[] = 'l.created_at <= ?';
                            $params[] = $filtro_hasta . ' 23:59:59';
                        }
                        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                        $page_aud = max(1, (int)($_GET['page_aud'] ?? 1));
                        $limitAud = 25;
                        $offsetAud = ($page_aud - 1) * $limitAud;

                        $countStmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM admin_audit_log l $whereSql"
                        );
                        $countStmt->execute($params);
                        $totalAud = (int)$countStmt->fetchColumn();
                        $totalPagesAud = (int)ceil($totalAud / $limitAud);

                        $sqlAud = "
                            SELECT l.*, u.username, u.nombre AS admin_nombre
                            FROM admin_audit_log l
                            LEFT JOIN admin_users u ON u.id = l.admin_user_id
                            $whereSql
                            ORDER BY l.created_at DESC
                            LIMIT $limitAud OFFSET $offsetAud
                        ";
                        $stmtAud = $pdo->prepare($sqlAud);
                        $stmtAud->execute($params);
                        $logs = $stmtAud->fetchAll();
                        ?>
                        <form method="get" class="row g-3 filtros mb-3">
                            <input type="hidden" name="tab" value="auditoria">
                            <div class="col-md-3">
                                <label class="form-label" for="filtro_accion">Acción</label>
                                <input type="text" class="form-control" id="filtro_accion" name="filtro_accion"
                                       placeholder="ej. login_ok, eliminar"
                                       value="<?= htmlspecialchars($filtro_accion, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="filtro_desde">Desde</label>
                                <input type="date" class="form-control" id="filtro_desde" name="filtro_desde"
                                       value="<?= htmlspecialchars($filtro_desde, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="filtro_hasta">Hasta</label>
                                <input type="date" class="form-control" id="filtro_hasta" name="filtro_hasta"
                                       value="<?= htmlspecialchars($filtro_hasta, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary">Filtrar</button>
                                <a href="<?= htmlspecialchars(admin_page_url('auditoria'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Limpiar</a>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Usuario</th>
                                        <th>Acción</th>
                                        <th>Entidad</th>
                                        <th>Detalle</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">Sin registros.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)$log['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <?php if (!empty($log['admin_nombre'])): ?>
                                                        <?= htmlspecialchars((string)$log['admin_nombre'], ENT_QUOTES, 'UTF-8') ?>
                                                        <small class="text-muted">(<?= htmlspecialchars((string)$log['username'], ENT_QUOTES, 'UTF-8') ?>)</small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code><?= htmlspecialchars((string)$log['accion'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                                <td>
                                                    <?php
                                                    $ent = (string)($log['entidad'] ?? '');
                                                    $eid = $log['entidad_id'] ?? '';
                                                    echo $ent !== '' ? htmlspecialchars($ent . ($eid !== '' ? ' #' . $eid : ''), ENT_QUOTES, 'UTF-8') : '—';
                                                    ?>
                                                </td>
                                                <td class="small" style="max-width:280px;word-break:break-word;">
                                                    <?= htmlspecialchars((string)($log['detalle'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                                <td class="small"><?= htmlspecialchars((string)($log['ip'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPagesAud > 1): ?>
                            <nav>
                                <ul class="pagination justify-content-center">
                                    <?php for ($i = 1; $i <= $totalPagesAud; $i++): ?>
                                        <li class="page-item <?= $i === $page_aud ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(admin_page_url('auditoria', [
                                                'page_aud' => $i,
                                                'filtro_accion' => $filtro_accion,
                                                'filtro_desde' => $filtro_desde,
                                                'filtro_hasta' => $filtro_hasta,
                                            ]), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
