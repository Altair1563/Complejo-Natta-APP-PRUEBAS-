<?php
require_once __DIR__ . '/../../config/session.php';
secure_session_start();
header('Content-Type: application/json');

// Verificar sesión
if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Validar token CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
    exit;
}

// Incluir configuración de base de datos
define('_ACCESS', true);
require_once __DIR__ . '/../../config/db.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en ajax_cancelar_solicitudes: " . $conn->connect_error);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}

// Obtener legajo y familia
$legajo = $_POST['legajo'] ?? '';
$nro_familia = $_SESSION['nro_familia'];

if (empty($legajo)) {
    echo json_encode(['success' => false, 'message' => 'Legajo no proporcionado']);
    $conn->close();
    exit;
}

// Verificar que el legajo pertenezca a la familia (buscar en legajos y legajos_inactivos)
$sql_verificar = "SELECT 1 FROM legajos WHERE nro_legajo = ? AND nro_familia = ?
                  UNION
                  SELECT 1 FROM legajos_inactivos WHERE nro_legajo = ? AND nro_familia = ?";
$stmt_verificar = $conn->prepare($sql_verificar);
$stmt_verificar->bind_param("ssss", $legajo, $nro_familia, $legajo, $nro_familia);
$stmt_verificar->execute();
$stmt_verificar->store_result();

if ($stmt_verificar->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'El legajo no pertenece a su familia']);
    $stmt_verificar->close();
    $conn->close();
    exit;
}
$stmt_verificar->close();

// Proceder a cancelar solicitudes pendientes de ese legajo
$sql_delete = "DELETE FROM solicitudes_talon WHERE nro_legajo = ? AND estado = 'pendiente'";
$stmt_delete = $conn->prepare($sql_delete);
$stmt_delete->bind_param("s", $legajo);

if ($stmt_delete->execute()) {
    $affected = $stmt_delete->affected_rows;
    echo json_encode(['success' => true, 'message' => "Solicitudes canceladas: $affected"]);
} else {
    error_log("Error al cancelar solicitudes para legajo $legajo: " . $stmt_delete->error);
    echo json_encode(['success' => false, 'message' => 'Error al cancelar las solicitudes']);
}

$stmt_delete->close();
$conn->close();
?>
