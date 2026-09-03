<?php
/**
 * Cuotas y solicitudes de talón por alumno (talón de pago).
 */

require_once __DIR__ . '/familia_context.php';

/**
 * @return array<string, list<array<string, mixed>>>
 */
function talon_ctx_build_cuotas_por_alumno(mysqli $conn, int $nroFamilia): array
{
    $nombresCuotas = [
        1 => 'Marzo', 2 => 'Abril', 3 => 'Mayo', 4 => 'Junio', 5 => 'Julio', 6 => 'Agosto',
        7 => 'Septiembre', 8 => 'Octubre', 9 => 'Noviembre', 10 => 'Adelanto de Reserva de vacante (2027)',
        11 => 'Resto Reserva de Vacante', 12 => 'Reserva de Vacante 2026',
    ];

    $cuotasPorAlumno = [];
    $stmt = $conn->prepare(
        'SELECT nro_legajo, curso FROM legajos WHERE nro_familia = ? ORDER BY apellido_alumno, nombre_alumno'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $nroFamilia);
    $stmt->execute();
    $alumnos = familia_ctx_fetch_all_from_stmt($stmt);
    $stmt->close();

    foreach ($alumnos as $alumno) {
        $legajo = (string)($alumno['nro_legajo'] ?? '');
        $curso = (string)($alumno['curso'] ?? '');
        if ($legajo === '') {
            continue;
        }

        $sqlCuotas = '
            SELECT c.id, c.numero_cuota, c.monto_facturado, c.diferencia
            FROM cuotas c
            INNER JOIN (
                SELECT numero_cuota, MAX(id) AS max_id
                FROM cuotas
                WHERE nro_legajo = ?
                GROUP BY numero_cuota
            ) ultimas ON c.id = ultimas.max_id
            WHERE c.nro_legajo = ?
            ORDER BY c.numero_cuota ASC
        ';
        $stmtCuotas = $conn->prepare($sqlCuotas);
        if (!$stmtCuotas) {
            continue;
        }
        $stmtCuotas->bind_param('ss', $legajo, $legajo);
        $stmtCuotas->execute();
        $cuotas = familia_ctx_fetch_all_from_stmt($stmtCuotas);
        $stmtCuotas->close();

        foreach ($cuotas as &$cuota) {
            $cuota['pagada'] = ((float)($cuota['diferencia'] ?? 0)) <= 0;
            $num = (int)($cuota['numero_cuota'] ?? 0);
            $cuota['descripcion'] = cuota_nombre_para_curso($num, $curso, $nombresCuotas[$num] ?? null);
            $cuota['monto'] = $cuota['monto_facturado'];
        }
        unset($cuota);
        if (curso_es_ingresante_externo_2027($curso)) {
            $cuotas = array_values(array_filter($cuotas, static function ($cuota) {
                return (int)($cuota['numero_cuota'] ?? 0) === ingresante_externo_2027_numero_cuota();
            }));
        }
        $cuotasPorAlumno[$legajo] = $cuotas;
    }

    $todosIdsCuotas = [];
    foreach ($cuotasPorAlumno as $cuotas) {
        foreach ($cuotas as $cuota) {
            $todosIdsCuotas[] = (int)$cuota['id'];
        }
    }

    $solicitudesPorCuota = [];
    if ($todosIdsCuotas !== []) {
        $placeholders = implode(',', array_fill(0, count($todosIdsCuotas), '?'));
        $sqlSolicitudes = "SELECT cuota_id, estado, fecha_solicitud FROM solicitudes_talon
                           WHERE cuota_id IN ($placeholders) ORDER BY cuota_id, fecha_solicitud DESC";
        $stmtSolicitudes = $conn->prepare($sqlSolicitudes);
        if ($stmtSolicitudes) {
            $types = str_repeat('i', count($todosIdsCuotas));
            $stmtSolicitudes->bind_param($types, ...$todosIdsCuotas);
            $stmtSolicitudes->execute();
            foreach (familia_ctx_fetch_all_from_stmt($stmtSolicitudes) as $row) {
                $cuotaId = (int)($row['cuota_id'] ?? 0);
                if (!isset($solicitudesPorCuota[$cuotaId])) {
                    $solicitudesPorCuota[$cuotaId] = $row;
                }
            }
            $stmtSolicitudes->close();
        }
    }

    foreach ($cuotasPorAlumno as &$cuotas) {
        foreach ($cuotas as &$cuota) {
            $cuotaId = (int)($cuota['id'] ?? 0);
            if (isset($solicitudesPorCuota[$cuotaId])) {
                $cuota['solicitud_estado'] = $solicitudesPorCuota[$cuotaId]['estado'];
                $cuota['solicitud_fecha'] = $solicitudesPorCuota[$cuotaId]['fecha_solicitud'];
                $cuota['solicitud_activa'] = ($solicitudesPorCuota[$cuotaId]['estado'] ?? '') !== 'generado';
            } else {
                $cuota['solicitud_estado'] = null;
                $cuota['solicitud_fecha'] = null;
                $cuota['solicitud_activa'] = false;
            }
        }
        unset($cuota);
    }
    unset($cuotas);

    return $cuotasPorAlumno;
}
