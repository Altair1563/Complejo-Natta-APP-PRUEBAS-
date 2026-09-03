<?php
/**
 * Auditoría de seguridad de la app pública (familias).
 * No registra solicitudes de talón, cambio de email, informes ni sugerencias.
 */

if (!function_exists('app_ensure_audit_schema')) {
    /**
     * @param mysqli|PDO $db
     */
    function app_ensure_audit_schema($db): void
    {
        static $installed = false;
        if ($installed) {
            return;
        }

        $sql = "
            CREATE TABLE IF NOT EXISTS app_audit_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dni_alumno VARCHAR(15) NULL,
                email VARCHAR(190) NULL,
                nro_familia VARCHAR(20) NULL,
                accion VARCHAR(64) NOT NULL,
                entidad VARCHAR(32) NULL,
                entidad_id VARCHAR(32) NULL,
                detalle TEXT NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                session_id VARCHAR(64) NULL,
                INDEX idx_app_audit_created (created_at),
                INDEX idx_app_audit_accion (accion),
                INDEX idx_app_audit_dni (dni_alumno),
                INDEX idx_app_audit_email (email),
                INDEX idx_app_audit_familia (nro_familia),
                INDEX idx_app_audit_entidad (entidad, entidad_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if ($db instanceof mysqli) {
            $db->query($sql);
        } elseif ($db instanceof PDO) {
            $db->exec($sql);
        }

        $installed = true;
    }
}

if (!function_exists('app_audit_client_ip')) {
    function app_audit_client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '') {
            return '0.0.0.0';
        }
        return substr((string)$ip, 0, 45);
    }
}

if (!function_exists('app_audit_log')) {
    /**
     * @param mysqli|PDO $db
     * @param array<string, scalar|null> $detalle
     */
    function app_audit_log(
        $db,
        string $accion,
        ?string $entidad = null,
        ?string $entidadId = null,
        array $detalle = [],
        ?string $dniAlumno = null,
        ?string $email = null,
        ?string $nroFamilia = null
    ): void {
        try {
            app_ensure_audit_schema($db);

            if ($dniAlumno === null && isset($_SESSION['dni_alumno'])) {
                $dniAlumno = (string)$_SESSION['dni_alumno'];
            }
            if ($email === null && isset($_SESSION['email'])) {
                $email = (string)$_SESSION['email'];
            }
            if ($nroFamilia === null && isset($_SESSION['nro_familia'])) {
                $nroFamilia = (string)$_SESSION['nro_familia'];
            }

            $gate = $_SESSION['privacy_gate'] ?? null;
            if (is_array($gate)) {
                if ($dniAlumno === null && !empty($gate['dni_alumno'])) {
                    $dniAlumno = (string)$gate['dni_alumno'];
                }
                if ($email === null && !empty($gate['email'])) {
                    $email = (string)$gate['email'];
                }
                if ($nroFamilia === null && !empty($gate['nro_familia'])) {
                    $nroFamilia = (string)$gate['nro_familia'];
                }
            }

            $json = $detalle !== [] ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null;
            $ip = app_audit_client_ip();
            $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
                ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255)
                : null;
            $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : null;

            if ($db instanceof mysqli) {
                $stmt = $db->prepare(
                    'INSERT INTO app_audit_log
                        (dni_alumno, email, nro_familia, accion, entidad, entidad_id, detalle, ip, user_agent, session_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new RuntimeException('app_audit_log prepare: ' . $db->error);
                }
                $stmt->bind_param(
                    'ssssssssss',
                    $dniAlumno,
                    $email,
                    $nroFamilia,
                    $accion,
                    $entidad,
                    $entidadId,
                    $json,
                    $ip,
                    $userAgent,
                    $sessionId
                );
                $stmt->execute();
                $stmt->close();
                return;
            }

            if ($db instanceof PDO) {
                $stmt = $db->prepare(
                    'INSERT INTO app_audit_log
                        (dni_alumno, email, nro_familia, accion, entidad, entidad_id, detalle, ip, user_agent, session_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $dniAlumno,
                    $email,
                    $nroFamilia,
                    $accion,
                    $entidad,
                    $entidadId,
                    $json,
                    $ip,
                    $userAgent,
                    $sessionId,
                ]);
            }
        } catch (Throwable $e) {
            error_log('app_audit_log: ' . $e->getMessage());
        }
    }
}
