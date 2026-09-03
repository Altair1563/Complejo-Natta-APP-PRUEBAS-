<?php
/**
 * AJAX Historial de Pagos
 * Devuelve el timeline de cuotas de un alumno en formato HTML
 *
 * Seguridad:
 * - Verifica sesión activa
 * - Valida token CSRF (POST)
 * - Valida que el legajo pertenezca a la familia del usuario
 * - Usa configuración centralizada de BD
 * - Escapa todas las salidas
 */

// Iniciar sesión y verificar autenticación
require_once __DIR__ . '/../../config/session.php';
secure_session_start();
if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    http_response_code(403);
    exit("Acceso denegado");
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrf_token)) {
    http_response_code(403);
    exit("Acceso denegado");
}

// Verificar que se envió el legajo
if (!isset($_POST['legajo']) || empty($_POST['legajo'])) {
    http_response_code(400);
    exit("Parámetro legajo faltante");
}

// ============================================
//  CONEXIÓN SEGURA A LA BASE DE DATOS
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/cuota-vigente.php';

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception("Error de conexión a la base de datos");
    }
} catch (Exception $e) {
    error_log("Error en ajax_historial.php: " . $e->getMessage());
    http_response_code(500);
    exit("Error interno del servidor");
}

$nro_legajo = $_POST['legajo'];
$nro_familia = $_SESSION['nro_familia'];

// ============================================
//  VERIFICAR QUE EL LEGAJO PERTENECE A LA FAMILIA
// ============================================
$stmt = $conn->prepare("SELECT 1 FROM legajos WHERE nro_legajo = ? AND nro_familia = ?
                        UNION
                        SELECT 1 FROM legajos_inactivos WHERE nro_legajo = ? AND nro_familia = ?");
$stmt->bind_param("ssss", $nro_legajo, $nro_familia, $nro_legajo, $nro_familia);
$stmt->execute();
$res = $stmt->get_result();
$alumno = $res->fetch_assoc();
$stmt->close();

if (!$alumno) {
    http_response_code(403);
    exit("Alumno no pertenece a esta familia");
}

// ============================================
//  OBTENER CUOTAS DEL ALUMNO
// ============================================
$stmt_cuotas = $conn->prepare("SELECT * FROM cuotas WHERE nro_legajo = ? ORDER BY numero_cuota ASC");
$stmt_cuotas->bind_param("s", $nro_legajo);
$stmt_cuotas->execute();
$result_cuotas = $stmt_cuotas->get_result();
$cuotas = $result_cuotas->fetch_all(MYSQLI_ASSOC);
$stmt_cuotas->close();

// ============================================
//  FUNCIONES AUXILIARES
// ============================================
function esc($str)
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// Mapeo de número de cuota a nombre de mes (1-12)
$meses = [
    1  => 'Marzo',
    2  => 'Abril',
    3  => 'Mayo',
    4  => 'Junio',
    5  => 'Julio',
    6  => 'Agosto',
    7  => 'Septiembre',
    8  => 'Octubre',
    9  => 'Noviembre',
    10 => 'Adelanto Reserva de Vacante',
    11 => 'Resto Reserva de Vacante',
    12 => 'RESERVA DE VACANTE 2026'
];

// ============================================
//  OBTENER CUOTA VIGENTE DESDE CONFIGURACIÓN
// ============================================
$cuotaVigente = (int)getConfig('cuota_vigente', 1);
$mesActual = (int)date('n'); // 1=enero ... 12=diciembre

// Función para determinar si una cuota es futura
function esCuotaFutura($numCuota, $cuotaVigente, $mesActual)
{
    $numCuota = (int)$numCuota;
    // Cuotas normales (1-9)
    if ($numCuota <= 9) {
        return $numCuota > $cuotaVigente;
    } else {
        // Cuotas de reserva (10,11,12): futuras solo antes de marzo
        return $mesActual < 3;
    }
}

// ============================================
//  GENERAR HTML DEL HISTORIAL
// ============================================
if (empty($cuotas)): ?>
    <p>No se encontraron cuotas para este alumno.</p>
<?php else: ?>
    <section id="cd-timeline" class="cd-container">
        <?php foreach ($cuotas as $cuota):
            $numCuota = (int)$cuota['numero_cuota'];
            $mes = isset($meses[$numCuota]) ? $meses[$numCuota] : 'Mes Desconocido';
            $ano = (int)date('Y');

            // Fecha de vencimiento estimada (30 del mes correspondiente)
            // Ajusta según tu lógica real (puede venir de la BD)
            $fechaVenc = sprintf("30/%02d/%d", $numCuota + 2, $ano); // Ej: cuota1 (marzo) -> 30/05

            $pendiente = isset($cuota['diferencia']) ? (float)$cuota['diferencia'] : 0;
            $montoFacturado = isset($cuota['monto_facturado']) ? (float)$cuota['monto_facturado'] : 0;
            $montoIngresado = isset($cuota['monto_ingresado']) ? (float)$cuota['monto_ingresado'] : 0;
            $fechaPago = !empty($cuota['fecha_pago']) ? date('d/m/Y', strtotime($cuota['fecha_pago'])) : '';

            $esFutura = esCuotaFutura($numCuota, $cuotaVigente, $mesActual);

            $iconVenc = '<i class="zmdi zmdi-timer zmdi-hc-fw"></i>';
            $iconAbonado = '<i class="zmdi zmdi-money zmdi-hc-fw"></i>';
            $iconPendiente = '<i class="zmdi zmdi-alert-circle zmdi-hc-fw"></i>';

            // Título de la cuota
            if ($esFutura) {
                $titulo = "Cuota $numCuota - $mes ($ano) - MONTO SUJETO A AUMENTO";
            } else {
                $titulo = "Cuota $numCuota - $mes ($ano) - $ " . number_format($montoFacturado, 2, ',', '.');
            }

            // Monto abonado formateado
            $abonadoStr = "$ " . number_format($montoIngresado, 2, ',', '.');

            // Texto del pendiente
            if ($esFutura) {
                $pendienteStr = "MONTO SUJETO A AUMENTO";
            } else {
                $pendienteStr = ($pendiente > 0.01) ? "$ " . number_format($pendiente, 2, ',', '.') : '-';
            }

            // Badge de fecha (color y texto según estado)
            if ($esFutura) {
                // Cuota futura: siempre naranja
                $badgeColor = "#d9a24f";
                if ($fechaPago) {
                    $badgeTexto = "Pago a favor registrado: $fechaPago";
                } else {
                    $badgeTexto = "Pago Pendiente";
                }
            } else {
                // Cuota vigente o pasada
                if ($pendiente <= 0.01) {
                    $badgeColor = "#03bc00"; // verde
                    $badgeTexto = $fechaPago ? "Pago registrado: $fechaPago" : "Pago Pendiente";
                } else {
                    $badgeColor = "#d9534f"; // rojo
                    $badgeTexto = "Pago Pendiente";
                }
            }
            $badgeHtml = "<span class=\"cd-date\" style=\"background-color:$badgeColor;\">" . esc($badgeTexto) . "</span>";
            ?>
        <div class="cd-timeline-block">
            <div class="cd-timeline-img">
                <img src="./assets/img/robot.jfif" alt="user-picture">
            </div>
            <div class="cd-timeline-content">
                <h4 class="text-center text-titles"><?= esc($titulo) ?></h4>
                <p class="text-center">
                    <?= $iconVenc ?> Venc: <?= esc($fechaVenc) ?><br>
                    <?= $iconAbonado ?> Abonado: <?= esc($abonadoStr) ?><br>
                    <?= $iconPendiente ?> Pendiente: <?= esc($pendienteStr) ?>
                </p>
                <?= $badgeHtml ?>
            </div>
        </div>
        <?php endforeach; ?>
    </section>
<?php endif;
$conn->close();
?>
