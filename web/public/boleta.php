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
$taraEmpaquesCalc = ceil(round((float)($pesada['tara_unitaria_aplicada_lb'] ?? 0) * (int)$pesada['cantidad_empaques'], 4));
$baseDescuentos = (float)($pesada['base_descuentos_lb'] ?? ($netoInicial - $taraEmpaquesCalc));
$taraEmpaquesTotal = ($pesada['base_descuentos_lb'] !== null) ? max(0.0, $netoInicial - $baseDescuentos) : $taraEmpaquesCalc;
$pesoFinal = (float)($pesada['peso_final_lb'] ?? $baseDescuentos);
$quintales = $pesoFinal / 100.0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boleta de Peso #<?= $correlativo ?> — <?= htmlspecialchars($empresa['nombre_comercial'] ?: $empresa['razon_social'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico?v=20261125">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico?v=20261125">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background: #f1f5f9; color: #0f172a; padding: 1.5rem 1rem; }
        .hoja-carta-doble { max-width: 900px; margin: 0 auto; }
        
        .boleta-contenedor { 
            background: #ffffff; 
            padding: 1.5rem 2.25rem; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px -1px rgba(0,0,0,0.08); 
            border: 1px solid #e2e8f0; 
        }
        
        /* ENCABEZADO CON EMPRESA A LA IZQUIERDA Y OPERACIÓN A LA DERECHA */
        .boleta-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #0f233a; padding-bottom: 0.75rem; margin-bottom: 0.85rem; gap: 1rem; }
        .empresa-info { text-align: left; }
        .empresa-logo { max-height: 52px; max-width: 120px; object-fit: contain; }
        .empresa-nombre { font-size: 1.15rem; font-weight: 800; color: #0f172a; line-height: 1.15; text-transform: uppercase; }
        .empresa-detalle { font-size: 0.78rem; color: #475569; margin-top: 0.15rem; line-height: 1.35; }

        .op-contenedor { text-align: right; }
        .op-tag { font-size: 2rem; font-weight: 900; letter-spacing: -0.5px; line-height: 1; color: <?= ($tipoOp === 'COMPRA' ? '#1e40af' : '#047857') ?>; }
        .op-sub { font-size: 1.05rem; font-weight: 800; color: #475569; margin-top: 0.15rem; font-family: monospace; letter-spacing: 0.5px; }
        .op-cierre { font-size: 0.78rem; color: #64748b; margin-top: 0.25rem; }

        /* GRID DE DATOS */
        .datos-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem 2rem; margin-bottom: 0.85rem; font-size: 0.82rem; }
        .dato-fila { display: flex; justify-content: space-between; border-bottom: 1px dotted #e2e8f0; padding-bottom: 0.2rem; }
        .dato-label { font-weight: 700; color: #0f172a; }
        .dato-val { color: #334155; }

        /* TABLA DE DESGLOSE */
        .tabla-pesaje { width: 100%; border-collapse: collapse; margin-bottom: 0.85rem; }
        .tabla-pesaje th { background: #f8fafc; color: #475569; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.3px; padding: 0.45rem 0.35rem; text-align: center; border-top: 1px solid #cbd5e1; border-bottom: 1px solid #cbd5e1; }
        .tabla-pesaje th.th-qq { background: #ffe4e6; color: #881337; font-weight: 700; font-size: 0.72rem; }
        .tabla-pesaje td { padding: 0.6rem 0.35rem; text-align: center; font-size: 0.95rem; font-weight: 700; border-bottom: 1px solid #e2e8f0; }
        .td-destacado { background: #fff1f2; color: #881337; font-size: 1.35rem !important; font-weight: 900 !important; }

        /* NOTAS Y OBSERVACIONES */
        .boleta-notas { font-size: 0.75rem; color: #475569; line-height: 1.45; margin-bottom: 1.75rem; }

        /* FIRMAS */
        .firmas-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3.5rem; text-align: center; font-size: 0.8rem; font-weight: 600; color: #334155; }
        .firma-linea { border-top: 1px solid #0f172a; padding-top: 0.35rem; }

        /* LÍNEA DE CORTE DIBUJADA Y RESPONSIVE CON TIJERA AL PRINCIPIO */
        .separador-corte {
            display: flex;
            align-items: center;
            margin: 1.25rem 0;
            width: 100%;
            gap: 0.5rem;
            color: #64748b;
        }
        .separador-corte .tijera-icono {
            font-size: 1.25rem;
            line-height: 1;
            user-select: none;
        }
        .separador-corte .linea-punteada {
            flex: 1;
            border-bottom: 2px dashed #94a3b8;
            height: 0;
        }

        /* BOTONES DE PANTALLA (OCULTOS EN IMPRESIÓN) */
        .acciones-pantalla { max-width: 900px; margin: 0 auto 1.25rem auto; display: flex; justify-content: space-between; align-items: center; }

        @media print {
            html, body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .hoja-carta-doble {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                height: 100% !important;
            }
            .boleta-contenedor {
                box-shadow: none !important;
                border: 1px solid #94a3b8 !important;
                padding: 1.15rem 1.65rem !important;
                margin: 0 !important;
                flex: 1 !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: space-between !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .boleta-header {
                padding-bottom: 0.5rem !important;
                margin-bottom: 0.6rem !important;
            }
            .empresa-logo {
                max-height: 52px !important;
                max-width: 120px !important;
            }
            .empresa-nombre {
                font-size: 1.2rem !important;
            }
            .op-tag {
                font-size: 1.9rem !important;
            }
            .datos-grid {
                gap: 0.25rem 2rem !important;
                margin-bottom: 0.65rem !important;
                font-size: 0.82rem !important;
            }
            .tabla-pesaje {
                margin-bottom: 0.65rem !important;
            }
            .tabla-pesaje th {
                padding: 0.35rem 0.25rem !important;
                font-size: 0.7rem !important;
            }
            .tabla-pesaje td {
                padding: 0.5rem 0.25rem !important;
                font-size: 0.95rem !important;
            }
            .td-destacado {
                font-size: 1.35rem !important;
            }
            .boleta-notas {
                font-size: 0.75rem !important;
                line-height: 1.4 !important;
                margin-bottom: 1.1rem !important;
            }
            .firmas-grid {
                gap: 3rem !important;
                font-size: 0.8rem !important;
                margin-top: 0.5rem !important;
            }
            .firma-linea {
                padding-top: 0.45rem !important;
            }
            .acciones-pantalla {
                display: none !important;
            }
            .separador-corte {
                margin: 0.75rem 0 !important;
                width: 100% !important;
            }
            .separador-corte .tijera-icono {
                font-size: 1.2rem !important;
            }
            @page {
                size: letter portrait;
                margin: 0.6cm 0.6cm;
            }
        }
    </style>
</head>
<body>

<div class="acciones-pantalla">
    <a href="index.php" style="color: #1e40af; font-size: 0.95rem; font-weight: 600; text-decoration: none;">
        &larr; Volver a Inicio
    </a>
    <div style="display: flex; gap: 1rem;">
        <a href="pesadas.php" class="btn btn-secondary" style="padding: 0.5rem 1.25rem; font-size: 0.9rem; text-decoration: none; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; color: #0f172a; font-weight: 600;">
            Historial de Boletas
        </a>
        <?php if ($tipoOp === 'VENTA'): ?>
        <a href="guia_remision.php?id=<?= $idPesada ?>" class="btn btn-secondary" style="padding: 0.5rem 1.25rem; font-size: 0.9rem; text-decoration: none; border: 1px solid #047857; background: #ecfdf5; border-radius: 6px; color: #047857; font-weight: 700;">
            🚚 Guía de Remisión
        </a>
        <?php endif; ?>
        <button type="button" onclick="descargarBoletaPDF()" id="btnDescargarBoletaPDF" style="padding: 0.5rem 1.25rem; font-size: 0.95rem; background: #ffffff; color: #0f172a; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
            📄 Descargar PDF
        </button>
        <button onclick="window.print()" style="padding: 0.5rem 1.5rem; font-size: 0.95rem; background: #0b57d0; color: #fff; border: none; border-radius: 6px; font-weight: 700; cursor: pointer;">
            🖨️ Imprimir Boletas (2 por hoja)
        </button>
    </div>
</div>

<div class="hoja-carta-doble">

<?php 
// Función auxiliar para renderizar el cuerpo de una boleta idéntica
$renderizarBoleta = function(string $etiquetaCopia) use ($empresa, $tipoOp, $correlativo, $fechaCierre, $pesada, $fechaEntrada, $fechaSalida, $netoInicial, $taraEmpaquesTotal, $baseDescuentos, $pesoFinal, $quintales) {
?>
    <div class="boleta-contenedor">

        <!-- 1. ENCABEZADO CON LOGO A LA IZQUIERDA Y OPERACIÓN A LA DERECHA -->
        <div class="boleta-header">
            <div class="empresa-info" style="display: flex; align-items: center; gap: 1rem;">
                <?php if (!empty($empresa['logo']) && file_exists(__DIR__ . '/' . $empresa['logo'])): ?>
                    <img src="<?= htmlspecialchars($empresa['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="empresa-logo" style="max-height: 55px; max-width: 120px; object-fit: contain;">
                <?php endif; ?>
                <div>
                    <div class="empresa-nombre">
                        <?= htmlspecialchars($empresa['nombre_comercial'] ?: $empresa['razon_social'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="empresa-detalle">
                        <?php if (!empty($empresa['direccion'])): ?>
                            <?= htmlspecialchars($empresa['direccion'], ENT_QUOTES, 'UTF-8') ?><br>
                        <?php endif; ?>
                        <?php if (!empty($empresa['rtn'])): ?>
                            <strong>RTN:</strong> <?= htmlspecialchars($empresa['rtn'], ENT_QUOTES, 'UTF-8') ?><br>
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

            <div class="op-contenedor">
                <div style="font-size: 0.72rem; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: #64748b; margin-bottom: 0.15rem;">
                    BOLETA DE PESO
                </div>
                <div class="op-tag">
                    <?= $tipoOp ?>
                </div>
                <div class="op-sub">
                    # <?= sprintf('%07d', $correlativo) ?>
                </div>
                <div class="op-cierre">
                    Cierre: <?= $fechaCierre ?>
                </div>
            </div>
        </div>

        <!-- 2. GRID DE DETALLES DEL VEHÍCULO, CLIENTE Y TRASLADO -->
        <div class="datos-grid">
            <div>
                <div class="dato-fila">
                    <span class="dato-label">Placa:</span>
                    <span class="dato-val" style="font-weight: 800; font-family: monospace; font-size: 0.95rem;"><?= htmlspecialchars($pesada['placa'], ENT_QUOTES, 'UTF-8') ?></span>
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

        <!-- 3. TABLA DE VALORES DE PESAJE -->
        <table class="tabla-pesaje">
            <thead>
                <tr>
                    <th>Bruto</th>
                    <th>Tara</th>
                    <th>Neto</th>
                    <th>Sacos</th>
                    <th>Tara Sacos</th>
                    <th>Neto</th>
                    <th>%H</th>
                    <th>%D</th>
                    <th style="background: #ffe4e6; color: #881337;">Total QQ</th>
                </tr>
            </thead>
            <tbody>
                <?php
                    $pctHumVal = (float)$pesada['ajuste_humedad_pct'];
                    $pctDefVal = (float)$pesada['descuento_calidad_pct'];
                    $baseQQ    = $baseDescuentos / 100.0;
                ?>
                <tr>
                    <td><?= number_format((float)$pesada['peso_bruto_lb'], 0) ?></td>
                    <td><?= number_format((float)$pesada['tara_vehiculo_lb'], 0) ?></td>
                    <td><?= number_format($netoInicial, 0) ?></td>
                    <td><?= (int)$pesada['cantidad_empaques'] ?></td>
                    <td><?= number_format($taraEmpaquesTotal, 0) ?></td>
                    <td>
                        <div><?= number_format($baseDescuentos, 0) ?></div>
                        <div style="font-size: 0.72rem; color: #475569; font-weight: 700;"><?= number_format($baseQQ, 2) ?> QQ</div>
                    </td>
                    <td><?= rtrim(rtrim(number_format($pctHumVal, 2), '0'), '.') ?>%</td>
                    <td><?= rtrim(rtrim(number_format($pctDefVal, 2), '0'), '.') ?>%</td>
                    <td class="td-destacado"><?= number_format($quintales, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- 4. OBSERVACIONES -->
        <div class="boleta-notas">
            <?php if (!empty($pesada['observaciones']) || !empty($pesada['observaciones_segunda'])): ?>
                <?php if (!empty($pesada['observaciones'])): ?>
                    <div>Observaciones primera: <?= htmlspecialchars($pesada['observaciones'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if (!empty($pesada['observaciones_segunda'])): ?>
                    <div>Observaciones segunda: <?= htmlspecialchars($pesada['observaciones_segunda'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            <?php else: ?>
                <div>Observaciones: —</div>
            <?php endif; ?>
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
<?php 
};

// 1. RENDERIZAR COPIA ORIGINAL (PARTE SUPERIOR)
$renderizarBoleta('ORIGINAL');
?>

<!-- LÍNEA DE CORTE DIBUJADA Y RESPONSIVE CON TIJERA AL PRINCIPIO -->
<div class="separador-corte">
    <span class="tijera-icono">✂</span>
    <div class="linea-punteada"></div>
</div>

<?php 
// 2. RENDERIZAR SEGUNDA COPIA (PARTE INFERIOR)
$renderizarBoleta('COPIA');
?>

</div>

<!-- Librería para descarga directa de PDF en cliente -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
function descargarBoletaPDF() {
    const btn = document.getElementById('btnDescargarBoletaPDF');
    const textoOriginal = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Generando PDF...';

    // Capturar únicamente la primera boleta (la original) para que sea un PDF limpio de 1 sola página
    const elemento = document.querySelector('.boleta-contenedor');
    if (!elemento || typeof html2pdf !== 'function') {
        window.print();
        btn.disabled = false;
        btn.innerHTML = textoOriginal;
        return;
    }

    const opt = {
        margin:       [10, 12, 10, 12],
        filename:     'Boleta_<?= $tipoOp ?>_<?= sprintf("%07d", $correlativo) ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, logging: false },
        jsPDF:        { unit: 'mm', format: 'letter', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(elemento).save().then(() => {
        btn.disabled = false;
        btn.innerHTML = textoOriginal;
    }).catch(err => {
        console.error('Error html2pdf boleta:', err);
        window.print();
        btn.disabled = false;
        btn.innerHTML = textoOriginal;
    });
}
</script>

</body>
</html>
