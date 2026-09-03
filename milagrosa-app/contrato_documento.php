<?php
/**
 * Vista del contrato con datos del responsable/alumno (lectura previa a la firma o copia firmada).
 * Permite descargar/imprimir en PDF desde el navegador.
 */
require_once __DIR__ . '/backend/bootstrap.php';
require_once __DIR__ . '/backend/lib/contract_render.php';
require_once __DIR__ . '/backend/lib/contract_pdf.php';
require_once __DIR__ . '/backend/lib/contract_preview_session.php';

// Evita que un error SQL puntual tumbe toda la página (db.php activa MYSQLI_REPORT_STRICT).
mysqli_report(MYSQLI_REPORT_OFF);

requireAuth();

$preview = contrato_preview_session_read();
$studentDni = $preview !== null
    ? trim((string)($preview['student_dni'] ?? ''))
    : trim((string)($_GET['student_dni'] ?? ''));

if ($studentDni === '') {
    http_response_code(400);
    echo 'Falta identificar al alumno. Abrí el contrato desde la app familiar.';
    exit;
}

try {
    $conn = getDbConnection();

    $nroFamilia = (string)($_SESSION['nro_familia'] ?? '');
    $email = (string)($_SESSION['email'] ?? '');

    if ($preview !== null) {
        $opts = [
            'declarant_name' => trim((string)($preview['declarant_name'] ?? '')),
            'declarant_dni' => preg_replace('/\D+/', '', (string)($preview['declarant_dni'] ?? '')),
            'declarant_domicilio' => trim((string)($preview['declarant_domicilio'] ?? '')),
            'declarant_localidad' => trim((string)($preview['declarant_localidad'] ?? '')),
            'email_responsable' => $email,
            'modo' => trim((string)($preview['modo'] ?? '')),
        ];
        $autoPdf = !empty($preview['auto_pdf']);
    } else {
        $opts = [
            'declarant_name' => '',
            'declarant_dni' => '',
            'declarant_domicilio' => '',
            'declarant_localidad' => '',
            'email_responsable' => $email,
            'modo' => trim((string)($_GET['modo'] ?? '')),
        ];
        $autoPdf = isset($_GET['pdf']) && (string)$_GET['pdf'] === '1';
    }

    $docData = contrato_build_document_data($conn, $nroFamilia, $studentDni, $opts);
    $conn->close();

    if ($docData === null) {
        http_response_code(404);
        echo 'No se encontró el contrato para este alumno.';
        exit;
    }

    $contractVersion = (string)$docData['contract_version'];
    $html = contrato_render_html_template($contractVersion, $docData);

    if ($html === null) {
        http_response_code(404);
        echo 'Plantilla de contrato no disponible para la versión «' . htmlspecialchars($contractVersion, ENT_QUOTES, 'UTF-8') . '».';
        exit;
    }

    $modo = (string)($opts['modo'] ?? '');
    $signedPdfUrl = '';
    if ($modo === 'firmado') {
        contrato_preview_session_store($studentDni, ['modo' => 'firmado']);
        $pdfAbsolute = contrato_resolve_signed_pdf_absolute((string)($docData['accepted_pdf_path'] ?? ''));
        if ($pdfAbsolute !== null) {
            $signedPdfUrl = contrato_preview_signed_pdf_url();
            if ($autoPdf) {
                header('Location: ' . $signedPdfUrl);
                exit;
            }
        }
    }

    $pdfActionHtml = $signedPdfUrl !== ''
        ? '<a class="contract-toolbar__btn" href="' . htmlspecialchars($signedPdfUrl, ENT_QUOTES, 'UTF-8')
            . '" target="_blank" rel="noopener">Descargar PDF firmado</a>'
        : '<button type="button" class="contract-toolbar__btn" id="btnContratoImprimir">Descargar PDF / Imprimir</button>';

    $toolbar = '<div class="contract-toolbar no-print" role="toolbar" aria-label="Acciones del contrato">'
        . '<a class="contract-toolbar__back" href="contratos.php">&larr; Volver a Contratos</a>'
        . '<div class="contract-toolbar__actions">'
        . $pdfActionHtml
        . '</div></div>';

    $toolbarCss = '<style>
.contract-toolbar{position:sticky;top:0;z-index:50;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:12px 20px;background:#0c3484;color:#fff;font-family:Segoe UI,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.15);}
.contract-toolbar__back{color:#fff;text-decoration:none;font-weight:600;}
.contract-toolbar__back:hover{text-decoration:underline;}
.contract-toolbar__btn{padding:10px 18px;border:none;border-radius:8px;background:#fff;color:#0c3484;font-weight:700;cursor:pointer;}
.contract-toolbar__btn:hover{background:#e8f0fe;}
a.contract-toolbar__btn{display:inline-block;text-decoration:none;text-align:center;}
@media (max-width:425px){
body{padding:12px 8px!important;}
.contract-page{padding:1.1rem 0.9rem!important;box-shadow:0 8px 18px rgba(0,0,0,.12);}
.contract-doc-logos{flex-direction:column;align-items:center;text-align:center;gap:14px;}
.contract-doc-logos__images{justify-content:center;flex-wrap:wrap;gap:14px;}
.contract-doc-logos__info{text-align:center;font-size:.8rem;}
.contract-doc-logo{max-height:72px;max-width:120px;}
.contract-doc-logo--natta{max-width:130px;}
h1{font-size:1.35rem;}
.family-data-block{padding:.85rem 1rem;font-size:.92rem;}
.clause{font-size:.95rem;}
.contract-toolbar{padding:10px 12px;flex-direction:column;align-items:stretch;gap:8px;}
.contract-toolbar__back{text-align:center;padding:4px 0;}
.contract-toolbar__actions{width:100%;}
.contract-toolbar__btn{width:100%;font-size:14px;padding:10px 12px;}
}
@media (max-width:370px){
body{padding:8px 6px!important;}
.contract-page{padding:.9rem .7rem!important;}
.contract-doc-logo{max-height:60px;max-width:100px;}
.contract-doc-logo--natta{max-width:110px;}
h1{font-size:1.15rem;}
.family-data-block{padding:.75rem .85rem;font-size:.88rem;}
.contract-toolbar{padding:8px 10px;}
.contract-toolbar__btn{font-size:13px;padding:9px 10px;}
}
@media print{.no-print{display:none!important;} body{padding:0!important;background:#fff!important;}}
</style>';

    $toolbarJs = '<script>
(function(){
  var btn = document.getElementById("btnContratoImprimir");
  if (btn) btn.addEventListener("click", function(){ window.print(); });
})();
</script>';

    $html = contrato_inject_after_body_open($html, $toolbarCss . $toolbar . $toolbarJs);

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo $html;
} catch (Throwable $e) {
    error_log(
        'contrato_documento.php [' . $e->getMessage() . '] '
        . $e->getFile() . ':' . $e->getLine()
        . ' student_dni=' . ($studentDni ?? '')
    );
    http_response_code(500);
    echo 'No se pudo generar el contrato. Intente nuevamente más tarde.';
}
