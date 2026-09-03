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
    error_log("Error de conexión en talondepago.php: " . $conn->connect_error);
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
//  MAPEO DE NÚMERO DE CUOTA A NOMBRE
// ============================================
$nombres_cuotas = [
    1  => 'Marzo',
    2  => 'Abril',
    3  => 'Mayo',
    4  => 'Junio',
    5  => 'Julio',
    6  => 'Agosto',
    7  => 'Septiembre',
    8  => 'Octubre',
    9  => 'Noviembre',
    10 => 'Adelanto de Reserva de vacante (2027)',
    11 => 'Resto Reserva de Vacante',
    12 => 'Reserva de Vacante 2026'
];

// ============================================
//  OBTENER ALUMNOS DEL GRUPO FAMILIAR
// ============================================
$alumnos = [];
$cuotasPorAlumno = [];
$alumnosConSolicitudes = [];
$alumnoTieneSolicitudActiva = [];

if (!$error_conexion) {
    $sql = "SELECT nro_legajo, nombre_alumno, apellido_alumno, curso FROM legajos WHERE nro_familia = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $nro_familia_raw);
        $stmt->execute();
        $alumnos = fetch_all_from_stmt($stmt);
        $stmt->close();

        // ============================================
        //  OBTENER CUOTAS DE CADA ALUMNO (SIN DUPLICADOS)
        // ============================================
        foreach ($alumnos as $alumno) {
            $legajo = $alumno['nro_legajo'];
            
            $sqlCuotas = "
                SELECT c.id, c.numero_cuota, c.monto_facturado, c.diferencia
                FROM cuotas c
                INNER JOIN (
                    SELECT numero_cuota, MAX(id) as max_id
                    FROM cuotas
                    WHERE nro_legajo = ?
                    GROUP BY numero_cuota
                ) ultimas ON c.id = ultimas.max_id
                WHERE c.nro_legajo = ?
                ORDER BY c.numero_cuota ASC
            ";
            $stmtCuotas = $conn->prepare($sqlCuotas);
            if ($stmtCuotas) {
                $stmtCuotas->bind_param("ss", $legajo, $legajo);
                $stmtCuotas->execute();
                $cuotas = fetch_all_from_stmt($stmtCuotas);
                $stmtCuotas->close();

                foreach ($cuotas as &$cuota) {
                    $cuota['pagada'] = ($cuota['diferencia'] <= 0);
                    $num = (int)$cuota['numero_cuota'];
                    $cuota['descripcion'] = cuota_nombre_para_curso(
                        $num,
                        (string)($alumno['curso'] ?? ''),
                        $nombres_cuotas[$num] ?? null
                    );
                    $cuota['monto'] = $cuota['monto_facturado'];
                }
                unset($cuota);
                if (curso_es_ingresante_externo_2027((string)($alumno['curso'] ?? ''))) {
                    $cuotas = array_values(array_filter($cuotas, static function ($cuota) {
                        return (int)($cuota['numero_cuota'] ?? 0) === ingresante_externo_2027_numero_cuota();
                    }));
                }
                $cuotasPorAlumno[$legajo] = $cuotas;
            } else {
                error_log("Error prepare cuotas para legajo $legajo: " . $conn->error);
            }
        }

        // ============================================
        //  OBTENER SOLICITUDES DE TALÓN PARA LAS CUOTAS
        // ============================================
        $todosIdsCuotas = [];
        foreach ($cuotasPorAlumno as $legajo => $cuotas) {
            foreach ($cuotas as $cuota) {
                $todosIdsCuotas[] = $cuota['id'];
            }
        }

        $solicitudesPorCuota = [];
        if (!empty($todosIdsCuotas)) {
            // Construir consulta con placeholders seguros
            $placeholders = implode(',', array_fill(0, count($todosIdsCuotas), '?'));
            $sqlSolicitudes = "SELECT cuota_id, estado, fecha_solicitud FROM solicitudes_talon WHERE cuota_id IN ($placeholders) ORDER BY cuota_id, fecha_solicitud DESC";
            $stmtSolicitudes = $conn->prepare($sqlSolicitudes);
            if ($stmtSolicitudes) {
                // Crear array de tipos (todos enteros) y pasar parámetros
                $types = str_repeat('i', count($todosIdsCuotas));
                $stmtSolicitudes->bind_param($types, ...$todosIdsCuotas);
                $stmtSolicitudes->execute();
                $resultSolicitudes = $stmtSolicitudes->get_result();
                while ($row = $resultSolicitudes->fetch_assoc()) {
                    $cuotaId = $row['cuota_id'];
                    if (!isset($solicitudesPorCuota[$cuotaId])) {
                        $solicitudesPorCuota[$cuotaId] = $row;
                    }
                }
                $stmtSolicitudes->close();
            } else {
                error_log("Error prepare solicitudes_talon: " . $conn->error);
            }
        }

        // Agregar información de solicitudes a cada cuota y calcular si el alumno tiene solicitudes activas
        foreach ($cuotasPorAlumno as $legajo => &$cuotas) {
            $solicitudesActivas = [];
            $tieneActiva = false;
            foreach ($cuotas as &$cuota) {
                $cuotaId = $cuota['id'];
                if (isset($solicitudesPorCuota[$cuotaId])) {
                    $cuota['solicitud_estado'] = $solicitudesPorCuota[$cuotaId]['estado'];
                    $cuota['solicitud_fecha'] = $solicitudesPorCuota[$cuotaId]['fecha_solicitud'];
                    $activa = ($solicitudesPorCuota[$cuotaId]['estado'] != 'generado');
                    $cuota['solicitud_activa'] = $activa;
                    if ($activa) {
                        $tieneActiva = true;
                        $solicitudesActivas[] = [
                            'fecha' => $solicitudesPorCuota[$cuotaId]['fecha_solicitud'],
                            'estado' => $solicitudesPorCuota[$cuotaId]['estado']
                        ];
                    }
                } else {
                    $cuota['solicitud_estado'] = null;
                    $cuota['solicitud_fecha'] = null;
                    $cuota['solicitud_activa'] = false;
                }
            }
            // Guardar resumen de solicitudes activas para el alumno
            if (!empty($solicitudesActivas)) {
                $ultima = $solicitudesActivas[0];
                $alumnosConSolicitudes[$legajo] = [
                    'fecha' => $ultima['fecha'],
                    'estado' => $ultima['estado']
                ];
            }
            $alumnoTieneSolicitudActiva[$legajo] = $tieneActiva;
        }
        unset($cuota);
    } else {
        error_log("Error prepare legajos: " . $conn->error);
    }
}

// ============================================
//  DATOS PARA MODAL DE PAGO (misma lógica home)
// ============================================
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
    <title>Solicitar Talón de Pago</title>
    <link rel="stylesheet" href="./css/main.css">
    <link rel="stylesheet" href="./css/pages/talondepago.css">
</head>
<body data-page="talon-de-pago">

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

    <!-- SOLICITUD DE TALÓN DE PAGO -->
    <div class="container-fluid" style="margin-top:30px;">

        <div style="
            background-color:#0c3484e8;
            color:white;
            border-radius:20px;
            padding:20px;
            box-shadow:0 8px 7px rgba(0,0,0,0.9);
            margin-bottom:20px;
        ">
            <h1 class="text-titles"><b>🧾 Solicitud de Talón de Pago</b></h1>
        </div>

        <?php if ($error_conexion): ?>
            <div style="background:#f8d7da;color:#721c24;padding:15px;border-radius:8px;margin-bottom:20px;">
                <?php echo htmlspecialchars($error_conexion, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div style="
            background: #d4e7ff;
            border-radius:16px;
            padding:25px;
            box-shadow: 0 12px 10px rgba(0, 0, 0, 1);
            margin-bottom:25px;
        ">
            <p style="font-size:16px;line-height:1.6;">
                Esta sección permite a las familias solicitar el <b>talón de pago impreso</b> para aquellos alumnos
                que abonarán la cuota <b>en efectivo</b>. Haga clic en la cuota deseada para solicitar el talón.
                Si se selecciona una cuota impaga, se incluirán automáticamente todas las cuotas pendientes correspondientes a períodos anteriores.
            </p>
            <p style="font-size:16px;line-height:1.6;">
                El talón solicitado será emitido por la administración y entregado al alumno correspondiente,
                permitiendo realizar el pago únicamente en <b>BANCO NACION</b>, por ventanilla.
            </p>
            <h4 style="color:#0c3484;">💵 Importante</h4>
            <ul style="font-size:16px;line-height:1.6;">
                <li>El pago en efectivo solo podrá realizarse con el talón impreso en impresora láser.</li>
                <li>El talón deberá presentarse exclusivamente en Banco Nación.</li>
                <li>La emisión puede demorar hasta 48 horas hábiles.</li>
                <li>Las cuotas con solicitud pendiente aparecen atenuadas y no se pueden volver a solicitar hasta que la administración las genere.</li>
                <li>Si ya tienes una solicitud activa para un alumno, no podrás solicitar más hasta que la cancele o la administración la procese.<br> Puedes cancelarla usando el botón ❌ junto a la fecha.</li>
            </ul>
        </div>

        <!-- Contenedor de mensajes global (oculto, ya no se usa) -->
        <div id="mensajeContainer" style="display:none;"></div>

        <!-- LISTADO DE ALUMNOS CON SUS CUOTAS -->
        <?php if (empty($alumnos)): ?>
            <div style="background:#ffffff; border-radius:16px; padding:20px; text-align:center;">
                <p>No hay alumnos registrados en este grupo familiar.</p>
            </div>
        <?php else: ?>
            <?php foreach ($alumnos as $alumno): 
                $legajo = $alumno['nro_legajo'];
                $nombreCompleto = trim($alumno['nombre_alumno'] . ' ' . $alumno['apellido_alumno']);
                $cuotas = $cuotasPorAlumno[$legajo] ?? [];
                $tieneSolicitud = isset($alumnosConSolicitudes[$legajo]);
                $infoSolicitud = $tieneSolicitud ? $alumnosConSolicitudes[$legajo] : null;
                $tieneActiva = $alumnoTieneSolicitudActiva[$legajo] ?? false;
            ?>
            <div class="alumno-card" data-legajo="<?php echo htmlspecialchars($legajo, ENT_QUOTES, 'UTF-8'); ?>" data-tiene-solicitud-activa="<?php echo $tieneActiva ? '1' : '0'; ?>">
                <div class="alumno-header">
                    <h4 class="alumno-nombre"><?php echo htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8'); ?></h4>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <!-- Contenedor para mensaje temporal de éxito -->
                        <div id="mensaje-<?php echo htmlspecialchars($legajo, ENT_QUOTES, 'UTF-8'); ?>" class="mensaje-alumno" style="display: none;"></div>
                        <?php if ($tieneSolicitud): ?>
                            <div class="solicitud-badge" title="Última solicitud: <?php echo htmlspecialchars($infoSolicitud['estado'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                📅 Solicitado: <?php echo date('d/m/Y', strtotime($infoSolicitud['fecha'])); ?> (<?php echo htmlspecialchars($infoSolicitud['estado'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)
                                <button class="btn-cancelar-solicitud" onclick="cancelarSolicitudesAlumno('<?php echo htmlspecialchars($legajo, ENT_QUOTES, 'UTF-8'); ?>')" title="Cancelar solicitud">❌</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($cuotas)): ?>
                    <p>No hay cuotas registradas para este alumno.</p>
                <?php else: ?>
                    <div class="cuota-lista">
                        <?php 
                        $pos = 0;
                        foreach ($cuotas as $cuota): 
                            $pagada = $cuota['pagada'];
                            $claseEstado = $pagada ? 'cuota-pagada' : 'cuota-impaga';
                            $estadoTexto = $pagada ? 'Pagada' : 'Impaga';
                            $deshabilitada = $cuota['solicitud_activa'] || $tieneActiva;
                        ?>
                        <div class="cuota-item <?php echo $claseEstado; ?> cuota <?php echo $deshabilitada ? 'cuota-deshabilitada' : ''; ?>" 
                             data-cuota-id="<?php echo $cuota['id']; ?>"
                             data-legajo="<?php echo htmlspecialchars($legajo, ENT_QUOTES, 'UTF-8'); ?>"
                             data-index="<?php echo $pos; ?>"
                             data-descripcion="<?php echo htmlspecialchars($cuota['descripcion'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                             data-pagada="<?php echo $pagada ? '1' : '0'; ?>"
                             data-monto="<?php echo $cuota['monto']; ?>"
                             data-solicitud-activa="<?php echo $cuota['solicitud_activa'] ? '1' : '0'; ?>"
                             <?php if ($deshabilitada): ?>
                             title="<?php echo $tieneActiva ? 'Ya existe una solicitud activa para este alumno. Cancélala para poder solicitar nuevamente.' : 'Solicitud pendiente para esta cuota'; ?>"
                             <?php endif; ?>
                             onclick="seleccionarCuota(this)">
                            <div><strong><?php echo htmlspecialchars($cuota['descripcion'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div><small><?php echo $estadoTexto; ?></small></div>
                        </div>
                        <?php 
                            $pos++;
                            endforeach; 
                        ?>
                    </div>
                    <?php if (!$tieneActiva): ?>
                        <button class="btn-solicitar btn-todos" onclick="solicitarTodasImpagas('<?php echo htmlspecialchars($legajo, ENT_QUOTES, 'UTF-8'); ?>')">
                            Solicitar todas las cuotas impagas
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
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
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script type="module" src="./frontend/js/index.js"></script>
</body>
</html>