-- Disponibilidad horaria para duplencias (SI / NO)
ALTER TABLE `curriculums`
  ADD COLUMN `duplencias` VARCHAR(2) NOT NULL DEFAULT 'NO' COMMENT 'SI|NO disponibilidad horaria' AFTER `fecha_subida`;
