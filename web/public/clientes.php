<?php
/**
 * Catálogo Maestro de Clientes y Proveedores
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo Administrativo / Operativo:
 * - Listado moderno responsivo con filtros de búsqueda rápida y tipo (Cliente, Proveedor o Ambos).
 * - Creación y edición ágil con validación estricta y protección CSRF.
 * - Alternancia de estado activo/inactivo.
 * - Control de roles RBAC:
 *   * SUPER / ADMINISTRADOR / SUPERVISOR: Crean, editan y gestionan.
 *   * OPERADOR: Consulta y alta ágil para no detener la fila de camiones en báscula.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';
require_once dirname(__DIR__) . '/src/sync_nube.php';

$usuarioActual = requerirAutenticacion();
$rolActual = $usuarioActual['rol'] ?? 'OPERADOR';
$empresaId = (int)$usuarioActual['empresa_id'];
$pdo = obtenerDB();

$mensajeExito = '';
$error = '';
$erroresCampos = [];

// Permisos: REMOTO es solo lectura; los demás perfiles pueden gestionar según rol.
$esSoloLectura = ($rolActual === 'REMOTO');
$puedeDesactivar = in_array($rolActual, ['SUPER', 'ADMINISTRADOR', 'SUPERVISOR'], true);

// 1. PROCESAR GUARDAR (CREAR O ACTUALIZAR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && in_array($_POST['accion'], ['crear', 'editar'], true)) {
    if ($esSoloLectura) {
        $error = 'Acceso de solo consulta: el perfil REMOTO no puede modificar el catálogo.';
    } elseif (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $accionId     = (int)($_POST['id_clie_prov'] ?? 0);
        $nombre       = trim((string)($_POST['nombre'] ?? ''));
        $rtn          = trim((string)($_POST['rtn'] ?? ''));
        $telefono     = trim((string)($_POST['telefono'] ?? ''));
        $direccion    = trim((string)($_POST['direccion'] ?? ''));
        $esCliente    = isset($_POST['es_cliente']) ? 1 : 0;
        $esProveedor  = isset($_POST['es_proveedor']) ? 1 : 0;

        // Validaciones
        if ($nombre === '') {
            $erroresCampos['nombre'] = 'El nombre o razón social es obligatorio.';
        } elseif (mb_strlen($nombre) > 150) {
            $erroresCampos['nombre'] = 'Máximo 150 caracteres permitidos.';
        }

        if (mb_strlen($rtn) > 20) {
            $erroresCampos['rtn'] = 'El RTN no puede exceder 20 caracteres.';
        }

        if (mb_strlen($telefono) > 30) {
            $erroresCampos['telefono'] = 'El teléfono no puede exceder 30 caracteres.';
        }

        if (mb_strlen($direccion) > 255) {
            $erroresCampos['direccion'] = 'La dirección no puede exceder 255 caracteres.';
        }

        if ($esCliente === 0 && $esProveedor === 0) {
            $erroresCampos['tipo'] = 'Debes marcar al menos una categoría: Cliente o Proveedor.';
        }

        if (empty($erroresCampos)) {
            try {
                if ($_POST['accion'] === 'crear') {
                    // Verificar si ya existe con el mismo nombre en la empresa
                    $stmtCheck = $pdo->prepare(
                        "SELECT id FROM t_clie_prov WHERE empresa_id = :empresa_id AND nombre = :nombre LIMIT 1"
                    );
                    $stmtCheck->execute([':empresa_id' => $empresaId, ':nombre' => $nombre]);
                    if ($stmtCheck->fetch()) {
                        $error = 'Ya existe un cliente o proveedor registrado con el nombre "' . htmlspecialchars($nombre) . '".';
                    } else {
                        $stmtInsert = $pdo->prepare(
                            "INSERT INTO t_clie_prov (empresa_id, nombre, rtn, telefono, direccion, es_cliente, es_proveedor, activo)
                             VALUES (:empresa_id, :nombre, :rtn, :telefono, :direccion, :es_cliente, :es_proveedor, 1)"
                        );
                        $stmtInsert->execute([
                            ':empresa_id'   => $empresaId,
                            ':nombre'       => $nombre,
                            ':rtn'          => ($rtn !== '' ? $rtn : null),
                            ':telefono'     => ($telefono !== '' ? $telefono : null),
                            ':direccion'    => ($direccion !== '' ? $direccion : null),
                            ':es_cliente'   => $esCliente,
                            ':es_proveedor' => $esProveedor,
                        ]);
                        $mensajeExito = "Se ha registrado exitosamente a \"$nombre\".";
                        sincronizarCatalogosAutomatico('t_clie_prov');
                    }
                } else {
                    // Modo Edición
                    $stmtUpdate = $pdo->prepare(
                        "UPDATE t_clie_prov 
                         SET nombre = :nombre,
                             rtn = :rtn,
                             telefono = :telefono,
                             direccion = :direccion,
                             es_cliente = :es_cliente,
                             es_proveedor = :es_proveedor
                         WHERE id = :id AND empresa_id = :empresa_id"
                    );
                    $stmtUpdate->execute([
                        ':nombre'       => $nombre,
                        ':rtn'          => ($rtn !== '' ? $rtn : null),
                        ':telefono'     => ($telefono !== '' ? $telefono : null),
                        ':direccion'    => ($direccion !== '' ? $direccion : null),
                        ':es_cliente'   => $esCliente,
                        ':es_proveedor' => $esProveedor,
                        ':id'           => $accionId,
                        ':empresa_id'   => $empresaId,
                    ]);
                    $mensajeExito = "Registro actualizado correctamente.";
                    sincronizarCatalogosAutomatico('t_clie_prov');
                }
            } catch (Exception $e) {
                $error = 'Error al guardar en la base de datos: ' . $e->getMessage();
            }
        }
    }
}

// 2. PROCESAR ACTIVAR / DESACTIVAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'alternar_estado') {
    if (!$puedeDesactivar) {
        $error = 'No tienes permisos suficientes para cambiar el estado de este registro.';
    } elseif (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido.';
    } else {
        $idCambiar = (int)($_POST['id_clie_prov'] ?? 0);
        $nuevoEstado = (int)($_POST['nuevo_estado'] ?? 0);

        try {
            $stmtEstado = $pdo->prepare(
                "UPDATE t_clie_prov SET activo = :activo WHERE id = :id AND empresa_id = :empresa_id"
            );
            $stmtEstado->execute([
                ':activo'     => $nuevoEstado,
                ':id'         => $idCambiar,
                ':empresa_id' => $empresaId,
            ]);
            $mensajeExito = ($nuevoEstado === 1) ? 'Registro activado exitosamente.' : 'Registro desactivado.';
            sincronizarCatalogosAutomatico('t_clie_prov');
        } catch (Exception $e) {
            $error = 'Error al cambiar estado: ' . $e->getMessage();
        }
    }
}

// 3. CONSULTA CON FILTROS
$busqueda = trim((string)($_GET['q'] ?? ''));
$filtroTipo = trim((string)($_GET['tipo'] ?? 'todos'));

$sql = "SELECT id, nombre, rtn, telefono, direccion, es_cliente, es_proveedor, activo 
        FROM t_clie_prov 
        WHERE empresa_id = :empresa_id";
$params = [':empresa_id' => $empresaId];

if ($busqueda !== '') {
    $sql .= " AND (nombre LIKE :busq OR rtn LIKE :busq OR telefono LIKE :busq)";
    $params[':busq'] = '%' . $busqueda . '%';
}

if ($filtroTipo === 'clientes') {
    $sql .= " AND es_cliente = 1";
} elseif ($filtroTipo === 'proveedores') {
    $sql .= " AND es_proveedor = 1";
}

$sql .= " ORDER BY activo DESC, nombre ASC";

$stmtLista = $pdo->prepare($sql);
$stmtLista->execute($params);
$registros = $stmtLista->fetchAll();

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Clientes y Proveedores', 'clientes');
?>

<div style="max-width: 1100px; margin: 0 auto;">

    <!-- ENCABEZADO DE SECCIÓN -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-light), rgba(59, 130, 246, 0.25)); display: flex; align-items: center; justify-content: center; color: var(--primary);">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 800; margin: 0; line-height: 1.2;">Clientes y Proveedores</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Directorio unificado de personas naturales o jurídicas asociadas a las pesadas</span>
            </div>
        </div>
        <?php if (!$esSoloLectura): ?>
        <button type="button" class="btn btn-primary" onclick="abrirModalNuevo()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            Nuevo Registro
        </button>
        <?php endif; ?>
    </div>

    <!-- ALERTAS -->
    <?php if ($mensajeExito !== ''): ?>
        <div class="alert alert-success">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <span><?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <!-- BARRA DE BÚSQUEDA Y FILTROS -->
    <div class="card" style="margin-bottom: 1.5rem; padding: 1.25rem 1.5rem;">
        <form method="GET" action="clientes.php" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 240px; position: relative;">
                <input 
                    type="text" 
                    name="q" 
                    class="form-control" 
                    placeholder="Buscar por nombre, RTN o teléfono..." 
                    value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div style="width: 200px;">
                <select name="tipo" class="form-control" onchange="this.form.submit()">
                    <option value="todos" <?= $filtroTipo === 'todos' ? 'selected' : '' ?>>Todos los tipos</option>
                    <option value="clientes" <?= $filtroTipo === 'clientes' ? 'selected' : '' ?>>Solo Clientes</option>
                    <option value="proveedores" <?= $filtroTipo === 'proveedores' ? 'selected' : '' ?>>Solo Proveedores</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                Buscar
            </button>

            <?php if ($busqueda !== '' || $filtroTipo !== 'todos'): ?>
                <a href="clientes.php" class="btn btn-secondary" title="Limpiar filtros">&times; Limpiar</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- TABLA DE RESULTADOS -->
    <div class="card">
        <div class="card-title">
            <span>Listado (<?= count($registros) ?> <?= count($registros) === 1 ? 'registro' : 'registros' ?>)</span>
            <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">Empresa ID #<?= $empresaId ?></span>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nombre / Razón Social</th>
                        <th>RTN / Identificación</th>
                        <th>Teléfono</th>
                        <th>Tipo</th>
                        <th>Estado</th>
                        <th style="text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registros)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                <?= $busqueda !== '' ? 'No se encontraron resultados para tu búsqueda.' : 'No hay clientes o proveedores registrados aún.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($registros as $reg): 
                            $inicial = mb_strtoupper(mb_substr($reg['nombre'], 0, 1, 'UTF-8'), 'UTF-8');
                        ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <div style="width: 38px; height: 38px; border-radius: var(--radius-sm); background: var(--bg-surface-elevated); border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--primary); font-size: 1rem; flex-shrink: 0;">
                                            <?= htmlspecialchars($inicial) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; color: var(--text-main);">
                                                <?= htmlspecialchars($reg['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                            <?php if (!empty($reg['direccion'])): ?>
                                                <div style="font-size: 0.78rem; color: var(--text-muted); max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    📍 <?= htmlspecialchars($reg['direccion'], ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <code><?= htmlspecialchars($reg['rtn'] ?: '—', ENT_QUOTES, 'UTF-8') ?></code>
                                </td>
                                <td style="color: var(--text-muted); font-size: 0.9rem;">
                                    <?= htmlspecialchars($reg['telefono'] ?: '—', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <div style="display: inline-flex; gap: 0.35rem; flex-wrap: wrap;">
                                        <?php if ($reg['es_cliente']): ?>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3);">
                                                CLIENTE
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($reg['es_proveedor']): ?>
                                            <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: var(--primary); border: 1px solid rgba(59, 130, 246, 0.3);">
                                                PROVEEDOR
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($reg['activo']): ?>
                                        <span class="badge badge-estable">ACTIVO</span>
                                    <?php else: ?>
                                        <span class="badge badge-inestable" style="background: rgba(239, 68, 68, 0.12); color: var(--danger); border-color: var(--danger);">INACTIVO</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($esSoloLectura): ?>
                                        <span style="color: var(--text-muted); font-size: 0.88rem;">Lectura</span>
                                    <?php else: ?>
                                    <div style="display: inline-flex; gap: 0.4rem; align-items: center;">
                                        <!-- Botón Editar -->
                                        <button 
                                            type="button" 
                                            class="btn btn-secondary" 
                                            style="padding: 0.35rem 0.65rem; font-size: 0.8rem; min-height: 32px;"
                                            onclick='abrirModalEditar(<?= json_encode($reg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                            title="Editar información"
                                        >
                                            ✏️ Editar
                                        </button>

                                        <!-- Alternar Activo/Inactivo -->
                                        <?php if ($puedeDesactivar): ?>
                                            <form method="POST" action="clientes.php" style="display: inline-block;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="accion" value="alternar_estado">
                                                <input type="hidden" name="id_clie_prov" value="<?= (int)$reg['id'] ?>">
                                                <input type="hidden" name="nuevo_estado" value="<?= $reg['activo'] ? 0 : 1 ?>">
                                                <button 
                                                    type="submit" 
                                                    class="btn <?= $reg['activo'] ? 'btn-danger-outline' : 'btn-success-outline' ?>" 
                                                    style="padding: 0.35rem 0.65rem; font-size: 0.8rem; min-height: 32px;"
                                                    onclick="return confirm('¿Confirmas <?= $reg['activo'] ? 'DESACTIVAR' : 'ACTIVAR' ?> este registro?');"
                                                >
                                                    <?= $reg['activo'] ? 'Desactivar' : 'Activar' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =======================================================================
     MODAL FLOTANTE / FORMULARIO CREAR Y EDITAR
     ======================================================================= -->
<div id="modalClieProv" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="max-width: 650px; width: 100%; max-height: 90vh; overflow-y: auto; margin: 0; box-shadow: var(--shadow-lg);">
        <div class="card-title" style="margin-bottom: 1.25rem;">
            <span id="modalTitulo">➕ Nuevo Cliente o Proveedor</span>
            <button type="button" class="btn btn-secondary" style="padding: 0.2rem 0.5rem; min-height: 28px;" onclick="cerrarModal()">&times;</button>
        </div>

        <form method="POST" action="clientes.php" autocomplete="off" id="formClieProv">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" id="formAccion" value="crear">
            <input type="hidden" name="id_clie_prov" id="formId" value="0">

            <div class="form-group">
                <label for="nombre" class="form-label">Nombre o Razón Social <span style="color: var(--danger);">*</span></label>
                <input 
                    type="text" 
                    id="nombre" 
                    name="nombre" 
                    class="form-control" 
                    placeholder="Ejemplo: Beneficio San Marcos S.A. / Juan Pérez" 
                    maxlength="150" 
                    required
                >
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="rtn" class="form-label">RTN / Documento Tributario</label>
                    <input 
                        type="text" 
                        id="rtn" 
                        name="rtn" 
                        class="form-control" 
                        placeholder="08011990123456" 
                        maxlength="20"
                    >
                </div>

                <div class="form-group">
                    <label for="telefono" class="form-label">Teléfono de Contacto</label>
                    <input 
                        type="text" 
                        id="telefono" 
                        name="telefono" 
                        class="form-control" 
                        placeholder="+504 9999-9999" 
                        maxlength="30"
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="direccion" class="form-label">Dirección / Ubicación</label>
                <input 
                    type="text" 
                    id="direccion" 
                    name="direccion" 
                    class="form-control" 
                    placeholder="Municipio, departamento o punto de referencia" 
                    maxlength="255"
                >
            </div>

            <!-- TIPO: CHECKBOXES MODERNOS -->
            <div class="form-group" style="background: var(--bg-surface-elevated); padding: 1rem 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                <label class="form-label" style="margin-bottom: 0.65rem;">Categoría en Operaciones de Pesaje <span style="color: var(--danger);">*</span></label>
                <div style="display: flex; gap: 2rem; align-items: center; flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.95rem; font-weight: 500;">
                        <input type="checkbox" name="es_cliente" id="checkCliente" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                        <span>🛒 Es Cliente (Ventas / Despachos)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.95rem; font-weight: 500;">
                        <input type="checkbox" name="es_proveedor" id="checkProveedor" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                        <span>📦 Es Proveedor (Compras / Entradas)</span>
                    </label>
                </div>
                <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.4rem;">
                    Puede estar marcado en ambas si realiza tanto compras como ventas de producto.
                </small>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; align-items: center; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-lg" id="btnGuardar">
                    💾 Guardar Registro
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('modalClieProv');

function abrirModalNuevo() {
    document.getElementById('modalTitulo').innerText = '➕ Nuevo Cliente o Proveedor';
    document.getElementById('formAccion').value = 'crear';
    document.getElementById('formId').value = '0';
    document.getElementById('nombre').value = '';
    document.getElementById('rtn').value = '';
    document.getElementById('telefono').value = '';
    document.getElementById('direccion').value = '';
    document.getElementById('checkCliente').checked = true;
    document.getElementById('checkProveedor').checked = false;
    document.getElementById('btnGuardar').innerText = '💾 Registrar';
    modal.style.display = 'flex';
    document.getElementById('nombre').focus();
}

function abrirModalEditar(data) {
    document.getElementById('modalTitulo').innerText = '✏️ Editar: ' + data.nombre;
    document.getElementById('formAccion').value = 'editar';
    document.getElementById('formId').value = data.id;
    document.getElementById('nombre').value = data.nombre || '';
    document.getElementById('rtn').value = data.rtn || '';
    document.getElementById('telefono').value = data.telefono || '';
    document.getElementById('direccion').value = data.direccion || '';
    document.getElementById('checkCliente').checked = (parseInt(data.es_cliente) === 1);
    document.getElementById('checkProveedor').checked = (parseInt(data.es_proveedor) === 1);
    document.getElementById('btnGuardar').innerText = '💾 Guardar Cambios';
    modal.style.display = 'flex';
    document.getElementById('nombre').focus();
}

function cerrarModal() {
    modal.style.display = 'none';
}

// Cerrar si hace clic fuera del contenido de la tarjeta
window.onclick = function(event) {
    if (event.target === modal) {
        cerrarModal();
    }
}
</script>

<?php
renderizarPie();
