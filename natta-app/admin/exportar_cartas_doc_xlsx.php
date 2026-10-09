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
    admin_load_cartas_doc_data();
    $ruta = admin_cd_ruta_salida();
    if (!is_file($ruta)) {
        throw new RuntimeException('No se generó el Excel.');
    }
} catch (RuntimeException $e) {
    error_log('exportar_cartas_doc_xlsx: ' . $e->getMessage());
    http_response_code(500);
    exit('Error al exportar.');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="legajos + resp - deuda.xlsx"');
header('Content-Length: ' . (string)filesize($ruta));
readfile($ruta);
exit;
