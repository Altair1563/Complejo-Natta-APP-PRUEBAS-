<?php
require_once __DIR__ . '/../../includes/alta_alumnos_nuevos_lib.php';
$aanUltimos = admin_aan_fetch_ultimos($pdo, 5);
$aanFormAction = admin_page_url('alta-alumnos-nuevos');
$aanH = static function ($str) {
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
            <div class="tab-pane fade show active" id="alta-alumnos-nuevos" role="tabpanel">
                <div class="alumnos-nuevos-admin alumnos-nuevos-alta">
                    <div class="aan-shell">
                        <h2 class="aan-title">Alta de Alumnos Nuevos</h2>

                        <form method="post" action="<?= $aanH($aanFormAction) ?>">
                            <input type="hidden" name="csrf_token" value="<?= $aanH($csrf_token) ?>">
                            <input type="hidden" name="accion" value="alta_alumno_crear">

                            <section class="aan-section">
                                <h4>Datos del Alumno</h4>
                                <div class="row g-3">
                                    <div class="col-md-4"><label>Apellido</label><input name="apellido_alumno" class="form-control uppercase" required></div>
                                    <div class="col-md-4"><label>Nombre</label><input name="nombre_alumno" class="form-control uppercase" required></div>
                                    <div class="col-md-4"><label>DNI</label><input name="nro_documento_alumno" class="form-control" required></div>
                                    <div class="col-md-3"><label>Fecha Nacimiento</label><input type="date" name="fecha_nacimiento_alumno" class="form-control"></div>
                                    <div class="col-md-3"><label>Sexo</label>
                                        <select name="sexo" class="form-select" required>
                                            <option value="">Seleccionar...</option>
                                            <option>MASCULINO</option><option>FEMENINO</option><option>OTRO</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3"><label>Nacionalidad</label><input name="nacionalidad_alumno" value="ARGENTINA" class="form-control uppercase"></div>
                                    <div class="col-md-3"><label>Curso</label><input name="codigo_curso" class="form-control" value="EXCJ" readonly></div>
                                    <div class="col-md-3"><label>Fecha Ingreso</label><input type="date" name="fecha_ingreso" class="form-control" value="2026-03-01" readonly></div>
                                </div>
                            </section>

                            <section class="aan-section">
                                <h4>Responsable 1</h4>
                                <div class="row g-3">
                                    <div class="col-md-4"><label>Nombre y Apellido</label><input name="nombre_madre" class="form-control uppercase" required></div>
                                    <div class="col-md-3"><label>DNI</label><input name="nro_documento_madre" class="form-control" required></div>
                                    <div class="col-md-3"><label>Celular</label><input name="celular_madre" class="form-control"></div>
                                    <div class="col-md-3"><label>Email</label><input type="email" name="email_madre" class="form-control lowercase"></div>
                                    <div class="col-md-4"><label>Dirección</label><input id="dir1" class="form-control"></div>
                                    <div class="col-md-2"><label>Número</label><input id="num1" class="form-control"></div>
                                    <div class="col-md-3"><label>CP</label><input id="cp1" class="form-control"></div>
                                    <div class="col-md-3"><label>Localidad</label><input id="loc1" class="form-control uppercase"></div>
                                </div>
                            </section>

                            <section class="aan-section">
                                <h4>Responsable 2 (Padre) — opcional</h4>
                                <div class="row g-3">
                                    <div class="col-md-4"><label>Nombre y Apellido</label><input name="nombre_padre" class="form-control uppercase"></div>
                                    <div class="col-md-3"><label>DNI</label><input name="nro_documento_padre" class="form-control"></div>
                                    <div class="col-md-3"><label>Celular</label><input name="celular_padre" class="form-control"></div>
                                    <div class="col-md-3"><label>Email</label><input type="email" name="email_padre" class="form-control lowercase"></div>
                                </div>
                            </section>

                            <section class="aan-section">
                                <h4>Responsable Familiar
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="aanCopiarResponsable1('familia')">Copiar Responsable 1</button>
                                </h4>
                                <div class="row g-3">
                                    <div class="col-md-4"><label>Nombre y Apellido</label><input name="responsable_familia" id="resp_fam" class="form-control uppercase"></div>
                                    <div class="col-md-2"><label>Tipo Doc</label><input name="tipo_doc_resp_familia" id="tipo_fam" class="form-control" value="DNI" readonly></div>
                                    <div class="col-md-3"><label>DNI</label><input name="nro_doc_resp_familia" id="dni_fam" class="form-control"></div>
                                    <div class="col-md-3"><label>Teléfono</label><input name="telefono_resp_familia" id="tel_fam" class="form-control"></div>
                                    <div class="col-md-4"><label>Dirección</label><input name="direccion_calle_familia" id="dir_fam" class="form-control"></div>
                                    <div class="col-md-2"><label>Número</label><input name="direccion_numero_familia" id="num_fam" class="form-control"></div>
                                    <div class="col-md-3"><label>CP</label><input name="codigo_postal_familia" id="cp_fam" class="form-control"></div>
                                    <div class="col-md-3"><label>Localidad</label><input name="localidad_familia" id="loc_fam" class="form-control uppercase"></div>
                                </div>
                            </section>

                            <section class="aan-section">
                                <h4>Responsable AFIP
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="aanCopiarResponsable1('afip')">Copiar Responsable 1</button>
                                </h4>
                                <div class="row g-3">
                                    <div class="col-md-4"><label>Nombre y Apellido</label><input name="responsable_afip" id="resp_afip" class="form-control uppercase"></div>
                                    <div class="col-md-2"><label>Tipo Doc</label><input name="tipo_doc_resp_afip" id="tipo_afip" class="form-control" value="DNI" readonly></div>
                                    <div class="col-md-3"><label>DNI</label><input name="nro_doc_resp_afip" id="dni_afip" class="form-control"></div>
                                    <div class="col-md-3"><label>Email</label><input type="email" name="email_resp_afip" id="email_afip" class="form-control lowercase"></div>
                                    <div class="col-md-4"><label>Dirección</label><input name="direccion_calle_afip" id="dir_afip" class="form-control"></div>
                                    <div class="col-md-2"><label>Número</label><input name="direccion_numero_afip" id="num_afip" class="form-control"></div>
                                    <div class="col-md-3"><label>CP</label><input name="codigo_postal_afip" id="cp_afip" class="form-control"></div>
                                    <div class="col-md-3"><label>Localidad</label><input name="localidad_afip" id="loc_afip" class="form-control uppercase"></div>
                                </div>
                            </section>

                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-primary px-5">Guardar alumno</button>
                            </div>
                        </form>

                        <div class="mt-5">
                            <h4>Últimos alumnos cargados</h4>
                            <table class="table table-bordered table-striped mt-3 text-center align-middle">
                                <thead class="table-primary">
                                    <tr>
                                        <th>ID</th>
                                        <th>Alumno</th>
                                        <th>Responsable</th>
                                        <th>Registro</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($aanUltimos === []): ?>
                                        <tr><td colspan="4" class="text-muted">Sin registros aún.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($aanUltimos as $row): ?>
                                            <tr>
                                                <td><?= (int)$row['id'] ?></td>
                                                <td class="datos-alumno">
                                                    <?= $aanH($row['apellido_alumno']) ?>, <?= $aanH($row['nombre_alumno']) ?><br>
                                                    <small>DNI: <?= $aanH($row['nro_documento_alumno']) ?></small>
                                                </td>
                                                <td class="datos-responsable">
                                                    <?= $aanH($row['responsable_familia']) ?><br>
                                                    <small>DNI: <?= $aanH($row['nro_doc_resp_familia']) ?></small><br>
                                                    <small><?= $aanH($row['email_resp_afip']) ?></small>
                                                </td>
                                                <td class="datos-fecha"><?= $aanH($row['fecha_registro']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <div class="text-center mt-3">
                                <a href="<?= $aanH(admin_page_url('lista-alumnos-nuevos')) ?>" class="btn btn-success">Ver lista completa</a>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                function aanCopiarResponsable1(dest) {
                    const nom = document.querySelector('[name="nombre_madre"]')?.value || '';
                    const dni = document.querySelector('[name="nro_documento_madre"]')?.value || '';
                    const cel = document.querySelector('[name="celular_madre"]')?.value || '';
                    const email = (document.querySelector('[name="email_madre"]')?.value || '').toLowerCase();
                    const dir = document.getElementById('dir1')?.value || '';
                    const num = document.getElementById('num1')?.value || '';
                    const cp = document.getElementById('cp1')?.value || '';
                    const loc = document.getElementById('loc1')?.value || '';

                    if (dest === 'familia') {
                        document.getElementById('resp_fam').value = nom;
                        document.getElementById('dni_fam').value = dni;
                        document.getElementById('tel_fam').value = cel;
                        document.getElementById('dir_fam').value = dir;
                        document.getElementById('num_fam').value = num;
                        document.getElementById('cp_fam').value = cp;
                        document.getElementById('loc_fam').value = loc;
                    } else if (dest === 'afip') {
                        document.getElementById('resp_afip').value = nom;
                        document.getElementById('dni_afip').value = dni;
                        document.getElementById('email_afip').value = email;
                        document.getElementById('dir_afip').value = dir;
                        document.getElementById('num_afip').value = num;
                        document.getElementById('cp_afip').value = cp;
                        document.getElementById('loc_afip').value = loc;
                    }
                }
                </script>
            </div>
