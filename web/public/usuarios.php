<?php
/**
 * Gestión de Usuarios y Roles (RBAC Granular)
 * Proyecto: tytBasculaAntigravity
 *
 * Reglas de Permisos por Rol:
 * - SUPER y ADMINISTRADOR: Control total (ver todos, crear, editar datos, cambiar contraseñas de cualquier usuario, activar/desactivar).
 * - SUPERVISOR: Puede ver todos los usuarios de la empresa y crear nuevos usuarios (roles OPERADOR o SUPERVISOR), pero NO puede editar contraseñas de otros ni desactivar administradores.
 * - OPERADOR: Solo puede ver su propio usuario y acceder a cambiar su propia contraseña (enlace a mi_clave.php).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';
require_once dirname(__DIR__) . '/src/sync_nube.php';

$usuarioActual = requerirAutenticacion();
$rolActual = $usuarioActual['rol'] ?? 'OPERADOR';
if ($rolActual === 'REMOTO') {
    http_response_code(403);
    die('Acceso denegado: el usuario REMOTO es de solo consulta.');
}
$pdo = obtenerDB();
$empresaId = (int)$usuarioActual['empresa_id'];
$esSuper = ($rolActual === 'SUPER');
$esAdminOSuper = in_array($rolActual, ['SUPER', 'ADMINISTRADOR'], true);
$esSupervisor = ($rolActual === 'SUPERVISOR');
$esOperador = ($rolActual === 'OPERADOR');

// Si es operador, solo puede ver y gestionar su propio usuario
$puedeCrear = ($esAdminOSuper || $esSupervisor);
$puedeModificarOtros = $esAdminOSuper;
$puedeCambiarClaveOtros = $esAdminOSuper;

// Roles que puede crear según su privilegio
$rolesDisponibles = [];
if ($esAdminOSuper) {
    $rolesDisponibles = [
        'OPERADOR'      => 'Operador (Pesaje y Emisión de Boletas)',
        'SUPERVISOR'    => 'Supervisor (Autorizaciones Especiales y Reportes)',
        'ADMINISTRADOR' => 'Administrador (Control Total de Empresa)',
        'REMOTO'        => 'Remoto (Solo Consulta Web / Nube)',
    ];
} elseif ($esSupervisor) {
    // Supervisor solo puede crear Operadores, Supervisores o Remotos
    $rolesDisponibles = [
        'OPERADOR'      => 'Operador (Pesaje y Emisión de Boletas)',
        'SUPERVISOR'    => 'Supervisor (Autorizaciones Especiales y Reportes)',
        'REMOTO'        => 'Remoto (Solo Consulta Web / Nube)',
    ];
}

$mensajeExito = '';
$error = '';
$valores = ['nombre' => '', 'usuario' => '', 'email' => '', 'rol' => 'OPERADOR'];

// 1. ACCIÓN: CREAR USUARIO (Super, Administrador, Supervisor)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear') {
    if (!$puedeCrear) {
        $error = 'No tienes permisos para registrar nuevos usuarios.';
    } elseif (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
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
            $error = 'Por favor completa todos los campos obligatorios (*).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El correo electrónico no tiene un formato válido.';
        } elseif (mb_strlen($usuarioLogin) > 80 || preg_match('/[\x00-\x1F\x7F]/', $usuarioLogin)) {
            $error = 'El nombre de usuario contiene caracteres no permitidos o supera 80 letras.';
        } elseif (!isset($rolesDisponibles[$rol])) {
            $error = 'El rol seleccionado no está permitido para tu nivel de privilegios.';
        } elseif (strlen($clave) < 4) {
            $error = 'La contraseña debe tener un mínimo de 4 caracteres.';
        } elseif ($clave !== $confirmar) {
            $error = 'Las contraseñas no coinciden. Por favor verifícalas.';
        } else {
            try {
                $stmtExiste = $pdo->prepare("SELECT id FROM t_usuarios WHERE usuario = :usuario LIMIT 1");
                $stmtExiste->execute([':usuario' => $usuarioLogin]);
                if ($stmtExiste->fetch()) {
                    $error = 'El nombre de usuario "' . htmlspecialchars($usuarioLogin) . '" ya está en uso.';
                } else {
                    $claveHash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);
                    $claveVis  = cifrarClaveVisible($clave);
                    try {
                        $stmtInsert = $pdo->prepare(
                            "INSERT INTO t_usuarios (empresa_id, nombre, usuario, email, clave_hash, clave_visible, rol, activo)
                             VALUES (:empresa_id, :nombre, :usuario, :email, :clave_hash, :clave_visible, :rol, 1)"
                        );
                        $stmtInsert->execute([
                            ':empresa_id'    => $empresaId,
                            ':nombre'        => $nombre,
                            ':usuario'       => $usuarioLogin,
                            ':email'         => $email,
                            ':clave_hash'    => $claveHash,
                            ':clave_visible' => $claveVis,
                            ':rol'           => $rol,
                        ]);
                    } catch (PDOException $eCol) {
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
                    }

                    $mensajeExito = "El usuario \"$usuarioLogin\" ha sido creado exitosamente.";
                    $valores = ['nombre' => '', 'usuario' => '', 'email' => '', 'rol' => 'OPERADOR'];
                    sincronizarCatalogosAutomatico('t_usuarios');
                }
            } catch (Exception $e) {
                $error = 'Error al registrar usuario: ' . $e->getMessage();
            }
        }
    }
}

// 2. ACCIÓN: RESETEAR CONTRASEÑA DE UN USUARIO (Solo Super y Administrador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'reset_clave') {
    if (!$puedeCambiarClaveOtros) {
        $error = 'Acceso denegado: Solo el Administrador o Super pueden cambiar contraseñas de otros usuarios.';
    } elseif (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido.';
    } else {
        $idTarget = (int)($_POST['id_usuario'] ?? 0);
        $nuevaClave = (string)($_POST['nueva_clave'] ?? '');

        if ($nuevaClave === '' || strlen($nuevaClave) < 4) {
            $error = 'La nueva contraseña debe tener al menos 4 caracteres.';
        } else {
            try {
                $stmtTarget = $pdo->prepare("SELECT id, usuario, rol FROM t_usuarios WHERE id = :id AND empresa_id = :empresa_id LIMIT 1");
                $stmtTarget->execute([':id' => $idTarget, ':empresa_id' => $empresaId]);
                $usrObjetivo = $stmtTarget->fetch(PDO::FETCH_ASSOC);

                if (!$usrObjetivo) {
                    $error = 'No se encontró el usuario seleccionado.';
                } elseif ($usrObjetivo['rol'] === 'SUPER' && !$esSuper) {
                    $error = 'Solo un usuario SUPER puede editar la contraseña de una cuenta SUPER.';
                } else {
                    $nuevoHash = password_hash($nuevaClave, PASSWORD_BCRYPT, ['cost' => 12]);
                    $nuevaVis  = cifrarClaveVisible($nuevaClave);
                    try {
                        $stmtReset = $pdo->prepare("UPDATE t_usuarios SET clave_hash = :hash, clave_visible = :vis WHERE id = :id AND empresa_id = :empresa_id");
                        $stmtReset->execute([
                            ':hash'       => $nuevoHash,
                            ':vis'        => $nuevaVis,
                            ':id'         => $idTarget,
                            ':empresa_id' => $empresaId,
                        ]);
                    } catch (PDOException $eCol) {
                        $stmtReset = $pdo->prepare("UPDATE t_usuarios SET clave_hash = :hash WHERE id = :id AND empresa_id = :empresa_id");
                        $stmtReset->execute([
                            ':hash'       => $nuevoHash,
                            ':id'         => $idTarget,
                            ':empresa_id' => $empresaId,
                        ]);
                    }
                    $mensajeExito = 'Contraseña actualizada correctamente para @' . $usrObjetivo['usuario'] . '.';
                    sincronizarCatalogosAutomatico('t_usuarios');
                }
            } catch (Exception $e) {
                $error = 'Error al actualizar contraseña: ' . $e->getMessage();
            }
        }
    }
}

// 3. ACCIÓN: ACTIVAR / DESACTIVAR ESTADO (Solo Super y Administrador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'alternar_estado') {
    if (!$puedeModificarOtros) {
        $error = 'Acceso denegado: Solo el Administrador puede cambiar el estado de las cuentas.';
    } elseif (!validarCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Token de seguridad inválido.';
    } else {
        $idCambiar = (int)($_POST['id_usuario'] ?? 0);
        $nuevoEstado = (int)($_POST['nuevo_estado'] ?? 0);

        if ($idCambiar === (int)$usuarioActual['id']) {
            $error = 'No puedes desactivar tu propia cuenta activa con la que has iniciado sesión.';
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
                $mensajeExito = ($nuevoEstado === 1) ? 'Usuario activado exitosamente.' : 'Usuario desactivado del sistema.';
                sincronizarCatalogosAutomatico('t_usuarios');
            } catch (Exception $e) {
                $error = 'Error al actualizar estado del usuario: ' . $e->getMessage();
            }
        }
    }
}

// 4. CONSULTA DE USUARIOS
// Si es Operador: Solo ve su propio registro
// Si es Super / Administrador / Supervisor: Ve todos los usuarios de la empresa
if ($esOperador) {
    $stmtUsuarios = $pdo->prepare(
        "SELECT id, nombre, usuario, email, rol, activo 
         FROM t_usuarios 
         WHERE id = :id 
         LIMIT 1"
    );
    $stmtUsuarios->execute([':id' => (int)$usuarioActual['id']]);
} else {
    if ($esSuper) {
        try {
            $stmtUsuarios = $pdo->prepare(
                "SELECT id, nombre, usuario, email, rol, activo, clave_visible
                 FROM t_usuarios
                 WHERE empresa_id = :empresa_id
                 ORDER BY activo DESC, rol ASC, nombre ASC"
            );
            $stmtUsuarios->execute([':empresa_id' => $empresaId]);
        } catch (PDOException $eCol) {
            $stmtUsuarios = $pdo->prepare(
                "SELECT id, nombre, usuario, email, rol, activo
                 FROM t_usuarios
                 WHERE empresa_id = :empresa_id
                 ORDER BY activo DESC, rol ASC, nombre ASC"
            );
            $stmtUsuarios->execute([':empresa_id' => $empresaId]);
        }
    } else {
        $stmtUsuarios = $pdo->prepare(
            "SELECT id, nombre, usuario, email, rol, activo 
             FROM t_usuarios 
             WHERE empresa_id = :empresa_id 
             ORDER BY activo DESC, rol ASC, nombre ASC"
        );
        $stmtUsuarios->execute([':empresa_id' => $empresaId]);
    }
}
$listaUsuarios = $stmtUsuarios->fetchAll();

$tokenCsrf = obtenerCsrfToken();
renderizarEncabezado('Usuarios y Roles', 'usuarios');
?>

<div style="max-width: 1040px; margin: 0 auto;">

    <!-- ENCABEZADO -->
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="width: 44px; height: 44px; border-radius: var(--radius-md); background: linear-gradient(135deg, var(--primary-light), rgba(59, 130, 246, 0.25)); display: flex; align-items: center; justify-content: center; color: var(--primary);">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>
            <div>
                <h2 style="font-size: 1.5rem; font-weight: 800; margin: 0; line-height: 1.2;">
                    <?= $esOperador ? 'Mi Perfil de Usuario' : 'Control de Usuarios y Roles' ?>
                </h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">
                    <?= $esOperador 
                        ? 'Información de tu cuenta y acceso al cambio de contraseña personal' 
                        : 'Administración de cuentas con control de permisos granular (RBAC)' 
                    ?>
                </span>
            </div>
        </div>

        <div style="display: flex; gap: 0.75rem; align-items: center;">
            <a href="mi_clave.php" class="btn btn-secondary">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
                Mi Contraseña
            </a>

            <?php if ($puedeCrear): ?>
                <a href="#formNuevoUsuario" class="btn btn-primary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Nuevo Usuario
                </a>
            <?php endif; ?>
        </div>
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

    <!-- TABLA DE USUARIOS (OPERADOR SOLO SE VE A SÍ MISMO; SUPERVISOR VE TODOS PERO NO MODIFICA; ADMIN CONTROLA TODO) -->
    <div class="card" style="margin-bottom: 2rem;">
        <div class="card-title">
            <span><?= $esOperador ? 'Datos de tu Cuenta' : 'Directorio de Usuarios (' . count($listaUsuarios) . ')' ?></span>
            <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">
                Rol actual: <strong><?= htmlspecialchars($rolActual) ?></strong>
            </span>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Usuario / Nombre</th>
                        <th>Correo Electrónico</th>
                        <th>Rol de Acceso</th>
                        <?php if ($esSuper): ?>
                            <th>Contraseña</th>
                        <?php endif; ?>
                        <th>Estado</th>
                        <th style="text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($listaUsuarios)): ?>
                        <tr>
                            <td colspan="<?= $esSuper ? 6 : 5 ?>" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                No se encontraron registros de usuario.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($listaUsuarios as $usr): 
                            $inicial = mb_strtoupper(mb_substr($usr['nombre'], 0, 1, 'UTF-8'), 'UTF-8');
                            $esMiUsuario = ((int)$usr['id'] === (int)$usuarioActual['id']);
                            $clavePlanaUsr = $esSuper ? descifrarClaveVisible($usr['clave_visible'] ?? null) : '';
                        ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <div style="width: 36px; height: 36px; border-radius: 50%; background: var(--bg-surface-elevated); border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; font-weight: 700; color: var(--primary); font-size: 0.95rem; flex-shrink: 0;">
                                            <?= htmlspecialchars($inicial) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; color: var(--text-main); display: flex; align-items: center; gap: 0.35rem;">
                                                <?= htmlspecialchars($usr['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if ($esMiUsuario): ?>
                                                    <span class="badge badge-modo" style="font-size: 0.68rem; padding: 0.1rem 0.4rem;">Tú</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); font-family: monospace;">
                                                @<?= htmlspecialchars($usr['usuario'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td style="color: var(--text-muted); font-size: 0.9rem;">
                                    <?= htmlspecialchars($usr['email'], ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?php 
                                        $badgeStyle = 'background: rgba(59, 130, 246, 0.12); color: var(--primary); border: 1px solid rgba(59, 130, 246, 0.3);';
                                        if ($usr['rol'] === 'ADMINISTRADOR' || $usr['rol'] === 'SUPER') {
                                            $badgeStyle = 'background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.35);';
                                        } elseif ($usr['rol'] === 'SUPERVISOR') {
                                            $badgeStyle = 'background: rgba(245, 158, 11, 0.15); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3);';
                                        }
                                    ?>
                                    <span class="badge" style="<?= $badgeStyle ?>">
                                        <?= htmlspecialchars($usr['rol'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <?php if ($esSuper): ?>
                                <td>
                                    <?php if ($clavePlanaUsr !== ''): ?>
                                        <div style="display: inline-flex; align-items: center; gap: 0.4rem; background: var(--bg-surface-elevated); border: 1px solid var(--border-color); padding: 0.2rem 0.5rem; border-radius: 6px;">
                                            <span class="pass-mask" style="font-family: monospace; letter-spacing: 1px; color: var(--text-muted); font-size: 0.85rem;">••••••••</span>
                                            <code class="pass-real" style="display: none; font-family: monospace; font-weight: 700; color: var(--text-main); font-size: 0.85rem;"><?= htmlspecialchars($clavePlanaUsr, ENT_QUOTES, 'UTF-8') ?></code>
                                            <button
                                                type="button"
                                                onclick="toggleVerClaveFila(this)"
                                                title="Mostrar / ocultar contraseña"
                                                style="background: none; border: none; cursor: pointer; padding: 0 0.15rem; font-size: 0.9rem; line-height: 1; opacity: 0.8;"
                                            >👁️</button>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size: 0.78rem; color: var(--text-muted); font-style: italic;" title="Asigna o edita la contraseña con el botón Clave para habilitar su visualización">
                                            Sin registro (editar)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($usr['activo']): ?>
                                        <span class="badge badge-estable">ACTIVO</span>
                                    <?php else: ?>
                                        <span class="badge badge-inestable" style="background: rgba(239, 68, 68, 0.12); color: var(--danger); border-color: var(--danger);">INACTIVO</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($esMiUsuario): ?>
                                        <a href="mi_clave.php" class="btn btn-secondary" style="padding: 0.3rem 0.65rem; font-size: 0.8rem; min-height: 32px;">
                                            Cambiar mi clave
                                        </a>
                                    <?php elseif ($puedeModificarOtros && ($esSuper || $usr['rol'] !== 'SUPER')): ?>
                                        <!-- ACCIONES EXCLUSIVAS DE ADMINISTRADOR Y SUPER -->
                                        <div style="display: inline-flex; gap: 0.4rem; align-items: center;">
                                            <!-- Botón Editar Contraseña Modal -->
                                            <button 
                                                type="button" 
                                                class="btn btn-secondary" 
                                                style="padding: 0.3rem 0.65rem; font-size: 0.8rem; min-height: 32px;"
                                                data-uid="<?= (int)$usr['id'] ?>"
                                                data-usuario="<?= htmlspecialchars($usr['usuario'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-clave="<?= $esSuper ? htmlspecialchars($clavePlanaUsr, ENT_QUOTES, 'UTF-8') : '' ?>"
                                                onclick="abrirModalClaveUsuario(this)"
                                                title="Ver / editar contraseña de este usuario"
                                            >
                                                🔑 Clave
                                            </button>

                                            <!-- Formulario Alternar Activo/Inactivo -->
                                            <form method="POST" action="usuarios.php" style="display: inline-block;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="accion" value="alternar_estado">
                                                <input type="hidden" name="id_usuario" value="<?= (int)$usr['id'] ?>">
                                                <input type="hidden" name="nuevo_estado" value="<?= $usr['activo'] ? 0 : 1 ?>">
                                                <button 
                                                    type="submit" 
                                                    class="btn <?= $usr['activo'] ? 'btn-danger-outline' : 'btn-success-outline' ?>" 
                                                    style="padding: 0.3rem 0.65rem; font-size: 0.8rem; min-height: 32px;"
                                                    onclick="return confirm('¿Confirmas <?= $usr['activo'] ? 'DESACTIVAR' : 'ACTIVAR' ?> al usuario <?= htmlspecialchars($usr['usuario']) ?>?');"
                                                >
                                                    <?= $usr['activo'] ? 'Desactivar' : 'Activar' ?>
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <!-- SUPERVISOR: Solo lectura de otros usuarios -->
                                        <span style="color: var(--text-muted); font-size: 0.8rem; font-style: italic;">Solo lectura</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- FORMULARIO CREAR NUEVO USUARIO (SOLO SUPER, ADMIN Y SUPERVISOR) -->
    <?php if ($puedeCrear): ?>
    <div class="card" id="formNuevoUsuario">
        <div class="card-title">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <div style="width: 32px; height: 32px; border-radius: var(--radius-sm); background: var(--primary-light); display: flex; align-items: center; justify-content: center; color: var(--primary);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                </div>
                <span>Crear Nuevo Usuario</span>
            </div>
            <span style="font-size: 0.82rem; color: var(--text-muted);">Campos marcados con (*) son requeridos</span>
        </div>

        <form method="POST" action="usuarios.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" value="crear">

            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    Información de la Cuenta
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="nombre" class="form-label">Nombre Completo <span style="color: var(--danger);">*</span></label>
                        <input 
                            type="text" 
                            id="nombre" 
                            name="nombre" 
                            class="form-control" 
                            placeholder="Ejemplo: Carlos Martínez"
                            maxlength="150" 
                            required
                            value="<?= htmlspecialchars($valores['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="usuario" class="form-label">Nombre de Usuario (Login) <span style="color: var(--danger);">*</span></label>
                        <input 
                            type="text" 
                            id="usuario" 
                            name="usuario" 
                            class="form-control" 
                            placeholder="cmartinez"
                            maxlength="80" 
                            required
                            value="<?= htmlspecialchars($valores['usuario'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="email" class="form-label">Correo Electrónico <span style="color: var(--danger);">*</span></label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-control" 
                            placeholder="cmartinez@empresa.com"
                            maxlength="254" 
                            required
                            value="<?= htmlspecialchars($valores['email'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="rol" class="form-label">Nivel de Acceso / Rol <span style="color: var(--danger);">*</span></label>
                        <select id="rol" name="rol" class="form-control" required>
                            <?php foreach ($rolesDisponibles as $keyRol => $descRol): ?>
                                <option value="<?= htmlspecialchars($keyRol) ?>" <?= $valores['rol'] === $keyRol ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($descRol) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    Contraseña Inicial
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="clave" class="form-label">Contraseña <span style="color: var(--danger);">*</span></label>
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
                        <label for="confirmacion" class="form-label">Confirmar Contraseña <span style="color: var(--danger);">*</span></label>
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
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; align-items: center; margin-top: 1rem; background: var(--bg-surface-elevated); padding: 1.25rem 1.5rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                <button type="reset" class="btn btn-secondary">Limpiar</button>
                <button type="submit" class="btn btn-primary btn-lg">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Registrar Usuario
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- MODAL PARA VER Y EDITAR CONTRASEÑA DE USUARIOS -->
<?php if ($puedeCambiarClaveOtros): ?>
<div id="modalEditarClave" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); z-index: 9999; align-items: center; justify-content: center; padding: 1rem;">
    <div class="card" style="width: 100%; max-width: 430px; margin: 0; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.25);">
        <div class="card-title" style="margin-bottom: 1rem;">
            <span>🔑 Contraseña de <strong id="modalClaveUsuarioLbl"></strong></span>
            <button type="button" onclick="cerrarModalClaveUsuario()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--text-muted); line-height: 1;">✕</button>
        </div>

        <form id="formResetClave" method="POST" action="usuarios.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" value="reset_clave">
            <input type="hidden" id="resetUserId" name="id_usuario" value="">

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label for="resetUserPass" class="form-label">
                    <?= $esSuper ? 'Contraseña actual / nueva (mínimo 4 caracteres)' : 'Nueva contraseña (mínimo 4 caracteres)' ?>
                </label>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <input
                        type="<?= $esSuper ? 'text' : 'password' ?>"
                        id="resetUserPass"
                        name="nueva_clave"
                        class="form-control"
                        minlength="4"
                        maxlength="1024"
                        placeholder="Escribe la contraseña..."
                        required
                        style="font-family: monospace; font-weight: 600;"
                    >
                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="toggleInputModalClave()"
                        title="Mostrar / ocultar"
                        style="padding: 0.5rem 0.75rem; flex-shrink: 0;"
                    >👁️</button>
                </div>
                <?php if ($esSuper): ?>
                    <small id="modalClaveAvisoSinRegistro" style="display: none; color: var(--text-muted); margin-top: 0.4rem; font-size: 0.78rem;">
                        Este usuario fue creado antes de activar el registro visible. Escribe y guarda una contraseña para dejarla visible.
                    </small>
                <?php endif; ?>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.6rem;">
                <button type="button" class="btn btn-secondary" onclick="cerrarModalClaveUsuario()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Contraseña</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleVerClaveFila(btn) {
    const contenedor = btn.parentElement;
    if (!contenedor) return;
    const mask = contenedor.querySelector('.pass-mask');
    const real = contenedor.querySelector('.pass-real');
    if (!mask || !real) return;
    const mostrando = real.style.display !== 'none';
    real.style.display = mostrando ? 'none' : 'inline';
    mask.style.display = mostrando ? 'inline' : 'none';
    btn.textContent = mostrando ? '👁️' : '🙈';
}

function abrirModalClaveUsuario(btn) {
    const id = btn.getAttribute('data-uid') || '';
    const usuario = btn.getAttribute('data-usuario') || '';
    const claveActual = btn.getAttribute('data-clave') || '';

    document.getElementById('resetUserId').value = id;
    document.getElementById('modalClaveUsuarioLbl').textContent = '@' + usuario;

    const input = document.getElementById('resetUserPass');
    input.value = claveActual;

    const aviso = document.getElementById('modalClaveAvisoSinRegistro');
    if (aviso) {
        aviso.style.display = (claveActual === '') ? 'block' : 'none';
    }

    const modal = document.getElementById('modalEditarClave');
    modal.style.display = 'flex';
    setTimeout(function() {
        input.focus();
        input.select();
    }, 30);
}

function cerrarModalClaveUsuario() {
    document.getElementById('modalEditarClave').style.display = 'none';
    document.getElementById('resetUserPass').value = '';
}

function toggleInputModalClave() {
    const input = document.getElementById('resetUserPass');
    input.type = (input.type === 'password') ? 'text' : 'password';
    input.focus();
}

document.getElementById('modalEditarClave').addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarModalClaveUsuario();
    }
});
</script>
<?php endif; ?>

<?php
renderizarPie();
