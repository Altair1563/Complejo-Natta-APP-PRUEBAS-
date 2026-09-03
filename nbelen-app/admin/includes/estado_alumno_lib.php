<?php
/**
 * Lógica de negocio para estado de documentación de alumnos (secretarías).
 */

require_once NATTA_ROOT . '/backend/lib/contract_institution.php';
require_once NATTA_ROOT . '/config/tenant_helpers.php';
require_once __DIR__ . '/db_collate.php';
require_once __DIR__ . '/cuotas_admin_lib.php';
require_once NATTA_ROOT . '/includes/curriculums_lib.php';

function estado_alumno_ensure_contratos_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!contrato_db_column_exists($conn, 'contratos_aceptados', 'admin_aprobado')) {
        try {
            $conn->query(
                'ALTER TABLE contratos_aceptados
                 ADD COLUMN admin_aprobado TINYINT(1) NOT NULL DEFAULT 0'
            );
            contrato_db_column_exists_reset();
        } catch (mysqli_sql_exception $e) {
            error_log('estado_alumno schema admin_aprobado: ' . $e->getMessage());
        }
    }

    if (!contrato_db_column_exists($conn, 'contratos_aceptados', 'info_erronea')) {
        try {
            $conn->query(
                "ALTER TABLE contratos_aceptados
                 ADD COLUMN info_erronea TINYINT(1) NOT NULL DEFAULT 0
                 COMMENT '1 = secretaría marcó datos de firma como erróneos'"
            );
            contrato_db_column_exists_reset();
        } catch (mysqli_sql_exception $e) {
            error_log('estado_alumno schema info_erronea: ' . $e->getMessage());
        }
    }

    estado_alumno_ensure_doc_recibida_schema($conn);

    $done = true;
}

/**
 * Tabla para marcar documentación recibida aunque el alumno aún no haya firmado el contrato.
 */
function estado_alumno_ensure_doc_recibida_schema(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS documentacion_recibida (
                student_dni VARCHAR(20) NOT NULL,
                contract_version VARCHAR(100) NOT NULL,
                recibida TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT '1 = secretaría recibió la documentación del alumno',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (student_dni, contract_version),
                KEY idx_doc_recibida_version (contract_version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('estado_alumno schema documentacion_recibida: ' . $e->getMessage());
    }

    $done = true;
}

/**
 * Marca o quita Doc. recibida (con o sin contrato firmado).
 * Si ya hay contrato activo, también sincroniza admin_aprobado.
 *
 * @return array{ok:bool, msg:string, admin_aprobado?:bool}
 */
function estado_alumno_set_doc_recibida(mysqli $conn, string $studentDni, int $recibida): array
{
    $studentDni = trim($studentDni);
    $recibida = $recibida === 1 ? 1 : 0;

    if ($studentDni === '') {
        return ['ok' => false, 'msg' => 'DNI de alumno inválido.'];
    }

    estado_alumno_ensure_contratos_schema($conn);

    $contractVersion = estado_alumno_obtener_contrato_vigente_por_alumno($conn, $studentDni);
    if ($contractVersion === '') {
        return [
            'ok' => false,
            'msg' => 'No hay contrato vigente configurado para la institución del alumno.',
        ];
    }

    $sqlUpsert = 'INSERT INTO documentacion_recibida (student_dni, contract_version, recibida, updated_at)
                  VALUES (?, ?, ?, NOW())
                  ON DUPLICATE KEY UPDATE recibida = VALUES(recibida), updated_at = NOW()';
    $stmtUpsert = $conn->prepare($sqlUpsert);
    if (!$stmtUpsert) {
        return ['ok' => false, 'msg' => 'No se pudo preparar el guardado de documentación recibida.'];
    }

    try {
        estado_alumno_stmt_bind($stmtUpsert, 'ssi', [$studentDni, $contractVersion, $recibida]);
        $stmtUpsert->execute();
        $stmtUpsert->close();
    } catch (Throwable $e) {
        error_log('estado_alumno_set_doc_recibida upsert: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'No se pudo guardar la documentación recibida.'];
    }

    $sqlUpdate = "UPDATE contratos_aceptados
                  SET admin_aprobado = ?, updated_at = NOW()
                  WHERE status = 'activo'
                    AND contract_version = ?
                    AND " . admin_sql_collate('TRIM(student_dni)') . ' = ?';
    $stmtUpdate = $conn->prepare($sqlUpdate);
    if ($stmtUpdate) {
        try {
            estado_alumno_stmt_bind($stmtUpdate, 'iss', [$recibida, $contractVersion, $studentDni]);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        } catch (Throwable $e) {
            error_log('estado_alumno_set_doc_recibida sync contrato: ' . $e->getMessage());
        }
    }

    return [
        'ok' => true,
        'admin_aprobado' => $recibida === 1,
        'msg' => $recibida === 1
            ? 'Documentación marcada como recibida.'
            : 'Se quitó la marca de documentación recibida.',
    ];
}

/**
 * Marca o quita Doc. recibida para todos los alumnos activos de un curso o escuela.
 *
 * @return array{ok:bool, msg:string, actualizados?:int, admin_aprobado?:bool}
 */
function estado_alumno_bulk_set_doc_recibida(
    mysqli $conn,
    string $curso,
    string $escuelaCodigo,
    string $contractVersion,
    int $recibida
): array {
    $curso = mb_strtoupper(trim($curso), 'UTF-8');
    $escuelaCodigo = strtoupper(trim($escuelaCodigo));
    $recibida = $recibida === 1 ? 1 : 0;

    if ($contractVersion === '') {
        return [
            'ok' => false,
            'msg' => 'No hay contrato vigente configurado para la institución de este curso.',
        ];
    }

    estado_alumno_ensure_contratos_schema($conn);

    $filtro = estado_alumno_filtro_curso_o_escuela($curso, $escuelaCodigo);
    $whereCurso = $filtro['sql'];

    $sqlUpsert = "INSERT INTO documentacion_recibida (student_dni, contract_version, recibida, updated_at)
                  SELECT TRIM(l.dni_alumno), ?, ?, NOW()
                  FROM legajos l
                  WHERE {$whereCurso}
                    AND TRIM(l.dni_alumno) <> ''
                  ON DUPLICATE KEY UPDATE recibida = VALUES(recibida), updated_at = NOW()";
    $stmtUpsert = $conn->prepare($sqlUpsert);
    if (!$stmtUpsert) {
        return ['ok' => false, 'msg' => 'No se pudo preparar la actualización masiva.'];
    }

    $actualizados = 0;
    try {
        estado_alumno_stmt_bind(
            $stmtUpsert,
            'si' . $filtro['types'],
            array_merge([$contractVersion, $recibida], $filtro['params'])
        );
        $stmtUpsert->execute();
        $actualizados = max(0, (int)$stmtUpsert->affected_rows);
        $stmtUpsert->close();
    } catch (Throwable $e) {
        error_log('estado_alumno_bulk_set_doc_recibida upsert: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'No se pudo actualizar la documentación del curso.'];
    }

    $joinDni = estado_alumno_sql_join_dni('ca.student_dni', 'l.dni_alumno');
    $sqlBulk = "UPDATE contratos_aceptados ca
                INNER JOIN legajos l ON {$joinDni}
                SET ca.admin_aprobado = ?, ca.updated_at = NOW()
                WHERE {$whereCurso}
                  AND ca.contract_version = ?
                  AND ca.status = 'activo'";
    $stmtBulk = $conn->prepare($sqlBulk);
    if ($stmtBulk) {
        try {
            estado_alumno_stmt_bind(
                $stmtBulk,
                'i' . $filtro['types'] . 's',
                array_merge([$recibida], $filtro['params'], [$contractVersion])
            );
            $stmtBulk->execute();
            $stmtBulk->close();
        } catch (Throwable $e) {
            error_log('estado_alumno_bulk_set_doc_recibida sync contratos: ' . $e->getMessage());
        }
    }

    return [
        'ok' => true,
        'admin_aprobado' => $recibida === 1,
        'actualizados' => $actualizados,
        'msg' => $recibida === 1
            ? 'Se marcó la documentación recibida de ' . $actualizados . ' alumno(s).'
            : 'Se quitó la marca de documentación recibida de ' . $actualizados . ' alumno(s).',
    ];
}

/**
 * Valor de Doc. recibida previo a la firma (para propagar a admin_aprobado al firmar).
 */
function estado_alumno_doc_recibida_valor(mysqli $conn, string $studentDni, string $contractVersion): int
{
    $studentDni = trim($studentDni);
    $contractVersion = trim($contractVersion);
    if ($studentDni === '' || $contractVersion === '') {
        return 0;
    }

    estado_alumno_ensure_doc_recibida_schema($conn);

    $sql = 'SELECT recibida FROM documentacion_recibida
            WHERE ' . admin_sql_collate('TRIM(student_dni)') . ' = ?
              AND contract_version = ?
            LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    try {
        estado_alumno_stmt_bind($stmt, 'ss', [$studentDni, $contractVersion]);
        $stmt->execute();
        $rows = estado_alumno_fetch_all_from_stmt($stmt);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('estado_alumno_doc_recibida_valor: ' . $e->getMessage());
        return 0;
    }

    return !empty($rows) && (int)($rows[0]['recibida'] ?? 0) === 1 ? 1 : 0;
}

function estado_alumno_sql_join_dni(string $leftExpr, string $rightExpr): string
{
    $left = admin_sql_collate('TRIM(' . $leftExpr . ')');
    $right = admin_sql_collate('TRIM(' . $rightExpr . ')');

    return $left . ' = ' . $right;
}

/**
 * @param list<string|int|float|null> $values
 */
function estado_alumno_stmt_bind(mysqli_stmt $stmt, string $types, array $values): void
{
    $params = array_merge([$types], array_values($values));
    $refs = [];
    foreach ($params as $index => $value) {
        $refs[$index] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function estado_alumno_escuelas_catalog(): array
{
    if (!function_exists('tenant_escuelas_catalog')) {
        require_once NATTA_ROOT . '/config/tenant_helpers.php';
    }

    return tenant_escuelas_catalog();
}

function estado_alumno_h($str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Resumen de alumno para auditoría / paneles de supervisión.
 *
 * @return array{dni:string,nombre:string,apellido:string,curso:string,escuela:string,legajo:string}
 */
function estado_alumno_lookup_alumno_resumen(mysqli $conn, string $studentDni): array
{
    $studentDni = trim($studentDni);
    $empty = [
        'dni' => $studentDni,
        'nombre' => '',
        'apellido' => '',
        'curso' => '',
        'escuela' => '',
        'legajo' => '',
    ];
    if ($studentDni === '') {
        return $empty;
    }

    try {
        $sql = 'SELECT dni_alumno, nombre_alumno, apellido_alumno, curso, nro_legajo
                FROM legajos
                WHERE dni_alumno = ?
                LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $empty;
        }
        $stmt->bind_param('s', $studentDni);
        $stmt->execute();
        $rows = estado_alumno_fetch_all_from_stmt($stmt);
        $stmt->close();
        if (empty($rows)) {
            return $empty;
        }
        $row = $rows[0];
        $curso = (string)($row['curso'] ?? '');

        return [
            'dni' => (string)($row['dni_alumno'] ?? $studentDni),
            'nombre' => (string)($row['nombre_alumno'] ?? ''),
            'apellido' => (string)($row['apellido_alumno'] ?? ''),
            'curso' => $curso,
            'escuela' => estado_alumno_obtener_escuela_desde_curso($curso),
            'legajo' => (string)($row['nro_legajo'] ?? ''),
        ];
    } catch (Throwable $e) {
        error_log('estado_alumno lookup alumno: ' . $e->getMessage());

        return $empty;
    }
}

function estado_alumno_fetch_all_from_stmt($stmt): array
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

if (!function_exists('fetchAllFromStmt')) {
    /**
     * Alias requerido por contract_institution.php en el panel admin.
     */
    function fetchAllFromStmt(mysqli_stmt $stmt): array
    {
        return estado_alumno_fetch_all_from_stmt($stmt);
    }
}

function estado_alumno_obtener_escuela_desde_curso($curso): string
{
    if (!function_exists('tenant_escuela_desde_curso')) {
        require_once NATTA_ROOT . '/config/tenant_helpers.php';
    }

    return tenant_escuela_desde_curso($curso);
}

/**
 * Filtro SQL por curso concreto o por escuela (catálogo / RIGHT).
 *
 * @return array{sql:string, types:string, params:list<string>}
 */
function estado_alumno_filtro_curso_o_escuela(string $curso, string $escuelaCodigo, string $cursoExpr = 'l.curso'): array
{
    $curso = mb_strtoupper(trim($curso), 'UTF-8');
    if ($curso !== '') {
        return [
            'sql' => 'UPPER(TRIM(' . $cursoExpr . ')) = ?',
            'types' => 's',
            'params' => [$curso],
        ];
    }

    if (!function_exists('tenant_filtro_sql_escuela')) {
        require_once NATTA_ROOT . '/config/tenant_helpers.php';
    }

    return tenant_filtro_sql_escuela($escuelaCodigo, $cursoExpr);
}

function estado_alumno_obtener_contrato_vigente_por_curso(mysqli $conn, string $curso): string
{
    try {
        $contract = contrato_fetch_vigente_por_curso($conn, $curso);

        return $contract !== null ? (string)$contract['contract_version'] : '';
    } catch (Throwable $e) {
        error_log('estado_alumno contrato vigente: ' . $e->getMessage());

        return '';
    }
}

function estado_alumno_obtener_contrato_vigente_por_escuela(mysqli $conn, string $escuelaCodigo): string
{
    $escuelaCodigo = strtoupper(trim($escuelaCodigo));
    if ($escuelaCodigo === '' || strlen($escuelaCodigo) !== 2) {
        return '';
    }

    try {
        $contract = contrato_fetch_vigente_por_codigo($conn, $escuelaCodigo);

        return $contract !== null ? (string)$contract['contract_version'] : '';
    } catch (Throwable $e) {
        error_log('estado_alumno contrato vigente por escuela: ' . $e->getMessage());

        return '';
    }
}

function estado_alumno_obtener_contrato_vigente_por_alumno(mysqli $conn, string $studentDni): string
{
    $studentDni = trim($studentDni);
    if ($studentDni === '') {
        return '';
    }

    try {
        $sql = 'SELECT curso FROM legajos WHERE dni_alumno = ? LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('s', $studentDni);
        $stmt->execute();
        $rows = estado_alumno_fetch_all_from_stmt($stmt);
        $stmt->close();
        if (empty($rows)) {
            return '';
        }

        return estado_alumno_obtener_contrato_vigente_por_curso($conn, (string)($rows[0]['curso'] ?? ''));
    } catch (Throwable $e) {
        error_log('estado_alumno contrato por alumno: ' . $e->getMessage());

        return '';
    }
}

function estado_alumno_cursos_catalogo_por_escuela(): array
{
    if (!function_exists('tenant_cursos_por_escuela')) {
        require_once NATTA_ROOT . '/config/tenant_helpers.php';
    }

    return tenant_cursos_por_escuela();
}

function estado_alumno_comparar_cursos($a, $b): int
{
    return strnatcasecmp((string)$a, (string)$b);
}

function estado_alumno_cargar_alumnos_activos_por_curso(
    mysqli $conn,
    string $curso,
    string $contractVersion = '',
    string $buscar = '',
    string $escuelaCodigo = ''
): array {
    $curso = mb_strtoupper(trim($curso), 'UTF-8');
    $escuelaCodigo = strtoupper(trim($escuelaCodigo));
    if ($curso === '' && ($escuelaCodigo === '' || strlen($escuelaCodigo) !== 2)) {
        return [];
    }

    $joinDni = estado_alumno_sql_join_dni('ca.student_dni', 'l.dni_alumno');
    $hasAdminCol = contrato_db_column_exists($conn, 'contratos_aceptados', 'admin_aprobado');
    $adminSelect = $hasAdminCol ? 'COALESCE(ca.admin_aprobado, 0) AS admin_aprobado' : '0 AS admin_aprobado';
    $hasPdfCol = contrato_db_column_exists($conn, 'contratos_aceptados', 'accepted_pdf_path');
    $pdfSelect = $hasPdfCol ? 'ca.accepted_pdf_path' : 'NULL AS accepted_pdf_path';

    $whereCursoFiltro = estado_alumno_filtro_curso_o_escuela($curso, $escuelaCodigo);
    $whereCurso = $whereCursoFiltro['sql'];

    if ($contractVersion !== '') {
        $sql = "SELECT
                    l.nro_legajo,
                    l.apellido_alumno,
                    l.nombre_alumno,
                    l.dni_alumno,
                    l.nro_familia,
                    l.curso,
                    ca.id AS contrato_id,
                    {$adminSelect},
                    {$pdfSelect}
                FROM legajos l
                LEFT JOIN contratos_aceptados ca
                    ON {$joinDni}
                    AND ca.contract_version = ?
                    AND ca.status = 'activo'
                WHERE {$whereCurso}
                ORDER BY l.curso ASC, l.apellido_alumno ASC, l.nombre_alumno ASC, l.nro_legajo ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta de legajos.');
        }
        estado_alumno_stmt_bind(
            $stmt,
            's' . $whereCursoFiltro['types'],
            array_merge([$contractVersion], $whereCursoFiltro['params'])
        );
    } else {
        $sql = "SELECT
                    l.nro_legajo,
                    l.apellido_alumno,
                    l.nombre_alumno,
                    l.dni_alumno,
                    l.nro_familia,
                    l.curso,
                    NULL AS contrato_id,
                    0 AS admin_aprobado,
                    NULL AS accepted_pdf_path
                FROM legajos l
                WHERE {$whereCurso}
                ORDER BY l.curso ASC, l.apellido_alumno ASC, l.nombre_alumno ASC, l.nro_legajo ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta de legajos.');
        }
        estado_alumno_stmt_bind($stmt, $whereCursoFiltro['types'], $whereCursoFiltro['params']);
    }

    $stmt->execute();
    $filas = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();

    $alumnos = [];
    foreach ($filas as $fila) {
        $alumnos[] = [
            'nro_legajo' => $fila['nro_legajo'] ?? '',
            'apellido_alumno' => $fila['apellido_alumno'] ?? '',
            'nombre_alumno' => $fila['nombre_alumno'] ?? '',
            'dni_alumno' => $fila['dni_alumno'] ?? '',
            'nro_familia' => $fila['nro_familia'] ?? '',
            'curso' => $fila['curso'] ?? '',
            'contrato_id' => $fila['contrato_id'] ?? null,
            'admin_aprobado' => (int)($fila['admin_aprobado'] ?? 0),
            'pdf_disponible' => !empty($fila['contrato_id'])
                && estado_alumno_contrato_pdf_disponible((string)($fila['accepted_pdf_path'] ?? '')),
        ];
    }

    $buscarNorm = mb_strtoupper(trim($buscar), 'UTF-8');
    if ($buscarNorm !== '') {
        $filtrados = [];
        foreach ($alumnos as $alumno) {
            $texto = mb_strtoupper(
                trim(
                    ($alumno['apellido_alumno'] ?? '') . ' ' .
                    ($alumno['nombre_alumno'] ?? '') . ' ' .
                    ($alumno['nro_legajo'] ?? '') . ' ' .
                    ($alumno['dni_alumno'] ?? '') . ' ' .
                    ($alumno['curso'] ?? '')
                ),
                'UTF-8'
            );

            if (mb_strpos($texto, $buscarNorm, 0, 'UTF-8') !== false) {
                $filtrados[] = $alumno;
            }
        }

        return $filtrados;
    }

    return $alumnos;
}

/**
 * Alumnos activos del curso con datos del firmante parseados desde accepted_text.
 *
 * @return list<array{
 *   nro_legajo:string,
 *   apellido_alumno:string,
 *   nombre_alumno:string,
 *   dni_alumno:string,
 *   nro_familia:string,
 *   curso:string,
 *   contrato_id:mixed,
 *   firmante_nombre:string,
 *   firmante_dni:string,
 *   firmante_domicilio:string,
 *   firmante_localidad:string,
 *   info_erronea:int
 * }>
 */
function estado_alumno_cargar_revision_contratos_por_curso(
    mysqli $conn,
    string $curso,
    string $contractVersion = '',
    string $buscar = '',
    string $escuelaCodigo = ''
): array {
    $curso = mb_strtoupper(trim($curso), 'UTF-8');
    $escuelaCodigo = strtoupper(trim($escuelaCodigo));
    if ($curso === '' && ($escuelaCodigo === '' || strlen($escuelaCodigo) !== 2)) {
        return [];
    }

    require_once NATTA_ROOT . '/backend/lib/contract_render.php';
    estado_alumno_ensure_doc_recibida_schema($conn);

    $joinDni = estado_alumno_sql_join_dni('ca.student_dni', 'l.dni_alumno');
    $joinDniDoc = estado_alumno_sql_join_dni('dr.student_dni', 'l.dni_alumno');
    $hasInfoErronea = contrato_db_column_exists($conn, 'contratos_aceptados', 'info_erronea');
    $infoSelect = $hasInfoErronea ? 'COALESCE(ca.info_erronea, 0) AS info_erronea' : '0 AS info_erronea';
    $hasAdminCol = contrato_db_column_exists($conn, 'contratos_aceptados', 'admin_aprobado');
    // Doc. recibida puede marcarse antes de firmar (documentacion_recibida) o tras firma (admin_aprobado).
    if ($hasAdminCol) {
        $adminSelect = 'CASE
            WHEN COALESCE(ca.admin_aprobado, 0) = 1 OR COALESCE(dr.recibida, 0) = 1 THEN 1
            ELSE 0
        END AS admin_aprobado';
    } else {
        $adminSelect = 'CASE WHEN COALESCE(dr.recibida, 0) = 1 THEN 1 ELSE 0 END AS admin_aprobado';
    }

    $whereCursoFiltro = estado_alumno_filtro_curso_o_escuela($curso, $escuelaCodigo);
    $whereCurso = $whereCursoFiltro['sql'];

    if ($contractVersion !== '') {
        $sql = "SELECT
                    l.nro_legajo,
                    l.apellido_alumno,
                    l.nombre_alumno,
                    l.dni_alumno,
                    l.nro_familia,
                    l.curso,
                    ca.id AS contrato_id,
                    ca.accepted_text,
                    {$adminSelect},
                    {$infoSelect}
                FROM legajos l
                LEFT JOIN contratos_aceptados ca
                    ON {$joinDni}
                    AND ca.contract_version = ?
                    AND ca.status = 'activo'
                LEFT JOIN documentacion_recibida dr
                    ON {$joinDniDoc}
                    AND dr.contract_version = ?
                WHERE {$whereCurso}
                ORDER BY l.curso ASC, l.apellido_alumno ASC, l.nombre_alumno ASC, l.nro_legajo ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta de revisión de contratos.');
        }
        estado_alumno_stmt_bind(
            $stmt,
            'ss' . $whereCursoFiltro['types'],
            array_merge([$contractVersion, $contractVersion], $whereCursoFiltro['params'])
        );
    } else {
        $sql = "SELECT
                    l.nro_legajo,
                    l.apellido_alumno,
                    l.nombre_alumno,
                    l.dni_alumno,
                    l.nro_familia,
                    l.curso,
                    NULL AS contrato_id,
                    NULL AS accepted_text,
                    0 AS admin_aprobado,
                    0 AS info_erronea
                FROM legajos l
                WHERE {$whereCurso}
                ORDER BY l.curso ASC, l.apellido_alumno ASC, l.nombre_alumno ASC, l.nro_legajo ASC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta de revisión de contratos.');
        }
        estado_alumno_stmt_bind($stmt, $whereCursoFiltro['types'], $whereCursoFiltro['params']);
    }

    $stmt->execute();
    $filas = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();

    $alumnos = [];
    foreach ($filas as $fila) {
        $firmante = [
            'nombre' => '',
            'dni' => '',
            'domicilio' => '',
            'localidad' => '',
        ];
        if (!empty($fila['contrato_id']) && !empty($fila['accepted_text'])) {
            $parsed = contrato_parse_accepted_text((string)$fila['accepted_text']);
            if (is_array($parsed)) {
                $firmante['nombre'] = (string)($parsed['nombre'] ?? '');
                $firmante['dni'] = (string)($parsed['dni'] ?? '');
                $firmante['domicilio'] = (string)($parsed['domicilio'] ?? '');
                $firmante['localidad'] = (string)($parsed['localidad'] ?? '');
            }
        }

        $alumnos[] = [
            'nro_legajo' => $fila['nro_legajo'] ?? '',
            'apellido_alumno' => $fila['apellido_alumno'] ?? '',
            'nombre_alumno' => $fila['nombre_alumno'] ?? '',
            'dni_alumno' => $fila['dni_alumno'] ?? '',
            'nro_familia' => $fila['nro_familia'] ?? '',
            'curso' => $fila['curso'] ?? '',
            'contrato_id' => $fila['contrato_id'] ?? null,
            'firmante_nombre' => $firmante['nombre'],
            'firmante_dni' => $firmante['dni'],
            'firmante_domicilio' => $firmante['domicilio'],
            'firmante_localidad' => $firmante['localidad'],
            'admin_aprobado' => (int)($fila['admin_aprobado'] ?? 0),
            'info_erronea' => (int)($fila['info_erronea'] ?? 0),
        ];
    }

    $buscarNorm = mb_strtoupper(trim($buscar), 'UTF-8');
    if ($buscarNorm !== '') {
        $filtrados = [];
        foreach ($alumnos as $alumno) {
            $texto = mb_strtoupper(
                trim(
                    ($alumno['apellido_alumno'] ?? '') . ' ' .
                    ($alumno['nombre_alumno'] ?? '') . ' ' .
                    ($alumno['nro_legajo'] ?? '') . ' ' .
                    ($alumno['dni_alumno'] ?? '') . ' ' .
                    ($alumno['curso'] ?? '')
                ),
                'UTF-8'
            );

            if (mb_strpos($texto, $buscarNorm, 0, 'UTF-8') !== false) {
                $filtrados[] = $alumno;
            }
        }

        return $filtrados;
    }

    return $alumnos;
}

/**
 * Listado admin de contratos firmados con datos de firmante (todas las escuelas).
 *
 * @return list<array{
 *   contrato_id:int|string,
 *   nro_legajo:string,
 *   apellido_alumno:string,
 *   nombre_alumno:string,
 *   dni_alumno:string,
 *   nro_familia:string,
 *   curso:string,
 *   contract_version:string,
 *   accepted_at:string,
 *   firmante_nombre:string,
 *   firmante_dni:string,
 *   firmante_domicilio:string,
 *   firmante_localidad:string,
 *   info_erronea:int
 * }>
 */
function estado_alumno_cargar_revision_contratos_admin(
    mysqli $conn,
    bool $soloErroneos = false,
    string $cursoFiltro = '',
    string $buscar = ''
): array {
    require_once NATTA_ROOT . '/backend/lib/contract_render.php';
    estado_alumno_ensure_contratos_schema($conn);

    $joinDni = estado_alumno_sql_join_dni('ca.student_dni', 'l.dni_alumno');
    $hasInfoErronea = contrato_db_column_exists($conn, 'contratos_aceptados', 'info_erronea');
    $infoSelect = $hasInfoErronea ? 'COALESCE(ca.info_erronea, 0) AS info_erronea' : '0 AS info_erronea';

    $where = ["ca.status = 'activo'"];
    $types = '';
    $params = [];

    if ($soloErroneos) {
        if (!$hasInfoErronea) {
            return [];
        }
        $where[] = 'COALESCE(ca.info_erronea, 0) = 1';
    }

    $cursoFiltro = mb_strtoupper(trim($cursoFiltro), 'UTF-8');
    if ($cursoFiltro !== '') {
        $where[] = 'UPPER(TRIM(l.curso)) = ?';
        $types .= 's';
        $params[] = $cursoFiltro;
    }

    $buscar = trim($buscar);
    if ($buscar !== '') {
        $where[] = '(l.apellido_alumno LIKE ? OR l.nombre_alumno LIKE ? OR l.dni_alumno LIKE ? OR l.nro_legajo LIKE ? OR l.nro_familia LIKE ?)';
        $like = '%' . $buscar . '%';
        $types .= 'sssss';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);
    $orderInfo = $hasInfoErronea ? 'COALESCE(ca.info_erronea, 0) DESC,' : '';
    $sql = "SELECT
                ca.id AS contrato_id,
                ca.contract_version,
                ca.accepted_at,
                ca.accepted_text,
                {$infoSelect},
                l.nro_legajo,
                l.apellido_alumno,
                l.nombre_alumno,
                l.dni_alumno,
                l.nro_familia,
                l.curso
            FROM contratos_aceptados ca
            INNER JOIN legajos l ON {$joinDni}
            WHERE {$whereSql}
            ORDER BY {$orderInfo}
                     l.curso ASC,
                     l.apellido_alumno ASC,
                     l.nombre_alumno ASC,
                     ca.id DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta admin de revisión de contratos.');
    }
    if ($types !== '') {
        estado_alumno_stmt_bind($stmt, $types, $params);
    }
    $stmt->execute();
    $filas = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();

    $filasOut = [];
    foreach ($filas as $fila) {
        $firmante = [
            'nombre' => '',
            'dni' => '',
            'domicilio' => '',
            'localidad' => '',
        ];
        if (!empty($fila['accepted_text'])) {
            $parsed = contrato_parse_accepted_text((string)$fila['accepted_text']);
            if (is_array($parsed)) {
                $firmante['nombre'] = (string)($parsed['nombre'] ?? '');
                $firmante['dni'] = (string)($parsed['dni'] ?? '');
                $firmante['domicilio'] = (string)($parsed['domicilio'] ?? '');
                $firmante['localidad'] = (string)($parsed['localidad'] ?? '');
            }
        }

        $filasOut[] = [
            'contrato_id' => $fila['contrato_id'] ?? null,
            'contract_version' => (string)($fila['contract_version'] ?? ''),
            'accepted_at' => (string)($fila['accepted_at'] ?? ''),
            'nro_legajo' => $fila['nro_legajo'] ?? '',
            'apellido_alumno' => $fila['apellido_alumno'] ?? '',
            'nombre_alumno' => $fila['nombre_alumno'] ?? '',
            'dni_alumno' => $fila['dni_alumno'] ?? '',
            'nro_familia' => $fila['nro_familia'] ?? '',
            'curso' => $fila['curso'] ?? '',
            'firmante_nombre' => $firmante['nombre'],
            'firmante_dni' => $firmante['dni'],
            'firmante_domicilio' => $firmante['domicilio'],
            'firmante_localidad' => $firmante['localidad'],
            'info_erronea' => (int)($fila['info_erronea'] ?? 0),
        ];
    }

    return $filasOut;
}

/**
 * Elimina un contrato marcado como info errónea (solo panel admin).
 *
 * @return array{ok:bool,msg:string}
 */
function estado_alumno_eliminar_contrato_erroneo(mysqli $conn, int $contratoId): array
{
    $contratoId = (int)$contratoId;
    if ($contratoId < 1) {
        return ['ok' => false, 'msg' => 'ID de contrato inválido.'];
    }

    estado_alumno_ensure_contratos_schema($conn);
    if (!contrato_db_column_exists($conn, 'contratos_aceptados', 'info_erronea')) {
        return ['ok' => false, 'msg' => 'La columna info_erronea no está disponible.'];
    }

    $hasPdfCol = contrato_db_column_exists($conn, 'contratos_aceptados', 'accepted_pdf_path');
    $pdfSelect = $hasPdfCol ? 'accepted_pdf_path' : 'NULL AS accepted_pdf_path';

    $sql = "SELECT id, {$pdfSelect}, COALESCE(info_erronea, 0) AS info_erronea
            FROM contratos_aceptados
            WHERE id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['ok' => false, 'msg' => 'No se pudo preparar la consulta del contrato.'];
    }
    estado_alumno_stmt_bind($stmt, 'i', [$contratoId]);
    $stmt->execute();
    $rows = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();

    if ($rows === []) {
        return ['ok' => false, 'msg' => 'Contrato no encontrado.'];
    }

    $row = $rows[0];
    if ((int)($row['info_erronea'] ?? 0) !== 1) {
        return ['ok' => false, 'msg' => 'Solo se pueden eliminar contratos marcados como info errónea.'];
    }

    $pdfPath = trim((string)($row['accepted_pdf_path'] ?? ''));

    $stmtDel = $conn->prepare('DELETE FROM contratos_aceptados WHERE id = ? AND info_erronea = 1 LIMIT 1');
    if (!$stmtDel) {
        return ['ok' => false, 'msg' => 'No se pudo preparar la eliminación.'];
    }
    estado_alumno_stmt_bind($stmtDel, 'i', [$contratoId]);
    try {
        $stmtDel->execute();
        $afectadas = $stmtDel->affected_rows;
        $stmtDel->close();
    } catch (mysqli_sql_exception $e) {
        error_log('estado_alumno_eliminar_contrato_erroneo: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'No se pudo eliminar el contrato.'];
    }

    if ($afectadas < 1) {
        return ['ok' => false, 'msg' => 'No se eliminó el contrato (posiblemente ya no estaba marcado como erróneo).'];
    }

    if ($pdfPath !== '') {
        require_once NATTA_ROOT . '/backend/lib/contract_pdf.php';
        $absolute = contrato_resolve_signed_pdf_absolute($pdfPath);
        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    return ['ok' => true, 'msg' => 'Contrato erróneo eliminado. La familia podrá volver a firmar.'];
}

function estado_alumno_contrato_pdf_disponible(?string $acceptedPdfPath): bool
{
    static $contractPdfLoaded = false;
    if (!$contractPdfLoaded) {
        require_once NATTA_ROOT . '/backend/lib/contract_pdf.php';
        $contractPdfLoaded = true;
    }

    $rel = trim((string)$acceptedPdfPath);
    if ($rel === '') {
        return false;
    }

    return contrato_resolve_signed_pdf_absolute($rel) !== null;
}

/**
 * @return array{path: string, filename: string}|null
 */
function estado_alumno_obtener_pdf_contrato_firmado(mysqli $conn, string $studentDni): ?array
{
    $studentDni = trim($studentDni);
    if ($studentDni === '') {
        return null;
    }

    $contractVersion = estado_alumno_obtener_contrato_vigente_por_alumno($conn, $studentDni);
    if ($contractVersion === '' || !contrato_db_column_exists($conn, 'contratos_aceptados', 'accepted_pdf_path')) {
        return null;
    }

    $joinDni = estado_alumno_sql_join_dni('ca.student_dni', 'l.dni_alumno');
    $sql = "SELECT ca.accepted_pdf_path
            FROM contratos_aceptados ca
            INNER JOIN legajos l ON {$joinDni}
            WHERE " . admin_sql_collate('TRIM(l.dni_alumno)') . " = ?
              AND ca.contract_version = ?
              AND ca.status = 'activo'
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    estado_alumno_stmt_bind($stmt, 'ss', [$studentDni, $contractVersion]);
    $stmt->execute();
    $rows = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();

    if (empty($rows)) {
        return null;
    }

    $rel = trim((string)($rows[0]['accepted_pdf_path'] ?? ''));
    if ($rel === '') {
        return null;
    }

    static $contractPdfLoaded = false;
    if (!$contractPdfLoaded) {
        require_once NATTA_ROOT . '/backend/lib/contract_pdf.php';
        $contractPdfLoaded = true;
    }

    $absolute = contrato_resolve_signed_pdf_absolute($rel);
    if ($absolute === null) {
        return null;
    }

    return [
        'path' => $absolute,
        'filename' => basename($absolute),
    ];
}

function estado_alumno_aplicar_estado_contratos_a_alumnos(mysqli $conn, array &$alumnos, string $contractVersion): void
{
    // Compatibilidad: la carga con JOIN ya aplica el estado del contrato vigente.
    unset($conn, $alumnos, $contractVersion);
}

function estado_alumno_construir_cursos_por_escuela(mysqli $conn, array $escuelas): array
{
    $cursosPorEscuela = estado_alumno_cursos_catalogo_por_escuela();
    foreach (array_keys($escuelas) as $codigoEscuela) {
        if (!isset($cursosPorEscuela[$codigoEscuela])) {
            $cursosPorEscuela[$codigoEscuela] = [];
        }
    }

    try {
        $sqlCursos = "SELECT DISTINCT TRIM(curso) AS curso
                      FROM legajos
                      WHERE TRIM(COALESCE(curso, '')) <> ''";
        $stmtCursos = $conn->prepare($sqlCursos);
        if ($stmtCursos) {
            $stmtCursos->execute();
            $filasCursos = estado_alumno_fetch_all_from_stmt($stmtCursos);
            $stmtCursos->close();

            foreach ($filasCursos as $filaCurso) {
                $curso = trim((string)($filaCurso['curso'] ?? ''));
                if ($curso === '') {
                    continue;
                }
                $codigo = estado_alumno_obtener_escuela_desde_curso($curso);
                if ($codigo !== '' && isset($cursosPorEscuela[$codigo])) {
                    $cursosPorEscuela[$codigo][] = $curso;
                }
            }
        }
    } catch (mysqli_sql_exception $e) {
        error_log('estado_alumno cursos legajos: ' . $e->getMessage());
    }

    foreach ($cursosPorEscuela as $codigo => $cursos) {
        $cursos = array_values(array_unique(array_map('trim', $cursos)));
        usort($cursos, 'estado_alumno_comparar_cursos');
        $cursosPorEscuela[$codigo] = $cursos;
    }

    return $cursosPorEscuela;
}

/**
 * @param bool $permitirComplejo Si true, acepta escuela=ALL (todo el complejo) y cualquier escuela del catálogo.
 *                                Pensado para la solapa Estados de cuenta (lectura).
 */
function estado_alumno_resolve_escuela_activa(
    array $escuelas,
    string $requested,
    bool $permitirComplejo = false
): string {
    $requested = strtoupper(trim($requested));
    $rolEscuelaInstitucionCompleta = admin_is_rol_escuela()
        && function_exists('tenant_admin_tiene_alcance_total')
        && tenant_admin_tiene_alcance_total();

    if ($rolEscuelaInstitucionCompleta) {
        if ($permitirComplejo && ($requested === 'ALL' || $requested === 'COMPLEJO')) {
            return 'ALL';
        }
        if (isset($escuelas[$requested])) {
            return $requested;
        }

        return $permitirComplejo ? 'ALL' : tenant_escuela_default();
    }

    if ($permitirComplejo) {
        if ($requested === 'ALL' || $requested === 'COMPLEJO') {
            return 'ALL';
        }
        if (isset($escuelas[$requested])) {
            return $requested;
        }
        if (admin_is_rol_escuela()) {
            $codigo = admin_escuela_codigo();
            if ($codigo === '' || !isset($escuelas[$codigo])) {
                http_response_code(403);
                die('Su usuario no tiene una escuela asignada. Contacte al administrador.');
            }

            // Por defecto todo el complejo en consultas de cuenta.
            return 'ALL';
        }

        return 'ALL';
    }

    if (admin_is_rol_escuela()) {
        $codigo = admin_escuela_codigo();
        if ($codigo === '' || !isset($escuelas[$codigo])) {
            http_response_code(403);
            die('Su usuario no tiene una escuela asignada. Contacte al administrador.');
        }

        return $codigo;
    }

    return isset($escuelas[$requested]) ? $requested : tenant_escuela_default();
}

/**
 * Une los cursos de todas las escuelas (ordenados) para filtros de todo el complejo.
 *
 * @param array<string, list<string>> $cursosPorEscuela
 * @return list<string>
 */
function estado_alumno_cursos_todos(array $cursosPorEscuela): array
{
    $todos = [];
    foreach ($cursosPorEscuela as $cursos) {
        foreach ($cursos as $curso) {
            $curso = trim((string)$curso);
            if ($curso === '') {
                continue;
            }
            $todos[mb_strtoupper($curso, 'UTF-8')] = $curso;
        }
    }
    $lista = array_values($todos);
    usort($lista, 'estado_alumno_comparar_cursos');

    return $lista;
}

function estado_alumno_curso_pertenece_escuela(string $curso, string $escuelaCodigo, array $cursosPorEscuela): bool
{
    $curso = mb_strtoupper(trim($curso), 'UTF-8');
    $codigoCurso = estado_alumno_obtener_escuela_desde_curso($curso);

    if ($codigoCurso !== $escuelaCodigo || !isset($cursosPorEscuela[$escuelaCodigo])) {
        return false;
    }

    foreach ($cursosPorEscuela[$escuelaCodigo] as $cursoEscuela) {
        if (mb_strtoupper(trim((string)$cursoEscuela), 'UTF-8') === $curso) {
            return true;
        }
    }

    return false;
}

function estado_alumno_alumno_pertenece_escuela(mysqli $conn, string $studentDni, string $escuelaCodigo): bool
{
    $studentDni = trim($studentDni);
    if ($studentDni === '') {
        return false;
    }

    $joinDni = admin_sql_collate('TRIM(l.dni_alumno)') . ' = ?';
    $sql = "SELECT l.curso
            FROM legajos l
            WHERE {$joinDni}
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    estado_alumno_stmt_bind($stmt, 's', [$studentDni]);
    $stmt->execute();
    $rows = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();
    if (empty($rows)) {
        return false;
    }

    return estado_alumno_obtener_escuela_desde_curso((string)($rows[0]['curso'] ?? '')) === $escuelaCodigo;
}

function estado_alumno_validar_escuela_codigo(string $codigo, array $escuelas): ?string
{
    $codigo = strtoupper(trim($codigo));

    return isset($escuelas[$codigo]) ? $codigo : null;
}

function estado_alumno_resolve_vista(string $requested): string
{
    $requested = strtolower(trim($requested));

    if (
        $requested === 'control_usuarios'
        && function_exists('admin_can_access_control_usuarios')
        && admin_can_access_control_usuarios()
    ) {
        return 'control_usuarios';
    }

    return in_array(
        $requested,
        ['cuentas', 'listado_familias', 'contratos', 'revision_contratos', 'emails', 'bolsa'],
        true
    )
        ? $requested
        : 'cuentas';
}

function estado_alumno_public_uploads_dir(): string
{
    return curriculum_public_uploads_dir();
}

function estado_alumno_curriculum_resolve_archivo_path(string $archivo): ?string
{
    return curriculum_resolve_archivo_path($archivo);
}

function estado_alumno_curriculum_areas_catalog(): array
{
    return curriculum_areas_catalog();
}

/**
 * @return array{buscar: string, area: string}
 */
function estado_alumno_parse_filtros_bolsa(): array
{
    return [
        'buscar' => trim((string)($_GET['buscar_bolsa'] ?? '')),
        'area'   => trim((string)($_GET['area_bolsa'] ?? '')),
    ];
}

function estado_alumno_curriculum_mime_type(string $path): string
{
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'rtf'  => 'application/rtf',
        'txt'  => 'text/plain',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];

    return $map[$ext] ?? 'application/octet-stream';
}

function estado_alumno_curriculum_download_filename(string $nombreApellido, string $archivoPath): string
{
    $base = preg_replace('/[^A-Za-z0-9_\- ]+/u', '', trim($nombreApellido));
    $base = trim(preg_replace('/\s+/', '_', $base ?? ''));
    if ($base === '') {
        $base = 'curriculum';
    }

    $ext = strtolower(pathinfo($archivoPath, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'pdf';
    }

    return $base . '.' . $ext;
}

function estado_alumno_fmt_fecha(?string $fecha): string
{
    if ($fecha === null || trim($fecha) === '') {
        return '—';
    }

    $ts = strtotime($fecha);

    return $ts !== false ? date('d/m/Y H:i', $ts) : '—';
}

/**
 * @return array{curriculums: list<array<string, mixed>>, total: int, error: string}
 */
function estado_alumno_cargar_curriculums(mysqli $conn, string $buscar = '', string $areaFiltro = ''): array
{
    curriculum_ensure_schema($conn);
    $tieneArea = curriculum_db_column_exists($conn, 'area');
    $tieneFechaSubida = curriculum_db_column_exists($conn, 'fecha_subida');
    $tieneDuplencias = curriculum_db_column_exists($conn, 'duplencias');
    $areaSelect = $tieneArea ? 'area' : "'otros' AS area";
    $fechaSelect = $tieneFechaSubida ? 'fecha_subida' : 'NULL AS fecha_subida';
    $duplenciasSelect = $tieneDuplencias ? 'duplencias' : "'NO' AS duplencias";
    $orderBy = $tieneFechaSubida ? 'fecha_subida DESC, id DESC' : 'id DESC';

    $sql = "SELECT id, nombre_apellido, email, telefono, {$areaSelect}, archivo, {$fechaSelect}, {$duplenciasSelect}
            FROM curriculums
            ORDER BY {$orderBy}";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [
            'curriculums' => [],
            'total'       => 0,
            'error'       => 'No se pudo preparar la consulta de currículums.',
        ];
    }

    $stmt->execute();
    $filas = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();

    $buscarNorm = mb_strtoupper(trim($buscar), 'UTF-8');
    $areaFiltroValida = $areaFiltro !== '' ? curriculum_validar_area($areaFiltro) : null;
    $curriculums = [];

    foreach ($filas as $fila) {
        $nombre = trim((string)($fila['nombre_apellido'] ?? ''));
        $email = trim((string)($fila['email'] ?? ''));
        $telefono = trim((string)($fila['telefono'] ?? ''));
        $archivo = trim((string)($fila['archivo'] ?? ''));
        $area = curriculum_validar_area((string)($fila['area'] ?? 'otros')) ?? 'otros';

        if ($areaFiltroValida !== null && $area !== $areaFiltroValida) {
            continue;
        }

        if ($buscarNorm !== '') {
            $texto = mb_strtoupper($nombre . ' ' . $email . ' ' . $telefono, 'UTF-8');
            if (mb_strpos($texto, $buscarNorm, 0, 'UTF-8') === false) {
                continue;
            }
        }

        $archivoPath = estado_alumno_curriculum_resolve_archivo_path($archivo);

        $curriculums[] = [
            'id'              => (int)($fila['id'] ?? 0),
            'nombre_apellido' => $nombre,
            'email'           => $email,
            'telefono'        => $telefono,
            'area'            => $area,
            'area_etiqueta'   => curriculum_area_etiqueta($area),
            'archivo'         => $archivo,
            'fecha_subida'    => (string)($fila['fecha_subida'] ?? ''),
            'duplencias'      => curriculum_normalizar_duplencias($fila['duplencias'] ?? 'NO'),
            'cv_disponible'   => $archivoPath !== null,
            'archivo_nombre'  => $archivo !== '' ? basename(str_replace('\\', '/', $archivo)) : '',
        ];
    }

    return [
        'curriculums' => $curriculums,
        'total'       => count($curriculums),
        'error'       => '',
    ];
}

function estado_alumno_obtener_curriculum_por_id(mysqli $conn, int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    curriculum_ensure_schema($conn);
    $tieneArea = curriculum_db_column_exists($conn, 'area');
    $tieneFechaSubida = curriculum_db_column_exists($conn, 'fecha_subida');
    $areaSelect = $tieneArea ? 'area' : "'otros' AS area";
    $fechaSelect = $tieneFechaSubida ? 'fecha_subida' : 'NULL AS fecha_subida';

    $sql = "SELECT id, nombre_apellido, email, telefono, {$areaSelect}, archivo, {$fechaSelect}
            FROM curriculums
            WHERE id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    estado_alumno_stmt_bind($stmt, 'i', [$id]);
    $stmt->execute();
    $filas = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();

    if (empty($filas)) {
        return null;
    }

    $fila = $filas[0];
    $archivo = trim((string)($fila['archivo'] ?? ''));
    $area = curriculum_validar_area((string)($fila['area'] ?? 'otros')) ?? 'otros';

    return [
        'id'              => (int)($fila['id'] ?? 0),
        'nombre_apellido' => trim((string)($fila['nombre_apellido'] ?? '')),
        'email'           => trim((string)($fila['email'] ?? '')),
        'telefono'        => trim((string)($fila['telefono'] ?? '')),
        'area'            => $area,
        'area_etiqueta'   => curriculum_area_etiqueta($area),
        'archivo'         => $archivo,
        'fecha_subida'    => (string)($fila['fecha_subida'] ?? ''),
        'archivo_path'    => estado_alumno_curriculum_resolve_archivo_path($archivo),
    ];
}

/**
 * @return array{
 *   mes: int,
 *   curso: string,
 *   buscar: string,
 *   estado: string,
 *   beca: string,
 *   beca_pct: string
 * }
 */
function estado_alumno_parse_filtros_cuenta(): array
{
    $mes = 0;
    if (isset($_GET['mes']) && $_GET['mes'] !== '') {
        $mes = (int)$_GET['mes'];
        if ($mes < 1 || $mes > 12) {
            $mes = 0;
        }
    }

    return [
        'mes'      => $mes,
        'curso'    => mb_strtoupper(trim((string)($_GET['curso'] ?? '')), 'UTF-8'),
        'buscar'   => trim((string)($_GET['buscar'] ?? '')),
        'estado'   => trim((string)($_GET['estado'] ?? '')),
        'beca'     => trim((string)($_GET['beca'] ?? '')),
        'beca_pct' => trim((string)($_GET['beca_pct'] ?? '')),
    ];
}

/**
 * Estados de cuenta solo se consultan con mes de corte elegido.
 */
function estado_alumno_cuentas_filtros_completos(array $filtros): bool
{
    $mes = (int)($filtros['mes'] ?? 0);

    return $mes >= 1 && $mes <= 12;
}

/**
 * Listado por familias (secretaría): solo consulta si hay mes de corte en la URL
 * (es decir, tras enviar el formulario de filtros).
 */
function estado_alumno_listado_familias_debe_consultar(): bool
{
    if (!isset($_GET['mes']) || $_GET['mes'] === '') {
        return false;
    }
    $mes = (int)$_GET['mes'];

    return $mes >= 1 && $mes <= 12;
}

function estado_alumno_fmt_monto(float $monto): string
{
    return number_format($monto, 2, ',', '.');
}

/**
 * @param array{mes:int,curso:string,buscar:string,estado:string,beca:string,beca_pct:string} $filtros
 * @return array{
 *   alumnos: list<array<string, mixed>>,
 *   resumen: array{total:int,al_dia:int,con_deuda:int,becados:int},
 *   nombre_mes: string,
 *   error: string
 * }
 */
function estado_alumno_cargar_estado_cuenta_alumnos(
    mysqli $conn,
    string $escuelaCodigo,
    array $filtros,
    array $cursosPorEscuela
): array {
    $escuelaCodigo = strtoupper(trim($escuelaCodigo));
    $ambitoComplejo = ($escuelaCodigo === 'ALL' || $escuelaCodigo === 'COMPLEJO');
    $mesSeleccionado = (int)$filtros['mes'];
    $nombreMes = $mesSeleccionado >= 1 && $mesSeleccionado <= 12
        ? admin_cuota_nombre_mes($mesSeleccionado)
        : '';
    $umbral = admin_cuota_umbral_al_dia();

    if (!estado_alumno_cuentas_filtros_completos($filtros)) {
        return [
            'alumnos'         => [],
            'resumen'         => ['total' => 0, 'al_dia' => 0, 'con_deuda' => 0, 'becados' => 0],
            'nombre_mes'      => $nombreMes,
            'ambito_complejo' => $ambitoComplejo,
            'error'           => '',
        ];
    }

    $cursoFiltro = (string)$filtros['curso'];
    if ($cursoFiltro !== '') {
        if ($ambitoComplejo) {
            $cursosTodos = estado_alumno_cursos_todos($cursosPorEscuela);
            $cursoValido = false;
            foreach ($cursosTodos as $cursoItem) {
                if (mb_strtoupper(trim((string)$cursoItem), 'UTF-8') === $cursoFiltro) {
                    $cursoValido = true;
                    break;
                }
            }
            if (!$cursoValido) {
                $cursoFiltro = '';
            }
        } elseif (!estado_alumno_curso_pertenece_escuela($cursoFiltro, $escuelaCodigo, $cursosPorEscuela)) {
            $cursoFiltro = '';
        }
    }

    $sql = "SELECT
                l.nro_legajo,
                l.apellido_alumno,
                l.nombre_alumno,
                l.curso,
                l.dni_alumno,
                l.nro_familia,
                l.porcentaje_descuento,
                l.codigo_descuento,
                c.numero_cuota,
                COALESCE(c.diferencia, 0) AS diferencia
            FROM legajos l
            LEFT JOIN cuotas c
                ON c.nro_legajo COLLATE utf8mb4_unicode_ci = l.nro_legajo COLLATE utf8mb4_unicode_ci
               AND c.numero_cuota <= 12";

    $where = [];
    $types = '';
    $params = [];

    if (!$ambitoComplejo) {
        if (!function_exists('tenant_filtro_sql_escuela')) {
            require_once NATTA_ROOT . '/config/tenant_helpers.php';
        }
        $filtroEscuela = tenant_filtro_sql_escuela($escuelaCodigo);
        $where[] = $filtroEscuela['sql'];
        $types .= $filtroEscuela['types'];
        foreach ($filtroEscuela['params'] as $param) {
            $params[] = $param;
        }
    }

    if ($cursoFiltro !== '') {
        $where[] = 'UPPER(TRIM(l.curso)) = ?';
        $types .= 's';
        $params[] = $cursoFiltro;
    }

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY l.apellido_alumno ASC, l.nombre_alumno ASC, l.nro_legajo ASC, c.numero_cuota ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [
            'alumnos'         => [],
            'resumen'         => ['total' => 0, 'al_dia' => 0, 'con_deuda' => 0, 'becados' => 0],
            'nombre_mes'      => $nombreMes,
            'ambito_complejo' => $ambitoComplejo,
            'error'           => 'No se pudo preparar la consulta de alumnos.',
        ];
    }

    if ($types !== '') {
        estado_alumno_stmt_bind($stmt, $types, $params);
    }
    $stmt->execute();
    $filas = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();

    $legajosData = [];
    foreach ($filas as $fila) {
        $leg = trim((string)($fila['nro_legajo'] ?? ''));
        if ($leg === '' || $leg === 'INACTIVO') {
            continue;
        }

        if (!isset($legajosData[$leg])) {
            $legajosData[$leg] = [
                'nro_legajo'           => $leg,
                'apellido_alumno'       => $fila['apellido_alumno'] ?? '',
                'nombre_alumno'         => $fila['nombre_alumno'] ?? '',
                'curso'                 => $fila['curso'] ?? '',
                'dni_alumno'            => $fila['dni_alumno'] ?? '',
                'nro_familia'           => $fila['nro_familia'] ?? '',
                'porcentaje_descuento'  => (int)($fila['porcentaje_descuento'] ?? 0),
                'codigo_descuento'      => $fila['codigo_descuento'] ?? '',
                'cuotas'                => [],
            ];
        }

        if (!is_null($fila['numero_cuota'])) {
            $legajosData[$leg]['cuotas'][] = [
                'numero_cuota' => (int)$fila['numero_cuota'],
                'diferencia'   => (float)$fila['diferencia'],
            ];
        }
    }

    $buscarNorm = mb_strtoupper((string)$filtros['buscar'], 'UTF-8');
    $estadoFiltro = (string)$filtros['estado'];
    $becaFiltro = (string)$filtros['beca'];
    $becaPctFiltro = (string)$filtros['beca_pct'];
    $becaPctValidos = ['25', '50', '75', '100'];

    $alumnos = [];
    foreach ($legajosData as $info) {
        $curso = trim((string)($info['curso'] ?? ''));
        $escuela = estado_alumno_obtener_escuela_desde_curso($curso);
        $ventana = admin_cuota_acumular_ventana_legajo($info['cuotas'], $escuela, $mesSeleccionado, false);
        $deuda = (float)$ventana['deuda_neta'];
        $alDia = $deuda <= $umbral;
        $pctBeca = (int)($info['porcentaje_descuento'] ?? 0);

        if ($cursoFiltro !== '' && mb_strtoupper(trim($curso), 'UTF-8') !== $cursoFiltro) {
            continue;
        }

        if ($estadoFiltro === 'al_dia' && !$alDia) {
            continue;
        }
        if ($estadoFiltro === 'con_deuda' && $alDia) {
            continue;
        }

        if ($becaFiltro === 'con_beca' && $pctBeca <= 0) {
            continue;
        }
        if ($becaFiltro === 'sin_beca' && $pctBeca > 0) {
            continue;
        }

        if ($becaPctFiltro !== '' && in_array($becaPctFiltro, $becaPctValidos, true) && $pctBeca !== (int)$becaPctFiltro) {
            continue;
        }

        if ($buscarNorm !== '') {
            $texto = mb_strtoupper(
                ($info['apellido_alumno'] ?? '') . ' ' .
                ($info['nombre_alumno'] ?? '') . ' ' .
                ($info['nro_legajo'] ?? '') . ' ' .
                ($info['dni_alumno'] ?? '') . ' ' .
                ($info['curso'] ?? ''),
                'UTF-8'
            );
            if (mb_strpos($texto, $buscarNorm, 0, 'UTF-8') === false) {
                continue;
            }
        }

        $alumnos[] = [
            'nro_legajo'           => $info['nro_legajo'],
            'apellido_alumno'      => $info['apellido_alumno'],
            'nombre_alumno'        => $info['nombre_alumno'],
            'curso'                => $info['curso'],
            'escuela_codigo'       => $escuela,
            'dni_alumno'           => $info['dni_alumno'],
            'nro_familia'          => $info['nro_familia'],
            'porcentaje_descuento' => $pctBeca,
            'codigo_descuento'     => $info['codigo_descuento'],
            'deuda_hasta_mes'      => $deuda,
            'primer_mes_impago'    => $ventana['primer_mes_impago'],
            'ultimo_mes_impago'    => $ventana['ultimo_mes_impago'],
            'al_dia'               => $alDia,
        ];
    }

    $resumen = ['total' => 0, 'al_dia' => 0, 'con_deuda' => 0, 'becados' => 0];
    foreach ($alumnos as $alumno) {
        $resumen['total']++;
        if (!empty($alumno['al_dia'])) {
            $resumen['al_dia']++;
        } else {
            $resumen['con_deuda']++;
        }
        if ((int)($alumno['porcentaje_descuento'] ?? 0) > 0) {
            $resumen['becados']++;
        }
    }

    return [
        'alumnos'          => $alumnos,
        'resumen'          => $resumen,
        'nombre_mes'       => $nombreMes,
        'ambito_complejo'  => $ambitoComplejo,
        'error'            => '',
    ];
}

/**
 * @return array{curso: string, buscar: string}
 */
function estado_alumno_parse_filtros_emails(): array
{
    return [
        'curso'  => mb_strtoupper(trim((string)($_GET['curso'] ?? '')), 'UTF-8'),
        'buscar' => trim((string)($_GET['buscar'] ?? '')),
    ];
}

/**
 * @param array{curso:string,buscar:string} $filtros
 * @return array{
 *   alumnos: list<array<string, mixed>>,
 *   total: int,
 *   error: string
 * }
 */
function estado_alumno_cargar_emails_alumnos(
    mysqli $conn,
    string $escuelaCodigo,
    array $filtros,
    array $cursosPorEscuela
): array {
    $escuelaCodigo = strtoupper(trim($escuelaCodigo));

    if (!function_exists('tenant_filtro_sql_escuela')) {
        require_once NATTA_ROOT . '/config/tenant_helpers.php';
    }
    $filtroEscuela = tenant_filtro_sql_escuela($escuelaCodigo);

    $sql = "SELECT
                l.nro_legajo,
                l.apellido_alumno,
                l.nombre_alumno,
                l.curso,
                l.nro_familia,
                ef.mail_padre,
                ef.mail_padre_trabajo,
                ef.mail_madre,
                ef.mail_madre_trabajo
            FROM legajos l
            LEFT JOIN email_familia ef
                ON TRIM(ef.nro_familia) = TRIM(CAST(l.nro_familia AS CHAR))
            WHERE {$filtroEscuela['sql']}
              AND TRIM(COALESCE(l.nro_legajo, '')) <> ''
              AND UPPER(TRIM(l.nro_legajo)) <> 'INACTIVO'
            ORDER BY l.apellido_alumno ASC, l.nombre_alumno ASC, l.nro_legajo ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [
            'alumnos' => [],
            'total'   => 0,
            'error'   => 'No se pudo preparar la consulta de emails.',
        ];
    }

    estado_alumno_stmt_bind($stmt, $filtroEscuela['types'], $filtroEscuela['params']);
    $stmt->execute();
    $filas = estado_alumno_fetch_all_from_stmt($stmt);
    $stmt->close();

    $cursoFiltro = (string)$filtros['curso'];
    if ($cursoFiltro !== '' && !estado_alumno_curso_pertenece_escuela($cursoFiltro, $escuelaCodigo, $cursosPorEscuela)) {
        $cursoFiltro = '';
    }

    $buscarNorm = mb_strtoupper((string)$filtros['buscar'], 'UTF-8');
    $alumnos = [];

    foreach ($filas as $fila) {
        $leg = trim((string)($fila['nro_legajo'] ?? ''));
        if ($leg === '') {
            continue;
        }

        $curso = trim((string)($fila['curso'] ?? ''));
        if ($cursoFiltro !== '' && mb_strtoupper($curso, 'UTF-8') !== $cursoFiltro) {
            continue;
        }

        $apellido = trim((string)($fila['apellido_alumno'] ?? ''));
        $nombre = trim((string)($fila['nombre_alumno'] ?? ''));
        $nombreCompleto = trim($apellido . ', ' . $nombre, ', ');

        if ($buscarNorm !== '') {
            $hayCoincidencia = false;
            foreach ([$apellido, $nombre, $nombreCompleto, $leg, (string)($fila['nro_familia'] ?? '')] as $campo) {
                if ($campo !== '' && mb_strpos(mb_strtoupper($campo, 'UTF-8'), $buscarNorm, 0, 'UTF-8') !== false) {
                    $hayCoincidencia = true;
                    break;
                }
            }
            if (!$hayCoincidencia) {
                foreach (['mail_padre', 'mail_padre_trabajo', 'mail_madre', 'mail_madre_trabajo'] as $mailCol) {
                    $mail = trim((string)($fila[$mailCol] ?? ''));
                    if ($mail !== '' && mb_strpos(mb_strtoupper($mail, 'UTF-8'), $buscarNorm, 0, 'UTF-8') !== false) {
                        $hayCoincidencia = true;
                        break;
                    }
                }
            }
            if (!$hayCoincidencia) {
                continue;
            }
        }

        $alumnos[] = [
            'nro_legajo'          => $leg,
            'apellido_alumno'     => $apellido,
            'nombre_alumno'       => $nombre,
            'curso'               => $curso,
            'nro_familia'         => $fila['nro_familia'] ?? '',
            'mail_padre'          => trim((string)($fila['mail_padre'] ?? '')),
            'mail_padre_trabajo'  => trim((string)($fila['mail_padre_trabajo'] ?? '')),
            'mail_madre'          => trim((string)($fila['mail_madre'] ?? '')),
            'mail_madre_trabajo'  => trim((string)($fila['mail_madre_trabajo'] ?? '')),
        ];
    }

    return [
        'alumnos' => $alumnos,
        'total'   => count($alumnos),
        'error'   => '',
    ];
}

/**
 * @param list<array<string, mixed>> $alumnos
 */
function estado_alumno_export_emails_excel(array $alumnos, string $escuelaCodigo, string $escuelaNombre): void
{
    $escuelaCodigo = preg_replace('/[^A-Za-z0-9_-]+/', '', $escuelaCodigo) ?: 'escuela';
    $fecha = date('Y-m-d');
    $filename = 'emails_familia_' . $escuelaCodigo . '_' . $fecha . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        throw new RuntimeException('No se pudo iniciar la exportación.');
    }

    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, [
        'Escuela',
        'Curso',
        'Apellido',
        'Nombre',
        'Mail1 (padre)',
        'Mail2 (padre trabajo)',
        'Mail3 (madre)',
        'Mail4 (madre trabajo)',
    ], ';');

    foreach ($alumnos as $alumno) {
        fputcsv($out, [
            $escuelaNombre,
            (string)($alumno['curso'] ?? ''),
            (string)($alumno['apellido_alumno'] ?? ''),
            (string)($alumno['nombre_alumno'] ?? ''),
            (string)($alumno['mail_padre'] ?? ''),
            (string)($alumno['mail_padre_trabajo'] ?? ''),
            (string)($alumno['mail_madre'] ?? ''),
            (string)($alumno['mail_madre_trabajo'] ?? ''),
        ], ';');
    }

    fclose($out);
}
