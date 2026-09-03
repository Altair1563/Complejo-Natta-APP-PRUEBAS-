<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/guard.php';

admin_guard_json_or_die();

$pdo = admin_get_pdo();

$accion = $_POST['accion'] ?? $_GET['tipo'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['tipo'])) {
    header('Content-Type: application/json; charset=utf-8');
    $tipo = $_GET['tipo'];
    $id = (int)($_GET['id'] ?? 0);

    if ($tipo === 'heartbeat') {
        $tab = $_GET['tab'] ?? '';
        $allowedTabs = [
            'comunicados-generales', 'comunicados-individuales',
            'informes', 'emails', 'talones', 'sugerencias',
            'actualizaciones', 'configuracion',
            'informacion-matriculas', 'informacion-ingresado-facturado', 'informacion-app',
            'listado-familias', 'estado-alumno',
            'alta-alumnos-nuevos', 'lista-alumnos-nuevos', 'auditoria', 'auditoria-app', 'usuarios', 'qr-app',
        ];
        if (!in_array($tab, $allowedTabs, true) || !admin_can($tab)) {
            echo json_encode(['ok' => false, 'error' => 'Tab inválido']);
            exit;
        }

        $queryByTab = [
            'comunicados-generales' => "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(fecha)), 0) AS ts FROM comunicados",
            'comunicados-individuales' => "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(fecha)), 0) AS ts FROM notificaciones WHERE mensaje LIKE '[Individual] %' OR mensaje LIKE '📢 [Individual] %'",
            'informes' => "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(fecha_creacion)), 0) AS ts FROM informes_error",
            'emails' => "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(fecha_solicitud)), 0) AS ts FROM solicitudes_email",
            'talones' => "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(fecha_solicitud)), 0) AS ts FROM solicitudes_talon",
            'sugerencias' => "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(fecha)), 0) AS ts FROM sugerencias",
            'actualizaciones' => "SELECT COUNT(*) AS total, 0 AS ts FROM comunicados",
            'configuracion' => "SELECT COUNT(*) AS total, 0 AS ts FROM configuracion",
            'informacion-matriculas'          => 'SELECT COUNT(*) AS total, 0 AS ts FROM legajos',
            'informacion-ingresado-facturado' => 'SELECT COUNT(*) AS total, 0 AS ts FROM legajos',
            'informacion-app'                 => 'SELECT COUNT(*) AS total, 0 AS ts FROM legajos',
            'listado-familias' => 'SELECT COUNT(DISTINCT nro_familia) AS total, 0 AS ts FROM legajos',
            'alta-alumnos-nuevos' => 'SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(fecha_registro)), 0) AS ts FROM alta_alumnos_nuevos',
            'lista-alumnos-nuevos' => 'SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(fecha_registro)), 0) AS ts FROM alta_alumnos_nuevos',
            'auditoria' => "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(created_at)), 0) AS ts FROM admin_audit_log",
            'auditoria-app' => "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(created_at)), 0) AS ts FROM app_audit_log",
            'usuarios' => "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(updated_at)), 0) AS ts FROM admin_users",
        ];

        try {
            $stmt = $pdo->query($queryByTab[$tab]);
            $row = $stmt->fetch() ?: ['total' => 0, 'ts' => 0];
            echo json_encode([
                'ok' => true,
                'tab' => $tab,
                'total' => (int)($row['total'] ?? 0),
                'ts' => (int)($row['ts'] ?? 0),
            ]);
        } catch (Throwable $e) {
            error_log("Error en admin_ajax (heartbeat): " . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Error en la base de datos']);
        }
        exit;
    }

    if ($tipo === 'comunicado' && $id > 0) {
        admin_require_capability('comunicados');
        $stmt = $pdo->prepare("SELECT titulo, contenido, fecha, archivo_pdf FROM comunicados WHERE id = ?");
        $stmt->execute([$id]);
        $com = $stmt->fetch();
        if ($com) {
            echo json_encode([
                'ok' => true,
                'tipo' => 'comunicado',
                'data' => [
                    'titulo' => (string)($com['titulo'] ?? ''),
                    'contenido' => (string)($com['contenido'] ?? ''),
                    'fecha' => (string)($com['fecha'] ?? ''),
                    'tiene_pdf' => !empty($com['archivo_pdf']),
                ],
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Comunicado no encontrado.']);
        }
        exit;
    }

    if ($tipo === 'informe' && $id > 0) {
        admin_require_capability('informes');
        $stmt = $pdo->prepare("SELECT * FROM informes_error WHERE id = ?");
        $stmt->execute([$id]);
        $inf = $stmt->fetch();
        if ($inf) {
            echo json_encode([
                'ok' => true,
                'tipo' => 'informe',
                'data' => [
                    'nro_familia' => (string)($inf['nro_familia'] ?? ''),
                    'fecha_creacion' => (string)($inf['fecha_creacion'] ?? ''),
                    'estado' => (string)($inf['estado'] ?? ''),
                    'error_descripcion' => (string)($inf['error_descripcion'] ?? ''),
                ],
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Informe no encontrado.']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Solicitud inválida']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf_post();

    $accion = $_POST['accion'] ?? '';
    $tipo = $_POST['tipo'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $estado = $_POST['estado'] ?? '';

    $response = ['ok' => false, 'error' => 'Acción no válida'];
    $allowedEstados = [
        'informe' => ['pendiente', 'en_proceso', 'resuelto'],
        'email' => ['pendiente', 'aprobado', 'rechazado'],
        'talon' => ['pendiente', 'generado', 'entregado'],
    ];

    $tipoCap = [
        'informe' => 'informes',
        'email' => 'emails',
        'talon' => 'talones',
        'sugerencia' => 'sugerencias',
        'comunicado' => 'comunicados',
        'notificacion' => 'comunicados',
    ];

    if ($accion === 'cambiar_estado') {
        $cap = $tipoCap[$tipo] ?? '';
        if ($cap === '' || !admin_can($cap)) {
            $response = ['ok' => false, 'error' => 'Sin permiso'];
        } else {
            try {
                if ($tipo === 'informe') {
                    if (!in_array($estado, $allowedEstados['informe'], true)) {
                        $response = ['ok' => false, 'error' => 'Estado inválido'];
                    } else {
                        $stmt = $pdo->prepare("UPDATE informes_error SET estado = ? WHERE id = ?");
                        $stmt->execute([$estado, $id]);
                        $response = ['ok' => true];
                    }
                } elseif ($tipo === 'email') {
                    if (!in_array($estado, $allowedEstados['email'], true)) {
                        $response = ['ok' => false, 'error' => 'Estado inválido'];
                    } else {
                        $stmt = $pdo->prepare("UPDATE solicitudes_email SET estado = ? WHERE id = ?");
                        $stmt->execute([$estado, $id]);
                        $response = ['ok' => true];
                    }
                } elseif ($tipo === 'talon') {
                    if (!in_array($estado, $allowedEstados['talon'], true)) {
                        $response = ['ok' => false, 'error' => 'Estado inválido'];
                    } else {
                        $stmt = $pdo->prepare("UPDATE solicitudes_talon SET estado = ? WHERE id = ?");
                        $stmt->execute([$estado, $id]);
                        $response = ['ok' => true];
                    }
                } elseif ($tipo === 'sugerencia') {
                    $stmt = $pdo->prepare("UPDATE sugerencias SET leido = 1 WHERE id = ?");
                    $stmt->execute([$id]);
                    $response = ['ok' => true];
                    $estado = 'leido';
                }
                if ($response['ok']) {
                    admin_audit_log($pdo, 'cambiar_estado', $tipo, $id, ['estado' => $estado]);
                }
            } catch (Throwable $e) {
                error_log("Error en admin_ajax (cambiar_estado): " . $e->getMessage());
                $response = ['ok' => false, 'error' => 'Error en la base de datos'];
            }
        }
    } elseif ($accion === 'eliminar') {
        $cap = $tipoCap[$tipo] ?? '';
        if ($cap === '' || !admin_can($cap)) {
            $response = ['ok' => false, 'error' => 'Sin permiso'];
        } else {
            try {
                if ($tipo === 'comunicado') {
                    require_once NATTA_ROOT . '/includes/comunicados_pdf.php';
                    comunicado_delete_pdf($id);
                    $stmt = $pdo->prepare("DELETE FROM comunicados WHERE id = ?");
                    $stmt->execute([$id]);
                    $response = ['ok' => true];
                } elseif ($tipo === 'informe') {
                    $stmt = $pdo->prepare("DELETE FROM informes_error WHERE id = ?");
                    $stmt->execute([$id]);
                    $response = ['ok' => true];
                } elseif ($tipo === 'email') {
                    $stmt = $pdo->prepare("DELETE FROM solicitudes_email WHERE id = ?");
                    $stmt->execute([$id]);
                    $response = ['ok' => true];
                } elseif ($tipo === 'talon') {
                    $stmt = $pdo->prepare("DELETE FROM solicitudes_talon WHERE id = ?");
                    $stmt->execute([$id]);
                    $response = ['ok' => true];
                } elseif ($tipo === 'sugerencia') {
                    $stmt = $pdo->prepare("DELETE FROM sugerencias WHERE id = ?");
                    $stmt->execute([$id]);
                    $response = ['ok' => true];
                } elseif ($tipo === 'notificacion') {
                    $stmt = $pdo->prepare("DELETE FROM notificaciones WHERE id = ?");
                    $stmt->execute([$id]);
                    $response = ['ok' => true];
                }
                if ($response['ok']) {
                    admin_audit_log($pdo, 'eliminar', $tipo, $id, []);
                }
            } catch (Throwable $e) {
                error_log("Error en admin_ajax (eliminar): " . $e->getMessage());
                $response = ['ok' => false, 'error' => 'Error en la base de datos'];
            }
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}
