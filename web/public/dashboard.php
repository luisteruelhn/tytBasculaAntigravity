<?php
/**
 * Dashboard de Operación por Empresa
 * Proyecto: tytBasculaAntigravity
 *
 * Muestra métricas consolidadas, KPIs, análisis de compras y ventas por producto,
 * evolución diaria y notas pendientes.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombreSesion = $usuarioActual['empresa_nombre'] ?? 'INVERSIONES VALLECILLO, SB';
$pdo = obtenerDB();

// 1. FILTROS DE EMPRESA Y FECHAS
$empresaFiltroId = (int)($_GET['empresa_id'] ?? $empresaId);
$stmtEmp = $pdo->query("SELECT id, razon_social, nombre_comercial FROM t_empresas WHERE activo = 1 ORDER BY razon_social ASC");
$listaEmpresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

$empresaSeleccionada = null;
foreach ($listaEmpresas as $emp) {
    if ((int)$emp['id'] === $empresaFiltroId) {
        $empresaSeleccionada = $emp;
        break;
    }
}
$nombreEmpresaMostrar = $empresaSeleccionada ? ($empresaSeleccionada['nombre_comercial'] ?: $empresaSeleccionada['razon_social']) : $empresaNombreSesion;

function normalizarFecha(string $f): string {
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $f)) {
        [$d, $m, $y] = explode('/', $f);
        return "$y-$m-$d";
    }
    return $f;
}

date_default_timezone_set('America/Tegucigalpa');

$fechaDesde = trim((string)($_GET['desde'] ?? date('Y-m-01')));
$fechaHasta = trim((string)($_GET['hasta'] ?? date('Y-m-d')));

$fechaDesdeSql = normalizarFecha($fechaDesde);
$fechaHastaSql = normalizarFecha($fechaHasta);

if ($fechaDesdeSql === '') {
    $fechaDesdeSql = date('Y-m-01');
}
if ($fechaHastaSql === '') {
    $fechaHastaSql = date('Y-m-d');
}

// 2. KPIS GENERALES
// A. Notas abiertas (actuales, cualquier fecha, fuera de rango)
$stmtAbiertas = $pdo->prepare("SELECT COUNT(*) AS total FROM t_pesadas WHERE empresa_id = :empresa_id AND estado = 'ABIERTA'");
$stmtAbiertas->execute([':empresa_id' => $empresaFiltroId]);
$totalNotasAbiertas = (int)($stmtAbiertas->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

// B. Pesadas cerradas en el rango
$stmtCerradas = $pdo->prepare("
    SELECT COUNT(*) AS total_cerradas,
           COALESCE(SUM(peso_final_lb), 0) AS suma_final_lb
    FROM t_pesadas
    WHERE empresa_id = :empresa_id 
      AND estado = 'CERRADA'
      AND DATE(COALESCE(emitida_at, segunda_pesada_at)) >= :desde
      AND DATE(COALESCE(emitida_at, segunda_pesada_at)) <= :hasta
");
$stmtCerradas->execute([
    ':empresa_id' => $empresaFiltroId,
    ':desde'      => $fechaDesdeSql,
    ':hasta'      => $fechaHastaSql
]);
$datosCerradas = $stmtCerradas->fetch(PDO::FETCH_ASSOC);
$totalPesadasCerradas = (int)($datosCerradas['total_cerradas'] ?? 0);
$sumaLibrasFinales    = (float)($datosCerradas['suma_final_lb'] ?? 0);
$totalQuintales       = round($sumaLibrasFinales / 100.0, 2);

// 3. DATOS DE COMPRAS Y VENTAS
function obtenerResumenOperacion(PDO $pdo, int $empresaId, string $operacion, string $desde, string $hasta): array {
    // Totales y espera promedio
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cerradas,
               COALESCE(SUM(peso_final_lb), 0) AS total_lb,
               AVG(CASE 
                   WHEN primera_pesada_at IS NOT NULL AND segunda_pesada_at IS NOT NULL AND segunda_pesada_at >= primera_pesada_at
                   THEN TIMESTAMPDIFF(SECOND, primera_pesada_at, segunda_pesada_at) / 3600.0
                   ELSE NULL
               END) AS espera_promedio_horas,
               SUM(CASE 
                   WHEN primera_pesada_at IS NOT NULL AND segunda_pesada_at IS NOT NULL AND segunda_pesada_at >= primera_pesada_at THEN 1 
                   ELSE 0 
               END) AS notas_con_tiempo_valido
        FROM t_pesadas
        WHERE empresa_id = :empresa_id
          AND estado = 'CERRADA'
          AND tipo_operacion = :operacion
          AND DATE(COALESCE(emitida_at, segunda_pesada_at)) >= :desde
          AND DATE(COALESCE(emitida_at, segunda_pesada_at)) <= :hasta
    ");
    $stmt->execute([
        ':empresa_id' => $empresaId,
        ':operacion'  => $operacion,
        ':desde'      => $desde,
        ':hasta'      => $hasta
    ]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    // Desglose por producto
    $stmtProd = $pdo->prepare("
        SELECT prod.nombre AS producto_nombre,
               COUNT(p.idpesada) AS cerradas,
               COALESCE(SUM(p.peso_final_lb), 0) AS final_lb
        FROM t_pesadas p
        JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
        JOIN t_productos prod ON prod.id = pr.producto_id
        WHERE p.empresa_id = :empresa_id
          AND p.estado = 'CERRADA'
          AND p.tipo_operacion = :operacion
          AND DATE(COALESCE(p.emitida_at, p.segunda_pesada_at)) >= :desde
          AND DATE(COALESCE(p.emitida_at, p.segunda_pesada_at)) <= :hasta
        GROUP BY prod.id, prod.nombre
        ORDER BY prod.nombre ASC
    ");
    $stmtProd->execute([
        ':empresa_id' => $empresaId,
        ':operacion'  => $operacion,
        ':desde'      => $desde,
        ':hasta'      => $hasta
    ]);
    $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    return [
        'cerradas'           => (int)($res['cerradas'] ?? 0),
        'total_lb'           => (float)($res['total_lb'] ?? 0),
        'total_qq'           => round((float)($res['total_lb'] ?? 0) / 100.0, 2),
        'espera_promedio'    => $res['espera_promedio_horas'] !== null ? round((float)$res['espera_promedio_horas'], 2) : null,
        'notas_con_tiempo'   => (int)($res['notas_con_tiempo_valido'] ?? 0),
        'productos'          => $productos
    ];
}

$datosCompras = obtenerResumenOperacion($pdo, $empresaFiltroId, 'COMPRA', $fechaDesdeSql, $fechaHastaSql);
$datosVentas  = obtenerResumenOperacion($pdo, $empresaFiltroId, 'VENTA', $fechaDesdeSql, $fechaHastaSql);

// 4. EVOLUCIÓN DIARIA
$stmtDiario = $pdo->prepare("
    SELECT DATE(COALESCE(emitida_at, segunda_pesada_at)) AS fecha_cierre,
           tipo_operacion,
           COUNT(*) AS cerradas,
           COALESCE(SUM(peso_final_lb), 0) AS final_lb
    FROM t_pesadas
    WHERE empresa_id = :empresa_id
      AND estado = 'CERRADA'
      AND DATE(COALESCE(emitida_at, segunda_pesada_at)) >= :desde
      AND DATE(COALESCE(emitida_at, segunda_pesada_at)) <= :hasta
    GROUP BY fecha_cierre, tipo_operacion
    ORDER BY fecha_cierre ASC, tipo_operacion ASC
");
$stmtDiario->execute([
    ':empresa_id' => $empresaFiltroId,
    ':desde'      => $fechaDesdeSql,
    ':hasta'      => $fechaHastaSql
]);
$filasDiarias = $stmtDiario->fetchAll(PDO::FETCH_ASSOC);

// Mapeo para gráfico diario
$mapaDiarioCompras = [];
$mapaDiarioVentas  = [];
foreach ($filasDiarias as $fd) {
    $f = $fd['fecha_cierre'];
    if ($fd['tipo_operacion'] === 'COMPRA') {
        $mapaDiarioCompras[$f] = (float)$fd['final_lb'];
    } elseif ($fd['tipo_operacion'] === 'VENTA') {
        $mapaDiarioVentas[$f] = (float)$fd['final_lb'];
    }
}

// Generar array de todos los días del rango para el gráfico
$diasRango = [];
$fechaIter = new DateTime($fechaDesdeSql);
$fechaFinIter = new DateTime($fechaHastaSql);
while ($fechaIter <= $fechaFinIter) {
    $diasRango[] = $fechaIter->format('Y-m-d');
    $fechaIter->modify('+1 day');
}
if (empty($diasRango)) {
    $diasRango[] = $fechaDesdeSql;
}

// Calcular max valor para escala del gráfico
$maxLb = 1.0;
foreach ($diasRango as $dia) {
    $cVal = $mapaDiarioCompras[$dia] ?? 0.0;
    $vVal = $mapaDiarioVentas[$dia] ?? 0.0;
    if ($cVal > $maxLb) $maxLb = $cVal;
    if ($vVal > $maxLb) $maxLb = $vVal;
}

// Puntos SVG
$widthSvg = 900;
$heightSvg = 180;
$paddingSvg = 30;
$numPuntos = count($diasRango);
$puntosCompras = [];
$puntosVentas = [];

foreach ($diasRango as $idx => $dia) {
    $x = $numPuntos > 1 ? $paddingSvg + ($idx / ($numPuntos - 1)) * ($widthSvg - 2 * $paddingSvg) : $widthSvg / 2;
    $cVal = $mapaDiarioCompras[$dia] ?? 0.0;
    $vVal = $mapaDiarioVentas[$dia] ?? 0.0;
    $yC = ($heightSvg - $paddingSvg) - ($cVal / $maxLb) * ($heightSvg - 2 * $paddingSvg);
    $yV = ($heightSvg - $paddingSvg) - ($vVal / $maxLb) * ($heightSvg - 2 * $paddingSvg);
    $puntosCompras[] = round($x, 1) . ',' . round($yC, 1);
    $puntosVentas[] = round($x, 1) . ',' . round($yV, 1);
}

// 5. NOTAS PENDIENTES ACTUALES (Hasta 20 ABIERTAS más antiguas)
$stmtPendientes = $pdo->prepare("
    SELECT p.idpesada, p.tipo_operacion, p.id_compra, p.id_venta,
           p.primera_pesada_at, cp.nombre AS persona_nombre
    FROM t_pesadas p
    LEFT JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
    WHERE p.empresa_id = :empresa_id AND p.estado = 'ABIERTA'
    ORDER BY p.primera_pesada_at ASC
    LIMIT 20
");
$stmtPendientes->execute([':empresa_id' => $empresaFiltroId]);
$notasPendientes = $stmtPendientes->fetchAll(PDO::FETCH_ASSOC);

// 6. PESADAS MANUALES EN EL RANGO (origen_bruto = 'MANUAL' O origen_tara = 'MANUAL')
$stmtManuales = $pdo->prepare("
    SELECT p.idpesada, p.tipo_operacion, p.id_compra, p.id_venta, p.estado,
           p.placa, p.peso_bruto_lb, p.tara_vehiculo_lb, p.peso_final_lb,
           p.origen_bruto, p.origen_tara,
           COALESCE(p.emitida_at, p.segunda_pesada_at, p.primera_pesada_at, p.fecha_at) AS fecha_ref,
           cp.nombre AS persona_nombre,
           prod.nombre AS producto_nombre
    FROM t_pesadas p
    LEFT JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
    LEFT JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
    LEFT JOIN t_productos prod ON prod.id = pr.producto_id
    WHERE p.empresa_id = :empresa_id
      AND p.estado <> 'ANULADA'
      AND (p.origen_bruto = 'MANUAL' OR p.origen_tara = 'MANUAL')
      AND DATE(COALESCE(p.emitida_at, p.segunda_pesada_at, p.primera_pesada_at, p.fecha_at)) >= :desde
      AND DATE(COALESCE(p.emitida_at, p.segunda_pesada_at, p.primera_pesada_at, p.fecha_at)) <= :hasta
    ORDER BY fecha_ref DESC, p.idpesada DESC
");
$stmtManuales->execute([
    ':empresa_id' => $empresaFiltroId,
    ':desde'      => $fechaDesdeSql,
    ':hasta'      => $fechaHastaSql,
]);
$pesadasManuales = $stmtManuales->fetchAll(PDO::FETCH_ASSOC);
$totalPesadasManuales = count($pesadasManuales);

renderizarEncabezado('Dashboard', 'dashboard');
?>

<div style="max-width: 1200px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div style="margin-bottom: 1.5rem;">
        <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
            OPERACIÓN POR EMPRESA
        </span>
        <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.2rem 0 0.2rem 0; color: var(--text-main); line-height: 1.1;">
            Dashboard
        </h1>
        <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
            <?= htmlspecialchars($nombreEmpresaMostrar, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>

    <!-- TARJETA DE FILTROS SUPERIORES -->
    <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.5rem;">
        <form method="GET" action="dashboard.php" style="margin: 0;">
            <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1rem;">
                <div style="flex: 1; min-width: 240px;">
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

                <div style="width: 175px;">
                    <label for="desde" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Desde
                    </label>
                    <input type="date" id="desde" name="desde" class="form-control" style="height: 40px; border-radius: 6px;" value="<?= htmlspecialchars($fechaDesdeSql, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div style="width: 175px;">
                    <label for="hasta" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Hasta
                    </label>
                    <input type="date" id="hasta" name="hasta" class="form-control" style="height: 40px; border-radius: 6px;" value="<?= htmlspecialchars($fechaHastaSql, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <button type="submit" class="btn btn-primary" style="height: 40px; padding: 0 1.5rem; font-weight: 700; background: #0b57d0; border-radius: 6px;">
                    Consultar
                </button>
            </div>

            <p style="font-size: 0.8rem; color: #64748b; margin: 0; line-height: 1.4;">
                Cierres por fecha emitida_at, ambos días completos (America/Tegucigalpa). Solo CERRADAS; se excluyen ABIERTAS y ANULADAS de libras y QQ. Resultados guardados, sin recalcular descuentos. 1 QQ = 100 lb.
            </p>
        </form>
    </div>

    <!-- BOTONES DE ACCESO RÁPIDO / PILL TABS -->
    <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap;">
        <a href="#notasPendientes" class="btn btn-secondary" style="border-radius: 8px; font-weight: 600; font-size: 0.85rem; padding: 0.5rem 1.15rem; text-decoration: none;">
            Notas pendientes
        </a>
        <a href="#pesadasManuales" class="btn btn-secondary" style="border-radius: 8px; font-weight: 600; font-size: 0.85rem; padding: 0.5rem 1.15rem; text-decoration: none;">
            Pesadas manuales (<?= $totalPesadasManuales ?>)
        </a>
        <a href="reportes.php?empresa_id=<?= $empresaFiltroId ?>&desde=<?= urlencode($fechaDesdeSql) ?>&hasta=<?= urlencode($fechaHastaSql) ?>" class="btn btn-primary" style="background: #0b57d0; border-radius: 8px; font-weight: 700; font-size: 0.85rem; padding: 0.5rem 1.25rem; text-decoration: none;">
            Reportes del rango
        </a>
        <a href="index.php" class="btn btn-secondary" style="border-radius: 8px; font-weight: 600; font-size: 0.85rem; padding: 0.5rem 1.15rem; text-decoration: none;">
            Inicio · lectura en vivo
        </a>
    </div>

    <!-- 5 TARJETAS KPI EN FILA -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        
        <!-- KPI 1: Notas abiertas -->
        <div class="card" style="padding: 1.5rem 1.4rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            <div style="font-size: 0.82rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem;">
                Notas abiertas
            </div>
            <div style="font-size: 2.2rem; font-weight: 800; color: var(--text-main); line-height: 1; margin-bottom: 0.45rem;">
                <?= number_format($totalNotasAbiertas) ?>
            </div>
            <div style="font-size: 0.78rem; color: #64748b;">
                Actuales, de cualquier fecha; fuera del rango.
            </div>
        </div>

        <!-- KPI 2: Pesadas cerradas -->
        <div class="card" style="padding: 1.5rem 1.4rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            <div style="font-size: 0.82rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem;">
                Pesadas cerradas
            </div>
            <div style="font-size: 2.2rem; font-weight: 800; color: var(--text-main); line-height: 1; margin-bottom: 0.45rem;">
                <?= number_format($totalPesadasCerradas) ?>
            </div>
            <div style="font-size: 0.78rem; color: #64748b;">
                Cierre dentro del rango.
            </div>
        </div>

        <!-- KPI 3: Pesadas manuales -->
        <a href="#pesadasManuales" class="card" style="padding: 1.5rem 1.4rem; border-radius: 12px; border: 1px solid <?= $totalPesadasManuales > 0 ? 'rgba(245, 158, 11, 0.45)' : 'var(--border-color)' ?>; background: var(--bg-surface); text-decoration: none; display: block;">
            <div style="font-size: 0.82rem; font-weight: 600; color: <?= $totalPesadasManuales > 0 ? '#d97706' : '#64748b' ?>; margin-bottom: 0.5rem;">
                Pesadas manuales
            </div>
            <div style="font-size: 2.2rem; font-weight: 800; color: <?= $totalPesadasManuales > 0 ? '#d97706' : 'var(--text-main)' ?>; line-height: 1; margin-bottom: 0.45rem;">
                <?= number_format($totalPesadasManuales) ?>
            </div>
            <div style="font-size: 0.78rem; color: #64748b;">
                Con bruto o tara manual en el rango (ver detalle).
            </div>
        </a>

        <!-- KPI 4: Libras finales -->
        <div class="card" style="padding: 1.5rem 1.4rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            <div style="font-size: 0.82rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem;">
                Libras finales
            </div>
            <div style="font-size: 2.2rem; font-weight: 800; color: var(--text-main); line-height: 1; margin-bottom: 0.45rem;">
                <?= number_format($sumaLibrasFinales) ?>
            </div>
            <div style="font-size: 0.78rem; color: #64748b;">
                Suma de peso final guardado.
            </div>
        </div>

        <!-- KPI 4: Quintales -->
        <div class="card" style="padding: 1.5rem 1.4rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            <div style="font-size: 0.82rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem;">
                Quintales
            </div>
            <div style="font-size: 2.2rem; font-weight: 800; color: var(--text-main); line-height: 1; margin-bottom: 0.45rem;">
                <?= number_format($totalQuintales, 2) ?>
            </div>
            <div style="font-size: 0.78rem; color: #64748b;">
                Libras finales / 100; dos decimales.
            </div>
        </div>

    </div>

    <!-- BLOQUE DE 2 COLUMNAS: COMPRAS Y VENTAS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">

        <!-- COLUMNA 1: COMPRAS -->
        <div class="card" style="padding: 1.75rem 1.75rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            <h2 style="font-size: 1.4rem; font-weight: 800; margin: 0 0 0.5rem 0; color: var(--text-main);">
                Compras
            </h2>
            <div style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem;">
                <?= $datosCompras['cerradas'] ?> cerradas &middot; 
                <?= number_format($datosCompras['total_lb']) ?> lb &middot; 
                <?= number_format($datosCompras['total_qq'], 2) ?> QQ &middot; 
                Espera promedio: <span style="font-weight: 800;"><?= $datosCompras['espera_promedio'] !== null ? $datosCompras['espera_promedio'] . ' h' : 'Sin datos' ?></span>
            </div>
            <p style="font-size: 0.8rem; color: #64748b; line-height: 1.4; margin-bottom: 1.25rem;">
                Tiempo entre primera y segunda pesada, en horas decimales (<?= $datosCompras['notas_con_tiempo'] ?> notas con ambas fechas válidas). Solo cerradas del rango de cierre; excluye anuladas. 1.50 h equivale a 1 hora y 30 minutos.
            </p>

            <h3 style="font-size: 1.05rem; font-weight: 800; margin: 0 0 0.35rem 0; color: var(--text-main);">
                Compras por producto
            </h3>
            <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1rem;">
                Productos en orden alfabético, agrupando todas sus presentaciones. Incluye productos inactivos con historial.
            </p>

            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Producto</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Cerradas</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Final lb</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">QQ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($datosCompras['productos'])): ?>
                        <tr>
                            <td colspan="4" style="padding: 1rem 0.5rem; color: #64748b; font-style: italic;">
                                Sin cierres en este rango.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($datosCompras['productos'] as $prod): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.65rem 0.5rem; font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($prod['producto_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= $prod['cerradas'] ?></td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= number_format((float)$prod['final_lb']) ?></td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= number_format((float)$prod['final_lb'] / 100.0, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- COLUMNA 2: VENTAS -->
        <div class="card" style="padding: 1.75rem 1.75rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            <h2 style="font-size: 1.4rem; font-weight: 800; margin: 0 0 0.5rem 0; color: var(--text-main);">
                Ventas
            </h2>
            <div style="font-size: 0.95rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.5rem;">
                <?= $datosVentas['cerradas'] ?> cerradas &middot; 
                <?= number_format($datosVentas['total_lb']) ?> lb &middot; 
                <?= number_format($datosVentas['total_qq'], 2) ?> QQ &middot; 
                Espera promedio: <span style="font-weight: 800;"><?= $datosVentas['espera_promedio'] !== null ? $datosVentas['espera_promedio'] . ' h' : 'Sin datos' ?></span>
            </div>
            <p style="font-size: 0.8rem; color: #64748b; line-height: 1.4; margin-bottom: 1.25rem;">
                Tiempo entre primera y segunda pesada, en horas decimales (<?= $datosVentas['notas_con_tiempo'] ?> notas con ambas fechas válidas). Solo cerradas del rango de cierre; excluye anuladas. 1.50 h equivale a 1 hora y 30 minutos.
            </p>

            <h3 style="font-size: 1.05rem; font-weight: 800; margin: 0 0 0.35rem 0; color: var(--text-main);">
                Ventas por producto
            </h3>
            <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1rem;">
                Productos en orden alfabético, agrupando todas sus presentaciones. Incluye productos inactivos con historial.
            </p>

            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Producto</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Cerradas</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Final lb</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">QQ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($datosVentas['productos'])): ?>
                        <tr>
                            <td colspan="4" style="padding: 1rem 0.5rem; color: #64748b; font-style: italic;">
                                Sin cierres en este rango.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($datosVentas['productos'] as $prod): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.65rem 0.5rem; font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($prod['producto_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= $prod['cerradas'] ?></td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= number_format((float)$prod['final_lb']) ?></td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= number_format((float)$prod['final_lb'] / 100.0, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- TARJETA: EVOLUCIÓN DIARIA (GRÁFICO LINEAL Y TABLA) -->
    <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.5rem;">
        <h2 style="font-size: 1.35rem; font-weight: 800; margin: 0 0 0.5rem 0; color: var(--text-main);">
            Evolución diaria
        </h2>
        <div style="font-size: 0.85rem; color: #475569; display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;">
            <span><strong style="color: #0b57d0; font-size: 1.1rem;">&bull;</strong> Compras</span>
            <span><strong style="color: #64748b; font-size: 1.1rem;">&bull;</strong> Ventas</span>
            <span>&middot; Libras finales por día</span>
        </div>

        <!-- GRÁFICO VECTORIAL LIGERO SVG -->
        <div style="background: #ffffff; border: 1px solid #f1f5f9; border-radius: 8px; padding: 1rem 0.5rem; margin-bottom: 0.75rem; overflow-x: auto;">
            <svg viewBox="0 0 <?= $widthSvg ?> <?= $heightSvg ?>" style="width: 100%; height: auto; max-height: 220px; display: block;">
                <!-- Eje base horizontal -->
                <line x1="<?= $paddingSvg ?>" y1="<?= $heightSvg - $paddingSvg ?>" x2="<?= $widthSvg - $paddingSvg ?>" y2="<?= $heightSvg - $paddingSvg ?>" stroke="#cbd5e1" stroke-width="1.5" />

                <!-- Línea de Compras -->
                <?php if (!empty($puntosCompras)): ?>
                    <polyline fill="none" stroke="#0b57d0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" points="<?= implode(' ', $puntosCompras) ?>" />
                <?php endif; ?>

                <!-- Línea de Ventas -->
                <?php if (!empty($puntosVentas)): ?>
                    <polyline fill="none" stroke="#64748b" stroke-width="2" stroke-dasharray="4,4" stroke-linecap="round" stroke-linejoin="round" points="<?= implode(' ', $puntosVentas) ?>" />
                <?php endif; ?>

                <!-- Etiquetas de fechas extremas -->
                <text x="<?= $paddingSvg ?>" y="<?= $heightSvg - 8 ?>" fill="#64748b" font-size="11" font-family="sans-serif">
                    <?= date('d/m/Y', strtotime($fechaDesdeSql)) ?>
                </text>
                <text x="<?= $widthSvg - $paddingSvg ?>" y="<?= $heightSvg - 8 ?>" text-anchor="end" fill="#64748b" font-size="11" font-family="sans-serif">
                    <?= date('d/m/Y', strtotime($fechaHastaSql)) ?>
                </text>
            </svg>
        </div>

        <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1.25rem;">
            Solo días con cierres; compras y ventas separadas. Días sin filas equivalen a cero.
        </p>

        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                    <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Fecha de cierre</th>
                    <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Operación</th>
                    <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Cerradas</th>
                    <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Final lb</th>
                    <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">QQ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filasDiarias)): ?>
                    <tr>
                        <td colspan="5" style="padding: 1.25rem 0.5rem; color: #64748b; font-style: italic;">
                            Sin cierres en este rango de fechas.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($filasDiarias as $fd): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 0.65rem 0.5rem; font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($fd['fecha_cierre'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= $fd['tipo_operacion'] === 'COMPRA' ? 'Compra' : 'Venta' ?></td>
                            <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= $fd['cerradas'] ?></td>
                            <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= number_format((float)$fd['final_lb']) ?></td>
                            <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= number_format((float)$fd['final_lb'] / 100.0, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- TARJETA: DETALLE DE PESADAS MANUALES DEL RANGO -->
    <div id="pesadasManuales" class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.35rem;">
            <h2 style="font-size: 1.35rem; font-weight: 800; margin: 0; color: var(--text-main);">
                Pesadas manuales en el rango (<?= $totalPesadasManuales ?>)
            </h2>
            <span style="font-size: 0.78rem; font-weight: 700; color: #b45309; background: rgba(245, 158, 11, 0.14); border: 1px solid rgba(245, 158, 11, 0.35); padding: 0.25rem 0.65rem; border-radius: 6px;">
                Auditoría de capturas manuales
            </span>
        </div>
        <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1rem;">
            Detalla todas las boletas dentro del rango seleccionado donde el peso bruto, la tara vehicular o ambos fueron ingresados en modo <strong>MANUAL</strong>.
        </p>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Boleta</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Operación</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Fecha</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Cliente / Proveedor</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Producto / Placa</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Captura Manual</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Bruto / Tara (lb)</th>
                        <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Final (lb / QQ)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pesadasManuales)): ?>
                        <tr>
                            <td colspan="8" style="padding: 1.25rem 0.5rem; color: #64748b; font-style: italic;">
                                No hay pesadas manuales registradas en este rango de fechas.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pesadasManuales as $pm): ?>
                            <?php
                                $numBol = ($pm['tipo_operacion'] === 'COMPRA')
                                    ? '#' . sprintf('%07d', (int)$pm['id_compra'])
                                    : '#' . sprintf('%07d', (int)$pm['id_venta']);
                                $esBrutoManual = (($pm['origen_bruto'] ?? '') === 'MANUAL');
                                $esTaraManual  = (($pm['origen_tara'] ?? '') === 'MANUAL');
                                if ($esBrutoManual && $esTaraManual) {
                                    $detalleManual = 'Bruto y Tara';
                                } elseif ($esBrutoManual) {
                                    $detalleManual = 'Peso Bruto';
                                } else {
                                    $detalleManual = 'Tara Vehículo';
                                }
                                $brutoTxt = $pm['peso_bruto_lb'] !== null ? number_format((float)$pm['peso_bruto_lb']) : '—';
                                $taraTxt  = $pm['tara_vehiculo_lb'] !== null ? number_format((float)$pm['tara_vehiculo_lb']) : '—';
                                $finalLb  = $pm['peso_final_lb'] !== null ? (float)$pm['peso_final_lb'] : null;
                            ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.65rem 0.5rem; font-weight: 700;">
                                    <?php if (($pm['estado'] ?? '') === 'CERRADA'): ?>
                                        <a href="boleta.php?id=<?= (int)$pm['idpesada'] ?>" style="color: #0b57d0; text-decoration: none;" title="Ver boleta">
                                            <?= $numBol ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #0b57d0;"><?= $numBol ?></span>
                                        <span style="font-size: 0.72rem; color: #d97706;">(ABIERTA)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155;">
                                    <?= $pm['tipo_operacion'] === 'COMPRA' ? 'Compra' : 'Venta' ?>
                                </td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155;">
                                    <?= htmlspecialchars((string)($pm['fecha_ref'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="padding: 0.65rem 0.5rem; font-weight: 600; color: var(--text-main);">
                                    <?= htmlspecialchars((string)($pm['persona_nombre'] ?? 'Sin asignar'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155;">
                                    <?= htmlspecialchars((string)($pm['producto_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($pm['placa'])): ?>
                                        <span style="font-size: 0.76rem; color: #64748b; font-family: monospace;">· <?= htmlspecialchars((string)$pm['placa'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.65rem 0.5rem;">
                                    <span style="font-size: 0.76rem; font-weight: 700; color: #b45309; background: rgba(245, 158, 11, 0.14); border: 1px solid rgba(245, 158, 11, 0.35); padding: 0.15rem 0.5rem; border-radius: 5px;">
                                        <?= $detalleManual ?>
                                    </span>
                                </td>
                                <td style="padding: 0.65rem 0.5rem; color: #334155; font-family: monospace;">
                                    B: <?= $brutoTxt ?><?= $esBrutoManual ? ' (M)' : '' ?> / T: <?= $taraTxt ?><?= $esTaraManual ? ' (M)' : '' ?>
                                </td>
                                <td style="padding: 0.65rem 0.5rem; font-weight: 600; color: var(--text-main);">
                                    <?php if ($finalLb !== null): ?>
                                        <?= number_format($finalLb) ?> lb (<?= number_format($finalLb / 100.0, 2) ?> QQ)
                                    <?php else: ?>
                                        <span style="color: #64748b; font-weight: 400;">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TARJETA: NOTAS PENDIENTES ACTUALES -->
    <div id="notasPendientes" class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 2rem;">
        <h2 style="font-size: 1.35rem; font-weight: 800; margin: 0 0 0.5rem 0; color: var(--text-main);">
            Notas pendientes actuales
        </h2>
        <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.75rem;">
            Hasta 20 notas ABIERTAS más antiguas por primera_pesada_at, de cualquier fecha. No es un saldo histórico al final del rango.
        </p>

        <div style="margin-bottom: 1rem;">
            <a href="segunda_pesada.php" style="font-size: 0.85rem; font-weight: 700; color: #0b57d0; text-decoration: none;">
                Ver todas &middot; Segunda pesada
            </a>
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-color); text-align: left;">
                    <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Nota</th>
                    <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Primera pesada</th>
                    <th style="padding: 0.6rem 0.5rem; font-weight: 700; color: #475569;">Cliente / proveedor</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($notasPendientes)): ?>
                    <tr>
                        <td colspan="3" style="padding: 1.25rem 0.5rem; color: #64748b; font-style: italic;">
                            Sin notas pendientes.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($notasPendientes as $np): ?>
                        <?php 
                            $numBoleta = ($np['tipo_operacion'] === 'COMPRA')
                                ? '#' . sprintf('%07d', $np['id_compra'])
                                : '#' . sprintf('%07d', $np['id_venta']);
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 0.65rem 0.5rem; font-weight: 700; color: #0b57d0;"><?= $numBoleta ?></td>
                            <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= htmlspecialchars($np['primera_pesada_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td style="padding: 0.65rem 0.5rem; color: #334155;"><?= htmlspecialchars($np['persona_nombre'] ?? 'Sin asignar', ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PIE ACLARATORIO -->
    <div style="font-size: 0.78rem; color: #94a3b8; text-align: center; margin-bottom: 3rem;">
        Consulta actualizada al cargar esta página. Las fracciones históricas se conservan y se presentan con la convención de libras enteras de reportes; los totales se redondean después de sumar.
    </div>

</div>

<style>
[data-theme="dark"] .card table td[style*="color: #334155"] {
    color: #f1f5f9 !important;
    font-weight: 600;
}
[data-theme="dark"] .card table th[style*="color: #475569"] {
    color: #94a3b8 !important;
}
[data-theme="dark"] .card table tr[style*="border-bottom: 1px solid #f1f5f9"] {
    border-bottom-color: var(--border-color) !important;
}
</style>

<?php
renderizarPie();
