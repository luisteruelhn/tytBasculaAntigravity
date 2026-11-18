<?php
/**
 * Reporte Imprimible / PDF de Guía de Remisión (Solo Ventas Cerradas)
 * Proyecto: tytBasculaAntigravity
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

$usuarioActual = requerirAutenticacion();
$empresaId = (int)$usuarioActual['empresa_id'];
$pdo = obtenerDB();

$idPesada = (int)($_GET['id'] ?? 0);
if ($idPesada <= 0) {
    die('ID de pesada inválido.');
}

// 1. OBTENER DATOS DE LA VENTA CERRADA
$stmt = $pdo->prepare("
    SELECT p.*,
           cp.nombre AS persona_nombre,
           cp.rtn AS persona_rtn,
           pr.nombre AS presentacion_nombre,
           prod.nombre AS producto_nombre
    FROM t_pesadas p
    JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
    JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
    JOIN t_productos prod ON prod.id = pr.producto_id
    WHERE p.idpesada = :id AND p.empresa_id = :empresa_id AND p.tipo_operacion = 'VENTA' AND p.estado = 'CERRADA'
    LIMIT 1
");
$stmt->execute([':id' => $idPesada, ':empresa_id' => $empresaId]);
$pesada = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pesada) {
    die('No se encontró la venta cerrada especificada para emitir Guía de Remisión.');
}

// 2. DATOS DE LA EMPRESA
$stmtEmp = $pdo->prepare("SELECT * FROM t_empresas WHERE id = :id LIMIT 1");
$stmtEmp->execute([':id' => $empresaId]);
$empresa = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: [];

$pesoFinalLb = (float)($pesada['peso_final_lb'] ?? 0);
$quintales   = round($pesoFinalLb / 100.0, 2);
$correlativo = sprintf('%06d', (int)($pesada['id_venta'] ?? $pesada['idpesada']));
$numGuia     = trim((string)($pesada['guia_remision'] ?? '')) ?: $correlativo;

$fechaRaw = !empty($pesada['segunda_pesada_at']) ? $pesada['segunda_pesada_at'] : $pesada['fecha_at'];
$mesesEs = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];
$ts = strtotime((string)$fechaRaw);
$fechaTraslado = date('d', $ts) . ' / ' . ($mesesEs[(int)date('n', $ts)] ?? date('m', $ts)) . ' / ' . date('Y', $ts);

$puntoPartida = trim((string)($pesada['origen'] ?? '')) ?: 'CEIBITA SUR, SB';
$puntoLlegada = trim((string)($pesada['destino'] ?? '')) ?: '—';
$marcaPlaca   = trim((string)($pesada['marca_vehiculo'] ?? '') . ' ' . (string)($pesada['placa'] ?? ''));

$motivoSeleccionado = trim((string)($pesada['motivo_traslado'] ?? '')) ?: 'Venta';
$motivoOtroTexto    = trim((string)($pesada['motivo_traslado_otro'] ?? ''));

/**
 * Convierte un número entero (0 - 999,999) a texto en español.
 */
function enteroALetrasEs(int $n): string
{
    if ($n === 0) return 'Cero';
    if ($n === 100) return 'Cien';

    $unidades = ['', 'Un', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis', 'Siete', 'Ocho', 'Nueve',
        'Diez', 'Once', 'Doce', 'Trece', 'Catorce', 'Quince', 'Dieciséis', 'Diecisiete', 'Dieciocho', 'Diecinueve', 'Veinte'];
    $decenas = ['', '', 'Veinti', 'Treinta', 'Cuarenta', 'Cincuenta', 'Sesenta', 'Setenta', 'Ochenta', 'Noventa'];
    $centenas = ['', 'Ciento', 'Doscientos', 'Trescientos', 'Cuatrocientos', 'Quinientos', 'Seiscientos', 'Setecientos', 'Ochocientos', 'Novecientos'];

    $convertirMenorMil = function (int $num) use ($unidades, $decenas, $centenas): string {
        if ($num === 0) return '';
        if ($num === 100) return 'Cien';
        $res = [];
        if ($num >= 100) {
            $res[] = $centenas[intdiv($num, 100)];
            $num %= 100;
        }
        if ($num <= 20 && $num > 0) {
            $res[] = $unidades[$num];
        } elseif ($num > 20) {
            $d = intdiv($num, 10);
            $u = $num % 10;
            if ($d === 2) {
                $res[] = 'Veinti' . strtolower($unidades[$u]);
            } else {
                $res[] = $decenas[$d] . ($u > 0 ? ' y ' . $unidades[$u] : '');
            }
        }
        return implode(' ', $res);
    };

    $partes = [];
    if ($n >= 1000) {
        $miles = intdiv($n, 1000);
        $partes[] = ($miles === 1 ? 'Mil' : $convertirMenorMil($miles) . ' Mil');
        $n %= 1000;
    }
    if ($n > 0) {
        $partes[] = $convertirMenorMil($n);
    }
    return implode(' ', $partes);
}

$enteroQQ = (int)floor($quintales);
$decQQ    = (int)round(($quintales - $enteroQQ) * 100);
$pesoEnLetras = enteroALetrasEs($enteroQQ) . ' QQ con ' . sprintf('%02d', $decQQ) . '/100';

// 4 columnas exactas de Motivo de Traslado según formato
$columnasMotivos = [
    ['Venta', 'Compra', 'Consignación', 'Venta con entrega a terceros'],
    ['Venta sujeta a confirmación por el comprador', 'Traslado entre establecimientos de la misma Empresa', 'Devolución', 'Otros (especificar)'],
    ['Recojo de bienes', 'Importación', 'Exportación'],
    ['Traslado zona primaria', 'Traslado por emisor itinerante de comprobantes de pago', 'Traslado de bienes para transformación', 'Transformación']
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guía de Remisión N° <?= htmlspecialchars($numGuia, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; }
        body { background: #f1f5f9; color: #0f172a; padding: 1.5rem 1rem; }
        .acciones { max-width: 920px; margin: 0 auto 1rem auto; display: flex; justify-content: space-between; align-items: center; }
        .btn { padding: 0.5rem 1.15rem; border-radius: 6px; font-weight: 700; font-size: 0.9rem; cursor: pointer; text-decoration: none; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; }
        .btn-primary { background: #0b57d0; color: #fff; border-color: #0b57d0; }

        .guia-hoja { max-width: 920px; margin: 0 auto; background: #fff; padding: 1.75rem 2rem; border: 1px solid #cbd5e1; border-radius: 6px; }
        .guia-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1.5rem; margin-bottom: 1rem; }
        .empresa-bloque { display: flex; align-items: center; gap: 1rem; }
        .empresa-logo { max-height: 70px; max-width: 110px; object-fit: contain; }
        .empresa-titulo { font-size: 1.55rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; }
        .empresa-sub { font-size: 0.78rem; color: #334155; line-height: 1.35; margin-top: 0.15rem; }

        .recuadro-guia { border: 2px solid #0f172a; border-radius: 10px; padding: 0.65rem 1.25rem; text-align: center; min-width: 240px; }
        .recuadro-guia-titulo { font-size: 1rem; font-weight: 900; border-bottom: 1px solid #0f172a; padding-bottom: 0.3rem; margin-bottom: 0.45rem; font-style: italic; }
        .recuadro-guia-num { font-size: 1.25rem; font-weight: 900; color: #b91c1c; font-family: monospace; letter-spacing: 1px; }

        .datos-traslado { display: grid; grid-template-columns: 1.15fr 1fr; gap: 0.35rem 1.5rem; font-size: 0.82rem; margin-bottom: 0.85rem; }
        .campo-linea { display: flex; gap: 0.4rem; align-items: baseline; }
        .campo-linea strong { white-space: nowrap; }
        .campo-linea span { flex: 1; border-bottom: 1px solid #475569; padding: 0 0.25rem; font-weight: 600; min-height: 1.1rem; }

        .motivos-box { border: 1.5px solid #0f172a; border-radius: 8px; padding: 0.55rem 0.75rem; margin-bottom: 0.85rem; }
        .motivos-titulo { font-size: 0.78rem; font-weight: 800; margin-bottom: 0.35rem; }
        .motivos-grid { display: grid; grid-template-columns: 1fr 1.25fr 0.9fr 1.35fr; gap: 0.25rem 0.65rem; font-size: 0.72rem; }
        .motivo-item { display: flex; justify-content: space-between; align-items: center; gap: 0.3rem; line-height: 1.15; }
        .check-box { width: 14px; height: 14px; border: 1px solid #0f172a; display: inline-flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 900; flex-shrink: 0; }

        .tabla-bienes { width: 100%; border-collapse: collapse; margin-bottom: 0.75rem; font-size: 0.85rem; }
        .tabla-bienes th, .tabla-bienes td { border: 1px solid #0f172a; padding: 0.45rem 0.5rem; }
        .tabla-bienes th { background: #f1f5f9; font-size: 0.74rem; text-transform: uppercase; text-align: center; }
        .tabla-bienes td { vertical-align: middle; }

        .pie-transporte { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.75rem; font-size: 0.78rem; }
        .caja-borde { border: 1px solid #0f172a; border-radius: 6px; padding: 0.45rem 0.65rem; }
        .caja-titulo { font-weight: 800; font-size: 0.74rem; margin-bottom: 0.3rem; }
        .sub-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
        .sub-campo { border-top: 1px solid #cbd5e1; padding-top: 0.2rem; }
        .sub-campo small { display: block; font-size: 0.65rem; color: #475569; }
        .sub-campo div { font-weight: 700; margin-top: 0.1rem; }

        .peso-letras { font-size: 0.85rem; font-weight: 700; margin-bottom: 2.2rem; border-bottom: 1px solid #475569; padding-bottom: 0.2rem; }
        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; text-align: center; font-size: 0.82rem; font-weight: 700; }
        .firma-linea { border-top: 1px solid #0f172a; padding-top: 0.3rem; }

        @media print {
            body { background: #fff; padding: 0; }
            .acciones { display: none !important; }
            .guia-hoja { border: none; padding: 0; max-width: 100%; }
            @page { size: letter portrait; margin: 0.8cm; }
        }
    </style>
</head>
<body>

<div class="acciones">
    <a href="boleta.php?id=<?= $idPesada ?>" class="btn">&larr; Volver a Boleta</a>
    <div style="display: flex; gap: 0.75rem;">
        <button type="button" onclick="descargarGuiaPDF()" id="btnGuiaPDF" class="btn">📄 Descargar PDF</button>
        <button type="button" onclick="window.print()" class="btn btn-primary">🖨️ Imprimir Guía</button>
    </div>
</div>

<div class="guia-hoja" id="guiaImprimible">
    <div class="guia-header">
        <div class="empresa-bloque">
            <?php if (!empty($empresa['logo']) && file_exists(__DIR__ . '/' . $empresa['logo'])): ?>
                <img src="<?= htmlspecialchars($empresa['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="empresa-logo">
            <?php endif; ?>
            <div>
                <div class="empresa-titulo"><?= htmlspecialchars($empresa['nombre_comercial'] ?: ($empresa['razon_social'] ?? 'INVERSIONES VALLECILLO'), ENT_QUOTES, 'UTF-8') ?></div>
                <div class="empresa-sub">
                    <?php if (!empty($empresa['rtn'])): ?><strong>R.T.N.:</strong> <?= htmlspecialchars($empresa['rtn'], ENT_QUOTES, 'UTF-8') ?> &middot; <?php endif; ?>
                    <?php if (!empty($empresa['telefono'])): ?><strong>Tel:</strong> <?= htmlspecialchars($empresa['telefono'], ENT_QUOTES, 'UTF-8') ?><br><?php endif; ?>
                    <?php if (!empty($empresa['direccion'])): ?><?= htmlspecialchars($empresa['direccion'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="recuadro-guia">
            <div class="recuadro-guia-titulo">GUIA DE REMISION</div>
            <div class="recuadro-guia-num">N° <?= htmlspecialchars($numGuia, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>

    <div class="datos-traslado">
        <div class="campo-linea"><strong>Fecha de Inicio de Traslado:</strong><span><?= htmlspecialchars($fechaTraslado, ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="campo-linea"><strong>Punto de partida:</strong><span><?= htmlspecialchars($puntoPartida, ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="campo-linea"><strong>Destinatario:</strong><span><?= htmlspecialchars($pesada['persona_nombre'], ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="campo-linea"><strong>Punto de llegada:</strong><span><?= htmlspecialchars($puntoLlegada, ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="campo-linea"><strong>R.T.N.:</strong><span><?= htmlspecialchars((string)($pesada['persona_rtn'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span></div>
        <div class="campo-linea"><strong>Venta Ref.:</strong><span>V. <?= htmlspecialchars($correlativo, ENT_QUOTES, 'UTF-8') ?></span></div>
    </div>

    <div class="motivos-box">
        <div class="motivos-titulo">Motivo de Traslado:</div>
        <div class="motivos-grid">
            <?php foreach ($columnasMotivos as $col): ?>
                <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                    <?php foreach ($col as $item):
                        $marcado = ($motivoSeleccionado === $item);
                        $textoItem = $item;
                        if ($item === 'Otros (especificar)' && $marcado && $motivoOtroTexto !== '') {
                            $textoItem .= ': ' . $motivoOtroTexto;
                        }
                    ?>
                        <div class="motivo-item">
                            <span><?= htmlspecialchars($textoItem, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="check-box"><?= $marcado ? '✓' : '' ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <table class="tabla-bienes">
        <thead>
            <tr>
                <th style="width: 6%;">N°</th>
                <th style="width: 54%;">DESCRIPCIÓN</th>
                <th style="width: 14%;">UNIDAD DE MEDIDA</th>
                <th style="width: 12%;">CANTIDAD</th>
                <th style="width: 14%;">PESO TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td align="center"><strong>1</strong></td>
                <td>
                    <strong><?= htmlspecialchars($pesada['producto_nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                    (<?= htmlspecialchars($pesada['presentacion_nombre'], ENT_QUOTES, 'UTF-8') ?>)
                    <br><small style="color: #475569;">V. <?= htmlspecialchars($correlativo, ENT_QUOTES, 'UTF-8') ?></small>
                </td>
                <td align="center"><strong>QQ</strong></td>
                <td align="center"><strong><?= (int)$pesada['cantidad_empaques'] ?></strong></td>
                <td align="right"><strong><?= number_format($quintales, 2) ?></strong></td>
            </tr>
            <tr>
                <td colspan="4" align="right" style="font-weight: 800;">TOTAL QQ:</td>
                <td align="right" style="font-weight: 900; font-size: 0.95rem;"><?= number_format($quintales, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="pie-transporte">
        <div class="caja-borde">
            <div class="caja-titulo">Datos del Transportista:</div>
            <div class="sub-grid">
                <div class="sub-campo"><small>R.T.N.</small><div><?= htmlspecialchars((string)($pesada['transportista_rtn'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="sub-campo"><small>Denominación Apellidos y Nombres</small><div><?= htmlspecialchars((string)($pesada['motorista'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
            </div>
        </div>
        <div class="caja-borde">
            <div class="caja-titulo">Datos de la Unidad de Transporte y Conductor:</div>
            <div class="sub-grid">
                <div class="sub-campo"><small>Marca y Placa</small><div><?= htmlspecialchars($marcaPlaca ?: '—', ENT_QUOTES, 'UTF-8') ?></div></div>
                <div class="sub-campo"><small>Licencia de conducir</small><div><?= htmlspecialchars((string)($pesada['licencia'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div></div>
            </div>
        </div>
    </div>

    <div class="peso-letras">Son: <?= htmlspecialchars($pesoEnLetras, ENT_QUOTES, 'UTF-8') ?></div>

    <div class="firmas">
        <div class="firma-linea">Firma</div>
        <div class="firma-linea">Recibí Conforme</div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function descargarGuiaPDF() {
    const btn = document.getElementById('btnGuiaPDF');
    const txt = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Generando...';
    const el = document.getElementById('guiaImprimible');
    if (typeof html2pdf !== 'function') {
        window.print();
        btn.disabled = false;
        btn.innerHTML = txt;
        return;
    }
    html2pdf().set({
        margin: [6, 6, 6, 6],
        filename: 'Guia_Remision_<?= htmlspecialchars($numGuia, ENT_QUOTES, 'UTF-8') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, logging: false },
        jsPDF: { unit: 'mm', format: 'letter', orientation: 'portrait' }
    }).from(el).save().finally(() => {
        btn.disabled = false;
        btn.innerHTML = txt;
    });
}
</script>
</body>
</html>
