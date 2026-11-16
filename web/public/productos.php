<?php
/**
 * Catálogo Maestro de Productos y Presentaciones
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo de Catálogo:
 * - Vista y diseño fiel a la imagen de referencia: Título "CATÁLOGO / Productos", subtítulo de empresa,
 *   botón azul "Nuevo producto", campo "Buscar producto" con botón "Buscar".
 * - Tabla con columnas: Producto, Categoría, Presentaciones, Estado, Acción.
 * - Modal moderno para creación y edición de Producto con selector de Categoría (Café, Aceites, Granos Básicos).
 * - Alta y edición ágil, activación/desactivación y validación CSRF.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
$rolActual = $usuarioActual['rol'] ?? 'OPERADOR';
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombre = $usuarioActual['empresa_nombre'] ?? 'INVERSIONES VALLECILLO, SB';
$pdo = obtenerDB();

// Cargar categorías activas para el formulario
$stmtCats = $pdo->prepare("SELECT id, codigo, nombre FROM t_categorias WHERE empresa_id = :empresa_id AND activo = 1 ORDER BY nombre ASC");
$stmtCats->execute([':empresa_id' => $empresaId]);
$listaCategorias = $stmtCats->fetchAll();

$mensajeExito = '';
$error = '';
$erroresCampos = [];

// 1. PROCESAR CREAR O EDITAR PRODUCTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && in_array($_POST['accion'], ['crear', 'editar'], true)) {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $prodId = (int)($_POST['id_producto'] ?? 0);
        $categoriaId = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
        $nombreProducto = trim((string)($_POST['nombre'] ?? ''));
        $presentacionesTexto = trim((string)($_POST['presentaciones'] ?? ''));
        $activo = isset($_POST['activo']) ? 1 : 0;

        if ($nombreProducto === '') {
            $erroresCampos['nombre'] = 'El nombre del producto es obligatorio.';
        } elseif (mb_strlen($nombreProducto) > 100) {
            $erroresCampos['nombre'] = 'Máximo 100 caracteres permitidos.';
        }

        if (empty($erroresCampos)) {
            try {
                $pdo->beginTransaction();

                if ($_POST['accion'] === 'crear') {
                    $stmtCheck = $pdo->prepare(
                        "SELECT id FROM t_productos WHERE empresa_id = :empresa_id AND UPPER(nombre) = UPPER(:nombre) LIMIT 1"
                    );
                    $stmtCheck->execute([':empresa_id' => $empresaId, ':nombre' => $nombreProducto]);
                    if ($stmtCheck->fetch()) {
                        throw new RuntimeException("Ya existe un producto registrado con el nombre \"$nombreProducto\".");
                    }

                    $stmtIns = $pdo->prepare(
                        "INSERT INTO t_productos (empresa_id, categoria_id, nombre, activo) 
                         VALUES (:empresa_id, :categoria_id, :nombre, :activo)"
                    );
                    $stmtIns->execute([
                        ':empresa_id'    => $empresaId,
                        ':categoria_id'  => $categoriaId,
                        ':nombre'        => mb_strtoupper($nombreProducto, 'UTF-8'),
                        ':activo'        => 1,
                    ]);
                    $prodId = (int)$pdo->lastInsertId();
                    $mensajeExito = "Producto registrado exitosamente.";
                } else {
                    $stmtUpd = $pdo->prepare(
                        "UPDATE t_productos 
                         SET categoria_id = :categoria_id, nombre = :nombre, activo = :activo 
                         WHERE id = :id AND empresa_id = :empresa_id"
                    );
                    $stmtUpd->execute([
                        ':categoria_id' => $categoriaId,
                        ':nombre'       => mb_strtoupper($nombreProducto, 'UTF-8'),
                        ':activo'       => $activo,
                        ':id'           => $prodId,
                        ':empresa_id'   => $empresaId,
                    ]);
                    $mensajeExito = "Producto actualizado correctamente.";
                }

                // Procesar presentaciones asociadas
                $lineasPres = preg_split('/[\r\n,]+/', $presentacionesTexto);
                $presentacionesLimpias = [];
                foreach ($lineasPres as $lp) {
                    $lp = trim($lp);
                    if ($lp !== '') {
                        $presentacionesLimpias[] = mb_strtoupper($lp, 'UTF-8');
                    }
                }

                if (empty($presentacionesLimpias)) {
                    $presentacionesLimpias[] = 'SACOS';
                }

                $stmtDel = $pdo->prepare("DELETE FROM t_producto_presentaciones WHERE producto_id = :prod_id");
                $stmtDel->execute([':prod_id' => $prodId]);

                $stmtPresIns = $pdo->prepare(
                    "INSERT INTO t_producto_presentaciones (producto_id, nombre, activo) VALUES (:prod_id, :nombre, 1)"
                );
                foreach (array_unique($presentacionesLimpias) as $pNom) {
                    $stmtPresIns->execute([':prod_id' => $prodId, ':nombre' => $pNom]);
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error al guardar producto: ' . $e->getMessage();
            }
        }
    }
}

// 2. PROCESAR ALTERNAR ESTADO (ACTIVO / INACTIVO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'alternar_estado') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido.';
    } else {
        $idCambiar = (int)($_POST['id_producto'] ?? 0);
        $nuevoEstado = (int)($_POST['nuevo_estado'] ?? 0);

        try {
            $stmtEstado = $pdo->prepare(
                "UPDATE t_productos SET activo = :activo WHERE id = :id AND empresa_id = :empresa_id"
            );
            $stmtEstado->execute([
                ':activo'     => $nuevoEstado,
                ':id'         => $idCambiar,
                ':empresa_id' => $empresaId,
            ]);
            $mensajeExito = ($nuevoEstado === 1) ? 'Producto activado.' : 'Producto desactivado.';
        } catch (Exception $e) {
            $error = 'Error al cambiar estado: ' . $e->getMessage();
        }
    }
}

// 3. CONSULTA DE PRODUCTOS CON CATEGORÍA Y CONTEO DE PRESENTACIONES
$busqueda = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT p.id, p.categoria_id, p.nombre, p.activo, 
               COALESCE(c.nombre, 'Sin categoría') AS categoria_nombre,
               COUNT(pr.id) AS total_presentaciones,
               GROUP_CONCAT(pr.nombre ORDER BY pr.id SEPARATOR ', ') AS lista_presentaciones
        FROM t_productos p
        LEFT JOIN t_categorias c ON c.id = p.categoria_id
        LEFT JOIN t_producto_presentaciones pr ON pr.producto_id = p.id AND pr.activo = 1
        WHERE p.empresa_id = :empresa_id";
$params = [':empresa_id' => $empresaId];

if ($busqueda !== '') {
    $sql .= " AND (p.nombre LIKE :busq OR c.nombre LIKE :busq)";
    $params[':busq'] = '%' . $busqueda . '%';
}

$sql .= " GROUP BY p.id, p.categoria_id, p.nombre, p.activo, c.nombre ORDER BY p.nombre ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$productos = $stmt->fetchAll();

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Productos', 'productos');
?>

<div style="max-width: 1100px; margin: 0 auto;">

    <!-- ENCABEZADO IDÉNTICO A LA CAPTURA DEL USUARIO -->
    <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
                CATÁLOGO
            </span>
            <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.25rem 0 0.15rem 0; color: var(--text-main); line-height: 1.1;">
                Productos
            </h1>
            <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <button type="button" class="btn btn-primary" onclick="abrirModalNuevo()" style="font-size: 0.95rem; font-weight: 600; padding: 0.65rem 1.35rem; border-radius: var(--radius-sm); background: #0b57d0; box-shadow: none;">
            Nuevo producto
        </button>
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

    <!-- TARJETA PRINCIPAL DEL CATÁLOGO -->
    <div class="card" style="padding: 2rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">

        <!-- BUSCADOR SUPERIOR -->
        <div style="margin-bottom: 2rem;">
            <label for="buscar_producto" class="form-label" style="font-size: 0.88rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.45rem;">
                Buscar producto
            </label>
            <form method="GET" action="productos.php" style="display: flex; gap: 0.65rem; align-items: center; max-width: 420px;">
                <input 
                    type="text" 
                    id="buscar_producto" 
                    name="q" 
                    class="form-control" 
                    style="border-radius: 6px; height: 40px; min-height: 40px;"
                    value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>"
                >
                <button type="submit" class="btn btn-secondary" style="height: 40px; min-height: 40px; padding: 0 1.25rem; font-weight: 600; border-radius: 6px; background: #e8f0fe; color: #1e40af; border: none;">
                    Buscar
                </button>
                <?php if ($busqueda !== ''): ?>
                    <a href="productos.php" class="btn btn-secondary" style="height: 40px; min-height: 40px; padding: 0 0.85rem;" title="Limpiar búsqueda">&times;</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- TABLA DE PRODUCTOS CON COLUMNA CATEGORÍA -->
        <div class="table-responsive" style="border: none; background: transparent;">
            <table class="table" style="border-collapse: separate; border-spacing: 0; min-width: 100%;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 34%;">
                            Producto
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 22%;">
                            Categoría
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 16%;">
                            Presentaciones
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 14%;">
                            Estado
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 14%;">
                            Acción
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productos)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                No se encontraron productos registrados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productos as $p): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 1.15rem 0.5rem; font-weight: 800; font-size: 0.95rem; letter-spacing: 0.3px; color: var(--text-main);">
                                    <?= htmlspecialchars($p['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="padding: 1.15rem 0.5rem; font-size: 0.92rem; color: var(--text-main);">
                                    <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: var(--primary); font-size: 0.8rem;">
                                        <?= htmlspecialchars($p['categoria_nombre'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td style="padding: 1.15rem 0.5rem; color: var(--text-main); font-size: 0.95rem;">
                                    <span title="<?= htmlspecialchars($p['lista_presentaciones'] ?: 'Sin presentaciones') ?>">
                                        <?= (int)$p['total_presentaciones'] ?>
                                    </span>
                                </td>
                                <td style="padding: 1.15rem 0.5rem; color: var(--text-main); font-size: 0.95rem;">
                                    <?= $p['activo'] ? 'Activo' : 'Inactivo' ?>
                                </td>
                                <td style="padding: 1.15rem 0.5rem;">
                                    <button 
                                        type="button" 
                                        style="background: none; border: none; padding: 0; color: #1e40af; font-size: 0.95rem; font-weight: 500; cursor: pointer; text-decoration: underline; text-underline-offset: 3px;"
                                        onclick='abrirModalEditar(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    >
                                        Editar
                                    </button>
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
     MODAL FLOTANTE / FORMULARIO CREAR Y EDITAR PRODUCTO
     ======================================================================= -->
<div id="modalProducto" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="max-width: 580px; width: 100%; max-height: 90vh; overflow-y: auto; margin: 0; box-shadow: var(--shadow-lg);">
        <div class="card-title" style="margin-bottom: 1.25rem;">
            <span id="modalProdTitulo">➕ Nuevo Producto</span>
            <button type="button" class="btn btn-secondary" style="padding: 0.2rem 0.5rem; min-height: 28px;" onclick="cerrarModal()">&times;</button>
        </div>

        <form method="POST" action="productos.php" autocomplete="off" id="formProducto">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" id="prodAccion" value="crear">
            <input type="hidden" name="id_producto" id="prodId" value="0">

            <div class="form-group">
                <label for="prod_categoria" class="form-label">Categoría del Producto</label>
                <select id="prod_categoria" name="categoria_id" class="form-control">
                    <option value="">-- Sin categoría asignada --</option>
                    <?php foreach ($listaCategorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>">
                            <?= htmlspecialchars($cat['nombre'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: var(--text-muted); font-size: 0.78rem;">Agrupa el producto para reportes consolidados.</small>
            </div>

            <div class="form-group">
                <label for="prod_nombre" class="form-label">Nombre del Producto <span style="color: var(--danger);">*</span></label>
                <input 
                    type="text" 
                    id="prod_nombre" 
                    name="nombre" 
                    class="form-control" 
                    placeholder="Ejemplo: CAFE ORO SHG" 
                    maxlength="100" 
                    required
                >
            </div>

            <div class="form-group">
                <label for="prod_presentaciones" class="form-label">Presentaciones / Empaques</label>
                <textarea 
                    id="prod_presentaciones" 
                    name="presentaciones" 
                    class="form-control" 
                    rows="3" 
                    placeholder="Ingresa una por línea o separadas por comas (Ej: SACOS, GRANEL, QUINTALES)"
                ></textarea>
                <small style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-top: 0.35rem;">
                    Estas presentaciones aparecerán en el selector de la Primera Pesada.
                </small>
            </div>

            <div class="form-group" id="divEstadoActivo" style="display: none;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="activo" id="prod_activo" value="1" style="width: 18px; height: 18px;">
                    <span style="font-weight: 600;">Producto Activo para Pesaje</span>
                </label>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; align-items: center; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-lg" id="btnGuardarProd">
                    Guardar Producto
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const modalProd = document.getElementById('modalProducto');

function abrirModalNuevo() {
    document.getElementById('modalProdTitulo').innerText = '➕ Nuevo Producto';
    document.getElementById('prodAccion').value = 'crear';
    document.getElementById('prodId').value = '0';
    document.getElementById('prod_categoria').value = '';
    document.getElementById('prod_nombre').value = '';
    document.getElementById('prod_presentaciones').value = 'SACOS\nGRANEL';
    document.getElementById('divEstadoActivo').style.display = 'none';
    document.getElementById('btnGuardarProd').innerText = 'Guardar Producto';
    modalProd.style.display = 'flex';
    document.getElementById('prod_nombre').focus();
}

function abrirModalEditar(data) {
    document.getElementById('modalProdTitulo').innerText = '✏️ Editar Producto: ' + data.nombre;
    document.getElementById('prodAccion').value = 'editar';
    document.getElementById('prodId').value = data.id;
    document.getElementById('prod_categoria').value = data.categoria_id || '';
    document.getElementById('prod_nombre').value = data.nombre || '';
    document.getElementById('prod_presentaciones').value = data.lista_presentaciones ? data.lista_presentaciones.replace(/, /g, '\n') : '';
    document.getElementById('divEstadoActivo').style.display = 'block';
    document.getElementById('prod_activo').checked = (parseInt(data.activo) === 1);
    document.getElementById('btnGuardarProd').innerText = 'Actualizar Cambios';
    modalProd.style.display = 'flex';
    document.getElementById('prod_nombre').focus();
}

function cerrarModal() {
    modalProd.style.display = 'none';
}

window.onclick = function(event) {
    if (event.target === modalProd) {
        cerrarModal();
    }
}
</script>

<?php
renderizarPie();
