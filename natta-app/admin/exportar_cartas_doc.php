<?php
require_once __DIR__ . '/includes/guard.php';
admin_guard_or_die();

if (!admin_can('cartas-doc')) {
    http_response_code(403);
    exit('Acceso denegado');
}

define('NATTA_ROOT', dirname(__DIR__));
require_once __DIR__ . '/includes/cartas_doc_lib.php';

try {
    $data = admin_load_cartas_doc_data();
    admin_export_cartas_doc_csv($data);
} catch (RuntimeException $e) {
    error_log('exportar_cartas_doc: ' . $e->getMessage());
    http_response_code(500);
    exit('Error al exportar.');
}
