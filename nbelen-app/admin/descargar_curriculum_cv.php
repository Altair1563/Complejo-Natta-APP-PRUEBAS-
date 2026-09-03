<?php
/**
 * Descarga segura del CV de un currículum (panel estado alumnos / secretaría).
 */

define('NATTA_ROOT', dirname(__DIR__));

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/estado_alumno_lib.php';

if (!admin_is_logged_in() || !admin_can_access_estado_alumno()) {
    http_response_code(403);
    echo 'No autorizado.';
    exit;
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    http_response_code(400);
    echo 'Currículum no válido.';
    exit;
}

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    require_once __DIR__ . '/includes/db_collate.php';
    admin_mysqli_apply_collation($conn);
} catch (Throwable $e) {
    error_log('descargar_curriculum_cv conexión: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error interno.';
    exit;
}

$curriculum = estado_alumno_obtener_curriculum_por_id($conn, (int)$id);
$conn->close();

if ($curriculum === null) {
    http_response_code(404);
    echo 'Currículum no encontrado.';
    exit;
}

$archivoPath = $curriculum['archivo_path'] ?? null;
if ($archivoPath === null || !is_file($archivoPath)) {
    http_response_code(404);
    echo 'El archivo del CV no se encuentra en el servidor.';
    exit;
}

$filename = estado_alumno_curriculum_download_filename(
    (string)($curriculum['nombre_apellido'] ?? ''),
    $archivoPath
);
$mime = estado_alumno_curriculum_mime_type($archivoPath);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string)filesize($archivoPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');
readfile($archivoPath);
exit;
