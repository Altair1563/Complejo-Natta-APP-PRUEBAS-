<?php
/**
 * Exportación Excel de emails de familia (panel estado alumnos / secretaría).
 */

define('NATTA_ROOT', dirname(__DIR__));

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/estado_alumno_lib.php';

if (!admin_is_logged_in() || !admin_can_access_estado_alumno()) {
    http_response_code(403);
    echo 'No autorizado.';
    exit;
}

$escuelas = estado_alumno_escuelas_catalog();
$escuelaActiva = estado_alumno_resolve_escuela_activa($escuelas, (string)($_GET['escuela'] ?? tenant_escuela_default()));
$filtrosEmails = estado_alumno_parse_filtros_emails();

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    require_once __DIR__ . '/includes/db_collate.php';
    admin_mysqli_apply_collation($conn);
} catch (Throwable $e) {
    error_log('exportar_estado_alumno_emails conexión: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error interno.';
    exit;
}

try {
    $cursosPorEscuela = estado_alumno_construir_cursos_por_escuela($conn, $escuelas);
    $emailsData = estado_alumno_cargar_emails_alumnos($conn, $escuelaActiva, $filtrosEmails, $cursosPorEscuela);
    $conn->close();

    if (($emailsData['error'] ?? '') !== '') {
        http_response_code(500);
        echo $emailsData['error'];
        exit;
    }

    estado_alumno_export_emails_excel(
        $emailsData['alumnos'] ?? [],
        $escuelaActiva,
        (string)($escuelas[$escuelaActiva] ?? $escuelaActiva)
    );
} catch (Throwable $e) {
    $conn->close();
    error_log('exportar_estado_alumno_emails: ' . $e->getMessage());
    http_response_code(500);
    echo 'Error al exportar.';
    exit;
}
