<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Argentina/Buenos_Aires');

if (isset($_SESSION['dni_alumno'])) {
    header('Location: ../home.php');
    exit;
}

$gate = $_SESSION['privacy_gate'] ?? null;
if (!is_array($gate) || empty($gate['dni_alumno']) || empty($gate['email']) || empty($gate['nro_familia'])) {
    header('Location: ../index.php');
    exit;
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    $_SESSION['error_privacy_gate'] = 'Token de seguridad inválido. Recargue la página.';
    header('Location: privacy_gate.php');
    exit;
}

if ((string)($_POST['privacy_accept'] ?? '') !== '1') {
    $_SESSION['error_privacy_gate'] = 'Debe marcar la casilla para aceptar la Política de Privacidad.';
    header('Location: privacy_gate.php');
    exit;
}

define('_ACCESS', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/privacy_policy.php';
require_once __DIR__ . '/../config/app_audit.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log('privacy_gate_submit.php: ' . $conn->connect_error);
    $_SESSION['error_privacy_gate'] = 'Error de conexión. Intente más tarde.';
    header('Location: privacy_gate.php');
    exit;
}
$conn->set_charset('utf8mb4');

$dni = (string)$gate['dni_alumno'];
$email = (string)$gate['email'];
$nroFam = (string)$gate['nro_familia'];
$gateVersion = (string)$gate['policy_version'];

$active = null;
try {
    $active = privacy_policy_get_active($conn);
} catch (Throwable $e) {
    error_log('privacy_gate_submit.php get_active: ' . $e->getMessage());
}

if ($active === null || (string)$active['policy_version'] !== $gateVersion) {
    unset($_SESSION['privacy_gate']);
    $_SESSION['error_login'] = 'La política de privacidad fue actualizada. Inicie sesión nuevamente.';
    $conn->close();
    header('Location: ../index.php');
    exit;
}

$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
$acceptedAt = date('Y-m-d H:i:s');
$policyHashLive = (string)$active['policy_hash'];

try {
    if (!privacy_policy_try_record_acceptance(
        $conn,
        $dni,
        $email,
        $nroFam,
        $gateVersion,
        $policyHashLive,
        $acceptedAt,
        $ip,
        $userAgent
    )) {
        error_log('privacy_gate_submit.php: fallo al registrar aceptación para ' . $email);
        $_SESSION['error_privacy_gate'] = 'No se pudo registrar la aceptación. Intente nuevamente.';
        $conn->close();
        header('Location: privacy_gate.php');
        exit;
    }
} catch (Throwable $e) {
    error_log('privacy_gate_submit.php insert: ' . $e->getMessage());
    $_SESSION['error_privacy_gate'] = 'Error al guardar. Intente nuevamente.';
    $conn->close();
    header('Location: privacy_gate.php');
    exit;
}

app_audit_log($conn, 'privacy_aceptada', 'privacy_policy', $gateVersion, [
    'policy_hash' => $policyHashLive,
], $dni, $email, $nroFam);

unset($_SESSION['privacy_gate']);

$_SESSION['dni_alumno'] = $dni;
$_SESSION['nro_familia'] = $nroFam;
$_SESSION['email'] = $email;
require_once __DIR__ . '/../config/app.php';
app_stamp_family_session();

secure_session_regenerate();

app_audit_log($conn, 'login_ok', 'sesion', null, [
    'via' => 'privacy_gate',
], $dni, $email, $nroFam);

$conn->close();

header('Location: ../home.php');
exit;