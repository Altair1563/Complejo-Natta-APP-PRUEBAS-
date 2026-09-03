            <div class="tab-pane fade show active" id="estado-alumno" role="tabpanel">
                <div class="card mt-3">
                    <div class="card-header">Documentación de alumnos (secretarías y directivos)</div>
                    <div class="card-body">
                        <p class="mb-3">
                            Panel compartido: las secretarías consultan estados de cuenta y documentación;
                            los directivos ven lo mismo y además la pestaña <strong>Control de Usuarios</strong>.
                        </p>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <a href="estado_alumno.php" class="btn btn-primary" target="_blank" rel="noopener">
                                Abrir panel de estado alumnos
                            </a>
                            <a href="<?= htmlspecialchars(admin_page_url('usuarios'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">
                                Crear usuario secretaría / directivo
                            </a>
                        </div>
                        <p class="small text-muted mt-3 mb-0">
                            Link único (secretarías y directivos):
                            <code><?= htmlspecialchars(
                                (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                                . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio')
                                . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin'), '/\\')
                                . '/estado_alumno.php',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></code>
                        </p>
                    </div>
                </div>
            </div>
