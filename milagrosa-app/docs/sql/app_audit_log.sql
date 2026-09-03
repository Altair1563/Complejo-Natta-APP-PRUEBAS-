-- Auditoría de seguridad de la app pública (familias).
-- Eventos: ingresos, cierres de sesión, contraseñas, política de privacidad, contratos.
-- No incluye: talones, cambios de email, informes de error ni sugerencias.

CREATE TABLE IF NOT EXISTS `app_audit_log` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `dni_alumno` varchar(15) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `nro_familia` varchar(20) DEFAULT NULL,
  `accion` varchar(64) NOT NULL,
  `entidad` varchar(32) DEFAULT NULL,
  `entidad_id` varchar(32) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `session_id` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_app_audit_created` (`created_at`),
  KEY `idx_app_audit_accion` (`accion`),
  KEY `idx_app_audit_dni` (`dni_alumno`),
  KEY `idx_app_audit_email` (`email`),
  KEY `idx_app_audit_familia` (`nro_familia`),
  KEY `idx_app_audit_entidad` (`entidad`,`entidad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
