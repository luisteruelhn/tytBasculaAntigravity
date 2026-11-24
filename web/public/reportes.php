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
$stmtEmp = $pdo->query("SELECT id, razon_social, nombre_comercial, logo, rtn, direccion, telefono, email FROM t_empresas WHERE activo = 1 ORDER BY razon_social ASC");
$listaEmpresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

// Datos de la empresa seleccionada para el reporte
$empresaInfo = null;
foreach ($listaEmpresas as $empItem) {
    if ((int)$empItem['id'] === $empresaFiltroId) {
        $empresaInfo = $empItem;
        break;
    }
}
if (!$empresaInfo && !empty($listaEmpresas)) {
    $empresaInfo = $listaEmpresas[0];
}

$stmtPersonas = $pdo->prepare("SELECT id, nombre, es_cliente, es_proveedor FROM t_clie_prov WHERE empresa_id = :empresa_id ORDER BY nombre ASC");
$stmtPersonas->execute([':empresa_id' => $empresaFiltroId]);
$listaPersonas = $stmtPersonas->fetchAll(PDO::FETCH_ASSOC);

// Cargar categorías y productos para los filtros
$stmtCategorias = $pdo->query("SELECT id, nombre FROM t_categorias ORDER BY nombre ASC");
$listaCategorias = $stmtCategorias ? $stmtCategorias->fetchAll(PDO::FETCH_ASSOC) : [];

$stmtProductos = $pdo->query("SELECT id, nombre, categoria_id FROM t_productos WHERE activo = 1 ORDER BY nombre ASC");
$listaProductos = $stmtProductos ? $stmtProductos->fetchAll(PDO::FETCH_ASSOC) : [];

// 2. PARÁMETROS DEL FILTRO
$filtroOperacion = trim((string)($_GET['operacion'] ?? 'TODOS')); // TODOS, COMPRA, VENTA
$tipoPersona     = trim((string)($_GET['tipo_persona'] ?? 'TODOS')); // TODOS, CLIENTES, PROVEEDORES
$personaId       = (int)($_GET['persona_id'] ?? 0);
$categoriaId     = (int)($_GET['categoria_id'] ?? 0);
$productoId      = (int)($_GET['producto_id'] ?? 0);
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
// Usar el primer día del mes en curso y el día actual (en zona America/Tegucigalpa) por defecto
if ($fechaDesdeSql === '') {
    $fechaDesdeSql = date('Y-m-01');
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

if ($categoriaId > 0) {
    $sql .= " AND prod.categoria_id = :categoria_id";
    $params[':categoria_id'] = $categoriaId;
}

if ($productoId > 0) {
    $sql .= " AND prod.id = :producto_id";
    $params[':producto_id'] = $productoId;
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

// Función helper para calcular métricas exactas de una fila del reporte
function calcularMetricasFilaReporte(array $r): array {
    $bruto = (float)$r['peso_bruto_lb'];
    $taraV = (float)$r['tara_vehiculo_lb'];
    $neto  = max(0.0, $bruto - $taraV);
    $cant  = (int)$r['cantidad_empaques'];
    $taraCalc = ceil(round((float)($r['tara_unitaria_aplicada_lb'] ?? 0) * $cant, 4));
    $baseNeto = ($r['base_descuentos_lb'] !== null) ? (float)$r['base_descuentos_lb'] : max(0.0, $neto - $taraCalc);
    $taraE    = ($r['base_descuentos_lb'] !== null) ? max(0.0, $neto - $baseNeto) : $taraCalc;
    $pctH     = (float)($r['ajuste_humedad_pct'] ?? 0);
    $pctD     = (float)($r['descuento_calidad_pct'] ?? 0);
    $finalLb  = (float)$r['peso_final_lb'];
    $qq       = round($finalLb / 100.0, 2);

    return [
        'bruto'     => $bruto,
        'tara'      => $taraV,
        'neto'      => $neto,
        'empaques'  => $cant,
        'tara_emp'  => $taraE,
        'base_neto' => $baseNeto,
        'pct_h'     => $pctH,
        'pct_d'     => $pctD,
        'final'     => $finalLb,
        'qq'        => $qq,
    ];
}

function formatearPorcentajeReporte(float $pct): string {
    return $pct > 0 ? rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%' : '';
}

// Función helper para calcular totales de un conjunto de filas
function sumarTotales(array $items): array {
    $t = ['bruto' => 0.0, 'tara' => 0.0, 'neto' => 0.0, 'empaques' => 0, 'tara_emp' => 0.0, 'base_neto' => 0.0, 'final' => 0.0, 'qq' => 0.0];
    foreach ($items as $r) {
        $m = calcularMetricasFilaReporte($r);
        $t['bruto']     += $m['bruto'];
        $t['tara']      += $m['tara'];
        $t['neto']      += $m['neto'];
        $t['empaques']  += $m['empaques'];
        $t['tara_emp']  += $m['tara_emp'];
        $t['base_neto'] += $m['base_neto'];
        $t['final']     += $m['final'];
        $t['qq']        += $m['qq'];
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
    echo '<tr><th colspan="16" style="background-color: #0f172a; color: #ffffff; font-size: 14pt; padding: 10px; text-align: center;">REPORTE DE BOLETAS DE PESAJE</th></tr>';
    echo '<tr><td colspan="16" style="text-align: center; color: #475569; font-size: 10pt;">Empresa: ' . htmlspecialchars($empresaNombre) . ' &middot; Del ' . date('d/m/Y', strtotime($fechaDesdeSql)) . ' al ' . date('d/m/Y', strtotime($fechaHastaSql)) . '</td></tr>';
    echo '<tr><td colspan="16" style="height: 10px; background-color: #ffffff; border: none;"></td></tr>';

    $totGenBruto = 0.0;
    $totGenTara  = 0.0;
    $totGenNeto  = 0.0;
    $totGenEmp   = 0;
    $totGenTaraE = 0.0;
    $totGenBase  = 0.0;
    $totGenFinal = 0.0;
    $totGenQQ    = 0.0;
    $totGenPesadas = 0;

    foreach ($arbol as $op => $categorias) {
        $tituloOp = ($op === 'COMPRA') ? 'COMPRAS' : 'VENTAS';
        $colorOpHeader = ($op === 'COMPRA') ? '#1e40af' : '#047857';

        echo '<tr><th colspan="16" style="background-color: ' . $colorOpHeader . '; color: #ffffff; font-size: 12pt; text-align: left; padding: 6px 10px;">SECCIÓN: ' . $tituloOp . '</th></tr>';

        $totOpBruto = 0.0;
        $totOpTara  = 0.0;
        $totOpNeto  = 0.0;
        $totOpEmp   = 0;
        $totOpTaraE = 0.0;
        $totOpBase  = 0.0;
        $totOpFinal = 0.0;
        $totOpQQ    = 0.0;
        $totOpPesadas = 0;

        foreach ($categorias as $nombreCategoria => $productos) {
            echo '<tr style="background-color: #334155; color: #ffffff; font-weight: bold;">';
            echo '<td colspan="16" style="padding: 5px 10px; font-size: 11pt;">CATEGORÍA: ' . htmlspecialchars($nombreCategoria) . '</td>';
            echo '</tr>';

            $totCatBruto = 0.0;
            $totCatTara  = 0.0;
            $totCatNeto  = 0.0;
            $totCatEmp   = 0;
            $totCatTaraE = 0.0;
            $totCatBase  = 0.0;
            $totCatFinal = 0.0;
            $totCatQQ    = 0.0;
            $totCatPesadas = 0;

            foreach ($productos as $nombreProducto => $boletasProducto) {
                echo '<tr style="background-color: #f1f5f9; font-weight: bold;">';
                echo '<td colspan="16" style="color: #0f172a; font-size: 10.5pt; padding-left: 15px;">Producto: ' . htmlspecialchars($nombreProducto) . '</td>';
                echo '</tr>';

                echo '<tr style="background-color: #e2e8f0; color: #1e293b; font-weight: bold; text-align: center; font-size: 9.5pt;">';
                echo '<th>Operación</th>';
                echo '<th>Boleta</th>';
                echo '<th>Entrada / Primera</th>';
                echo '<th>Salida / Segunda</th>';
                echo '<th>Cliente / Proveedor</th>';
                echo '<th>Producto</th>';
                echo '<th>Presentación</th>';
                echo '<th>BRUTO</th>';
                echo '<th>TARA</th>';
                echo '<th>NETO</th>';
                echo '<th>SACOS</th>';
                echo '<th>TARA SACOS</th>';
                echo '<th>NETO</th>';
                echo '<th>%H</th>';
                echo '<th>%D</th>';
                echo '<th>TOTAL QQ</th>';
                echo '</tr>';

                $subBruto = 0.0;
                $subTara  = 0.0;
                $subNeto  = 0.0;
                $subEmp   = 0;
                $subTaraE = 0.0;
                $subBase  = 0.0;
                $subFinal = 0.0;
                $subQQ    = 0.0;
                $subPesadas = count($boletasProducto);

                foreach ($boletasProducto as $b) {
                    $numB = ($b['tipo_operacion'] === 'COMPRA') ? '#' . sprintf('%07d', $b['id_compra']) : '#' . sprintf('%07d', $b['id_venta']);
                    $m    = calcularMetricasFilaReporte($b);

                    $subBruto += $m['bruto'];
                    $subTara  += $m['tara'];
                    $subNeto  += $m['neto'];
                    $subEmp   += $m['empaques'];
                    $subTaraE += $m['tara_emp'];
                    $subBase  += $m['base_neto'];
                    $subFinal += $m['final'];
                    $subQQ    += $m['qq'];

                    echo '<tr>';
                    echo '<td align="center">' . htmlspecialchars($b['tipo_operacion']) . '</td>';
                    echo '<td align="center" style="font-weight: bold;">' . htmlspecialchars($numB) . '</td>';
                    echo '<td align="center">' . htmlspecialchars((string)$b['primera_pesada_at']) . '</td>';
                    echo '<td align="center">' . htmlspecialchars((string)$b['segunda_pesada_at']) . '</td>';
                    echo '<td>' . htmlspecialchars((string)$b['persona_nombre']) . '</td>';
                    echo '<td>' . htmlspecialchars((string)$b['producto_nombre']) . '</td>';
                    echo '<td>' . htmlspecialchars((string)$b['presentacion_nombre']) . '</td>';
                    echo '<td align="right">' . number_format($m['bruto'], 0) . '</td>';
                    echo '<td align="right">' . number_format($m['tara'], 0) . '</td>';
                    echo '<td align="right">' . number_format($m['neto'], 0) . '</td>';
                    echo '<td align="right">' . $m['empaques'] . '</td>';
                    echo '<td align="right">' . number_format($m['tara_emp'], 2) . '</td>';
                    echo '<td align="right">' . number_format($m['base_neto'], 0) . '<br><span style="font-size: 8.5pt; color: #475569;">' . number_format($m['base_neto'] / 100.0, 2) . '</span></td>';
                    echo '<td align="right">' . formatearPorcentajeReporte($m['pct_h']) . '</td>';
                    echo '<td align="right">' . formatearPorcentajeReporte($m['pct_d']) . '</td>';
                    echo '<td align="right" style="font-weight: bold;">' . number_format($m['qq'], 2) . '</td>';
                    echo '</tr>';
                }

                // Fila de Subtotal por Producto con conteo de pesadas
                echo '<tr style="background-color: #f8fafc; font-weight: bold; border-top: 1px solid #94a3b8;">';
                echo '<td colspan="7" align="right" style="color: #475569;">SUBTOTAL (' . htmlspecialchars($nombreProducto) . ' &middot; ' . $subPesadas . ' pesadas):</td>';
                echo '<td align="right">' . number_format($subBruto, 0) . '</td>';
                echo '<td align="right">' . number_format($subTara, 0) . '</td>';
                echo '<td align="right">' . number_format($subNeto, 0) . '</td>';
                echo '<td align="right">' . $subEmp . '</td>';
                echo '<td align="right">' . number_format($subTaraE, 2) . '</td>';
                echo '<td align="right">' . number_format($subBase, 0) . '<br><span style="font-size: 8.5pt; color: #475569;">' . number_format($subBase / 100.0, 2) . '</span></td>';
                echo '<td></td>';
                echo '<td></td>';
                echo '<td align="right" style="color: #0b57d0;">' . number_format($subQQ, 2) . '</td>';
                echo '</tr>';

                $totCatBruto += $subBruto;
                $totCatTara  += $subTara;
                $totCatNeto  += $subNeto;
                $totCatEmp   += $subEmp;
                $totCatTaraE += $subTaraE;
                $totCatBase  += $subBase;
                $totCatFinal += $subFinal;
                $totCatQQ    += $subQQ;
                $totCatPesadas += $subPesadas;
            }

            // Fila de Subtotal por Categoría con conteo de pesadas
            echo '<tr style="background-color: #e2e8f0; font-weight: bold; font-size: 10.5pt;">';
            echo '<td colspan="7" align="right" style="color: #1e293b;">SUBTOTAL CATEGORÍA ' . htmlspecialchars($nombreCategoria) . ' (' . $totCatPesadas . ' pesadas):</td>';
            echo '<td align="right">' . number_format($totCatBruto, 0) . '</td>';
            echo '<td align="right">' . number_format($totCatTara, 0) . '</td>';
            echo '<td align="right">' . number_format($totCatNeto, 0) . '</td>';
            echo '<td align="right">' . $totCatEmp . '</td>';
            echo '<td align="right">' . number_format($totCatTaraE, 2) . '</td>';
            echo '<td align="right">' . number_format($totCatBase, 0) . '<br><span style="font-size: 8.5pt; color: #475569;">' . number_format($totCatBase / 100.0, 2) . '</span></td>';
            echo '<td></td>';
            echo '<td></td>';
            echo '<td align="right" style="color: #1e293b;">' . number_format($totCatQQ, 2) . '</td>';
            echo '</tr>';
            echo '<tr><td colspan="16" style="height: 6px; background-color: #ffffff; border: none;"></td></tr>';

            $totOpBruto += $totCatBruto;
            $totOpTara  += $totCatTara;
            $totOpNeto  += $totCatNeto;
            $totOpEmp   += $totCatEmp;
            $totOpTaraE += $totCatTaraE;
            $totOpBase  += $totCatBase;
            $totOpFinal += $totCatFinal;
            $totOpQQ    += $totCatQQ;
            $totOpPesadas += $totCatPesadas;
        }

        // Fila de Subtotal de Toda la Operación (Total Compras o Total Ventas)
        echo '<tr style="background-color: #dbeafe; font-weight: bold; font-size: 11pt;">';
        echo '<td colspan="7" align="right" style="color: #1e3a8a;">SUBTOTAL ' . $tituloOp . ' (' . $totOpPesadas . ' pesadas):</td>';
        echo '<td align="right">' . number_format($totOpBruto, 0) . '</td>';
        echo '<td align="right">' . number_format($totOpTara, 0) . '</td>';
        echo '<td align="right">' . number_format($totOpNeto, 0) . '</td>';
        echo '<td align="right">' . $totOpEmp . '</td>';
        echo '<td align="right">' . number_format($totOpTaraE, 2) . '</td>';
        echo '<td align="right">' . number_format($totOpBase, 0) . '<br><span style="font-size: 8.5pt; color: #1e3a8a;">' . number_format($totOpBase / 100.0, 2) . '</span></td>';
        echo '<td></td>';
        echo '<td></td>';
        echo '<td align="right" style="color: #1e3a8a;">' . number_format($totOpQQ, 2) . '</td>';
        echo '</tr>';
        echo '<tr><td colspan="16" style="height: 12px; background-color: #ffffff; border: none;"></td></tr>';

        // Acumular a totales Generales
        $totGenBruto += $totOpBruto;
        $totGenTara  += $totOpTara;
        $totGenNeto  += $totOpNeto;
        $totGenEmp   += $totOpEmp;
        $totGenTaraE += $totOpTaraE;
        $totGenBase  += $totOpBase;
        $totGenFinal += $totOpFinal;
        $totGenQQ    += $totOpQQ;
        $totGenPesadas += $totOpPesadas;
    }

    // Acumulador de subtotales generales por producto (consolidando compras y ventas)
    $totalesPorProducto = [];
    foreach ($todasBoletas as $b) {
        $pNom = $b['producto_nombre'] ?: 'SIN PRODUCTO';
        if (!isset($totalesPorProducto[$pNom])) {
            $totalesPorProducto[$pNom] = ['bruto' => 0.0, 'tara' => 0.0, 'neto' => 0.0, 'empaques' => 0, 'tara_emp' => 0.0, 'base_neto' => 0.0, 'final' => 0.0, 'qq' => 0.0, 'boletas' => 0];
        }
        $m = calcularMetricasFilaReporte($b);

        $totalesPorProducto[$pNom]['bruto']     += $m['bruto'];
        $totalesPorProducto[$pNom]['tara']      += $m['tara'];
        $totalesPorProducto[$pNom]['neto']      += $m['neto'];
        $totalesPorProducto[$pNom]['empaques']  += $m['empaques'];
        $totalesPorProducto[$pNom]['tara_emp']  += $m['tara_emp'];
        $totalesPorProducto[$pNom]['base_neto'] += $m['base_neto'];
        $totalesPorProducto[$pNom]['final']     += $m['final'];
        $totalesPorProducto[$pNom]['qq']        += $m['qq'];
        $totalesPorProducto[$pNom]['boletas']++;
    }

    // SECCIÓN: RESUMEN Y SUBTOTAL GENERAL POR PRODUCTO
    echo '<tr><td colspan="16" style="height: 16px; background-color: #ffffff; border: none;"></td></tr>';
    echo '<tr><th colspan="16" style="background-color: #334155; color: #ffffff; font-size: 11pt; text-align: left; padding: 6px 10px;">RESUMEN DE SUBTOTALES GENERALES POR PRODUCTO (COMPRAS Y VENTAS)</th></tr>';
    echo '<tr style="background-color: #e2e8f0; color: #0f172a; font-weight: bold; text-align: center; font-size: 10pt;">';
    echo '<th colspan="2">Boletas</th><th colspan="5">Producto</th><th>BRUTO</th><th>TARA</th><th>NETO</th><th>SACOS</th><th>TARA SACOS</th><th colspan="3">NETO</th><th>TOTAL QQ</th>';
    echo '</tr>';

    foreach ($totalesPorProducto as $pNom => $tProd) {
        echo '<tr style="background-color: #f8fafc; font-weight: bold;">';
        echo '<td colspan="2" align="center">' . $tProd['boletas'] . ' boletas</td>';
        echo '<td colspan="5" style="color: #0f172a; padding-left: 10px;">' . htmlspecialchars($pNom) . '</td>';
        echo '<td align="right">' . number_format($tProd['bruto'], 0) . '</td>';
        echo '<td align="right">' . number_format($tProd['tara'], 0) . '</td>';
        echo '<td align="right">' . number_format($tProd['neto'], 0) . '</td>';
        echo '<td align="right">' . $tProd['empaques'] . '</td>';
        echo '<td align="right">' . number_format($tProd['tara_emp'], 2) . '</td>';
        echo '<td colspan="3" align="right">' . number_format($tProd['base_neto'], 0) . ' (' . number_format($tProd['base_neto'] / 100.0, 2) . ')</td>';
        echo '<td align="right" style="color: #0b57d0;">' . number_format($tProd['qq'], 2) . '</td>';
        echo '</tr>';
    }
    echo '<tr><td colspan="16" style="height: 14px; background-color: #ffffff; border: none;"></td></tr>';

    // Fila de TOTAL GENERAL CONSOLIDADO
    echo '<tr style="background-color: #0f172a; color: #ffffff; font-weight: bold; font-size: 12pt;">';
    echo '<td colspan="7" align="right" style="padding: 8px;">TOTAL GENERAL CONSOLIDADO:</td>';
    echo '<td align="right">' . number_format($totGenBruto, 0) . '</td>';
    echo '<td align="right">' . number_format($totGenTara, 0) . '</td>';
    echo '<td align="right">' . number_format($totGenNeto, 0) . '</td>';
    echo '<td align="right">' . $totGenEmp . '</td>';
    echo '<td align="right">' . number_format($totGenTaraE, 2) . '</td>';
    echo '<td colspan="3" align="right">' . number_format($totGenBase, 0) . ' (' . number_format($totGenBase / 100.0, 2) . ')</td>';
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
    <div class="no-print" style="margin-bottom: 1.5rem;">
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
    <div class="card no-print" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 2rem;">
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

                <div>
                    <label for="categoria_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Categoría
                    </label>
                    <select id="categoria_id" name="categoria_id" class="form-control" style="height: 40px; border-radius: 6px;" onchange="filtrarCategoria(this.value)">
                        <option value="0">Todas las categorías</option>
                        <?php foreach ($listaCategorias as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat['id'] === $categoriaId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="producto_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Producto
                    </label>
                    <select id="producto_id" name="producto_id" class="form-control" style="height: 40px; border-radius: 6px;">
                        <option value="0">Todos los productos</option>
                        <?php foreach ($listaProductos as $prod): 
                            $prodCatId = (int)($prod['categoria_id'] ?? 0);
                            $oculto = ($categoriaId > 0 && $prodCatId !== $categoriaId);
                        ?>
                            <option value="<?= $prod['id'] ?>" data-cat="<?= $prodCatId ?>" <?= $prod['id'] === $productoId ? 'selected' : '' ?> style="<?= $oculto ? 'display:none;' : '' ?>">
                                <?= htmlspecialchars($prod['nombre'], ENT_QUOTES, 'UTF-8') ?>
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
                <button type="button" onclick="descargarPDFDirecto()" id="btnDescargarPDF" class="btn btn-secondary" style="height: 40px; padding: 0 1.15rem; font-weight: 600; border-radius: 6px; display: inline-flex; align-items: center; gap: 0.35rem;">
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
        
        <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #0f233a; padding-bottom: 1.25rem; margin-bottom: 2rem; gap: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 1.25rem;">
                <?php if (!empty($empresaInfo['logo']) && file_exists(__DIR__ . '/' . $empresaInfo['logo'])): ?>
                    <img src="<?= htmlspecialchars($empresaInfo['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Logo" style="max-height: 80px; max-width: 130px; object-fit: contain;">
                <?php endif; ?>
                <div>
                    <h2 style="font-size: 1.45rem; font-weight: 800; margin: 0 0 0.2rem 0; color: var(--text-main); text-transform: uppercase; line-height: 1.15;">
                        <?= htmlspecialchars($empresaInfo['nombre_comercial'] ?: ($empresaInfo['razon_social'] ?? $empresaNombre), ENT_QUOTES, 'UTF-8') ?>
                    </h2>
                    <div style="font-size: 0.82rem; color: #64748b; line-height: 1.4;">
                        <?php if (!empty($empresaInfo['direccion'])): ?>
                            <?= htmlspecialchars($empresaInfo['direccion'], ENT_QUOTES, 'UTF-8') ?><br>
                        <?php endif; ?>
                        <?php if (!empty($empresaInfo['telefono'])): ?>
                            <strong>Tel:</strong> <?= htmlspecialchars($empresaInfo['telefono'], ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                        <?php if (!empty($empresaInfo['email'])): ?>
                            &middot; <?= htmlspecialchars($empresaInfo['email'], ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div style="text-align: right;">
                <h3 style="font-size: 1.35rem; font-weight: 900; letter-spacing: 0.5px; margin: 0 0 0.25rem 0; color: #0f233a; line-height: 1.1;">
                    REPORTE DE BOLETAS <?= ($filtroOperacion !== 'TODOS' ? '— ' . $filtroOperacion . 'S' : '') ?>
                </h3>
                <div style="font-size: 0.85rem; color: #64748b; font-weight: 600;">
                    Del <?= date('d/m/Y', strtotime($fechaDesdeSql)) ?> al <?= date('d/m/Y', strtotime($fechaHastaSql)) ?> &middot; Cierre
                </div>
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

                $totOpBruto = 0.0; $totOpTara = 0.0; $totOpNeto = 0.0; $totOpEmp = 0; $totOpTaraE = 0.0; $totOpBase = 0.0; $totOpFinal = 0.0; $totOpQQ = 0.0; $totOpPesadas = 0;
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
                    $totCatBruto = 0.0; $totCatTara = 0.0; $totCatNeto = 0.0; $totCatEmp = 0; $totCatTaraE = 0.0; $totCatBase = 0.0; $totCatFinal = 0.0; $totCatQQ = 0.0; $totCatPesadas = 0;
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
                        $totCatBase  += $subP['base_neto'];
                        $totCatFinal += $subP['final'];
                        $totCatQQ    += $subP['qq'];
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
                                        <th style="padding: 0.5rem 0.4rem; text-align: left; font-weight: 800; color: #0f172a;">Entrada</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: left; font-weight: 800; color: #0f172a;">Salida</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: left; font-weight: 800; color: #0f172a;">Boleta</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: left; font-weight: 800; color: #0f172a;">Cliente / proveedor</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800; color: #0f172a;">BRUTO</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800; color: #0f172a;">TARA</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800; color: #0f172a;">NETO</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800; color: #0f172a;">SACOS</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800; color: #0f172a;">TARA SACOS</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800; color: #0f172a;">NETO</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800; color: #0f172a;">%H</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800; color: #0f172a;">%D</th>
                                        <th style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800; color: #0f172a;">TOTAL QQ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itemsProd as $row): 
                                        $m = calcularMetricasFilaReporte($row);

                                        $numBoleta = ($row['tipo_operacion'] === 'COMPRA') 
                                            ? '#' . sprintf('%07d', $row['id_compra']) 
                                            : '#' . sprintf('%07d', $row['id_venta']);
                                        
                                        $fEntrada = !empty($row['primera_pesada_at']) ? date('d/m/Y H:i', strtotime($row['primera_pesada_at'])) : '—';
                                        $fSalida  = !empty($row['segunda_pesada_at']) ? date('d/m/Y H:i', strtotime($row['segunda_pesada_at'])) : '—';
                                    ?>
                                        <tr style="border-bottom: 1px dotted #e2e8f0; vertical-align: top;">
                                            <td style="padding: 0.5rem 0.4rem;"><?= $fEntrada ?></td>
                                            <td style="padding: 0.5rem 0.4rem;"><?= $fSalida ?></td>
                                            <td style="padding: 0.5rem 0.4rem; font-weight: 800; font-family: monospace; color: <?= $colorGrupo ?>;"><?= htmlspecialchars($numBoleta, ENT_QUOTES, 'UTF-8') ?></td>
                                            <td style="padding: 0.5rem 0.4rem; font-weight: 600;"><?= htmlspecialchars((string)($row['persona_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= number_format($m['bruto'], 0) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= number_format($m['tara'], 0) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 600;"><?= number_format($m['neto'], 0) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= $m['empaques'] ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= number_format($m['tara_emp'], 2) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right; line-height: 1.15;">
                                                <div><?= number_format($m['base_neto'], 0) ?></div>
                                                <div style="font-size: 0.72rem; color: #475569;"><?= number_format($m['base_neto'] / 100.0, 2) ?></div>
                                            </td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= formatearPorcentajeReporte($m['pct_h']) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right;"><?= formatearPorcentajeReporte($m['pct_d']) ?></td>
                                            <td style="padding: 0.5rem 0.4rem; text-align: right; font-weight: 800;"><?= number_format($m['qq'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <!-- SUB-TOTAL POR PRODUCTO -->
                                    <tr style="border-top: 1px solid #94a3b8; font-weight: 800; background: #f8fafc; vertical-align: top;">
                                        <td colspan="4" style="padding: 0.6rem 0.4rem; text-align: right; color: #475569;">
                                            SUBTOTAL <?= htmlspecialchars($nombreProd) ?> (<?= $cantP ?> pesadas):
                                        </td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= number_format($subP['bruto'], 0) ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= number_format($subP['tara'], 0) ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= number_format($subP['neto'], 0) ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= $subP['empaques'] ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right;"><?= number_format($subP['tara_emp'], 2) ?></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right; line-height: 1.15;">
                                            <div><?= number_format($subP['base_neto'], 0) ?></div>
                                            <div style="font-size: 0.72rem; color: #475569;"><?= number_format($subP['base_neto'] / 100.0, 2) ?></div>
                                        </td>
                                        <td style="padding: 0.6rem 0.4rem;"></td>
                                        <td style="padding: 0.6rem 0.4rem;"></td>
                                        <td style="padding: 0.6rem 0.4rem; text-align: right; color: #0b57d0;"><?= number_format($subP['qq'], 2) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>

                    <!-- SUB-TOTAL DE CATEGORÍA -->
                    <div style="background: #e2e8f0; border-radius: 6px; padding: 0.6rem 1rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; font-weight: 800; font-size: 0.88rem; color: #1e293b;">
                        <span>SUBTOTAL CATEGORÍA <?= htmlspecialchars($nombreCat) ?> (<?= $totCatPesadas ?> pesadas)</span>
                        <div style="display: flex; gap: 1.5rem;">
                            <span>Neto: <?= number_format($totCatBase, 0) ?> lb (<?= number_format($totCatBase / 100.0, 2) ?>)</span>
                            <span style="color: <?= $colorGrupo ?>;">TOTAL QQ: <?= number_format($totCatQQ, 2) ?></span>
                        </div>
                    </div>

                    <?php 
                    $totOpBruto += $totCatBruto;
                    $totOpTara  += $totCatTara;
                    $totOpNeto  += $totCatNeto;
                    $totOpEmp   += $totCatEmp;
                    $totOpTaraE += $totCatTaraE;
                    $totOpBase  += $totCatBase;
                    $totOpFinal += $totCatFinal;
                    $totOpQQ    += $totCatQQ;
                    $totOpPesadas += $totCatPesadas;
                    ?>
                <?php endforeach; ?>

                <!-- SUB-TOTAL GENERAL DE LA OPERACIÓN -->
                <div style="background: #dbeafe; border-left: 5px solid <?= $colorGrupo ?>; border-radius: 6px; padding: 0.85rem 1.25rem; margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center; font-weight: 900; font-size: 0.95rem; color: #1e3a8a;">
                    <span>SUBTOTAL <?= $tituloGrupo ?> (<?= $totOpPesadas ?> pesadas cerradas)</span>
                    <div style="display: flex; gap: 1.75rem;">
                        <span>Bruto: <?= number_format($totOpBruto, 0) ?></span>
                        <span>Tara: <?= number_format($totOpTara, 0) ?></span>
                        <span>Neto: <?= number_format($totOpBase, 0) ?> (<?= number_format($totOpBase / 100.0, 2) ?>)</span>
                        <span style="color: <?= $colorGrupo ?>;">TOTAL QQ: <?= number_format($totOpQQ, 2) ?></span>
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
                            <span style="font-size: 0.75rem; color: #94a3b8; display: block;">Total Neto</span>
                            <strong style="font-size: 1.35rem; color: #60a5fa;"><?= number_format($totalesGenerales['base_neto'], 0) ?> lb</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; color: #94a3b8; display: block;">TOTAL QQ</span>
                            <strong style="font-size: 1.35rem; color: #60a5fa;"><?= number_format($totalesGenerales['qq'], 2) ?></strong>
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

<?php
// Pasar productos a JS para reconstruir el select limpiamente en cualquier navegador
$productosJson = json_encode($listaProductos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!-- Librería para generación directa de PDF en cliente -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
const CATALOGO_PRODUCTOS = <?= $productosJson ?>;

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

function filtrarCategoria(catId) {
    const selProd = document.getElementById('producto_id');
    if (!selProd) return;

    catId = parseInt(catId || '0', 10);
    const prodSeleccionadoPrevio = parseInt(selProd.value || '0', 10);

    // Vaciar y reconstruir options para que funcione en 100% de los navegadores (Chrome/Edge/Firefox)
    selProd.innerHTML = '<option value="0">Todos los productos</option>';

    CATALOGO_PRODUCTOS.forEach(p => {
        const cId = parseInt(p.categoria_id || '0', 10);
        if (catId === 0 || cId === catId) {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.nombre;
            opt.setAttribute('data-cat', cId);
            if (parseInt(p.id, 10) === prodSeleccionadoPrevio) {
                opt.selected = true;
            }
            selProd.appendChild(opt);
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const catSel = document.getElementById('categoria_id');
    if (catSel && catSel.value !== '0') {
        filtrarCategoria(catSel.value);
    }
});

function imprimirReporte() {
    window.print();
}

function descargarPDFDirecto() {
    const btn = document.getElementById('btnDescargarPDF');
    const textoOriginal = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Generando PDF...';

    const elemento = document.getElementById('seccionReporteImprimible');
    if (!elemento) {
        alert('No se encontró el contenedor del reporte');
        btn.disabled = false;
        btn.innerHTML = textoOriginal;
        return;
    }

    if (typeof html2pdf !== 'function') {
        // Si no está disponible html2pdf, abrir diálogo nativo de imprimir/guardar PDF
        window.print();
        btn.disabled = false;
        btn.innerHTML = textoOriginal;
        return;
    }

    const opt = {
        margin:       [6, 6, 6, 6],
        filename:     'Reporte_Boletas_<?= date('Ymd_His') ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, logging: false },
        jsPDF:        { unit: 'mm', format: 'letter', orientation: 'landscape' },
        pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
    };

    html2pdf().set(opt).from(elemento).save().then(() => {
        btn.disabled = false;
        btn.innerHTML = textoOriginal;
    }).catch(err => {
        console.error('Error html2pdf:', err);
        window.print();
        btn.disabled = false;
        btn.innerHTML = textoOriginal;
    });
}
</script>

<style>
@media print {
    .app-sidebar, .mobile-topbar, .footer, #formReporte, .no-print, #chat-widget-btn, #chat-widget-panel, [class*="chat-"] {
        display: none !important;
    }
    .app-main-wrapper, .app-main, .app-content {
        margin: 0 !important;
        padding: 0 !important;
    }
    #seccionReporteImprimible {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        max-width: 100% !important;
    }
    body {
        background: #ffffff !important;
        color: #000000 !important;
    }
    @page {
        size: letter landscape;
        margin: 0.8cm;
    }
}
</style>

<?php
renderizarPie();
