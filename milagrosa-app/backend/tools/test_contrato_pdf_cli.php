<?php
/**
 * Prueba local de diagnóstico PDF (CLI): php backend/tools/test_contrato_pdf_cli.php
 */
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/backend/lib/contract_render.php';
require_once BASE_PATH . '/backend/lib/contract_pdf.php';

$result = contrato_pdf_diagnostic();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
