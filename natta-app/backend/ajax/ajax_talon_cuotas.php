<?php
require_once __DIR__ . '/../../backend/bootstrap.php';
require_once __DIR__ . '/../lib/talon_context.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido']);
    exit;
}

if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida']);
    exit;
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token CSRF inválido']);
    exit;
}

$nroFamilia = filter_var($_SESSION['nro_familia'], FILTER_VALIDATE_INT);
if ($nroFamilia === false || $nroFamilia <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Sesión inválida']);
    exit;
}

try {
    $conn = getDbConnection();
    $cuotasPorAlumno = talon_ctx_build_cuotas_por_alumno($conn, (int)$nroFamilia);
    $conn->close();
    echo json_encode([
        'ok' => true,
        'cuotasPorAlumno' => $cuotasPorAlumno,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('ajax_talon_cuotas: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Error al cargar cuotas']);
}
