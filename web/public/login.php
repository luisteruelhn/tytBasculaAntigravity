<?php
/**
 * Pantalla de Inicio de Sesión (Login)
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo 1: Control de Acceso y Autenticación.
 * Diseño adaptado para báscula de rastras y transporte de carga pesada.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

// Redirigir al inicio si ya hay sesión activa
if (obtenerUsuarioActual() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
$usuarioIngresado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!validarCsrfToken($csrfToken)) {
        $error = 'La sesión del formulario venció. Por favor recarga la página.';
    } else {
        $usuarioIngresado = trim((string)($_POST['usuario'] ?? ''));
        $claveIngresada = (string)($_POST['clave'] ?? '');
        $ipCliente = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // Validaciones estrictas de entrada y caracteres de control
        if ($usuarioIngresado === '' || $claveIngresada === '') {
            $error = 'Por favor ingresa tu usuario y contraseña.';
        } elseif (mb_strlen($usuarioIngresado) > 80 || mb_strlen($claveIngresada) > 1024) {
            $error = 'La longitud de los datos ingresados excede el límite permitido.';
        } elseif (preg_match('/[\x00-\x1F\x7F]/', $usuarioIngresado) || str_contains($claveIngresada, "\0")) {
            $error = 'Los datos ingresados contienen caracteres no válidos.';
        } elseif (!verificarTasaLimite('ip:' . $ipCliente, 25, 60)) {
            // Protección contra fuerza bruta por IP
            http_response_code(429);
            $error = 'Demasiados intentos desde este equipo. Por favor espera 1 minuto.';
        } elseif (!verificarTasaLimite('usr:' . mb_strtolower($usuarioIngresado), 5, 300)) {
            // Protección contra fuerza bruta por nombre de cuenta
            http_response_code(429);
            $error = 'Demasiados intentos fallidos en esta cuenta. Por seguridad, espera 5 minutos.';
        } else {
            try {
                $pdo = obtenerDB();

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

                // Hash señuelo para defensa contra ataques de tiempo (timing attacks)
                $hashAComparar = $usuario 
                    ? (string)$usuario['clave_hash'] 
                    : '$2y$12$e8eLh4bQG4L3tFpXqZ4qjeW1F5O8bA9Q3mZ8Y6W1F5O8bA9Q3mZ8Y';

                $claveCorrecta = password_verify($claveIngresada, $hashAComparar);

                if ($usuario && $claveCorrecta) {
                    if (!$usuario['activo']) {
                        $error = 'Tu cuenta de usuario se encuentra desactivada.';
                    } elseif (!$usuario['empresa_activa']) {
                        $error = 'La empresa asociada se encuentra inactiva.';
                    } else {
                        // Limpiar contador de intentos fallidos para esta cuenta
                        limpiarTasaLimite('usr:' . mb_strtolower($usuarioIngresado));

                        iniciarSesionSegura();
                        session_regenerate_id(true);

                        $_SESSION['usuario_id']     = (int)$usuario['id'];
                        $_SESSION['empresa_id']     = (int)$usuario['empresa_id'];
                        $_SESSION['usuario_nombre'] = (string)$usuario['nombre'];
                        $_SESSION['usuario_login']  = (string)$usuario['usuario'];
                        $_SESSION['usuario_rol']    = (string)$usuario['rol'];
                        $_SESSION['empresa_nombre'] = (string)($usuario['nombre_comercial'] ?: $usuario['razon_social']);
                        $_SESSION['ultimo_acceso']  = time();

                        header('Location: index.php');
                        exit;
                    }
                } else {
                    $error = 'Usuario o contraseña incorrectos.';
                }
            } catch (Exception $e) {
                $error = 'Error de conexión: ' . $e->getMessage();
            }
        }
    }
}

$tokenFormulario = obtenerCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso — Báscula de Rastras y Carga Pesada</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/theme.js"></script>
    <style>
        .login-page-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
            background: radial-gradient(circle at 30% 20%, #1e293b 0%, #090d16 100%);
        }

        .login-card-dual {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            max-width: 960px;
            width: 100%;
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg), 0 20px 40px -15px rgba(0,0,0,0.7);
            overflow: hidden;
        }

        /* Panel Izquierdo: Ilustración Rastra y Báscula */
        .login-hero {
            background: linear-gradient(165deg, #0f172a 0%, #030712 100%);
            padding: 2.75rem 2.25rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 1px solid var(--border-color);
            position: relative;
        }

        .hero-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .hero-logo {
            max-width: 65px;
            height: auto;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4));
        }

        .hero-title-group h2 {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        .hero-title-group span {
            font-size: 0.75rem;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 700;
        }

        .rastra-graphic {
            margin: 2rem 0;
            text-align: center;
        }

        .rastra-svg {
            width: 100%;
            max-width: 440px;
            height: auto;
            filter: drop-shadow(0 10px 15px rgba(0,0,0,0.5));
        }

        .hero-features {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 0.85rem 1.2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
        }

        .hero-features div {
            display: flex;
            flex-direction: column;
        }

        .hero-features .label {
            color: var(--text-muted);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .hero-features .val {
            color: var(--led-color);
            font-weight: 700;
            font-family: monospace;
            font-size: 0.95rem;
        }

        /* Panel Derecho: Formulario */
        .login-form-panel {
            padding: 3rem 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-heading {
            margin-bottom: 2rem;
        }

        .login-heading h1 {
            font-size: 1.65rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.35rem;
        }

        .login-heading p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.15);
            border: 1px solid var(--danger);
            color: #fca5a5;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 820px) {
            .login-card-dual {
                grid-template-columns: 1fr;
            }
            .login-hero {
                padding: 1.75rem 1.5rem;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }
            .login-form-panel {
                padding: 2rem 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .login-page-container {
                padding: 0.75rem;
            }
            .login-hero {
                padding: 1.25rem 1rem;
            }
            .hero-title-group h2 {
                font-size: 1.1rem;
            }
            .login-form-panel {
                padding: 1.5rem 1rem;
            }
            .login-heading h1 {
                font-size: 1.35rem;
            }
            .hero-features {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start;
            }
            .hero-features div[style*="text-align: right"] {
                text-align: left !important;
            }
        }
    </style>
</head>
<body>

<div class="login-page-container">
    <div class="login-card-dual">
        
        <!-- PANEL IZQUIERDO: ILUSTRACIÓN DE RASTRA EN BÁSCULA CAMIONERA -->
        <section class="login-hero">
            <div class="hero-brand">
                <img src="assets/img/tytlogo.png" alt="TYT Systems" class="hero-logo">
                <div class="hero-title-group">
                    <span>CONTROL DE CARGA PESADA</span>
                    <h2>BÁSCULA CAMIONERA</h2>
                </div>
            </div>

            <!-- Gráfico Vectorial: Cabezal Cuadradito (Cabover) con Rastra sobre Báscula -->
            <div class="rastra-graphic">
                <svg class="rastra-svg" viewBox="0 0 520 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Sombra general del vehículo sobre la plataforma -->
                    <ellipse cx="250" cy="172" rx="230" ry="8" fill="#000000" opacity="0.4"/>

                    <!-- Plataforma de Báscula de Acero y Celdas de Carga -->
                    <rect x="15" y="172" width="490" height="14" rx="2" fill="#334155" stroke="#475569" stroke-width="2"/>
                    <!-- Rampas laterales -->
                    <polygon points="5,186 15,172 15,186" fill="#475569"/>
                    <polygon points="505,172 515,186 505,186" fill="#475569"/>
                    <!-- Sensores de pesaje (Celdas de Carga) -->
                    <rect x="55" y="186" width="22" height="12" rx="1" fill="#64748b"/>
                    <rect x="249" y="186" width="22" height="12" rx="1" fill="#64748b"/>
                    <rect x="443" y="186" width="22" height="12" rx="1" fill="#64748b"/>
                    <!-- Cimiento de hormigón -->
                    <rect x="5" y="198" width="510" height="12" fill="#1e293b"/>
                    <line x1="5" y1="198" x2="515" y2="198" stroke="#334155" stroke-width="1"/>

                    <!-- REMOLQUE / FURGÓN DE RASTRA (53 Pies) -->
                    <rect x="25" y="38" width="270" height="104" rx="3" fill="#1e293b" stroke="#3b82f6" stroke-width="2.5"/>
                    <!-- Panel corrugado / franjas estructurales del furgón -->
                    <line x1="75" y1="39" x2="75" y2="141" stroke="#334155" stroke-width="2"/>
                    <line x1="125" y1="39" x2="125" y2="141" stroke="#334155" stroke-width="2"/>
                    <line x1="175" y1="39" x2="175" y2="141" stroke="#334155" stroke-width="2"/>
                    <line x1="225" y1="39" x2="225" y2="141" stroke="#334155" stroke-width="2"/>
                    <line x1="275" y1="39" x2="275" y2="141" stroke="#334155" stroke-width="2"/>
                    <!-- Franja reflectiva de seguridad en el furgón -->
                    <rect x="26" y="132" width="268" height="6" fill="#f59e0b" opacity="0.85"/>
                    <rect x="26" y="44" width="268" height="3" fill="#3b82f6" opacity="0.6"/>
                    <!-- Patas / Tren de apoyo del remolque -->
                    <rect x="135" y="142" width="8" height="24" fill="#64748b"/>
                    <rect x="150" y="142" width="8" height="24" fill="#64748b"/>
                    <rect x="130" y="162" width="32" height="5" fill="#475569"/>

                    <!-- CHIMENEAS DE ESCAPE VERTICALES (Detrás del Cabezal) -->
                    <rect x="296" y="22" width="7" height="85" fill="#94a3b8" stroke="#cbd5e1" stroke-width="1"/>
                    <polygon points="299,12 295,22 304,22" fill="#cbd5e1"/>
                    <rect x="306" y="26" width="6" height="81" fill="#64748b"/>

                    <!-- CABEZAL CUADRADITO / CHATO (Cabover Classic COE) -->
                    <!-- Carrocería principal cúbica/chata -->
                    <path d="M 298 142 L 298 42 L 398 42 Q 412 42 412 56 L 412 142 Z" fill="#2563eb" stroke="#60a5fa" stroke-width="2.5"/>
                    
                    <!-- Deflector / Visera superior cuadrada -->
                    <path d="M 330 36 L 416 36 L 414 43 L 330 43 Z" fill="#1d4ed8"/>
                    
                    <!-- Gran Parabrisas Cuadrado / Panorámico -->
                    <rect x="338" y="48" width="70" height="36" rx="2" fill="#0f172a" stroke="#93c5fd" stroke-width="1.5"/>
                    <!-- Brillo de cristal -->
                    <polygon points="342,80 375,52 388,52 355,80" fill="#93c5fd" opacity="0.25"/>
                    <polygon points="372,80 400,56 405,56 385,80" fill="#93c5fd" opacity="0.25"/>

                    <!-- Ventana lateral del conductor -->
                    <rect x="306" y="52" width="24" height="28" rx="2" fill="#0f172a" stroke="#93c5fd" stroke-width="1.5"/>
                    <!-- Manija de puerta -->
                    <rect x="310" y="90" width="8" height="3" rx="1" fill="#cbd5e1"/>

                    <!-- Parrilla Frontal Cuadrada Clásica (Parrillón Chato) -->
                    <rect x="372" y="92" width="37" height="36" rx="2" fill="#090d16" stroke="#94a3b8" stroke-width="1.5"/>
                    <!-- Ranuras de ventilación cromadas -->
                    <line x1="375" y1="98" x2="406" y2="98" stroke="#cbd5e1" stroke-width="2"/>
                    <line x1="375" y1="104" x2="406" y2="104" stroke="#cbd5e1" stroke-width="2"/>
                    <line x1="375" y1="110" x2="406" y2="110" stroke="#cbd5e1" stroke-width="2"/>
                    <line x1="375" y1="116" x2="406" y2="116" stroke="#cbd5e1" stroke-width="2"/>
                    <line x1="375" y1="122" x2="406" y2="122" stroke="#cbd5e1" stroke-width="2"/>

                    <!-- Parachoques Frontal Pesado Cromado -->
                    <rect x="365" y="134" width="49" height="12" rx="2" fill="#94a3b8" stroke="#f1f5f9" stroke-width="1.5"/>
                    <!-- Faroles delanteros cuadrados gemelos -->
                    <rect x="396" y="136" width="7" height="7" rx="1" fill="#fef08a" stroke="#ca8a04" stroke-width="1"/>
                    <rect x="386" y="136" width="7" height="7" rx="1" fill="#fef08a" stroke="#ca8a04" stroke-width="1"/>

                    <!-- Tanque de Diésel Cilíndrico y Estribos -->
                    <rect x="312" y="142" width="42" height="15" rx="4" fill="#64748b" stroke="#94a3b8" stroke-width="1"/>
                    <!-- Estribo de acceso a cabina -->
                    <line x1="394" y1="148" x2="406" y2="148" stroke="#94a3b8" stroke-width="2"/>

                    <!-- RUEDAS DE ALTA RESISTENCIA (Rines de 10 birlos) -->
                    <!-- Eje Trasero Furgón 1 -->
                    <circle cx="58" cy="156" r="17" fill="#020617" stroke="#334155" stroke-width="3"/>
                    <circle cx="58" cy="156" r="8" fill="#94a3b8"/>
                    <circle cx="58" cy="156" r="3" fill="#0f172a"/>
                    <!-- Eje Trasero Furgón 2 -->
                    <circle cx="98" cy="156" r="17" fill="#020617" stroke="#334155" stroke-width="3"/>
                    <circle cx="98" cy="156" r="8" fill="#94a3b8"/>
                    <circle cx="98" cy="156" r="3" fill="#0f172a"/>

                    <!-- Eje de Tracción Trasero Cabezal 1 -->
                    <circle cx="282" cy="156" r="17" fill="#020617" stroke="#334155" stroke-width="3"/>
                    <circle cx="282" cy="156" r="8" fill="#94a3b8"/>
                    <circle cx="282" cy="156" r="3" fill="#0f172a"/>
                    <!-- Eje de Tracción Trasero Cabezal 2 -->
                    <circle cx="322" cy="156" r="17" fill="#020617" stroke="#334155" stroke-width="3"/>
                    <circle cx="322" cy="156" r="8" fill="#94a3b8"/>
                    <circle cx="322" cy="156" r="3" fill="#0f172a"/>

                    <!-- Eje Direccional Delantero (Justo debajo de la cabina chata) -->
                    <circle cx="392" cy="156" r="17" fill="#020617" stroke="#334155" stroke-width="3"/>
                    <circle cx="392" cy="156" r="8" fill="#cbd5e1"/>
                    <circle cx="392" cy="156" r="3" fill="#0f172a"/>

                    <!-- DISPLAY DIGITAL DE LA BÁSCULA (Indicador Industrial) -->
                    <rect x="382" y="10" width="124" height="26" rx="4" fill="#020617" stroke="#10b981" stroke-width="1.5"/>
                    <text x="392" y="28" fill="#10b981" font-family="'Courier New', monospace" font-size="13" font-weight="900" letter-spacing="1">84,520 LB</text>
                </svg>
            </div>

            <div class="hero-features">
                <div>
                    <span class="label">Capacidad Plataforma</span>
                    <span class="val">120,000 LB</span>
                </div>
                <div style="text-align: right;">
                    <span class="label">Operación</span>
                    <span class="val" style="color: var(--primary);">24 / 7 CONTROLES</span>
                </div>
            </div>
        </section>

        <!-- PANEL DERECHO: FORMULARIO DE ACCESO -->
        <section class="login-form-panel">
            <div class="login-heading" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                <div>
                    <h1>Iniciar Sesión</h1>
                    <p>Ingresa tus credenciales para acceder al sistema.</p>
                </div>
                <!-- Botón de Alternancia de Tema -->
                <button type="button" class="btn-tema-toggle" onclick="alternarTema()" aria-label="Cambiar tema">
                    ☀️ <span class="tema-texto">Modo Claro</span>
                </button>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert-error" role="alert">
                    ⚠️ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokenFormulario, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label for="usuario" class="form-label">Usuario:</label>
                    <input 
                        type="text" 
                        id="usuario" 
                        name="usuario" 
                        class="form-control" 
                        placeholder="Ejemplo: admin"
                        value="<?= htmlspecialchars($usuarioIngresado, ENT_QUOTES, 'UTF-8') ?>" 
                        required 
                        autofocus
                    >
                </div>

                <div class="form-group" style="margin-bottom: 2rem;">
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

                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                    Ingresar a Báscula &rarr;
                </button>
            </form>

            <div style="margin-top: 2rem; font-size: 0.8rem; color: var(--text-muted); text-align: center;">
                Báscula para rastras y transporte pesado &bull; TYT Systems
            </div>
        </section>

    </div>
</div>

</body>
</html>
