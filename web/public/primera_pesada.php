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

                // Obtener correlativo único continuo (Compra y Venta comparten secuencia a partir de 419)
                $colCorrelativo = ($tipoOperacion === 'COMPRA') ? 'id_compra' : 'id_venta';
                $stmtCorr = $pdo->prepare("
                    SELECT GREATEST(
                        418,
                        COALESCE(MAX(id_compra), 0),
                        COALESCE(MAX(id_venta), 0)
                    ) + 1 AS sig
                    FROM t_pesadas
                    WHERE empresa_id = :empresa_id
                    FOR UPDATE
                ");
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
                            $taraTotalEmp = ceil(round($taraUnitaria * $cantEmpaques, 4));
                        }
                    }

                    $baseDescuentos = max(0.0, $pesoNetoSinDesc - $taraTotalEmp);
                    $descHumedadLb  = ceil(round($baseDescuentos * ($pctHumedad / 100.0), 4));
                    $descDefectosLb = ceil(round($baseDescuentos * ($pctDefectos / 100.0), 4));
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

                require_once dirname(__DIR__) . '/src/sync_nube.php';
                sincronizarPesadaAutomatica($idPesada);

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
$personas = $pdo->prepare("SELECT id, nombre, direccion, es_cliente, es_proveedor FROM t_clie_prov WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY nombre ASC");
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

// Consultar placas con boleta ABIERTA en patio para prevenir doble registro accidental
$stmtPatio = $pdo->prepare("
    SELECT p.idpesada, p.placa, p.tipo_operacion, p.id_compra, p.id_venta, cp.nombre AS persona_nombre
    FROM t_pesadas p
    LEFT JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
    WHERE p.empresa_id = :empresa_id AND p.estado = 'ABIERTA' AND p.placa IS NOT NULL AND p.placa <> ''
");
$stmtPatio->execute([':empresa_id' => $empresaId]);
$placasAbiertasPatio = $stmtPatio->fetchAll(PDO::FETCH_ASSOC);

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Primera Pesada', 'primera');
?>

<div style="max-width: 1040px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div class="cockpit-page-header" style="margin-bottom: 1.25rem;">
        <span class="cockpit-eyebrow" style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
            PESAJE
        </span>
        <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.2rem 0 0.2rem 0; color: var(--text-main); line-height: 1.1;">
            Primera pesada
        </h1>
        <span class="cockpit-empresa-sub" style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
            <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>
        </span>
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

        <!-- FILA 1: BARRA SUPERIOR COMPACTA (TIPO DE TRANSACCIÓN + TOGGLE PESADA ÚNICA) -->
        <div class="card cockpit-top-bar" style="padding: 0.75rem 1.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 0.85rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.85rem;">
            <div class="cockpit-tx-group" style="display: flex; align-items: center; gap: 0.85rem; flex-wrap: wrap;">
                <span class="cockpit-tx-label" style="font-size: 0.82rem; font-weight: 800; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.5px;">
                    Transacción:
                </span>
                <div style="display: inline-flex; background: #e2e8f0; padding: 3px; border-radius: 9px; gap: 4px; flex-wrap: wrap;">
                    <button type="button" id="btnTxCompra" onclick="cambiarTransaccionPrincipal('COMPRA')" style="padding: 0.45rem 1.05rem; border-radius: 7px; font-weight: 800; font-size: 0.86rem; border: none; cursor: pointer; background: #1e40af; color: #ffffff; transition: all 0.15s ease;">
                        📥 COMPRA (Entra cargado)
                    </button>
                    <button type="button" id="btnTxVenta" onclick="cambiarTransaccionPrincipal('VENTA')" style="padding: 0.45rem 1.05rem; border-radius: 7px; font-weight: 800; font-size: 0.86rem; border: none; cursor: pointer; background: transparent; color: #475569; transition: all 0.15s ease;">
                        📤 VENTA (Entra vacío)
                    </button>
                </div>
                <span id="badgeOpOperacion" style="font-size: 0.75rem; font-weight: 800; background: #dbeafe; color: #1e40af; padding: 0.2rem 0.65rem; border-radius: 999px;">
                    COMPRA
                </span>
            </div>

            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; margin: 0; font-weight: 700; font-size: 0.85rem; color: #0f172a; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.4rem 0.85rem;">
                    <input type="checkbox" id="chkPesadaUnica" name="pesada_unica" value="1" onchange="alternarPesadaUnica(this.checked, true)" style="width: 16px; height: 16px; cursor: pointer;">
                    <span>⚡ Pesada Única (cerrar en un solo paso)</span>
                </label>
                <span id="avisoSugerenciaAceite" style="display: none; font-size: 0.76rem; color: #047857; font-weight: 700;">
                    💡 Sugerido para ACEITE
                </span>
            </div>
        </div>

        <!-- FILA 2: CAPTURA DEL PESO + INDICADOR EN VIVO (MISMA ALTURA) -->
        <div class="cockpit-grid-2" style="display: grid; grid-template-columns: 1.1fr 1fr; gap: 1rem; margin-bottom: 0.85rem; align-items: stretch;">
            
            <!-- PANEL IZQUIERDO: SELECCIÓN Y FIJAR PESO -->
            <div class="card cockpit-captura-panel" style="padding: 1rem 1.25rem; margin: 0; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                        <h3 style="font-size: 1.02rem; font-weight: 800; margin: 0; color: var(--text-main);">
                            Captura del peso
                        </h3>
                        <span id="txtGuiaFijar" style="font-size: 0.76rem; color: var(--text-muted);">
                            Compra = Bruto &middot; Venta = Tara
                        </span>
                    </div>

                    <div class="cockpit-captura-row" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 0.75rem; align-items: end; margin-bottom: 0.75rem;">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                                <label for="tipo_registro" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin: 0;">
                                    Peso a registrar
                                </label>
                                <label style="display: inline-flex; align-items: center; cursor: pointer; margin: 0;" title="Habilitar cambio manual entre Peso Bruto y Tara">
                                    <input type="checkbox" id="chk_habilitar_tipo_reg" onchange="alternarEdicionTipoRegistro(this.checked)" style="cursor: pointer; width: 14px; height: 14px;">
                                </label>
                            </div>
                            <select id="tipo_registro" name="tipo_registro" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem; background: #f8fafc; color: #475569;" disabled>
                                <option value="BRUTO" selected>Peso Bruto</option>
                                <option value="TARA">Tara de Vehículo</option>
                            </select>
                            <input type="hidden" id="tipo_registro_hidden" name="tipo_registro" value="BRUTO">
                        </div>

                        <div>
                            <label for="modo_captura" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem;">
                                Modo de captura
                            </label>
                            <select id="modo_captura" name="modo_captura" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" onchange="alternarModoCaptura(this.value)">
                                <option value="INDICADOR" selected>Indicador en vivo</option>
                                <option value="MANUAL">Manual</option>
                            </select>
                        </div>

                        <button type="button" id="btnFijarPeso" class="btn btn-primary" onclick="fijarPeso()" style="background: #2563eb; height: 38px; padding: 0 1.15rem; font-weight: 700; border-radius: 6px; font-size: 0.88rem; white-space: nowrap;">
                            Fijar peso
                        </button>
                    </div>

                    <div id="campoPesoManual" style="display: none; margin-bottom: 0.75rem;">
                        <label for="input_manual" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                            Ingresar peso manual (lb)
                        </label>
                        <input type="number" id="input_manual" class="form-control" style="height: 36px; border-radius: 6px; font-size: 0.88rem;" placeholder="0" min="1" step="1">
                    </div>
                </div>

                <!-- DISPLAY ROJO BURDEOS COMPACTO: PESO FIJADO -->
                <div id="cajaPesoFijadoPrimera" style="scroll-margin-top: 68px; background: #701a2b; color: #ffffff; border-radius: 8px; padding: 0.75rem 1.15rem; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.8px; text-transform: uppercase; opacity: 0.92;">
                        PESO FIJADO
                    </span>
                    <div style="font-size: 1.85rem; font-weight: 900; line-height: 1; display: flex; align-items: baseline; gap: 0.35rem;">
                        <span id="valorPesoFijado">--</span>
                        <span style="font-size: 0.95rem; font-weight: 600; opacity: 0.85;">lb</span>
                    </div>
                </div>
            </div>

            <!-- PANEL DERECHO: INDICADOR EN VIVO COMPACTO -->
            <div class="cockpit-live-panel" style="background: #0f233a; color: #ffffff; border-radius: 12px; padding: 1rem 1.35rem; display: flex; flex-direction: column; justify-content: space-between;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <span style="font-size: 0.68rem; font-weight: 800; letter-spacing: 1.2px; text-transform: uppercase; color: #94a3b8;">
                            INDICADOR DE PESO
                        </span>
                        <h2 style="font-size: 1.25rem; font-weight: 800; margin: 0.1rem 0 0 0; color: #ffffff;">
                            Lectura en vivo
                        </h2>
                    </div>
                    <span id="liveEstableBadge" style="background: #1e3a5f; color: #60a5fa; font-size: 0.72rem; font-weight: 700; padding: 0.25rem 0.7rem; border-radius: 999px; border: 1px solid #2563eb;">
                        Estable
                    </span>
                </div>

                <div style="text-align: center; padding: 0.45rem 0;">
                    <div style="font-size: 3.1rem; font-weight: 900; line-height: 1; letter-spacing: -1px; display: inline-flex; align-items: baseline; gap: 0.4rem;">
                        <span id="livePesoValor">0</span>
                        <span style="font-size: 1.35rem; font-weight: 700; color: #93c5fd;">lb</span>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 0.6rem; font-size: 0.76rem; color: #94a3b8;">
                    <div>
                        <span id="liveModoTexto">Conectando...</span>
                        <div style="font-size: 0.68rem; color: #64748b;" id="liveUltimaLectura">Última lectura: --:--:--</div>
                    </div>
                    <div style="text-align: right;">
                        <span style="color: #cbd5e1;">Fuente &middot; Configurada</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- FILA 3: OPERACIÓN + VEHÍCULO + TRASLADO Y OBSERVACIONES (3 FILAS DE 3 COLUMNAS) -->
        <div id="seccionDatosPrimera" class="card" style="padding: 1.1rem 1.35rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1rem;">
            <input type="hidden" id="tipo_operacion" name="tipo_operacion" value="COMPRA">
            <input type="hidden" id="responsable_id" name="responsable_id" value="<?= (int)$usuarioActual['id'] ?>">

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem; flex-wrap: wrap; gap: 0.5rem;">
                <h3 style="font-size: 1rem; font-weight: 800; margin: 0; color: var(--text-main);">
                    Datos de operación, vehículo y traslado
                </h3>
                <small id="txtResponsableNota" style="color: var(--text-muted); font-size: 0.76rem; font-weight: 600;">
                    Responsable: <strong style="color: var(--text-main);"><?= htmlspecialchars($usuarioActual['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>
                </small>
            </div>

            <!-- LÍNEA 1: PROVEEDOR/CLIENTE, PRODUCTO, PRESENTACIÓN (3 COLUMNAS) -->
            <div class="cockpit-grid-3" style="display: grid; grid-template-columns: 1.3fr 1.1fr 0.9fr; gap: 0.85rem; margin-bottom: 0.75rem;">
                <div>
                    <label for="clie_prov_id" id="lblPersona" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                        Proveedor <span style="color: var(--danger);">*</span>
                    </label>
                    <select id="clie_prov_id" name="clie_prov_id" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" onchange="actualizarSugerenciaUbicacion()">
                        <option value="" disabled selected>Selecciona persona o empresa</option>
                        <?php foreach ($listaPersonas as $p): ?>
                            <option value="<?= $p['id'] ?>" data-cliente="<?= $p['es_cliente'] ?>" data-proveedor="<?= $p['es_proveedor'] ?>" data-direccion="<?= htmlspecialchars((string)($p['direccion'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="producto_id" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                        Producto <span style="color: var(--danger);">*</span>
                    </label>
                    <select id="producto_id" name="producto_id" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" onchange="filtrarPresentaciones(this.value)">
                        <option value="" disabled selected>Selecciona un producto</option>
                        <?php foreach ($listaProductos as $prod): ?>
                            <option value="<?= $prod['id'] ?>">
                                <?= htmlspecialchars($prod['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="presentacion_id" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                        Presentación <span style="color: var(--danger);">*</span>
                    </label>
                    <select id="presentacion_id" name="presentacion_id" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;">
                        <option value="" disabled selected>Selecciona presentación</option>
                    </select>
                </div>
            </div>

            <!-- LÍNEA 2: PLACA, MOTORISTA, LICENCIA (3 COLUMNAS) -->
            <div class="cockpit-grid-3" style="display: grid; grid-template-columns: 0.9fr 1.3fr 1.1fr; gap: 0.85rem; margin-bottom: 0.75rem;">
                <div>
                    <label for="placa" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                        Placa <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" id="placa" name="placa" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem; text-transform: uppercase;">
                </div>
                <div>
                    <label for="motorista" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                        Motorista
                    </label>
                    <input type="text" id="motorista" name="motorista" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;">
                </div>
                <div>
                    <label for="licencia" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                        Licencia
                    </label>
                    <input type="text" id="licencia" name="licencia" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;">
                </div>
            </div>
            <div id="avisoPlacaEnPatio" style="display: none; margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: 8px; background: #fef3c7; border: 1px solid #f59e0b; color: #92400e; font-size: 0.84rem; font-weight: 600;"></div>

            <!-- LÍNEA 3: ORIGEN, DESTINO, OBSERVACIONES (3 COLUMNAS) -->
            <div class="cockpit-grid-3" style="display: grid; grid-template-columns: 1fr 1fr 1.3fr; gap: 0.85rem; margin-bottom: 0.9rem;">
                <div>
                    <label for="origen" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                        Origen / Punto de partida
                    </label>
                    <input type="text" id="origen" name="origen" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;">
                    <datalist id="listaCeibitaSugerida">
                        <option value="CEIBITA SUR, SANTA BARBARA">
                        <option value="CEIBITA SUR, SB">
                    </datalist>
                    <datalist id="listaDireccionPersona"></datalist>
                </div>
                <div>
                    <label for="destino" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                        Destino
                    </label>
                    <input type="text" id="destino" name="destino" list="listaCeibitaSugerida" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" placeholder="CEIBITA SUR, SANTA BARBARA">
                </div>
                <div>
                    <label for="observaciones" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                        Observaciones
                    </label>
                    <input type="text" id="observaciones" name="observaciones" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" placeholder="Notas opcionales sobre la carga o vehículo">
                </div>
            </div>

            <!-- BLOQUE DE CIERRE DIRECTO (VISIBLE SOLO EN PESADA ÚNICA) -->
            <div id="bloquePesadaUnica" style="display: none; padding: 0.9rem 1.1rem; border-radius: 10px; border: 2px solid #0b57d0; background: #f8fafc; margin-bottom: 0.85rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem; flex-wrap: wrap; gap: 0.5rem;">
                    <h4 style="font-size: 0.95rem; font-weight: 800; margin: 0; color: #0b57d0;">
                        ⚡ Liquidación y Cierre Directo (Pesada Única)
                    </h4>
                    <span style="font-size: 0.75rem; font-weight: 700; color: #475569;">1 QQ = 100 lb</span>
                </div>

                <div class="cockpit-grid-5" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.75rem; margin-bottom: 0.75rem;">
                    <div>
                        <label for="peso_comp_unica" id="lblPesoCompUnica" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                            Tara vehículo / recipiente (lb)
                        </label>
                        <input type="number" id="peso_comp_unica" name="peso_comp_unica" class="form-control" style="height: 36px; font-size: 0.86rem;" value="0" min="0" step="1" oninput="recalcularPesadaUnica()">
                    </div>
                    <div>
                        <label for="idempaque_unica" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                            Tipo de empaque
                        </label>
                        <select id="idempaque_unica" name="idempaque" class="form-control" style="height: 36px; font-size: 0.86rem;" onchange="recalcularPesadaUnica()">
                            <option value="0" data-tara="0">Sin empaque (0 lb)</option>
                            <?php foreach ($listaEmpaques as $emp): ?>
                                <option value="<?= (int)$emp['idempaque'] ?>" data-tara="<?= (float)$emp['taralbs'] ?>">
                                    <?= htmlspecialchars($emp['nombre'], ENT_QUOTES, 'UTF-8') ?> (<?= number_format((float)$emp['taralbs'], 3) ?> lb)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="cantidad_empaques_unica" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                            Cantidad de empaques
                        </label>
                        <input type="number" id="cantidad_empaques_unica" name="cantidad_empaques" class="form-control" style="height: 36px; font-size: 0.86rem;" value="0" min="0" step="1" oninput="recalcularPesadaUnica()">
                    </div>
                    <div>
                        <label for="ajuste_humedad_unica" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                            % Humedad
                        </label>
                        <input type="number" id="ajuste_humedad_unica" name="ajuste_humedad_pct" class="form-control" style="height: 36px; font-size: 0.86rem;" value="0" min="0" max="100" step="0.01" oninput="recalcularPesadaUnica()">
                    </div>
                    <div>
                        <label for="descuento_calidad_unica" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                            % Defectos
                        </label>
                        <input type="number" id="descuento_calidad_unica" name="descuento_calidad_pct" class="form-control" style="height: 36px; font-size: 0.86rem;" value="0" min="0" max="100" step="0.01" oninput="recalcularPesadaUnica()">
                    </div>
                </div>

                <!-- RESUMEN EN VIVO DE PESADA ÚNICA (CON DESCUENTOS DETALLADOS) -->
                <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.65rem 1rem; margin-bottom: 0.65rem;">
                    <div class="cockpit-liq-5" style="display: grid; grid-template-columns: repeat(4, 1fr) 1.3fr; gap: 0.5rem; align-items: center; margin-bottom: 0.4rem;">
                        <div>
                            <span style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Neto Inicial</span>
                            <strong id="resUnicaNeto" style="font-size: 1.05rem; color: var(--text-main);">0 lb</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Tara Sacos</span>
                            <strong id="resUnicaTaraEmp" style="font-size: 1.05rem; color: #475569;">0 lb</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Desc. Humedad</span>
                            <strong id="resUnicaDescHum" style="font-size: 1.05rem; color: #b91c1c;">0 lb</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Desc. Defectos</span>
                            <strong id="resUnicaDescDef" style="font-size: 1.05rem; color: #b91c1c;">0 lb</strong>
                        </div>
                        <div class="cockpit-liq-total" style="background: #eff6ff; padding: 0.4rem 0.7rem; border-radius: 6px; border: 1px solid #bfdbfe; text-align: right;">
                            <span style="font-size: 0.66rem; color: #1e40af; font-weight: 800; text-transform: uppercase; display: block;">TOTAL FINAL</span>
                            <strong id="resUnicaTotal" style="font-size: 1.15rem; color: #1e40af;">0 lb &middot; 0.00 QQ</strong>
                        </div>
                    </div>
                    <div id="resUnicaDetalle" style="font-size: 0.75rem; color: #64748b; border-top: 1px dashed #cbd5e1; padding-top: 0.35rem;">
                        Fija el peso para ver la liquidación final.
                    </div>
                </div>

                <!-- GUÍA DE REMISIÓN EN PESADA ÚNICA (SOLO SI ES VENTA) -->
                <div id="subBloqueGuiaUnica" style="display: none; border-top: 1px dashed #cbd5e1; padding-top: 0.65rem;">
                    <div style="font-size: 0.78rem; font-weight: 800; color: #047857; margin-bottom: 0.45rem; text-transform: uppercase;">
                        Guía de Remisión (Venta)
                    </div>
                    <div class="cockpit-grid-4" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;">
                        <div>
                            <label for="guia_remision_unica" class="form-label" style="font-size: 0.74rem; margin-bottom: 0.15rem;">N° Guía de Remisión</label>
                            <input type="text" id="guia_remision_unica" name="guia_remision" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="30" placeholder="Ej. 002861">
                        </div>
                        <div>
                            <label for="motivo_traslado_unica" class="form-label" style="font-size: 0.74rem; margin-bottom: 0.15rem;">Motivo de Traslado</label>
                            <select id="motivo_traslado_unica" name="motivo_traslado" class="form-control" style="height: 34px; font-size: 0.85rem;" onchange="document.getElementById('boxMotivoOtroUnica').style.display = (this.value === 'Otros (especificar)') ? 'block' : 'none';">
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
                        <div>
                            <label for="marca_vehiculo_unica" class="form-label" style="font-size: 0.74rem; margin-bottom: 0.15rem;">Marca del Vehículo</label>
                            <input type="text" id="marca_vehiculo_unica" name="marca_vehiculo" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="60">
                        </div>
                        <div>
                            <label for="transportista_rtn_unica" class="form-label" style="font-size: 0.74rem; margin-bottom: 0.15rem;">R.T.N. Transportista</label>
                            <input type="text" id="transportista_rtn_unica" name="transportista_rtn" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="20">
                        </div>
                        <div id="boxMotivoOtroUnica" style="display: none; grid-column: 1 / -1;">
                            <label for="motivo_traslado_otro_unica" class="form-label" style="font-size: 0.74rem; margin-bottom: 0.15rem;">Especificar otro motivo</label>
                            <input type="text" id="motivo_traslado_otro_unica" name="motivo_traslado_otro" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="120">
                        </div>
                    </div>
                </div>
            </div>

            <div id="avisoFaltantePrimera" style="display: none; margin-bottom: 0.75rem; padding: 0.75rem 0.95rem; border-radius: 8px; background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; font-size: 0.85rem; font-weight: 600;"></div>
            <span id="txtGuiaGuardar" style="display: none;"></span>

            <!-- ACCIONES ABAJO: GUARDAR JUNTO A CANCELAR -->
            <div class="cockpit-actions" style="display: flex; gap: 1rem; align-items: center;">
                <button type="submit" id="btnGuardarPesada" class="btn btn-primary" style="background: #2563eb; height: 38px; padding: 0 1.65rem; font-weight: 700; border-radius: 6px; font-size: 0.92rem; white-space: nowrap;">
                    Guardar primera pesada
                </button>
                <a href="index.php" style="color: #64748b; font-size: 0.9rem; font-weight: 600; text-decoration: none;">
                    Cancelar
                </a>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
const TODAS_PRESENTACIONES = <?= json_encode($listaPresentaciones, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const PLACAS_ABIERTAS_PATIO = <?= json_encode($placasAbiertasPatio, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let pesoEnVivo = 0;
let esPesoEstable = true;

// Actualizar dinámicamente lectura en vivo desde el Monitor (vía puente local Apache para PC y LAN móvil)
function conectarLecturaEnVivo() {
    setInterval(async () => {
        try {
            const res = await fetch('api_peso_local.php?accion=peso', { credentials: 'same-origin' });
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

                const elModo = document.getElementById('liveModoTexto');
                if (elModo) {
                    const esReal = String(data.origen || '').toLowerCase() === 'real';
                    elModo.innerText = esReal ? 'Modo real' : 'Modo simulado';
                    elModo.style.color = esReal ? '#6ee7b7' : '#94a3b8';
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

    // Actualizar filtro de Proveedor / Cliente, sugerencia Origen/Destino y sub-bloque Guía de Remisión
    filtrarPersonas(tipo);
    actualizarSugerenciaUbicacion(tipo);
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

function actualizarSugerenciaUbicacion(tipo) {
    const inputOrigen = document.getElementById('origen');
    const inputDestino = document.getElementById('destino');
    if (!inputOrigen || !inputDestino) return;

    const tipoActual = tipo || (document.getElementById('tipo_operacion')?.value || 'COMPRA');
    const esVenta = (tipoActual === 'VENTA');
    const sugeridoPlanta = 'CEIBITA SUR, SANTA BARBARA';
    const listaPlantaId = 'listaCeibitaSugerida';
    const listaDirId = 'listaDireccionPersona';

    const selPersona = document.getElementById('clie_prov_id');
    const optSel = (selPersona && selPersona.selectedIndex >= 0) ? selPersona.options[selPersona.selectedIndex] : null;
    const dirPersona = (optSel && optSel.value) ? (optSel.getAttribute('data-direccion') || '').trim() : '';

    const dlDir = document.getElementById(listaDirId);
    if (dlDir) {
        dlDir.innerHTML = '';
        if (dirPersona !== '') {
            const opt = document.createElement('option');
            opt.value = dirPersona;
            dlDir.appendChild(opt);
        }
    }

    inputOrigen.placeholder = esVenta ? sugeridoPlanta : dirPersona;
    inputDestino.placeholder = esVenta ? dirPersona : sugeridoPlanta;

    if (esVenta) {
        inputOrigen.setAttribute('list', listaPlantaId);
        if (dirPersona !== '') {
            inputDestino.setAttribute('list', listaDirId);
        } else {
            inputDestino.removeAttribute('list');
        }
    } else {
        if (dirPersona !== '') {
            inputOrigen.setAttribute('list', listaDirId);
        } else {
            inputOrigen.removeAttribute('list');
        }
        inputDestino.setAttribute('list', listaPlantaId);
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
    const taraEmp = Math.ceil(Number((taraUnit * Math.max(0, cantEmp)).toFixed(4)));

    const base = Math.max(0, netoVeh - taraEmp);
    const pctH = Math.max(0, parseFloat(document.getElementById('ajuste_humedad_unica').value || '0'));
    const pctD = Math.max(0, parseFloat(document.getElementById('descuento_calidad_unica').value || '0'));
    const descH = Math.ceil(Number((base * (pctH / 100)).toFixed(4)));
    const descD = Math.ceil(Number((base * (pctD / 100)).toFixed(4)));
    const finalLb = Math.max(0, base - descH - descD);
    const qq = finalLb / 100;

    const elNeto = document.getElementById('resUnicaNeto');
    const elTaraEmp = document.getElementById('resUnicaTaraEmp');
    const elDescHum = document.getElementById('resUnicaDescHum');
    const elDescDef = document.getElementById('resUnicaDescDef');
    const elDetalle = document.getElementById('resUnicaDetalle');
    const elTotal = document.getElementById('resUnicaTotal');

    if (elNeto) elNeto.innerText = `${netoVeh.toLocaleString('en-US')} lb`;
    if (elTaraEmp) elTaraEmp.innerText = `${taraEmp.toLocaleString('en-US')} lb`;
    if (elDescHum) elDescHum.innerText = `${descH.toLocaleString('en-US')} lb (${pctH}%)`;
    if (elDescDef) elDescDef.innerText = `${descD.toLocaleString('en-US')} lb (${pctD}%)`;
    if (elDetalle) {
        elDetalle.innerText = `Bruto: ${bruto.toLocaleString('en-US')} lb · Tara: ${taraV.toLocaleString('en-US')} lb · Neto: ${netoVeh.toLocaleString('en-US')} lb · Tara emp: ${taraEmp.toLocaleString('en-US')} lb · Base: ${base.toLocaleString('en-US')} lb`;
    }
    if (elTotal) {
        elTotal.innerText = `${finalLb.toLocaleString('en-US')} lb · ${qq.toFixed(2)} QQ`;
    }
}

function mostrarAvisoPrimera(mensajes) {
    const box = document.getElementById('avisoFaltantePrimera');
    if (!box) return;
    const lista = Array.isArray(mensajes) ? mensajes : [mensajes];
    box.innerHTML = '<strong>No se puede guardar todavía. Falta lo siguiente:</strong><ul style="margin: 0.35rem 0 0 1.2rem; padding: 0;">' +
        lista.map((m) => `<li>${m}</li>`).join('') + '</ul>';
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function ocultarAvisoPrimera() {
    const box = document.getElementById('avisoFaltantePrimera');
    if (box) box.style.display = 'none';
}

function obtenerFaltantesPrimeraPesada() {
    const faltantes = [];
    const pesoFijado = parseFloat(document.getElementById('inputPesoFijado')?.value || '0');
    const tipoOp = document.getElementById('tipo_operacion')?.value || 'COMPRA';
    const persona = document.getElementById('clie_prov_id')?.value || '';
    const producto = document.getElementById('producto_id')?.value || '';
    const presentacion = document.getElementById('presentacion_id')?.value || '';
    const placa = (document.getElementById('placa')?.value || '').trim();
    const chkUnica = document.getElementById('chkPesadaUnica');

    if (pesoFijado <= 0) {
        faltantes.push('Fijar el peso de la báscula (haz clic en <strong>Fijar peso</strong>).');
    }
    if (!persona) {
        faltantes.push(`Seleccionar el <strong>${tipoOp === 'COMPRA' ? 'Proveedor' : 'Cliente'}</strong>.`);
    }
    if (!producto) {
        faltantes.push('Seleccionar el <strong>Producto</strong>.');
    }
    if (!presentacion) {
        faltantes.push('Seleccionar la <strong>Presentación</strong>.');
    }
    if (!placa) {
        faltantes.push('Ingresar el número de <strong>Placa</strong> del vehículo.');
    }

    if (chkUnica && chkUnica.checked) {
        const tipoReg = document.getElementById('tipo_registro_hidden')?.value || 'BRUTO';
        const pesoComp = parseFloat(document.getElementById('peso_comp_unica')?.value || '0');
        const bruto = (tipoReg === 'BRUTO') ? pesoFijado : pesoComp;
        const taraV = (tipoReg === 'BRUTO') ? pesoComp : pesoFijado;
        if (pesoFijado > 0 && bruto <= taraV) {
            faltantes.push('En Pesada Única, el Peso Bruto debe ser mayor que la Tara del vehículo/recipiente.');
        }
        if (tipoOp === 'VENTA') {
            const selMotivo = document.getElementById('motivo_traslado_unica');
            const inpOtro = document.getElementById('motivo_traslado_otro_unica');
            if (selMotivo && selMotivo.value === 'Otros (especificar)' && inpOtro && inpOtro.value.trim() === '') {
                faltantes.push('Especificar el motivo de traslado ("Otros (especificar)") en la Guía de Remisión.');
            }
        }
    }

    return faltantes;
}

document.addEventListener('DOMContentLoaded', () => {
    const formP = document.getElementById('formPrimeraPesada');
    if (formP) {
        formP.addEventListener('submit', (e) => {
            const faltantes = obtenerFaltantesPrimeraPesada();
            if (faltantes.length > 0) {
                e.preventDefault();
                mostrarAvisoPrimera(faltantes);
            } else {
                ocultarAvisoPrimera();
            }
        });
    }
});

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
            mostrarAvisoPrimera('Por favor ingresa un peso manual válido mayor a 0 lb antes de fijar.');
            inpManual.focus();
            return;
        }
    } else {
        pesoAFijar = pesoEnVivo;
        if (pesoAFijar <= 0) {
            mostrarAvisoPrimera('No hay una lectura válida (&gt; 0 lb) en el indicador. Verifica la báscula o cambia a modo Manual.');
            return;
        }
    }

    document.getElementById('inputPesoFijado').value = pesoAFijar;
    document.getElementById('valorPesoFijado').innerText = pesoAFijar.toLocaleString('en-US');

    ocultarAvisoPrimera();
    recalcularPesadaUnica();

    if (window.innerWidth <= 768) {
        const cajaFijado = document.getElementById('cajaPesoFijadoPrimera');
        if (cajaFijado) {
            setTimeout(() => cajaFijado.scrollIntoView({ behavior: 'smooth', block: 'start' }), 180);
        }
    }
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
    if (typeof select._comboSync === 'function') select._comboSync();
    actualizarSugerenciaUbicacion(tipoOp);
}

/**
 * Convierte un <select> en un combo con búsqueda instantánea en Vanilla JS
 * conservando intacto el <select> oculto para envío POST y eventos existentes.
 */
function inicializarBuscadorSelect(selectId, placeholderTexto) {
    const select = document.getElementById(selectId);
    if (!select || select.dataset.buscadorListo) return;
    select.dataset.buscadorListo = '1';
    select.style.display = 'none';

    const wrap = document.createElement('div');
    wrap.style.position = 'relative';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control';
    input.placeholder = placeholderTexto;
    input.autocomplete = 'off';
    input.style.cssText = 'height: 38px; min-height: 38px; border-radius: 6px; font-size: 0.88rem; padding: 0.35rem 2.1rem 0.35rem 0.75rem;';

    const btnLimpiar = document.createElement('button');
    btnLimpiar.type = 'button';
    btnLimpiar.innerHTML = '&times;';
    btnLimpiar.title = 'Limpiar selección';
    btnLimpiar.style.cssText = 'display: none; position: absolute; right: 6px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: #64748b; font-size: 1.15rem; cursor: pointer; padding: 0 4px; line-height: 1;';

    const lista = document.createElement('div');
    lista.style.cssText = 'display: none; position: absolute; left: 0; right: 0; top: calc(100% + 2px); max-height: 230px; overflow-y: auto; background: var(--bg-surface, #ffffff); border: 1px solid var(--border-color, #cbd5e1); border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.16); z-index: 60;';

    wrap.appendChild(input);
    wrap.appendChild(btnLimpiar);
    wrap.appendChild(lista);
    select.parentNode.insertBefore(wrap, select.nextSibling);

    const normalizar = (t) => String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

    function sincronizarTextoDesdeSelect() {
        const opt = select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        if (opt && opt.value) {
            input.value = opt.text.trim();
            btnLimpiar.style.display = 'block';
        } else {
            input.value = '';
            btnLimpiar.style.display = 'none';
        }
    }
    select._comboSync = sincronizarTextoDesdeSelect;

    function renderOpciones(filtroRaw) {
        const filtro = normalizar(filtroRaw);
        lista.innerHTML = '';
        let visibles = 0;

        Array.from(select.options).forEach((opt) => {
            if (!opt.value || opt.style.display === 'none') return;
            const texto = opt.text.trim();
            if (filtro !== '' && !normalizar(texto).includes(filtro)) return;

            const item = document.createElement('div');
            item.textContent = texto;
            const seleccionado = (select.value === opt.value);
            item.style.cssText = 'padding: 0.5rem 0.75rem; font-size: 0.86rem; cursor: pointer; color: var(--text-main); border-bottom: 1px solid var(--border-color, #f1f5f9);' +
                (seleccionado ? 'background: rgba(37, 99, 235, 0.12); font-weight: 700;' : '');

            item.addEventListener('mousedown', (e) => {
                e.preventDefault();
                select.value = opt.value;
                sincronizarTextoDesdeSelect();
                lista.style.display = 'none';
                select.dispatchEvent(new Event('change'));
            });
            item.addEventListener('mouseenter', () => { item.style.background = 'rgba(37, 99, 235, 0.08)'; });
            item.addEventListener('mouseleave', () => { item.style.background = seleccionado ? 'rgba(37, 99, 235, 0.12)' : 'transparent'; });

            lista.appendChild(item);
            visibles++;
        });

        if (visibles === 0) {
            const vacio = document.createElement('div');
            vacio.textContent = 'Sin coincidencias';
            vacio.style.cssText = 'padding: 0.6rem 0.75rem; font-size: 0.84rem; color: var(--text-muted); text-align: center;';
            lista.appendChild(vacio);
        }
        lista.style.display = 'block';
    }

    input.addEventListener('focus', () => {
        input.select();
        renderOpciones('');
    });
    input.addEventListener('input', () => {
        btnLimpiar.style.display = input.value ? 'block' : 'none';
        if (select.value !== '') {
            select.value = '';
            select.dispatchEvent(new Event('change'));
        }
        renderOpciones(input.value);
    });
    input.addEventListener('blur', () => {
        setTimeout(() => {
            lista.style.display = 'none';
            if (select.value === '') input.value = '';
            else sincronizarTextoDesdeSelect();
        }, 160);
    });

    btnLimpiar.addEventListener('click', () => {
        select.value = '';
        input.value = '';
        btnLimpiar.style.display = 'none';
        select.dispatchEvent(new Event('change'));
        input.focus();
    });

    sincronizarTextoDesdeSelect();
}

document.addEventListener('DOMContentLoaded', () => {
    inicializarBuscadorSelect('clie_prov_id', 'Buscar cliente o proveedor...');
    inicializarBuscadorSelect('producto_id', 'Buscar producto...');

    const inpPlaca = document.getElementById('placa');
    const boxPatio = document.getElementById('avisoPlacaEnPatio');
    if (inpPlaca && boxPatio && Array.isArray(PLACAS_ABIERTAS_PATIO) && PLACAS_ABIERTAS_PATIO.length > 0) {
        const normPlaca = (s) => String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
        const verificarPlacaPatio = () => {
            const pBusq = normPlaca(inpPlaca.value);
            if (pBusq.length < 3) {
                boxPatio.style.display = 'none';
                return;
            }
            const coincidencia = PLACAS_ABIERTAS_PATIO.find(item => normPlaca(item.placa) === pBusq);
            if (coincidencia) {
                const corr = coincidencia.tipo_operacion === 'COMPRA' ? coincidencia.id_compra : coincidencia.id_venta;
                const numBol = '#' + String(corr || '').padStart(7, '0');
                const opTxt = coincidencia.tipo_operacion === 'COMPRA' ? 'Compra' : 'Venta';
                const perTxt = coincidencia.persona_nombre ? ' · ' + coincidencia.persona_nombre : '';
                boxPatio.innerHTML = '';
                const spanTxt = document.createElement('span');
                spanTxt.textContent = `⚠️ La placa ${coincidencia.placa} ya tiene abierta en patio la boleta ${numBol} (${opTxt}${perTxt}). ¿Viene de salida? `;
                const linkSeg = document.createElement('a');
                linkSeg.href = 'segunda_pesada.php?id=' + encodeURIComponent(coincidencia.idpesada);
                linkSeg.style.cssText = 'font-weight: 800; text-decoration: underline; margin-left: 0.35rem; color: inherit;';
                linkSeg.textContent = 'Ir a Segunda Pesada para cerrarla →';
                boxPatio.appendChild(spanTxt);
                boxPatio.appendChild(linkSeg);
                boxPatio.style.display = 'block';
            } else {
                boxPatio.style.display = 'none';
            }
        };
        inpPlaca.addEventListener('input', verificarPlacaPatio);
        inpPlaca.addEventListener('change', verificarPlacaPatio);
    }
});

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

    const selProd = document.getElementById('producto_id');
    const nombreProd = (selProd && selProd.selectedIndex >= 0) ? selProd.options[selProd.selectedIndex].text.toUpperCase() : '';

    if (filtradas.length === 1) {
        selPres.value = filtradas[0].id;
    } else if (nombreProd.includes('CAFE')) {
        const optSacos = filtradas.find(p => String(p.nombre || '').toUpperCase().includes('SACOS'));
        if (optSacos) selPres.value = optSacos.id;
    } else if (nombreProd.includes('ACEITE')) {
        const optGranel = filtradas.find(p => String(p.nombre || '').toUpperCase().includes('GRANEL'));
        if (optGranel) selPres.value = optGranel.id;
    }

    // Sugerir Pesada Única automáticamente cuando el producto seleccionado sea ACEITE
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
