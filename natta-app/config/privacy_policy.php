<?php
/**
 * Política de privacidad: lectura de versión vigente y aceptaciones.
 * Requiere que el caller haya definido _ACCESS y cargado config/db.php si usa conexión propia.
 */

if (!defined('_ACCESS')) {
    http_response_code(403);
    die('Acceso prohibido');
}

/**
 * Cadena canónica cuyo SHA-256 debe coincidir con privacy_policies.policy_hash para la versión activa.
 * Al cambiar el texto legal o la versión: generar nueva fila en BD, nuevo hash y actualizar esta constante.
 */
const PRIVACY_POLICY_CANONICAL_FINGERPRINT = 'privacy_policy_canonical_v2026-05-10-1_natta_app_ley25326';

/**
 * @return array{id:int,policy_version:string,policy_hash:string,effective_at:string}|null
 */
function privacy_policy_get_active(mysqli $conn): ?array
{
    $sql = 'SELECT id, policy_version, policy_hash, effective_at
            FROM privacy_policies
            WHERE is_active = 1
            ORDER BY id DESC
            LIMIT 1';
    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) {
        error_log('privacy_policy_get_active: no hay política activa en privacy_policies');
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row ?: null;
}

function privacy_policy_user_has_accepted(
    mysqli $conn,
    string $dniAlumno,
    string $email,
    string $policyVersion
): bool {
    if ($policyVersion === '') {
        return false;
    }
    $sql = "SELECT id FROM privacy_policy_acceptances
            WHERE dni_alumno = ? AND email = ? AND policy_version = ? AND status = 'activo'
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('privacy_policy_user_has_accepted: prepare failed: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('sss', $dniAlumno, $email, $policyVersion);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}

/**
 * Registra aceptación de la política vigente (idempotente si ya existe por UNIQUE).
 *
 * @return bool true si hay fila activa al finalizar (insert nuevo o ya existía)
 */
function privacy_policy_try_record_acceptance(
    mysqli $conn,
    string $dniAlumno,
    string $email,
    string $nroFamilia,
    string $policyVersion,
    string $policyHash,
    string $acceptedAt,
    string $ip,
    string $userAgent
): bool {
    if (
        $dniAlumno === '' || $email === '' || $nroFamilia === '' ||
        $policyVersion === '' || $policyHash === ''
    ) {
        return false;
    }

    if (privacy_policy_user_has_accepted($conn, $dniAlumno, $email, $policyVersion)) {
        return true;
    }

    $sql = "INSERT INTO privacy_policy_acceptances (
                dni_alumno, email, nro_familia, policy_version, policy_hash,
                accepted_at, ip_address, user_agent, accepted_checkbox, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'activo')";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('privacy_policy_try_record_acceptance: prepare failed: ' . $conn->error);
        return false;
    }
    $stmt->bind_param(
        'ssssssss',
        $dniAlumno,
        $email,
        $nroFamilia,
        $policyVersion,
        $policyHash,
        $acceptedAt,
        $ip,
        $userAgent
    );
    $ok = $stmt->execute();
    if (!$ok) {
        if ((int)$stmt->errno === 1062) {
            $stmt->close();
            return true;
        }
        error_log('privacy_policy_try_record_acceptance: execute failed: ' . $stmt->error);
        $stmt->close();
        return false;
    }
    $stmt->close();
    return true;
}
