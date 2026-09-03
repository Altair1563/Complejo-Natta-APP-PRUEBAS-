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
define('DB_USER', 'u694426208_admin');
define('DB_PASS', '9b^0i4+njS#');
define('DB_NAME', 'u694426208_test_natta');

// Opcional: zona horaria y charset
date_default_timezone_set('America/Argentina/Buenos_Aires');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);