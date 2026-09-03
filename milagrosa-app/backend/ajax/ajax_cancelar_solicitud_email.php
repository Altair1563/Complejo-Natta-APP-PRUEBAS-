<?php
require_once __DIR__ . '/../../config/session.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['nro_familia'])) {
    echo json_encode(["ok" => false, "msg" => "Sesión expirada"]);
    exit;
}

// Validar token CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "msg" => "Token CSRF inválido"]);
    exit;
}

define('_ACCESS', true);
require_once __DIR__ . '/../../config/db.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en ajax_cancelar_solicitud_email: " . $conn->connect_error);
    echo json_encode(["ok" => false, "msg" => "Error de conexión a la base de datos"]);
    exit;
}

$posicion = isset($_POST['posicion']) ? (int)$_POST['posicion'] : 0;
$nro_familia = $_SESSION['nro_familia'];

if (!$posicion || $posicion < 1 || $posicion > 4) {
    echo json_encode(["ok" => false, "msg" => "Posición no válida"]);
    $conn->close();
    exit;
}

$sql = "UPDATE solicitudes_email
        SET estado = 'rechazado'
        WHERE nro_familia = ? AND posicion = ? AND estado = 'pendiente'";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Error en prepare: " . $conn->error);
    echo json_encode(["ok" => false, "msg" => "Error interno del servidor"]);
    $conn->close();
    exit;
}
$stmt->bind_param("si", $nro_familia, $posicion);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(["ok" => true, "msg" => "Solicitud cancelada correctamente."]);
} else {
    echo json_encode(["ok" => false, "msg" => "No se encontró ninguna solicitud pendiente para cancelar."]);
}

$stmt->close();
$conn->close();
?>
