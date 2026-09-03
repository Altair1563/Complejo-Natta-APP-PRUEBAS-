<?php
/**
 * Utilidades de seguridad para formularios públicos.
 * Solo debe incluirse desde otros scripts PHP.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Acceso prohibido');
}

define('_ACCESS', true);
require_once __DIR__ . '/../conection/forms.php';

function security_init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            session_set_cookie_params(0, '/', '', $secure, true);
        }
        session_start();
    }
}

function csrf_generate_token(): string
{
    security_init_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    if (empty($_SESSION['form_start'])) {
        $_SESSION['form_start'] = time();
    }
    return $_SESSION['csrf_token'];
}

function security_reset_form_session(): void
{
    security_init_session();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['form_start'] = time();
}

function form_timing_is_valid(int $minSeconds = FORM_MIN_SECONDS): bool
{
    security_init_session();
    if (empty($_SESSION['form_start'])) {
        return false;
    }
    $elapsed = time() - (int) $_SESSION['form_start'];
    return $elapsed >= $minSeconds && $elapsed <= 86400;
}

function csrf_validate(?string $token): bool
{
    security_init_session();
    return isset($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

function security_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rate_limit_check(string $action, int $maxAttempts, int $windowSeconds): bool
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'natta_rate_limit';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $ip = security_client_ip();
    $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $action . '|' . $ip) . '.json';
    $now = time();
    $attempts = [];

    if (is_file($file)) {
        $raw = file_get_contents($file);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $attempts = array_values(array_filter($decoded, static fn($ts) => is_int($ts) && ($now - $ts) < $windowSeconds));
        }
    }

    if (count($attempts) >= $maxAttempts) {
        return false;
    }

    $attempts[] = $now;
    file_put_contents($file, json_encode($attempts), LOCK_EX);

    return true;
}

function honeypot_is_valid(): bool
{
    return !isset($_POST['website']) || trim((string) $_POST['website']) === '';
}

function validate_email_format(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && strlen($email) <= 254;
}

function validate_phone(string $phone): bool
{
    $phone = trim($phone);
    return $phone !== '' && strlen($phone) <= 30 && preg_match('/^[\d\s+\-().]{6,30}$/', $phone);
}

function validate_text_field(string $value, int $maxLength): bool
{
    $value = trim($value);
    return $value !== '' && mb_strlen($value) <= $maxLength;
}

function verify_recaptcha(?string $token): bool
{
    if (RECAPTCHA_SECRET_KEY === '') {
        return true;
    }
    if ($token === null || $token === '') {
        return false;
    }

    $payload = http_build_query([
        'secret' => RECAPTCHA_SECRET_KEY,
        'response' => $token,
        'remoteip' => security_client_ip(),
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 5,
        ],
    ]);

    $response = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    if ($response === false) {
        return false;
    }

    $data = json_decode($response, true);
    return !empty($data['success']) && ($data['score'] ?? 0) >= RECAPTCHA_MIN_SCORE;
}

function validate_common_form_protection(string $rateAction, int $rateLimit): ?string
{
    if (!honeypot_is_valid()) {
        return 'Solicitud rechazada.';
    }
    if (!form_timing_is_valid()) {
        return 'El formulario se envió demasiado rápido. Intentá de nuevo.';
    }
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        return 'Token de seguridad inválido. Recargá la página e intentá de nuevo.';
    }
    if (!rate_limit_check($rateAction, $rateLimit, FORM_RATE_WINDOW)) {
        return 'Demasiados envíos. Intentá más tarde.';
    }
    if (!verify_recaptcha($_POST['g-recaptcha-response'] ?? null)) {
        return 'Verificación anti-spam fallida.';
    }
    return null;
}

function db_connect(): mysqli
{
    if (!defined('DB_HOST')) {
        require_once __DIR__ . '/../conection/db.php';
    }
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new RuntimeException('Conexión fallida');
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * @return array{ok:bool,error?:string,ext?:string,safe_name?:string}
 */
function validate_uploaded_document(array $file, int $maxBytes = CV_MAX_BYTES): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Error en la carga del archivo.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Archivo no válido.'];
    }
    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'El archivo supera el tamaño máximo permitido (5 MB).'];
    }

    $originalName = basename((string) $file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'doc', 'docx'];
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'Solo se permiten archivos PDF, DOC y DOCX.'];
    }

    $allowedMime = [
        'pdf' => ['application/pdf', 'application/x-pdf'],
        'doc' => ['application/msword', 'application/vnd.ms-word'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($mime === false || !in_array($mime, $allowedMime[$ext], true)) {
        return ['ok' => false, 'error' => 'El tipo de archivo no coincide con la extensión.'];
    }

    $handle = fopen($file['tmp_name'], 'rb');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'No se pudo leer el archivo.'];
    }
    $header = fread($handle, 8);
    fclose($handle);

    if ($ext === 'pdf' && strncmp($header, '%PDF', 4) !== 0) {
        return ['ok' => false, 'error' => 'El archivo PDF no es válido.'];
    }
    if ($ext === 'doc' && strncmp($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) !== 0) {
        return ['ok' => false, 'error' => 'El archivo DOC no es válido.'];
    }
    if ($ext === 'docx' && strncmp($header, "PK\x03\x04", 4) !== 0) {
        return ['ok' => false, 'error' => 'El archivo DOCX no es válido.'];
    }

    $safeName = 'cv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

    return ['ok' => true, 'ext' => $ext, 'safe_name' => $safeName];
}
