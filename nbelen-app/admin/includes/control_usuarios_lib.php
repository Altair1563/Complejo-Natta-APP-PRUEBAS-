<?php
/**
 * Datos del panel Dirección · Control de Usuarios.
 */

require_once __DIR__ . '/estado_alumno_lib.php';

/** Acciones de secretaría relevantes para el panel directivo. */
const CONTROL_USUARIOS_ACCIONES = [
    'login_ok',
    'consulta_estado_cuenta',
    'revision_contratos',
    'doc_recibida',
    'info_erronea',
    'bulk_doc_recibida',
];

function control_usuarios_h($str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * @return list<array{id:int,username:string,nombre:string,escuela_codigo:?string,ultimo_login:?string,activo:int}>
 */
function control_usuarios_listar_secretarias(PDO $pdo, string $escuelaFiltro = ''): array
{
    $escuelaFiltro = strtoupper(trim($escuelaFiltro));
    try {
        if ($escuelaFiltro !== '' && $escuelaFiltro !== 'ALL') {
            $stmt = $pdo->prepare(
                "SELECT id, username, nombre, escuela_codigo, ultimo_login, activo
                 FROM admin_users
                 WHERE rol = 'secretaria' AND activo = 1 AND UPPER(TRIM(escuela_codigo)) = ?
                 ORDER BY nombre ASC, username ASC"
            );
            $stmt->execute([$escuelaFiltro]);
        } else {
            $stmt = $pdo->query(
                "SELECT id, username, nombre, escuela_codigo, ultimo_login, activo
                 FROM admin_users
                 WHERE rol = 'secretaria' AND activo = 1
                 ORDER BY escuela_codigo ASC, nombre ASC, username ASC"
            );
        }

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        error_log('control_usuarios secretarias: ' . $e->getMessage());

        return [];
    }
}

/**
 * Actividad reciente de secretarías (auditoría).
 * Filtrar por escuela eligiendo previamente los IDs de secretaría de esa escuela.
 *
 * @param list<int> $secretariaIds
 * @return list<array<string, mixed>>
 */
function control_usuarios_actividad_reciente(
    PDO $pdo,
    array $secretariaIds,
    string $escuelaFiltro = '',
    int $limit = 80
): array {
    unset($escuelaFiltro); // Las secretarías ya vienen acotadas por escuela.
    $secretariaIds = array_values(array_filter(array_map('intval', $secretariaIds), static fn ($id) => $id > 0));
    if ($secretariaIds === []) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $placeholders = implode(',', array_fill(0, count($secretariaIds), '?'));
    $acciones = CONTROL_USUARIOS_ACCIONES;
    $accionPlaceholders = implode(',', array_fill(0, count($acciones), '?'));

    $sql = "SELECT l.id, l.created_at, l.admin_user_id, l.accion, l.detalle,
                   u.nombre AS usuario_nombre, u.username, u.escuela_codigo
            FROM admin_audit_log l
            INNER JOIN admin_users u ON u.id = l.admin_user_id
            WHERE l.admin_user_id IN ($placeholders)
              AND l.accion IN ($accionPlaceholders)
            ORDER BY l.created_at DESC
            LIMIT $limit";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($secretariaIds, $acciones));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('control_usuarios actividad: ' . $e->getMessage());

        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $detalle = [];
        if (!empty($row['detalle'])) {
            $decoded = json_decode((string)$row['detalle'], true);
            if (is_array($decoded)) {
                $detalle = $decoded;
            }
        }
        $escuelaDetalle = strtoupper(trim((string)($detalle['escuela'] ?? '')));
        $escuelaUser = strtoupper(trim((string)($row['escuela_codigo'] ?? '')));
        $escuela = $escuelaDetalle !== '' ? $escuelaDetalle : $escuelaUser;

        $out[] = [
            'id' => (int)$row['id'],
            'created_at' => (string)$row['created_at'],
            'admin_user_id' => (int)$row['admin_user_id'],
            'usuario_nombre' => (string)$row['usuario_nombre'],
            'username' => (string)$row['username'],
            'accion' => (string)$row['accion'],
            'escuela' => $escuela,
            'detalle' => $detalle,
        ];
    }

    return $out;
}

/**
 * Contadores de actividad por secretaría (últimos N días).
 *
 * @param list<int> $secretariaIds
 * @return array<int, array{consultas_cuenta:int,revisiones:int,doc_recibida:int,info_erronea:int,logins:int}>
 */
function control_usuarios_resumen_por_secretaria(PDO $pdo, array $secretariaIds, int $dias = 14): array
{
    $secretariaIds = array_values(array_filter(array_map('intval', $secretariaIds), static fn ($id) => $id > 0));
    $base = [
        'consultas_cuenta' => 0,
        'revisiones' => 0,
        'doc_recibida' => 0,
        'info_erronea' => 0,
        'logins' => 0,
    ];
    $map = [];
    foreach ($secretariaIds as $id) {
        $map[$id] = $base;
    }
    if ($secretariaIds === []) {
        return $map;
    }

    $dias = max(1, min(90, $dias));
    $placeholders = implode(',', array_fill(0, count($secretariaIds), '?'));
    $sql = "SELECT admin_user_id, accion, COUNT(*) AS total
            FROM admin_audit_log
            WHERE admin_user_id IN ($placeholders)
              AND created_at >= (NOW() - INTERVAL {$dias} DAY)
              AND accion IN ('login_ok','consulta_estado_cuenta','revision_contratos','doc_recibida','info_erronea','bulk_doc_recibida')
            GROUP BY admin_user_id, accion";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($secretariaIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $uid = (int)$row['admin_user_id'];
            if (!isset($map[$uid])) {
                continue;
            }
            $total = (int)$row['total'];
            switch ((string)$row['accion']) {
                case 'login_ok':
                    $map[$uid]['logins'] += $total;
                    break;
                case 'consulta_estado_cuenta':
                    $map[$uid]['consultas_cuenta'] += $total;
                    break;
                case 'revision_contratos':
                    $map[$uid]['revisiones'] += $total;
                    break;
                case 'doc_recibida':
                case 'bulk_doc_recibida':
                    $map[$uid]['doc_recibida'] += $total;
                    break;
                case 'info_erronea':
                    $map[$uid]['info_erronea'] += $total;
                    break;
            }
        }
    } catch (Throwable $e) {
        error_log('control_usuarios resumen: ' . $e->getMessage());
    }

    return $map;
}

/**
 * Avance de revisión de contratos de una escuela.
 *
 * @return array{total:int,firmados:int,doc_recibida:int,info_erronea:int,pendientes_doc:int,error:string}
 */
function control_usuarios_avance_contratos(mysqli $conn, string $escuelaCodigo): array
{
    $escuelaCodigo = strtoupper(trim($escuelaCodigo));
    $empty = [
        'total' => 0,
        'firmados' => 0,
        'doc_recibida' => 0,
        'info_erronea' => 0,
        'pendientes_doc' => 0,
        'error' => '',
    ];
    if ($escuelaCodigo === '' || strlen($escuelaCodigo) !== 2) {
        $empty['error'] = 'Escuela no válida.';

        return $empty;
    }

    try {
        estado_alumno_ensure_contratos_schema($conn);
        $contractVersion = estado_alumno_obtener_contrato_vigente_por_escuela($conn, $escuelaCodigo);
        $alumnos = estado_alumno_cargar_revision_contratos_por_curso(
            $conn,
            '',
            $contractVersion,
            '',
            $escuelaCodigo
        );
    } catch (Throwable $e) {
        error_log('control_usuarios avance: ' . $e->getMessage());
        $empty['error'] = 'No se pudo cargar el avance de contratos.';

        return $empty;
    }

    $firmados = 0;
    $doc = 0;
    $erronea = 0;
    foreach ($alumnos as $alumno) {
        if (!empty($alumno['contrato_id'])) {
            $firmados++;
            if ((int)($alumno['info_erronea'] ?? 0) === 1) {
                $erronea++;
            }
        }
        if ((int)($alumno['admin_aprobado'] ?? 0) === 1) {
            $doc++;
        }
    }
    $total = count($alumnos);

    return [
        'total' => $total,
        'firmados' => $firmados,
        'doc_recibida' => $doc,
        'info_erronea' => $erronea,
        'pendientes_doc' => max(0, $total - $doc),
        'error' => '',
    ];
}

/**
 * Etiqueta legible de una acción de auditoría.
 */
function control_usuarios_label_accion(string $accion): string
{
    $map = [
        'login_ok' => 'Ingreso al panel',
        'consulta_estado_cuenta' => 'Consultó estados de cuenta',
        'revision_contratos' => 'Revisó contratos',
        'doc_recibida' => 'Doc. recibida',
        'info_erronea' => 'Info. errónea',
        'bulk_doc_recibida' => 'Doc. recibida (masivo)',
    ];

    return $map[$accion] ?? $accion;
}

/**
 * Texto corto del detalle de una actividad.
 *
 * @param array<string, mixed> $detalle
 */
function control_usuarios_texto_detalle(string $accion, array $detalle): string
{
    $curso = trim((string)($detalle['curso'] ?? ''));
    $alumno = trim((string)($detalle['alumno'] ?? ''));
    $escuela = trim((string)($detalle['escuela'] ?? ''));
    $partes = [];

    if ($accion === 'doc_recibida' || $accion === 'info_erronea') {
        $valor = !empty($detalle['valor']) ? 'Marcó' : 'Desmarcó';
        $partes[] = $valor;
        if ($alumno !== '') {
            $partes[] = $alumno;
        }
        if ($curso !== '') {
            $partes[] = 'curso ' . $curso;
        }
    } elseif ($accion === 'bulk_doc_recibida') {
        $valor = !empty($detalle['valor']) ? 'Marcó' : 'Desmarcó';
        $partes[] = $valor . ' documentación masiva';
        if ($curso !== '') {
            $partes[] = 'curso ' . $curso;
        } elseif ($escuela !== '') {
            $partes[] = 'escuela ' . $escuela;
        }
        if (isset($detalle['actualizados'])) {
            $partes[] = (int)$detalle['actualizados'] . ' alumnos';
        }
    } elseif ($accion === 'consulta_estado_cuenta') {
        if ($curso !== '') {
            $partes[] = 'curso ' . $curso;
        }
        if ($escuela !== '') {
            $partes[] = 'escuela ' . $escuela;
        }
        if (!empty($detalle['buscar'])) {
            $partes[] = 'búsqueda «' . (string)$detalle['buscar'] . '»';
        }
        if (isset($detalle['total'])) {
            $partes[] = (int)$detalle['total'] . ' alumnos';
        }
    } elseif ($accion === 'revision_contratos') {
        if ($curso !== '') {
            $partes[] = 'curso ' . $curso;
        } elseif ($escuela !== '') {
            $partes[] = 'escuela ' . $escuela;
        }
        if (!empty($detalle['buscar'])) {
            $partes[] = 'búsqueda «' . (string)$detalle['buscar'] . '»';
        }
    } elseif ($accion === 'login_ok') {
        $partes[] = 'Acceso a estado alumnos';
    }

    return implode(' · ', $partes);
}

/**
 * Cursos tocados recientemente a partir de la actividad.
 *
 * @param list<array<string, mixed>> $actividad
 * @return list<array{curso:string,veces:int,ultima:string,usuarios:list<string>}>
 */
function control_usuarios_cursos_trabajados(array $actividad): array
{
    $map = [];
    foreach ($actividad as $item) {
        $detalle = is_array($item['detalle'] ?? null) ? $item['detalle'] : [];
        $curso = strtoupper(trim((string)($detalle['curso'] ?? '')));
        if ($curso === '') {
            continue;
        }
        if (!isset($map[$curso])) {
            $map[$curso] = [
                'curso' => $curso,
                'veces' => 0,
                'ultima' => (string)($item['created_at'] ?? ''),
                'usuarios' => [],
            ];
        }
        $map[$curso]['veces']++;
        $nombre = (string)($item['usuario_nombre'] ?? $item['username'] ?? '');
        if ($nombre !== '' && !in_array($nombre, $map[$curso]['usuarios'], true)) {
            $map[$curso]['usuarios'][] = $nombre;
        }
        if ((string)($item['created_at'] ?? '') > $map[$curso]['ultima']) {
            $map[$curso]['ultima'] = (string)$item['created_at'];
        }
    }

    $list = array_values($map);
    usort($list, static function ($a, $b) {
        return strcmp((string)$b['ultima'], (string)$a['ultima']);
    });

    return array_slice($list, 0, 20);
}
