<?php
/**
 * Endpoint Local para Reintento Automático de Sincronización Pendiente (cada 5 min)
 * Proyecto: tytBasculaAntigravity
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/sync_nube.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = requerirAutenticacion();
session_write_close();

$hostActual = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
$esLocal = in_array($hostActual, ['localhost', '127.0.0.1', '::1'], true);
if (!$esLocal || ($usuario['rol'] ?? '') === 'REMOTO') {
    echo json_encode(['ok' => false, 'omitido' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $resultado = procesarPendientesSyncNube(obtenerDB());
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Error al procesar pendientes']);
}
