<?php
/**
 * Catálogo Maestro de Tipos de Empaque / Presentaciones (MariaDB: t_presentaciones)
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo de Catálogo:
 * Presentaciones base de pesaje (SACOS, A GRANEL, etc.)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
$rolActual = $usuarioActual['rol'] ?? 'OPERADOR';
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombre = $usuarioActual['empresa_nombre'] ?? 'BÁSCULA';
$esAdmin = ($rolActual === 'SUPER' || $rolActual === 'ADMINISTRADOR');
$pdo = obtenerDB();

$mensajeExito = '';
$error = '';
$erroresCampos = [];

// 1. PROCESAR ALTA Y EDICIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && in_array($_POST['accion'], ['crear', 'editar'], true)) {
    if (!$esAdmin) {
        $error = 'No tienes permisos para modificar el catálogo.';
    } elseif (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $presId      = (int)($_POST['id_presentacion'] ?? 0);
        $codigo      = mb_strtoupper(trim((string)($_POST['codigo'] ?? '')), 'UTF-8');
        $nombre      = mb_strtoupper(trim((string)($_POST['nombre'] ?? '')), 'UTF-8');
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $activo      = isset($_POST['activo']) ? 1 : 0;

        if ($codigo === '') {
            $erroresCampos['codigo'] = 'El código es obligatorio.';
        } elseif (mb_strlen($codigo) > 20) {
            $erroresCampos['codigo'] = 'Máximo 20 caracteres.';
        }

        if ($nombre === '') {
            $erroresCampos['nombre'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($nombre) > 80) {
            $erroresCampos['nombre'] = 'Máximo 80 caracteres.';
        }

        if (empty($erroresCampos)) {
            try {
                if ($_POST['accion'] === 'crear') {
                    $stmtCheck = $pdo->prepare(
                        "SELECT id FROM t_presentaciones WHERE empresa_id = :empresa_id AND codigo = :codigo LIMIT 1"
                    );
                    $stmtCheck->execute([':empresa_id' => $empresaId, ':codigo' => $codigo]);
                    if ($stmtCheck->fetch()) {
                        $error = "Ya existe un tipo de empaque con el código \"$codigo\".";
                    } else {
                        $stmtIns = $pdo->prepare(
                            "INSERT INTO t_presentaciones (empresa_id, codigo, nombre, descripcion, activo)
                             VALUES (:empresa_id, :codigo, :nombre, :descripcion, 1)"
                        );
                        $stmtIns->execute([
                            ':empresa_id'   => $empresaId,
                            ':codigo'       => $codigo,
                            ':nombre'       => $nombre,
                            ':descripcion'  => ($descripcion !== '' ? $descripcion : null),
                        ]);
                        $mensajeExito = "Tipo de empaque \"$nombre\" registrado con éxito.";
                    }
                } else {
                    $stmtUpd = $pdo->prepare(
                        "UPDATE t_presentaciones 
                         SET nombre = :nombre, descripcion = :descripcion, activo = :activo
                         WHERE id = :id AND empresa_id = :empresa_id"
                    );
                    $stmtUpd->execute([
                        ':nombre'      => $nombre,
                        ':descripcion' => ($descripcion !== '' ? $descripcion : null),
                        ':activo'      => $activo,
                        ':id'          => $presId,
                        ':empresa_id'  => $empresaId,
                    ]);
                    $mensajeExito = "Tipo de empaque \"$nombre\" actualizado correctamente.";
                }
            } catch (Exception $e) {
                $error = 'Error en base de datos: ' . $e->getMessage();
            }
        }
    }
}

// 2. CONSULTA Y BÚSQUEDA
$busqueda = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT id, codigo, nombre, descripcion, activo 
        FROM t_presentaciones 
        WHERE empresa_id = :empresa_id";
$params = [':empresa_id' => $empresaId];

if ($busqueda !== '') {
    $sql .= " AND (codigo LIKE :busq OR nombre LIKE :busq)";
    $params[':busq'] = '%' . $busqueda . '%';
}

$sql .= " ORDER BY activo DESC, nombre ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Tipos de Empaque', 'presentaciones_tipo');
?>

<div style="max-width: 1000px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
                CATÁLOGO
            </span>
            <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.25rem 0 0.25rem 0; color: var(--text-main); line-height: 1.1;">
                Tipos de empaque
            </h1>
            <p style="margin: 0; font-size: 0.95rem; color: var(--text-muted);">
                Presentaciones para pesaje de vehículos (Saco, A granel, etc.).
            </p>
        </div>

        <?php if ($esAdmin): ?>
        <button type="button" class="btn btn-primary" onclick="abrirModalNuevoTipo()" style="font-size: 0.95rem; font-weight: 600; padding: 0.65rem 1.35rem; border-radius: var(--radius-sm); background: #0b57d0; box-shadow: none;">
            Nuevo tipo
        </button>
        <?php endif; ?>
    </div>

    <!-- ALERTAS -->
    <?php if ($mensajeExito !== ''): ?>
        <div class="alert alert-success" style="margin-bottom: 1.5rem;">
            <span>✅ <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" style="margin-bottom: 1.5rem;">
            <span>⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <!-- TARJETA PRINCIPAL -->
    <div class="card" style="padding: 2rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">

        <!-- BUSCADOR -->
        <div style="margin-bottom: 2rem;">
            <label for="buscar_tipo" class="form-label" style="font-size: 0.88rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.45rem;">
                Buscar tipo de empaque
            </label>
            <form method="GET" action="presentaciones_tipo.php" style="display: flex; gap: 0.65rem; align-items: center; max-width: 420px;">
                <input 
                    type="text" 
                    id="buscar_tipo" 
                    name="q" 
                    class="form-control" 
                    placeholder="Código o nombre..."
                    style="border-radius: 6px; height: 40px; min-height: 40px;"
                    value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>"
                >
                <button type="submit" class="btn btn-secondary" style="height: 40px; min-height: 40px; padding: 0 1.25rem; font-weight: 600; border-radius: 6px; background: #e8f0fe; color: #1e40af; border: none;">
                    Buscar
                </button>
                <?php if ($busqueda !== ''): ?>
                    <a href="presentaciones_tipo.php" class="btn btn-secondary" style="height: 40px; min-height: 40px; padding: 0 0.85rem;" title="Limpiar">&times;</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- TABLA -->
        <div class="table-responsive" style="border: none; background: transparent;">
            <table class="table" style="border-collapse: separate; border-spacing: 0; min-width: 100%;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 25%;">
                            Código
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 35%;">
                            Tipo de empaque
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 20%;">
                            Estado
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 20%;">
                            Acción
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tipos)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                No se encontraron tipos de empaque registrados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tipos as $item): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 1.15rem 0.5rem; font-weight: 800; font-family: monospace; font-size: 0.95rem; color: var(--primary);">
                                    <?= htmlspecialchars($item['codigo'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="padding: 1.15rem 0.5rem; font-weight: 700; color: var(--text-main); font-size: 0.95rem;">
                                    <?= htmlspecialchars($item['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($item['descripcion'])): ?>
                                        <div style="font-size: 0.8rem; font-weight: normal; color: var(--text-muted); margin-top: 0.2rem;">
                                            <?= htmlspecialchars($item['descripcion'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1.15rem 0.5rem; color: var(--text-main); font-size: 0.95rem;">
                                    <?= $item['activo'] ? 'Activo' : 'Inactivo' ?>
                                </td>
                                <td style="padding: 1.15rem 0.5rem;">
                                    <?php if ($esAdmin): ?>
                                    <button 
                                        type="button" 
                                        style="background: none; border: none; padding: 0; color: #1e40af; font-size: 0.95rem; font-weight: 500; cursor: pointer; text-decoration: underline; text-underline-offset: 3px;"
                                        onclick='abrirModalEditarTipo(<?= json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    >
                                        Editar
                                    </button>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.88rem;">Lectura</span>
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

<!-- MODAL CREAR / EDITAR -->
<div id="modalTipo" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="max-width: 480px; width: 100%; margin: 0; box-shadow: var(--shadow-lg);">
        <div class="card-title" style="margin-bottom: 1.25rem;">
            <span id="modalTipoTitulo">Nuevo Tipo de Empaque</span>
            <button type="button" class="btn btn-secondary" style="padding: 0.2rem 0.5rem; min-height: 28px;" onclick="cerrarModalTipo()">&times;</button>
        </div>

        <form method="POST" action="presentaciones_tipo.php" autocomplete="off" id="formTipo">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" id="tipoAccion" value="crear">
            <input type="hidden" name="id_presentacion" id="tipoId" value="0">

            <div class="form-group">
                <label for="tipo_codigo" class="form-label">Código Único <span style="color: var(--danger);">*</span></label>
                <input 
                    type="text" 
                    id="tipo_codigo" 
                    name="codigo" 
                    class="form-control" 
                    placeholder="Ej. SACO, GRANEL" 
                    maxlength="20" 
                    required
                    style="text-transform: uppercase;"
                >
            </div>

            <div class="form-group">
                <label for="tipo_nombre" class="form-label">Nombre del Tipo de Empaque <span style="color: var(--danger);">*</span></label>
                <input 
                    type="text" 
                    id="tipo_nombre" 
                    name="nombre" 
                    class="form-control" 
                    placeholder="Ej. SACO, A GRANEL" 
                    maxlength="80" 
                    required
                    style="text-transform: uppercase;"
                >
            </div>

            <div class="form-group">
                <label for="tipo_descripcion" class="form-label">Descripción</label>
                <input 
                    type="text" 
                    id="tipo_descripcion" 
                    name="descripcion" 
                    class="form-control" 
                    placeholder="Detalles adicionales" 
                    maxlength="150"
                >
            </div>

            <div class="form-group" id="divTipoActivo" style="display: none;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="activo" id="tipo_activo" value="1" style="width: 18px; height: 18px;">
                    <span style="font-weight: 600;">Tipo de Empaque Activo</span>
                </label>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; align-items: center; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalTipo()">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-lg" id="btnGuardarTipo">
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const modalTipo = document.getElementById('modalTipo');

function abrirModalNuevoTipo() {
    document.getElementById('modalTipoTitulo').innerText = 'Nuevo Tipo de Empaque';
    document.getElementById('tipoAccion').value = 'crear';
    document.getElementById('tipoId').value = '0';
    document.getElementById('tipo_codigo').value = '';
    document.getElementById('tipo_codigo').readOnly = false;
    document.getElementById('tipo_nombre').value = '';
    document.getElementById('tipo_descripcion').value = '';
    document.getElementById('divTipoActivo').style.display = 'none';
    document.getElementById('btnGuardarTipo').innerText = 'Guardar';
    modalTipo.style.display = 'flex';
    document.getElementById('tipo_codigo').focus();
}

function abrirModalEditarTipo(data) {
    document.getElementById('modalTipoTitulo').innerText = 'Editar: ' + data.nombre;
    document.getElementById('tipoAccion').value = 'editar';
    document.getElementById('tipoId').value = data.id;
    document.getElementById('tipo_codigo').value = data.codigo;
    document.getElementById('tipo_codigo').readOnly = true;
    document.getElementById('tipo_nombre').value = data.nombre || '';
    document.getElementById('tipo_descripcion').value = data.descripcion || '';
    document.getElementById('divTipoActivo').style.display = 'block';
    document.getElementById('tipo_activo').checked = (parseInt(data.activo) === 1);
    document.getElementById('btnGuardarTipo').innerText = 'Actualizar';
    modalTipo.style.display = 'flex';
    document.getElementById('tipo_nombre').focus();
}

function cerrarModalTipo() {
    modalTipo.style.display = 'none';
}

window.onclick = function(event) {
    if (event.target === modalTipo) {
        cerrarModalTipo();
    }
}
</script>

<?php
renderizarPie();
