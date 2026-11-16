<?php
/**
 * Catálogo Maestro de Categorías de Producto
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo de Catálogo:
 * Permite listar, crear, editar y activar/desactivar categorías de productos
 * en MariaDB (`t_categorias`). Permite clasificar productos como Café, Aceites, Granos Básicos, etc.
 * Con control de unicidad por empresa y protección CSRF.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
$rolActual = $usuarioActual['rol'] ?? 'OPERADOR';
$empresaId = (int)$usuarioActual['empresa_id'];
$empresaNombre = $usuarioActual['empresa_nombre'] ?? 'INVERSIONES VALLECILLO, SB';
$pdo = obtenerDB();

$mensajeExito = '';
$error = '';
$erroresCampos = [];

// 1. PROCESAR ALTA Y EDICIÓN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && in_array($_POST['accion'], ['crear', 'editar'], true)) {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $catId       = (int)($_POST['id_categoria'] ?? 0);
        $codigo      = mb_strtoupper(trim((string)($_POST['codigo'] ?? '')), 'UTF-8');
        $nombre      = mb_strtoupper(trim((string)($_POST['nombre'] ?? '')), 'UTF-8');
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $activo      = isset($_POST['activo']) ? 1 : 0;

        if ($codigo === '') {
            $erroresCampos['codigo'] = 'El código es obligatorio.';
        } elseif (mb_strlen($codigo) > 20) {
            $erroresCampos['codigo'] = 'Máximo 20 caracteres para el código.';
        }

        if ($nombre === '') {
            $erroresCampos['nombre'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($nombre) > 80) {
            $erroresCampos['nombre'] = 'Máximo 80 caracteres.';
        }

        if (mb_strlen($descripcion) > 150) {
            $erroresCampos['descripcion'] = 'Máximo 150 caracteres para la descripción.';
        }

        if (empty($erroresCampos)) {
            try {
                if ($_POST['accion'] === 'crear') {
                    $stmtCheck = $pdo->prepare(
                        "SELECT id FROM t_categorias WHERE empresa_id = :empresa_id AND codigo = :codigo LIMIT 1"
                    );
                    $stmtCheck->execute([':empresa_id' => $empresaId, ':codigo' => $codigo]);
                    if ($stmtCheck->fetch()) {
                        $error = "Ya existe una categoría con el código \"$codigo\".";
                    } else {
                        $stmtIns = $pdo->prepare(
                            "INSERT INTO t_categorias (empresa_id, codigo, nombre, descripcion, activo)
                             VALUES (:empresa_id, :codigo, :nombre, :descripcion, 1)"
                        );
                        $stmtIns->execute([
                            ':empresa_id'   => $empresaId,
                            ':codigo'       => $codigo,
                            ':nombre'       => $nombre,
                            ':descripcion'  => ($descripcion !== '' ? $descripcion : null),
                        ]);
                        $mensajeExito = "Categoría \"$nombre\" registrada con éxito.";
                    }
                } else {
                    $stmtUpd = $pdo->prepare(
                        "UPDATE t_categorias 
                         SET nombre = :nombre, descripcion = :descripcion, activo = :activo
                         WHERE id = :id AND empresa_id = :empresa_id"
                    );
                    $stmtUpd->execute([
                        ':nombre'      => $nombre,
                        ':descripcion' => ($descripcion !== '' ? $descripcion : null),
                        ':activo'      => $activo,
                        ':id'          => $catId,
                        ':empresa_id'  => $empresaId,
                    ]);
                    $mensajeExito = "Categoría \"$nombre\" actualizada correctamente.";
                }
            } catch (Exception $e) {
                $error = 'Error al guardar en base de datos: ' . $e->getMessage();
            }
        }
    }
}

// 2. PROCESAR CAMBIO DE ESTADO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'alternar_estado') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido.';
    } else {
        $idCambiar = (int)($_POST['id_categoria'] ?? 0);
        $nuevoEstado = (int)($_POST['nuevo_estado'] ?? 0);

        try {
            $stmtEstado = $pdo->prepare(
                "UPDATE t_categorias SET activo = :activo WHERE id = :id AND empresa_id = :empresa_id"
            );
            $stmtEstado->execute([
                ':activo'     => $nuevoEstado,
                ':id'         => $idCambiar,
                ':empresa_id' => $empresaId,
            ]);
            $mensajeExito = ($nuevoEstado === 1) ? 'Categoría activada.' : 'Categoría desactivada.';
        } catch (Exception $e) {
            $error = 'Error al cambiar estado: ' . $e->getMessage();
        }
    }
}

// 3. CONSULTA CON CONTEO DE PRODUCTOS ASOCIADOS
$busqueda = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT c.id, c.codigo, c.nombre, c.descripcion, c.activo,
               COUNT(p.id) AS total_productos
        FROM t_categorias c
        LEFT JOIN t_productos p ON p.categoria_id = c.id
        WHERE c.empresa_id = :empresa_id";
$params = [':empresa_id' => $empresaId];

if ($busqueda !== '') {
    $sql .= " AND (c.codigo LIKE :busq OR c.nombre LIKE :busq)";
    $params[':busq'] = '%' . $busqueda . '%';
}

$sql .= " GROUP BY c.id, c.codigo, c.nombre, c.descripcion, c.activo ORDER BY c.activo DESC, c.nombre ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categorias = $stmt->fetchAll();

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Categorías de Producto', 'categorias');
?>

<div style="max-width: 1000px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
                CATÁLOGO
            </span>
            <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.25rem 0 0.15rem 0; color: var(--text-main); line-height: 1.1;">
                Categorías de Producto
            </h1>
            <span style="font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                <?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <button type="button" class="btn btn-primary" onclick="abrirModalNuevo()" style="font-size: 0.95rem; font-weight: 600; padding: 0.65rem 1.35rem; border-radius: var(--radius-sm); background: #0b57d0; box-shadow: none;">
            Nueva categoría
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

    <!-- TARJETA PRINCIPAL -->
    <div class="card" style="padding: 2rem 2.25rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface);">

        <!-- BUSCADOR -->
        <div style="margin-bottom: 2rem;">
            <label for="buscar_cat" class="form-label" style="font-size: 0.88rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.45rem;">
                Buscar categoría
            </label>
            <form method="GET" action="categorias.php" style="display: flex; gap: 0.65rem; align-items: center; max-width: 420px;">
                <input 
                    type="text" 
                    id="buscar_cat" 
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
                    <a href="categorias.php" class="btn btn-secondary" style="height: 40px; min-height: 40px; padding: 0 0.85rem;" title="Limpiar">&times;</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- TABLA -->
        <div class="table-responsive" style="border: none; background: transparent;">
            <table class="table" style="border-collapse: separate; border-spacing: 0; min-width: 100%;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 20%;">
                            Código
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 35%;">
                            Categoría
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 15%;">
                            Productos
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 15%;">
                            Estado
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 15%;">
                            Acción
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categorias)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                No se encontraron categorías de producto registradas.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categorias as $item): ?>
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
                                    <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: var(--primary);">
                                        <?= (int)$item['total_productos'] ?> productos
                                    </span>
                                </td>
                                <td style="padding: 1.15rem 0.5rem; color: var(--text-main); font-size: 0.95rem;">
                                    <?= $item['activo'] ? 'Activo' : 'Inactivo' ?>
                                </td>
                                <td style="padding: 1.15rem 0.5rem;">
                                    <button 
                                        type="button" 
                                        style="background: none; border: none; padding: 0; color: #1e40af; font-size: 0.95rem; font-weight: 500; cursor: pointer; text-decoration: underline; text-underline-offset: 3px;"
                                        onclick='abrirModalEditar(<?= json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
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
     MODAL FLOTANTE / FORMULARIO CREAR Y EDITAR CATEGORÍA
     ======================================================================= -->
<div id="modalCat" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; margin: 0; box-shadow: var(--shadow-lg);">
        <div class="card-title" style="margin-bottom: 1.25rem;">
            <span id="modalCatTitulo">➕ Nueva Categoría</span>
            <button type="button" class="btn btn-secondary" style="padding: 0.2rem 0.5rem; min-height: 28px;" onclick="cerrarModal()">&times;</button>
        </div>

        <form method="POST" action="categorias.php" autocomplete="off" id="formCat">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" id="catAccion" value="crear">
            <input type="hidden" name="id_categoria" id="catId" value="0">

            <div class="form-group">
                <label for="cat_codigo" class="form-label">Código Único <span style="color: var(--danger);">*</span></label>
                <input 
                    type="text" 
                    id="cat_codigo" 
                    name="codigo" 
                    class="form-control" 
                    placeholder="Ejemplo: CAFE, ACEITES, GRANOS" 
                    maxlength="20" 
                    required
                    style="text-transform: uppercase;"
                >
            </div>

            <div class="form-group">
                <label for="cat_nombre" class="form-label">Nombre de Categoría <span style="color: var(--danger);">*</span></label>
                <input 
                    type="text" 
                    id="cat_nombre" 
                    name="nombre" 
                    class="form-control" 
                    placeholder="Ejemplo: CAFÉ / ACEITES / GRANOS BÁSICOS" 
                    maxlength="80" 
                    required
                    style="text-transform: uppercase;"
                >
            </div>

            <div class="form-group">
                <label for="cat_descripcion" class="form-label">Descripción u Observaciones</label>
                <input 
                    type="text" 
                    id="cat_descripcion" 
                    name="descripcion" 
                    class="form-control" 
                    placeholder="Detalles sobre los productos de esta categoría" 
                    maxlength="150"
                >
            </div>

            <div class="form-group" id="divCatActivo" style="display: none;">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="activo" id="cat_activo" value="1" style="width: 18px; height: 18px;">
                    <span style="font-weight: 600;">Categoría Activa</span>
                </label>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; align-items: center; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-lg" id="btnGuardarCat">
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const modalCat = document.getElementById('modalCat');

function abrirModalNuevo() {
    document.getElementById('modalCatTitulo').innerText = '➕ Nueva Categoría';
    document.getElementById('catAccion').value = 'crear';
    document.getElementById('catId').value = '0';
    document.getElementById('cat_codigo').value = '';
    document.getElementById('cat_codigo').readOnly = false;
    document.getElementById('cat_nombre').value = '';
    document.getElementById('cat_descripcion').value = '';
    document.getElementById('divCatActivo').style.display = 'none';
    document.getElementById('btnGuardarCat').innerText = 'Guardar';
    modalCat.style.display = 'flex';
    document.getElementById('cat_codigo').focus();
}

function abrirModalEditar(data) {
    document.getElementById('modalCatTitulo').innerText = '✏️ Editar: ' + data.nombre;
    document.getElementById('catAccion').value = 'editar';
    document.getElementById('catId').value = data.id;
    document.getElementById('cat_codigo').value = data.codigo;
    document.getElementById('cat_codigo').readOnly = true;
    document.getElementById('cat_nombre').value = data.nombre || '';
    document.getElementById('cat_descripcion').value = data.descripcion || '';
    document.getElementById('divCatActivo').style.display = 'block';
    document.getElementById('cat_activo').checked = (parseInt(data.activo) === 1);
    document.getElementById('btnGuardarCat').innerText = 'Actualizar';
    modalCat.style.display = 'flex';
    document.getElementById('cat_nombre').focus();
}

function cerrarModal() {
    modalCat.style.display = 'none';
}

window.onclick = function(event) {
    if (event.target === modalCat) {
        cerrarModal();
    }
}
</script>

<?php
renderizarPie();
