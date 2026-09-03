<?php
require_once __DIR__ . '/../../config/session.php';
secure_session_start();
header('Content-Type: application/json');

// Verificar sesión
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

// Conexión segura
define('_ACCESS', true);
require_once __DIR__ . '/../../config/db.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log("Error de conexión en ajax_agregar_email: " . $conn->connect_error);
    echo json_encode(["ok" => false, "msg" => "Error de conexión a la base de datos"]);
    exit;
}

// Obtener datos POST
$posicion = isset($_POST['posicion']) ? (int)$_POST['posicion'] : 0;
$email_nuevo = isset($_POST['email']) ? trim($_POST['email']) : '';
$nro_familia = $_SESSION['nro_familia'];

if (!$posicion || !$email_nuevo) {
    echo json_encode(["ok" => false, "msg" => "Datos incompletos"]);
    $conn->close();
    exit;
}

// Validación básica de email
if (!filter_var($email_nuevo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["ok" => false, "msg" => "El formato del email no es válido"]);
    $conn->close();
    exit;
}

// Mapeo de posición a nombre de campo
$map_campos = [
    1 => 'mail_padre',
    2 => 'mail_padre_trabajo',
    3 => 'mail_madre',
    4 => 'mail_madre_trabajo'
];

if (!isset($map_campos[$posicion])) {
    echo json_encode(["ok" => false, "msg" => "Posición inválida"]);
    $conn->close();
    exit;
}

$campo = $map_campos[$posicion];

// Verificar si ya existe una solicitud pendiente para esta posición
$check_sql = "SELECT id FROM solicitudes_email WHERE nro_familia = ? AND posicion = ? AND estado = 'pendiente'";
$check_stmt = $conn->prepare($check_sql);
if (!$check_stmt) {
    error_log("Error en prepare (check pendiente): " . $conn->error);
    echo json_encode(["ok" => false, "msg" => "Error interno del servidor"]);
    $conn->close();
    exit;
}
$check_stmt->bind_param("si", $nro_familia, $posicion);
$check_stmt->execute();
$check_stmt->store_result();
if ($check_stmt->num_rows > 0) {
    $check_stmt->close();
    echo json_encode(["ok" => false, "msg" => "Ya existe una solicitud pendiente para este email. Debes cancelarla antes de realizar un nuevo cambio."]);
    $conn->close();
    exit;
}
$check_stmt->close();

// Obtener el email actual desde email_familia
$sql_actual = "SELECT $campo FROM email_familia WHERE nro_familia = ?";
$stmt_actual = $conn->prepare($sql_actual);
if (!$stmt_actual) {
    error_log("Error en prepare (email actual): " . $conn->error);
    echo json_encode(["ok" => false, "msg" => "Error interno del servidor"]);
    $conn->close();
    exit;
}
$stmt_actual->bind_param("s", $nro_familia);
$stmt_actual->execute();
$res_actual = $stmt_actual->get_result();
$email_actual = '';
if ($row = $res_actual->fetch_assoc()) {
    $email_actual = $row[$campo] ?? '';
}
$stmt_actual->close();

// Si el email nuevo es igual al actual, no tiene sentido
if ($email_actual === $email_nuevo) {
    echo json_encode(["ok" => false, "msg" => "El email nuevo es igual al actual. No se requiere cambio."]);
    $conn->close();
    exit;
}

// Insertar solicitud en tabla solicitudes_email
$sql_insert = "INSERT INTO solicitudes_email
               (nro_familia, posicion, email_actual, email_nuevo, estado)
               VALUES (?, ?, ?, ?, 'pendiente')";
$stmt_insert = $conn->prepare($sql_insert);
if (!$stmt_insert) {
    error_log("Error en prepare (insert): " . $conn->error);
    echo json_encode(["ok" => false, "msg" => "Error interno del servidor"]);
    $conn->close();
    exit;
}
$stmt_insert->bind_param("siss", $nro_familia, $posicion, $email_actual, $email_nuevo);

if ($stmt_insert->execute()) {
    echo json_encode(["ok" => true, "msg" => "Solicitud enviada. Será procesada en 48 hs hábiles."]);
} else {
    error_log("Error al insertar solicitud: " . $stmt_insert->error);
    echo json_encode(["ok" => false, "msg" => "Error al guardar la solicitud. Intente más tarde."]);
}

$stmt_insert->close();
$conn->close();
?>
