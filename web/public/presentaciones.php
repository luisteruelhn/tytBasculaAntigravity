<?php
/**
 * Catálogo Maestro de Taras de Empaque (MariaDB: t_taras)
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo de Catálogo:
 * Permite listar, registrar y editar el peso de sacos o empaques vacíos (taralbs)
 * Catálogo compartido de la instalación administrado por SUPER.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
$rolActual = $usuarioActual['rol'] ?? 'OPERADOR';
$esSuper = ($rolActual === 'SUPER');
$pdo = obtenerDB();

$mensajeExito = '';
$error = '';
$erroresCampos = [];

// 1. PROCESAR ALTA Y EDICIÓN (SOLO SUPER O ADMIN CON PERMISO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && in_array($_POST['accion'], ['crear', 'editar'], true)) {
    if (!$esSuper && $rolActual !== 'ADMINISTRADOR') {
        $error = 'Solo el perfil Super puede administrar los valores de las taras de empaque.';
    } elseif (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $idEmpaque = (int)($_POST['idempaque'] ?? 0);
        $nombre    = trim((string)($_POST['nombre'] ?? ''));
        $taralbsStr = trim((string)($_POST['taralbs'] ?? ''));

        if ($nombre === '') {
            $erroresCampos['nombre'] = 'El nombre del empaque o saco es obligatorio.';
        } elseif (mb_strlen($nombre) > 80) {
            $erroresCampos['nombre'] = 'Máximo 80 caracteres.';
        }

        if ($taralbsStr === '' || !is_numeric($taralbsStr) || (float)$taralbsStr < 0) {
            $erroresCampos['taralbs'] = 'Ingresa una tara unitaria válida en libras (>= 0).';
        }

        if (empty($erroresCampos)) {
            $taralbs = round((float)$taralbsStr, 3);
            try {
                if ($_POST['accion'] === 'crear') {
                    $stmtIns = $pdo->prepare(
                        "INSERT INTO t_taras (nombre, taralbs) VALUES (:nombre, :taralbs)"
                    );
                    $stmtIns->execute([
                        ':nombre'  => $nombre,
                        ':taralbs' => $taralbs
                    ]);
                    $mensajeExito = "Tara \"$nombre\" registrada con éxito.";
                } else {
                    $stmtUpd = $pdo->prepare(
                        "UPDATE t_taras SET nombre = :nombre, taralbs = :taralbs WHERE idempaque = :id"
                    );
                    $stmtUpd->execute([
                        ':nombre'  => $nombre,
                        ':taralbs' => $taralbs,
                        ':id'      => $idEmpaque
                    ]);
                    $mensajeExito = "Tara \"$nombre\" actualizada correctamente.";
                }
            } catch (Exception $e) {
                $error = 'Error en base de datos: ' . $e->getMessage();
            }
        }
    }
}

// 2. CONSULTA Y BÚSQUEDA
$busqueda = trim((string)($_GET['q'] ?? ''));
$sql = "SELECT idempaque, nombre, taralbs FROM t_taras WHERE 1=1";
$params = [];

if ($busqueda !== '') {
    $sql .= " AND nombre LIKE :busq";
    $params[':busq'] = '%' . $busqueda . '%';
}

$sql .= " ORDER BY taralbs ASC, nombre ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$taras = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Taras de Empaque', 'presentaciones');
?>

<div style="max-width: 1000px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
                CATÁLOGO
            </span>
            <h1 style="font-size: 2.2rem; font-weight: 800; margin: 0.25rem 0 0.25rem 0; color: var(--text-main); line-height: 1.1;">
                Taras de empaque
            </h1>
            <p style="margin: 0; font-size: 0.95rem; color: var(--text-muted);">
                Peso de empaques y sacos vacíos, en libras por unidad.
            </p>
        </div>

        <?php if ($esSuper || $rolActual === 'ADMINISTRADOR'): ?>
        <button type="button" class="btn btn-primary" onclick="abrirModalNuevaTara()" style="font-size: 0.95rem; font-weight: 600; padding: 0.65rem 1.35rem; border-radius: var(--radius-sm); background: #0b57d0; box-shadow: none;">
            Nueva tara
        </button>
        <?php endif; ?>
    </div>

    <!-- AVISO DE CATÁLOGO COMPARTIDO -->
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.5rem; font-size: 0.88rem; color: var(--text-muted);">
        Catálogo compartido por las empresas de esta instalación. Super administra sus valores.
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

        <!-- BUSCADOR -->
        <div style="margin-bottom: 2rem;">
            <label for="buscar_empaque" class="form-label" style="font-size: 0.88rem; font-weight: 600; color: var(--text-main); margin-bottom: 0.45rem;">
                Buscar empaque o saco
            </label>
            <form method="GET" action="presentaciones.php" style="display: flex; gap: 0.65rem; align-items: center; max-width: 420px;">
                <input 
                    type="text" 
                    id="buscar_empaque" 
                    name="q" 
                    class="form-control" 
                    style="border-radius: 6px; height: 40px; min-height: 40px;"
                    value="<?= htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8') ?>"
                >
                <button type="submit" class="btn btn-secondary" style="height: 40px; min-height: 40px; padding: 0 1.25rem; font-weight: 600; border-radius: 6px; background: #e8f0fe; color: #1e40af; border: none;">
                    Buscar
                </button>
                <?php if ($busqueda !== ''): ?>
                    <a href="presentaciones.php" class="btn btn-secondary" style="height: 40px; min-height: 40px; padding: 0 0.85rem;" title="Limpiar">&times;</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- TABLA FIEL AL DISEÑO -->
        <div class="table-responsive" style="border: none; background: transparent;">
            <table class="table" style="border-collapse: separate; border-spacing: 0; min-width: 100%;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 45%;">
                            Empaque o saco
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 35%;">
                            Tara unitaria (lb)
                        </th>
                        <th style="background: transparent; color: var(--text-main); font-size: 0.95rem; font-weight: 700; text-transform: none; padding: 0.85rem 0.5rem; width: 20%;">
                            Acción
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($taras)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                No se encontraron registros de taras de empaque.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($taras as $t): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 1.15rem 0.5rem; font-weight: 700; color: var(--text-main); font-size: 0.95rem;">
                                    <?= htmlspecialchars($t['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td style="padding: 1.15rem 0.5rem; font-weight: 500; color: var(--text-main); font-size: 0.95rem;">
                                    <?= number_format((float)$t['taralbs'], 3, '.', '') ?>
                                </td>
                                <td style="padding: 1.15rem 0.5rem;">
                                    <?php if ($esSuper || $rolActual === 'ADMINISTRADOR'): ?>
                                        <button 
                                            type="button" 
                                            style="background: none; border: none; padding: 0; color: #1e40af; font-size: 0.95rem; font-weight: 500; cursor: pointer; text-decoration: underline; text-underline-offset: 3px;"
                                            onclick='abrirModalEditarTara(<?= json_encode($t, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
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

<!-- =======================================================================
     MODAL DE ALTA Y EDICIÓN DE TARA DE EMPAQUE
     ======================================================================= -->
<div id="modalTara" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); z-index: 2000; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="max-width: 480px; width: 100%; margin: 0; box-shadow: var(--shadow-lg);">
        <div class="card-title" style="margin-bottom: 1.25rem;">
            <span id="modalTaraTitulo">Nueva tara de empaque</span>
            <button type="button" class="btn btn-secondary" style="padding: 0.2rem 0.5rem; min-height: 28px;" onclick="cerrarModalTara()">&times;</button>
        </div>

        <form method="POST" action="presentaciones.php" autocomplete="off" id="formTara">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" id="taraAccion" value="crear">
            <input type="hidden" name="idempaque" id="taraId" value="0">

            <div class="form-group">
                <label for="tara_nombre" class="form-label">Empaque o saco <span style="color: var(--danger);">*</span></label>
                <input 
                    type="text" 
                    id="tara_nombre" 
                    name="nombre" 
                    class="form-control" 
                    placeholder="Ej. Saco de 0.25 lb" 
                    maxlength="80" 
                    required
                >
            </div>

            <div class="form-group">
                <label for="tara_lbs" class="form-label">Tara unitaria en libras (lb) <span style="color: var(--danger);">*</span></label>
                <input 
                    type="number" 
                    step="0.001" 
                    min="0" 
                    id="tara_lbs" 
                    name="taralbs" 
                    class="form-control" 
                    placeholder="0.250" 
                    required
                >
                <small style="color: var(--text-muted); font-size: 0.78rem;">Precisión de hasta tres decimales (ejemplo: 0.250, 0.500, 1.000).</small>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; align-items: center; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalTara()">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-lg" id="btnGuardarTara">
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const modalTara = document.getElementById('modalTara');

function abrirModalNuevaTara() {
    document.getElementById('modalTaraTitulo').innerText = 'Nueva tara de empaque';
    document.getElementById('taraAccion').value = 'crear';
    document.getElementById('taraId').value = '0';
    document.getElementById('tara_nombre').value = '';
    document.getElementById('tara_lbs').value = '';
    document.getElementById('btnGuardarTara').innerText = 'Guardar';
    modalTara.style.display = 'flex';
    document.getElementById('tara_nombre').focus();
}

function abrirModalEditarTara(data) {
    document.getElementById('modalTaraTitulo').innerText = 'Editar tara: ' + data.nombre;
    document.getElementById('taraAccion').value = 'editar';
    document.getElementById('taraId').value = data.idempaque;
    document.getElementById('tara_nombre').value = data.nombre || '';
    document.getElementById('tara_lbs').value = parseFloat(data.taralbs).toFixed(3);
    document.getElementById('btnGuardarTara').innerText = 'Actualizar';
    modalTara.style.display = 'flex';
    document.getElementById('tara_lbs').focus();
}

function cerrarModalTara() {
    modalTara.style.display = 'none';
}

window.onclick = function(event) {
    if (event.target === modalTara) {
        cerrarModalTara();
    }
}
</script>

<?php
renderizarPie();
