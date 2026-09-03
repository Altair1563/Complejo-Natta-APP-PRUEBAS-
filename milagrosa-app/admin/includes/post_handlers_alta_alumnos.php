<?php
/**
 * POST: alta de alumnos nuevos.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['accion'] ?? '') !== 'alta_alumno_crear') {
    return;
}

admin_require_capability('alta-alumnos-nuevos');
admin_verify_csrf_post();
require_once __DIR__ . '/alta_alumnos_nuevos_lib.php';

$resultado = admin_aan_procesar_alta($pdo, $_POST);
admin_redirect_tab('alta-alumnos-nuevos', strip_tags($resultado['html']), $resultado['type']);
exit;
