<?php
/**
 * Regenera un PDF firmado en storage sin BD (extrae texto del PDF existente).
 */
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/backend/lib/contract_render.php';
require_once BASE_PATH . '/backend/lib/contract_pdf.php';

$pdfPath = $argv[1] ?? BASE_PATH . '/storage/contratos_firmados/2026/Contrato_CI_2027_v1_00000000_20260101000000.pdf';
$pdfPath = str_replace('\\', '/', $pdfPath);

if (!is_file($pdfPath)) {
    fwrite(STDERR, "No existe: {$pdfPath}\n");
    exit(1);
}

$basename = basename($pdfPath, '.pdf');
if (!preg_match('/^(Contrato_[A-Za-z0-9_]+)_(\d+)_(\d{14})$/', $basename, $m)) {
    fwrite(STDERR, "Nombre de archivo no reconocido: {$basename}\n");
    exit(1);
}

$contractVersion = $m[1];
$studentDni = $m[2];
$tsRaw = $m[3];
$acceptedAtUtc = substr($tsRaw, 0, 4) . '-' . substr($tsRaw, 4, 2) . '-' . substr($tsRaw, 6, 2)
    . ' ' . substr($tsRaw, 8, 2) . ':' . substr($tsRaw, 10, 2) . ':' . substr($tsRaw, 12, 2);

$raw = (string) file_get_contents($pdfPath);
$text = '';
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams)) {
    foreach ($streams[1] as $chunk) {
        $decoded = @gzuncompress($chunk);
        if ($decoded === false) {
            $decoded = @gzinflate($chunk);
        }
        if ($decoded !== false) {
            $text .= ' ' . $decoded;
        }
    }
}

$docData = [
    'contract_version' => $contractVersion,
    'alumno_dni' => $studentDni,
    'codigo_institucion' => 'CI',
    'curso' => 'SCI',
    'accepted_at_utc' => $acceptedAtUtc,
    'fecha_aceptacion' => contrato_format_fecha_aceptacion($acceptedAtUtc),
    'fecha_aceptacion_corta' => contrato_format_fecha_aceptacion_corta($acceptedAtUtc),
    'ip_registro' => '[Registrada por el sistema]',
    'user_agent' => '',
    'codigo_verificacion' => '',
    'version_documento' => $contractVersion,
    'nombre_responsable' => '',
    'dni_responsable' => '',
    'domicilio' => '',
    'localidad' => '',
    'email_responsable' => '',
    'alumno_nombre' => '',
    'fragmento_institucion' => '',
    'nombre_institucion' => 'Jardin de Infantes La Milagrosa',
];

if (preg_match('/Entre el Sr\.\s+(.+?)\s+con DNI N°\s+(\d+)/u', $text, $pm)) {
    $docData['nombre_responsable'] = trim($pm[1]);
    $docData['dni_responsable'] = trim($pm[2]);
}
if (preg_match('/domicilio real en\s+(.+?)\s+localidad de\s+([^,\)]+)/u', $text, $pm)) {
    $docData['domicilio'] = trim($pm[1]);
    $docData['localidad'] = trim($pm[2]);
}
if (preg_match('/Alumno:\s+(.+?)\s*\(DNI\s+(\d+)/u', $text, $pm)) {
    $docData['alumno_nombre'] = trim($pm[1]);
    $docData['alumno_dni'] = trim($pm[2]);
}
if (preg_match('/curso\s+([^)]+)\)/u', $text, $pm)) {
    $docData['curso'] = trim($pm[1]);
}
if (preg_match('/[a-f0-9]{64}/i', $text, $pm)) {
    $docData['codigo_verificacion'] = strtolower($pm[0]);
}

$codigo = contrato_escuela_codigo_desde_curso($docData['curso']);
if ($codigo !== '') {
    $docData['codigo_institucion'] = $codigo;
}

echo "Regenerando: {$pdfPath}\n";
echo "Versión: {$contractVersion}, DNI: {$studentDni}\n";

$result = contrato_regenerate_signed_pdf_at_path($contractVersion, $docData, $pdfPath);
if ($result === null) {
    fwrite(STDERR, contrato_pdf_user_message() . PHP_EOL);
    exit(1);
}

echo 'OK sha256=' . substr($result['sha256'], 0, 16) . '...' . PHP_EOL;
echo 'size=' . filesize($pdfPath) . ' bytes' . PHP_EOL;

passthru('php ' . escapeshellarg(BASE_PATH . '/backend/tools/inspect_pdf_margins.php') . ' ' . escapeshellarg($pdfPath));
