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

        // Validaciones mínimas
        if ($pesoFijado <= 0) {
            $error = 'Debes fijar un peso mayor a cero antes de guardar.';
        } elseif ($clieProvId <= 0) {
            $error = 'Selecciona una persona o empresa (cliente/proveedor).';
        } elseif ($productoId <= 0 || $presentacionId <= 0) {
            $error = 'Selecciona el producto y la presentación.';
        } elseif ($placa === '') {
            $error = 'La placa del vehículo es obligatoria.';
        } else {
            try {
                $pdo->beginTransaction();

                // Obtener correlativo de compra o venta para la empresa
                $colCorrelativo = ($tipoOperacion === 'COMPRA') ? 'id_compra' : 'id_venta';
                $stmtCorr = $pdo->prepare("SELECT COALESCE(MAX($colCorrelativo), 0) + 1 AS sig FROM t_pesadas WHERE empresa_id = :empresa_id");
                $stmtCorr->execute([':empresa_id' => $empresaId]);
                $correlativo = (int)$stmtCorr->fetch(PDO::FETCH_ASSOC)['sig'];

                // Definir pesos según tipo_registro
                $pesoBruto = ($tipoRegistro === 'BRUTO') ? $pesoFijado : null;
                $taraVehiculo = ($tipoRegistro === 'TARA') ? $pesoFijado : null;
                $origenBruto = ($tipoRegistro === 'BRUTO') ? ($modoCaptura === 'MANUAL' ? 'MANUAL' : 'INDICADOR') : null;
                $origenTara = ($tipoRegistro === 'TARA') ? ($modoCaptura === 'MANUAL' ? 'MANUAL' : 'INDICADOR') : null;
                $capturadoPor = (int)$usuarioActual['id'];

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

        <!-- SELECTOR DESTACADO DE TIPO DE TRANSACCIÓN -->
        <div style="background: var(--bg-surface); border: 2px solid #2563eb; padding: 0.65rem 1rem; border-radius: 10px; display: flex; align-items: center; gap: 0.85rem; box-shadow: var(--shadow-sm);">
            <label for="selector_transaccion_superior" style="font-size: 0.88rem; font-weight: 800; color: #1e40af; text-transform: uppercase; margin: 0;">
                Tipo de Transacción:
            </label>
            <select id="selector_transaccion_superior" class="form-control" style="height: 40px; font-weight: 800; font-size: 1rem; border-radius: 6px; border: 1px solid #cbd5e1; min-width: 160px; color: #1e40af; background: #eff6ff;" onchange="cambiarTransaccionPrincipal(this.value)">
                <option value="COMPRA" selected>COMPRA</option>
                <option value="VENTA">VENTA</option>
            </select>
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
    <?php if ($pesadaGuardada): ?>
        <div class="card" style="padding: 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">
            
            <div style="background: #e8f0fe; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 1.75rem; font-weight: 500; font-size: 0.95rem;">
                Primera pesada guardada.
            </div>

            <div style="margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.6rem; font-weight: 800; margin: 0 0 0.5rem 0; color: var(--text-main);">
                    <?= ($pesadaGuardada['tipo_operacion'] === 'COMPRA' ? 'Compra #' . $pesadaGuardada['id_compra'] : 'Venta #' . $pesadaGuardada['id_venta']) ?>
                </h2>
                <div style="font-size: 1.05rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.85rem; letter-spacing: 0.3px;">
                    <?= htmlspecialchars($pesadaGuardada['persona_nombre'], ENT_QUOTES, 'UTF-8') ?> &middot; 
                    <?= htmlspecialchars($pesadaGuardada['producto_nombre'], ENT_QUOTES, 'UTF-8') ?> &middot; 
                    <?= htmlspecialchars($pesadaGuardada['presentacion_nombre'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="font-size: 1.05rem; color: var(--text-main); margin-bottom: 0.4rem;">
                    <?= ($pesadaGuardada['tipo_primera_pesada'] === 'BRUTO' ? 'Peso bruto' : 'Tara de vehículo') ?>: 
                    <strong style="font-weight: 800;"><?= number_format((float)($pesadaGuardada['peso_bruto_lb'] ?? $pesadaGuardada['tara_vehiculo_lb']), 0) ?> lb</strong>
                </div>
                <div style="font-size: 0.95rem; color: var(--text-muted);">
                    Placa <?= htmlspecialchars($pesadaGuardada['placa'], ENT_QUOTES, 'UTF-8') ?> &middot; Responsable <?= htmlspecialchars($pesadaGuardada['responsable_nombre'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>

            <div style="background: #f1f5f9; color: #475569; border-radius: 8px; padding: 0.85rem 1.25rem; margin-bottom: 2rem; font-size: 0.92rem;">
                Nota pendiente de segunda pesada.
            </div>

            <div style="display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap;">
                <a href="segunda_pesada.php?id=<?= $pesadaGuardada['idpesada'] ?>" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-weight: 700; background: #0b57d0; border-radius: 6px;">
                    Completar segunda pesada
                </a>
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

        <!-- BLOQUE OPERACIÓN -->
        <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.5rem;">
            <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0 0 1.25rem 0; color: var(--text-main);">
                Operación
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.25rem;">
                <div>
                    <label for="tipo_operacion" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Tipo de operación (Controlado por selector superior)
                    </label>
                    <input type="hidden" id="tipo_operacion" name="tipo_operacion" value="COMPRA">
                    <input type="text" id="tipo_operacion_display" class="form-control" style="height: 42px; border-radius: 6px; font-weight: 800; color: #1e40af; background: #f1f5f9; cursor: not-allowed;" value="COMPRA" readonly>
                </div>

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
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.25rem;">
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

                <div>
                    <label for="presentacion_id" class="form-label" style="font-size: 0.82rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem;">
                        Presentación
                    </label>
                    <select id="presentacion_id" name="presentacion_id" class="form-control" style="height: 42px; border-radius: 6px;" required>
                        <option value="" disabled selected>Selecciona una presentación</option>
                    </select>
                </div>
            </div>

            <div style="max-width: 49%;">
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
                        Origen
                    </label>
                    <input type="text" id="origen" name="origen" class="form-control" style="height: 42px; border-radius: 6px;">
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
    const inputDisplayOp = document.getElementById('tipo_operacion_display');
    const btnFijar = document.getElementById('btnFijarPeso');
    const txtGuiaFijar = document.getElementById('txtGuiaFijar');
    const txtGuiaGuardar = document.getElementById('txtGuiaGuardar');

    inputHiddenOp.value = tipo;
    inputDisplayOp.value = tipo;

    // Regla de negocio:
    // - En COMPRA: Primero viene cargado -> se pesa PESO BRUTO.
    // - En VENTA: Primero entra vacío a cargar -> se pesa TARA DEL VEHÍCULO.
    // No queda fijado permanentemente; el operador puede cambiarlo manualmente si lo necesita.
    if (tipo === 'COMPRA') {
        selTipoReg.value = 'BRUTO';
        document.getElementById('tipo_registro_hidden').value = 'BRUTO';
        inputDisplayOp.style.color = '#1e40af';
    } else {
        selTipoReg.value = 'TARA';
        document.getElementById('tipo_registro_hidden').value = 'TARA';
        inputDisplayOp.style.color = '#047857';
    }

    // Habilitar botón de fijar peso automáticamente al tener ya seleccionado el tipo
    btnFijar.disabled = false;
    if (txtGuiaFijar) txtGuiaFijar.style.display = 'none';
    if (txtGuiaGuardar) txtGuiaGuardar.innerText = 'Fija el peso antes de guardar.';

    // Actualizar filtro de Proveedor / Cliente
    filtrarPersonas(tipo);
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
