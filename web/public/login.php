<?php
/**
 * Pantalla de Inicio de Sesión (Login)
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo 1: Control de Acceso y Autenticación.
 * Valida credenciales contra la tabla t_usuarios en MariaDB (XAMPP),
 * genera sesiones seguras y previene ataques CSRF y de fuerza bruta.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

// Si el usuario ya tiene sesión activa, redirigir al panel principal
if (obtenerUsuarioActual() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
$usuarioIngresado = '';

// Procesar el formulario cuando se envía por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validar token CSRF para evitar envíos cruzados no autorizados
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!validarCsrfToken($csrfToken)) {
        $error = 'La sesión del formulario venció. Por favor recarga la página.';
    } else {
        $usuarioIngresado = trim((string)($_POST['usuario'] ?? ''));
        $claveIngresada = (string)($_POST['clave'] ?? '');

        if ($usuarioIngresado === '' || $claveIngresada === '') {
            $error = 'Por favor ingresa tu usuario y contraseña.';
        } else {
            try {
                $pdo = obtenerDB();

                // 2. Consultar el usuario y su empresa mediante sentencia preparada
                $stmt = $pdo->prepare(
                    "SELECT u.id, u.empresa_id, u.nombre, u.usuario, u.clave_hash, u.rol, u.activo,
                            e.razon_social, e.nombre_comercial, e.activo AS empresa_activa
                     FROM t_usuarios u
                     JOIN t_empresas e ON e.id = u.empresa_id
                     WHERE u.usuario = :usuario
                     LIMIT 1"
                );
                $stmt->execute([':usuario' => $usuarioIngresado]);
                $usuario = $stmt->fetch();

                // 3. Verificar contraseña y estado de la cuenta
                if ($usuario && password_verify($claveIngresada, $usuario['clave_hash'])) {
                    if (!$usuario['activo']) {
                        $error = 'Tu cuenta de usuario se encuentra desactivada.';
                    } elseif (!$usuario['empresa_activa']) {
                        $error = 'La empresa asociada se encuentra inactiva.';
                    } else {
                        // 4. Iniciar sesión segura y regenerar ID contra ataques de fijación
                        iniciarSesionSegura();
                        session_regenerate_id(true);

                        $_SESSION['usuario_id']     = (int)$usuario['id'];
                        $_SESSION['empresa_id']     = (int)$usuario['empresa_id'];
                        $_SESSION['usuario_nombre'] = (string)$usuario['nombre'];
                        $_SESSION['usuario_login']  = (string)$usuario['usuario'];
                        $_SESSION['usuario_rol']    = (string)$usuario['rol'];
                        $_SESSION['empresa_nombre'] = (string)($usuario['nombre_comercial'] ?: $usuario['razon_social']);
                        $_SESSION['ultimo_acceso']  = time();

                        // Redirigir al inicio del sistema
                        header('Location: index.php');
                        exit;
                    }
                } else {
                    $error = 'Usuario o contraseña incorrectos.';
                }
            } catch (Exception $e) {
                $error = 'Error de conexión con MariaDB: ' . $e->getMessage();
            }
        }
    }
}

// Token CSRF fresco para el formulario
$tokenFormulario = obtenerCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión — TYTONE BÁSCULA</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: radial-gradient(circle at top, #1e293b 0%, #0f172a 100%);
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-lg);
            text-align: center;
        }

        .login-logo {
            max-width: 130px;
            height: auto;
            margin-bottom: 1.25rem;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3));
        }

        .login-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.25rem;
        }

        .login-subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1.75rem;
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.15);
            border: 1px solid var(--danger);
            color: #fca5a5;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            margin-bottom: 1.25rem;
            text-align: left;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <!-- Logo institucional del proyecto -->
        <img src="assets/img/tytlogo.png" alt="TYT Systems Logo" class="login-logo">

        <h1 class="login-title">TYTONE BÁSCULA</h1>
        <p class="login-subtitle">Sistema de Control de Pesaje Camionero</p>

        <?php if (!empty($error)): ?>
            <div class="alert-error" role="alert">
                ⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off" style="text-align: left;">
            <!-- Protección contra ataques CSRF -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenFormulario, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-group">
                <label for="usuario" class="form-label">Usuario:</label>
                <input 
                    type="text" 
                    id="usuario" 
                    name="usuario" 
                    class="form-control" 
                    placeholder="Ingresa tu usuario"
                    value="<?= htmlspecialchars($usuarioIngresado, ENT_QUOTES, 'UTF-8') ?>" 
                    required 
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="clave" class="form-label">Contraseña:</label>
                <input 
                    type="password" 
                    id="clave" 
                    name="clave" 
                    class="form-control" 
                    placeholder="••••••••••••" 
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary btn-lg" style="width: 100%; margin-top: 0.5rem;">
                Ingresar al Sistema &rarr;
            </button>
        </form>

        <p style="margin-top: 2rem; font-size: 0.8rem; color: var(--text-muted);">
            Acceso restringido a personal operativo y administrativo.
        </p>
    </div>
</div>

</body>
</html>
