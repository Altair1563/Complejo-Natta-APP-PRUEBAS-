-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1:3306
-- Tiempo de generación: 06-06-2026 a las 13:56:04
-- Versión del servidor: 11.8.6-MariaDB-log
-- Versión de PHP: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `u207063327_contactos_db`
--
-- Contratos (2026-06): la tabla `contracts` fue eliminada. La configuración del
-- contrato vigente por colegio vive en `contratos_instituciones` (contract_anio,
-- contract_revision, contrato_activo, accepted_text). Las plantillas HTML están
-- en docs/contratos/ y los PDF firmados en storage/contratos_firmados/.
-- Migración desde esquema antiguo: docs/sql/contratos_unificar_instituciones.sql
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admin_audit_log`
--

CREATE TABLE `admin_audit_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `admin_user_id` int(10) UNSIGNED DEFAULT NULL,
  `accion` varchar(64) NOT NULL,
  `entidad` varchar(32) DEFAULT NULL,
  `entidad_id` int(10) UNSIGNED DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `session_id` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `rol` enum('superadmin','admin') NOT NULL DEFAULT 'admin',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `ultimo_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `alta_alumnos_nuevos`
--

CREATE TABLE `alta_alumnos_nuevos` (
  `id` int(11) NOT NULL,
  `apellido_alumno` varchar(100) NOT NULL,
  `nombre_alumno` varchar(100) NOT NULL,
  `nro_documento_alumno` varchar(15) NOT NULL,
  `fecha_nacimiento_alumno` date DEFAULT NULL,
  `sexo` enum('MASCULINO','FEMENINO','OTRO') NOT NULL,
  `nacionalidad_alumno` varchar(50) DEFAULT 'ARGENTINA',
  `codigo_curso` varchar(20) DEFAULT NULL,
  `fecha_ingreso` date DEFAULT NULL,
  `responsable_afip` varchar(50) DEFAULT NULL,
  `tipo_doc_resp_afip` varchar(10) DEFAULT NULL,
  `nro_doc_resp_afip` varchar(15) DEFAULT NULL,
  `email_resp_afip` varchar(120) DEFAULT NULL,
  `direccion_calle_afip` varchar(100) DEFAULT NULL,
  `direccion_numero_afip` varchar(10) DEFAULT NULL,
  `direccion_piso_afip` varchar(10) DEFAULT NULL,
  `direccion_depto_afip` varchar(10) DEFAULT NULL,
  `codigo_postal_afip` varchar(10) DEFAULT NULL,
  `localidad_afip` varchar(100) DEFAULT NULL,
  `responsable_familia` varchar(50) DEFAULT NULL,
  `tipo_doc_resp_familia` varchar(10) DEFAULT NULL,
  `nro_doc_resp_familia` varchar(15) DEFAULT NULL,
  `telefono_resp_familia` varchar(30) DEFAULT NULL,
  `direccion_calle_familia` varchar(100) DEFAULT NULL,
  `direccion_numero_familia` varchar(10) DEFAULT NULL,
  `direccion_piso_familia` varchar(10) DEFAULT NULL,
  `direccion_depto_familia` varchar(10) DEFAULT NULL,
  `codigo_postal_familia` varchar(10) DEFAULT NULL,
  `localidad_familia` varchar(100) DEFAULT NULL,
  `nombre_padre` varchar(100) DEFAULT NULL,
  `fecha_nacimiento_padre` date DEFAULT NULL,
  `nro_documento_padre` varchar(15) DEFAULT NULL,
  `celular_padre` varchar(30) DEFAULT NULL,
  `email_padre` varchar(120) DEFAULT NULL,
  `nombre_madre` varchar(100) DEFAULT NULL,
  `fecha_nacimiento_madre` date DEFAULT NULL,
  `nro_documento_madre` varchar(15) DEFAULT NULL,
  `celular_madre` varchar(30) DEFAULT NULL,
  `email_madre` varchar(120) DEFAULT NULL,
  `plan_cuotas` int(11) DEFAULT 1,
  `fecha_registro` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `comunicados`
--

CREATE TABLE `comunicados` (
  `id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `contenido` text NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion`
--

CREATE TABLE `configuracion` (
  `id` int(11) NOT NULL,
  `clave` varchar(50) NOT NULL,
  `valor` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `fecha_actualizacion` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contactos`
--

CREATE TABLE `contactos` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `nombre_apellido` varchar(255) NOT NULL,
  `telefono` varchar(50) NOT NULL,
  `mensaje` text NOT NULL,
  `fecha` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contratos_aceptados`
--

CREATE TABLE `contratos_aceptados` (
  `id` int(11) NOT NULL,
  `user_dni` varchar(20) NOT NULL,
  `student_dni` varchar(20) NOT NULL,
  `nro_legajo` varchar(20) NOT NULL,
  `nro_familia` varchar(20) NOT NULL,
  `contract_version` varchar(100) NOT NULL,
  `contract_hash` char(64) NOT NULL,
  `signed_document_sha256` char(64) DEFAULT NULL COMMENT 'SHA-256 del PDF firmado depositado',
  `accepted_text` text NOT NULL,
  `accepted_checkbox` tinyint(1) NOT NULL DEFAULT 1,
  `accepted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `accepted_at_utc` datetime DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `password_reconfirmed` tinyint(1) NOT NULL DEFAULT 0,
  `pdf_url` varchar(500) NOT NULL,
  `accepted_pdf_path` varchar(512) DEFAULT NULL COMMENT 'Ruta relativa en storage/contratos_firmados',
  `confirmation_pdf_url` varchar(500) DEFAULT NULL,
  `confirmation_email_sent` tinyint(1) NOT NULL DEFAULT 0,
  `confirmation_email_sent_at` datetime DEFAULT NULL,
  `status` enum('activo','revocado','actualizado') NOT NULL DEFAULT 'activo',
  `admin_aprobado` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = administración confirmó documentación y requisitos',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `contratos_instituciones`
--

CREATE TABLE `contratos_instituciones` (
  `codigo` varchar(2) NOT NULL COMMENT 'Código escuela: últimas 2 letras del curso en mayúsculas',
  `responsable_institucion` varchar(512) NOT NULL COMMENT 'Ej: la Sra. Magdalena FERREIRA',
  `institucion` varchar(512) NOT NULL COMMENT 'Ej: del Instituto Jesús Niño (DIEGEP 0838)',
  `domicilio_institucion` varchar(512) NOT NULL COMMENT 'Texto tras "con domicilio en"',
  `contract_anio` char(4) NOT NULL DEFAULT '2027' COMMENT 'Año del ciclo lectivo (plantilla HTML en docs/contratos/)',
  `contract_revision` varchar(10) NOT NULL DEFAULT 'v1' COMMENT 'Revisión del contrato (ej. v1 → Contrato_XX_2027_v1.html)',
  `contrato_activo` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = contrato habilitado para firma en esta institución',
  `accepted_text` text NOT NULL COMMENT 'Texto legal de aceptación (mismo texto para todas las instituciones)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cuotas`
--

CREATE TABLE `cuotas` (
  `id` int(11) NOT NULL,
  `nro_legajo` varchar(20) NOT NULL,
  `numero_cuota` int(11) DEFAULT NULL,
  `recibo_nro` varchar(50) DEFAULT NULL,
  `monto_facturado` decimal(10,2) DEFAULT NULL,
  `monto_ingresado` decimal(10,2) DEFAULT NULL,
  `diferencia` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fecha_liquidacion` date DEFAULT NULL,
  `fecha_pago` date DEFAULT NULL,
  `estado` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `curriculums`
--

CREATE TABLE `curriculums` (
  `id` int(11) NOT NULL,
  `nombre_apellido` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `area` varchar(30) NOT NULL DEFAULT 'otros',
  `archivo` varchar(255) NOT NULL,
  `fecha_subida` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `email_familia`
--

CREATE TABLE `email_familia` (
  `id` int(11) NOT NULL,
  `nro_familia` varchar(20) NOT NULL,
  `mail_padre` varchar(100) DEFAULT NULL,
  `mail_padre_trabajo` varchar(100) DEFAULT NULL,
  `mail_madre` varchar(100) DEFAULT NULL,
  `mail_madre_trabajo` varchar(100) DEFAULT NULL,
  `mail_resp_afip` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `informes_error`
--

CREATE TABLE `informes_error` (
  `id` int(11) NOT NULL,
  `nro_familia` varchar(50) NOT NULL,
  `error_descripcion` text NOT NULL,
  `correccion_sugerida` text DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `estado` enum('pendiente','en_proceso','resuelto') DEFAULT 'pendiente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `legajos`
--

CREATE TABLE `legajos` (
  `id` int(11) NOT NULL,
  `nro_familia` int(10) DEFAULT NULL,
  `nro_legajo` varchar(20) NOT NULL,
  `apellido_alumno` varchar(100) DEFAULT NULL,
  `nombre_alumno` varchar(100) DEFAULT NULL,
  `curso` varchar(20) DEFAULT NULL,
  `dni_alumno` varchar(15) DEFAULT NULL,
  `codigo_descuento` tinyint(4) DEFAULT NULL,
  `porcentaje_descuento` tinyint(4) DEFAULT NULL,
  `saldo_total` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `legajos_inactivos`
--

CREATE TABLE `legajos_inactivos` (
  `id` int(11) NOT NULL,
  `nro_familia` int(10) DEFAULT NULL,
  `nro_legajo` varchar(20) NOT NULL,
  `apellido_alumno` varchar(100) DEFAULT NULL,
  `nombre_alumno` varchar(100) DEFAULT NULL,
  `curso` varchar(20) DEFAULT NULL,
  `dni_alumno` varchar(15) NOT NULL,
  `codigo_descuento` tinyint(4) DEFAULT NULL,
  `porcentaje_descuento` tinyint(4) DEFAULT NULL,
  `saldo_total` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Disparadores `legajos_inactivos`
--
DELIMITER $$
CREATE TRIGGER `before_update_legajos_inactivos` BEFORE UPDATE ON `legajos_inactivos` FOR EACH ROW BEGIN
    SET NEW.nro_legajo = 'INACTIVO';
    SET NEW.curso = '----';
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `failed_attempts` int(11) DEFAULT 0,
  `last_attempt` timestamp NULL DEFAULT NULL,
  `locked_until` timestamp NULL DEFAULT NULL,
  `strike_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `notificaciones`
--

CREATE TABLE `notificaciones` (
  `id` int(11) NOT NULL,
  `nro_familia` int(11) NOT NULL,
  `mensaje` varchar(255) NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `leido` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `dni_alumno` varchar(20) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `privacy_policies`
--

CREATE TABLE `privacy_policies` (
  `id` int(10) UNSIGNED NOT NULL,
  `policy_version` varchar(64) NOT NULL,
  `policy_hash` char(64) NOT NULL COMMENT 'SHA-256 hex de la huella canónica de la versión',
  `effective_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `privacy_policy_acceptances`
--

CREATE TABLE `privacy_policy_acceptances` (
  `id` int(10) UNSIGNED NOT NULL,
  `dni_alumno` varchar(32) NOT NULL,
  `email` varchar(255) NOT NULL,
  `nro_familia` varchar(32) NOT NULL,
  `policy_version` varchar(64) NOT NULL,
  `policy_hash` char(64) NOT NULL,
  `accepted_at` datetime NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(1000) NOT NULL DEFAULT '',
  `accepted_checkbox` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(16) NOT NULL DEFAULT 'activo',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitudes_email`
--

CREATE TABLE `solicitudes_email` (
  `id` int(11) NOT NULL,
  `nro_familia` varchar(50) NOT NULL,
  `posicion` tinyint(4) NOT NULL COMMENT '1: mail_padre, 2: mail_padre_trabajo, 3: mail_madre, 4: mail_madre_trabajo',
  `email_actual` varchar(100) NOT NULL,
  `email_nuevo` varchar(100) NOT NULL,
  `fecha_solicitud` datetime NOT NULL DEFAULT current_timestamp(),
  `estado` enum('pendiente','aprobado','rechazado','cancelado') NOT NULL DEFAULT 'pendiente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitudes_talon`
--

CREATE TABLE `solicitudes_talon` (
  `id` int(11) NOT NULL,
  `nro_familia` varchar(50) NOT NULL,
  `nro_legajo` varchar(20) NOT NULL,
  `cuota_id` int(11) NOT NULL,
  `fecha_solicitud` datetime NOT NULL DEFAULT current_timestamp(),
  `estado` enum('pendiente','generado','entregado') NOT NULL DEFAULT 'pendiente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sugerencias`
--

CREATE TABLE `sugerencias` (
  `id` int(11) NOT NULL,
  `nro_familia` varchar(50) NOT NULL,
  `fecha` timestamp NULL DEFAULT current_timestamp(),
  `mensaje` text NOT NULL,
  `leido` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `dni_alumno` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `admin_audit_log`
--
ALTER TABLE `admin_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_created` (`created_at`),
  ADD KEY `idx_audit_accion` (`accion`),
  ADD KEY `idx_audit_user` (`admin_user_id`),
  ADD KEY `idx_audit_entidad` (`entidad`,`entidad_id`);

--
-- Indices de la tabla `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_admin_username` (`username`);

--
-- Indices de la tabla `alta_alumnos_nuevos`
--
ALTER TABLE `alta_alumnos_nuevos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nro_documento_alumno` (`nro_documento_alumno`);

--
-- Indices de la tabla `comunicados`
--
ALTER TABLE `comunicados`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `clave` (`clave`);

--
-- Indices de la tabla `contactos`
--
ALTER TABLE `contactos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `contratos_aceptados`
--
ALTER TABLE `contratos_aceptados`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_contrato_student_version` (`student_dni`,`contract_version`),
  ADD KEY `idx_contratos_user_dni` (`user_dni`),
  ADD KEY `idx_contratos_student_dni` (`student_dni`),
  ADD KEY `idx_contratos_legajo` (`nro_legajo`),
  ADD KEY `idx_contratos_familia` (`nro_familia`),
  ADD KEY `idx_contratos_version` (`contract_version`),
  ADD KEY `idx_contratos_accepted_at` (`accepted_at`);

--
-- Indices de la tabla `contratos_instituciones`
--
ALTER TABLE `contratos_instituciones`
  ADD PRIMARY KEY (`codigo`),
  ADD KEY `idx_contratos_inst_activo` (`contrato_activo`);

--
-- Indices de la tabla `cuotas`
--
ALTER TABLE `cuotas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unq_cuota_legajo` (`nro_legajo`,`numero_cuota`);

--
-- Indices de la tabla `curriculums`
--
ALTER TABLE `curriculums`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `email_familia`
--
ALTER TABLE `email_familia`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nro_familia` (`nro_familia`);

--
-- Indices de la tabla `informes_error`
--
ALTER TABLE `informes_error`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `legajos`
--
ALTER TABLE `legajos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nro_legajo` (`nro_legajo`),
  ADD UNIQUE KEY `dni_alumno` (`dni_alumno`);

--
-- Indices de la tabla `legajos_inactivos`
--
ALTER TABLE `legajos_inactivos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dni_alumno` (`dni_alumno`),
  ADD UNIQUE KEY `dni_alumno_2` (`dni_alumno`),
  ADD UNIQUE KEY `nro_legajo` (`nro_legajo`);

--
-- Indices de la tabla `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`);

--
-- Indices de la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dni_alumno` (`dni_alumno`),
  ADD KEY `token` (`token`);

--
-- Indices de la tabla `privacy_policies`
--
ALTER TABLE `privacy_policies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_privacy_policies_active` (`is_active`),
  ADD KEY `idx_privacy_policies_version` (`policy_version`);

--
-- Indices de la tabla `privacy_policy_acceptances`
--
ALTER TABLE `privacy_policy_acceptances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_privacy_accept_user_version` (`dni_alumno`,`email`,`policy_version`),
  ADD KEY `idx_privacy_accept_lookup` (`dni_alumno`,`email`,`status`),
  ADD KEY `idx_privacy_accept_version` (`policy_version`);

--
-- Indices de la tabla `solicitudes_email`
--
ALTER TABLE `solicitudes_email`
  ADD PRIMARY KEY (`id`),
  ADD KEY `nro_familia` (`nro_familia`),
  ADD KEY `estado` (`estado`);

--
-- Indices de la tabla `solicitudes_talon`
--
ALTER TABLE `solicitudes_talon`
  ADD PRIMARY KEY (`id`),
  ADD KEY `nro_familia` (`nro_familia`),
  ADD KEY `nro_legajo` (`nro_legajo`),
  ADD KEY `cuota_id` (`cuota_id`);

--
-- Indices de la tabla `sugerencias`
--
ALTER TABLE `sugerencias`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD UNIQUE KEY `uq_usuarios_dni_email` (`dni_alumno`,`email`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `admin_audit_log`
--
ALTER TABLE `admin_audit_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `alta_alumnos_nuevos`
--
ALTER TABLE `alta_alumnos_nuevos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `comunicados`
--
ALTER TABLE `comunicados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `contactos`
--
ALTER TABLE `contactos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `contratos_aceptados`
--
ALTER TABLE `contratos_aceptados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cuotas`
--
ALTER TABLE `cuotas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `curriculums`
--
ALTER TABLE `curriculums`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `email_familia`
--
ALTER TABLE `email_familia`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `informes_error`
--
ALTER TABLE `informes_error`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `legajos`
--
ALTER TABLE `legajos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `legajos_inactivos`
--
ALTER TABLE `legajos_inactivos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `notificaciones`
--
ALTER TABLE `notificaciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `privacy_policies`
--
ALTER TABLE `privacy_policies`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `privacy_policy_acceptances`
--
ALTER TABLE `privacy_policy_acceptances`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `solicitudes_email`
--
ALTER TABLE `solicitudes_email`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `solicitudes_talon`
--
ALTER TABLE `solicitudes_talon`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `sugerencias`
--
ALTER TABLE `sugerencias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `contratos_aceptados`
--
ALTER TABLE `contratos_aceptados`
  ADD CONSTRAINT `fk_contratos_usuario` FOREIGN KEY (`user_dni`) REFERENCES `usuarios` (`dni_alumno`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Filtros para la tabla `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`dni_alumno`) REFERENCES `usuarios` (`dni_alumno`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
