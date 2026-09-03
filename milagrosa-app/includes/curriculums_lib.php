<?php
/**
 * Áreas de postulación — bolsa de trabajo / currículums.
 */

if (!defined('NATTA_ROOT')) {
    define('NATTA_ROOT', dirname(__DIR__));
}

/**
 * @return array<string, string> slug => etiqueta visible
 */
function curriculum_areas_catalog(): array
{
    return [
        'jardin_inicial' => 'Jardín/Inicial',
        'primaria'       => 'Primaria',
        'secundario'     => 'Secundario',
        'terciario'      => 'Terciario',
        'ingles'         => 'Inglés',
        'informatica'    => 'Informática',
        'otros'          => 'Otros',
    ];
}

function curriculum_validar_area(string $area): ?string
{
    $area = strtolower(trim($area));
    $catalog = curriculum_areas_catalog();

    return isset($catalog[$area]) ? $area : null;
}

/**
 * Áreas docentes que requieren declarar requisitos para suplencias.
 *
 * @return list<string>
 */
function curriculum_areas_con_requisitos_suplencias(): array
{
    return [
        'jardin_inicial',
        'primaria',
        'secundario',
        'ingles',
        'informatica',
    ];
}

function curriculum_area_requiere_suplencias(string $area): bool
{
    return in_array($area, curriculum_areas_con_requisitos_suplencias(), true);
}

function curriculum_validar_cuil(string $cuil): bool
{
    $cuil = trim($cuil);
    if ($cuil === '' || strlen($cuil) > 15) {
        return false;
    }

    $digits = preg_replace('/\D+/', '', $cuil) ?? '';

    return strlen($digits) === 11;
}

function curriculum_normalizar_cuil(string $cuil): string
{
    $digits = preg_replace('/\D+/', '', trim($cuil)) ?? '';
    if (strlen($digits) !== 11) {
        return trim($cuil);
    }

    return substr($digits, 0, 2) . '-' . substr($digits, 2, 8) . '-' . substr($digits, 10, 1);
}

/**
 * @param array<string, mixed> $post
 * @return array{titulo_consejo: bool, certificado_aptitud: bool, disponibilidad_horaria: bool}|null
 */
function curriculum_validar_requisitos_suplencias(string $area, array $post): ?array
{
    if (!curriculum_area_requiere_suplencias($area)) {
        return null;
    }

    $titulo = !empty($post['requisito_titulo_consejo']);
    $certificado = !empty($post['requisito_certificado_aptitud']);
    $disponibilidad = !empty($post['requisito_disponibilidad_horaria']);

    if (!$titulo || !$certificado) {
        return null;
    }

    return [
        'titulo_consejo'        => $titulo,
        'certificado_aptitud'   => $certificado,
        'disponibilidad_horaria'=> $disponibilidad,
    ];
}

/**
 * @param array<string, mixed> $post
 */
function curriculum_duplencias_desde_post(array $post): string
{
    return !empty($post['requisito_disponibilidad_horaria']) ? 'SI' : 'NO';
}

function curriculum_normalizar_duplencias(?string $valor): string
{
    return strtoupper(trim((string)$valor)) === 'SI' ? 'SI' : 'NO';
}

function curriculum_area_etiqueta(string $area): string
{
    $catalog = curriculum_areas_catalog();

    return $catalog[$area] ?? 'Otros';
}

/**
 * @return list<string>
 */
function curriculum_upload_dirs(): array
{
    static $dirs = null;
    if ($dirs !== null) {
        return $dirs;
    }

    $candidates = [
        dirname(NATTA_ROOT) . DIRECTORY_SEPARATOR . 'uploads',
        NATTA_ROOT . DIRECTORY_SEPARATOR . 'uploads',
    ];

    $dirs = [];
    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real !== false && is_dir($real) && !in_array($real, $dirs, true)) {
            $dirs[] = $real;
        }
    }

    if ($dirs === []) {
        $dirs[] = dirname(NATTA_ROOT) . DIRECTORY_SEPARATOR . 'uploads';
    }

    return $dirs;
}

function curriculum_public_uploads_dir(): string
{
    $dirs = curriculum_upload_dirs();

    return $dirs[0];
}

function curriculum_db_column_exists(mysqli $conn, string $column): bool
{
    static $cache = [];

    if (isset($cache[$column])) {
        return $cache[$column];
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    $result = $conn->query("SHOW COLUMNS FROM curriculums LIKE '" . $conn->real_escape_string($column) . "'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    if ($exists) {
        $cache[$column] = true;
    }

    return $exists;
}

function curriculum_ensure_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!curriculum_db_column_exists($conn, 'area')) {
        try {
            $conn->query(
                "ALTER TABLE curriculums
                 ADD COLUMN area VARCHAR(30) NOT NULL DEFAULT 'otros' AFTER telefono,
                 ADD INDEX idx_curriculums_area (area)"
            );
        } catch (mysqli_sql_exception $e) {
            error_log('curriculum schema area: ' . $e->getMessage());
        }
    }

    if (!curriculum_db_column_exists($conn, 'cuil')) {
        try {
            $conn->query(
                "ALTER TABLE curriculums
                 ADD COLUMN cuil VARCHAR(15) NULL DEFAULT NULL AFTER telefono"
            );
        } catch (mysqli_sql_exception $e) {
            error_log('curriculum schema cuil: ' . $e->getMessage());
        }
    }

    if (!curriculum_db_column_exists($conn, 'requisitos_suplencias')) {
        try {
            $conn->query(
                "ALTER TABLE curriculums
                 ADD COLUMN requisitos_suplencias TEXT NULL DEFAULT NULL AFTER area"
            );
        } catch (mysqli_sql_exception $e) {
            error_log('curriculum schema requisitos_suplencias: ' . $e->getMessage());
        }
    }

    if (!curriculum_db_column_exists($conn, 'duplencias')) {
        try {
            $conn->query(
                "ALTER TABLE curriculums
                 ADD COLUMN duplencias VARCHAR(2) NOT NULL DEFAULT 'NO' COMMENT 'SI|NO disponibilidad horaria' AFTER fecha_subida"
            );
        } catch (mysqli_sql_exception $e) {
            error_log('curriculum schema duplencias: ' . $e->getMessage());
        }
    }

    $done = true;
}

function curriculum_resolve_archivo_path(string $archivo): ?string
{
    $archivo = trim($archivo);
    if ($archivo === '' || str_contains($archivo, '..')) {
        return null;
    }

    $archivoNorm = str_replace('\\', '/', $archivo);
    $relative = preg_replace('#^uploads/#i', '', $archivoNorm);
    $basename = basename($relative);
    if ($basename === '' || $basename === '.' || $basename === '..') {
        return null;
    }

    foreach (curriculum_upload_dirs() as $uploadsDir) {
        $uploadsReal = realpath($uploadsDir);
        if ($uploadsReal === false || !is_dir($uploadsReal)) {
            continue;
        }

        $candidate = $uploadsReal . DIRECTORY_SEPARATOR . $basename;
        if (!is_file($candidate)) {
            continue;
        }

        $real = realpath($candidate);
        if ($real === false || !str_starts_with($real, $uploadsReal . DIRECTORY_SEPARATOR)) {
            continue;
        }

        return $real;
    }

    return null;
}

function curriculum_sanitize_filename(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'cv';
    $name = trim($name, '._-');

    return $name !== '' ? $name : 'cv';
}
