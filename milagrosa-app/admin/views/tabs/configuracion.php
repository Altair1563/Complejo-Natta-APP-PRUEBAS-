            <!-- ========== CONFIGURACIÓN ========== -->
            <?php
            $mesesCuota = [
                1 => 'MARZO', 2 => 'ABRIL', 3 => 'MAYO', 4 => 'JUNIO',
                5 => 'JULIO', 6 => 'AGOSTO', 7 => 'SEPTIEMBRE', 8 => 'OCTUBRE',
                9 => 'NOVIEMBRE', 10 => 'ADELANTO RV', 11 => 'RESTO RV', 12 => 'RV COMPLETA',
            ];
            ?>
            <div class="tab-pane fade <?= $active_tab=='configuracion'?'show active':'' ?>" id="configuracion" role="tabpanel">
                <div class="row mt-3 g-4 admin-config-grid">
                    <div class="col-lg-6">
                        <div class="admin-config-card admin-config-card--cuota h-100">
                            <div class="admin-config-card__header">
                                <span class="admin-config-card__icon" aria-hidden="true">📅</span>
                                <div>
                                    <h2 class="admin-config-card__title">Cuota vigente</h2>
                                    <p class="admin-config-card__subtitle">Historial de pagos y cálculo de arrastre</p>
                                </div>
                            </div>
                            <div class="admin-config-card__body">
                                <div class="admin-config-highlight">
                                    <span class="admin-config-highlight__label">Valor actual</span>
                                    <span class="admin-config-highlight__value">
                                        <?= (int)$cuota_vigente_actual ?>
                                        <small>(<?= htmlspecialchars(nombreMesCuota($cuota_vigente_actual), ENT_QUOTES, 'UTF-8') ?>)</small>
                                    </span>
                                </div>

                                <form method="post" action="<?= htmlspecialchars(admin_page_url('configuracion'), ENT_QUOTES, 'UTF-8') ?>" class="admin-config-form">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="accion" value="actualizar_cuota_vigente">
                                    <label for="cuota_vigente" class="form-label">Nueva cuota (1–12)</label>
                                    <div class="admin-config-form__row">
                                        <input
                                            type="number"
                                            class="form-control admin-config-input"
                                            id="cuota_vigente"
                                            name="cuota_vigente"
                                            min="1"
                                            max="12"
                                            value="<?= (int)$cuota_vigente_actual ?>"
                                            required
                                        >
                                        <button type="submit" class="btn admin-config-btn admin-config-btn--primary">Actualizar</button>
                                    </div>
                                </form>

                                <details class="admin-config-details">
                                    <summary>Ver equivalencia de meses</summary>
                                    <div class="admin-config-meses-grid">
                                        <?php foreach ($mesesCuota as $num => $nombre): ?>
                                            <div class="admin-config-mes-item<?= (int)$cuota_vigente_actual === (int)$num ? ' is-active' : '' ?>">
                                                <span class="admin-config-mes-item__num"><?= (int)$num ?></span>
                                                <span class="admin-config-mes-item__name"><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="admin-config-card admin-config-card--mantenimiento h-100">
                            <div class="admin-config-card__header">
                                <span class="admin-config-card__icon" aria-hidden="true">🛠️</span>
                                <div>
                                    <h2 class="admin-config-card__title">Modo mantenimiento</h2>
                                    <p class="admin-config-card__subtitle">Bloqueo temporal de la app para familias</p>
                                </div>
                            </div>
                            <div class="admin-config-card__body d-flex flex-column">
                                <p class="admin-config-desc">
                                    Cuando está activado, los padres verán la pantalla de mantenimiento y se cerrarán
                                    las sesiones abiertas. El panel de administración no se ve afectado.
                                </p>

                                <div class="admin-config-status<?= $mantenimiento_activo ? ' is-on' : ' is-off' ?>">
                                    <span class="admin-config-status__dot" aria-hidden="true"></span>
                                    <span class="admin-config-status__label">Estado actual</span>
                                    <strong class="admin-config-status__value">
                                        <?= $mantenimiento_activo ? 'ACTIVADO' : 'DESACTIVADO' ?>
                                    </strong>
                                </div>

                                <div class="admin-config-actions mt-auto">
                                    <form method="post" action="<?= htmlspecialchars(admin_page_url('configuracion'), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="activar_modo_mantenimiento">
                                        <button
                                            type="submit"
                                            class="btn admin-config-btn admin-config-btn--activate"
                                            <?= $mantenimiento_activo ? 'disabled' : '' ?>
                                        >
                                            Activar
                                        </button>
                                    </form>
                                    <form method="post" action="<?= htmlspecialchars(admin_page_url('configuracion'), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="desactivar_modo_mantenimiento">
                                        <button
                                            type="submit"
                                            class="btn admin-config-btn admin-config-btn--deactivate"
                                            <?= !$mantenimiento_activo ? 'disabled' : '' ?>
                                        >
                                            Desactivar
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
