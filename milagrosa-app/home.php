<?php
require_once __DIR__ . '/config/session.php';
secure_session_start();

// ============================================
//  CONFIGURACIÓN Y COMPROBACIÓN DE SESIÓN
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
define('_ACCESS', true); // Permite la inclusión del archivo de configuración
require_once __DIR__ . '/config/db.php'; // Ajusta la ruta según tu estructura

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión: " . $conn->connect_error);
    die("Lo sentimos, no se pudo conectar con la base de datos. Intente más tarde.");
}
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/admin/includes/functions.php';

// ============================================
//  OBTENER CUOTA VIGENTE DESDE CONFIGURACIÓN
// ============================================
$cuota_vigente = 1; // valor por defecto
$stmt_cfg = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'cuota_vigente'");
if ($stmt_cfg) {
    $stmt_cfg->execute();
    $stmt_cfg->bind_result($cv);
    if ($stmt_cfg->fetch()) {
        $cuota_vigente = (int)$cv;
    }
    $stmt_cfg->close();
}
$mesActual = (int)date('n'); // 1=enero ... 12=diciembre

// ============================================
//  FUNCIÓN AUXILIAR (compatibilidad sin mysqlnd)
// ============================================
function fetch_all_from_stmt($stmt)
{
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

// ============================================
//  FUNCIONES PARA MANEJO DE CUOTAS
// ============================================
function obtenerEscuelaDesdeCurso($curso)
{
    $curso = mb_strtoupper(trim($curso ?? ''), 'UTF-8');
    if ($curso !== '' && mb_strlen($curso, 'UTF-8') >= 2) {
        return mb_substr($curso, -2, 2, 'UTF-8');
    }
    return '';
}

function cuotaANumeroMesLogico($numero_cuota, $escuela)
{
    $numero_cuota = (int)$numero_cuota;
    if ($escuela === 'SU') {
        if ($numero_cuota >= 2 && $numero_cuota <= 10) {
            return $numero_cuota - 1;
        }
        return null; // SU no tiene RV
    } else {
        if ($numero_cuota >= 1 && $numero_cuota <= 9) {
            return $numero_cuota;
        }
        if ($numero_cuota >= 10 && $numero_cuota <= 12) {
            return $numero_cuota;
        }
        return null;
    }
}

// Función para determinar si una cuota es futura (misma que en ajax_historial)
function esCuotaFutura($numCuota, $cuotaVigente, $mesActual)
{
    $numCuota = (int)$numCuota;
    if ($numCuota <= 9) {
        return $numCuota > $cuotaVigente;
    } else {
        // Cuotas de reserva (10,11,12): futuras solo antes de marzo
        return $mesActual < 3;
    }
}

$nro_familia = $_SESSION['nro_familia'];

// ============================================
//  NOTIFICACIONES NO LEÍDAS
// ============================================
$nro_familia_int = (int)$nro_familia;
$sqlNotif = "SELECT COUNT(*) AS total FROM notificaciones WHERE nro_familia = ? AND leido = 0";
$stmtNotif = $conn->prepare($sqlNotif);
$stmtNotif->bind_param("i", $nro_familia_int);
$stmtNotif->execute();
$resNotif = $stmtNotif->get_result();
$noLeidas = 0;
if ($row = $resNotif->fetch_assoc()) {
    $noLeidas = (int)$row['total'];
}
$stmtNotif->close();

// Última actualización del sistema (mtime más reciente entre rptfacing.xls/.xlsx … rptfacing5)
$ultimaActualizacionSistema = admin_ultima_actualizacion_rptfacing(__DIR__ . '/config/imports');

// ============================================
//  OBTENER ALUMNOS ACTIVOS E INACTIVOS DE LA FAMILIA
// ============================================

// Alumnos activos (tabla legajos)
$sql = "SELECT *, 0 AS es_inactivo FROM legajos WHERE nro_familia = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $nro_familia);
$stmt->execute();
$alumnos_activos = fetch_all_from_stmt($stmt);
$stmt->close();

// Alumnos inactivos (tabla legajos_inactivos) con bandera es_inactivo = 1
$sql_inactivos = "SELECT *, 1 AS es_inactivo FROM legajos_inactivos WHERE nro_familia = ?";
$stmt_inac = $conn->prepare($sql_inactivos);
$stmt_inac->bind_param("s", $nro_familia);
$stmt_inac->execute();
$alumnos_inactivos = fetch_all_from_stmt($stmt_inac);
$stmt_inac->close();

// Fusionar ambos arreglos
$alumnos = array_merge($alumnos_activos, $alumnos_inactivos);

// ============================================
//  CALCULAR SALDOS POR ALUMNO (SÓLO CUOTAS VIGENTES) Y TOTAL FAMILIAR
// ============================================
$saldoTotalFamiliarVigente = 0.0;
$alumnosConSaldo = [];        // para JS, contiene saldo vigente
$saldoPorLegajo = [];          // (opcional) mantenemos por si se usa en otro lado
$rangoMesesPorLegajo = [];
$tieneFilasCuotasPorLegajo = []; // algún registro en `cuotas` para ese nro_legajo

foreach ($alumnos as $alumno) {
    $legajo = $alumno['nro_legajo'] ?? '';
    $esInactivo = (bool)$alumno['es_inactivo'];

    if ($legajo !== '') {
        $sqlExisteCuota = "SELECT 1 AS ok FROM cuotas WHERE nro_legajo = ? LIMIT 1";
        $stmtEx = $conn->prepare($sqlExisteCuota);
        $stmtEx->bind_param("s", $legajo);
        $stmtEx->execute();
        $filasEx = fetch_all_from_stmt($stmtEx);
        $stmtEx->close();
        $tieneFilasCuotasPorLegajo[$legajo] = !empty($filasEx);
    }

    // Obtener todas las cuotas del alumno (con diferencia > 0)
    $sqlCuotas = "SELECT numero_cuota, diferencia FROM cuotas WHERE nro_legajo = ? AND diferencia > 0";
    $stmtCuotas = $conn->prepare($sqlCuotas);
    $stmtCuotas->bind_param("s", $legajo);
    $stmtCuotas->execute();
    $cuotasAlumno = fetch_all_from_stmt($stmtCuotas);
    $stmtCuotas->close();

    // Calcular saldo vigente (excluyendo futuras)
    $saldoVigente = 0.0;
    foreach ($cuotasAlumno as $c) {
        if (!esCuotaFutura($c['numero_cuota'], $cuota_vigente, $mesActual)) {
            $saldoVigente += (float)$c['diferencia'];
        }
    }
    $saldoVigente = round($saldoVigente, 2);
    $saldoTotalFamiliarVigente += $saldoVigente;

    // Guardar en arrays para uso posterior
    $alumnosConSaldo[] = [
        'legajo'          => (string)$legajo,
        'nombre_completo' => trim(($alumno['nombre_alumno'] ?? '') . ' ' . ($alumno['apellido_alumno'] ?? '')),
        'curso'           => $alumno['curso'] ?? '',
        'saldo'           => $saldoVigente,
        'es_inactivo'     => $esInactivo,
    ];

    if ($legajo !== '') {
        $saldoPorLegajo[$legajo] = $saldoVigente; // para usar en el tile
    }

    // ============================================
    //  Calcular meses adeudados para el rango (solo vigentes)
    // ============================================
    $escuela = obtenerEscuelaDesdeCurso($alumno['curso'] ?? '');
    $mesesAdeudados = [];
    foreach ($cuotasAlumno as $c) {
        // Solo considerar cuotas NO futuras y con diferencia > 0.01
        if (!esCuotaFutura($c['numero_cuota'], $cuota_vigente, $mesActual) && $c['diferencia'] > 0.01) {
            $mesLogico = cuotaANumeroMesLogico($c['numero_cuota'], $escuela);
            if ($mesLogico !== null) {
                $mesesAdeudados[] = $mesLogico;
            }
        }
    }
    sort($mesesAdeudados);

    if (!empty($mesesAdeudados)) {
        $primerMes = $mesesAdeudados[0];
        $ultimoMes = $mesesAdeudados[count($mesesAdeudados) - 1];
        $rango = nombreMesCuota($primerMes);
        if ($primerMes != $ultimoMes) {
            $rango .= ' - ' . nombreMesCuota($ultimoMes);
        }
    } else {
        $rango = 'Alumno al Día';
    }

    $rangoMesesPorLegajo[$legajo] = $rango;
}

// ============================================
//  AUTO‑LEGAJO (si hay un solo alumno en total)
// ============================================
$autolegajo = (count($alumnos) === 1) ? ($alumnos[0]['nro_legajo'] ?? null) : null;

// ============================================
//  ÚLTIMO DÍA DEL MES QUE CORRESPONDE A LA CUOTA VIGENTE
//  (cuota 1=marzo … 9=noviembre del ciclo; no usar el mes calendario del servidor)
// ============================================
$ano_ciclo_lectivo = 2026;
$cv_venc = (int)$cuota_vigente;
if ($cv_venc >= 1 && $cv_venc <= 9) {
    $mes_venc = $cv_venc + 2; // 3=marzo, 4=abril, … 11=noviembre
    $ultimo_dia_mes = date('d/m/Y', mktime(0, 0, 0, $mes_venc + 1, 0, $ano_ciclo_lectivo));
} else {
    // Cuotas RV (10–12): sin mapeo mensual fijo en pantalla; mantener último día del mes actual
    $ultimo_dia_mes = date('d/m/Y', strtotime('last day of this month'));
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Inicio - Jardin de Infantes La Milagrosa</title>
    <link rel="stylesheet" href="./css/main.css" />
    <link rel="stylesheet" href="./css/pages/home.css" />
    <link rel="stylesheet" href="./css/components/contract-modal-header.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
</head>

<body data-page="home">

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
                        <i class="zmdi zmdi-balance zmdi-hc-fw"></i> Administracion <i class="zmdi zmdi-caret-down pull-right"></i>
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
            <div>
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
                <li class="notification-status-item">
                    <span class="system-last-update">
                        <span class="system-last-update-dot" aria-hidden="true"></span>
                        Ultima actualizacion: <?php echo htmlspecialchars($ultimaActualizacionSistema, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
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
            <div id="notificacionesContent">
                <div class="notif-empty">Cargando...</div>
            </div>
        </div>

        <!-- Bloque de introducción y medios de pago -->
        <div class="container-fluid" style="background-color:#0c3484e8;box-shadow: 0 8px 7px rgba(0, 0, 0, 1);">
            <div class="page-header" style="color:white; border-radius:20px; padding:0 10px; margin:10px 0 20px;">
                <br>
                <h1 class="text-titles"><b>📱 Bienvenidos a la APP de Jardin de Infantes La Milagrosa</b></h1><br>
                <p style="font-size:18px;">
                    Estimadas familias:<br>
                    Esta aplicación está diseñada para que puedan consultar el estado de cuenta de sus hijos y acceder a información importante de la administración de forma rápida y segura, desde cualquier dispositivo.<br><br>
                    Para que sus pagos se acrediten correctamente, una vez realizado el pago es indispensable enviar el comprobante descargado completo (donde debe figurar fecha, importe, número de transacción, cuenta emisora y CBU destinatario) al correo:<strong>📧 recibos.lamilagrosa@gmail.com</strong><br><br>
                    <strong>🚨 Importante:</strong> Los pagos pueden demorar hasta <strong>72 horas hábiles</strong> en reflejarse en el sistema.<br>
                    <strong>⚠️ Atencion:</strong> Todas las cuotas <strong>estan sujetas a la aplicacion de intereses</strong> pasado un mes de su fecha de vencimiento.

                </p>

                <div>
                    <h3 class="text-titles"><b>💳 Medios de Pago</b></h3>
                </div>
                <div style="padding-right:5px;padding-left: 5px; border-radius:20px; box-shadow:0 4px 7px rgba(0,0,0,1); background-color:#082359;" class="container-fluid">
                    <div class="row-mediosdepago">
                        <div class="col-mediosdepago">
                            <u><b>Transferencia Bancaria</b></u><br>
                            * TITULAR: Complejo Educ.pbro.e.e natta.<br>
                            * CBU: 0110661520066100245226<br>
                            * Referencia: Nº de legajo o DNI del alumno
                        </div>
                        <div class="col-mediosdepago">
                            <u><b>Red Link / Pago mis Cuentas</b></u><br>
                            * Código de pago: DNI del alumno<br>
                            * Ingresar también el número de referencia que figura en el talón.
                        </div>
                        <div class="col-mediosdepago">
                            <u><b>Efectivo</b></u><br>
                            * En cualquier Banco Nación, por caja.<br>
                            * Presentar el código de barras de la boleta de pago. (impresa en impresora laser)
                        </div>
                    </div>
                </div><br>
            </div>
        </div>

        <!-- Panel de información (tiles) -->
        <div class="container-fluid" style="background-color:#ffffff00;">
            <div class="page-header" style="text-align-last:center; background-color:#082359e8; color:white; border-radius:30px; box-shadow:0 8px 7px rgba(0,0,0,1);">
                <h1 class="text-titles"><b style="position:relative; top:10px;left: 10px;">Información del Alumno - Ciclo Lectivo 2026</b></h1>
            </div>
        </div>

        <div class="full-box" style="text-align-last:center;">
            <?php if (!empty($alumnos)): ?>
                <?php $i = 0;
                foreach ($alumnos as $alumno):
                    $leg = $alumno['nro_legajo'] ?? '';
                    $saldoParaTile = $saldoPorLegajo[$leg] ?? 0.0; // ya es vigente
                    $rangoMeses = $rangoMesesPorLegajo[$leg] ?? 'Alumno al Día';
                    // Usamos la bandera es_inactivo para saber si es inactivo
                    $esInactivo = (bool)$alumno['es_inactivo'];
                    // Inactivo con deuda vigente en `cuotas`: mostrar igual que activo en Total a pagar
                    $mostrarTotalesPago = !$esInactivo || (float)$saldoParaTile > 0.01;
                    $tieneFilasCuotas = $tieneFilasCuotasPorLegajo[$leg] ?? false;
                    $inactivoSinCuotas = $esInactivo && !$tieneFilasCuotas;

                    if ($esInactivo) {
                        $bgColor = '#b57272c9;'; // gris para inactivos
                    } else {
                        $bgColor = ($i % 2 == 0) ? '#1e6dcfc7' : '#a3ccffc7';
                    }

                ?>
                    <div style="background-color: <?= $bgColor ?>;border-radius: 125px; width: 95%; justify-self: center; margin: 25px; box-shadow:0 8px 7px rgba(0,0,0,1);">
                        <article class="full-box tile">
                            <div class="full-box tile-title text-center text-titles text-uppercase">Datos Alumno</div>
                            <div class="full-box tile-icon text-center"><i class="zmdi zmdi-pin-account"></i></div>
                            <div class="full-box tile-number text-center">
                                <span class="alumno-info">
                                    <medium>
                                        <b><?= htmlspecialchars(trim(($alumno['nombre_alumno'] ?? '') . ' ' . ($alumno['apellido_alumno'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></b>
                                        <a
                                            href="contratos.php"
                                            class="btn-contrato btn-contract-pending contrato-link-home"
                                            data-student-dni="<?= htmlspecialchars($alumno['dni_alumno'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-es-inactivo="<?= $esInactivo ? '1' : '0'; ?>"
                                            style="display:none;text-decoration:none;"
                                            aria-hidden="true"
                                            title="Ir a Contratos para revisar y firmar el contrato">
                                            Contrato: Pendiente
                                        </a>
                                    </medium><br>
                                    <medium><b>DNI: <?= htmlspecialchars($alumno['dni_alumno'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></b></medium><br>
                                    <?php if (!$esInactivo): ?>
                                        <medium><b>Curso: <?= htmlspecialchars($alumno['curso'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></b></medium><br>
                                    <?php endif; ?>
                                    <medium><b>Legajo: <?= htmlspecialchars($alumno['nro_legajo'], ENT_QUOTES, 'UTF-8'); ?></b></medium><br>
                                    <?php if ($esInactivo): ?>
                                        <medium><b>INACTIVO</b></medium><br>
                                    <?php endif; ?>
                                    <?php
                                    $porc_beca = $alumno['porcentaje_descuento'] ?? null;
                                    if ($porc_beca !== null && $porc_beca !== '' && (int)$porc_beca > 0):
                                        $porc_beca_txt = (string)(int)$porc_beca;
                                    ?>
                                        <medium><b>BECADO: <?= htmlspecialchars($porc_beca_txt, ENT_QUOTES, 'UTF-8'); ?>%</b></medium><br>
                                    <?php endif; ?>

                                </span>
                            </div>
                        </article>

                        <!-- ========================================== -->
                        <!-- TILE DE TOTAL A PAGAR (AHORA CON SALDO VIGENTE) -->
                        <!-- ========================================== -->
                        <article class="full-box tile">
                            <div class="full-box tile-title text-center text-titles text-uppercase">Cuota Vigente - 2026</div>
                            <div class="full-box tile-icon text-center"><i class="zmdi zmdi-calendar-note"></i></div>

                            <!-- Monto + estado + vencimiento (un solo bloque para alinear) -->
                            <div class="full-box tile-number text-titles tile-cuota-values">
                                <?php if ($mostrarTotalesPago): ?>
                                    <p class="alumno-saldo"><b>$<?= number_format((float)$saldoParaTile, 2, ',', '.'); ?></b></p>
                                <?php elseif ($inactivoSinCuotas): ?>
                                    <p class="alumno-saldo"><b><?= htmlspecialchars('Sin Información', ENT_QUOTES, 'UTF-8'); ?></b></p>
                                <?php else: ?>
                                    <p class="alumno-saldo"><b>$---</b></p>
                                <?php endif; ?>

                                <p class="tile-cuota-rango">
                                    <b>
                                        <?php if ($mostrarTotalesPago): ?>
                                            <?= htmlspecialchars($rangoMeses) ?>
                                        <?php else: ?>
                                            Alumno INACTIVO
                                        <?php endif; ?>
                                    </b>
                                </p>

                                <?php if (!$inactivoSinCuotas): ?>
                                <p class="alumno-saldo tile-cuota-vencimiento">
                                    <b>
                                        <?php if ($mostrarTotalesPago): ?>
                                            <?= $ultimo_dia_mes ?>
                                        <?php else: ?>
                                            --/--/--
                                        <?php endif; ?>
                                    </b>
                                </p>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php $i++;
                endforeach; ?>
            <?php else: ?>
                <p>No se encontraron alumnos asociados a esta cuenta.</p>
            <?php endif; ?>

            <!-- Segundo botón para abrir modal -->
            <div style="background-color:#ffffff00;">
                <button id="btnPagarGeneral2" style="box-shadow:0 8px 7px rgba(0,0,0,1);padding:8px 80px;font-size: 20px;border-radius:12px;background:#0c3484;color:#fff;cursor:pointer;position:relative;z-index:2;">Pagar / Opciones</button>
            </div>
        </div>

        <!-- Cabecera del historial + botones de alumnos -->
        <div class="container-fluid" style="background-color:#ffffff00;">
            <div class="page-header" style="background-color:#082359e8; color:white; border-radius:30px; box-shadow:0 8px 7px rgba(0,0,0,1); padding:10px 16px;text-align: center;">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;justify-content:center">
                    <h1 class="text-titles" style="margin:0;"><b>Historial de pagos - 2026</b></h1>
                    <span class="badge-legajo">Legajo: <b id="legajo-actual">—</b></span>
                </div>
                <?php if (!empty($alumnos)): ?>
                    <div class="alumnos-buttons" style="margin-top:12px;">
                        <?php foreach ($alumnos as $al):
                            // También podemos usar la bandera para dar estilo diferente si queremos
                            $esInactivoBtn = (bool)$al['es_inactivo'];
                            $claseExtra = $esInactivoBtn ? 'btn-inactivo' : '';
                        ?>
                            <button class="btn-alumno <?= $claseExtra ?>"
                                data-legajo="<?= htmlspecialchars($al['nro_legajo'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-nombre="<?= htmlspecialchars(trim(($al['nombre_alumno'] ?? '') . ' ' . ($al['apellido_alumno'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="spinner btn-spinner" aria-hidden="true"></span>
                                <span class="btn-alumno-label"><?= htmlspecialchars(trim(($al['nombre_alumno'] ?? '') . ' ' . ($al['apellido_alumno'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Contenedor del historial (cargado vía AJAX) -->
        <div class="container-fluid" style="background-color:#ffffff00; padding:0px 38px 24px;">
            <div style="border-radius: 15px; font-size: 15px;" id="historial-container">
                <p>Seleccione un alumno para ver el historial.</p>
            </div>
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
            <select id="selectorAlumnoTotal" class="alumno-selector">
                <option value="">Seleccione un alumno</option>
            </select>
            <div id="resumenAlumnoTotal" class="resumen-pago"></div>
        </div>

        <div id="seccionCuota" class="modal-section">
            <h4>Pago de Cuota Específica</h4>
            <select id="selectorAlumnoCuota" class="alumno-selector">
                <option value="">Seleccione un alumno</option>
            </select>
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
                                    <p><strong>Titular:</strong> Instituto Jardin de Infantes La Milagrosa</p>
                                    <p><strong>CBU:</strong>
                                        <span id="cbuText">0110661520066100245226</span>
                                        <button id="btnCopiarCBU" style="margin-left:8px;padding:4px 8px;border:1px solid #0c3484;border-radius:4px;background:#fff;cursor:pointer">📋 Copiar</button>
                                    </p>
                                    <p><strong>Banco:</strong> Banco Nación</p>
                                    <p><strong>CUIT:</strong> 30-67618077-6</p>
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