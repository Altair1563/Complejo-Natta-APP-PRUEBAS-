<?php
/**
 * Contexto financiero de la familia para el frontend (vía AJAX, no en DOM).
 */

require_once __DIR__ . '/ingresantes_externos_2027.php';

function familia_ctx_fetch_all_from_stmt(mysqli_stmt $stmt): array
{
    if (function_exists('fetchAllFromStmt')) {
        return fetchAllFromStmt($stmt);
    }

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

function familia_ctx_es_cuota_futura(int $numCuota, int $cuotaVigente, int $mesActual, string $curso = ''): bool
{
    return cuota_es_futura_para_curso($numCuota, $cuotaVigente, $mesActual, $curso);
}

function familia_ctx_cuota_vigente(mysqli $conn): int
{
    $cuotaVigente = 1;
    $stmt = $conn->prepare("SELECT valor FROM configuracion WHERE clave = 'cuota_vigente' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $stmt->bind_result($cv);
        if ($stmt->fetch()) {
            $cuotaVigente = (int)$cv;
        }
        $stmt->close();
    }

    return max(1, min(12, $cuotaVigente));
}

/**
 * @return array{nroFamilia: int, saldoTotalFamiliar: float, alumnos: list<array<string, mixed>>, autolegajo: ?string}
 */
function familia_ctx_build(mysqli $conn, int $nroFamilia): array
{
    $cuotaVigente = familia_ctx_cuota_vigente($conn);
    $mesActual = (int)date('n');
    $alumnosRaw = [];

    $sqlActivos = 'SELECT nro_legajo, nombre_alumno, apellido_alumno, curso, dni_alumno
                   FROM legajos WHERE nro_familia = ? ORDER BY apellido_alumno, nombre_alumno';
    $stmt = $conn->prepare($sqlActivos);
    if ($stmt) {
        $stmt->bind_param('i', $nroFamilia);
        $stmt->execute();
        foreach (familia_ctx_fetch_all_from_stmt($stmt) as $row) {
            $row['es_inactivo'] = false;
            $alumnosRaw[] = $row;
        }
        $stmt->close();
    }

    $sqlInactivos = 'SELECT nro_legajo, nombre_alumno, apellido_alumno, curso, dni_alumno
                     FROM legajos_inactivos WHERE nro_familia = ? ORDER BY apellido_alumno, nombre_alumno';
    $stmt = $conn->prepare($sqlInactivos);
    if ($stmt) {
        $stmt->bind_param('i', $nroFamilia);
        $stmt->execute();
        foreach (familia_ctx_fetch_all_from_stmt($stmt) as $row) {
            $row['es_inactivo'] = true;
            $alumnosRaw[] = $row;
        }
        $stmt->close();
    }

    $saldoTotal = 0.0;
    $alumnos = [];
    foreach ($alumnosRaw as $alumno) {
        $legajo = (string)($alumno['nro_legajo'] ?? '');
        $saldoVigente = 0.0;

        if ($legajo !== '') {
            $stmtCuotas = $conn->prepare(
                'SELECT numero_cuota, diferencia FROM cuotas WHERE nro_legajo = ? AND diferencia > 0'
            );
            if ($stmtCuotas) {
                $stmtCuotas->bind_param('s', $legajo);
                $stmtCuotas->execute();
                foreach (familia_ctx_fetch_all_from_stmt($stmtCuotas) as $c) {
                    if (!familia_ctx_es_cuota_futura((int)$c['numero_cuota'], $cuotaVigente, $mesActual, (string)($alumno['curso'] ?? ''))) {
                        $saldoVigente += (float)$c['diferencia'];
                    }
                }
                $stmtCuotas->close();
            }
        }

        $saldoVigente = round($saldoVigente, 2);
        $saldoTotal += $saldoVigente;
        $alumnos[] = [
            'legajo' => $legajo,
            'nombre_completo' => trim(($alumno['nombre_alumno'] ?? '') . ' ' . ($alumno['apellido_alumno'] ?? '')),
            'curso' => (string)($alumno['curso'] ?? ''),
            'saldo' => $saldoVigente,
            'es_inactivo' => (bool)($alumno['es_inactivo'] ?? false),
        ];
    }

    $autolegajo = count($alumnosRaw) === 1 ? (string)($alumnosRaw[0]['nro_legajo'] ?? '') : null;
    if ($autolegajo === '') {
        $autolegajo = null;
    }

    return [
        'nroFamilia' => $nroFamilia,
        'saldoTotalFamiliar' => round($saldoTotal, 2),
        'alumnos' => $alumnos,
        'autolegajo' => $autolegajo,
    ];
}
