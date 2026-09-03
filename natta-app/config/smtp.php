<?php
/**
 * Configuración SMTP para envío de correos
 * 
 * IMPORTANTE: Este archivo debe estar protegido por .htaccess (Deny from all)
 */

// Evitar acceso directo desde el navegador
if (!defined('_ACCESS')) {
    http_response_code(403);
    die('Acceso prohibido');
}

// Datos de conexión SMTP
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_USER', 'administracion@complejonatta.com');
define('SMTP_PASS', 'W+XSJH&We=0');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_FROM', 'administracion@complejonatta.com');
define('SMTP_FROM_NAME', 'Complejo Natta');