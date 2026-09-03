<?php
/**
 * Recibe el formulario de Trabajá con nosotros y guarda el CV en curriculums.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

foreach ([__DIR__ . '/../natta-app', dirname(__DIR__)] as $candidateRoot) {
    if (is_file($candidateRoot . '/config/db.php')) {
        define('NATTA_ROOT', $candidateRoot);
        break;
    }
}
if (!defined('NATTA_ROOT')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Configuración del servidor incompleta.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/security.php';
require_once NATTA_ROOT . '/includes/curriculums_lib.php';

function curriculum_json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    curriculum_json_error('Método no permitido.', 405);
}

$protectionError = validate_common_form_protection('curriculum', FORM_RATE_LIMIT_CV);
if ($protectionError !== null) {
    curriculum_json_error($protectionError);
}

$nombreApellido = trim((string)($_POST['nombre_apellido'] ?? ''));
$cuil = trim((string)($_POST['cuil'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$telefono = trim((string)($_POST['telefono'] ?? ''));
$area = curriculum_validar_area((string)($_POST['area'] ?? ''));

if (!validate_text_field($nombreApellido, 120)) {
    curriculum_json_error('Completá nombre y apellido (máx. 120 caracteres).');
}
if (!curriculum_validar_cuil($cuil)) {
    curriculum_json_error('El CUIL ingresado no es válido. Usá el formato xx-xxxxxxxx-xx.');
}
$cuil = curriculum_normalizar_cuil($cuil);
if (!validate_email_format($email)) {
    curriculum_json_error('El email ingresado no es válido.');
}
if (!validate_phone($telefono)) {
    curriculum_json_error('El teléfono no es válido.');
}
if ($area === null) {
    curriculum_json_error('Seleccioná el área para la que postulás.');
}

$requisitosSuplencias = curriculum_validar_requisitos_suplencias($area, $_POST);
if (curriculum_area_requiere_suplencias($area) && $requisitosSuplencias === null) {
    curriculum_json_error('Debés marcar los requisitos obligatorios para suplencias.');
}
$requisitosJson = $requisitosSuplencias !== null
    ? (json_encode($requisitosSuplencias, JSON_UNESCAPED_UNICODE) ?: '')
    : '';
$duplencias = curriculum_duplencias_desde_post($_POST);

$file = $_FILES['curriculum'] ?? null;
if (!is_array($file)) {
    curriculum_json_error('Debés adjuntar tu CV en PDF o Word.');
}

$validation = validate_uploaded_document($file, 8 * 1024 * 1024);
if (!$validation['ok']) {
    curriculum_json_error($validation['error'] ?? 'No se pudo procesar el archivo enviado.');
}

$uploadsDir = curriculum_public_uploads_dir();
if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0755, true)) {
    error_log('procesar_curriculum: no se pudo crear ' . $uploadsDir);
    curriculum_json_error('Error interno al guardar el archivo.', 500);
}

$ext = (string)($validation['ext'] ?? '');
$storedName = curriculum_sanitize_filename(pathinfo((string)($file['name'] ?? 'cv'), PATHINFO_FILENAME))
    . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = $uploadsDir . DIRECTORY_SEPARATOR . $storedName;

if (!move_uploaded_file((string)$file['tmp_name'], $destPath)) {
    error_log('procesar_curriculum: move_uploaded_file falló hacia ' . $destPath);
    curriculum_json_error('No se pudo guardar el CV. Intente nuevamente.', 500);
}

try {
    $conn = db_connect();
    curriculum_ensure_schema($conn);

    $fields = [
        'nombre_apellido' => $nombreApellido,
        'email'           => $email,
        'telefono'        => $telefono,
        'archivo'         => $storedName,
    ];

    if (curriculum_db_column_exists($conn, 'cuil')) {
        $fields['cuil'] = $cuil;
    }
    if (curriculum_db_column_exists($conn, 'area')) {
        $fields['area'] = $area;
    }
    if (curriculum_db_column_exists($conn, 'requisitos_suplencias')) {
        $fields['requisitos_suplencias'] = $requisitosJson;
    }
    if (curriculum_db_column_exists($conn, 'duplencias')) {
        $fields['duplencias'] = $duplencias;
    }

    $columns = array_keys($fields);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO curriculums (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('prepare insert');
    }

    $types = str_repeat('s', count($fields));
    $values = array_values($fields);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
    $conn->close();
} catch (Throwable $e) {
    @unlink($destPath);
    error_log('procesar_curriculum db: ' . $e->getMessage());
    curriculum_json_error('No se pudo registrar su postulación. Intente más tarde.', 500);
}

security_reset_form_session();

echo json_encode([
    'ok'  => true,
    'msg' => '¡Gracias! Recibimos su CV correctamente.',
], JSON_UNESCAPED_UNICODE);
