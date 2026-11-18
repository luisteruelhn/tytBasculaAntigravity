<?php
/**
 * Módulo de Primera Pesada (Captura de Entrada / Bruto o Tara)
 * Proyecto: tytBasculaAntigravity
 * 
 * Directo, funcional, ligero y conectado a MariaDB (t_pesadas).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
if (tieneRol($usuarioActual, ['REMOTO'])) {
    http_response_code(403);
    die('Acceso de solo consulta: el usuario REMOTO no puede registrar pesajes.');
}
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombre = $usuarioActual['empresa_nombre'] ?? 'BÁSCULA INDUSTRIAL';
$pdo = obtenerDB();

$mensajeExito = '';
$error = '';
$pesadaGuardada = null;

// PROCESAR GUARDADO DE PRIMERA PESADA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'guardar_primera') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $tipoRegistro   = trim((string)($_POST['tipo_registro'] ?? 'BRUTO')); // BRUTO o TARA
        $modoCaptura    = trim((string)($_POST['modo_captura'] ?? 'INDICADOR')); // INDICADOR o MANUAL
        $pesoFijado     = (float)($_POST['peso_fijado'] ?? 0);
        
        $tipoOperacion  = trim((string)($_POST['tipo_operacion'] ?? 'COMPRA'));
        $clieProvId     = (int)($_POST['clie_prov_id'] ?? 0);
        $productoId     = (int)($_POST['producto_id'] ?? 0);
        $presentacionId = (int)($_POST['presentacion_id'] ?? 0);
        $responsableId  = (int)($_POST['responsable_id'] ?? $usuarioActual['id']);

        $placa          = mb_strtoupper(trim((string)($_POST['placa'] ?? '')), 'UTF-8');
        $motorista      = trim((string)($_POST['motorista'] ?? ''));
        $licencia       = trim((string)($_POST['licencia'] ?? ''));
        $origen         = trim((string)($_POST['origen'] ?? ''));
        $destino        = trim((string)($_POST['destino'] ?? ''));
        $observaciones  = trim((string)($_POST['observaciones'] ?? ''));

        // Parámetros de Pesada Única (cierre directo en un solo paso)
        $esPesadaUnica  = isset($_POST['pesada_unica']) && $_POST['pesada_unica'] === '1';
        $pesoCompUnica  = (float)($_POST['peso_comp_unica'] ?? 0);
        $idEmpaque      = (int)($_POST['idempaque'] ?? 0);
        $cantEmpaques   = max(0, (int)($_POST['cantidad_empaques'] ?? 0));
        $pctHumedad     = max(0.0, (float)($_POST['ajuste_humedad_pct'] ?? 0));
        $pctDefectos    = max(0.0, (float)($_POST['descuento_calidad_pct'] ?? 0));
        $guiaRemision   = trim((string)($_POST['guia_remision'] ?? ''));
        $motivoTraslado = trim((string)($_POST['motivo_traslado'] ?? ''));
        $motivoOtro     = trim((string)($_POST['motivo_traslado_otro'] ?? ''));
        $transpRtn      = trim((string)($_POST['transportista_rtn'] ?? ''));
        $marcaVehiculo  = trim((string)($_POST['marca_vehiculo'] ?? ''));

        // Validaciones mínimas
        if ($pesoFijado <= 0) {
            $error = 'Debes fijar un peso mayor a cero antes de guardar.';
        } elseif ($clieProvId <= 0) {
            $error = 'Selecciona una persona o empresa (cliente/proveedor).';
        } elseif ($productoId <= 0 || $presentacionId <= 0) {
            $error = 'Selecciona el producto y la presentación.';
        } elseif ($placa === '') {
            $error = 'La placa del vehículo es obligatoria.';
        } elseif ($esPesadaUnica && $pesoCompUnica < 0) {
            $error = 'El peso complementario en pesada única no puede ser negativo.';
        } else {
            try {
                $pdo->beginTransaction();

                // Obtener correlativo de compra o venta para la empresa
                $colCorrelativo = ($tipoOperacion === 'COMPRA') ? 'id_compra' : 'id_venta';
                $stmtCorr = $pdo->prepare("SELECT COALESCE(MAX($colCorrelativo), 0) + 1 AS sig FROM t_pesadas WHERE empresa_id = :empresa_id");
                $stmtCorr->execute([':empresa_id' => $empresaId]);
                $correlativo = (int)$stmtCorr->fetch(PDO::FETCH_ASSOC)['sig'];

                $capturadoPor = (int)$usuarioActual['id'];
                $modoCapturaVal = ($modoCaptura === 'MANUAL' ? 'MANUAL' : 'INDICADOR');

                if ($esPesadaUnica) {
                    // Cierre en un solo paso
                    if ($tipoRegistro === 'BRUTO') {
                        $pesoBruto = $pesoFijado;
                        $taraVehiculo = $pesoCompUnica;
                        $origenBruto = $modoCapturaVal;
                        $origenTara = ($pesoCompUnica > 0 ? 'MANUAL' : $modoCapturaVal);
                    } else {
                        $taraVehiculo = $pesoFijado;
                        $pesoBruto = $pesoCompUnica;
                        $origenTara = $modoCapturaVal;
                        $origenBruto = 'MANUAL';
                    }

                    if ($pesoBruto <= $taraVehiculo) {
                        throw new RuntimeException('En Pesada Única el peso bruto (' . number_format($pesoBruto, 0) . ' lb) debe ser mayor a la tara (' . number_format($taraVehiculo, 0) . ' lb).');
                    }

                    $pesoNetoSinDesc = $pesoBruto - $taraVehiculo;
                    $taraUnitaria = 0.0;
                    $taraTotalEmp = 0.0;
                    if ($idEmpaque > 0 && $cantEmpaques > 0) {
                        $stmtTara = $pdo->prepare("SELECT taralbs FROM t_taras WHERE idempaque = :id LIMIT 1");
                        $stmtTara->execute([':id' => $idEmpaque]);
                        $rowTara = $stmtTara->fetch(PDO::FETCH_ASSOC);
                        if ($rowTara) {
                            $taraUnitaria = (float)$rowTara['taralbs'];
                            $taraTotalEmp = round($taraUnitaria * $cantEmpaques);
                        }
                    }

                    $baseDescuentos = max(0.0, $pesoNetoSinDesc - $taraTotalEmp);
                    $descHumedadLb  = round($baseDescuentos * ($pctHumedad / 100.0));
                    $descDefectosLb = round($baseDescuentos * ($pctDefectos / 100.0));
                    $pesoFinalLb    = max(0.0, $baseDescuentos - $descHumedadLb - $descDefectosLb);

                    $sql = "INSERT INTO t_pesadas (
                                empresa_id, tipo_operacion, $colCorrelativo, estado,
                                clie_prov_id, presentacion_id, placa, motorista, licencia,
                                origen, destino, observaciones, abierto_por, responsable_id,
                                tipo_primera_pesada, primera_pesada_at, segunda_pesada_at, entrada_camion_at, salida_camion_at,
                                emitida_at, emitida_por,
                                peso_bruto_lb, bruto_capturado_por, origen_bruto,
                                tara_vehiculo_lb, tara_capturada_por, origen_tara,
                                idempaque, cantidad_empaques, tara_unitaria_aplicada_lb,
                                base_descuentos_lb, ajuste_humedad_pct, descuento_humedad_lb,
                                descuento_calidad_pct, descuento_defectos_lb, peso_final_lb, calculo_libras_enteras,
                                guia_remision, motivo_traslado, motivo_traslado_otro, transportista_rtn, marca_vehiculo
                            ) VALUES (
                                :empresa_id, :tipo_operacion, :correlativo, 'CERRADA',
                                :clie_prov_id, :presentacion_id, :placa, :motorista, :licencia,
                                :origen, :destino, :observaciones, :abierto_por, :responsable_id,
                                :tipo_primera, NOW(), NOW(), NOW(), NOW(),
                                NOW(), :emitida_por,
                                :peso_bruto, :bruto_por, :origen_bruto,
                                :tara_vehiculo, :tara_por, :origen_tara,
                                :idempaque, :cant_empaques, :tara_unitaria,
                                :base_descuentos, :pct_humedad, :desc_humedad_lb,
                                :pct_calidad, :desc_defectos_lb, :peso_final_lb, 1,
                                :guia_remision, :motivo_traslado, :motivo_traslado_otro, :transportista_rtn, :marca_vehiculo
                            )";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':empresa_id'           => $empresaId,
                        ':tipo_operacion'       => $tipoOperacion,
                        ':correlativo'          => $correlativo,
                        ':clie_prov_id'         => $clieProvId,
                        ':presentacion_id'      => $presentacionId,
                        ':placa'                => $placa,
                        ':motorista'            => ($motorista !== '' ? $motorista : null),
                        ':licencia'             => ($licencia !== '' ? $licencia : null),
                        ':origen'               => ($origen !== '' ? $origen : null),
                        ':destino'              => ($destino !== '' ? $destino : null),
                        ':observaciones'        => ($observaciones !== '' ? $observaciones : null),
                        ':abierto_por'          => $capturadoPor,
                        ':responsable_id'       => $responsableId,
                        ':tipo_primera'         => $tipoRegistro,
                        ':emitida_por'          => $capturadoPor,
                        ':peso_bruto'           => $pesoBruto,
                        ':bruto_por'            => $capturadoPor,
                        ':origen_bruto'         => $origenBruto,
                        ':tara_vehiculo'        => $taraVehiculo,
                        ':tara_por'             => $capturadoPor,
                        ':origen_tara'          => $origenTara,
                        ':idempaque'            => ($idEmpaque > 0 ? $idEmpaque : null),
                        ':cant_empaques'        => $cantEmpaques,
                        ':tara_unitaria'        => ($taraUnitaria > 0 ? $taraUnitaria : null),
                        ':base_descuentos'      => $baseDescuentos,
                        ':pct_humedad'          => $pctHumedad,
                        ':desc_humedad_lb'      => $descHumedadLb,
                        ':pct_calidad'          => $pctDefectos,
                        ':desc_defectos_lb'     => $descDefectosLb,
                        ':peso_final_lb'        => $pesoFinalLb,
                        ':guia_remision'        => ($tipoOperacion === 'VENTA' && $guiaRemision !== '' ? $guiaRemision : null),
                        ':motivo_traslado'      => ($tipoOperacion === 'VENTA' && $motivoTraslado !== '' ? $motivoTraslado : null),
                        ':motivo_traslado_otro' => ($tipoOperacion === 'VENTA' && $motivoOtro !== '' ? $motivoOtro : null),
                        ':transportista_rtn'    => ($tipoOperacion === 'VENTA' && $transpRtn !== '' ? $transpRtn : null),
                        ':marca_vehiculo'       => ($tipoOperacion === 'VENTA' && $marcaVehiculo !== '' ? $marcaVehiculo : null),
                    ]);
                } else {
                    // Primera pesada estándar (ABIERTA en patio)
                    $pesoBruto = ($tipoRegistro === 'BRUTO') ? $pesoFijado : null;
                    $taraVehiculo = ($tipoRegistro === 'TARA') ? $pesoFijado : null;
                    $origenBruto = ($tipoRegistro === 'BRUTO') ? $modoCapturaVal : null;
                    $origenTara = ($tipoRegistro === 'TARA') ? $modoCapturaVal : null;

                    $sql = "INSERT INTO t_pesadas (
                                empresa_id, tipo_operacion, $colCorrelativo, estado,
                                clie_prov_id, presentacion_id, placa, motorista, licencia,
                                origen, destino, observaciones, abierto_por, responsable_id,
                                tipo_primera_pesada, primera_pesada_at, entrada_camion_at,
                                peso_bruto_lb, bruto_capturado_por, origen_bruto,
                                tara_vehiculo_lb, tara_capturada_por, origen_tara
                            ) VALUES (
                                :empresa_id, :tipo_operacion, :correlativo, 'ABIERTA',
                                :clie_prov_id, :presentacion_id, :placa, :motorista, :licencia,
                                :origen, :destino, :observaciones, :abierto_por, :responsable_id,
                                :tipo_primera, NOW(), NOW(),
                                :peso_bruto, :bruto_por, :origen_bruto,
                                :tara_vehiculo, :tara_por, :origen_tara
                            )";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':empresa_id'     => $empresaId,
                        ':tipo_operacion' => $tipoOperacion,
                        ':correlativo'    => $correlativo,
                        ':clie_prov_id'   => $clieProvId,
                        ':presentacion_id'=> $presentacionId,
                        ':placa'          => $placa,
                        ':motorista'      => ($motorista !== '' ? $motorista : null),
                        ':licencia'       => ($licencia !== '' ? $licencia : null),
                        ':origen'         => ($origen !== '' ? $origen : null),
                        ':destino'        => ($destino !== '' ? $destino : null),
                        ':observaciones'  => ($observaciones !== '' ? $observaciones : null),
                        ':abierto_por'    => $capturadoPor,
                        ':responsable_id' => $responsableId,
                        ':tipo_primera'   => $tipoRegistro,
                        ':peso_bruto'     => $pesoBruto,
                        ':bruto_por'      => ($pesoBruto !== null ? $capturadoPor : null),
                        ':origen_bruto'   => $origenBruto,
                        ':tara_vehiculo'  => $taraVehiculo,
                        ':tara_por'       => ($taraVehiculo !== null ? $capturadoPor : null),
                        ':origen_tara'    => $origenTara
                    ]);
                }

                $idPesada = (int)$pdo->lastInsertId();
                $pdo->commit();

                // Cargar datos para la vista de confirmación post-guardado (fiel a Imagen 2)
                $stmtInfo = $pdo->prepare("
                    SELECT p.*, cp.nombre AS persona_nombre, pr.nombre AS presentacion_nombre, 
                           prod.nombre AS producto_nombre, u.nombre AS responsable_nombre
                    FROM t_pesadas p
                    JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
                    JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
                    JOIN t_productos prod ON prod.id = pr.producto_id
                    JOIN t_usuarios u ON u.id = p.responsable_id
                    WHERE p.idpesada = :id
                ");
                $stmtInfo->execute([':id' => $idPesada]);
                $pesadaGuardada = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error al registrar la primera pesada: ' . $e->getMessage();
            }
        }
    }
}

// CARGA DE LISTAS PARA LOS SELECTS
$personas = $pdo->prepare("SELECT id, nombre, es_cliente, es_proveedor FROM t_clie_prov WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY nombre ASC");
$personas->execute([':empresa_id' => $empresaId]);
$listaPersonas = $personas->fetchAll(PDO::FETCH_ASSOC);

$productos = $pdo->prepare("SELECT id, nombre FROM t_productos WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY nombre ASC");
$productos->execute([':empresa_id' => $empresaId]);
$listaProductos = $productos->fetchAll(PDO::FETCH_ASSOC);

// Cargar todas las presentaciones por producto para el selector dependiente
$pres = $pdo->prepare("
    SELECT pr.id, pr.producto_id, pr.nombre 
    FROM t_producto_presentaciones pr 
    JOIN t_productos p ON p.id = pr.producto_id 
    WHERE p.empresa_id = :empresa_id AND pr.activo = 1 
    ORDER BY pr.nombre ASC
");
$pres->execute([':empresa_id' => $empresaId]);
$listaPresentaciones = $pres->fetchAll(PDO::FETCH_ASSOC);

$usuarios = $pdo->prepare("SELECT id, nombre, rol FROM t_usuarios WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY nombre ASC");
$usuarios->execute([':empresa_id' => $empresaId]);
$listaUsuarios = $usuarios->fetchAll(PDO::FETCH_ASSOC);

$listaEmpaques = $pdo->query("SELECT idempaque, nombre, taralbs FROM t_taras ORDER BY taralbs ASC")->fetchAll(PDO::FETCH_ASSOC);

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Primera Pesada', 'primera');
?>

<div style="max-width: 1040px; margin: 0 auto;">

    <!-- ENCABEZADO CON SELECTOR PRINCIPAL DE COMPRA / VENTA -->
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
                PESAJE
            </span>
            <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.2rem 0 0.2rem 0; color: var(--text-main); line-height: 1.1;">
                Primera pesada
            </h1>
            <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <!-- SWITCH SEGMENTADO DE TIPO DE TRANSACCIÓN (COMPRA / VENTA EN 1 CLIC) -->
        <div>
            <div style="display: inline-flex; background: #e2e8f0; padding: 4px; border-radius: 10px; gap: 4px;">
                <button type="button" id="btnTxCompra" onclick="cambiarTransaccionPrincipal('COMPRA')" style="padding: 0.6rem 1.4rem; border-radius: 8px; font-weight: 800; font-size: 0.95rem; border: none; cursor: pointer; background: #1e40af; color: #ffffff; transition: all 0.15s ease;">
                    📥 COMPRA
                </button>
                <button type="button" id="btnTxVenta" onclick="cambiarTransaccionPrincipal('VENTA')" style="padding: 0.6rem 1.4rem; border-radius: 8px; font-weight: 800; font-size: 0.95rem; border: none; cursor: pointer; background: transparent; color: #475569; transition: all 0.15s ease;">
                    📤 VENTA
                </button>
            </div>
            <div style="font-size: 0.72rem; color: var(--text-muted); text-align: right; margin-top: 0.25rem; font-weight: 600;">
                1 clic para cambiar entre Compra (Bruto) y Venta (Tara)
            </div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" style="margin-bottom: 1.5rem;">
            <span>⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <!-- =======================================================================
         PANTALLA 2: CONFIRMACIÓN POST-GUARDADO (FIEL A IMAGEN 2)
         ======================================================================= -->
    <?php if ($pesadaGuardada): 
        $fueCerrada = (($pesadaGuardada['estado'] ?? '') === 'CERRADA');
    ?>
        <div class="card" style="padding: 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            
            <?php if ($fueCerrada): ?>
                <div style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1.75rem; font-weight: 700; font-size: 0.95rem;">
                    ✅ Pesada única guardada y boleta cerrada en un solo paso.
                </div>
            <?php else: ?>
                <div style="background: #e8f0fe; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1.75rem; font-weight: 500; font-size: 0.95rem;">
                    Primera pesada guardada.
                </div>
            <?php endif; ?>

            <div style="margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.6rem; font-weight: 800; margin: 0 0 0.5rem 0; color: var(--text-main);">
                    <?= ($pesadaGuardada['tipo_operacion'] === 'COMPRA' ? 'Compra #' . $pesadaGuardada['id_compra'] : 'Venta #' . $pesadaGuardada['id_venta']) ?>
                </h2>
                <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.85rem; letter-spacing: 0.3px;">
                    <?= htmlspecialchars($pesadaGuardada['persona_nombre'], ENT_QUOTES, 'UTF-8') ?> &middot; 
                    <?= htmlspecialchars($pesadaGuardada['producto_nombre'], ENT_QUOTES, 'UTF-8') ?> &middot; 
                    <?= htmlspecialchars($pesadaGuardada['presentacion_nombre'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if ($fueCerrada): ?>
                    <div style="font-size: 1.05rem; color: var(--text-main); margin-bottom: 0.4rem;">
                        Peso final neto: <strong style="font-weight: 800;"><?= number_format((float)$pesadaGuardada['peso_final_lb'], 0) ?> lb</strong>
                        (<strong><?= number_format((float)$pesadaGuardada['peso_final_lb'] / 100.0, 2) ?> QQ</strong>)
                    </div>
                <?php else: ?>
                    <div style="font-size: 1.05rem; color: var(--text-main); margin-bottom: 0.4rem;">
                        <?= ($pesadaGuardada['tipo_primera_pesada'] === 'BRUTO' ? 'Peso bruto' : 'Tara de vehículo') ?>: 
                        <strong style="font-weight: 800;"><?= number_format((float)($pesadaGuardada['peso_bruto_lb'] ?? $pesadaGuardada['tara_vehiculo_lb']), 0) ?> lb</strong>
                    </div>
                <?php endif; ?>
                <div style="font-size: 0.95rem; color: var(--text-muted);">
                    Placa <?= htmlspecialchars($pesadaGuardada['placa'], ENT_QUOTES, 'UTF-8') ?> &middot; Responsable <?= htmlspecialchars($pesadaGuardada['responsable_nombre'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>

            <?php if (!$fueCerrada): ?>
                <div style="background: #f1f5f9; color: #475569; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 2rem; font-size: 0.92rem;">
                    Nota pendiente de segunda pesada.
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap;">
                <?php if ($fueCerrada): ?>
                    <a href="boleta.php?id=<?= (int)$pesadaGuardada['idpesada'] ?>" target="_blank" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-weight: 700; background: #0b57d0; border-radius: 6px; text-decoration: none;">
                        🖨️ Ver / Imprimir Boleta
                    </a>
                    <?php if ($pesadaGuardada['tipo_operacion'] === 'VENTA'): ?>
                        <a href="guia_remision.php?id=<?= (int)$pesadaGuardada['idpesada'] ?>" target="_blank" class="btn btn-secondary" style="padding: 0.75rem 1.5rem; font-weight: 700; color: #047857; border: 1px solid #047857; background: #ecfdf5; border-radius: 6px; text-decoration: none;">
                            🚚 Guía de Remisión
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="segunda_pesada.php?id=<?= $pesadaGuardada['idpesada'] ?>" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-weight: 700; background: #0b57d0; border-radius: 6px;">
                        Completar segunda pesada
                    </a>
                <?php endif; ?>
                <a href="primera_pesada.php" class="btn btn-secondary" style="padding: 0.75rem 1.25rem; font-weight: 600; text-decoration: none;">
                    Registrar otra pesada
                </a>
                <a href="index.php" style="color: #1e40af; font-size: 0.95rem; font-weight: 600; text-decoration: none; margin-left: 0.5rem;">
                    Volver al inicio
                </a>
            </div>
        </div>

    <?php else: ?>

    <!-- =======================================================================
         PANTALLA 1: FORMULARIO DE PRIMERA PESADA (FIEL A IMAGEN 1)
         ======================================================================= -->
    <form method="POST" action="primera_pesada.php" id="formPrimeraPesada" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="accion" value="guardar_primera">
        <input type="hidden" name="peso_fijado" id="inputPesoFijado" value="0">

        <!-- BLOQUE SUPERIOR: CAPTURA DEL PESO + INDICADOR LED -->
        <div style="display: grid; grid-template-columns: 1.1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; align-items: stretch;">
            
            <!-- PANEL IZQUIERDO: SELECCIÓN Y FIJAR PESO -->
            <div class="card" style="padding: 1.75rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <h3 style="font-size: 1.1rem; font-weight: 800; margin: 0 0 1.25rem 0; color: var(--text-main);">
                        Captura del peso
                    </h3>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                                <label for="tipo_registro" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin: 0;">
                                    Peso a registrar
                                </label>
                                <label style="display: inline-flex; align-items: center; cursor: pointer; margin: 0;" title="Habilitar cambio manual entre Peso Bruto y Tara">
                                    <input type="checkbox" id="chk_habilitar_tipo_reg" onchange="alternarEdicionTipoRegistro(this.checked)" style="cursor: pointer; width: 16px; height: 16px;">
                                </label>
                            </div>
                            <select id="tipo_registro" name="tipo_registro" class="form-control" style="height: 42px; border-radius: 6px; background: #f8fafc; color: #475569;" disabled>
                                <option value="BRUTO" selected>Peso Bruto</option>
                                <option value="TARA">Tara de Vehículo</option>
                            </select>
                            <!-- Input oculto para garantizar el envío POST cuando el select esté deshabilitado -->
                            <input type="hidden" id="tipo_registro_hidden" name="tipo_registro" value="BRUTO">
                        </div>

                        <div>
                            <label for="modo_captura" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                                Modo de captura
                            </label>
                            <select id="modo_captura" name="modo_captura" class="form-control" style="height: 42px; border-radius: 6px;" onchange="alternarModoCaptura(this.value)">
                                <option value="INDICADOR" selected>Indicador</option>
                                <option value="MANUAL">Manual</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 1.5rem;">
                        <button type="button" id="btnFijarPeso" class="btn btn-primary" onclick="fijarPeso()" style="background: #2563eb; padding: 0.55rem 1.25rem; font-weight: 600; border-radius: 6px;" disabled>
                            Fijar peso
                        </button>
                        <span id="txtGuiaFijar" style="font-size: 0.82rem; color: var(--text-muted);">
                            Selecciona bruto o tara antes de fijar el peso.
                        </span>
                    </div>

                    <div id="campoPesoManual" style="display: none; margin-bottom: 1.25rem;">
                        <label for="input_manual" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted);">
                            Ingresar peso manual (lb)
                        </label>
                        <input type="number" id="input_manual" class="form-control" style="height: 42px; border-radius: 6px;" placeholder="0" min="1" step="1">
                    </div>

                    <!-- TOGGLE PESADA ÚNICA (CIERRE EN UN SOLO PASO) -->
                    <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem;">
                        <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer; margin: 0; font-weight: 700; font-size: 0.88rem; color: #0f172a;">
                            <input type="checkbox" id="chkPesadaUnica" name="pesada_unica" value="1" onchange="alternarPesadaUnica(this.checked, true)" style="width: 18px; height: 18px; cursor: pointer;">
                            <span>⚡ Pesada Única (cerrar boleta en un solo paso)</span>
                        </label>
                        <div id="avisoSugerenciaAceite" style="display: none; margin-top: 0.35rem; font-size: 0.78rem; color: #047857; font-weight: 600;">
                            💡 Sugerido automáticamente para ACEITE (puedes desmarcarlo si irá a segunda pesada).
                        </div>
                    </div>
                </div>

                <!-- DISPLAY ROJO BURDEOS: PESO FIJADO -->
                <div style="background: #701a2b; color: #ffffff; border-radius: 8px; padding: 1.25rem 1.5rem;">
                    <div style="font-size: 0.72rem; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; opacity: 0.9; margin-bottom: 0.25rem;">
                        PESO FIJADO
                    </div>
                    <div style="font-size: 2.2rem; font-weight: 900; line-height: 1; display: flex; align-items: baseline; gap: 0.4rem;">
                        <span id="valorPesoFijado">--</span>
                        <span style="font-size: 1.1rem; font-weight: 600; opacity: 0.85;">lb</span>
                    </div>
                </div>
            </div>

            <!-- PANEL DERECHO: INDICADOR EN VIVO (CARD AZUL MARINO INDUSTRIAL) -->
            <div style="background: #0f233a; color: #ffffff; border-radius: 12px; padding: 1.75rem 2rem; display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-md);">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem;">
                        <div>
                            <span style="font-size: 0.72rem; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: #94a3b8;">
                                INDICADOR DE PESO
                            </span>
                            <h2 style="font-size: 1.6rem; font-weight: 800; margin: 0.25rem 0 0 0; color: #ffffff;">
                                Lectura en vivo
                            </h2>
                        </div>
                        <span id="liveEstableBadge" style="background: #1e3a5f; color: #60a5fa; font-size: 0.75rem; font-weight: 700; padding: 0.35rem 0.85rem; border-radius: 999px; border: 1px solid #2563eb;">
                            Estable
                        </span>
                    </div>

                    <!-- DISPLAY CENTRAL DEL PESO -->
                    <div style="text-align: center; padding: 2rem 0;">
                        <div style="font-size: 4rem; font-weight: 900; line-height: 1; letter-spacing: -1px; display: inline-flex; align-items: baseline; gap: 0.5rem;">
                            <span id="livePesoValor">0</span>
                            <span style="font-size: 1.6rem; font-weight: 700; color: #93c5fd;">lb</span>
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 1rem; font-size: 0.8rem; color: #94a3b8;">
                    <div>
                        <span id="liveModoTexto">Modo simulado</span>
                        <div style="font-size: 0.72rem; color: #64748b;" id="liveUltimaLectura">Última lectura: --:--:--</div>
                    </div>
                    <div style="text-align: right;">
                        <span style="color: #cbd5e1;">Fuente &middot; Configurada</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- BLOQUE OPERACIÓN (GRID SIMÉTRICO 2x2) -->
        <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.5rem;">
            <input type="hidden" id="tipo_operacion" name="tipo_operacion" value="COMPRA">
            <div style="display: flex; align-items: center; gap: 0.65rem; margin-bottom: 1.25rem;">
                <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0; color: var(--text-main);">
                    Operación
                </h3>
                <span id="badgeOpOperacion" style="font-size: 0.78rem; font-weight: 800; background: #dbeafe; color: #1e40af; padding: 0.2rem 0.7rem; border-radius: 999px;">
                    COMPRA
                </span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.25rem;">
                <div>
                    <label for="clie_prov_id" id="lblPersona" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Proveedor
                    </label>
                    <select id="clie_prov_id" name="clie_prov_id" class="form-control" style="height: 42px; border-radius: 6px;" required>
                        <option value="" disabled selected>Selecciona una persona o empresa</option>
                        <?php foreach ($listaPersonas as $p): ?>
                            <option value="<?= $p['id'] ?>" data-cliente="<?= $p['es_cliente'] ?>" data-proveedor="<?= $p['es_proveedor'] ?>">
                                <?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="producto_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Producto
                    </label>
                    <select id="producto_id" name="producto_id" class="form-control" style="height: 42px; border-radius: 6px;" onchange="filtrarPresentaciones(this.value)" required>
                        <option value="" disabled selected>Selecciona un producto</option>
                        <?php foreach ($listaProductos as $prod): ?>
                            <option value="<?= $prod['id'] ?>">
                                <?= htmlspecialchars($prod['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div>
                    <label for="presentacion_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Presentación
                    </label>
                    <select id="presentacion_id" name="presentacion_id" class="form-control" style="height: 42px; border-radius: 6px;" required>
                        <option value="" disabled selected>Selecciona una presentación</option>
                    </select>
                </div>

                <div>
                    <label for="responsable_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Usuario responsable
                    </label>
                    <select id="responsable_id" name="responsable_id" class="form-control" style="height: 42px; border-radius: 6px;" onchange="actualizarNotaResponsable(this)">
                        <?php foreach ($listaUsuarios as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $u['id'] === $usuarioActual['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small id="txtResponsableNota" style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.35rem;">
                        La captura queda registrada por <?= htmlspecialchars($usuarioActual['nombre'], ENT_QUOTES, 'UTF-8') ?>.
                    </small>
                </div>
            </div>
        </div>

        <!-- BLOQUE VEHÍCULO Y TRASLADO -->
        <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 2rem;">
            <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0 0 1.25rem 0; color: var(--text-main);">
                Vehículo y traslado
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.25rem;">
                <div>
                    <label for="placa" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Placa <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" id="placa" name="placa" class="form-control" style="height: 42px; border-radius: 6px; text-transform: uppercase;" required>
                </div>
                <div>
                    <label for="motorista" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Motorista
                    </label>
                    <input type="text" id="motorista" name="motorista" class="form-control" style="height: 42px; border-radius: 6px;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.25rem;">
                <div>
                    <label for="licencia" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Licencia
                    </label>
                    <input type="text" id="licencia" name="licencia" class="form-control" style="height: 42px; border-radius: 6px;">
                </div>
                <div>
                    <label for="origen" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Origen / Punto de partida
                    </label>
                    <input type="text" id="origen" name="origen" list="listaOrigenSugerido" class="form-control" style="height: 42px; border-radius: 6px;" placeholder="CEIBITA SUR, SANTA BARBARA">
                    <datalist id="listaOrigenSugerido">
                        <option value="CEIBITA SUR, SANTA BARBARA">
                        <option value="CEIBITA SUR, SB">
                    </datalist>
                </div>
            </div>

            <div style="max-width: 49%; margin-bottom: 1.25rem;">
                <label for="destino" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                    Destino
                </label>
                <input type="text" id="destino" name="destino" class="form-control" style="height: 42px; border-radius: 6px;">
            </div>

            <div>
                <label for="observaciones" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                    Observaciones
                </label>
                <textarea id="observaciones" name="observaciones" class="form-control" rows="3" style="border-radius: 6px;"></textarea>
            </div>
        </div>

        <!-- BLOQUE DE CIERRE DIRECTO (VISIBLE SOLO EN PESADA ÚNICA) -->
        <div id="bloquePesadaUnica" class="card" style="display: none; padding: 1.75rem 2rem; border-radius: 12px; border: 2px solid #0b57d0; background: var(--bg-surface); margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
                <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0; color: #0b57d0;">
                    ⚡ Liquidación y Cierre Directo (Pesada Única)
                </h3>
                <span style="font-size: 0.8rem; font-weight: 700; color: #475569;">1 QQ = 100 lb</span>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 1rem; margin-bottom: 1.25rem;">
                <div>
                    <label for="peso_comp_unica" id="lblPesoCompUnica" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted);">
                        Tara vehículo / recipiente (lb)
                    </label>
                    <input type="number" id="peso_comp_unica" name="peso_comp_unica" class="form-control" value="0" min="0" step="1" oninput="recalcularPesadaUnica()">
                </div>
                <div>
                    <label for="idempaque_unica" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted);">
                        Tipo de empaque
                    </label>
                    <select id="idempaque_unica" name="idempaque" class="form-control" onchange="recalcularPesadaUnica()">
                        <option value="0" data-tara="0">Sin empaque (0 lb)</option>
                        <?php foreach ($listaEmpaques as $emp): ?>
                            <option value="<?= (int)$emp['idempaque'] ?>" data-tara="<?= (float)$emp['taralbs'] ?>">
                                <?= htmlspecialchars($emp['nombre'], ENT_QUOTES, 'UTF-8') ?> (<?= number_format((float)$emp['taralbs'], 3) ?> lb)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="cantidad_empaques_unica" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted);">
                        Cantidad de empaques
                    </label>
                    <input type="number" id="cantidad_empaques_unica" name="cantidad_empaques" class="form-control" value="0" min="0" step="1" oninput="recalcularPesadaUnica()">
                </div>
                <div>
                    <label for="ajuste_humedad_unica" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted);">
                        % Humedad
                    </label>
                    <input type="number" id="ajuste_humedad_unica" name="ajuste_humedad_pct" class="form-control" value="0" min="0" max="100" step="0.01" oninput="recalcularPesadaUnica()">
                </div>
                <div>
                    <label for="descuento_calidad_unica" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted);">
                        % Defectos
                    </label>
                    <input type="number" id="descuento_calidad_unica" name="descuento_calidad_pct" class="form-control" value="0" min="0" max="100" step="0.01" oninput="recalcularPesadaUnica()">
                </div>
            </div>

            <!-- RESUMEN EN VIVO DE PESADA ÚNICA -->
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.85rem 1.25rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
                <div id="resUnicaDetalle" style="font-size: 0.85rem; color: #334155;">
                    Fija el peso para ver la liquidación final.
                </div>
                <div style="text-align: right;">
                    <span style="font-size: 0.78rem; color: #64748b; display: block;">TOTAL FINAL</span>
                    <strong id="resUnicaTotal" style="font-size: 1.35rem; color: #881337;">0 lb &middot; 0.00 QQ</strong>
                </div>
            </div>

            <!-- GUÍA DE REMISIÓN EN PESADA ÚNICA (SOLO SI ES VENTA) -->
            <div id="subBloqueGuiaUnica" style="display: none; border-top: 1px dashed #cbd5e1; padding-top: 1rem; margin-top: 0.5rem;">
                <h4 style="margin: 0 0 0.75rem 0; font-size: 0.95rem; font-weight: 800; color: #047857;">
                    Guía de Remisión (Venta)
                </h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div>
                        <label for="guia_remision_unica" class="form-label" style="font-size: 0.8rem;">N° Guía de Remisión</label>
                        <input type="text" id="guia_remision_unica" name="guia_remision" class="form-control" maxlength="30" placeholder="Ej. 002861">
                    </div>
                    <div>
                        <label for="motivo_traslado_unica" class="form-label" style="font-size: 0.8rem;">Motivo de Traslado</label>
                        <select id="motivo_traslado_unica" name="motivo_traslado" class="form-control" onchange="document.getElementById('boxMotivoOtroUnica').style.display = (this.value === 'Otros (especificar)') ? 'block' : 'none';">
                            <option value="Venta" selected>Venta</option>
                            <option value="Compra">Compra</option>
                            <option value="Consignación">Consignación</option>
                            <option value="Venta con entrega a terceros">Venta con entrega a terceros</option>
                            <option value="Venta sujeta a confirmación por el comprador">Venta sujeta a confirmación por el comprador</option>
                            <option value="Traslado entre establecimientos de la misma Empresa">Traslado entre establecimientos de la misma Empresa</option>
                            <option value="Devolución">Devolución</option>
                            <option value="Otros (especificar)">Otros (especificar)</option>
                            <option value="Recojo de bienes">Recojo de bienes</option>
                            <option value="Importación">Importación</option>
                            <option value="Exportación">Exportación</option>
                            <option value="Traslado zona primaria">Traslado zona primaria</option>
                            <option value="Traslado por emisor itinerante de comprobantes de pago">Traslado por emisor itinerante de comprobantes de pago</option>
                            <option value="Traslado de bienes para transformación">Traslado de bienes para transformación</option>
                            <option value="Transformación">Transformación</option>
                        </select>
                    </div>
                    <div id="boxMotivoOtroUnica" style="display: none;">
                        <label for="motivo_traslado_otro_unica" class="form-label" style="font-size: 0.8rem;">Especificar otro motivo</label>
                        <input type="text" id="motivo_traslado_otro_unica" name="motivo_traslado_otro" class="form-control" maxlength="120">
                    </div>
                    <div>
                        <label for="marca_vehiculo_unica" class="form-label" style="font-size: 0.8rem;">Marca del Vehículo</label>
                        <input type="text" id="marca_vehiculo_unica" name="marca_vehiculo" class="form-control" maxlength="60">
                    </div>
                    <div>
                        <label for="transportista_rtn_unica" class="form-label" style="font-size: 0.8rem;">R.T.N. Transportista</label>
                        <input type="text" id="transportista_rtn_unica" name="transportista_rtn" class="form-control" maxlength="20">
                    </div>
                </div>
            </div>
        </div>

        <!-- ACCIONES INFERIORES -->
        <div style="display: flex; gap: 1.25rem; align-items: center; margin-bottom: 3rem;">
            <button type="submit" id="btnGuardarPesada" class="btn btn-primary" style="background: #2563eb; padding: 0.75rem 1.75rem; font-weight: 700; border-radius: 6px;" disabled>
                Guardar primera pesada
            </button>
            <a href="index.php" style="color: #1e40af; font-size: 0.95rem; font-weight: 600; text-decoration: none;">
                Cancelar
            </a>
            <span id="txtGuiaGuardar" style="font-size: 0.85rem; color: var(--text-muted);">
                Selecciona si registras peso bruto o tara del vehículo.
            </span>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
const TODAS_PRESENTACIONES = <?= json_encode($listaPresentaciones, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let pesoEnVivo = 0;
let esPesoEstable = true;

// Actualizar dinámicamente lectura en vivo desde el Monitor
function conectarLecturaEnVivo() {
    setInterval(async () => {
        try {
            const res = await fetch('http://127.0.0.1:8018/api/v1/peso');
            if (res.ok) {
                const data = await res.json();
                pesoEnVivo = Math.round(Number(data.peso_lb || 0));
                esPesoEstable = Boolean(data.estable);

                const elVal = document.getElementById('livePesoValor');
                if (elVal) elVal.innerText = pesoEnVivo.toLocaleString('en-US');

                const elBadge = document.getElementById('liveEstableBadge');
                if (elBadge) {
                    elBadge.innerText = esPesoEstable ? 'Estable' : 'Inestable';
                    elBadge.style.color = esPesoEstable ? '#60a5fa' : '#f87171';
                }

                const elHora = document.getElementById('liveUltimaLectura');
                if (elHora) {
                    const ahora = new Date();
                    elHora.innerText = 'Última lectura: ' + ahora.toLocaleTimeString();
                }
            }
        } catch (e) {
            // Error silencioso para no romper la pantalla si el hardware está desconectado
        }
    }, 400);
}

document.addEventListener('DOMContentLoaded', () => {
    conectarLecturaEnVivo();
    
    // Inicializar lógica de Compra por defecto
    cambiarTransaccionPrincipal('COMPRA');

    const selTipoReg = document.getElementById('tipo_registro');
    if (selTipoReg) {
        selTipoReg.addEventListener('change', () => {
            const btnFijar = document.getElementById('btnFijarPeso');
            const txtGuiaFijar = document.getElementById('txtGuiaFijar');
            const txtGuiaGuardar = document.getElementById('txtGuiaGuardar');
            
            if (selTipoReg.value !== '') {
                btnFijar.disabled = false;
                if (txtGuiaFijar) txtGuiaFijar.style.display = 'none';
                if (txtGuiaGuardar) txtGuiaGuardar.innerText = 'Fija el peso antes de guardar.';
            }
        });
    }
});

// Control maestro de Compra vs Venta y automatización de Bruto vs Tara inicial
function cambiarTransaccionPrincipal(tipo) {
    const selTipoReg = document.getElementById('tipo_registro');
    const inputHiddenOp = document.getElementById('tipo_operacion');
    const badgeOp = document.getElementById('badgeOpOperacion');
    const btnCompra = document.getElementById('btnTxCompra');
    const btnVenta = document.getElementById('btnTxVenta');
    const btnFijar = document.getElementById('btnFijarPeso');
    const txtGuiaFijar = document.getElementById('txtGuiaFijar');
    const txtGuiaGuardar = document.getElementById('txtGuiaGuardar');

    inputHiddenOp.value = tipo;
    if (badgeOp) {
        badgeOp.innerText = tipo;
        badgeOp.style.background = (tipo === 'COMPRA') ? '#dbeafe' : '#d1fae5';
        badgeOp.style.color = (tipo === 'COMPRA') ? '#1e40af' : '#047857';
    }
    if (btnCompra && btnVenta) {
        btnCompra.style.background = (tipo === 'COMPRA') ? '#1e40af' : 'transparent';
        btnCompra.style.color = (tipo === 'COMPRA') ? '#ffffff' : '#475569';
        btnVenta.style.background = (tipo === 'VENTA') ? '#047857' : 'transparent';
        btnVenta.style.color = (tipo === 'VENTA') ? '#ffffff' : '#475569';
    }

    // Regla de negocio:
    // - En COMPRA: Primero viene cargado -> se pesa PESO BRUTO.
    // - En VENTA: Primero entra vacío a cargar -> se pesa TARA DEL VEHÍCULO.
    // No queda fijado permanentemente; el operador puede cambiarlo manualmente si lo necesita.
    if (tipo === 'COMPRA') {
        selTipoReg.value = 'BRUTO';
        document.getElementById('tipo_registro_hidden').value = 'BRUTO';
    } else {
        selTipoReg.value = 'TARA';
        document.getElementById('tipo_registro_hidden').value = 'TARA';
    }

    // Habilitar botón de fijar peso automáticamente al tener ya seleccionado el tipo
    btnFijar.disabled = false;
    if (txtGuiaFijar) txtGuiaFijar.style.display = 'none';
    if (txtGuiaGuardar) txtGuiaGuardar.innerText = 'Fija el peso antes de guardar.';

    // Actualizar filtro de Proveedor / Cliente y sub-bloque Guía de Remisión
    filtrarPersonas(tipo);
    const subGuia = document.getElementById('subBloqueGuiaUnica');
    if (subGuia) {
        subGuia.style.display = (tipo === 'VENTA') ? 'block' : 'none';
    }
    const chkUnica = document.getElementById('chkPesadaUnica');
    if (chkUnica && chkUnica.checked) {
        selTipoReg.value = 'BRUTO';
        document.getElementById('tipo_registro_hidden').value = 'BRUTO';
        recalcularPesadaUnica();
    }
}

// Permitir habilitar o bloquear manualmente el selector de peso
function alternarEdicionTipoRegistro(habilitar) {
    const sel = document.getElementById('tipo_registro');
    sel.disabled = !habilitar;
    if (habilitar) {
        sel.style.background = '#ffffff';
        sel.style.color = 'var(--text-main)';
        sel.focus();
    } else {
        sel.style.background = '#f8fafc';
        sel.style.color = '#475569';
    }
}

// Sincronizar input oculto al cambiar manualmente
document.addEventListener('DOMContentLoaded', () => {
    const selTipoReg = document.getElementById('tipo_registro');
    if (selTipoReg) {
        selTipoReg.addEventListener('change', () => {
            document.getElementById('tipo_registro_hidden').value = selTipoReg.value;
            recalcularPesadaUnica();
        });
    }
});

let pesadaUnicaAutoActivada = false;

function alternarPesadaUnica(activa, cambioManual) {
    if (cambioManual) {
        pesadaUnicaAutoActivada = false;
        const aviso = document.getElementById('avisoSugerenciaAceite');
        if (aviso && !activa) aviso.style.display = 'none';
    }

    const bloque = document.getElementById('bloquePesadaUnica');
    const btnGuardar = document.getElementById('btnGuardarPesada');
    const tipoOp = document.getElementById('tipo_operacion').value;
    const subGuia = document.getElementById('subBloqueGuiaUnica');
    const selTipoReg = document.getElementById('tipo_registro');

    if (bloque) bloque.style.display = activa ? 'block' : 'none';
    if (subGuia) subGuia.style.display = (activa && tipoOp === 'VENTA') ? 'block' : 'none';

    if (btnGuardar) {
        btnGuardar.innerText = activa ? '⚡ Guardar y cerrar pesada única' : 'Guardar primera pesada';
        btnGuardar.style.background = activa ? '#047857' : '#2563eb';
    }

    if (activa) {
        selTipoReg.value = 'BRUTO';
        document.getElementById('tipo_registro_hidden').value = 'BRUTO';
        recalcularPesadaUnica();
    } else {
        // Restaurar sugerencia normal por operación si se desactiva
        selTipoReg.value = (tipoOp === 'COMPRA') ? 'BRUTO' : 'TARA';
        document.getElementById('tipo_registro_hidden').value = selTipoReg.value;
    }
}

function recalcularPesadaUnica() {
    const chk = document.getElementById('chkPesadaUnica');
    if (!chk || !chk.checked) return;

    const pesoFijado = parseFloat(document.getElementById('inputPesoFijado').value || '0');
    const tipoReg = document.getElementById('tipo_registro_hidden').value;
    const pesoComp = parseFloat(document.getElementById('peso_comp_unica').value || '0');

    const lblComp = document.getElementById('lblPesoCompUnica');
    if (lblComp) {
        lblComp.innerText = (tipoReg === 'BRUTO') ? 'Tara vehículo / recipiente (lb)' : 'Peso Bruto con carga (lb)';
    }

    const bruto = (tipoReg === 'BRUTO') ? pesoFijado : pesoComp;
    const taraV = (tipoReg === 'BRUTO') ? pesoComp : pesoFijado;
    const netoVeh = Math.max(0, bruto - taraV);

    const selEmp = document.getElementById('idempaque_unica');
    const optEmp = selEmp.options[selEmp.selectedIndex];
    const taraUnit = optEmp ? parseFloat(optEmp.getAttribute('data-tara') || '0') : 0;
    const cantEmp = parseInt(document.getElementById('cantidad_empaques_unica').value || '0', 10);
    const taraEmp = Math.round(taraUnit * Math.max(0, cantEmp));

    const base = Math.max(0, netoVeh - taraEmp);
    const pctH = Math.max(0, parseFloat(document.getElementById('ajuste_humedad_unica').value || '0'));
    const pctD = Math.max(0, parseFloat(document.getElementById('descuento_calidad_unica').value || '0'));
    const descH = Math.round(base * (pctH / 100));
    const descD = Math.round(base * (pctD / 100));
    const finalLb = Math.max(0, base - descH - descD);
    const qq = finalLb / 100;

    const elDetalle = document.getElementById('resUnicaDetalle');
    const elTotal = document.getElementById('resUnicaTotal');
    if (elDetalle) {
        elDetalle.innerText = `Bruto: ${bruto.toLocaleString('en-US')} lb · Tara: ${taraV.toLocaleString('en-US')} lb · Neto: ${netoVeh.toLocaleString('en-US')} lb · Tara emp: ${taraEmp.toLocaleString('en-US')} lb · Base: ${base.toLocaleString('en-US')} lb`;
    }
    if (elTotal) {
        elTotal.innerText = `${finalLb.toLocaleString('en-US')} lb · ${qq.toFixed(2)} QQ`;
    }
}

function alternarModoCaptura(modo) {
    const divManual = document.getElementById('campoPesoManual');
    if (divManual) {
        divManual.style.display = (modo === 'MANUAL') ? 'block' : 'none';
    }
}

function fijarPeso() {
    const modo = document.getElementById('modo_captura').value;
    let pesoAFijar = 0;

    if (modo === 'MANUAL') {
        const inpManual = document.getElementById('input_manual');
        pesoAFijar = parseInt(inpManual.value || '0', 10);
        if (pesoAFijar <= 0) {
            alert('Por favor ingresa un peso manual válido.');
            inpManual.focus();
            return;
        }
    } else {
        pesoAFijar = pesoEnVivo;
    }

    document.getElementById('inputPesoFijado').value = pesoAFijar;
    document.getElementById('valorPesoFijado').innerText = pesoAFijar.toLocaleString('en-US');

    // Habilitar botón de guardado
    const btnGuardar = document.getElementById('btnGuardarPesada');
    const txtGuiaGuardar = document.getElementById('txtGuiaGuardar');
    if (btnGuardar) {
        btnGuardar.disabled = false;
        if (txtGuiaGuardar) txtGuiaGuardar.style.display = 'none';
    }
    recalcularPesadaUnica();
}

function filtrarPersonas(tipoOp) {
    const lbl = document.getElementById('lblPersona');
    lbl.innerText = (tipoOp === 'COMPRA') ? 'Proveedor' : 'Cliente';

    const select = document.getElementById('clie_prov_id');
    const opts = select.querySelectorAll('option');

    opts.forEach(opt => {
        if (!opt.value) return; // omitir placeholder
        const esCli = opt.getAttribute('data-cliente') === '1';
        const esProv = opt.getAttribute('data-proveedor') === '1';

        if (tipoOp === 'COMPRA') {
            opt.style.display = esProv ? 'block' : 'none';
        } else {
            opt.style.display = esCli ? 'block' : 'none';
        }
    });

    select.value = '';
}

function filtrarPresentaciones(productoId) {
    const selPres = document.getElementById('presentacion_id');
    selPres.innerHTML = '<option value="" disabled selected>Selecciona una presentación</option>';

    const filtradas = TODAS_PRESENTACIONES.filter(p => String(p.producto_id) === String(productoId));
    filtradas.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.nombre;
        selPres.appendChild(opt);
    });

    if (filtradas.length === 1) {
        selPres.value = filtradas[0].id;
    }

    // Sugerir Pesada Única automáticamente cuando el producto seleccionado sea ACEITE
    const selProd = document.getElementById('producto_id');
    const nombreProd = (selProd && selProd.selectedIndex >= 0) ? selProd.options[selProd.selectedIndex].text.toUpperCase() : '';
    const chkUnica = document.getElementById('chkPesadaUnica');
    const avisoAceite = document.getElementById('avisoSugerenciaAceite');

    if (chkUnica) {
        if (nombreProd.includes('ACEITE')) {
            if (!chkUnica.checked) {
                chkUnica.checked = true;
                pesadaUnicaAutoActivada = true;
                alternarPesadaUnica(true, false);
            }
            if (avisoAceite) avisoAceite.style.display = 'block';
        } else {
            if (avisoAceite) avisoAceite.style.display = 'none';
            if (pesadaUnicaAutoActivada) {
                chkUnica.checked = false;
                pesadaUnicaAutoActivada = false;
                alternarPesadaUnica(false, false);
            }
        }
    }
}

function actualizarNotaResponsable(sel) {
    const txt = document.getElementById('txtResponsableNota');
    const optNombre = sel.options[sel.selectedIndex].text;
    if (txt) {
        txt.innerText = 'La captura queda registrada por ' + optNombre + '.';
    }
}
</script>

<?php
renderizarPie();
