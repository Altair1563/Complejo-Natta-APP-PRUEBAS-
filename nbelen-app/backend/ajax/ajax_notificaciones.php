<?php
require_once __DIR__ . '/../../config/session.php';
secure_session_start();
header('Content-Type: application/json; charset=utf-8');

function normalize_plain_text($value)
{
    $text = is_string($value) ? $value : '';
    $text = strip_tags($text);
    $text = preg_replace("/[\r\n\t]+/", ' ', $text);
    return trim($text);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([]);
    exit;
}

// Verificar sesión
if (!isset($_SESSION['nro_familia'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrf_token)) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

// Incluir configuración centralizada
define('_ACCESS', true);
require_once __DIR__ . '/../../config/db.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en ajax_notificaciones: " . $conn->connect_error);
    echo json_encode([]);
    exit;
}

$nroFamilia = (int)$_SESSION['nro_familia']; // Validación adicional como entero

$sql = "SELECT id, mensaje, fecha, leido
        FROM notificaciones
        WHERE nro_familia = ?
        ORDER BY fecha DESC
        LIMIT 10";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Error prepare en ajax_notificaciones: " . $conn->error);
    echo json_encode([]);
    $conn->close();
    exit;
}

$stmt->bind_param("i", $nroFamilia);
$stmt->execute();
$result = $stmt->get_result();

$notificaciones = [];
while ($row = $result->fetch_assoc()) {
    $notificaciones[] = [
        'id' => $row['id'],
        'mensaje' => normalize_plain_text($row['mensaje'] ?? ''),
        'fecha' => date('d/m/Y H:i', strtotime($row['fecha'])),
        'leido' => (int)$row['leido']
    ];
}

$stmt->close();
$conn->close();

echo json_encode($notificaciones, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
?>
