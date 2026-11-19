-- =============================================================================
-- Script de Actualización Segura (Idempotente) para BD Local en PC de Báscula
-- Proyecto: TYTOne Báscula Antigravity
--
-- Seguro para ejecutar sobre una base de datos en uso/pruebas:
-- NO borra pesadas, clientes, productos ni cambia el modo SERIAL/indicador real.
-- =============================================================================

SET NAMES utf8mb4;

-- 0. Columnas de contacto en t_empresas (usadas en empresa.php, boleta.php y guia_remision.php)
ALTER TABLE t_empresas
    ADD COLUMN IF NOT EXISTS telefono VARCHAR(50) DEFAULT NULL AFTER direccion,
    ADD COLUMN IF NOT EXISTS email VARCHAR(150) DEFAULT NULL AFTER telefono;

-- 1. Nuevas tablas de catálogos (Categorías y Presentaciones)
CREATE TABLE IF NOT EXISTS t_categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(150) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_empresa_codigo (empresa_id, codigo),
    KEY idx_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS t_presentaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(150) DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_empresa_codigo (empresa_id, codigo),
    KEY idx_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semilla inicial de categorías (solo si no existen para empresa 1)
INSERT IGNORE INTO t_categorias (empresa_id, codigo, nombre, descripcion, activo) VALUES
    (1, 'CAFE', 'CAFÉ', 'Café pergamino, húmedo, oro, resaca y derivados', 1),
    (1, 'ACEITES', 'ACEITES', 'Aceite crudo, refinado de palma y grasas', 1),
    (1, 'GRANOS_BASICOS', 'GRANOS BÁSICOS', 'Maíz, frijol, arroz, sorgo y granos alimenticios', 1);

-- Semilla inicial de presentaciones (solo si no existen para empresa 1)
INSERT IGNORE INTO t_presentaciones (empresa_id, codigo, nombre, descripcion, activo) VALUES
    (1, 'SACOS', 'SACOS', 'Presentación en sacos o bultos', 1),
    (1, 'GRANEL', 'A GRANEL', 'Carga a granel sin empaque', 1);

-- 2. Columna categoria_id en t_productos
ALTER TABLE t_productos
    ADD COLUMN IF NOT EXISTS categoria_id INT UNSIGNED DEFAULT NULL AFTER empresa_id;

-- 3. Columna gemini_api_key en t_configuracion_bascula (Asistente IA)
ALTER TABLE t_configuracion_bascula
    ADD COLUMN IF NOT EXISTS gemini_api_key VARCHAR(255) DEFAULT NULL AFTER unidad_indicador;

-- 4. Columnas de Guía de Remisión en t_pesadas (Ventas)
ALTER TABLE t_pesadas
    ADD COLUMN IF NOT EXISTS observaciones_segunda TEXT NULL AFTER observaciones,
    ADD COLUMN IF NOT EXISTS guia_remision VARCHAR(30) NULL AFTER observaciones_segunda,
    ADD COLUMN IF NOT EXISTS motivo_traslado VARCHAR(80) NULL AFTER guia_remision,
    ADD COLUMN IF NOT EXISTS motivo_traslado_otro VARCHAR(120) NULL AFTER motivo_traslado,
    ADD COLUMN IF NOT EXISTS transportista_rtn VARCHAR(20) NULL AFTER motivo_traslado_otro,
    ADD COLUMN IF NOT EXISTS marca_vehiculo VARCHAR(60) NULL AFTER transportista_rtn;

-- 5. Rol REMOTO (solo consulta) y usuario maria.jose (sin tocar otros usuarios existentes)
ALTER TABLE t_usuarios
    MODIFY COLUMN rol ENUM('SUPER', 'ADMINISTRADOR', 'SUPERVISOR', 'OPERADOR', 'REMOTO') NOT NULL DEFAULT 'OPERADOR';

INSERT INTO t_usuarios (empresa_id, nombre, usuario, email, clave_hash, rol, activo)
SELECT 1, 'María José', 'maria.jose', 'maria.jose@bascula.local', '$2y$12$d12QvYNWMKcFKDpdTz7BgugwkflZsMrjQC8i040RLpic/qFWHH1jm', 'REMOTO', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM t_usuarios WHERE usuario = 'maria.jose');
