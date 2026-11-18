<?php
/**
 * Conexión a Base de Datos MariaDB (XAMPP)
 * Proyecto: tytBasculaAntigravity
 *
 * Este archivo centraliza la configuración y la instancia PDO para
 * interactuar con MariaDB de forma segura, usando sentencias preparadas.
 */

declare(strict_types=1);

// Fijar zona horaria oficial del proyecto para evitar desfase UTC en hostings remotos
if (date_default_timezone_get() !== 'America/Tegucigalpa') {
    date_default_timezone_set('America/Tegucigalpa');
}

// Cargar configuración privada del entorno (Hostinger, VPS, local) si existe
if (file_exists(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

// Parámetros de conexión predeterminados para XAMPP
if (!defined('DB_HOST')) define('DB_HOST', getenv('BASCULA_DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', (int)(getenv('BASCULA_DB_PORT') ?: 3306));
if (!defined('DB_NAME')) define('DB_NAME', getenv('BASCULA_DB_NAME') ?: 'dbBascula');
if (!defined('DB_USER')) define('DB_USER', getenv('BASCULA_DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('BASCULA_DB_PASS') ?: '');

/**
 * Obtiene la conexión PDO singleton a la base de datos.
 *
 * @return PDO Instancia activa de conexión.
 * @throws PDOException Si falla la conexión con el servidor MariaDB.
 */
function obtenerDB(): PDO
{
    static $conexion = null;

    if ($conexion === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        $opciones = [
            // Lanzar excepciones en errores para manejo controlado
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Desactivar emulación de preparadas para máxima seguridad contra inyecciones SQL
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Devolver registros como arreglos asociativos por defecto
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Tiempo de espera para la conexión (en segundos)
            PDO::ATTR_TIMEOUT            => 3,
        ];

        try {
            $conexion = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $e) {
            // Log de error interno sin exponer credenciales en pantalla
            error_log('Error de conexión a MariaDB en XAMPP: ' . $e->getMessage());
            throw new RuntimeException(
                'No se pudo conectar a la base de datos en XAMPP (127.0.0.1:3306/dbBascula). ' .
                'Por favor verifica que el servicio MySQL esté iniciado en el panel de XAMPP.'
            );
        }
    }

    return $conexion;
}
