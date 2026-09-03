<?php
/**
 * Configuración del tenant (institución).
 * Cada carpeta de app (natta-app, milagrosa-app, nbelen-app, …) apunta a su propia DB
 * y define aquí escuelas/cursos/branding. El código de negocio es el mismo.
 *
 * Convención de cursos Nuestra Señora de Itati:
 *   {grado}{división}{nivel}  →  1AJ, 1BP, 2AS, …
 *   J = Jardín, P = Primaria, S = Secundaria
 *
 * La escuela NO se infiere solo con las últimas 2 letras (1AJ→AJ, 1BJ→BJ rompería
 * el agrupamiento). Se resuelve por el catálogo `cursos_por_escuela` (y, si hace
 * falta, por la letra final J/P/S → JB/PB/SB).
 */

if (!defined('_ACCESS') && !defined('TENANT_BOOTSTRAP')) {
    http_response_code(403);
    die('Acceso prohibido');
}

return [
    'id' => 'nbelen',
    'slug' => 'nbelen-app',
    'name' => 'Nuestra Señora de Itati',
    'name_short' => 'N.S. Itati',
    'name_legal' => 'Instituto Nuestra Señora de Itati',
    'public_url_fallback' => 'https://complejonatta.com/nbelen-app/index.php',

    /** Una sola institución con tres niveles (Jardín / Primaria / Secundaria). */
    'institucion_unica' => true,
    'admin_scope_codigo' => 'NB',

    'db' => [
        'host' => '127.0.0.1:3306',
        'user' => 'u207063327_Elias4',
        'pass' => '>YjTJ6l2',
        'name' => 'u207063327_NBelen',
    ],

    /**
     * Código de 2 letras => nombre del nivel/establecimiento.
     * Códigos usados también en contratos_instituciones.
     */
    'escuelas' => [
        'JB' => 'Jardín de Infantes Pinocho',
        'PB' => 'Primaria Nuestra Señora de Itati',
        'SB' => 'Secundaria Nuestra Señora de Itati',
    ],

    /**
     * Código escuela => lista de cursos válidos.
     */
    'cursos_por_escuela' => [
        'JB' => ['1AJ', '1BJ', '2AJ', '2BJ', '3AJ', '3BJ', 'NAN'],
        'PB' => [
            '1AP', '1BP',
            '2AP', '2BP',
            '3AP', '3BP',
            '4AP', '4BP',
            '5AP', '5BP',
            '6AP', '6BP',
        ],
        'SB' => [
            '1AS',
            '2AS', '2BS',
            '3AS', '3BS',
            '4AS',
            '5AS',
            '6AS',
        ],
    ],

    /**
     * Letra final del curso → código de escuela (respaldo si el curso no está en el catálogo).
     */
    'escuela_por_letra_final' => [
        'J' => 'JB',
        'P' => 'PB',
        'S' => 'SB',
        'N' => 'JB', // NAN (ingresos nuevos) → Jardín
    ],

    /** Curso por defecto al dar de alta alumnos nuevos. */
    'curso_alta_nuevos' => 'NAN',

    /** Escuela por defecto en paneles admin cuando no hay filtro. */
    'escuela_default' => 'JB',

    'branding' => [
        'app_share_title' => 'App Nuestra Señora de Itati',
        'app_share_text' => 'Accedé a la APP de Nuestra Señora de Itati',
        'qr_modal_title' => 'Compartir la APP Nuestra Señora de Itati',
        'contract_logo_alt' => 'Instituto Nuestra Señora de Itati',
        'email_from_name' => 'Nuestra Señora de Itati',
        'logo_file' => 'LogoNbelen.jpg',
        'titular_cuenta' => 'COMPLEJO EDUCATIVO NTRA SRA DE ITATI',
    ],
];
