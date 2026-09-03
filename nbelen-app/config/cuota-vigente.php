<?php
// config/configuracion.php
if (!defined('_ACCESS')) {
    die('Acceso prohibido');
}

/**
 * Obtiene el valor de una configuración por su clave
 */
function getConfig($clave, $default = null) {
    global $conn; // o pasar la conexión por parámetro
    $stmt = $conn->prepare("SELECT valor FROM configuracion WHERE clave = ?");
    $stmt->bind_param("s", $clave);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['valor'];
    }
    return $default;
}

/**
 * Actualiza o inserta una configuración
 */
function setConfig($clave, $valor, $descripcion = '') {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE valor = VALUES(valor), descripcion = VALUES(descripcion)");
    $stmt->bind_param("sss", $clave, $valor, $descripcion);
    return $stmt->execute();
}
?>