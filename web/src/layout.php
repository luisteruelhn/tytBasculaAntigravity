<?php
/**
 * Plantilla Base y Layout (Sidebar Cockpit Moderno y Encabezado)
 * Proyecto: tytBasculaAntigravity
 *
 * Renderiza la interfaz común con menú lateral tipo SaaS industrial,
 * colapsable, responsivo, con badges dinámicos e integración Dark/Light.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Renderiza la cabecera HTML, el sidebar moderno y abre el contenedor principal.
 *
 * @param string $titulo Titulo de la página.
 * @param string $seccionActiva Identificador de la sección para marcar el elemento activo.
 */
function renderizarEncabezado(string $titulo = 'Sistema de Báscula', string $seccionActiva = 'dashboard'): void
{
    $usuario = obtenerUsuarioActual();
    $nombreEmpresa = $usuario['empresa_nombre'] ?? 'BÁSCULA INDUSTRIAL';
    $nombreUsuario = $usuario['nombre'] ?? 'Invitado';
    $rolUsuario = $usuario['rol'] ?? '';

    // Consultar pesadas pendientes de segunda pesada para el badge dinámico
    $totalPendientes = 0;
    $esModoUltimoPeso = ($rolUsuario === 'REMOTO') || !esAccesoPlantaLocal();
    $ultimoPesoSidebar = 0.0;

    try {
        $pdo = obtenerDB();
        $empresaId = (int)($usuario['empresa_id'] ?? 0);
        if ($empresaId > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM t_pesadas WHERE empresa_id = :empresa_id AND estado = 'ABIERTA'");
            $stmt->execute([':empresa_id' => $empresaId]);
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) AS total FROM t_pesadas WHERE estado = 'ABIERTA'");
        }
        $totalPendientes = (int)($stmt->fetch()['total'] ?? 0);

        if ($esModoUltimoPeso && $empresaId > 0) {
            $stmtUlt = $pdo->prepare("
                SELECT COALESCE(peso_final_lb, peso_bruto_lb, tara_vehiculo_lb, 0) AS peso
                FROM t_pesadas
                WHERE empresa_id = :empresa_id AND estado <> 'ANULADA'
                ORDER BY COALESCE(segunda_pesada_at, primera_pesada_at, fecha_at) DESC, idpesada DESC
                LIMIT 1
            ");
            $stmtUlt->execute([':empresa_id' => $empresaId]);
            $ultimoPesoSidebar = (float)($stmtUlt->fetchColumn() ?: 0);
        }
    } catch (Exception $e) {
        // Si la base no está conectada o no hay sesión, continúa en 0 sin interrumpir la vista
        $totalPendientes = 0;
    }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?> — TYTONE BÁSCULA</title>
    <!-- Estilos CSS Modernos y Ligeros -->
    <link rel="stylesheet" href="assets/css/style.css?v=20261124m">
    <!-- Carga inmediata de tema para evitar parpadeos -->
    <script src="assets/js/theme.js"></script>
</head>
<body>

<div class="app-layout">
    <!-- Telón de fondo para versión móvil -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- =======================================================================
         BARRA LATERAL (SIDEBAR INDUSTRIAL MODERNO)
         ======================================================================= -->
    <aside class="app-sidebar" id="appSidebar">
        <!-- 1. Encabezado de la barra con Logo TYT y Empresa -->
        <div class="sidebar-header">
            <img src="assets/img/tytlogo.png" alt="TYT Systems" class="sidebar-logo">
            <div class="sidebar-brand-text">
                <span class="brand-title">TYTONE</span>
                <span class="brand-subtitle" title="<?= htmlspecialchars($nombreEmpresa, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($nombreEmpresa, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <!-- Botón para colapsar en escritorio -->
            <button type="button" class="sidebar-toggle-btn" id="sidebarToggle" title="Colapsar / Expandir menú">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
            </button>
        </div>

        <!-- 2. Navegación organizada en bloques funcionales con íconos vectoriales limpios -->
        <nav class="sidebar-nav">
            <!-- Bloque Operación -->
            <div>
                <span class="nav-group-title">Operación</span>
                <a href="index.php" class="nav-item <?= $seccionActiva === 'monitor' ? 'active' : '' ?>" title="Inicio / Monitor de Pesaje">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                        <line x1="8" y1="21" x2="16" y2="21"></line>
                        <line x1="12" y1="17" x2="12" y2="21"></line>
                    </svg>
                    <span class="nav-label">Inicio / Monitor</span>
                </a>

                <?php if (!$usuario || !tieneRol($usuario, ['REMOTO'])): ?>
                <a href="primera_pesada.php" class="nav-item <?= $seccionActiva === 'primera' ? 'active' : '' ?>" title="Primera Pesada (Entrada)">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M1 3h15v13H1z"></path>
                        <path d="M16 8h4l3 3v5h-7V8z"></path>
                        <circle cx="5.5" cy="18.5" r="2.5"></circle>
                        <circle cx="18.5" cy="18.5" r="2.5"></circle>
                    </svg>
                    <span class="nav-label">Primera Pesada</span>
                </a>

                <a href="segunda_pesada.php" class="nav-item <?= $seccionActiva === 'segunda' ? 'active' : '' ?>" title="Segunda Pesada (Salida)">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    <span class="nav-label">Segunda Pesada</span>
                    <?php if ($totalPendientes > 0): ?>
                        <span class="nav-badge" title="<?= $totalPendientes ?> camiones pendientes en patio">
                            <?= $totalPendientes ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>

            <!-- Bloque Gestión y Consultas -->
            <div>
                <span class="nav-group-title">Consultas</span>
                <a href="dashboard.php" class="nav-item <?= $seccionActiva === 'dashboard' ? 'active' : '' ?>" title="Dashboard de Operación">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="7" height="9"></rect>
                        <rect x="14" y="3" width="7" height="5"></rect>
                        <rect x="14" y="12" width="7" height="9"></rect>
                        <rect x="3" y="16" width="7" height="5"></rect>
                    </svg>
                    <span class="nav-label">Dashboard</span>
                </a>

                <a href="pesadas.php" class="nav-item <?= $seccionActiva === 'pesadas' ? 'active' : '' ?>" title="Boletas e Historial">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    <span class="nav-label">Boletas e Historial</span>
                </a>

                <a href="reportes.php" class="nav-item <?= $seccionActiva === 'reportes' ? 'active' : '' ?>" title="Reportes y Estadísticas">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                    <span class="nav-label">Reportes</span>
                </a>
            </div>

            <!-- Bloque Catálogos -->
            <div>
                <span class="nav-group-title">Catálogos</span>
                <a href="clientes.php" class="nav-item <?= $seccionActiva === 'clientes' ? 'active' : '' ?>" title="Clientes y Proveedores">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span class="nav-label">Clientes / Proveedores</span>
                </a>

                <a href="categorias.php" class="nav-item <?= $seccionActiva === 'categorias' ? 'active' : '' ?>" title="Categorías de Producto">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <line x1="8" y1="6" x2="21" y2="6"></line>
                        <line x1="8" y1="12" x2="21" y2="12"></line>
                        <line x1="8" y1="18" x2="21" y2="18"></line>
                        <line x1="3" y1="6" x2="3.01" y2="6"></line>
                        <line x1="3" y1="12" x2="3.01" y2="12"></line>
                        <line x1="3" y1="18" x2="3.01" y2="18"></line>
                    </svg>
                    <span class="nav-label">Categorías</span>
                </a>

                <a href="productos.php" class="nav-item <?= $seccionActiva === 'productos' ? 'active' : '' ?>" title="Productos">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line>
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                        <line x1="12" y1="22.08" x2="12" y2="12"></line>
                    </svg>
                    <span class="nav-label">Productos</span>
                </a>

                <a href="presentaciones.php" class="nav-item <?= $seccionActiva === 'presentaciones' ? 'active' : '' ?>" title="Taras de Empaque (Sacos)">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <path d="M16 10a4 4 0 0 1-8 0"></path>
                    </svg>
                    <span class="nav-label">Taras de empaque</span>
                </a>

                <a href="presentaciones_tipo.php" class="nav-item <?= $seccionActiva === 'presentaciones_tipo' ? 'active' : '' ?>" title="Tipos de Empaque (Saco / Granel)">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <!-- Ícono de camión / vehículo -->
                        <path d="M1 3h15v13H1z"></path>
                        <path d="M16 8h4l3 3v5h-7V8z"></path>
                        <circle cx="5.5" cy="18.5" r="2.5"></circle>
                        <circle cx="18.5" cy="18.5" r="2.5"></circle>
                    </svg>
                    <span class="nav-label">Tipos de empaque</span>
                </a>
            </div>

            <!-- Bloque Configuración y Sistema (Admin / Super) -->
            <?php if ($usuario && tieneRol($usuario, ['SUPER', 'ADMINISTRADOR', 'SUPERVISOR'])): ?>
            <div>
                <span class="nav-group-title">Sistema</span>
                <?php if (tieneRol($usuario, ['SUPER', 'ADMINISTRADOR'])): ?>
                <a href="empresa.php" class="nav-item <?= $seccionActiva === 'empresa' ? 'active' : '' ?>" title="Datos de la Empresa">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M3 21h18"></path>
                        <path d="M5 21V7l8-4v18"></path>
                        <path d="M19 21V11l-6-4"></path>
                        <path d="M9 9h1"></path>
                        <path d="M9 13h1"></path>
                        <path d="M9 17h1"></path>
                    </svg>
                    <span class="nav-label">Mi Empresa</span>
                </a>
                <?php endif; ?>

                <a href="usuarios.php" class="nav-item <?= $seccionActiva === 'usuarios' ? 'active' : '' ?>" title="Usuarios y Roles">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="8.5" cy="7" r="4"></circle>
                        <line x1="20" y1="8" x2="20" y2="14"></line>
                        <line x1="23" y1="11" x2="17" y2="11"></line>
                    </svg>
                    <span class="nav-label">Usuarios</span>
                </a>

                <a href="config_bascula.php" class="nav-item <?= $seccionActiva === 'config' ? 'active' : '' ?>" title="Configuración de Báscula">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                    <span class="nav-label">Configuración Báscula</span>
                </a>

                <?php if (tieneRol($usuario, ['SUPER'])): ?>
                <a href="despliegue.php" class="nav-item <?= $seccionActiva === 'despliegue' ? 'active' : '' ?>" title="Despliegue y Sincronización Remota">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" y1="3" x2="12" y2="15"></line>
                    </svg>
                    <span class="nav-label">Despliegue / Nube</span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </nav>

        <!-- 3. Mini-Monitor en vivo integrado en la barra lateral -->
        <div class="sidebar-bascula-card">
            <div id="sidebarStatusDot" class="bascula-status-dot dot-estable"></div>
            <div class="bascula-info">
                <span class="bascula-label" id="sidebarModoLabel"><?= $esModoUltimoPeso ? 'ÚLTIMA PESADA' : 'BÁSCULA' ?></span>
                <span class="bascula-weight" id="sidebarPesoLabel"><?= number_format($ultimoPesoSidebar, 0) ?> LB</span>
            </div>
        </div>

        <!-- 4. Pie de usuario, alternancia de tema y salida -->
        <div class="sidebar-footer">
            <div class="user-profile">
                <a href="mi_clave.php" style="display: flex; align-items: center; gap: 0.75rem; text-decoration: none; color: inherit;" title="Cambiar mi contraseña">
                    <div class="user-avatar" title="<?= htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(strtoupper(substr($nombreUsuario, 0, 2)), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="user-details">
                        <span class="user-name"><?= htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="user-role"><?= htmlspecialchars($rolUsuario, ENT_QUOTES, 'UTF-8') ?> &bull; <small style="color: var(--primary);">Mi Clave</small></span>
                    </div>
                </a>
            </div>

            <div class="sidebar-actions">
                <button type="button" class="btn-tema-toggle" onclick="alternarTema()" title="Cambiar tema">
                    ☀️
                </button>
                <a href="logout.php" class="btn-logout" title="Cerrar sesión">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- =======================================================================
         CONTENIDO PRINCIPAL
         ======================================================================= -->
    <div class="app-main-wrapper">
        <!-- Barra superior para dispositivos móviles -->
        <header class="mobile-topbar">
            <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Abrir menú lateral">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
            <span class="mobile-brand">TYTONE BÁSCULA</span>
            <button type="button" class="btn-tema-toggle" onclick="alternarTema()" title="Cambiar tema">
                ☀️
            </button>
        </header>

        <main class="main-content">
<?php
}

/**
 * Renderiza el cierre de la estructura principal y carga scripts base.
 */
function renderizarPie(): void
{
    $usuario = obtenerUsuarioActual();
    $esAdmin = $usuario && tieneRol($usuario, ['SUPER', 'ADMINISTRADOR', 'ADMIN', 'REMOTO']);
    $rolUsuario = $usuario['rol'] ?? '';
    $esModoRemoto = ($rolUsuario === 'REMOTO') || !esAccesoPlantaLocal();
    $configBasculaSync = null;

    if ($usuario && !$esModoRemoto) {
        try {
            $pdoSync = obtenerDB();
            $stmtSync = $pdoSync->prepare("
                SELECT modo, puerto_com, baudios
                FROM t_configuracion_bascula
                WHERE empresa_id = :empresa_id AND estacion = 'PRINCIPAL'
                LIMIT 1
            ");
            $stmtSync->execute([':empresa_id' => (int)($usuario['empresa_id'] ?? 1)]);
            $filaSync = $stmtSync->fetch(PDO::FETCH_ASSOC);
            if ($filaSync) {
                $configBasculaSync = [
                    'modo'       => (($filaSync['modo'] ?? 'SERIAL') === 'SERIAL') ? 'real' : 'simulado',
                    'puerto_com' => (string)($filaSync['puerto_com'] ?? ''),
                    'baudios'    => (int)($filaSync['baudios'] ?? 9600),
                ];
            }
        } catch (Exception $e) {
            $configBasculaSync = null;
        }
    }
?>
        </main>

        <footer class="footer">
            <p>TYTOne Santa Bárbara Cel:+504 95173632 &copy; <?= date('Y') ?> — Sistema de Pesaje Electrónico Industrial</p>
        </footer>
    </div>
</div>

<?php if ($esAdmin): ?>
<!-- WIDGET FLOTANTE: ASISTENTE IA DE BÁSCULA (SOLO SUPER / ADMIN) -->
<div id="aiChatWidget" style="position: fixed; bottom: 22px; right: 22px; z-index: 99999; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <!-- Botón Flotante -->
    <button type="button" id="btnToggleChat" onclick="toggleChatGemini()" style="display: flex; align-items: center; gap: 0.5rem; background: #0b57d0; color: #ffffff; border: none; border-radius: 50px; padding: 0.65rem 1.25rem; font-weight: 700; font-size: 0.88rem; box-shadow: 0 4px 14px rgba(11, 87, 208, 0.4); cursor: pointer; transition: transform 0.15s ease, background 0.2s ease;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
        </svg>
        <span>Asistente TYTOne</span>
    </button>

    <!-- Ventana del Chat (Oculta por defecto) -->
    <div id="chatBoxContainer" style="display: none; width: 380px; max-width: calc(100vw - 32px); height: 500px; max-height: calc(100vh - 90px); background: #ffffff; border: 1px solid #cbd5e1; border-radius: 14px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.18); overflow: hidden; flex-direction: column; position: absolute; bottom: 56px; right: 0;">
        
        <!-- Cabecera -->
        <div style="background: #0f172a; color: #ffffff; padding: 0.85rem 1rem; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <div style="width: 10px; height: 10px; background: #22c55e; border-radius: 50%;"></div>
                <div>
                    <strong style="font-size: 0.9rem; display: block; line-height: 1.1;">Asistente TYTOne</strong>
                    <span style="font-size: 0.72rem; color: #94a3b8;">Consultas de pesadas y reportes</span>
                </div>
            </div>
            <button type="button" onclick="toggleChatGemini()" style="background: none; border: none; color: #94a3b8; font-size: 1.3rem; cursor: pointer; line-height: 1; padding: 0 0.35rem;">
                &times;
            </button>
        </div>

        <!-- Área de mensajes -->
        <div id="chatMessages" style="flex: 1; padding: 1rem; overflow-y: auto; background: #f8fafc; display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.85rem;">
            <div style="background: #ffffff; border: 1px solid #e2e8f0; padding: 0.75rem 0.9rem; border-radius: 10px; color: #1e293b; max-width: 90%; align-self: flex-start; line-height: 1.4;">
                👋 Hola. Soy tu Asistente TYTOne. Pregúntame sobre camiones en patio, compras, ventas o reportes.
            </div>
        </div>

        <!-- Atajos sugeridos -->
        <div style="padding: 0.4rem 0.75rem; background: #f1f5f9; border-top: 1px solid #e2e8f0; display: flex; gap: 0.4rem; overflow-x: auto; white-space: nowrap;">
            <button type="button" onclick="enviarAtajoChat('¿Cuántas pesadas hay hoy?')" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; font-size: 0.72rem; padding: 0.25rem 0.6rem; cursor: pointer; color: #334155;">
                📊 Pesadas hoy
            </button>
            <button type="button" onclick="enviarAtajoChat('¿Hay camiones pendientes en patio?')" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; font-size: 0.72rem; padding: 0.25rem 0.6rem; cursor: pointer; color: #334155;">
                🚚 En patio
            </button>
            <button type="button" onclick="enviarAtajoChat('¿Cuáles son las últimas boletas cerradas?')" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; font-size: 0.72rem; padding: 0.25rem 0.6rem; cursor: pointer; color: #334155;">
                📋 Últimas boletas
            </button>
        </div>

        <!-- Input de envío -->
        <form id="chatForm" onsubmit="enviarMensajeChat(event)" style="display: flex; border-top: 1px solid #cbd5e1; background: #ffffff; margin: 0;">
            <input type="text" id="chatInput" placeholder="Escribe tu consulta operativa..." style="flex: 1; border: none; padding: 0.75rem 0.9rem; font-size: 0.85rem; outline: none; background: transparent;">
            <button type="submit" id="chatSendBtn" style="background: #0b57d0; color: #ffffff; border: none; padding: 0 1rem; font-weight: 700; font-size: 0.85rem; cursor: pointer;">
                Enviar
            </button>
        </form>
    </div>
</div>

<script>
function toggleChatGemini() {
    const box = document.getElementById('chatBoxContainer');
    if (box.style.display === 'none' || box.style.display === '') {
        box.style.display = 'flex';
        document.getElementById('chatInput').focus();
    } else {
        box.style.display = 'none';
    }
}

function enviarAtajoChat(texto) {
    document.getElementById('chatInput').value = texto;
    document.getElementById('chatForm').dispatchEvent(new Event('submit'));
}

async function enviarMensajeChat(e) {
    e.preventDefault();
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if (!msg) return;

    const cont = document.getElementById('chatMessages');
    const sendBtn = document.getElementById('chatSendBtn');

    // Mensaje de usuario
    cont.innerHTML += `<div style="background: #0b57d0; color: #ffffff; padding: 0.75rem 0.9rem; border-radius: 10px; max-width: 90%; align-self: flex-end; line-height: 1.4;">${escapeHtml(msg)}</div>`;
    input.value = '';
    sendBtn.disabled = true;
    cont.scrollTop = cont.scrollHeight;

    // Indicador escribiendo
    const idTemp = 'msg_load_' + Date.now();
    cont.innerHTML += `<div id="${idTemp}" style="background: #ffffff; border: 1px solid #e2e8f0; padding: 0.65rem 0.9rem; border-radius: 10px; color: #64748b; max-width: 90%; align-self: flex-start; font-style: italic;">Consultando datos...</div>`;
    cont.scrollTop = cont.scrollHeight;

    try {
        const resp = await fetch('api_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mensaje: msg })
        });
        const data = await resp.json();
        const loadElem = document.getElementById(idTemp);
        if (loadElem) loadElem.remove();

        if (data.ok) {
            cont.innerHTML += `<div style="background: #ffffff; border: 1px solid #e2e8f0; padding: 0.75rem 0.9rem; border-radius: 10px; color: #1e293b; max-width: 90%; align-self: flex-start; line-height: 1.4; white-space: pre-wrap;">${escapeHtml(data.respuesta)}</div>`;
        } else {
            cont.innerHTML += `<div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.65rem 0.9rem; border-radius: 10px; max-width: 90%; align-self: flex-start;">⚠️ ${escapeHtml(data.error || 'Error al procesar la respuesta.')}</div>`;
        }
    } catch (err) {
        const loadElem = document.getElementById(idTemp);
        if (loadElem) loadElem.remove();
        cont.innerHTML += `<div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.65rem 0.9rem; border-radius: 10px; max-width: 90%; align-self: flex-start;">⚠️ Error de conexión con el servicio.</div>`;
    } finally {
        sendBtn.disabled = false;
        cont.scrollTop = cont.scrollHeight;
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
<?php endif; ?>

<?php if ($configBasculaSync !== null): ?>
<script>
const BASCULA_DB_SYNC = <?= json_encode($configBasculaSync, JSON_UNESCAPED_UNICODE) ?>;

async function notificarApiBascula(cfg) {
    try {
        if (cfg.puerto_com) {
            await fetch('api_peso_local.php?accion=configurar_serial', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ puerto_com: cfg.puerto_com, baudios: Number(cfg.baudios || 9600) })
            });
        }
        if (cfg.modo) {
            await fetch('api_peso_local.php?accion=modo', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ modo: cfg.modo })
            });
        }
    } catch (e) {
        // Silencioso si el microservicio 8018 aún no está iniciado
    }
}

async function sincronizarBasculaApi() {
    if (!document.getElementById('displayPesoValor') && !document.getElementById('livePesoValor')) return;
    try {
        const resp = await fetch('api_peso_local.php?accion=salud', { credentials: 'same-origin' });
        if (!resp.ok) return;
        const salud = await resp.json();
        if (salud.pausado_manual) return;
        const requiereSync = (
            salud.modo !== BASCULA_DB_SYNC.modo ||
            (BASCULA_DB_SYNC.puerto_com && salud.puerto_com !== BASCULA_DB_SYNC.puerto_com) ||
            (BASCULA_DB_SYNC.modo === 'real' && !salud.lector_activo)
        );
        if (requiereSync) {
            await notificarApiBascula(BASCULA_DB_SYNC);
        }
    } catch (e) {
        // Microservicio local apagado
    }
}

document.addEventListener('DOMContentLoaded', sincronizarBasculaApi);

// Reintento automático de pesadas pendientes hacia Hostinger cada 5 minutos
function intentarSyncPendientesNube() {
    fetch('api_sync_pendientes.php', { method: 'POST', credentials: 'same-origin' }).catch(() => {});
}
setTimeout(intentarSyncPendientesNube, 15000);
setInterval(intentarSyncPendientesNube, 300000);
</script>
<?php endif; ?>

<!-- Scripts esenciales: monitor de báscula y control del sidebar -->
<script src="assets/js/pesaje.js?v=20261124m"></script>
<script src="assets/js/sidebar.js?v=20261124m"></script>
</body>
</html>
<?php
}
