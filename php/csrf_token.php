<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    require_once __DIR__ . '/security.php';

    echo json_encode([
        'csrf_token' => csrf_generate_token(),
        'recaptcha_site_key' => RECAPTCHA_SITE_KEY,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'No se pudo generar el token de seguridad.']);
}
