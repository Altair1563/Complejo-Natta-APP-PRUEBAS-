            <!-- ========== QR APP FAMILIAS ========== -->
            <div class="tab-pane fade <?= $active_tab === 'qr-app' ? 'show active' : '' ?>" id="qr-app" role="tabpanel">
                <div class="card mt-3 admin-qr-card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span>Código QR — App para familias</span>
                    </div>
                    <div class="card-body admin-qr-body">
                        <p class="text-muted mb-3">
                            Este código lleva al acceso de la aplicación (login de familias). Podés descargarlo para imprimirlo en afiches o compartirlo por WhatsApp y otros medios.
                        </p>
                        <div class="admin-qr-layout">
                            <div id="admin-qrcode" class="admin-qr-code" aria-label="Código QR de la aplicación"></div>
                            <div class="admin-qr-actions">
                                <p class="admin-qr-url-label"><strong>Enlace:</strong></p>
                                <p class="admin-qr-url">
                                    <a href="<?= htmlspecialchars(APP_PUBLIC_URL, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                                        <?= htmlspecialchars(APP_PUBLIC_URL, ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </p>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-primary" id="btnDescargarQr">
                                        Descargar QR (PNG)
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" id="btnCompartirQr">
                                        Compartir
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="btnCopiarUrlQr">
                                        Copiar enlace
                                    </button>
                                </div>
                                <p class="admin-qr-hint small text-muted mt-3 mb-0">
                                    «Compartir» usa el menú del celular cuando está disponible (WhatsApp, correo, etc.). En PC, descargá la imagen o copiá el enlace.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3 admin-qr-card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span>Generador de códigos QR</span>
                    </div>
                    <div class="card-body admin-qr-body">
                        <p class="text-muted mb-3">
                            Pegá cualquier enlace (por ejemplo, una página del sitio) y generá su código QR para descargarlo o compartirlo.
                        </p>
                        <div class="admin-qr-gen-form mb-3">
                            <label for="qrGenUrl" class="admin-qr-url-label"><strong>Enlace o texto:</strong></label>
                            <div class="d-flex flex-wrap gap-2">
                                <input type="text" class="form-control admin-qr-gen-input" id="qrGenUrl"
                                       placeholder="https://complejonatta.com/trabaja-con-nosotros.html"
                                       autocomplete="off" spellcheck="false">
                                <button type="button" class="btn btn-primary" id="btnGenerarQr">
                                    Generar QR
                                </button>
                            </div>
                            <p class="admin-qr-gen-error small text-danger mt-2 mb-0" id="qrGenError" hidden></p>
                        </div>
                        <div class="admin-qr-layout admin-qr-gen-result" id="qrGenResult" hidden>
                            <div id="admin-qrcode-gen" class="admin-qr-code" aria-label="Código QR generado"></div>
                            <div class="admin-qr-actions">
                                <p class="admin-qr-url-label"><strong>Enlace:</strong></p>
                                <p class="admin-qr-url">
                                    <a href="#" target="_blank" rel="noopener noreferrer" id="qrGenLink"></a>
                                </p>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-primary" id="btnDescargarQrGen">
                                        Descargar QR (PNG)
                                    </button>
                                    <button type="button" class="btn btn-outline-primary" id="btnCompartirQrGen">
                                        Compartir
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="btnCopiarUrlQrGen">
                                        Copiar enlace
                                    </button>
                                </div>
                                <p class="admin-qr-hint small text-muted mt-3 mb-0">
                                    «Compartir» usa el menú del celular cuando está disponible (WhatsApp, correo, etc.). En PC, descargá la imagen o copiá el enlace.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
