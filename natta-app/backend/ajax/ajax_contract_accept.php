<?php
require_once __DIR__ . '/../../backend/bootstrap.php';
require_once __DIR__ . '/../lib/contract_institution.php';
require_once __DIR__ . '/../lib/contract_render.php';
require_once __DIR__ . '/../lib/contract_pdf.php';
header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;

// La generación del PDF + SMTP puede superar el timeout del cliente; no abortar a mitad del envío.
ignore_user_abort(true);
if (function_exists('set_time_limit')) {
    @set_time_limit(180);
}

if (!isset($_SESSION['dni_alumno']) || !isset($_SESSION['nro_familia'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida']);
    exit;
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Token CSRF inválido']);
    exit;
}

$studentDni = trim((string)($_POST['student_dni'] ?? ''));
$nroLegajo = trim((string)($_POST['nro_legajo'] ?? ''));
$password = trim((string)($_POST['password'] ?? ''));
$checkbox = (string)($_POST['accepted_checkbox'] ?? '0');
$declarantName = trim((string)($_POST['declarant_name'] ?? ''));
$declarantDni = preg_replace('/\D+/', '', (string)($_POST['declarant_dni'] ?? ''));
$declarantDomicilio = trim((string)($_POST['declarant_domicilio'] ?? ''));
$declarantLocalidad = trim((string)($_POST['declarant_localidad'] ?? ''));

if (
    $studentDni === '' || $nroLegajo === '' || $password === ''
    || $declarantName === '' || $declarantDni === ''
    || $declarantDomicilio === '' || $declarantLocalidad === ''
) {
    echo json_encode(['ok' => false, 'msg' => 'Complete todos los datos requeridos antes de firmar.']);
    exit;
}
if (!preg_match('/^\d{6,12}$/', $declarantDni)) {
    echo json_encode(['ok' => false, 'msg' => 'Debe ingresar un DNI válido']);
    exit;
}
if ($checkbox !== '1') {
    echo json_encode(['ok' => false, 'msg' => 'Debe marcar la declaración de lectura y aceptación para continuar.']);
    exit;
}

$conn = getDbConnection();
contrato_ensure_acceptance_schema($conn);
$nroFamilia = (string)($_SESSION['nro_familia'] ?? '');
$userDni = (string)($_SESSION['dni_alumno'] ?? '');
$email = trim((string)($_SESSION['email'] ?? ''));
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
$acceptedAtUtc = date('Y-m-d H:i:s');

$sqlStudent = "SELECT nombre_alumno, apellido_alumno, curso, 0 AS es_inactivo
               FROM legajos
               WHERE nro_familia = ? AND dni_alumno = ? AND nro_legajo = ?
               UNION
               SELECT nombre_alumno, apellido_alumno, curso, 1 AS es_inactivo
               FROM legajos_inactivos
               WHERE nro_familia = ? AND dni_alumno = ? AND nro_legajo = ?
               LIMIT 1";
$stmtStudent = $conn->prepare($sqlStudent);
if (!$stmtStudent) {
    echo json_encode(['ok' => false, 'msg' => 'Error de validación']);
    $conn->close();
    exit;
}
$stmtStudent->bind_param('ssssss', $nroFamilia, $studentDni, $nroLegajo, $nroFamilia, $studentDni, $nroLegajo);
$stmtStudent->execute();
$studentRows = fetchAllFromStmt($stmtStudent);
$stmtStudent->close();
if (empty($studentRows)) {
    echo json_encode(['ok' => false, 'msg' => 'Alumno no válido para esta familia']);
    $conn->close();
    exit;
}
$studentName = trim(((string)$studentRows[0]['nombre_alumno']) . ' ' . ((string)$studentRows[0]['apellido_alumno']));
$esInactivo = ((int)($studentRows[0]['es_inactivo'] ?? 0) === 1);
$cursoAlumno = (string)($studentRows[0]['curso'] ?? '');

if ($esInactivo) {
    echo json_encode(['ok' => false, 'msg' => 'El alumno está inactivo y no puede firmar nuevos contratos']);
    $conn->close();
    exit;
}

if (!contrato_alumno_puede_firmar($conn, $nroLegajo, $cursoAlumno)) {
    echo json_encode(['ok' => false, 'msg' => contrato_msg_firma_bloqueada($cursoAlumno)]);
    $conn->close();
    exit;
}

$sqlUser = "SELECT password_hash, email FROM usuarios WHERE dni_alumno = ? " . ($email !== '' ? "AND email = ? " : "") . "LIMIT 1";
$stmtUser = $conn->prepare($sqlUser);
if (!$stmtUser) {
    echo json_encode(['ok' => false, 'msg' => 'No se pudo validar contraseña']);
    $conn->close();
    exit;
}
if ($email !== '') {
    $stmtUser->bind_param('ss', $userDni, $email);
} else {
    $stmtUser->bind_param('s', $userDni);
}
$stmtUser->execute();
$userRows = fetchAllFromStmt($stmtUser);
$stmtUser->close();

if (empty($userRows) || !password_verify($password, (string)$userRows[0]['password_hash'])) {
    echo json_encode(['ok' => false, 'msg' => 'Contraseña incorrecta']);
    $conn->close();
    exit;
}
$dbEmail = trim((string)($userRows[0]['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = $dbEmail;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = '';
}

$codigoInst = contrato_codigo_institucion_desde_curso($cursoAlumno);
if ($codigoInst === null) {
    echo json_encode(['ok' => false, 'msg' => 'No se pudo determinar la institución a partir del curso del alumno.']);
    $conn->close();
    exit;
}

$contract = contrato_fetch_vigente_por_codigo($conn, $codigoInst);
if ($contract === null) {
    $versionEsperada = contrato_version_desde_codigo_institucion($codigoInst);
    echo json_encode([
        'ok' => false,
        'msg' => 'No hay contrato vigente configurado para la institución «' . $codigoInst . '» (versión «' . $versionEsperada . '»). Revise contratos_instituciones.',
    ]);
    $conn->close();
    exit;
}
$contractVersion = (string)$contract['contract_version'];
$baseAcceptedText = (string)$contract['accepted_text'];

$instRow = contrato_fetch_institucion_por_codigo($conn, $codigoInst);
if ($instRow === null) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Falta configurar la institución en la base de datos para el código «' . $codigoInst . '».',
    ]);
    $conn->close();
    exit;
}
$instFragmento = contrato_fragmento_institucion_texto($instRow);
$preambulo = contrato_preambulo_aceptacion_texto(
    $declarantName,
    $declarantDni,
    $declarantDomicilio,
    $declarantLocalidad,
    $instRow
);
$acceptedText = $preambulo . ' ' . $baseAcceptedText
    . ' Alumno: ' . $studentName . ' (DNI ' . $studentDni . ', curso ' . $cursoAlumno . ').';
$reglamentoInfo = contrato_reglamento_info_desde_codigo($codigoInst);
$reglamentoPdfUrl = contrato_reglamento_pdf_url_desde_codigo($codigoInst);
$documentoVigente = contrato_documento_vigente_titulo($contractVersion);

$sqlAlreadySigned = "SELECT id FROM contratos_aceptados
                     WHERE student_dni = ? AND nro_familia = ? AND contract_version = ? AND status = 'activo'
                     LIMIT 1";
$stmtAlready = $conn->prepare($sqlAlreadySigned);
if ($stmtAlready) {
    $stmtAlready->bind_param('sss', $studentDni, $nroFamilia, $contractVersion);
    $stmtAlready->execute();
    $alreadyRows = fetchAllFromStmt($stmtAlready);
    $stmtAlready->close();
    if (!empty($alreadyRows)) {
        echo json_encode(['ok' => false, 'msg' => 'Este contrato ya fue firmado para este alumno']);
        $conn->close();
        exit;
    }
}

$docDataForPdf = [
    'contract_version' => $contractVersion,
    'nombre_responsable' => $declarantName,
    'dni_responsable' => $declarantDni,
    'domicilio' => $declarantDomicilio,
    'localidad' => $declarantLocalidad,
    'email_responsable' => $email,
    'alumno_nombre' => $studentName,
    'alumno_dni' => $studentDni,
    'curso' => $cursoAlumno,
    'codigo_verificacion' => '',
    'version_documento' => $contractVersion,
    'fragmento_institucion' => $instFragmento,
    'fecha_aceptacion' => contrato_format_fecha_aceptacion($acceptedAtUtc),
    'fecha_aceptacion_corta' => contrato_format_fecha_aceptacion_corta($acceptedAtUtc),
    'ip_registro' => $ip,
    'user_agent' => $userAgent,
    'nombre_institucion' => contrato_nombre_institucion_display($codigoInst, $instRow),
    'codigo_institucion' => $codigoInst,
    'accepted_at_utc' => $acceptedAtUtc,
];

$storageError = contrato_ensure_signed_pdf_storage_writable();
if ($storageError !== null) {
    echo json_encode(['ok' => false, 'msg' => $storageError]);
    $conn->close();
    exit;
}

$archived = contrato_archive_signed_document($contractVersion, $docDataForPdf);
if ($archived === null) {
    echo json_encode([
        'ok' => false,
        'msg' => contrato_pdf_user_message(),
        'pdf_error_code' => contrato_pdf_get_last_error()['code'] ?? 'unknown',
    ]);
    $conn->close();
    exit;
}

$signedDocumentSha256 = (string)$archived['sha256'];
$contractHash = (string)($archived['sha256_initial'] ?? $signedDocumentSha256);
$acceptedPdfPath = (string)$archived['pdf_relative'];
$signedPdfDownloadUrl = contrato_signed_pdf_download_url($studentDni);

$hasSignedPdfCols = contrato_db_column_exists($conn, 'contratos_aceptados', 'accepted_pdf_path')
    && contrato_db_column_exists($conn, 'contratos_aceptados', 'signed_document_sha256');

$mapContractInsertError = static function (mysqli $conn, mysqli_stmt $stmt): string {
    $err = trim((string)($stmt->error ?: $conn->error));
    error_log('ajax_contract_accept INSERT: ' . $err);
    if ($err === '') {
        return 'No se pudo registrar la aceptación. Intente nuevamente en unos minutos.';
    }
    if (stripos($err, 'Duplicate') !== false) {
        return 'Este contrato ya fue registrado para este alumno.';
    }
    if (stripos($err, 'foreign key constraint') !== false || stripos($err, 'fk_contratos_usuario') !== false) {
        return 'No se pudo vincular la firma con su usuario. Cierre sesión, ingrese nuevamente e intente otra vez.';
    }

    return 'No se pudo registrar la aceptación. Intente nuevamente en unos minutos.';
};

$conn->begin_transaction();
try {
    // confirmation_email_sent / _at quedan en 0/NULL; se actualizan tras el SMTP.
    if ($hasSignedPdfCols) {
        $sqlInsert = "INSERT INTO contratos_aceptados (
                        user_dni, student_dni, nro_legajo, nro_familia,
                        contract_version, contract_hash, signed_document_sha256, accepted_text, accepted_checkbox,
                        accepted_at_utc, ip_address, user_agent, password_reconfirmed,
                        pdf_url, accepted_pdf_path, confirmation_email_sent, confirmation_email_sent_at, status,
                        created_at, updated_at
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 1, ?, ?, 0, NULL, 'activo', ?, ?)";
        $stmtInsert = $conn->prepare($sqlInsert);
        if (!$stmtInsert) {
            throw new RuntimeException('prepare_insert:' . $conn->error);
        }
        $stmtInsert->bind_param(
            'sssssssssssssss',
            $userDni,
            $studentDni,
            $nroLegajo,
            $nroFamilia,
            $contractVersion,
            $contractHash,
            $signedDocumentSha256,
            $acceptedText,
            $acceptedAtUtc,
            $ip,
            $userAgent,
            $signedPdfDownloadUrl,
            $acceptedPdfPath,
            $acceptedAtUtc,
            $acceptedAtUtc
        );
    } else {
        $sqlInsert = "INSERT INTO contratos_aceptados (
                        user_dni, student_dni, nro_legajo, nro_familia,
                        contract_version, contract_hash, accepted_text, accepted_checkbox,
                        accepted_at_utc, ip_address, user_agent, password_reconfirmed,
                        pdf_url, confirmation_email_sent, confirmation_email_sent_at, status,
                        created_at, updated_at
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 1, ?, 0, NULL, 'activo', ?, ?)";
        $stmtInsert = $conn->prepare($sqlInsert);
        if (!$stmtInsert) {
            throw new RuntimeException('prepare_insert:' . $conn->error);
        }
        $stmtInsert->bind_param(
            'sssssssssssss',
            $userDni,
            $studentDni,
            $nroLegajo,
            $nroFamilia,
            $contractVersion,
            $contractHash,
            $acceptedText,
            $acceptedAtUtc,
            $ip,
            $userAgent,
            $signedPdfDownloadUrl,
            $acceptedAtUtc,
            $acceptedAtUtc
        );
    }
    if (!$stmtInsert->execute()) {
        $msg = $mapContractInsertError($conn, $stmtInsert);
        $stmtInsert->close();
        throw new RuntimeException('insert_failed:' . $msg);
    }
    $stmtInsert->close();
    $conn->commit();

    try {
        contrato_aplicar_doc_recibida_a_aceptacion($conn, $studentDni, $contractVersion);
    } catch (Throwable $e) {
        error_log('ajax_contract_accept doc_recibida sync: ' . $e->getMessage());
    }
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Error en ajax_contract_accept (guardado): ' . $e->getMessage());
    $msg = 'No se pudo registrar la aceptación. Intente nuevamente en unos minutos.';
    if (str_starts_with($e->getMessage(), 'insert_failed:')) {
        $msg = substr($e->getMessage(), strlen('insert_failed:'));
    }
    echo json_encode(['ok' => false, 'msg' => $msg]);
    $conn->close();
    exit;
}

try {
    app_audit_log($conn, 'contract_aceptado', 'contrato', $nroLegajo, [
        'student_dni' => $studentDni,
        'contract_version' => $contractVersion,
        'declarant_dni' => $declarantDni,
        'email_destino' => $email,
    ], $userDni, (string)($_SESSION['email'] ?? ''), $nroFamilia);
} catch (Throwable $e) {
    error_log('ajax_contract_accept audit: ' . $e->getMessage());
}

// Responder al cliente antes del SMTP: el PDF ya está guardado y un timeout del
// navegador/proxy no debe cortar el envío del email de confirmación.
$willSendEmail = $email !== '';
echo json_encode([
    'ok' => true,
    'msg' => $willSendEmail
        ? 'Contrato aceptado correctamente. Le enviamos el comprobante por email.'
        : 'Contrato aceptado correctamente. No hay un email válido en la cuenta para enviar el comprobante; puede descargar el PDF desde la app.',
    'accepted_at_utc' => $acceptedAtUtc,
    'contract_version' => $contractVersion,
    'contract_hash' => $contractHash,
    'signed_document_sha256' => $signedDocumentSha256,
    'signed_pdf_url' => $signedPdfDownloadUrl,
    'confirmation_email_sent' => false,
    'confirmation_email_queued' => $willSendEmail,
], JSON_UNESCAPED_UNICODE);

if (function_exists('fastcgi_finish_request')) {
    @fastcgi_finish_request();
} else {
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

if ($email !== '') {
    try {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        require_once __DIR__ . '/../../config/smtp.php';

        $contratoPersonalizadoUrl = contrato_documento_url_para_familia([
            'student_dni' => $studentDni,
            'declarant_name' => $declarantName,
            'declarant_dni' => $declarantDni,
            'declarant_domicilio' => $declarantDomicilio,
            'declarant_localidad' => $declarantLocalidad,
        ]);

        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = (int)SMTP_PORT;
        $mail->Timeout = 40;
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($email);
        contrato_configure_mailer_utf8($mail);
        $mail->isHTML(true);
        $mailContent = contrato_build_confirmation_email([
            'email' => $email,
            'student_name' => $studentName,
            'student_dni' => $studentDni,
            'curso' => $cursoAlumno,
            'documento_vigente' => $documentoVigente,
            'contract_version' => $contractVersion,
            'accepted_at_utc' => $acceptedAtUtc,
            'ip' => $ip,
            'contract_hash' => $signedDocumentSha256,
            'signed_pdf_url' => $signedPdfDownloadUrl,
            'declarant_name' => $declarantName,
            'declarant_dni' => $declarantDni,
            'declarant_domicilio' => $declarantDomicilio,
            'declarant_localidad' => $declarantLocalidad,
            'institucion_fragmento' => $instFragmento,
            'pdf_url' => $signedPdfDownloadUrl,
            'contrato_personalizado_url' => $contratoPersonalizadoUrl,
            'user_agent' => $userAgent,
            'reglamento_pdf_url' => $reglamentoPdfUrl,
            'reglamento_email_label' => $reglamentoInfo['email_link_label'],
        ]);
        $mail->Subject = $mailContent['subject'];
        $mail->Body = $mailContent['html'];
        $mail->AltBody = $mailContent['text'];

        $pdfAbsolute = (string)($archived['pdf_absolute'] ?? '');
        if ($pdfAbsolute !== '' && is_file($pdfAbsolute) && is_readable($pdfAbsolute)) {
            $mail->addAttachment(
                $pdfAbsolute,
                'Contrato_firmado_' . preg_replace('/\D+/', '', $studentDni) . '.pdf'
            );
        }

        if (!$mail->send()) {
            throw new RuntimeException('PHPMailer send() retornó false: ' . $mail->ErrorInfo);
        }

        $mailSentAt = date('Y-m-d H:i:s');
        $sqlUpdateMail = "UPDATE contratos_aceptados
                          SET confirmation_email_sent = 1, confirmation_email_sent_at = ?, updated_at = ?
                          WHERE student_dni = ? AND contract_version = ? AND status = 'activo'";
        $stmtUpdate = $conn->prepare($sqlUpdateMail);
        if ($stmtUpdate) {
            $stmtUpdate->bind_param('ssss', $mailSentAt, $mailSentAt, $studentDni, $contractVersion);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        }
    } catch (Throwable $e) {
        $extra = '';
        if (isset($mail) && $mail instanceof PHPMailer && $mail->ErrorInfo !== '') {
            $extra = ' | PHPMailer: ' . $mail->ErrorInfo;
        }
        error_log('ajax_contract_accept email: ' . $e->getMessage() . $extra . ' | to=' . $email);
    }
} else {
    error_log('ajax_contract_accept email: sin destinatario válido (session/db) para student_dni=' . $studentDni);
}

$conn->close();
