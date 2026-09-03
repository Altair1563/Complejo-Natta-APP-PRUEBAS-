<?php
/**
 * AJAX Cuotas para el modal de pago
 * Devuelve las cuotas de un alumno (solo las vigentes y con saldo pendiente) en formato JSON
 */

require_once __DIR__ . '/../../config/session.php';
secure_session_start();
if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Acceso denegado']));
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrf_token)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Token CSRF inválido']));
}

if (!isset($_POST['legajo']) || empty($_POST['legajo'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Legajo no proporcionado']));
}

// ============================================
//  CONEXIÓN SEGURA A LA BASE DE DATOS
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/../../config/db.php';

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception("Error de conexión");
    }
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    error_log("Error en ajax_cuotas.php: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['error' => 'Error interno del servidor']));
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
if ($res->fetch_row() === null) {
    http_response_code(403);
    exit(json_encode(['error' => 'Alumno no pertenece a esta familia']));
}
$stmt->close();

// ============================================
//  OBTENER CUOTA VIGENTE DESDE CONFIGURACIÓN
// ============================================
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

// ============================================
//  FUNCIÓN PARA DETERMINAR SI UNA CUOTA ES FUTURA
// ============================================
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

// ============================================
//  OBTENER CUOTAS DEL ALUMNO (SOLO VIGENTES Y CON SALDO PENDIENTE)
// ============================================
$stmt = $conn->prepare("SELECT id, numero_cuota, monto_facturado, monto_ingresado, diferencia, estado, fecha_pago
                        FROM cuotas
                        WHERE nro_legajo = ? AND diferencia > 0
                        ORDER BY numero_cuota ASC");
$stmt->bind_param("s", $nro_legajo);
$stmt->execute();
$result = $stmt->get_result();
$cuotas = [];

while ($row = $result->fetch_assoc()) {
    $numCuota = (int)$row['numero_cuota'];
    // Filtrar: solo incluir cuotas NO futuras
    if (!esCuotaFutura($numCuota, $cuota_vigente, $mesActual)) {
        // Agregar descripción del mes
        $meses = [
            1  => 'MARZO',
            2  => 'ABRIL',
            3  => 'MAYO',
            4  => 'JUNIO',
            5  => 'JULIO',
            6  => 'AGOSTO',
            7  => 'SEPTIEMBRE',
            8  => 'OCTUBRE',
            9  => 'NOVIEMBRE',
            10 => 'ADELANTO RV',
            11 => 'RESTO RV',
            12 => 'RV COMPLETA'
        ];
        $row['descripcion'] = $meses[$numCuota] ?? 'Desconocido';
        $row['pendiente'] = (float)$row['diferencia'];
        $row['monto'] = (float)$row['monto_facturado'];
        $row['pagado'] = (float)$row['monto_ingresado'];
        $cuotas[] = $row;
    }
}

$stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode($cuotas);
?>
