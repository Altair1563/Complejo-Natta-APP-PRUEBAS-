<?php
/**
 * Configuración del tenant (institución).
 * Cada carpeta de app (natta-app, milagrosa-app, …) apunta a su propia DB
 * y define aquí escuelas/cursos/branding. El código de negocio es el mismo.
 *
 * Convención de cursos Jardin de Infantes La Milagrosa (3 letras):
 *   SCI / SRI / SVI / NUI
 * La "escuela" (sala) se infiere con las últimas 2 letras del curso:
 *   SCI→CI, SRI→RI, SVI→VI, NUI→UI
 */

if (!defined('_ACCESS') && !defined('TENANT_BOOTSTRAP')) {
    http_response_code(403);
    die('Acceso prohibido');
}

return [
    'id' => 'milagrosa',
    'slug' => 'milagrosa-app',
    'name' => 'Jardin de Infantes La Milagrosa',
    'name_short' => 'Milagrosa',
    'name_legal' => 'Instituto Jardin de Infantes La Milagrosa',
    'public_url_fallback' => 'https://complejonatta.com/milagrosa-app/index.php',

    /** Jardin de Infantes La Milagrosa es una única institución; las salas son cursos/filtros internos. */
    'institucion_unica' => true,
    'admin_scope_codigo' => 'AL',

    'db' => [
        'host' => '127.0.0.1:3306',
        'user' => 'u207063327_Elias3',
        'pass' => 'G4ig=e?G;',
        'name' => 'u207063327_LaMilagrosa',
    ],

    /**
     * Código de 2 letras (últimas del curso) => nombre de sala/establecimiento.
     */
    'escuelas' => [
        'CI' => 'Sala Celeste Inicial',
        'RI' => 'Sala Roja Inicial',
        'VI' => 'Sala Verde Inicial',
        'UI' => 'Nuevos Inicial',
    ],

    /**
     * Código escuela => lista de cursos válidos en esa sala.
     */
    'cursos_por_escuela' => [
        'CI' => ['SCI'],
        'RI' => ['SRI'],
        'VI' => ['SVI'],
        'UI' => ['NUI'],
    ],

    /** Curso por defecto al dar de alta alumnos nuevos. */
    'curso_alta_nuevos' => 'NUI',

    /** Escuela por defecto en paneles admin cuando no hay filtro. */
    'escuela_default' => 'CI',

    'branding' => [
        'app_share_title' => 'App Jardin de Infantes La Milagrosa',
        'app_share_text' => 'Accedé a la APP de Jardin de Infantes La Milagrosa',
        'qr_modal_title' => 'Compartir la APP Jardin de Infantes La Milagrosa',
        'contract_logo_alt' => 'Instituto Jardin de Infantes La Milagrosa',
        'email_from_name' => 'Jardin de Infantes La Milagrosa',
        'logo_file' => 'LogoMilagrosa.png',
    ],
];
