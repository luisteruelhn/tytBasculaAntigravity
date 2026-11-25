<?php
/**
 * Gestión de Datos de la Empresa (Incluye Teléfono, Email y Logo)
 * Proyecto: tytBasculaAntigravity
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';
require_once dirname(__DIR__) . '/src/sync_nube.php';

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

// Obtener datos actuales
$stmt = $pdo->prepare(
    "SELECT id, razon_social, nombre_comercial, rtn, direccion, telefono, email, logo, activo 
     FROM t_empresas 
     WHERE id = :id 
     LIMIT 1"
);
$stmt->execute([':id' => $empresaId]);
$empresa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$empresa) {
    die("Error: No se encontró la empresa asociada en la base de datos.");
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $razonSocial     = trim((string)($_POST['razon_social'] ?? ''));
        $nombreComercial = trim((string)($_POST['nombre_comercial'] ?? ''));
        $rtn             = trim((string)($_POST['rtn'] ?? ''));
        $direccion       = trim((string)($_POST['direccion'] ?? ''));
        $telefono        = trim((string)($_POST['telefono'] ?? ''));
        $email           = trim((string)($_POST['email'] ?? ''));
        $logoPath        = $empresa['logo'];

        if ($razonSocial === '') {
            $erroresCampos['razon_social'] = 'La Razón Social es obligatoria.';
        }

        // Manejo de subida de Logo
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['logo']['tmp_name'];
            $fileName = $_FILES['logo']['name'];
            $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $permitidas = ['png', 'jpg', 'jpeg', 'svg', 'webp'];

            if (!in_array($fileExt, $permitidas, true)) {
                $erroresCampos['logo'] = 'Formato no permitido. Usa PNG, JPG, WEBP o SVG.';
            } else {
                $destDir = __DIR__ . '/assets/img/logos';
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0777, true);
                }
                $nuevoNombre = 'logo_empresa_' . $empresaId . '_' . time() . '.' . $fileExt;
                $destPath = $destDir . '/' . $nuevoNombre;
                if (move_uploaded_file($fileTmp, $destPath)) {
                    $logoPath = 'assets/img/logos/' . $nuevoNombre;
                } else {
                    $erroresCampos['logo'] = 'No se pudo guardar la imagen del logo en el servidor.';
                }
            }
        }

        if (empty($erroresCampos)) {
            try {
                $updateStmt = $pdo->prepare(
                    "UPDATE t_empresas 
                     SET razon_social = :razon_social,
                         nombre_comercial = :nombre_comercial,
                         rtn = :rtn,
                         direccion = :direccion,
                         telefono = :telefono,
                         email = :email,
                         logo = :logo
                     WHERE id = :id"
                );
                $updateStmt->execute([
                    ':razon_social'     => $razonSocial,
                    ':nombre_comercial' => ($nombreComercial !== '' ? $nombreComercial : null),
                    ':rtn'              => ($rtn !== '' ? $rtn : null),
                    ':direccion'        => ($direccion !== '' ? $direccion : null),
                    ':telefono'         => ($telefono !== '' ? $telefono : null),
                    ':email'            => ($email !== '' ? $email : null),
                    ':logo'             => $logoPath,
                    ':id'               => $empresaId
                ]);

                $_SESSION['empresa_nombre'] = $nombreComercial ?: $razonSocial;
                $empresa['razon_social']     = $razonSocial;
                $empresa['nombre_comercial'] = $nombreComercial;
                $empresa['rtn']              = $rtn;
                $empresa['direccion']        = $direccion;
                $empresa['telefono']         = $telefono;
                $empresa['email']            = $email;
                $empresa['logo']             = $logoPath;

                $mensajeExito = 'Los datos y logo de la empresa se han actualizado correctamente.';
                sincronizarCatalogosAutomatico('t_empresas');
            } catch (Exception $e) {
                $error = 'Error al actualizar en la base de datos: ' . $e->getMessage();
            }
        }
    }
}

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Datos de la Empresa', 'empresa');
?>

<div style="max-width: 920px; margin: 0 auto;">

    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h2 style="font-size: 1.5rem; font-weight: 800; margin: 0; line-height: 1.2;">Perfil de Empresa</h2>
            <span style="font-size: 0.85rem; color: var(--text-muted);">Configuración institucional para boletas de peso e informes oficiales</span>
        </div>
        <a href="index.php" class="btn btn-secondary">Volver al Inicio</a>
    </div>

    <?php if ($mensajeExito !== ''): ?>
        <div class="alert alert-success"><span>✅ <?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></span></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><span>⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span></div>
    <?php endif; ?>

    <form method="POST" action="empresa.php" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">

        <!-- LOGO Y DATOS PRINCIPALES -->
        <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.5rem;">
            <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0 0 1.25rem 0;">Logo Institucional</h3>
            
            <div style="display: flex; gap: 2rem; align-items: center; flex-wrap: wrap;">
                <div style="width: 140px; height: 100px; border: 2px dashed #cbd5e1; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #f8fafc; overflow: hidden;">
                    <?php if (!empty($empresa['logo']) && file_exists(__DIR__ . '/' . $empresa['logo'])): ?>
                        <img src="<?= htmlspecialchars($empresa['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="Logo Empresa" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    <?php else: ?>
                        <span style="color: #94a3b8; font-size: 0.8rem; font-weight: 600; text-align: center; padding: 0.5rem;">Sin Logo</span>
                    <?php endif; ?>
                </div>

                <div style="flex: 1; min-width: 250px;">
                    <label for="logo" class="form-label" style="font-weight: 600; font-size: 0.88rem;">Subir nuevo logo</label>
                    <input type="file" id="logo" name="logo" class="form-control" accept="image/png, image/jpeg, image/webp, image/svg+xml">
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.35rem;">
                        Aparecerá en el encabezado de las boletas impresas. Recomendado: formato horizontal PNG o JPG transparente.
                    </small>
                </div>
            </div>
        </div>

        <!-- IDENTIFICACIÓN -->
        <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 1.5rem;">
            <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0 0 1.25rem 0;">Identificación Legal</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.25rem;">
                <div>
                    <label for="razon_social" class="form-label" style="font-weight: 600;">Razón Social <span style="color: var(--danger);">*</span></label>
                    <input type="text" id="razon_social" name="razon_social" class="form-control" required value="<?= htmlspecialchars($empresa['razon_social'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                    <label for="nombre_comercial" class="form-label" style="font-weight: 600;">Nombre Comercial / Marca</label>
                    <input type="text" id="nombre_comercial" name="nombre_comercial" class="form-control" value="<?= htmlspecialchars($empresa['nombre_comercial'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div>
                <label for="rtn" class="form-label" style="font-weight: 600;">RTN / Identificación Tributaria</label>
                <input type="text" id="rtn" name="rtn" class="form-control" style="max-width: 48%;" value="<?= htmlspecialchars($empresa['rtn'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <!-- CONTACTO Y UBICACIÓN -->
        <div class="card" style="padding: 1.75rem 2rem; border-radius: 12px; border: 1px solid var(--border-color); background: var(--bg-surface); margin-bottom: 2rem;">
            <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0 0 1.25rem 0;">Contacto y Dirección (Para Boleta de Peso)</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.25rem;">
                <div>
                    <label for="telefono" class="form-label" style="font-weight: 600;">Teléfono</label>
                    <input type="text" id="telefono" name="telefono" class="form-control" placeholder="+504 2234-5678 / 9988-7766" value="<?= htmlspecialchars($empresa['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                    <label for="email" class="form-label" style="font-weight: 600;">Correo Electrónico</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="contacto@empresa.com" value="<?= htmlspecialchars($empresa['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div>
                <label for="direccion" class="form-label" style="font-weight: 600;">Dirección Física de la Báscula</label>
                <input type="text" id="direccion" name="direccion" class="form-control" placeholder="Predio Industrial, Carretera principal..." value="<?= htmlspecialchars($empresa['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <div style="display: flex; gap: 1rem; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary btn-lg" style="padding: 0.75rem 2rem; font-weight: 700; background: #0b57d0;">
                Guardar Información
            </button>
        </div>
    </form>
</div>

<?php
renderizarPie();
