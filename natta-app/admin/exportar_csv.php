<?php
require_once __DIR__ . '/includes/guard.php';
admin_guard_or_die();

$tabla = $_GET['tabla'] ?? '';
$exportCap = [
    'informes' => 'informes',
    'emails' => 'emails',
    'talones' => 'talones',
    'sugerencias' => 'sugerencias',
];
if (!isset($exportCap[$tabla]) || !admin_can($exportCap[$tabla])) {
    header('HTTP/1.0 403 Forbidden');
    exit('Acceso denegado');
}

define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=3306;dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    error_log("Error en exportar_csv.php: " . $e->getMessage());
    die('Error de conexión');
}

$filename = 'export.csv';
$sql = '';
$params = [];

switch ($tabla) {
    case 'informes':
        $where = [];
        if (!empty($_GET['filtro_estado_inf'])) {
            $where[] = "estado = ?";
            $params[] = $_GET['filtro_estado_inf'];
        }
        if (!empty($_GET['filtro_familia_inf'])) {
            $where[] = "nro_familia LIKE ?";
            $params[] = '%' . $_GET['filtro_familia_inf'] . '%';
        }
        $sql = "SELECT * FROM informes_error";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY fecha_creacion DESC";
        $filename = 'informes_error.csv';
        break;
    case 'emails':
        $where = [];
        if (!empty($_GET['filtro_estado_email'])) {
            $where[] = "estado = ?";
            $params[] = $_GET['filtro_estado_email'];
        }
        if (!empty($_GET['filtro_familia_email'])) {
            $where[] = "nro_familia LIKE ?";
            $params[] = '%' . $_GET['filtro_familia_email'] . '%';
        }
        $sql = "SELECT * FROM solicitudes_email";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY fecha_solicitud DESC";
        $filename = 'solicitudes_email.csv';
        break;
    case 'talones':
        $sql = "SELECT * FROM solicitudes_talon ORDER BY fecha_solicitud DESC";
        $filename = 'solicitudes_talon.csv';
        break;
    case 'sugerencias':
        $where = [];
        if (isset($_GET['filtro_leido']) && $_GET['filtro_leido'] !== '') {
            $where[] = "leido = ?";
            $params[] = $_GET['filtro_leido'];
        }
        if (!empty($_GET['filtro_familia_sug'])) {
            $where[] = "nro_familia LIKE ?";
            $params[] = '%' . $_GET['filtro_familia_sug'] . '%';
        }
        $sql = "SELECT * FROM sugerencias";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY fecha DESC";
        $filename = 'sugerencias.csv';
        break;
    default:
        die('Tabla no válida');
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    // Si no hay datos, crear un array con los encabezados
    $encabezados = [];
    switch ($tabla) {
        case 'informes':
            $encabezados = ['id', 'nro_familia', 'error_descripcion', 'fecha_creacion', 'estado'];
            break;
        case 'emails':
            $encabezados = ['id', 'nro_familia', 'posicion', 'email_actual', 'email_nuevo', 'fecha_solicitud', 'estado'];
            break;
        case 'talones':
            $encabezados = ['id', 'nro_familia', 'nro_legajo', 'cuota_id', 'fecha_solicitud', 'estado'];
            break;
        case 'sugerencias':
            $encabezados = ['id', 'nro_familia', 'mensaje', 'fecha', 'leido'];
            break;
    }
    $rows = [$encabezados];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
// BOM para UTF-8 (opcional, mejora compatibilidad con Excel)
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
// Escribir encabezados (usamos las claves del primer registro)
fputcsv($output, array_keys($rows[0]), ';');
// Escribir datos
foreach ($rows as $row) {
    fputcsv($output, $row, ';');
}
fclose($output);
exit;