<?php

require_once __DIR__ . '/contract_institution.php';

if (!function_exists('contrato_esc_html')) {
    function contrato_esc_html(?string $value): string
    {
        $s = (string)$value;
        if ($s === '') {
            return '';
        }
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        if (defined('ENT_HTML5')) {
            $flags |= ENT_HTML5;
        }

        return htmlspecialchars($s, $flags, 'UTF-8');
    }
}

if (!function_exists('contrato_inject_after_body_open')) {
    function contrato_inject_after_body_open(string $html, string $inject): string
    {
        $pos = stripos($html, '<body');
        if ($pos === false) {
            return $inject . $html;
        }
        $gt = strpos($html, '>', $pos);
        if ($gt === false) {
            return $inject . $html;
        }

        return substr($html, 0, $gt + 1) . $inject . substr($html, $gt + 1);
    }
}

/**
 * @return array<string, mixed>|null
 */
function contrato_fetch_signed_acceptance(
    mysqli $conn,
    string $studentDni,
    string $nroFamilia,
    string $contractVersion
): ?array {
    $extraCols = '';
    if (contrato_db_column_exists($conn, 'contratos_aceptados', 'signed_document_sha256')) {
        $extraCols .= ', signed_document_sha256';
    }
    if (contrato_db_column_exists($conn, 'contratos_aceptados', 'accepted_pdf_path')) {
        $extraCols .= ', accepted_pdf_path';
    }

    $queries = [
        "SELECT accepted_text, accepted_at_utc, ip_address, user_agent, contract_hash{$extraCols}
         FROM contratos_aceptados
         WHERE student_dni = ? AND nro_familia = ? AND contract_version = ? AND status = 'activo'
         LIMIT 1",
        "SELECT accepted_text, accepted_at_utc, ip_address, user_agent, contract_hash{$extraCols}
         FROM contratos_aceptados
         WHERE student_dni = ? AND nro_familia = ? AND contract_version = ?
         ORDER BY id DESC
         LIMIT 1",
    ];

    foreach ($queries as $sql) {
        try {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('sss', $studentDni, $nroFamilia, $contractVersion);
            $stmt->execute();
            $rows = fetchAllFromStmt($stmt);
            $stmt->close();
            if (!empty($rows)) {
                return $rows[0];
            }
        } catch (Throwable $e) {
            error_log('contrato_fetch_signed_acceptance: ' . $e->getMessage());
        }
    }

    return null;
}

if (!function_exists('fetchAllFromStmt')) {
    /**
     * Compatible con servidores sin mysqlnd (misma lógica que backend/bootstrap.php).
     */
    function fetchAllFromStmt(mysqli_stmt $stmt): array
    {
        $rows = [];
        if (method_exists($stmt, 'get_result')) {
            $res = $stmt->get_result();
            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = $r;
                }
            }
        } else {
            $stmt->store_result();
            $meta = $stmt->result_metadata();
            if (!$meta) {
                return $rows;
            }
            $fields = [];
            $row = [];
            while ($field = $meta->fetch_field()) {
                $fields[] = &$row[$field->name];
            }
            call_user_func_array([$stmt, 'bind_result'], $fields);
            while ($stmt->fetch()) {
                $r = [];
                foreach ($row as $k => $v) {
                    $r[$k] = $v;
                }
                $rows[] = $r;
            }
            unset($row);
        }

        return $rows;
    }
}

/**
 * Ruta del template HTML según contract_version (ej. Contrato_CI_2027_v1).
 */
function contrato_template_path(string $contractVersion): ?string
{
    $version = trim($contractVersion);
    if ($version === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $version)) {
        return null;
    }
    $path = __DIR__ . '/../../docs/contratos/' . $version . '.html';
    if (!is_file($path)) {
        error_log('contrato_template_path: no existe «' . $path . '» (versión «' . $version . '»)');

        return null;
    }

    return realpath($path) ?: $path;
}

/**
 * Extrae datos del firmante desde accepted_text guardado al firmar.
 *
 * @return array{nombre:string,dni:string,domicilio:string,localidad:string,alumno_nombre:string,alumno_dni:string,curso:string}|null
 */
function contrato_parse_accepted_text(?string $acceptedText): ?array
{
    $text = trim((string)$acceptedText);
    if ($text === '') {
        return null;
    }

    $out = [
        'nombre' => '',
        'dni' => '',
        'domicilio' => '',
        'localidad' => '',
        'alumno_nombre' => '',
        'alumno_dni' => '',
        'curso' => '',
    ];

    if (preg_match(
        '/Entre el Sr\.\s+(.+?)\s+con DNI N°\s+(\d+)\s*,\s*con domicilio real en\s+(.+?)\s+localidad de\s+([^,]+)/u',
        $text,
        $m
    )) {
        $out['nombre'] = trim($m[1]);
        $out['dni'] = trim($m[2]);
        $out['domicilio'] = trim($m[3]);
        $out['localidad'] = trim($m[4]);
    }

    if (preg_match('/Alumno:\s+(.+?)\s*\(DNI\s+(\d+)\s*,\s*curso\s+([^)]+)\)/u', $text, $m)) {
        $out['alumno_nombre'] = trim($m[1]);
        $out['alumno_dni'] = trim($m[2]);
        $out['curso'] = trim($m[3]);
    }

    if ($out['nombre'] === '' && $out['alumno_nombre'] === '') {
        return null;
    }

    return $out;
}

/**
 * @return array<string, string>
 */
function contrato_document_placeholders(array $data): array
{
    $nombre = (string)($data['nombre_responsable'] ?? '');
    $fecha = (string)($data['fecha_aceptacion'] ?? '');
    $fechaCorta = (string)($data['fecha_aceptacion_corta'] ?? '');
    if ($fechaCorta === '' && $fecha !== '') {
        $fechaCorta = preg_replace('/\s+H:i.*$/', '', $fecha);
    }
    if ($fechaCorta === '') {
        $fechaCorta = date('d/m/Y');
    }

    $hashDisplay = trim((string)($data['codigo_verificacion'] ?? ''));
    if ($hashDisplay === '') {
        $hashDisplay = '[Pendiente — se generará al registrar la aceptación sobre el PDF depositado]';
    }

    $userAgent = trim((string)($data['user_agent'] ?? ''));
    if ($userAgent === '') {
        $userAgent = '[No registrado]';
    }

    return [
        '{{LOGOS_HEADER}}' => (string)($data['logos_header_html'] ?? ''),
        '{{NOMBRE_RESPONSABLE}}' => contrato_esc_html($nombre !== '' ? $nombre : '[NOMBRE COMPLETO DEL RESPONSABLE]'),
        '{{DNI_RESPONSABLE}}' => contrato_esc_html((string)($data['dni_responsable'] ?? '[DNI]')),
        '{{DOMICILIO}}' => contrato_esc_html((string)($data['domicilio'] ?? '[CALLE Y NÚMERO]')),
        '{{LOCALIDAD}}' => contrato_esc_html((string)($data['localidad'] ?? '[LOCALIDAD]')),
        '{{EMAIL_RESPONSABLE}}' => contrato_esc_html((string)($data['email_responsable'] ?? '[EMAIL DEL RESPONSABLE]')),
        '{{ALUMNO_NOMBRE}}' => contrato_esc_html((string)($data['alumno_nombre'] ?? '')),
        '{{ALUMNO_DNI}}' => contrato_esc_html((string)($data['alumno_dni'] ?? '')),
        '{{CURSO}}' => contrato_esc_html((string)($data['curso'] ?? '')),
        '{{CODIGO_VERIFICACION}}' => contrato_esc_html($hashDisplay),
        '{{USER_AGENT}}' => contrato_esc_html($userAgent),
        '{{VERSION_DOCUMENTO}}' => contrato_esc_html((string)($data['version_documento'] ?? '')),
        '{{FRAGMENTO_INSTITUCION}}' => contrato_esc_html((string)($data['fragmento_institucion'] ?? '')),
        '{{FECHA_ACEPTACION}}' => contrato_esc_html($fecha !== '' ? $fecha : date('d/m/Y')),
        '{{FECHA_ACEPTACION_CORTA}}' => contrato_esc_html($fechaCorta),
        '{{IP_REGISTRO}}' => contrato_esc_html((string)($data['ip_registro'] ?? '[Registrada por el sistema]')),
        '{{NOMBRE_INSTITUCION}}' => contrato_esc_html((string)($data['nombre_institucion'] ?? 'Instituto educativo')),
    ];
}

/**
 * Nombre legible de la institución para textos de aceptación digital.
 */
function contrato_nombre_institucion_display(string $codigoInst, array $instRow = []): string
{
    if (!function_exists('tenant_escuelas_catalog')) {
        require_once __DIR__ . '/../../config/tenant_helpers.php';
    }
    $labels = tenant_escuelas_catalog();

    $codigo = mb_strtoupper(trim($codigoInst), 'UTF-8');
    if (isset($labels[$codigo])) {
        return $labels[$codigo];
    }

    $inst = trim((string)($instRow['institucion'] ?? ''));
    $inst = preg_replace('/^del\s+/iu', '', $inst);

    return $inst !== '' ? $inst : (function_exists('tenant_name') ? tenant_name() : 'Establecimiento educativo');
}

function contrato_format_fecha_aceptacion_corta(?string $acceptedAtUtc): string
{
    $raw = trim((string)$acceptedAtUtc);
    if ($raw === '') {
        return date('d/m/Y');
    }
    try {
        $dt = new DateTime($raw, new DateTimeZone('UTC'));
        $tz = @timezone_open('America/Argentina/Buenos_Aires');
        $dt->setTimezone($tz instanceof DateTimeZone ? $tz : new DateTimeZone('-03:00'));

        return $dt->format('d/m/Y');
    } catch (Exception $e) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            $parts = explode(' ', $raw);
            $ymd = explode('-', $parts[0]);

            return ($ymd[2] ?? '') . '/' . ($ymd[1] ?? '') . '/' . ($ymd[0] ?? '');
        }

        return $raw;
    }
}

/**
 * Bloque técnico/legal final (aceptación digital e integridad). No altera cláusulas del contrato.
 */
function contrato_plantilla_bloque_aceptacion_digital(): string
{
    return <<<'HTML'
    <div class="digital-acceptance-section no-break">
        <h3>CONSTANCIA DE ACEPTACIÓN DIGITAL</h3>
        <p>El/la responsable <span class="bold">{{NOMBRE_RESPONSABLE}}</span>, DNI <span class="bold">{{DNI_RESPONSABLE}}</span>, con domicilio electrónico <span class="bold">{{EMAIL_RESPONSABLE}}</span>, manifiesta haber leído y aceptado expresamente el <strong>Contrato de Servicio Educativo 2027</strong> y el <strong>Reglamento Institucional vigente</strong> del <span class="bold">{{NOMBRE_INSTITUCION}}</span>, mediante la plataforma digital del establecimiento, con reingreso de credenciales y aceptación obligatoria, quedando registrada su manifestación de voluntad.</p>
        <p>Se deja constancia de que la aceptación fue registrada electrónicamente. El establecimiento generó y resguardó un archivo PDF íntegro con los datos del firmante, la versión del documento, la fecha y hora de aceptación y la presente constancia. Sobre dicho archivo se calculó una huella criptográfica SHA-256, útil para verificar su integridad y detectar modificaciones posteriores.</p>
        <p>Esta constancia se emite como evidencia electrónica de aceptación, conforme a la Ley 25.506, el Código Civil y Comercial de la Nación y normas complementarias aplicables.</p>

        <p class="digital-acceptance-meta">
            <span class="bold">Alumno/a:</span> {{ALUMNO_NOMBRE}} &nbsp;| DNI: {{ALUMNO_DNI}} &nbsp;| Curso: {{CURSO}}<br>
            <span class="bold">Huella SHA-256 del PDF:</span> <span class="verification-code">{{CODIGO_VERIFICACION}}</span><br>
            <span class="bold">Versión del documento:</span> {{VERSION_DOCUMENTO}}<br>
            <span class="bold">Fecha y hora de aceptación:</span> {{FECHA_ACEPTACION}}<br>
            <span class="bold">Dirección IP registrada:</span> {{IP_REGISTRO}}<br>
            <span class="bold">Navegador / dispositivo:</span> {{USER_AGENT}}
        </p>
    </div>
HTML;
}

/**
 * Renderiza el HTML del contrato con los datos de la familia/alumno.
 */
function contrato_render_html_template(string $contractVersion, array $data): ?string
{
    $path = contrato_template_path($contractVersion);
    if ($path === null) {
        return null;
    }

    $html = (string)file_get_contents($path);
    if ($html === '') {
        return null;
    }

    $data['logos_header_html'] = contrato_html_logos_header($data);
    $placeholders = contrato_document_placeholders($data);
    $placeholders['{{BLOQUE_ACEPTACION_DIGITAL}}'] = contrato_plantilla_bloque_aceptacion_digital();

    $html = str_replace(array_keys($placeholders), array_values($placeholders), $html);

    return contrato_apply_legacy_digital_acceptance_block($html, $placeholders);
}

/**
 * Sustituye bloques antiguos embebidos en plantillas que aún no usan {{BLOQUE_ACEPTACION_DIGITAL}}.
 *
 * @param array<string, string> $placeholders
 */
function contrato_apply_legacy_digital_acceptance_block(string $html, array $placeholders): string
{
    if (strpos($html, '{{BLOQUE_ACEPTACION_DIGITAL}}') !== false) {
        return $html;
    }

    $block = str_replace(array_keys($placeholders), array_values($placeholders), contrato_plantilla_bloque_aceptacion_digital());
    $pattern = '/<div class="digital-acceptance-section[^>]*>[\s\S]*?<\/div>\s*(?=<\/div>\s*<\/body>)/i';

    return preg_replace($pattern, $block, $html, 1) ?? $html;
}

function contrato_escuela_codigo_desde_curso(?string $curso): string
{
    $c = mb_strtoupper(trim((string)$curso), 'UTF-8');
    if (mb_strlen($c, 'UTF-8') < 2) {
        return '';
    }

    return mb_substr($c, -2, 2, 'UTF-8');
}

function contrato_assets_img_base_url(): string
{
    return contrato_public_app_root_url() . '/assets/img/';
}

/**
 * Texto institucional del encabezado del contrato según código de institución.
 *
 * @return list<string>
 */
function contrato_encabezado_institucion_lineas(string $codigo): array
{
    if (!function_exists('tenant_escuelas_catalog')) {
        require_once __DIR__ . '/../../config/tenant_helpers.php';
    }
    $nombreTenant = tenant_name();
    $escuelas = tenant_escuelas_catalog();
    $codigo = mb_strtoupper(trim($codigo), 'UTF-8');
    $sala = $escuelas[$codigo] ?? '';

    $lineas = [
        $nombreTenant,
        'Instituto educativo',
    ];
    if ($sala !== '') {
        $lineas[] = $sala . ' (' . $codigo . ')';
    }
    $lineas[] = 'Buenos Aires - Argentina';

    return $lineas;
}

/**
 * Bloque de logo (Jardin de Infantes La Milagrosa) con URL absoluta para impresión y vista web.
 */
function contrato_html_logos_header(array $data): string
{
    $base = contrato_assets_img_base_url();
    $codigo = trim((string)($data['codigo_institucion'] ?? ''));
    if ($codigo === '') {
        $codigo = contrato_escuela_codigo_desde_curso((string)($data['curso'] ?? ''));
    }
    $codigo = mb_strtoupper($codigo, 'UTF-8');

    $logoSrc = $base . 'LogoMilagrosa.png';

    $lineas = contrato_encabezado_institucion_lineas($codigo);
    $lineasHtml = '';
    foreach ($lineas as $i => $linea) {
        if ($i > 0) {
            $lineasHtml .= '<br />';
        }
        $lineasHtml .= htmlspecialchars($linea, ENT_QUOTES, 'UTF-8');
    }
    $infoHtml = '<div class="contract-doc-logos__info">' . $lineasHtml . '</div>';

    return '<div class="contract-doc-logos">'
        . '<div class="contract-doc-logos__images">'
        . '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8')
        . '" alt="Jardin de Infantes La Milagrosa" class="contract-doc-logo contract-doc-logo--natta" />'
        . '</div>'
        . $infoHtml
        . '</div>';
}

/**
 * Arma los datos del documento para un alumno del grupo familiar.
 *
 * @param array{
 *   declarant_name?:string,
 *   declarant_dni?:string,
 *   declarant_domicilio?:string,
 *   declarant_localidad?:string,
 *   modo?:string
 * } $opts
 * @return array<string, mixed>|null
 */
function contrato_build_document_data(mysqli $conn, string $nroFamilia, string $studentDni, array $opts = []): ?array
{
    try {
        $studentDni = trim($studentDni);
        $nroFamilia = trim($nroFamilia);
        if ($studentDni === '' || $nroFamilia === '') {
            return null;
        }

        $rows = [];
        $sqlStudent = "SELECT nombre_alumno, apellido_alumno, dni_alumno, curso, nro_legajo, 0 AS es_inactivo
                       FROM legajos WHERE nro_familia = ? AND dni_alumno = ?
                       UNION
                       SELECT nombre_alumno, apellido_alumno, dni_alumno, curso, nro_legajo, 1 AS es_inactivo
                       FROM legajos_inactivos WHERE nro_familia = ? AND dni_alumno = ?
                       LIMIT 1";
        try {
            $stmtStudent = $conn->prepare($sqlStudent);
            if ($stmtStudent) {
                $stmtStudent->bind_param('ssss', $nroFamilia, $studentDni, $nroFamilia, $studentDni);
                $stmtStudent->execute();
                $rows = fetchAllFromStmt($stmtStudent);
                $stmtStudent->close();
            }
        } catch (Throwable $e) {
            error_log('contrato_build_document_data legajos: ' . $e->getMessage());
            $sqlFallback = 'SELECT nombre_alumno, apellido_alumno, dni_alumno, curso, nro_legajo
                            FROM legajos WHERE nro_familia = ? AND dni_alumno = ? LIMIT 1';
            $stmtFb = $conn->prepare($sqlFallback);
            if ($stmtFb) {
                $stmtFb->bind_param('ss', $nroFamilia, $studentDni);
                $stmtFb->execute();
                $rows = fetchAllFromStmt($stmtFb);
                $stmtFb->close();
            }
        }

        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        $alumnoNombre = trim(((string)($row['nombre_alumno'] ?? '')) . ' ' . ((string)($row['apellido_alumno'] ?? '')));
        $alumnoDni = trim((string)($row['dni_alumno'] ?? $studentDni));
        $curso = trim((string)($row['curso'] ?? ''));

        $codigoInst = contrato_codigo_institucion_desde_curso($curso);
        if ($codigoInst === null) {
            return null;
        }

        $contract = contrato_fetch_vigente_por_codigo($conn, $codigoInst);
        if ($contract === null) {
            return null;
        }

        $instRow = contrato_fetch_institucion_por_codigo($conn, $codigoInst);
        if ($instRow === null) {
            return null;
        }

        $contractVersion = (string)$contract['contract_version'];
        $contractHash = '';

        $declarantName = trim((string)($opts['declarant_name'] ?? ''));
        $declarantDni = preg_replace('/\D+/', '', (string)($opts['declarant_dni'] ?? ''));
        $declarantDomicilio = trim((string)($opts['declarant_domicilio'] ?? ''));
        $declarantLocalidad = trim((string)($opts['declarant_localidad'] ?? ''));
        $email = trim((string)($opts['email_responsable'] ?? ''));
        $fechaAceptacion = '';
        $fechaAceptacionCorta = '';
        $ipRegistro = '[Registrada por el sistema]';
        $modo = trim((string)($opts['modo'] ?? ''));

        $userAgent = '';
        $acceptedPdfPath = '';
        $signedRow = null;

        if ($modo === 'firmado' || ($declarantName === '' && $declarantDni === '')) {
            $signedRow = contrato_fetch_signed_acceptance($conn, $studentDni, $nroFamilia, $contractVersion);
            if ($signedRow !== null) {
                $storedHash = trim((string)($signedRow['signed_document_sha256'] ?? ''));
                if ($storedHash === '') {
                    $storedHash = trim((string)($signedRow['contract_hash'] ?? ''));
                }
                if ($storedHash !== '') {
                    $contractHash = $storedHash;
                }
                $uaFirmado = trim((string)($signedRow['user_agent'] ?? ''));
                if ($uaFirmado !== '') {
                    $userAgent = $uaFirmado;
                }
                $acceptedPdfPath = trim((string)($signedRow['accepted_pdf_path'] ?? ''));
                $ipFirmado = trim((string)($signedRow['ip_address'] ?? ''));
                if ($ipFirmado !== '') {
                    $ipRegistro = $ipFirmado;
                }
                $parsed = contrato_parse_accepted_text((string)($signedRow['accepted_text'] ?? ''));
                if ($parsed !== null) {
                    if ($declarantName === '') {
                        $declarantName = $parsed['nombre'];
                    }
                    if ($declarantDni === '') {
                        $declarantDni = $parsed['dni'];
                    }
                    if ($declarantDomicilio === '') {
                        $declarantDomicilio = $parsed['domicilio'];
                    }
                    if ($declarantLocalidad === '') {
                        $declarantLocalidad = $parsed['localidad'];
                    }
                    if ($alumnoNombre === '') {
                        $alumnoNombre = $parsed['alumno_nombre'];
                    }
                }
                $acceptedUtc = (string)($signedRow['accepted_at_utc'] ?? '');
                if ($acceptedUtc !== '') {
                    $fechaAceptacion = contrato_format_fecha_aceptacion($acceptedUtc);
                    $fechaAceptacionCorta = contrato_format_fecha_aceptacion_corta($acceptedUtc);
                }
            }
        }

        if ($fechaAceptacionCorta === '') {
            $fechaAceptacionCorta = date('d/m/Y');
        }

        return [
            'contract_version' => $contractVersion,
            'nombre_responsable' => $declarantName,
            'dni_responsable' => $declarantDni,
            'domicilio' => $declarantDomicilio,
            'localidad' => $declarantLocalidad,
            'email_responsable' => $email,
            'alumno_nombre' => $alumnoNombre,
            'alumno_dni' => $alumnoDni,
            'curso' => $curso,
            'codigo_verificacion' => $contractHash,
            'version_documento' => $contractVersion,
            'fragmento_institucion' => contrato_fragmento_institucion_texto($instRow),
            'fecha_aceptacion' => $fechaAceptacion,
            'fecha_aceptacion_corta' => $fechaAceptacionCorta,
            'ip_registro' => $ipRegistro,
            'user_agent' => $userAgent,
            'nombre_institucion' => contrato_nombre_institucion_display($codigoInst, $instRow),
            'codigo_institucion' => $codigoInst,
            'accepted_pdf_path' => $acceptedPdfPath,
        ];
    } catch (Throwable $e) {
        error_log('contrato_build_document_data: ' . $e->getMessage());

        return null;
    }
}

function contrato_format_fecha_aceptacion(?string $acceptedAtUtc): string
{
    $raw = trim((string)$acceptedAtUtc);
    if ($raw === '') {
        return date('d/m/Y H:i') . ' hs';
    }
    try {
        $dt = new DateTime($raw, new DateTimeZone('UTC'));
        $tz = @timezone_open('America/Argentina/Buenos_Aires');
        $dt->setTimezone($tz instanceof DateTimeZone ? $tz : new DateTimeZone('-03:00'));

        return $dt->format('d/m/Y H:i') . ' hs';
    } catch (Exception $e) {
        return $raw;
    }
}

/**
 * URL pública del contrato personalizado (requiere sesión del responsable).
 *
 * @param array<string, string> $params student_dni, declarant_*, etc.
 */
function contrato_documento_url_para_familia(array $params): string
{
    unset($params);

    return contrato_public_app_root_url() . '/contratos.php';
}
