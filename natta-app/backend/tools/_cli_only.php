<?php
/**
 * Defensa en profundidad: estos scripts no deben correr por HTTP
 * aunque falle el .htaccess del directorio.
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    exit;
}
