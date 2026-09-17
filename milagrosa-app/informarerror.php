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
    header("Location: /milagrosa-app/index.php");
    exit;
}

// Generar token CSRF para formularios y peticiones AJAX
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
//  CONEXIÓN A LA BASE DE DATOS (SEGURA)
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/backend/lib/ingresantes_externos_2027.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en informar_error.php: " . $conn->connect_error);
    $error_conexion = "No se pudo conectar con la base de datos. Intente más tarde.";
} else {
    $error_conexion = null;
    $conn->set_charset('utf8mb4');
}

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

function esCuotaFuturaPago($numCuota, $cuotaVigente, $mesActual, $curso = '')
{
    return cuota_es_futura_para_curso((int)$numCuota, (int)$cuotaVigente, (int)$mesActual, (string)$curso);
}

$nro_familia_raw = $_SESSION['nro_familia'];
$nro_familia_int = filter_var($nro_familia_raw, FILTER_VALIDATE_INT);
if ($nro_familia_int === false || $nro_familia_int <= 0) {
    // Si no es válido, cerramos sesión por seguridad
    destroy_session_fully();
    header("Location: /milagrosa-app/index.php");
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
//  DATOS PARA MODAL DE PAGO (misma lógica home)
// ============================================
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
//  PROCESAR EL ENVÍO DEL FORMULARIO
//  (Soporta tanto AJAX como envío tradicional)
// ============================================
$mensaje_status = '';
$mensaje_texto = '';

// Detectar si es petición AJAX (por campo oculto o header)
$isAjax = (!empty($_POST['ajax']) && $_POST['ajax'] === '1') || 
          (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_informe']) && !$error_conexion) {
    // Validar token CSRF
    $csrf_post = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_post)) {
        $mensaje_status = 'error';
        $mensaje_texto = 'Token de seguridad inválido. Recargue la página.';
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => $mensaje_status,
                'message' => $mensaje_texto
            ]);
            $conn->close();
            exit;
        }
    }

    $error_descripcion = trim($_POST['error_descripcion'] ?? '');
    $correccion_sugerida = trim($_POST['correccion_sugerida'] ?? '');

    if ($mensaje_status === 'error') {
        // Ya tenemos el mensaje por token inválido
    } elseif (empty($error_descripcion)) {
        $mensaje_status = 'error';
        $mensaje_texto = 'Debes describir el error.';
    } else {
        // Insertar en la tabla informes_error
        $sqlInsert = "INSERT INTO informes_error (nro_familia, error_descripcion, correccion_sugerida) VALUES (?, ?, ?)";
        $stmtInsert = $conn->prepare($sqlInsert);
        if ($stmtInsert) {
            $stmtInsert->bind_param("sss", $nro_familia_raw, $error_descripcion, $correccion_sugerida);
            if ($stmtInsert->execute()) {
                $mensaje_status = 'success';
                $mensaje_texto = 'Informe de error enviado correctamente. El área administrativa evaluará el caso y realizará las correcciones necesarias.';
            } else {
                $mensaje_status = 'error';
                $mensaje_texto = 'Ocurrió un error al guardar el informe. Intente nuevamente.';
                error_log("Error al insertar informe: " . $stmtInsert->error);
            }
            $stmtInsert->close();
        } else {
            $mensaje_status = 'error';
            $mensaje_texto = 'Error interno del servidor.';
            error_log("Error prepare insert: " . $conn->error);
        }
    }

    // Si es AJAX, devolvemos JSON y terminamos
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => $mensaje_status,
            'message' => $mensaje_texto
        ]);
        $conn->close();
        exit;
    }
    // Si no es AJAX, el flujo continúa y se mostrará el mensaje en HTML
}

if ($conn && !$conn->connect_error) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Informar Error en la Información</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <link rel="stylesheet" href="./css/main.css">
    <link rel="stylesheet" href="./css/pages/informarerror.css">
</head>
<body data-page="informar-error">

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
                <img src="./assets/img/LogoMilagrosa.png" alt="UserIcon" />
                <figcaption class="text-center text-titles">
                    <b>Jardin de Infantes La Milagrosa</b>
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

    <!-- CONTENIDO ESPECÍFICO DE LA PÁGINA: INFORMAR ERROR -->
    <div class="container-fluid" style="margin-top:30px;">

        <div class="info-contenedor">
            <h1 class="text-titles"><b>⚠️ Informar Error en la Información</b></h1>
            <p style="font-size:18px;">
                Si detecta algún dato incorrecto en la información de su grupo familiar o en el estado de cuenta, utilice este canal para comunicarlo a la administración.
            </p>
        </div>

        <?php if ($error_conexion): ?>
            <div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:8px;margin-bottom:20px;">
                <?php echo htmlspecialchars($error_conexion, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <!-- Bloque informativo original (ahora con más detalles) -->
        <div class="info-texto">
            <h4 style="color:#0c3484;">📋 ¿Qué tipo de errores pueden reportarse?</h4>
            <ul>
                <li>Datos personales incorrectos (nombre, DNI, fecha de nacimiento).</li>
                <li>Alumno no asignado correctamente al grupo familiar.</li>
                <li>Errores en el historial de pagos o cuotas mal imputadas.</li>
                <li>Problemas con la visualización de comunicados o notificaciones.</li>
                <li>Cualquier otro dato que no coincida con la realidad.</li>
            </ul>

            <p style="margin-top:20px; font-size:14px; color:#555;">
                <strong>Importante:</strong> Los reportes serán evaluados por el área administrativa. La corrección de los datos puede demorar hasta 72 horas hábiles.
            </p>
        </div>

        <!-- FORMULARIO PARA ENVIAR INFORME DE ERROR (similar a librodesugerencias) -->
        <div class="form-sugerencia">
            <h4 style="color:#0c3484; margin-top:0;">✍️ Enviar informe de error</h4>

            <!-- Contenedor para mensajes (se llena vía AJAX) -->
            <div id="mensaje-container" style="display: none;"></div>

            <?php if (!$error_conexion): ?>
            <form id="informeForm" method="POST" action="">
                <!-- Campo oculto para indicar que es AJAX -->
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="enviar_informe" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                <label for="error_descripcion">Descripción del error *</label>
                <textarea name="error_descripcion" id="error_descripcion" placeholder="Explique detalladamente qué información es incorrecta y cómo debería ser." required></textarea>

                <label for="correccion_sugerida">Corrección sugerida (opcional)</label>
                <textarea name="correccion_sugerida" id="correccion_sugerida" placeholder="Si conoce el dato correcto, indíquelo aquí."></textarea>

                <button type="submit" class="btn-enviar" id="btnEnviar">Enviar informe</button>
                <span id="loading" style="display: none;"><span class="loading-spinner"></span> Enviando...</span>
            </form>
            <?php else: ?>
                <p>No se puede enviar el informe debido a un error de conexión. Intente más tarde.</p>
            <?php endif; ?>
        </div>

        <!-- Enlace a correo directo (opcional, lo dejamos como respaldo) -->
        <div style="text-align: center; margin-top: 20px;">
            <a href="mailto:?subject=Error%20en%20información&body=Legajo%20del%20alumno:%0D%0ADescripción%20del%20error:%0D%0ADato%20correcto%20(si%20corresponde):" class="btn-correo" style="background:#0c3484; color:white; padding:10px 20px; border-radius:50px; text-decoration:none; display:inline-block; box-shadow: 0 12px 10px rgba(0,0,0,1);">
                ✉️ Enviar correo directamente (alternativa)
            </a>
        </div><br>
    </div>
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
                            <p><strong>Titular:</strong> Jardin de Infantes La Milagrosa</p>
                            <p><strong>CBU:</strong> <span id="cbuText">0140058801500100639139</span> <button id="btnCopiarCBU" style="margin-left:8px;padding:4px 8px;border:1px solid #0c3484;border-radius:4px;background:#fff;cursor:pointer">📋 Copiar</button></p>
                            <p><strong>Banco:</strong> Banco Provincia Bs. As.</p>
                            <p><strong>CUIT:</strong> 30-68509781-4</p>
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