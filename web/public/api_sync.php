<?php
/**
 * Receptor HTTPS de Sincronización desde Planta Local → Nube (Hostinger)
 * Proyecto: tytBasculaAntigravity
 *
 * Recibe lotes JSON firmados con X-Bascula-Token desde la PC de producción
 * e inserta/actualiza de forma idempotente catálogos y pesadas.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$tokenConfigurado = defined('SYNC_TOKEN') ? (string)SYNC_TOKEN : (string)(getenv('BASCULA_SYNC_TOKEN') ?: '');
$tokenRecibido    = trim((string)($_SERVER['HTTP_X_BASCULA_TOKEN'] ?? ''));

if ($tokenConfigurado === '' || $tokenRecibido === '' || !hash_equals($tokenConfigurado, $tokenRecibido)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token de sincronización inválido o no configurado en el servidor remoto.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode((string)$rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Payload JSON inválido.']);
    exit;
}

// Tablas permitidas para sincronización idempotente (excluye configuración serial local)
$tablasPermitidas = [
    't_empresas',
    't_usuarios',
    't_categorias',
    't_presentaciones',
    't_productos',
    't_producto_presentaciones',
    't_taras',
    't_clie_prov',
    't_pesadas',
    't_pesada_anulaciones',
];

try {
    $pdo = obtenerDB();
    $pdo->beginTransaction();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    $resumen = [];
    $tablasDatos = $payload['tablas'] ?? [];

    if (is_array($tablasDatos)) {
        foreach ($tablasPermitidas as $tabla) {
            if (empty($tablasDatos[$tabla]) || !is_array($tablasDatos[$tabla])) {
                continue;
            }

            // Obtener columnas reales de la tabla en el servidor remoto
            $colsRemotas = $pdo->query("SHOW COLUMNS FROM `{$tabla}`")->fetchAll(PDO::FETCH_COLUMN);
            if (!$colsRemotas) {
                continue;
            }
            $mapaCols = array_flip($colsRemotas);
            $procesados = 0;

            foreach ($tablasDatos[$tabla] as $fila) {
                if (!is_array($fila)) {
                    continue;
                }
                $filaFiltrada = array_intersect_key($fila, $mapaCols);
                if (empty($filaFiltrada)) {
                    continue;
                }

                $columnas = array_keys($filaFiltrada);
                $colsSql = array_map(fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', $columnas);
                $placeholders = array_map(fn($c) => ':' . $c, $columnas);
                $updates = array_map(fn($c) => "`{$c}` = VALUES(`{$c}`)", $columnas);

                $sql = sprintf(
                    'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                    $tabla,
                    implode(', ', $colsSql),
                    implode(', ', $placeholders),
                    implode(', ', $updates)
                );

                $stmt = $pdo->prepare($sql);
                foreach ($filaFiltrada as $col => $val) {
                    $stmt->bindValue(':' . $col, $val);
                }
                $stmt->execute();
                $procesados++;
            }

            $resumen[$tabla] = $procesados;
        }
    }

    // Sincronizar bajas de presentaciones de productos editados en catálogo local
    if (!empty($payload['reemplazar_producto_presentaciones']) && isset($tablasDatos['t_producto_presentaciones']) && is_array($tablasDatos['t_producto_presentaciones'])) {
        $idsPresVigentes = array_values(array_filter(array_map(
            static fn($f) => isset($f['id']) ? (int)$f['id'] : 0,
            $tablasDatos['t_producto_presentaciones']
        )));
        if (!empty($idsPresVigentes)) {
            $phPres = implode(',', array_fill(0, count($idsPresVigentes), '?'));
            $stmtDelPres = $pdo->prepare("DELETE FROM `t_producto_presentaciones` WHERE `id` NOT IN ({$phPres})");
            $stmtDelPres->execute($idsPresVigentes);
        } else {
            $pdo->exec('DELETE FROM `t_producto_presentaciones`');
        }
    }

    // Si es una sincronización completa (espejo exacto), purgar pesadas eliminadas en planta local
    if (!empty($payload['reemplazar_pesadas']) && isset($tablasDatos['t_pesadas']) && is_array($tablasDatos['t_pesadas'])) {
        $idsVigentes = array_values(array_filter(array_map(
            static fn($f) => isset($f['idpesada']) ? (int)$f['idpesada'] : 0,
            $tablasDatos['t_pesadas']
        )));
        if (!empty($idsVigentes)) {
            $phIds = implode(',', array_fill(0, count($idsVigentes), '?'));
            $stmtDelAnul = $pdo->prepare("DELETE FROM `t_pesada_anulaciones` WHERE `idpesada` NOT IN ({$phIds})");
            $stmtDelAnul->execute($idsVigentes);
            $stmtDelPes = $pdo->prepare("DELETE FROM `t_pesadas` WHERE `idpesada` NOT IN ({$phIds})");
            $stmtDelPes->execute($idsVigentes);
            $resumen['t_pesadas_eliminadas'] = $stmtDelPes->rowCount();
        } else {
            $pdo->exec('DELETE FROM `t_pesada_anulaciones`');
            $resumen['t_pesadas_eliminadas'] = (int)$pdo->exec('DELETE FROM `t_pesadas`');
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->commit();

    echo json_encode([
        'ok'        => true,
        'mensaje'   => 'Sincronización aplicada correctamente en Hostinger.',
        'resumen'   => $resumen,
        'timestamp' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Error al sincronizar en base de datos remota: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
