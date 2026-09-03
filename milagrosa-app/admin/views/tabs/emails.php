            <!-- ========== SOLICITUDES EMAIL ========== -->
            <div class="tab-pane fade <?= $active_tab=='emails'?'show active':'' ?>" id="emails" role="tabpanel">
                <!-- ... (contenido de emails sin cambios) ... -->
                <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Solicitudes de Cambio de Email</span>
                        <div>
                            <span class="badge bg-info me-2">Total: <?= $pdo->query("SELECT COUNT(*) FROM solicitudes_email")->fetchColumn() ?></span>
                            <?php
                            $export_params = array_filter([
                                'filtro_estado_email' => $_GET['filtro_estado_email'] ?? '',
                                'filtro_familia_email' => $_GET['filtro_familia_email'] ?? ''
                            ]);
                            ?>
                            <a href="exportar_csv.php?tabla=emails&<?= http_build_query($export_params) ?>" class="btn btn-sm btn-success">Exportar CSV</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php
                        $filtro_estado_email = $_GET['filtro_estado_email'] ?? '';
                        $filtro_familia_email = $_GET['filtro_familia_email'] ?? '';
                        $where_email = [];
                        $params_email = [];
                        if ($filtro_estado_email !== '') {
                            $where_email[] = "estado = ?";
                            $params_email[] = $filtro_estado_email;
                        }
                        if ($filtro_familia_email !== '') {
                            $where_email[] = "nro_familia LIKE ?";
                            $params_email[] = "%$filtro_familia_email%";
                        }
                        $where_clause_email = $where_email ? "WHERE " . implode(" AND ", $where_email) : "";
                        
                        $page_email = isset($_GET['page_email']) ? (int)$_GET['page_email'] : 1;
                        $limit = 20;
                        $offset = ($page_email - 1) * $limit;
                        
                        $total_email = $pdo->prepare("SELECT COUNT(*) FROM solicitudes_email $where_clause_email");
                        $total_email->execute($params_email);
                        $total_reg_email = $total_email->fetchColumn();
                        $total_pages_email = ceil($total_reg_email / $limit);
                        
                        $sql_email = "SELECT * FROM solicitudes_email $where_clause_email ORDER BY fecha_solicitud DESC LIMIT $limit OFFSET $offset";
                        $stmt_email = $pdo->prepare($sql_email);
                        $stmt_email->execute($params_email);
                        $emails = $stmt_email->fetchAll();
                        ?>
                        <form method="get" class="row g-3 filtros">
                            <input type="hidden" name="tab" value="emails">
                            <div class="col-auto">
                                <select name="filtro_estado_email" class="form-select">
                                    <option value="">Todos los estados</option>
                                    <option value="pendiente" <?= $filtro_estado_email=='pendiente'?'selected':'' ?>>Pendiente</option>
                                    <option value="aprobado" <?= $filtro_estado_email=='aprobado'?'selected':'' ?>>Aprobado</option>
                                    <option value="rechazado" <?= $filtro_estado_email=='rechazado'?'selected':'' ?>>Rechazado</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <input type="text" name="filtro_familia_email" class="form-control" placeholder="N° Familia" value="<?php echo htmlspecialchars($filtro_familia_email, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Filtrar</button>
                                <a href="<?= htmlspecialchars(admin_page_url('emails'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Limpiar</a>
                            </div>
                        </form>

                        <form method="post" id="batchFormEmails">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="tabla" value="emails">
                            <div class="batch-bar row g-2 align-items-center">
                                <div class="col-auto">
                                    <select name="batch_action" class="form-select" id="batchActionEmails">
                                        <option value="">Acciones masivas</option>
                                        <option value="estado">Cambiar estado</option>
                                        <option value="eliminar">Eliminar seleccionados</option>
                                    </select>
                                </div>
                                <div class="col-auto" id="estadoSelectorEmails" style="display:none;">
                                    <select name="nuevo_estado" class="form-select">
                                        <option value="pendiente">Pendiente</option>
                                        <option value="aprobado">Aprobado</option>
                                        <option value="rechazado">Rechazado</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-success" onclick="return confirm('¿Aplicar acción a los seleccionados?')">Aplicar</button>
                                </div>
                            </div>

                            <table class="table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllEmails"></th>
                                        <th>ID</th>
                                        <th>Familia</th>
                                        <th>Posición</th>
                                        <th>Email Actual</th>
                                        <th>Email Nuevo</th>
                                        <th>Fecha</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($emails as $e): 
                                        $posMap = [1=>'Padre',2=>'Padre trabajo',3=>'Madre',4=>'Madre trabajo'];
                                    ?>
                                    <tr id="row-email-<?= $e['id'] ?>">
                                        <td><input type="checkbox" name="ids[]" value="<?= $e['id'] ?>" class="rowCheckboxEmails"></td>
                                        <td><?= $e['id'] ?></td>
                                        <td><?php echo htmlspecialchars($e['nro_familia'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= $posMap[$e['posicion']] ?? htmlspecialchars($e['posicion'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?php echo htmlspecialchars($e['email_actual'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($e['email_nuevo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= htmlspecialchars($e['fecha_solicitud'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <select class="form-select form-select-sm estado-select" data-tipo="email" data-id="<?= $e['id'] ?>">
                                                <option value="pendiente" <?= $e['estado']=='pendiente'?'selected':'' ?>>Pendiente</option>
                                                <option value="aprobado" <?= $e['estado']=='aprobado'?'selected':'' ?>>Aprobado</option>
                                                <option value="rechazado" <?= $e['estado']=='rechazado'?'selected':'' ?>>Rechazado</option>
                                            </select>
                                        </td>
                                        <td class="table-actions">
                                            <button class="btn btn-sm btn-danger btn-eliminar" data-tipo="email" data-id="<?= $e['id'] ?>">Eliminar</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>

                        <?php if ($total_pages_email > 1): ?>
                        <nav>
                            <ul class="pagination justify-content-center">
                                <?php for ($i = 1; $i <= $total_pages_email; $i++): ?>
                                <li class="page-item <?= $i == $page_email ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(admin_page_url('emails', [
                                        'page_email' => $i,
                                        'filtro_estado_email' => $filtro_estado_email,
                                        'filtro_familia_email' => $filtro_familia_email,
                                    ]), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
                                </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
