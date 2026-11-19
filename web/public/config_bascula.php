<?php
/**
 * Configuración de Báscula e Indicador Serial
 * Proyecto: tytBasculaAntigravity
 *
 * Administra los parámetros físicos de comunicación COM, modo Real/Simulado,
 * y pruebas de conexión para el pesaje industrial.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
if (tieneRol($usuarioActual, ['REMOTO'])) {
    http_response_code(403);
    die('Acceso denegado: el usuario REMOTO es de solo consulta.');
}
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombre = $usuarioActual['empresa_nombre'] ?? 'INVERSIONES VALLECILLO, SB';
$pdo = obtenerDB();

// 1. OBTENER CONFIGURACIÓN ACTUAL
$stmt = $pdo->prepare("SELECT * FROM t_configuracion_bascula WHERE empresa_id = :empresa_id AND estacion = 'PRINCIPAL'");
$stmt->execute([':empresa_id' => $empresaId]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    // Si no existe, inicializar con valores predeterminados
    $pdo->prepare("
        INSERT INTO t_configuracion_bascula 
        (empresa_id, estacion, modo, puerto_com, baudios, bits_datos, paridad, bits_parada, control_flujo, protocolo, unidad_indicador, timeout_lectura_ms, intervalo_lectura_ms, max_trama_bytes, parametros_confirmados, creado_por, actualizado_por)
        VALUES 
        (:empresa_id, 'PRINCIPAL', 'SERIAL', 'COM9', 9600, 8, 'N', 1.0, 'NINGUNO', 'C18 = 4', 'lb', 1000, 500, 512, 1, :uid, :uid)
    ")->execute([':empresa_id' => $empresaId, ':uid' => (int)$usuarioActual['id']]);

    $stmt->execute([':empresa_id' => $empresaId]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
}

$mensajeExito = null;
$mensajeError = null;

// 2. PROCESAR ACCIONES DEL FORMULARIO
$sincronizarApiAlCargar = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string)($_POST['accion'] ?? ''));

    if ($accion === 'guardar') {
        $puertoCom  = strtoupper(trim((string)($_POST['puerto_com'] ?? '')));
        $geminiKey  = trim((string)($_POST['gemini_api_key'] ?? ''));
        $baudios    = (int)($_POST['baudios'] ?? 9600);
        $bitsDatos  = (int)($_POST['bits_datos'] ?? 8);
        $paridad    = trim((string)($_POST['paridad'] ?? 'N'));
        $bitsParada = (float)($_POST['bits_parada'] ?? 1.0);
        $controlFlujo = trim((string)($_POST['control_flujo'] ?? 'NINGUNO'));
        $protocolo  = trim((string)($_POST['protocolo'] ?? 'C18 = 4'));
        $unidad     = trim((string)($_POST['unidad_indicador'] ?? 'lb'));
        $timeoutMs  = (int)($_POST['timeout_lectura_ms'] ?? 1000);
        $intervaloMs= (int)($_POST['intervalo_lectura_ms'] ?? 500);
        $maxTrama   = (int)($_POST['max_trama_bytes'] ?? 512);
        $capacidadMax = trim((string)($_POST['capacidad_max_lb'] ?? '')) ?: null;
        $divisionLb   = trim((string)($_POST['division_lb'] ?? '')) ?: null;

        if ($puertoCom !== '' && !preg_match('/^COM\d{1,3}$/i', $puertoCom)) {
            $mensajeError = 'El puerto debe tener el formato COM1 hasta COM99.';
        } else {
            $stmtUpdate = $pdo->prepare("
                UPDATE t_configuracion_bascula SET
                    puerto_com = :puerto_com,
                    gemini_api_key = :gemini_api_key,
                    baudios = :baudios,
                    bits_datos = :bits_datos,
                    paridad = :paridad,
                    bits_parada = :bits_parada,
                    control_flujo = :control_flujo,
                    protocolo = :protocolo,
                    unidad_indicador = :unidad_indicador,
                    timeout_lectura_ms = :timeout_lectura_ms,
                    intervalo_lectura_ms = :intervalo_lectura_ms,
                    max_trama_bytes = :max_trama_bytes,
                    capacidad_max_lb = :capacidad_max_lb,
                    division_lb = :division_lb,
                    actualizado_por = :actualizado_por,
                    actualizada_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':puerto_com'           => $puertoCom ?: null,
                ':gemini_api_key'       => $geminiKey ?: null,
                ':baudios'              => $baudios,
                ':bits_datos'           => $bitsDatos,
                ':paridad'              => $paridad,
                ':bits_parada'          => $bitsParada,
                ':control_flujo'        => $controlFlujo,
                ':protocolo'            => $protocolo,
                ':unidad_indicador'     => $unidad,
                ':timeout_lectura_ms'   => $timeoutMs,
                ':intervalo_lectura_ms' => $intervaloMs,
                ':max_trama_bytes'      => $maxTrama,
                ':capacidad_max_lb'     => $capacidadMax,
                ':division_lb'          => $divisionLb,
                ':actualizado_por'      => (int)$usuarioActual['id'],
                ':id'                   => (int)$config['id']
            ]);

            $mensajeExito = 'Parámetros de comunicación guardados correctamente.';
            $sincronizarApiAlCargar = true;
            
            // Recargar datos
            $stmt->execute([':empresa_id' => $empresaId]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } elseif ($accion === 'activar_real') {
        $stmtModo = $pdo->prepare("
            UPDATE t_configuracion_bascula SET
                modo = 'SERIAL',
                parametros_confirmados = 1,
                actualizado_por = :uid,
                actualizada_at = NOW()
            WHERE id = :id
        ");
        $stmtModo->execute([':uid' => (int)$usuarioActual['id'], ':id' => (int)$config['id']]);
        $mensajeExito = 'Báscula Real activada para la estación PRINCIPAL.';
        $sincronizarApiAlCargar = true;
        $stmt->execute([':empresa_id' => $empresaId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($accion === 'activar_simulado') {
        $stmtModo = $pdo->prepare("
            UPDATE t_configuracion_bascula SET
                modo = 'SIMULADO',
                actualizado_por = :uid,
                actualizada_at = NOW()
            WHERE id = :id
        ");
        $stmtModo->execute([':uid' => (int)$usuarioActual['id'], ':id' => (int)$config['id']]);
        $mensajeExito = 'Modo Simulado activado para pruebas locales.';
        $sincronizarApiAlCargar = true;
        $stmt->execute([':empresa_id' => $empresaId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// 3. DETECCIÓN DE PUERTOS EN WINDOWS
$puertosDetectados = [];
try {
    $salidaPuertos = @shell_exec('powershell -NoProfile -Command "[System.IO.Ports.SerialPort]::GetPortNames()"');
    if ($salidaPuertos) {
        $lineas = explode("\n", trim($salidaPuertos));
        foreach ($lineas as $lp) {
            $lp = trim($lp);
            if ($lp !== '' && !in_array($lp, $puertosDetectados, true)) {
                $puertosDetectados[] = $lp;
            }
        }
    }
} catch (Exception $e) {
    // Si falla la llamada powershell, continúa
}

$modoActual = ($config['modo'] ?? 'SERIAL') === 'SERIAL' ? 'REAL' : 'SIMULADO';

renderizarEncabezado('Configuración Báscula', 'config');
?>

<div style="max-width: 1000px; margin: 0 auto;">

    <!-- ENCABEZADO DE PÁGINA -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
                AJUSTES DEL INDICADOR
            </span>
            <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.2rem 0 0.2rem 0; color: var(--text-main); line-height: 1.1;">
                Configuración serial
            </h1>
            <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?> &middot; Estación PRINCIPAL
            </span>
        </div>

        <div>
            <span class="badge" style="background: <?= $modoActual === 'REAL' ? 'rgba(34, 197, 94, 0.15)' : 'rgba(59, 130, 246, 0.15)' ?>; color: <?= $modoActual === 'REAL' ? '#16a34a' : '#2563eb' ?>; font-weight: 800; font-size: 0.85rem; padding: 0.5rem 0.85rem; border-radius: 8px; border: 1px solid <?= $modoActual === 'REAL' ? '#86efac' : '#bfdbfe' ?>;">
                Configurado: <?= $modoActual ?>
            </span>
        </div>
    </div>

    <!-- NOTIFICACIONES -->
    <?php if ($mensajeExito): ?>
        <div style="background: rgba(34, 197, 94, 0.15); border: 1px solid #86efac; color: #166534; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem;">
            ✅ <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($mensajeError): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid #fca5a5; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; font-size: 0.9rem;">
            ⚠️ <?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1.75rem; font-size: 0.85rem; color: #475569;">
        El modo guardado determina la fuente de lectura del sistema. La prueba en vivo abre el COM escrito durante un máximo de 30 segundos y muestra los datos recibidos sin guardarlos ni transmitir datos.
    </div>

    <!-- TARJETA 1: PARÁMETROS DE COMUNICACIÓN -->
    <div class="card" style="padding: 2rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.75rem;">
        <h2 style="font-size: 1.35rem; font-weight: 800; margin: 0 0 0.35rem 0; color: #0f172a;">
            Parámetros de comunicación
        </h2>
        <p style="font-size: 0.82rem; color: #64748b; margin-bottom: 1.5rem;">
            Deja vacíos los parámetros físicos que aún no conoces. Guardar ajustes conserva los parámetros de comunicación.
        </p>

        <form method="POST" action="config_bascula.php" id="formSerial">
            <input type="hidden" name="accion" value="guardar">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
                
                <!-- Puerto COM -->
                <div>
                    <label for="puerto_com" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Puerto COM
                    </label>
                    <input type="text" id="puerto_com" name="puerto_com" class="form-control" style="height: 40px; border-radius: 6px; font-weight: 700;" value="<?= htmlspecialchars($config['puerto_com'] ?? 'COM9', ENT_QUOTES, 'UTF-8') ?>" placeholder="Ej: COM9">
                    
                    <div style="margin-top: 0.45rem; font-size: 0.78rem; color: #64748b;">
                        <?php if (!empty($puertosDetectados)): ?>
                            Detectados en Windows: <strong><?= implode(', ', $puertosDetectados) ?></strong>
                        <?php else: ?>
                            Ejemplo de formato: COM9. Déjalo vacío si no está confirmado.
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Baudios -->
                <div>
                    <label for="baudios" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Baudios
                    </label>
                    <select id="baudios" name="baudios" class="form-control" style="height: 40px; border-radius: 6px;">
                        <?php 
                        $listaBaudios = [1200, 2400, 4800, 9600, 14400, 19200, 38400, 57600, 115200];
                        $baudActual = (int)($config['baudios'] ?? 9600);
                        foreach ($listaBaudios as $b): ?>
                            <option value="<?= $b ?>" <?= $b === $baudActual ? 'selected' : '' ?>><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Bits de datos -->
                <div>
                    <label for="bits_datos" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Bits de datos
                    </label>
                    <select id="bits_datos" name="bits_datos" class="form-control" style="height: 40px; border-radius: 6px;">
                        <option value="8" <?= ((int)$config['bits_datos'] === 8) ? 'selected' : '' ?>>8</option>
                        <option value="7" <?= ((int)$config['bits_datos'] === 7) ? 'selected' : '' ?>>7</option>
                    </select>
                </div>

                <!-- Paridad -->
                <div>
                    <label for="paridad" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Paridad
                    </label>
                    <select id="paridad" name="paridad" class="form-control" style="height: 40px; border-radius: 6px;">
                        <option value="N" <?= ($config['paridad'] === 'N') ? 'selected' : '' ?>>Sin paridad (N)</option>
                        <option value="E" <?= ($config['paridad'] === 'E') ? 'selected' : '' ?>>Par (E)</option>
                        <option value="O" <?= ($config['paridad'] === 'O') ? 'selected' : '' ?>>Impar (O)</option>
                    </select>
                </div>

                <!-- Bits de parada -->
                <div>
                    <label for="bits_parada" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Bits de parada
                    </label>
                    <select id="bits_parada" name="bits_parada" class="form-control" style="height: 40px; border-radius: 6px;">
                        <option value="1" <?= ((float)$config['bits_parada'] == 1.0) ? 'selected' : '' ?>>1</option>
                        <option value="2" <?= ((float)$config['bits_parada'] == 2.0) ? 'selected' : '' ?>>2</option>
                    </select>
                </div>

                <!-- Control de flujo -->
                <div>
                    <label for="control_flujo" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Control de flujo
                    </label>
                    <select id="control_flujo" name="control_flujo" class="form-control" style="height: 40px; border-radius: 6px;">
                        <option value="NINGUNO" <?= ($config['control_flujo'] === 'NINGUNO') ? 'selected' : '' ?>>Ninguno</option>
                        <option value="XON_XOFF" <?= ($config['control_flujo'] === 'XON_XOFF') ? 'selected' : '' ?>>XON / XOFF</option>
                        <option value="RTS_CTS" <?= ($config['control_flujo'] === 'RTS_CTS') ? 'selected' : '' ?>>RTS / CTS</option>
                    </select>
                </div>

                <!-- Protocolo -->
                <div>
                    <label for="protocolo" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Protocolo del indicador
                    </label>
                    <input type="text" id="protocolo" name="protocolo" class="form-control" style="height: 40px; border-radius: 6px;" value="<?= htmlspecialchars($config['protocolo'] ?? 'C18 = 4', ENT_QUOTES, 'UTF-8') ?>">
                    <small style="color: #64748b; font-size: 0.75rem;">Para LP7510 con salida continua PC (C18=4): LP7510_PC.</small>
                </div>

                <!-- Unidad -->
                <div>
                    <label for="unidad_indicador" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Unidad del indicador
                    </label>
                    <select id="unidad_indicador" name="unidad_indicador" class="form-control" style="height: 40px; border-radius: 6px;">
                        <option value="lb" <?= ($config['unidad_indicador'] === 'lb') ? 'selected' : '' ?>>Libras (lb)</option>
                        <option value="kg" <?= ($config['unidad_indicador'] === 'kg') ? 'selected' : '' ?>>Kilogramos (kg)</option>
                    </select>
                </div>

                <!-- Espera máxima (ms) -->
                <div>
                    <label for="timeout_lectura_ms" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Espera máxima de lectura (ms)
                    </label>
                    <input type="number" id="timeout_lectura_ms" name="timeout_lectura_ms" class="form-control" style="height: 40px; border-radius: 6px;" value="<?= (int)($config['timeout_lectura_ms'] ?? 1000) ?>">
                </div>

                <!-- Intervalo de lectura (ms) -->
                <div>
                    <label for="intervalo_lectura_ms" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Intervalo de lectura (ms)
                    </label>
                    <input type="number" id="intervalo_lectura_ms" name="intervalo_lectura_ms" class="form-control" style="height: 40px; border-radius: 6px;" value="<?= (int)($config['intervalo_lectura_ms'] ?? 500) ?>">
                </div>

                <!-- Tamaño máximo de trama (bytes) -->
                <div>
                    <label for="max_trama_bytes" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Tamaño máximo de trama (bytes)
                    </label>
                    <input type="number" id="max_trama_bytes" name="max_trama_bytes" class="form-control" style="height: 40px; border-radius: 6px;" value="<?= (int)($config['max_trama_bytes'] ?? 512) ?>">
                </div>

                <!-- API Key de Gemini (Solo Administrador / Super) -->
                <div style="grid-column: 1 / -1;">
                    <label for="gemini_api_key" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        🤖 Google Gemini API Key (Asistente IA de Báscula)
                    </label>
                    <input type="password" id="gemini_api_key" name="gemini_api_key" class="form-control" style="height: 40px; border-radius: 6px; font-family: monospace;" value="<?= htmlspecialchars($config['gemini_api_key'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pega aquí tu clave AI Studio (ej: AIzaSy...)">
                    <small style="color: #64748b; font-size: 0.75rem;">Opcional. Activa el asistente inteligente en todo el sistema exclusivamente para Super y Administradores.</small>
                </div>

            </div>

            <!-- BOTONES DE ACCIÓN -->
            <div style="display: flex; gap: 0.85rem; align-items: center; flex-wrap: wrap; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                <button type="submit" class="btn btn-primary" style="height: 40px; padding: 0 1.5rem; font-weight: 700; background: #0b57d0; border-radius: 6px;">
                    Guardar ajustes
                </button>
                <button type="button" onclick="iniciarPruebaEco()" class="btn btn-secondary" id="btnTestStart" style="height: 40px; padding: 0 1.25rem; font-weight: 600; border-radius: 6px;">
                    Iniciar prueba en vivo
                </button>
                <button type="button" onclick="detenerPruebaEco()" class="btn btn-secondary" id="btnTestStop" disabled style="height: 40px; padding: 0 1.25rem; font-weight: 600; border-radius: 6px;">
                    Detener prueba
                </button>
                <a href="index.php" style="font-size: 0.85rem; color: #475569; text-decoration: none; margin-left: auto;">
                    Volver al inicio
                </a>
            </div>
        </form>
    </div>

    <!-- TARJETA 2: LECTURA DE PRUEBA -->
    <div class="card" style="padding: 2rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.75rem;">
        <h2 style="font-size: 1.35rem; font-weight: 800; margin: 0 0 1.25rem 0; color: #0f172a;">
            Lectura de prueba
        </h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
            <div>
                <span style="font-size: 0.78rem; font-weight: 600; color: #64748b; display: block; margin-bottom: 0.25rem;">Estado del puerto</span>
                <strong id="testEstado" style="color: #0f172a; font-size: 0.95rem;">Sin iniciar</strong>
            </div>
            <div>
                <span style="font-size: 0.78rem; font-weight: 600; color: #64748b; display: block; margin-bottom: 0.25rem;">Parámetros de esta prueba</span>
                <strong id="testParams" style="color: #0f172a; font-size: 0.95rem;">—</strong>
            </div>
            <div>
                <span style="font-size: 0.78rem; font-weight: 600; color: #64748b; display: block; margin-bottom: 0.25rem;">Tiempo transcurrido</span>
                <strong id="testTiempo" style="color: #0f172a; font-size: 0.95rem;">0 s</strong>
            </div>
            <div>
                <span style="font-size: 0.78rem; font-weight: 600; color: #64748b; display: block; margin-bottom: 0.25rem;">Recibidos</span>
                <strong id="testRecibidos" style="color: #0f172a; font-size: 0.95rem;">0 bytes &middot; 0 bloques</strong>
            </div>
            <div>
                <span style="font-size: 0.78rem; font-weight: 600; color: #64748b; display: block; margin-bottom: 0.25rem;">Último dato</span>
                <strong id="testUltimo" style="color: #0f172a; font-size: 0.95rem;">Ninguno</strong>
            </div>
            <div>
                <span style="font-size: 0.78rem; font-weight: 600; color: #64748b; display: block; margin-bottom: 0.25rem;">Respuesta del lector</span>
                <strong id="testRespuesta" style="color: #0f172a; font-size: 0.95rem;">Sin iniciar</strong>
            </div>
        </div>

        <p id="testMensaje" style="font-size: 0.82rem; color: #64748b; margin: 0 0 1rem 0;">
            La prueba solo escucha; el puente por sí solo no genera datos. Para comprobarlo se necesita una prueba de eco o jumpear TX/RX.
        </p>

        <div id="testLog" style="background: #0f172a; color: #38bdf8; font-family: monospace; font-size: 0.8rem; padding: 1rem; border-radius: 8px; min-height: 80px; max-height: 180px; overflow-y: auto; display: none;">
            <!-- Datos en vivo -->
        </div>
    </div>

    <!-- TARJETA 3: CONFIRMACIÓN DE PARÁMETROS -->
    <div class="card" style="padding: 2rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 2.5rem;">
        <h2 style="font-size: 1.35rem; font-weight: 800; margin: 0 0 0.5rem 0; color: #0f172a;">
            Confirmación de parámetros
        </h2>
        <p style="font-size: 0.82rem; color: #64748b; margin-bottom: 1.5rem;">
            Después de guardar, coteja los valores con el indicador. Confirmarlos activa Báscula real para la estación PRINCIPAL. Puedes volver a Simulado cuando lo necesites.
        </p>

        <form method="POST" action="config_bascula.php" style="margin-bottom: 1.25rem;">
            <input type="hidden" name="accion" value="activar_real">
            
            <label style="display: flex; align-items: center; gap: 0.65rem; font-size: 0.88rem; font-weight: 600; color: #1e293b; margin-bottom: 1.25rem; cursor: pointer;">
                <input type="checkbox" name="cotejado" value="1" required style="width: 18px; height: 18px;">
                He cotejado los parámetros guardados con el indicador.
            </label>

            <button type="submit" class="btn btn-primary" style="height: 40px; padding: 0 1.5rem; font-weight: 700; background: #0b57d0; border-radius: 6px;">
                Activar Báscula real
            </button>
        </form>

        <form method="POST" action="config_bascula.php" style="display: inline;">
            <input type="hidden" name="accion" value="activar_simulado">
            <button type="submit" class="btn btn-link" style="font-size: 0.85rem; color: #0b57d0; text-decoration: underline; background: none; border: none; padding: 0; cursor: pointer;">
                Usar Simulado
            </button>
        </form>

        <div style="font-size: 0.78rem; color: #94a3b8; margin-top: 1.5rem;">
            Última actualización: <?= htmlspecialchars($config['actualizada_at'] ?? '2026-11-16', ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>

</div>

<script>
let timerPrueba = null;
let segundosPrueba = 0;
let ssePrueba = null;

function agregarLineaLogPrueba(texto, color = '') {
    const log = document.getElementById('testLog');
    const div = document.createElement('div');
    if (color) div.style.color = color;
    div.textContent = `[${new Date().toLocaleTimeString()}] ${texto}`;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

function iniciarPruebaEco() {
    const puerto = document.getElementById('puerto_com').value.trim().toUpperCase();
    const baudios = document.getElementById('baudios').value;
    const bitsDatos = document.getElementById('bits_datos').value;
    const paridad = document.getElementById('paridad').value;
    const bitsParada = document.getElementById('bits_parada').value;
    const controlFlujo = document.getElementById('control_flujo').value;

    if (!puerto) {
        alert('Por favor indica un puerto COM primero.');
        return;
    }

    detenerPruebaEco(false);

    document.getElementById('btnTestStart').disabled = true;
    document.getElementById('btnTestStop').disabled = false;
    document.getElementById('testEstado').textContent = 'Escuchando en ' + puerto;
    document.getElementById('testEstado').style.color = '#0b57d0';
    document.getElementById('testParams').textContent = `${puerto}, ${baudios} baudios, ${bitsDatos}${paridad}${bitsParada}`;
    document.getElementById('testLog').style.display = 'block';
    document.getElementById('testLog').innerHTML = '';
    agregarLineaLogPrueba(`Conectando a ${puerto} (liberando lector 8018)...`);

    segundosPrueba = 0;
    document.getElementById('testTiempo').textContent = '0 s';
    timerPrueba = setInterval(() => {
        segundosPrueba++;
        document.getElementById('testTiempo').textContent = segundosPrueba + ' s';
    }, 1000);

    const params = new URLSearchParams({
        puerto_com: puerto,
        baudios: baudios,
        bits_datos: bitsDatos,
        paridad: paridad,
        bits_parada: bitsParada,
        control_flujo: controlFlujo
    });

    ssePrueba = new EventSource('serial_prueba.php?' + params.toString());
    ssePrueba.onmessage = (evento) => {
        try {
            const dato = JSON.parse(evento.data);
            if (dato.estado === 'cerrado') {
                detenerPruebaEco(true);
                return;
            }
            const texto = dato.texto ? `${dato.mensaje} -> ${dato.texto}` : (dato.mensaje || evento.data);
            const color = dato.ok === false ? '#dc2626' : (dato.estado === 'datos' ? '#16a34a' : '');
            agregarLineaLogPrueba(texto, color);
        } catch (e) {
            agregarLineaLogPrueba(evento.data);
        }
    };
    ssePrueba.onerror = () => {
        detenerPruebaEco(true);
    };
}

function detenerPruebaEco(mostrarFin = true) {
    if (ssePrueba) {
        ssePrueba.close();
        ssePrueba = null;
    }
    if (timerPrueba) {
        clearInterval(timerPrueba);
        timerPrueba = null;
    }
    document.getElementById('btnTestStart').disabled = false;
    document.getElementById('btnTestStop').disabled = true;
    if (mostrarFin) {
        document.getElementById('testEstado').textContent = 'Prueba finalizada';
        document.getElementById('testEstado').style.color = '#64748b';
        agregarLineaLogPrueba('Puerto liberado.');
    }
}

<?php if ($sincronizarApiAlCargar): ?>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof notificarApiBascula === 'function') {
        notificarApiBascula({
            modo: '<?= ($config['modo'] ?? 'SERIAL') === 'SERIAL' ? 'real' : 'simulado' ?>',
            puerto_com: <?= json_encode((string)($config['puerto_com'] ?? '')) ?>,
            baudios: <?= (int)($config['baudios'] ?? 9600) ?>
        });
    }
});
<?php endif; ?>
</script>

<?php
renderizarPie();
