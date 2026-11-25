<?php
/**
 * Cliente Local de Sincronización Offline-First hacia Hostinger
 * Proyecto: tytBasculaAntigravity
 *
 * Envía pesadas y catálogos desde la PC local hacia inversionesvallecillo.com.
 * Si no hay internet o el servidor remoto no responde, encola el evento en
 * t_sincronizacion_pendiente sin bloquear ni retrasar la operación en planta.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

/**
 * Carga la configuración local de sincronización si existe.
 *
 * @return array{url: string, token: string, habilitado: bool}
 */
function obtenerConfigSyncNube(): array
{
    $archivo = dirname(__DIR__) . '/config/sync_remoto.php';
    if (is_file($archivo)) {
        require_once $archivo;
    }

    $url   = defined('SYNC_REMOTE_URL') ? trim((string)SYNC_REMOTE_URL) : 'https://inversionesvallecillo.com/bascula/api_sync.php';
    $token = defined('SYNC_TOKEN') ? trim((string)SYNC_TOKEN) : '';

    return [
        'url'        => $url,
        'token'      => $token,
        'habilitado' => ($url !== '' && $token !== ''),
    ];
}

/**
 * Genera un UUID v4 estándar para eventos de la cola local.
 */
function generarUuidSync(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

/**
 * Registra una pesada pendiente en la cola local cuando no hay conexión.
 */
function encolarPesadaPendiente(PDO $pdo, int $idPesada): void
{
    try {
        $stmtCheck = $pdo->prepare("
            SELECT id_evento FROM t_sincronizacion_pendiente
            WHERE entidad = 't_pesadas' AND entidad_id = :id AND enviado_at IS NULL
            LIMIT 1
        ");
        $stmtCheck->execute([':id' => (string)$idPesada]);
        if ($stmtCheck->fetchColumn()) {
            return;
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO t_sincronizacion_pendiente (id_evento, entidad, entidad_id, accion, datos)
            VALUES (:uuid, 't_pesadas', :id, 'UPSERT', :datos)
        ");
        $stmtIns->execute([
            ':uuid'  => generarUuidSync(),
            ':id'    => (string)$idPesada,
            ':datos' => json_encode(['idpesada' => $idPesada]),
        ]);
    } catch (Throwable $e) {
        // Silencioso para no interrumpir la operación local
    }
}

/**
 * Registra un cambio pendiente de catálogos/usuarios/empresa cuando no hay conexión.
 */
function encolarCatalogosPendientes(PDO $pdo, string $origen = 'catalogos'): void
{
    try {
        $stmtCheck = $pdo->query("
            SELECT id_evento FROM t_sincronizacion_pendiente
            WHERE entidad = 'catalogos' AND enviado_at IS NULL
            LIMIT 1
        ");
        if ($stmtCheck && $stmtCheck->fetchColumn()) {
            return;
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO t_sincronizacion_pendiente (id_evento, entidad, entidad_id, accion, datos)
            VALUES (:uuid, 'catalogos', 'ALL', 'UPSERT', :datos)
        ");
        $stmtIns->execute([
            ':uuid'  => generarUuidSync(),
            ':datos' => json_encode(['origen' => $origen]),
        ]);
    } catch (Throwable $e) {
        // Silencioso para no interrumpir la operación local
    }
}

/**
 * Obtiene el bloque base de las 8 tablas maestras de catálogos y administración.
 */
function cargarTablasMaestrasSync(PDO $pdo): array
{
    return [
        't_empresas'                => $pdo->query('SELECT * FROM t_empresas')->fetchAll(PDO::FETCH_ASSOC),
        // Excluir usuarios OPERADOR (pesadores locales con claves rápidas) por seguridad en la nube
        't_usuarios'                => $pdo->query("SELECT * FROM t_usuarios WHERE rol <> 'OPERADOR'")->fetchAll(PDO::FETCH_ASSOC),
        't_categorias'              => $pdo->query('SELECT * FROM t_categorias')->fetchAll(PDO::FETCH_ASSOC),
        't_presentaciones'          => $pdo->query('SELECT * FROM t_presentaciones')->fetchAll(PDO::FETCH_ASSOC),
        't_productos'               => $pdo->query('SELECT * FROM t_productos')->fetchAll(PDO::FETCH_ASSOC),
        't_producto_presentaciones' => $pdo->query('SELECT * FROM t_producto_presentaciones')->fetchAll(PDO::FETCH_ASSOC),
        't_taras'                   => $pdo->query('SELECT * FROM t_taras')->fetchAll(PDO::FETCH_ASSOC),
        't_clie_prov'               => $pdo->query('SELECT * FROM t_clie_prov')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/**
 * Envía un payload de tablas al endpoint HTTPS remoto (api_sync.php).
 *
 * @return array{ok: bool, mensaje: string, resumen?: array}
 */
function enviarPayloadSyncNube(array $tablas, int $timeoutSeg = 4, array $opciones = []): array
{
    $cfg = obtenerConfigSyncNube();
    if (!$cfg['habilitado']) {
        return ['ok' => false, 'mensaje' => 'Sincronización aún no configurada en este equipo.'];
    }

    $jsonBody = json_encode(array_merge(['tablas' => $tablas], $opciones), JSON_UNESCAPED_UNICODE);
    if ($jsonBody === false) {
        return ['ok' => false, 'mensaje' => 'No se pudo serializar el lote de datos.'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($cfg['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeg,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'X-Bascula-Token: ' . $cfg['token'],
            ],
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'mensaje' => 'Sin conexión con el servidor remoto: ' . $err];
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => $timeoutSeg,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json; charset=utf-8\r\n"
                                 . 'X-Bascula-Token: ' . $cfg['token'] . "\r\n",
                'content'       => $jsonBody,
            ],
        ]);
        $resp = @file_get_contents($cfg['url'], false, $ctx);
        $httpCode = 200;
        if ($resp === false) {
            return ['ok' => false, 'mensaje' => 'No se pudo contactar con ' . $cfg['url']];
        }
    }

    $data = json_decode((string)$resp, true);
    if (is_array($data) && !empty($data['ok'])) {
        return [
            'ok'      => true,
            'mensaje' => (string)($data['mensaje'] ?? 'Sincronizado correctamente'),
            'resumen' => (array)($data['resumen'] ?? []),
        ];
    }

    $detalleError = is_array($data) && isset($data['error'])
        ? (string)$data['error']
        : ('HTTP ' . $httpCode . ' — ' . substr(strip_tags((string)$resp), 0, 120));

    return ['ok' => false, 'mensaje' => $detalleError];
}

/**
 * Sincroniza de inmediato una pesada recién guardada, cerrada o anulada (incluyendo t_pesada_anulaciones).
 * Nunca lanza excepciones ni bloquea el flujo local.
 */
function sincronizarPesadaAutomatica(int $idPesada): void
{
    if (function_exists('esAccesoPlantaLocal') && !esAccesoPlantaLocal()) {
        return;
    }

    try {
        $pdo = obtenerDB();
        $cfg = obtenerConfigSyncNube();
        if (!$cfg['habilitado']) {
            encolarPesadaPendiente($pdo, $idPesada);
            return;
        }

        // Recolectar la pesada actual + pendientes previas (máx 15 por lote rápido)
        $ids = [$idPesada];
        $stmtPend = $pdo->query("
            SELECT entidad_id FROM t_sincronizacion_pendiente
            WHERE entidad = 't_pesadas' AND enviado_at IS NULL
            ORDER BY creado_at ASC LIMIT 15
        ");
        foreach ($stmtPend->fetchAll(PDO::FETCH_COLUMN) as $idPend) {
            $idInt = (int)$idPend;
            if ($idInt > 0 && !in_array($idInt, $ids, true)) {
                $ids[] = $idInt;
            }
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtPesadas = $pdo->prepare("SELECT * FROM t_pesadas WHERE idpesada IN ({$placeholders})");
        $stmtPesadas->execute($ids);
        $filasPesadas = $stmtPesadas->fetchAll(PDO::FETCH_ASSOC);

        if (empty($filasPesadas)) {
            return;
        }

        $stmtAnul = $pdo->prepare("SELECT * FROM t_pesada_anulaciones WHERE idpesada IN ({$placeholders})");
        $stmtAnul->execute($ids);
        $filasAnulaciones = $stmtAnul->fetchAll(PDO::FETCH_ASSOC);

        $tablas = cargarTablasMaestrasSync($pdo);
        $tablas['t_pesadas'] = $filasPesadas;
        if (!empty($filasAnulaciones)) {
            $tablas['t_pesada_anulaciones'] = $filasAnulaciones;
        }

        $resultado = enviarPayloadSyncNube($tablas, 3, ['reemplazar_producto_presentaciones' => true]);
        if ($resultado['ok']) {
            $stmtOk = $pdo->prepare("
                UPDATE t_sincronizacion_pendiente
                SET enviado_at = NOW(), intentos = intentos + 1
                WHERE (
                    (entidad = 't_pesadas' AND entidad_id IN ({$placeholders}))
                    OR entidad = 'catalogos'
                ) AND enviado_at IS NULL
            ");
            $stmtOk->execute(array_map('strval', $ids));
        } else {
            encolarPesadaPendiente($pdo, $idPesada);
            $stmtFail = $pdo->prepare("
                UPDATE t_sincronizacion_pendiente
                SET intentos = intentos + 1
                WHERE entidad = 't_pesadas' AND entidad_id IN ({$placeholders}) AND enviado_at IS NULL
            ");
            $stmtFail->execute(array_map('strval', $ids));
        }
    } catch (Throwable $e) {
        // Silencioso en pesaje local
    }
}

/**
 * Sincroniza de inmediato cualquier cambio guardado en catálogos, empresa o usuarios.
 * Si no hay internet, deja marca en t_sincronizacion_pendiente para reintento automático.
 */
function sincronizarCatalogosAutomatico(string $origen = 'catalogos'): void
{
    if (function_exists('esAccesoPlantaLocal') && !esAccesoPlantaLocal()) {
        return;
    }

    try {
        $pdo = obtenerDB();
        $cfg = obtenerConfigSyncNube();
        if (!$cfg['habilitado']) {
            encolarCatalogosPendientes($pdo, $origen);
            return;
        }

        $tablas = cargarTablasMaestrasSync($pdo);

        // Aprovechar para incluir pesadas pendientes si hubiera en cola
        $stmtPend = $pdo->query("
            SELECT entidad_id FROM t_sincronizacion_pendiente
            WHERE entidad = 't_pesadas' AND enviado_at IS NULL
            ORDER BY creado_at ASC LIMIT 15
        ");
        $ids = array_values(array_unique(array_filter(array_map('intval', $stmtPend->fetchAll(PDO::FETCH_COLUMN)))));
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmtPes = $pdo->prepare("SELECT * FROM t_pesadas WHERE idpesada IN ({$ph})");
            $stmtPes->execute($ids);
            $filasPes = $stmtPes->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($filasPes)) {
                $tablas['t_pesadas'] = $filasPes;
            }
            $stmtAnul = $pdo->prepare("SELECT * FROM t_pesada_anulaciones WHERE idpesada IN ({$ph})");
            $stmtAnul->execute($ids);
            $filasAnul = $stmtAnul->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($filasAnul)) {
                $tablas['t_pesada_anulaciones'] = $filasAnul;
            }
        }

        $resultado = enviarPayloadSyncNube($tablas, 3, ['reemplazar_producto_presentaciones' => true]);
        if ($resultado['ok']) {
            if (!empty($ids)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmtOk = $pdo->prepare("
                    UPDATE t_sincronizacion_pendiente
                    SET enviado_at = NOW(), intentos = intentos + 1
                    WHERE (
                        (entidad = 't_pesadas' AND entidad_id IN ({$ph}))
                        OR entidad = 'catalogos'
                    ) AND enviado_at IS NULL
                ");
                $stmtOk->execute(array_map('strval', $ids));
            } else {
                $pdo->exec("
                    UPDATE t_sincronizacion_pendiente
                    SET enviado_at = NOW(), intentos = intentos + 1
                    WHERE entidad = 'catalogos' AND enviado_at IS NULL
                ");
            }
        } else {
            encolarCatalogosPendientes($pdo, $origen);
            $pdo->exec("
                UPDATE t_sincronizacion_pendiente
                SET intentos = intentos + 1
                WHERE entidad = 'catalogos' AND enviado_at IS NULL
            ");
        }
    } catch (Throwable $e) {
        // Silencioso para no bloquear guardado local
    }
}

/**
 * Sincronización completa manual (catálogos + todas las pesadas) desde Despliegue / Nube.
 *
 * @return array{ok: bool, mensaje: string, resumen?: array}
 */
function sincronizarTodoConNube(PDO $pdo): array
{
    $tablas = cargarTablasMaestrasSync($pdo);
    $tablas['t_pesadas']            = $pdo->query('SELECT * FROM t_pesadas')->fetchAll(PDO::FETCH_ASSOC);
    $tablas['t_pesada_anulaciones'] = $pdo->query('SELECT * FROM t_pesada_anulaciones')->fetchAll(PDO::FETCH_ASSOC);

    $res = enviarPayloadSyncNube($tablas, 12, [
        'reemplazar_pesadas'                 => true,
        'reemplazar_producto_presentaciones' => true,
    ]);
    if ($res['ok']) {
        $pdo->exec('UPDATE t_sincronizacion_pendiente SET enviado_at = NOW(), intentos = intentos + 1 WHERE enviado_at IS NULL');
    }
    return $res;
}

/**
 * Procesa los eventos encolados en t_sincronizacion_pendiente (pesadas y/o catálogos).
 * Usado por el reintento automático cada 5 minutos en el equipo local.
 *
 * @return array{ok: bool, pendientes: int, enviados: int}
 */
function procesarPendientesSyncNube(PDO $pdo): array
{
    $cfg = obtenerConfigSyncNube();
    if (!$cfg['habilitado']) {
        return ['ok' => false, 'pendientes' => 0, 'enviados' => 0];
    }

    $stmtCatPend = $pdo->query("
        SELECT COUNT(*) FROM t_sincronizacion_pendiente
        WHERE entidad = 'catalogos' AND enviado_at IS NULL
    ");
    $hayCatalogosPendientes = ((int)($stmtCatPend ? $stmtCatPend->fetchColumn() : 0)) > 0;

    $stmtPend = $pdo->query("
        SELECT entidad_id FROM t_sincronizacion_pendiente
        WHERE entidad = 't_pesadas' AND enviado_at IS NULL
        ORDER BY creado_at ASC LIMIT 20
    ");
    $ids = array_values(array_unique(array_filter(array_map('intval', $stmtPend->fetchAll(PDO::FETCH_COLUMN)))));

    if (empty($ids) && !$hayCatalogosPendientes) {
        return ['ok' => true, 'pendientes' => 0, 'enviados' => 0];
    }

    $tablas = cargarTablasMaestrasSync($pdo);
    $filasPesadas = [];

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtPesadas = $pdo->prepare("SELECT * FROM t_pesadas WHERE idpesada IN ({$placeholders})");
        $stmtPesadas->execute($ids);
        $filasPesadas = $stmtPesadas->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($filasPesadas)) {
            $tablas['t_pesadas'] = $filasPesadas;
        }

        $stmtAnul = $pdo->prepare("SELECT * FROM t_pesada_anulaciones WHERE idpesada IN ({$placeholders})");
        $stmtAnul->execute($ids);
        $filasAnul = $stmtAnul->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($filasAnul)) {
            $tablas['t_pesada_anulaciones'] = $filasAnul;
        }
    }

    if (empty($filasPesadas) && !$hayCatalogosPendientes) {
        if (!empty($ids)) {
            // Limpiar IDs huérfanos en cola cuyas pesadas ya no existan localmente
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtClean = $pdo->prepare("
                UPDATE t_sincronizacion_pendiente
                SET enviado_at = NOW()
                WHERE entidad = 't_pesadas' AND entidad_id IN ({$placeholders}) AND enviado_at IS NULL
            ");
            $stmtClean->execute(array_map('strval', $ids));
        }
        return ['ok' => true, 'pendientes' => 0, 'enviados' => 0];
    }

    $res = enviarPayloadSyncNube($tablas, 5, ['reemplazar_producto_presentaciones' => true]);
    if ($res['ok']) {
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtOk = $pdo->prepare("
                UPDATE t_sincronizacion_pendiente
                SET enviado_at = NOW(), intentos = intentos + 1
                WHERE (
                    (entidad = 't_pesadas' AND entidad_id IN ({$placeholders}))
                    OR entidad = 'catalogos'
                ) AND enviado_at IS NULL
            ");
            $stmtOk->execute(array_map('strval', $ids));
        } else {
            $pdo->exec("
                UPDATE t_sincronizacion_pendiente
                SET enviado_at = NOW(), intentos = intentos + 1
                WHERE entidad = 'catalogos' AND enviado_at IS NULL
            ");
        }
        return ['ok' => true, 'pendientes' => 0, 'enviados' => count($filasPesadas) + ($hayCatalogosPendientes ? 1 : 0)];
    }

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtFail = $pdo->prepare("
            UPDATE t_sincronizacion_pendiente
            SET intentos = intentos + 1
            WHERE (
                (entidad = 't_pesadas' AND entidad_id IN ({$placeholders}))
                OR entidad = 'catalogos'
            ) AND enviado_at IS NULL
        ");
        $stmtFail->execute(array_map('strval', $ids));
    } else {
        $pdo->exec("
            UPDATE t_sincronizacion_pendiente
            SET intentos = intentos + 1
            WHERE entidad = 'catalogos' AND enviado_at IS NULL
        ");
    }

    return ['ok' => false, 'pendientes' => count($ids) + ($hayCatalogosPendientes ? 1 : 0), 'enviados' => 0];
}
