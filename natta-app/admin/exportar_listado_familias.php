<?php
require_once __DIR__ . '/includes/guard.php';
admin_guard_or_die();

// Dashboard (cap listado-familias) o panel Secretaría · Estado alumnos.
if (!admin_can('listado-familias') && !admin_can_access_estado_alumno()) {
    http_response_code(403);
    exit('Acceso denegado');
}

define('NATTA_ROOT', dirname(__DIR__));
require_once __DIR__ . '/includes/listado_familias_data.php';

try {
    $data = admin_load_listado_familias_data();
    admin_export_listado_familias_csv($data);
} catch (RuntimeException $e) {
    error_log('exportar_listado_familias: ' . $e->getMessage());
    http_response_code(500);
    exit('Error al exportar.');
}
