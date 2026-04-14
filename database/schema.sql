-- =====================================================
-- ETTUR LA UNIVERSIDAD - Sistema Integral de Recaudación
-- Base de Datos MySQL - Railway
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "-05:00"; -- Perú

-- -----------------------------------------------------
-- Tabla: roles
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(30) NOT NULL,
  `descripcion` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rol_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `nombre`, `descripcion`) VALUES
(1, 'admin', 'Administrador General - Control Total'),
(2, 'coadmin', 'Coadministrador - Validación y Reportes'),
(3, 'trabajador', 'Trabajador - Registro de Pagos');

-- -----------------------------------------------------
-- Tabla: usuarios
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rol_id` INT UNSIGNED NOT NULL,
  `nombres` VARCHAR(100) NOT NULL,
  `apellidos` VARCHAR(100) NOT NULL,
  `dni` VARCHAR(8) NOT NULL,
  `telefono` VARCHAR(15) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_baja` DATETIME DEFAULT NULL,
  `registrado_por` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dni` (`dni`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_rol` (`rol_id`),
  KEY `idx_activo` (`activo`),
  CONSTRAINT `fk_usuario_rol` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_usuario_registrado` FOREIGN KEY (`registrado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin por defecto (password: Admin2025!)
INSERT INTO `usuarios` (`rol_id`, `nombres`, `apellidos`, `dni`, `telefono`, `username`, `password_hash`) VALUES
(1, 'Administrador', 'General', '00000000', '999999999', 'admin', '$2y$12$LJ3m4ks9h0qX8V7Z0YtJPOW3xK5nR8gH2vN1bC6dF9eA4wS7uI0Km');

-- -----------------------------------------------------
-- Tabla: tarifas
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `tarifas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo` ENUM('verano', 'normal') NOT NULL,
  `monto` DECIMAL(10,2) NOT NULL,
  `descripcion` VARCHAR(200) DEFAULT NULL,
  `dia_inicio` TINYINT UNSIGNED NOT NULL,
  `mes_inicio` TINYINT UNSIGNED NOT NULL,
  `dia_fin` TINYINT UNSIGNED NOT NULL,
  `mes_fin` TINYINT UNSIGNED NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `modificado_por` INT UNSIGNED DEFAULT NULL,
  `fecha_modificacion` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tipo_tarifa` (`tipo`),
  CONSTRAINT `fk_tarifa_mod` FOREIGN KEY (`modificado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tarifas` (`tipo`, `monto`, `descripcion`, `dia_inicio`, `mes_inicio`, `dia_fin`, `mes_fin`) VALUES
('verano', 15.00, 'Tarifa temporada de verano', 1, 1, 15, 4),
('normal', 12.00, 'Tarifa temporada normal', 16, 4, 31, 12);

-- -----------------------------------------------------
-- Tabla: trabajador_config (Puesta en Marcha)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `trabajador_config` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` INT UNSIGNED NOT NULL,
  `fecha_inicio_cobro` DATE NOT NULL COMMENT 'Fecha desde la cual se empieza a cobrar (saldo inicial)',
  `notas` TEXT DEFAULT NULL,
  `configurado_por` INT UNSIGNED DEFAULT NULL,
  `fecha_configuracion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trabajador_config` (`usuario_id`),
  CONSTRAINT `fk_config_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_config_por` FOREIGN KEY (`configurado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabla: periodos_pago
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `periodos_pago` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `anio` SMALLINT UNSIGNED NOT NULL,
  `mes` TINYINT UNSIGNED NOT NULL,
  `quincena` TINYINT UNSIGNED NOT NULL COMMENT '1=primera quincena, 2=segunda quincena',
  `fecha_inicio` DATE NOT NULL,
  `fecha_fin` DATE NOT NULL,
  `tipo_tarifa` ENUM('verano', 'normal') NOT NULL,
  `monto` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_periodo` (`anio`, `mes`, `quincena`),
  KEY `idx_fechas` (`fecha_inicio`, `fecha_fin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabla: pagos
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pagos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trabajador_id` INT UNSIGNED NOT NULL,
  `periodo_id` INT UNSIGNED NOT NULL,
  `monto_pagado` DECIMAL(10,2) NOT NULL,
  `metodo_pago` ENUM('yape', 'transferencia', 'efectivo') NOT NULL DEFAULT 'yape',
  `comprobante_url` VARCHAR(500) DEFAULT NULL,
  `comprobante_nombre` VARCHAR(255) DEFAULT NULL,
  `estado` ENUM('pendiente', 'aprobado', 'rechazado') NOT NULL DEFAULT 'pendiente',
  `fecha_pago` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_validacion` DATETIME DEFAULT NULL,
  `validado_por` INT UNSIGNED DEFAULT NULL,
  `observaciones` TEXT DEFAULT NULL,
  `observacion_rechazo` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pago_periodo` (`trabajador_id`, `periodo_id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_fecha_pago` (`fecha_pago`),
  KEY `idx_periodo` (`periodo_id`),
  CONSTRAINT `fk_pago_trabajador` FOREIGN KEY (`trabajador_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pago_periodo` FOREIGN KEY (`periodo_id`) REFERENCES `periodos_pago` (`id`),
  CONSTRAINT `fk_pago_validador` FOREIGN KEY (`validado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabla: auditoria
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `auditoria` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` INT UNSIGNED DEFAULT NULL,
  `accion` VARCHAR(100) NOT NULL,
  `tabla_afectada` VARCHAR(50) DEFAULT NULL,
  `registro_id` INT UNSIGNED DEFAULT NULL,
  `datos_anteriores` JSON DEFAULT NULL,
  `datos_nuevos` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario_audit` (`usuario_id`),
  KEY `idx_fecha_audit` (`fecha`),
  KEY `idx_accion` (`accion`),
  CONSTRAINT `fk_audit_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabla: sesiones
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sesiones` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_expiracion` DATETIME NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_usuario_sesion` (`usuario_id`),
  KEY `idx_expiracion` (`fecha_expiracion`),
  CONSTRAINT `fk_sesion_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
