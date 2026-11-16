<?php
/**
 * Tablero Principal (Dashboard) y Monitor de Pesaje
 * Proyecto: tytBasculaAntigravity
 *
 * Muestra el display de peso en tiempo real, accesos directos al ciclo de pesaje
 * y el estado de los servicios (XAMPP MariaDB y microservicio FastAPI).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

// Verificar estado de conexión con la base de datos MariaDB
$estadoDB = false;
$errorDB = null;
$totalPendientes = 0;
$pesadasRecientes = [];

try {
    $pdo = obtenerDB();
    $estadoDB = true;
    $usuario = obtenerUsuarioActual();
    $empresaId = (int)($usuario['empresa_id'] ?? 1);

    // Consultar pesadas pendientes de segunda pesada (estado ABIERTA)
    $stmtPendientes = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM t_pesadas WHERE empresa_id = :empresa_id AND estado = 'ABIERTA'"
    );
    $stmtPendientes->execute([':empresa_id' => $empresaId]);
    $totalPendientes = (int)($stmtPendientes->fetch()['total'] ?? 0);
} catch (Exception $e) {
    $errorDB = $e->getMessage();
}

renderizarEncabezado('Inicio / Monitor de Pesaje', 'monitor');
?>

<!-- =======================================================================
     DISPLAY DIGITAL DE PESAJE EN VIVO
     ======================================================================= -->
<div class="card" style="padding: 1rem 1.5rem; margin-bottom: 2rem;">
    <div class="display-bascula-panel">
        <div class="display-encabezado">
            <span>Indicador Electrónico de Báscula</span>
            <div class="display-badges">
                <span id="badgeModo" class="badge badge-modo">SIMULADO</span>
                <span id="badgeEstable" class="badge badge-inestable">INICIALIZANDO...</span>
            </div>
        </div>

        <div class="display-valor-wrapper">
            <div id="displayPesoValor" class="display-valor">0</div>
            <div id="displayPesoUnidad" class="display-unidad">LB</div>
        </div>

        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
            <span>Lectura continua en vivo &bull; Microservicio en loopback (8018)</span>
        </div>
    </div>
</div>

<!-- =======================================================================
     ACCIONES RÁPIDAS DE PESAJE
     ======================================================================= -->
<div class="grid-2" style="margin-bottom: 2rem;">
    <div class="card" style="border-left: 4px solid var(--primary);">
        <h3 class="card-title">🚛 Primera Pesada (Entrada)</h3>
        <p style="color: var(--text-muted); margin-bottom: 1.25rem; font-size: 0.95rem;">
            Registra el ingreso de un camión a la báscula, captura su peso inicial (Bruto o Tara) y asocia placa, cliente/proveedor y producto.
        </p>
        <a href="primera_pesada.php" class="btn btn-primary btn-lg" style="width: 100%;">
            Iniciar Primera Pesada
        </a>
    </div>

    <div class="card" style="border-left: 4px solid var(--success);">
        <div class="card-title">
            <span>✅ Segunda Pesada (Salida)</span>
            <span class="badge" style="background-color: var(--success-bg); color: var(--success); font-size: 0.8rem;">
                <?= $totalPendientes ?> Pendientes
            </span>
        </div>
        <p style="color: var(--text-muted); margin-bottom: 1.25rem; font-size: 0.95rem;">
            Completa el pesaje final del camión, calcula automáticamente el peso Neto y genera la boleta de pesaje lista para imprimir.
        </p>
        <a href="segunda_pesada.php" class="btn btn-success btn-lg" style="width: 100%;">
            Procesar Segunda Pesada (<?= $totalPendientes ?>)
        </a>
    </div>
</div>

<!-- =======================================================================
     ESTADO DEL SISTEMA Y BASE DE DATOS
     ======================================================================= -->
<div class="grid-2">
    <!-- Diagnóstico de Servicios -->
    <div class="card">
        <h3 class="card-title">🛠️ Estado de Servicios Locales</h3>
        <ul style="list-style: none; display: flex; flex-direction: column; gap: 0.75rem;">
            <li style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                <span>Servidor Web Apache (XAMPP):</span>
                <span class="badge badge-estable">ACTIVO</span>
            </li>
            <li style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                <span>Base de Datos MariaDB (dbBascula):</span>
                <?php if ($estadoDB): ?>
                    <span class="badge badge-estable">CONECTADO</span>
                <?php else: ?>
                    <span class="badge badge-inestable" title="<?= htmlspecialchars($errorDB ?? '', ENT_QUOTES, 'UTF-8') ?>">DESCONECTADO</span>
                <?php endif; ?>
            </li>
            <li style="display: flex; justify-content: space-between; align-items: center;">
                <span>Microservicio Báscula (FastAPI :8018):</span>
                <span id="badgeEstadoMicroservicio" class="badge badge-modo">CONSULTANDO...</span>
            </li>
        </ul>
        <?php if ($errorDB): ?>
            <div style="margin-top: 1rem; padding: 0.75rem; background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); border-radius: var(--radius-sm); font-size: 0.85rem; color: #fca5a5;">
                ⚠️ <?= htmlspecialchars($errorDB, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Controles de Prueba para el Simulador (Dev) -->
    <div class="card">
        <h3 class="card-title">🧪 Controles de Simulación (Dev)</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem;">
            Utiliza estos botones para probar el comportamiento de pesaje en esta computadora de desarrollo:
        </p>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <button type="button" class="btn btn-secondary" onclick="simularEvento('camion')">
                🚚 Simular Camión (38,500 lb)
            </button>
            <button type="button" class="btn btn-secondary" onclick="simularEvento('despejar')">
                🧹 Despejar Báscula (0 lb)
            </button>
        </div>
    </div>
</div>

<script>
// Función para disparar eventos rápidos al microservicio en desarrollo
async function simularEvento(accion) {
    try {
        const url = accion === 'camion' 
            ? 'http://127.0.0.1:8018/api/v1/simulador/camion?peso_bruto=38500'
            : 'http://127.0.0.1:8018/api/v1/simulador/despejar';
        await fetch(url, { method: 'POST' });
    } catch (e) {
        alert('No se pudo conectar con el microservicio FastAPI en el puerto 8018. ¿Está iniciado?');
    }
}

// Chequear el microservicio FastAPI para actualizar el badge de diagnóstico
document.addEventListener('DOMContentLoaded', async () => {
    const badge = document.getElementById('badgeEstadoMicroservicio');
    try {
        const resp = await fetch('http://127.0.0.1:8018/api/v1/salud');
        if (resp.ok) {
            badge.textContent = 'ACTIVO (8018)';
            badge.className = 'badge badge-estable';
        } else {
            badge.textContent = 'ERROR HTTP';
            badge.className = 'badge badge-inestable';
        }
    } catch (e) {
        badge.textContent = 'NO INICIADO';
        badge.className = 'badge badge-inestable';
    }
});
</script>

<?php
renderizarPie();
?>
