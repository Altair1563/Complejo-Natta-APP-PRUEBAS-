<?php
/**
 * Prueba de archivo PDF firmado con plantilla real (CLI).
 */
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/backend/lib/contract_render.php';
require_once BASE_PATH . '/backend/lib/contract_pdf.php';

$version = 'Contrato_CI_2027_v1';
$docData = [
    'contract_version' => $version,
    'nombre_responsable' => 'Prueba Diagnostico',
    'dni_responsable' => '12345678',
    'domicilio' => 'Calle Test 1',
    'localidad' => 'Córdoba',
    'email_responsable' => 'test@example.com',
    'alumno_nombre' => 'Alumno Prueba',
    'alumno_dni' => '99999999',
    'curso' => 'SCI',
    'codigo_verificacion' => '',
    'version_documento' => $version,
    'fragmento_institucion' => 'Institución de prueba',
    'fecha_aceptacion' => date('d/m/Y H:i'),
    'fecha_aceptacion_corta' => date('d/m/Y'),
    'ip_registro' => '127.0.0.1',
    'user_agent' => 'CLI test',
    'nombre_institucion' => 'Jardin de Infantes La Milagrosa',
    'codigo_institucion' => 'CI',
    'accepted_at_utc' => gmdate('Y-m-d H:i:s'),
];

$archived = contrato_archive_signed_document($version, $docData);
if ($archived === null) {
    fwrite(STDERR, contrato_pdf_user_message() . PHP_EOL);
    $err = contrato_pdf_get_last_error();
    if ($err !== null) {
        fwrite(STDERR, 'code=' . $err['code'] . PHP_EOL);
    }
    exit(1);
}

echo 'OK pdf_relative=' . $archived['pdf_relative'] . PHP_EOL;
echo 'sha256=' . substr($archived['sha256'], 0, 16) . '...' . PHP_EOL;
echo 'size=' . filesize($archived['pdf_absolute']) . ' bytes' . PHP_EOL;
@unlink($archived['pdf_absolute']);
exit(0);
