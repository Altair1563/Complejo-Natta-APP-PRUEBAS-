            <div class="tab-pane fade show active" id="auditoria-app" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>Auditoría de la app (familias)</span>
                        <span class="badge bg-secondary">Solo lectura</span>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">
                            Registra ingresos, cierres de sesión, contraseñas, política de privacidad y contratos.
                            No incluye solicitudes de talón, cambio de email, informes ni sugerencias.
                        </p>
                        <?php
                        require_once NATTA_ROOT . '/config/app_audit.php';
                        app_ensure_audit_schema($pdo);

                        $filtro_accion = trim((string)($_GET['filtro_accion'] ?? ''));
                        $filtro_email = trim((string)($_GET['filtro_email'] ?? ''));
                        $filtro_dni = trim((string)($_GET['filtro_dni'] ?? ''));
                        $filtro_desde = trim((string)($_GET['filtro_desde'] ?? ''));
                        $filtro_hasta = trim((string)($_GET['filtro_hasta'] ?? ''));
                        $where = [];
                        $params = [];

                        if ($filtro_accion !== '') {
                            $where[] = 'l.accion LIKE ?';
                            $params[] = '%' . $filtro_accion . '%';
                        }
                        if ($filtro_email !== '') {
                            $where[] = 'l.email LIKE ?';
                            $params[] = '%' . $filtro_email . '%';
                        }
                        if ($filtro_dni !== '') {
                            $where[] = 'l.dni_alumno LIKE ?';
                            $params[] = '%' . $filtro_dni . '%';
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

                        $pageAudApp = max(1, (int)($_GET['page_aud_app'] ?? 1));
                        $limitAudApp = 25;
                        $offsetAudApp = ($pageAudApp - 1) * $limitAudApp;

                        $countStmt = $pdo->prepare(
                            "SELECT COUNT(*) FROM app_audit_log l $whereSql"
                        );
                        $countStmt->execute($params);
                        $totalAudApp = (int)$countStmt->fetchColumn();
                        $totalPagesAudApp = (int)ceil($totalAudApp / $limitAudApp);

                        $sqlAudApp = "
                            SELECT l.*
                            FROM app_audit_log l
                            $whereSql
                            ORDER BY l.created_at DESC
                            LIMIT $limitAudApp OFFSET $offsetAudApp
                        ";
                        $stmtAudApp = $pdo->prepare($sqlAudApp);
                        $stmtAudApp->execute($params);
                        $logsApp = $stmtAudApp->fetchAll();
                        ?>
                        <form method="get" class="row g-3 filtros mb-3">
                            <input type="hidden" name="tab" value="auditoria-app">
                            <div class="col-md-2">
                                <label class="form-label" for="filtro_accion">Acción</label>
                                <input type="text" class="form-control" id="filtro_accion" name="filtro_accion"
                                       placeholder="ej. login_ok"
                                       value="<?= htmlspecialchars($filtro_accion, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="filtro_email">Email</label>
                                <input type="text" class="form-control" id="filtro_email" name="filtro_email"
                                       value="<?= htmlspecialchars($filtro_email, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="filtro_dni">DNI alumno</label>
                                <input type="text" class="form-control" id="filtro_dni" name="filtro_dni"
                                       value="<?= htmlspecialchars($filtro_dni, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="filtro_desde">Desde</label>
                                <input type="date" class="form-control" id="filtro_desde" name="filtro_desde"
                                       value="<?= htmlspecialchars($filtro_desde, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="filtro_hasta">Hasta</label>
                                <input type="date" class="form-control" id="filtro_hasta" name="filtro_hasta"
                                       value="<?= htmlspecialchars($filtro_hasta, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-1 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary">Filtrar</button>
                            </div>
                        </form>
                        <div class="mb-2">
                            <a href="<?= htmlspecialchars(admin_page_url('auditoria-app'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-secondary">Limpiar filtros</a>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Email</th>
                                        <th>DNI</th>
                                        <th>Familia</th>
                                        <th>Acción</th>
                                        <th>Entidad</th>
                                        <th>Detalle</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logsApp)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">Sin registros.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($logsApp as $log): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string)$log['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="small"><?= htmlspecialchars((string)($log['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string)($log['dni_alumno'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string)($log['nro_familia'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><code><?= htmlspecialchars((string)$log['accion'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                                <td>
                                                    <?php
                                                    $ent = (string)($log['entidad'] ?? '');
                                                    $eid = (string)($log['entidad_id'] ?? '');
                                                    echo $ent !== '' ? htmlspecialchars($ent . ($eid !== '' ? ' #' . $eid : ''), ENT_QUOTES, 'UTF-8') : '—';
                                                    ?>
                                                </td>
                                                <td class="small" style="max-width:240px;word-break:break-word;">
                                                    <?= htmlspecialchars((string)($log['detalle'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                </td>
                                                <td class="small"><?= htmlspecialchars((string)($log['ip'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPagesAudApp > 1): ?>
                            <nav>
                                <ul class="pagination justify-content-center">
                                    <?php for ($i = 1; $i <= $totalPagesAudApp; $i++): ?>
                                        <li class="page-item <?= $i === $pageAudApp ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(admin_page_url('auditoria-app', [
                                                'page_aud_app' => $i,
                                                'filtro_accion' => $filtro_accion,
                                                'filtro_email' => $filtro_email,
                                                'filtro_dni' => $filtro_dni,
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
