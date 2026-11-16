<?php
/**
 * Gestión de Datos de la Empresa
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo Administrativo: Permite consultar y actualizar los datos
 * de la empresa emisora que opera la báscula (Razón Social, RTN, etc.)
 * con diseño moderno SaaS industrial, validación estricta y CSRF.
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

<div style="max-width: 920px; margin: 0 auto;">

    <!-- ENCABEZADO DE PÁGINA MODERNO -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 42px; height: 42px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-light), rgba(59, 130, 246, 0.25)); display: flex; align-items: center; justify-content: center; color: var(--primary);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/>
                        <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/>
                        <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/>
                        <path d="M10 6h4"/>
                        <path d="M10 10h4"/>
                        <path d="M10 14h4"/>
                        <path d="M10 18h4"/>
                    </svg>
                </div>
                <div>
                    <h2 style="font-size: 1.5rem; font-weight: 800; margin: 0; line-height: 1.2;">Perfil de Empresa</h2>
                    <span style="font-size: 0.85rem; color: var(--text-muted);">Configuración de datos institucionales para boletas y reportes de báscula</span>
                </div>
            </div>
        </div>
        <a href="index.php" class="btn btn-secondary">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Volver al Inicio
        </a>
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

    <!-- FORMULARIO PRINCIPAL -->
    <form method="POST" action="empresa.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">

        <!-- SECCIÓN 1: IDENTIFICACIÓN LEGAL Y COMERCIAL -->
        <div class="form-section">
            <div class="form-section-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                </svg>
                Identificación de la Empresa
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="razon_social" class="form-label">
                        Razón Social <span style="color: var(--danger); font-weight: 700;">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="razon_social" 
                        name="razon_social" 
                        class="form-control" 
                        maxlength="150" 
                        required 
                        placeholder="Nombre legal completo"
                        value="<?= htmlspecialchars($empresa['razon_social'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <?php if (isset($erroresCampos['razon_social'])): ?>
                        <span style="color: var(--danger); font-size: 0.8rem; margin-top: 0.35rem; display: block;">
                            <?= htmlspecialchars($erroresCampos['razon_social'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.3rem;">
                        Aparecerá en el encabezado de las boletas impresas.
                    </small>
                </div>

                <div class="form-group">
                    <label for="nombre_comercial" class="form-label">
                        Nombre Comercial / Marca
                    </label>
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
                        <span style="color: var(--danger); font-size: 0.8rem; margin-top: 0.35rem; display: block;">
                            <?= htmlspecialchars($erroresCampos['nombre_comercial'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.3rem;">
                        Nombre visible en la barra lateral y bienvenida del sistema.
                    </small>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 2: REGISTRO TRIBUTARIO Y UBICACIÓN FÍSICA -->
        <div class="form-section">
            <div class="form-section-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                Tributación y Ubicación de la Estación
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="rtn" class="form-label">
                        RTN / Registro Tributario
                    </label>
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
                        <span style="color: var(--danger); font-size: 0.8rem; margin-top: 0.35rem; display: block;">
                            <?= htmlspecialchars($erroresCampos['rtn'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.3rem;">
                        Número de identificación tributaria para constancias fiscales.
                    </small>
                </div>

                <div class="form-group">
                    <label for="direccion" class="form-label">
                        Dirección o Ubicación de la Báscula
                    </label>
                    <input 
                        type="text" 
                        id="direccion" 
                        name="direccion" 
                        class="form-control" 
                        maxlength="255" 
                        placeholder="Carretera al norte, Km 15, Predio industrial..."
                        value="<?= htmlspecialchars($empresa['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <?php if (isset($erroresCampos['direccion'])): ?>
                        <span style="color: var(--danger); font-size: 0.8rem; margin-top: 0.35rem; display: block;">
                            <?= htmlspecialchars($erroresCampos['direccion'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                    <small style="color: var(--text-muted); font-size: 0.78rem; display: block; margin-top: 0.3rem;">
                        Dirección física donde opera la báscula de pesaje.
                    </small>
                </div>
            </div>
        </div>

        <!-- BARRA INFERIOR DE ACCIONES -->
        <div style="display: flex; gap: 1rem; justify-content: flex-end; align-items: center; margin-top: 1.5rem; background: var(--bg-surface); padding: 1.25rem 1.5rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); box-shadow: var(--shadow-sm);">
            <a href="index.php" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                Guardar Información
            </button>
        </div>
    </form>
</div>

<?php
renderizarPie();
