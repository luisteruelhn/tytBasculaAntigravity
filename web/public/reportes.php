<?php
/**
 * Módulo de Reportes por Fechas y Cliente / Proveedor
 * Proyecto: tytBasculaAntigravity
 *
 * Mejoras aplicadas:
 * 1. Formulario unificado y distribuido en una sola tarjeta con selector de Operación (Todos, Compras, Ventas).
 * 2. Clasificación separada por Compras y Ventas, con subtotalización independiente para cada tipo.
 * 3. Total general consolidado al final.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombre = $usuarioActual['empresa_nombre'] ?? 'INVERSIONES VALLECILLO, SB';
$pdo = obtenerDB();

// 1. CARGA DE EMPRESA Y LISTAS
$empresaFiltroId = (int)($_GET['empresa_id'] ?? $empresaId);
$stmtEmp = $pdo->query("SELECT id, razon_social, nombre_comercial FROM t_empresas WHERE activo = 1 ORDER BY razon_social ASC");
$listaEmpresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

$stmtPersonas = $pdo->prepare("SELECT id, nombre, es_cliente, es_proveedor FROM t_clie_prov WHERE empresa_id = :empresa_id ORDER BY nombre ASC");
$stmtPersonas->execute([':empresa_id' => $empresaFiltroId]);
$listaPersonas = $stmtPersonas->fetchAll(PDO::FETCH_ASSOC);

// 2. PARÁMETROS DEL FILTRO
$filtroOperacion = trim((string)($_GET['operacion'] ?? 'TODOS')); // TODOS, COMPRA, VENTA
$tipoPersona     = trim((string)($_GET['tipo_persona'] ?? 'TODOS')); // TODOS, CLIENTES, PROVEEDORES
$personaId       = (int)($_GET['persona_id'] ?? 0);
$fechaDesde      = trim((string)($_GET['desde'] ?? ''));
$fechaHasta      = trim((string)($_GET['hasta'] ?? ''));

function normalizarFecha(string $f): string {
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $f)) {
        [$d, $m, $y] = explode('/', $f);
        return "$y-$m-$d";
    }
    return $f;
}

$fechaDesdeSql = normalizarFecha($fechaDesde);
$fechaHastaSql = normalizarFecha($fechaHasta);
if ($fechaDesdeSql === '') {
    $fechaDesdeSql = '2026-11-01';
}
if ($fechaHastaSql === '') {
    $fechaHastaSql = date('Y-m-d');
}

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
$params = [':empresa_id' => $empresaFiltroId];

if ($filtroOperacion === 'COMPRA' || $filtroOperacion === 'VENTA') {
    $sql .= " AND p.tipo_operacion = :op";
    $params[':op'] = $filtroOperacion;
}

if ($fechaDesdeSql !== '') {
    $sql .= " AND DATE(p.segunda_pesada_at) >= :desde";
    $params[':desde'] = $fechaDesdeSql;
}

if ($fechaHastaSql !== '') {
    $sql .= " AND DATE(p.segunda_pesada_at) <= :hasta";
    $params[':hasta'] = $fechaHastaSql;
}

if ($personaId > 0) {
    $sql .= " AND p.clie_prov_id = :persona_id";
    $params[':persona_id'] = $personaId;
} elseif ($tipoPersona === 'CLIENTES') {
    $sql .= " AND cp.es_cliente = 1";
} elseif ($tipoPersona === 'PROVEEDORES') {
    $sql .= " AND cp.es_proveedor = 1";
}

$sql .= " ORDER BY p.tipo_operacion ASC, p.segunda_pesada_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$todasBoletas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. CLASIFICACIÓN EN GRUPOS: COMPRAS Y VENTAS
$grupos = [
    'COMPRA' => ['titulo' => 'COMPRAS', 'items' => []],
    'VENTA'  => ['titulo' => 'VENTAS',  'items' => []]
];

foreach ($todasBoletas as $b) {
    $op = ($b['tipo_operacion'] === 'VENTA') ? 'VENTA' : 'COMPRA';
    $grupos[$op]['items'][] = $b;
}

// Función helper para calcular totales de un conjunto de filas
function sumarTotales(array $items): array {
    $t = ['bruto' => 0.0, 'neto' => 0.0, 'tara' => 0.0, 'empaques' => 0, 'tara_emp' => 0.0];
    foreach ($items as $r) {
        $bruto = (float)$r['peso_bruto_lb'];
        $taraV = (float)$r['tara_vehiculo_lb'];
        $neto  = max(0.0, $bruto - $taraV);
        $cant  = (int)$r['cantidad_empaques'];
        $taraE = round((float)($r['tara_unitaria_aplicada_lb'] ?? 0) * $cant);

        $t['bruto'] += $bruto;
        $t['tara'] += $taraV;
        $t['neto'] += $neto;
        $t['empaques'] += $cant;
        $t['tara_emp'] += $taraE;
    }
    return $t;
}

// 5. EXPORTAR A EXCEL CSV
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_boletas_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($out, ['Tipo Operacion', 'Boleta', 'Entrada/Primera', 'Salida/Segunda', 'Cliente/Proveedor', 'Producto', 'Presentacion', 'Bruto lb', 'Tara lb', 'Neto lb', 'Empaques', 'Tara Empaques lb', 'Final lb', 'Total QQ']);
    foreach ($todasBoletas as $b) {
        $numB = ($b['tipo_operacion'] === 'COMPRA') ? '#' . sprintf('%07d', $b['id_compra']) : '#' . sprintf('%07d', $b['id_venta']);
        fputcsv($out, [
            $b['tipo_operacion'],
            $numB,
            $b['primera_pesada_at'],
            $b['segunda_pesada_at'],
            $b['persona_nombre'],
            $b['producto_nombre'],
            $b['presentacion_nombre'],
            $b['peso_bruto_lb'],
            $b['tara_vehiculo_lb'],
            max(0, (float)$b['peso_bruto_lb'] - (float)$b['tara_vehiculo_lb']),
            $b['cantidad_empaques'],
            round((float)($b['tara_unitaria_aplicada_lb'] ?? 0) * (int)$b['cantidad_empaques']),
            $b['peso_final_lb'],
            round((float)$b['peso_final_lb'] / 100.0, 2)
        ]);
    }
    exit;
}

renderizarEncabezado('Reporte de Boletas', 'reportes');
?>

<div style="max-width: 1200px; margin: 0 auto;">

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

    <!-- TARJETA UNIFICADA DE FILTROS -->
    <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 2rem;">
        <form method="GET" action="reportes.php" id="formReporte">
            
            <!-- FILA 1: FILTROS PRINCIPALES (EMPRESA, OPERACIÓN, TIPO PERSONA, CLIENTE/PROV) -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
                <div>
                    <label for="empresa_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Empresa
                    </label>
                    <select id="empresa_id" name="empresa_id" class="form-control" style="height: 40px; border-radius: 6px;" onchange="this.form.submit()">
                        <?php foreach ($listaEmpresas as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $emp['id'] === $empresaFiltroId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['nombre_comercial'] ?: $emp['razon_social'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="operacion" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Transacción
                    </label>
                    <select id="operacion" name="operacion" class="form-control" style="height: 40px; border-radius: 6px;" onchange="this.form.submit()">
                        <option value="TODOS" <?= $filtroOperacion === 'TODOS' ? 'selected' : '' ?>>Todas (Compras y Ventas)</option>
                        <option value="COMPRA" <?= $filtroOperacion === 'COMPRA' ? 'selected' : '' ?>>Solo Compras</option>
                        <option value="VENTA" <?= $filtroOperacion === 'VENTA' ? 'selected' : '' ?>>Solo Ventas</option>
                    </select>
                </div>

                <div>
                    <label for="tipo_persona" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Tipo de persona
                    </label>
                    <select id="tipo_persona" name="tipo_persona" class="form-control" style="height: 40px; border-radius: 6px;" onchange="filtrarTipoPersona(this.value)">
                        <option value="TODOS" <?= $tipoPersona === 'TODOS' ? 'selected' : '' ?>>Clientes y proveedores</option>
                        <option value="CLIENTES" <?= $tipoPersona === 'CLIENTES' ? 'selected' : '' ?>>Clientes</option>
                        <option value="PROVEEDORES" <?= $tipoPersona === 'PROVEEDORES' ? 'selected' : '' ?>>Proveedores</option>
                    </select>
                </div>

                <div>
                    <label for="persona_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Cliente / proveedor
                    </label>
                    <select id="persona_id" name="persona_id" class="form-control" style="height: 40px; border-radius: 6px;">
                        <option value="0">Todos</option>
                        <?php foreach ($listaPersonas as $p): ?>
                            <option value="<?= $p['id'] ?>" data-cli="<?= $p['es_cliente'] ?>" data-prov="<?= $p['es_proveedor'] ?>" <?= $p['id'] === $personaId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- FILA 2: FECHA DESDE, HASTA, BOTÓN GENERAR Y ACCIONES -->
            <div style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <div style="width: 160px;">
                    <label for="desde" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Desde
                    </label>
                    <input type="date" id="desde" name="desde" class="form-control" style="height: 40px; border-radius: 6px;" value="<?= htmlspecialchars($fechaDesdeSql, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div style="width: 160px;">
                    <label for="hasta" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Hasta
                    </label>
                    <input type="date" id="hasta" name="hasta" class="form-control" style="height: 40px; border-radius: 6px;" value="<?= htmlspecialchars($fechaHastaSql, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <button type="submit" class="btn btn-primary" style="height: 40px; padding: 0 1.25rem; font-weight: 700; background: #0b57d0; border-radius: 6px;">
                    Generar reporte
                </button>

                <!-- LÍNEA VERTICAL SUAVE -->
                <div style="width: 1px; height: 36px; background: #cbd5e1; margin: 0 0.35rem; align-self: flex-end; margin-bottom: 2px;"></div>

                <button type="button" onclick="imprimirReporte()" class="btn btn-primary" style="height: 40px; background: #0b57d0; padding: 0 1.15rem; font-weight: 700; border-radius: 6px;">
                    Imprimir
                </button>
                <a href="reportes.php?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-secondary" style="height: 40px; padding: 0 1.15rem; font-weight: 600; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center;">
                    Generar Excel
                </a>
                <button type="button" onclick="imprimirReporte()" class="btn btn-secondary" style="height: 40px; padding: 0 1.15rem; font-weight: 600; border-radius: 6px;">
                    Descargar PDF
                </button>
                <a href="https://web.whatsapp.com/" target="_blank" class="btn btn-secondary" style="height: 40px; padding: 0 1.15rem; font-weight: 600; text-decoration: none; border-radius: 6px; display: inline-flex; align-items: center;">
                    WhatsApp Web
                </a>
            </div>

            <p style="font-size: 0.82rem; color: #475569; margin: 1.25rem 0 0 0;">
                Boletas cerradas por fecha de cierre, incluyendo ambos días. Se clasifican de forma separada las Compras y las Ventas con sus respectivos subtotales independientes.
            </p>
        </form>
    </div>

    <!-- TARJETA DEL REPORTE IMPRIMIBLE -->
    <div id="seccionReporteImprimible" class="card" style="padding: 2.5rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
        
        <div style="text-align: center; margin-bottom: 2rem;">
            <h2 style="font-size: 1.5rem; font-weight: 800; margin: 0 0 0.35rem 0; color: var(--text-main); text-transform: uppercase;">
                <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <h3 style="font-size: 1.25rem; font-weight: 900; letter-spacing: 0.5px; margin: 0 0 0.35rem 0; color: #0f233a;">
                REPORTE DE BOLETAS <?= ($filtroOperacion !== 'TODOS' ? '— ' . $filtroOperacion . 'S' : '') ?>
            </h3>
            <div style="font-size: 0.85rem; color: #64748b; font-weight: 600;">
                Del <?= date('d/m/Y', strtotime($fechaDesdeSql)) ?> al <?= date('d/m/Y', strtotime($fechaHastaSql)) ?> &middot; Fecha de cierre
            </div>
        </div>

        <?php if (empty($todasBoletas)): ?>
            <div style="text-align: center; color: var(--text-muted); padding: 3.5rem 1rem;">
                No hay boletas cerradas registradas para los filtros indicados.
            </div>
        <?php else: ?>

            <?php 
            $totalesGenerales = sumarTotales($todasBoletas);

            foreach ($grupos as $tipoClave => $grupo): 
                if (empty($grupo['items'])) continue;
                $totGrupo = sumarTotales($grupo['items']);
                $colorGrupo = ($tipoClave === 'COMPRA') ? '#1e40af' : '#047857';
            ?>
                <!-- ENCABEZADO DE SECCIÓN: COMPRAS O VENTAS -->
                <div style="display: flex; align-items: center; justify-content: space-between; margin: 2rem 0 0.75rem 0; border-bottom: 2px solid <?= $colorGrupo ?>; padding-bottom: 0.4rem;">
                    <h4 style="margin: 0; font-size: 1.15rem; font-weight: 900; color: <?= $colorGrupo ?>; letter-spacing: 0.5px;">
                        <?= $grupo['titulo'] ?> (<?= count($grupo['items']) ?>)
                    </h4>
                    <span style="font-size: 0.8rem; font-weight: 700; color: #64748b;">
                        Subtotal Neto: <?= number_format($totGrupo['neto'], 0) ?> lb
                    </span>
                </div>

                <div class="table-responsive" style="border: none; background: transparent; overflow-x: auto; margin-bottom: 2rem;">
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
                            <?php foreach ($grupo['items'] as $row): 
                                $bruto = (float)$row['peso_bruto_lb'];
                                $taraV = (float)$row['tara_vehiculo_lb'];
                                $neto  = max(0.0, $bruto - $taraV);
                                $cantEmp = (int)$row['cantidad_empaques'];
                                $taraEmp = round((float)($row['tara_unitaria_aplicada_lb'] ?? 0) * $cantEmp);

                                $numBoleta = ($row['tipo_operacion'] === 'COMPRA') 
                                    ? '#' . sprintf('%07d', $row['id_compra']) 
                                    : '#' . sprintf('%07d', $row['id_venta']);
                                
                                $fEntrada = !empty($row['primera_pesada_at']) ? date('d/m/Y H:i', strtotime($row['primera_pesada_at'])) : '—';
                                $fSalida  = !empty($row['segunda_pesada_at']) ? date('d/m/Y H:i', strtotime($row['segunda_pesada_at'])) : '—';
                            ?>
                                <tr style="border-bottom: 1px dotted #e2e8f0;">
                                    <td style="padding: 0.65rem 0.4rem;"><?= $fEntrada ?></td>
                                    <td style="padding: 0.65rem 0.4rem;"><?= $fSalida ?></td>
                                    <td style="padding: 0.65rem 0.4rem; font-weight: 800; font-family: monospace; color: <?= $colorGrupo ?>;"><?= htmlspecialchars($numBoleta, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="padding: 0.65rem 0.4rem; font-weight: 600;"><?= htmlspecialchars($row['persona_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="padding: 0.65rem 0.4rem;"><?= htmlspecialchars($row['producto_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="padding: 0.65rem 0.4rem; text-align: right;"><?= number_format($bruto, 0) ?></td>
                                    <td style="padding: 0.65rem 0.4rem; text-align: right; font-weight: 700;"><?= number_format($neto, 0) ?></td>
                                    <td style="padding: 0.65rem 0.4rem; text-align: right;"><?= number_format($taraV, 0) ?></td>
                                    <td style="padding: 0.65rem 0.4rem; text-align: right;"><?= $cantEmp ?></td>
                                    <td style="padding: 0.65rem 0.4rem; text-align: right;"><?= number_format($taraEmp, 0) ?></td>
                                </tr>
                                <tr style="border-bottom: 1px solid #cbd5e1; background: #fafafa;">
                                    <td colspan="10" style="padding: 0.35rem 0.4rem; font-size: 0.72rem; color: #64748b;">
                                        Presentación: <?= htmlspecialchars($row['presentacion_nombre'], ENT_QUOTES, 'UTF-8') ?> &middot; 
                                        Placa: <?= htmlspecialchars($row['placa'], ENT_QUOTES, 'UTF-8') ?> &middot; 
                                        Tipo 1ra: <?= $row['tipo_primera_pesada'] ?> &middot; 
                                        Fecha cierre: <?= $row['segunda_pesada_at'] ?> &middot; 
                                        Responsable: <?= htmlspecialchars($row['responsable_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?> &middot; 
                                        Cerrado por: <?= htmlspecialchars($row['cerrado_por_nombre'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <!-- SUB-TOTAL DE ESTE GRUPO (COMPRAS O VENTAS) -->
                            <tr style="border-top: 2px solid <?= $colorGrupo ?>; font-weight: 900; background: #f8fafc;">
                                <td colspan="5" style="padding: 0.75rem 0.4rem; text-align: right; color: <?= $colorGrupo ?>; letter-spacing: 0.5px;">
                                    SUBTOTAL <?= $grupo['titulo'] ?>:
                                </td>
                                <td style="padding: 0.75rem 0.4rem; text-align: right;"><?= number_format($totGrupo['bruto'], 0) ?></td>
                                <td style="padding: 0.75rem 0.4rem; text-align: right; color: <?= $colorGrupo ?>;"><?= number_format($totGrupo['neto'], 0) ?></td>
                                <td style="padding: 0.75rem 0.4rem; text-align: right;"><?= number_format($totGrupo['tara'], 0) ?></td>
                                <td style="padding: 0.75rem 0.4rem; text-align: right;"><?= $totGrupo['empaques'] ?></td>
                                <td style="padding: 0.75rem 0.4rem; text-align: right;"><?= number_format($totGrupo['tara_emp'], 0) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <!-- RESUMEN CONSOLIDADO TOTAL GENERAL (SI SE MUESTRAN AMBOS) -->
            <?php if ($filtroOperacion === 'TODOS' && !empty($grupos['COMPRA']['items']) && !empty($grupos['VENTA']['items'])): ?>
                <div style="background: #0f233a; color: #ffffff; border-radius: 8px; padding: 1rem 1.5rem; margin-top: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Balance Consolidado</div>
                        <div style="font-size: 1.25rem; font-weight: 900;">TOTAL GENERAL DE BÁSCULA</div>
                    </div>
                    <div style="display: flex; gap: 2rem; text-align: right;">
                        <div>
                            <span style="font-size: 0.75rem; color: #94a3b8; display: block;">Total Bruto</span>
                            <strong style="font-size: 1.15rem;"><?= number_format($totalesGenerales['bruto'], 0) ?> lb</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; color: #94a3b8; display: block;">Total Neto</span>
                            <strong style="font-size: 1.25rem; color: #60a5fa;"><?= number_format($totalesGenerales['neto'], 0) ?> lb</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; color: #94a3b8; display: block;">Total Tara</span>
                            <strong style="font-size: 1.15rem;"><?= number_format($totalesGenerales['tara'], 0) ?> lb</strong>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <!-- PIE DEL REPORTE -->
        <div style="margin-top: 1.5rem; font-size: 0.78rem; color: #64748b; line-height: 1.6;">
            <div><?= count($todasBoletas) ?> boletas cerradas. No incluye abiertas ni anuladas.</div>
            <div>Libras enteras. Total QQ = libras finales / 100. Fechas en America/Tegucigalpa.</div>
        </div>
    </div>
</div>

<script>
function filtrarTipoPersona(tipo) {
    const sel = document.getElementById('persona_id');
    const opts = sel.querySelectorAll('option');

    opts.forEach(opt => {
        if (opt.value === '0') return;
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
