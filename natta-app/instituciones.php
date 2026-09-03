<?php
require_once __DIR__ . '/config/session.php';
secure_session_start();

// ============================================
//  CONFIGURACIÓN Y COMPROBACIÓN DE SESIÓN
// ============================================
if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    header("Location: /natta-app/index.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
//  CONEXIÓN A LA BASE DE DATOS (SEGURA)
// ============================================
define('_ACCESS', true); // Permite la inclusión del archivo de configuración
require_once __DIR__ . '/config/db.php'; // Ajusta la ruta según tu estructura

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión: " . $conn->connect_error);
    die("Lo sentimos, no se pudo conectar con la base de datos. Intente más tarde.");
}
$conn->set_charset('utf8mb4');

// ============================================
//  FUNCIÓN AUXILIAR (compatibilidad)
// ============================================
function fetch_all_from_stmt($stmt) {
    $rows = [];
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) $rows[] = $r;
        }
    } else {
        $stmt->store_result();
        $meta = $stmt->result_metadata();
        if (!$meta) return $rows;
        $fields = [];
        $row = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array([$stmt, 'bind_result'], $fields);
        while ($stmt->fetch()) {
            $r = [];
            foreach ($row as $k => $v) $r[$k] = $v;
            $rows[] = $r;
        }
        unset($row);
    }
    return $rows;
}

$nro_familia = $_SESSION['nro_familia'];

// ============================================
//  NOTIFICACIONES NO LEÍDAS
// ============================================
$nro_familia_int = (int)$nro_familia;
$sqlNotif = "SELECT COUNT(*) as noLeidas FROM notificaciones WHERE nro_familia = ? AND leido = 0";
$stmtNotif = $conn->prepare($sqlNotif);
$stmtNotif->bind_param("i", $nro_familia_int);
$stmtNotif->execute();
$resultNotif = fetch_all_from_stmt($stmtNotif);
$stmtNotif->close();

$noLeidas = 0;
if (!empty($resultNotif)) {
    $noLeidas = (int)$resultNotif[0]['noLeidas'];
}

// ============================================
//  VARIABLES PARA EL MODAL DE PAGOS (misma lógica que home.php)
// ============================================
function esCuotaFutura($numCuota, $cuotaVigente, $mesActual)
{
    $numCuota = (int)$numCuota;
    if ($numCuota <= 9) {
        return $numCuota > $cuotaVigente;
    }
    // Cuotas de reserva (10,11,12): futuras solo antes de marzo
    return $mesActual < 3;
}

$alumnosConSaldo = [];
$saldoTotalFamiliar = 0.0;
$autolegajo = '';

$cuota_vigente = 1;
$stmt_cfg = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'cuota_vigente'");
if ($stmt_cfg) {
    $stmt_cfg->execute();
    $stmt_cfg->bind_result($cv);
    if ($stmt_cfg->fetch()) {
        $cuota_vigente = (int)$cv;
    }
    $stmt_cfg->close();
}
$mesActual = (int)date('n');

// Alumnos activos e inactivos, igual que en home.php
$sql = "SELECT *, 0 AS es_inactivo FROM legajos WHERE nro_familia = ?";
$stmt = $conn->prepare($sql);
$alumnos_activos = [];
if ($stmt) {
    $stmt->bind_param("s", $nro_familia);
    $stmt->execute();
    $alumnos_activos = fetch_all_from_stmt($stmt);
    $stmt->close();
}

$sql_inactivos = "SELECT *, 1 AS es_inactivo FROM legajos_inactivos WHERE nro_familia = ?";
$stmt_inac = $conn->prepare($sql_inactivos);
$alumnos_inactivos = [];
if ($stmt_inac) {
    $stmt_inac->bind_param("s", $nro_familia);
    $stmt_inac->execute();
    $alumnos_inactivos = fetch_all_from_stmt($stmt_inac);
    $stmt_inac->close();
}

$alumnos = array_merge($alumnos_activos, $alumnos_inactivos);

foreach ($alumnos as $alumno) {
    $legajo = $alumno['nro_legajo'] ?? '';
    $esInactivo = (bool)($alumno['es_inactivo'] ?? 0);

    $sqlCuotas = "SELECT numero_cuota, diferencia FROM cuotas WHERE nro_legajo = ? AND diferencia > 0";
    $stmtCuotas = $conn->prepare($sqlCuotas);
    if (!$stmtCuotas) {
        continue;
    }
    $stmtCuotas->bind_param("s", $legajo);
    $stmtCuotas->execute();
    $cuotasAlumno = fetch_all_from_stmt($stmtCuotas);
    $stmtCuotas->close();

    $saldoVigente = 0.0;
    foreach ($cuotasAlumno as $c) {
        if (!esCuotaFutura($c['numero_cuota'], $cuota_vigente, $mesActual)) {
            $saldoVigente += (float)$c['diferencia'];
        }
    }
    $saldoVigente = round($saldoVigente, 2);
    $saldoTotalFamiliar += $saldoVigente;

    $alumnosConSaldo[] = [
        'legajo'          => (string)$legajo,
        'nombre_completo' => trim(($alumno['nombre_alumno'] ?? '') . ' ' . ($alumno['apellido_alumno'] ?? '')),
        'curso'           => $alumno['curso'] ?? '',
        'saldo'           => $saldoVigente,
        'es_inactivo'     => $esInactivo,
    ];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Period</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <link rel="stylesheet" href="./css/main.css">
    <link rel="stylesheet" href="./css/pages/instituciones.css">
</head>
<body data-page="info-importante">
    <!-- SideBar -->
    <section class="full-box cover dashboard-sideBar">
        <div class="full-box dashboard-sideBar-bg btn-menu-dashboard"></div>
        <div class="full-box dashboard-sideBar-ct">
            <div class="full-box text-uppercase text-center text-titles dashboard-sideBar-title">
                <b></b><i class="zmdi zmdi-close btn-menu-dashboard visible-xs"></i>
            </div>
            <div class="full-box dashboard-sideBar-UserInfo">
                <figure class="full-box">
                    <img src="./assets/img/logo4.png" alt="UserIcon" />
                    <figcaption class="text-center text-titles">
                        <b>Complejo Educativo Pbro. Eliseo <br /> Esteban Natta</b>
                    </figcaption>
                </figure>
                <ul class="full-box list-unstyled text-center">
                    <li><a href="#!" class="btn-modal-help"><i class="zmdi zmdi-help-outline"></i></a></li>
                    <li><a href="#!" class="btn-exit-system"><i class="zmdi zmdi-power"></i></a></li>
                </ul>
            </div>
            <ul class="list-unstyled full-box dashboard-sideBar-Menu">
                <li><a href="home.php"><i class="zmdi zmdi-view-dashboard zmdi-hc-fw"></i> Estado de Cuenta</a></li>
                <li>
                    <a href="#!" class="btn-sideBar-SubMenu">
                        <i class="zmdi zmdi-case zmdi-hc-fw"></i> Administracion <i class="zmdi zmdi-caret-down pull-right"></i>
                    </a>
                    <ul class="list-unstyled full-box">
                        <li><a href="talondepago.php"><i class="zmdi zmdi-money-box zmdi-hc-fw"></i>Solicitar Talon de Pago </a></li>
                        <li><a href="contratos.php"><i class="zmdi zmdi-assignment zmdi-hc-fw"></i> Contratos</a></li>
                        <li><a href="agregaremail.php"><i class="zmdi zmdi-email zmdi-hc-fw"></i>Agregar Email a grupo familiar</a></li>
                        <li><a href="informarerror.php"><i class="zmdi zmdi-card-alert zmdi-hc-fw"></i>Informar Error en la Información</a></li>
                    </ul>
                </li>
                <li>
                    <a href="#!" class="btn-sideBar-SubMenu">
                        <i class="zmdi zmdi-face zmdi-hc-fw"></i> Usuarios <i class="zmdi zmdi-caret-down pull-right"></i>
                    </a>
                    <ul class="list-unstyled full-box">
                        <li><a href="info-importante.php"><i class="zmdi zmdi-notifications-active zmdi-hc-fw"></i> Informacion Importante</a></li>
                        <li><a href="instituciones.php"><i class="zmdi zmdi-account-box-phone zmdi-hc-fw"></i> Instituciones: Info. de Contacto</a></li>
                        <li><a href="librodesugerencias.php"><i class="zmdi zmdi-library zmdi-hc-fw"></i> Libro de Sugerencias</a></li>
                    </ul>
                </li>
            </ul>
            <!-- Botón abrir modal -->
            <div style=""><button id="btnPagarGeneral1" style="margin:20px;padding:8px 70px;border-radius:12px;background:#0c3484;color:#fff;cursor:pointer;">Pagar / Opciones</button></div>
        </div>
    </section>

    <!-- Content page -->
    <section class="full-box dashboard-contentPage">
        <nav class="full-box dashboard-Navbar" style="position:relative;">
            <ul class="full-box list-unstyled text-right">
                <li class="pull-left"><a href="#!" class="btn-menu-dashboard"><i class="zmdi zmdi-more-vert"></i></a></li>
                <li style="display:inline-block; position:relative;">
                    <a href="#!" class="btn-Notifications-area">
                        <i class="zmdi zmdi-notifications-none"></i>
                        <?php if ($noLeidas > 0): ?>
                            <span class="badge" id="notificationBadge"><?php echo $noLeidas; ?></span>
                        <?php else: ?>
                            <span class="badge" id="notificationBadge" style="display:none;">0</span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="#!" class="btn-modal-help"><i class="zmdi zmdi-help-outline"></i></a></li>
            </ul>
        </nav>

        <!-- Notificaciones panel -->
        <div id="notificacionesPanel">
            <h4>Notificaciones</h4>
            <div id="notificacionesContent"><div class="notif-empty">Cargando...</div></div>
        </div>

        <!-- Intro / Medios de pago -->
        <div class="container-fluid" style="background-color:#0c3484e8;box-shadow: 0 8px 7px rgba(0, 0, 0, 1);">
            <div class="page-header" style="color:white; border-radius:20px; padding:0 10px; margin:10px 0 20px;">
                <br>
                <h1 class="text-titles"><b>📞 Instituciones – Información de Contacto</b></h1><br>
                <p style="font-size:18px;">
                    En esta sección encontrará los datos de contacto oficiales de las distintas instituciones que forman parte del Complejo Natta.<br>
                    Cada contacto está destinado a consultas específicas, por lo que solicitamos comunicarse únicamente por el canal correspondiente<br> para una atención más ágil y ordenada.
                </p>
            </div><br>
        </div>
        
        <!-- ================= CONTACTO INSTITUCIONAL ================= -->
        <div class="container-fluid contacto-wrapper">
            <!-- Administración General -->
            <div class="contacto-card admin-card">
                <h3>🏫 Administración General</h3>
                <p><b>📧 Correo:</b> <a href="mailto:complejo_natta@yahoo.com.ar">complejo_natta@yahoo.com.ar</a></p>
                <p><b>Canal exclusivo para:</b></p>
                <ul>
                    <li>Consultas administrativas</li>
                    <li>Pagos, comprobantes y estados de cuenta</li>
                    <li>Solicitudes de talones de pago</li>
                    <li>Regularización de deudas</li>
                    <li>Corrección de datos administrativos</li>
                </ul>
                <p><b>⏰ Horario:</b> Lun a Vie de 09:00 a 15:00 hs</p>
                <p><b>📍 Dirección:</b> Pbro. E.E. Natta 269, Tristán Suárez</p>
            </div><br>

            <h3 class="contacto-subtitulo">🧾 Secretarías</h3>

            <!-- Grid de instituciones -->
            <div class="contacto-grid">
                <!-- Jardin La Casita de Jesús -->
                <div class="contacto-card">
                    <h4>🧒 Jardín La Casita de Jesús</h4>
                    <p><b>Secretaría:</b> 09:00 a 11:00 / 14:00 a 16:00</p>
                    <p><b>📞 Tel:</b> 7514-6358 / 11-5804-9237</p>
                    <p><b>📧 Email:</b> <a href="mailto:lacasitadejesus3136@gmail.com">lacasitadejesus3136@gmail.com</a></p>
                    <p><b>📍 Ubicación:</b> Paraguay 846, Ezeiza</p>
                </div>

                <div class="contacto-card">
                    <h4>🐜 Jardín La Hormiguita Viajera</h4>
                    <p><b>Secretaría:</b> 09:00 a 11:00 / 14:00 a 16:00</p>
                    <p><b>📞 Tel:</b> 7548-5685</p>
                    <p><b>📧 Email:</b> <a href="mailto:jardinlhv@gmail.com">jardinlhv@gmail.com</a></p>
                    <p><b>📍 Ubicación:</b> Pbro. E.E. Natta 283, Tristán Suárez</p>
                </div>

                <div class="contacto-card">
                    <h4>😊 Jardín de la Alegría</h4>
                    <p><b>Secretaría:</b> 09:00 a 11:00 / 14:00 a 16:00</p>
                    <p><b>📞 Tel:</b> 7532-5028</p>
                    <p><b>📧 Email:</b> <a href="mailto:jardin91alegria@gmail.com">jardin91alegria@gmail.com</a></p>
                    <p><b>📍 Ubicación:</b> Caracas 892, Barrio Santa Marta</p>
                </div>

                <div class="contacto-card">
                    <h4>🎒 Instituto Jesús Niño</h4>
                    <p><b>Secretaría:</b> 09:00 a 11:00 / 14:00 a 16:00</p>
                    <p><b>📞 Tel:</b> 7538-7445</p>
                    <p><b>📧 Email:</b> <a href="mailto:complejonatta@gmail.com">complejonatta@gmail.com</a></p>
                    <p><b>📍 Ubicación:</b> Pbro. E.E. Natta 241</p>
                </div>

                <div class="contacto-card">
                    <h4>✝️ Instituto Santa Cruz</h4>
                    <p><b>Secretaría:</b> 09:00 a 11:00 / 14:00 a 16:00</p>
                    <p><b>📞 Tel:</b> 2132-1707</p>
                    <p><b>📧 Email:</b> <a href="mailto:santacruz1639@yahoo.com.ar">santacruz1639@yahoo.com.ar</a></p>
                    <p><b>📍 Ubicación:</b> Pedro de Mendoza 1259</p>
                </div>

                <div class="contacto-card">
                    <h4>📘 Instituto Manuel Belgrano</h4>
                    <p><b>Secretaría:</b> 09:00 a 11:00 / 14:00 a 16:00</p>
                    <p><b>📞 Tel:</b> 7558-3230</p>
                    <p><b>📧 Email:</b> <a href="mailto:imbcomplejonatta@gmail.com">imbcomplejonatta@gmail.com</a></p>
                    <p><b>📍 Ubicación:</b> Pbro. E.E. Natta 241</p>
                </div>

                <div class="contacto-card">
                    <h4>🛠️ IET Manuel Belgrano</h4>
                    <p><b>Secretaría:</b> 09:00 a 11:00 / 14:00 a 16:00</p>
                    <p><b>📞 Tel:</b> 7559-8137</p>
                    <p><b>📧 Email:</b> <a href="mailto:idet4182@yahoo.com.ar">idet4182@yahoo.com.ar</a></p>
                    <p><b>📍 Ubicación:</b> Pbro. E.E. Natta 269</p>
                </div>

                <div class="contacto-card">
                    <h4>🎓 Instituto Superior Manuel Belgrano</h4>
                    <p><b>Secretaría:</b> 19:00 a 22:00</p>
                    <p><b>📞 Tel:</b> 7558-3230</p>
                    <p><b>📧 Email:</b> <a href="mailto:ismb4178@yahoo.com.ar">ismb4178@yahoo.com.ar</a></p>
                    <p><b>📍 Ubicación:</b> Pbro. E.E. Natta 241</p>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Dialog help -->
    <div tabindex="-1" role="dialog" id="Dialog-Help">
        <div class="modal-dialog" role="document">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header" style="border-bottom:none;display:flex;justify-content:flex-end;">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="border:none;background:none;font-size:24px;line-height:1;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="padding:0 24px 24px 24px;">
                    <?php require __DIR__ . '/includes/aviso_importante_modal_body.php'; ?>
                </div>
                <div class="modal-footer" style="border-top:none;padding:0 24px 24px 24px;display:flex;justify-content:flex-end;">
                    <button type="button" class="btn btn-primary btn-raised" data-dismiss="modal"><i class="zmdi zmdi-thumb-up"></i> Ok</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Pagos (aunque no se use, se deja para no romper el JS) -->
    <div id="overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:90"></div>
    <div id="modalPago" style="display:none;position:fixed;top:1%;left:50%;transform:translateX(-50%);width:95%;max-width:600px;max-height:98vh;overflow-y:auto;background:#fff;border-radius:16px;padding:20px;z-index:100;box-shadow:0 10px 30px #0003;">
        <h3 style="color:#0c3484;margin-bottom:20px;">Opciones de Pago</h3>
        <div style="display:grid;gap:12px;margin:16px 0;font-size:larger;">
            <button class="btn-opcion" data-tipo="familia" style="padding:15px;border:2px solid #0c3484;border-radius:12px;background:#fff;color:#0c3484;cursor:pointer;font-weight:bold;transition:all 0.3s">👨🏻‍👩🏻‍👧🏻‍👦🏻 Pagar TODO el total familiar</button>
            <button class="btn-opcion" data-tipo="alumno" style="padding:15px;border:2px solid #0c3484;border-radius:12px;background:#fff;color:#0c3484;cursor:pointer;font-weight:bold;transition:all 0.3s">👨🏽‍🎓👩🏻‍🎓 Pagar total de un alumno específico</button>
            <button class="btn-opcion" data-tipo="cuota" style="padding:15px;border:2px solid #0c3484;border-radius:12px;background:#fff;color:#0c3484;cursor:pointer;font-weight:bold;transition:all 0.3s">📅 Pagar una cuota específica</button>
        </div>
        <div id="seccionFamilia" class="modal-section" style="display:none;"><h4>Pago Total Familiar</h4><div id="resumenFamiliar" class="resumen-pago"></div></div>
        <div id="seccionAlumno" class="modal-section" style="display:none;"><h4>Pago Total por Alumno</h4><select id="selectorAlumnoTotal" class="alumno-selector"><option value="">Seleccione un alumno</option></select><div id="resumenAlumnoTotal" class="resumen-pago"></div></div>
        <div id="seccionCuota" class="modal-section" style="display:none;"><h4>Pago de Cuota Específica</h4><select id="selectorAlumnoCuota" class="alumno-selector"><option value="">Seleccione un alumno</option></select><div id="listaCuotas" style="margin-top:15px;"></div></div>
        <div id="detalleSeleccion" style="display:none;margin-top:20px;padding-top:20px;border-top:2px solid #e9ecef;">
            <h4>Detalle del Pago</h4>
            <div class="resumen-pago">
                <p><b>Concepto:</b> <span id="textoDetalle"></span></p>
                <p id="textoArrastre" style="margin-top:4px;font-size:13px;color:#555;"></p>
                <p><b>Monto a pagar:</b> <strong>$ <span id="montoPago">0.00</span></strong></p>
                <p style="margin:4px 0;"><strong>Referencia:</strong> <span id="legajoReferencia">—</span></p>
            </div>
            <input type="hidden" id="modalTipo" name="tipo_pago" value="">
            <input type="hidden" id="modalId" name="id_relacionado" value="">
            <input type="hidden" id="modalMonto" name="monto" value="">
            <input type="hidden" id="modalCuotaId" name="cuota_id" value="">
            <div style="display:grid;gap:10px;margin:16px 0;">
                <details style="border:1px solid #eee;border-radius:12px;padding:15px" open>
                    <summary><strong>🏦 Transferencia Bancaria (Recomendado)</strong></summary>
                    <div style="margin-top:12px">
                        <div class="banco-info">
                            <h5>🏛️ Datos del Beneficiario:</h5>
                            <div>
                                <p><strong>Titular:</strong> Complejo Educ. Pbro. E.E. Natta</p>
                                <p><strong>CBU:</strong> <span id="cbuText">0110661520066100245226</span> <button id="btnCopiarCBU" style="margin-left:8px;padding:4px 8px;border:1px solid #0c3484;border-radius:4px;background:#fff;cursor:pointer">📋 Copiar</button></p>
                                <p><strong>Banco:</strong> Banco Nación</p>
                                <p><strong>CUIT:</strong> 30-67618077-6</p>
                            </div>
                            <p style="margin-top:10px;font-size:13px;color:#555;">Copiá el CBU y pegalo en tu homebanking. En concepto/observaciones colocá la <strong>referencia</strong> indicada arriba (ej: <em>53096</em>) para que podamos identificar el pago.</p>
                            <p style="margin-top:4px;font-size:12px;color:#777;">Recordá que si tenés cuotas anteriores impagas, el pago de una cuota específica puede incluir el arrastre de saldos pendientes.</p>
                        </div>
                    </div>
                </details>
            </div>
        </div>
        <button id="cerrarModal" style="margin-top:20px;padding:10px 20px;border:1px solid #ddd;border-radius:10px;background:#f8f9fa;cursor:pointer">Cerrar</button>
    </div>

    <!-- Scripts -->
    <script src="./js/jquery-3.1.1.min.js"></script>
    <script src="./js/bootstrap.min.js"></script>
    <script src="./js/material.min.js"></script>
    <script src="./js/ripples.min.js"></script>
    <script src="./js/sweetalert2.min.js"></script>
    <script src="./js/jquery.mCustomScrollbar.concat.min.js"></script>
    <script>if (typeof $ !== 'undefined') $(function(){ $.material.init(); });</script>
    <script id="page-data" type="application/json"><?php echo json_encode([
        'csrfToken' => $csrf_token,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?></script>
    <script type="module" src="./frontend/js/index.js"></script>
</body>
</html>