<?php
/**
 * Boleta Oficial de Peso (Impresión / Consulta)
 * Proyecto: tytBasculaAntigravity
 *
 * Fiel al diseño industrial:
 * - Tipo de operación (COMPRA / VENTA) en grande y destacado
 * - A la derecha: Nombre de la empresa, Dirección, Teléfono, Email y Logo si existe
 * - Formato limpio para impresión directa (Ctrl+P / Botón Imprimir)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
$empresaId = (int)$usuarioActual['empresa_id'];
$pdo = obtenerDB();

$idPesada = (int)($_GET['id'] ?? 0);
if ($idPesada <= 0) {
    die("ID de pesada inválido.");
}

// 1. OBTENER DATOS DE LA PESADA
$stmt = $pdo->prepare("
    SELECT p.*, 
           cp.nombre AS persona_nombre,
           pr.nombre AS presentacion_nombre,
           prod.nombre AS producto_nombre,
           u_op.nombre AS operador_nombre,
           u_resp.nombre AS responsable_nombre
    FROM t_pesadas p
    JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
    JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
    JOIN t_productos prod ON prod.id = pr.producto_id
    LEFT JOIN t_usuarios u_op ON u_op.id = p.emitida_por
    LEFT JOIN t_usuarios u_resp ON u_resp.id = p.responsable_id
    WHERE p.idpesada = :id AND p.empresa_id = :empresa_id
    LIMIT 1
");
$stmt->execute([':id' => $idPesada, ':empresa_id' => $empresaId]);
$pesada = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pesada) {
    die("No se encontró la boleta de peso especificada.");
}

// 2. OBTENER DATOS INSTITUCIONALES DE LA EMPRESA
$stmtEmp = $pdo->prepare("SELECT * FROM t_empresas WHERE id = :id LIMIT 1");
$stmtEmp->execute([':id' => $empresaId]);
$empresa = $stmtEmp->fetch(PDO::FETCH_ASSOC);

$tipoOp = $pesada['tipo_operacion']; // COMPRA o VENTA
$correlativo = ($tipoOp === 'COMPRA') ? $pesada['id_compra'] : $pesada['id_venta'];
$tituloOp = ($tipoOp === 'COMPRA' ? 'COMPRA #' : 'VENTA #') . $correlativo;

// Fechas formateadas
$fechaEntrada = !empty($pesada['primera_pesada_at']) ? date('d/m/Y H:i', strtotime($pesada['primera_pesada_at'])) : '—';
$fechaSalida  = !empty($pesada['segunda_pesada_at']) ? date('d/m/Y H:i', strtotime($pesada['segunda_pesada_at'])) : '—';
$fechaCierre  = !empty($pesada['segunda_pesada_at']) ? date('d/m/Y H:i', strtotime($pesada['segunda_pesada_at'])) : date('d/m/Y H:i', strtotime($pesada['fecha_at']));

$netoInicial = max(0.0, (float)$pesada['peso_bruto_lb'] - (float)$pesada['tara_vehiculo_lb']);
$taraEmpaquesTotal = round((float)($pesada['tara_unitaria_aplicada_lb'] ?? 0) * (int)$pesada['cantidad_empaques']);
$baseDescuentos = (float)($pesada['base_descuentos_lb'] ?? ($netoInicial - $taraEmpaquesTotal));
$pesoFinal = (float)($pesada['peso_final_lb'] ?? $baseDescuentos);
$quintales = $pesoFinal / 100.0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boleta de Peso #<?= $correlativo ?> — <?= htmlspecialchars($empresa['nombre_comercial'] ?: $empresa['razon_social'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f1f5f9; color: #0f172a; padding: 2rem 1rem; }
        .boleta-contenedor { max-width: 900px; margin: 0 auto; background: #ffffff; padding: 2.5rem 3rem; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        
        /* ENCABEZADO CON OPERACIÓN EN GRANDE A LA IZQUIERDA Y EMPRESA A LA DERECHA */
        .boleta-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f233a; padding-bottom: 1.5rem; margin-bottom: 1.5rem; gap: 1.5rem; }
        .op-tag { font-size: 2.4rem; font-weight: 900; letter-spacing: -0.5px; line-height: 1; color: <?= ($tipoOp === 'COMPRA' ? '#1e40af' : '#047857') ?>; }
        .op-sub { font-size: 1.25rem; font-weight: 800; color: #475569; margin-top: 0.25rem; }
        .op-cierre { font-size: 0.85rem; color: #64748b; margin-top: 0.35rem; }

        .empresa-info { text-align: right; max-width: 450px; }
        .empresa-logo { max-height: 60px; max-width: 180px; object-fit: contain; margin-bottom: 0.5rem; }
        .empresa-nombre { font-size: 1.25rem; font-weight: 800; color: #0f172a; line-height: 1.2; text-transform: uppercase; }
        .empresa-detalle { font-size: 0.85rem; color: #475569; margin-top: 0.25rem; line-height: 1.4; }

        /* GRID DE DATOS */
        .datos-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 2rem; margin-bottom: 1.75rem; font-size: 0.92rem; }
        .dato-fila { display: flex; justify-content: space-between; border-bottom: 1px dotted #e2e8f0; padding-bottom: 0.35rem; }
        .dato-label { font-weight: 700; color: #0f172a; }
        .dato-val { color: #334155; }

        /* TABLA DE DESGLOSE */
        .tabla-pesaje { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
        .tabla-pesaje th { background: #f8fafc; color: #475569; font-weight: 500; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.3px; padding: 0.65rem 0.4rem; text-align: center; border-top: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1; }
        .tabla-pesaje th.th-qq { background: #ffe4e6; color: #881337; font-weight: 700; font-size: 0.75rem; }
        .tabla-pesaje td { padding: 1rem 0.4rem; text-align: center; font-size: 1.05rem; font-weight: 700; border-bottom: 1px solid #e2e8f0; }
        .td-destacado { background: #fff1f2; color: #881337; font-size: 1.6rem !important; font-weight: 900 !important; }

        /* NOTAS Y OBSERVACIONES */
        .boleta-notas { font-size: 0.82rem; color: #475569; line-height: 1.6; margin-bottom: 3.5rem; }

        /* FIRMAS */
        .firmas-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; text-align: center; font-size: 0.88rem; font-weight: 600; color: #334155; }
        .firma-linea { border-top: 1px solid #0f172a; padding-top: 0.5rem; }

        /* BOTONES DE PANTALLA (OCULTOS EN IMPRESIÓN) */
        .acciones-pantalla { max-width: 900px; margin: 0 auto 1.5rem auto; display: flex; justify-content: space-between; align-items: center; }

        @media print {
            body { background: #ffffff; padding: 0; }
            .boleta-contenedor { box-shadow: none; border: none; padding: 0; max-width: 100%; }
            .acciones-pantalla { display: none !important; }
        }
    </style>
</head>
<body>

<div class="acciones-pantalla">
    <a href="segunda_pesada.php" style="color: #1e40af; font-size: 0.95rem; font-weight: 600; text-decoration: none;">
        &larr; Volver a Segunda Pesada
    </a>
    <div style="display: flex; gap: 1rem;">
        <a href="pesadas.php" class="btn btn-secondary" style="padding: 0.5rem 1.25rem; font-size: 0.9rem; text-decoration: none; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; color: #0f172a; font-weight: 600;">
            Historial de Boletas
        </a>
        <button onclick="window.print()" style="padding: 0.5rem 1.5rem; font-size: 0.95rem; background: #0b57d0; color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer;">
            🖨️ Imprimir Boleta
        </button>
    </div>
</div>

<div class="boleta-contenedor">

    <!-- 1. ENCABEZADO CON COMPRA/VENTA EN GRANDE Y DATOS DE LA EMPRESA A LA DERECHA -->
    <div class="boleta-header">
        <div>
            <div style="font-size: 0.8rem; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: #64748b; margin-bottom: 0.25rem;">
                BOLETA DE PESO
            </div>
            <!-- TIPO DE OPERACIÓN EN GRANDE -->
            <div class="op-tag">
                <?= $tipoOp ?>
            </div>
            <div class="op-sub">
                <?= ($tipoOp === 'COMPRA' ? 'Compra #' . $pesada['id_compra'] : 'Venta #' . $pesada['id_venta']) ?>
            </div>
            <div class="op-cierre">
                Cierre: <?= $fechaCierre ?>
            </div>
        </div>

        <div class="empresa-info">
            <?php if (!empty($empresa['logo']) && file_exists(__DIR__ . '/' . $empresa['logo'])): ?>
                <img src="<?= htmlspecialchars($empresa['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="empresa-logo"><br>
            <?php endif; ?>
            <div class="empresa-nombre">
                <?= htmlspecialchars($empresa['nombre_comercial'] ?: $empresa['razon_social'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="empresa-detalle">
                <?php if (!empty($empresa['rtn'])): ?>
                    <strong>RTN:</strong> <?= htmlspecialchars($empresa['rtn'], ENT_QUOTES, 'UTF-8') ?><br>
                <?php endif; ?>
                <?php if (!empty($empresa['direccion'])): ?>
                    <?= htmlspecialchars($empresa['direccion'], ENT_QUOTES, 'UTF-8') ?><br>
                <?php endif; ?>
                <?php if (!empty($empresa['telefono'])): ?>
                    <strong>Tel:</strong> <?= htmlspecialchars($empresa['telefono'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
                <?php if (!empty($empresa['email'])): ?>
                    &middot; <?= htmlspecialchars($empresa['email'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 2. GRID DE DETALLES DEL VEHÍCULO, CLIENTE Y TRASLADO -->
    <div class="datos-grid">
        <div>
            <div class="dato-fila">
                <span class="dato-label">Placa:</span>
                <span class="dato-val" style="font-weight: 800; font-family: monospace; font-size: 1rem;"><?= htmlspecialchars($pesada['placa'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Licencia:</span>
                <span class="dato-val"><?= htmlspecialchars($pesada['licencia'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Entrada / primera:</span>
                <span class="dato-val"><?= $fechaEntrada ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Salida / segunda:</span>
                <span class="dato-val"><?= $fechaSalida ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Cliente / proveedor:</span>
                <span class="dato-val" style="font-weight: 700;"><?= htmlspecialchars($pesada['persona_nombre'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Producto:</span>
                <span class="dato-val" style="font-weight: 700;"><?= htmlspecialchars($pesada['producto_nombre'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <div>
            <div class="dato-fila">
                <span class="dato-label">Transacción:</span>
                <span class="dato-val" style="font-weight: 800; color: #1e40af;"><?= $tipoOp ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Motorista:</span>
                <span class="dato-val"><?= htmlspecialchars($pesada['motorista'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Origen:</span>
                <span class="dato-val"><?= htmlspecialchars($pesada['origen'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Destino:</span>
                <span class="dato-val"><?= htmlspecialchars($pesada['destino'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Operador de cierre:</span>
                <span class="dato-val"><?= htmlspecialchars($pesada['operador_nombre'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="dato-fila">
                <span class="dato-label">Presentación:</span>
                <span class="dato-val"><?= htmlspecialchars($pesada['presentacion_nombre'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </div>

    <!-- 3. TABLA DE VALORES DE PESAJE FIEL A LA IMAGEN -->
    <table class="tabla-pesaje">
        <thead>
            <tr>
                <th>Bruto lb</th>
                <th>Tara lb</th>
                <th>Neto lb</th>
                <th>Empaques</th>
                <th>Tara empaques lb</th>
                <th>Base lb</th>
                <th>%H</th>
                <th>%D</th>
                <th>Final lb</th>
                <th style="background: #ffe4e6; color: #881337;">Total QQ</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= number_format((float)$pesada['peso_bruto_lb'], 0) ?></td>
                <td><?= number_format((float)$pesada['tara_vehiculo_lb'], 0) ?></td>
                <td><?= number_format($netoInicial, 0) ?></td>
                <td><?= (int)$pesada['cantidad_empaques'] ?></td>
                <td><?= number_format($taraEmpaquesTotal, 0) ?></td>
                <td><?= number_format($baseDescuentos, 0) ?></td>
                <td><?= number_format((float)$pesada['ajuste_humedad_pct'], 2) ?></td>
                <td><?= number_format((float)$pesada['descuento_calidad_pct'], 2) ?></td>
                <td style="font-weight: 800;"><?= number_format($pesoFinal, 0) ?></td>
                <td class="td-destacado"><?= number_format($quintales, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <!-- 4. NOTAS DESCRIPTIVAS Y DETALLE DE CÁLCULO -->
    <div class="boleta-notas">
        <div>Descuento humedad: <?= number_format((float)$pesada['descuento_humedad_lb'], 0) ?> lb. Descuento defectos: <?= number_format((float)$pesada['descuento_defectos_lb'], 0) ?> lb.</div>
        <div>Tara unitaria aplicada: <?= number_format((float)($pesada['tara_unitaria_aplicada_lb'] ?? 0), 3) ?> lb. 1 QQ = 100 lb.</div>
        <div>Origen bruto: <?= htmlspecialchars($pesada['origen_bruto'] ?? 'INDICADOR', ENT_QUOTES, 'UTF-8') ?>. Origen tara: <?= htmlspecialchars($pesada['origen_tara'] ?? 'INDICADOR', ENT_QUOTES, 'UTF-8') ?>.</div>
        <?php if (!empty($pesada['observaciones'])): ?>
            <div>Observaciones primera: <?= htmlspecialchars($pesada['observaciones'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($pesada['observaciones_segunda'])): ?>
            <div>Observaciones segunda: <?= htmlspecialchars($pesada['observaciones_segunda'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div style="margin-top: 0.35rem; color: #94a3b8;">Copia para impresión / reimpresión. Fechas en America/Tegucigalpa.</div>
    </div>

    <!-- 5. FIRMAS -->
    <div class="firmas-grid">
        <div class="firma-linea">
            Firma operador báscula
        </div>
        <div class="firma-linea">
            Firma cliente / proveedor
        </div>
    </div>

</div>

</body>
</html>
