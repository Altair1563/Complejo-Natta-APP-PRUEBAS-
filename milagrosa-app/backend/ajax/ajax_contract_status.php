<?php
require_once __DIR__ . '/../../backend/bootstrap.php';
require_once __DIR__ . '/../lib/contract_institution.php';
header('Content-Type: application/json');

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

$conn = getDbConnection();
$nroFamilia = (string)($_SESSION['nro_familia'] ?? '');

$sqlStudents = "SELECT dni_alumno, nro_legajo, curso, 0 AS es_inactivo FROM legajos WHERE nro_familia = ?
                UNION
                SELECT dni_alumno, nro_legajo, curso, 1 AS es_inactivo FROM legajos_inactivos WHERE nro_familia = ?";
$stmtStudents = $conn->prepare($sqlStudents);
if (!$stmtStudents) {
    echo json_encode(['ok' => false, 'msg' => 'No se pudo obtener alumnos']);
    $conn->close();
    exit;
}
$stmtStudents->bind_param('ss', $nroFamilia, $nroFamilia);
$stmtStudents->execute();
$students = fetchAllFromStmt($stmtStudents);
$stmtStudents->close();

$statusByStudent = [];
foreach ($students as $student) {
    $dni = (string)($student['dni_alumno'] ?? '');
    $legajo = (string)($student['nro_legajo'] ?? '');
    if ($dni === '') {
        continue;
    }
    $statusByStudent[$dni] = [
        'student_dni' => $dni,
        'nro_legajo' => $legajo,
        'curso' => (string)($student['curso'] ?? ''),
        'signed' => false,
        'admin_aprobado' => false,
        'es_inactivo' => ((int)($student['es_inactivo'] ?? 0) === 1),
        'has_signed_history' => false,
        'contract_version' => '',
    ];
}

$acceptedByDni = [];
$sqlAccepted = "SELECT student_dni, contract_version, admin_aprobado
                FROM contratos_aceptados
                WHERE nro_familia = ? AND status = 'activo'";
$stmtAccepted = $conn->prepare($sqlAccepted);
if ($stmtAccepted) {
    $stmtAccepted->bind_param('s', $nroFamilia);
    $stmtAccepted->execute();
    $acceptedRows = fetchAllFromStmt($stmtAccepted);
    $stmtAccepted->close();
    $hasAdminCol = !empty($acceptedRows) && array_key_exists('admin_aprobado', $acceptedRows[0]);
    foreach ($acceptedRows as $row) {
        $dni = (string)($row['student_dni'] ?? '');
        if ($dni === '') {
            continue;
        }
        $acceptedByDni[$dni][] = [
            'contract_version' => (string)($row['contract_version'] ?? ''),
            'admin_aprobado' => $hasAdminCol ? ((int)($row['admin_aprobado'] ?? 0) === 1) : false,
        ];
    }
}

$sqlHistory = "SELECT DISTINCT student_dni
               FROM contratos_aceptados
               WHERE nro_familia = ?";
$stmtHistory = $conn->prepare($sqlHistory);
if ($stmtHistory) {
    $stmtHistory->bind_param('s', $nroFamilia);
    $stmtHistory->execute();
    $historyRows = fetchAllFromStmt($stmtHistory);
    $stmtHistory->close();
    foreach ($historyRows as $row) {
        $dni = (string)($row['student_dni'] ?? '');
        if (isset($statusByStudent[$dni])) {
            $statusByStudent[$dni]['has_signed_history'] = true;
        }
    }
}

$cuotaVigente = 1;
$stmtCfg = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'cuota_vigente'");
if ($stmtCfg) {
    $stmtCfg->execute();
    $stmtCfg->bind_result($cvCfg);
    if ($stmtCfg->fetch()) {
        $cuotaVigente = (int)$cvCfg;
    }
    $stmtCfg->close();
}
$mesActual = (int)date('n');

$legajosConCurso = [];
foreach ($statusByStudent as $stLeg) {
    $leg = (string)($stLeg['nro_legajo'] ?? '');
    if ($leg !== '') {
        $legajosConCurso[$leg] = (string)($stLeg['curso'] ?? '');
    }
}
$sinDeudasFamilia = contrato_sin_deudas_familia_estado(
    $conn,
    $legajosConCurso,
    $cuotaVigente,
    $mesActual
);

foreach ($statusByStudent as $dni => &$st) {
    $curso = (string)($st['curso'] ?? '');
    $codigoInst = contrato_codigo_institucion_desde_curso($curso);
    $contract = $codigoInst !== null ? contrato_fetch_vigente_por_codigo($conn, $codigoInst) : null;
    $expectedVersion = $contract !== null ? (string)$contract['contract_version'] : '';

    $st['contract_version'] = $expectedVersion;
    if ($codigoInst !== null) {
        $reglamentoInfo = contrato_reglamento_info_desde_codigo($codigoInst);
        $st['reglamento_pdf_url'] = contrato_reglamento_pdf_url_desde_codigo($codigoInst);
        $st['reglamento_link_label'] = $reglamentoInfo['modal_link_label'];
    } else {
        $st['reglamento_pdf_url'] = '';
        $st['reglamento_link_label'] = '';
    }

    if ($expectedVersion !== '' && isset($acceptedByDni[$dni])) {
        foreach ($acceptedByDni[$dni] as $acceptance) {
            if ($acceptance['contract_version'] === $expectedVersion) {
                $st['signed'] = true;
                $st['admin_aprobado'] = $acceptance['admin_aprobado'];
                break;
            }
        }
    }

    $adelantoRv = contrato_adelanto_rv_estado($conn, (string)($st['nro_legajo'] ?? ''), $curso);
    $restoRv = contrato_resto_rv_estado($conn, (string)($st['nro_legajo'] ?? ''), $curso);
    $st['requisitos'] = contrato_calcular_requisitos(
        !empty($st['signed']),
        !empty($st['admin_aprobado']),
        $adelantoRv,
        $restoRv,
        $sinDeudasFamilia
    );
    $st['es_ingresante_externo'] = curso_es_ingresante_externo_2027($curso);
    $st['firma_habilitada'] = contrato_alumno_puede_firmar(
        $conn,
        (string)($st['nro_legajo'] ?? ''),
        $curso
    );
    $st['noviembre_abonado'] = $st['firma_habilitada'];

    if ($st['es_inactivo'] && !$st['signed']) {
        $st['estado_flujo'] = 'inactivo_sin_firma';
    } elseif (!$st['signed'] && !$st['firma_habilitada']) {
        $st['estado_flujo'] = !empty($st['es_ingresante_externo'])
            ? 'firma_bloqueada_adelanto_rv'
            : 'firma_bloqueada_noviembre';
    } elseif ($expectedVersion === '') {
        $st['estado_flujo'] = 'pendiente_firma';
    } elseif (!$st['signed']) {
        $st['estado_flujo'] = 'pendiente_firma';
    } elseif (empty($st['requisitos']['todos_cumplidos'])) {
        $st['estado_flujo'] = 'pendiente_aprobacion';
    } else {
        $st['estado_flujo'] = 'aprobado';
    }
}
unset($st);

$conn->close();
echo json_encode([
    'ok' => true,
    'status' => array_values($statusByStudent),
]);
