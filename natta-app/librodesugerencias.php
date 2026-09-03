<?php
require_once __DIR__ . '/config/session.php';
secure_session_start();

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================
//  COMPROBACIÓN DE SESIÓN
// ============================================
if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    header("Location: /natta-app/index.php");
    exit;
}

// ============================================
//  CONEXIÓN A LA BASE DE DATOS (SEGURA)
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/backend/lib/ingresantes_externos_2027.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en librodesugerencias.php: " . $conn->connect_error);
    $error_conexion = "No se pudo conectar con la base de datos. Intente más tarde.";
} else {
    $error_conexion = null;
    $conn->set_charset('utf8mb4');
}

// ============================================
//  FUNCIÓN AUXILIAR (compatibilidad)
//  Idealmente mover a un archivo común
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

$nro_familia_raw = $_SESSION['nro_familia'];
$nro_familia_int = filter_var($nro_familia_raw, FILTER_VALIDATE_INT);
if ($nro_familia_int === false || $nro_familia_int <= 0) {
    // Si no es válido, cerramos sesión por seguridad
    destroy_session_fully();
    header("Location: /natta-app/index.php");
    exit;
}

// ============================================
//  NOTIFICACIONES NO LEÍDAS
// ============================================
$noLeidas = 0;
if (!$error_conexion) {
    $sqlNotif = "SELECT COUNT(*) as noLeidas FROM notificaciones WHERE nro_familia = ? AND leido = 0";
    $stmtNotif = $conn->prepare($sqlNotif);
    if ($stmtNotif) {
        $stmtNotif->bind_param("i", $nro_familia_int);
        $stmtNotif->execute();
        $resultNotif = fetch_all_from_stmt($stmtNotif);
        $stmtNotif->close();
        if (!empty($resultNotif)) {
            $noLeidas = (int)$resultNotif[0]['noLeidas'];
        }
    } else {
        error_log("Error prepare notificaciones: " . $conn->error);
    }
}

// ============================================
//  GENERAR TOKEN CSRF PARA EL FORMULARIO
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
//  DATOS PARA MODAL DE PAGO (misma lógica home)
// ============================================
function esCuotaFuturaPago($numCuota, $cuotaVigente, $mesActual, $curso = '')
{
    return cuota_es_futura_para_curso((int)$numCuota, (int)$cuotaVigente, (int)$mesActual, (string)$curso);
}

$alumnosConSaldo = [];
$saldoTotalFamiliar = 0.0;

if (!$error_conexion) {
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

    $sqlActivos = "SELECT nro_legajo, nombre_alumno, apellido_alumno, curso, 0 AS es_inactivo FROM legajos WHERE nro_familia = ?";
    $stmtActivos = $conn->prepare($sqlActivos);
    $activos = [];
    if ($stmtActivos) {
        $stmtActivos->bind_param("s", $nro_familia_raw);
        $stmtActivos->execute();
        $activos = fetch_all_from_stmt($stmtActivos);
        $stmtActivos->close();
    }

    $sqlInactivos = "SELECT nro_legajo, nombre_alumno, apellido_alumno, curso, 1 AS es_inactivo FROM legajos_inactivos WHERE nro_familia = ?";
    $stmtInactivos = $conn->prepare($sqlInactivos);
    $inactivos = [];
    if ($stmtInactivos) {
        $stmtInactivos->bind_param("s", $nro_familia_raw);
        $stmtInactivos->execute();
        $inactivos = fetch_all_from_stmt($stmtInactivos);
        $stmtInactivos->close();
    }

    $alumnosPago = array_merge($activos, $inactivos);
    foreach ($alumnosPago as $alumno) {
        $legajo = $alumno['nro_legajo'] ?? '';
        $sqlSaldo = "SELECT numero_cuota, diferencia FROM cuotas WHERE nro_legajo = ? AND diferencia > 0";
        $stmtSaldo = $conn->prepare($sqlSaldo);
        if (!$stmtSaldo) {
            continue;
        }
        $stmtSaldo->bind_param("s", $legajo);
        $stmtSaldo->execute();
        $rowsSaldo = fetch_all_from_stmt($stmtSaldo);
        $stmtSaldo->close();

        $saldoVigente = 0.0;
        foreach ($rowsSaldo as $rowSaldo) {
            if (!esCuotaFuturaPago($rowSaldo['numero_cuota'] ?? 0, $cuota_vigente, $mesActual, (string)($alumno['curso'] ?? ''))) {
                $saldoVigente += (float)($rowSaldo['diferencia'] ?? 0);
            }
        }
        $saldoVigente = round($saldoVigente, 2);
        $saldoTotalFamiliar += $saldoVigente;

        $alumnosConSaldo[] = [
            'legajo'          => (string)$legajo,
            'nombre_completo' => trim(($alumno['nombre_alumno'] ?? '') . ' ' . ($alumno['apellido_alumno'] ?? '')),
            'curso'           => $alumno['curso'] ?? '',
            'saldo'           => $saldoVigente,
            'es_inactivo'     => (bool)($alumno['es_inactivo'] ?? 0),
        ];
    }
}

// ============================================
//  PROCESAR EL ENVÍO DEL FORMULARIO (vía AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && !$error_conexion) {
    // Validar token CSRF
    if (!isset($_POST['csrf_token'], $_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Token de seguridad inválido. Recargue la página.']);
        exit;
    }

    $mensaje = trim($_POST['mensaje'] ?? '');
    $response = ['status' => '', 'message' => ''];

    if (empty($mensaje)) {
        $response['status'] = 'error';
        $response['message'] = 'El mensaje no puede estar vacío.';
    } else {
        $sqlInsert = "INSERT INTO sugerencias (nro_familia, mensaje) VALUES (?, ?)";
        $stmtInsert = $conn->prepare($sqlInsert);
        if ($stmtInsert) {
            $stmtInsert->bind_param("ss", $nro_familia_raw, $mensaje);
            if ($stmtInsert->execute()) {
                $response['status'] = 'success';
                $response['message'] = 'Sugerencia enviada correctamente. Tendremos en cuenta lo que nos informa y de ser necesario nos comunicaremos en las próximas 48 hs.';
            } else {
                $response['status'] = 'error';
                $response['message'] = 'Ocurrió un error al guardar la sugerencia. Intente nuevamente.';
                error_log("Error al insertar sugerencia: " . $stmtInsert->error);
            }
            $stmtInsert->close();
        } else {
            $response['status'] = 'error';
            $response['message'] = 'Error interno del servidor.';
            error_log("Error prepare insert sugerencia: " . $conn->error);
        }
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

if ($conn && !$conn->connect_error) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Libro de Sugerencias</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <link rel="stylesheet" href="./css/main.css">
    <link rel="stylesheet" href="./css/pages/librodesugerencias.css">
</head>
<body data-page="libro-sugerencias">

<!-- ============================================ -->
<!-- SIDEBAR                                      -->
<!-- ============================================ -->
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
        <!-- Botón para abrir modal de pago (se mantiene por si acaso, pero sin funcionalidad) -->
        <div style="">
            <button id="btnPagarGeneral1" style="margin:20px;padding:8px 70px;border-radius:12px;background:#0c3484;color:#fff;cursor:pointer;">Pagar / Opciones</button>
        </div>
    </div>
</section>

<!-- ============================================ -->
<!-- CONTENIDO PRINCIPAL                          -->
<!-- ============================================ -->
<section class="full-box dashboard-contentPage">
    <nav class="full-box dashboard-Navbar" style="position:relative;">
        <ul class="full-box list-unstyled text-right">
            <li class="pull-left"><a href="#!" class="btn-menu-dashboard"><i class="zmdi zmdi-more-vert"></i></a></li>
            <li style="display:inline-block; position:relative;">
                <a href="#!" class="btn-Notifications-area">
                    <i class="zmdi zmdi-notifications-none"></i>
                    <?php if ($noLeidas > 0): ?>
                        <span class="badge" id="notificationBadge"><?php echo $noLeidas; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li><a href="#!" class="btn-modal-help"><i class="zmdi zmdi-help-outline"></i></a></li>
        </ul>
    </nav>

    <!-- Panel de notificaciones -->
    <div id="notificacionesPanel">
        <h4>Notificaciones</h4>
        <div id="notificacionesContent"><div class="notif-empty">Cargando...</div></div>
    </div>

    <!-- LIBRO DE SUGERENCIAS -->
    <div class="container-fluid" style="margin-top:30px;">

        <div style="
            background-color:#0c3484e8;
            color:white;
            border-radius:20px;
            padding:20px;
            box-shadow:0 8px 7px rgba(0,0,0,0.9);
            margin-bottom:20px;
        ">
            <h1 class="text-titles"><b>📘 Libro de Sugerencias</b></h1>
            <p style="font-size:18px;margin-top:10px;">
                Espacio institucional destinado a recibir manifestaciones formales de las familias.
            </p>
        </div>

        <?php if ($error_conexion): ?>
            <div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:8px;margin-bottom:20px;">
                <?php echo htmlspecialchars($error_conexion, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- Texto informativo con dos columnas -->
        <div style="
            background: #d4e7ff;
            border-radius:16px;
            padding:25px;
            box-shadow: 0 12px 10px rgba(0,0,0,1);
            margin-bottom:25px;
        ">
            <p style="font-size:16px;line-height:1.6;">
                El <b>Libro de Sugerencias</b> del <b>Complejo Educativo Pbro. Eliseo Esteban Natta</b>
                se encuentra disponible para que las familias puedan realizar presentaciones formales relacionadas
                con el funcionamiento administrativo, institucional o de los servicios brindados.
            </p>

            <p style="font-size:16px;line-height:1.6;"><b>
                Este canal tiene como finalidad promover el diálogo, detectar oportunidades de mejora
                y fortalecer la convivencia dentro de la comunidad educativa.
            </b></p><br>

            <div style="display: flex; gap: 30px; margin-top: 20px;">
                <div style="flex: 1;">
                    <h4 style="color:#0c3484; margin-top:0;">📝 ¿Qué puede registrarse?</h4>
                    <ul style="font-size:16px;line-height:1.6; padding-left:20px;">
                        <li>Quejas formales.</li>
                        <li>Reclamos administrativos o institucionales.</li>
                        <li>Sugerencias constructivas.</li>
                        <li>Observaciones vinculadas a la atención o los servicios.</li>
                    </ul>
                </div>
                <div style="flex: 1;">
                    <h4 style="color:#0c3484; margin-top:0;">📌 Consideraciones importantes</h4>
                    <ul style="font-size:16px;line-height:1.6; padding-left:20px;">
                        <li>Las presentaciones deben realizarse de manera clara y respetuosa.</li>
                        <li>Detallar la situación con la mayor precisión posible, a fin de facilitar su correcta evaluación y seguimiento.</li>
                        <li>Las comunicaciones anónimas no serán consideradas.</li>
                        <li>Este canal no reemplaza los medios administrativos habituales.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- FORMULARIO PARA ENVIAR SUGERENCIA (con AJAX) -->
        <div class="form-sugerencia">
            <h4 style="color:#0c3484; margin-top:0;">✍️ Enviar una sugerencia, queja o consulta</h4>

            <!-- Contenedor para mensajes (se llena vía AJAX) -->
            <div id="mensajeContainer"></div>

            <?php if (!$error_conexion): ?>
            <form id="formSugerencia" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <label for="mensaje">Mensaje *</label>
                <textarea name="mensaje" id="mensaje" placeholder="Escriba aquí su mensaje..." required></textarea>

                <button type="submit" name="enviar_sugerencia" class="btn-enviar">Enviar</button>
            </form>
            <?php else: ?>
                <p>No se puede enviar la sugerencia debido a un error de conexión. Intente más tarde.</p>
            <?php endif; ?>
        </div>
    </div><br>
</section>

<!-- ============================================ -->
<!-- MODAL DE AYUDA (sin Bootstrap)               -->
<!-- ============================================ -->
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

<!-- ============================================ -->
<!-- MODAL DE PAGOS                               -->
<!-- ============================================ -->
<div id="overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:90"></div>
<div id="modalPago" style="display:none;position:fixed;top:1%;left:50%;transform:translateX(-50%);width:95%;max-width:600px;max-height:98vh;overflow-y:auto;background:#fff;border-radius:16px;padding:20px;z-index:100;box-shadow:0 10px 30px #0003;">
    <h3 style="color:#0c3484;margin-bottom:20px;">Opciones de Pago</h3>
    <div style="display:grid;gap:12px;margin:16px 0;font-size:larger;">
        <button class="btn-opcion" data-tipo="familia" style="padding:15px;border:2px solid #0c3484;border-radius:12px;background:#fff;color:#0c3484;cursor:pointer;font-weight:bold;transition:all 0.3s">👨🏻‍👩🏻‍👧🏻‍👦🏻 Pagar TODO el total familiar</button>
        <button class="btn-opcion" data-tipo="alumno" style="padding:15px;border:2px solid #0c3484;border-radius:12px;background:#fff;color:#0c3484;cursor:pointer;font-weight:bold;transition:all 0.3s">👨🏽‍🎓👩🏻‍🎓 Pagar total de un alumno específico</button>
        <button class="btn-opcion" data-tipo="cuota" style="padding:15px;border:2px solid #0c3484;border-radius:12px;background:#fff;color:#0c3484;cursor:pointer;font-weight:bold;transition:all 0.3s">📅 Pagar una cuota específica</button>
    </div>
    <div id="seccionFamilia" class="modal-section">
        <h4>Pago Total Familiar</h4>
        <div id="resumenFamiliar" class="resumen-pago"></div>
    </div>
    <div id="seccionAlumno" class="modal-section">
        <h4>Pago Total por Alumno</h4>
        <select id="selectorAlumnoTotal" class="alumno-selector"><option value="">Seleccione un alumno</option></select>
        <div id="resumenAlumnoTotal" class="resumen-pago"></div>
    </div>
    <div id="seccionCuota" class="modal-section">
        <h4>Pago de Cuota Específica</h4>
        <select id="selectorAlumnoCuota" class="alumno-selector"><option value="">Seleccione un alumno</option></select>
        <div id="listaCuotas" style="margin-top:15px;"></div>
    </div>
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
                        <p style="margin-top:10px;font-size:13px;color:#555;">Copiá el CBU y pegalo en tu homebanking. En concepto/observaciones colocá la <strong>referencia</strong> indicada arriba para que podamos identificar el pago.</p>
                        <p style="margin-top:4px;font-size:12px;color:#777;">Recordá que si tenés cuotas anteriores impagas, el pago de una cuota específica puede incluir el arrastre de saldos pendientes.</p>
                    </div>
                </div>
            </details>
        </div>
    </div>
    <button id="cerrarModal" style="margin-top:20px;padding:10px 20px;border:1px solid #ddd;border-radius:10px;background:#f8f9fa;cursor:pointer">Cerrar</button>
</div>

<!-- ============================================ -->
<!-- SCRIPTS (JavaScript)                         -->
<!-- ============================================ -->
<script src="./js/jquery-3.1.1.min.js"></script>
<script src="./js/bootstrap.min.js"></script>
<script src="./js/material.min.js"></script>
<script src="./js/ripples.min.js"></script>
<script src="./js/sweetalert2.min.js"></script>
<script src="./js/jquery.mCustomScrollbar.concat.min.js"></script>
<script>if (typeof $ !== 'undefined') $(function(){ $.material.init(); });</script>
<script id="page-data" type="application/json"><?php echo json_encode([
    'csrfToken' => $csrf_token,
], JSON_HEX_TAG); ?></script>
<script type="module" src="./frontend/js/index.js"></script>
</body>
</html>