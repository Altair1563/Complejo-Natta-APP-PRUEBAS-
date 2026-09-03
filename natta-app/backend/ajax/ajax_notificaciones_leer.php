<?php
require_once __DIR__ . '/../../config/session.php';
secure_session_start();
header('Content-Type: application/json');

// Verificar sesión
if (!isset($_SESSION['nro_familia'])) {
    echo json_encode(['success' => false]);
    exit;
}

// Validar token CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
    exit;
}

// Incluir configuración centralizada
define('_ACCESS', true);
require_once __DIR__ . '/../../config/db.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en ajax_notificaciones_leer: " . $conn->connect_error);
    echo json_encode(['success' => false]);
    exit;
}

$nroFamilia = (int)$_SESSION['nro_familia']; // Validación como entero

$sql = "UPDATE notificaciones SET leido = 1 WHERE nro_familia = ? AND leido = 0";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Error prepare en ajax_notificaciones_leer: " . $conn->error);
    echo json_encode(['success' => false]);
    $conn->close();
    exit;
}

$stmt->bind_param("i", $nroFamilia);
$success = $stmt->execute();

$stmt->close();
$conn->close();

echo json_encode(['success' => $success]);
?>
