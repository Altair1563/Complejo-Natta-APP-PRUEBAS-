<?php
/**
 * Crea tablas admin_users / admin_audit_log y usuario inicial si no existe.
 */

function admin_ensure_schema(PDO $pdo): void
{
    static $installed = false;
    if ($installed) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            rol ENUM('superadmin','admin') NOT NULL DEFAULT 'admin',
            activo TINYINT(1) NOT NULL DEFAULT 1,
            ultimo_login DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_admin_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_audit_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_user_id INT UNSIGNED NULL,
            accion VARCHAR(64) NOT NULL,
            entidad VARCHAR(32) NULL,
            entidad_id INT UNSIGNED NULL,
            detalle TEXT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            session_id VARCHAR(64) NULL,
            INDEX idx_audit_created (created_at),
            INDEX idx_audit_accion (accion),
            INDEX idx_audit_user (admin_user_id),
            INDEX idx_audit_entidad (entidad, entidad_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $count = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($count === 0 && defined('ADMIN_PASSWORD_HASH')) {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_users (username, password_hash, nombre, rol, activo)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            'admin',
            ADMIN_PASSWORD_HASH,
            'Administrador',
            'superadmin',
        ]);
    }

    try {
        $pdo->exec(
            "ALTER TABLE admin_users MODIFY rol ENUM('superadmin','admin','secretaria','directivo') NOT NULL DEFAULT 'admin'"
        );
    } catch (PDOException $e) {
        // Ya migrado o entorno sin permisos ALTER.
    }

    try {
        $pdo->exec(
            'ALTER TABLE admin_users ADD COLUMN escuela_codigo VARCHAR(2) NULL DEFAULT NULL AFTER rol'
        );
    } catch (PDOException $e) {
        // Columna ya existe.
    }

    try {
        $pdo->exec(
            'ALTER TABLE comunicados ADD COLUMN archivo_pdf VARCHAR(255) NULL DEFAULT NULL AFTER contenido'
        );
    } catch (PDOException $e) {
        // Columna ya existe.
    }

    require_once dirname(__DIR__, 2) . '/config/app_audit.php';
    app_ensure_audit_schema($pdo);

    $installed = true;
}
