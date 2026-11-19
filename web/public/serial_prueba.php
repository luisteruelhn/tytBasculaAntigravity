<?php
/**
 * Prueba en Vivo del Puerto Serial (SSE)
 * Proyecto: tytBasculaAntigravity
 *
 * Libera temporalmente el lector de FastAPI (:8018), ejecuta el script de
 * diagnóstico serial en modo solo lectura y transmite los eventos al navegador.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

$usuarioActual = requerirAutenticacion();
if (tieneRol($usuarioActual, ['REMOTO'])) {
    http_response_code(403);
    exit('Acceso denegado');
}
// Liberar el bloqueo de sesión antes de iniciar el stream SSE
session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

/**
 * Envía un evento SSE en formato JSON.
 */
function enviarEventoSSE(array $payload): void
{
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

/**
 * Envía una petición POST corta al microservicio local FastAPI (:8018).
 */
function notificarLectorApi(string $endpoint): void
{
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'timeout' => 1.5,
            'header'  => "Content-Type: application/json\r\n",
            'content' => '{}',
        ],
    ]);
    @file_get_contents('http://127.0.0.1:8018' . $endpoint, false, $ctx);
}

$puertoCom    = strtoupper(trim((string)($_GET['puerto_com'] ?? '')));
$baudios      = trim((string)($_GET['baudios'] ?? '9600'));
$bitsDatos    = trim((string)($_GET['bits_datos'] ?? '8'));
$paridad      = strtoupper(trim((string)($_GET['paridad'] ?? 'N')));
$bitsParada   = trim((string)($_GET['bits_parada'] ?? '1'));
$controlFlujo = strtoupper(trim((string)($_GET['control_flujo'] ?? 'NINGUNO')));

if (!preg_match('/^COM[1-9][0-9]{0,2}$/', $puertoCom)) {
    enviarEventoSSE(['ok' => false, 'estado' => 'error', 'mensaje' => 'Puerto COM inválido.']);
    exit;
}

if (!in_array($bitsParada, ['1', '1.5', '2'], true)) {
    $bitsParada = rtrim(rtrim($bitsParada, '0'), '.');
    if (!in_array($bitsParada, ['1', '1.5', '2'], true)) {
        $bitsParada = '1';
    }
}

$raizProyecto = dirname(__DIR__, 2);
$pythonExe = $raizProyecto . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'python.exe';
if (!is_file($pythonExe)) {
    $pythonExe = $raizProyecto . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
}
$scriptPrueba = $raizProyecto . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'probar_lectura_serial.py';

if (!is_file($pythonExe) || !is_file($scriptPrueba)) {
    enviarEventoSSE(['ok' => false, 'estado' => 'error', 'mensaje' => 'No se encontró el intérprete Python o el script de prueba.']);
    exit;
}

set_time_limit(45);
notificarLectorApi('/api/v1/lector/detener');

$cmd = escapeshellarg($pythonExe) . ' -u ' . escapeshellarg($scriptPrueba);
$pipes = [];
$proceso = @proc_open($cmd, [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, $raizProyecto);

if (!is_resource($proceso)) {
    notificarLectorApi('/api/v1/lector/iniciar');
    enviarEventoSSE(['ok' => false, 'estado' => 'error', 'mensaje' => 'No se pudo iniciar la prueba serial.']);
    exit;
}

try {
    $entrada = json_encode([
        'puerto_com'    => $puertoCom,
        'baudios'       => $baudios,
        'bits_datos'    => $bitsDatos,
        'paridad'       => $paridad,
        'bits_parada'   => $bitsParada,
        'control_flujo' => $controlFlujo,
    ]);
    fwrite($pipes[0], $entrada ?: '{}');
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], true);
    while (!feof($pipes[1])) {
        if (connection_aborted()) {
            break;
        }
        $linea = fgets($pipes[1]);
        if ($linea === false) {
            break;
        }
        $linea = trim($linea);
        if ($linea !== '') {
            echo 'data: ' . $linea . "\n\n";
            @flush();
        }
    }
} finally {
    foreach ([1, 2] as $idx) {
        if (isset($pipes[$idx]) && is_resource($pipes[$idx])) {
            fclose($pipes[$idx]);
        }
    }
    if (is_resource($proceso)) {
        @proc_terminate($proceso);
        @proc_close($proceso);
    }
    notificarLectorApi('/api/v1/lector/iniciar');
}
