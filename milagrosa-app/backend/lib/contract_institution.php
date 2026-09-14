<?php

require_once __DIR__ . '/ingresantes_externos_2027.php';

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($string, $encoding = null)
    {
        return strtoupper((string)$string);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null)
    {
        return strlen((string)$string);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null)
    {
        return $length === null
            ? substr((string)$string, (int)$start)
            : substr((string)$string, (int)$start, (int)$length);
    }
}

if (!function_exists('fetchAllFromStmt') && function_exists('fetch_all_from_stmt')) {
    function fetchAllFromStmt(mysqli_stmt $stmt): array
    {
        return fetch_all_from_stmt($stmt);
    }
}

/**
 * @return bool
 */
function contrato_db_column_exists(mysqli $conn, string $table, string $column): bool
{
    $cache = &contrato_db_column_exists_cache_store();
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $dbName = defined('DB_NAME') ? DB_NAME : '';
    $sql = 'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $cache[$key] = false;

        return false;
    }
    $stmt->bind_param('sss', $dbName, $table, $column);
    $stmt->execute();
    $rows = fetchAllFromStmt($stmt);
    $stmt->close();
    $cache[$key] = !empty($rows) && (int)($rows[0]['c'] ?? 0) > 0;

    return $cache[$key];
}

/**
 * @return array<string, bool>
 */
function &contrato_db_column_exists_cache_store(): array
{
    static $cache = [];

    return $cache;
}

function contrato_db_column_exists_reset(): void
{
    $cache = &contrato_db_column_exists_cache_store();
    $cache = [];
}

/**
 * Asegura columnas usadas por la firma digital (PDF depositado, SHA, aprobación admin).
 */
function contrato_ensure_acceptance_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = [
        'signed_document_sha256' => "ADD COLUMN signed_document_sha256 char(64) DEFAULT NULL COMMENT 'SHA-256 del PDF firmado depositado' AFTER contract_hash",
        'accepted_pdf_path' => "ADD COLUMN accepted_pdf_path varchar(512) DEFAULT NULL COMMENT 'Ruta relativa en storage/contratos_firmados' AFTER pdf_url",
        'admin_aprobado' => "ADD COLUMN admin_aprobado tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = administración confirmó documentación y requisitos' AFTER status",
        'info_erronea' => "ADD COLUMN info_erronea tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = secretaría marcó datos de firma como erróneos' AFTER admin_aprobado",
    ];

    foreach ($columns as $column => $ddl) {
        if (contrato_db_column_exists($conn, 'contratos_aceptados', $column)) {
            continue;
        }
        try {
            $conn->query('ALTER TABLE contratos_aceptados ' . $ddl);
            contrato_db_column_exists_reset();
        } catch (Throwable $e) {
            error_log('contrato_ensure_acceptance_schema ' . $column . ': ' . $e->getMessage());
        }
    }

    $done = true;
}

/**
 * Si secretaría ya marcó Doc. recibida antes de la firma, copia el flag a admin_aprobado.
 */
function contrato_aplicar_doc_recibida_a_aceptacion(
    mysqli $conn,
    string $studentDni,
    string $contractVersion
): void {
    $studentDni = trim($studentDni);
    $contractVersion = trim($contractVersion);
    if ($studentDni === '' || $contractVersion === '') {
        return;
    }

    if (!contrato_db_column_exists($conn, 'contratos_aceptados', 'admin_aprobado')) {
        return;
    }

    $sqlSelect = 'SELECT recibida FROM documentacion_recibida
                  WHERE TRIM(student_dni) = ? AND contract_version = ?
                  LIMIT 1';
    $stmtSelect = $conn->prepare($sqlSelect);
    if (!$stmtSelect) {
        return;
    }

    $stmtSelect->bind_param('ss', $studentDni, $contractVersion);
    $stmtSelect->execute();
    $rows = fetchAllFromStmt($stmtSelect);
    $stmtSelect->close();

    if (empty($rows) || (int)($rows[0]['recibida'] ?? 0) !== 1) {
        return;
    }

    $sqlUpdate = "UPDATE contratos_aceptados
                  SET admin_aprobado = 1, updated_at = NOW()
                  WHERE status = 'activo'
                    AND contract_version = ?
                    AND TRIM(student_dni) = ?";
    $stmtUpdate = $conn->prepare($sqlUpdate);
    if (!$stmtUpdate) {
        return;
    }

    $stmtUpdate->bind_param('ss', $contractVersion, $studentDni);
    try {
        $stmtUpdate->execute();
    } catch (Throwable $e) {
        error_log('contrato_aplicar_doc_recibida_a_aceptacion: ' . $e->getMessage());
    }
    $stmtUpdate->close();
}

/**
 * Código de institución = últimas 2 letras del curso en mayúsculas (ej. 4AET -> ET, 5BJN -> JN).
 */
function contrato_codigo_institucion_desde_curso(?string $curso): ?string
{
    $c = mb_strtoupper(trim((string)$curso), 'UTF-8');
    if ($c === '' || mb_strlen($c, 'UTF-8') < 2) {
        return null;
    }
    return mb_substr($c, -2, 2, 'UTF-8');
}

/**
 * @return array{codigo:string,responsable_institucion:string,institucion:string,domicilio_institucion:string}|null
 */
function contrato_fetch_institucion_por_codigo(mysqli $conn, string $codigo): ?array
{
    $codigo = mb_strtoupper(trim($codigo), 'UTF-8');
    if ($codigo === '' || mb_strlen($codigo, 'UTF-8') !== 2) {
        return null;
    }
    $sql = 'SELECT codigo, responsable_institucion, institucion, domicilio_institucion
            FROM contratos_instituciones WHERE codigo = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $rows = fetchAllFromStmt($stmt);
    $stmt->close();
    if (empty($rows)) {
        return null;
    }
    $r = $rows[0];
    return [
        'codigo' => (string)$r['codigo'],
        'responsable_institucion' => (string)$r['responsable_institucion'],
        'institucion' => (string)$r['institucion'],
        'domicilio_institucion' => (string)$r['domicilio_institucion'],
    ];
}

/**
 * Texto legal de aceptación (mismo para todas las instituciones si no hay fila en BD).
 */
function contrato_default_accepted_text(): string
{
    return 'Declaro haber leído y aceptado íntegramente el Contrato de servicios educativos vigente y el reglamento institucional aplicable.';
}

/**
 * Código de sala (CI, RI, VI, UI) → segmento del nombre de archivo HTML (Contrato_CI_2027_v1.html).
 */
function contrato_slug_institucion(string $codigo): string
{
    $codigo = mb_strtoupper(trim($codigo), 'UTF-8');

    return $codigo;
}

/**
 * Nombre de versión / plantilla HTML (ej. Contrato_CI_2027_v1).
 */
function contrato_version_desde_codigo_institucion(string $codigo, string $anio = '2027', string $revision = 'v1'): string
{
    $slug = contrato_slug_institucion($codigo);
    $anio = preg_replace('/\D/', '', $anio) ?: '2027';
    $revision = trim($revision) !== '' ? trim($revision) : 'v1';

    return 'Contrato_' . $slug . '_' . $anio . '_' . $revision;
}

/**
 * @return array{contract_version:string,contract_anio:string,contract_revision:string,accepted_text:string,contrato_activo:bool}|null
 */
function contrato_fetch_vigente_por_codigo(mysqli $conn, string $codigo): ?array
{
    $codigo = mb_strtoupper(trim($codigo), 'UTF-8');
    if ($codigo === '' || mb_strlen($codigo, 'UTF-8') !== 2) {
        return null;
    }

    if (!contrato_db_column_exists($conn, 'contratos_instituciones', 'contrato_activo')) {
        error_log('contrato_fetch_vigente_por_codigo: faltan columnas de contrato en contratos_instituciones');

        return null;
    }

    $sql = 'SELECT codigo, contract_anio, contract_revision, contrato_activo, accepted_text
            FROM contratos_instituciones
            WHERE codigo = ?
            LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $rows = fetchAllFromStmt($stmt);
    $stmt->close();
    if (empty($rows)) {
        return null;
    }
    $r = $rows[0];
    if ((int)($r['contrato_activo'] ?? 0) !== 1) {
        return null;
    }

    $anio = trim((string)($r['contract_anio'] ?? '2027'));
    $revision = trim((string)($r['contract_revision'] ?? 'v1'));
    $acceptedText = trim((string)($r['accepted_text'] ?? ''));
    if ($acceptedText === '') {
        $acceptedText = contrato_default_accepted_text();
    }

    return [
        'contract_version' => contrato_version_desde_codigo_institucion($codigo, $anio, $revision),
        'contract_anio' => $anio,
        'contract_revision' => $revision,
        'accepted_text' => $acceptedText,
        'contrato_activo' => true,
    ];
}

/**
 * @return array{contract_version:string,contract_anio:string,contract_revision:string,accepted_text:string,contrato_activo:bool}|null
 */
function contrato_fetch_vigente_por_curso(mysqli $conn, ?string $curso): ?array
{
    $codigo = contrato_codigo_institucion_desde_curso($curso);
    if ($codigo === null) {
        return null;
    }

    return contrato_fetch_vigente_por_codigo($conn, $codigo);
}

function contrato_public_app_root_url(): string
{
    if (!defined('APP_PUBLIC_URL')) {
        require_once __DIR__ . '/../../config/app.php';
    }
    $url = defined('APP_PUBLIC_URL') ? APP_PUBLIC_URL : 'https://complejonatta.com/milagrosa-app/index.php';

    return (string)preg_replace('#/index\.php$#', '', rtrim($url, '/'));
}

function contrato_documento_public_url(): string
{
    return contrato_public_app_root_url() . '/contrato_documento.php';
}

function contrato_docs_public_base_url(): string
{
    return contrato_public_app_root_url() . '/docs';
}

/**
 * @return array{modal_link_label:string,email_link_label:string,filename:string}
 */
function contrato_reglamento_info_desde_codigo(string $codigo): array
{
    // Un único reglamento para CI / RI / VI / UI (Jardin de Infantes La Milagrosa).
    return [
        'modal_link_label' => 'Leer REGLAMENTO INSTITUCIONAL 2027',
        'email_link_label' => 'Reglamento CPEEN 2027',
        'filename' => 'REGLAMENTO CPEEN 2027 - La Milagrosa.pdf',
    ];
}

function contrato_reglamento_pdf_url_desde_codigo(string $codigo): string
{
    $info = contrato_reglamento_info_desde_codigo($codigo);

    return contrato_docs_public_base_url() . '/reglamento-institucional/' . rawurlencode($info['filename']);
}

function contrato_documento_vigente_titulo(?string $contractVersion): string
{
    $v = (string)$contractVersion;
    if (preg_match('/20\d{2}/', $v, $m)) {
        return 'Contrato de Servicio Educativo ' . $m[0];
    }

    return 'Contrato de Servicio Educativo';
}

function contrato_domicilio_electronico_institucion(): string
{
    return 'nattadomicilioelectronico@gmail.com';
}

function contrato_fragmento_institucion_texto(array $instRow): string
{
    $resp = trim((string)($instRow['responsable_institucion'] ?? ''));
    $inst = trim((string)($instRow['institucion'] ?? ''));
    $sede = trim((string)($instRow['domicilio_institucion'] ?? ''));
    $email = trim((string)($instRow['email_domicilio_electronico'] ?? ''));
    if ($email === '') {
        $email = contrato_domicilio_electronico_institucion();
    }

    return $resp . ', en representación ' . $inst . ', con domicilio electrónico en ' . $email . ' y sede en ' . $sede;
}

function contrato_preambulo_familia_texto(
    string $declarantName,
    string $declarantDni,
    string $declarantDomicilio,
    string $declarantLocalidad
): string {
    return 'Entre el Sr. ' . $declarantName . ' con DNI N° ' . $declarantDni
        . ', con domicilio real en ' . $declarantDomicilio . ' localidad de ' . $declarantLocalidad
        . ', en adelante "LA FAMILIA"';
}

function contrato_preambulo_aceptacion_texto(
    string $declarantName,
    string $declarantDni,
    string $declarantDomicilio,
    string $declarantLocalidad,
    array $instRow
): string {
    $familia = contrato_preambulo_familia_texto($declarantName, $declarantDni, $declarantDomicilio, $declarantLocalidad);
    $inst = contrato_fragmento_institucion_texto($instRow);

    return $familia . ' y ' . $inst
        . ', en adelante "ESTABLECIMIENTO EDUCATIVO" celebran el presente contrato de servicios educativos sujeto a las siguientes cláusulas.';
}

/**
 * Configura PHPMailer para envío correcto de acentos y eñes (UTF-8).
 */
function contrato_configure_mailer_utf8(\PHPMailer\PHPMailer\PHPMailer $mail): void
{
    $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mail->Encoding = 'base64';
}

function contrato_email_html_wrapper(string $innerHtml): string
{
    return '<!DOCTYPE html>'
        . '<html lang="es">'
        . '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>'
        . '<body style="margin:0;padding:16px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#222;">'
        . $innerHtml
        . '</body></html>';
}

/**
 * @return array{html:string,text:string,subject:string}
 */
function contrato_build_confirmation_email(array $data): array
{
    $h = static function (?string $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    $email = (string)($data['email'] ?? '');
    $studentName = (string)($data['student_name'] ?? '');
    $studentDni = (string)($data['student_dni'] ?? '');
    $curso = (string)($data['curso'] ?? '');
    $documentoVigente = (string)($data['documento_vigente'] ?? '');
    $contractVersion = (string)($data['contract_version'] ?? '');
    $acceptedAtUtc = (string)($data['accepted_at_utc'] ?? '');
    $ip = (string)($data['ip'] ?? '');
    $contractHash = (string)($data['contract_hash'] ?? '');
    $declarantName = (string)($data['declarant_name'] ?? '');
    $declarantDni = (string)($data['declarant_dni'] ?? '');
    $declarantDomicilio = (string)($data['declarant_domicilio'] ?? '');
    $declarantLocalidad = (string)($data['declarant_localidad'] ?? '');
    $instFragmento = (string)($data['institucion_fragmento'] ?? '');
    $pdfUrl = (string)($data['pdf_url'] ?? '');
    $contratoPersonalizadoUrl = trim((string)($data['contrato_personalizado_url'] ?? ''));
    $reglamentoUrl = (string)($data['reglamento_pdf_url'] ?? '');
    $reglamentoLabel = (string)($data['reglamento_email_label'] ?? 'Reglamento');
    $signedPdfUrl = trim((string)($data['signed_pdf_url'] ?? ''));
    if ($signedPdfUrl === '') {
        $signedPdfUrl = $pdfUrl;
    }
    // Etiquetas para los links que se envían por email.
    $contratoLinkTexto = $documentoVigente . ' — Descargar PDF firmado';

    $field = static function (string $label, string $value) use ($h): string {
        return '<p style="margin:0 0 10px;"><strong>' . $h($label) . '</strong><br>' . $h($value) . '</p>';
    };

    $inner = '<h2 style="margin:0 0 18px;font-size:18px;color:#0c3484;letter-spacing:0.02em;">'
        . 'LA FIRMA DEL CONTRATO SE REGISTRÓ CORRECTAMENTE EN NUESTRA BASE DE DATOS.</h2>'
        . '<p style="margin:0 0 18px;text-align:justify;">Tenga en cuenta que este trámite debe realizarse de manera individual por cada alumno. '
        . 'Si su grupo familiar tiene más de un alumno, deberá completar la firma del contrato en cada caso.</p>'
        . $field('Responsable:', $declarantName)
        . $field('DNI del Responsable:', $declarantDni)
        . $field('Correo electrónico:', $email)
        . '<hr style="border:none;border-top:1px solid #ddd;margin:16px 0;" />'
        . $field('Alumno:', $studentName)
        . $field('DNI del Alumno:', $studentDni)
        . $field('Curso:', $curso)
        . '<hr style="border:none;border-top:1px solid #ddd;margin:16px 0;" />'
        . $field('Documento aceptado:', $documentoVigente)
        . $field('Versión del documento:', $contractVersion)
        . $field('Fecha y hora de registro:', $acceptedAtUtc)
        . $field('Dirección IP registrada:', $ip)
        . $field('Huella SHA-256 (archivo PDF depositado):', $contractHash)
        . '<p style="margin:0 0 14px;font-size:13px;color:#555;text-align:justify;">La huella identifica el PDF íntegro aceptado y resguardado por el establecimiento. Permite verificar que el archivo no fue alterado; no equivale a firma digital certificada.</p>'
        . '<h3 style="margin:22px 0 10px;font-size:15px;color:#0c3484;">Declaración de conformidad</h3>'
        . '<p style="margin:0 0 14px;text-align:justify;">El responsable mencionado precedentemente declara haber leído y aceptado el '
        . '<strong>Contrato de Servicios Educativos</strong> correspondiente al ciclo lectivo 2027, así como también el '
        . '<strong>Reglamento Interno Institucional</strong>.</p>'
        . '<h3 style="margin:18px 0 10px;font-size:15px;color:#0c3484;">Partes intervinientes</h3>'
        . '<p style="margin:0 0 12px;"><strong>• RESPONSABLE:</strong><br>Sr. ' . $h($declarantName) . ', DNI Nº ' . $h($declarantDni)
        . ', con domicilio real en ' . $h($declarantDomicilio) . ', localidad de ' . $h($declarantLocalidad)
        . ', en carácter de responsable legal del alumno/a ' . $h(mb_strtoupper($studentName, 'UTF-8')) . '.</p>'
        . '<p style="margin:0 0 14px;"><strong>• DIRECTIVO DEL ESTABLECIMIENTO EDUCATIVO:</strong><br>' . $h($instFragmento) . '.</p>'
        . '<h3 style="margin:18px 0 10px;font-size:15px;color:#0c3484;">Contrato y documentación</h3>'
        . '<p style="margin:0 0 12px;text-align:justify;">Puede descargar el <strong>PDF íntegro firmado</strong> y acceder al contrato en la app familiar.</p>'
        . '<ul style="margin:0 0 16px;padding-left:1.2rem;">'
        . '<li style="margin-bottom:6px;"><a href="' . $h($signedPdfUrl) . '">' . $h($contratoLinkTexto) . '</a></li>'
        . '<li><a href="' . $h($reglamentoUrl) . '">' . $h($reglamentoLabel) . ' — Descargar PDF</a></li>'
        . '</ul>'
        . '<p style="margin:0;font-size:13px;color:#555;">Este comprobante constituye constancia digital de aceptación registrada en el sistema institucional.</p>';

    $text = "LA FIRMA DEL CONTRATO SE REGISTRÓ CORRECTAMENTE EN NUESTRA BASE DE DATOS.\n\n"
        . "Tenga en cuenta que este trámite debe realizarse de manera individual por cada alumno. "
        . "Si su grupo familiar tiene más de un alumno, deberá completar la firma del contrato en cada caso.\n\n"
        . "Responsable: {$declarantName}\n"
        . "DNI del Responsable: {$declarantDni}\n"
        . "Correo electrónico: {$email}\n\n"
        . "Alumno: {$studentName}\n"
        . "DNI del Alumno: {$studentDni}\n"
        . "Curso: {$curso}\n\n"
        . "Documento aceptado:\n{$documentoVigente}\n\n"
        . "Versión del documento:\n{$contractVersion}\n\n"
        . "Fecha y hora de registro:\n{$acceptedAtUtc}\n\n"
        . "Dirección IP registrada:\n{$ip}\n\n"
        . "Huella SHA-256 (archivo PDF depositado):\n{$contractHash}\n"
        . "(Permite verificar integridad del PDF; no equivale a firma digital certificada.)\n\n"
        . "Declaración de conformidad\n\n"
        . "El responsable mencionado precedentemente declara haber leído y aceptado el Contrato de Servicios Educativos "
        . "correspondiente al ciclo lectivo 2027, así como también el Reglamento Interno Institucional.\n\n"
        . "Partes intervinientes\n\n"
        . "• RESPONSABLE:\n"
        . "Sr. {$declarantName}, DNI Nº {$declarantDni}, con domicilio real en {$declarantDomicilio}, localidad de {$declarantLocalidad}, en carácter de responsable legal del alumno/a "
        . mb_strtoupper($studentName, 'UTF-8') . ".\n\n"
        . "• DIRECTIVO DEL ESTABLECIMIENTO EDUCATIVO:\n"
        . "{$instFragmento}.\n\n"
        . "Contrato y documentación:\n\n"
        . "Documentación:\n\n"
        . "• {$contratoLinkTexto} — {$signedPdfUrl}\n"
        . "• {$reglamentoLabel} — {$reglamentoUrl}\n\n"
        . "Este comprobante constituye constancia digital de aceptación registrada en el sistema institucional.\n";

    return [
        'subject' => 'LA FIRMA DEL CONTRATO SE REGISTRÓ CORRECTAMENTE EN NUESTRA BASE DE DATOS.',
        'html' => contrato_email_html_wrapper($inner),
        'text' => $text,
    ];
}

/**
 * Cuota futura según configuración vigente (misma lógica que home.php / contratos.php).
 */
function contrato_es_cuota_futura_status(int $numCuota, int $cuotaVigente, int $mesActual, string $curso = ''): bool
{
    return cuota_es_futura_para_curso($numCuota, $cuotaVigente, $mesActual, $curso);
}

function contrato_curso_es_su(string $curso): bool
{
    $curso = mb_strtoupper(trim($curso), 'UTF-8');

    return $curso !== '' && mb_substr($curso, -2, 2, 'UTF-8') === 'SU';
}

/**
 * @return array{aplica:bool,cumplido:bool}
 */
function contrato_cuota_rv_estado(mysqli $conn, string $legajo, string $curso, int $numeroCuota): array
{
    if (contrato_curso_es_su($curso)) {
        return ['aplica' => false, 'cumplido' => true, 'disponible' => false];
    }
    if ($legajo === '') {
        return ['aplica' => true, 'cumplido' => false, 'disponible' => false];
    }

    $stmt = $conn->prepare(
        'SELECT COALESCE(diferencia, 0) AS diferencia, COALESCE(monto_facturado, 0) AS monto_facturado
         FROM cuotas WHERE nro_legajo = ? AND numero_cuota = ? LIMIT 1'
    );
    if (!$stmt) {
        return ['aplica' => true, 'cumplido' => false, 'disponible' => false];
    }
    $stmt->bind_param('si', $legajo, $numeroCuota);
    $stmt->execute();
    $rows = fetchAllFromStmt($stmt);
    $stmt->close();

    if (empty($rows)) {
        return ['aplica' => true, 'cumplido' => false, 'disponible' => false];
    }

    $montoFacturado = (float)($rows[0]['monto_facturado'] ?? 0);
    $diferencia = (float)($rows[0]['diferencia'] ?? 0);
    $disponible = $montoFacturado > 0.01;

    return [
        'aplica' => true,
        'cumplido' => $disponible && $diferencia <= 0.01,
        'disponible' => $disponible,
    ];
}

function contrato_adelanto_rv_estado(mysqli $conn, string $legajo, string $curso): array
{
    return contrato_cuota_rv_estado($conn, $legajo, $curso, 10);
}

function contrato_resto_rv_estado(mysqli $conn, string $legajo, string $curso): array
{
    if (curso_es_ingresante_externo_2027($curso)) {
        return ['aplica' => false, 'cumplido' => true, 'disponible' => false];
    }

    return contrato_cuota_rv_estado($conn, $legajo, $curso, 11);
}

function contrato_numero_cuota_noviembre(string $curso): int
{
    return contrato_curso_es_su($curso) ? 10 : 9;
}

/**
 * Habilitación de firma: noviembre (ciclo regular) o cuota 10 Adelanto RV 2027 (NUI).
 */
function contrato_alumno_puede_firmar(mysqli $conn, string $legajo, string $curso): bool
{
    if (curso_es_ingresante_externo_2027($curso)) {
        $adelanto = contrato_adelanto_rv_estado($conn, $legajo, $curso);

        return !empty($adelanto['cumplido']);
    }

    return contrato_alumno_noviembre_abonado($conn, $legajo, $curso);
}

/**
 * Noviembre abonado para un alumno (cuota 9, o 10 en cursos SU): requiere fila en cuotas con diferencia saldada.
 */
function contrato_alumno_noviembre_abonado(mysqli $conn, string $legajo, string $curso): bool
{
    $legajo = trim($legajo);
    if ($legajo === '') {
        return false;
    }

    $numNoviembre = contrato_numero_cuota_noviembre($curso);
    $stmt = $conn->prepare(
        'SELECT COALESCE(diferencia, 0) AS diferencia FROM cuotas WHERE nro_legajo = ? AND numero_cuota = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('si', $legajo, $numNoviembre);
    $stmt->execute();
    $rows = fetchAllFromStmt($stmt);
    $stmt->close();

    return $rows !== [] && (float)($rows[0]['diferencia'] ?? 0) <= 0.01;
}

/**
 * @param array<string,string> $legajosConCurso legajo => curso
 */
function contrato_familia_noviembre_abonado(mysqli $conn, array $legajosConCurso): bool
{
    if ($legajosConCurso === []) {
        return false;
    }

    foreach ($legajosConCurso as $legajo => $curso) {
        if (!contrato_alumno_puede_firmar($conn, (string)$legajo, (string)$curso)) {
            return false;
        }
    }

    return true;
}

/** Mensaje único para el bloqueo de firma sin noviembre abonado. */
function contrato_msg_firma_bloqueada_noviembre(): string
{
    return 'La firma del contrato se habilita únicamente una vez abonada la cuota de NOVIEMBRE. '
        . 'Aún no registramos el pago de noviembre para este alumno.';
}

function contrato_msg_firma_bloqueada_adelanto_rv(): string
{
    return 'La firma del contrato se habilita únicamente una vez abonada la CUOTA-10 '
        . ingresante_externo_2027_nombre_cuota()
        . '. Aún no registramos ese pago para este alumno.';
}

function contrato_msg_firma_bloqueada(string $curso): string
{
    if (curso_es_ingresante_externo_2027($curso)) {
        return contrato_msg_firma_bloqueada_adelanto_rv();
    }

    return contrato_msg_firma_bloqueada_noviembre();
}

/**
 * @param array<string,string> $legajosConCurso legajo => curso
 *
 * Para evaluar "sin deudas 2026":
 * - regulares: noviembre abonado
 * - NUI (nuevos ingresantes 2027): CUOTA-11 Resto RV abonada
 */
function contrato_familia_puede_evaluar_sin_deuda(mysqli $conn, array $legajosConCurso): bool
{
    if ($legajosConCurso === []) {
        return false;
    }

    foreach ($legajosConCurso as $legajo => $curso) {
        $legajo = (string)$legajo;
        $curso = (string)$curso;
        if ($legajo === '') {
            return false;
        }
        if (curso_es_ingresante_externo_2027($curso)) {
            $resto = contrato_cuota_rv_estado($conn, $legajo, $curso, 11);
            if (empty($resto['cumplido'])) {
                return false;
            }
            continue;
        }
        if (!contrato_alumno_noviembre_abonado($conn, $legajo, $curso)) {
            return false;
        }
    }

    return true;
}

/**
 * Deuda del ciclo 2026 (marzo–noviembre / cuotas 1–9). No incluye RV (10–12).
 *
 * @return array<string,bool> legajo => tiene deuda ciclo 2026
 */
function contrato_deuda_ciclo_2026_por_legajos(
    mysqli $conn,
    array $legajos,
    int $cuotaVigente,
    int $mesActual,
    array $cursoPorLegajo = []
): array {
    $resultado = [];
    foreach ($legajos as $legajo) {
        $legajo = (string)$legajo;
        if ($legajo === '') {
            continue;
        }
        $curso = (string)($cursoPorLegajo[$legajo] ?? '');

        if (curso_es_ingresante_externo_2027($curso)) {
            $resultado[$legajo] = false;
            continue;
        }

        $stmt = $conn->prepare(
            'SELECT numero_cuota, diferencia FROM cuotas WHERE nro_legajo = ? AND diferencia > 0'
        );
        if (!$stmt) {
            $resultado[$legajo] = false;
            continue;
        }
        $stmt->bind_param('s', $legajo);
        $stmt->execute();
        $rows = fetchAllFromStmt($stmt);
        $stmt->close();

        $saldo = 0.0;
        foreach ($rows as $row) {
            $numCuota = (int)($row['numero_cuota'] ?? 0);
            if ($numCuota < 1 || $numCuota > 9) {
                continue;
            }
            if (!contrato_es_cuota_futura_status($numCuota, $cuotaVigente, $mesActual, $curso)) {
                $saldo += (float)($row['diferencia'] ?? 0);
            }
        }
        $resultado[$legajo] = round($saldo, 2) > 0.01;
    }

    return $resultado;
}

/**
 * @param array<string,string> $legajosConCurso legajo => curso
 *
 * @return array{aplica:bool,cumplido:bool,disponible:bool}
 */
function contrato_sin_deudas_familia_estado(
    mysqli $conn,
    array $legajosConCurso,
    int $cuotaVigente,
    int $mesActual
): array {
    if ($legajosConCurso === []) {
        return ['aplica' => true, 'cumplido' => false, 'disponible' => false];
    }

    $puedeEvaluar = contrato_familia_puede_evaluar_sin_deuda($conn, $legajosConCurso);
    if (!$puedeEvaluar) {
        return ['aplica' => true, 'cumplido' => false, 'disponible' => false];
    }

    $deudaPorLegajo = contrato_deuda_ciclo_2026_por_legajos(
        $conn,
        array_keys($legajosConCurso),
        $cuotaVigente,
        $mesActual,
        $legajosConCurso
    );
    foreach ($deudaPorLegajo as $tieneDeuda) {
        if ($tieneDeuda) {
            return ['aplica' => true, 'cumplido' => false, 'disponible' => true];
        }
    }

    return ['aplica' => true, 'cumplido' => true, 'disponible' => true];
}

/**
 * @return array<string,bool> legajo => tiene deuda vigente
 */
function contrato_deuda_vigente_por_legajos(
    mysqli $conn,
    array $legajos,
    int $cuotaVigente,
    int $mesActual,
    array $cursoPorLegajo = []
): array {
    $resultado = [];
    foreach ($legajos as $legajo) {
        $legajo = (string)$legajo;
        if ($legajo === '') {
            continue;
        }
        $curso = (string)($cursoPorLegajo[$legajo] ?? '');

        $stmt = $conn->prepare(
            'SELECT numero_cuota, diferencia FROM cuotas WHERE nro_legajo = ? AND diferencia > 0'
        );
        if (!$stmt) {
            $resultado[$legajo] = false;
            continue;
        }
        $stmt->bind_param('s', $legajo);
        $stmt->execute();
        $rows = fetchAllFromStmt($stmt);
        $stmt->close();

        $saldo = 0.0;
        foreach ($rows as $row) {
            $numCuota = (int)($row['numero_cuota'] ?? 0);
            if (!contrato_es_cuota_futura_status($numCuota, $cuotaVigente, $mesActual, $curso)) {
                $saldo += (float)($row['diferencia'] ?? 0);
            }
        }
        $resultado[$legajo] = round($saldo, 2) > 0.01;
    }

    return $resultado;
}

/**
 * Requisitos para validez del contrato 2027 (vista familiar).
 *
 * @return array{
 *   firma:bool,
 *   documentacion:bool,
 *   adelanto_rv:array{aplica:bool,cumplido:bool,disponible?:bool},
 *   resto_rv:array{aplica:bool,cumplido:bool,disponible?:bool},
 *   sin_deudas_familia:array{aplica:bool,cumplido:bool,disponible?:bool},
 *   todos_cumplidos:bool
 * }
 */
function contrato_calcular_requisitos(
    bool $signed,
    bool $adminAprobado,
    array $adelantoRv,
    array $restoRv,
    array $sinDeudasFamilia
): array {
    $firma = $signed;
    $documentacion = $adminAprobado;
    $adelantoOk = !$adelantoRv['aplica'] || !empty($adelantoRv['cumplido']);
    $restoOk = !$restoRv['aplica'] || !empty($restoRv['cumplido']);
    $sinDeudasOk = !$sinDeudasFamilia['aplica'] || !empty($sinDeudasFamilia['cumplido']);
    $todos = $firma && $documentacion && $adelantoOk && $restoOk && $sinDeudasOk;

    return [
        'firma' => $firma,
        'documentacion' => $documentacion,
        'adelanto_rv' => $adelantoRv,
        'resto_rv' => $restoRv,
        'sin_deudas_familia' => $sinDeudasFamilia,
        'todos_cumplidos' => $todos,
    ];
}
