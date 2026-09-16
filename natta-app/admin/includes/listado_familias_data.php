<?php
/**
 * Datos y exportación CSV — Listado de familias (admin).
 */

require_once __DIR__ . '/cuotas_admin_lib.php';

// ================== HELPERS ==================
function admin_lf_nombreMesCuota($n) {
    return admin_cuota_nombre_mes($n);
}

// Helper por si el host no tiene mysqlnd (get_result)
function admin_lf_fetch_all_from_stmt($stmt) {
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

// Helper seguro para imprimir HTML (evita deprecated por null)
// Extra: obtener código de escuela desde el curso (últimas 2 letras)
// obtenerEscuelaDesdeCurso / cuotaANumeroMesLogico → cuotas_admin_lib.php

// Parsear lista JSON / coma (familias y legajos mezclados)
function parseListaJson($str) {
    $str = trim($str);
    if ($str === '') return [];

    $result = [];

    // Intentar como JSON
    $decoded = json_decode($str, true);
    if (is_array($decoded)) {
        foreach ($decoded as $item) {
            if (is_string($item) || is_numeric($item)) {
                $val = trim((string)$item);
                if ($val !== '') $result[] = $val;
            }
        }
    } else {
        // Lista separada por coma / espacios
        $parts = preg_split('/[,\s]+/', $str);
        foreach ($parts as $p) {
            $p = trim($p, " \t\n\r\0\x0B\"'");
            if ($p !== '') $result[] = $p;
        }
    }

    return array_values(array_unique($result));
}

/**
 * @param list<mixed> $params
 * @return list<string>
 */
function admin_lf_cargar_nro_legajos(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $legajos = [];

    if ($types !== '') {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('admin listado_familias prepare legajos: ' . $conn->error);
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $filas = admin_lf_fetch_all_from_stmt($stmt);
        $stmt->close();
    } else {
        $res = $conn->query($sql);
        if (!$res) {
            error_log('admin listado_familias query legajos: ' . $conn->error);
            return [];
        }
        $filas = [];
        while ($row = $res->fetch_assoc()) {
            $filas[] = $row;
        }
        $res->free();
    }

    foreach ($filas as $row) {
        $leg = trim((string)($row['nro_legajo'] ?? ''));
        if ($leg !== '' && $leg !== 'INACTIVO') {
            $legajos[] = $leg;
        }
    }

    return array_values(array_unique($legajos));
}

/**
 * @return string SQL con placeholder ? para cada familia en IN (...).
 */
function admin_lf_sql_legajos_cuotas(string $tablaLegajos, string $whereExtra = ''): string
{
    $permitidas = ['legajos', 'legajos_inactivos'];
    if (!in_array($tablaLegajos, $permitidas, true)) {
        throw new InvalidArgumentException('Tabla de legajos no permitida');
    }

    $where = "l.nro_legajo IS NOT NULL AND TRIM(l.nro_legajo) <> '' AND l.nro_legajo <> 'INACTIVO'";
    if ($whereExtra !== '') {
        $where .= ' AND (' . $whereExtra . ')';
    }

    return "
        SELECT
            l.nro_familia,
            l.nro_legajo,
            l.apellido_alumno,
            l.nombre_alumno,
            l.curso,
            l.dni_alumno,
            COALESCE(l.saldo_total, 0) AS saldo_total,
            c.numero_cuota,
            COALESCE(c.diferencia, 0) AS diferencia
        FROM {$tablaLegajos} l
        LEFT JOIN cuotas c
            ON c.nro_legajo COLLATE utf8mb4_unicode_ci = l.nro_legajo COLLATE utf8mb4_unicode_ci
           AND c.numero_cuota <= 12
        WHERE {$where}
        ORDER BY
            l.nro_familia,
            l.nro_legajo,
            c.numero_cuota
    ";
}

/**
 * @param list<mixed> $params
 * @return list<array<string, mixed>>
 */
function admin_lf_ejecutar_sql_legajos(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    if ($types !== '') {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error al preparar datos. Intente más tarde.');
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $filas = admin_lf_fetch_all_from_stmt($stmt);
        $stmt->close();
        return $filas;
    }

    $res = $conn->query($sql);
    if (!$res) {
        error_log('admin listado_familias query: ' . $conn->error);
        throw new RuntimeException('Error al preparar datos. Intente más tarde.');
    }
    $filas = [];
    while ($row = $res->fetch_assoc()) {
        $filas[] = $row;
    }
    $res->free();
    return $filas;
}

/**
 * @param array<string, array<string, mixed>> $legajosData
 * @param list<array<string, mixed>> $filas
 */
function admin_lf_agregar_filas_a_legajos(array &$legajosData, array $filas, bool $esInactivo): void
{
    foreach ($filas as $row) {
        $leg = (string)($row['nro_legajo'] ?? '');
        if ($leg === '') {
            continue;
        }
        if ($esInactivo && isset($legajosData[$leg])) {
            continue;
        }

        if (!isset($legajosData[$leg])) {
            $legajosData[$leg] = [
                'nro_familia'     => $row['nro_familia'],
                'nro_legajo'      => $leg,
                'apellido_alumno' => $row['apellido_alumno'],
                'nombre_alumno'   => $row['nombre_alumno'],
                'curso'           => $row['curso'],
                'dni_alumno'      => $row['dni_alumno'],
                'saldo_total'     => (float)($row['saldo_total'] ?? 0),
                'es_inactivo'     => $esInactivo,
                'cuotas'          => [],
            ];
        }

        if (!is_null($row['numero_cuota'])) {
            $legajosData[$leg]['cuotas'][] = [
                'numero_cuota' => (int)$row['numero_cuota'],
                'diferencia'   => (float)$row['diferencia'],
            ];
        }
    }
}

// ================== LEGAJOS DE BAJA ==================
$legajosBaja = [
];
$legajosBaja = array_values(array_unique($legajosBaja));



/**
 * @return array<string, mixed>
 */
function admin_load_listado_familias_data(): array
{
    if (!defined('_ACCESS')) {
        define('_ACCESS', true);
    }
    require_once NATTA_ROOT . '/config/db.php';
    require_once __DIR__ . '/db_collate.php';

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log('admin listado_familias conexión: ' . $conn->connect_error);
        throw new RuntimeException('Error interno del servidor. Intente más tarde.');
    }
    admin_mysqli_apply_collation($conn);

    global $legajosBaja;

    $legajosInactivos = admin_lf_cargar_nro_legajos(
        $conn,
        "SELECT DISTINCT nro_legajo FROM legajos_inactivos WHERE nro_legajo IS NOT NULL AND TRIM(nro_legajo) <> ''"
    );

    $anioCd = (int)date('Y');
    $legajosMarcados = admin_lf_cargar_nro_legajos(
        $conn,
        "SELECT DISTINCT nro_legajo FROM legajos_con_cd WHERE anio = ? AND nro_legajo IS NOT NULL AND TRIM(nro_legajo) <> ''",
        'i',
        [$anioCd]
    );

// ================== LECTURA DE FILTROS ==================
$mesCorteDefecto = admin_cuota_mes_vigente_config($conn); // mes vigente (última actualización)
$mesSeleccionado = (isset($_GET['mes']) && $_GET['mes'] !== '') ? (int)$_GET['mes'] : $mesCorteDefecto;
$estadoFiltro    = isset($_GET['estado']) ? trim($_GET['estado']) : ""; // "", al_dia, con_deuda
$buscar          = isset($_GET['buscar']) ? trim($_GET['buscar']) : ""; // búsqueda por palabra
$escuelaFiltro   = isset($_GET['escuela']) ? trim($_GET['escuela']) : ""; // CJ, HV, JA, etc.
// Panel secretaría usa escuela=ALL para "todo el complejo".
if (strcasecmp($escuelaFiltro, 'ALL') === 0) {
    $escuelaFiltro = '';
}
// NUEVO: filtro mixto familias/legajos desde JSON o lista
$listaJsonRaw    = isset($_GET['lista_json']) ? trim($_GET['lista_json']) : "";
$tokensFiltro    = parseListaJson($listaJsonRaw);
$famIdsFiltro    = [];
$legajosFiltro   = [];

foreach ($tokensFiltro as $tok) {
    if (strpos($tok, '/') !== false) {
        // Contiene "/", lo tratamos como legajo
        $legajosFiltro[] = $tok;
    } else {
        // Sin "/", lo tratamos como N° de familia
        $famIdsFiltro[] = $tok;
    }
}

$filtrarPorJson = (count($famIdsFiltro) > 0 || count($legajosFiltro) > 0);

// Limitar mes lógico entre 1 y 12
if ($mesSeleccionado < 1 || $mesSeleccionado > 12) {
    $mesSeleccionado = $mesCorteDefecto;
}

$nombreMesSeleccionado = admin_lf_nombreMesCuota($mesSeleccionado);

// ================== OPTIMIZACIÓN: SOLO TRAER 30 FAMILIAS SI NO HAY FILTROS ==================
$hayFiltros = ($buscar !== '' || $escuelaFiltro !== '' || $filtrarPorJson || $estadoFiltro !== '');

$legajosData = [];

if (!$hayFiltros) {
    $sqlFam = "
        SELECT DISTINCT nro_familia
        FROM (
            SELECT nro_familia FROM legajos WHERE nro_familia IS NOT NULL
            UNION
            SELECT nro_familia FROM legajos_inactivos WHERE nro_familia IS NOT NULL
        ) AS fam
        ORDER BY nro_familia
        LIMIT 30
    ";
    $resFam = $conn->query($sqlFam);
    $familiasLimitadas = [];
    if ($resFam) {
        while ($rowFam = $resFam->fetch_assoc()) {
            $familiasLimitadas[] = $rowFam['nro_familia'];
        }
        $resFam->free();
    }

    if (!empty($familiasLimitadas)) {
        $in = implode(',', array_fill(0, count($familiasLimitadas), '?'));
        $whereFam = "l.nro_familia IN ($in)";
        $types = str_repeat('s', count($familiasLimitadas));

        $filasActivos = admin_lf_ejecutar_sql_legajos(
            $conn,
            admin_lf_sql_legajos_cuotas('legajos', $whereFam),
            $types,
            $familiasLimitadas
        );
        admin_lf_agregar_filas_a_legajos($legajosData, $filasActivos, false);

        $filasInactivos = admin_lf_ejecutar_sql_legajos(
            $conn,
            admin_lf_sql_legajos_cuotas('legajos_inactivos', $whereFam),
            $types,
            $familiasLimitadas
        );
        admin_lf_agregar_filas_a_legajos($legajosData, $filasInactivos, true);
    }
} else {
    $filasActivos = admin_lf_ejecutar_sql_legajos($conn, admin_lf_sql_legajos_cuotas('legajos'));
    admin_lf_agregar_filas_a_legajos($legajosData, $filasActivos, false);

    $filasInactivos = admin_lf_ejecutar_sql_legajos($conn, admin_lf_sql_legajos_cuotas('legajos_inactivos'));
    admin_lf_agregar_filas_a_legajos($legajosData, $filasInactivos, true);
}

// ================== CALCULAR DEUDA Y RANGO DE MESES IMPAGOS POR LEGAJO ==================
$familias = [];

foreach ($legajosData as $leg => $info) {
    $nro_familia = $info['nro_familia'];
    $curso       = $info['curso'];
    $escuela     = obtenerEscuelaDesdeCurso($curso);

    $ventana = admin_cuota_acumular_ventana_legajo($info['cuotas'], $escuela, $mesSeleccionado, false, (string)$curso);
    $deudaHastaMes = (float)($ventana['deuda_impaga'] ?? $ventana['deuda_neta']);
    $primerMesImpagoLogic = $ventana['primer_mes_impago'];
    $ultimoMesImpagoLogic = $ventana['ultimo_mes_impago'];
    $mesesImpagos = $ventana['meses_impagos'] ?? [];

    // saldo_total no distingue meses: solo sirve como respaldo cuando el legajo
    // (p.ej. baja/inactivo) no tiene ninguna cuota cargada.
    $saldoTotalLegajo = (float)($info['saldo_total'] ?? 0);
    if (empty($info['cuotas']) && $saldoTotalLegajo > admin_cuota_umbral_al_dia()) {
        $deudaHastaMes = $saldoTotalLegajo;
    }

    // Construimos estructura de familias
    if (!isset($familias[$nro_familia])) {
        $familias[$nro_familia] = [
            'nro_familia'       => $nro_familia,
            'alumnos'           => [],
            'deuda_total'       => 0.0,
            'primer_mes_impago' => null,
            'ultimo_mes_impago' => null
        ];
    }

    $familias[$nro_familia]['alumnos'][] = [
        'nro_legajo'        => $info['nro_legajo'],
        'apellido_alumno'   => $info['apellido_alumno'],
        'nombre_alumno'     => $info['nombre_alumno'],
        'curso'             => $info['curso'],
        'dni_alumno'        => $info['dni_alumno'],
        'deuda_hasta_mes'   => $deudaHastaMes,
        'primer_mes_impago' => $primerMesImpagoLogic,
        'ultimo_mes_impago' => $ultimoMesImpagoLogic,
        'meses_impagos'     => $mesesImpagos,
        'escuela'           => $escuela,
        'es_inactivo'       => !empty($info['es_inactivo']),
    ];

    if ($deudaHastaMes > admin_cuota_umbral_al_dia()) {
        $familias[$nro_familia]['deuda_total'] += $deudaHastaMes;
    }

    if (!is_null($primerMesImpagoLogic)) {
        if (is_null($familias[$nro_familia]['primer_mes_impago']) ||
            $primerMesImpagoLogic < $familias[$nro_familia]['primer_mes_impago']) {
            $familias[$nro_familia]['primer_mes_impago'] = $primerMesImpagoLogic;
        }
    }
    if (!is_null($ultimoMesImpagoLogic)) {
        if (is_null($familias[$nro_familia]['ultimo_mes_impago']) ||
            $ultimoMesImpagoLogic > $familias[$nro_familia]['ultimo_mes_impago']) {
            $familias[$nro_familia]['ultimo_mes_impago'] = $ultimoMesImpagoLogic;
        }
    }
}

// ================== APLICAR FILTRO DE ESTADO + ESCUELA + BÚSQUEDA + JSON ==================
$familiasFiltradas = [];
$buscarNorm   = mb_strtoupper($buscar, 'UTF-8');
$escuelaNorm  = mb_strtoupper($escuelaFiltro, 'UTF-8');

foreach ($familias as $idFam => $fam) {
    $tieneDeuda = $fam['deuda_total'] > 0.01;

    // 0) Filtro JSON (familias/legajos)
    if ($filtrarPorJson) {
        $matchJson = false;

        // ¿Coincide por nro_familia?
        if (in_array((string)$fam['nro_familia'], $famIdsFiltro, true)) {
            $matchJson = true;
        } else {
            // ¿Coincide por algún legajo?
            foreach ($fam['alumnos'] as $alumno) {
                if (in_array((string)$alumno['nro_legajo'], $legajosFiltro, true)) {
                    $matchJson = true;
                    break;
                }
            }
        }

        if (!$matchJson) {
            continue;
        }
    }

    // 1) Filtro por estado de familia
    if ($estadoFiltro === 'al_dia' && $tieneDeuda) {
        continue;
    }
    if ($estadoFiltro === 'con_deuda' && !$tieneDeuda) {
        continue;
    }

    // 2) Filtro por escuela (si se seleccionó alguna)
    $coincideEscuela = true;
    if ($escuelaNorm !== '') {
        $coincideEscuela = false;
        foreach ($fam['alumnos'] as $alumno) {
            $esc = mb_strtoupper($alumno['escuela'] ?? '', 'UTF-8');
            if ($esc === $escuelaNorm) {
                $coincideEscuela = true;
                break;
            }
        }
    }
    if (!$coincideEscuela) {
        continue;
    }

    // 3) Filtro por búsqueda
    $coincideBusqueda = true;
    if ($buscarNorm !== '') {
        $coincideBusqueda = false;
        foreach ($fam['alumnos'] as $alumno) {
            $texto = mb_strtoupper(
                ($alumno['apellido_alumno'] ?? '') . ' ' .
                ($alumno['nombre_alumno'] ?? '')   . ' ' .
                ($alumno['curso'] ?? '')           . ' ' .
                ($alumno['nro_legajo'] ?? '')      . ' ' .
                ($alumno['dni_alumno'] ?? '')      .
                (!empty($alumno['es_inactivo']) ? ' INACTIVO' : ''),
                'UTF-8'
            );
            if (mb_stripos($texto, $buscarNorm, 0, 'UTF-8') !== false) {
                $coincideBusqueda = true;
                break;
            }
        }
    }

    if (!$coincideBusqueda) {
        continue;
    }

    $familiasFiltradas[$idFam] = $fam;
}

// ================== TOTALES ==================
$totalFamilias      = count($familiasFiltradas);
$totalFamiliasDeuda = 0;
foreach ($familiasFiltradas as $f) {
    if ($f['deuda_total'] > 0.01) {
        $totalFamiliasDeuda++;
    }
}
$totalFamiliasAlDia = $totalFamilias - $totalFamiliasDeuda;


    $conn->close();

    return compact(
        'mesSeleccionado', 'estadoFiltro', 'buscar', 'escuelaFiltro', 'listaJsonRaw',
        'filtrarPorJson', 'nombreMesSeleccionado', 'hayFiltros', 'familiasFiltradas',
        'totalFamilias', 'totalFamiliasDeuda', 'totalFamiliasAlDia',
        'legajosInactivos', 'legajosMarcados', 'legajosBaja'
    );
}

/**
 * @param array<string, mixed> $data
 */
function admin_export_listado_familias_csv(array $data): void
{
    $familiasFiltradas = $data['familiasFiltradas'] ?? [];
    $nombreMesSeleccionado = (string)($data['nombreMesSeleccionado'] ?? '');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="listado_familias.csv"');

    $out = fopen('php://output', 'w');

    fputcsv($out, [
        'Familia',
        'Nro Legajo',
        'Apellido',
        'Nombre',
        'Curso',
        'DNI',
        'Deuda hasta ' . $nombreMesSeleccionado,
        'Primer mes adeudado',
        'Estado familia',
    ]);

    foreach ($familiasFiltradas as $familia) {
        $tieneDeuda = ($familia['deuda_total'] ?? 0) > 0.01;
        $estadoFam = $tieneDeuda ? 'CON DEUDA' : 'AL DIA';
        $desdeFam = !empty($familia['primer_mes_impago'])
            ? admin_lf_nombreMesCuota((int)$familia['primer_mes_impago'])
            : '';

        foreach ($familia['alumnos'] as $alumno) {
            $deudaAlumno = (float)($alumno['deuda_hasta_mes'] ?? 0);
            $primerMesAl = !empty($alumno['primer_mes_impago'])
                ? admin_lf_nombreMesCuota((int)$alumno['primer_mes_impago'])
                : '';

            fputcsv($out, [
                $familia['nro_familia'],
                $alumno['nro_legajo'],
                $alumno['apellido_alumno'],
                $alumno['nombre_alumno'],
                $alumno['curso'],
                $alumno['dni_alumno'],
                number_format(max(0, $deudaAlumno), 2, '.', ''),
                $primerMesAl ?: $desdeFam,
                $estadoFam,
            ]);
        }
    }

    fclose($out);
}
