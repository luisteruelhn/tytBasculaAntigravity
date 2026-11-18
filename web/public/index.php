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
$ultimaPesada = null;
$hostActual = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
$esEntornoRemoto = ($hostActual !== '' && !in_array($hostActual, ['localhost', '127.0.0.1', '::1'], true));
$esRemoto = false;
$mostrarModoUltimoPeso = $esEntornoRemoto;

try {
    $pdo = obtenerDB();
    $estadoDB = true;
    $usuario = obtenerUsuarioActual();
    $esRemoto = $usuario && tieneRol($usuario, ['REMOTO']);
    $mostrarModoUltimoPeso = $esEntornoRemoto || $esRemoto;
    $empresaId = (int)($usuario['empresa_id'] ?? 1);

    // Consultar pesadas pendientes de segunda pesada (estado ABIERTA)
    $stmtPendientes = $pdo->prepare(
        "SELECT COUNT(*) AS total FROM t_pesadas WHERE empresa_id = :empresa_id AND estado = 'ABIERTA'"
    );
    $stmtPendientes->execute([':empresa_id' => $empresaId]);
    $totalPendientes = (int)($stmtPendientes->fetch()['total'] ?? 0);

    if ($mostrarModoUltimoPeso) {
        $stmtUltima = $pdo->prepare("
            SELECT p.idpesada, p.tipo_operacion, p.id_compra, p.id_venta, p.estado, p.placa,
                   COALESCE(p.peso_final_lb, p.peso_bruto_lb, p.tara_vehiculo_lb, 0) AS peso_mostrar_lb,
                   COALESCE(p.segunda_pesada_at, p.primera_pesada_at, p.fecha_at) AS fecha_mov,
                   cp.nombre AS persona_nombre, prod.nombre AS producto_nombre
            FROM t_pesadas p
            LEFT JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
            LEFT JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
            LEFT JOIN t_productos prod ON prod.id = pr.producto_id
            WHERE p.empresa_id = :empresa_id AND p.estado <> 'ANULADA'
            ORDER BY COALESCE(p.segunda_pesada_at, p.primera_pesada_at, p.fecha_at) DESC, p.idpesada DESC
            LIMIT 1
        ");
        $stmtUltima->execute([':empresa_id' => $empresaId]);
        $ultimaPesada = $stmtUltima->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Exception $e) {
    $errorDB = $e->getMessage();
}

renderizarEncabezado('Inicio / Monitor de Pesaje', 'monitor');
?>

<!-- =======================================================================
     DISPLAY DIGITAL (EN VIVO EN LOCAL / ÚLTIMA PESADA EN REMOTO)
     ======================================================================= -->
<div class="card" style="padding: 1rem 1.5rem; margin-bottom: 2rem;">
    <div class="display-bascula-panel">
        <?php if ($mostrarModoUltimoPeso): ?>
            <?php
            $ultPeso = $ultimaPesada ? (float)$ultimaPesada['peso_mostrar_lb'] : 0;
            $ultOp   = $ultimaPesada ? ($ultimaPesada['tipo_operacion'] === 'COMPRA' ? 'COMPRA #' . $ultimaPesada['id_compra'] : 'VENTA #' . $ultimaPesada['id_venta']) : 'SIN REGISTROS';
            $ultEst  = $ultimaPesada ? (string)$ultimaPesada['estado'] : '—';
            $ultDesc = $ultimaPesada
                ? htmlspecialchars(($ultimaPesada['producto_nombre'] ?: 'Producto') . ' · ' . ($ultimaPesada['persona_nombre'] ?: '') . ' · Placa ' . $ultimaPesada['placa'] . ' · ' . date('d/m/Y H:i', strtotime((string)$ultimaPesada['fecha_mov'])), ENT_QUOTES, 'UTF-8')
                : 'Aún no hay pesadas registradas en el sistema.';
            ?>
            <div class="display-encabezado">
                <span>Indicador Electrónico &middot; Última Pesada Registrada</span>
                <div class="display-badges">
                    <span class="badge badge-modo"><?= htmlspecialchars($ultOp, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="badge badge-estable"><?= htmlspecialchars($ultEst, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <div class="display-valor-wrapper">
                <div id="displayPesoValor" data-modo-remoto="1" class="display-valor"><?= number_format($ultPeso, 0) ?></div>
                <div id="displayPesoUnidad" class="display-unidad">LB</div>
            </div>

            <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                <span><?= $ultDesc ?></span>
            </div>
        <?php else: ?>
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
        <?php endif; ?>
    </div>
</div>

<!-- =======================================================================
     ACCIONES RÁPIDAS DE PESAJE (O CONSULTA PARA REMOTO)
     ======================================================================= -->
<?php if (!empty($esRemoto)): ?>
<div class="grid-2" style="margin-bottom: 2rem;">
    <div class="card" style="border-left: 4px solid var(--primary);">
        <h3 class="card-title">📊 Dashboard y Boletas</h3>
        <p style="color: var(--text-muted); margin-bottom: 1.25rem; font-size: 0.95rem;">
            Consulta los totales acumulados del día, boletas cerradas, camiones en patio y Guías de Remisión.
        </p>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="dashboard.php" class="btn btn-primary" style="flex: 1;">Ver Dashboard</a>
            <a href="pesadas.php" class="btn btn-secondary" style="flex: 1;">Boletas e Historial (<?= $totalPendientes ?> en patio)</a>
        </div>
    </div>

    <div class="card" style="border-left: 4px solid var(--success);">
        <h3 class="card-title">📈 Reportes y Catálogos</h3>
        <p style="color: var(--text-muted); margin-bottom: 1.25rem; font-size: 0.95rem;">
            Genera reportes por rango de fechas, categoría o producto para imprimir, PDF o Excel.
        </p>
        <a href="reportes.php" class="btn btn-success btn-lg" style="width: 100%;">
            Abrir Reportes
        </a>
    </div>
</div>
<?php else: ?>
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
<?php endif; ?>

<!-- =======================================================================
     ESTADO DEL SISTEMA Y BASE DE DATOS
     ======================================================================= -->
<div class="grid-2">
    <!-- Diagnóstico de Servicios -->
    <div class="card">
        <h3 class="card-title"><?= $mostrarModoUltimoPeso ? '🛠️ Estado del Sistema Web' : '🛠️ Estado de Servicios Locales' ?></h3>
        <ul style="list-style: none; display: flex; flex-direction: column; gap: 0.75rem;">
            <?php if (!$mostrarModoUltimoPeso): ?>
            <li style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                <span>Servidor Web Apache (XAMPP):</span>
                <span class="badge badge-estable">ACTIVO</span>
            </li>
            <?php endif; ?>
            <li style="display: flex; justify-content: space-between; align-items: center; <?= !$mostrarModoUltimoPeso ? 'border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;' : '' ?>">
                <span>Base de Datos (MariaDB / MySQL):</span>
                <?php if ($estadoDB): ?>
                    <span class="badge badge-estable">CONECTADO</span>
                <?php else: ?>
                    <span class="badge badge-inestable" title="<?= htmlspecialchars($errorDB ?? '', ENT_QUOTES, 'UTF-8') ?>">DESCONECTADO</span>
                <?php endif; ?>
            </li>
            <?php if ($mostrarModoUltimoPeso): ?>
            <li style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); padding-top: 0.5rem;">
                <span>Último movimiento sincronizado:</span>
                <span class="badge badge-modo">
                    <?= $ultimaPesada ? htmlspecialchars(date('d/m/Y H:i', strtotime((string)$ultimaPesada['fecha_mov'])), ENT_QUOTES, 'UTF-8') : 'Sin datos' ?>
                </span>
            </li>
            <?php else: ?>
            <li style="display: flex; justify-content: space-between; align-items: center;">
                <span>Microservicio Báscula (FastAPI :8018):</span>
                <span id="badgeEstadoMicroservicio" class="badge badge-modo">CONSULTANDO...</span>
            </li>
            <?php endif; ?>
        </ul>
        <?php if ($errorDB): ?>
            <div style="margin-top: 1rem; padding: 0.75rem; background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); border-radius: var(--radius-sm); font-size: 0.85rem; color: #fca5a5;">
                ⚠️ <?= htmlspecialchars($errorDB, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($esRemoto) && !$esEntornoRemoto): ?>
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
    <?php endif; ?>
</div>

<?php if (!$mostrarModoUltimoPeso): ?>
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
    if (!badge) return;
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
<?php endif; ?>

<?php
renderizarPie();
?>
