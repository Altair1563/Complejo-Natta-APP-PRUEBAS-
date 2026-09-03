            <!-- ========== SOLICITUDES TALÓN ========== -->
            <div class="tab-pane fade <?= $active_tab=='talones'?'show active':'' ?>" id="talones" role="tabpanel">
                <!-- ... (contenido de talones sin cambios) ... -->
                <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Solicitudes de Talón</span>
                        <div>
                            <span class="badge bg-info me-2">Total: <?php
                                $count_sql = "SELECT COUNT(DISTINCT CONCAT(st.nro_familia, '-', st.nro_legajo, '-', st.fecha_solicitud)) 
                                              FROM solicitudes_talon st 
                                              LEFT JOIN cuotas c ON st.cuota_id = c.id";
                                $count_stmt = $pdo->query($count_sql);
                                echo $count_stmt->fetchColumn();
                            ?></span>
                            <?php
                            $export_params = array_filter([
                                'filtro_estado_talon' => $_GET['filtro_estado_talon'] ?? '',
                                'filtro_familia_talon' => $_GET['filtro_familia_talon'] ?? ''
                            ]);
                            ?>
                            <a href="exportar_csv.php?tabla=talones&<?= http_build_query($export_params) ?>" class="btn btn-sm btn-success">Exportar CSV</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php
                        $filtro_estado_talon = $_GET['filtro_estado_talon'] ?? '';
                        $filtro_familia_talon = $_GET['filtro_familia_talon'] ?? '';
                        $where_talon = [];
                        $params_talon = [];

                        if ($filtro_estado_talon !== '') {
                            $where_talon[] = "st.estado = ?";
                            $params_talon[] = $filtro_estado_talon;
                        }
                        if ($filtro_familia_talon !== '') {
                            $where_talon[] = "st.nro_familia LIKE ?";
                            $params_talon[] = "%$filtro_familia_talon%";
                        }

                        $where_clause_talon = $where_talon ? "WHERE " . implode(" AND ", $where_talon) : "";

                        $page_talon = isset($_GET['page_talon']) ? (int)$_GET['page_talon'] : 1;
                        $limit = 20;
                        $offset = ($page_talon - 1) * $limit;

                        // Consulta para obtener el total de grupos (paginación)
                        $total_sql = "SELECT COUNT(DISTINCT CONCAT(st.nro_familia, '-', st.nro_legajo, '-', st.fecha_solicitud)) 
                                      FROM solicitudes_talon st 
                                      LEFT JOIN cuotas c ON st.cuota_id = c.id 
                                      $where_clause_talon";
                        $total_stmt = $pdo->prepare($total_sql);
                        $total_stmt->execute($params_talon);
                        $total_reg_talon = $total_stmt->fetchColumn();
                        $total_pages_talon = ceil($total_reg_talon / $limit);

                        // Consulta principal: una fila por grupo, con el ID más alto y la cuota máxima
                        $sql_talon = "
                            SELECT 
                                MAX(st.id) AS id,
                                st.nro_familia,
                                st.nro_legajo,
                                st.fecha_solicitud,
                                st.estado,
                                MAX(c.numero_cuota) AS ultima_cuota
                            FROM solicitudes_talon st
                            LEFT JOIN cuotas c ON st.cuota_id = c.id
                            $where_clause_talon
                            GROUP BY st.nro_familia, st.nro_legajo, st.fecha_solicitud, st.estado
                            ORDER BY st.fecha_solicitud DESC
                            LIMIT $limit OFFSET $offset
                        ";

                        $stmt_talon = $pdo->prepare($sql_talon);
                        $stmt_talon->execute($params_talon);
                        $talones = $stmt_talon->fetchAll();
                        ?>

                        <form method="get" class="row g-3 filtros">
                            <input type="hidden" name="tab" value="talones">
                            <div class="col-auto">
                                <select name="filtro_estado_talon" class="form-select">
                                    <option value="">Todos los estados</option>
                                    <option value="pendiente" <?= $filtro_estado_talon == 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                    <option value="generado" <?= $filtro_estado_talon == 'generado' ? 'selected' : '' ?>>Generado</option>
                                    <option value="entregado" <?= $filtro_estado_talon == 'entregado' ? 'selected' : '' ?>>Entregado</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <input type="text" name="filtro_familia_talon" class="form-control" placeholder="N° Familia" value="<?= htmlspecialchars($filtro_familia_talon, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Filtrar</button>
                                <a href="<?= htmlspecialchars(admin_page_url('talones'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Limpiar</a>
                            </div>
                        </form>

                        <form method="post" id="batchFormTalones">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="tabla" value="talones">

                            <div class="batch-bar row g-2 align-items-center">
                                <div class="col-auto">
                                    <select name="batch_action" class="form-select" id="batchActionTalones">
                                        <option value="">Acciones masivas</option>
                                        <option value="estado">Cambiar estado</option>
                                        <option value="eliminar">Eliminar seleccionados</option>
                                    </select>
                                </div>
                                <div class="col-auto" id="estadoSelectorTalones" style="display:none;">
                                    <select name="nuevo_estado" class="form-select">
                                        <option value="pendiente">Pendiente</option>
                                        <option value="generado">Generado</option>
                                        <option value="entregado">Entregado</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-success" onclick="return confirm('¿Aplicar acción a los seleccionados?')">Aplicar</button>
                                </div>
                            </div>

                            <table class="table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAllTalones"></th>
                                        <th>ID</th>
                                        <th>Familia</th>
                                        <th>Legajo</th>
                                        <th>Cuota</th>
                                        <th>Fecha</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($talones as $t): ?>
                                        <tr id="row-talon-<?= $t['id'] ?>">
                                            <td><input type="checkbox" name="ids[]" value="<?= $t['id'] ?>" class="rowCheckboxTalones"></td>
                                            <td><?= $t['id'] ?></td>
                                            <td><?= htmlspecialchars($t['nro_familia'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($t['nro_legajo'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <?php if (!empty($t['ultima_cuota'])): ?>
                                                    <?= (int)$t['ultima_cuota'] . ' - ' . htmlspecialchars(nombreMesCuota((int)$t['ultima_cuota']), ENT_QUOTES, 'UTF-8') ?>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($t['fecha_solicitud'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <select class="form-select form-select-sm estado-select" data-tipo="talon" data-id="<?= $t['id'] ?>">
                                                    <option value="pendiente" <?= $t['estado'] == 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                                    <option value="generado" <?= $t['estado'] == 'generado' ? 'selected' : '' ?>>Generado</option>
                                                    <option value="entregado" <?= $t['estado'] == 'entregado' ? 'selected' : '' ?>>Entregado</option>
                                                </select>
                                            </td>
                                            <td class="table-actions">
                                                <button class="btn btn-sm btn-danger btn-eliminar" data-tipo="talon" data-id="<?= $t['id'] ?>">Eliminar</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>

                        <?php if ($total_pages_talon > 1): ?>
                            <nav>
                                <ul class="pagination justify-content-center">
                                    <?php for ($i = 1; $i <= $total_pages_talon; $i++): ?>
                                        <li class="page-item <?= $i == $page_talon ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(admin_page_url('talones', [
                                                'page_talon' => $i,
                                                'filtro_estado_talon' => $filtro_estado_talon,
                                                'filtro_familia_talon' => $filtro_familia_talon,
                                            ]), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
