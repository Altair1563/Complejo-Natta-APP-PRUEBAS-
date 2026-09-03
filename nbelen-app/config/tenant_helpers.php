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

if (!function_exists('tenant_curso_a_escuela_map')) {
    /**
     * Mapa curso (MAYÚSCULAS) => código escuela.
     *
     * @return array<string, string>
     */
    function tenant_curso_a_escuela_map(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach (tenant_cursos_por_escuela() as $escuela => $cursos) {
            $escuela = strtoupper(trim((string)$escuela));
            if ($escuela === '') {
                continue;
            }
            foreach ((array)$cursos as $curso) {
                $curso = mb_strtoupper(trim((string)$curso), 'UTF-8');
                if ($curso !== '') {
                    $map[$curso] = $escuela;
                }
            }
        }

        return $map;
    }
}

if (!function_exists('tenant_escuela_desde_curso')) {
    /**
     * Resuelve el código de escuela (2 letras) a partir del curso.
     * 1) Catálogo cursos_por_escuela
     * 2) escuela_por_letra_final (última letra del curso)
     * 3) Últimas 2 letras del curso (convención Natta/Milagrosa)
     */
    function tenant_escuela_desde_curso(?string $curso): string
    {
        $curso = mb_strtoupper(trim((string)$curso), 'UTF-8');
        if ($curso === '') {
            return '';
        }

        $map = tenant_curso_a_escuela_map();
        if (isset($map[$curso])) {
            return $map[$curso];
        }

        $letraMap = tenant_config()['escuela_por_letra_final'] ?? [];
        if (is_array($letraMap) && $letraMap !== [] && mb_strlen($curso, 'UTF-8') >= 1) {
            $letra = mb_substr($curso, -1, 1, 'UTF-8');
            if (isset($letraMap[$letra])) {
                return strtoupper(trim((string)$letraMap[$letra]));
            }
        }

        if (mb_strlen($curso, 'UTF-8') >= 2) {
            return mb_substr($curso, -2, 2, 'UTF-8');
        }

        return '';
    }
}

if (!function_exists('tenant_filtro_sql_escuela')) {
    /**
     * Fragmento SQL + params para filtrar legajos por escuela.
     * Si los cursos del catálogo no terminan en el código de escuela (caso Nbelen),
     * usa IN (...). Si coinciden (Natta/Milagrosa), usa RIGHT(..., 2).
     *
     * @return array{sql:string, types:string, params:list<string>}
     */
    function tenant_filtro_sql_escuela(string $escuelaCodigo, string $cursoExpr = 'l.curso'): array
    {
        $escuelaCodigo = strtoupper(trim($escuelaCodigo));
        $cursos = [];
        foreach ((array)(tenant_cursos_por_escuela()[$escuelaCodigo] ?? []) as $curso) {
            $curso = mb_strtoupper(trim((string)$curso), 'UTF-8');
            if ($curso !== '') {
                $cursos[] = $curso;
            }
        }
        $cursos = array_values(array_unique($cursos));

        $usaCatalogo = false;
        foreach ($cursos as $curso) {
            if (mb_strlen($curso, 'UTF-8') < 2 || mb_substr($curso, -2, 2, 'UTF-8') !== $escuelaCodigo) {
                $usaCatalogo = true;
                break;
            }
        }

        if ($cursos !== [] && $usaCatalogo) {
            $placeholders = implode(',', array_fill(0, count($cursos), '?'));

            return [
                'sql' => 'UPPER(TRIM(' . $cursoExpr . ')) IN (' . $placeholders . ')',
                'types' => str_repeat('s', count($cursos)),
                'params' => $cursos,
            ];
        }

        return [
            'sql' => 'UPPER(RIGHT(TRIM(' . $cursoExpr . '), 2)) = ?',
            'types' => 's',
            'params' => [$escuelaCodigo],
        ];
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

        return (string)($keys[0] ?? 'JB');
    }
}

if (!function_exists('tenant_curso_alta_nuevos')) {
    function tenant_curso_alta_nuevos(): string
    {
        return strtoupper(trim((string)(tenant_config()['curso_alta_nuevos'] ?? 'NAN')));
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
        return strtoupper(trim((string)(tenant_config()['admin_scope_codigo'] ?? 'NB')));
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

        return $codigo === tenant_admin_scope_codigo() || isset(tenant_escuelas_catalog()[$codigo]);
    }
}

if (!function_exists('tenant_name')) {
    function tenant_name(): string
    {
        return (string)(tenant_config()['name'] ?? 'Nuestra Señora de Itati');
    }
}

if (!function_exists('tenant_name_legal')) {
    function tenant_name_legal(): string
    {
        return (string)(tenant_config()['name_legal'] ?? tenant_name());
    }
}

if (!function_exists('tenant_slug')) {
    function tenant_slug(): string
    {
        return (string)(tenant_config()['slug'] ?? 'nbelen-app');
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
        $file = trim((string)(tenant_branding()['logo_file'] ?? 'LogoNbelen.jpg'));

        return $file !== '' ? $file : 'LogoNbelen.jpg';
    }
}
