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
           cat.nombre AS categoria_nombre,
           u_resp.nombre AS responsable_nombre,
           u_ab.nombre AS abierto_por_nombre,
           u_bruto.nombre AS bruto_por_nombre,
           u_tara.nombre AS tara_por_nombre,
           u_cerr.nombre AS cerrado_por_nombre
    FROM t_pesadas p
    JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
    JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
    JOIN t_productos prod ON prod.id = pr.producto_id
    LEFT JOIN t_categorias cat ON cat.id = prod.categoria_id
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

// 5. EXPORTAR A EXCEL (.XLS / XML NATIVO CON ESTILOS, AGRUPADO POR OPERACIÓN Y PRODUCTO CON SUBTOTALES)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $nombreArchivo = 'reporte_boletas_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Cache-Control: max-age=0');

    // Estructurar datos: Operación -> Categoría -> Producto -> Filas
    $arbol = [];
    foreach ($todasBoletas as $b) {
        $op   = $b['tipo_operacion'];
        $cat  = $b['categoria_nombre'] ?: 'GENERAL';
        $prod = $b['producto_nombre'] ?: 'SIN PRODUCTO';
        $arbol[$op][$cat][$prod][] = $b;
    }

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Reporte de Boletas</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>';
    echo '<body>';
    echo '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse; font-family: Calibri, sans-serif; font-size: 11pt;">';

    // Título Principal
    echo '<tr><th colspan="14" style="background-color: #0f172a; color: #ffffff; font-size: 14pt; padding: 10px; text-align: center;">REPORTE DE BOLETAS DE PESAJE</th></tr>';
    echo '<tr><td colspan="14" style="text-align: center; color: #475569; font-size: 10pt;">Empresa: ' . htmlspecialchars($empresaNombre) . ' &middot; Del ' . date('d/m/Y', strtotime($fechaDesdeSql)) . ' al ' . date('d/m/Y', strtotime($fechaHastaSql)) . '</td></tr>';
    echo '<tr><td colspan="14" style="height: 10px; background-color: #ffffff; border: none;"></td></tr>';

    $totGenBruto = 0.0;
    $totGenTara  = 0.0;
    $totGenNeto  = 0.0;
    $totGenEmp   = 0;
    $totGenTaraE = 0.0;
    $totGenFinal = 0.0;
    $totGenQQ    = 0.0;
    $totGenPesadas = 0;

    foreach ($arbol as $op => $categorias) {
        $tituloOp = ($op === 'COMPRA') ? 'COMPRAS' : 'VENTAS';
        $colorOpHeader = ($op === 'COMPRA') ? '#1e40af' : '#047857';

        echo '<tr><th colspan="14" style="background-color: ' . $colorOpHeader . '; color: #ffffff; font-size: 12pt; text-align: left; padding: 6px 10px;">SECCIÓN: ' . $tituloOp . '</th></tr>';

        $totOpBruto = 0.0;
        $totOpTara  = 0.0;
        $totOpNeto  = 0.0;
        $totOpEmp   = 0;
        $totOpTaraE = 0.0;
        $totOpFinal = 0.0;
        $totOpQQ    = 0.0;
        $totOpPesadas = 0;

        foreach ($categorias as $nombreCategoria => $productos) {
            echo '<tr style="background-color: #334155; color: #ffffff; font-weight: bold;">';
            echo '<td colspan="14" style="padding: 5px 10px; font-size: 11pt;">CATEGORÍA: ' . htmlspecialchars($nombreCategoria) . '</td>';
            echo '</tr>';

            $totCatBruto = 0.0;
            $totCatTara  = 0.0;
            $totCatNeto  = 0.0;
            $totCatEmp   = 0;
            $totCatTaraE = 0.0;
            $totCatFinal = 0.0;
            $totCatQQ    = 0.0;
            $totCatPesadas = 0;

            foreach ($productos as $nombreProducto => $boletasProducto) {
                echo '<tr style="background-color: #f1f5f9; font-weight: bold;">';
                echo '<td colspan="14" style="color: #0f172a; font-size: 10.5pt; padding-left: 15px;">Producto: ' . htmlspecialchars($nombreProducto) . '</td>';
                echo '</tr>';

                echo '<tr style="background-color: #e2e8f0; color: #1e293b; font-weight: bold; text-align: center; font-size: 9.5pt;">';
                echo '<th>Operación</th>';
                echo '<th>Boleta</th>';
                echo '<th>Entrada / Primera</th>';
                echo '<th>Salida / Segunda</th>';
                echo '<th>Cliente / Proveedor</th>';
                echo '<th>Producto</th>';
                echo '<th>Presentación</th>';
                echo '<th>Bruto lb</th>';
                echo '<th>Tara lb</th>';
                echo '<th>Neto lb</th>';
                echo '<th>Empaques</th>';
                echo '<th>Tara Emp lb</th>';
                echo '<th>Final lb</th>';
                echo '<th>Total QQ</th>';
                echo '</tr>';

                $subBruto = 0.0;
                $subTara  = 0.0;
                $subNeto  = 0.0;
                $subEmp   = 0;
                $subTaraE = 0.0;
                $subFinal = 0.0;
                $subQQ    = 0.0;
                $subPesadas = count($boletasProducto);

                foreach ($boletasProducto as $b) {
                    $numB  = ($b['tipo_operacion'] === 'COMPRA') ? '#' . sprintf('%07d', $b['id_compra']) : '#' . sprintf('%07d', $b['id_venta']);
                    $bruto = (float)$b['peso_bruto_lb'];
                    $tara  = (float)$b['tara_vehiculo_lb'];
                    $neto  = max(0.0, $bruto - $tara);
                    $cantE = (int)$b['cantidad_empaques'];
                    $taraE = round((float)($b['tara_unitaria_aplicada_lb'] ?? 0) * $cantE);
                    $final = (float)$b['peso_final_lb'];
                    $qq    = round($final / 100.0, 2);

                    $subBruto += $bruto;
                    $subTara  += $tara;
                    $subNeto  += $neto;
                    $subEmp   += $cantE;
                    $subTaraE += $taraE;
                    $subFinal += $final;
                    $subQQ    += $qq;

                    echo '<tr>';
                    echo '<td align="center">' . htmlspecialchars($b['tipo_operacion']) . '</td>';
                    echo '<td align="center" style="font-weight: bold;">' . htmlspecialchars($numB) . '</td>';
                    echo '<td align="center">' . htmlspecialchars((string)$b['primera_pesada_at']) . '</td>';
                    echo '<td align="center">' . htmlspecialchars((string)$b['segunda_pesada_at']) . '</td>';
                    echo '<td>' . htmlspecialchars((string)$b['persona_nombre']) . '</td>';
                    echo '<td>' . htmlspecialchars((string)$b['producto_nombre']) . '</td>';
                    echo '<td>' . htmlspecialchars((string)$b['presentacion_nombre']) . '</td>';
                    echo '<td align="right">' . number_format($bruto) . '</td>';
                    echo '<td align="right">' . number_format($tara) . '</td>';
                    echo '<td align="right">' . number_format($neto) . '</td>';
                    echo '<td align="center">' . $cantE . '</td>';
                    echo '<td align="right">' . number_format($taraE) . '</td>';
                    echo '<td align="right" style="font-weight: bold;">' . number_format($final) . '</td>';
                    echo '<td align="right" style="font-weight: bold;">' . number_format($qq, 2) . '</td>';
                    echo '</tr>';
                }

                // Fila de Subtotal por Producto con conteo de pesadas
                echo '<tr style="background-color: #f8fafc; font-weight: bold; border-top: 1px solid #94a3b8;">';
                echo '<td colspan="7" align="right" style="color: #475569;">SUBTOTAL (' . htmlspecialchars($nombreProducto) . ' &middot; ' . $subPesadas . ' pesadas):</td>';
                echo '<td align="right">' . number_format($subBruto) . '</td>';
                echo '<td align="right">' . number_format($subTara) . '</td>';
                echo '<td align="right">' . number_format($subNeto) . '</td>';
                echo '<td align="center">' . $subEmp . '</td>';
                echo '<td align="right">' . number_format($subTaraE) . '</td>';
                echo '<td align="right" style="color: #0b57d0;">' . number_format($subFinal) . '</td>';
                echo '<td align="right" style="color: #0b57d0;">' . number_format($subQQ, 2) . '</td>';
                echo '</tr>';

                $totCatBruto += $subBruto;
                $totCatTara  += $subTara;
                $totCatNeto  += $subNeto;
                $totCatEmp   += $subEmp;
                $totCatTaraE += $subTaraE;
                $totCatFinal += $subFinal;
                $totCatQQ    += $subQQ;
                $totCatPesadas += $subPesadas;
            }

            // Fila de Subtotal por Categoría con conteo de pesadas
            echo '<tr style="background-color: #e2e8f0; font-weight: bold; font-size: 10.5pt;">';
            echo '<td colspan="7" align="right" style="color: #1e293b;">SUBTOTAL CATEGORÍA ' . htmlspecialchars($nombreCategoria) . ' (' . $totCatPesadas . ' pesadas):</td>';
            echo '<td align="right">' . number_format($totCatBruto) . '</td>';
            echo '<td align="right">' . number_format($totCatTara) . '</td>';
            echo '<td align="right">' . number_format($totCatNeto) . '</td>';
            echo '<td align="center">' . $totCatEmp . '</td>';
            echo '<td align="right">' . number_format($totCatTaraE) . '</td>';
            echo '<td align="right" style="color: #1e293b;">' . number_format($totCatFinal) . '</td>';
            echo '<td align="right" style="color: #1e293b;">' . number_format($totCatQQ, 2) . '</td>';
            echo '</tr>';
            echo '<tr><td colspan="14" style="height: 6px; background-color: #ffffff; border: none;"></td></tr>';

            $totOpBruto += $totCatBruto;
            $totOpTara  += $totCatTara;
            $totOpNeto  += $totCatNeto;
            $totOpEmp   += $totCatEmp;
            $totOpTaraE += $totCatTaraE;
            $totOpFinal += $totCatFinal;
            $totOpQQ    += $totCatQQ;
            $totOpPesadas += $totCatPesadas;
        }

        // Fila de Subtotal de Toda la Operación (Total Compras o Total Ventas)
        echo '<tr style="background-color: #dbeafe; font-weight: bold; font-size: 11pt;">';
        echo '<td colspan="7" align="right" style="color: #1e3a8a;">SUBTOTAL ' . $tituloOp . ' (' . $totOpPesadas . ' pesadas):</td>';
        echo '<td align="right">' . number_format($totOpBruto) . '</td>';
        echo '<td align="right">' . number_format($totOpTara) . '</td>';
        echo '<td align="right">' . number_format($totOpNeto) . '</td>';
        echo '<td align="center">' . $totOpEmp . '</td>';
        echo '<td align="right">' . number_format($totOpTaraE) . '</td>';
        echo '<td align="right" style="color: #1e3a8a;">' . number_format($totOpFinal) . '</td>';
        echo '<td align="right" style="color: #1e3a8a;">' . number_format($totOpQQ, 2) . '</td>';
        echo '</tr>';
        echo '<tr><td colspan="14" style="height: 12px; background-color: #ffffff; border: none;"></td></tr>';

        // Acumular a totales Generales
        $totGenBruto += $totOpBruto;
        $totGenTara  += $totOpTara;
        $totGenNeto  += $totOpNeto;
        $totGenEmp   += $totOpEmp;
        $totGenTaraE += $totOpTaraE;
        $totGenFinal += $totOpFinal;
        $totGenQQ    += $totOpQQ;
        $totGenPesadas += $totOpPesadas;
    }

    // Acumulador de subtotales generales por producto (consolidando compras y ventas)
    $totalesPorProducto = [];
    foreach ($todasBoletas as $b) {
        $pNom = $b['producto_nombre'] ?: 'SIN PRODUCTO';
        if (!isset($totalesPorProducto[$pNom])) {
            $totalesPorProducto[$pNom] = ['bruto' => 0.0, 'tara' => 0.0, 'neto' => 0.0, 'empaques' => 0, 'tara_emp' => 0.0, 'final' => 0.0, 'qq' => 0.0, 'boletas' => 0];
        }
        $b_bruto = (float)$b['peso_bruto_lb'];
        $b_tara  = (float)$b['tara_vehiculo_lb'];
        $b_neto  = max(0.0, $b_bruto - $b_tara);
        $b_cantE = (int)$b['cantidad_empaques'];
        $b_taraE = round((float)($b['tara_unitaria_aplicada_lb'] ?? 0) * $b_cantE);
        $b_final = (float)$b['peso_final_lb'];
        $b_qq    = round($b_final / 100.0, 2);

        $totalesPorProducto[$pNom]['bruto']    += $b_bruto;
        $totalesPorProducto[$pNom]['tara']     += $b_tara;
        $totalesPorProducto[$pNom]['neto']     += $b_neto;
        $totalesPorProducto[$pNom]['empaques'] += $b_cantE;
        $totalesPorProducto[$pNom]['tara_emp'] += $b_taraE;
        $totalesPorProducto[$pNom]['final']    += $b_final;
        $totalesPorProducto[$pNom]['qq']       += $b_qq;
        $totalesPorProducto[$pNom]['boletas']++;
    }

    // SECCIÓN: RESUMEN Y SUBTOTAL GENERAL POR PRODUCTO
    echo '<tr><td colspan="14" style="height: 16px; background-color: #ffffff; border: none;"></td></tr>';
    echo '<tr><th colspan="14" style="background-color: #334155; color: #ffffff; font-size: 11pt; text-align: left; padding: 6px 10px;">RESUMEN DE SUBTOTALES GENERALES POR PRODUCTO (COMPRAS Y VENTAS)</th></tr>';
    echo '<tr style="background-color: #e2e8f0; color: #0f172a; font-weight: bold; text-align: center; font-size: 10pt;">';
    echo '<th colspan="2">Boletas</th><th colspan="5">Producto</th><th>Bruto lb</th><th>Tara lb</th><th>Neto lb</th><th>Empaques</th><th>Tara Emp lb</th><th>Final lb</th><th>Total QQ</th>';
    echo '</tr>';

    foreach ($totalesPorProducto as $pNom => $tProd) {
        echo '<tr style="background-color: #f8fafc; font-weight: bold;">';
        echo '<td colspan="2" align="center">' . $tProd['boletas'] . ' boletas</td>';
        echo '<td colspan="5" style="color: #0f172a; padding-left: 10px;">' . htmlspecialchars($pNom) . '</td>';
        echo '<td align="right">' . number_format($tProd['bruto']) . '</td>';
        echo '<td align="right">' . number_format($tProd['tara']) . '</td>';
        echo '<td align="right">' . number_format($tProd['neto']) . '</td>';
        echo '<td align="center">' . $tProd['empaques'] . '</td>';
        echo '<td align="right">' . number_format($tProd['tara_emp']) . '</td>';
        echo '<td align="right" style="color: #0b57d0;">' . number_format($tProd['final']) . '</td>';
        echo '<td align="right" style="color: #0b57d0;">' . number_format($tProd['qq'], 2) . '</td>';
        echo '</tr>';
    }
    echo '<tr><td colspan="14" style="height: 14px; background-color: #ffffff; border: none;"></td></tr>';

    // Fila de TOTAL GENERAL CONSOLIDADO
    echo '<tr style="background-color: #0f172a; color: #ffffff; font-weight: bold; font-size: 12pt;">';
    echo '<td colspan="7" align="right" style="padding: 8px;">TOTAL GENERAL CONSOLIDADO:</td>';
    echo '<td align="right">' . number_format($totGenBruto) . '</td>';
    echo '<td align="right">' . number_format($totGenTara) . '</td>';
    echo '<td align="right">' . number_format($totGenNeto) . '</td>';
    echo '<td align="center">' . $totGenEmp . '</td>';
    echo '<td align="right">' . number_format($totGenTaraE) . '</td>';
    echo '<td align="right" style="color: #60a5fa;">' . number_format($totGenFinal) . '</td>';
    echo '<td align="right" style="color: #60a5fa;">' . number_format($totGenQQ, 2) . '</td>';
    echo '</tr>';

    echo '</table>';
    echo '</body></html>';
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

            // Estructurar árbol para la vista web imprimible: Operación -> Categoría -> Producto
            $arbolWeb = [];
            foreach ($todasBoletas as $b) {
                $op   = $b['tipo_operacion'];
                $cat  = $b['categoria_nombre'] ?: 'GENERAL';
                $prod = $b['producto_nombre'] ?: 'SIN PRODUCTO';
                $arbolWeb[$op][$cat][$prod][] = $b;
            }

            foreach ($arbolWeb as $tipoClave => $categoriasWeb): 
                $tituloGrupo = ($tipoClave === 'COMPRA') ? 'COMPRAS' : 'VENTAS';
                $colorGrupo = ($tipoClave === 'COMPRA') ? '#1e40af' : '#047857';

                $totOpBruto = 0.0; $totOpTara = 0.0; $totOpNeto = 0.0; $totOpEmp = 0; $totOpTaraE = 0.0; $totOpPesadas = 0;
            ?>
                <!-- ENCABEZADO DE SECCIÓN: COMPRAS O VENTAS -->
                <div style="margin: 2.5rem 0 1rem 0; border-bottom: 2px solid <?= $colorGrupo ?>; padding-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: baseline;">
                    <h4 style="margin: 0; font-size: 1.25rem; font-weight: 900; color: <?= $colorGrupo ?>; letter-spacing: 0.5px;">
                        SECCIÓN: <?= $tituloGrupo ?>
                    </h4>
                    <span style="font-size: 0.85rem; font-weight: 700; color: #64748b;">
                        Detalle agrupado por Categoría y Producto
                    </span>
                </div>

                <?php foreach ($categoriasWeb as $nombreCat => $productosWeb): 
                    $totCatBruto = 0.0; $totCatTara = 0.0; $totCatNeto = 0.0; $totCatEmp = 0; $totCatTaraE = 0.0; $totCatPesadas = 0;
                ?>
                    <div style="background: #334155; color: #ffffff; padding: 0.45rem 0.85rem; border-radius: 6px; font-weight: 800; font-size: 0.92rem; margin: 1.5rem 0 0.75rem 0; letter-spacing: 0.5px;">
                        📁 CATEGORÍA: <?= htmlspecialchars($nombreCat, ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <?php foreach ($productosWeb as $nombreProd => $itemsProd): 
                        $subP = sumarTotales($itemsProd);
                        $cantP = count($itemsProd);
                        $totCatBruto += $subP['bruto'];
                        $totCatTara  += $subP['tara'];
                        $totCatNeto  += $subP['neto'];
                        $totCatEmp   += $subP['empaques'];
                        $totCatTaraE += $subP['tara_emp'];
                        $totCatPesadas += $cantP;
                    ?>
                        <div style="background: #f1f5f9; border-left: 4px solid <?= $colorGrupo ?>; padding: 0.4rem 0.75rem; font-weight: 800; font-size: 0.88rem; color: #0f172a; margin-bottom: 0.5rem; display: flex; justify-content: space-between;">
                            <span>📦 Producto: <?= htmlspecialchars($nombreProd, ENT_QUOTES, 'UTF-8') ?></span>
                            <span style="font-size: 0.8rem; font-weight: 700; color: #475569;"><?= $cantP ?> pesadas</span>
                        </div>

                        <div class="table-responsive" style="border: none; background: transparent; overflow-x: auto; margin-bottom: 1rem;">
                            <table class="table" style="border-collapse: collapse; min-width: 100%; font-size: 0.82rem;">
                                <thead>
                                    <tr style="border-top: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1; background: #ffffff;">
                                        <th style="padding: 0.5rem 0.4rem; text-align: left; font-weight: 700; color: #475569;">Entrada</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: left; font-weight: 700; color: #475569;">Salida</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: left; font-weight: 700; color: #475569;">Boleta</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: left; font-weight: 700; color: #475569;">Cliente / proveedor</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Bruto lb</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Tara lb</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Neto lb</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Emp.</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Tara Emp.</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">Final lb</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 700; color: #475569;">QQ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itemsProd as $row): 
                                        $bruto = (float)$row['peso_bruto_lb'];
                                        $taraV = (float)$row['tara_vehiculo_lb'];
                                        $neto  = max(0.0, $bruto - $taraV);
                                        $cantEmp = (int)$row['cantidad_empaques'];
                                        $taraEmp = round((float)($row['tara_unitaria_aplicada_lb'] ?? 0) * $cantEmp);
                                        $finalLb = (float)$row['peso_final_lb'];
                                        $qqTot   = round($finalLb / 100.0, 2);

                                        $numBoleta = ($row['tipo_operacion'] === 'COMPRA') 
                                            ? '#' . sprintf('%07d', $row['id_compra']) 
                                            : '#' . sprintf('%07d', $row['id_venta']);
                                        
                                        $fEntrada = !empty($row['primera_pesada_at']) ? date('d/m/Y H:i', strtotime($row['primera_pesada_at'])) : '—';
                                        $fSalida  = !empty($row['segunda_pesada_at']) ? date('d/m/Y H:i', strtotime($row['segunda_pesada_at'])) : '—';
                                    ?>
                                        <tr style="border-bottom: 1px dotted #e2e8f0;">
                                            <td style="padding: 0.5rem 0.4rem;"><?= $fEntrada ?></td>
                                            <td style="padding: 0.5rem 0.4rem;"><?= $fSalida ?></td>
                                            <td style="padding: 0.5rem 0.4rem; font-weight: 800; font-family: monospace; color: <?= $colorGrupo ?>;"><?= htmlspecialchars($numBoleta, ENT_QUOTES, 'UTF-8') ?></td>
                                            <td style="padding: 0.5rem 0.4rem; font-weight: 600;"><?= htmlspecialchars($row['persona_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= number_format($bruto, 0) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= number_format($taraV, 0) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 600;"><?= number_format($neto, 0) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= $cantEmp ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= number_format($taraEmp, 0) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800;"><?= number_format($finalLb, 0) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800;"><?= number_format($qqTot, 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- SUB-TOTAL POR PRODUCTO -->
                                    <tr style="border-top: 1px solid #94a3b8; font-weight: 800; background: #f8fafc;">
                                        <td colspan="4" style="padding: 0.6rem 0.4rem; text-align: right; color: #475569;">
                                            SUBTOTAL <?= htmlspecialchars($nombreProd) ?> (<?= $cantP ?> pesadas):
                                        </td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= number_format($subP['bruto'], 0) ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= number_format($subP['tara'], 0) ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= number_format($subP['neto'], 0) ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= $subP['empaques'] ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= number_format($subP['tara_emp'], 0) ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right; color: #0b57d0;"><?= number_format(array_sum(array_column($itemsProd, 'peso_final_lb')), 0) ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right; color: #0b57d0;"><?= number_format(array_sum(array_column($itemsProd, 'peso_final_lb')) / 100.0, 2) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>

                    <!-- SUB-TOTAL DE CATEGORÍA -->
                    <div style="background: #e2e8f0; border-radius: 6px; padding: 0.6rem 1rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; font-weight: 800; font-size: 0.88rem; color: #1e293b;">
                        <span>SUBTOTAL CATEGORÍA <?= htmlspecialchars($nombreCat) ?> (<?= $totCatPesadas ?> pesadas)</span>
                        <div style="display: flex; gap: 1.5rem;">
                            <span>Neto: <?= number_format($totCatNeto, 0) ?> lb</span>
                            <span style="color: <?= $colorGrupo ?>;">Final: <?= number_format(array_sum(array_map(fn($prodItems) => array_sum(array_column($prodItems, 'peso_final_lb')), $productosWeb)), 0) ?> lb</span>
                            <span style="color: <?= $colorGrupo ?>;">QQ: <?= number_format(array_sum(array_map(fn($prodItems) => array_sum(array_column($prodItems, 'peso_final_lb')), $productosWeb)) / 100.0, 2) ?></span>
                        </div>
                    </div>

                    <?php 
                    $totOpBruto += $totCatBruto;
                    $totOpTara  += $totCatTara;
                    $totOpNeto  += $totCatNeto;
                    $totOpEmp   += $totCatEmp;
                    $totOpTaraE += $totCatTaraE;
                    $totOpPesadas += $totCatPesadas;
                    ?>
                <?php endforeach; ?>

                <!-- SUB-TOTAL GENERAL DE LA OPERACIÓN -->
                <div style="background: #dbeafe; border-left: 5px solid <?= $colorGrupo ?>; border-radius: 6px; padding: 0.85rem 1.25rem; margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center; font-weight: 900; font-size: 0.95rem; color: #1e3a8a;">
                    <span>SUBTOTAL <?= $tituloGrupo ?> (<?= $totOpPesadas ?> pesadas cerradas)</span>
                    <div style="display: flex; gap: 1.75rem;">
                        <span>Bruto: <?= number_format($totOpBruto, 0) ?> lb</span>
                        <span>Tara: <?= number_format($totOpTara, 0) ?> lb</span>
                        <span>Neto: <?= number_format($totOpNeto, 0) ?> lb</span>
                        <span style="color: <?= $colorGrupo ?>;">Final: <?= number_format($totGrupo['neto'], 0) ?> lb</span>
                    </div>
                </div>

            <?php endforeach; ?>

            <!-- RESUMEN CONSOLIDADO TOTAL GENERAL (SI SE MUESTRAN AMBOS) -->
            <?php if ($filtroOperacion === 'TODOS' && count($arbolWeb) > 1): ?>
                <div style="background: #0f233a; color: #ffffff; border-radius: 8px; padding: 1.25rem 1.75rem; margin-top: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem;">
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Balance Consolidado</div>
                        <div style="font-size: 1.35rem; font-weight: 900;">TOTAL GENERAL DE BÁSCULA (<?= count($todasBoletas) ?> pesadas)</div>
                    </div>
                    <div style="display: flex; gap: 2rem; text-align: right;">
                        <div>
                            <span style="font-size: 0.75rem; color: #94a3b8; display: block;">Total Bruto</span>
                            <strong style="font-size: 1.15rem;"><?= number_format($totalesGenerales['bruto'], 0) ?> lb</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; color: #94a3b8; display: block;">Total Tara</span>
                            <strong style="font-size: 1.15rem;"><?= number_format($totalesGenerales['tara'], 0) ?> lb</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; color: #94a3b8; display: block;">Total Neto Final</span>
                            <strong style="font-size: 1.35rem; color: #60a5fa;"><?= number_format($totalesGenerales['neto'], 0) ?> lb</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; color: #94a3b8; display: block;">Total QQ</span>
                            <strong style="font-size: 1.35rem; color: #60a5fa;"><?= number_format($totalesGenerales['neto'] / 100.0, 2) ?></strong>
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
