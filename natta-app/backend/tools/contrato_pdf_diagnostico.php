<?php
/**
 * Diagnóstico de Dompdf y almacenamiento de PDFs firmados.
 * Solo CLI: php backend/tools/contrato_pdf_diagnostico.php
 */
require_once __DIR__ . '/_cli_only.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/contract_pdf.php';

$result = contrato_pdf_diagnostic();
$result['php_version'] = PHP_VERSION;
$result['memory_limit'] = ini_get('memory_limit');
$result['max_execution_time'] = ini_get('max_execution_time');
$result['base_path'] = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
$result['hint'] = !empty($result['ok'])
    ? 'El servidor está listo para generar PDFs al firmar contratos.'
    : 'Corrija los ítems con ok:false antes de firmar en producción. Suba vendor/ o ejecute composer install en natta-app.';

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
