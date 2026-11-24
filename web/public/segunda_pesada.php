<?php
/**
 * Módulo de Segunda Pesada (Cierre de Pesaje y Descuentos)
 * Proyecto: tytBasculaAntigravity
 *
 * Flujo:
 * 1. Paso 1: Seleccionar pesada pendiente de la lista / búsqueda (Placa, Boleta, Cliente/Proveedor).
 * 2. Paso 2: Capturar el peso complementario (Bruto o Tara), descontar empaques (t_taras)
 *    y registrar descuentos (humedad y calidad/defectos) para cerrar la nota.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
if (tieneRol($usuarioActual, ['REMOTO'])) {
    http_response_code(403);
    die('Acceso de solo consulta: el usuario REMOTO no puede cerrar pesajes.');
}
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombre = $usuarioActual['empresa_nombre'] ?? 'BÁSCULA INDUSTRIAL';
$pdo = obtenerDB();

$mensajeExito = '';
$error = '';
$pesadaCerrada = null;

// 1. PROCESAR CIERRE DE SEGUNDA PESADA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'cerrar_pesada') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $idPesada       = (int)($_POST['idpesada'] ?? 0);
        $modoCaptura    = trim((string)($_POST['modo_captura'] ?? 'INDICADOR'));
        $pesoSegunda    = (float)($_POST['peso_fijado'] ?? 0);
        $idEmpaque      = (int)($_POST['idempaque'] ?? 0);
        $cantEmpaques   = (int)($_POST['cantidad_empaques'] ?? 0);
        $pctHumedad     = (float)($_POST['ajuste_humedad_pct'] ?? 0);
        $pctDefectos    = (float)($_POST['descuento_calidad_pct'] ?? 0);
        $obsSegunda     = trim((string)($_POST['observaciones_segunda'] ?? ''));
        $guiaRemision   = trim((string)($_POST['guia_remision'] ?? ''));
        $motivoTraslado = trim((string)($_POST['motivo_traslado'] ?? ''));
        $motivoOtro     = trim((string)($_POST['motivo_traslado_otro'] ?? ''));
        $transpRtn      = trim((string)($_POST['transportista_rtn'] ?? ''));
        $marcaVehiculo  = trim((string)($_POST['marca_vehiculo'] ?? ''));

        // Cargar datos de la primera pesada
        $stmtP = $pdo->prepare("SELECT * FROM t_pesadas WHERE idpesada = :id AND empresa_id = :empresa_id AND estado = 'ABIERTA' LIMIT 1");
        $stmtP->execute([':id' => $idPesada, ':empresa_id' => $empresaId]);
        $nota = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$nota) {
            $error = 'La nota seleccionada no está abierta o no existe.';
        } elseif ($pesoSegunda <= 0) {
            $error = 'Debes fijar un peso complementario válido mayor a cero.';
        } else {
            try {
                $pdo->beginTransaction();

                $capturadoPor = (int)$usuarioActual['id'];
                $origenCaptura = ($modoCaptura === 'MANUAL') ? 'MANUAL' : 'INDICADOR';

                // Determinar Bruto y Tara según tipo_primera_pesada
                if ($nota['tipo_primera_pesada'] === 'BRUTO') {
                    // Si en la 1ra pesada se pesó BRUTO (caso habitual en COMPRA):
                    // En la 2da pesada OBLIGATORIAMENTE se pesa la TARA DEL VEHÍCULO VACÍO.
                    $pesoBruto = (float)$nota['peso_bruto_lb'];
                    $taraVehiculo = $pesoSegunda;
                    $origenTara = $origenCaptura;
                    $taraPor = $capturadoPor;
                    $origenBruto = $nota['origen_bruto'];
                    $brutoPor = $nota['bruto_capturado_por'];

                    if ($taraVehiculo >= $pesoBruto) {
                        throw new RuntimeException("Error de validación en COMPRA: La tara del camión (" . number_format($taraVehiculo, 0) . " lb) no puede ser mayor o igual al peso bruto inicial (" . number_format($pesoBruto, 0) . " lb).");
                    }
                } else {
                    // Si en la 1ra pesada se pesó TARA (caso habitual en VENTA):
                    // En la 2da pesada OBLIGATORIAMENTE se pesa el PESO BRUTO CON CARGA.
                    $pesoBruto = $pesoSegunda;
                    $taraVehiculo = (float)$nota['tara_vehiculo_lb'];
                    $origenBruto = $origenCaptura;
                    $brutoPor = $capturadoPor;
                    $origenTara = $nota['origen_tara'];
                    $taraPor = $nota['tara_capturada_por'];

                    if ($pesoBruto <= $taraVehiculo) {
                        throw new RuntimeException("Error de validación en VENTA: El peso bruto con carga (" . number_format($pesoBruto, 0) . " lb) debe ser estrictamente mayor a la tara del camión vacío (" . number_format($taraVehiculo, 0) . " lb).");
                    }
                }

                $pesoNetoSinDescuentos = $pesoBruto - $taraVehiculo;

                // Descuento de Tara de Empaques (Sacos)
                $taraUnitaria = 0.0;
                $taraTotalEmpaques = 0.0;
                $tieneEmpaque = false;
                if ($idEmpaque > 0 && $cantEmpaques > 0) {
                    $stmtTara = $pdo->prepare("SELECT taralbs FROM t_taras WHERE idempaque = :id LIMIT 1");
                    $stmtTara->execute([':id' => $idEmpaque]);
                    $rowTara = $stmtTara->fetch(PDO::FETCH_ASSOC);
                    if ($rowTara) {
                        $tieneEmpaque = true;
                        $taraUnitaria = (float)$rowTara['taralbs'];
                        $taraTotalEmpaques = ceil(round($taraUnitaria * $cantEmpaques, 4)); // Redondeo hacia arriba
                    }
                }

                // Base de descuentos (Neto sin sacos)
                $baseDescuentos = max(0.0, $pesoNetoSinDescuentos - $taraTotalEmpaques);

                // Descuentos por porcentaje (redondeo hacia arriba)
                $descHumedadLb = ceil(round($baseDescuentos * ($pctHumedad / 100.0), 4));
                $descDefectosLb = ceil(round($baseDescuentos * ($pctDefectos / 100.0), 4));
                $totalDescuentosLb = $descHumedadLb + $descDefectosLb;

                $pesoFinalLb = max(0.0, $baseDescuentos - $totalDescuentosLb);

                // Actualizar y cerrar la pesada
                $sqlUpd = "UPDATE t_pesadas SET
                            estado = 'CERRADA',
                            segunda_pesada_at = NOW(),
                            salida_camion_at = NOW(),
                            emitida_at = NOW(),
                            emitida_por = :emitida_por,
                            peso_bruto_lb = :peso_bruto,
                            bruto_capturado_por = :bruto_por,
                            origen_bruto = :origen_bruto,
                            tara_vehiculo_lb = :tara_vehiculo,
                            tara_capturada_por = :tara_por,
                            origen_tara = :origen_tara,
                            idempaque = :idempaque,
                            cantidad_empaques = :cant_empaques,
                            tara_unitaria_aplicada_lb = :tara_unitaria,
                            base_descuentos_lb = :base_descuentos,
                            ajuste_humedad_pct = :pct_humedad,
                            descuento_humedad_lb = :desc_humedad_lb,
                            descuento_calidad_pct = :pct_calidad,
                            descuento_defectos_lb = :desc_defectos_lb,
                            peso_final_lb = :peso_final_lb,
                            calculo_libras_enteras = 1,
                            observaciones_segunda = :obs_segunda,
                            guia_remision = :guia_remision,
                            motivo_traslado = :motivo_traslado,
                            motivo_traslado_otro = :motivo_traslado_otro,
                            transportista_rtn = :transportista_rtn,
                            marca_vehiculo = :marca_vehiculo
                           WHERE idpesada = :id AND empresa_id = :empresa_id";

                $stmtUpd = $pdo->prepare($sqlUpd);
                $stmtUpd->execute([
                    ':emitida_por'          => $capturadoPor,
                    ':peso_bruto'           => $pesoBruto,
                    ':bruto_por'            => $brutoPor,
                    ':origen_bruto'         => $origenBruto,
                    ':tara_vehiculo'        => $taraVehiculo,
                    ':tara_por'             => $taraPor,
                    ':origen_tara'          => $origenTara,
                    ':idempaque'            => ($tieneEmpaque ? $idEmpaque : null),
                    ':cant_empaques'        => ($tieneEmpaque ? $cantEmpaques : 0),
                    ':tara_unitaria'        => ($tieneEmpaque ? $taraUnitaria : null),
                    ':base_descuentos'      => $baseDescuentos,
                    ':pct_humedad'          => $pctHumedad,
                    ':desc_humedad_lb'      => $descHumedadLb,
                    ':pct_calidad'          => $pctDefectos,
                    ':desc_defectos_lb'     => $descDefectosLb,
                    ':peso_final_lb'        => $pesoFinalLb,
                    ':obs_segunda'          => ($obsSegunda !== '' ? $obsSegunda : null),
                    ':guia_remision'        => ($guiaRemision !== '' ? $guiaRemision : null),
                    ':motivo_traslado'      => ($motivoTraslado !== '' ? $motivoTraslado : null),
                    ':motivo_traslado_otro' => ($motivoOtro !== '' ? $motivoOtro : null),
                    ':transportista_rtn'    => ($transpRtn !== '' ? $transpRtn : null),
                    ':marca_vehiculo'       => ($marcaVehiculo !== '' ? $marcaVehiculo : null),
                    ':id'                   => $idPesada,
                    ':empresa_id'           => $empresaId
                ]);

                $pdo->commit();

                require_once dirname(__DIR__) . '/src/sync_nube.php';
                sincronizarPesadaAutomatica((int)$idPesada);

                // Cargar datos de la boleta cerrada para confirmación
                $stmtCerrada = $pdo->prepare("
                    SELECT p.*, cp.nombre AS persona_nombre, pr.nombre AS presentacion_nombre, 
                           prod.nombre AS producto_nombre, u.nombre AS operador_nombre
                    FROM t_pesadas p
                    JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
                    JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
                    JOIN t_productos prod ON prod.id = pr.producto_id
                    JOIN t_usuarios u ON u.id = p.emitida_por
                    WHERE p.idpesada = :id
                ");
                $stmtCerrada->execute([':id' => $idPesada]);
                $pesadaCerrada = $stmtCerrada->fetch(PDO::FETCH_ASSOC);

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error al cerrar la segunda pesada: ' . $e->getMessage();
            }
        }
    }
}

// 2. PESADA SELECCIONADA PARA CERRAR (SI SE PASÓ POR ?id=...)
$idSeleccionada = (int)($_GET['id'] ?? 0);
$pesadaActiva = null;

if ($idSeleccionada > 0 && !$pesadaCerrada) {
    $stmtActiva = $pdo->prepare("
        SELECT p.*, cp.nombre AS persona_nombre, pr.nombre AS presentacion_nombre, 
               prod.nombre AS producto_nombre, u.nombre AS responsable_nombre
        FROM t_pesadas p
        JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
        JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
        JOIN t_productos prod ON prod.id = pr.producto_id
        JOIN t_usuarios u ON u.id = p.responsable_id
        WHERE p.idpesada = :id AND p.empresa_id = :empresa_id AND p.estado = 'ABIERTA'
        LIMIT 1
    ");
    $stmtActiva->execute([':id' => $idSeleccionada, ':empresa_id' => $empresaId]);
    $pesadaActiva = $stmtActiva->fetch(PDO::FETCH_ASSOC);
}

// 3. CONSULTA DE PESADAS PENDIENTES (PASO 1)
$filtroQ = trim((string)($_GET['q'] ?? ''));
$sqlPendientes = "
    SELECT p.idpesada, p.tipo_operacion, p.id_compra, p.id_venta, p.placa, p.motorista,
           p.tipo_primera_pesada, p.peso_bruto_lb, p.tara_vehiculo_lb, p.primera_pesada_at,
           cp.nombre AS persona_nombre, prod.nombre AS producto_nombre, pr.nombre AS presentacion_nombre
    FROM t_pesadas p
    JOIN t_clie_prov cp ON cp.id = p.clie_prov_id
    JOIN t_producto_presentaciones pr ON pr.id = p.presentacion_id
    JOIN t_productos prod ON prod.id = pr.producto_id
    WHERE p.empresa_id = :empresa_id AND p.estado = 'ABIERTA'
";
$paramsPend = [':empresa_id' => $empresaId];

if ($filtroQ !== '') {
    $sqlPendientes .= " AND (p.placa LIKE :q OR cp.nombre LIKE :q OR prod.nombre LIKE :q)";
    $paramsPend[':q'] = '%' . $filtroQ . '%';
}

$sqlPendientes .= " ORDER BY p.idpesada DESC";
$stmtPend = $pdo->prepare($sqlPendientes);
$stmtPend->execute($paramsPend);
$pesadasPendientes = $stmtPend->fetchAll(PDO::FETCH_ASSOC);

// Cargar catálogo de taras de empaque (t_taras)
$tarasEmpaque = $pdo->query("SELECT idempaque, nombre, taralbs FROM t_taras ORDER BY taralbs ASC")->fetchAll(PDO::FETCH_ASSOC);

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Segunda Pesada', 'segunda');
?>

<div style="max-width: 1040px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div class="cockpit-page-header" style="margin-bottom: 1.5rem;">
        <span class="cockpit-eyebrow" style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
            PESAJE
        </span>
        <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.2rem 0 0.2rem 0; color: var(--text-main); line-height: 1.1;">
            Segunda pesada
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
         VISTA 3: CONFIRMACIÓN DE PESADA CERRADA
         ======================================================================= -->
    <?php if ($pesadaCerrada): ?>
        <div class="card" style="padding: 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            <div style="background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1.75rem; font-weight: 600; font-size: 1rem;">
                ✅ Segunda pesada completada y nota cerrada con éxito.
            </div>

            <div style="margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.6rem; font-weight: 800; margin: 0 0 0.5rem 0; color: var(--text-main);">
                    <?= ($pesadaCerrada['tipo_operacion'] === 'COMPRA' ? 'Compra #' . $pesadaCerrada['id_compra'] : 'Venta #' . $pesadaCerrada['id_venta']) ?>
                </h2>
                <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.85rem;">
                    <?= htmlspecialchars($pesadaCerrada['persona_nombre'], ENT_QUOTES, 'UTF-8') ?> &middot; 
                    <?= htmlspecialchars($pesadaCerrada['producto_nombre'], ENT_QUOTES, 'UTF-8') ?> &middot; 
                    <?= htmlspecialchars($pesadaCerrada['presentacion_nombre'], ENT_QUOTES, 'UTF-8') ?>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; background: #f8fafc; padding: 1.25rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div>
                        <span style="font-size: 0.78rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Peso Bruto</span>
                        <div style="font-size: 1.25rem; font-weight: 800; color: var(--text-main);"><?= number_format((float)$pesadaCerrada['peso_bruto_lb'], 0) ?> lb</div>
                    </div>
                    <div>
                        <span style="font-size: 0.78rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Tara Vehículo</span>
                        <div style="font-size: 1.25rem; font-weight: 800; color: var(--text-main);"><?= number_format((float)$pesadaCerrada['tara_vehiculo_lb'], 0) ?> lb</div>
                    </div>
                    <div>
                        <span style="font-size: 0.78rem; color: #1e40af; font-weight: 700; text-transform: uppercase;">Peso Final Liquidado</span>
                        <div style="font-size: 1.4rem; font-weight: 900; color: #1e40af;"><?= number_format((float)$pesadaCerrada['peso_final_lb'], 0) ?> lb</div>
                        <small style="color: #64748b; font-weight: 600;"><?= number_format((float)$pesadaCerrada['peso_final_lb'] / 100.0, 2) ?> QQ</small>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap;">
                <a href="boleta.php?id=<?= $pesadaCerrada['idpesada'] ?>" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-weight: 700; background: #0b57d0; border-radius: 6px;" target="_blank">
                    🖨️ Imprimir / Ver Boleta
                </a>
                <a href="segunda_pesada.php" class="btn btn-secondary" style="padding: 0.75rem 1.25rem; font-weight: 600; text-decoration: none;">
                    Atender otra segunda pesada
                </a>
                <a href="index.php" style="color: #1e40af; font-size: 0.95rem; font-weight: 600; text-decoration: none;">
                    Volver al inicio
                </a>
            </div>
        </div>

    <!-- =======================================================================
         VISTA 2: FORMULARIO DE CIERRE PARA LA PESADA SELECCIONADA
         ======================================================================= -->
    <?php elseif ($pesadaActiva): 
        $tipoFaltante = ($pesadaActiva['tipo_primera_pesada'] === 'BRUTO') ? 'TARA' : 'BRUTO';
        $primerPeso = (float)($pesadaActiva['peso_bruto_lb'] ?? $pesadaActiva['tara_vehiculo_lb']);
    ?>
        <div style="margin-bottom: 0.65rem;">
            <a href="segunda_pesada.php" style="color: #1e40af; font-size: 0.88rem; font-weight: 600; text-decoration: none;">
                &larr; Volver a la lista de pendientes
            </a>
        </div>

        <form method="POST" action="segunda_pesada.php" id="formSegundaPesada" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" value="cerrar_pesada">
            <input type="hidden" name="idpesada" value="<?= (int)$pesadaActiva['idpesada'] ?>">
            <input type="hidden" name="peso_fijado" id="inputPesoFijado" value="0">
            <input type="hidden" id="primerPesoVal" value="<?= $primerPeso ?>">
            <input type="hidden" id="tipoPrimeraPesada" value="<?= $pesadaActiva['tipo_primera_pesada'] ?>">

            <!-- DETALLE DE LA NOTA ABIERTA (REDISTRIBUIDO HORIZONTALMENTE) -->
            <div class="card cockpit-nota-banner" style="padding: 0.85rem 1.35rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 0.85rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: baseline; gap: 0.85rem; flex-wrap: wrap;">
                    <span style="font-size: 0.82rem; font-weight: 800; color: #1e40af; background: #dbeafe; padding: 0.25rem 0.65rem; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                        <?= ($pesadaActiva['tipo_operacion'] === 'COMPRA' ? 'COMPRA #' . $pesadaActiva['id_compra'] : 'VENTA #' . $pesadaActiva['id_venta']) ?>
                    </span>
                    <h2 style="font-size: 1.5rem; font-weight: 900; margin: 0; color: var(--text-main); line-height: 1.1;">
                        <?= htmlspecialchars((string)($pesadaActiva['persona_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    </h2>
                </div>
                <div style="display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; font-size: 0.96rem; font-weight: 600; color: var(--text-muted);">
                    <span>Producto: <strong style="color: var(--text-main);"><?= htmlspecialchars((string)($pesadaActiva['producto_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>&middot;</span>
                    <span>Presentación: <strong style="color: var(--text-main);"><?= htmlspecialchars((string)($pesadaActiva['presentacion_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>&middot;</span>
                    <span>Placa: <strong style="color: var(--text-main); font-size: 1.05rem;"><?= htmlspecialchars((string)($pesadaActiva['placa'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                </div>
            </div>

            <!-- FILA 2: CAPTURA COMPLEMENTARIA + INDICADOR EN VIVO (MISMA ALTURA) -->
            <div class="cockpit-grid-2" style="display: grid; grid-template-columns: 1.1fr 1fr; gap: 1rem; align-items: stretch; margin-bottom: 0.85rem;">
                <!-- IZQUIERDA: CAPTURA COMPLEMENTARIA -->
                <div class="card cockpit-captura-panel" style="padding: 1rem 1.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin: 0; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem; gap: 0.5rem; flex-wrap: wrap;">
                            <h3 style="font-size: 1.02rem; font-weight: 800; margin: 0; color: var(--text-main);">
                                Captura complementaria
                            </h3>
                            <span style="font-size: 0.74rem; font-weight: 800; padding: 0.2rem 0.55rem; border-radius: 6px; background: <?= ($tipoFaltante === 'TARA' ? '#fef3c7; color: #92400e;' : '#dbeafe; color: #1e40af;') ?>">
                                <?= ($tipoFaltante === 'TARA' ? '🚛 PESAJE DE TARA (SALIDA VACÍO)' : '📦 PESAJE DE BRUTO (SALIDA CARGADO)') ?>
                            </span>
                        </div>
                        <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0 0 0.65rem 0;">
                            <?= ($tipoFaltante === 'TARA' 
                                ? 'En esta <strong>COMPRA</strong>, el camión ya descargó y se debe capturar su peso tara vacío (menor a ' . number_format($primerPeso, 0) . ' lb).'
                                : 'En esta <strong>VENTA</strong>, el camión ya fue cargado y se debe capturar su peso bruto total (mayor a ' . number_format($primerPeso, 0) . ' lb).') ?>
                        </p>

                        <div class="cockpit-captura-row" style="display: grid; grid-template-columns: 1fr auto; gap: 0.75rem; align-items: end; margin-bottom: 0.65rem;">
                            <div>
                                <label for="modo_captura" class="form-label" style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem;">
                                    Modo de captura
                                </label>
                                <select id="modo_captura" name="modo_captura" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.9rem;" onchange="alternarModoCaptura(this.value)">
                                    <option value="INDICADOR" selected>Indicador en vivo</option>
                                    <option value="MANUAL">Manual</option>
                                </select>
                            </div>
                            <button type="button" id="btnFijarPeso" class="btn btn-primary" onclick="fijarSegundoPeso()" style="background: #2563eb; height: 38px; padding: 0 1.1rem; font-weight: 700; border-radius: 6px; font-size: 0.88rem; white-space: nowrap;">
                                Fijar peso complementario
                            </button>
                        </div>

                        <div id="campoPesoManual" style="display: none; margin-bottom: 0.65rem;">
                            <label for="input_manual" class="form-label" style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem;">
                                Ingresar peso manual (lb)
                            </label>
                            <input type="number" id="input_manual" class="form-control" style="height: 38px; border-radius: 6px;" placeholder="0" min="1" step="1">
                        </div>
                    </div>

                    <!-- DISPLAYS APILADOS: SEGUNDO PESO FIJADO (BURDEOS) + PRIMERA PESADA (PIZARRA CÁLIDA) -->
                    <div id="cajaPesoFijadoSegunda" style="scroll-margin-top: 68px; display: flex; flex-direction: column; gap: 0.5rem;">
                        <div style="background: #701a2b; color: #ffffff; border-radius: 8px; padding: 0.65rem 1.15rem; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.8px; text-transform: uppercase; opacity: 0.92;">
                                <?= ($tipoFaltante === 'TARA' ? 'TARA FIJADA' : 'PESO BRUTO FIJADO') ?>
                            </span>
                            <div style="font-size: 1.75rem; font-weight: 900; line-height: 1; display: flex; align-items: baseline; gap: 0.35rem;">
                                <span id="valorSegundoPeso">--</span>
                                <span style="font-size: 0.95rem; font-weight: 600; opacity: 0.85;">lb</span>
                            </div>
                        </div>

                        <div style="background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); border: 1px solid #cbd5e1; color: #0f172a; border-radius: 8px; padding: 0.65rem 1.15rem; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; font-weight: 800; letter-spacing: 0.8px; text-transform: uppercase; color: #475569;">
                                PRIMERA PESADA (<?= $pesadaActiva['tipo_primera_pesada'] ?>)
                            </span>
                            <div style="font-size: 1.75rem; font-weight: 900; line-height: 1; display: flex; align-items: baseline; gap: 0.35rem; color: #1e293b;">
                                <span><?= number_format($primerPeso, 0) ?></span>
                                <span style="font-size: 0.95rem; font-weight: 700; color: #475569;">lb</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DERECHA: INDICADOR AZUL MARINO EN VIVO -->
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

            <!-- FILA 3: TARA DE EMPAQUE, DESCUENTOS DETALLADOS, OBSERVACIONES Y CIERRE -->
            <div id="seccionLiquidacionSegunda" class="card" style="padding: 1.1rem 1.35rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.25rem;">
                <h3 style="font-size: 1.02rem; font-weight: 800; margin: 0 0 0.75rem 0; color: var(--text-main);">
                    Tara de empaque y descuentos
                </h3>

                <!-- 4 CONTROLES EN UNA SOLA FILA BALANCEADA -->
                <div class="cockpit-grid-4" style="display: grid; grid-template-columns: 1.2fr 0.9fr 0.95fr 0.95fr; gap: 0.85rem; margin-bottom: 0.9rem;">
                    <div>
                        <label for="idempaque" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem;">
                            Empaque o saco
                        </label>
                        <?php
                        $taraSugerida = (($pesadaActiva['tipo_operacion'] ?? '') === 'VENTA') ? 0.25 : 0.50;
                        $empaqueYaSugerido = false;
                        ?>
                        <select id="idempaque" name="idempaque" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" onchange="calcularTotales()">
                            <option value="0" data-peso="0">Sin empaques (A granel)</option>
                            <?php foreach ($tarasEmpaque as $t):
                                $seleccionarEmpaque = (!$empaqueYaSugerido && abs((float)$t['taralbs'] - $taraSugerida) < 0.0001);
                                if ($seleccionarEmpaque) {
                                    $empaqueYaSugerido = true;
                                }
                            ?>
                                <option value="<?= (int)$t['idempaque'] ?>" data-peso="<?= (float)$t['taralbs'] ?>"<?= $seleccionarEmpaque ? ' selected' : '' ?>>
                                    <?= htmlspecialchars($t['nombre'], ENT_QUOTES, 'UTF-8') ?> (<?= number_format((float)$t['taralbs'], 3) ?> lb)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="cantidad_empaques" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem;">
                            Cantidad de sacos / empaques
                        </label>
                        <input type="number" min="0" step="1" id="cantidad_empaques" name="cantidad_empaques" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" value="0" oninput="calcularTotales()">
                    </div>
                    <div>
                        <label for="ajuste_humedad_pct" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem;">
                            Descuento humedad (%)
                        </label>
                        <input type="number" min="0" max="100" step="0.1" id="ajuste_humedad_pct" name="ajuste_humedad_pct" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" value="0" oninput="calcularTotales()">
                    </div>
                    <div>
                        <label for="descuento_calidad_pct" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.25rem;">
                            Descuento calidad / defectos (%)
                        </label>
                        <input type="number" min="0" max="100" step="0.1" id="descuento_calidad_pct" name="descuento_calidad_pct" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" value="0" oninput="calcularTotales()">
                    </div>
                </div>

                <?php if (($notaSeleccionada['tipo_operacion'] ?? '') === 'VENTA'): ?>
                <!-- GUÍA DE REMISIÓN COMPACTA (SOLO VENTAS) -->
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 0.9rem;">
                    <div style="font-size: 0.8rem; font-weight: 800; color: #047857; margin-bottom: 0.5rem; text-transform: uppercase;">
                        Guía de Remisión (Venta)
                    </div>
                    <div class="cockpit-grid-4" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;">
                        <div>
                            <label for="guia_remision" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                                N° Guía de Remisión
                            </label>
                            <input type="text" id="guia_remision" name="guia_remision" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="30" placeholder="Ej. 002861">
                        </div>
                        <div>
                            <label for="motivo_traslado" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                                Motivo de Traslado
                            </label>
                            <select id="motivo_traslado" name="motivo_traslado" class="form-control" style="height: 34px; font-size: 0.85rem;" onchange="document.getElementById('boxMotivoOtro').style.display = (this.value === 'Otros (especificar)') ? 'block' : 'none';">
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
                            <label for="marca_vehiculo" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                                Marca del Vehículo
                            </label>
                            <input type="text" id="marca_vehiculo" name="marca_vehiculo" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="60" placeholder="Ej. Freightliner">
                        </div>
                        <div>
                            <label for="transportista_rtn" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                                R.T.N. Transportista
                            </label>
                            <input type="text" id="transportista_rtn" name="transportista_rtn" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="20" placeholder="Opcional">
                        </div>
                        <div id="boxMotivoOtro" style="display: none; grid-column: 1 / -1;">
                            <label for="motivo_traslado_otro" class="form-label" style="font-size: 0.74rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                                Especificar otro motivo
                            </label>
                            <input type="text" id="motivo_traslado_otro" name="motivo_traslado_otro" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="120" placeholder="Detalle del motivo">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div id="avisoPesoInvalido" style="display: none; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 8px; padding: 0.6rem 0.85rem; margin-bottom: 0.75rem; font-size: 0.84rem; font-weight: 600;"></div>

                <!-- BLOQUE INFERIOR BALANCEADO: LIQUIDACIÓN DETALLADA (IZQUIERDA) + OBSERVACIONES (DERECHA) -->
                <div class="cockpit-split-bottom" style="display: grid; grid-template-columns: 1.35fr 0.85fr; gap: 1rem; align-items: stretch; margin-bottom: 0.9rem;">
                    <!-- IZQUIERDA: LIQUIDACIÓN CON CADA DESCUENTO DETALLADO -->
                    <div style="background: #f8fafc; padding: 0.85rem 1.1rem; border-radius: 10px; border: 1px solid #cbd5e1; display: flex; flex-direction: column; justify-content: space-between;">
                        <div class="cockpit-liq-5" style="display: grid; grid-template-columns: repeat(4, 1fr) 1.25fr; gap: 0.6rem; align-items: center; margin-bottom: 0.55rem;">
                            <div>
                                <span style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Neto Inicial</span>
                                <div id="resNetoInicial" style="font-size: 1.1rem; font-weight: 800; color: var(--text-main);">0 lb</div>
                            </div>
                            <div>
                                <span style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Tara Sacos</span>
                                <div id="resTaraSacos" style="font-size: 1.1rem; font-weight: 800; color: #475569;">0 lb</div>
                            </div>
                            <div>
                                <span style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Desc. Humedad</span>
                                <div id="resDescHumedadLb" style="font-size: 1.1rem; font-weight: 800; color: #b91c1c;">0 lb</div>
                                <small id="resDescHumedadPct" style="font-size: 0.7rem; color: #94a3b8; font-weight: 700;">0%</small>
                            </div>
                            <div>
                                <span style="font-size: 0.68rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block;">Desc. Calidad</span>
                                <div id="resDescCalidadLb" style="font-size: 1.1rem; font-weight: 800; color: #b91c1c;">0 lb</div>
                                <small id="resDescCalidadPct" style="font-size: 0.7rem; color: #94a3b8; font-weight: 700;">0%</small>
                            </div>
                            <div class="cockpit-liq-total" style="background: #eff6ff; padding: 0.5rem 0.75rem; border-radius: 8px; border: 1px solid #bfdbfe; text-align: right;">
                                <span style="font-size: 0.68rem; color: #1e40af; font-weight: 800; text-transform: uppercase; display: block;">Peso Final</span>
                                <div id="resPesoFinal" style="font-size: 1.35rem; font-weight: 900; color: #1e40af; line-height: 1.05;">0 lb</div>
                                <small id="resQuintales" style="font-weight: 800; color: #1e3a8a; font-size: 0.82rem;">0.00 QQ</small>
                            </div>
                        </div>
                        <div id="resDesgloseTexto" style="font-size: 0.76rem; color: #64748b; border-top: 1px dashed #cbd5e1; padding-top: 0.45rem; line-height: 1.35;">
                            Fija el peso complementario para ver la liquidación en vivo.
                        </div>
                    </div>

                    <!-- DERECHA: OBSERVACIONES DE SALIDA -->
                    <div style="background: #f8fafc; padding: 0.85rem 1rem; border-radius: 10px; border: 1px solid #cbd5e1; display: flex; flex-direction: column;">
                        <label for="observaciones_segunda" class="form-label" style="font-size: 0.76rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.35rem;">
                            Observaciones de salida
                        </label>
                        <textarea id="observaciones_segunda" name="observaciones_segunda" class="form-control" rows="2" style="font-size: 0.86rem; flex: 1; resize: none;" placeholder="Notas sobre el estado de la carga o cierre"></textarea>
                    </div>
                </div>

                <div id="avisoFaltanteGuardar" style="display: none; margin-bottom: 0.75rem; padding: 0.75rem 0.95rem; border-radius: 8px; background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; font-size: 0.86rem; font-weight: 600;"></div>

                <div class="cockpit-actions" style="display: flex; gap: 1rem; align-items: center;">
                    <button type="submit" id="btnCerrarPesada" class="btn btn-primary" style="background: #0b57d0; padding: 0.65rem 1.65rem; font-weight: 700; border-radius: 6px; font-size: 0.95rem;">
                        Guardar y cerrar nota
                    </button>
                    <a href="segunda_pesada.php" style="color: #64748b; font-size: 0.9rem; font-weight: 600; text-decoration: none;">
                        Cancelar
                    </a>
                </div>
            </div>
        </form>

    <!-- =======================================================================
         VISTA 1: LISTADO DE PESADAS PENDIENTES DE SEGUNDA PESADA (PASO 1)
         ======================================================================= -->
    <?php else: ?>
        <div class="card" style="padding: 2rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h2 style="font-size: 1.35rem; font-weight: 800; margin: 0; color: var(--text-main);">
                        Camiones pendientes en patio
                    </h2>
                    <p style="margin: 0.2rem 0 0 0; font-size: 0.88rem; color: var(--text-muted);">
                        Selecciona el camión o boleta abierta para realizar la segunda pesada.
                    </p>
                </div>

                <form method="GET" action="segunda_pesada.php" style="display: flex; gap: 0.65rem; align-items: center; max-width: 350px;">
                    <input type="text" name="q" class="form-control" placeholder="Buscar por placa o persona..." value="<?= htmlspecialchars($filtroQ, ENT_QUOTES, 'UTF-8') ?>" style="height: 40px; border-radius: 6px;">
                    <button type="submit" class="btn btn-secondary" style="height: 40px; padding: 0 1rem; font-weight: 600; background: #e8f0fe; color: #1e40af; border: none;">
                        Buscar
                    </button>
                </form>
            </div>

            <div class="table-responsive" style="border: none; background: transparent;">
                <table class="table" style="border-collapse: separate; border-spacing: 0; min-width: 100%;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <th style="background: transparent; color: var(--text-main); font-size: 0.9rem; font-weight: 700; padding: 0.85rem 0.5rem;">Boleta</th>
                            <th style="background: transparent; color: var(--text-main); font-size: 0.9rem; font-weight: 700; padding: 0.85rem 0.5rem;">Placa</th>
                            <th style="background: transparent; color: var(--text-main); font-size: 0.9rem; font-weight: 700; padding: 0.85rem 0.5rem;">Cliente / Proveedor</th>
                            <th style="background: transparent; color: var(--text-main); font-size: 0.9rem; font-weight: 700; padding: 0.85rem 0.5rem;">Producto</th>
                            <th style="background: transparent; color: var(--text-main); font-size: 0.9rem; font-weight: 700; padding: 0.85rem 0.5rem;">1ra Captura</th>
                            <th style="background: transparent; color: var(--text-main); font-size: 0.9rem; font-weight: 700; padding: 0.85rem 0.5rem; text-align: right;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pesadasPendientes)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 3.5rem 1rem;">
                                    No hay camiones pendientes de segunda pesada en este momento.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pesadasPendientes as $pend): ?>
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <td style="padding: 1.15rem 0.5rem; font-weight: 800; color: #1e40af;">
                                        <?= ($pend['tipo_operacion'] === 'COMPRA' ? 'Compra #' . $pend['id_compra'] : 'Venta #' . $pend['id_venta']) ?>
                                    </td>
                                    <td style="padding: 1.15rem 0.5rem; font-weight: 800; font-family: monospace; font-size: 1rem; color: var(--text-main);">
                                        <?= htmlspecialchars((string)($pend['placa'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td style="padding: 1.15rem 0.5rem; font-weight: 600; color: var(--text-main);">
                                        <?= htmlspecialchars((string)($pend['persona_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td style="padding: 1.15rem 0.5rem; font-size: 0.92rem; color: var(--text-muted);">
                                        <?= htmlspecialchars((string)($pend['producto_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)($pend['presentacion_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>)
                                    </td>
                                    <td style="padding: 1.15rem 0.5rem; font-weight: 700; color: #701a2b;">
                                        <?= number_format((float)($pend['peso_bruto_lb'] ?? $pend['tara_vehiculo_lb']), 0) ?> lb
                                        <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: normal;">(<?= $pend['tipo_primera_pesada'] ?>)</span>
                                    </td>
                                    <td style="padding: 1.15rem 0.5rem; text-align: right;">
                                        <a href="segunda_pesada.php?id=<?= $pend['idpesada'] ?>" class="btn btn-primary" style="padding: 0.45rem 1rem; font-size: 0.88rem; font-weight: 700; background: #0b57d0; text-decoration: none; border-radius: 5px;">
                                            Completar &rarr;
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
let pesoEnVivo = 0;
let esPesoEstable = true;

// Actualizar dinámicamente lectura en vivo si estamos en formulario de pesaje
function conectarLecturaEnVivo() {
    const elVal = document.getElementById('livePesoValor');
    if (!elVal) return;

    setInterval(async () => {
        try {
            const res = await fetch('api_peso_local.php?accion=peso', { credentials: 'same-origin' });
            if (res.ok) {
                const data = await res.json();
                pesoEnVivo = Math.round(Number(data.peso_lb || 0));
                esPesoEstable = Boolean(data.estable);

                elVal.innerText = pesoEnVivo.toLocaleString('en-US');

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
            // Silencioso
        }
    }, 400);
}

document.addEventListener('DOMContentLoaded', () => {
    conectarLecturaEnVivo();
    calcularTotales();

    const form = document.getElementById('formSegundaPesada');
    if (form) {
        form.addEventListener('submit', (e) => {
            const faltantes = obtenerFaltantesSegundaPesada();
            if (faltantes.length > 0) {
                e.preventDefault();
                mostrarAvisoFaltante(faltantes);
            } else {
                ocultarAvisoFaltante();
            }
        });
    }
});

function mostrarAvisoFaltante(mensajes) {
    const box = document.getElementById('avisoFaltanteGuardar');
    if (!box) return;
    const lista = Array.isArray(mensajes) ? mensajes : [mensajes];
    box.innerHTML = '<strong>No se puede guardar todavía. Falta lo siguiente:</strong><ul style="margin: 0.35rem 0 0 1.2rem; padding: 0;">' +
        lista.map((m) => `<li>${m}</li>`).join('') + '</ul>';
    box.style.display = 'block';
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function ocultarAvisoFaltante() {
    const box = document.getElementById('avisoFaltanteGuardar');
    if (box) box.style.display = 'none';
}

function obtenerFaltantesSegundaPesada() {
    const faltantes = [];
    const primerPeso = parseFloat(document.getElementById('primerPesoVal')?.value || '0');
    const segundoPeso = parseFloat(document.getElementById('inputPesoFijado')?.value || '0');
    const tipoPrimera = document.getElementById('tipoPrimeraPesada')?.value || 'BRUTO';

    if (segundoPeso <= 0) {
        faltantes.push('Fijar el peso complementario (haz clic en <strong>Fijar peso complementario</strong>).');
    } else {
        const bruto = (tipoPrimera === 'BRUTO') ? primerPeso : segundoPeso;
        const tara  = (tipoPrimera === 'BRUTO') ? segundoPeso : primerPeso;
        if (bruto <= tara) {
            faltantes.push(
                tipoPrimera === 'TARA'
                    ? `El peso bruto complementario (${bruto.toLocaleString('en-US')} lb) debe ser mayor que la tara inicial (${tara.toLocaleString('en-US')} lb).`
                    : `La tara del vehículo (${tara.toLocaleString('en-US')} lb) debe ser menor que el peso bruto inicial (${bruto.toLocaleString('en-US')} lb).`
            );
        }
    }

    const selMotivo = document.getElementById('motivo_traslado');
    const inpOtro = document.getElementById('motivo_traslado_otro');
    if (selMotivo && selMotivo.value === 'Otros (especificar)' && inpOtro && inpOtro.value.trim() === '') {
        faltantes.push('Especificar el motivo de traslado ("Otros (especificar)") en la Guía de Remisión.');
    }

    return faltantes;
}

function alternarModoCaptura(modo) {
    const divManual = document.getElementById('campoPesoManual');
    if (divManual) {
        divManual.style.display = (modo === 'MANUAL') ? 'block' : 'none';
    }
}

function fijarSegundoPeso() {
    const modo = document.getElementById('modo_captura').value;
    let peso = 0;

    if (modo === 'MANUAL') {
        const inpManual = document.getElementById('input_manual');
        peso = parseInt(inpManual.value || '0', 10);
        if (peso <= 0) {
            mostrarAvisoFaltante('Ingresa un peso manual válido mayor a 0 lb antes de fijar.');
            inpManual.focus();
            return;
        }
    } else {
        peso = pesoEnVivo;
        if (peso <= 0) {
            mostrarAvisoFaltante('No hay una lectura válida (&gt; 0 lb) en el indicador. Verifica la báscula o cambia a modo Manual.');
            return;
        }
    }

    document.getElementById('inputPesoFijado').value = peso;
    document.getElementById('valorSegundoPeso').innerText = peso.toLocaleString('en-US');

    ocultarAvisoFaltante();
    calcularTotales();

    if (window.innerWidth <= 768) {
        const cajaFijado = document.getElementById('cajaPesoFijadoSegunda');
        if (cajaFijado) {
            setTimeout(() => cajaFijado.scrollIntoView({ behavior: 'smooth', block: 'start' }), 180);
        }
    }
}

function calcularTotales() {
    const elSegundo = document.getElementById('inputPesoFijado');
    if (!elSegundo) return;

    const primerPeso = parseFloat(document.getElementById('primerPesoVal').value || '0');
    const segundoPeso = parseFloat(elSegundo.value || '0');
    const tipoPrimera = document.getElementById('tipoPrimeraPesada').value;

    let bruto = 0;
    let tara = 0;

    if (tipoPrimera === 'BRUTO') {
        bruto = primerPeso;
        tara = segundoPeso;
    } else {
        bruto = segundoPeso;
        tara = primerPeso;
    }

    const resAviso = document.getElementById('avisoPesoInvalido');
    if (segundoPeso > 0 && bruto <= tara) {
        if (resAviso) {
            resAviso.style.display = 'block';
            resAviso.innerText = (tipoPrimera === 'TARA') 
                ? '⚠️ El peso bruto complementario (' + bruto.toLocaleString('en-US') + ' lb) debe ser mayor que la tara inicial (' + tara.toLocaleString('en-US') + ' lb).'
                : '⚠️ La tara del vehículo (' + tara.toLocaleString('en-US') + ' lb) no puede ser mayor o igual al peso bruto (' + bruto.toLocaleString('en-US') + ' lb).';
        }
    } else {
        if (resAviso) resAviso.style.display = 'none';
    }

    const netoInicial = Math.max(0, bruto - tara);
    document.getElementById('resNetoInicial').innerText = netoInicial.toLocaleString('en-US') + ' lb';

    // Tara de sacos
    const selEmpaque = document.getElementById('idempaque');
    let taraUnitaria = 0;
    let empaqueNombre = 'Sin empaque';
    if (selEmpaque && selEmpaque.selectedIndex >= 0) {
        const optEmpaque = selEmpaque.options[selEmpaque.selectedIndex];
        taraUnitaria = parseFloat(optEmpaque.getAttribute('data-peso') || '0');
        empaqueNombre = optEmpaque.text;
    }
    const cantEmpaques = parseInt(document.getElementById('cantidad_empaques').value || '0', 10);
    const taraSacos = Math.ceil(Number((taraUnitaria * cantEmpaques).toFixed(4)));
    document.getElementById('resTaraSacos').innerText = taraSacos.toLocaleString('en-US') + ' lb';

    // Base de descuentos
    const baseDesc = Math.max(0, netoInicial - taraSacos);

    // Descuentos porcentuales detallados (redondeo hacia arriba)
    const pctHum = parseFloat(document.getElementById('ajuste_humedad_pct').value || '0');
    const pctDef = parseFloat(document.getElementById('descuento_calidad_pct').value || '0');
    const descHum = Math.ceil(Number((baseDesc * (pctHum / 100.0)).toFixed(4)));
    const descDef = Math.ceil(Number((baseDesc * (pctDef / 100.0)).toFixed(4)));
    const totalDesc = descHum + descDef;

    const elHumLb = document.getElementById('resDescHumedadLb');
    const elHumPct = document.getElementById('resDescHumedadPct');
    const elCalLb = document.getElementById('resDescCalidadLb');
    const elCalPct = document.getElementById('resDescCalidadPct');
    if (elHumLb) elHumLb.innerText = descHum.toLocaleString('en-US') + ' lb';
    if (elHumPct) elHumPct.innerText = pctHum + '%';
    if (elCalLb) elCalLb.innerText = descDef.toLocaleString('en-US') + ' lb';
    if (elCalPct) elCalPct.innerText = pctDef + '%';

    const pesoFinal = Math.max(0, baseDesc - totalDesc);
    document.getElementById('resPesoFinal').innerText = pesoFinal.toLocaleString('en-US') + ' lb';
    document.getElementById('resQuintales').innerText = (pesoFinal / 100.0).toFixed(2) + ' QQ';

    // Retroalimentación explicativa en texto
    const elTexto = document.getElementById('resDesgloseTexto');
    if (elTexto) {
        if (segundoPeso <= 0) {
            elTexto.innerHTML = 'Fija el peso complementario para ver la liquidación en vivo.';
        } else if (bruto <= tara) {
            elTexto.innerHTML = '<strong style="color: #b91c1c;">No se puede liquidar:</strong> El Peso Bruto debe ser estrictamente mayor a la Tara del vehículo.';
        } else {
            elTexto.innerHTML = `<strong>Desglose:</strong> Bruto (${bruto.toLocaleString()} lb) &minus; Tara (${tara.toLocaleString()} lb) = <strong>Neto Inicial: ${netoInicial.toLocaleString()} lb</strong>. ` +
                (taraSacos > 0 ? `Menos tara empaques (${cantEmpaques} &times; ${taraUnitaria} lb = ${taraSacos.toLocaleString()} lb) &rarr; Base: <strong>${baseDesc.toLocaleString()} lb</strong>. ` : '') +
                (totalDesc > 0 ? `Menos descuentos (Humedad ${pctHum}%: ${descHum.toLocaleString()} lb + Defectos ${pctDef}%: ${descDef.toLocaleString()} lb) &rarr; ` : '') +
                `<strong>Final: ${pesoFinal.toLocaleString()} lb (${(pesoFinal / 100.0).toFixed(2)} QQ)</strong>.`;
        }
    }
}
</script>

<?php
renderizarPie();
