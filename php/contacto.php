<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/security.php';

function json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

$protectionError = validate_common_form_protection('contact', FORM_RATE_LIMIT_CONTACT);
if ($protectionError !== null) {
    json_error($protectionError);
}

$email = trim((string) ($_POST['email'] ?? ''));
$nombre = trim((string) ($_POST['nombre_apellido'] ?? ''));
$telefono = trim((string) ($_POST['telefono'] ?? ''));
$mensaje = trim((string) ($_POST['mensaje'] ?? ''));

if (!validate_email_format($email)) {
    json_error('El email no tiene un formato válido.');
}
if (!validate_text_field($nombre, 120)) {
    json_error('El nombre y apellido es obligatorio (máx. 120 caracteres).');
}
if (!validate_phone($telefono)) {
    json_error('El teléfono no es válido.');
}
if (!validate_text_field($mensaje, 2000)) {
    json_error('El mensaje es obligatorio (máx. 2000 caracteres).');
}

try {
    $conn = db_connect();
    $stmt = $conn->prepare('INSERT INTO contactos (email, nombre_apellido, telefono, mensaje) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $email, $nombre, $telefono, $mensaje);

    if ($stmt->execute()) {
        security_reset_form_session();
        echo json_encode(['status' => 'success']);
    } else {
        json_error('No se pudo guardar el mensaje.', 500);
    }

    $stmt->close();
    $conn->close();
} catch (Throwable $e) {
    json_error('Error del servidor.', 500);
}
