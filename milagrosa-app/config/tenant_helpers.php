<?php
/**
 * Helpers para leer la config del tenant sin acoplar cada include a tenant.php.
 */

if (!function_exists('tenant_config')) {
    /**
     * @return array<string, mixed>
     */
    function tenant_config(): array
    {
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg;
        }
        if (!defined('TENANT_BOOTSTRAP')) {
            define('TENANT_BOOTSTRAP', true);
        }
        $cfg = require __DIR__ . '/tenant.php';
        if (!is_array($cfg)) {
            $cfg = [];
        }

        return $cfg;
    }
}

if (!function_exists('tenant_escuelas_catalog')) {
    /**
     * @return array<string, string>
     */
    function tenant_escuelas_catalog(): array
    {
        $escuelas = tenant_config()['escuelas'] ?? [];

        return is_array($escuelas) ? $escuelas : [];
    }
}

if (!function_exists('tenant_cursos_por_escuela')) {
    /**
     * @return array<string, list<string>>
     */
    function tenant_cursos_por_escuela(): array
    {
        $cursos = tenant_config()['cursos_por_escuela'] ?? [];

        return is_array($cursos) ? $cursos : [];
    }
}

if (!function_exists('tenant_escuela_default')) {
    function tenant_escuela_default(): string
    {
        $code = strtoupper(trim((string)(tenant_config()['escuela_default'] ?? '')));
        $catalog = tenant_escuelas_catalog();
        if ($code !== '' && isset($catalog[$code])) {
            return $code;
        }
        $keys = array_keys($catalog);

        return (string)($keys[0] ?? 'CI');
    }
}

if (!function_exists('tenant_curso_alta_nuevos')) {
    function tenant_curso_alta_nuevos(): string
    {
        return strtoupper(trim((string)(tenant_config()['curso_alta_nuevos'] ?? 'NUI')));
    }
}

if (!function_exists('tenant_institucion_unica')) {
    function tenant_institucion_unica(): bool
    {
        return !empty(tenant_config()['institucion_unica']);
    }
}

if (!function_exists('tenant_admin_scope_codigo')) {
    function tenant_admin_scope_codigo(): string
    {
        return strtoupper(trim((string)(tenant_config()['admin_scope_codigo'] ?? 'AL')));
    }
}

if (!function_exists('tenant_admin_tiene_alcance_total')) {
    function tenant_admin_tiene_alcance_total(?string $codigo = null): bool
    {
        if (!tenant_institucion_unica()) {
            return false;
        }

        $codigo = $codigo === null && function_exists('admin_escuela_codigo')
            ? admin_escuela_codigo()
            : strtoupper(trim((string)$codigo));

        // Compatibilidad con usuarios existentes que todavía guardan CI/RI/VI/UI.
        return $codigo === tenant_admin_scope_codigo() || isset(tenant_escuelas_catalog()[$codigo]);
    }
}

if (!function_exists('tenant_name')) {
    function tenant_name(): string
    {
        return (string)(tenant_config()['name'] ?? 'Jardin de Infantes La Milagrosa');
    }
}

if (!function_exists('tenant_slug')) {
    function tenant_slug(): string
    {
        return (string)(tenant_config()['slug'] ?? 'milagrosa-app');
    }
}

if (!function_exists('tenant_branding')) {
    /**
     * @return array<string, string>
     */
    function tenant_branding(): array
    {
        $b = tenant_config()['branding'] ?? [];

        return is_array($b) ? $b : [];
    }
}

if (!function_exists('tenant_logo_file')) {
    function tenant_logo_file(): string
    {
        $file = trim((string)(tenant_branding()['logo_file'] ?? 'LogoMilagrosa.png'));

        return $file !== '' ? $file : 'LogoMilagrosa.png';
    }
}
