<?php
require_once __DIR__ . '/../../backend/bootstrap.php';
require_once __DIR__ . '/../lib/contract_institution.php';
require_once __DIR__ . '/../lib/contract_render.php';
header('Content-Type: application/json');

if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida']);
    exit;
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token CSRF inválido']);
    exit;
}

$studentDni = trim((string)($_POST['student_dni'] ?? ''));
if ($studentDni === '') {
    echo json_encode(['ok' => false, 'msg' => 'Alumno inválido']);
    exit;
}

$conn = getDbConnection();
$nroFamilia = (string)($_SESSION['nro_familia'] ?? '');

$sqlBelongs = "SELECT curso, nro_legajo, 0 AS es_inactivo FROM legajos WHERE nro_familia = ? AND dni_alumno = ?
               UNION
               SELECT curso, nro_legajo, 1 AS es_inactivo FROM legajos_inactivos WHERE nro_familia = ? AND dni_alumno = ?
               LIMIT 1";
$stmtBelongs = $conn->prepare($sqlBelongs);
if (!$stmtBelongs) {
    echo json_encode(['ok' => false, 'msg' => 'Error de validación']);
    $conn->close();
    exit;
}
$stmtBelongs->bind_param('ssss', $nroFamilia, $studentDni, $nroFamilia, $studentDni);
$stmtBelongs->execute();
$belongsRows = fetchAllFromStmt($stmtBelongs);
$stmtBelongs->close();

if (empty($belongsRows)) {
    echo json_encode(['ok' => false, 'msg' => 'El alumno no pertenece al grupo familiar']);
    $conn->close();
    exit;
}
$esInactivo = ((int)($belongsRows[0]['es_inactivo'] ?? 0) === 1);
$cursoAlumno = (string)($belongsRows[0]['curso'] ?? '');
$nroLegajoAlumno = (string)($belongsRows[0]['nro_legajo'] ?? '');

$codigoInst = contrato_codigo_institucion_desde_curso($cursoAlumno);
if ($codigoInst === null) {
    echo json_encode(['ok' => false, 'msg' => 'No se pudo determinar la institución a partir del curso del alumno.']);
    $conn->close();
    exit;
}

$contract = contrato_fetch_vigente_por_codigo($conn, $codigoInst);
if ($contract === null) {
    $versionEsperada = contrato_version_desde_codigo_institucion($codigoInst);
    echo json_encode([
        'ok' => false,
        'msg' => 'No hay contrato vigente configurado para la institución «' . $codigoInst . '» (versión «' . $versionEsperada . '»). Revise contratos_instituciones (contrato_activo, contract_anio, contract_revision).',
    ]);
    $conn->close();
    exit;
}
$contractVersion = (string)$contract['contract_version'];

$signed = false;
$sqlSigned = "SELECT id FROM contratos_aceptados
              WHERE student_dni = ? AND nro_familia = ? AND contract_version = ? AND status = 'activo'
              LIMIT 1";
$stmtSigned = $conn->prepare($sqlSigned);
if ($stmtSigned) {
    $stmtSigned->bind_param('sss', $studentDni, $nroFamilia, $contractVersion);
    $stmtSigned->execute();
    $signedRows = fetchAllFromStmt($stmtSigned);
    $stmtSigned->close();
    $signed = !empty($signedRows);
}

$hasSignedHistory = false;
$sqlHistory = "SELECT id FROM contratos_aceptados WHERE student_dni = ? AND nro_familia = ? LIMIT 1";
$stmtHistory = $conn->prepare($sqlHistory);
if ($stmtHistory) {
    $stmtHistory->bind_param('ss', $studentDni, $nroFamilia);
    $stmtHistory->execute();
    $historyRows = fetchAllFromStmt($stmtHistory);
    $stmtHistory->close();
    $hasSignedHistory = !empty($historyRows);
}

if ($esInactivo && !$hasSignedHistory) {
    echo json_encode(['ok' => false, 'msg' => 'Alumno inactivo sin firmas previas: no corresponde nueva firma']);
    $conn->close();
    exit;
}

if (!$signed && !contrato_alumno_noviembre_abonado($conn, $nroLegajoAlumno, $cursoAlumno)) {
    echo json_encode(['ok' => false, 'msg' => contrato_msg_firma_bloqueada_noviembre()]);
    $conn->close();
    exit;
}

$instRow = contrato_fetch_institucion_por_codigo($conn, $codigoInst);
if ($instRow === null) {
    echo json_encode([
        'ok' => false,
        'msg' => $codigoInst === null
            ? 'No se pudo determinar la institución a partir del curso del alumno.'
            : 'Falta configurar la institución en la base de datos (tabla contratos_instituciones) para el código «' . $codigoInst . '». Complete los datos institucionales y active el contrato.',
    ]);
    $conn->close();
    exit;
}

$conn->close();
echo json_encode([
    'ok' => true,
    'signed' => $signed,
    'es_inactivo' => $esInactivo,
    'has_signed_history' => $hasSignedHistory,
    'responsable_email' => (string)($_SESSION['email'] ?? ''),
    'curso' => $cursoAlumno,
    'institucion_contrato' => [
        'codigo' => $instRow['codigo'],
        'curso' => $cursoAlumno,
        'responsable_institucion' => $instRow['responsable_institucion'],
        'institucion' => $instRow['institucion'],
        'domicilio_institucion' => $instRow['domicilio_institucion'],
    ],
    'contract' => [
        'contract_version' => $contractVersion,
        'accepted_text' => (string)$contract['accepted_text'],
        'reglamento_pdf_url' => contrato_reglamento_pdf_url_desde_codigo($codigoInst),
        'reglamento_link_label' => contrato_reglamento_info_desde_codigo($codigoInst)['modal_link_label'],
    ]
]);
