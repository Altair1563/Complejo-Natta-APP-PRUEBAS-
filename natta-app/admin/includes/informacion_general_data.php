<?php
/**
 * Carga de datos para el panel Información general (admin).
 */

require_once __DIR__ . '/cuotas_admin_lib.php';

function admin_ig_fetch_all_from_stmt(mysqli_stmt $stmt): array
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
        if ($meta) {
            $fields = [];
            $row = [];
            while ($field = $meta->fetch_field()) {
                $fields[] = &$row[$field->name];
            }
            call_user_func_array([$stmt, 'bind_result'], $fields);
            while ($stmt->fetch()) {
                $temp = [];
                foreach ($row as $key => $val) {
                    $temp[$key] = $val;
                }
                $rows[] = $temp;
            }
        }
    }
    return $rows;
}

function norm_familia($n) {
    return trim((string)$n);
}

function admin_ig_nombreMesCuota($n) {
    return admin_cuota_nombre_mes($n);
}

function fmt_money_ar($n) {
    return number_format((float)$n, 2, ',', '.');
}

/**
 * Cupo de becas equivalentes al 100%: 10% de la matrícula, redondeo siempre hacia arriba.
 * Si el total es múltiplo de 10, el 10% es entero (p. ej. 300 → 30) y se suma 1 beca (→ 31).
 * Si no, se usa ceil(10% × N) (p. ej. 301 → ceil(30,1) = 31).
 */
function cupo_becas_al_100_redondeo_arriba(int $nAlumnos): float {
    if ($nAlumnos <= 0) {
        return 0.0;
    }
    if ($nAlumnos % 10 === 0) {
        return (float)($nAlumnos / 10 + 1);
    }
    return (float)ceil($nAlumnos * 0.10);
}

/** Formato para equivalentes de beca en tablas (coma decimal, hasta 4 decimales sin ceros finales). */
function fmt_equiv_beca_listado(float $x): string {
    $s = number_format($x, 4, ',', '.');
    if (strpos($s, ',') !== false) {
        $s = rtrim(rtrim($s, '0'), ',');
    }
    return $s === '' ? '0' : $s;
}

/**
 * Carga legajos + cuotas desde legajos o legajos_inactivos.
 *
 * @return array<string, array<string, mixed>>
 */
function admin_ig_cargar_legajos_cuotas_desde_tabla(mysqli $conn, string $tablaLegajos): array
{
    $permitidas = ['legajos', 'legajos_inactivos'];
    if (!in_array($tablaLegajos, $permitidas, true)) {
        throw new InvalidArgumentException('Tabla de legajos no permitida');
    }

    $sqlLegCuotas = "
        SELECT
            l.nro_familia,
            l.nro_legajo,
            l.curso,
            l.dni_alumno,
            l.porcentaje_descuento,
            c.numero_cuota,
            COALESCE(c.diferencia, 0) AS diferencia,
            COALESCE(c.monto_facturado, 0) AS monto_facturado,
            COALESCE(c.monto_ingresado, 0) AS monto_ingresado
        FROM {$tablaLegajos} l
        LEFT JOIN cuotas c
            ON c.nro_legajo COLLATE utf8mb4_unicode_ci = l.nro_legajo COLLATE utf8mb4_unicode_ci
           AND c.numero_cuota <= 12
        ORDER BY l.nro_legajo, c.numero_cuota
    ";

    $stmtLc = $conn->prepare($sqlLegCuotas);
    if (!$stmtLc) {
        error_log('informacion_general prepare legajos/cuotas (' . $tablaLegajos . '): ' . $conn->error);
        throw new RuntimeException('Error al preparar datos. Intente más tarde.');
    }
    try {
        $stmtLc->execute();
    } catch (mysqli_sql_exception $e) {
        error_log('informacion_general legajos/cuotas (' . $tablaLegajos . '): ' . $e->getMessage());
        throw new RuntimeException($e->getMessage());
    }
    $filasLc = admin_ig_fetch_all_from_stmt($stmtLc);
    $stmtLc->close();

    $legajosData = [];
    foreach ($filasLc as $row) {
        $leg = (string)($row['nro_legajo'] ?? '');
        if ($leg === '') {
            continue;
        }
        if (!isset($legajosData[$leg])) {
            $legajosData[$leg] = [
                'nro_familia' => $row['nro_familia'],
                'nro_legajo' => $leg,
                'curso' => $row['curso'],
                'dni_alumno' => $row['dni_alumno'],
                'porcentaje_descuento' => $row['porcentaje_descuento'],
                'cuotas' => [],
            ];
        }
        if ($row['numero_cuota'] !== null && $row['numero_cuota'] !== '') {
            $legajosData[$leg]['cuotas'][] = [
                'numero_cuota' => (int)$row['numero_cuota'],
                'diferencia' => (float)$row['diferencia'],
                'monto_facturado' => (float)$row['monto_facturado'],
                'monto_ingresado' => (float)$row['monto_ingresado'],
            ];
        }
    }

    return $legajosData;
}

/**
 * Une legajos activos e inactivos para el bloque financiero (activos tienen prioridad).
 *
 * @param array<string, array<string, mixed>> $legajosActivos
 * @param array<string, array<string, mixed>> $legajosInactivos
 * @return array<string, array<string, mixed>>
 */
function admin_ig_legajos_para_deuda(array $legajosActivos, array $legajosInactivos): array
{
    $legajosDeuda = $legajosActivos;
    foreach ($legajosActivos as $leg => $_) {
        $legajosDeuda[$leg]['es_inactivo'] = false;
    }
    foreach ($legajosInactivos as $leg => $info) {
        if (isset($legajosDeuda[$leg])) {
            continue;
        }
        $info['es_inactivo'] = true;
        $legajosDeuda[$leg] = $info;
    }
    return $legajosDeuda;
}


/**
 * @return array<string, mixed>
 */
function admin_load_informacion_general_data(): array
{
    if (!defined('_ACCESS')) {
        define('_ACCESS', true);
    }
    require_once NATTA_ROOT . '/config/db.php';
    require_once __DIR__ . '/db_collate.php';
    require_once NATTA_ROOT . '/backend/lib/contract_institution.php';

    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        admin_mysqli_apply_collation($conn);
    } catch (Throwable $e) {
        error_log('admin informacion_general conexión: ' . $e->getMessage());
        throw new RuntimeException('Error interno del servidor. Intente más tarde.');
    }

// ---------- Legajos + cuotas ----------
$legajosData = admin_ig_cargar_legajos_cuotas_desde_tabla($conn, 'legajos');
$legajosInactivosData = admin_ig_cargar_legajos_cuotas_desde_tabla($conn, 'legajos_inactivos');
$legajosDeudaData = admin_ig_legajos_para_deuda($legajosData, $legajosInactivosData);

// Mes de corte por defecto = cuota vigente configurada (última actualización). Editable a mano.
$mesUltimaActualizacion = admin_cuota_mes_vigente_config($conn);

if (isset($_GET['mes']) && $_GET['mes'] !== '') {
    $mesSeleccionado = (int)$_GET['mes'];
    if ($mesSeleccionado < 1 || $mesSeleccionado > 12) {
        $mesSeleccionado = $mesUltimaActualizacion;
    }
} else {
    $mesSeleccionado = $mesUltimaActualizacion;
}
$nombreMesSeleccionado = admin_ig_nombreMesCuota($mesSeleccionado);

$totalAlumnos = count($legajosData);
$porEscuela = [];
/** escuela => [ curso => cantidad ] */
$porCursoPorEscuela = [];
$becados = 0;
/** Suma de (porcentaje_descuento/100) por alumno: cupos equivalentes a beca al 100%. */
$equivBecas100 = 0.0;
/** escuela => suma de porcentaje_descuento/100 (becas equivalentes al 100% otorgadas) */
$equivBecasPorEscuela = [];
$conteoBecaNivel = [25 => 0, 50 => 0, 75 => 0, 100 => 0, '_otro' => 0];

$deudaTotalComplejo = 0.0;
$deudaPorEscuela = [];
$facturadoPorEscuela = [];
$ingresadoPorEscuela = [];
$montoFacturadoVentana = 0.0;
$montoIngresadoVentana = 0.0;
$cuotasSlotsTotal = 0;
$cuotasSlotsAbonadas = 0;
$morosos = 0;
$morososActivos = 0;
$morososInactivos = 0;
$deudaLegajosInactivos = 0.0;

foreach ($legajosData as $info) {
    $curso = trim((string)($info['curso'] ?? ''));
    $escuela = obtenerEscuelaDesdeCurso($curso);
    $keyEsc = $escuela !== '' ? $escuela : '(sin escuela)';

    $porEscuela[$keyEsc] = ($porEscuela[$keyEsc] ?? 0) + 1;

    $keyCurso = $curso !== '' ? $curso : '(sin curso)';
    if (!isset($porCursoPorEscuela[$keyEsc])) {
        $porCursoPorEscuela[$keyEsc] = [];
    }
    $porCursoPorEscuela[$keyEsc][$keyCurso] = ($porCursoPorEscuela[$keyEsc][$keyCurso] ?? 0) + 1;

    $pDesc = (int)($info['porcentaje_descuento'] ?? 0);
    if (!isset($equivBecasPorEscuela[$keyEsc])) {
        $equivBecasPorEscuela[$keyEsc] = 0.0;
    }
    if ($pDesc > 0) {
        $becados++;
        $equivBecas100 += $pDesc / 100.0;
        $equivBecasPorEscuela[$keyEsc] += $pDesc / 100.0;
        if (isset($conteoBecaNivel[$pDesc])) {
            $conteoBecaNivel[$pDesc]++;
        } else {
            $conteoBecaNivel['_otro']++;
        }
    }
}

foreach ($legajosDeudaData as $info) {
    $curso = trim((string)($info['curso'] ?? ''));
    $escuela = obtenerEscuelaDesdeCurso($curso);
    $keyEsc = $escuela !== '' ? $escuela : '(sin escuela)';
    $esInactivo = !empty($info['es_inactivo']);

    $ventana = admin_cuota_acumular_ventana_legajo($info['cuotas'], $escuela, $mesSeleccionado, true);

    $deudaTotalComplejo += $ventana['deuda_reportada'];
    if ($esInactivo) {
        $deudaLegajosInactivos += $ventana['deuda_reportada'];
    }
    if (!isset($deudaPorEscuela[$keyEsc])) {
        $deudaPorEscuela[$keyEsc] = 0.0;
    }
    $deudaPorEscuela[$keyEsc] += $ventana['deuda_reportada'];

    if (!isset($facturadoPorEscuela[$keyEsc])) {
        $facturadoPorEscuela[$keyEsc] = 0.0;
    }
    $facturadoPorEscuela[$keyEsc] += $ventana['monto_facturado'];

    if (!isset($ingresadoPorEscuela[$keyEsc])) {
        $ingresadoPorEscuela[$keyEsc] = 0.0;
    }
    $ingresadoPorEscuela[$keyEsc] += $ventana['monto_ingresado'];

    $montoFacturadoVentana += $ventana['monto_facturado'];
    $montoIngresadoVentana += $ventana['monto_ingresado'];
    $cuotasSlotsTotal += $ventana['slots_total'];
    $cuotasSlotsAbonadas += $ventana['slots_abonadas'];

    if ($ventana['moroso']) {
        $morosos++;
        if ($esInactivo) {
            $morososInactivos++;
        } else {
            $morososActivos++;
        }
    }
}

/** Uso del cupo global (complejo): equivalentes totales vs cupo con regla de redondeo sobre matrícula total. */
$cupoBecas100Teorico = cupo_becas_al_100_redondeo_arriba($totalAlumnos);
$pctUsoCupoDiezPorciento = $cupoBecas100Teorico > 1e-9 ? round(100 * $equivBecas100 / $cupoBecas100Teorico, 2) : 0.0;
/** Solo referencia: alumnos con alguna beca / total (suele inflar vs cupo en becas parciales). */
$pctCabezasBecadas = $totalAlumnos > 0 ? round(100 * $becados / $totalAlumnos, 2) : 0.0;
$pctCuotasAbonadas = $cuotasSlotsTotal > 0 ? round(100 * $cuotasSlotsAbonadas / $cuotasSlotsTotal, 2) : null;
/** % cobrado en la ventana = monto ingresado ÷ monto facturado hasta el corte. */
$pctCobradoVentana = $montoFacturadoVentana > 0.01 ? round(100 * $montoIngresadoVentana / $montoFacturadoVentana, 2) : null;
$pctMorosos = $totalAlumnos > 0 ? round(100 * $morososActivos / $totalAlumnos, 2) : 0.0;

uksort($porEscuela, 'strnatcasecmp');
foreach (array_keys($porEscuela) as $_ek) {
    if (!isset($equivBecasPorEscuela[$_ek])) {
        $equivBecasPorEscuela[$_ek] = 0.0;
    }
}
uksort($equivBecasPorEscuela, 'strnatcasecmp');
uksort($porCursoPorEscuela, 'strnatcasecmp');
foreach ($porCursoPorEscuela as $escK => $_) {
    uksort($porCursoPorEscuela[$escK], 'strnatcasecmp');
}
uksort($deudaPorEscuela, 'strnatcasecmp');
uksort($facturadoPorEscuela, 'strnatcasecmp');
uksort($ingresadoPorEscuela, 'strnatcasecmp');
$deudaTotalComplejo = round(array_sum($deudaPorEscuela), 2);
$deudaLegajosInactivos = round($deudaLegajosInactivos, 2);
$deudaTotalSinInactivos = round($deudaTotalComplejo - $deudaLegajosInactivos, 2);

// ---------- Familia -> escuelas (para emails por escuela) ----------
$familiaEscuelas = [];
foreach ($legajosData as $info) {
    $nf = norm_familia($info['nro_familia'] ?? '');
    if ($nf === '') {
        continue;
    }
    $esc = obtenerEscuelaDesdeCurso($info['curso'] ?? '');
    if ($esc === '') {
        $esc = '(sin escuela)';
    }
    if (!isset($familiaEscuelas[$nf])) {
        $familiaEscuelas[$nf] = [];
    }
    $familiaEscuelas[$nf][$esc] = true;
}

// ---------- Emails ----------
$emailsGlobal = [];
$emailsPorEscuelaConteo = [];

$sqlMail = 'SELECT nro_familia, mail_padre, mail_padre_trabajo, mail_madre, mail_madre_trabajo, mail_resp_afip FROM email_familia';
$resMail = @$conn->query($sqlMail);
if ($resMail) {
    while ($em = $resMail->fetch_assoc()) {
        $nf = norm_familia($em['nro_familia'] ?? '');
        $cols = ['mail_padre', 'mail_padre_trabajo', 'mail_madre', 'mail_madre_trabajo', 'mail_resp_afip'];
        $famEmails = [];
        foreach ($cols as $col) {
            $raw = trim((string)($em[$col] ?? ''));
            if ($raw === '') {
                continue;
            }
            $low = mb_strtolower($raw, 'UTF-8');
            $famEmails[$low] = true;
            $emailsGlobal[$low] = true;
        }
        if ($nf === '' || empty($famEmails)) {
            continue;
        }
        $escuelasFam = isset($familiaEscuelas[$nf]) ? array_keys($familiaEscuelas[$nf]) : ['(sin legajo activo)'];
        foreach ($escuelasFam as $escKey) {
            if (!isset($emailsPorEscuelaConteo[$escKey])) {
                $emailsPorEscuelaConteo[$escKey] = [];
            }
            foreach (array_keys($famEmails) as $low) {
                $emailsPorEscuelaConteo[$escKey][$low] = true;
            }
        }
    }
    $resMail->free();
} else {
    error_log('informacion_general email_familia: ' . $conn->error);
}

$totalEmailsDistintosComplejo = count($emailsGlobal);
$emailsPorEscuelaNum = [];
foreach ($emailsPorEscuelaConteo as $esc => $set) {
    $emailsPorEscuelaNum[$esc] = count($set);
}
uksort($emailsPorEscuelaNum, 'strnatcasecmp');

// ---------- Usuarios ----------
$cuentasUsuarios = 0;
$resU = @$conn->query('SELECT COUNT(*) AS c FROM usuarios');
if ($resU && ($r = $resU->fetch_assoc())) {
    $cuentasUsuarios = (int)($r['c'] ?? 0);
    $resU->free();
} else {
    error_log('informacion_general usuarios: ' . $conn->error);
}

$usuariosAppUso = null;
$resP = @$conn->query(
    "SELECT COUNT(DISTINCT CONCAT(dni_alumno, '|', email)) AS c
     FROM privacy_policy_acceptances
     WHERE status = 'activo'"
);
if ($resP && ($r = $resP->fetch_assoc())) {
    $usuariosAppUso = (int)($r['c'] ?? 0);
    $resP->free();
} else {
    error_log('informacion_general privacy_policy_acceptances: ' . $conn->error);
}

// ---------- Contratos ----------
$contractVersion = '';
$resCv = @$conn->query(
    "SELECT COUNT(*) AS c, MAX(contract_anio) AS anio
     FROM contratos_instituciones
     WHERE contrato_activo = 1"
);
if ($resCv instanceof mysqli_result) {
    $rowCv = $resCv->fetch_assoc();
    $resCv->free();
    $nContratos = (int)($rowCv['c'] ?? 0);
    $anioContrato = trim((string)($rowCv['anio'] ?? '2027'));
    if ($nContratos > 0) {
        $contractVersion = 'Contratos ' . ($anioContrato !== '' ? $anioContrato : 'vigente') . ' (' . $nContratos . ' instituciones activas)';
    }
} else {
    error_log('informacion_general contratos_instituciones: ' . $conn->error);
}

$aceptacionesPorAlumno = [];
$resSig = @$conn->query(
    "SELECT student_dni, nro_familia, contract_version
     FROM contratos_aceptados
     WHERE status = 'activo'"
);
if ($resSig instanceof mysqli_result) {
    while ($sr = $resSig->fetch_assoc()) {
        $dni = trim((string)($sr['student_dni'] ?? ''));
        $nf = norm_familia($sr['nro_familia'] ?? '');
        if ($dni === '' || $nf === '') {
            continue;
        }
        $aceptacionesPorAlumno[$nf . '|' . $dni] = trim((string)($sr['contract_version'] ?? ''));
    }
    $resSig->free();
} else {
    error_log('informacion_general contratos_aceptados: ' . $conn->error);
}

$firmadosActivos = 0;
foreach ($legajosData as $info) {
    $k = norm_familia($info['nro_familia'] ?? '') . '|' . trim((string)($info['dni_alumno'] ?? ''));
    if ($k === '|' || !isset($aceptacionesPorAlumno[$k])) {
        continue;
    }
    $curso = trim((string)($info['curso'] ?? ''));
    if ($curso === '') {
        continue;
    }
    try {
        $vigente = contrato_fetch_vigente_por_curso($conn, $curso);
    } catch (Throwable $e) {
        error_log('informacion_general contrato vigente: ' . $e->getMessage());
        continue;
    }
    if ($vigente !== null && $aceptacionesPorAlumno[$k] === (string)$vigente['contract_version']) {
        $firmadosActivos++;
    }
}
$faltanFirmar = $contractVersion !== '' ? max(0, $totalAlumnos - $firmadosActivos) : null;

$barCupoPct = min(100.0, (float)$pctUsoCupoDiezPorciento);
$cupoSobreTope = $pctUsoCupoDiezPorciento > 100.0 + 1e-6;

    $conn->close();

    return compact(
        'mesSeleccionado', 'nombreMesSeleccionado', 'totalAlumnos', 'porEscuela', 'porCursoPorEscuela',
        'becados', 'equivBecas100', 'equivBecasPorEscuela', 'conteoBecaNivel', 'deudaTotalComplejo',
        'deudaPorEscuela', 'facturadoPorEscuela', 'ingresadoPorEscuela', 'montoFacturadoVentana', 'montoIngresadoVentana', 'cuotasSlotsTotal',
        'cuotasSlotsAbonadas', 'morosos', 'morososActivos', 'morososInactivos', 'deudaLegajosInactivos', 'deudaTotalSinInactivos',
        'cupoBecas100Teorico', 'pctUsoCupoDiezPorciento',
        'pctCabezasBecadas', 'pctCuotasAbonadas', 'pctCobradoVentana', 'pctMorosos', 'totalEmailsDistintosComplejo',
        'emailsPorEscuelaNum', 'cuentasUsuarios', 'usuariosAppUso', 'contractVersion',
        'firmadosActivos', 'faltanFirmar', 'barCupoPct', 'cupoSobreTope'
    );
}
