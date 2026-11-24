<?php
/**
 * Control Local del Microservicio de Báscula (:8018) y Lectura COM
 * Proyecto: tytBasculaAntigravity
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

header('Content-Type: application/json; charset=utf-8');

$usuario = requerirAutenticacion();
session_write_close();

$esLocal = esAccesoPlantaLocal();
if (!$esLocal || ($usuario['rol'] ?? '') === 'REMOTO' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Operación no permitida']);
    exit;
}

/**
 * Ejecuta una petición corta contra FastAPI (:8018).
 */
function llamarApiBascula(string $ruta, string $metodo = 'GET', ?array $body = null): ?array
{
    $opts = [
        'http' => [
            'method'        => $metodo,
            'timeout'       => 2.0,
            'ignore_errors' => true,
            'header'        => "Content-Type: application/json\r\n",
        ],
    ];
    if ($body !== null) {
        $opts['http']['content'] = json_encode($body);
    }
    $resp = @file_get_contents('http://127.0.0.1:8018' . $ruta, false, stream_context_create($opts));
    if ($resp === false) {
        return null;
    }
    $json = json_decode($resp, true);
    return is_array($json) ? $json : null;
}

$accion = trim((string)($_GET['accion'] ?? ''));

if ($accion === 'parar') {
    $res = llamarApiBascula('/api/v1/lector/detener', 'POST', []);
    echo json_encode([
        'ok'             => true,
        'lector_activo'  => false,
        'pausado_manual' => true,
        'mensaje'        => $res ? 'Lectura detenida. Puerto COM liberado.' : 'Microservicio 8018 no estaba activo.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === 'arrancar') {
    $salud = llamarApiBascula('/api/v1/salud');
    if (!$salud) {
        $raiz = dirname(__DIR__, 2);
        $python = $raiz . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'python.exe';
        if (!is_file($python)) {
            $python = $raiz . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
        }
        if (!is_file($python)) {
            echo json_encode(['ok' => false, 'error' => 'No se encontró Python para iniciar el servicio :8018.']);
            exit;
        }

        $psCmd = sprintf(
            'powershell -NoProfile -Command "Start-Process -FilePath %s -ArgumentList \'-B\', \'-m\', \'uvicorn\', \'api.main:app\', \'--host\', \'127.0.0.1\', \'--port\', \'8018\' -WorkingDirectory %s -WindowStyle Hidden"',
            escapeshellarg($python),
            escapeshellarg($raiz)
        );
        @pclose(@popen($psCmd, 'r'));

        for ($i = 0; $i < 12; $i++) {
            usleep(350000);
            $salud = llamarApiBascula('/api/v1/salud');
            if ($salud) {
                break;
            }
        }
    }

    if (!$salud) {
        echo json_encode(['ok' => false, 'error' => 'No se pudo iniciar el microservicio FastAPI (:8018).']);
        exit;
    }

    // Cargar parámetros reales de la PC desde MariaDB
    $pdo = obtenerDB();
    $stmt = $pdo->prepare("SELECT modo, puerto_com, baudios FROM t_configuracion_bascula WHERE empresa_id = :eid AND estacion = 'PRINCIPAL' LIMIT 1");
    $stmt->execute([':eid' => (int)($usuario['empresa_id'] ?? 1)]);
    $cfg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $puerto  = (string)($cfg['puerto_com'] ?? '');
    $baudios = (int)($cfg['baudios'] ?? 9600);
    $modo    = (($cfg['modo'] ?? 'SERIAL') === 'SERIAL') ? 'real' : 'simulado';

    if ($puerto !== '') {
        llamarApiBascula('/api/v1/configurar_serial', 'POST', ['puerto_com' => $puerto, 'baudios' => $baudios]);
    }
    llamarApiBascula('/api/v1/modo', 'POST', ['modo' => $modo]);
    $res = llamarApiBascula('/api/v1/lector/iniciar', 'POST', []);

    echo json_encode([
        'ok'             => true,
        'lector_activo'  => (bool)($res['lector_activo'] ?? false),
        'pausado_manual' => false,
        'puerto_com'     => $puerto,
        'modo'           => $modo,
        'mensaje'        => $puerto !== '' ? "Lectura iniciada en {$puerto} ({$modo})." : 'Microservicio iniciado.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acción no válida']);
