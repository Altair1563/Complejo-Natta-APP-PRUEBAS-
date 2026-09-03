<?php
$navResolved = admin_nav_resolve($_GET);
$active_grupo = $navResolved['grupo'];
$active_tab = $navResolved['tab'];
$admin_nav_groups = admin_nav_visible_groups();
$admin_nav_badges = admin_nav_notification_counts($pdo);

require __DIR__ . '/post_handlers_users.php';
require __DIR__ . '/post_handlers_alta_alumnos.php';
$mensaje = ''; // Para acciones que recargan (crear comunicado)
$msg_from_url = '';
$msg_type_from_url = 'success';
if (isset($_SESSION['admin_flash_msg'])) {
    $msg_from_url = (string)$_SESSION['admin_flash_msg'];
    unset($_SESSION['admin_flash_msg']);
} elseif (isset($_GET['msg'])) {
    // Compatibilidad con enlaces viejos.
    $msg_from_url = (string)$_GET['msg'];
}
if (isset($_SESSION['admin_flash_type'])) {
    $msg_type_from_url = (string)$_SESSION['admin_flash_type'];
    unset($_SESSION['admin_flash_type']);
} elseif (isset($_GET['msg_type'])) {
    // Compatibilidad con enlaces viejos.
    $msg_type_from_url = (string)$_GET['msg_type'];
}
if (!in_array($msg_type_from_url, ['success', 'danger', 'warning', 'info', 'primary', 'secondary', 'dark'], true)) {
    $msg_type_from_url = 'info';
}

// Si la carga supera post_max_size, PHP deja vacíos $_POST y $_FILES.
// Sin este control la UI "no hace nada" al enviar archivos grandes.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST)
    && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    $maxPost = (string)ini_get('post_max_size');
    $warn = 'La carga no se procesó porque supera el límite del servidor (post_max_size';
    if ($maxPost !== '') {
        $warn .= ': ' . $maxPost;
    }
    $warn .= '). Suba menos archivos por vez o reduzca su tamaño.';
    admin_redirect_tab($active_tab !== '' ? $active_tab : 'actualizaciones', $warn, 'warning');
}

// Procesar creación de comunicado (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'comunicado_crear') {
    admin_require_capability('comunicados');
    admin_verify_csrf_post();
    require_once NATTA_ROOT . '/includes/comunicados_pdf.php';

    $titulo = normalizePlainText($_POST['titulo'] ?? '', 255);
    $contenido = normalizePlainText($_POST['contenido'] ?? '', 0, true);
    if ($titulo !== '' && $contenido !== '') {
        $newId = 0;
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO comunicados (titulo, contenido, fecha) VALUES (?, ?, NOW())");
            $stmt->execute([$titulo, $contenido]);
            $newId = (int)$pdo->lastInsertId();

            $pdfFile = $_FILES['pdf_adjunto'] ?? null;
            if (is_array($pdfFile) && ($pdfFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $pdfResult = comunicado_save_pdf_upload($pdfFile, $newId);
                if (!$pdfResult['ok']) {
                    $pdo->rollBack();
                    comunicado_delete_pdf($newId);
                    admin_redirect_tab('comunicados-generales', $pdfResult['msg'], 'danger');
                }
                if ($pdfResult['saved']) {
                    $stmtPdf = $pdo->prepare('UPDATE comunicados SET archivo_pdf = ? WHERE id = ?');
                    $stmtPdf->execute([$pdfResult['path'], $newId]);
                }
            }

            $mensajeNotif = "📢 Nuevo comunicado: " . (strlen($titulo) > 40 ? substr($titulo, 0, 40) . "..." : $titulo) . " (Ver en Información Importante)";
            $stmtNotif = $pdo->prepare("INSERT INTO notificaciones (nro_familia, mensaje) SELECT DISTINCT nro_familia, ? FROM legajos WHERE nro_familia IS NOT NULL");
            $stmtNotif->execute([$mensajeNotif]);

            $pdo->commit();

            admin_audit_log($pdo, 'comunicado_crear', 'comunicado', $newId, ['titulo' => $titulo]);
            admin_redirect_tab('comunicados-generales', 'Comunicado publicado.', 'success');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!empty($newId)) {
                comunicado_delete_pdf((int)$newId);
            }
            error_log("Error al crear comunicado: " . $e->getMessage());
            admin_redirect_tab('comunicados-generales', 'Error al publicar el comunicado. Intente nuevamente.', 'danger');
        }
    }
    admin_redirect_tab('comunicados-generales', 'Título y contenido obligatorios.', 'warning');
}

// Procesar creación de comunicado individual por familia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'comunicado_individual_crear') {
    admin_require_capability('comunicados');
    admin_verify_csrf_post();

    $nroFamilia = filter_var($_POST['nro_familia'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $tituloIndividual = normalizePlainText($_POST['titulo_individual'] ?? '', 120);
    $contenidoIndividual = normalizePlainText($_POST['contenido_individual'] ?? '', 0, true);

    if ($nroFamilia === false || $nroFamilia === null) {
        $mensaje = 'Ingrese un número de familia válido.';
        $msgType = 'warning';
    } elseif ($tituloIndividual === '' || $contenidoIndividual === '') {
        $mensaje = 'Número de familia, título y comunicado son obligatorios.';
        $msgType = 'warning';
    } else {
        try {
            $stmtFamilia = $pdo->prepare("SELECT 1 FROM legajos WHERE nro_familia = ? LIMIT 1");
            $stmtFamilia->execute([$nroFamilia]);
            $familiaExiste = (bool)$stmtFamilia->fetchColumn();

            if (!$familiaExiste) {
                $mensaje = 'No existe una familia registrada con ese número.';
                $msgType = 'warning';
            } else {
                $mensajeNotif = "[Individual] " . $tituloIndividual . ": " . $contenidoIndividual;
                if (function_exists('mb_substr')) {
                    $mensajeNotif = mb_substr($mensajeNotif, 0, 255);
                } else {
                    $mensajeNotif = substr($mensajeNotif, 0, 255);
                }

                $stmtNotif = $pdo->prepare("INSERT INTO notificaciones (nro_familia, mensaje, fecha, leido) VALUES (?, ?, NOW(), 0)");
                $stmtNotif->execute([(int)$nroFamilia, $mensajeNotif]);
                $notifId = (int)$pdo->lastInsertId();

                admin_audit_log($pdo, 'comunicado_individual_crear', 'notificacion', $notifId, [
                    'nro_familia' => (int)$nroFamilia,
                    'titulo' => $tituloIndividual,
                ]);
                $mensaje = 'Comunicado individual enviado correctamente.';
                $msgType = 'success';
            }
        } catch (Throwable $e) {
            error_log("Error al crear comunicado individual: " . $e->getMessage());
            $mensaje = 'Error al enviar el comunicado individual. Intente nuevamente.';
            $msgType = 'danger';
        }
    }

    admin_redirect_tab('comunicados-individuales', $mensaje, $msgType);
}

// Procesar actualización de cuota vigente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_cuota_vigente') {
    admin_require_capability('configuracion');
    admin_verify_csrf_post();
    $nueva_cuota = (int)($_POST['cuota_vigente'] ?? 0);
    if ($nueva_cuota >= 1 && $nueva_cuota <= 12) {
        try {
            $anterior = (int)getConfigPDO($pdo, 'cuota_vigente', 1);
            setConfigPDO($pdo, 'cuota_vigente', (string)$nueva_cuota, 'Cuota vigente actual (1=marzo, 2=abril, ...)');
            admin_audit_log($pdo, 'cuota_vigente_actualizar', 'configuracion', null, [
                'anterior' => $anterior,
                'nueva' => $nueva_cuota,
            ]);
            admin_redirect_tab(
                'configuracion',
                'Cuota vigente actualizada a ' . $nueva_cuota . ' (' . nombreMesCuota($nueva_cuota) . ').',
                'success'
            );
        } catch (Throwable $e) {
            error_log("Error al actualizar cuota vigente: " . $e->getMessage());
            admin_redirect_tab('configuracion', 'Error al actualizar la cuota vigente.', 'danger');
        }
    }
    admin_redirect_tab('configuracion', 'Valor de cuota inválido (debe ser 1-12).', 'warning');
}

// Activar / desactivar modo mantenimiento del login público
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['accion'])
    && in_array($_POST['accion'], ['activar_modo_mantenimiento', 'desactivar_modo_mantenimiento'], true)
) {
    admin_require_capability('configuracion');
    admin_verify_csrf_post();
    $activar = $_POST['accion'] === 'activar_modo_mantenimiento';
    $activo = getConfigPDO($pdo, 'modo_mantenimiento', '0') === '1';

    if ($activar && $activo) {
        admin_redirect_tab('configuracion', 'El modo mantenimiento ya está activado.', 'info');
    }
    if (!$activar && !$activo) {
        admin_redirect_tab('configuracion', 'El modo mantenimiento ya está desactivado.', 'info');
    }

    try {
        $nuevo = $activar ? '1' : '0';
        setConfigPDO(
            $pdo,
            'modo_mantenimiento',
            $nuevo,
            'Pantalla de mantenimiento en index.php (1=activo, 0=inactivo)'
        );
        if ($nuevo === '1') {
            setConfigPDO(
                $pdo,
                'modo_mantenimiento_epoch',
                (string)time(),
                'Invalida sesiones de familias al activar mantenimiento'
            );
        }
        admin_audit_log($pdo, 'modo_mantenimiento_toggle', 'configuracion', null, [
            'anterior' => $activo ? '1' : '0',
            'nuevo' => $nuevo,
        ]);
        $msg = $nuevo === '1'
            ? 'Modo mantenimiento activado. Las sesiones abiertas se cerrarán automáticamente.'
            : 'Modo mantenimiento desactivado.';
        admin_redirect_tab('configuracion', $msg, 'success');
    } catch (Throwable $e) {
        error_log('Error al cambiar modo mantenimiento: ' . $e->getMessage());
        admin_redirect_tab('configuracion', 'Error al cambiar el modo mantenimiento.', 'danger');
    }
}

// Subir archivos para importaciones (rutas fijas; no se usa el nombre original del cliente)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'subir_archivos_importacion') {
    try {
        admin_require_capability('actualizaciones');
        admin_verify_csrf_post();
        $notes = [];
        $anyFail = false;
        $importsDir = NATTA_ROOT . '/config/imports';
        if (!is_dir($importsDir) && !@mkdir($importsDir, 0755, true) && !is_dir($importsDir)) {
            admin_redirect_tab('actualizaciones', 'No se pudo crear la carpeta config/imports/.', 'danger');
            exit;
        }
        $baseImports = realpath($importsDir);
        if ($baseImports === false) {
            admin_redirect_tab('actualizaciones', 'No se encontró la carpeta config/imports/.', 'danger');
            exit;
        }
        $baseImports .= DIRECTORY_SEPARATOR;

        if (!empty($_FILES['archivos_rptfacing']['name'])) {
            $names = $_FILES['archivos_rptfacing']['name'];
            if (!is_array($names)) {
                $names = [$names];
                $_FILES['archivos_rptfacing'] = [
                    'name'     => [$_FILES['archivos_rptfacing']['name']],
                    'type'     => [$_FILES['archivos_rptfacing']['type']],
                    'tmp_name' => [$_FILES['archivos_rptfacing']['tmp_name']],
                    'error'    => [$_FILES['archivos_rptfacing']['error']],
                    'size'     => [$_FILES['archivos_rptfacing']['size']],
                ];
            }
            $seenSlot = [];
            $n = count($_FILES['archivos_rptfacing']['name']);
            for ($i = 0; $i < $n; $i++) {
                $err = (int)($_FILES['archivos_rptfacing']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $origName = (string)($_FILES['archivos_rptfacing']['name'][$i] ?? '');
                $slot = adminResolveRptFacingBase($origName);
                if ($slot === null) {
                    $anyFail = true;
                    $notes[] = 'Nombre no reconocido: ' . basename($origName) . ' (use rptfacing.xls/xlsx … rptfacing5.xls/xlsx).';
                    continue;
                }
                if (isset($seenSlot[$slot])) {
                    $anyFail = true;
                    $notes[] = $slot . ': hay más de un archivo para el mismo destino en este envío.';
                    continue;
                }
                $seenSlot[$slot] = true;

                $slice = [
                    'name'     => $origName,
                    'type'     => $_FILES['archivos_rptfacing']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['archivos_rptfacing']['tmp_name'][$i] ?? '',
                    'error'    => $err,
                    'size'     => (int)($_FILES['archivos_rptfacing']['size'][$i] ?? 0),
                ];
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if ($ext === 'xlsx') {
                    @unlink($baseImports . $slot . '.xls');
                    $r = adminImportSaveUploaded($slice, $baseImports . $slot . '.xlsx', ['xlsx']);
                } elseif ($ext === 'xls') {
                    @unlink($baseImports . $slot . '.xlsx');
                    $r = adminImportSaveUploaded($slice, $baseImports . $slot . '.xls', ['xls']);
                } else {
                    $r = ['ok' => false, 'saved' => false, 'msg' => 'Use .xls o .xlsx.'];
                }
                if (!$r['ok']) {
                    $anyFail = true;
                    $notes[] = $slot . ': ' . $r['msg'];
                } elseif ($r['saved']) {
                    $notes[] = $slot . ' guardado (' . $r['msg'] . ').';
                }
            }
        }

        $r = adminImportSaveUpload('archivo_email_padres', $baseImports . 'email-padres.csv', ['csv']);
        if (!$r['ok']) {
            $anyFail = true;
            $notes[] = 'email-padres.csv: ' . $r['msg'];
        } elseif ($r['saved']) {
            $notes[] = 'email-padres.csv guardado.';
        }

        $r = adminImportSaveUpload('archivo_legajos', $baseImports . 'legajos.csv', ['csv']);
        if (!$r['ok']) {
            $anyFail = true;
            $notes[] = 'legajos.csv: ' . $r['msg'];
        } elseif ($r['saved']) {
            $notes[] = 'legajos.csv guardado.';
        }

        $r = adminImportSaveUpload('archivo_legajos_inactivos', $baseImports . 'legajos-inactivos.csv', ['csv']);
        if (!$r['ok']) {
            $anyFail = true;
            $notes[] = 'legajos-inactivos.csv: ' . $r['msg'];
        } elseif ($r['saved']) {
            $notes[] = 'legajos-inactivos.csv guardado.';
        }

        if (!$anyFail && empty($notes)) {
            $notes[] = 'No se seleccionó ningún archivo.';
        }
        $msg = implode(' ', $notes);
        admin_audit_log($pdo, 'archivos_importacion_subir', 'actualizaciones', null, [
            'notas' => $notes,
            'fallo' => $anyFail,
        ]);
        admin_redirect_tab('actualizaciones', $msg, $anyFail ? 'warning' : 'success');
    } catch (Throwable $e) {
        error_log('subir_archivos_importacion: ' . $e->getMessage());
        admin_redirect_tab('actualizaciones', 'Error interno al guardar archivos. Revise permisos de carpeta y límites del servidor.', 'danger');
    }
}

// Procesar acciones masivas (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_action'])) {
    admin_verify_csrf_post();

    $tabla = $_POST['tabla'] ?? '';
    $capMap = [
        'informes' => 'informes',
        'emails' => 'emails',
        'talones' => 'talones',
        'sugerencias' => 'sugerencias',
    ];
    if (!isset($capMap[$tabla])) {
        admin_redirect_tab('talones', 'Tabla no válida.', 'danger');
    }
    admin_require_capability($capMap[$tabla]);
    $accion_masiva = $_POST['batch_action'];
    $ids = $_POST['ids'] ?? [];
    
    if (!empty($ids) && is_array($ids)) {
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        try {
            $pdo->beginTransaction();
            $msg = '';
            
            if ($tabla === 'informes') {
                if ($accion_masiva === 'eliminar') {
                    $stmt = $pdo->prepare("DELETE FROM informes_error WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $msg = "Registros eliminados correctamente.";
                } elseif ($accion_masiva === 'estado') {
                    $nuevo_estado = $_POST['nuevo_estado'] ?? '';
                    if (in_array($nuevo_estado, ['pendiente', 'en_proceso', 'resuelto'])) {
                        $stmt = $pdo->prepare("UPDATE informes_error SET estado = ? WHERE id IN ($placeholders)");
                        $stmt->execute(array_merge([$nuevo_estado], $ids));
                        $msg = "Estado actualizado correctamente.";
                    }
                }
            } elseif ($tabla === 'emails') {
                if ($accion_masiva === 'eliminar') {
                    $stmt = $pdo->prepare("DELETE FROM solicitudes_email WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $msg = "Solicitudes eliminadas correctamente.";
                } elseif ($accion_masiva === 'estado') {
                    $nuevo_estado = $_POST['nuevo_estado'] ?? '';
                    if (in_array($nuevo_estado, ['pendiente', 'aprobado', 'rechazado'])) {
                        $stmt = $pdo->prepare("UPDATE solicitudes_email SET estado = ? WHERE id IN ($placeholders)");
                        $stmt->execute(array_merge([$nuevo_estado], $ids));
                        $msg = "Estado actualizado correctamente.";
                    }
                }
            } elseif ($tabla === 'talones') {
                if ($accion_masiva === 'eliminar') {
                    $stmt = $pdo->prepare("DELETE FROM solicitudes_talon WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $msg = "Solicitudes eliminadas correctamente.";
                } elseif ($accion_masiva === 'estado') {
                    $nuevo_estado = $_POST['nuevo_estado'] ?? '';
                    if (in_array($nuevo_estado, ['pendiente', 'generado', 'entregado'])) {
                        $stmt = $pdo->prepare("UPDATE solicitudes_talon SET estado = ? WHERE id IN ($placeholders)");
                        $stmt->execute(array_merge([$nuevo_estado], $ids));
                        $msg = "Estado actualizado correctamente.";
                    }
                }
            } elseif ($tabla === 'sugerencias') {
                if ($accion_masiva === 'eliminar') {
                    $stmt = $pdo->prepare("DELETE FROM sugerencias WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $msg = "Sugerencias eliminadas correctamente.";
                } elseif ($accion_masiva === 'leido') {
                    $stmt = $pdo->prepare("UPDATE sugerencias SET leido = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $msg = "Sugerencias marcadas como leídas.";
                }
            }
            
            $pdo->commit();
            admin_audit_log($pdo, 'batch_' . $accion_masiva, $tabla, null, [
                'ids' => $ids,
                'nuevo_estado' => $_POST['nuevo_estado'] ?? null,
            ]);
            $tipo_msg = 'success';
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log("Error en acción masiva: " . $e->getMessage());
            $msg = 'Error al procesar la acción.';
            $tipo_msg = 'danger';
        }
    } else {
        $msg = 'No se seleccionaron registros.';
        $tipo_msg = 'warning';
    }
    admin_redirect_tab($tabla, $msg, $tipo_msg);
}

// Eliminar contrato marcado como info errónea (solo panel admin / Revisión Contratos).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_contrato_erroneo') {
    admin_require_capability('revision-contratos');
    admin_verify_csrf_post();

    $contratoId = (int)($_POST['contrato_id'] ?? 0);
    $redirectExtra = array_filter([
        'solo_erroneos' => isset($_POST['solo_erroneos']) && (string)$_POST['solo_erroneos'] === '1' ? '1' : null,
        'curso' => trim((string)($_POST['curso'] ?? '')) !== '' ? trim((string)$_POST['curso']) : null,
        'buscar' => trim((string)($_POST['buscar'] ?? '')) !== '' ? trim((string)$_POST['buscar']) : null,
    ], static function ($v) {
        return $v !== null && $v !== '';
    });

    require_once __DIR__ . '/estado_alumno_lib.php';
    require_once __DIR__ . '/db_collate.php';

    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        admin_mysqli_apply_collation($conn);
        $result = estado_alumno_eliminar_contrato_erroneo($conn, $contratoId);
        $conn->close();
    } catch (Throwable $e) {
        error_log('eliminar_contrato_erroneo: ' . $e->getMessage());
        admin_redirect_tab('revision-contratos', 'Error al eliminar el contrato.', 'danger', $redirectExtra);
    }

    if (!empty($result['ok'])) {
        admin_audit_log($pdo, 'eliminar_contrato_erroneo', 'contratos_aceptados', $contratoId, [
            'contrato_id' => $contratoId,
        ]);
        admin_redirect_tab('revision-contratos', (string)$result['msg'], 'success', $redirectExtra);
    }

    admin_redirect_tab(
        'revision-contratos',
        (string)($result['msg'] ?? 'No se pudo eliminar el contrato.'),
        'danger',
        $redirectExtra
    );
}
