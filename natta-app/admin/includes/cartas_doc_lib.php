<?php
/**
 * Cartas documento (CD) y notificaciones fehacientes (NF).
 * El listado sale de docs/cartas-documento/legajo + resp/Legajos limpio prueba.xlsx.
 * La deuda se completa con los legajos activos (no usa legajos_inactivos).
 *
 * CD: adeuda marzo, abril o mayo. Ese alumno no lleva NF.
 * NF: no adeuda esos meses, y sí junio o julio. Ese alumno no lleva CD.
 * La deuda total del grupo es la suma impaga desde marzo hasta septiembre.
 */

require_once __DIR__ . '/listado_familias_data.php';

function admin_cd_ruta_plantilla(): string
{
    return NATTA_ROOT . '/docs/cartas-documento/legajo + resp/Legajos limpio prueba.xlsx';
}

function admin_cd_ruta_salida(): string
{
    return NATTA_ROOT . '/docs/cartas-documento/legajo + resp/legajos + resp - deuda.xlsx';
}

function admin_cd_ruta_salida_relativa(): string
{
    return 'docs/cartas-documento/legajo + resp/legajos + resp - deuda.xlsx';
}

/**
 * @param list<array{numero_cuota:int, diferencia:float}> $cuotas
 * @return array{
 *   cd: bool,
 *   nf: bool,
 *   meses: list<int>,
 *   meses_cd: list<int>,
 *   meses_nf: list<int>,
 *   meses_documento: list<int>,
 *   deuda: float,
 *   deuda_documento: float,
 *   deuda_cd: float,
 *   deuda_nf: float
 * }
 */
function admin_cd_clasificar_legajo(array $cuotas, string $escuela, string $curso, int $mesCorte = 7): array
{
    $vacio = [
        'cd' => false,
        'nf' => false,
        'meses' => [],
        'meses_cd' => [],
        'meses_nf' => [],
        'meses_documento' => [],
        'deuda' => 0.0,
        'deuda_documento' => 0.0,
        'deuda_cd' => 0.0,
        'deuda_nf' => 0.0,
    ];

    if ($mesCorte < 1 || $mesCorte > 12) {
        $mesCorte = 7;
    }

    if (curso_es_ingresante_externo_2027($curso)) {
        return $vacio;
    }

    $umbral = admin_cuota_umbral_al_dia();
    $impagos = [];

    foreach ($cuotas as $cuota) {
        $mes = cuotaANumeroMesLogico((int)($cuota['numero_cuota'] ?? 0), $escuela);
        if ($mes === null || $mes < 1 || $mes > $mesCorte) {
            continue;
        }
        $dif = (float)($cuota['diferencia'] ?? 0);
        if ($dif <= $umbral) {
            continue;
        }
        $impagos[$mes] = ($impagos[$mes] ?? 0.0) + $dif;
    }

    $mesesCd = [];
    $mesesNf = [];
    $meses = [];
    $deuda = 0.0;
    $deudaCdMeses = 0.0;
    $deudaNfMeses = 0.0;
    foreach ($impagos as $mes => $monto) {
        $mes = (int)$mes;
        $deuda += $monto;
        $meses[] = $mes;
        if ($mes >= 1 && $mes <= 3) {
            $mesesCd[] = $mes;
            $deudaCdMeses += $monto;
        } elseif ($mes >= 4 && $mes <= 5) {
            $mesesNf[] = $mes;
            $deudaNfMeses += $monto;
        }
    }
    sort($mesesCd, SORT_NUMERIC);
    sort($mesesNf, SORT_NUMERIC);
    sort($meses, SORT_NUMERIC);

    $esCd = $mesesCd !== [];
    $esNf = !$esCd && $mesesNf !== [];

    return [
        'cd' => $esCd,
        'nf' => $esNf,
        'meses' => $meses,
        'meses_cd' => $esCd ? $mesesCd : [],
        'meses_nf' => $esNf ? $mesesNf : [],
        'meses_documento' => $esCd ? $mesesCd : ($esNf ? $mesesNf : []),
        'deuda' => $deuda,
        'deuda_documento' => $esCd ? $deudaCdMeses : ($esNf ? $deudaNfMeses : 0.0),
        'deuda_cd' => $esCd ? $deuda : 0.0,
        'deuda_nf' => $esNf ? $deuda : 0.0,
    ];
}

function admin_cd_formato_meses_lista(array $meses): string
{
    $nombres = [];
    foreach ($meses as $mes) {
        $txt = admin_cuota_nombre_mes((int)$mes);
        if ($txt !== '') {
            $nombres[] = $txt;
        }
    }
    return implode(', ', $nombres);
}

function admin_cd_formato_meses(array $meses): string
{
    return admin_cuota_formato_meses_impagos($meses);
}

function admin_cd_norm_texto(string $s): string
{
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
    ]);
    $s = preg_replace('/[^A-Z0-9 ]+/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', (string)$s);
    return trim((string)$s);
}

function admin_cd_norm_legajo(string $legajo): string
{
    return preg_replace('/\s+/', '', trim($legajo)) ?? '';
}

function admin_cd_prefijo_grupo(string $idGrupo): string
{
    $partes = explode('-', trim($idGrupo), 2);
    $prefijo = strtoupper(trim($partes[0] ?? ''));
    if ($prefijo === 'ID') {
        return 'ET';
    }
    return $prefijo;
}

function admin_cd_token_parecido(string $a, string $b): bool
{
    if ($a === $b) {
        return true;
    }
    if ($a === '' || $b === '') {
        return false;
    }
    $la = strlen($a);
    $lb = strlen($b);
    if (abs($la - $lb) > 2) {
        return false;
    }
    $dist = levenshtein($a, $b);
    return $dist <= ($la <= 4 || $lb <= 4 ? 1 : 2);
}

function admin_cd_nombres_compatibles(string $excelNorm, string $dbNorm): bool
{
    if ($excelNorm === '' || $dbNorm === '') {
        return false;
    }
    if ($excelNorm === $dbNorm) {
        return true;
    }

    $excelTokens = preg_split('/\s+/', $excelNorm) ?: [];
    $dbTokens = preg_split('/\s+/', $dbNorm) ?: [];
    if (count($excelTokens) < 2 || count($dbTokens) < 2) {
        return false;
    }
    if (!admin_cd_token_parecido($excelTokens[0], $dbTokens[0])) {
        return false;
    }

    $corto = count($excelTokens) <= count($dbTokens) ? $excelTokens : $dbTokens;
    $largo = count($excelTokens) <= count($dbTokens) ? $dbTokens : $excelTokens;
    $i = 0;
    foreach ($largo as $token) {
        if ($i < count($corto) && admin_cd_token_parecido($token, $corto[$i])) {
            $i++;
        }
    }
    return $i === count($corto);
}

/**
 * @param list<array<string, mixed>> $candidatos
 * @return array<string, mixed>|null
 */
function admin_cd_elegir_por_nombre(array $candidatos, string $alumnoNorm): ?array
{
    if ($alumnoNorm === '') {
        return null;
    }

    $exactos = [];
    $parciales = [];
    foreach ($candidatos as $info) {
        $dbNorm = (string)($info['nombre_norm'] ?? '');
        if ($dbNorm === $alumnoNorm) {
            $exactos[] = $info;
        } elseif (admin_cd_nombres_compatibles($alumnoNorm, $dbNorm)) {
            $parciales[] = $info;
        }
    }

    if (count($exactos) === 1) {
        return $exactos[0];
    }
    if (count($exactos) > 1) {
        return null;
    }
    if (count($parciales) === 1) {
        return $parciales[0];
    }
    return null;
}

/**
 * Cursos que no van en la carta documento ni suman a la deuda:
 * 61MB, 62MB, 63MB, 64MB, 7AET y todo Superior (SU).
 */
function admin_cd_curso_excluido(string $curso): bool
{
    $c = mb_strtoupper(preg_replace('/\s+/', '', $curso) ?? '', 'UTF-8');
    if ($c === '') {
        return false;
    }
    if (in_array($c, ['61MB', '62MB', '63MB', '64MB', '7AET'], true)) {
        return true;
    }
    return obtenerEscuelaDesdeCurso($c) === 'SU';
}

/**
 * @param array<string, array<string, mixed>> $legajosData
 * @return array{por_legajo: array<string, array<string, mixed>>, por_familia: array<string, list<array<string, mixed>>>, todos: list<array<string, mixed>>}
 */
function admin_cd_indexar_legajos(array $legajosData, int $mesCorte = 7): array
{
    $porLegajo = [];
    $porFamilia = [];
    $todos = [];

    foreach ($legajosData as $info) {
        if (!empty($info['es_inactivo'])) {
            continue;
        }
        $curso = (string)($info['curso'] ?? '');
        $escuela = obtenerEscuelaDesdeCurso($curso);
        $excluido = admin_cd_curso_excluido($curso);
        // Excluidos: no clasifican CD/NF ni aportan deuda.
        $clasif = $excluido
            ? admin_cd_clasificar_legajo([], $escuela, $curso, $mesCorte)
            : admin_cd_clasificar_legajo($info['cuotas'] ?? [], $escuela, $curso, $mesCorte);
        $nroFamilia = trim((string)($info['nro_familia'] ?? ''));
        $armado = $info;
        $armado['escuela'] = $escuela;
        $armado['clasif'] = $clasif;
        $armado['excluido_cd'] = $excluido;
        $armado['nombre_norm'] = admin_cd_norm_texto(
            trim((string)($info['apellido_alumno'] ?? '')) . ' ' . trim((string)($info['nombre_alumno'] ?? ''))
        );
        $armado['nro_familia'] = $nroFamilia;

        $legajo = admin_cd_norm_legajo((string)($info['nro_legajo'] ?? ''));
        if ($legajo !== '') {
            // Se conserva para ubicar la familia por legajo del Excel.
            $porLegajo[$legajo] = $armado;
        }
        if ($excluido) {
            continue;
        }
        if ($nroFamilia !== '') {
            $porFamilia[$nroFamilia][] = $armado;
        }
        $todos[] = $armado;
    }

    return [
        'por_legajo' => $porLegajo,
        'por_familia' => $porFamilia,
        'todos' => $todos,
    ];
}

/**
 * Cruza el Excel con la DB.
 * Si un grupo tiene al menos un legajo (ej. 5/01), busca su nro_familia en contactos
 * y trae a TODOS los hermanos activos de esa familia con su deuda de cuotas.
 *
 * @param list<array<string, mixed>> $filas
 * @param array{por_legajo: array<string, array<string, mixed>>, por_familia: array<string, list<array<string, mixed>>>, todos: list<array<string, mixed>>} $indice
 * @return array{
 *   filas: list<array<string, mixed>>,
 *   hermanos_por_grupo: array<string, list<array<string, mixed>>>
 * }
 */
function admin_cd_asignar_deuda(array $filas, array $indice): array
{
    $porGrupo = [];
    foreach ($filas as $i => $fila) {
        $id = (string)($fila['id_grupo'] ?? '');
        $porGrupo[$id][] = $i;
    }

    $asignadas = $filas;
    $hermanosPorGrupo = [];

    foreach ($porGrupo as $idGrupo => $indicesFila) {
        $familiasDb = [];
        $usadosEnExcel = [];

        foreach ($indicesFila as $i) {
            $legajo = admin_cd_norm_legajo((string)($filas[$i]['legajo'] ?? ''));
            if ($legajo === '' || !isset($indice['por_legajo'][$legajo])) {
                continue;
            }
            $match = $indice['por_legajo'][$legajo];
            $asignadas[$i]['match'] = $match;
            $usadosEnExcel[$legajo] = true;
            $nroFamilia = trim((string)($match['nro_familia'] ?? ''));
            if ($nroFamilia !== '') {
                $familiasDb[$nroFamilia] = true;
            }
        }

        // Hermanos de la base: todos los activos de esas familias.
        $hermanosDb = [];
        $vistos = [];
        foreach (array_keys($familiasDb) as $nroFamilia) {
            foreach ($indice['por_familia'][$nroFamilia] ?? [] as $info) {
                $leg = admin_cd_norm_legajo((string)($info['nro_legajo'] ?? ''));
                if ($leg === '' || isset($vistos[$leg])) {
                    continue;
                }
                $vistos[$leg] = true;
                $hermanosDb[] = $info;
            }
        }
        $hermanosPorGrupo[$idGrupo] = $hermanosDb;

        // Filas del Excel sin legajo: completar con hermanos de la misma familia (DB).
        $libres = [];
        foreach ($hermanosDb as $info) {
            $leg = admin_cd_norm_legajo((string)($info['nro_legajo'] ?? ''));
            if ($leg !== '' && isset($usadosEnExcel[$leg])) {
                continue;
            }
            $libres[] = $info;
        }

        foreach ($indicesFila as $i) {
            if (isset($asignadas[$i]['match'])) {
                continue;
            }
            $legajo = admin_cd_norm_legajo((string)($filas[$i]['legajo'] ?? ''));
            if ($legajo !== '') {
                $asignadas[$i]['nota'] = 'Legajo no está entre los activos';
                continue;
            }
            if ($libres === []) {
                $asignadas[$i]['nota'] = 'Sin hermanos pendientes en la familia de la DB';
                continue;
            }

            $alumnoNorm = admin_cd_norm_texto((string)($filas[$i]['alumno'] ?? ''));
            $match = admin_cd_elegir_por_nombre($libres, $alumnoNorm);
            if ($match === null && count($libres) === 1) {
                $match = $libres[0];
            }
            if (!is_array($match)) {
                // El listado igual trae a todos los hermanos por nro_familia desde la DB.
                $asignadas[$i]['nota'] = 'Sin legajo en Excel: deuda de hermanos por familia en DB';
                continue;
            }
            $legMatch = admin_cd_norm_legajo((string)($match['nro_legajo'] ?? ''));
            $libres = array_values(array_filter(
                $libres,
                static function (array $info) use ($legMatch): bool {
                    return admin_cd_norm_legajo((string)($info['nro_legajo'] ?? '')) !== $legMatch;
                }
            ));
            $asignadas[$i]['match'] = $match;
            $usadosEnExcel[$legMatch] = true;
        }
    }

    foreach ($asignadas as $i => $fila) {
        if (empty($fila['match'])) {
            $asignadas[$i]['deuda'] = null;
            continue;
        }
        if (!empty($fila['match']['excluido_cd'])) {
            // Curso excluido: no va en la carta ni suma deuda (sirve solo para ubicar la familia).
            $asignadas[$i]['deuda'] = null;
            $asignadas[$i]['nota'] = 'Curso ' . (string)($fila['match']['curso'] ?? '') . ' excluido de carta documento';
            continue;
        }
        // Deuda siempre desde cuotas de la base contactos.
        $clasif = $fila['match']['clasif'] ?? [];
        $asignadas[$i]['deuda'] = (float)($clasif['deuda'] ?? 0);
    }

    return [
        'filas' => $asignadas,
        'hermanos_por_grupo' => $hermanosPorGrupo,
    ];
}

/**
 * @param array<string, mixed> $match
 * @return array<string, mixed>
 */
function admin_cd_alumno_desde_match_db(array $match, array $metaExcel = []): array
{
    $clasif = $match['clasif'] ?? [];
    $apellido = trim((string)($match['apellido_alumno'] ?? ''));
    $nombre = trim((string)($match['nombre_alumno'] ?? ''));
    $alumno = trim($apellido . ' ' . $nombre);

    return [
        'nro_legajo' => (string)($match['nro_legajo'] ?? ''),
        'alumno' => $alumno,
        'apellido_alumno' => $apellido,
        'nombre_alumno' => $nombre,
        'curso' => (string)($match['curso'] ?? ''),
        'dni_alumno' => (string)($match['dni_alumno'] ?? ''),
        'escuela' => (string)($match['escuela'] ?? ''),
        'cd' => !empty($clasif['cd']),
        'nf' => !empty($clasif['nf']),
        'meses' => $clasif['meses'] ?? [],
        'meses_documento' => $clasif['meses_documento'] ?? [],
        'deuda' => (float)($clasif['deuda'] ?? 0),
        'deuda_documento' => (float)($clasif['deuda_documento'] ?? 0),
        'deuda_cd' => (float)($clasif['deuda_cd'] ?? 0),
        'deuda_nf' => (float)($clasif['deuda_nf'] ?? 0),
        'responsable' => (string)($metaExcel['responsable'] ?? ''),
        'domicilio' => (string)($metaExcel['domicilio'] ?? ''),
        'colegio' => (string)($metaExcel['colegio'] ?? ''),
        'match_ok' => true,
        'nro_familia_db' => (string)($match['nro_familia'] ?? ''),
    ];
}

function admin_cd_cargar_phpspreadsheet(): void
{
    $autoload = NATTA_ROOT . '/importador-excel/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('No está disponible la librería para leer Excel.');
    }
    require_once $autoload;
}

/**
 * Lee la columna "LEGAJOS FALTANTES" de la hoja "control de legajos".
 * Ubica las columnas por encabezado (Id grupo / LEGAJOS FALTANTES).
 *
 * @return array<string, string> id_grupo => legajo
 */
function admin_cd_leer_legajos_faltantes(\PhpOffice\PhpSpreadsheet\Spreadsheet $libro): array
{
    $hoja = $libro->getSheetByName('control de legajos');
    if ($hoja === null) {
        return [];
    }
    $colId = null;
    $colLeg = null;
    foreach (range('A', 'M') as $c) {
        $h = mb_strtoupper(trim((string)$hoja->getCell($c . '1')->getValue()), 'UTF-8');
        if ($h === 'ID GRUPO') {
            $colId = $c;
        } elseif (str_contains($h, 'LEGAJO') && str_contains($h, 'FALTANTE')) {
            $colLeg = $c;
        }
    }
    if ($colId === null || $colLeg === null) {
        return [];
    }
    $map = [];
    $ultima = (int)$hoja->getHighestDataRow();
    for ($r = 2; $r <= $ultima; $r++) {
        $id = trim((string)$hoja->getCell($colId . $r)->getValue());
        $leg = admin_cd_norm_legajo((string)$hoja->getCell($colLeg . $r)->getValue());
        if ($id !== '' && $leg !== '') {
            $map[$id] = $leg;
        }
    }
    return $map;
}

/**
 * Completa en memoria las filas de Alumnos con LEGAJOS FALTANTES:
 * si el grupo no tiene ningún legajo, se asigna al primer alumno del grupo.
 *
 * @param list<array<string, mixed>> $filas
 * @param array<string, string> $faltantes
 * @return list<array<string, mixed>>
 */
function admin_cd_aplicar_legajos_faltantes(array $filas, array $faltantes): array
{
    if ($faltantes === []) {
        return $filas;
    }
    $primera = [];
    $tieneLegajo = [];
    foreach ($filas as $i => $fila) {
        $id = trim((string)($fila['id_grupo'] ?? ''));
        if ($id === '') {
            continue;
        }
        if (!isset($primera[$id])) {
            $primera[$id] = $i;
        }
        if (trim((string)($fila['legajo'] ?? '')) !== '') {
            $tieneLegajo[$id] = true;
        }
    }
    foreach ($faltantes as $id => $leg) {
        if (!isset($primera[$id]) || !empty($tieneLegajo[$id])) {
            continue;
        }
        $filas[$primera[$id]]['legajo'] = $leg;
        $filas[$primera[$id]]['legajo_desde_control'] = true;
    }
    return $filas;
}

/**
 * Arma la hoja "control de legajos": familias (mismo responsable) donde
 * ningún alumno del grupo Excel tiene legajo cargado.
 * Conserva la columna "LEGAJOS FALTANTES" que completa el usuario.
 *
 * @param list<array<string, mixed>> $filas Filas leídas de la hoja Alumnos
 * @param array<string, string> $faltantes id_grupo => legajo ya cargado
 */
function admin_cd_agregar_hoja_control_legajos(\PhpOffice\PhpSpreadsheet\Spreadsheet $libro, array $filas, array $faltantes = []): void
{
    $grupos = [];
    foreach ($filas as $fila) {
        $idGrupo = trim((string)($fila['id_grupo'] ?? ''));
        $alumno = trim((string)($fila['alumno'] ?? ''));
        if ($idGrupo === '' && $alumno === '') {
            continue;
        }
        if ($idGrupo === '') {
            $idGrupo = '—';
        }
        if (!isset($grupos[$idGrupo])) {
            $grupos[$idGrupo] = [
                'id_grupo' => $idGrupo,
                'colegio' => trim((string)($fila['colegio'] ?? '')),
                'responsable' => trim((string)($fila['responsable'] ?? '')),
                'domicilio' => trim((string)($fila['domicilio'] ?? '')),
                'alumnos' => [],
            ];
        }
        $resp = trim((string)($fila['responsable'] ?? ''));
        if ($grupos[$idGrupo]['responsable'] === '' && $resp !== '') {
            $grupos[$idGrupo]['responsable'] = $resp;
        }
        $dom = trim((string)($fila['domicilio'] ?? ''));
        if ($grupos[$idGrupo]['domicilio'] === '' && $dom !== '') {
            $grupos[$idGrupo]['domicilio'] = $dom;
        }
        $col = trim((string)($fila['colegio'] ?? ''));
        if ($grupos[$idGrupo]['colegio'] === '' && $col !== '') {
            $grupos[$idGrupo]['colegio'] = $col;
        }
        // Un legajo que vino de "control de legajos" no cuenta como propio del Excel.
        $legajoPropio = !empty($fila['legajo_desde_control']) ? '' : trim((string)($fila['legajo'] ?? ''));
        $grupos[$idGrupo]['alumnos'][] = [
            'legajo' => $legajoPropio,
            'alumno' => $alumno,
            'obs' => trim((string)($fila['observaciones'] ?? '')),
        ];
    }

    $sinLegajo = [];
    foreach ($grupos as $g) {
        foreach ($g['alumnos'] as $a) {
            if ($a['legajo'] !== '') {
                continue 2;
            }
        }
        $sinLegajo[] = $g;
    }

    $porResp = [];
    foreach ($sinLegajo as $g) {
        $resp = trim($g['responsable']);
        $clave = $resp === '' ? '(SIN RESPONSABLE)' : mb_strtoupper($resp, 'UTF-8');
        $porResp[$clave][] = $g;
    }
    uksort($porResp, static function (string $a, string $b): int {
        if ($a === '(SIN RESPONSABLE)') {
            return 1;
        }
        if ($b === '(SIN RESPONSABLE)') {
            return -1;
        }
        return strnatcasecmp($a, $b);
    });

    $existente = $libro->getSheetByName('control de legajos');
    if ($existente !== null) {
        $libro->removeSheetByIndex($libro->getIndex($existente));
    }

    $hoja = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($libro, 'control de legajos');
    $libro->addSheet($hoja);

    // Mismo orden de columnas que usa el usuario (A queda libre).
    $headers = [
        'B1' => 'Responsable',
        'C1' => 'Cant. grupos',
        'D1' => 'Id grupo',
        'E1' => 'Colegio',
        'F1' => 'LEGAJOS FALTANTES',
        'G1' => 'Alumnos (sin legajo)',
        'H1' => 'Domicilio',
        'I1' => 'Observaciones',
    ];
    foreach ($headers as $cell => $title) {
        $hoja->setCellValue($cell, $title);
    }
    $hoja->getStyle('B1:I1')->getFont()->setBold(true);
    $hoja->getStyle('B1:I1')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB('D9E2F3');

    $row = 2;
    foreach ($porResp as $claveResp => $listaGrupos) {
        $respMostrar = $claveResp === '(SIN RESPONSABLE)'
            ? '(SIN RESPONSABLE)'
            : (string)($listaGrupos[0]['responsable'] ?? $claveResp);
        $cantGrupos = count($listaGrupos);
        foreach ($listaGrupos as $g) {
            $nombres = [];
            $obs = [];
            foreach ($g['alumnos'] as $a) {
                if ($a['alumno'] !== '') {
                    $nombres[] = $a['alumno'];
                }
                if ($a['obs'] !== '') {
                    $obs[] = $a['obs'];
                }
            }
            $hoja->setCellValue('B' . $row, $respMostrar);
            $hoja->setCellValue('C' . $row, $cantGrupos);
            $hoja->setCellValue('D' . $row, $g['id_grupo']);
            $hoja->setCellValue('E' . $row, $g['colegio']);
            $legFaltante = (string)($faltantes[$g['id_grupo']] ?? '');
            if ($legFaltante !== '') {
                $hoja->setCellValueExplicit(
                    'F' . $row,
                    $legFaltante,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
            $hoja->setCellValue('G' . $row, implode(' / ', $nombres));
            $hoja->setCellValue('H' . $row, $g['domicilio']);
            $hoja->setCellValue('I' . $row, implode(' | ', array_values(array_unique($obs))));
            if ($cantGrupos > 1 && $claveResp !== '(SIN RESPONSABLE)') {
                $hoja->getStyle('B' . $row . ':I' . $row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FFF2CC');
            }
            $row++;
        }
    }

    foreach (range('B', 'I') as $col) {
        $hoja->getColumnDimension($col)->setAutoSize(true);
    }
    if ($row > 2) {
        $hoja->getStyle('B1:I' . ($row - 1))
            ->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP)
            ->setWrapText(true);
    }
}

/**
 * Lee la planilla limpia, completa Deuda y guarda el Excel nuevo.
 *
 * @param array<string, array<string, mixed>> $legajosData
 * @return array{filas: list<array<string, mixed>>, coincidencias: int, sin_coincidencia: int, ruta: string}
 */
function admin_cd_generar_excel(array $legajosData, ?string $destino = null, int $mesCorte = 7): array
{
    if (!defined('NATTA_ROOT')) {
        throw new RuntimeException('NATTA_ROOT no definido.');
    }

    $plantilla = admin_cd_ruta_plantilla();
    $destino = $destino ?? admin_cd_ruta_salida();
    if (!is_file($plantilla)) {
        throw new RuntimeException('No se encontró la planilla Legajos limpio prueba.xlsx.');
    }
    if (realpath($plantilla) !== false && realpath($plantilla) === realpath($destino)) {
        throw new RuntimeException('La salida no puede pisar la planilla original.');
    }

    admin_cd_cargar_phpspreadsheet();

    $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($plantilla);
    $alumnos = $libro->getSheetByName('Alumnos');
    if ($alumnos === null) {
        throw new RuntimeException('La planilla no tiene la hoja Alumnos.');
    }

    $ultima = $alumnos->getHighestDataRow();
    $filas = [];
    for ($r = 2; $r <= $ultima; $r++) {
        $idGrupo = trim((string)$alumnos->getCell('A' . $r)->getValue());
        $alumno = trim((string)$alumnos->getCell('D' . $r)->getValue());
        if ($idGrupo === '' && $alumno === '') {
            continue;
        }
        $filas[] = [
            'fila' => $r,
            'id_grupo' => $idGrupo,
            'colegio' => trim((string)$alumnos->getCell('B' . $r)->getValue()),
            'legajo' => trim((string)$alumnos->getCell('C' . $r)->getValue()),
            'alumno' => $alumno,
            'domicilio' => trim((string)$alumnos->getCell('F' . $r)->getValue()),
            'responsable' => trim((string)$alumnos->getCell('G' . $r)->getValue()),
            'observaciones' => trim((string)$alumnos->getCell('H' . $r)->getValue()),
        ];
    }

    // Respaldo: legajos cargados a mano en "control de legajos" (LEGAJOS FALTANTES).
    $legajosFaltantes = admin_cd_leer_legajos_faltantes($libro);
    $filas = admin_cd_aplicar_legajos_faltantes($filas, $legajosFaltantes);

    $cartasMeta = [];
    $cartas = $libro->getSheetByName('Cartas');
    if ($cartas !== null) {
        $ultimaCartaMeta = $cartas->getHighestDataRow();
        for ($r = 2; $r <= $ultimaCartaMeta; $r++) {
            $id = trim((string)$cartas->getCell('A' . $r)->getValue());
            if ($id === '') {
                continue;
            }
            $cartasMeta[$id] = [
                'domicilio' => trim((string)$cartas->getCell('E' . $r)->getValue()),
                'localidad' => trim((string)$cartas->getCell('F' . $r)->getValue()),
                'cp' => trim((string)$cartas->getCell('G' . $r)->getValue()),
            ];
        }
        foreach ($filas as $i => $fila) {
            $id = (string)($fila['id_grupo'] ?? '');
            if ($id === '' || !isset($cartasMeta[$id])) {
                continue;
            }
            $meta = $cartasMeta[$id];
            if ($meta['domicilio'] !== '') {
                $filas[$i]['domicilio'] = $meta['domicilio'];
            }
            $filas[$i]['localidad'] = $meta['localidad'];
            $filas[$i]['cp'] = $meta['cp'];
        }
    }

    $indice = admin_cd_indexar_legajos($legajosData, $mesCorte);
    $asignacion = admin_cd_asignar_deuda($filas, $indice);
    $filas = $asignacion['filas'];
    $hermanosPorGrupo = $asignacion['hermanos_por_grupo'];

    $deudaPorGrupo = [];
    foreach ($hermanosPorGrupo as $idGrupo => $hermanos) {
        $suma = 0.0;
        foreach ($hermanos as $info) {
            $suma += (float)(($info['clasif']['deuda'] ?? 0));
        }
        if ($hermanos !== []) {
            $deudaPorGrupo[$idGrupo] = $suma;
        }
    }

    $coincidencias = 0;
    $sinCoincidencia = 0;
    foreach ($filas as $fila) {
        $r = (int)$fila['fila'];
        if ($fila['deuda'] === null) {
            $sinCoincidencia++;
            $nota = trim((string)($fila['nota'] ?? ''));
            if ($nota !== '') {
                $obs = trim((string)$fila['observaciones']);
                if ($obs === '') {
                    $alumnos->setCellValue('H' . $r, $nota);
                } elseif (mb_stripos($obs, $nota, 0, 'UTF-8') === false) {
                    $alumnos->setCellValue('H' . $r, $obs . ' | ' . $nota);
                }
            }
            continue;
        }

        $coincidencias++;
        $deuda = (float)$fila['deuda'];
        $alumnos->setCellValue('E' . $r, $deuda);
        $alumnos->getStyle('E' . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        $id = (string)$fila['id_grupo'];
        if (!array_key_exists($id, $deudaPorGrupo)) {
            $deudaPorGrupo[$id] = ($deudaPorGrupo[$id] ?? 0.0) + $deuda;
        }
    }

    if ($cartas !== null) {
        $colDeuda = 'O';
        $headerDeuda = trim((string)$cartas->getCell('O1')->getValue());
        if ($headerDeuda === '' || admin_cd_norm_texto($headerDeuda) === 'DEUDA') {
            $cartas->setCellValue('O1', 'Deuda');
        } else {
            $colDeuda = 'P';
            $cartas->setCellValue('P1', 'Deuda');
        }
        $ultimaCarta = $cartas->getHighestDataRow();
        for ($r = 2; $r <= $ultimaCarta; $r++) {
            $id = trim((string)$cartas->getCell('A' . $r)->getValue());
            if ($id === '' || !array_key_exists($id, $deudaPorGrupo)) {
                continue;
            }
            $cartas->setCellValue($colDeuda . $r, (float)$deudaPorGrupo[$id]);
            $cartas->getStyle($colDeuda . $r)->getNumberFormat()->setFormatCode('#,##0.00');
        }
    }

    admin_cd_agregar_hoja_control_legajos($libro, $filas, $legajosFaltantes);

    $dir = dirname($destino);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta del Excel nuevo.');
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro);
    $writer->setPreCalculateFormulas(false);
    $writer->save($destino);
    $libro->disconnectWorksheets();

    return [
        'filas' => $filas,
        'hermanos_por_grupo' => $hermanosPorGrupo,
        'coincidencias' => $coincidencias,
        'sin_coincidencia' => $sinCoincidencia,
        'ruta' => $destino,
    ];
}

/**
 * @return array<string, mixed>
 */
function admin_load_cartas_doc_data(): array
{
    if (!defined('_ACCESS')) {
        define('_ACCESS', true);
    }
    if (!defined('NATTA_ROOT')) {
        throw new RuntimeException('NATTA_ROOT no definido.');
    }

    require_once NATTA_ROOT . '/config/db.php';
    require_once __DIR__ . '/db_collate.php';

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log('admin cartas_doc conexión: ' . $conn->connect_error);
        throw new RuntimeException('Error interno del servidor. Intente más tarde.');
    }
    admin_mysqli_apply_collation($conn);

    $tipoFiltro = isset($_GET['tipo']) ? strtolower(trim((string)$_GET['tipo'])) : 'cd';
    if (!in_array($tipoFiltro, ['cd', 'nf', 'todas'], true)) {
        $tipoFiltro = 'cd';
    }
    // Deuda total del grupo: siempre hasta septiembre (mes lógico 7).
    $mesCorte = 7;
    $nombreMesCorte = 'SEPTIEMBRE';

    $buscar = isset($_GET['buscar']) ? trim((string)$_GET['buscar']) : '';
    $escuelaFiltro = isset($_GET['escuela']) ? strtoupper(trim((string)$_GET['escuela'])) : '';
    $escuelasValidas = ['CJ', 'HV', 'JA', 'JN', 'SC', 'MB', 'ET', 'SU'];
    if (!in_array($escuelaFiltro, $escuelasValidas, true)) {
        $escuelaFiltro = '';
    }

    $filasDb = admin_lf_ejecutar_sql_legajos($conn, admin_lf_sql_legajos_cuotas('legajos'));
    $conn->close();

    $legajosData = [];
    admin_lf_agregar_filas_a_legajos($legajosData, $filasDb, false);

    $generado = admin_cd_generar_excel($legajosData, null, $mesCorte);
    $hermanosPorGrupo = $generado['hermanos_por_grupo'] ?? [];
    $familias = [];

    // Metadatos del Excel (responsable, domicilio, etc.) por grupo.
    foreach ($generado['filas'] as $fila) {
        $idGrupo = (string)($fila['id_grupo'] ?? '');
        if ($idGrupo === '' && trim((string)($fila['alumno'] ?? '')) === '') {
            continue;
        }
        if ($idGrupo === '') {
            $idGrupo = '—';
        }
        if (!isset($familias[$idGrupo])) {
            $familias[$idGrupo] = [
                'nro_familia' => $idGrupo,
                'id_grupo' => $idGrupo,
                'colegio' => (string)($fila['colegio'] ?? ''),
                'responsable' => (string)($fila['responsable'] ?? ''),
                'domicilio' => (string)($fila['domicilio'] ?? ''),
                'localidad' => (string)($fila['localidad'] ?? ''),
                'cp' => (string)($fila['cp'] ?? ''),
                'alumnos' => [],
                'tiene_cd' => false,
                'tiene_nf' => false,
                'deuda_cd' => 0.0,
                'deuda_nf' => 0.0,
            ];
        }
        if ($familias[$idGrupo]['responsable'] === '' && trim((string)($fila['responsable'] ?? '')) !== '') {
            $familias[$idGrupo]['responsable'] = (string)$fila['responsable'];
        }
        if ($familias[$idGrupo]['domicilio'] === '' && trim((string)($fila['domicilio'] ?? '')) !== '') {
            $familias[$idGrupo]['domicilio'] = (string)$fila['domicilio'];
        }
        if ($familias[$idGrupo]['localidad'] === '' && trim((string)($fila['localidad'] ?? '')) !== '') {
            $familias[$idGrupo]['localidad'] = (string)$fila['localidad'];
        }
        if ($familias[$idGrupo]['cp'] === '' && trim((string)($fila['cp'] ?? '')) !== '') {
            $familias[$idGrupo]['cp'] = (string)$fila['cp'];
        }
    }

    // Alumnos y deudas: desde la DB por nro_familia (incluye hermanos sin legajo en el Excel).
    foreach ($familias as $idGrupo => $fam) {
        $meta = [
            'responsable' => (string)($fam['responsable'] ?? ''),
            'domicilio' => (string)($fam['domicilio'] ?? ''),
            'colegio' => (string)($fam['colegio'] ?? ''),
        ];
        $hermanos = $hermanosPorGrupo[$idGrupo] ?? [];

        if ($hermanos !== []) {
            $alumnos = [];
            $tieneCd = false;
            $tieneNf = false;
            $deudaCd = 0.0;
            $deudaNf = 0.0;
            foreach ($hermanos as $match) {
                $alumno = admin_cd_alumno_desde_match_db($match, $meta);
                if ($alumno['escuela'] === '') {
                    $alumno['escuela'] = admin_cd_prefijo_grupo($idGrupo);
                }
                $alumnos[] = $alumno;
                $deudaHastaSep = (float)$alumno['deuda'];
                $deudaCd += $deudaHastaSep;
                $deudaNf += $deudaHastaSep;
                if (!empty($alumno['cd'])) {
                    $tieneCd = true;
                }
                if (!empty($alumno['nf'])) {
                    $tieneNf = true;
                }
            }
            $nroFamDb = '';
            foreach ($hermanos as $match) {
                $nroFamDb = trim((string)($match['nro_familia'] ?? ''));
                if ($nroFamDb !== '') {
                    break;
                }
            }
            $familias[$idGrupo]['alumnos'] = $alumnos;
            $familias[$idGrupo]['tiene_cd'] = $tieneCd;
            $familias[$idGrupo]['tiene_nf'] = $tieneNf;
            $familias[$idGrupo]['deuda_cd'] = $deudaCd;
            $familias[$idGrupo]['deuda_nf'] = $deudaNf;
            $familias[$idGrupo]['nro_familia_db'] = $nroFamDb;
            continue;
        }

        // Sin legajo ancla en el Excel: solo filas que hayan matcheado en DB.
        $nroFamDb = '';
        foreach ($generado['filas'] as $fila) {
            if ((string)($fila['id_grupo'] ?? '') !== $idGrupo) {
                continue;
            }
            $match = $fila['match'] ?? null;
            if (!is_array($match)) {
                continue;
            }
            if ($nroFamDb === '') {
                $nroFamDb = trim((string)($match['nro_familia'] ?? ''));
            }
            if (!empty($match['excluido_cd'])) {
                continue;
            }
            $alumno = admin_cd_alumno_desde_match_db($match, $meta);
            if ($alumno['escuela'] === '') {
                $alumno['escuela'] = admin_cd_prefijo_grupo($idGrupo);
            }
            $familias[$idGrupo]['alumnos'][] = $alumno;
            $familias[$idGrupo]['deuda_cd'] += (float)$alumno['deuda'];
            $familias[$idGrupo]['deuda_nf'] += (float)$alumno['deuda'];
            if (!empty($alumno['cd'])) {
                $familias[$idGrupo]['tiene_cd'] = true;
            }
            if (!empty($alumno['nf'])) {
                $familias[$idGrupo]['tiene_nf'] = true;
            }
        }
        $familias[$idGrupo]['nro_familia_db'] = $nroFamDb;
    }

    $buscarNorm = admin_cd_norm_texto($buscar);
    $familiasFiltradas = [];
    $familiasDbYaIncluidas = [];

    foreach ($familias as $idFam => $fam) {
        if ($tipoFiltro === 'cd' && empty($fam['tiene_cd'])) {
            continue;
        }
        if ($tipoFiltro === 'nf' && empty($fam['tiene_nf'])) {
            continue;
        }
        if ($tipoFiltro === 'todas' && empty($fam['tiene_cd']) && empty($fam['tiene_nf'])) {
            continue;
        }

        // Escuela: entra si el alumno de esa sede lleva CD/NF (según filtro).
        // Después se muestran también todos los hermanos del grupo.
        if ($escuelaFiltro !== '') {
            $familiaTieneEscuela = false;
            foreach ($fam['alumnos'] as $alumno) {
                if (strtoupper((string)$alumno['escuela']) !== $escuelaFiltro) {
                    continue;
                }
                if ($tipoFiltro === 'cd' && !empty($alumno['cd'])) {
                    $familiaTieneEscuela = true;
                    break;
                }
                if ($tipoFiltro === 'nf' && !empty($alumno['nf'])) {
                    $familiaTieneEscuela = true;
                    break;
                }
                if ($tipoFiltro === 'todas' && (!empty($alumno['cd']) || !empty($alumno['nf']))) {
                    $familiaTieneEscuela = true;
                    break;
                }
            }
            if (!$familiaTieneEscuela) {
                continue;
            }
        }

        // Una sola ficha/carta por familia de la DB (el Excel puede repetir el mismo grupo).
        $nroFamDb = trim((string)($fam['nro_familia_db'] ?? ''));
        if ($nroFamDb !== '') {
            if (isset($familiasDbYaIncluidas[$nroFamDb])) {
                continue;
            }
            $familiasDbYaIncluidas[$nroFamDb] = $idFam;
        }

        $alumnosVisibles = $fam['alumnos'];
        if ($buscarNorm !== '') {
            $busquedaCoincide = false;
            foreach ($fam['alumnos'] as $alumno) {
                $texto = admin_cd_norm_texto(
                    ($fam['id_grupo'] ?? '') . ' ' .
                    ($fam['colegio'] ?? '') . ' ' .
                    ($fam['responsable'] ?? '') . ' ' .
                    ($fam['domicilio'] ?? '') . ' ' .
                    ($alumno['alumno'] ?? '') . ' ' .
                    ($alumno['nro_legajo'] ?? '') . ' ' .
                    ($alumno['dni_alumno'] ?? '') . ' ' .
                    ($alumno['curso'] ?? '')
                );
                if (mb_strpos($texto, $buscarNorm, 0, 'UTF-8') !== false) {
                    $busquedaCoincide = true;
                    break;
                }
            }
            if (!$busquedaCoincide) {
                continue;
            }
        }

        if ($alumnosVisibles === []) {
            continue;
        }

        usort($alumnosVisibles, static function (array $a, array $b): int {
            return strnatcasecmp((string)$a['alumno'], (string)$b['alumno']);
        });

        $deudaCd = 0.0;
        $deudaNf = 0.0;
        $tieneCd = false;
        $tieneNf = false;
        foreach ($alumnosVisibles as $alumno) {
            if (!empty($alumno['cd'])) {
                $tieneCd = true;
            }
            if (!empty($alumno['nf'])) {
                $tieneNf = true;
            }
            // Total hasta septiembre: suma de todos los hermanos del grupo.
            $deudaHastaSep = (float)($alumno['deuda'] ?? 0);
            $deudaCd += $deudaHastaSep;
            $deudaNf += $deudaHastaSep;
        }

        $fam['alumnos'] = $alumnosVisibles;
        $fam['tiene_cd'] = $tieneCd;
        $fam['tiene_nf'] = $tieneNf;
        $fam['deuda_cd'] = $deudaCd;
        $fam['deuda_nf'] = $deudaNf;
        $familiasFiltradas[$idFam] = $fam;
    }

    uksort($familiasFiltradas, static function ($a, $b): int {
        return strnatcasecmp((string)$a, (string)$b);
    });

    $totalFamilias = count($familiasFiltradas);
    $totalAlumnos = 0;
    $totalFamiliasCd = 0;
    $totalFamiliasNf = 0;
    $totalAlumnosCd = 0;
    $totalAlumnosNf = 0;
    $montoCd = 0.0;
    $montoNf = 0.0;

    foreach ($familiasFiltradas as $fam) {
        if (!empty($fam['tiene_cd'])) {
            $totalFamiliasCd++;
            $montoCd += (float)$fam['deuda_cd'];
        }
        if (!empty($fam['tiene_nf'])) {
            $totalFamiliasNf++;
            $montoNf += (float)$fam['deuda_nf'];
        }
        foreach ($fam['alumnos'] as $alumno) {
            $totalAlumnos++;
            if (!empty($alumno['cd'])) {
                $totalAlumnosCd++;
            }
            if (!empty($alumno['nf'])) {
                $totalAlumnosNf++;
            }
        }
    }

    $filasCotejo = $generado['filas'] ?? [];
    $coincidencias = (int)$generado['coincidencias'];
    $sinCoincidencia = (int)$generado['sin_coincidencia'];
    $rutaExcelRelativa = admin_cd_ruta_salida_relativa();

    return compact(
        'tipoFiltro',
        'buscar',
        'escuelaFiltro',
        'mesCorte',
        'nombreMesCorte',
        'familiasFiltradas',
        'totalFamilias',
        'totalAlumnos',
        'totalFamiliasCd',
        'totalFamiliasNf',
        'totalAlumnosCd',
        'totalAlumnosNf',
        'montoCd',
        'montoNf',
        'coincidencias',
        'sinCoincidencia',
        'rutaExcelRelativa',
        'filasCotejo'
    );
}

/**
 * @param array<string, mixed> $data
 */
function admin_export_cartas_doc_csv(array $data): void
{
    $tipoFiltro = (string)($data['tipoFiltro'] ?? 'cd');
    $familias = $data['familiasFiltradas'] ?? [];
    $nombre = 'cartas_doc_' . $tipoFiltro . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Id grupo',
        'Colegio',
        'Responsable',
        'Domicilio',
        'Documento',
        'Legajo',
        'Alumno',
        'Curso',
        'DNI',
        'Meses que definen CD o NF',
        'Deuda de esos meses',
        'Deuda hasta el corte',
    ], ';');

    foreach ($familias as $familia) {
        foreach ($familia['alumnos'] as $alumno) {
            $documento = !empty($alumno['cd']) ? 'CD' : 'NF';
            fputcsv($out, [
                $familia['id_grupo'] ?? $familia['nro_familia'],
                $familia['colegio'] ?? '',
                $alumno['responsable'] !== '' ? $alumno['responsable'] : ($familia['responsable'] ?? ''),
                $alumno['domicilio'] !== '' ? $alumno['domicilio'] : ($familia['domicilio'] ?? ''),
                $documento,
                $alumno['nro_legajo'],
                $alumno['alumno'],
                $alumno['curso'],
                $alumno['dni_alumno'],
                admin_cd_formato_meses_lista($alumno['meses_documento'] ?? []),
                number_format((float)($alumno['deuda_documento'] ?? 0), 2, ',', ''),
                number_format((float)($alumno['deuda'] ?? 0), 2, ',', ''),
            ], ';');
        }
    }

    fclose($out);
}

/**
 * @param list<array<string, mixed>> $filas
 */
function admin_export_cartas_doc_cotejo(array $filas): void
{
    admin_cd_cargar_phpspreadsheet();

    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Cotejo');
    $hoja->fromArray([['Legajo-Excel', 'Legajo-DB', 'NombreAlumno-Excel', 'NombreAlumno-DB']], null, 'A1');

    $filaExcel = 2;
    foreach ($filas as $fila) {
        $match = $fila['match'] ?? null;
        $legajoDb = '';
        $nombreDb = '';
        if (is_array($match)) {
            $legajoDb = (string)($match['nro_legajo'] ?? '');
            $nombreDb = trim(
                (string)($match['apellido_alumno'] ?? '') . ' ' . (string)($match['nombre_alumno'] ?? '')
            );
        }
        $hoja->setCellValueExplicit('A' . $filaExcel, (string)($fila['legajo'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hoja->setCellValueExplicit('B' . $filaExcel, $legajoDb, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hoja->setCellValueExplicit('C' . $filaExcel, (string)($fila['alumno'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hoja->setCellValueExplicit('D' . $filaExcel, $nombreDb, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $filaExcel++;
    }

    foreach (['A', 'B', 'C', 'D'] as $col) {
        $hoja->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="cotejo-legajos.xlsx"');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro);
    $writer->setPreCalculateFormulas(false);
    $writer->save('php://output');
    $libro->disconnectWorksheets();
}

function admin_cd_ruta_modelo(): string
{
    return NATTA_ROOT . '/docs/cartas-documento/modelo-CD/MODELO CARTA DOC.docx';
}

function admin_cd_xml(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function admin_cd_grupo_en_letras(int $n): string
{
    $unidades = [
        '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
    ];
    $decenas = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
    $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];
    $veinti = [
        20 => 'veinte', 21 => 'veintiuno', 22 => 'veintidós', 23 => 'veintitrés', 24 => 'veinticuatro',
        25 => 'veinticinco', 26 => 'veintiséis', 27 => 'veintisiete', 28 => 'veintiocho', 29 => 'veintinueve',
    ];

    if ($n <= 0) {
        return '';
    }
    if ($n === 100) {
        return 'cien';
    }

    $partes = [];
    $c = intdiv($n, 100);
    $resto = $n % 100;
    if ($c > 0) {
        $partes[] = $centenas[$c];
    }
    if ($resto > 0 && $resto < 20) {
        $partes[] = $unidades[$resto];
    } elseif ($resto >= 20 && $resto < 30) {
        $partes[] = $veinti[$resto];
    } elseif ($resto >= 30) {
        $d = intdiv($resto, 10);
        $u = $resto % 10;
        $partes[] = $u === 0 ? $decenas[$d] : ($decenas[$d] . ' y ' . $unidades[$u]);
    }

    return implode(' ', $partes);
}

function admin_cd_entero_en_letras(int $n): string
{
    if ($n === 0) {
        return 'cero';
    }
    if ($n < 0) {
        return 'menos ' . admin_cd_entero_en_letras(abs($n));
    }

    $partes = [];
    $millones = intdiv($n, 1000000);
    $miles = intdiv($n % 1000000, 1000);
    $resto = $n % 1000;

    if ($millones > 0) {
        if ($millones === 1) {
            $partes[] = 'un millón';
        } else {
            $partes[] = preg_replace('/uno$/', 'un', admin_cd_grupo_en_letras($millones)) . ' millones';
        }
    }
    if ($miles > 0) {
        if ($miles === 1) {
            $partes[] = 'mil';
        } else {
            $partes[] = preg_replace('/uno$/', 'un', admin_cd_grupo_en_letras($miles)) . ' mil';
        }
    }
    if ($resto > 0) {
        $partes[] = admin_cd_grupo_en_letras($resto);
    }

    return implode(' ', $partes);
}

function admin_cd_monto_carta(float $monto): string
{
    $monto = round(max(0, $monto), 2);
    $entero = (int)floor($monto);
    $centavos = (int)round(($monto - $entero) * 100);
    if ($centavos === 100) {
        $entero++;
        $centavos = 0;
    }
    $letras = admin_cd_entero_en_letras($entero);
    $numero = number_format($entero + ($centavos / 100), 2, ',', '.');

    return $letras . ' con ' . str_pad((string)$centavos, 2, '0', STR_PAD_LEFT) . '/100 ($ ' . $numero . '.-)';
}

function admin_cd_nombre_colegio_carta(string $colegio): string
{
    $clave = admin_cd_norm_texto($colegio);
    $map = [
        'CASITA DE JESUS' => 'Jardín Casita de Jesús',
        'JARDIN DE LA ALEGRIA' => 'Jardín de la Alegría',
        'LA HORMIGUITA VIAJERA' => 'Jardín La Hormiguita Viajera',
        'JESUS NINO' => 'Instituto Jesús Niño',
        'SANTA CRUZ' => 'Instituto Santa Cruz',
        'MANUEL BELGRANO' => 'Instituto Manuel Belgrano',
        'IDET' => 'Instituto de Educación Técnica',
    ];

    return $map[$clave] ?? trim($colegio);
}

/**
 * @return array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}
 */
function admin_cd_datos_carta_familia(array $familia): array
{
    // Carta por familia: alumno con CD y todos los hermanos del grupo.
    $alumnosGrupo = $familia['alumnos'] ?? [];
    if ($alumnosGrupo === []) {
        $alumnosGrupo = [];
    }

    $nombres = [];
    $colegios = [];
    $responsable = trim((string)($familia['responsable'] ?? ''));
    $domicilio = trim((string)($familia['domicilio'] ?? ''));
    $localidad = trim((string)($familia['localidad'] ?? ''));
    $cp = trim((string)($familia['cp'] ?? ''));
    foreach ($alumnosGrupo as $alumno) {
        // Nombre completo desde la DB (apellido + nombre), sin coma para que la
        // lista "A, B y C" no se mezcle con el separador.
        $apellido = trim((string)($alumno['apellido_alumno'] ?? ''));
        $nombre = trim((string)($alumno['nombre_alumno'] ?? ''));
        if ($apellido !== '' || $nombre !== '') {
            $nombres[] = trim(preg_replace('/\s+/', ' ', $apellido . ' ' . $nombre) ?? ($apellido . ' ' . $nombre));
        } else {
            $plano = trim((string)($alumno['alumno'] ?? ''));
            if ($plano !== '') {
                $nombres[] = $plano;
            }
        }
        $colegio = admin_cd_nombre_colegio_carta((string)($alumno['colegio'] ?? ''));
        if ($colegio === '') {
            $colegio = admin_cd_nombre_colegio_carta((string)($familia['colegio'] ?? ''));
        }
        if ($colegio !== '') {
            $colegios[$colegio] = true;
        }
        if ($responsable === '' && trim((string)($alumno['responsable'] ?? '')) !== '') {
            $responsable = trim((string)$alumno['responsable']);
        }
        if ($domicilio === '' && trim((string)($alumno['domicilio'] ?? '')) !== '') {
            $domicilio = trim((string)$alumno['domicilio']);
        }
    }
    $nombres = array_values(array_unique($nombres));
    if ($colegios === []) {
        $colegioFamilia = admin_cd_nombre_colegio_carta((string)($familia['colegio'] ?? ''));
        if ($colegioFamilia !== '') {
            $colegios[$colegioFamilia] = true;
        }
    }

    $cantidad = count($nombres);
    if ($cantidad > 1) {
        $ultimo = array_pop($nombres);
        $lista = $cantidad === 2 ? ($nombres[0] . ' y ' . $ultimo) : (implode(', ', $nombres) . ' y ' . $ultimo);
        $tratamiento = 'sus hijos';
    } else {
        $lista = $nombres[0] ?? '';
        $tratamiento = 'su hijo';
    }

    $colegioTxt = implode(' y ', array_keys($colegios));
    if ($colegioTxt === '') {
        $colegioTxt = 'Jardín Casita de Jesús';
    }
    if ($responsable === '') {
        $responsable = 'Responsable no informado';
    }
    if ($domicilio === '') {
        $domicilio = 'Domicilio no informado';
    }
    if ($localidad === '') {
        $localidad = 'Localidad no informada';
    }
    if ($cp === '') {
        $cp = 'CP no informado';
    }

    return [$colegioTxt, $responsable, $domicilio, $tratamiento, $lista, $localidad, $cp];
}

function admin_cd_rellenar_modelo_xml(string $xml, array $familia): string
{
    [$colegio, $responsable, $domicilio, $tratamiento, $alumnos, $localidad, $cp] = admin_cd_datos_carta_familia($familia);
    $monto = admin_cd_monto_carta((float)($familia['deuda_cd'] ?? 0));
    $plural = str_starts_with($tratamiento, 'sus ');

    $xml = str_replace('Jardín Casita de Jesús- Complejo ', admin_cd_xml($colegio) . '- Complejo ', $xml);
    $xml = str_replace('>IANATELLI</w:t>', '>' . admin_cd_xml($responsable) . '</w:t>', $xml);
    $xml = str_replace('<w:t>, Omar</w:t>', '<w:t></w:t>', $xml);
    $xml = str_replace('<w:t>, Marina Soledad</w:t>', '<w:t></w:t>', $xml);
    $xml = str_replace('<w:t>Aguilar, José Oscar</w:t>', '<w:t></w:t>', $xml);
    $xml = str_replace('<w:t>Garay   N° 1163</w:t>', '<w:t>' . admin_cd_xml($domicilio) . '</w:t>', $xml);
    $xml = str_replace('<w:t>Garay</w:t>', '<w:t>' . admin_cd_xml($domicilio) . '</w:t>', $xml);
    $xml = str_replace('<w:t xml:space="preserve"> N° </w:t>', '<w:t></w:t>', $xml);
    $xml = str_replace('<w:t>1163</w:t>', '<w:t></w:t>', $xml);
    $xml = str_replace('<w:t>Ezeiza</w:t>', '<w:t>' . admin_cd_xml($localidad) . '</w:t>', $xml);
    $xml = preg_replace(
        '/<w:t>18<\/w:t><\/w:r><w:r\b[^>]*>\s*<w:rPr>.*?<\/w:rPr>\s*<w:t>04<\/w:t>/s',
        '<w:t>' . admin_cd_xml($cp) . '</w:t></w:r><w:r><w:t></w:t>',
        $xml
    );
    $xml = preg_replace(
        '/<w:t>18<\/w:t><\/w:r><w:r\b[^>]*>\s*<w:rPr>.*?<\/w:rPr>\s*<w:t>0<\/w:t><\/w:r><w:r\b[^>]*>\s*<w:rPr>.*?<\/w:rPr>\s*<w:t>4<\/w:t>/s',
        '<w:t>' . admin_cd_xml($cp) . '</w:t></w:r><w:r><w:t></w:t></w:r><w:r><w:t></w:t>',
        $xml
    );
    $xml = str_replace(
        'cuatrocientos ochenta y seis mil trescientos ocho con 59/100 ($ 486.308,59.-)',
        admin_cd_xml($monto),
        $xml
    );
    $xml = str_replace('IANATELLI, Máximo ', admin_cd_xml($alumnos) . ' ', $xml);

    if ($plural) {
        $xml = str_replace('enseñanza brindada a su</w:t>', 'enseñanza brindada a sus</w:t>', $xml);
        $xml = str_replace('<w:t xml:space="preserve"> hij</w:t>', '<w:t xml:space="preserve"> hijos</w:t>', $xml);
        $xml = preg_replace(
            '/(<w:t xml:space="preserve"> hijos<\/w:t><\/w:r><w:r\b[^>]*>\s*<w:rPr>.*?<\/w:rPr>\s*<w:t>)o(<\/w:t>)/s',
            '$1$2',
            $xml
        );
        $xml = str_replace('<w:t xml:space="preserve"> a su</w:t>', '<w:t xml:space="preserve"> a sus</w:t>', $xml);
        $xml = str_replace('<w:t xml:space="preserve"> hijo</w:t>', '<w:t xml:space="preserve"> hijos</w:t>', $xml);
    }

    return $xml;
}

function admin_cd_docx_familia(array $familia): string
{
    $modelo = admin_cd_ruta_modelo();
    if (!is_file($modelo)) {
        throw new RuntimeException('No se encontró el modelo de carta documento.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cdmod');
    if ($tmp === false || !copy($modelo, $tmp)) {
        throw new RuntimeException('No se pudo copiar el modelo de carta documento.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo abrir el modelo de carta documento.');
    }
    $xml = $zip->getFromName('word/document.xml');
    if (!is_string($xml) || $xml === '') {
        $zip->close();
        @unlink($tmp);
        throw new RuntimeException('El modelo de carta documento no tiene texto.');
    }
    $zip->addFromString('word/document.xml', admin_cd_rellenar_modelo_xml($xml, $familia));
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    if ($bin === false) {
        throw new RuntimeException('No se pudo armar la carta documento.');
    }

    return $bin;
}

/**
 * @param array<string, array<string, mixed>> $familias
 */
function admin_export_cartas_documento(array $familias): void
{
    $cartas = [];
    foreach ($familias as $familia) {
        if (empty($familia['tiene_cd'])) {
            continue;
        }
        // Una carta por familia de la DB: ya incluye a todos los hermanos.
        $nroFamDb = trim((string)($familia['nro_familia_db'] ?? ''));
        $clave = $nroFamDb !== ''
            ? 'db:' . $nroFamDb
            : 'grupo:' . (string)($familia['id_grupo'] ?? $familia['nro_familia'] ?? '');
        if ($clave === 'grupo:' || isset($cartas[$clave])) {
            continue;
        }
        $cartas[$clave] = $familia;
    }

    if ($cartas === []) {
        throw new RuntimeException('No hay cartas documento para generar.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cdzip');
    if ($tmp === false) {
        throw new RuntimeException('No se pudo crear el archivo de cartas.');
    }
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('No se pudo crear el archivo de cartas.');
    }

    foreach ($cartas as $familia) {
        $id = (string)($familia['id_grupo'] ?? $familia['nro_familia'] ?? 'familia');
        $nroFamDb = trim((string)($familia['nro_familia_db'] ?? ''));
        $responsable = trim((string)($familia['responsable'] ?? 'familia'));
        $nombre = 'CD ' . ($nroFamDb !== '' ? ('Fam ' . $nroFamDb . ' ') : '') . $id . ' ' . $responsable;
        $nombre = preg_replace('/[^\p{L}\p{N} _.-]+/u', '', $nombre) ?? $nombre;
        $nombre = trim(preg_replace('/\s+/', ' ', $nombre) ?? $nombre);
        if ($nombre === '') {
            $nombre = 'CD ' . $id;
        }
        $zip->addFromString($nombre . '.docx', admin_cd_docx_familia($familia));
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="cartas-documento.zip"');
    header('Content-Length: ' . (string)filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
}
