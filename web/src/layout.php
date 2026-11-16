<?php
/**
 * Plantilla Base y Layout (Encabezado y Pie)
 * Proyecto: tytBasculaAntigravity
 *
 * Renderiza la interfaz común: cabecera HTML, barra de navegación,
 * indicador de usuario y cierre con scripts esenciales.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Renderiza la cabecera HTML y barra de navegación superior.
 *
 * @param string $titulo Titulo de la página.
 * @param string $seccionActiva Identificador de la sección actual para el menú.
 */
function renderizarEncabezado(string $titulo = 'Sistema de Báscula', string $seccionActiva = 'dashboard'): void
{
    $usuario = obtenerUsuarioActual();
    $nombreEmpresa = $usuario['empresa_nombre'] ?? 'BÁSCULA INDUSTRIAL';
    $nombreUsuario = $usuario['nombre'] ?? 'Invitado';
    $rolUsuario = $usuario['rol'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?> — tytBascula</title>
    <!-- Estilos CSS Modernos y Ligeros -->
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Carga inmediata de tema para evitar parpadeos -->
    <script src="assets/js/theme.js"></script>
</head>
<body>

<header class="navbar">
    <a href="index.php" class="brand">
        <img src="assets/img/tytlogo.png" alt="TYT Systems" class="navbar-logo" style="height: 38px; width: auto; object-fit: contain;">
        <span>TYTONE BÁSCULA</span>
        <span class="brand-badge"><?= htmlspecialchars($nombreEmpresa, ENT_QUOTES, 'UTF-8') ?></span>
    </a>

    <nav>
        <ul class="nav-links">
            <li><a href="index.php" class="<?= $seccionActiva === 'dashboard' ? 'active' : '' ?>">📊 Inicio</a></li>
            <li><a href="primera_pesada.php" class="<?= $seccionActiva === 'primera' ? 'active' : '' ?>">🚛 Primera Pesada</a></li>
            <li><a href="segunda_pesada.php" class="<?= $seccionActiva === 'segunda' ? 'active' : '' ?>">✅ Segunda Pesada</a></li>
            <li><a href="pesadas.php" class="<?= $seccionActiva === 'pesadas' ? 'active' : '' ?>">📋 Boletas</a></li>
            <?php if ($usuario && tieneRol($usuario, ['SUPER', 'ADMINISTRADOR'])): ?>
                <li><a href="config_bascula.php" class="<?= $seccionActiva === 'config' ? 'active' : '' ?>">⚙️ Configuración</a></li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="nav-user">
        <!-- Botón de Alternancia de Tema Claro / Oscuro -->
        <button type="button" class="btn-tema-toggle" onclick="alternarTema()" aria-label="Cambiar tema">
            ☀️ <span class="tema-texto">Modo Claro</span>
        </button>

        <?php if ($usuario): ?>
            <span>👤 <?= htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8') ?> <span class="user-badge">(<?= htmlspecialchars($rolUsuario, ENT_QUOTES, 'UTF-8') ?>)</span></span>
            <a href="logout.php" class="btn btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.85rem;">Salir</a>
        <?php else: ?>
            <a href="login.php" class="btn btn-primary" style="padding: 0.35rem 0.75rem; font-size: 0.85rem;">Ingresar</a>
        <?php endif; ?>
    </div>
</header>

<main class="main-container">
<?php
}

/**
 * Renderiza el pie de página y scripts JavaScript.
 */
function renderizarPie(): void
{
?>
</main>

<footer class="footer">
    <p>tytBasculaAntigravity &copy; <?= date('Y') ?> — Sistema de Pesaje Electrónico Industrial</p>
</footer>

<!-- Monitor de Báscula en Vivo -->
<script src="assets/js/pesaje.js"></script>
</body>
</html>
<?php
}
