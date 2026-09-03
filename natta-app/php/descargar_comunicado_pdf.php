<?php
/**
 * Descarga segura del PDF adjunto a un comunicado general (familias autenticadas).
 */

require_once __DIR__ . '/../config/session.php';
secure_session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    http_response_code(403);
    echo 'No autorizado.';
    exit;
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    http_response_code(400);
    echo 'Comunicado no válido.';
    exit;
}

define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/comunicados_pdf.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=3306;dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    error_log('descargar_comunicado_pdf: conexión — ' . $e->getMessage());
    http_response_code(500);
    echo 'Error interno.';
    exit;
}

$stmt = $pdo->prepare('SELECT id, titulo, archivo_pdf, activo FROM comunicados WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$comunicado = $stmt->fetch();

if (!$comunicado || (int)($comunicado['activo'] ?? 0) !== 1 || empty($comunicado['archivo_pdf'])) {
    http_response_code(404);
    echo 'El PDF no está disponible.';
    exit;
}

$pdfPath = comunicado_pdf_absolute_path((int)$comunicado['id']);
if ($pdfPath === null) {
    http_response_code(404);
    echo 'El archivo PDF no se encuentra en el servidor.';
    exit;
}

$titulo = preg_replace('/[^A-Za-z0-9_\- ]+/', '', (string)($comunicado['titulo'] ?? 'comunicado'));
$titulo = trim($titulo);
if ($titulo === '') {
    $titulo = 'comunicado';
}
$filename = $titulo . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string)filesize($pdfPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');
readfile($pdfPath);
exit;
