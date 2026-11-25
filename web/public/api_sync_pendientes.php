<?php
/**
 * Endpoint Local para Reintento Automático de Sincronización Pendiente (cada 5 min)
 * Proyecto: tytBasculaAntigravity
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';
require_once dirname(__DIR__) . '/src/sync_nube.php';

header('Content-Type: application/json; charset=utf-8');

$esSyncManual = (trim((string)($_GET['modo'] ?? 'pendientes')) === 'todo');
$usuario = obtenerUsuarioActual($esSyncManual);
if ($usuario === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada']);
    exit;
}
session_write_close();

$esLocal = esAccesoPlantaLocal();
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
    $modo = trim((string)($_GET['modo'] ?? 'pendientes'));
    $resultado = ($modo === 'todo')
        ? sincronizarTodoConNube(obtenerDB())
        : procesarPendientesSyncNube(obtenerDB());
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Error al procesar pendientes']);
}
