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

$usuario = requerirAutenticacion();

// Verificar estado de conexión con la base de datos MariaDB
$estadoDB = false;
$errorDB = null;
$totalPendientes = 0;
$ultimaPesada = null;
$esEntornoRemoto = !esAccesoPlantaLocal();
$esRemoto = tieneRol($usuario, ['REMOTO']);
$mostrarModoUltimoPeso = $esEntornoRemoto || $esRemoto;
$urlRedLocal = !$mostrarModoUltimoPeso ? obtenerUrlRedLocal() : '';

try {
    $pdo = obtenerDB();
    $estadoDB = true;
    $empresaId = (int)$usuario['empresa_id'];

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
                    <span id="badgeModo" class="badge badge-modo">REAL</span>
                    <span id="badgeEstable" class="badge badge-inestable">INICIALIZANDO...</span>
                </div>
            </div>

            <div class="display-valor-wrapper">
                <div id="displayPesoValor" class="display-valor">0</div>
                <div id="displayPesoUnidad" class="display-unidad">LB</div>
            </div>

            <div style="width: 100%; display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; font-size: 0.85rem; color: var(--text-muted); margin-top: 0.35rem;">
                <span>Lectura continua en vivo &bull; Red local habilitada</span>
                <div style="margin-left: auto; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="button" onclick="abrirModalQrLocal()" class="btn btn-secondary" style="padding: 0.38rem 0.8rem; font-size: 0.82rem; font-weight: 700; border-radius: 6px;">
                        📱 Abrir en celular (QR)
                    </button>
                    <button type="button" id="btnToggleLecturaCom" onclick="alternarLecturaComInicio()" class="btn btn-primary" style="background: #1e40af; padding: 0.4rem 0.95rem; font-size: 0.84rem; font-weight: 700; border-radius: 6px;">
                        ▶️ Arrancar Lectura
                    </button>
                </div>
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
            <li style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                <span>Microservicio Báscula (FastAPI :8018):</span>
                <span id="badgeEstadoMicroservicio" class="badge badge-modo">CONSULTANDO...</span>
            </li>
            <li style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <span id="estadoSyncInicio" style="font-size: 0.85rem; color: var(--text-muted);">Sincronización con Hostinger:</span>
                <button type="button" id="btnSyncInicio" onclick="sincronizarNubeInicio()" class="btn btn-primary" style="background: #047857; padding: 0.4rem 0.95rem; font-size: 0.84rem; font-weight: 700; border-radius: 6px;">
                    ☁️ Sincronizar con la Nube
                </button>
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
<!-- Modal Ligero de Acceso Móvil por Red Local (QR Offline) -->
<div id="modalQrLocal" onclick="if (event.target === this) cerrarModalQrLocal();" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, 0.65); align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="max-width: 390px; width: 100%; padding: 1.35rem 1.5rem; text-align: center; border-radius: 14px; box-shadow: 0 20px 40px rgba(0,0,0,0.35);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">
            <span style="font-weight: 800; font-size: 1rem; color: var(--text-main);">📱 Acceso en Celular (Red Local)</span>
            <button type="button" onclick="cerrarModalQrLocal()" style="background: transparent; border: none; font-size: 1.25rem; cursor: pointer; color: var(--text-muted); line-height: 1;">&times;</button>
        </div>
        <p style="font-size: 0.83rem; color: var(--text-muted); margin: 0 0 0.9rem 0; line-height: 1.45;">
            Conecta tu celular al <strong>mismo Wi-Fi de la planta</strong> y escanea este código con la cámara para ver el peso en vivo y capturar pesadas:
        </p>
        <div id="contenedorQrInicio" style="width: 210px; height: 210px; margin: 0 auto 0.9rem auto; padding: 8px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px;"></div>
        <div style="background: var(--bg-main, #f8fafc); border: 1px solid var(--border-color); border-radius: 8px; padding: 0.5rem 0.65rem; font-family: monospace; font-size: 0.84rem; font-weight: 700; color: var(--primary); word-break: break-all; margin-bottom: 0.85rem;" id="textoUrlQrInicio">
            <?= htmlspecialchars($urlRedLocal, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div style="display: flex; gap: 0.6rem; justify-content: center;">
            <button type="button" class="btn btn-primary" onclick="copiarUrlQrInicio()" id="btnCopiarUrlQrInicio" style="font-size: 0.83rem; padding: 0.42rem 0.9rem;">
                📋 Copiar enlace
            </button>
            <button type="button" class="btn btn-secondary" onclick="cerrarModalQrLocal()" style="font-size: 0.83rem; padding: 0.42rem 0.9rem;">
                Cerrar
            </button>
        </div>
    </div>
</div>

<script src="assets/js/qr_local.js"></script>
<script>
const URL_RED_LOCAL_INICIO = <?= json_encode($urlRedLocal, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function abrirModalQrLocal() {
    const modal = document.getElementById('modalQrLocal');
    const caja = document.getElementById('contenedorQrInicio');
    if (caja && !caja.dataset.listo && typeof window.generarQrSvg === 'function') {
        caja.innerHTML = window.generarQrSvg(URL_RED_LOCAL_INICIO);
        caja.dataset.listo = '1';
    }
    if (modal) modal.style.display = 'flex';
}

function cerrarModalQrLocal() {
    const modal = document.getElementById('modalQrLocal');
    if (modal) modal.style.display = 'none';
}

function copiarUrlQrInicio() {
    const btn = document.getElementById('btnCopiarUrlQrInicio');
    if (navigator.clipboard && URL_RED_LOCAL_INICIO) {
        navigator.clipboard.writeText(URL_RED_LOCAL_INICIO).then(() => {
            if (btn) {
                const original = btn.textContent;
                btn.textContent = '✅ Copiado';
                setTimeout(() => { btn.textContent = original; }, 1800);
            }
        });
    }
}

// Función para disparar eventos rápidos al microservicio en desarrollo
async function simularEvento(accion) {
    try {
        const url = accion === 'camion'
            ? 'api_peso_local.php?accion=sim_camion'
            : 'api_peso_local.php?accion=sim_despejar';
        await fetch(url, { method: 'POST', credentials: 'same-origin' });
    } catch (e) {
        alert('No se pudo conectar con el microservicio FastAPI en el puerto 8018. ¿Está iniciado?');
    }
}

// Chequear el microservicio FastAPI para actualizar el badge y botón de lectura COM
let lecturaComActiva = false;

function actualizarBotonLecturaCom(activo, pausado, servicioArriba, puerto) {
    lecturaComActiva = Boolean(activo);
    const badge = document.getElementById('badgeEstadoMicroservicio');
    const btn = document.getElementById('btnToggleLecturaCom');
    if (badge) {
        if (!servicioArriba) {
            badge.textContent = 'APAGADO (COM LIBRE)';
            badge.className = 'badge badge-inestable';
        } else if (activo) {
            badge.textContent = (puerto ? puerto + ' ACTIVO' : 'ACTIVO (8018)');
            badge.className = 'badge badge-estable';
        } else if (pausado) {
            badge.textContent = 'PAUSADO (COM LIBRE)';
            badge.className = 'badge badge-modo';
        } else {
            badge.textContent = 'ACTIVO (8018)';
            badge.className = 'badge badge-estable';
        }
    }
    if (btn) {
        btn.textContent = lecturaComActiva ? '⏹️ Parar Lectura' : '▶️ Arrancar Lectura';
        btn.style.background = lecturaComActiva ? '#b91c1c' : '#1e40af';
    }
}

async function consultarEstadoMicroservicio() {
    try {
        const resp = await fetch('api_peso_local.php?accion=salud', { credentials: 'same-origin' });
        if (resp.ok) {
            const s = await resp.json();
            actualizarBotonLecturaCom(s.lector_activo, s.pausado_manual, true, s.puerto_com);
        } else {
            actualizarBotonLecturaCom(false, false, false, '');
        }
    } catch (e) {
        actualizarBotonLecturaCom(false, false, false, '');
    }
}

async function alternarLecturaComInicio() {
    const btn = document.getElementById('btnToggleLecturaCom');
    if (!btn) return;
    const accion = lecturaComActiva ? 'parar' : 'arrancar';
    btn.disabled = true;
    btn.textContent = accion === 'arrancar' ? 'Iniciando...' : 'Deteniendo...';

    try {
        const resp = await fetch('api_bascula_control.php?accion=' + accion, {
            method: 'POST',
            credentials: 'same-origin'
        });
        const data = await resp.json();
        if (data.ok) {
            await consultarEstadoMicroservicio();
        } else {
            alert(data.error || 'No se pudo cambiar el estado del lector.');
            await consultarEstadoMicroservicio();
        }
    } catch (e) {
        alert('Error de comunicación con el servidor local.');
        await consultarEstadoMicroservicio();
    } finally {
        btn.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', consultarEstadoMicroservicio);

async function sincronizarNubeInicio() {
    const btn = document.getElementById('btnSyncInicio');
    const lbl = document.getElementById('estadoSyncInicio');
    if (!btn || !lbl) return;

    btn.disabled = true;
    btn.textContent = 'Sincronizando...';
    lbl.textContent = 'Enviando datos a Hostinger...';
    lbl.style.color = 'var(--text-muted)';

    try {
        const resp = await fetch('api_sync_pendientes.php?modo=todo', {
            method: 'POST',
            credentials: 'same-origin'
        });
        const data = await resp.json();
        if (data.ok) {
            lbl.textContent = '✅ ' + (data.mensaje || 'Sincronizado correctamente');
            lbl.style.color = '#16a34a';
        } else {
            lbl.textContent = '⚠️ ' + (data.mensaje || data.error || 'No se pudo sincronizar');
            lbl.style.color = '#dc2626';
        }
    } catch (e) {
        lbl.textContent = '⚠️ Error de conexión con el servidor local';
        lbl.style.color = '#dc2626';
    } finally {
        btn.disabled = false;
        btn.textContent = '☁️ Sincronizar con la Nube';
    }
}
</script>
<?php endif; ?>

<?php
renderizarPie();
?>
