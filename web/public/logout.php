<?php
/**
 * Cierre de Sesión (Logout)
 * Proyecto: tytBasculaAntigravity
 *
 * Limpia y destruye la sesión activa del usuario y redirige al login.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/auth.php';

cerrarSesion();

header('Location: login.php');
exit;
