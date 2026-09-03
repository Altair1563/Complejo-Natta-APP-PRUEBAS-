<?php
/**
 * Panel Dirección · Control de Usuarios.
 * Supervisa la actividad de secretarías (estados de cuenta y revisión de contratos).
 */

define('NATTA_ROOT', dirname(__DIR__));

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/control_usuarios_lib.php';
require_once __DIR__ . '/includes/escuelas_catalog.php';

$controlUsuariosSelf = 'control_usuarios.php';
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
            && admin_can_access_control_usuarios_from_user($user)
        ) {
            admin_set_session_user($user);
            unset($_SESSION[$failKey . '_count'], $_SESSION[$failKey . '_locked']);
            secure_session_regenerate();
            admin_touch_last_login($pdo, (int)$user['id']);
            admin_audit_log($pdo, 'login_ok', 'admin_user', (int)$user['id'], [
                'username' => $user['username'],
                'destino' => 'control_usuarios',
            ]);
            $destino = ((string)$user['rol'] === ADMIN_ROLE_DIRECTIVO)
                ? 'estado_alumno.php?vista=control_usuarios'
                : $controlUsuariosSelf;
            header('Location: ' . $destino);
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
            'destino' => 'control_usuarios',
        ]);
        $login_error = 'Usuario o contraseña incorrectos.';
    }
}

if (!admin_is_logged_in() || !admin_can_access_control_usuarios()) {
    require __DIR__ . '/views/directivo_login.php';
    exit;
}

if (admin_is_directivo()) {
    header('Location: estado_alumno.php?vista=control_usuarios');
    exit;
}

$usuarioActual = admin_current_user();
$escuelas = admin_escuelas_catalog();
$escuelaDirectivo = admin_is_directivo()
    ? admin_escuela_codigo()
    : strtoupper(trim((string)($_GET['escuela'] ?? 'ALL')));

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

$avance = [
    'total' => 0,
    'firmados' => 0,
    'doc_recibida' => 0,
    'info_erronea' => 0,
    'pendientes_doc' => 0,
    'error' => '',
];

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    require_once __DIR__ . '/includes/db_collate.php';
    admin_mysqli_apply_collation($conn);
    if ($escuelaDirectivo !== '' && $escuelaDirectivo !== 'ALL') {
        $avance = control_usuarios_avance_contratos($conn, $escuelaDirectivo);
    }
    $conn->close();
} catch (Throwable $e) {
    error_log('control_usuarios conexion: ' . $e->getMessage());
    $avance['error'] = 'No se pudo cargar el avance de contratos.';
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dirección · Control de Usuarios</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/pages/admin.css">
    <link rel="stylesheet" href="../css/pages/estados-secretaria-documentacion.css">
</head>
<body data-page="control-usuarios">

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
                        Dirección · Control de Usuarios
                    </h1>
                    <p class="text-muted mb-0 small mt-1">
                        👤 <?php echo control_usuarios_h($usuarioActual['nombre']); ?>
                        (<?php echo control_usuarios_h($usuarioActual['username']); ?>)
                        <?php if (admin_is_directivo()): ?>
                            · <span class="badge bg-primary"><?php echo control_usuarios_h($nombreEscuela); ?></span>
                        <?php else: ?>
                            · <span class="badge bg-dark"><?php echo control_usuarios_h($usuarioActual['rol']); ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="text-muted mb-0 small mt-1">
                        Seguimiento del trabajo de secretaría: consultas de estados de cuenta, revisión de contratos
                        y casillas de documentación recibida / info errónea.
                    </p>
                    <div class="update-pill">
                        <span class="update-dot"></span>
                        <span>
                            <?php if ($escuelaDirectivo !== 'ALL'): ?>
                                Doc. recibida: <?php echo (int)$avance['doc_recibida']; ?>/<?php echo (int)$avance['total']; ?>
                                · Firmados: <?php echo (int)$avance['firmados']; ?>
                                <?php if ((int)$avance['info_erronea'] > 0): ?>
                                    · Info. errónea: <?php echo (int)$avance['info_erronea']; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                Secretarías activas: <?php echo count($secretarias); ?>
                                · Actividad reciente: <?php echo count($actividad); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
            <a href="admin_logout.php" class="btn btn-danger admin-header__logout">🚪 Cerrar sesión</a>
        </div>

        <?php if (!admin_is_directivo()): ?>
        <nav class="admin-nav-sub mt-2 admin-nav-escuelas" aria-label="Escuelas">
            <ul class="nav nav-pills admin-nav-subtabs">
                <li class="nav-item">
                    <a class="nav-link<?php echo $escuelaDirectivo === 'ALL' ? ' active' : ''; ?>"
                       href="?escuela=ALL">Todo el complejo</a>
                </li>
                <?php foreach ($escuelas as $codigo => $nombre): ?>
                    <li class="nav-item">
                        <a class="nav-link<?php echo $escuelaDirectivo === $codigo ? ' active' : ''; ?>"
                           href="?escuela=<?php echo urlencode($codigo); ?>"
                           title="<?php echo control_usuarios_h($nombre); ?>">
                            <span class="escuela-tab-code"><?php echo control_usuarios_h($codigo); ?></span>
                            <?php echo control_usuarios_h($nombre); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php endif; ?>

        <div class="estado-alumno-admin mt-3">
            <?php if ($escuelaDirectivo !== 'ALL'): ?>
                <?php if ($avance['error'] !== ''): ?>
                    <div class="alert alert-warning"><?php echo control_usuarios_h($avance['error']); ?></div>
                <?php else: ?>
                    <div class="resumen-wrapper">
                        <div class="resumen">
                            <div class="resumen-text">
                                Avance de revisión en
                                <span class="resumen-highlight"><?php echo control_usuarios_h($nombreEscuela); ?></span>.
                                Alumnos activos:
                                <span class="resumen-highlight"><?php echo (int)$avance['total']; ?></span>.
                                <span class="resumen-meta">
                                    Cumplimiento doc. recibida: <?php echo $pctDoc; ?>%
                                    · Contratos firmados: <?php echo $pctFirmados; ?>%
                                </span>
                            </div>
                            <div class="resumen-badges">
                                <div class="badge-pill badge-info">Firmados: <?php echo (int)$avance['firmados']; ?></div>
                                <div class="badge-pill badge-success">Doc. recibida: <?php echo (int)$avance['doc_recibida']; ?></div>
                                <div class="badge-pill badge-danger">Sin doc.: <?php echo (int)$avance['pendientes_doc']; ?></div>
                                <?php if ((int)$avance['info_erronea'] > 0): ?>
                                    <div class="badge-pill badge-danger">Info. errónea: <?php echo (int)$avance['info_erronea']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="alumnos-card mb-3">
                <div class="alumnos-card-header">
                    <h2 class="alumnos-card-title">Secretarías · últimos 14 días</h2>
                </div>
                <?php if ($secretarias === []): ?>
                    <div class="no-data">
                        No hay usuarios de secretaría activos<?php
                            echo $escuelaDirectivo !== 'ALL' ? ' para esta escuela.' : '.';
                        ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <?php if ($escuelaDirectivo === 'ALL'): ?>
                                        <th>Escuela</th>
                                    <?php endif; ?>
                                    <th>Estados de cuenta</th>
                                    <th>Revisión contratos</th>
                                    <th>Doc. recibida</th>
                                    <th>Info. errónea</th>
                                    <th>Último acceso</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($secretarias as $sec): ?>
                                    <?php
                                    $sid = (int)$sec['id'];
                                    $res = $resumenPorUser[$sid] ?? [
                                        'consultas_cuenta' => 0,
                                        'revisiones' => 0,
                                        'doc_recibida' => 0,
                                        'info_erronea' => 0,
                                    ];
                                    $escSec = (string)($sec['escuela_codigo'] ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo control_usuarios_h((string)$sec['nombre']); ?></strong>
                                            <div class="small text-muted"><?php echo control_usuarios_h((string)$sec['username']); ?></div>
                                        </td>
                                        <?php if ($escuelaDirectivo === 'ALL'): ?>
                                            <td><?php echo control_usuarios_h($escSec !== '' ? $escSec : '—'); ?></td>
                                        <?php endif; ?>
                                        <td>
                                            <span class="estado-contrato-pill <?php echo (int)$res['consultas_cuenta'] > 0 ? 'aprobado' : 'pendiente'; ?>">
                                                <?php echo (int)$res['consultas_cuenta']; ?> consultas
                                            </span>
                                        </td>
                                        <td>
                                            <span class="estado-contrato-pill <?php echo (int)$res['revisiones'] > 0 ? 'firmado' : 'pendiente'; ?>">
                                                <?php echo (int)$res['revisiones']; ?> revisiones
                                            </span>
                                        </td>
                                        <td>
                                            <span class="estado-contrato-pill <?php echo (int)$res['doc_recibida'] > 0 ? 'aprobado' : ''; ?>">
                                                <?php echo (int)$res['doc_recibida']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="estado-contrato-pill <?php echo (int)$res['info_erronea'] > 0 ? 'pendiente' : ''; ?>">
                                                <?php echo (int)$res['info_erronea']; ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted"><?php echo control_usuarios_h((string)($sec['ultimo_login'] ?? '—')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted px-3 py-2 mb-0">
                        Contadores de actividad registrada en el panel de secretaría durante los últimos 14 días.
                    </p>
                <?php endif; ?>
            </div>

            <div class="row g-3">
                <div class="col-lg-5">
                    <div class="alumnos-card h-100">
                        <div class="alumnos-card-header">
                            <h2 class="alumnos-card-title">Cursos con actividad reciente</h2>
                        </div>
                        <?php if ($cursosTrabajados === []): ?>
                            <div class="no-data">Todavía no hay cursos registrados en la actividad reciente.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Curso</th>
                                            <th>Acciones</th>
                                            <th>Secretaría</th>
                                            <th>Última</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cursosTrabajados as $cursoItem): ?>
                                            <tr>
                                                <td><strong><?php echo control_usuarios_h($cursoItem['curso']); ?></strong></td>
                                                <td>
                                                    <span class="badge-pill badge-info"><?php echo (int)$cursoItem['veces']; ?></span>
                                                </td>
                                                <td class="small"><?php echo control_usuarios_h(implode(', ', $cursoItem['usuarios'])); ?></td>
                                                <td class="small text-muted"><?php echo control_usuarios_h($cursoItem['ultima']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="alumnos-card h-100">
                        <div class="alumnos-card-header">
                            <h2 class="alumnos-card-title">Actividad reciente</h2>
                        </div>
                        <?php if ($actividad === []): ?>
                            <div class="no-data">
                                Sin actividad registrada aún. Las acciones de secretaría aparecerán acá a medida que trabajen en el panel.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive control-usuarios-actividad">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Usuario</th>
                                            <th>Acción</th>
                                            <th>Detalle</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($actividad as $item): ?>
                                            <?php
                                            $accion = (string)$item['accion'];
                                            $detalle = is_array($item['detalle'] ?? null) ? $item['detalle'] : [];
                                            $pillClass = '';
                                            if ($accion === 'doc_recibida' || $accion === 'bulk_doc_recibida') {
                                                $pillClass = !empty($detalle['valor']) ? 'aprobado' : '';
                                            } elseif ($accion === 'info_erronea') {
                                                $pillClass = !empty($detalle['valor']) ? 'pendiente' : '';
                                            } elseif ($accion === 'consulta_estado_cuenta' || $accion === 'revision_contratos') {
                                                $pillClass = 'firmado';
                                            }
                                            $texto = control_usuarios_texto_detalle($accion, $detalle);
                                            ?>
                                            <tr>
                                                <td class="small text-muted text-nowrap"><?php echo control_usuarios_h((string)$item['created_at']); ?></td>
                                                <td><?php echo control_usuarios_h((string)$item['usuario_nombre']); ?></td>
                                                <td>
                                                    <span class="estado-contrato-pill <?php echo $pillClass; ?>">
                                                        <?php echo control_usuarios_h(control_usuarios_label_accion($accion)); ?>
                                                    </span>
                                                </td>
                                                <td class="small"><?php echo $texto !== '' ? control_usuarios_h($texto) : '—'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
