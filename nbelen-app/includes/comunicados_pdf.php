<?php
/**
 * Almacenamiento y descarga de PDFs adjuntos a comunicados generales.
 */

if (!defined('NATTA_ROOT')) {
    define('NATTA_ROOT', dirname(__DIR__));
}

define('COMUNICADO_PDF_MAX_BYTES', 15 * 1024 * 1024);

function comunicado_pdf_storage_dir(): string
{
    return NATTA_ROOT . '/storage/comunicados_pdfs';
}

function comunicado_pdf_relative_path(int $id): string
{
    return 'comunicados_pdfs/comunicado_' . $id . '.pdf';
}

function comunicado_pdf_absolute_path(int $id): ?string
{
    $path = comunicado_pdf_storage_dir() . '/comunicado_' . $id . '.pdf';
    return is_file($path) ? $path : null;
}

function comunicado_ensure_pdf_storage(): bool
{
    $dir = comunicado_pdf_storage_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }
    return is_dir($dir) && is_writable($dir);
}

/**
 * @param array{name?:string, type?:string, tmp_name?:string, error?:int, size?:int} $file
 * @return array{ok: bool, saved: bool, msg: string, path: string}
 */
function comunicado_save_pdf_upload(array $file, int $comunicadoId): array
{
    $empty = ['ok' => true, 'saved' => false, 'msg' => '', 'path' => ''];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $empty;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'saved' => false, 'msg' => 'Error al subir el PDF (código ' . (int)($file['error'] ?? 0) . ').', 'path' => ''];
    }
    if (($file['size'] ?? 0) > COMUNICADO_PDF_MAX_BYTES) {
        return ['ok' => false, 'saved' => false, 'msg' => 'El PDF es demasiado grande (máx. 15 MB).', 'path' => ''];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'saved' => false, 'msg' => 'Subida de PDF no válida.', 'path' => ''];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return ['ok' => false, 'saved' => false, 'msg' => 'Solo se permiten archivos PDF.', 'path' => ''];
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $allowed = ['application/pdf', 'application/x-pdf'];
        if ($mime !== '' && !in_array($mime, $allowed, true)) {
            return ['ok' => false, 'saved' => false, 'msg' => 'El archivo no es un PDF válido.', 'path' => ''];
        }
    }

    if (!comunicado_ensure_pdf_storage()) {
        return ['ok' => false, 'saved' => false, 'msg' => 'No se pudo preparar el directorio de almacenamiento.', 'path' => ''];
    }

    $dest = comunicado_pdf_storage_dir() . '/comunicado_' . $comunicadoId . '.pdf';
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'saved' => false, 'msg' => 'No se pudo guardar el PDF.', 'path' => ''];
    }

    return [
        'ok' => true,
        'saved' => true,
        'msg' => basename($dest),
        'path' => comunicado_pdf_relative_path($comunicadoId),
    ];
}

function comunicado_delete_pdf(int $comunicadoId): void
{
    $path = comunicado_pdf_absolute_path($comunicadoId);
    if ($path !== null) {
        @unlink($path);
    }
}

function comunicado_has_pdf(array $comunicado): bool
{
    if (empty($comunicado['archivo_pdf'])) {
        return false;
    }
    $id = (int)($comunicado['id'] ?? 0);
    return $id > 0 && comunicado_pdf_absolute_path($id) !== null;
}
