<?php
/**
 * Dashboard administrativo — punto de entrada.
 *
 * Backend distribuido en admin/includes/ y vistas en admin/views/.
 * Interactividad en frontend/js/pages/adminDashboardPage.js (patrón home.php).
 */

define('NATTA_ROOT', dirname(__DIR__));

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/post_handlers.php';

$cuota_vigente_actual = (int)getConfigPDO($pdo, 'cuota_vigente', 1);
$mantenimiento_activo = getConfigPDO($pdo, 'modo_mantenimiento', '0') === '1';

$importsDir = NATTA_ROOT . '/config/imports';
$admin_path_email_padres      = $importsDir . '/email-padres.csv';
$admin_path_legajos           = $importsDir . '/legajos.csv';
$admin_path_legajos_inactivos = $importsDir . '/legajos-inactivos.csv';

require __DIR__ . '/views/layout_start.php';

$tabViews = [
    'comunicados-generales'    => 'comunicados-generales.php',
    'comunicados-individuales' => 'comunicados-individuales.php',
    'qr-app'                   => 'qr-app.php',
    'informes'                 => 'informes.php',
    'emails'                   => 'emails.php',
    'talones'                  => 'talones.php',
    'sugerencias'              => 'sugerencias.php',
    'actualizaciones'          => 'actualizaciones.php',
    'configuracion'            => 'configuracion.php',
    'informacion-matriculas'          => 'informacion-matriculas.php',
    'informacion-ingresado-facturado' => 'informacion-ingresado-facturado.php',
    'informacion-app'                 => 'informacion-app.php',
    'listado-familias'         => 'listado-familias.php',
    'estado-alumno'            => 'estado-alumno.php',
    'revision-contratos'       => 'revision-contratos.php',
    'alta-alumnos-nuevos'      => 'alta-alumnos-nuevos.php',
    'lista-alumnos-nuevos'     => 'lista-alumnos-nuevos.php',
    'auditoria'                => 'auditoria.php',
    'auditoria-app'            => 'auditoria-app.php',
    'usuarios'                 => 'usuarios.php',
];

if (isset($tabViews[$active_tab]) && admin_can($active_tab)) {
    require __DIR__ . '/views/tabs/' . $tabViews[$active_tab];
} else {
    echo '<div class="alert alert-warning mt-3">No tiene permiso para esta sección o la pestaña no existe.</div>';
}

require __DIR__ . '/views/layout_end.php';
