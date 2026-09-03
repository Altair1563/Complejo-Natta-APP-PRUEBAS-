<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();

// Desactivar mostrar errores en producción (solo log)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

function normalize_plain_text($value)
{
    $text = is_string($value) ? $value : '';
    $text = strip_tags($text);
    $text = preg_replace("/[\r\n\t]+/", ' ', $text);
    return trim($text);
}

// Verificar sesión
if (!isset($_SESSION['nro_familia'])) {
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida']);
    exit;
}

$nro_familia_raw = $_SESSION['nro_familia'];
$nro_familia_int = filter_var($nro_familia_raw, FILTER_VALIDATE_INT);
if ($nro_familia_int === false || $nro_familia_int <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Sesión inválida']);
    exit;
}

// ============================================
//  CONEXIÓN A LA BASE DE DATOS (SEGURA)
// ============================================
define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (Throwable $e) {
    error_log("Error de conexión en get_notificaciones.php: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Error interno del servidor']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, mensaje, fecha, leido
        FROM notificaciones
        WHERE nro_familia = ?
        ORDER BY leido ASC, fecha DESC
    ");
    $stmt->execute([$nro_familia_int]);
    $notificaciones = $stmt->fetchAll();
    foreach ($notificaciones as &$notificacion) {
        $notificacion['mensaje'] = normalize_plain_text($notificacion['mensaje'] ?? '');
    }
    unset($notificacion);

    echo json_encode([
        'ok' => true,
        'data' => $notificaciones
    ]);
} catch (Throwable $e) {
    error_log("Error en consulta get_notificaciones.php: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Error al obtener notificaciones']);
    exit;
}