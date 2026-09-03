<?php
/**
 * Descarga segura del PDF de contrato firmado (panel estado alumnos / secretaría).
 */

define('NATTA_ROOT', dirname(__DIR__));

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/estado_alumno_lib.php';

if (!admin_is_logged_in() || !admin_can_access_estado_alumno()) {
    http_response_code(403);
    echo 'No autorizado.';
    exit;
}

$studentDni = trim((string)($_GET['dni'] ?? ''));
if ($studentDni === '') {
    http_response_code(400);
    echo 'Alumno no válido.';
    exit;
}

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    require_once __DIR__ . '/includes/db_collate.php';
    admin_mysqli_apply_collation($conn);
} catch (Throwable $e) {
    error_log('descargar_contrato_firmado conexión: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error interno.';
    exit;
}

$escuelas = estado_alumno_escuelas_catalog();
$escuelaActiva = estado_alumno_resolve_escuela_activa($escuelas, (string)($_GET['escuela'] ?? 'CJ'));

if (admin_is_rol_escuela() && !estado_alumno_alumno_pertenece_escuela($conn, $studentDni, $escuelaActiva)) {
    $conn->close();
    http_response_code(403);
    echo 'No autorizado para este alumno.';
    exit;
}

$pdf = estado_alumno_obtener_pdf_contrato_firmado($conn, $studentDni);
$conn->close();

if ($pdf === null) {
    http_response_code(404);
    echo 'El contrato firmado no está disponible.';
    exit;
}

$filename = str_replace('"', '', $pdf['filename']);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string)filesize($pdf['path']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache');
readfile($pdf['path']);
exit;
