<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/db.php';

$pdo = obtenerDB();

// 1. Crear tabla t_categorias
$sqlTabla = "
CREATE TABLE IF NOT EXISTS t_categorias (
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
echo "Tabla t_categorias creada/verificada exitosamente.\n";

// 2. Sembrar categorías iniciales (CAFÉ, ACEITES, GRANOS BÁSICOS)
$sqlSeed = "
INSERT INTO t_categorias (empresa_id, codigo, nombre, descripcion, activo)
VALUES 
    (1, 'CAFE', 'CAFÉ', 'Café pergamino, húmedo, oro, resaca y derivados', 1),
    (1, 'ACEITES', 'ACEITES', 'Aceite crudo, refinado de palma y grasas', 1),
    (1, 'GRANOS_BASICOS', 'GRANOS BÁSICOS', 'Maíz, frijol, arroz, sorgo y granos alimenticios', 1)
ON DUPLICATE KEY UPDATE 
    nombre = VALUES(nombre), 
    descripcion = VALUES(descripcion), 
    activo = VALUES(activo);
";
$pdo->exec($sqlSeed);
echo "Categorías iniciales (CAFÉ, ACEITES, GRANOS BÁSICOS) sembradas.\n";

// 3. Agregar columna categoria_id a t_productos si no existe
$cols = $pdo->query("DESCRIBE t_productos")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('categoria_id', $cols, true)) {
    $pdo->exec("
        ALTER TABLE t_productos 
        ADD COLUMN categoria_id INT UNSIGNED NULL AFTER empresa_id,
        ADD INDEX idx_categoria (categoria_id)
    ");
    echo "Columna categoria_id agregada a t_productos.\n";
} else {
    echo "Columna categoria_id ya existía en t_productos.\n";
}

// 4. Asociar productos existentes a su categoría
$pdo->exec("UPDATE t_productos SET categoria_id = 1 WHERE nombre LIKE 'CAFE%'");
$pdo->exec("UPDATE t_productos SET categoria_id = 2 WHERE nombre LIKE 'ACEITE%'");
echo "Productos vinculados a sus categorías.\n";
