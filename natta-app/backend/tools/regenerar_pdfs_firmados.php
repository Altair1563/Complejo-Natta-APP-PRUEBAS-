<?php
/**
 * Regenera PDFs firmados en storage con el render actual (márgenes cero).
 * Solo CLI: php backend/tools/regenerar_pdfs_firmados.php [año]
 */
require_once __DIR__ . '/_cli_only.php';

define('BASE_PATH', dirname(__DIR__, 2));

if (!defined('_ACCESS')) {
    define('_ACCESS', true);
}

require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/backend/lib/contract_render.php';
require_once BASE_PATH . '/backend/lib/contract_pdf.php';

$year = isset($argv[1]) ? trim($argv[1]) : '2026';

try {
    $conn = app_mysqli();
    if ($conn->connect_error) {
        throw new RuntimeException('DB: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');

    $results = contrato_regenerate_signed_pdfs_from_db($conn, $year);
    $conn->close();

    if ($results === []) {
        fwrite(STDERR, "No se encontraron PDFs firmados para regenerar (año {$year}).\n");
        exit(1);
    }

    foreach ($results as $row) {
        $status = !empty($row['updated']) ? 'OK+DB' : (!empty($row['sha256']) ? 'OK' : 'FAIL');
        echo $status . ' ' . ($row['pdf_relative'] ?? '') . PHP_EOL;
        if (!empty($row['sha256'])) {
            echo '  sha256=' . substr($row['sha256'], 0, 16) . '...' . PHP_EOL;
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
