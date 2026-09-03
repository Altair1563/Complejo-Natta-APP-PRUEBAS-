            <!-- ========== SUGERENCIAS ========== -->
            <div class="tab-pane fade <?= $active_tab=='sugerencias'?'show active':'' ?>" id="sugerencias" role="tabpanel">
                <!-- ... (contenido de sugerencias sin cambios) ... -->
                <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Sugerencias</span>
                        <div>
                            <span class="badge bg-info me-2">Total: <?= $pdo->query("SELECT COUNT(*) FROM sugerencias")->fetchColumn() ?></span>
                            <?php
                            $export_params = array_filter([
                                'filtro_leido' => $_GET['filtro_leido'] ?? '',
                                'filtro_familia_sug' => $_GET['filtro_familia_sug'] ?? ''
                            ]);
                            ?>
                            <a href="exportar_csv.php?tabla=sugerencias&<?= http_build_query($export_params) ?>" class="btn btn-sm btn-success">Exportar CSV</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php
                        $filtro_leido = $_GET['filtro_leido'] ?? '';
                        $filtro_familia_sug = $_GET['filtro_familia_sug'] ?? '';
                        $where_sug = [];
                        $params_sug = [];
                        if ($filtro_leido !== '') {
                            $where_sug[] = "leido = ?";
                            $params_sug[] = $filtro_leido;
                        }
                        if ($filtro_familia_sug !== '') {
                            $where_sug[] = "nro_familia LIKE ?";
                            $params_sug[] = "%$filtro_familia_sug%";
                        }
                        $where_clause_sug = $where_sug ? "WHERE " . implode(" AND ", $where_sug) : "";
                        
                        $page_sug = isset($_GET['page_sug']) ? (int)$_GET['page_sug'] : 1;
                        $limit = 20;
                        $offset = ($page_sug - 1) * $limit;
                        
                        $total_sug = $pdo->prepare("SELECT COUNT(*) FROM sugerencias $where_clause_sug");
                        $total_sug->execute($params_sug);
                        $total_reg_sug = $total_sug->fetchColumn();
                        $total_pages_sug = ceil($total_reg_sug / $limit);
                        
                        $sql_sug = "SELECT * FROM sugerencias $where_clause_sug ORDER BY fecha DESC LIMIT $limit OFFSET $offset";
                        $stmt_sug = $pdo->prepare($sql_sug);
                        $stmt_sug->execute($params_sug);
                        $sugs = $stmt_sug->fetchAll();
                        ?>
                        <form method="get" class="row g-3 filtros">
                            <input type="hidden" name="tab" value="sugerencias">
                            <div class="col-auto">
                                <select name="filtro_leido" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="1" <?= $filtro_leido=='1'?'selected':'' ?>>Leídas</option>
                                    <option value="0" <?= $filtro_leido=='0'?'selected':'' ?>>No leídas</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <input type="text" name="filtro_familia_sug" class="form-control" placeholder="N° Familia" value="<?php echo htmlspecialchars($filtro_familia_sug, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Filtrar</button>
                                <a href="<?= htmlspecialchars(admin_page_url('sugerencias'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Limpiar</a>
                            </div>
                        </form>

                        <form method="post" id="batchFormSugerencias">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="tabla" value="sugerencias">
                            <div class="batch-bar row g-2 align-items-center">
                                <div class="col-auto">
                                    <select name="batch_action" class="form-select" id="batchActionSugerencias">
                                        <option value="">Acciones masivas</option>
                                        <option value="leido">Marcar como leídas</option>
                                        <option value="eliminar">Eliminar seleccionados</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-success" onclick="return confirm('¿Aplicar acción a los seleccionados?')">Aplicar</button>
                                </div>
                            </div>

                            <table class="table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllSugerencias"></th>
                                        <th>ID</th>
                                        <th>Familia</th>
                                        <th>Fecha</th>
                                        <th>Mensaje</th>
                                        <th>Leído</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sugs as $s): ?>
                                    <tr id="row-sugerencia-<?= $s['id'] ?>">
                                        <td><input type="checkbox" name="ids[]" value="<?= $s['id'] ?>" class="rowCheckboxSugerencias"></td>
                                        <td><?= $s['id'] ?></td>
                                        <td><?php echo htmlspecialchars($s['nro_familia'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?= htmlspecialchars($s['fecha'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?php echo htmlspecialchars(substr($s['mensaje'], 0, 70), ENT_QUOTES, 'UTF-8') . (strlen($s['mensaje']) > 70 ? '…' : ''); ?></td>
                                        <td>
                                            <?php if ($s['leido']): ?>
                                                <span class="badge bg-success">Leído</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">No leído</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="table-actions">
                                            <button class="btn btn-sm btn-success btn-marcar-leido" data-id="<?= $s['id'] ?>" <?= $s['leido']?'disabled':'' ?>>Marcar leída</button>
                                            <button class="btn btn-sm btn-danger btn-eliminar" data-tipo="sugerencia" data-id="<?= $s['id'] ?>">Eliminar</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>

                        <?php if ($total_pages_sug > 1): ?>
                        <nav>
                            <ul class="pagination justify-content-center">
                                <?php for ($i = 1; $i <= $total_pages_sug; $i++): ?>
                                <li class="page-item <?= $i == $page_sug ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(admin_page_url('sugerencias', [
                                        'page_sug' => $i,
                                        'filtro_leido' => $filtro_leido,
                                        'filtro_familia_sug' => $filtro_familia_sug,
                                    ]), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
                                </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
