<?php
// ================== FUNCIONES PARA CONFIGURACIÓN (PDO) ==================
function getConfigPDO($pdo, $clave, $default = null) {
    $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = ?");
    $stmt->execute([$clave]);
    $row = $stmt->fetch();
    return $row ? $row['valor'] : $default;
}

function setConfigPDO($pdo, $clave, $valor, $descripcion = '') {
    $stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE valor = VALUES(valor), descripcion = VALUES(descripcion)");
    return $stmt->execute([$clave, $valor, $descripcion]);
}

// ================== FUNCIÓN PARA NOMBRE DE MES ==================
function nombreMesCuota($n) {
    $map = [
        1  => 'MARZO',
        2  => 'ABRIL',
        3  => 'MAYO',
        4  => 'JUNIO',
        5  => 'JULIO',
        6  => 'AGOSTO',
        7  => 'SEPTIEMBRE',
        8  => 'OCTUBRE',
        9  => 'NOVIEMBRE',
        10 => 'ADELANTO RV',
        11 => 'RESTO RV',
        12 => 'RV COMPLETA'
    ];
    return isset($map[$n]) ? $map[$n] : '';
}

function normalizePlainText($value, $maxLen = 0, $preserveNewLines = false) {
    $text = is_string($value) ? $value : '';
    $text = strip_tags($text);
    if ($preserveNewLines) {
        $text = preg_replace("/\r\n?/", "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
    } else {
        $text = preg_replace("/[\r\n\t]+/", ' ', $text);
    }
    $text = trim($text);
    if ($maxLen > 0 && function_exists('mb_substr')) {
        $text = mb_substr($text, 0, $maxLen);
    }
    return $text;
}

define('ADMIN_IMPORT_MAX_BYTES', 35 * 1024 * 1024);

function adminAllowedMimeByExtension(string $ext): array {
    $map = [
        'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream', 'application/CDFV2', 'application/cdfv2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];
    return $map[$ext] ?? [];
}

function adminImportFormatFileInfo(string $path): string {
    if (!is_file($path) || !is_readable($path)) {
        return '<span class="text-muted">Sin archivo en servidor</span>';
    }
    $mtime = filemtime($path);
    $size = filesize($path);
    $kb = $size !== false ? round($size / 1024, 1) : 0;
    $fecha = $mtime ? date('d/m/Y H:i', $mtime) : '—';
    return htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') . ' · ' . $kb . ' KB';
}

/**
 * @param array{name?:string, type?:string, tmp_name?:string, error?:int, size?:int} $f
 * @return array{ok: bool, saved: bool, msg: string}
 */
function adminImportSaveUploaded(array $f, string $destFullPath, array $allowedExt): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'saved' => false, 'msg' => ''];
    }
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'saved' => false, 'msg' => 'Error al subir (código ' . (int)($f['error'] ?? 0) . ').'];
    }
    if (($f['size'] ?? 0) > ADMIN_IMPORT_MAX_BYTES) {
        return ['ok' => false, 'saved' => false, 'msg' => 'Archivo demasiado grande (máx. 35 MB).'];
    }
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        return ['ok' => false, 'saved' => false, 'msg' => 'Subida no válida.'];
    }
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'saved' => false, 'msg' => 'Extensión no permitida.'];
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $f['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $allowedMime = adminAllowedMimeByExtension($ext);
        if ($mime !== '' && !empty($allowedMime) && !in_array($mime, $allowedMime, true)) {
            return ['ok' => false, 'saved' => false, 'msg' => 'Tipo MIME no permitido (' . $mime . ').'];
        }
    }
    $destDir = dirname($destFullPath);
    if (!is_dir($destDir)) {
        return ['ok' => false, 'saved' => false, 'msg' => 'Directorio destino inexistente.'];
    }
    if (!is_writable($destDir)) {
        return ['ok' => false, 'saved' => false, 'msg' => 'Sin permiso de escritura en el directorio.'];
    }
    if (!@move_uploaded_file($f['tmp_name'], $destFullPath)) {
        return ['ok' => false, 'saved' => false, 'msg' => 'No se pudo guardar el archivo.'];
    }
    return ['ok' => true, 'saved' => true, 'msg' => basename($destFullPath)];
}

/**
 * @return array{ok: bool, saved: bool, msg: string}
 */
function adminImportSaveUpload(string $field, string $destFullPath, array $allowedExt): array {
    if (!isset($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'saved' => false, 'msg' => ''];
    }
    return adminImportSaveUploaded($_FILES[$field], $destFullPath, $allowedExt);
}

/** Nombres base esperados en importador-excel/ (rptfacing … rptfacing5) */
$admin_rpt_facing_bases = ['rptfacing', 'rptfacing1', 'rptfacing2', 'rptfacing3', 'rptfacing4', 'rptfacing5'];

/**
 * Obtiene el slot rptfacing / rptfacing1 … rptfacing5 a partir del nombre del archivo subido.
 */
function adminResolveRptFacingBase(string $clientFilename): ?string {
    $base = basename($clientFilename);
    if (preg_match('/^rptfacing\.(xls|xlsx)$/i', $base)) {
        return 'rptfacing';
    }
    if (preg_match('/^rptfacing([1-5])\.(xls|xlsx)$/i', $base, $m)) {
        return 'rptfacing' . $m[1];
    }
    return null;
}

/**
 * Fecha/hora de la última subida de Excel rptfacing (mtime más reciente en config/imports).
 */
function admin_ultima_actualizacion_rptfacing(?string $importsDir = null): string
{
    global $admin_rpt_facing_bases, $pdo, $conn;

    try {
        $valor = null;
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'ultima_actualizacion_cuotas' LIMIT 1");
            $stmt->execute();
            $valor = $stmt->fetchColumn();
        } elseif (isset($conn) && $conn instanceof mysqli) {
            $stmt = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'ultima_actualizacion_cuotas' LIMIT 1");
            if ($stmt) {
                $stmt->execute();
                $stmt->bind_result($valor);
                $stmt->fetch();
                $stmt->close();
            }
        }
        if (is_string($valor) && ($ts = strtotime($valor)) !== false) {
            return date('d/m/Y H:i', $ts);
        }
    } catch (Throwable $e) {
        error_log('admin_ultima_actualizacion_rptfacing: ' . $e->getMessage());
    }

    if ($importsDir === null) {
        $importsDir = (defined('NATTA_ROOT') ? NATTA_ROOT : dirname(__DIR__, 2)) . '/config/imports';
    }
    $importsDir = rtrim($importsDir, '/\\') . DIRECTORY_SEPARATOR;

    $maxMtime = 0;
    foreach ($admin_rpt_facing_bases as $base) {
        foreach (['xls', 'xlsx'] as $ext) {
            $full = $importsDir . $base . '.' . $ext;
            if (!is_file($full)) {
                continue;
            }
            $ts = @filemtime($full);
            if ($ts !== false && $ts > $maxMtime) {
                $maxMtime = $ts;
            }
        }
    }

    if ($maxMtime <= 0) {
        return 'Sin archivo rptfacing en servidor';
    }

    return date('d/m/Y H:i', $maxMtime);
}
