<?php
/**
 * Módulo de Boletas e Historial de Pesadas
 * Proyecto: tytBasculaAntigravity
 *
 * Listado histórico de notas (Cerradas, Abiertas y Anuladas)
 * Filtros rápidos: Búsqueda (Número, Placa, Persona), Tipo (Todas, Compra, Venta), Estado y Fecha.
 * Acciones: Ver / Imprimir Boleta.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';
$syncFile = dirname(__DIR__) . '/src/sync_nube.php';
if (is_file($syncFile)) {
    require_once $syncFile;
}

$usuarioActual = requerirAutenticacion();
$esRemoto = tieneRol($usuarioActual, ['REMOTO']);
$puedeAnular = !$esRemoto && tieneRol($usuarioActual, ['SUPER', 'ADMINISTRADOR', 'SUPERVISOR']);
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombre = $usuarioActual['empresa_nombre'] ?? 'BÁSCULA INDUSTRIAL';
$pdo = obtenerDB();

$mensajeExito = null;
$mensajeError = null;

// 0. PROCESAR ANULACIÓN DE PESADA (POST + CSRF + RBAC)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'anular') {
    if (!$puedeAnular) {
        http_response_code(403);
        die('Acceso denegado: no tienes permisos para anular pesadas.');
    }

    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $mensajeError = 'Token de seguridad inválido o sesión expirada. Recarga la página e intenta de nuevo.';
    } else {
        $idAnular = (int)($_POST['idpesada'] ?? 0);
        $motivo   = trim((string)($_POST['motivo'] ?? ''));

        if ($idAnular <= 0 || $motivo === '') {
            $mensajeError = 'Debes indicar un motivo válido para anular la boleta.';
        } else {
            if (mb_strlen($motivo) > 500) {
                $motivo = mb_substr($motivo, 0, 500);
            }

            try {
                $pdo->beginTransaction();

                $stmtSel = $pdo->prepare("
                    SELECT idpesada, estado, tipo_operacion, id_compra, id_venta,
                           peso_bruto_lb, tara_vehiculo_lb, origen_bruto, origen_tara, primera_pesada_at
                    FROM t_pesadas
                    WHERE idpesada = :id AND empresa_id = :empresa_id
                    FOR UPDATE
                ");
                $stmtSel->execute([':id' => $idAnular, ':empresa_id' => $empresaId]);
                $fila = $stmtSel->fetch(PDO::FETCH_ASSOC);

                if (!$fila) {
                    throw new RuntimeException('La boleta seleccionada no existe.');
                }
                if ($fila['estado'] === 'ANULADA') {
                    throw new RuntimeException('Esta boleta ya se encuentra anulada.');
                }

                $uid = (int)$usuarioActual['id'];

                if ($fila['estado'] === 'CERRADA') {
                    $stmtUpd = $pdo->prepare("
                        UPDATE t_pesadas
                        SET estado = 'ANULADA'
                        WHERE idpesada = :id AND empresa_id = :empresa_id
                    ");
                    $stmtUpd->execute([':id' => $idAnular, ':empresa_id' => $empresaId]);
                } else {
                    // Nota ABIERTA: completar campos requeridos por chk_pesadas_capturas y chk_pesadas_emision
                    $ahora = date('Y-m-d H:i:s');
                    $primeraAt = (string)($fila['primera_pesada_at'] ?? $ahora);
                    if ($ahora < $primeraAt) {
                        $ahora = $primeraAt;
                    }

                    $bruto = $fila['peso_bruto_lb'] !== null ? (float)$fila['peso_bruto_lb'] : (float)($fila['tara_vehiculo_lb'] ?? 0);
                    $tara  = $fila['tara_vehiculo_lb'] !== null ? (float)$fila['tara_vehiculo_lb'] : $bruto;

                    $stmtUpd = $pdo->prepare("
                        UPDATE t_pesadas
                        SET estado = 'ANULADA',
                            peso_bruto_lb = COALESCE(peso_bruto_lb, :bruto),
                            bruto_capturado_por = COALESCE(bruto_capturado_por, :uid1),
                            origen_bruto = COALESCE(origen_bruto, origen_tara, 'MANUAL'),
                            tara_vehiculo_lb = COALESCE(tara_vehiculo_lb, :tara),
                            tara_capturada_por = COALESCE(tara_capturada_por, :uid2),
                            origen_tara = COALESCE(origen_tara, origen_bruto, 'MANUAL'),
                            segunda_pesada_at = :segunda_at,
                            emitida_at = :emitida_at,
                            emitida_por = :uid3,
                            peso_final_lb = 0
                        WHERE idpesada = :id AND empresa_id = :empresa_id
                    ");
                    $stmtUpd->execute([
                        ':bruto'      => $bruto,
                        ':uid1'       => $uid,
                        ':tara'       => $tara,
                        ':uid2'       => $uid,
                        ':segunda_at' => $ahora,
                        ':emitida_at' => $ahora,
                        ':uid3'       => $uid,
                        ':id'         => $idAnular,
                        ':empresa_id' => $empresaId,
                    ]);
                }

                $stmtAnul = $pdo->prepare("
                    INSERT INTO t_pesada_anulaciones (idpesada, anulado_por, motivo)
                    VALUES (:id, :uid, :motivo)
                ");
                $stmtAnul->execute([
                    ':id'     => $idAnular,
                    ':uid'    => $uid,
                    ':motivo' => $motivo,
                ]);

                $pdo->commit();

                if (function_exists('sincronizarPesadaAutomatica')) {
                    try {
                        sincronizarPesadaAutomatica($idAnular);
                    } catch (Throwable $eSync) {
                        // La anulación local ya quedó confirmada
                    }
                }

                $numBoleta = $fila['tipo_operacion'] === 'COMPRA'
                    ? '#' . sprintf('%07d', (int)$fila['id_compra'])
                    : '#' . sprintf('%07d', (int)$fila['id_venta']);
                $mensajeExito = "Boleta {$numBoleta} anulada correctamente.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensajeError = 'No se pudo anular la boleta: ' . $e->getMessage();
            }
        }
    }
}

$csrfToken = obtenerCsrfToken();

// 1. FILTROS DE CONSULTA
$busqueda   = trim((string)($_GET['q'] ?? ''));
$filtroTipo = trim((string)($_GET['tipo'] ?? 'TODOS')); // TODOS, COMPRA, VENTA
$filtroEst  = trim((string)($_GET['estado'] ?? 'TODOS')); // TODOS, CERRADA, ABIERTA, ANULADA
$filtroFecha = trim((string)($_GET['fecha'] ?? '')); // YYYY-MM-DD

$sql = "
    SELECT p.idpesada, p.tipo_operacion, p.id_compra, p.id_venta, p.estado,
           p.placa, p.motorista, p.peso_bruto_lb, p.tara_vehiculo_lb, p.peso_final_lb,
           p.fecha_at, p.primera_pesada_at, p.segunda_pesada_at,
           cp.nombre AS persona_nombre, prod.nombre AS producto_nombre, pr.nombre AS presentacion_nombre
    FROM t_pesadas p
    JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
    JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
    JOIN t_productos prod ON prod.id = pr.producto_id
    WHERE p.empresa_id = :empresa_id
    ORDER BY p.idpesada DESC LIMIT 500
";
$params = [':empresa_id' => $empresaId];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$boletas = $stmt->fetchAll(PDO::FETCH_ASSOC);

renderizarEncabezado('Boletas e Historial', 'pesadas');
?>

<div style="max-width: 1100px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
                CONSULTAS
            </span>
            <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.2rem 0 0.2rem 0; color: var(--text-main); line-height: 1.1;">
                Boletas e Historial
            </h1>
            <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <a href="index.php" class="btn btn-secondary" style="padding: 0.65rem 1.15rem; font-weight: 600; text-decoration: none; border-radius: 6px;">
                Volver al Inicio
            </a>
            <?php if (!$esRemoto): ?>
            <a href="primera_pesada.php" class="btn btn-primary" style="padding: 0.65rem 1.25rem; font-weight: 700; background: #0b57d0; text-decoration: none; border-radius: 6px;">
                + Nueva Primera Pesada
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- TARJETA PRINCIPAL -->
    <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">

        <?php if ($mensajeExito): ?>
            <div style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-weight: 600;">
                ✅ <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($mensajeError): ?>
            <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-weight: 600;">
                ⚠️ <?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- FILTROS RÁPIDOS -->
        <form method="GET" action="pesadas.php" id="formFiltrosHistorial" style="display: flex; gap: 0.85rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1.75rem;">
            <div style="flex: 1; min-width: 220px;">
                <label for="q" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                    Buscar placa, cliente o # boleta
                </label>
                <input type="search" id="q" name="q" class="form-control" placeholder="Ej. 0000003, HAA1234, Vallecillo..." value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" style="height: 40px; border-radius: 6px;">
            </div>

            <div style="width: 140px;">
                <label for="tipo" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                    Operación
                </label>
                <select id="tipo" name="tipo" class="form-control" style="height: 40px; border-radius: 6px;">
                    <option value="TODOS" <?= $filtroTipo === 'TODOS' ? 'selected' : '' ?>>Todas</option>
                    <option value="COMPRA" <?= $filtroTipo === 'COMPRA' ? 'selected' : '' ?>>Compra</option>
                    <option value="VENTA" <?= $filtroTipo === 'VENTA' ? 'selected' : '' ?>>Venta</option>
                </select>
            </div>

            <div style="width: 140px;">
                <label for="estado" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                    Estado
                </label>
                <select id="estado" name="estado" class="form-control" style="height: 40px; border-radius: 6px;">
                    <option value="TODOS" <?= $filtroEst === 'TODOS' ? 'selected' : '' ?>>Todos</option>
                    <option value="CERRADA" <?= $filtroEst === 'CERRADA' ? 'selected' : '' ?>>Cerradas</option>
                    <option value="ABIERTA" <?= $filtroEst === 'ABIERTA' ? 'selected' : '' ?>>Abiertas</option>
                </select>
            </div>

            <div style="width: 160px;">
                <label for="fecha" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                    Fecha
                </label>
                <input type="date" id="fecha" name="fecha" class="form-control" value="<?= htmlspecialchars($filtroFecha, ENT_QUOTES, 'UTF-8') ?>" style="height: 40px; border-radius: 6px;">
            </div>

            <button type="submit" class="btn btn-secondary" style="height: 40px; padding: 0 1.25rem; font-weight: 600; border-radius: 6px; background: #e8f0fe; color: #1e40af; border: none;">
                Filtrar
            </button>

            <button type="button" id="btnLimpiarFiltrosHistorial" class="btn btn-secondary" style="height: 40px; padding: 0 0.85rem; display: none; align-items: center; border-radius: 6px;" title="Limpiar filtros">&times; Limpiar</button>
        </form>

        <!-- TABLA DE HISTORIAL -->
        <div class="table-responsive" style="border: none; background: transparent;">
            <table class="table" style="border-collapse: separate; border-spacing: 0; min-width: 100%;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="background: transparent; color: #475569; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; padding: 0.75rem 0.5rem;">Boleta</th>
                        <th style="background: transparent; color: #475569; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; padding: 0.75rem 0.5rem;">Fecha / Hora</th>
                        <th style="background: transparent; color: #475569; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; padding: 0.75rem 0.5rem;">Placa</th>
                        <th style="background: transparent; color: #475569; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; padding: 0.75rem 0.5rem;">Cliente / Proveedor</th>
                        <th style="background: transparent; color: #475569; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; padding: 0.75rem 0.5rem;">Producto</th>
                        <th style="background: transparent; color: #475569; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; padding: 0.75rem 0.5rem; text-align: right;">Peso Final</th>
                        <th style="background: transparent; color: #475569; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; padding: 0.75rem 0.5rem; text-align: center;">Estado</th>
                        <th style="background: transparent; color: #475569; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; padding: 0.75rem 0.5rem; text-align: right;">Acción</th>
                    </tr>
                </thead>
                <tbody id="tablaHistorialBody">
                    <?php if (empty($boletas)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 3.5rem 1rem;">
                                No se encontraron boletas en el historial con los filtros indicados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($boletas as $b): 
                            $numCorr = ($b['tipo_operacion'] === 'COMPRA') ? (int)$b['id_compra'] : (int)$b['id_venta'];
                            $numFormato = '# ' . sprintf('%07d', $numCorr);
                            $numSinEspacio = '#' . sprintf('%07d', $numCorr);
                            $fechaFmt = date('d/m/Y H:i', strtotime($b['segunda_pesada_at'] ?? $b['fecha_at']));
                            $fechaIso = date('Y-m-d', strtotime((string)$b['fecha_at']));
                            $esCerrada = ($b['estado'] === 'CERRADA');
                            $esAnulada = ($b['estado'] === 'ANULADA');
                            $textoBusq = trim(implode(' ', [
                                $numFormato,
                                $numSinEspacio,
                                (string)$numCorr,
                                (string)($b['placa'] ?? ''),
                                (string)($b['persona_nombre'] ?? ''),
                                (string)($b['producto_nombre'] ?? ''),
                                (string)($b['presentacion_nombre'] ?? ''),
                                (string)$b['tipo_operacion'],
                            ]));
                        ?>
                            <tr class="fila-historial" data-op="<?= htmlspecialchars($b['tipo_operacion'], ENT_QUOTES, 'UTF-8') ?>" data-est="<?= htmlspecialchars($b['estado'], ENT_QUOTES, 'UTF-8') ?>" data-fecha="<?= htmlspecialchars($fechaIso, ENT_QUOTES, 'UTF-8') ?>" data-texto="<?= htmlspecialchars($textoBusq, ENT_QUOTES, 'UTF-8') ?>" style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 1rem 0.5rem; font-family: monospace; font-weight: 800; font-size: 0.95rem; color: <?= ($b['tipo_operacion'] === 'COMPRA' ? '#1e40af' : '#047857') ?>;">
                                    <?php if ($esCerrada): ?>
                                        <a href="boleta.php?id=<?= (int)$b['idpesada'] ?>" target="_blank" style="color: inherit; text-decoration: none;" title="Abrir vista de impresión de boleta">
                                            <?= htmlspecialchars($numFormato, ENT_QUOTES, 'UTF-8') ?>
                                            <div style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; font-family: sans-serif;"><?= $b['tipo_operacion'] ?></div>
                                        </a>
                                    <?php elseif (!$esAnulada && !$esRemoto): ?>
                                        <a href="segunda_pesada.php" style="color: inherit; text-decoration: none;" title="Ver camiones pendientes en patio">
                                            <?= htmlspecialchars($numFormato, ENT_QUOTES, 'UTF-8') ?>
                                            <div style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; font-family: sans-serif;"><?= $b['tipo_operacion'] ?></div>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($numFormato, ENT_QUOTES, 'UTF-8') ?>
                                        <div style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; font-family: sans-serif;"><?= $b['tipo_operacion'] ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem 0.5rem; font-size: 0.85rem; color: #475569;">
                                    <?= $fechaFmt ?>
                                </td>
                                <td style="padding: 1rem 0.5rem; font-weight: 800; font-family: monospace; font-size: 0.95rem; color: var(--text-main);">
                                    <?= htmlspecialchars((string)($b['placa'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="padding: 1rem 0.5rem; font-weight: 600; color: var(--text-main); font-size: 0.92rem;">
                                    <?= htmlspecialchars((string)($b['persona_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="padding: 1rem 0.5rem; font-size: 0.88rem; color: var(--text-muted);">
                                    <?= htmlspecialchars((string)($b['producto_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                    <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars((string)($b['presentacion_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td style="padding: 1rem 0.5rem; text-align: right; font-weight: 800; font-size: 1rem; color: var(--text-main);">
                                    <?php if ($esAnulada): ?>
                                        <span style="color: #dc2626; font-weight: 700; font-size: 0.85rem;">Anulada</span>
                                    <?php elseif ($esCerrada): ?>
                                        <?= number_format((float)$b['peso_final_lb'], 0) ?> lb
                                        <div style="font-size: 0.75rem; color: #1e40af; font-weight: 700;"><?= number_format((float)$b['peso_final_lb'] / 100.0, 2) ?> QQ</div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-weight: normal; font-size: 0.85rem;">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem 0.5rem; text-align: center;">
                                    <?php if ($esAnulada): ?>
                                        <span style="background: #fef2f2; color: #dc2626; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 999px;">
                                            Anulada
                                        </span>
                                    <?php elseif ($esCerrada): ?>
                                        <span style="background: #ecfdf5; color: #047857; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 999px;">
                                            Cerrada
                                        </span>
                                    <?php else: ?>
                                        <span style="background: #fef3c7; color: #b45309; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 999px;">
                                            Abierta
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem 0.5rem; text-align: right; white-space: nowrap;">
                                    <?php if ($esAnulada): ?>
                                        <span style="font-size: 0.8rem; color: #dc2626; font-weight: 700;">Sin acciones</span>
                                    <?php elseif ($esCerrada): ?>
                                        <div style="display: inline-flex; gap: 0.45rem; align-items: center;">
                                            <a href="boleta.php?id=<?= (int)$b['idpesada'] ?>" target="_blank" class="btn btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.82rem; font-weight: 700; color: #1e40af; border: 1px solid #bfdbfe; background: #eff6ff; text-decoration: none; border-radius: 5px;" title="Ver e imprimir boleta">
                                                🖨️ Boleta
                                            </a>
                                            <?php if ($b['tipo_operacion'] === 'VENTA'): ?>
                                                <a href="guia_remision.php?id=<?= (int)$b['idpesada'] ?>" target="_blank" class="btn btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.82rem; font-weight: 700; color: #047857; border: 1px solid #a7f3d0; background: #ecfdf5; text-decoration: none; border-radius: 5px;" title="Imprimir Guía de Remisión">
                                                    🚚 Guía Remisión
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($puedeAnular): ?>
                                                <button type="button" onclick="abrirModalAnular(<?= (int)$b['idpesada'] ?>, '<?= htmlspecialchars($numFormato . ' (' . ($b['placa'] ?? '') . ')', ENT_QUOTES, 'UTF-8') ?>')" style="padding: 0.35rem 0.65rem; font-size: 0.82rem; font-weight: 700; color: #dc2626; border: 1px solid #fecaca; background: #fef2f2; border-radius: 5px; cursor: pointer;" title="Anular esta boleta">
                                                    ✕ Anular
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($esRemoto): ?>
                                        <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">En patio (solo consulta)</span>
                                    <?php else: ?>
                                        <div style="display: inline-flex; gap: 0.45rem; align-items: center;">
                                            <a href="segunda_pesada.php?id=<?= $b['idpesada'] ?>" class="btn btn-primary" style="padding: 0.35rem 0.85rem; font-size: 0.85rem; font-weight: 700; background: #0b57d0; text-decoration: none; border-radius: 5px;">
                                                Completar &rarr;
                                            </a>
                                            <?php if ($puedeAnular): ?>
                                                <button type="button" onclick="abrirModalAnular(<?= (int)$b['idpesada'] ?>, '<?= htmlspecialchars($numFormato . ' (' . ($b['placa'] ?? '') . ')', ENT_QUOTES, 'UTF-8') ?>')" style="padding: 0.35rem 0.65rem; font-size: 0.82rem; font-weight: 700; color: #dc2626; border: 1px solid #fecaca; background: #fef2f2; border-radius: 5px; cursor: pointer;" title="Anular esta boleta">
                                                    ✕ Anular
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="filaSinCoincidenciasHistorial" style="display: none;">
                            <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                No se encontraron boletas en el historial con los filtros indicados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($puedeAnular): ?>
<div id="modalAnular" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
    <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: 12px; max-width: 440px; width: 100%; padding: 1.5rem; box-shadow: 0 20px 35px rgba(0,0,0,0.25);">
        <h3 style="margin: 0 0 0.35rem 0; color: #dc2626; font-size: 1.15rem;">Anular Boleta <span id="anularLabel"></span></h3>
        <p style="margin: 0 0 1rem 0; font-size: 0.85rem; color: var(--text-muted);">Esta acción marcará la pesada como <strong>ANULADA</strong> y quedará registrada en la bitácora de auditoría.</p>
        <form method="POST" action="pesadas.php">
            <input type="hidden" name="accion" value="anular">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="idpesada" id="anularIdPesada" value="">
            <label for="anularMotivo" style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">Motivo de anulación (obligatorio):</label>
            <textarea id="anularMotivo" name="motivo" rows="3" maxlength="500" required class="form-control" placeholder="Ej: Error en placa o producto seleccionado..." style="width: 100%; margin-bottom: 1rem;"></textarea>
            <div style="display: flex; justify-content: flex-end; gap: 0.6rem;">
                <button type="button" onclick="cerrarModalAnular()" class="btn btn-secondary" style="padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">Cancelar</button>
                <button type="submit" style="padding: 0.5rem 1rem; border-radius: 6px; border: none; background: #dc2626; color: #fff; font-weight: 700; cursor: pointer;">Confirmar Anulación</button>
            </div>
        </form>
    </div>
</div>
<script>
function abrirModalAnular(id, etiqueta) {
    document.getElementById('anularIdPesada').value = id;
    document.getElementById('anularLabel').textContent = etiqueta;
    document.getElementById('anularMotivo').value = '';
    document.getElementById('modalAnular').style.display = 'flex';
    document.getElementById('anularMotivo').focus();
}
function cerrarModalAnular() {
    document.getElementById('modalAnular').style.display = 'none';
}
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputQ = document.getElementById('q');
    const selTipo = document.getElementById('tipo');
    const selEst = document.getElementById('estado');
    const inpFecha = document.getElementById('fecha');
    const btnLimpiar = document.getElementById('btnLimpiarFiltrosHistorial');
    const formFiltros = document.getElementById('formFiltrosHistorial');
    const filas = Array.from(document.querySelectorAll('#tablaHistorialBody tr.fila-historial'));
    const filaVacia = document.getElementById('filaSinCoincidenciasHistorial');

    const normalizar = (t) => String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/#/g, ' ').replace(/\s+/g, ' ').toLowerCase().trim();
    const compactar = (t) => normalizar(t).replace(/\s+/g, '');

    function filtrarHistorialEnVivo() {
        const q = normalizar(inputQ ? inputQ.value : '');
        const qCompacta = compactar(inputQ ? inputQ.value : '');
        const tokens = q ? q.split(' ').filter(Boolean) : [];
        const op = selTipo ? selTipo.value : 'TODOS';
        const est = selEst ? selEst.value : 'TODOS';
        const fecha = inpFecha ? inpFecha.value : '';

        let visibles = 0;
        filas.forEach(tr => {
            const texto = normalizar(tr.getAttribute('data-texto'));
            const matchQ = (q === '' || tokens.every(tok => texto.includes(tok)) || compactar(texto).includes(qCompacta));
            const matchOp = (op === 'TODOS' || tr.getAttribute('data-op') === op);
            const matchEst = (est === 'TODOS' || tr.getAttribute('data-est') === est);
            const matchFecha = (fecha === '' || tr.getAttribute('data-fecha') === fecha);

            const mostrar = matchQ && matchOp && matchEst && matchFecha;
            tr.style.display = mostrar ? '' : 'none';
            if (mostrar) visibles++;
        });

        if (filaVacia) {
            filaVacia.style.display = (filas.length > 0 && visibles === 0) ? '' : 'none';
        }
        if (btnLimpiar) {
            const hayFiltros = (q !== '' || op !== 'TODOS' || est !== 'TODOS' || fecha !== '');
            btnLimpiar.style.display = hayFiltros ? 'inline-flex' : 'none';
        }
    }

    if (inputQ) {
        inputQ.addEventListener('input', filtrarHistorialEnVivo);
        inputQ.addEventListener('search', filtrarHistorialEnVivo);
    }
    if (selTipo) selTipo.addEventListener('change', filtrarHistorialEnVivo);
    if (selEst) selEst.addEventListener('change', filtrarHistorialEnVivo);
    if (inpFecha) {
        inpFecha.addEventListener('change', filtrarHistorialEnVivo);
        inpFecha.addEventListener('input', filtrarHistorialEnVivo);
    }
    if (btnLimpiar) {
        btnLimpiar.addEventListener('click', function() {
            if (inputQ) inputQ.value = '';
            if (selTipo) selTipo.value = 'TODOS';
            if (selEst) selEst.value = 'TODOS';
            if (inpFecha) inpFecha.value = '';
            filtrarHistorialEnVivo();
        });
    }
    if (formFiltros) {
        formFiltros.addEventListener('submit', function(e) {
            e.preventDefault();
            filtrarHistorialEnVivo();
        });
    }

    filtrarHistorialEnVivo();
});
</script>

<?php
renderizarPie();
