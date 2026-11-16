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
    try {
        $pdo = obtenerDB();
        $stmt = $pdo->query("SELECT COUNT(*) AS total FROM t_pesadas WHERE estado = 'PENDIENTE_SEGUNDA'");
        $totalPendientes = (int)($stmt->fetch()['total'] ?? 0);
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
    <title><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?> — TYTONE BÁSCULA</title>
    <!-- Estilos CSS Modernos y Ligeros -->
    <link rel="stylesheet" href="assets/css/style.css">
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
                <a href="index.php" class="nav-item <?= $seccionActiva === 'dashboard' ? 'active' : '' ?>" title="Inicio / Monitor">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="7" height="9"></rect>
                        <rect x="14" y="3" width="7" height="5"></rect>
                        <rect x="14" y="12" width="7" height="9"></rect>
                        <rect x="3" y="16" width="7" height="5"></rect>
                    </svg>
                    <span class="nav-label">Inicio / Monitor</span>
                </a>

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
            </div>

            <!-- Bloque Gestión y Consultas -->
            <div>
                <span class="nav-group-title">Consultas</span>
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

            <!-- Bloque Catálogos y Configuración (Admin / Super / Supervisor) -->
            <?php if ($usuario && tieneRol($usuario, ['SUPER', 'ADMINISTRADOR', 'SUPERVISOR'])): ?>
            <div>
                <span class="nav-group-title">Administración</span>
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

                <a href="clientes.php" class="nav-item <?= $seccionActiva === 'clientes' ? 'active' : '' ?>" title="Clientes y Proveedores">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span class="nav-label">Clientes / Proveedores</span>
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

                <a href="presentaciones.php" class="nav-item <?= $seccionActiva === 'presentaciones' ? 'active' : '' ?>" title="Presentaciones y Empaque">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <path d="M16 10a4 4 0 0 1-8 0"></path>
                    </svg>
                    <span class="nav-label">Taras de empaque</span>
                </a>

                <a href="taras.php" class="nav-item <?= $seccionActiva === 'taras' ? 'active' : '' ?>" title="Taras de Vehículos">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <path d="M12 2v20"></path>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                    <span class="nav-label">Taras de Vehículos</span>
                </a>

                <a href="config_bascula.php" class="nav-item <?= $seccionActiva === 'config' ? 'active' : '' ?>" title="Configuración de Báscula">
                    <svg class="nav-icon" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                    <span class="nav-label">Configuración Báscula</span>
                </a>
            </div>
            <?php endif; ?>
        </nav>

        <!-- 3. Mini-Monitor en vivo integrado en la barra lateral -->
        <div class="sidebar-bascula-card">
            <div id="sidebarStatusDot" class="bascula-status-dot dot-estable"></div>
            <div class="bascula-info">
                <span class="bascula-label" id="sidebarModoLabel">BÁSCULA</span>
                <span class="bascula-weight" id="sidebarPesoLabel">0 LB</span>
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
?>
        </main>

        <footer class="footer">
            <p>tytBasculaAntigravity &copy; <?= date('Y') ?> — Sistema de Pesaje Electrónico Industrial</p>
        </footer>
    </div>
</div>

<!-- Scripts esenciales: monitor de báscula y control del sidebar -->
<script src="assets/js/pesaje.js"></script>
<script src="assets/js/sidebar.js"></script>
</body>
</html>
<?php
}
