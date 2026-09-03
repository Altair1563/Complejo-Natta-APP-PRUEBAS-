            <!-- ========== INFORMES DE ERROR ========== -->
            <div class="tab-pane fade <?= $active_tab=='informes'?'show active':'' ?>" id="informes" role="tabpanel">
                <!-- ... (contenido de informes sin cambios) ... -->
                <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Informes de Error</span>
                        <div>
                            <span class="badge bg-info me-2">Total: <?= $pdo->query("SELECT COUNT(*) FROM informes_error")->fetchColumn() ?></span>
                            <?php
                            $export_params = array_filter([
                                'filtro_estado_inf' => $_GET['filtro_estado_inf'] ?? '',
                                'filtro_familia_inf' => $_GET['filtro_familia_inf'] ?? ''
                            ]);
                            ?>
                            <a href="exportar_csv.php?tabla=informes&<?= http_build_query($export_params) ?>" class="btn btn-sm btn-success">Exportar CSV</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php
                        $filtro_estado_inf = $_GET['filtro_estado_inf'] ?? '';
                        $filtro_familia_inf = $_GET['filtro_familia_inf'] ?? '';
                        $where_inf = [];
                        $params_inf = [];
                        if ($filtro_estado_inf !== '') {
                            $where_inf[] = "estado = ?";
                            $params_inf[] = $filtro_estado_inf;
                        }
                        if ($filtro_familia_inf !== '') {
                            $where_inf[] = "nro_familia LIKE ?";
                            $params_inf[] = "%$filtro_familia_inf%";
                        }
                        $where_clause_inf = $where_inf ? "WHERE " . implode(" AND ", $where_inf) : "";
                        
                        $page_inf = isset($_GET['page_inf']) ? (int)$_GET['page_inf'] : 1;
                        $limit = 20;
                        $offset = ($page_inf - 1) * $limit;
                        
                        $total_inf = $pdo->prepare("SELECT COUNT(*) FROM informes_error $where_clause_inf");
                        $total_inf->execute($params_inf);
                        $total_reg_inf = $total_inf->fetchColumn();
                        $total_pages_inf = ceil($total_reg_inf / $limit);
                        
                        $sql_inf = "SELECT * FROM informes_error $where_clause_inf ORDER BY fecha_creacion DESC LIMIT $limit OFFSET $offset";
                        $stmt_inf = $pdo->prepare($sql_inf);
                        $stmt_inf->execute($params_inf);
                        $informes = $stmt_inf->fetchAll();
                        ?>
                        <!-- Filtros GET -->
                        <form method="get" class="row g-3 filtros">
                            <input type="hidden" name="tab" value="informes">
                            <div class="col-auto">
                                <select name="filtro_estado_inf" class="form-select">
                                    <option value="">Todos los estados</option>
                                    <option value="pendiente" <?= $filtro_estado_inf=='pendiente'?'selected':'' ?>>Pendiente</option>
                                    <option value="en_proceso" <?= $filtro_estado_inf=='en_proceso'?'selected':'' ?>>En proceso</option>
                                    <option value="resuelto" <?= $filtro_estado_inf=='resuelto'?'selected':'' ?>>Resuelto</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <input type="text" name="filtro_familia_inf" class="form-control" placeholder="N° Familia" value="<?php echo htmlspecialchars($filtro_familia_inf, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Filtrar</button>
                                <a href="<?= htmlspecialchars(admin_page_url('informes'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Limpiar</a>
                            </div>
                        </form>

                        <!-- Acciones masivas: formulario que envuelve barra y tabla -->
                        <form method="post" id="batchFormInformes">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="tabla" value="informes">
                            <div class="batch-bar row g-2 align-items-center">
                                <div class="col-auto">
                                    <select name="batch_action" class="form-select" id="batchActionInformes">
                                        <option value="">Acciones masivas</option>
                                        <option value="estado">Cambiar estado</option>
                                        <option value="eliminar">Eliminar seleccionados</option>
                                    </select>
                                </div>
                                <div class="col-auto" id="estadoSelectorInformes" style="display:none;">
                                    <select name="nuevo_estado" class="form-select">
                                        <option value="pendiente">Pendiente</option>
                                        <option value="en_proceso">En proceso</option>
                                        <option value="resuelto">Resuelto</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-success" onclick="return confirm('¿Aplicar acción a los seleccionados?')">Aplicar</button>
                                </div>
                            </div>

                            <table class="table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllInformes"></th>
                                        <th>ID</th>
                                        <th>Familia</th>
                                        <th>Descripción</th>
                                        <th>Fecha</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($informes as $i): ?>
                                    <tr id="row-informe-<?= $i['id'] ?>">
                                        <td><input type="checkbox" name="ids[]" value="<?= $i['id'] ?>" class="rowCheckboxInformes"></td>
                                        <td><?= $i['id'] ?></td>
                                        <td><?php echo htmlspecialchars($i['nro_familia'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(substr($i['error_descripcion'], 0, 50), ENT_QUOTES, 'UTF-8') . (strlen($i['error_descripcion']) > 50 ? '…' : ''); ?></td>
                                        <td><?= htmlspecialchars($i['fecha_creacion'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <select class="form-select form-select-sm estado-select" data-tipo="informe" data-id="<?= $i['id'] ?>">
                                                <option value="pendiente" <?= $i['estado']=='pendiente'?'selected':'' ?>>Pendiente</option>
                                                <option value="en_proceso" <?= $i['estado']=='en_proceso'?'selected':'' ?>>En proceso</option>
                                                <option value="resuelto" <?= $i['estado']=='resuelto'?'selected':'' ?>>Resuelto</option>
                                            </select>
                                        </td>
                                        <td class="table-actions">
                                            <button class="btn btn-sm btn-info btn-ver" data-bs-toggle="modal" data-bs-target="#modalVerInforme" data-id="<?= $i['id'] ?>">Ver</button>
                                            <button class="btn btn-sm btn-danger btn-eliminar" data-tipo="informe" data-id="<?= $i['id'] ?>">Eliminar</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>

                        <?php if ($total_pages_inf > 1): ?>
                        <nav>
                            <ul class="pagination justify-content-center">
                                <?php for ($i = 1; $i <= $total_pages_inf; $i++): ?>
                                <li class="page-item <?= $i == $page_inf ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(admin_page_url('informes', [
                                        'page_inf' => $i,
                                        'filtro_estado_inf' => $filtro_estado_inf,
                                        'filtro_familia_inf' => $filtro_familia_inf,
                                    ]), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
                                </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
