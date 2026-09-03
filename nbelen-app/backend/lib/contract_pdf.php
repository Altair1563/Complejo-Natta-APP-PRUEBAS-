<?php

/**
 * Generación y archivo del PDF firmado + huella SHA-256 del archivo depositado.
 */

require_once __DIR__ . '/contract_render.php';

/** @var array{code:string,detail?:string}|null */
$GLOBALS['contrato_pdf_last_error'] = null;

/**
 * Registra el último fallo de generación de PDF (código interno, sin rutas).
 */
function contrato_pdf_set_error(string $code, ?string $detail = null): void
{
    $GLOBALS['contrato_pdf_last_error'] = ['code' => $code, 'detail' => $detail];
}

/**
 * @return array{code:string,detail?:string}|null
 */
function contrato_pdf_get_last_error(): ?array
{
    $err = $GLOBALS['contrato_pdf_last_error'] ?? null;

    return is_array($err) && isset($err['code']) ? $err : null;
}

/**
 * Mensaje legible para el usuario según el código de error.
 */
function contrato_pdf_user_message(?string $code = null): string
{
    if ($code === null) {
        $err = contrato_pdf_get_last_error();
        $code = $err['code'] ?? 'unknown';
    }

    $messages = [
        'dompdf_missing' => 'No se pudo generar el PDF firmado: falta la librería Dompdf. '
            . 'En el servidor, ejecute «composer install» dentro de la carpeta natta-app o suba la carpeta vendor/ completa.',
        'php_extension_missing' => 'No se pudo generar el PDF firmado: el servidor debe tener activas las extensiones PHP «dom» y «mbstring».',
        'template_missing' => 'No se pudo generar el PDF firmado: no se encontró la plantilla HTML del contrato para esta versión. '
            . 'Verifique que exista el archivo en docs/contratos/ y que la versión en la base de datos coincida.',
        'template_empty' => 'No se pudo generar el PDF firmado: la plantilla del contrato está vacía o no se pudo leer.',
        'render_failed' => 'No se pudo generar el PDF firmado: falló la conversión HTML a PDF. '
            . 'Revise el registro de errores del servidor (memoria, tiempo de ejecución o contenido del contrato).',
        'render_empty' => 'No se pudo generar el PDF firmado: Dompdf produjo un archivo vacío.',
        'write_failed' => 'No se pudo guardar el PDF firmado en storage/contratos_firmados. Verifique permisos de escritura en storage/.',
        'hash_failed' => 'No se pudo generar el PDF firmado: error al calcular la huella del documento.',
        'storage_not_writable' => 'No se pudo preparar el almacenamiento de PDFs firmados.',
        'unknown' => 'No se pudo generar el PDF firmado. Contacte al administrador del sistema.',
    ];

    return $messages[$code] ?? $messages['unknown'];
}

/**
 * Carga el autoloader de Composer (Dompdf, etc.) si aún no está disponible.
 */
function contrato_load_composer_autoload(): bool
{
    if (class_exists(\Dompdf\Dompdf::class, false)) {
        return true;
    }

    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    $autoload = $base . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('contrato_load_composer_autoload: no existe ' . $autoload);

        return false;
    }

    require_once $autoload;

    return class_exists(\Dompdf\Dompdf::class);
}

/**
 * Crea la jerarquía storage/ si falta y archivos de protección mínimos.
 */
function contrato_storage_bootstrap_files(string $storageRoot, string $contratosDir): void
{
    $htaccessRoot = $storageRoot . '/.htaccess';
    if (!is_file($htaccessRoot)) {
        file_put_contents($htaccessRoot, "Require all denied\n");
    }

    $htaccessContratos = $contratosDir . '/.htaccess';
    if (!is_file($htaccessContratos)) {
        file_put_contents($htaccessContratos, "Require all denied\n");
    }

    $indexHtml = $contratosDir . '/index.html';
    if (!is_file($indexHtml)) {
        file_put_contents($indexHtml, '');
    }

    $gitkeep = $contratosDir . '/.gitkeep';
    if (!is_file($gitkeep)) {
        touch($gitkeep);
    }
}

/**
 * Prepara storage/contratos_firmados y comprueba que el servidor web pueda escribir.
 *
 * @return string|null Mensaje de error si no es escribible; null si OK.
 */
function contrato_ensure_signed_pdf_storage_writable(): ?string
{
    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    $storageRoot = $base . '/storage';
    $dir = $storageRoot . '/contratos_firmados';

    foreach ([$storageRoot, $dir] as $path) {
        if (!is_dir($path)) {
            if (!@mkdir($path, 0775, true) && !is_dir($path)) {
                return 'No se pudo crear la carpeta «' . $path . '».';
            }
        }
    }

    contrato_storage_bootstrap_files($storageRoot, $dir);

    if (!is_writable($dir)) {
        @chmod($dir, 0775);
        if (!is_dir($storageRoot) || !@chmod($storageRoot, 0775)) {
            // sin permisos en el padre; se intenta igual el hijo
        }
        @chmod($dir, 0775);
    }

    if (!is_writable($dir)) {
        return 'La carpeta storage/contratos_firmados no tiene permiso de escritura para el usuario del servidor web. '
            . 'Asigne permisos 775 (o 777 en hosting compartido) a storage/ y storage/contratos_firmados.';
    }

    $probe = $dir . '/.write_test_' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'ok') === false) {
        return 'No se pudo escribir un archivo de prueba en storage/contratos_firmados.';
    }
    @unlink($probe);

    return null;
}

/**
 * Directorio de almacenamiento de PDFs firmados (fuera de la URL pública directa).
 */
function contrato_signed_pdf_storage_dir(): string
{
    contrato_ensure_signed_pdf_storage_writable();

    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

    return $base . '/storage/contratos_firmados';
}

/**
 * Estilos de impresión/PDF: Dompdf no soporta bien flex ni bloques enormes con break-inside:avoid.
 */
function contrato_pdf_font_dir(): string
{
    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

    return rtrim(str_replace('\\', '/', $base), '/') . '/assets/fonts';
}

/** Márgenes de página PDF (@page): arriba, derecha, abajo, izquierda. */
function contrato_pdf_page_margins(): array
{
    return [
        'top' => '10mm',
        'right' => '20mm',
        'bottom' => '0',
        'left' => '10mm',
    ];
}

function contrato_pdf_page_margins_css(): string
{
    $m = contrato_pdf_page_margins();

    return $m['top'] . ' ' . $m['right'] . ' ' . $m['bottom'] . ' ' . $m['left'];
}

function contrato_pdf_override_css(): string
{
    $fontDir = contrato_pdf_font_dir();
    $pageMargins = contrato_pdf_page_margins_css();

    return <<<CSS
<style id="contrato-pdf-overrides">
@font-face {
    font-family: Calibri;
    font-style: normal;
    font-weight: normal;
    src: url("{$fontDir}/Carlito-Regular.ttf") format("truetype");
}
@font-face {
    font-family: Calibri;
    font-style: normal;
    font-weight: bold;
    src: url("{$fontDir}/Carlito-Bold.ttf") format("truetype");
}
@font-face {
    font-family: Calibri;
    font-style: italic;
    font-weight: normal;
    src: url("{$fontDir}/Carlito-Italic.ttf") format("truetype");
}
@font-face {
    font-family: Calibri;
    font-style: italic;
    font-weight: bold;
    src: url("{$fontDir}/Carlito-BoldItalic.ttf") format("truetype");
}
@page {
    margin: {$pageMargins} !important;
    padding: 0 !important;
}
* {
    box-sizing: border-box !important;
}
html {
    padding: 0 !important;
    width: 100% !important;
    max-width: none !important;
}
body {
    margin: 0 !important;
    padding: 0 !important;
    width: 100% !important;
    max-width: none !important;
}
body {
    background: #fff !important;
    max-width: none !important;
    font-family: Calibri, Carlito, DejaVu Sans, sans-serif !important;
    font-size: 10.5pt !important;
    line-height: 1.35 !important;
}
.contract-page {
    max-width: none !important;
    width: auto !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    background: #fff !important;
}
.contract-doc-logos {
    display: table !important;
    width: 100% !important;
    table-layout: fixed !important;
    margin: 0 0 6pt !important;
    padding: 0 0 5pt 0 !important;
    border-bottom: 1px solid #dee2e6 !important;
    text-align: left !important;
}
.contract-doc-logos__images {
    display: table-cell !important;
    width: 42% !important;
    vertical-align: middle !important;
    text-align: left !important;
    padding: 0 !important;
    white-space: nowrap !important;
}
.contract-doc-logos__info {
    display: table-cell !important;
    width: 58% !important;
    vertical-align: middle !important;
    text-align: left !important;
    margin: 0 !important;
    padding: 0 0 0 6pt !important;
    font-size: 7.5pt !important;
    line-height: 1 !important;
    font-family: Calibri, Carlito, DejaVu Sans, sans-serif !important;
}
.contract-doc-logos__line {
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
}
.contract-doc-logo {
    display: inline-block !important;
    max-height: 64pt !important;
    max-width: 108pt !important;
    width: auto !important;
    height: auto !important;
    margin: 0 10pt 0 0 !important;
    vertical-align: middle !important;
}
.contract-doc-logo--natta { max-width: 118pt !important; max-height: 68pt !important; }
.institution-header {
    margin: 0 0 6pt 0 !important;
    padding: 0 0 4pt 0 !important;
    text-align: center !important;
}
h1 {
    font-size: 13pt !important;
    margin: 2pt 0 !important;
    text-align: center !important;
}
.family-data-block {
    margin: 6pt 0 8pt 0 !important;
    padding: 6pt 0 !important;
    font-size: 9pt !important;
    border-radius: 0 !important;
}
.clause {
    margin: 4pt 0 !important;
    padding: 0 !important;
    font-size: 9.5pt !important;
    orphans: 2;
    widows: 2;
}
.clause-list { margin: 3pt 0 3pt 16pt !important; padding: 0 !important; }
.indent-list {
    list-style-type: upper-roman !important;
    margin: 3pt 0 3pt 18pt !important;
    padding: 0 !important;
}
.indent-list li {
    margin-bottom: 2pt !important;
    list-style-type: inherit !important;
    display: list-item !important;
}
.indent-list li::before { content: none !important; display: none !important; }
.digital-acceptance-section {
    margin: 8pt 0 0 0 !important;
    padding: 6pt 0 0 0 !important;
    font-size: 8.5pt !important;
}
.digital-acceptance-section h3 {
    font-size: 9.5pt !important;
    margin: 6pt 0 3pt 0 !important;
}
.digital-acceptance-section p {
    margin: 0 0 4pt 0 !important;
}
.digital-acceptance-meta {
    margin: 6pt 0 0 0 !important;
    padding: 4pt 0 0 0 !important;
    line-height: 1.35 !important;
    text-align: left !important;
}
p, dl, blockquote, figure, li, .clause, .family-data-block {
    margin-left: 0 !important;
    margin-right: 0 !important;
    overflow-wrap: break-word !important;
    word-wrap: break-word !important;
}
.verification-code {
    font-size: 6.5pt !important;
    word-wrap: break-word !important;
    display: block !important;
    margin-top: 2pt !important;
    line-height: 1.15 !important;
}
</style>
CSS;
}

/**
 * Sustituye URLs remotas de logos por rutas locales (Dompdf las renderiza mejor).
 */
function contrato_html_embed_local_images(string $html): string
{
    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    $assetsDir = rtrim(str_replace('\\', '/', $base), '/') . '/assets/img/';

    return (string)preg_replace_callback(
        '#\bsrc="([^"]*/assets/img/([^"?]+))(?:\?[^"]*)?"#i',
        static function (array $m) use ($assetsDir): string {
            $name = rawurldecode($m[2]);
            if (strpos($name, '..') !== false) {
                return $m[0];
            }
            $local = $assetsDir . $name;
            if (!is_file($local)) {
                return $m[0];
            }
            $path = str_replace('\\', '/', realpath($local) ?: $local);

            return 'src="' . $path . '"';
        },
        $html
    );
}

/**
 * Elimina propiedades CSS que provocan fallos en Dompdf (p. ej. flex → Page::add_line).
 */
function contrato_html_strip_dompdf_unsafe_css(string $css): string
{
    $css = preg_replace('/\bdisplay\s*:\s*flex\b/i', 'display: block', $css);
    $css = preg_replace('/\bdisplay\s*:\s*inline-flex\b/i', 'display: inline-block', $css);
    $patterns = [
        '/\bflex-direction\s*:[^;}\n]+;?/i',
        '/\bflex-wrap\s*:[^;}\n]+;?/i',
        '/\bflex(?:-grow|-shrink|-basis)?\s*:[^;}\n]+;?/i',
        '/\balign-items\s*:[^;}\n]+;?/i',
        '/\balign-self\s*:[^;}\n]+;?/i',
        '/\bjustify-content\s*:[^;}\n]+;?/i',
        '/\bgap\s*:[^;}\n]+;?/i',
        '/\bobject-fit\s*:[^;}\n]+;?/i',
        '/\bbreak-inside\s*:[^;}\n]+;?/i',
        '/\bpage-break-inside\s*:[^;}\n]+;?/i',
        '/\bcounter-reset\s*:[^;}\n]+;?/i',
        '/\bcounter-increment\s*:[^;}\n]+;?/i',
    ];
    foreach ($patterns as $pattern) {
        $css = preg_replace($pattern, '', $css);
    }
    $css = preg_replace('/[^{}]+::before\s*\{[^}]*content\s*:\s*counter[^}]*\}/i', '', $css);

    return $css;
}

/**
 * Elimina bloques @media con llaves anidadas (el regex simple dejaba CSS inválido).
 */
function contrato_html_remove_at_media_block(string $html, string $media): string
{
    $pattern = '/@media\s+' . preg_quote($media, '/') . '\s*\{/i';
    while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE)) {
        $start = (int)$m[0][1];
        $pos = $start + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($html);
        while ($pos < $len && $depth > 0) {
            $ch = $html[$pos];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
            }
            $pos++;
        }
        $html = substr($html, 0, $start) . substr($html, $pos);
    }

    return $html;
}

/**
 * HTML listo para conversión a PDF (sin barra de herramientas de pantalla).
 */
function contrato_html_para_pdf(string $html): string
{
    $html = preg_replace_callback(
        '/<style([^>]*)>([\s\S]*?)<\/style>/i',
        static function (array $m): string {
            $attrs = $m[1];
            $css = contrato_html_strip_dompdf_unsafe_css($m[2]);

            return '<style' . $attrs . '>' . $css . '</style>';
        },
        $html
    ) ?? $html;

    $html = preg_replace_callback(
        '/\sstyle="([^"]*)"/i',
        static function (array $m): string {
            return ' style="' . contrato_html_strip_dompdf_unsafe_css($m[1]) . '"';
        },
        $html
    ) ?? $html;

    $html = preg_replace('/<div class="contract-toolbar[^>]*>.*?<\/div>\s*/is', '', $html) ?? $html;
    $html = contrato_html_remove_at_media_block($html, 'print');
    // La plantilla trae CSS de pantalla (flex, counters, etc.) incompatible con Dompdf; el PDF usa solo overrides.
    $html = preg_replace('/<style(?![^>]*id=["\']contrato-pdf-overrides)[^>]*>[\s\S]*?<\/style>/i', '', $html) ?? $html;
    $html = contrato_html_embed_local_images($html);
    $html = preg_replace('/<body([^>]*)>/i', '<body$1 style="margin:0!important;padding:0!important;">', $html, 1) ?? $html;
    $html = preg_replace('/<div class="contract-page"([^>]*)>/i', '<div class="contract-page"$1 style="margin:0!important;padding:0!important;">', $html, 1) ?? $html;

    if (stripos($html, '</head>') !== false) {
        $html = str_ireplace('</head>', contrato_pdf_override_css() . '</head>', $html);
    } else {
        $html = contrato_pdf_override_css() . $html;
    }

    return $html;
}

/**
 * Aplica márgenes @page en Dompdf (sustituye el default de 1.2 cm y evita padding en contenedor 100%).
 */
function contrato_pdf_apply_page_margins(\Dompdf\Dompdf $dompdf): void
{
    $pageStyles = $dompdf->getCss()->get_page_styles();
    if (!isset($pageStyles['base'])) {
        return;
    }

    $m = contrato_pdf_page_margins();
    $base = $pageStyles['base'];
    $map = [
        'margin_top' => $m['top'],
        'margin_right' => $m['right'],
        'margin_bottom' => $m['bottom'],
        'margin_left' => $m['left'],
    ];
    foreach ($map as $side => $value) {
        $base->set_prop($side, $value, true);
    }
}

/**
 * Renderiza HTML→PDF con márgenes @page controlados (tras parsear CSS, antes de apply_styles).
 */
function contrato_dompdf_render(\Dompdf\Dompdf $dompdf): void
{
    $ref = new ReflectionClass($dompdf);

    $setPhpConfig = $ref->getMethod('setPhpConfig');
    $setPhpConfig->setAccessible(true);
    $setPhpConfig->invoke($dompdf);

    $processHtml = $ref->getMethod('processHtml');
    $processHtml->setAccessible(true);
    $processHtml->invoke($dompdf);

    $dompdf->getCss()->apply_styles($dompdf->getTree());

    // Tras apply_styles, las reglas html{margin:0} pisan @page en el frame raíz; restaurar márgenes.
    contrato_pdf_apply_page_margins($dompdf);

    $pageStyles = $dompdf->getCss()->get_page_styles();
    $basePageStyle = $pageStyles['base'];
    unset($pageStyles['base']);

    foreach ($pageStyles as $pageStyle) {
        $pageStyle->inherit($basePageStyle);
    }

    if (is_array($basePageStyle->size)) {
        [$width, $height] = $basePageStyle->size;
        $dompdf->setPaper([0, 0, $width, $height]);
    }

    $canvas = $dompdf->getCanvas();
    $size = $dompdf->getPaperSize();
    if ($canvas->get_width() !== $size[2] || $canvas->get_height() !== $size[3]) {
        $dompdf->setCanvas(\Dompdf\CanvasFactory::get_instance(
            $dompdf,
            $dompdf->getPaperSize(),
            $dompdf->getPaperOrientation()
        ));
        $dompdf->getFontMetrics()->setCanvas($dompdf->getCanvas());
        $canvas = $dompdf->getCanvas();
    }

    $rootFrame = $dompdf->getTree()->get_root();
    $root = \Dompdf\Frame\Factory::decorate_root($rootFrame, $dompdf);
    foreach ($dompdf->getTree() as $frame) {
        if ($frame === $rootFrame) {
            continue;
        }
        \Dompdf\Frame\Factory::decorate_frame($frame, $dompdf, $root);
    }

    $title = $dompdf->getDom()->getElementsByTagName('title');
    if ($title->length) {
        $canvas->add_info('Title', trim($title->item(0)->nodeValue));
    }

    $root->set_containing_block(0, 0, $canvas->get_width(), $canvas->get_height());
    $root->set_renderer(new \Dompdf\Renderer($dompdf));
    $root->reflow();

    $callbacks = $dompdf->getCallbacks();
    if (isset($callbacks['end_document'])) {
        foreach ($callbacks['end_document'] as $f) {
            $canvas->page_script($f);
        }
    }

    if (!$dompdf->getOptions()->getDebugKeepTemp()) {
        \Dompdf\Image\Cache::clear($dompdf->getOptions()->getDebugPng());
    }

    $restorePhpConfig = $ref->getMethod('restorePhpConfig');
    $restorePhpConfig->setAccessible(true);
    $restorePhpConfig->invoke($dompdf);
}

/**
 * @return bool
 */
function contrato_html_to_pdf_file(string $html, string $absolutePath): bool
{
    if (!extension_loaded('dom') || !extension_loaded('mbstring')) {
        $missing = [];
        if (!extension_loaded('dom')) {
            $missing[] = 'dom';
        }
        if (!extension_loaded('mbstring')) {
            $missing[] = 'mbstring';
        }
        contrato_pdf_set_error('php_extension_missing', implode(',', $missing));
        error_log('contrato_html_to_pdf_file: extensiones PHP faltantes: ' . implode(', ', $missing));

        return false;
    }

    if (!contrato_load_composer_autoload()) {
        contrato_pdf_set_error('dompdf_missing');
        error_log('contrato_html_to_pdf_file: Dompdf no disponible. Ejecute «composer install» en la raíz del proyecto (carpeta vendor/).');

        return false;
    }

    try {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $chroot = realpath($base) ?: $base;

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Calibri');
        $options->set('chroot', $chroot);
        $options->set('defaultMediaType', 'print');
        $options->set('isFontSubsettingEnabled', true);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        contrato_dompdf_render($dompdf);

        $bytes = $dompdf->output();
        if ($bytes === '' || $bytes === null) {
            contrato_pdf_set_error('render_empty');
            error_log('contrato_html_to_pdf_file: salida PDF vacía');

            return false;
        }

        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($absolutePath, $bytes) === false) {
            contrato_pdf_set_error('write_failed');
            error_log('contrato_html_to_pdf_file: no se pudo escribir ' . $absolutePath);

            return false;
        }

        return true;
    } catch (Throwable $e) {
        contrato_pdf_set_error('render_failed', $e->getMessage());
        error_log('contrato_html_to_pdf_file: ' . $e->getMessage());

        return false;
    }
}

/**
 * Nombre de archivo seguro para el PDF firmado.
 */
function contrato_signed_pdf_filename(string $contractVersion, string $studentDni, string $acceptedAtUtc): string
{
    $version = preg_replace('/[^A-Za-z0-9_\-]/', '_', $contractVersion);
    $dni = preg_replace('/\D+/', '', $studentDni);
    $ts = preg_replace('/[^0-9]/', '', $acceptedAtUtc);
    if ($ts === '') {
        $ts = date('YmdHis');
    }

    return ($version !== '' ? $version : 'Contrato')
        . '_' . ($dni !== '' ? $dni : 'alumno')
        . '_' . $ts
        . '.pdf';
}

/**
 * Genera el PDF integral del contrato aceptado y calcula SHA-256 sobre el archivo depositado.
 *
 * @return array{pdf_absolute:string,pdf_relative:string,sha256:string,sha256_initial:string,html_snapshot:string}|null
 */
function contrato_archive_signed_document(string $contractVersion, array $docData): ?array
{
    contrato_pdf_set_error('unknown');

    $storageError = contrato_ensure_signed_pdf_storage_writable();
    if ($storageError !== null) {
        contrato_pdf_set_error('storage_not_writable', $storageError);
        error_log('contrato_archive_signed_document: ' . $storageError);

        return null;
    }

    $acceptedAtUtc = (string)($docData['accepted_at_utc'] ?? date('Y-m-d H:i:s'));
    $studentDni = (string)($docData['alumno_dni'] ?? '');
    $filename = contrato_signed_pdf_filename($contractVersion, $studentDni, $acceptedAtUtc);
    $subdir = date('Y', strtotime($acceptedAtUtc) ?: time());
    $relative = $subdir . '/' . $filename;
    $absolute = contrato_signed_pdf_storage_dir() . '/' . $relative;

    $sha256 = '';
    $sha256Initial = '';
    $htmlSnapshot = '';
    for ($pass = 0; $pass < 2; $pass++) {
        $passData = $docData;
        $passData['codigo_verificacion'] = $sha256 !== '' ? $sha256 : '';

        $html = contrato_render_html_template($contractVersion, $passData);
        if ($html === null) {
            $templatePath = contrato_template_path($contractVersion);
            contrato_pdf_set_error($templatePath === null ? 'template_missing' : 'template_empty');
            error_log('contrato_archive_signed_document: plantilla no disponible para «' . $contractVersion . '»');

            return null;
        }
        if ($html === '') {
            contrato_pdf_set_error('template_empty');
            error_log('contrato_archive_signed_document: plantilla vacía para «' . $contractVersion . '»');

            return null;
        }

        $html = contrato_html_para_pdf($html);
        if (!contrato_html_to_pdf_file($html, $absolute)) {
            if (contrato_pdf_get_last_error() === null) {
                contrato_pdf_set_error('render_failed');
            }

            return null;
        }

        $newHash = hash_file('sha256', $absolute);
        if ($newHash === false) {
            contrato_pdf_set_error('hash_failed');
            @unlink($absolute);

            return null;
        }

        if ($sha256Initial === '') {
            $sha256Initial = $newHash;
        }

        if ($newHash === $sha256) {
            $htmlSnapshot = $html;
            break;
        }

        $sha256 = $newHash;
        $htmlSnapshot = $html;
    }

    if ($sha256 === '') {
        contrato_pdf_set_error('render_failed');
        error_log('contrato_archive_signed_document: no se obtuvo huella SHA-256');

        return null;
    }

    $GLOBALS['contrato_pdf_last_error'] = null;

    return [
        'pdf_absolute' => $absolute,
        'pdf_relative' => $relative,
        'sha256' => $sha256,
        'sha256_initial' => $sha256Initial !== '' ? $sha256Initial : $sha256,
        'html_snapshot' => $htmlSnapshot,
    ];
}

/**
 * Ruta absoluta del PDF firmado almacenado, si existe y es legible.
 */
function contrato_resolve_signed_pdf_absolute(?string $acceptedPdfPath): ?string
{
    $rel = trim((string)$acceptedPdfPath);
    if ($rel === '' || strpos($rel, '..') !== false) {
        return null;
    }

    $base = realpath(contrato_signed_pdf_storage_dir());
    if ($base === false) {
        return null;
    }

    $full = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    $real = realpath($full);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        return null;
    }

    if (strpos($real, $base) !== 0) {
        return null;
    }

    return $real;
}

/**
 * URL de descarga del PDF firmado (requiere sesión).
 */
function contrato_signed_pdf_download_url(string $studentDni): string
{
    unset($studentDni);

    return contrato_public_app_root_url() . '/contrato_documento_firmado.php';
}
