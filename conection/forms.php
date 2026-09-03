<?php
/**
 * Configuración de formularios (protegido por .htaccess en /conection/)
 */
if (!defined('_ACCESS')) {
    http_response_code(403);
    die('Acceso prohibido');
}

// Límites de envío por IP (ventana en segundos)
define('FORM_RATE_LIMIT_CONTACT', 5);
define('FORM_RATE_LIMIT_CV', 5);
define('FORM_RATE_WINDOW', 3600);

// Tiempo mínimo entre carga del formulario y envío (anti-bot)
define('FORM_MIN_SECONDS', 3);

// Curriculum: tamaño máximo 5 MB
define('CV_MAX_BYTES', 5 * 1024 * 1024);

// reCAPTCHA v3 (opcional). Dejar vacío para usar solo honeypot + rate limit.
define('RECAPTCHA_SITE_KEY', '');
define('RECAPTCHA_SECRET_KEY', '');
define('RECAPTCHA_MIN_SCORE', 0.5);
