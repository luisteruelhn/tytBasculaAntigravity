<?php
/**
 * Gestión de Sesión, Autenticación y Seguridad (CSRF / RBAC)
 * Proyecto: tytBasculaAntigravity
 *
 * Provee funciones para iniciar sesión segura, verificar roles de usuario,
 * proteger contra CSRF y evitar secuestro de sesiones.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

/**
 * Inicializa la sesión con directivas de cookies seguras.
 */
function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Directivas de seguridad para cookies de sesión
        $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_set_cookie_params([
            'lifetime' => 0,              // Cookie de sesión (se borra al cerrar el navegador)
            'path'     => '/',
            'domain'   => '',
            'secure'   => $seguro,        // Solo HTTPS si está disponible
            'httponly' => true,          // Inaccesible desde JavaScript (mitiga XSS)
            'samesite' => 'Strict',      // Mitiga CSRF a nivel de navegador
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();

        // Control de inactividad: expira tras 30 minutos sin uso
        if (isset($_SESSION['ultimo_acceso']) && (time() - $_SESSION['ultimo_acceso'] > 1800)) {
            cerrarSesion();
            return;
        }
        $_SESSION['ultimo_acceso'] = time();

        // Generar token CSRF si no existe
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}

/**
 * Retorna el token CSRF actual para incrustarlo en formularios.
 */
function obtenerCsrfToken(): string
{
    iniciarSesionSegura();
    return $_SESSION['csrf_token'] ?? '';
}

/**
 * Valida que el token CSRF enviado coincida con el de la sesión.
 *
 * @param string|null $tokenEnviado Token recibido por POST.
 * @return bool True si es válido, False si fue alterado o falta.
 */
function validarCsrfToken(?string $tokenEnviado): bool
{
    iniciarSesionSegura();
    if (empty($tokenEnviado) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $tokenEnviado);
}

/**
 * Retorna los datos del usuario actualmente autenticado o null.
 *
 * @return array|null Datos del usuario o null si no hay sesión iniciada.
 */
function obtenerUsuarioActual(): ?array
{
    iniciarSesionSegura();
    if (empty($_SESSION['usuario_id']) || empty($_SESSION['empresa_id'])) {
        return null;
    }

    return [
        'id'               => (int)$_SESSION['usuario_id'],
        'empresa_id'       => (int)$_SESSION['empresa_id'],
        'nombre'           => (string)($_SESSION['usuario_nombre'] ?? ''),
        'usuario'          => (string)($_SESSION['usuario_login'] ?? ''),
        'rol'              => (string)($_SESSION['usuario_rol'] ?? 'OPERADOR'),
        'empresa_nombre'   => (string)($_SESSION['empresa_nombre'] ?? ''),
    ];
}

/**
 * Exige que exista un usuario logueado. Si no, redirige a login.php.
 *
 * @return array Datos del usuario verificado.
 */
function requerirAutenticacion(): array
{
    $usuario = obtenerUsuarioActual();
    if ($usuario === null) {
        header('Location: login.php');
        exit;
    }
    return $usuario;
}

/**
 * Control de Tasa de Intentos (Rate Limiting) contra Ataques de Fuerza Bruta.
 *
 * @param string $clave Clave identificadora (ej: 'ip:127.0.0.1' o 'usr:admin').
 * @param int $limiteIntentos Número máximo de intentos permitidos en la ventana.
 * @param int $segundosVentana Ventana de tiempo en segundos (ej: 60s o 300s).
 * @return bool True si el intento es permitido, False si está temporalmente bloqueado.
 */
function verificarTasaLimite(string $clave, int $limiteIntentos = 5, int $segundosVentana = 300): bool
{
    $directorio = sys_get_temp_dir() . '/bascula_intentos';
    if (!is_dir($directorio)) {
        @mkdir($directorio, 0700, true);
    }

    $archivo = $directorio . '/' . hash('sha256', $clave) . '.json';
    $ahora = time();
    $datos = ['intentos' => 0, 'primero' => $ahora];

    if (is_file($archivo)) {
        $contenido = @file_get_contents($archivo);
        if ($contenido !== false) {
            $decodificado = json_decode($contenido, true);
            if (is_array($decodificado)) {
                $datos = $decodificado;
            }
        }
    }

    // Si la ventana expiró, reiniciar conteo
    if ($ahora - ($datos['primero'] ?? 0) > $segundosVentana) {
        $datos = ['intentos' => 0, 'primero' => $ahora];
    }

    if ($datos['intentos'] >= $limiteIntentos) {
        return false; // Bloqueado
    }

    $datos['intentos']++;
    @file_put_contents($archivo, json_encode($datos), LOCK_EX);
    return true;
}

/**
 * Limpia los intentos fallidos tras un inicio de sesión exitoso.
 *
 * @param string $clave Clave identificadora a limpiar.
 */
function limpiarTasaLimite(string $clave): void
{
    $archivo = sys_get_temp_dir() . '/bascula_intentos/' . hash('sha256', $clave) . '.json';
    if (is_file($archivo)) {
        @unlink($archivo);
    }
}

/**
 * Comprueba si el usuario tiene uno de los roles permitidos.
 *
 * @param array $usuario Datos del usuario actual.
 * @param array $rolesPermitidos Lista de roles con permiso (ej: ['SUPER', 'ADMINISTRADOR']).
 * @return bool
 */
function tieneRol(array $usuario, array $rolesPermitidos): bool
{
    return in_array($usuario['rol'], $rolesPermitidos, true);
}

/**
 * Cierra la sesión activa y limpia todas las variables.
 */
function cerrarSesion(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}
