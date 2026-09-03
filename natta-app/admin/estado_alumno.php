<?php
/**
 * Panel de documentación de alumnos para secretarías.
 * Acceso restringido por escuela según el usuario creado en admin.
 */

define('NATTA_ROOT', dirname(__DIR__));

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/estado_alumno_lib.php';

$estadoAlumnoSelf = 'estado_alumno.php';
$pdo = admin_get_pdo();
$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $failKey = admin_login_fail_key($username);
    $lockedUntil = (int)($_SESSION[$failKey . '_locked'] ?? 0);

    if ($lockedUntil > time()) {
        $waitSeconds = $lockedUntil - time();
        $login_error = 'Acceso temporalmente bloqueado. Reintente en ' . $waitSeconds . ' segundos.';
    } elseif (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        $login_error = 'Solicitud inválida.';
    } elseif ($username === '' || $password === '') {
        $login_error = 'Usuario y contraseña son obligatorios.';
    } else {
        $user = admin_find_user_by_username($pdo, $username);
        if (
            $user
            && password_verify($password, $user['password_hash'])
            && admin_can_access_estado_alumno_from_user($user)
        ) {
            admin_set_session_user($user);
            unset($_SESSION[$failKey . '_count'], $_SESSION[$failKey . '_locked']);
            secure_session_regenerate();
            admin_touch_last_login($pdo, (int)$user['id']);
            admin_audit_log($pdo, 'login_ok', 'admin_user', (int)$user['id'], [
                'username' => $user['username'],
                'destino' => 'estado_alumno',
            ]);
            header('Location: ' . $estadoAlumnoSelf);
            exit;
        }

        $failCount = (int)($_SESSION[$failKey . '_count'] ?? 0) + 1;
        $_SESSION[$failKey . '_count'] = $failCount;
        if ($failCount >= 5) {
            $_SESSION[$failKey . '_locked'] = time() + 900;
            $_SESSION[$failKey . '_count'] = 0;
        }
        admin_audit_log($pdo, 'login_fail', 'admin_user', null, [
            'username' => $username,
            'destino' => 'estado_alumno',
        ]);
        $login_error = 'Usuario o contraseña incorrectos.';
    }
}

if (!admin_is_logged_in() || !admin_can_access_estado_alumno()) {
    require __DIR__ . '/views/secretaria_login.php';
    exit;
}

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    require_once __DIR__ . '/includes/db_collate.php';
    admin_mysqli_apply_collation($conn);
    estado_alumno_ensure_contratos_schema($conn);
} catch (Throwable $e) {
    error_log('Error de conexión estado_alumno: ' . $e->getMessage());
    die('Error interno del servidor. Intente más tarde.');
}

$escuelas = estado_alumno_escuelas_catalog();
$vistaActiva = estado_alumno_resolve_vista((string)($_GET['vista'] ?? 'cuentas'));
// En Estados de cuenta / Listado por familias (y Control de Usuarios para admin), se puede consultar todo el complejo.
$permitirComplejoCuentas = ($vistaActiva === 'cuentas' || $vistaActiva === 'listado_familias');
$permitirComplejoControl = ($vistaActiva === 'control_usuarios' && !admin_is_directivo());
$permitirComplejo = $permitirComplejoCuentas || $permitirComplejoControl;
$escuelaActiva = estado_alumno_resolve_escuela_activa(
    $escuelas,
    (string)($_GET['escuela'] ?? ($permitirComplejo ? 'ALL' : 'CJ')),
    $permitirComplejo
);
$ambitoCuentasComplejo = (
    ($vistaActiva === 'cuentas' || $vistaActiva === 'listado_familias')
    && $escuelaActiva === 'ALL'
);
// Mutations (contratos) siempre contra la escuela asignada de la secretaría/directivo.
$escuelaEscritura = admin_is_rol_escuela()
    ? admin_escuela_codigo()
    : (($escuelaActiva === 'ALL') ? 'CJ' : $escuelaActiva);
// Tabs de escuela: admins siempre; roles de escuela solo en cuentas/listado (o control, si admin).
$mostrarTabsEscuelas = !admin_is_rol_escuela()
    || $vistaActiva === 'cuentas'
    || $vistaActiva === 'listado_familias'
    || ($vistaActiva === 'control_usuarios' && !admin_is_directivo());
$cursoSeleccionado = mb_strtoupper(trim((string)($_GET['curso'] ?? '')), 'UTF-8');
$usuarioActual = admin_current_user();
$filtrosCuenta = estado_alumno_parse_filtros_cuenta();
$cuentasData = [
    'alumnos' => [],
    'resumen' => ['total' => 0, 'al_dia' => 0, 'con_deuda' => 0, 'becados' => 0],
    'nombre_mes' => '',
    'ambito_complejo' => false,
    'error' => '',
];
$cuentasCargadas = false;
$ultimaActualizacion = '';
$listadoFamiliasData = [];
$listadoFamiliasError = '';
$listadoFamiliasCargado = false;
$bolsaData = ['curriculums' => [], 'total' => 0, 'error' => ''];
$filtrosBolsa = estado_alumno_parse_filtros_bolsa();
$bolsaBuscar = $filtrosBolsa['buscar'];
$bolsaAreaFiltro = $filtrosBolsa['area'];
$filtrosEmails = estado_alumno_parse_filtros_emails();
$emailsData = ['alumnos' => [], 'total' => 0, 'error' => ''];
$alumnosRevision = [];
$errorCargaRevision = '';
$totalAlumnosRevision = 0;
$totalFirmadosRevision = 0;
$totalAprobadosRevision = 0;
$totalInfoErroneaRevision = 0;
$buscarContratos = (string)($filtrosCuenta['buscar'] ?? '');
$secretarias = [];
$resumenPorUser = [];
$actividad = [];
$cursosTrabajados = [];
$avance = [
    'total' => 0,
    'firmados' => 0,
    'doc_recibida' => 0,
    'info_erronea' => 0,
    'pendientes_doc' => 0,
    'error' => '',
];
$pctDoc = 0;
$pctFirmados = 0;
$escuelaDirectivo = admin_is_directivo() ? admin_escuela_codigo() : $escuelaActiva;
$nombreEscuela = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_info_erronea') {
    header('Content-Type: application/json; charset=utf-8');

    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Token CSRF inválido. Recargue la página.']);
        $conn->close();
        exit;
    }

    $studentDni = trim((string)($_POST['student_dni'] ?? ''));
    $infoErronea = ((int)($_POST['info_erronea'] ?? 0) === 1) ? 1 : 0;

    if ($studentDni === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'DNI de alumno inválido.']);
        $conn->close();
        exit;
    }

    if (admin_is_rol_escuela() && !estado_alumno_alumno_pertenece_escuela($conn, $studentDni, $escuelaEscritura)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'No tiene permiso para modificar alumnos de otra escuela.']);
        $conn->close();
        exit;
    }

    if (!contrato_db_column_exists($conn, 'contratos_aceptados', 'info_erronea')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'La columna info_erronea no está disponible.']);
        $conn->close();
        exit;
    }

    $contractVersion = estado_alumno_obtener_contrato_vigente_por_alumno($conn, $studentDni);
    if ($contractVersion === '') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'msg' => 'No hay contrato vigente configurado para la institución del alumno.']);
        $conn->close();
        exit;
    }

    $sqlUpdate = "UPDATE contratos_aceptados
                  SET info_erronea = ?, updated_at = NOW()
                  WHERE status = 'activo'
                    AND contract_version = ?
                    AND " . admin_sql_collate('TRIM(student_dni)') . ' = ?';
    $stmtUpdate = $conn->prepare($sqlUpdate);
    if (!$stmtUpdate) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'No se pudo preparar la actualización.']);
        $conn->close();
        exit;
    }

    try {
        $stmtUpdate->bind_param('iss', $infoErronea, $contractVersion, $studentDni);
        $stmtUpdate->execute();
        $filas = $stmtUpdate->affected_rows;
        $stmtUpdate->close();
    } catch (mysqli_sql_exception $e) {
        error_log('estado_alumno toggle info_erronea: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'msg' => 'No se pudo actualizar el aviso de info errónea.']);
        $conn->close();
        exit;
    }

    if ($filas < 1) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'msg' => 'El alumno no tiene un contrato firmado activo para la versión vigente.']);
        $conn->close();
        exit;
    }

    $alumnoResumen = estado_alumno_lookup_alumno_resumen($conn, $studentDni);
    admin_audit_log($pdo, 'info_erronea', 'alumno', null, [
        'valor' => $infoErronea === 1,
        'student_dni' => $studentDni,
        'curso' => $alumnoResumen['curso'],
        'escuela' => $alumnoResumen['escuela'] !== '' ? $alumnoResumen['escuela'] : $escuelaEscritura,
        'alumno' => trim($alumnoResumen['apellido'] . ', ' . $alumnoResumen['nombre'], ' ,'),
        'legajo' => $alumnoResumen['legajo'],
        'contract_version' => $contractVersion,
    ]);

    echo json_encode([
        'ok' => true,
        'info_erronea' => $infoErronea === 1,
        'msg' => $infoErronea === 1
            ? 'Marcado como info errónea.'
            : 'Se quitó el aviso de info errónea.',
    ]);
    $conn->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_admin_aprobado') {
    header('Content-Type: application/json; charset=utf-8');

    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Token CSRF inválido. Recargue la página.']);
        $conn->close();
        exit;
    }

    $studentDni = trim((string)($_POST['student_dni'] ?? ''));
    $adminAprobado = ((int)($_POST['admin_aprobado'] ?? 0) === 1) ? 1 : 0;

    if ($studentDni === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'DNI de alumno inválido.']);
        $conn->close();
        exit;
    }

    if (admin_is_rol_escuela() && !estado_alumno_alumno_pertenece_escuela($conn, $studentDni, $escuelaEscritura)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'No tiene permiso para modificar alumnos de otra escuela.']);
        $conn->close();
        exit;
    }

    $result = estado_alumno_set_doc_recibida($conn, $studentDni, $adminAprobado);
    if (empty($result['ok'])) {
        $msg = (string)($result['msg'] ?? 'No se pudo actualizar la documentación recibida.');
        $code = (stripos($msg, 'vigente') !== false) ? 409 : 500;
        http_response_code($code);
        echo json_encode(['ok' => false, 'msg' => $msg]);
        $conn->close();
        exit;
    }

    $alumnoResumen = estado_alumno_lookup_alumno_resumen($conn, $studentDni);
    admin_audit_log($pdo, 'doc_recibida', 'alumno', null, [
        'valor' => !empty($result['admin_aprobado']),
        'student_dni' => $studentDni,
        'curso' => $alumnoResumen['curso'],
        'escuela' => $alumnoResumen['escuela'] !== '' ? $alumnoResumen['escuela'] : $escuelaEscritura,
        'alumno' => trim($alumnoResumen['apellido'] . ', ' . $alumnoResumen['nombre'], ' ,'),
        'legajo' => $alumnoResumen['legajo'],
    ]);

    echo json_encode([
        'ok' => true,
        'admin_aprobado' => !empty($result['admin_aprobado']),
        'msg' => (string)($result['msg'] ?? 'Cambio guardado.'),
    ]);
    $conn->close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_admin_aprobado') {
    header('Content-Type: application/json; charset=utf-8');

    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])
    ) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Token CSRF inválido. Recargue la página.']);
        $conn->close();
        exit;
    }

    $cursoBulk = mb_strtoupper(trim((string)($_POST['curso'] ?? '')), 'UTF-8');
    $escuelaBulk = strtoupper(trim((string)($_POST['escuela'] ?? '')));
    $adminAprobado = ((int)($_POST['admin_aprobado'] ?? 0) === 1) ? 1 : 0;

    if ($cursoBulk === '' && ($escuelaBulk === '' || strlen($escuelaBulk) !== 2)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'msg' => 'Curso o escuela inválidos.']);
        $conn->close();
        exit;
    }

    $cursosPorEscuelaBulk = estado_alumno_construir_cursos_por_escuela($conn, $escuelas);
    if ($cursoBulk !== '') {
        if (!estado_alumno_curso_pertenece_escuela($cursoBulk, $escuelaEscritura, $cursosPorEscuelaBulk)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'msg' => 'El curso seleccionado no pertenece a su escuela.']);
            $conn->close();
            exit;
        }
        $contractVersion = estado_alumno_obtener_contrato_vigente_por_curso($conn, $cursoBulk);
    } else {
        if (admin_is_rol_escuela() && $escuelaBulk !== $escuelaEscritura) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'msg' => 'No tiene permiso para modificar otra escuela.']);
            $conn->close();
            exit;
        }
        if (!isset($escuelas[$escuelaBulk])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => 'Escuela inválida.']);
            $conn->close();
            exit;
        }
        $contractVersion = estado_alumno_obtener_contrato_vigente_por_escuela($conn, $escuelaBulk);
    }

    $result = estado_alumno_bulk_set_doc_recibida(
        $conn,
        $cursoBulk,
        $escuelaBulk,
        $contractVersion,
        $adminAprobado
    );
    if (empty($result['ok'])) {
        $msg = (string)($result['msg'] ?? 'No se pudo actualizar la documentación del curso.');
        $code = (stripos($msg, 'vigente') !== false) ? 409 : 500;
        http_response_code($code);
        echo json_encode(['ok' => false, 'msg' => $msg]);
        $conn->close();
        exit;
    }

    admin_audit_log($pdo, 'bulk_doc_recibida', 'curso', null, [
        'valor' => !empty($result['admin_aprobado']),
        'curso' => $cursoBulk,
        'escuela' => $escuelaBulk !== '' ? $escuelaBulk : $escuelaEscritura,
        'actualizados' => (int)($result['actualizados'] ?? 0),
        'contract_version' => $contractVersion,
    ]);

    echo json_encode([
        'ok' => true,
        'admin_aprobado' => !empty($result['admin_aprobado']),
        'actualizados' => (int)($result['actualizados'] ?? 0),
        'msg' => (string)($result['msg'] ?? 'Curso actualizado.'),
    ]);
    $conn->close();
    exit;
}

$cursosPorEscuela = estado_alumno_construir_cursos_por_escuela($conn, $escuelas);
$cursosEscuelaActiva = $ambitoCuentasComplejo
    ? estado_alumno_cursos_todos($cursosPorEscuela)
    : ($cursosPorEscuela[$escuelaActiva] ?? []);

$contractVersion = '';
$alumnos = [];
$errorCarga = '';
$totalAlumnos = 0;
$totalFirmados = 0;
$totalAprobados = 0;

if ($vistaActiva === 'contratos' || $vistaActiva === 'revision_contratos') {
    if ($cursoSeleccionado !== '') {
        if (!estado_alumno_curso_pertenece_escuela($cursoSeleccionado, $escuelaActiva, $cursosPorEscuela)) {
            $cursoSeleccionado = '';
        }
    }

    $contractVersion = $cursoSeleccionado !== ''
        ? estado_alumno_obtener_contrato_vigente_por_curso($conn, $cursoSeleccionado)
        : estado_alumno_obtener_contrato_vigente_por_escuela($conn, $escuelaActiva);

    if ($vistaActiva === 'contratos') {
        try {
            $alumnos = estado_alumno_cargar_alumnos_activos_por_curso(
                $conn,
                $cursoSeleccionado,
                $contractVersion,
                $buscarContratos,
                $escuelaActiva
            );
        } catch (Throwable $e) {
            error_log('estado_alumno alumnos: ' . $e->getMessage());
            $alumnos = [];
            $errorCarga = 'No se pudieron cargar los alumnos desde legajos. Intente nuevamente en unos minutos.';
        }

        $totalAlumnos = count($alumnos);
        foreach ($alumnos as $alumno) {
            if (!empty($alumno['contrato_id'])) {
                $totalFirmados++;
                if ((int)($alumno['admin_aprobado'] ?? 0) === 1) {
                    $totalAprobados++;
                }
            }
        }
    } else {
        try {
            $alumnosRevision = estado_alumno_cargar_revision_contratos_por_curso(
                $conn,
                $cursoSeleccionado,
                $contractVersion,
                $buscarContratos,
                $escuelaActiva
            );
            if (admin_is_rol_escuela() && ($cursoSeleccionado !== '' || $buscarContratos !== '')) {
                admin_audit_log($pdo, 'revision_contratos', 'curso', null, [
                    'escuela' => $escuelaActiva,
                    'curso' => $cursoSeleccionado,
                    'buscar' => $buscarContratos,
                    'total' => count($alumnosRevision),
                ]);
            }
        } catch (Throwable $e) {
            error_log('estado_alumno revision contratos: ' . $e->getMessage());
            $alumnosRevision = [];
            $errorCargaRevision = 'No se pudieron cargar los datos de revisión de contratos. Intente nuevamente en unos minutos.';
        }

        $totalAlumnosRevision = count($alumnosRevision);
        foreach ($alumnosRevision as $alumno) {
            if (!empty($alumno['contrato_id'])) {
                $totalFirmadosRevision++;
                if ((int)($alumno['info_erronea'] ?? 0) === 1) {
                    $totalInfoErroneaRevision++;
                }
            }
            if ((int)($alumno['admin_aprobado'] ?? 0) === 1) {
                $totalAprobadosRevision++;
            }
        }
    }
} elseif ($vistaActiva === 'cuentas') {
    require_once __DIR__ . '/includes/functions.php';
    $ultimaActualizacion = admin_ultima_actualizacion_rptfacing();

    $hayFiltrosCuenta = estado_alumno_cuentas_filtros_completos($filtrosCuenta);

    if ($hayFiltrosCuenta) {
        $cuentasCargadas = true;
        try {
            $cuentasData = estado_alumno_cargar_estado_cuenta_alumnos($conn, $escuelaActiva, $filtrosCuenta, $cursosPorEscuela);
            if (admin_is_rol_escuela() && empty($cuentasData['error'])) {
                admin_audit_log($pdo, 'consulta_estado_cuenta', 'escuela', null, [
                    'escuela' => $escuelaActiva,
                    'curso' => (string)($filtrosCuenta['curso'] ?? ''),
                    'mes' => (int)($filtrosCuenta['mes'] ?? 0),
                    'buscar' => (string)($filtrosCuenta['buscar'] ?? ''),
                    'estado' => (string)($filtrosCuenta['estado'] ?? ''),
                    'total' => (int)($cuentasData['resumen']['total'] ?? 0),
                    'al_dia' => (int)($cuentasData['resumen']['al_dia'] ?? 0),
                    'con_deuda' => (int)($cuentasData['resumen']['con_deuda'] ?? 0),
                ]);
            }
        } catch (Throwable $e) {
            error_log('estado_alumno cuentas: ' . $e->getMessage());
            $cuentasData['error'] = 'No se pudo cargar el estado de cuenta. Intente nuevamente en unos minutos.';
        }
    }
} elseif ($vistaActiva === 'listado_familias') {
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/includes/listado_familias_data.php';
    $ultimaActualizacion = admin_ultima_actualizacion_rptfacing();

    if (estado_alumno_listado_familias_debe_consultar()) {
        $listadoFamiliasCargado = true;
        try {
            $listadoFamiliasData = admin_load_listado_familias_data();
            if (admin_is_rol_escuela() && $listadoFamiliasData !== []) {
                admin_audit_log($pdo, 'consulta_listado_familias', 'escuela', null, [
                    'escuela' => $escuelaActiva,
                    'mes' => (int)($listadoFamiliasData['mesSeleccionado'] ?? 0),
                    'buscar' => (string)($listadoFamiliasData['buscar'] ?? ''),
                    'estado' => (string)($listadoFamiliasData['estadoFiltro'] ?? ''),
                    'total' => (int)($listadoFamiliasData['totalFamilias'] ?? 0),
                    'al_dia' => (int)($listadoFamiliasData['totalFamiliasAlDia'] ?? 0),
                    'con_deuda' => (int)($listadoFamiliasData['totalFamiliasDeuda'] ?? 0),
                ]);
            }
        } catch (Throwable $e) {
            error_log('estado_alumno listado_familias: ' . $e->getMessage());
            $listadoFamiliasError = 'No se pudo cargar el listado por familias. Intente nuevamente en unos minutos.';
        }
    }
} elseif ($vistaActiva === 'bolsa') {
    try {
        $bolsaData = estado_alumno_cargar_curriculums($conn, $bolsaBuscar, $bolsaAreaFiltro);
    } catch (Throwable $e) {
        error_log('estado_alumno bolsa: ' . $e->getMessage());
        $bolsaData['error'] = 'No se pudo cargar la bolsa de trabajo. Intente nuevamente en unos minutos.';
    }
} elseif ($vistaActiva === 'emails') {
    try {
        $emailsData = estado_alumno_cargar_emails_alumnos($conn, $escuelaActiva, $filtrosEmails, $cursosPorEscuela);
    } catch (Throwable $e) {
        error_log('estado_alumno emails: ' . $e->getMessage());
        $emailsData['error'] = 'No se pudieron cargar los emails. Intente nuevamente en unos minutos.';
    }
} elseif ($vistaActiva === 'control_usuarios') {
    require_once __DIR__ . '/includes/control_usuarios_lib.php';
    $escuelaDirectivo = admin_is_directivo()
        ? admin_escuela_codigo()
        : ($escuelaActiva === '' ? 'ALL' : $escuelaActiva);
    if ($escuelaDirectivo !== 'ALL' && !isset($escuelas[$escuelaDirectivo])) {
        $escuelaDirectivo = admin_is_directivo() ? admin_escuela_codigo() : 'ALL';
    }
    $secretarias = control_usuarios_listar_secretarias(
        $pdo,
        $escuelaDirectivo === 'ALL' ? '' : $escuelaDirectivo
    );
    $secretariaIds = array_map(static fn ($s) => (int)$s['id'], $secretarias);
    $resumenPorUser = control_usuarios_resumen_por_secretaria($pdo, $secretariaIds, 14);
    $actividad = control_usuarios_actividad_reciente($pdo, $secretariaIds, $escuelaDirectivo, 100);
    $cursosTrabajados = control_usuarios_cursos_trabajados($actividad);
    if ($escuelaDirectivo !== '' && $escuelaDirectivo !== 'ALL') {
        try {
            $avance = control_usuarios_avance_contratos($conn, $escuelaDirectivo);
        } catch (Throwable $e) {
            error_log('estado_alumno control_usuarios: ' . $e->getMessage());
            $avance['error'] = 'No se pudo cargar el avance de contratos.';
        }
    }
    $nombreEscuela = $escuelaDirectivo === 'ALL'
        ? 'Todo el complejo'
        : (($escuelas[$escuelaDirectivo] ?? $escuelaDirectivo) . ' (' . $escuelaDirectivo . ')');
    $pctDoc = $avance['total'] > 0
        ? (int)round(($avance['doc_recibida'] / $avance['total']) * 100)
        : 0;
    $pctFirmados = $avance['total'] > 0
        ? (int)round(($avance['firmados'] / $avance['total']) * 100)
        : 0;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo admin_is_directivo() ? 'Dirección' : 'Secretaría'; ?> - Estado alumnos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/pages/admin.css">
    <link rel="stylesheet" href="../css/pages/estados-secretaria-documentacion.css">
    <?php if ($vistaActiva === 'listado_familias'): ?>
    <link rel="stylesheet" href="../css/pages/estados-listado-familias.css">
    <?php endif; ?>
</head>
<body data-page="estado-alumno">

    <div class="container-fluid admin-page-shell mt-4">
        <?php
        $headerLogoFile = 'logo4.png';
        $headerLogoAlt = 'Complejo Educativo Natta';
        ?>
        <div class="admin-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="admin-header__brand d-flex align-items-center gap-3">
                <img src="../assets/img/<?= htmlspecialchars($headerLogoFile, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($headerLogoAlt, ENT_QUOTES, 'UTF-8') ?>"
                     class="admin-header-logo">
                <div>
                    <h1 class="mb-0">
                        <?php echo admin_is_directivo() ? 'Dirección' : 'Secretaría'; ?> · Estado alumnos
                    </h1>
                    <p class="text-muted mb-0 small mt-1">
                        👤 <?php echo estado_alumno_h($usuarioActual['nombre']); ?>
                        (<?php echo estado_alumno_h($usuarioActual['username']); ?>)
                        <?php if (admin_is_rol_escuela()): ?>
                            · <span class="badge bg-primary"><?php
                                if ($vistaActiva === 'control_usuarios' && $nombreEscuela !== '') {
                                    echo estado_alumno_h($nombreEscuela);
                                } elseif ($ambitoCuentasComplejo) {
                                    echo 'Todo el complejo';
                                } else {
                                    echo estado_alumno_h($escuelas[$escuelaActiva] ?? $escuelaActiva);
                                }
                            ?></span>
                        <?php else: ?>
                            · <span class="badge bg-dark"><?php echo estado_alumno_h($usuarioActual['rol']); ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="text-muted mb-0 small mt-1">
                        <?php if ($vistaActiva === 'cuentas'): ?>
                            Consultá el estado de cuenta de cualquier alumno del complejo: buscá por apellido, DNI o legajo y verificá si está al día.
                        <?php elseif ($vistaActiva === 'listado_familias'): ?>
                            Consultá el estado de cuenta por familia: con un legajo ves a todos los hermanos y el resumen familiar.
                        <?php elseif ($vistaActiva === 'revision_contratos'): ?>
                            Revisión de los datos declarados al firmar y recepción de documentación del contrato.
                        <?php elseif ($vistaActiva === 'bolsa'): ?>
                            Currículums enviados a la bolsa de trabajo del complejo.
                        <?php elseif ($vistaActiva === 'emails'): ?>
                            Emails de contacto de las familias por alumno de su institución.
                        <?php elseif ($vistaActiva === 'control_usuarios'): ?>
                            Seguimiento del trabajo de secretaría: consultas de estados de cuenta, revisión de contratos y casillas de documentación.
                        <?php else: ?>
                            Estado de firma de contratos y descarga del PDF firmado por curso y escuela.
                        <?php endif; ?>
                    </p>
                    <div class="update-pill">
                        <span id="loadingBox" style="display:inline-flex;align-items:center;gap:10px;">
                            <span class="spinner"></span>
                            <span>Cargando datos, aguarde un momento...</span>
                        </span>
                        <span id="readyBox" style="display:none;align-items:center;gap:6px;">
                            <span class="update-dot"></span>
                            <span>
                                <?php if ($vistaActiva === 'cuentas' || $vistaActiva === 'listado_familias'): ?>
                                    Última actualización:
                                    <?php echo $ultimaActualizacion !== '' ? estado_alumno_h($ultimaActualizacion) : 'sin datos'; ?>
                                <?php elseif ($vistaActiva === 'bolsa'): ?>
                                    Currículums cargados: <?php echo (int)($bolsaData['total'] ?? 0); ?>
                                <?php elseif ($vistaActiva === 'emails'): ?>
                                    Alumnos con emails: <?php echo (int)($emailsData['total'] ?? 0); ?>
                                <?php elseif ($vistaActiva === 'revision_contratos'): ?>
                                    Contrato vigente:
                                    <?php echo $contractVersion !== '' ? estado_alumno_h($contractVersion) : 'sin versión activa'; ?>
                                    · Con firma: <?php echo (int)$totalFirmadosRevision; ?>
                                    · Doc. recibida: <?php echo (int)$totalAprobadosRevision; ?>
                                <?php elseif ($vistaActiva === 'control_usuarios'): ?>
                                    <?php if ($escuelaDirectivo !== 'ALL'): ?>
                                        Doc. recibida: <?php echo (int)$avance['doc_recibida']; ?>/<?php echo (int)$avance['total']; ?>
                                        · Firmados: <?php echo (int)$avance['firmados']; ?>
                                    <?php else: ?>
                                        Secretarías activas: <?php echo count($secretarias); ?>
                                        · Actividad reciente: <?php echo count($actividad); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Contrato vigente:
                                    <?php echo $contractVersion !== '' ? estado_alumno_h($contractVersion) : 'sin versión activa'; ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>
            <a href="admin_logout.php" class="btn btn-danger admin-header__logout">🚪 Cerrar sesión</a>
        </div>

        <nav class="admin-nav-sub mt-2" aria-label="Secciones">
            <ul class="nav nav-pills admin-nav-subtabs">
                <?php
                    $escuelaOtrasVistas = $escuelaEscritura;
                    $queryCuentasParams = [
                        'vista' => 'cuentas',
                        'escuela' => $escuelaActiva === '' ? 'ALL' : $escuelaActiva,
                    ];
                    if ((int)$filtrosCuenta['mes'] >= 1) {
                        $queryCuentasParams['mes'] = $filtrosCuenta['mes'];
                    }
                    if ($filtrosCuenta['curso'] !== '') {
                        $queryCuentasParams['curso'] = $filtrosCuenta['curso'];
                    }
                    if ($filtrosCuenta['estado'] !== '') {
                        $queryCuentasParams['estado'] = $filtrosCuenta['estado'];
                    }
                    if ($filtrosCuenta['beca'] !== '') {
                        $queryCuentasParams['beca'] = $filtrosCuenta['beca'];
                    }
                    if ($filtrosCuenta['beca_pct'] !== '') {
                        $queryCuentasParams['beca_pct'] = $filtrosCuenta['beca_pct'];
                    }
                    if ($filtrosCuenta['buscar'] !== '') {
                        $queryCuentasParams['buscar'] = $filtrosCuenta['buscar'];
                    }
                    $queryCuentas = http_build_query($queryCuentasParams, '', '&', PHP_QUERY_RFC3986);
                    $queryListadoFamiliasParams = [
                        'vista' => 'listado_familias',
                        'escuela' => $escuelaActiva === '' ? 'ALL' : $escuelaActiva,
                    ];
                    if ($vistaActiva === 'listado_familias') {
                        $mesLf = (int)($_GET['mes'] ?? ($listadoFamiliasData['mesSeleccionado'] ?? 0));
                        if ($mesLf >= 1) {
                            $queryListadoFamiliasParams['mes'] = $mesLf;
                        }
                        $estadoLf = trim((string)($_GET['estado'] ?? ($listadoFamiliasData['estadoFiltro'] ?? '')));
                        if ($estadoLf !== '') {
                            $queryListadoFamiliasParams['estado'] = $estadoLf;
                        }
                        $buscarLf = trim((string)($_GET['buscar'] ?? ($listadoFamiliasData['buscar'] ?? '')));
                        if ($buscarLf !== '') {
                            $queryListadoFamiliasParams['buscar'] = $buscarLf;
                        }
                        $listaLf = trim((string)($_GET['lista_json'] ?? ($listadoFamiliasData['listaJsonRaw'] ?? '')));
                        if ($listaLf !== '') {
                            $queryListadoFamiliasParams['lista_json'] = $listaLf;
                        }
                    }
                    $queryListadoFamilias = http_build_query($queryListadoFamiliasParams, '', '&', PHP_QUERY_RFC3986);
                    $queryContratos = http_build_query([
                        'vista' => 'contratos',
                        'escuela' => $escuelaOtrasVistas,
                        'curso' => ($vistaActiva === 'contratos' || $vistaActiva === 'revision_contratos') ? $cursoSeleccionado : '',
                    ]);
                    $queryRevisionContratos = http_build_query([
                        'vista' => 'revision_contratos',
                        'escuela' => $escuelaOtrasVistas,
                        'curso' => ($vistaActiva === 'contratos' || $vistaActiva === 'revision_contratos') ? $cursoSeleccionado : '',
                    ]);
                    $queryEmails = http_build_query(array_filter([
                        'vista' => 'emails',
                        'escuela' => $escuelaOtrasVistas,
                        'curso' => $filtrosEmails['curso'] !== '' ? $filtrosEmails['curso'] : null,
                        'buscar' => $filtrosEmails['buscar'] !== '' ? $filtrosEmails['buscar'] : null,
                    ], static function ($v) {
                        return $v !== null && $v !== '';
                    }), '', '&', PHP_QUERY_RFC3986);
                    $queryBolsa = http_build_query(array_filter([
                        'vista' => 'bolsa',
                        'escuela' => $escuelaOtrasVistas,
                        'buscar_bolsa' => $bolsaBuscar !== '' ? $bolsaBuscar : null,
                        'area_bolsa' => $bolsaAreaFiltro !== '' ? $bolsaAreaFiltro : null,
                    ], static function ($v) {
                        return $v !== null && $v !== '';
                    }), '', '&', PHP_QUERY_RFC3986);
                    $queryControlUsuarios = http_build_query([
                        'vista' => 'control_usuarios',
                        'escuela' => admin_is_directivo()
                            ? admin_escuela_codigo()
                            : ($escuelaActiva === '' ? 'ALL' : $escuelaActiva),
                    ]);
                ?>
                <?php
                    $vistasNav = [
                        'cuentas' => ['label' => 'Estados de cuenta', 'icon' => '💰', 'query' => $queryCuentas],
                        'listado_familias' => ['label' => 'Listado por Familias', 'icon' => '👨‍👩‍👧‍👦', 'query' => $queryListadoFamilias],
                        'contratos' => ['label' => 'Contratos', 'icon' => '📄', 'query' => $queryContratos],
                        'revision_contratos' => ['label' => 'Revisión contratos', 'icon' => '🔍', 'query' => $queryRevisionContratos],
                        'emails' => ['label' => 'Emails familia', 'icon' => '✉️', 'query' => $queryEmails],
                        'bolsa' => ['label' => 'Bolsa de trabajo', 'icon' => '💼', 'query' => $queryBolsa],
                    ];
                    if (admin_can_access_control_usuarios()) {
                        $vistasNav['control_usuarios'] = [
                            'label' => 'Control de Usuarios',
                            'icon' => '👥',
                            'query' => $queryControlUsuarios,
                        ];
                    }
                    foreach ($vistasNav as $vistaKey => $vistaDef):
                ?>
                <li class="nav-item">
                    <a class="nav-link<?php echo $vistaActiva === $vistaKey ? ' active' : ''; ?>" href="?<?php echo estado_alumno_h($vistaDef['query']); ?>">
                        <span class="nav-icon" aria-hidden="true"><?php echo $vistaDef['icon']; ?></span>
                        <?php echo estado_alumno_h($vistaDef['label']); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <?php if ($mostrarTabsEscuelas && $vistaActiva !== 'bolsa'): ?>
        <nav class="admin-nav-sub mt-2 admin-nav-escuelas" aria-label="Escuelas">
            <ul class="nav nav-pills admin-nav-subtabs">
                <?php if ($vistaActiva === 'cuentas' || $vistaActiva === 'listado_familias' || ($vistaActiva === 'control_usuarios' && !admin_is_directivo())): ?>
                    <?php
                        $queryComplejo = ['vista' => $vistaActiva, 'escuela' => 'ALL'];
                        if ($vistaActiva === 'cuentas' && $ambitoCuentasComplejo) {
                            if ((int)$filtrosCuenta['mes'] >= 1) {
                                $queryComplejo['mes'] = $filtrosCuenta['mes'];
                            }
                            if ($filtrosCuenta['curso'] !== '') {
                                $queryComplejo['curso'] = $filtrosCuenta['curso'];
                            }
                            if ($filtrosCuenta['estado'] !== '') {
                                $queryComplejo['estado'] = $filtrosCuenta['estado'];
                            }
                            if ($filtrosCuenta['beca'] !== '') {
                                $queryComplejo['beca'] = $filtrosCuenta['beca'];
                            }
                            if ($filtrosCuenta['beca_pct'] !== '') {
                                $queryComplejo['beca_pct'] = $filtrosCuenta['beca_pct'];
                            }
                            if ($filtrosCuenta['buscar'] !== '') {
                                $queryComplejo['buscar'] = $filtrosCuenta['buscar'];
                            }
                        } elseif ($vistaActiva === 'listado_familias' && $ambitoCuentasComplejo) {
                            $mesLf = (int)($_GET['mes'] ?? ($listadoFamiliasData['mesSeleccionado'] ?? 0));
                            if ($mesLf >= 1) {
                                $queryComplejo['mes'] = $mesLf;
                            }
                            $estadoLf = trim((string)($_GET['estado'] ?? ($listadoFamiliasData['estadoFiltro'] ?? '')));
                            if ($estadoLf !== '') {
                                $queryComplejo['estado'] = $estadoLf;
                            }
                            $buscarLf = trim((string)($_GET['buscar'] ?? ($listadoFamiliasData['buscar'] ?? '')));
                            if ($buscarLf !== '') {
                                $queryComplejo['buscar'] = $buscarLf;
                            }
                            $listaLf = trim((string)($_GET['lista_json'] ?? ($listadoFamiliasData['listaJsonRaw'] ?? '')));
                            if ($listaLf !== '') {
                                $queryComplejo['lista_json'] = $listaLf;
                            }
                        }
                        $claseComplejo = (
                            (($vistaActiva === 'cuentas' || $vistaActiva === 'listado_familias') && $ambitoCuentasComplejo)
                            || ($vistaActiva === 'control_usuarios' && $escuelaDirectivo === 'ALL')
                        ) ? 'nav-link active' : 'nav-link';
                    ?>
                    <li class="nav-item">
                        <a class="<?php echo $claseComplejo; ?>" href="?<?php echo estado_alumno_h(http_build_query($queryComplejo)); ?>" title="Todas las escuelas del complejo">
                            Todo el complejo
                        </a>
                    </li>
                <?php endif; ?>
                <?php foreach ($escuelas as $codigo => $nombre): ?>
                    <?php
                        $queryEscuela = ['vista' => $vistaActiva, 'escuela' => $codigo];
                        if ($vistaActiva === 'contratos' || $vistaActiva === 'revision_contratos') {
                            $queryEscuela['curso'] = ($codigo === $escuelaActiva) ? $cursoSeleccionado : '';
                        } elseif ($vistaActiva === 'control_usuarios') {
                            // Solo filtro por escuela.
                        } elseif ($vistaActiva === 'cuentas') {
                            if ($codigo === $escuelaActiva) {
                                if ((int)$filtrosCuenta['mes'] >= 1) {
                                    $queryEscuela['mes'] = $filtrosCuenta['mes'];
                                }
                                if ($filtrosCuenta['curso'] !== '') {
                                    $queryEscuela['curso'] = $filtrosCuenta['curso'];
                                }
                                if ($filtrosCuenta['estado'] !== '') {
                                    $queryEscuela['estado'] = $filtrosCuenta['estado'];
                                }
                                if ($filtrosCuenta['beca'] !== '') {
                                    $queryEscuela['beca'] = $filtrosCuenta['beca'];
                                }
                                if ($filtrosCuenta['beca_pct'] !== '') {
                                    $queryEscuela['beca_pct'] = $filtrosCuenta['beca_pct'];
                                }
                                if ($filtrosCuenta['buscar'] !== '') {
                                    $queryEscuela['buscar'] = $filtrosCuenta['buscar'];
                                }
                            }
                        } elseif ($vistaActiva === 'listado_familias') {
                            if ($codigo === $escuelaActiva) {
                                $mesLf = (int)($_GET['mes'] ?? ($listadoFamiliasData['mesSeleccionado'] ?? 0));
                                if ($mesLf >= 1) {
                                    $queryEscuela['mes'] = $mesLf;
                                }
                                $estadoLf = trim((string)($_GET['estado'] ?? ($listadoFamiliasData['estadoFiltro'] ?? '')));
                                if ($estadoLf !== '') {
                                    $queryEscuela['estado'] = $estadoLf;
                                }
                                $buscarLf = trim((string)($_GET['buscar'] ?? ($listadoFamiliasData['buscar'] ?? '')));
                                if ($buscarLf !== '') {
                                    $queryEscuela['buscar'] = $buscarLf;
                                }
                                $listaLf = trim((string)($_GET['lista_json'] ?? ($listadoFamiliasData['listaJsonRaw'] ?? '')));
                                if ($listaLf !== '') {
                                    $queryEscuela['lista_json'] = $listaLf;
                                }
                            }
                        } elseif ($vistaActiva === 'emails') {
                            if ($codigo === $escuelaActiva) {
                                $queryEscuela['curso'] = $filtrosEmails['curso'];
                                if ($filtrosEmails['buscar'] !== '') {
                                    $queryEscuela['buscar'] = $filtrosEmails['buscar'];
                                }
                            }
                        }
                        $query = http_build_query($queryEscuela);
                        $escuelaTabActiva = $vistaActiva === 'control_usuarios' ? $escuelaDirectivo : $escuelaActiva;
                        $claseTab = ($codigo === $escuelaTabActiva && !$ambitoCuentasComplejo && !($vistaActiva === 'control_usuarios' && $escuelaDirectivo === 'ALL'))
                            ? 'nav-link active'
                            : 'nav-link';
                    ?>
                    <li class="nav-item">
                        <a class="<?php echo $claseTab; ?>" href="?<?php echo estado_alumno_h($query); ?>" title="<?php echo estado_alumno_h($nombre); ?>">
                            <?php echo estado_alumno_h($nombre); ?>
                            <span class="escuela-tab-code"> · <?php echo estado_alumno_h($codigo); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php endif; ?>

        <div class="tab-content admin-tab-content">
            <div class="estado-alumno-admin">
            <?php if ($vistaActiva === 'cuentas'): ?>
                <?php require __DIR__ . '/views/estado_alumno_cuentas.php'; ?>
            <?php elseif ($vistaActiva === 'listado_familias'): ?>
                <?php require __DIR__ . '/views/estado_alumno_listado_familias.php'; ?>
            <?php elseif ($vistaActiva === 'bolsa'): ?>
                <?php require __DIR__ . '/views/estado_alumno_bolsa.php'; ?>
            <?php elseif ($vistaActiva === 'emails'): ?>
                <?php require __DIR__ . '/views/estado_alumno_emails.php'; ?>
            <?php elseif ($vistaActiva === 'revision_contratos'): ?>
                <?php require __DIR__ . '/views/estado_alumno_revision_contratos.php'; ?>
            <?php elseif ($vistaActiva === 'control_usuarios'): ?>
                <?php require __DIR__ . '/views/estado_alumno_control_usuarios.php'; ?>
            <?php else: ?>
            <form method="get" class="filtros curso-form" id="cursoForm">
                <input type="hidden" name="vista" value="contratos">
                <input type="hidden" name="escuela" value="<?php echo estado_alumno_h($escuelaActiva); ?>">

                <div class="campo">
                    <label for="curso" class="form-label">Curso de <?php echo estado_alumno_h($escuelas[$escuelaActiva]); ?></label>
                    <select name="curso" id="curso" class="form-select">
                        <option value="" <?php echo $cursoSeleccionado === '' ? 'selected' : ''; ?>>Todos los cursos</option>
                        <?php foreach ($cursosEscuelaActiva as $curso): ?>
                            <?php $cursoValor = mb_strtoupper(trim((string)$curso), 'UTF-8'); ?>
                            <option value="<?php echo estado_alumno_h($cursoValor); ?>" <?php echo $cursoSeleccionado === $cursoValor ? 'selected' : ''; ?>>
                                <?php echo estado_alumno_h($cursoValor); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo campo-buscar">
                    <label for="buscar" class="form-label">Buscar (apellido, nombre, legajo, DNI)</label>
                    <input
                        type="text"
                        class="form-control"
                        name="buscar"
                        id="buscar"
                        value="<?php echo estado_alumno_h($buscarContratos); ?>"
                        placeholder="Ej: GARCÍA, 10144/01, 45678901"
                    >
                </div>

                <div class="campo">
                    <button type="submit" class="btn btn-primary btn-consultar">
                        <span class="spinner btn-spinner" aria-hidden="true"></span>
                        <span class="btn-consultar-label">Cargar alumnos</span>
                    </button>
                </div>
            </form>

            <?php if ($errorCarga !== ''): ?>
                <div class="alert alert-danger"><?php echo estado_alumno_h($errorCarga); ?></div>
            <?php elseif ($totalAlumnos === 0): ?>
                <div class="no-data">
                    <?php if ($buscarContratos !== ''): ?>
                        No se encontraron alumnos para la búsqueda en <?php echo estado_alumno_h($escuelas[$escuelaActiva]); ?>.
                    <?php elseif ($cursoSeleccionado !== ''): ?>
                        No hay alumnos activos en el curso <?php echo estado_alumno_h($cursoSeleccionado); ?>.
                    <?php else: ?>
                        No hay alumnos activos en <?php echo estado_alumno_h($escuelas[$escuelaActiva]); ?>.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="resumen-wrapper">
                    <div class="resumen">
                        <div class="resumen-text">
                            <?php if ($cursoSeleccionado !== ''): ?>
                                Curso <span class="resumen-highlight"><?php echo estado_alumno_h($cursoSeleccionado); ?></span>.
                            <?php else: ?>
                                Todos los cursos de <span class="resumen-highlight"><?php echo estado_alumno_h($escuelas[$escuelaActiva]); ?></span>.
                            <?php endif; ?>
                            Alumnos activos: <span class="resumen-highlight"><?php echo $totalAlumnos; ?></span>.
                        </div>
                        <div class="resumen-badges">
                            <div class="badge-pill badge-info">Firmados: <?php echo $totalFirmados; ?></div>
                            <div class="badge-pill badge-danger">Sin firma: <?php echo max(0, $totalAlumnos - $totalFirmados); ?></div>
                        </div>
                    </div>
                </div>

                <div class="alumnos-card" data-curso="<?php echo estado_alumno_h($cursoSeleccionado); ?>" data-escuela="<?php echo estado_alumno_h($escuelaActiva); ?>">
                    <div class="alumnos-card-header">
                        <h2 class="alumnos-card-title">Alumnos activos</h2>
                        <div class="alumnos-card-actions">
                            <span class="estado-contrato-pill">Estado de firma y descarga del PDF</span>
                        </div>
                    </div>

                    <div class="table-responsive">
                    <table class="table table-striped table-sm mb-0">
                        <thead>
                            <tr>
                                <th class="col-numero">Nº</th>
                                <th>Apellido y nombre</th>
                                <?php if ($cursoSeleccionado === ''): ?>
                                    <th>Curso</th>
                                <?php endif; ?>
                                <th>Legajo</th>
                                <th>DNI</th>
                                <th>Contrato</th>
                                <th class="col-accion" data-sortable="false">PDF firmado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alumnos as $indiceAlumno => $alumno): ?>
                                <?php
                                    $firmado = !empty($alumno['contrato_id']);
                                    $aprobado = $firmado && ((int)($alumno['admin_aprobado'] ?? 0) === 1);
                                    $rowClass = $aprobado
                                        ? 'row-aprobado'
                                        : ($firmado ? 'row-pendiente-aprobacion' : '');
                                    $estadoClass = $aprobado
                                        ? 'aprobado'
                                        : ($firmado ? 'firmado' : 'pendiente');
                                    $estadoTexto = $aprobado
                                        ? 'Aprobado'
                                        : ($firmado ? 'Firmado' : 'Sin firma');
                                    $pdfDisponible = !empty($alumno['pdf_disponible']);
                                    $pdfUrl = 'descargar_contrato_firmado.php?' . http_build_query([
                                        'dni' => (string)($alumno['dni_alumno'] ?? ''),
                                        'escuela' => $escuelaActiva,
                                    ], '', '&', PHP_QUERY_RFC3986);
                                ?>
                                <tr class="<?php echo estado_alumno_h($rowClass); ?>" data-student-dni="<?php echo estado_alumno_h($alumno['dni_alumno']); ?>">
                                    <td class="col-numero"><?php echo (int)$indiceAlumno + 1; ?></td>
                                    <td><?php echo estado_alumno_h(trim(($alumno['apellido_alumno'] ?? '') . ', ' . ($alumno['nombre_alumno'] ?? ''), ', ')); ?></td>
                                    <?php if ($cursoSeleccionado === ''): ?>
                                        <td><?php echo estado_alumno_h($alumno['curso'] ?? ''); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo estado_alumno_h($alumno['nro_legajo'] ?? ''); ?></td>
                                    <td><?php echo estado_alumno_h($alumno['dni_alumno'] ?? ''); ?></td>
                                    <td><span class="estado-contrato-pill <?php echo estado_alumno_h($estadoClass); ?>"><?php echo estado_alumno_h($estadoTexto); ?></span></td>
                                    <td class="col-accion">
                                        <?php if ($firmado && $pdfDisponible): ?>
                                            <a
                                                class="btn btn-success btn-sm"
                                                href="<?php echo estado_alumno_h($pdfUrl); ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >Descargar</a>
                                        <?php elseif ($firmado): ?>
                                            <span class="chip-sin-archivo" title="El PDF firmado no está disponible en el servidor">No disponible</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="flashContainer" aria-live="polite"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script type="module">
    import { initEstadoAlumnoPage } from '../frontend/js/pages/estadoAlumnoPage.js';
    initEstadoAlumnoPage();
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const csrfToken = <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const loading = document.getElementById('loadingBox');
        const ready = document.getElementById('readyBox');
        if (loading && ready) {
            loading.style.display = 'inline-flex';
            ready.style.display = 'none';
            setTimeout(function () {
                loading.style.display = 'none';
                ready.style.display = 'inline-flex';
            }, 1200);
        }

        const flashContainer = document.getElementById('flashContainer');

        function showFlash(message, ok) {
            if (!flashContainer) {
                return;
            }
            flashContainer.innerHTML = '<div class="flash-msg ' + (ok ? 'ok' : 'error') + '">' + message + '</div>';
            setTimeout(function () {
                flashContainer.innerHTML = '';
            }, 4200);
        }

        function postAdminAprobado(formData) {
            return fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload;
                    try {
                        payload = text ? JSON.parse(text) : {};
                    } catch (error) {
                        throw new Error('Respuesta inválida del servidor. Recargue la página e intente de nuevo.');
                    }
                    return { okHttp: response.ok, payload: payload };
                });
            });
        }

        function applyRowApprovalState(row, checked) {
            if (!row) {
                return;
            }

            // En revisión, las clases de fila reflejan firma/info errónea (no Doc. recibida).
            const isRevisionRow = !!row.querySelector('.js-info-erronea');
            if (!isRevisionRow) {
                row.classList.remove('row-aprobado', 'row-pendiente-aprobacion');
                if (checked) {
                    row.classList.add('row-aprobado');
                } else {
                    row.classList.add('row-pendiente-aprobacion');
                }
            }

            const pill = row.querySelector('.estado-contrato-pill');
            if (pill) {
                pill.classList.remove('firmado', 'aprobado', 'pendiente');
                if (checked) {
                    pill.classList.add('aprobado');
                    pill.textContent = 'Aprobado';
                } else {
                    pill.classList.add('firmado');
                    pill.textContent = 'Firmado';
                }
            }
        }

        function applyRowInfoErroneaState(row, checked) {
            if (!row) {
                return;
            }

            row.classList.remove('row-aprobado', 'row-pendiente-aprobacion', 'row-info-erronea');
            const estadoCell = row.querySelector('td:nth-child(2)');
            if (checked) {
                row.classList.add('row-info-erronea');
                if (estadoCell) {
                    estadoCell.innerHTML = '<span class="info-erronea-label">INFO. ERRONEA</span>';
                }
            } else {
                row.classList.add('row-aprobado');
                if (estadoCell) {
                    estadoCell.innerHTML = '<span class="text-muted">—</span>';
                }
            }
        }

        document.querySelectorAll('.js-info-erronea').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                const studentDni = checkbox.getAttribute('data-student-dni') || '';
                const previousChecked = !checkbox.checked;
                const formData = new FormData();
                formData.append('action', 'toggle_info_erronea');
                formData.append('csrf_token', csrfToken);
                formData.append('student_dni', studentDni);
                formData.append('info_erronea', checkbox.checked ? '1' : '0');

                checkbox.disabled = true;

                postAdminAprobado(formData)
                    .then(function (result) {
                        if (!result.okHttp || !result.payload.ok) {
                            throw new Error((result.payload && result.payload.msg) || 'No se pudo guardar el cambio.');
                        }

                        applyRowInfoErroneaState(checkbox.closest('tr'), checkbox.checked);
                        showFlash(result.payload.msg || 'Cambio guardado.', true);
                    })
                    .catch(function (error) {
                        checkbox.checked = previousChecked;
                        showFlash(error.message || 'Error al guardar.', false);
                    })
                    .finally(function () {
                        checkbox.disabled = false;
                    });
            });
        });

        if (!document.querySelector('.js-admin-aprobado')) {
            return;
        }

        document.querySelectorAll('.js-admin-aprobado').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                const studentDni = checkbox.getAttribute('data-student-dni') || '';
                const previousChecked = !checkbox.checked;
                const formData = new FormData();
                formData.append('action', 'toggle_admin_aprobado');
                formData.append('csrf_token', csrfToken);
                formData.append('student_dni', studentDni);
                formData.append('admin_aprobado', checkbox.checked ? '1' : '0');

                checkbox.disabled = true;

                postAdminAprobado(formData)
                    .then(function (result) {
                        if (!result.okHttp || !result.payload.ok) {
                            throw new Error((result.payload && result.payload.msg) || 'No se pudo guardar el cambio.');
                        }

                        applyRowApprovalState(checkbox.closest('tr'), checkbox.checked);
                        showFlash(result.payload.msg || 'Cambio guardado.', true);
                    })
                    .catch(function (error) {
                        checkbox.checked = previousChecked;
                        showFlash(error.message || 'Error al guardar.', false);
                    })
                    .finally(function () {
                        checkbox.disabled = false;
                    });
            });
        });

        document.querySelectorAll('.js-bulk-admin').forEach(function (button) {
            button.addEventListener('click', function () {
                const card = button.closest('.alumnos-card');
                const curso = card ? (card.getAttribute('data-curso') || '') : '';
                const escuela = card ? (card.getAttribute('data-escuela') || '') : '';
                const marcar = button.getAttribute('data-admin-aprobado') === '1';

                if (!curso && !escuela) {
                    showFlash('No se pudo identificar el curso o la escuela cargados.', false);
                    return;
                }

                const ambitoTxt = curso
                    ? 'este curso'
                    : 'todos los cursos de la escuela';
                const confirmar = marcar
                    ? '¿Marcar la documentación recibida de todos los alumnos en ' + ambitoTxt + '?'
                    : '¿Quitar la marca de documentación recibida de todos los alumnos en ' + ambitoTxt + '?';
                if (!window.confirm(confirmar)) {
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'bulk_admin_aprobado');
                formData.append('csrf_token', csrfToken);
                formData.append('curso', curso);
                formData.append('escuela', escuela);
                formData.append('admin_aprobado', marcar ? '1' : '0');

                button.disabled = true;

                postAdminAprobado(formData)
                    .then(function (result) {
                        if (!result.okHttp || !result.payload.ok) {
                            throw new Error((result.payload && result.payload.msg) || 'No se pudo actualizar el curso.');
                        }

                        document.querySelectorAll('.js-admin-aprobado').forEach(function (checkbox) {
                            checkbox.checked = marcar;
                            applyRowApprovalState(checkbox.closest('tr'), marcar);
                        });

                        showFlash(result.payload.msg || 'Curso actualizado.', true);
                    })
                    .catch(function (error) {
                        showFlash(error.message || 'Error al actualizar el curso.', false);
                    })
                    .finally(function () {
                        button.disabled = false;
                    });
            });
        });
    });
    </script>
</body>
</html>
