<?php
/**
 * Compatibilidad: redirige al dashboard refactorizado.
 */
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'admin_dashboard.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
