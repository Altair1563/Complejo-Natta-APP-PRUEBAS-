<?php
require_once __DIR__ . '/../config/session.php';
secure_session_start();

$gate = $_SESSION['privacy_gate'] ?? null;
if (is_array($gate) && !empty($gate['dni_alumno'])) {
    define('_ACCESS', true);
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/app_audit.php';

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$conn->connect_error) {
        $conn->set_charset('utf8mb4');
        app_audit_log(
            $conn,
            'privacy_rechazada',
            'privacy_policy',
            isset($gate['policy_version']) ? (string)$gate['policy_version'] : null,
            [],
            (string)$gate['dni_alumno'],
            isset($gate['email']) ? (string)$gate['email'] : null,
            isset($gate['nro_familia']) ? (string)$gate['nro_familia'] : null
        );
        $conn->close();
    }
}

unset($_SESSION['privacy_gate']);
unset($_SESSION['error_privacy_gate']);

header('Location: ../index.php');
exit;
