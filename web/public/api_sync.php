<?php
/**
 * Receptor HTTPS de Sincronización desde Planta Local → Nube (Hostinger)
 * Proyecto: tytBasculaAntigravity
 *
 * Recibe lotes JSON firmados con X-Bascula-Token desde la PC de producción
 * e inserta/actualiza de forma idempotente catálogos y pesadas.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$ipRemota = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$claveRateSync = 'sync_fail:' . $ipRemota;
if (!verificarTasaLimite($claveRateSync, 5, 900)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Demasiados intentos fallidos. IP bloqueada temporalmente por 15 minutos.']);
    exit;
}

$tokenConfigurado = defined('SYNC_TOKEN') ? (string)SYNC_TOKEN : (string)(getenv('BASCULA_SYNC_TOKEN') ?: '');
$tokenRecibido    = trim((string)($_SERVER['HTTP_X_BASCULA_TOKEN'] ?? ''));

if ($tokenConfigurado === '' || $tokenRecibido === '' || !hash_equals($tokenConfigurado, $tokenRecibido)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token de sincronización inválido o no configurado en el servidor remoto.']);
    exit;
}

// Token válido: limpiar contador de fallos de esta IP para nunca limitar envíos legítimos de planta
limpiarTasaLimite($claveRateSync);

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
    $tablasDatos = $payload['tablas'] ?? [];

    // Auto-migraciones idempotentes de esquema remoto ANTES de abrir transacción (DDL hace commit implícito en MySQL/MariaDB)
    if (is_array($tablasDatos)) {
        if (!empty($tablasDatos['t_usuarios'])) {
            try {
                $pdo->exec('ALTER TABLE `t_usuarios` ADD COLUMN IF NOT EXISTS `clave_visible` VARCHAR(512) DEFAULT NULL AFTER `clave_hash`');
            } catch (Throwable $eAlterUsr) {
                // Ignorar si ya existe o no tiene privilegio ALTER
            }
        }

        if (!empty($tablasDatos['t_pesadas'])) {
            try {
                $createPesRow = $pdo->query('SHOW CREATE TABLE `t_pesadas`')->fetch(PDO::FETCH_NUM);
                $ddlPesadas = (string)($createPesRow[1] ?? '');
                if ($ddlPesadas !== '' && stripos($ddlPesadas, 'chk_pesadas_descuentos') !== false && stripos($ddlPesadas, 'CEILING') === false) {
                    try {
                        $pdo->exec('ALTER TABLE `t_pesadas` DROP CONSTRAINT IF EXISTS `chk_pesadas_pesos`');
                        $pdo->exec('ALTER TABLE `t_pesadas` DROP CONSTRAINT IF EXISTS `chk_pesadas_descuentos`');
                    } catch (Throwable $eDrop) {
                        // Compatibilidad MySQL 8 si no es MariaDB
                        try { $pdo->exec('ALTER TABLE `t_pesadas` DROP CHECK `chk_pesadas_pesos`'); } catch (Throwable $e1) {}
                        try { $pdo->exec('ALTER TABLE `t_pesadas` DROP CHECK `chk_pesadas_descuentos`'); } catch (Throwable $e2) {}
                    }

                    $pdo->exec("
                        ALTER TABLE `t_pesadas`
                            ADD CONSTRAINT `chk_pesadas_pesos` CHECK (
                                peso_bruto_lb >= 0
                                AND tara_vehiculo_lb >= 0
                                AND peso_final_lb >= 0
                                AND (peso_bruto_lb IS NULL OR tara_vehiculo_lb IS NULL OR peso_bruto_lb >= tara_vehiculo_lb)
                                AND (
                                    peso_final_lb IS NULL
                                    OR peso_final_lb <= peso_bruto_lb - tara_vehiculo_lb - CASE
                                        WHEN calculo_libras_enteras THEN LEAST(
                                            ROUND(cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0), 0),
                                            CEILING(ROUND(cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0), 4))
                                        )
                                        ELSE cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0)
                                    END
                                )
                                AND calculo_libras_enteras IN (0, 1)
                                AND (calculo_libras_enteras = 0 OR (peso_bruto_lb MOD 1 = 0 AND tara_vehiculo_lb MOD 1 = 0 AND peso_final_lb MOD 1 = 0))
                            ),
                            ADD CONSTRAINT `chk_pesadas_descuentos` CHECK (
                                (base_descuentos_lb IS NULL AND descuento_humedad_lb IS NULL AND descuento_defectos_lb IS NULL)
                                OR (
                                    base_descuentos_lb IS NOT NULL
                                    AND descuento_humedad_lb IS NOT NULL
                                    AND descuento_defectos_lb IS NOT NULL
                                    AND calculo_libras_enteras = 1
                                    AND estado IN ('CERRADA', 'ANULADA')
                                    AND segunda_pesada_at IS NOT NULL
                                    AND base_descuentos_lb >= 0
                                    AND descuento_humedad_lb >= 0
                                    AND descuento_defectos_lb >= 0
                                    AND base_descuentos_lb MOD 1 = 0
                                    AND descuento_humedad_lb MOD 1 = 0
                                    AND descuento_defectos_lb MOD 1 = 0
                                    AND ajuste_humedad_pct + descuento_calidad_pct <= 100
                                    AND base_descuentos_lb IN (
                                        peso_bruto_lb - tara_vehiculo_lb - CEILING(ROUND(cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0), 4)),
                                        peso_bruto_lb - tara_vehiculo_lb - ROUND(cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0), 0)
                                    )
                                    AND descuento_humedad_lb IN (
                                        CEILING(ROUND(base_descuentos_lb * ajuste_humedad_pct / 100, 4)),
                                        ROUND(base_descuentos_lb * ajuste_humedad_pct / 100, 0)
                                    )
                                    AND descuento_defectos_lb IN (
                                        CEILING(ROUND(base_descuentos_lb * descuento_calidad_pct / 100, 4)),
                                        ROUND(base_descuentos_lb * descuento_calidad_pct / 100, 0)
                                    )
                                    AND peso_final_lb = base_descuentos_lb - descuento_humedad_lb - descuento_defectos_lb
                                )
                            )
                    ");
                }
            } catch (Throwable $eAlterChk) {
                // Continuar si el motor no requiere o no permite cambiar el CHECK
            }
        }
    }

    $pdo->beginTransaction();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    $resumen = [];

    if (is_array($tablasDatos)) {
        foreach ($tablasPermitidas as $tabla) {
            if (empty($tablasDatos[$tabla]) || !is_array($tablasDatos[$tabla])) {
                continue;
            }

            if ($tabla === 't_usuarios') {
                // Garantizar que ningún usuario pesador (OPERADOR) exista en la base de datos de la nube
                $pdo->exec("DELETE FROM `t_usuarios` WHERE `rol` = 'OPERADOR'");
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
                if ($tabla === 't_usuarios' && strtoupper(trim((string)($fila['rol'] ?? ''))) === 'OPERADOR') {
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
