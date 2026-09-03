<?php
/**
 * Vista previa del contrato en sesión (evita PII en query string).
 */

const CONTRATO_PREVIEW_SESSION_KEY = 'contract_document_view';
const CONTRATO_PREVIEW_TTL_SECONDS = 600;

/**
 * @param array{declarant_name?: string, declarant_dni?: string, declarant_domicilio?: string, declarant_localidad?: string, modo?: string, auto_pdf?: bool} $opts
 */
function contrato_preview_session_store(string $studentDni, array $opts = []): void
{
    $studentDni = trim($studentDni);
    if ($studentDni === '') {
        return;
    }

    $_SESSION[CONTRATO_PREVIEW_SESSION_KEY] = [
        'student_dni' => $studentDni,
        'declarant_name' => trim((string)($opts['declarant_name'] ?? '')),
        'declarant_dni' => preg_replace('/\D+/', '', (string)($opts['declarant_dni'] ?? '')),
        'declarant_domicilio' => trim((string)($opts['declarant_domicilio'] ?? '')),
        'declarant_localidad' => trim((string)($opts['declarant_localidad'] ?? '')),
        'modo' => trim((string)($opts['modo'] ?? '')),
        'auto_pdf' => !empty($opts['auto_pdf']),
        'expires' => time() + CONTRATO_PREVIEW_TTL_SECONDS,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function contrato_preview_session_read(): ?array
{
    $data = $_SESSION[CONTRATO_PREVIEW_SESSION_KEY] ?? null;
    if (!is_array($data)) {
        return null;
    }
    if ((int)($data['expires'] ?? 0) < time()) {
        unset($_SESSION[CONTRATO_PREVIEW_SESSION_KEY]);
        return null;
    }
    if (trim((string)($data['student_dni'] ?? '')) === '') {
        return null;
    }

    return $data;
}

function contrato_preview_document_url(): string
{
    require_once __DIR__ . '/contract_institution.php';

    return contrato_documento_public_url();
}

function contrato_preview_signed_pdf_url(): string
{
    require_once __DIR__ . '/contract_institution.php';

    return contrato_public_app_root_url() . '/contrato_documento_firmado.php';
}
