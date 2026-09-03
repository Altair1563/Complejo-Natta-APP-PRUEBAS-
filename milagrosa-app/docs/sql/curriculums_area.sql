-- Bolsa de trabajo: área de postulación del CV
-- Ejecutar en u207063327_contactos_db (o la base configurada en config/db.php)

ALTER TABLE `curriculums`
  ADD COLUMN `area` VARCHAR(30) NOT NULL DEFAULT 'otros' COMMENT 'jardin_inicial|primaria|secundario|terciario|ingles|informatica|otros' AFTER `telefono`,
  ADD INDEX `idx_curriculums_area` (`area`);

-- Registros existentes quedan con area = 'otros' por el DEFAULT.
