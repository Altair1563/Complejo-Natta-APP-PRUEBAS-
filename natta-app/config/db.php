<?php
/**
 * CONFIGURACIÓN DE BASE DE DATOS
 * No accesible directamente desde el navegador (protegido por .htaccess)
 */

// Si no se define la constante de acceso, se bloquea la ejecución
if (!defined('_ACCESS')) {
    http_response_code(403);
    die('Acceso prohibido');
}

define('DB_HOST', '127.0.0.1:3306');
define('DB_USER', 'u207063327_Elias2');
define('DB_PASS', 'QZdBxxPqCs8PN?$');
define('DB_NAME', 'u207063327_contactos_db');

// Opcional: zona horaria y charset
date_default_timezone_set('America/Argentina/Buenos_Aires');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);