<?php
/**
 * Pantalla de Inicio de Sesión (Login)
 * Proyecto: tytBasculaAntigravity
 *
 * Módulo 1: Control de Acceso y Autenticación.
 * Diseño adaptado para báscula de rastras y transporte de carga pesada.
 */

declare(strict_types=1);

// Si se accede a /bascula sin barra final (rewrite interno), redirigir a /bascula/login.php para no romper rutas relativas CSS/JS
$reqPathLogin = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
if ($reqPathLogin !== '' && preg_match('#/bascula$#i', $reqPathLogin)) {
    header('Location: ' . $reqPathLogin . '/login.php', true, 302);
    exit;
}

require_once dirname(__DIR__) . '/src/auth.php';

// Redirigir al inicio si ya hay sesión activa
if (obtenerUsuarioActual() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if (($_GET['inactividad'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = 'Tu sesión se cerró automáticamente tras 1 hora de inactividad.';
}
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
                    } elseif (($usuario['rol'] ?? '') === 'OPERADOR' && !esAccesoPlantaLocal()) {
                        $error = 'Las cuentas de Operador (pesador) son de uso exclusivo en la red local de planta.';
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
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso — Báscula de Rastras y Carga Pesada</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico?v=20261125">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico?v=20261125">
    <link rel="stylesheet" href="assets/css/style.css?v=20261125d">
    <script src="assets/js/theme.js?v=20261125d"></script>
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
            color: #f8fafc;
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
                    <h2>TRUCK SCALE</h2>
                </div>
            </div>

            <!-- Gráfico Vectorial: Rastra de Carga Pesada sobre Báscula -->
            <div class="rastra-graphic">
                <svg class="rastra-svg" viewBox="0 0 520 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <!-- Gradientes Metálicos Sobrios -->
                        <linearGradient id="opBlue" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#2563eb"/>
                            <stop offset="30%" stop-color="#1d4ed8"/>
                            <stop offset="72%" stop-color="#172554"/>
                            <stop offset="100%" stop-color="#0b1333"/>
                        </linearGradient>
                        <linearGradient id="opBlueDark" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#1e3a8a"/>
                            <stop offset="100%" stop-color="#091029"/>
                        </linearGradient>
                        <!-- Gradientes ghost-flame azules apenas perceptibles (flujo frente -> atrás) -->
                        <linearGradient id="opGhostFlame" x1="1" y1="0.1" x2="0" y2="0.45">
                            <stop offset="0%" stop-color="#93c5fd" stop-opacity="0.26"/>
                            <stop offset="45%" stop-color="#60a5fa" stop-opacity="0.16"/>
                            <stop offset="80%" stop-color="#3b82f6" stop-opacity="0.09"/>
                            <stop offset="100%" stop-color="#bfdbfe" stop-opacity="0.01"/>
                        </linearGradient>
                        <linearGradient id="opGhostFlameSoft" x1="1" y1="0.2" x2="0" y2="0.4">
                            <stop offset="0%" stop-color="#60a5fa" stop-opacity="0.18"/>
                            <stop offset="60%" stop-color="#93c5fd" stop-opacity="0.10"/>
                            <stop offset="100%" stop-color="#bfdbfe" stop-opacity="0.01"/>
                        </linearGradient>
                        <linearGradient id="chromeV" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#f8fafc"/>
                            <stop offset="28%" stop-color="#e2e8f0"/>
                            <stop offset="54%" stop-color="#64748b"/>
                            <stop offset="78%" stop-color="#f1f5f9"/>
                            <stop offset="100%" stop-color="#475569"/>
                        </linearGradient>
                        <linearGradient id="chromeH" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stop-color="#94a3b8"/>
                            <stop offset="35%" stop-color="#f8fafc"/>
                            <stop offset="68%" stop-color="#cbd5e1"/>
                            <stop offset="100%" stop-color="#475569"/>
                        </linearGradient>
                        <linearGradient id="trailerGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#334155"/>
                            <stop offset="55%" stop-color="#1e293b"/>
                            <stop offset="100%" stop-color="#0f172a"/>
                        </linearGradient>
                        <linearGradient id="glassGrad" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0%" stop-color="#334155"/>
                            <stop offset="50%" stop-color="#0f172a"/>
                            <stop offset="100%" stop-color="#020617"/>
                        </linearGradient>
                        <linearGradient id="deckGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#475569"/>
                            <stop offset="100%" stop-color="#1e293b"/>
                        </linearGradient>
                        <filter id="headlightGlow" x="-40%" y="-40%" width="180%" height="180%">
                            <feGaussianBlur stdDeviation="2" result="blur"/>
                            <feMerge>
                                <feMergeNode in="blur"/>
                                <feMergeNode in="SourceGraphic"/>
                            </feMerge>
                        </filter>

                        <!-- Silueta compacta del cabezal para recorte limpio de las llamas -->
                        <clipPath id="cabBodyClip">
                            <path d="M 340 138 L 340 50 Q 340 36 356 35 C 374 34 394 38 406 54 L 415 86 C 436 87 458 90 475 95 L 476 144 L 470 148 C 468 133 432 133 430 148 L 424 148 L 424 138 Z"/>
                        </clipPath>

                        <!-- Rueda Alcoa Cromada de 10 birlos reutilizable -->
                        <g id="opWheel">
                            <circle cx="0" cy="0" r="16" fill="#050810" stroke="#1e293b" stroke-width="2.2"/>
                            <circle cx="0" cy="0" r="12.5" fill="none" stroke="#334155" stroke-width="1"/>
                            <circle cx="0" cy="0" r="9.2" fill="url(#chromeV)" stroke="#94a3b8" stroke-width="0.8"/>
                            <circle cx="0" cy="0" r="6.2" fill="none" stroke="#1e293b" stroke-width="1.6" stroke-dasharray="1.8 2.1"/>
                            <circle cx="0" cy="0" r="3.6" fill="#475569" stroke="#e2e8f0" stroke-width="0.8"/>
                            <circle cx="0" cy="0" r="1.4" fill="#cbd5e1"/>
                        </g>
                    </defs>

                    <!-- Sombra general del vehículo sobre la plataforma -->
                    <ellipse cx="260" cy="172" rx="238" ry="7" fill="#000000" opacity="0.48"/>

                    <!-- Plataforma de Báscula de Acero y Celdas de Carga -->
                    <rect x="15" y="172" width="490" height="14" rx="2" fill="url(#deckGrad)" stroke="#64748b" stroke-width="1.6"/>
                    <line x1="16" y1="174" x2="504" y2="174" stroke="#94a3b8" stroke-width="0.8" opacity="0.5"/>
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

                    <!-- CHASIS Y QUINTA RUEDA (Conecta Furgón con Cabezal) -->
                    <rect x="256" y="138" width="214" height="9" rx="1.5" fill="#0f172a" stroke="#334155" stroke-width="1"/>
                    <rect x="288" y="134" width="32" height="6" rx="1.5" fill="#475569"/>

                    <!-- REMOLQUE / FURGÓN LARGO DE RASTRA (53 Pies) -->
                    <rect x="18" y="38" width="308" height="98" rx="3" fill="url(#trailerGrad)" stroke="#64748b" stroke-width="2"/>
                    <!-- Panel corrugado / franjas estructurales del furgón -->
                    <line x1="62" y1="39" x2="62" y2="135" stroke="#334155" stroke-width="1.4"/>
                    <line x1="106" y1="39" x2="106" y2="135" stroke="#334155" stroke-width="1.4"/>
                    <line x1="150" y1="39" x2="150" y2="135" stroke="#334155" stroke-width="1.4"/>
                    <line x1="194" y1="39" x2="194" y2="135" stroke="#334155" stroke-width="1.4"/>
                    <line x1="238" y1="39" x2="238" y2="135" stroke="#334155" stroke-width="1.4"/>
                    <line x1="282" y1="39" x2="282" y2="135" stroke="#334155" stroke-width="1.4"/>
                    <!-- Reflejo superior metálico y líneas horizontales en tonalidades azules -->
                    <line x1="20" y1="41" x2="324" y2="41" stroke="#93c5fd" stroke-width="1" opacity="0.28"/>
                    <rect x="19" y="44" width="306" height="2.5" fill="#3b82f6" opacity="0.48"/>
                    <rect x="19" y="117" width="306" height="4" fill="#1d4ed8" opacity="0.55"/>
                    <rect x="19" y="121" width="306" height="1.8" fill="#60a5fa" opacity="0.42"/>
                    <!-- Franja inferior reflectiva en tono azul metálico -->
                    <rect x="19" y="128" width="306" height="5" fill="#2563eb" opacity="0.68"/>
                    <rect x="19" y="128" width="306" height="1.2" fill="#93c5fd" opacity="0.45"/>
                    <!-- Patas / Tren de apoyo del remolque -->
                    <rect x="186" y="136" width="7" height="25" fill="#64748b"/>
                    <rect x="200" y="136" width="7" height="25" fill="#64748b"/>
                    <rect x="181" y="159" width="31" height="5" rx="1" fill="#475569"/>

                    <!-- GUARDABARROS TRASERO AZUL ESCULPIDO (Sobre ejes de tracción traseros) -->
                    <path d="M 263 148 Q 267 132 282 132 L 318 132 Q 335 132 337 148 L 329 148 Q 327 138 318 138 L 282 138 Q 273 138 271 148 Z" fill="url(#opBlue)" stroke="#3b82f6" stroke-width="0.9"/>

                    <!-- ESCAPES INCLINADOS CROMADOS (Sutil guiño detrás del sleeper) -->
                    <polygon points="331,136 336,136 324,25 318,28" fill="url(#chromeH)" stroke="#f8fafc" stroke-width="0.6"/>
                    <polygon points="336,136 341,136 330,30 325,33" fill="url(#chromeH)" stroke="#cbd5e1" stroke-width="0.5"/>
                    <rect x="328" y="72" width="13" height="3" rx="1" fill="#475569"/>
                    <rect x="331" y="112" width="12" height="3" rx="1" fill="#475569"/>

                    <!-- CABEZAL COMPACTO CON TROMPA CORTA (Estética Custom Azul/Rojo Sutil) -->
                    <!-- Base de carrocería azul metálico -->
                    <path d="M 340 138 L 340 50 Q 340 36 356 35 C 374 34 394 38 406 54 L 415 86 C 436 87 458 90 475 95 L 476 144 L 470 148 C 468 133 432 133 430 148 L 424 148 L 424 138 Z" fill="url(#opBlue)" stroke="#3b82f6" stroke-width="1.3"/>

                    <!-- Grupo recortado a la carrocería: Brillos, Llamas Sutiles y Líneas de Panel -->
                    <g clip-path="url(#cabBodyClip)">
                        <!-- Brillo superior aerodinámico -->
                        <path d="M 344 43 C 366 37 388 39 403 54" stroke="#93c5fd" stroke-width="2.2" opacity="0.4" stroke-linecap="round"/>

                        <!-- Llamas fantasma (ghost flames) 100% azules, apenas perceptibles (frente -> atrás) -->
                        <path d="M 476 94 L 476 102 C 458 99 440 96 422 95 C 409 94 398 92 388 88 C 402 88 416 89 430 90 C 446 92 462 93 476 94 Z" fill="url(#opGhostFlame)"/>
                        <path d="M 476 104 L 476 114 C 456 110 434 108 412 107 C 392 106 373 103 354 96 C 372 95 390 98 408 98 C 430 99 454 101 476 104 Z" fill="url(#opGhostFlame)"/>
                        <path d="M 474 118 L 474 131 C 456 125 438 122 420 123 C 398 124 374 121 350 113 C 372 112 394 114 414 114 C 434 115 454 116 474 118 Z" fill="url(#opGhostFlame)"/>
                        <path d="M 412 82 C 396 81 378 82 360 76 C 374 73 392 75 408 78 Z" fill="url(#opGhostFlameSoft)"/>
                        <path d="M 396 126 C 380 128 364 127 348 123 C 362 121 378 122 396 126 Z" fill="url(#opGhostFlameSoft)"/>

                        <!-- Reflejos muy tenues de barniz siguiendo las lenguas azules -->
                        <path d="M 474 95 C 452 93 426 91 394 89" stroke="#bfdbfe" stroke-width="0.55" opacity="0.18" stroke-linecap="round"/>
                        <path d="M 474 105 C 446 102 414 100 364 96" stroke="#bfdbfe" stroke-width="0.55" opacity="0.16" stroke-linecap="round"/>
                        <path d="M 468 119 C 436 116 398 115 358 113" stroke="#bfdbfe" stroke-width="0.5" opacity="0.14" stroke-linecap="round"/>

                        <!-- Costuras limpias de sleeper y puerta -->
                        <line x1="376" y1="36" x2="376" y2="138" stroke="#090d16" stroke-width="1.2" opacity="0.65"/>
                        <path d="M 378 62 L 407 62 L 413 88 L 413 136 L 378 136 Z" fill="none" stroke="#090d16" stroke-width="1" opacity="0.5"/>
                    </g>

                    <!-- Reborde del paso de rueda delantero -->
                    <path d="M 430 148 C 432 133 468 133 470 148" fill="none" stroke="#60a5fa" stroke-width="1.2" opacity="0.65"/>

                    <!-- VENTANAS, VISERA CROMADA Y ESPEJO -->
                    <!-- Ventana lateral del conductor -->
                    <path d="M 381 64 L 403 64 L 405 85 L 381 85 Z" fill="url(#glassGrad)" stroke="#cbd5e1" stroke-width="1.1"/>
                    <polygon points="384,83 395,66 400,66 389,83" fill="#93c5fd" opacity="0.22"/>
                    <!-- Botaguas superior -->
                    <path d="M 380 63 L 405 63 L 406 67 L 380 66 Z" fill="#1d4ed8" stroke="#60a5fa" stroke-width="0.6"/>

                    <!-- Parabrisas inclinado -->
                    <path d="M 404 60 L 411 60 L 417 85 L 408 85 Z" fill="url(#glassGrad)" stroke="#e2e8f0" stroke-width="1.1"/>
                    <line x1="407" y1="62" x2="412" y2="83" stroke="#93c5fd" stroke-width="1.1" opacity="0.35"/>

                    <!-- Visera cromada con luces de gálibo discretas -->
                    <path d="M 399 53 Q 412 53 420 60 L 416 64 Q 409 58 400 58 Z" fill="url(#chromeV)" stroke="#f8fafc" stroke-width="0.8"/>
                    <circle cx="404" cy="53" r="1.2" fill="#fef08a"/>
                    <circle cx="409.5" cy="54" r="1.2" fill="#fef08a"/>
                    <circle cx="415" cy="56" r="1.2" fill="#fef08a"/>

                    <!-- Espejo retrovisor cromado -->
                    <line x1="404" y1="68" x2="409" y2="68" stroke="#cbd5e1" stroke-width="1.1"/>
                    <line x1="405" y1="81" x2="409" y2="81" stroke="#cbd5e1" stroke-width="1.1"/>
                    <rect x="408.5" y="66" width="3" height="17" rx="1" fill="url(#chromeV)" stroke="#475569" stroke-width="0.5"/>

                    <!-- Manija y toma de aire cromada lateral -->
                    <rect x="382" y="102" width="9" height="2.6" rx="1" fill="url(#chromeV)"/>
                    <rect x="418" y="99" width="13" height="9" rx="1.8" fill="url(#chromeV)" stroke="#1e293b" stroke-width="0.8"/>
                    <line x1="420.5" y1="102" x2="428.5" y2="102" stroke="#334155" stroke-width="0.9"/>
                    <line x1="420.5" y1="105" x2="428.5" y2="105" stroke="#334155" stroke-width="0.9"/>

                    <!-- PARRILLA VERTICAL CROMADA, FARO LED Y PARACHOQUES -->
                    <path d="M 473 93 L 483 95 L 485 135 L 473 135 Z" fill="#0f172a" stroke="url(#chromeV)" stroke-width="2.2"/>
                    <line x1="476.5" y1="96" x2="477" y2="133" stroke="#e2e8f0" stroke-width="1.1"/>
                    <line x1="479.5" y1="96" x2="480.2" y2="133" stroke="#cbd5e1" stroke-width="1.1"/>
                    <line x1="482" y1="97" x2="483" y2="133" stroke="#e2e8f0" stroke-width="1.1"/>

                    <!-- Faro delantero LED -->
                    <path d="M 464 117 L 473 119 L 473 125 L 464 124 Z" fill="#fef08a" stroke="#e2e8f0" stroke-width="0.9" filter="url(#headlightGlow)"/>

                    <!-- Parachoques cromado y faldón inferior azul -->
                    <path d="M 466 135 L 490 136 L 488 149 L 466 148 Z" fill="url(#chromeV)" stroke="#f8fafc" stroke-width="0.9"/>
                    <line x1="468" y1="140" x2="488" y2="141" stroke="#ffffff" stroke-width="1.1" opacity="0.8"/>
                    <path d="M 465 148 L 482 149 L 479 155 L 464 154 Z" fill="url(#opBlueDark)" stroke="#3b82f6" stroke-width="0.7"/>

                    <!-- TANQUE DE DIÉSEL Y ESTRIBOS CROMADOS (Limpio entre ejes traseros y delantero) -->
                    <rect x="340" y="137" width="40" height="15" rx="5.5" fill="url(#chromeV)" stroke="#94a3b8" stroke-width="0.9"/>
                    <line x1="344" y1="140.5" x2="376" y2="140.5" stroke="#ffffff" stroke-width="1.5" opacity="0.75" stroke-linecap="round"/>
                    <rect x="351" y="137" width="2.5" height="15" fill="#334155"/>
                    <rect x="368" y="137" width="2.5" height="15" fill="#334155"/>

                    <rect x="383" y="138" width="40" height="14" rx="2.5" fill="url(#chromeV)" stroke="#94a3b8" stroke-width="0.9"/>
                    <rect x="388" y="141.5" width="30" height="2.6" rx="1" fill="#1e293b"/>
                    <rect x="388" y="146.5" width="30" height="2.6" rx="1" fill="#1e293b"/>

                    <!-- RUEDAS DE ALTA RESISTENCIA (5 ejes alineados sobre y=172) -->
                    <use href="#opWheel" x="56" y="156"/>
                    <use href="#opWheel" x="94" y="156"/>
                    <use href="#opWheel" x="282" y="156"/>
                    <use href="#opWheel" x="318" y="156"/>
                    <use href="#opWheel" x="450" y="156"/>

                    <!-- DISPLAY DIGITAL DE LA BÁSCULA (Indicador Industrial) -->
                    <rect x="394" y="8" width="116" height="25" rx="4" fill="#020617" stroke="#10b981" stroke-width="1.5"/>
                    <text x="403" y="25" fill="#10b981" font-family="'Courier New', monospace" font-size="12.5" font-weight="900" letter-spacing="1">84,520 LB</text>
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
