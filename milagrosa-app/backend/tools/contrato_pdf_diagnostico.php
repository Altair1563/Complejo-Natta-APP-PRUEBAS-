<?php
/**
 * Diagnóstico de Dompdf y almacenamiento de PDFs firmados (solo administración).
 * URL: /milagrosa-app/backend/tools/contrato_pdf_diagnostico.php
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/contract_pdf.php';

if (!defined('_ACCESS')) {
    define('_ACCESS', true);
}
require_once BASE_PATH . '/config/admin.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (password_verify((string)$_POST['admin_password'], ADMIN_PASSWORD_HASH)) {
        $_SESSION['admin_logged_in'] = true;
    }
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Diagnóstico PDF contratos</title></head><body>';
    echo '<h1>Diagnóstico PDF firmado</h1><p>Acceso restringido a administración.</p>';
    echo '<form method="post"><label>Contraseña admin <input type="password" name="admin_password" required></label> ';
    echo '<button type="submit">Entrar</button></form></body></html>';
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

$result = contrato_pdf_diagnostic();
$result['php_version'] = PHP_VERSION;
$result['memory_limit'] = ini_get('memory_limit');
$result['max_execution_time'] = ini_get('max_execution_time');
$result['base_path'] = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
$result['hint'] = $result['ok']
    ? 'El servidor está listo para generar PDFs al firmar contratos.'
    : 'Corrija los ítems con ok:false antes de firmar en producción. Suba vendor/ o ejecute composer install en natta-app.';

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
