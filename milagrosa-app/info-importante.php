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
    error_log("Error de conexión en info_importante.php: " . $conn->connect_error);
    $error_conexion = "No se pudo conectar con la base de datos. Intente más tarde.";
} else {
    $error_conexion = null;
    $conn->set_charset('utf8mb4');
}

// ============================================
//  FUNCIÓN AUXILIAR (compatibilidad)
//  Idealmente mover a un archivo común como 'funciones.php'
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

function parseIndividualMensaje($mensaje) {
    $raw = trim((string)$mensaje);
    $raw = preg_replace('/^📢\s*/u', '', $raw);
    $raw = preg_replace('/^\[Individual\]\s*/u', '', $raw);

    $titulo = 'Comunicado Individual';
    $contenido = $raw;
    if (preg_match('/^([^:]{1,120}):\s*(.*)$/us', $raw, $m)) {
        $titulo = trim($m[1]) !== '' ? trim($m[1]) : $titulo;
        $contenido = trim($m[2]) !== '' ? trim($m[2]) : $contenido;
    }
    return ['titulo' => $titulo, 'contenido' => $contenido];
}

// ============================================
//  NOTIFICACIONES NO LEÍDAS
// ============================================
$noLeidas = 0;
if (!$error_conexion) {
    $nro_familia_int = filter_var($nro_familia, FILTER_VALIDATE_INT);
    if ($nro_familia_int !== false && $nro_familia_int > 0) {
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
    } else {
        error_log("nro_familia no válido en info_importante.php");
    }
}

// ============================================
//  COMUNICADOS ACTIVOS (ordenados por fecha descendente)
// ============================================
$comunicados = [];
if (!$error_conexion) {
    $sqlComunicados = "SELECT * FROM comunicados WHERE activo = 1 ORDER BY fecha DESC";
    $stmtComunicados = $conn->prepare($sqlComunicados);
    if ($stmtComunicados) {
        $stmtComunicados->execute();
        $comunicados = fetch_all_from_stmt($stmtComunicados);
        $stmtComunicados->close();
    } else {
        error_log("Error prepare comunicados: " . $conn->error);
    }

    // Comunicados individuales de la familia desde notificaciones
    $nro_familia_int = filter_var($nro_familia, FILTER_VALIDATE_INT);
    if ($nro_familia_int !== false && $nro_familia_int > 0) {
        $sqlIndividuales = "SELECT id, mensaje, fecha
                            FROM notificaciones
                            WHERE nro_familia = ?
                              AND (mensaje LIKE ? OR mensaje LIKE ?)
                            ORDER BY fecha DESC";
        $stmtIndividuales = $conn->prepare($sqlIndividuales);
        if ($stmtIndividuales) {
            $prefijoNuevo = '[Individual] %';
            $prefijoAnterior = '📢 [Individual] %';
            $stmtIndividuales->bind_param("iss", $nro_familia_int, $prefijoNuevo, $prefijoAnterior);
            $stmtIndividuales->execute();
            $individuales = fetch_all_from_stmt($stmtIndividuales);
            $stmtIndividuales->close();

            foreach ($individuales as $ind) {
                $partes = parseIndividualMensaje($ind['mensaje'] ?? '');
                $comunicados[] = [
                    'id' => 'individual-' . (string)($ind['id'] ?? ''),
                    'titulo' => $partes['titulo'],
                    'contenido' => $partes['contenido'],
                    'fecha' => $ind['fecha'] ?? null,
                    'activo' => 1,
                    'es_individual' => 1,
                ];
            }
        } else {
            error_log("Error prepare comunicados individuales: " . $conn->error);
        }
    }

    // Reordenar comunicados generales + individuales por fecha descendente
    usort($comunicados, function ($a, $b) {
        $fa = strtotime((string)($a['fecha'] ?? ''));
        $fb = strtotime((string)($b['fecha'] ?? ''));
        if ($fa === $fb) {
            return 0;
        }
        return ($fa < $fb) ? 1 : -1;
    });
}

// ============================================
//  DATOS DE ALUMNOS (misma lógica que home.php)
// ============================================
function esCuotaFutura($numCuota, $cuotaVigente, $mesActual, $curso = '') {
    return cuota_es_futura_para_curso((int)$numCuota, (int)$cuotaVigente, (int)$mesActual, (string)$curso);
}

$alumnos = [];
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

    // Alumnos activos
    $sql = "SELECT *, 0 AS es_inactivo FROM legajos WHERE nro_familia = ?";
    $stmt = $conn->prepare($sql);
    $alumnos_activos = [];
    if ($stmt) {
        $stmt->bind_param("s", $nro_familia);
        $stmt->execute();
        $alumnos_activos = fetch_all_from_stmt($stmt);
        $stmt->close();
    } else {
        error_log("Error prepare legajos activos: " . $conn->error);
    }

    // Alumnos inactivos (misma lógica que home)
    $sql_inactivos = "SELECT *, 1 AS es_inactivo FROM legajos_inactivos WHERE nro_familia = ?";
    $stmt_inac = $conn->prepare($sql_inactivos);
    $alumnos_inactivos = [];
    if ($stmt_inac) {
        $stmt_inac->bind_param("s", $nro_familia);
        $stmt_inac->execute();
        $alumnos_inactivos = fetch_all_from_stmt($stmt_inac);
        $stmt_inac->close();
    } else {
        error_log("Error prepare legajos inactivos: " . $conn->error);
    }

    $alumnos = array_merge($alumnos_activos, $alumnos_inactivos);

    foreach ($alumnos as $alumno) {
        $legajo = $alumno['nro_legajo'] ?? '';
        $esInactivo = (bool)($alumno['es_inactivo'] ?? 0);

        $sqlCuotas = "SELECT numero_cuota, diferencia FROM cuotas WHERE nro_legajo = ? AND diferencia > 0";
        $stmtCuotas = $conn->prepare($sqlCuotas);
        if (!$stmtCuotas) {
            error_log("Error prepare cuotas: " . $conn->error);
            continue;
        }
        $stmtCuotas->bind_param("s", $legajo);
        $stmtCuotas->execute();
        $cuotasAlumno = fetch_all_from_stmt($stmtCuotas);
        $stmtCuotas->close();

        // Solo cuotas vigentes (igual que home.php)
        $saldoVigente = 0.0;
        foreach ($cuotasAlumno as $c) {
            if (!esCuotaFutura($c['numero_cuota'], $cuota_vigente, $mesActual, (string)($alumno['curso'] ?? ''))) {
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
}

if ($conn && !$conn->connect_error) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Informacion Importante</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <link rel="stylesheet" href="./css/main.css">
    <link rel="stylesheet" href="./css/pages/info-importante.css">
</head>
<body data-page="info-importante">

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
        <!-- Botón para abrir modal de pago -->
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

    <!-- Bloque de introducción -->
    <div class="container-fluid" style="background-color:#0c3484e8;box-shadow: 0 8px 7px rgba(0, 0, 0, 1);">
        <div class="page-header" style="color:white; border-radius:20px; padding:0 10px; margin:10px 0 20px;">
            <br>
            <h1 class="text-titles"><b>🔔 Avisos Administrativos</b></h1><br>
            <p style="font-size:18px;">
                En esta sección se publicarán los comunicados oficiales emitidos por la Administración del Jardin de Infantes La Milagrosa, con el objetivo de mantener a las familias debidamente informadas.<br><br>
                Aquí podrán encontrar información actualizada y relevante vinculada al ciclo lectivo, novedades institucionales, disposiciones administrativas, actualizaciones arancelarias, fechas de vencimiento, aplicación de intereses, modalidades y medios de pago, así como cualquier otra comunicación importante relacionada con la gestión administrativa.<br><br>
            </p>
        </div>
    </div>

    <?php if ($error_conexion): ?>
        <div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:8px;margin:20px;">
            <?php echo htmlspecialchars($error_conexion, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <!-- COMUNICADOS DINÁMICOS -->
    <div class="container-fluid" style="margin-top:20px;">
        <?php if (empty($comunicados)): ?>
            <div style="
                background:#d1dae9;
                border-radius:16px;
                padding:20px;
                box-shadow:0 6px 20px rgba(0,0,0,.12);
                border-left:6px solid #1c22ef;
            ">
                <h3 style="margin-top:0;color:#0c3484;">
                    📢 No hay comunicados
                </h3>
                <p>No hay comunicados publicados aún.</p>
            </div>
        <?php else: ?>
            <?php
            // Calculamos el número total para asignar el más alto al más nuevo
            $totalComunicados = count($comunicados);
            $numero = $totalComunicados;
            ?>
            <?php foreach ($comunicados as $comunicado): ?>
            <div style="
                background: #d4e7ff;
                border-radius:16px;
                padding:20px;
                box-shadow:0 12px 10px rgba(0,0,0,1);
                border-left:6px solid #1c2ea4;
                margin-bottom: 20px;
            ">
                <h3 style="margin-top:0;color:#0c3484;">
                    <?php if (!empty($comunicado['es_individual'])): ?>
                        📌 Comunicado Individual Nº <?php echo $numero--; ?>
                    <?php else: ?>
                        📢 Comunicado Nº <?php echo $numero--; ?>
                    <?php endif; ?>
                </h3>

                <h4 style="margin:10px 0;">
                    <?php echo htmlspecialchars($comunicado['titulo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </h4>

                <p style="font-size:16px;line-height:1.6; white-space: pre-line;">
                    <?php echo htmlspecialchars($comunicado['contenido'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </p>

                <?php
                $comunicadoId = (int)($comunicado['id'] ?? 0);
                $tienePdf = empty($comunicado['es_individual'])
                    && $comunicadoId > 0
                    && !empty($comunicado['archivo_pdf']);
                if ($tienePdf):
                ?>
                <div class="comunicado-pdf-download">
                    <a href="php/descargar_comunicado_pdf.php?id=<?php echo $comunicadoId; ?>" class="btn-descargar-pdf">
                        Descargar PDF
                    </a>
                </div>
                <?php endif; ?>

                <p style="font-size:14px;color:#666;margin-top:15px;">
                    📅 Publicado: <?php echo isset($comunicado['fecha']) ? date('d/m/Y', strtotime($comunicado['fecha'])) : 'Fecha desconocida'; ?>
                </p>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
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
        <button class="btn-opcion" data-tipo="familia" style="padding:15px;border:2px solid #0c3484;border-radius:12px;background:#fff;color:#0c3484;cursor:pointer;font-weight:bold;transition:all 0.3s">
            👨🏻‍👩🏻‍👧🏻‍👦🏻 Pagar TODO el total familiar
        </button>
        <button class="btn-opcion" data-tipo="alumno" style="padding:15px;border:2px solid #0c3484;border-radius:12px;background:#fff;color:#0c3484;cursor:pointer;font-weight:bold;transition:all 0.3s">
            👨🏽‍🎓👩🏻‍🎓 Pagar total de un alumno específico
        </button>
        <button class="btn-opcion" data-tipo="cuota" style="padding:15px;border:2px solid #0c3484;border-radius:12px;background:#fff;color:#0c3484;cursor:pointer;font-weight:bold;transition:all 0.3s">
            📅 Pagar una cuota específica
        </button>
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
                            <div>
                                <p><strong>Titular:</strong> Jardin de Infantes La Milagrosa</p>
                                <p><strong>CBU:</strong> 
                                    <span id="cbuText">0140058801500100639139</span>
                                    <button id="btnCopiarCBU" style="margin-left:8px;padding:4px 8px;border:1px solid #0c3484;border-radius:4px;background:#fff;cursor:pointer">📋 Copiar</button>
                                </p>
                                <p><strong>Banco:</strong> Banco Provincia Bs. As.</p>
                                <p><strong>CUIT:</strong> 30-68509781-4</p>
                            </div>
                        </div>
                        <p style="margin-top:10px;font-size:13px;color:#555;">
                            Copiá el CBU y pegalo en tu homebanking. En concepto/observaciones colocá la <strong>referencia</strong> indicada arriba (ej: <em>53096</em>) para que podamos identificar el pago.
                        </p>
                        <p style="margin-top:4px;font-size:12px;color:#777;">
                            Recordá que si tenés cuotas anteriores impagas, el pago de una cuota específica puede incluir el arrastre de saldos pendientes.
                        </p>
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
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?></script>
<script type="module" src="./frontend/js/index.js"></script>
</body>
</html>