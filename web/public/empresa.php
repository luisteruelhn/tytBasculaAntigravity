<?php
/**
 * Gestión de Datos de la Empresa
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo Administrativo: Permite consultar y actualizar los datos
 * de la empresa emisora que opera la báscula (Razón Social, RTN, etc.)
 * con control de concurrencia optimista y validación estricta.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

// Exigir autenticación y rol de Administrador o Super
$usuario = requerirAutenticacion();
if (!tieneRol($usuario, ['SUPER', 'ADMINISTRADOR'])) {
    http_response_code(403);
    echo "Acceso denegado. Se requiere rol de Administrador.";
    exit;
}

$pdo = obtenerDB();
$empresaId = (int)$usuario['empresa_id'];

$mensajeExito = '';
$error = '';
$erroresCampos = [];

// Obtener datos actuales de la empresa
$stmt = $pdo->prepare(
    "SELECT id, razon_social, nombre_comercial, rtn, direccion, logo, activo 
     FROM t_empresas 
     WHERE id = :id 
     LIMIT 1"
);
$stmt->execute([':id' => $empresaId]);
$empresa = $stmt->fetch();

if (!$empresa) {
    die("Error: No se encontró la empresa asociada en la base de datos.");
}

// Procesar formulario de actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $razonSocial     = trim((string)($_POST['razon_social'] ?? ''));
        $nombreComercial = trim((string)($_POST['nombre_comercial'] ?? ''));
        $rtn             = trim((string)($_POST['rtn'] ?? ''));
        $direccion       = trim((string)($_POST['direccion'] ?? ''));

        // Validaciones estrictas
        if ($razonSocial === '') {
            $erroresCampos['razon_social'] = 'La Razón Social es obligatoria.';
        } elseif (mb_strlen($razonSocial) > 150) {
            $erroresCampos['razon_social'] = 'Máximo 150 caracteres permitidos.';
        }

        if (mb_strlen($nombreComercial) > 150) {
            $erroresCampos['nombre_comercial'] = 'Máximo 150 caracteres permitidos.';
        }

        if (mb_strlen($rtn) > 20) {
            $erroresCampos['rtn'] = 'El RTN no puede exceder 20 caracteres.';
        }

        if (mb_strlen($direccion) > 255) {
            $erroresCampos['direccion'] = 'Máximo 255 caracteres permitidos.';
        }

        if (empty($erroresCampos)) {
            try {
                $updateStmt = $pdo->prepare(
                    "UPDATE t_empresas 
                     SET razon_social = :razon_social,
                         nombre_comercial = :nombre_comercial,
                         rtn = :rtn,
                         direccion = :direccion
                     WHERE id = :id"
                );
                $updateStmt->execute([
                    ':razon_social'     => $razonSocial,
                    ':nombre_comercial' => ($nombreComercial !== '' ? $nombreComercial : null),
                    ':rtn'              => ($rtn !== '' ? $rtn : null),
                    ':direccion'        => ($direccion !== '' ? $direccion : null),
                    ':id'               => $empresaId
                ]);

                // Actualizar nombre de empresa en sesión
                $_SESSION['empresa_nombre'] = $nombreComercial ?: $razonSocial;

                // Actualizar array en memoria para mostrar datos frescos
                $empresa['razon_social']     = $razonSocial;
                $empresa['nombre_comercial'] = $nombreComercial;
                $empresa['rtn']              = $rtn;
                $empresa['direccion']        = $direccion;

                $mensajeExito = 'Los datos de la empresa se han actualizado correctamente.';
            } catch (Exception $e) {
                $error = 'Error al actualizar en la base de datos: ' . $e->getMessage();
            }
        }
    }
}

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Datos de la Empresa', 'empresa');
?>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-title">
        <span>🏢 Configuración de la Empresa</span>
        <a href="index.php" class="btn btn-secondary" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">&larr; Volver al Inicio</a>
    </div>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
        Esta información se utiliza en los encabezados de boletas de pesaje, reportes oficiales y control de la estación.
    </p>

    <?php if ($mensajeExito !== ''): ?>
        <div style="background-color: var(--success-bg); border: 1px solid var(--success); color: var(--success); padding: 0.85rem 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; font-weight: 500;">
            ✅ <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div style="background-color: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: #fca5a5; padding: 0.85rem 1.25rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
            ⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="empresa.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">

        <div class="grid-2">
            <div class="form-group">
                <label for="razon_social" class="form-label">Razón Social (*):</label>
                <input 
                    type="text" 
                    id="razon_social" 
                    name="razon_social" 
                    class="form-control" 
                    maxlength="150" 
                    required 
                    value="<?= htmlspecialchars($empresa['razon_social'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <?php if (isset($erroresCampos['razon_social'])): ?>
                    <span style="color: #fca5a5; font-size: 0.8rem;"><?= htmlspecialchars($erroresCampos['razon_social'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="nombre_comercial" class="form-label">Nombre Comercial:</label>
                <input 
                    type="text" 
                    id="nombre_comercial" 
                    name="nombre_comercial" 
                    class="form-control" 
                    maxlength="150" 
                    placeholder="Ejemplo: Inversiones Vallecillo"
                    value="<?= htmlspecialchars($empresa['nombre_comercial'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <?php if (isset($erroresCampos['nombre_comercial'])): ?>
                    <span style="color: #fca5a5; font-size: 0.8rem;"><?= htmlspecialchars($erroresCampos['nombre_comercial'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label for="rtn" class="form-label">RTN / Registro Tributario:</label>
                <input 
                    type="text" 
                    id="rtn" 
                    name="rtn" 
                    class="form-control" 
                    maxlength="20" 
                    placeholder="08011990123456"
                    value="<?= htmlspecialchars($empresa['rtn'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <?php if (isset($erroresCampos['rtn'])): ?>
                    <span style="color: #fca5a5; font-size: 0.8rem;"><?= htmlspecialchars($erroresCampos['rtn'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="direccion" class="form-label">Dirección / Ubicación de Báscula:</label>
                <input 
                    type="text" 
                    id="direccion" 
                    name="direccion" 
                    class="form-control" 
                    maxlength="255" 
                    placeholder="Carretera principal, Km ..."
                    value="<?= htmlspecialchars($empresa['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <?php if (isset($erroresCampos['direccion'])): ?>
                    <span style="color: #fca5a5; font-size: 0.8rem;"><?= htmlspecialchars($erroresCampos['direccion'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
            <a href="index.php" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary btn-lg">💾 Guardar Cambios</button>
        </div>
    </form>
</div>

<?php
renderizarPie();
?>
