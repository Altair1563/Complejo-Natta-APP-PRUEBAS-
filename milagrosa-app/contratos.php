<?php
require_once __DIR__ . '/config/session.php';
secure_session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    header('Location: /milagrosa-app/index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

define('_ACCESS', true);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/backend/lib/ingresantes_externos_2027.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log('Error de conexión en contratos.php: ' . $conn->connect_error);
    $error_conexion = 'No se pudo conectar con la base de datos. Intente más tarde.';
} else {
    $error_conexion = null;
    $conn->set_charset('utf8mb4');
}

function fetch_all_from_stmt($stmt)
{
    $rows = [];
    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
    } else {
        $stmt->store_result();
        $meta = $stmt->result_metadata();
        if (!$meta) {
            return $rows;
        }
        $fields = [];
        $row = [];
        while ($field = $meta->fetch_field()) {
            $fields[] = &$row[$field->name];
        }
        call_user_func_array([$stmt, 'bind_result'], $fields);
        while ($stmt->fetch()) {
            $r = [];
            foreach ($row as $k => $v) {
                $r[$k] = $v;
            }
            $rows[] = $r;
        }
        unset($row);
    }
    return $rows;
}

$nro_familia_raw = $_SESSION['nro_familia'];
$nro_familia_int = filter_var($nro_familia_raw, FILTER_VALIDATE_INT);
if ($nro_familia_int === false || $nro_familia_int <= 0) {
    if (function_exists('destroy_session_fully')) {
        destroy_session_fully();
    }
    header('Location: /milagrosa-app/index.php');
    exit;
}

$noLeidas = 0;
if (!$error_conexion) {
    $sqlNotif = 'SELECT COUNT(*) as noLeidas FROM notificaciones WHERE nro_familia = ? AND leido = 0';
    $stmtNotif = $conn->prepare($sqlNotif);
    if ($stmtNotif) {
        $stmtNotif->bind_param('i', $nro_familia_int);
        $stmtNotif->execute();
        $resultNotif = fetch_all_from_stmt($stmtNotif);
        $stmtNotif->close();
        if (!empty($resultNotif)) {
            $noLeidas = (int)$resultNotif[0]['noLeidas'];
        }
    }
}

$alumnos = [];
if (!$error_conexion) {
    $sql = 'SELECT nro_legajo, nombre_alumno, apellido_alumno, dni_alumno, COALESCE(curso, \'\') AS curso, 0 AS es_inactivo
            FROM legajos WHERE nro_familia = ?
            UNION ALL
            SELECT nro_legajo, nombre_alumno, apellido_alumno, dni_alumno, COALESCE(curso, \'\') AS curso, 1 AS es_inactivo
            FROM legajos_inactivos WHERE nro_familia = ?
            ORDER BY apellido_alumno ASC, nombre_alumno ASC';
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $nro_familia_raw, $nro_familia_raw);
        $stmt->execute();
        $alumnos = fetch_all_from_stmt($stmt);
        $stmt->close();
    } else {
        error_log('Error prepare legajos contratos: ' . $conn->error);
    }
}

function esCuotaFuturaPago($numCuota, $cuotaVigente, $mesActual, $curso = '')
{
    return cuota_es_futura_para_curso((int)$numCuota, (int)$cuotaVigente, (int)$mesActual, (string)$curso);
}

$alumnosConSaldo = [];
$saldoTotalFamiliar = 0.0;

if (!$error_conexion && !empty($alumnos)) {
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

    foreach ($alumnos as $alumno) {
        $legajo = $alumno['nro_legajo'] ?? '';
        $sqlSaldo = 'SELECT numero_cuota, diferencia FROM cuotas WHERE nro_legajo = ? AND diferencia > 0';
        $stmtSaldo = $conn->prepare($sqlSaldo);
        if (!$stmtSaldo) {
            continue;
        }
        $stmtSaldo->bind_param('s', $legajo);
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
            'legajo' => (string)$legajo,
            'nombre_completo' => trim(($alumno['nombre_alumno'] ?? '') . ' ' . ($alumno['apellido_alumno'] ?? '')),
            'curso' => $alumno['curso'] ?? '',
            'saldo' => $saldoVigente,
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <title>Contratos</title>
    <link rel="stylesheet" href="./css/main.css">
    <link rel="stylesheet" href="./css/pages/contratos.css">
    <link rel="stylesheet" href="./css/components/contract-modal-header.css">
</head>
<body data-page="contratos">

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
                    <b>Instituto Jardin de Infantes La Milagrosa</b>
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
        <div>
            <button id="btnPagarGeneral1" style="margin:20px;padding:8px 70px;border-radius:12px;background:#0c3484;color:#fff;cursor:pointer;">Pagar / Opciones</button>
        </div>
    </div>
</section>

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

    <div id="notificacionesPanel">
        <h4>Notificaciones</h4>
        <div id="notificacionesContent"><div class="notif-empty">Cargando...</div></div>
    </div>

    <div class="container-fluid" style="margin-top:30px;">

        <div class="contratos-hero">
            <h1 class="text-titles"><b>Contratos</b></h1>
            <p class="contratos-hero-lead">En esta sección podrá visualizar y firmar el <strong>Contrato de Servicios Educativos vigente</strong> y el <strong>Reglamento Institucional</strong> correspondiente a cada alumno del grupo familiar.</p>
        </div>

        <?php if ($error_conexion): ?>
            <div class="contratos-alert contratos-alert-error">
                <?php echo htmlspecialchars($error_conexion, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="contratos-info-box">
            <div class="contratos-estados-leyenda" role="region" aria-label="Leyenda de estados del contrato 2027">
                <p class="contratos-estados-leyenda-title"><strong>Estados del contrato 2027</strong> — significado de los colores de estado:</p>
                <ul class="contratos-estados-leyenda-list">
                    <li>
                        <span class="leyenda-emoji" aria-hidden="true">🔴</span>
                        <span><strong class="leyenda-label leyenda-label--pendiente">Rojo — Pendiente de firma</strong><br>La firma del contrato aún no fue registrada.</span>
                    </li>
                    <li>
                        <span class="leyenda-emoji" aria-hidden="true">🟠</span>
                        <span><strong class="leyenda-label leyenda-label--revision">Naranja — Firmado / Requisitos pendientes</strong><br>El contrato ya fue firmado digitalmente, pero aún no tiene validez hasta cumplir la documentación, el pago de la Reserva de Vacante 2027 y no registrar deudas del ciclo 2026.</span>
                    </li>
                    <li>
                        <span class="leyenda-emoji" aria-hidden="true">🟢</span>
                        <span><strong class="leyenda-label leyenda-label--aprobado">Verde — Contrato aprobado</strong><br>La firma fue registrada correctamente, la documentación fue validada por Secretaría y la Reserva de Vacante 2027 se encuentra acreditada.</span>
                    </li>
                </ul>
            </div>

            <div class="contratos-aviso-noviembre" role="note" aria-label="Requisito para habilitar la firma">
                <p class="contratos-aviso-noviembre-title"><strong>Requisito para habilitar la firma:</strong></p>
                <p>La firma del contrato estará disponible cuando se cumpla el requisito de pago correspondiente a cada tipo de alumno.</p>
                <ul class="contratos-intro-list">
                    <li>En alumnos regulares se habilita una vez que la cuota de <strong>NOVIEMBRE</strong> se encuentre abonada.</li>
                    <li>En alumnos <strong>ingresantes 2027</strong> (curso <strong>NUI</strong>) se habilita únicamente cuando figure pagado el <strong>ADELANTO RV 2027</strong> (Adelanto de Reserva de vacante).</li>
                </ul>
            </div>

            <p class="contratos-info-subtitle"><strong>Desde esta sección podrá:</strong></p>
            <ul class="contratos-intro-list">
                <li>Consultar el estado actual de cada contrato.</li>
                <li>Realizar la firma digital del contrato cuando corresponda.</li>
                <li>Leer el contrato con los datos del responsable antes de firmar y, una vez firmado, descargarlo en PDF con la información aplicada.</li>
            </ul>

            <div class="contratos-info-importante" role="note" aria-label="Información importante">
                <p class="contratos-info-importante-title"><strong>Importante:</strong></p>
                <p>La matrícula y aprobación definitiva para el ciclo lectivo 2027 quedará sujeta al cumplimiento de las siguientes condiciones:</p>
                <ul class="contratos-intro-list contratos-condiciones-list">
                    <li>Presentación de la documentación requerida por cada institución.</li>
                    <li>Pago de la Reserva de Vacante 2027 (se habilitará una vez definidos los aranceles correspondientes. En el caso de los alumnos ingresantes, la Reserva de Vacante se abonará en dos etapas: Adelanto de Reserva de Vacante y Resto de Reserva de Vacante).</li>
                    <li>Que el grupo familiar no registre deudas pendientes al finalizar el ciclo lectivo 2026.</li>
                    <li>Firma y aceptación del Contrato de Servicios Educativos y del Reglamento Institucional vigente.</li>
                    <li>Cumplimiento de los requisitos académicos y administrativos establecidos por la institución.</li>
                </ul>
            </div>

            <div class="contratos-seguridad-aviso" role="note" aria-label="Aviso de seguridad">
                <p>Por motivos de seguridad, el sistema solicitará <strong>reconfirmar la contraseña de acceso</strong> antes de registrar la firma digital.</p>
            </div>
        </div>

        <?php if (empty($alumnos)): ?>
            <div class="contratos-empty-card">
                <p>No hay alumnos registrados en este grupo familiar.</p>
            </div>
        <?php else: ?>
            <div class="alumnos-grid">
            <?php foreach ($alumnos as $alumno):
                $legajo = $alumno['nro_legajo'] ?? '';
                $nombreCompleto = trim(($alumno['nombre_alumno'] ?? '') . ' ' . ($alumno['apellido_alumno'] ?? ''));
                $dni = trim((string)($alumno['dni_alumno'] ?? ''));
                $esInactivo = ((int)($alumno['es_inactivo'] ?? 0) === 1);
                ?>
            <div class="alumno-card contrato-alumno-card" data-legajo="<?php echo htmlspecialchars($legajo, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="contrato-alumno-header">
                    <div class="contrato-alumno-identidad">
                        <h4 class="alumno-nombre"><?php echo htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8'); ?></h4>
                        <p class="contrato-alumno-meta">
                            DNI <?php echo htmlspecialchars($dni !== '' ? $dni : '—', ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($legajo !== ''): ?>
                                · Legajo <?php echo htmlspecialchars($legajo, ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                            <?php if (!empty($alumno['curso'])): ?>
                                · Curso <?php echo htmlspecialchars($alumno['curso'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                            <?php if ($esInactivo): ?>
                                <span class="contrato-badge-inactivo">Inactivo</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="contrato-alumno-actions">
                        <span class="contrato-estado-text contrato-estado--loading" data-contrato-estado-dni="<?php echo htmlspecialchars($dni, ENT_QUOTES, 'UTF-8'); ?>"><span class="contrato-estado-spinner" aria-hidden="true"></span><span class="contrato-estado-msg">Consultando estado…</span></span>
                        <button
                            type="button"
                            class="btn-contrato btn-contract-pending"
                            data-student-dni="<?php echo htmlspecialchars($dni, ENT_QUOTES, 'UTF-8'); ?>"
                            data-legajo="<?php echo htmlspecialchars($legajo, ENT_QUOTES, 'UTF-8'); ?>"
                            data-es-inactivo="<?php echo $esInactivo ? '1' : '0'; ?>"
                            style="<?php echo $esInactivo ? 'display:none;' : ''; ?>"
                            data-nombre="<?php echo htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8'); ?>">
                            Contrato Pendiente
                        </button>
                    </div>
                </div>
                <div
                    class="contrato-requisitos-card"
                    data-contrato-requisitos-dni="<?php echo htmlspecialchars($dni, ENT_QUOTES, 'UTF-8'); ?>"
                    hidden
                    aria-hidden="true"></div>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</section>

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
                            <p><strong>Titular:</strong> Instituto Jardin de Infantes La Milagrosa</p>
                            <p><strong>CBU:</strong> <span id="cbuText">0110661520066100245226</span> <button id="btnCopiarCBU" style="margin-left:8px;padding:4px 8px;border:1px solid #0c3484;border-radius:4px;background:#fff;cursor:pointer">📋 Copiar</button></p>
                            <p><strong>Banco:</strong> Banco Nación</p>
                            <p><strong>CUIT:</strong> 30-67618077-6</p>
                        </div>
                        <p style="margin-top:10px;font-size:13px;color:#555;">Copiá el CBU y pegalo en tu homebanking. En concepto/observaciones colocá la <strong>referencia</strong> indicada arriba para que podamos identificar el pago.</p>
                    </div>
                </div>
            </details>
        </div>
    </div>
    <button id="cerrarModal" style="margin-top:20px;padding:10px 20px;border:1px solid #ddd;border-radius:10px;background:#f8f9fa;cursor:pointer">Cerrar</button>
</div>

<script src="./js/jquery-3.1.1.min.js"></script>
<script src="./js/bootstrap.min.js"></script>
<script src="./js/material.min.js"></script>
<script src="./js/ripples.min.js"></script>
<script src="./js/sweetalert2.min.js"></script>
<script src="./js/jquery.mCustomScrollbar.concat.min.js"></script>
<script>if (typeof $ !== 'undefined') $(function(){ $.material.init(); });</script>
<script id="page-data" type="application/json"><?php echo json_encode([
    'csrfToken' => $csrf_token,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="module" src="./frontend/js/index.js"></script>
</body>
</html>
