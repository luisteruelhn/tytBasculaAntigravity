<?php
/**
 * Gestión de Usuarios y Roles (RBAC)
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo Administrativo: Permite listar los usuarios de la empresa,
 * crear nuevas cuentas y activar/desactivar operadores o supervisores.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
if (!tieneRol($usuarioActual, ['SUPER', 'ADMINISTRADOR'])) {
    http_response_code(403);
    echo "Acceso denegado. Se requiere rol de Administrador.";
    exit;
}

$pdo = obtenerDB();
$empresaId = (int)$usuarioActual['empresa_id'];
$esSuper = ($usuarioActual['rol'] === 'SUPER');

$rolesDisponibles = [
    'OPERADOR'      => 'Operador (Pesaje y Boletas)',
    'SUPERVISOR'    => 'Supervisor (Autorizaciones y Reportes)',
    'ADMINISTRADOR' => 'Administrador (Control Total de Empresa)',
];

$mensajeExito = '';
$error = '';
$valores = ['nombre' => '', 'usuario' => '', 'email' => '', 'rol' => 'OPERADOR'];

// Procesar Creación de Usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'La sesión del formulario venció. Por favor recarga e intenta de nuevo.';
    } else {
        $nombre       = trim((string)($_POST['nombre'] ?? ''));
        $usuarioLogin = trim((string)($_POST['usuario'] ?? ''));
        $email        = trim((string)($_POST['email'] ?? ''));
        $rol          = trim((string)($_POST['rol'] ?? 'OPERADOR'));
        $clave        = (string)($_POST['clave'] ?? '');
        $confirmar    = (string)($_POST['confirmacion'] ?? '');

        $valores = ['nombre' => $nombre, 'usuario' => $usuarioLogin, 'email' => $email, 'rol' => $rol];

        if ($nombre === '' || $usuarioLogin === '' || $email === '' || $clave === '') {
            $error = 'Por favor completa todos los campos obligatorios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El correo electrónico no tiene un formato válido.';
        } elseif (mb_strlen($usuarioLogin) > 80 || preg_match('/[\x00-\x1F\x7F]/', $usuarioLogin)) {
            $error = 'El nombre de usuario no es válido o supera los 80 caracteres.';
        } elseif (!isset($rolesDisponibles[$rol])) {
            $error = 'El rol seleccionado no es válido.';
        } elseif (strlen($clave) < 4) {
            $error = 'La contraseña debe tener al menos 4 caracteres.';
        } elseif ($clave !== $confirmar) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            try {
                // Verificar unicidad de usuario y correo
                $stmtExiste = $pdo->prepare(
                    "SELECT id FROM t_usuarios WHERE usuario = :usuario LIMIT 1"
                );
                $stmtExiste->execute([':usuario' => $usuarioLogin]);
                if ($stmtExiste->fetch()) {
                    $error = 'El nombre de usuario "' . htmlspecialchars($usuarioLogin) . '" ya está en uso.';
                } else {
                    // Generar hash seguro con Argon2id o Bcrypt
                    $claveHash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);

                    $stmtInsert = $pdo->prepare(
                        "INSERT INTO t_usuarios (empresa_id, nombre, usuario, email, clave_hash, rol, activo)
                         VALUES (:empresa_id, :nombre, :usuario, :email, :clave_hash, :rol, 1)"
                    );
                    $stmtInsert->execute([
                        ':empresa_id'  => $empresaId,
                        ':nombre'      => $nombre,
                        ':usuario'     => $usuarioLogin,
                        ':email'       => $email,
                        ':clave_hash'  => $claveHash,
                        ':rol'         => $rol,
                    ]);

                    $mensajeExito = "El usuario \"$usuarioLogin\" ha sido creado exitosamente.";
                    $valores = ['nombre' => '', 'usuario' => '', 'email' => '', 'rol' => 'OPERADOR'];
                }
            } catch (Exception $e) {
                $error = 'Error al registrar en la base de datos: ' . $e->getMessage();
            }
        }
    }
}

// Procesar Cambio de Estado (Activar / Desactivar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'alternar_estado') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido.';
    } else {
        $idCambiar = (int)($_POST['id_usuario'] ?? 0);
        $nuevoEstado = (int)($_POST['nuevo_estado'] ?? 0);

        // No permitir desactivarse a uno mismo
        if ($idCambiar === (int)$usuarioActual['id']) {
            $error = 'No puedes desactivar tu propia cuenta activa.';
        } else {
            try {
                $stmtEstado = $pdo->prepare(
                    "UPDATE t_usuarios SET activo = :activo WHERE id = :id AND empresa_id = :empresa_id"
                );
                $stmtEstado->execute([
                    ':activo'     => $nuevoEstado,
                    ':id'         => $idCambiar,
                    ':empresa_id' => $empresaId,
                ]);
                $mensajeExito = ($nuevoEstado === 1) ? 'Usuario activado.' : 'Usuario desactivado.';
            } catch (Exception $e) {
                $error = 'Error al actualizar estado: ' . $e->getMessage();
            }
        }
    }
}

// Consultar lista de usuarios de la empresa
$stmtUsuarios = $pdo->prepare(
    "SELECT id, nombre, usuario, email, rol, activo 
     FROM t_usuarios 
     WHERE empresa_id = :empresa_id 
     ORDER BY activo DESC, rol ASC, nombre ASC"
);
$stmtUsuarios->execute([':empresa_id' => $empresaId]);
$listaUsuarios = $stmtUsuarios->fetchAll();

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Usuarios y Roles', 'usuarios');
?>

<div class="card">
    <div class="card-title">
        <span>👥 Usuarios de la Empresa</span>
        <a href="#formNuevoUsuario" class="btn btn-primary" style="font-size: 0.85rem; padding: 0.4rem 0.85rem;">
            + Nuevo Usuario
        </a>
    </div>

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

    <!-- TABLA DE USUARIOS ACTIVOS E INACTIVOS -->
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Nombre Completo</th>
                    <th>Usuario (Login)</th>
                    <th>Correo</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($listaUsuarios)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">No hay usuarios registrados.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($listaUsuarios as $usr): ?>
                        <tr>
                            <td style="font-weight: 600;">
                                <?= htmlspecialchars($usr['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ((int)$usr['id'] === (int)$usuarioActual['id']): ?>
                                    <span style="font-size: 0.72rem; color: var(--primary); margin-left: 0.35rem;">(Tú)</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= htmlspecialchars($usr['usuario'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= htmlspecialchars($usr['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: var(--primary);">
                                    <?= htmlspecialchars($usr['rol'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($usr['activo']): ?>
                                    <span class="badge badge-estable">ACTIVO</span>
                                <?php else: ?>
                                    <span class="badge badge-inestable">INACTIVO</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <?php if ((int)$usr['id'] !== (int)$usuarioActual['id']): ?>
                                    <form method="POST" action="usuarios.php" style="display: inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="alternar_estado">
                                        <input type="hidden" name="id_usuario" value="<?= (int)$usr['id'] ?>">
                                        <input type="hidden" name="nuevo_estado" value="<?= $usr['activo'] ? 0 : 1 ?>">
                                        <button 
                                            type="submit" 
                                            class="btn btn-secondary" 
                                            style="padding: 0.3rem 0.65rem; font-size: 0.8rem;"
                                            onclick="return confirm('¿Seguro que deseas <?= $usr['activo'] ? 'desactivar' : 'activar' ?> a este usuario?');"
                                        >
                                            <?= $usr['activo'] ? 'Desactivar' : 'Activar' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 0.8rem;">Sesión actual</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- FORMULARIO CREAR NUEVO USUARIO -->
<div class="card" id="formNuevoUsuario" style="margin-top: 2rem;">
    <h3 class="card-title">➕ Crear Nuevo Usuario</h3>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
        Crea credenciales para operadores o supervisores de la báscula.
    </p>

    <form method="POST" action="usuarios.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="accion" value="crear">

        <div class="grid-2">
            <div class="form-group">
                <label for="nombre" class="form-label">Nombre Completo (*):</label>
                <input 
                    type="text" 
                    id="nombre" 
                    name="nombre" 
                    class="form-control" 
                    placeholder="Ejemplo: Juan Pérez"
                    maxlength="150" 
                    required
                    value="<?= htmlspecialchars($valores['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label for="usuario" class="form-label">Nombre de Usuario / Login (*):</label>
                <input 
                    type="text" 
                    id="usuario" 
                    name="usuario" 
                    class="form-control" 
                    placeholder="Ejemplo: jperez"
                    maxlength="80" 
                    required
                    value="<?= htmlspecialchars($valores['usuario'], ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label for="email" class="form-label">Correo Electrónico (*):</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    class="form-control" 
                    placeholder="juan@empresa.com"
                    maxlength="254" 
                    required
                    value="<?= htmlspecialchars($valores['email'], ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="form-group">
                <label for="rol" class="form-label">Rol y Permisos (*):</label>
                <select id="rol" name="rol" class="form-control" required>
                    <?php foreach ($rolesDisponibles as $keyRol => $descRol): ?>
                        <option value="<?= htmlspecialchars($keyRol) ?>" <?= $valores['rol'] === $keyRol ? 'selected' : '' ?>>
                            <?= htmlspecialchars($descRol) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid-2">
            <div class="form-group">
                <label for="clave" class="form-label">Contraseña (*):</label>
                <input 
                    type="password" 
                    id="clave" 
                    name="clave" 
                    class="form-control" 
                    placeholder="Mínimo 4 caracteres"
                    maxlength="1024" 
                    required
                >
            </div>

            <div class="form-group">
                <label for="confirmacion" class="form-label">Confirmar Contraseña (*):</label>
                <input 
                    type="password" 
                    id="confirmacion" 
                    name="confirmacion" 
                    class="form-control" 
                    placeholder="Repite la contraseña"
                    maxlength="1024" 
                    required
                >
            </div>
        </div>

        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
            <button type="submit" class="btn btn-primary btn-lg">➕ Registrar Usuario</button>
        </div>
    </form>
</div>

<?php
renderizarPie();
?>
