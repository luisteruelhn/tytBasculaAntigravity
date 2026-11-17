<?php
/**
 * Plantilla de configuración privada por entorno (Producción / Hostinger / VPS)
 *
 * Copiar este archivo como `local.php` en esta misma carpeta (`web/config/local.php`).
 * El archivo `local.php` está ignorado por Git (.gitignore) para proteger credenciales
 * y evitar sobrescrituras al desplegar desde GitHub.
 */

declare(strict_types=1);

define('DB_HOST', 'localhost');          // En Hostinger suele ser 'localhost' o '127.0.0.1'
define('DB_PORT', 3306);
define('DB_NAME', 'nombre_base_datos');
define('DB_USER', 'usuario_mysql');
define('DB_PASS', 'contrasena_segura');
