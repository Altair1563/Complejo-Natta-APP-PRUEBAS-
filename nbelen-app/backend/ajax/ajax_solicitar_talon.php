<?php
require_once __DIR__ . '/../../config/session.php';
secure_session_start();
header('Content-Type: application/json');

// Desactivar mostrar errores (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Verificar sesión
if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión no válida']);
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
    error_log("Error de conexión en ajax_solicitar_talon: " . $conn->connect_error);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}

// Obtener y validar datos
$legajo = $_POST['legajo'] ?? '';
$cuotasJson = $_POST['cuotas'] ?? '[]';
$cuotas = json_decode($cuotasJson, true);
$nro_familia = $_SESSION['nro_familia'];

if (empty($legajo) || empty($cuotas) || !is_array($cuotas)) {
    echo json_encode(['success' => false, 'message' => 'Datos incompletos o inválidos']);
    $conn->close();
    exit;
}

// Validar que el legajo pertenezca a la familia (activos o inactivos)
$sql_verif = "SELECT nro_legajo FROM legajos WHERE nro_legajo = ? AND nro_familia = ?
              UNION
              SELECT nro_legajo FROM legajos_inactivos WHERE nro_legajo = ? AND nro_familia = ?";
$stmt_verif = $conn->prepare($sql_verif);
if (!$stmt_verif) {
    error_log("Error prepare en verificación legajo: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
    $conn->close();
    exit;
}
$stmt_verif->bind_param("ssss", $legajo, $nro_familia, $legajo, $nro_familia);
$stmt_verif->execute();
$result_verif = $stmt_verif->get_result();
if ($result_verif->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'El legajo no pertenece a su familia']);
    $stmt_verif->close();
    $conn->close();
    exit;
}
$stmt_verif->close();

// Convertir los IDs de cuota a enteros y filtrar duplicados
$cuota_ids = array_map('intval', $cuotas);
$cuota_ids = array_unique($cuota_ids);

if (empty($cuota_ids)) {
    echo json_encode(['success' => false, 'message' => 'No se especificaron cuotas válidas']);
    $conn->close();
    exit;
}

// Verificar que las cuotas existan y pertenezcan al legajo
$placeholders = implode(',', array_fill(0, count($cuota_ids), '?'));
$sql_cuotas = "SELECT id FROM cuotas WHERE nro_legajo = ? AND id IN ($placeholders)";
$stmt_cuotas = $conn->prepare($sql_cuotas);
if (!$stmt_cuotas) {
    error_log("Error prepare en verificación cuotas: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
    $conn->close();
    exit;
}

// Construir tipos para bind_param: primer parámetro string (legajo) + tantos enteros como IDs
$types = "s" . str_repeat("i", count($cuota_ids));
$params = array_merge([$legajo], $cuota_ids);
$stmt_cuotas->bind_param($types, ...$params);
$stmt_cuotas->execute();
$result_cuotas = $stmt_cuotas->get_result();

$ids_validos = [];
while ($row = $result_cuotas->fetch_assoc()) {
    $ids_validos[] = $row['id'];
}
$stmt_cuotas->close();

if (empty($ids_validos)) {
    echo json_encode(['success' => false, 'message' => 'Ninguna de las cuotas seleccionadas es válida']);
    $conn->close();
    exit;
}

// Insertar solicitudes (solo para los IDs válidos)
$insertados = 0;
$conn->begin_transaction();
try {
    $sql_insert = "INSERT INTO solicitudes_talon (nro_familia, nro_legajo, cuota_id) VALUES (?, ?, ?)";
    $stmt_insert = $conn->prepare($sql_insert);
    if (!$stmt_insert) {
        throw new Exception("Error preparando inserción: " . $conn->error);
    }

    foreach ($ids_validos as $cuota_id) {
        $stmt_insert->bind_param("ssi", $nro_familia, $legajo, $cuota_id);
        if ($stmt_insert->execute()) {
            $insertados++;
        } else {
            error_log("Error insertando solicitud: " . $stmt_insert->error);
        }
    }
    $stmt_insert->close();
    $conn->commit();

    if ($insertados > 0) {
        echo json_encode(['success' => true, 'message' => "$insertados solicitud(es) guardada(s)."]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se pudo guardar ninguna solicitud.']);
    }
} catch (Exception $e) {
    $conn->rollback();
    error_log("Excepción en ajax_solicitar_talon: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al procesar las solicitudes.']);
}

$conn->close();
?>
