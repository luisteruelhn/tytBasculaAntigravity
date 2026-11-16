<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/db.php';

$pdo = obtenerDB();
$empresaId = 1;

$productos = [
    'ACEITE'         => ['GRANEL'],
    'CAFE CORRIENTE' => ['SACOS'],
    'CAFE GUACUCO'   => ['SACOS'],
    'CAFE HÚMEDO'    => ['SACOS'],
    'CAFE NATURAL'   => ['SACOS'],
    'CAFE ORO SHG'   => ['SACOS'],
    'CAFE RESACA'    => ['SACOS']
];

foreach ($productos as $nombre => $presentaciones) {
    $stmt = $pdo->prepare('SELECT id FROM t_productos WHERE empresa_id = :emp_id AND nombre = :nombre LIMIT 1');
    $stmt->execute([':emp_id' => $empresaId, ':nombre' => $nombre]);
    $existe = $stmt->fetch();
    
    if (!$existe) {
        $ins = $pdo->prepare('INSERT INTO t_productos (empresa_id, nombre, activo) VALUES (:emp_id, :nombre, 1)');
        $ins->execute([':emp_id' => $empresaId, ':nombre' => $nombre]);
        $prodId = (int)$pdo->lastInsertId();
        echo "Insertado producto: $nombre (ID: $prodId)\n";
    } else {
        $prodId = (int)$existe['id'];
        echo "Ya existía producto: $nombre (ID: $prodId)\n";
    }

    foreach ($presentaciones as $pres) {
        $stmtP = $pdo->prepare('SELECT id FROM t_producto_presentaciones WHERE producto_id = :p_id AND nombre = :nombre LIMIT 1');
        $stmtP->execute([':p_id' => $prodId, ':nombre' => $pres]);
        if (!$stmtP->fetch()) {
            $insP = $pdo->prepare('INSERT INTO t_producto_presentaciones (producto_id, nombre, activo) VALUES (:p_id, :nombre, 1)');
            $insP->execute([':p_id' => $prodId, ':nombre' => $pres]);
            echo "  - Presentación agregada: $pres\n";
        }
    }
}
echo "PROCESO_FINALIZADO_CON_EXITO\n";
