<?php
/**
 * Puente Local Ligero hacia el Microservicio de Báscula (:8018)
 * Proyecto: tytBasculaAntigravity
 *
 * Permite que tanto la PC de planta como dispositivos móviles (celulares/tablets)
 * conectados a la Red Local (Wi-Fi LAN) consulten el peso en tiempo real y sincronicen
 * el estado del indicador sin exponer el puerto 8018 fuera de loopback (127.0.0.1).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$esPingActividad = (($_GET['accion'] ?? '') === 'keepalive');
$esLecturaFondo  = !$esPingActividad && (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET');
$usuario = obtenerUsuarioActual(!$esLecturaFondo);
if ($usuario === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada.']);
    exit;
}
// Liberar de inmediato el bloqueo de sesión para no frenar el polling cada 350ms
session_write_close();

if ($esPingActividad) {
    echo json_encode(['ok' => true]);
    exit;
}

if (!esAccesoPlantaLocal()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Disponible únicamente en la red local de planta.']);
    exit;
}

$accion = trim((string)($_GET['accion'] ?? 'peso'));
$metodoHttp = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$esOperadorPlanta = !tieneRol($usuario, ['REMOTO']);

$rutasLectura = [
    'peso'  => '/api/v1/peso',
    'salud' => '/api/v1/salud',
];

$rutasEscritura = [
    'configurar_serial' => '/api/v1/configurar_serial',
    'modo'              => '/api/v1/modo',
    'sim_camion'        => '/api/v1/simulador/camion?peso_bruto=38500',
    'sim_despejar'      => '/api/v1/simulador/despejar',
];

if (isset($rutasLectura[$accion])) {
    $rutaDestino = $rutasLectura[$accion];
    $metodoApi = 'GET';
    $cuerpoRaw = null;
} elseif (isset($rutasEscritura[$accion])) {
    if (!$esOperadorPlanta || $metodoHttp !== 'POST') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Operación no autorizada.']);
        exit;
    }
    $rutaDestino = $rutasEscritura[$accion];
    $metodoApi = 'POST';
    $cuerpoRaw = (string)file_get_contents('php://input');
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción inválida.']);
    exit;
}

$opcionesHttp = [
    'http' => [
        'method'        => $metodoApi,
        'timeout'       => 1.5,
        'ignore_errors' => true,
        'header'        => "Accept: application/json\r\nContent-Type: application/json\r\n",
    ],
];

if ($metodoApi === 'POST') {
    $opcionesHttp['http']['content'] = ($cuerpoRaw !== '' ? $cuerpoRaw : '{}');
}

$respuesta = @file_get_contents('http://127.0.0.1:8018' . $rutaDestino, false, stream_context_create($opcionesHttp));

if ($respuesta === false) {
    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'error' => 'Microservicio 8018 desconectado',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo $respuesta;
