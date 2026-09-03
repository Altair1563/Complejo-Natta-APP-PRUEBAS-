<?php
/**
 * Descarga del PDF firmado depositado (requiere sesión del grupo familiar).
 */
require_once __DIR__ . '/backend/bootstrap.php';
require_once __DIR__ . '/backend/lib/contract_institution.php';
require_once __DIR__ . '/backend/lib/contract_render.php';
require_once __DIR__ . '/backend/lib/contract_pdf.php';
require_once __DIR__ . '/backend/lib/contract_preview_session.php';

mysqli_report(MYSQLI_REPORT_OFF);
requireAuth();

$preview = contrato_preview_session_read();
$studentDni = $preview !== null
    ? trim((string)($preview['student_dni'] ?? ''))
    : trim((string)($_GET['student_dni'] ?? ''));
if ($studentDni === '') {
    http_response_code(400);
    echo 'Falta identificar al alumno. Abrí el contrato desde la app familiar.';
    exit;
}

$nroFamilia = (string)($_SESSION['nro_familia'] ?? '');
$conn = getDbConnection();

$sqlBelongs = 'SELECT curso FROM legajos WHERE nro_familia = ? AND dni_alumno = ?
               UNION
               SELECT curso FROM legajos_inactivos WHERE nro_familia = ? AND dni_alumno = ?
               LIMIT 1';
$stmt = $conn->prepare($sqlBelongs);
if (!$stmt) {
    $conn->close();
    http_response_code(500);
    echo 'Error de validación.';
    exit;
}
$stmt->bind_param('ssss', $nroFamilia, $studentDni, $nroFamilia, $studentDni);
$stmt->execute();
$rows = fetchAllFromStmt($stmt);
$stmt->close();

if (empty($rows)) {
    $conn->close();
    http_response_code(403);
    echo 'No autorizado.';
    exit;
}

$codigoInst = contrato_codigo_institucion_desde_curso((string)($rows[0]['curso'] ?? ''));
$contract = $codigoInst !== null ? contrato_fetch_vigente_por_codigo($conn, $codigoInst) : null;
if ($contract === null) {
    $conn->close();
    http_response_code(404);
    echo 'Contrato no encontrado.';
    exit;
}

$signed = contrato_fetch_signed_acceptance($conn, $studentDni, $nroFamilia, (string)$contract['contract_version']);
$conn->close();

if ($signed === null) {
    http_response_code(404);
    echo 'No hay contrato firmado para este alumno.';
    exit;
}

$pdfPath = contrato_resolve_signed_pdf_absolute((string)($signed['accepted_pdf_path'] ?? ''));
if ($pdfPath === null) {
    http_response_code(404);
    echo 'El archivo PDF firmado no está disponible.';
    exit;
}

$filename = basename($pdfPath);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . (string)filesize($pdfPath));
header('X-Content-Type-Options: nosniff');
readfile($pdfPath);
exit;
