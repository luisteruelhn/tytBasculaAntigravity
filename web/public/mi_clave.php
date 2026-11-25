<?php
/**
 * Cambio de Contraseña de Usuario
 * Proyecto: tytBasculaAntigravity
 *
 * Permite a cualquier usuario autenticado cambiar su propia contraseña.
 * Valida la contraseña actual, formato y longitud mínima de la nueva.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';
require_once dirname(__DIR__) . '/src/sync_nube.php';

$usuarioActual = requerirAutenticacion();
$pdo = obtenerDB();
$userId = (int)$usuarioActual['id'];

$mensajeExito = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario expiró. Por favor recarga e intenta de nuevo.';
    } else {
        $claveActual   = (string)($_POST['clave_actual'] ?? '');
        $claveNueva    = (string)($_POST['clave_nueva'] ?? '');
        $claveConfirm  = (string)($_POST['clave_confirm'] ?? '');

        if ($claveActual === '' || $claveNueva === '' || $claveConfirm === '') {
            $error = 'Por favor completa todos los campos.';
        } elseif (strlen($claveNueva) < 4) {
            $error = 'La nueva contraseña debe tener al menos 4 caracteres.';
        } elseif ($claveNueva !== $claveConfirm) {
            $error = 'La nueva contraseña y su confirmación no coinciden.';
        } elseif (!verificarTasaLimite('clave_usr:' . $userId, 5, 300)) {
            http_response_code(429);
            $error = 'Demasiados intentos fallidos. Por seguridad, espera 5 minutos antes de volver a intentarlo.';
        } else {
            // Verificar contraseña actual en base de datos
            $stmt = $pdo->prepare("SELECT clave_hash FROM t_usuarios WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $cuenta = $stmt->fetch();

            if (!$cuenta || !password_verify($claveActual, $cuenta['clave_hash'])) {
                $error = 'La contraseña actual ingresada es incorrecta.';
            } else {
                limpiarTasaLimite('clave_usr:' . $userId);
                try {
                    $nuevoHash = password_hash($claveNueva, PASSWORD_BCRYPT, ['cost' => 12]);
                    $nuevaVisible = cifrarClaveVisible($claveNueva);
                    try {
                        $upd = $pdo->prepare("UPDATE t_usuarios SET clave_hash = :hash, clave_visible = :vis WHERE id = :id");
                        $upd->execute([':hash' => $nuevoHash, ':vis' => $nuevaVisible, ':id' => $userId]);
                    } catch (PDOException $eCol) {
                        $upd = $pdo->prepare("UPDATE t_usuarios SET clave_hash = :hash WHERE id = :id");
                        $upd->execute([':hash' => $nuevoHash, ':id' => $userId]);
                    }

                    $mensajeExito = '¡Tu contraseña ha sido cambiada exitosamente!';
                    if (($usuarioActual['rol'] ?? '') !== 'OPERADOR') {
                        sincronizarCatalogosAutomatico('t_usuarios');
                    }
                } catch (Exception $e) {
                    $error = 'Error al actualizar la contraseña: ' . $e->getMessage();
                }
            }
        }
    }
}

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Mi Contraseña', 'clave');
?>

<div style="max-width: 580px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-light), rgba(59, 130, 246, 0.25)); display: flex; align-items: center; justify-content: center; color: var(--primary);">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 800; margin: 0; line-height: 1.2;">Mi Contraseña</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Actualiza tu clave de acceso personal al sistema</span>
            </div>
        </div>
        <a href="index.php" class="btn btn-secondary">Volver al Inicio</a>
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

    <!-- TARJETA FORMULARIO -->
    <div class="card">
        <div class="card-title">
            <span>Cambio Seguro de Clave</span>
            <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">Usuario: <strong><?= htmlspecialchars($usuarioActual['usuario']) ?></strong></span>
        </div>

        <form method="POST" action="mi_clave.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="clave_actual" class="form-label">Contraseña Actual <span style="color: var(--danger);">*</span></label>
                <input 
                    type="password" 
                    id="clave_actual" 
                    name="clave_actual" 
                    class="form-control" 
                    required 
                    placeholder="Ingresa tu clave actual"
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="clave_nueva" class="form-label">Nueva Contraseña <span style="color: var(--danger);">*</span></label>
                <input 
                    type="password" 
                    id="clave_nueva" 
                    name="clave_nueva" 
                    class="form-control" 
                    required 
                    placeholder="Mínimo 4 caracteres"
                    minlength="4"
                >
                <small style="color: var(--text-muted); font-size: 0.78rem;">Usa una clave segura y fácil de recordar para tus operaciones de báscula.</small>
            </div>

            <div class="form-group">
                <label for="clave_confirm" class="form-label">Confirmar Nueva Contraseña <span style="color: var(--danger);">*</span></label>
                <input 
                    type="password" 
                    id="clave_confirm" 
                    name="clave_confirm" 
                    class="form-control" 
                    required 
                    placeholder="Repite la nueva contraseña"
                    minlength="4"
                >
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; align-items: center; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <a href="index.php" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary btn-lg">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    Actualizar Contraseña
                </button>
            </div>
        </form>
    </div>
</div>

<?php
renderizarPie();
