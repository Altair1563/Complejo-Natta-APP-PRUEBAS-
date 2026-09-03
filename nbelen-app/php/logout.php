<?php
// ============================================
// logout.php - Cierre de sesión seguro
// ============================================

require_once __DIR__ . '/../config/session.php';
secure_session_start();

if (isset($_SESSION['dni_alumno'], $_SESSION['nro_familia'])) {
    define('_ACCESS', true);
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/app_audit.php';

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$conn->connect_error) {
        $conn->set_charset('utf8mb4');
        app_audit_log(
            $conn,
            'logout',
            'sesion',
            null,
            [],
            (string)$_SESSION['dni_alumno'],
            isset($_SESSION['email']) ? (string)$_SESSION['email'] : null,
            (string)$_SESSION['nro_familia']
        );
        $conn->close();
    }
}

destroy_session_fully();

// Evitar que la página se almacene en caché (para que el botón "atrás" no muestre contenido privado)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Redirigir al inicio
header("Location: /nbelen-app/index.php");
exit;
