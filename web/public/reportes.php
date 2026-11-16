<?php
/**
 * Módulo de Reportes por Fechas y Cliente / Proveedor
 * Proyecto: tytBasculaAntigravity
 *
 * Fiel a la captura:
 * - Filtros: Empresa, Tipo de persona, Cliente / Proveedor, Fechas Desde / Hasta.
 * - Acciones: Generar reporte, Imprimir (Carta horizontal), Generar Excel, Descargar PDF, Abrir WhatsApp Web.
 * - Tabla detallada de boletas cerradas con subtítulo de auditoría y fila de TOTALES.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombre = $usuarioActual['empresa_nombre'] ?? 'INVERSIONES VALLECILLO, SB';
$pdo = obtenerDB();

// 1. CARGA DE LISTAS PARA LOS FILTROS
$stmtEmp = $pdo->query("SELECT id, razon_social, nombre_comercial FROM t_empresas WHERE activo = 1 ORDER BY razon_social ASC");
$listaEmpresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

$stmtPersonas = $pdo->prepare("SELECT id, nombre, es_cliente, es_proveedor FROM t_clie_prov WHERE empresa_id = :empresa_id ORDER BY nombre ASC");
$stmtPersonas->execute([':empresa_id' => $empresaId]);
$listaPersonas = $stmtPersonas->fetchAll(PDO::FETCH_ASSOC);

// 2. PARÁMETROS DEL FILTRO
$tipoPersona = trim((string)($_GET['tipo_persona'] ?? 'TODOS')); // TODOS, CLIENTES, PROVEEDORES
$personaId   = (int)($_GET['persona_id'] ?? 0);
$fechaDesde  = trim((string)($_GET['desde'] ?? date('Y-m-01'))); // Por defecto 1er día del mes
$fechaHasta  = trim((string)($_GET['hasta'] ?? date('Y-m-d')));

// 3. CONSULTA DE BOLETAS CERRADAS
$sql = "
    SELECT p.*,
           cp.nombre AS persona_nombre,
           pr.nombre AS presentacion_nombre,
           prod.nombre AS producto_nombre,
           u_resp.nombre AS responsable_nombre,
           u_ab.nombre AS abierto_por_nombre,
           u_bruto.nombre AS bruto_por_nombre,
           u_tara.nombre AS tara_por_nombre,
           u_cerr.nombre AS cerrado_por_nombre
    FROM t_pesadas p
    JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
    JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
    JOIN t_productos prod ON prod.id = pr.producto_id
    LEFT JOIN t_usuarios u_resp ON u_resp.id = p.responsable_id
    LEFT JOIN t_usuarios u_ab ON u_ab.id = p.abierto_por
    LEFT JOIN t_usuarios u_bruto ON u_bruto.id = p.bruto_capturado_por
    LEFT JOIN t_usuarios u_tara ON u_tara.id = p.tara_capturada_por
    LEFT JOIN t_usuarios u_cerr ON u_cerr.id = p.emitida_por
    WHERE p.empresa_id = :empresa_id AND p.estado = 'CERRADA'
";
$params = [':empresa_id' => $empresaId];

if ($fechaDesde !== '') {
    $sql .= " AND DATE(p.segunda_pesada_at) >= :desde";
    $params[':desde'] = $fechaDesde;
}

if ($fechaHasta !== '') {
    $sql .= " AND DATE(p.segunda_pesada_at) <= :hasta";
    $params[':hasta'] = $fechaHasta;
}

if ($personaId > 0) {
    $sql .= " AND p.clie_prov_id = :persona_id";
    $params[':persona_id'] = $personaId;
} elseif ($tipoPersona === 'CLIENTES') {
    $sql .= " AND cp.es_cliente = 1";
} elseif ($tipoPersona === 'PROVEEDORES') {
    $sql .= " AND cp.es_proveedor = 1";
}

$sql .= " ORDER BY p.segunda_pesada_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reporteBoletas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CÁLCULO DE TOTALES
$totBruto = 0.0;
$totTaraVehiculo = 0.0;
$totNeto = 0.0;
$totEmpaques = 0;
$totTaraEmpaques = 0.0;
$totBase = 0.0;
$totFinal = 0.0;

foreach ($reporteBoletas as $b) {
    $bruto = (float)$b['peso_bruto_lb'];
    $taraV = (float)$b['tara_vehiculo_lb'];
    $neto  = max(0.0, $bruto - $taraV);
    $cantEmp = (int)$b['cantidad_empaques'];
    $taraEmp = round((float)($b['tara_unitaria_aplicada_lb'] ?? 0) * $cantEmp);
    $base  = (float)($b['base_descuentos_lb'] ?? ($neto - $taraEmp));
    $final = (float)($b['peso_final_lb'] ?? $base);

    $totBruto += $bruto;
    $totTaraVehiculo += $taraV;
    $totNeto += $neto;
    $totEmpaques += $cantEmp;
    $totTaraEmpaques += $taraEmp;
    $totBase += $base;
    $totFinal += $final;
}

// 4. DESCARGA EXCEL EN FORMATO CSV LIMPIO (SI SE SOLICITA)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_boletas_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

    fputcsv($out, ['Entrada/Primera', 'Salida/Segunda', 'Boleta', 'Operacion', 'Cliente/Proveedor', 'Producto', 'Presentacion', 'Bruto lb', 'Tara lb', 'Neto lb', 'Empaques', 'Tara Empaques lb', 'Base lb', 'Final lb', 'Total QQ']);
    foreach ($reporteBoletas as $b) {
        $numB = ($b['tipo_operacion'] === 'COMPRA') ? '#' . sprintf('%07d', $b['id_compra']) : '#' . sprintf('%07d', $b['id_venta']);
        fputcsv($out, [
            $b['primera_pesada_at'],
            $b['segunda_pesada_at'],
            $numB,
            $b['tipo_operacion'],
            $b['persona_nombre'],
            $b['producto_nombre'],
            $b['presentacion_nombre'],
            $b['peso_bruto_lb'],
            $b['tara_vehiculo_lb'],
            max(0, (float)$b['peso_bruto_lb'] - (float)$b['tara_vehiculo_lb']),
            $b['cantidad_empaques'],
            round((float)($b['tara_unitaria_aplicada_lb'] ?? 0) * (int)$b['cantidad_empaques']),
            $b['base_descuentos_lb'],
            $b['peso_final_lb'],
            round((float)$b['peso_final_lb'] / 100.0, 2)
        ]);
    }
    exit;
}

renderizarEncabezado('Reporte de Boletas', 'reportes');
?>

<div style="max-width: 1180px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div style="margin-bottom: 1.5rem;">
        <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
            REPORTES
        </span>
        <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.2rem 0 0.2rem 0; color: var(--text-main); line-height: 1.1;">
            Reporte por fechas y cliente / proveedor
        </h1>
        <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
            <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>

    <!-- TARJETA DE FILTROS SUPERIORES -->
    <div class="card" style="padding: 2rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 2rem;">
        <form method="GET" action="reportes.php" id="formReporte">
            <div style="display: grid; grid-template-columns: 1.2fr 1fr 1.2fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem; align-items: flex-end;">
                <div>
                    <label for="empresa_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Empresa
                    </label>
                    <select id="empresa_id" name="empresa_id" class="form-control" style="height: 42px; border-radius: 6px;">
                        <?php foreach ($listaEmpresas as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $emp['id'] === $empresaId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['nombre_comercial'] ?: $emp['razon_social'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="tipo_persona" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Tipo de persona
                    </label>
                    <select id="tipo_persona" name="tipo_persona" class="form-control" style="height: 42px; border-radius: 6px;" onchange="filtrarTipoPersona(this.value)">
                        <option value="TODOS" <?= $tipoPersona === 'TODOS' ? 'selected' : '' ?>>Clientes y proveedores</option>
                        <option value="CLIENTES" <?= $tipoPersona === 'CLIENTES' ? 'selected' : '' ?>>Clientes</option>
                        <option value="PROVEEDORES" <?= $tipoPersona === 'PROVEEDORES' ? 'selected' : '' ?>>Proveedores</option>
                    </select>
                </div>

                <div>
                    <label for="persona_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Cliente / proveedor
                    </label>
                    <select id="persona_id" name="persona_id" class="form-control" style="height: 42px; border-radius: 6px;">
                        <option value="0">Todos</option>
                        <?php foreach ($listaPersonas as $p): ?>
                            <option value="<?= $p['id'] ?>" data-cli="<?= $p['es_cliente'] ?>" data-prov="<?= $p['es_proveedor'] ?>" <?= $p['id'] === $personaId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="desde" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Desde
                    </label>
                    <input type="date" id="desde" name="desde" class="form-control" style="height: 42px; border-radius: 6px;" value="<?= htmlspecialchars($fechaDesde, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div style="display: flex; gap: 1.25rem; align-items: flex-end; margin-bottom: 1.5rem;">
                <div style="width: 220px;">
                    <label for="hasta" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Hasta
                    </label>
                    <input type="date" id="hasta" name="hasta" class="form-control" style="height: 42px; border-radius: 6px;" value="<?= htmlspecialchars($fechaHasta, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <button type="submit" class="btn btn-primary" style="height: 42px; padding: 0 1.5rem; font-weight: 700; background: #0b57d0; border-radius: 6px;">
                    Generar reporte
                </button>
            </div>

            <p style="font-size: 0.85rem; color: #475569; margin: 0 0 1.5rem 0; line-height: 1.4;">
                Boletas cerradas por fecha de cierre, incluyendo ambos días. Puedes elegir una persona y/o su tipo en el catálogo, incluso inactivos con historial. Empresa: <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>.
            </p>

            <!-- BOTONES DE EXPORTACIÓN Y ENVÍO -->
            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                <button type="button" onclick="imprimirReporte()" class="btn btn-primary" style="background: #0b57d0; padding: 0.55rem 1.25rem; font-weight: 700; border-radius: 6px;">
                    Imprimir
                </button>
                <a href="reportes.php?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-secondary" style="padding: 0.55rem 1.25rem; font-weight: 600; text-decoration: none; border-radius: 6px;">
                    Generar Excel
                </a>
                <button type="button" onclick="imprimirReporte()" class="btn btn-secondary" style="padding: 0.55rem 1.25rem; font-weight: 600; border-radius: 6px;">
                    Descargar PDF
                </button>
                <a href="https://web.whatsapp.com/" target="_blank" class="btn btn-secondary" style="padding: 0.55rem 1.25rem; font-weight: 600; text-decoration: none; border-radius: 6px;">
                    Abrir WhatsApp Web
                </a>
            </div>
            <small style="display: block; font-size: 0.75rem; color: var(--text-muted); margin-top: 0.85rem;">
                Primero descarga el PDF; queda guardado en __pdfs. Después abre WhatsApp, selecciona el destinatario y adjunta el archivo como documento. En computadora se abre WhatsApp Web; en móvil, la aplicación si está instalada.
            </small>
        </form>
    </div>

    <!-- TARJETA DEL REPORTE IMPRIMIBLE -->
    <div id="seccionReporteImprimible" class="card" style="padding: 2.5rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
        
        <div style="text-align: center; margin-bottom: 2rem;">
            <h2 style="font-size: 1.5rem; font-weight: 800; margin: 0 0 0.35rem 0; color: var(--text-main); text-transform: uppercase;">
                <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <h3 style="font-size: 1.25rem; font-weight: 900; letter-spacing: 0.5px; margin: 0 0 0.35rem 0; color: #0f233a;">
                REPORTE DE BOLETAS
            </h3>
            <div style="font-size: 0.85rem; color: #64748b; font-weight: 600;">
                Del <?= date('d/m/Y', strtotime($fechaDesde)) ?> al <?= date('d/m/Y', strtotime($fechaHasta)) ?> &middot; Fecha de cierre
            </div>
        </div>

        <div class="table-responsive" style="border: none; background: transparent; overflow-x: auto;">
            <table class="table" style="border-collapse: collapse; min-width: 100%; font-size: 0.82rem;">
                <thead>
                    <tr style="border-top: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1; background: #f8fafc;">
                        <th style="padding: 0.65rem 0.4rem; text-align: left; font-weight: 700; color: #475569;">Entrada / primera</th>
                        <th style="padding: 0.65rem 0.4rem; text-align: left; font-weight: 700; color: #475569;">Salida / segunda</th>
                        <th style="padding: 0.65rem 0.4rem; text-align: left; font-weight: 700; color: #475569;">Boleta</th>
                        <th style="padding: 0.65rem 0.4rem; text-align: left; font-weight: 700; color: #475569;">Cliente / proveedor</th>
                        <th style="padding: 0.65rem 0.4rem; text-align: left; font-weight: 700; color: #475569;">Producto</th>
                        <th style="padding: 0.65rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Bruto lb</th>
                        <th style="padding: 0.65rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Neto lb</th>
                        <th style="padding: 0.65rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Tara lb</th>
                        <th style="padding: 0.65rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Empaques</th>
                        <th style="padding: 0.65rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Tara empaques lb</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reporteBoletas)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                No hay boletas cerradas registradas para el rango de fechas seleccionado.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reporteBoletas as $row): 
                            $bruto = (float)$row['peso_bruto_lb'];
                            $taraV = (float)$row['tara_vehiculo_lb'];
                            $neto  = max(0.0, $bruto - $taraV);
                            $cantEmp = (int)$row['cantidad_empaques'];
                            $taraEmp = round((float)($row['tara_unitaria_aplicada_lb'] ?? 0) * $cantEmp);

                            $numBoleta = ($row['tipo_operacion'] === 'COMPRA') 
                                ? 'Compra #' . $row['id_compra'] 
                                : 'Venta #' . $row['id_venta'];
                            
                            $fEntrada = !empty($row['primera_pesada_at']) ? date('d/m/Y H:i', strtotime($row['primera_pesada_at'])) : '—';
                            $fSalida  = !empty($row['segunda_pesada_at']) ? date('d/m/Y H:i', strtotime($row['segunda_pesada_at'])) : '—';
                        ?>
                            <!-- FILA PRINCIPAL -->
                            <tr style="border-bottom: 1px dotted #e2e8f0;">
                                <td style="padding: 0.65rem 0.4rem;"><?= $fEntrada ?></td>
                                <td style="padding: 0.65rem 0.4rem;"><?= $fSalida ?></td>
                                <td style="padding: 0.65rem 0.4rem; font-weight: 700; color: #1e40af;"><?= htmlspecialchars($numBoleta, ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding: 0.65rem 0.4rem; font-weight: 600;"><?= htmlspecialchars($row['persona_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding: 0.65rem 0.4rem;"><?= htmlspecialchars($row['producto_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding: 0.65rem 0.4rem; text-align: right;"><?= number_format($bruto, 0) ?></td>
                                <td style="padding: 0.65rem 0.4rem; text-align: right; font-weight: 700;"><?= number_format($neto, 0) ?></td>
                                <td style="padding: 0.65rem 0.4rem; text-align: right;"><?= number_format($taraV, 0) ?></td>
                                <td style="padding: 0.65rem 0.4rem; text-align: right;"><?= $cantEmp ?></td>
                                <td style="padding: 0.65rem 0.4rem; text-align: right;"><?= number_format($taraEmp, 0) ?></td>
                            </tr>
                            <!-- FILA SECUNDARIA DE DETALLE / AUDITORÍA (FIEL A IMAGEN) -->
                            <tr style="border-bottom: 1px solid #cbd5e1; background: #fafafa;">
                                <td colspan="10" style="padding: 0.35rem 0.4rem; font-size: 0.72rem; color: #64748b;">
                                    Presentación: <?= htmlspecialchars($row['presentacion_nombre'], ENT_QUOTES, 'UTF-8') ?> &middot; 
                                    Tipo primera: <?= $row['tipo_primera_pesada'] ?> &middot; 
                                    Fecha registro: <?= $row['fecha_at'] ?> &middot; 
                                    Fecha cierre: <?= $row['segunda_pesada_at'] ?> &middot; 
                                    Responsable: <?= htmlspecialchars($row['responsable_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?> &middot; 
                                    Abierto por: <?= htmlspecialchars($row['abierto_por_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?> &middot; 
                                    Bruto capturado por: <?= htmlspecialchars($row['bruto_por_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?> &middot; 
                                    Tara capturada por: <?= htmlspecialchars($row['tara_por_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?> &middot; 
                                    Cerrado por: <?= htmlspecialchars($row['cerrado_por_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- FILA DE TOTALES -->
                        <tr style="border-top: 2px solid #0f233a; border-bottom: 2px solid #0f233a; font-weight: 900; background: #f8fafc;">
                            <td colspan="5" style="padding: 0.75rem 0.4rem; text-align: center; letter-spacing: 1px;">TOTALES</td>
                            <td style="padding: 0.75rem 0.4rem; text-align: right;"><?= number_format($totBruto, 0) ?></td>
                            <td style="padding: 0.75rem 0.4rem; text-align: right;"><?= number_format($totNeto, 0) ?></td>
                            <td style="padding: 0.75rem 0.4rem; text-align: right;"><?= number_format($totTaraVehiculo, 0) ?></td>
                            <td style="padding: 0.75rem 0.4rem; text-align: right;"><?= $totEmpaques ?></td>
                            <td style="padding: 0.75rem 0.4rem; text-align: right;"><?= number_format($totTaraEmpaques, 0) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PIE DEL REPORTE -->
        <div style="margin-top: 1.5rem; font-size: 0.78rem; color: #64748b; line-height: 1.6;">
            <div><?= count($reporteBoletas) ?> boletas cerradas. No incluye abiertas ni anuladas.</div>
            <div>Libras enteras. Total QQ = libras finales / 100. &mdash; indica un desglose histórico no disponible; su total no se completa con ceros.</div>
            <div>Fechas en America/Tegucigalpa.</div>
        </div>
    </div>
</div>

<script>
function filtrarTipoPersona(tipo) {
    const sel = document.getElementById('persona_id');
    const opts = sel.querySelectorAll('option');

    opts.forEach(opt => {
        if (opt.value === '0') return; // Todos
        const esCli = opt.getAttribute('data-cli') === '1';
        const esProv = opt.getAttribute('data-prov') === '1';

        if (tipo === 'CLIENTES') {
            opt.style.display = esCli ? 'block' : 'none';
        } else if (tipo === 'PROVEEDORES') {
            opt.style.display = esProv ? 'block' : 'none';
        } else {
            opt.style.display = 'block';
        }
    });

    sel.value = '0';
}

function imprimirReporte() {
    window.print();
}
</script>

<style>
@media print {
    .app-sidebar, .mobile-topbar, .footer, #formReporte {
        display: none !important;
    }
    .app-main-wrapper {
        margin: 0 !important;
        padding: 0 !important;
    }
    #seccionReporteImprimible {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        max-width: 100% !important;
    }
    body {
        background: #ffffff !important;
    }
    @page {
        size: letter landscape;
        margin: 1cm;
    }
}
</style>

<?php
renderizarPie();
