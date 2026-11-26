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
$pesoPrevioFijado = 0;

// 0. CORREGIR / INVERTIR OPERACIÓN (COMPRA <-> VENTA) EN NOTA ABIERTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'invertir_operacion') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $idInvertir    = (int)($_POST['idpesada'] ?? 0);
        $nuevoClieProv = (int)($_POST['nuevo_clie_prov_id'] ?? 0);
        $pesoPrevioFijado = max(0, (int)round((float)($_POST['peso_previo'] ?? 0)));

        if ($idInvertir <= 0 || $nuevoClieProv <= 0) {
            $error = 'Selecciona el Cliente o Proveedor válido antes de aplicar la corrección.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmtNota = $pdo->prepare("
                    SELECT * FROM t_pesadas
                    WHERE idpesada = :id AND empresa_id = :empresa_id AND estado = 'ABIERTA'
                    LIMIT 1 FOR UPDATE
                ");
                $stmtNota->execute([':id' => $idInvertir, ':empresa_id' => $empresaId]);
                $notaAbierta = $stmtNota->fetch(PDO::FETCH_ASSOC);

                if (!$notaAbierta) {
                    throw new RuntimeException('La nota seleccionada ya no está abierta o no existe.');
                }

                $nuevoTipoOp = ($notaAbierta['tipo_operacion'] === 'COMPRA') ? 'VENTA' : 'COMPRA';

                // Validar que la persona pertenezca a la empresa y tenga el rol correspondiente
                $stmtPer = $pdo->prepare("
                    SELECT id, es_cliente, es_proveedor
                    FROM t_clie_prov
                    WHERE id = :id AND empresa_id = :empresa_id AND activo = 1
                    LIMIT 1
                ");
                $stmtPer->execute([':id' => $nuevoClieProv, ':empresa_id' => $empresaId]);
                $perValida = $stmtPer->fetch(PDO::FETCH_ASSOC);

                if (!$perValida) {
                    throw new RuntimeException('El cliente o proveedor seleccionado no es válido.');
                }
                if ($nuevoTipoOp === 'VENTA' && !(int)$perValida['es_cliente']) {
                    throw new RuntimeException('Para cambiar la nota a VENTA debes seleccionar un Cliente.');
                }
                if ($nuevoTipoOp === 'COMPRA' && !(int)$perValida['es_proveedor']) {
                    throw new RuntimeException('Para cambiar la nota a COMPRA debes seleccionar un Proveedor.');
                }

                // Conservar el mismo número correlativo (o siguiente libre si existiera colisión histórica)
                $corrActual = (int)($notaAbierta['id_compra'] ?? $notaAbierta['id_venta']);
                $colDestino = ($nuevoTipoOp === 'COMPRA') ? 'id_compra' : 'id_venta';
                $stmtDup = $pdo->prepare("
                    SELECT COUNT(*) FROM t_pesadas
                    WHERE empresa_id = :empresa_id AND {$colDestino} = :corr AND idpesada <> :id
                ");
                $stmtDup->execute([':empresa_id' => $empresaId, ':corr' => $corrActual, ':id' => $idInvertir]);
                if ((int)$stmtDup->fetchColumn() > 0) {
                    $stmtSig = $pdo->prepare("
                        SELECT GREATEST(418, COALESCE(MAX(id_compra), 0), COALESCE(MAX(id_venta), 0)) + 1
                        FROM t_pesadas WHERE empresa_id = :empresa_id FOR UPDATE
                    ");
                    $stmtSig->execute([':empresa_id' => $empresaId]);
                    $corrActual = (int)$stmtSig->fetchColumn();
                }

                $nuevoIdCompra = ($nuevoTipoOp === 'COMPRA') ? $corrActual : null;
                $nuevoIdVenta  = ($nuevoTipoOp === 'VENTA') ? $corrActual : null;

                // Invertir tipo de primera pesada y mover peso/auditoría inicial (cumpliendo chk_pesadas_capturas y chk_pesadas_origen)
                if ($notaAbierta['tipo_primera_pesada'] === 'BRUTO') {
                    $nuevoTipoPrimera = 'TARA';
                    $nuevoBruto       = null;
                    $nuevoBrutoPor    = null;
                    $nuevoOrigenBruto = null;
                    $nuevaTara        = (float)$notaAbierta['peso_bruto_lb'];
                    $nuevaTaraPor     = (int)$notaAbierta['bruto_capturado_por'];
                    $nuevoOrigenTara  = $notaAbierta['origen_bruto'];
                } else {
                    $nuevoTipoPrimera = 'BRUTO';
                    $nuevoBruto       = (float)$notaAbierta['tara_vehiculo_lb'];
                    $nuevoBrutoPor    = (int)$notaAbierta['tara_capturada_por'];
                    $nuevoOrigenBruto = $notaAbierta['origen_tara'];
                    $nuevaTara        = null;
                    $nuevaTaraPor     = null;
                    $nuevoOrigenTara  = null;
                }

                $stmtInv = $pdo->prepare("
                    UPDATE t_pesadas SET
                        tipo_operacion = :tipo_op,
                        id_compra = :id_compra,
                        id_venta = :id_venta,
                        clie_prov_id = :clie_prov_id,
                        tipo_primera_pesada = :tipo_primera,
                        peso_bruto_lb = :peso_bruto,
                        bruto_capturado_por = :bruto_por,
                        origen_bruto = :origen_bruto,
                        tara_vehiculo_lb = :tara_vehiculo,
                        tara_capturada_por = :tara_por,
                        origen_tara = :origen_tara
                    WHERE idpesada = :id AND empresa_id = :empresa_id AND estado = 'ABIERTA'
                ");
                $stmtInv->execute([
                    ':tipo_op'       => $nuevoTipoOp,
                    ':id_compra'     => $nuevoIdCompra,
                    ':id_venta'      => $nuevoIdVenta,
                    ':clie_prov_id'  => $nuevoClieProv,
                    ':tipo_primera'  => $nuevoTipoPrimera,
                    ':peso_bruto'    => $nuevoBruto,
                    ':bruto_por'     => $nuevoBrutoPor,
                    ':origen_bruto'  => $nuevoOrigenBruto,
                    ':tara_vehiculo' => $nuevaTara,
                    ':tara_por'      => $nuevaTaraPor,
                    ':origen_tara'   => $nuevoOrigenTara,
                    ':id'            => $idInvertir,
                    ':empresa_id'    => $empresaId,
                ]);

                $pdo->commit();

                require_once dirname(__DIR__) . '/src/sync_nube.php';
                sincronizarPesadaAutomatica($idInvertir);

                $_GET['id'] = (string)$idInvertir;
                $mensajeExito = 'Nota corregida a ' . $nuevoTipoOp . ' #' . sprintf('%07d', $corrActual) . ' (' . ($nuevoTipoPrimera === 'TARA' ? 'Entrada registrada como Tara' : 'Entrada registrada como Peso Bruto') . ').';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_GET['id'] = (string)$idInvertir;
                $error = 'No se pudo corregir la operación: ' . $e->getMessage();
            }
        }
    }
}

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
$personasCambioOp = [];

if ($idSeleccionada > 0 && !$pesadaCerrada) {
    $stmtActiva = $pdo->prepare("
        SELECT p.*, cp.nombre AS persona_nombre, cp.es_cliente AS persona_es_cliente, cp.es_proveedor AS persona_es_proveedor,
               pr.nombre AS presentacion_nombre, prod.nombre AS producto_nombre, u.nombre AS responsable_nombre
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

    if ($pesadaActiva) {
        $tipoOpDestino = ($pesadaActiva['tipo_operacion'] === 'COMPRA') ? 'VENTA' : 'COMPRA';
        $condRolPer = ($tipoOpDestino === 'VENTA') ? 'es_cliente = 1' : 'es_proveedor = 1';
        $stmtPerCambio = $pdo->prepare("
            SELECT id, nombre FROM t_clie_prov
            WHERE empresa_id = :empresa_id AND activo = 1 AND {$condRolPer}
            ORDER BY nombre ASC
        ");
        $stmtPerCambio->execute([':empresa_id' => $empresaId]);
        $personasCambioOp = $stmtPerCambio->fetchAll(PDO::FETCH_ASSOC);
    }
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
    <?php if ($pesadaActiva):
        $tipoFaltante = ($pesadaActiva['tipo_primera_pesada'] === 'BRUTO') ? 'TARA' : 'BRUTO';
        $primerPeso = (float)($pesadaActiva['peso_bruto_lb'] ?? $pesadaActiva['tara_vehiculo_lb']);
        $tipoOpInvertido = ($pesadaActiva['tipo_operacion'] === 'COMPRA') ? 'VENTA' : 'COMPRA';
        $corrMostrar = (int)($pesadaActiva['id_compra'] ?? $pesadaActiva['id_venta']);
        $personaActualCompatible = ($tipoOpInvertido === 'VENTA')
            ? ((int)($pesadaActiva['persona_es_cliente'] ?? 0) === 1)
            : ((int)($pesadaActiva['persona_es_proveedor'] ?? 0) === 1);
    ?>
    <div class="cockpit-page-header cockpit-page-header-compact" style="display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.75rem;">
        <div>
            <span class="cockpit-eyebrow" style="font-size: 0.72rem; font-weight: 800; color: #1e40af; letter-spacing: 1.4px; text-transform: uppercase; display: block; line-height: 1.1;">
                PESAJE
            </span>
            <div style="display: flex; align-items: baseline; gap: 0.75rem; flex-wrap: wrap;">
                <h1 style="font-size: 1.85rem; font-weight: 800; margin: 0.1rem 0 0 0; color: var(--text-main); line-height: 1.1;">
                    Segunda pesada
                </h1>
                <span class="cockpit-empresa-sub" style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px;">
                    <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <a href="segunda_pesada.php" style="color: #1e40af; font-size: 0.88rem; font-weight: 600; text-decoration: none;">
                &larr; Volver a la lista de pendientes
            </a>
            <button type="button" onclick="abrirPanelInvertirOperacion()" class="btn btn-secondary" style="padding: 0.35rem 0.8rem; min-height: 32px; font-size: 0.82rem; font-weight: 700; border-radius: 6px;">
                ⇄ ¿Se registró al revés? Cambiar a <?= $tipoOpInvertido ?>
            </button>
        </div>
    </div>
    <?php else: ?>
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
    <?php endif; ?>

    <?php if ($mensajeExito !== ''): ?>
        <div class="alert alert-success" style="margin-bottom: 1rem;">
            <span>✅ <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

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
    <?php elseif ($pesadaActiva): ?>
        <!-- PANEL RÁPIDO PARA CORREGIR COMPRA <-> VENTA SIN PERDER EL CORRELATIVO -->
        <div id="panelInvertirOperacion" class="card" style="display: none; padding: 1rem 1.25rem; margin-bottom: 0.85rem; border-radius: 10px; border: 2px solid #f59e0b; background: var(--bg-surface);">
            <form method="POST" action="segunda_pesada.php?id=<?= (int)$pesadaActiva['idpesada'] ?>" id="formInvertirOperacion" autocomplete="off" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="accion" value="invertir_operacion">
                <input type="hidden" name="idpesada" value="<?= (int)$pesadaActiva['idpesada'] ?>">
                <input type="hidden" name="peso_previo" id="inputPesoPrevioInvertir" value="0">

                <div style="font-size: 0.88rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.35rem;">
                    ⇄ Corregir nota #<?= sprintf('%07d', $corrMostrar) ?> de <?= $pesadaActiva['tipo_operacion'] ?> a <?= $tipoOpInvertido ?>
                </div>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0 0 0.75rem 0; line-height: 1.4;">
                    El primer peso (<strong><?= number_format($primerPeso, 0) ?> lb</strong>) pasará de <strong><?= $pesadaActiva['tipo_primera_pesada'] === 'BRUTO' ? 'Peso Bruto' : 'Tara' ?></strong> a <strong><?= $pesadaActiva['tipo_primera_pesada'] === 'BRUTO' ? 'Tara (camión vacío)' : 'Peso Bruto (camión cargado)' ?></strong> conservando la misma boleta <strong>#<?= sprintf('%07d', $corrMostrar) ?></strong>. Confirma el <?= $tipoOpInvertido === 'VENTA' ? 'Cliente' : 'Proveedor' ?>:
                </p>

                <div style="display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 240px;">
                        <label for="nuevo_clie_prov_id" class="form-label" style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.25rem;">
                            <?= $tipoOpInvertido === 'VENTA' ? 'Cliente para esta Venta' : 'Proveedor para esta Compra' ?> <span style="color: var(--danger);">*</span>
                        </label>
                        <select id="nuevo_clie_prov_id" name="nuevo_clie_prov_id" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" required>
                            <option value="" disabled <?= !$personaActualCompatible ? 'selected' : '' ?>>Selecciona <?= $tipoOpInvertido === 'VENTA' ? 'el cliente' : 'el proveedor' ?>...</option>
                            <?php foreach ($personasCambioOp as $perOp): ?>
                                <option value="<?= (int)$perOp['id'] ?>" <?= ($personaActualCompatible && (int)$perOp['id'] === (int)$pesadaActiva['clie_prov_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($perOp['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="height: 38px; padding: 0 1.15rem; font-weight: 700; background: <?= $tipoOpInvertido === 'VENTA' ? '#047857' : '#1e40af' ?>; border-radius: 6px; font-size: 0.86rem;">
                        Confirmar cambio a <?= $tipoOpInvertido ?>
                    </button>
                    <button type="button" onclick="cerrarPanelInvertirOperacion()" class="btn btn-secondary" style="height: 38px; padding: 0 0.9rem; font-size: 0.85rem; border-radius: 6px;">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>

        <form method="POST" action="segunda_pesada.php" id="formSegundaPesada" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" value="cerrar_pesada">
            <input type="hidden" name="idpesada" value="<?= (int)$pesadaActiva['idpesada'] ?>">
            <input type="hidden" name="peso_fijado" id="inputPesoFijado" value="<?= (int)$pesoPrevioFijado ?>">
            <input type="hidden" id="primerPesoVal" value="<?= $primerPeso ?>">
            <input type="hidden" id="tipoPrimeraPesada" value="<?= $pesadaActiva['tipo_primera_pesada'] ?>">

            <!-- DETALLE DE LA NOTA ABIERTA -->
            <div class="card cockpit-nota-banner" style="padding: 0.7rem 1.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 0.75rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.85rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <span style="font-size: 0.8rem; font-weight: 800; color: #1e40af; background: #dbeafe; padding: 0.22rem 0.65rem; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
                        <?= ($pesadaActiva['tipo_operacion'] === 'COMPRA' ? 'COMPRA #' . $pesadaActiva['id_compra'] : 'VENTA #' . $pesadaActiva['id_venta']) ?>
                    </span>
                    <h2 style="font-size: 1.38rem; font-weight: 900; margin: 0; color: var(--text-main); line-height: 1.1;">
                        <?= htmlspecialchars((string)($pesadaActiva['persona_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                    </h2>
                </div>
                <div style="display: flex; align-items: center; gap: 1.1rem; flex-wrap: wrap; font-size: 0.92rem; font-weight: 600; color: var(--text-muted);">
                    <span>Producto: <strong style="color: var(--text-main);"><?= htmlspecialchars((string)($pesadaActiva['producto_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>&middot;</span>
                    <span>Presentación: <strong style="color: var(--text-main);"><?= htmlspecialchars((string)($pesadaActiva['presentacion_nombre'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                    <span>&middot;</span>
                    <span>Placa: <strong style="color: var(--text-main); font-size: 1rem;"><?= htmlspecialchars((string)($pesadaActiva['placa'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                </div>
            </div>

            <!-- FILA 2: CAPTURA COMPLEMENTARIA + INDICADOR EN VIVO (BALANCEADA) -->
            <div class="cockpit-grid-2" style="display: grid; grid-template-columns: 1.1fr 1fr; gap: 0.9rem; align-items: stretch; margin-bottom: 0.75rem;">
                <!-- IZQUIERDA: CAPTURA COMPLEMENTARIA -->
                <div class="card cockpit-captura-panel" style="padding: 0.85rem 1.15rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin: 0; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem; gap: 0.5rem; flex-wrap: wrap;">
                            <h3 style="font-size: 1.02rem; font-weight: 800; margin: 0; color: var(--text-main);">
                                Captura complementaria
                            </h3>
                            <span style="font-size: 0.73rem; font-weight: 800; padding: 0.2rem 0.55rem; border-radius: 6px; background: <?= ($tipoFaltante === 'TARA' ? '#fef3c7; color: #92400e;' : '#dbeafe; color: #1e40af;') ?>">
                                <?= ($tipoFaltante === 'TARA' ? '🚛 TARA (SALIDA VACÍO) &lt; ' . number_format($primerPeso, 0) . ' lb' : '📦 BRUTO (SALIDA CARGADO) &gt; ' . number_format($primerPeso, 0) . ' lb') ?>
                            </span>
                        </div>

                        <div class="cockpit-captura-row-inline" style="display: flex; gap: 0.65rem; align-items: flex-end; margin-bottom: 0.65rem; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 135px;">
                                <label for="modo_captura" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                                    Modo de captura
                                </label>
                                <select id="modo_captura" name="modo_captura" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" onchange="alternarModoCaptura(this.value)">
                                    <option value="INDICADOR" selected>Indicador en vivo</option>
                                    <option value="MANUAL">Manual</option>
                                </select>
                            </div>
                            <div id="campoPesoManual" style="display: none; flex: 1; min-width: 120px;">
                                <label for="input_manual" class="form-label" style="font-size: 0.76rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                                    Peso manual (lb)
                                </label>
                                <input type="number" id="input_manual" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.9rem;" placeholder="0" min="1" step="1">
                            </div>
                            <button type="button" id="btnFijarPeso" class="btn btn-primary" onclick="fijarSegundoPeso()" style="background: #2563eb; height: 38px; padding: 0 1.05rem; font-weight: 700; border-radius: 6px; font-size: 0.86rem; white-space: nowrap;">
                                Fijar peso complementario
                            </button>
                        </div>
                    </div>

                    <!-- DISPLAYS LADO A LADO: SEGUNDO PESO FIJADO (BURDEOS) + PRIMERA PESADA -->
                    <div id="cajaPesoFijadoSegunda" style="scroll-margin-top: 68px; display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem;">
                        <div style="background: #701a2b; color: #ffffff; border-radius: 8px; padding: 0.55rem 0.9rem; display: flex; justify-content: space-between; align-items: center; gap: 0.45rem;">
                            <span style="font-size: 0.72rem; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; opacity: 0.92;">
                                <?= ($tipoFaltante === 'TARA' ? 'TARA FIJADA' : 'BRUTO FIJADO') ?>
                            </span>
                            <div style="font-size: 1.55rem; font-weight: 900; line-height: 1; display: flex; align-items: baseline; gap: 0.28rem;">
                                <span id="valorSegundoPeso"><?= $pesoPrevioFijado > 0 ? number_format($pesoPrevioFijado, 0) : '--' ?></span>
                                <span style="font-size: 0.85rem; font-weight: 600; opacity: 0.85;">lb</span>
                            </div>
                        </div>

                        <div style="background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); border: 1px solid #cbd5e1; color: #0f172a; border-radius: 8px; padding: 0.55rem 0.9rem; display: flex; justify-content: space-between; align-items: center; gap: 0.45rem;">
                            <span style="font-size: 0.72rem; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; color: #475569;">
                                1RA (<?= $pesadaActiva['tipo_primera_pesada'] ?>)
                            </span>
                            <div style="font-size: 1.55rem; font-weight: 900; line-height: 1; display: flex; align-items: baseline; gap: 0.28rem; color: #1e293b;">
                                <span><?= number_format($primerPeso, 0) ?></span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: #475569;">lb</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DERECHA: INDICADOR AZUL MARINO EN VIVO -->
                <div class="cockpit-live-panel" style="background: #0f233a; color: #ffffff; border-radius: 12px; padding: 0.85rem 1.25rem; display: flex; flex-direction: column; justify-content: space-between;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <span style="font-size: 0.68rem; font-weight: 800; letter-spacing: 1.1px; text-transform: uppercase; color: #94a3b8;">
                                INDICADOR DE PESO
                            </span>
                            <h2 style="font-size: 1.18rem; font-weight: 800; margin: 0.08rem 0 0 0; color: #ffffff;">
                                Lectura en vivo
                            </h2>
                        </div>
                        <span id="liveEstableBadge" style="background: #1e3a5f; color: #60a5fa; font-size: 0.72rem; font-weight: 700; padding: 0.22rem 0.65rem; border-radius: 999px; border: 1px solid #2563eb;">
                            Estable
                        </span>
                    </div>

                    <div style="text-align: center; padding: 0.25rem 0;">
                        <div style="font-size: 2.85rem; font-weight: 900; line-height: 1; letter-spacing: -1px; display: inline-flex; align-items: baseline; gap: 0.35rem;">
                            <span id="livePesoValor">0</span>
                            <span style="font-size: 1.25rem; font-weight: 700; color: #93c5fd;">lb</span>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 0.45rem; font-size: 0.74rem; color: #94a3b8;">
                        <div>
                            <span id="liveModoTexto">Conectando...</span>
                            <span style="font-size: 0.68rem; color: #64748b; margin-left: 0.45rem;" id="liveUltimaLectura">--:--:--</span>
                        </div>
                        <div style="text-align: right;">
                            <span style="color: #cbd5e1;">Fuente &middot; Configurada</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FILA 3: TARA DE EMPAQUE, DESCUENTOS DETALLADOS, OBSERVACIONES Y CIERRE -->
            <div id="seccionLiquidacionSegunda" class="card" style="padding: 0.85rem 1.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 0.5rem;">
                <h3 style="font-size: 0.98rem; font-weight: 800; margin: 0 0 0.55rem 0; color: var(--text-main);">
                    Tara de empaque y descuentos
                </h3>

                <!-- 4 CONTROLES EN UNA SOLA FILA BALANCEADA -->
                <div class="cockpit-grid-4" style="display: grid; grid-template-columns: 1.25fr 0.9fr 0.9fr 0.9fr; gap: 0.8rem; margin-bottom: 0.65rem;">
                    <div>
                        <label for="idempaque" class="form-label" style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
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
                        <label for="cantidad_empaques" class="form-label" style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                            Cantidad de sacos / empaques
                        </label>
                        <input type="number" min="0" step="1" id="cantidad_empaques" name="cantidad_empaques" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" value="0" oninput="calcularTotales()">
                    </div>
                    <div>
                        <label for="ajuste_humedad_pct" class="form-label" style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                            Descuento humedad (%)
                        </label>
                        <input type="number" min="0" max="100" step="0.1" id="ajuste_humedad_pct" name="ajuste_humedad_pct" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" value="0" oninput="calcularTotales()">
                    </div>
                    <div>
                        <label for="descuento_calidad_pct" class="form-label" style="font-size: 0.75rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.2rem;">
                            Descuento calidad / defectos (%)
                        </label>
                        <input type="number" min="0" max="100" step="0.1" id="descuento_calidad_pct" name="descuento_calidad_pct" class="form-control" style="height: 38px; border-radius: 6px; font-size: 0.88rem;" value="0" oninput="calcularTotales()">
                    </div>
                </div>

                <?php if (($pesadaActiva['tipo_operacion'] ?? '') === 'VENTA'): ?>
                <!-- GUÍA DE REMISIÓN COMPACTA (SOLO VENTAS) -->
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.55rem 0.9rem; margin-bottom: 0.65rem;">
                    <div style="font-size: 0.75rem; font-weight: 800; color: #047857; margin-bottom: 0.35rem; text-transform: uppercase;">
                        Guía de Remisión (Venta)
                    </div>
                    <div class="cockpit-grid-4" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.7rem;">
                        <div>
                            <label for="guia_remision" class="form-label" style="font-size: 0.72rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.15rem;">
                                N° Guía de Remisión
                            </label>
                            <input type="text" id="guia_remision" name="guia_remision" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="30" placeholder="Ej. 002861">
                        </div>
                        <div>
                            <label for="motivo_traslado" class="form-label" style="font-size: 0.72rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.15rem;">
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
                            <label for="marca_vehiculo" class="form-label" style="font-size: 0.72rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.15rem;">
                                Marca del Vehículo
                            </label>
                            <input type="text" id="marca_vehiculo" name="marca_vehiculo" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="60" placeholder="Ej. Freightliner">
                        </div>
                        <div>
                            <label for="transportista_rtn" class="form-label" style="font-size: 0.72rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.15rem;">
                                R.T.N. Transportista
                            </label>
                            <input type="text" id="transportista_rtn" name="transportista_rtn" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="20" placeholder="Opcional">
                        </div>
                        <div id="boxMotivoOtro" style="display: none; grid-column: 1 / -1;">
                            <label for="motivo_traslado_otro" class="form-label" style="font-size: 0.72rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.15rem;">
                                Especificar otro motivo
                            </label>
                            <input type="text" id="motivo_traslado_otro" name="motivo_traslado_otro" class="form-control" style="height: 34px; font-size: 0.85rem;" maxlength="120" placeholder="Detalle del motivo">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div id="avisoPesoInvalido" style="display: none; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 8px; padding: 0.5rem 0.85rem; margin-bottom: 0.6rem; font-size: 0.84rem; font-weight: 600;"></div>

                <!-- TABLA DE DESGLOSE IDÉNTICA A LA BOLETA (TODO EN UNA SOLA VISTA) -->
                <div class="tabla-liq-segunda-wrap" style="margin-bottom: 0.65rem;">
                    <table class="tabla-liq-segunda">
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
                                <th class="th-total-qq">Total QQ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td id="resBruto"><?= $pesadaActiva['tipo_primera_pesada'] === 'BRUTO' ? number_format($primerPeso, 0) : '--' ?></td>
                                <td id="resTara"><?= $pesadaActiva['tipo_primera_pesada'] === 'TARA' ? number_format($primerPeso, 0) : '--' ?></td>
                                <td id="resNetoInicial">0</td>
                                <td id="resSacos">0</td>
                                <td id="resTaraSacos">0</td>
                                <td>
                                    <div id="resBaseLb">0</div>
                                    <div id="resBaseQQ" class="sub-qq">0.00 QQ</div>
                                </td>
                                <td id="resDescHumedadPct">0%</td>
                                <td id="resDescCalidadPct">0%</td>
                                <td id="resQuintales" class="td-total-qq">0.00</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- OBSERVACIONES DE SALIDA EN UN SOLO RENGLÓN LARGO -->
                <div class="obs-renglon-unico" style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.75rem;">
                    <label for="observaciones_segunda" class="form-label" style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin: 0; white-space: nowrap;">
                        Observaciones de salida:
                    </label>
                    <input type="text" id="observaciones_segunda" name="observaciones_segunda" class="form-control" style="height: 38px; font-size: 0.88rem; border-radius: 6px; flex: 1;" placeholder="Notas opcionales sobre el estado de la carga o cierre...">
                </div>

                <div id="avisoFaltanteGuardar" style="display: none; margin-bottom: 0.65rem; padding: 0.65rem 0.9rem; border-radius: 8px; background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; font-size: 0.85rem; font-weight: 600;"></div>

                <div class="cockpit-actions" style="display: flex; gap: 1rem; align-items: center;">
                    <button type="submit" id="btnCerrarPesada" class="btn btn-primary" style="background: #0b57d0; height: 40px; padding: 0 1.65rem; font-weight: 700; border-radius: 6px; font-size: 0.94rem;">
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

                <form method="GET" action="segunda_pesada.php" id="formBuscarPendientes" style="display: flex; gap: 0.65rem; align-items: center; max-width: 350px;">
                    <input type="search" id="buscadorPendientes" name="q" class="form-control" placeholder="Buscar por placa, boleta o persona..." value="<?= htmlspecialchars($filtroQ, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" style="height: 40px; border-radius: 6px;">
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
                    <tbody id="tablaPendientesBody">
                        <?php if (empty($pesadasPendientes)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 3.5rem 1rem;">
                                    No hay camiones pendientes de segunda pesada en este momento.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pesadasPendientes as $pend): ?>
                                <tr class="fila-pendiente" style="border-bottom: 1px solid var(--border-color);">
                                    <td style="padding: 1.15rem 0.5rem; font-weight: 800; color: #1e40af;">
                                        <a href="segunda_pesada.php?id=<?= (int)$pend['idpesada'] ?>" style="color: inherit; text-decoration: none;" title="Completar segunda pesada">
                                            <?= ($pend['tipo_operacion'] === 'COMPRA' ? 'Compra #' . $pend['id_compra'] : 'Venta #' . $pend['id_venta']) ?>
                                        </a>
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
                            <tr id="filaSinCoincidenciasPendientes" style="display: none;">
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem;">
                                    No se encontraron camiones pendientes que coincidan con tu búsqueda.
                                </td>
                            </tr>
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

function abrirPanelInvertirOperacion() {
    const panel = document.getElementById('panelInvertirOperacion');
    if (!panel) return;
    const pesoActual = document.getElementById('inputPesoFijado')?.value || '0';
    const inpPrevio = document.getElementById('inputPesoPrevioInvertir');
    if (inpPrevio) inpPrevio.value = pesoActual;
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function cerrarPanelInvertirOperacion() {
    const panel = document.getElementById('panelInvertirOperacion');
    if (panel) panel.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    conectarLecturaEnVivo();
    calcularTotales();

    const formInv = document.getElementById('formInvertirOperacion');
    if (formInv) {
        formInv.addEventListener('submit', () => {
            const inpPrevio = document.getElementById('inputPesoPrevioInvertir');
            const pesoActual = document.getElementById('inputPesoFijado')?.value || '0';
            if (inpPrevio) inpPrevio.value = pesoActual;
        });
    }

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
            const opSug = (tipoPrimera === 'BRUTO') ? 'VENTA' : 'COMPRA';
            faltantes.push(
                (tipoPrimera === 'TARA'
                    ? `El peso bruto complementario (${bruto.toLocaleString('en-US')} lb) debe ser mayor que la tara inicial (${tara.toLocaleString('en-US')} lb).`
                    : `La tara del vehículo (${tara.toLocaleString('en-US')} lb) debe ser menor que el peso bruto inicial (${bruto.toLocaleString('en-US')} lb).`) +
                ` <a href="#panelInvertirOperacion" onclick="event.preventDefault(); abrirPanelInvertirOperacion();" style="font-weight: 800; text-decoration: underline; color: inherit;">⇄ Cambiar esta nota a ${opSug}</a>`
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
            const opSugerida = (tipoPrimera === 'BRUTO') ? 'VENTA' : 'COMPRA';
            const explicacion = (tipoPrimera === 'BRUTO')
                ? `⚠️ El peso fijado (${tara.toLocaleString('en-US')} lb) es mayor o igual al peso inicial (${bruto.toLocaleString('en-US')} lb). Si el camión entró vacío a cargar, corrige esta nota a <strong>VENTA</strong>:`
                : `⚠️ El peso fijado (${bruto.toLocaleString('en-US')} lb) es menor o igual a la tara inicial (${tara.toLocaleString('en-US')} lb). Si el camión entró cargado a descargar, corrige esta nota a <strong>COMPRA</strong>:`;
            resAviso.style.display = 'flex';
            resAviso.style.alignItems = 'center';
            resAviso.style.justifyContent = 'space-between';
            resAviso.style.flexWrap = 'wrap';
            resAviso.style.gap = '0.65rem';
            resAviso.innerHTML = `<span>${explicacion}</span>` +
                `<button type="button" onclick="abrirPanelInvertirOperacion()" class="btn btn-primary" style="padding: 0.35rem 0.85rem; font-size: 0.82rem; font-weight: 700; background: ${opSugerida === 'VENTA' ? '#047857' : '#1e40af'}; border-radius: 6px; white-space: nowrap;">⇄ Cambiar esta nota a ${opSugerida}</button>`;
        }
    } else {
        if (resAviso) resAviso.style.display = 'none';
    }

    const tieneAmbosValidos = (segundoPeso > 0 && bruto > tara);
    const netoInicial = tieneAmbosValidos ? Math.max(0, bruto - tara) : 0;

    // Tara de sacos
    const selEmpaque = document.getElementById('idempaque');
    let taraUnitaria = 0;
    if (selEmpaque && selEmpaque.selectedIndex >= 0) {
        const optEmpaque = selEmpaque.options[selEmpaque.selectedIndex];
        taraUnitaria = parseFloat(optEmpaque.getAttribute('data-peso') || '0');
    }
    const cantEmpaques = Math.max(0, parseInt(document.getElementById('cantidad_empaques').value || '0', 10) || 0);
    const taraSacos = Math.ceil(Number((taraUnitaria * cantEmpaques).toFixed(4)));

    // Base de descuentos (Neto después de tara de sacos)
    const baseDesc = tieneAmbosValidos ? Math.max(0, netoInicial - taraSacos) : 0;

    // Descuentos porcentuales detallados (redondeo hacia arriba)
    const pctHum = Math.max(0, parseFloat(document.getElementById('ajuste_humedad_pct').value || '0') || 0);
    const pctDef = Math.max(0, parseFloat(document.getElementById('descuento_calidad_pct').value || '0') || 0);
    const descHum = Math.ceil(Number((baseDesc * (pctHum / 100.0)).toFixed(4)));
    const descDef = Math.ceil(Number((baseDesc * (pctDef / 100.0)).toFixed(4)));
    const totalDesc = descHum + descDef;

    const pesoFinal = tieneAmbosValidos ? Math.max(0, baseDesc - totalDesc) : 0;
    const fmtPct = (v) => Number(v.toFixed(2)) + '%';

    const elBruto = document.getElementById('resBruto');
    const elTara  = document.getElementById('resTara');
    const elNeto1 = document.getElementById('resNetoInicial');
    const elSacos = document.getElementById('resSacos');
    const elTSaco = document.getElementById('resTaraSacos');
    const elBaseL = document.getElementById('resBaseLb');
    const elBaseQ = document.getElementById('resBaseQQ');
    const elHumP  = document.getElementById('resDescHumedadPct');
    const elCalP  = document.getElementById('resDescCalidadPct');
    const elTotQQ = document.getElementById('resQuintales');

    if (elBruto) elBruto.innerText = (tipoPrimera === 'BRUTO') ? primerPeso.toLocaleString('en-US') : (segundoPeso > 0 ? segundoPeso.toLocaleString('en-US') : '--');
    if (elTara)  elTara.innerText  = (tipoPrimera === 'TARA')  ? primerPeso.toLocaleString('en-US') : (segundoPeso > 0 ? segundoPeso.toLocaleString('en-US') : '--');
    if (elNeto1) elNeto1.innerText = netoInicial.toLocaleString('en-US');
    if (elSacos) elSacos.innerText = cantEmpaques.toLocaleString('en-US');
    if (elTSaco) elTSaco.innerText = taraSacos.toLocaleString('en-US');
    if (elBaseL) elBaseL.innerText = baseDesc.toLocaleString('en-US');
    if (elBaseQ) elBaseQ.innerText = (baseDesc / 100.0).toFixed(2) + ' QQ';
    if (elHumP)  elHumP.innerText  = fmtPct(pctHum);
    if (elCalP)  elCalP.innerText  = fmtPct(pctDef);
    if (elTotQQ) {
        elTotQQ.innerText = (pesoFinal / 100.0).toFixed(2);
        elTotQQ.title = pesoFinal.toLocaleString('en-US') + ' lb finales';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const inputBusq = document.getElementById('buscadorPendientes');
    if (!inputBusq) return;

    const filas = Array.from(document.querySelectorAll('#tablaPendientesBody tr.fila-pendiente'));
    const filaVacia = document.getElementById('filaSinCoincidenciasPendientes');
    const normalizar = (t) => String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/#/g, ' ').replace(/\s+/g, ' ').toLowerCase().trim();
    const compactar = (t) => normalizar(t).replace(/\s+/g, '');

    function filtrarEnVivo() {
        const q = normalizar(inputBusq.value);
        const qCompacta = compactar(inputBusq.value);
        const tokens = q ? q.split(' ').filter(Boolean) : [];
        let visibles = 0;
        filas.forEach(tr => {
            const texto = normalizar(tr.textContent);
            const coincide = (q === '' || tokens.every(tok => texto.includes(tok)) || compactar(texto).includes(qCompacta));
            tr.style.display = coincide ? '' : 'none';
            if (coincide) visibles++;
        });
        if (filaVacia) {
            filaVacia.style.display = (filas.length > 0 && visibles === 0) ? '' : 'none';
        }
    }

    inputBusq.addEventListener('input', filtrarEnVivo);
    inputBusq.addEventListener('search', filtrarEnVivo);
    const formBusq = document.getElementById('formBuscarPendientes');
    if (formBusq) {
        formBusq.addEventListener('submit', (e) => {
            e.preventDefault();
            filtrarEnVivo();
        });
    }
    if (inputBusq.value.trim() !== '') {
        filtrarEnVivo();
    }
});
</script>

<style>
<?php if ($pesadaActiva): ?>
@media screen and (min-width: 901px) {
    .main-content {
        margin: 1rem auto !important;
    }
    .footer {
        padding: 0.75rem 1rem !important;
    }
}
<?php endif; ?>
.tabla-liq-segunda-wrap {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.tabla-liq-segunda {
    width: 100%;
    min-width: 580px;
    border-collapse: collapse;
    background: #ffffff;
}
.tabla-liq-segunda th {
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    padding: 0.42rem 0.35rem;
    text-align: center;
    border-top: 1px solid #cbd5e1;
    border-bottom: 1px solid #cbd5e1;
}
.tabla-liq-segunda th.th-total-qq {
    background: #ffe4e6;
    color: #881337;
    font-weight: 700;
}
.tabla-liq-segunda td {
    padding: 0.48rem 0.35rem;
    text-align: center;
    font-size: 0.98rem;
    font-weight: 800;
    color: #0f172a;
    border-bottom: 1px solid #e2e8f0;
}
.tabla-liq-segunda .sub-qq {
    font-size: 0.72rem;
    color: #475569;
    font-weight: 700;
    margin-top: 0.05rem;
}
.tabla-liq-segunda td.td-total-qq {
    background: #fff1f2;
    color: #881337;
    font-size: 1.35rem;
    font-weight: 900;
}
[data-theme="dark"] .tabla-liq-segunda {
    background: transparent;
}
[data-theme="dark"] .tabla-liq-segunda th {
    background: var(--bg-surface-elevated);
    color: #94a3b8;
    border-color: var(--border-color);
}
[data-theme="dark"] .tabla-liq-segunda td {
    color: #f1f5f9;
    border-bottom-color: var(--border-color);
}
[data-theme="dark"] .tabla-liq-segunda .sub-qq {
    color: #94a3b8;
}
[data-theme="dark"] .tabla-liq-segunda th.th-total-qq {
    background: rgba(244, 63, 94, 0.22);
    color: #fda4af;
}
[data-theme="dark"] .tabla-liq-segunda td.td-total-qq {
    background: rgba(244, 63, 94, 0.14);
    color: #fda4af;
}
@media screen and (max-width: 640px) {
    .obs-renglon-unico {
        flex-direction: column;
        align-items: stretch !important;
        gap: 0.25rem !important;
    }
    .cockpit-captura-row-inline > div,
    .cockpit-captura-row-inline > button {
        flex: 1 1 100% !important;
        width: 100%;
    }
    #cajaPesoFijadoSegunda {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php
renderizarPie();
