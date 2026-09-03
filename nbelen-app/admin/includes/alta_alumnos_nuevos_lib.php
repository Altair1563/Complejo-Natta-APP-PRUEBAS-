<?php
/**
 * Alta de alumnos nuevos — lógica de negocio (admin).
 */

function admin_aan_post_str(array $post, string $key): string
{
    return trim((string)($post[$key] ?? ''));
}

function admin_aan_post_upper(array $post, string $key): string
{
    return mb_strtoupper(admin_aan_post_str($post, $key), 'UTF-8');
}

function admin_aan_post_lower(array $post, string $key): string
{
    return mb_strtolower(admin_aan_post_str($post, $key), 'UTF-8');
}

/**
 * @return array{type: string, html: string}
 */
function admin_aan_procesar_alta(PDO $pdo, array $post): array
{
    if (!function_exists('tenant_curso_alta_nuevos')) {
        require_once dirname(__DIR__, 2) . '/config/tenant_helpers.php';
    }
    $cursoAlta = tenant_curso_alta_nuevos();

    $post = $post;
    $post['codigo_curso'] = $cursoAlta;
    $post['fecha_ingreso'] = '2026-03-01';
    $post['tipo_doc_resp_familia'] = 'DNI';
    $post['tipo_doc_resp_afip'] = 'DNI';

    $apellidoAlumno = admin_aan_post_upper($post, 'apellido_alumno');
    $nombreAlumno = admin_aan_post_upper($post, 'nombre_alumno');
    $nroDocumentoAlumno = admin_aan_post_str($post, 'nro_documento_alumno');
    $fechaNacimientoAlumno = admin_aan_post_str($post, 'fecha_nacimiento_alumno') ?: null;
    $sexo = admin_aan_post_str($post, 'sexo');
    $nacionalidadAlumno = admin_aan_post_upper($post, 'nacionalidad_alumno') ?: 'ARGENTINA';
    $codigoCurso = $cursoAlta;
    $fechaIngreso = '2026-03-01';

    $responsableAfip = admin_aan_post_upper($post, 'responsable_afip');
    $tipoDocRespAfip = 'DNI';
    $nroDocRespAfip = admin_aan_post_str($post, 'nro_doc_resp_afip');
    $emailRespAfip = admin_aan_post_lower($post, 'email_resp_afip');
    $direccionCalleAfip = admin_aan_post_upper($post, 'direccion_calle_afip');
    $direccionNumeroAfip = admin_aan_post_str($post, 'direccion_numero_afip');
    $direccionPisoAfip = '';
    $direccionDeptoAfip = '';
    $codigoPostalAfip = admin_aan_post_str($post, 'codigo_postal_afip');
    $localidadAfip = admin_aan_post_upper($post, 'localidad_afip');

    $responsableFamilia = admin_aan_post_upper($post, 'responsable_familia');
    $tipoDocRespFamilia = 'DNI';
    $nroDocRespFamilia = admin_aan_post_str($post, 'nro_doc_resp_familia');
    $telefonoRespFamilia = admin_aan_post_str($post, 'telefono_resp_familia');
    $direccionCalleFamilia = admin_aan_post_upper($post, 'direccion_calle_familia');
    $direccionNumeroFamilia = admin_aan_post_str($post, 'direccion_numero_familia');
    $direccionPisoFamilia = '';
    $direccionDeptoFamilia = '';
    $codigoPostalFamilia = admin_aan_post_str($post, 'codigo_postal_familia');
    $localidadFamilia = admin_aan_post_upper($post, 'localidad_familia');

    $nombrePadre = admin_aan_post_upper($post, 'nombre_padre');
    $fechaNacimientoPadre = null;
    $nroDocumentoPadre = admin_aan_post_str($post, 'nro_documento_padre');
    $celularPadre = admin_aan_post_str($post, 'celular_padre');
    $emailPadre = admin_aan_post_lower($post, 'email_padre');

    $nombreMadre = admin_aan_post_upper($post, 'nombre_madre');
    $fechaNacimientoMadre = null;
    $nroDocumentoMadre = admin_aan_post_str($post, 'nro_documento_madre');
    $celularMadre = admin_aan_post_str($post, 'celular_madre');
    $emailMadre = admin_aan_post_lower($post, 'email_madre');

    $planCuotas = 1;

    if ($nroDocumentoAlumno === '') {
        return [
            'type' => 'danger',
            'html' => 'Debes ingresar el DNI del alumno.',
        ];
    }

    $chk = $pdo->prepare('SELECT id FROM alta_alumnos_nuevos WHERE nro_documento_alumno = ? LIMIT 1');
    $chk->execute([$nroDocumentoAlumno]);
    $existingId = $chk->fetchColumn();
    if ($existingId !== false) {
        return [
            'type' => 'warning',
            'html' => 'Ya existe un alumno con DNI <strong>' . htmlspecialchars($nroDocumentoAlumno, ENT_QUOTES, 'UTF-8')
                . '</strong> (ID: ' . (int)$existingId . ').',
        ];
    }

    $sql = 'INSERT INTO alta_alumnos_nuevos (
        apellido_alumno, nombre_alumno, nro_documento_alumno, fecha_nacimiento_alumno, sexo, nacionalidad_alumno,
        codigo_curso, fecha_ingreso,
        responsable_afip, tipo_doc_resp_afip, nro_doc_resp_afip, email_resp_afip,
        direccion_calle_afip, direccion_numero_afip, direccion_piso_afip, direccion_depto_afip,
        codigo_postal_afip, localidad_afip,
        responsable_familia, tipo_doc_resp_familia, nro_doc_resp_familia, telefono_resp_familia,
        direccion_calle_familia, direccion_numero_familia, direccion_piso_familia, direccion_depto_familia,
        codigo_postal_familia, localidad_familia,
        nombre_padre, fecha_nacimiento_padre, nro_documento_padre, celular_padre, email_padre,
        nombre_madre, fecha_nacimiento_madre, nro_documento_madre, celular_madre, email_madre, plan_cuotas
    ) VALUES (
        ?,?,?,?,?,?,?,?,
        ?,?,?,?,?,?,?,?,?,
        ?,?,?,?,?,?,?,?,?,
        ?,?,?,?,?,
        ?,?,?,?,?,?
    )';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $apellidoAlumno, $nombreAlumno, $nroDocumentoAlumno, $fechaNacimientoAlumno, $sexo, $nacionalidadAlumno,
            $codigoCurso, $fechaIngreso,
            $responsableAfip, $tipoDocRespAfip, $nroDocRespAfip, $emailRespAfip,
            $direccionCalleAfip, $direccionNumeroAfip, $direccionPisoAfip, $direccionDeptoAfip,
            $codigoPostalAfip, $localidadAfip,
            $responsableFamilia, $tipoDocRespFamilia, $nroDocRespFamilia, $telefonoRespFamilia,
            $direccionCalleFamilia, $direccionNumeroFamilia, $direccionPisoFamilia, $direccionDeptoFamilia,
            $codigoPostalFamilia, $localidadFamilia,
            $nombrePadre, $fechaNacimientoPadre, $nroDocumentoPadre, $celularPadre, $emailPadre,
            $nombreMadre, $fechaNacimientoMadre, $nroDocumentoMadre, $celularMadre, $emailMadre, $planCuotas,
        ]);
        $newId = (int)$pdo->lastInsertId();
        admin_audit_log($pdo, 'alta_alumno_nuevo', 'alta_alumnos_nuevos', $newId, [
            'dni' => $nroDocumentoAlumno,
        ]);

        return [
            'type' => 'success',
            'html' => 'Alumno registrado correctamente.',
        ];
    } catch (PDOException $e) {
        error_log('admin_aan_procesar_alta: ' . $e->getMessage());

        return [
            'type' => 'danger',
            'html' => 'Error al registrar el alumno. Intente nuevamente.',
        ];
    }
}

/**
 * @return list<array<string, mixed>>
 */
function admin_aan_fetch_ultimos(PDO $pdo, int $limit = 5): array
{
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->query(
        'SELECT id, apellido_alumno, nombre_alumno, nro_documento_alumno,
                responsable_familia, nro_doc_resp_familia, email_resp_afip, fecha_registro
         FROM alta_alumnos_nuevos
         ORDER BY id DESC
         LIMIT ' . (int)$limit
    );

    return $stmt ? $stmt->fetchAll() : [];
}

/**
 * @return list<array<string, mixed>>
 */
function admin_aan_fetch_todos(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, apellido_alumno, nombre_alumno, nro_documento_alumno,
                nombre_madre, nro_documento_madre, email_madre, fecha_registro
         FROM alta_alumnos_nuevos
         ORDER BY id DESC'
    );

    return $stmt ? $stmt->fetchAll() : [];
}
