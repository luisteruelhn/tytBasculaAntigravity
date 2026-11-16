<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/db.php';

$pdo = obtenerDB();

// 1. Crear tabla t_presentaciones si no existe
$sqlTabla = "
CREATE TABLE IF NOT EXISTS t_presentaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(150) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_empresa (empresa_id),
    UNIQUE KEY uk_empresa_codigo (empresa_id, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
$pdo->exec($sqlTabla);
echo "Tabla t_presentaciones verificada/creada exitosamente.\n";

// 2. Sembrar SACOS y A GRANEL para empresa_id = 1
$sqlSeed = "
INSERT INTO t_presentaciones (empresa_id, codigo, nombre, descripcion, activo)
VALUES 
    (1, 'SACOS', 'SACOS', 'Empaque en sacos de café u otros granos', 1),
    (1, 'GRANEL', 'A GRANEL', 'Carga suelta a granel / Cisterna o tolva', 1)
ON DUPLICATE KEY UPDATE 
    nombre = VALUES(nombre), 
    descripcion = VALUES(descripcion), 
    activo = VALUES(activo);
";
$pdo->exec($sqlSeed);
echo "Presentaciones iniciales (SACOS y A GRANEL) sembradas en base de datos.\n";
