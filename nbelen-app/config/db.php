<?php
/**
 * CONFIGURACIÓN DE BASE DE DATOS (tenant)
 * No accesible directamente desde el navegador (protegido por .htaccess)
 */

if (!defined('_ACCESS')) {
    http_response_code(403);
    die('Acceso prohibido');
}

if (!defined('TENANT_BOOTSTRAP')) {
    define('TENANT_BOOTSTRAP', true);
}
$tenant = require __DIR__ . '/tenant.php';
$db = $tenant['db'] ?? [];

define('DB_HOST', (string)($db['host'] ?? '127.0.0.1:3306'));
define('DB_USER', (string)($db['user'] ?? ''));
define('DB_PASS', (string)($db['pass'] ?? ''));
define('DB_NAME', (string)($db['name'] ?? ''));

if (!defined('TENANT_ID')) {
    define('TENANT_ID', (string)($tenant['id'] ?? 'nbelen'));
}
if (!defined('TENANT_SLUG')) {
    define('TENANT_SLUG', (string)($tenant['slug'] ?? 'nbelen-app'));
}
if (!defined('TENANT_NAME')) {
    define('TENANT_NAME', (string)($tenant['name'] ?? 'Nuestra Señora de Itati'));
}

date_default_timezone_set('America/Argentina/Buenos_Aires');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
