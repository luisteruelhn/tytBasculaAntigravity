<?php
/**
 * Módulo de Despliegue y Sincronización Remota (Hostinger / inversionesvallecillo.com)
 * Proyecto: tytBasculaAntigravity
 *
 * Permite configurar las credenciales del servidor remoto, probar conexión MySQL,
 * exportar respaldo .sql, generar el paquete .zip listo para public_html con config/local.php,
 * subir archivos vía FTP/FTPS y sincronizar pesadas + catálogos hacia Hostinger por HTTPS.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';
require_once dirname(__DIR__) . '/src/sync_nube.php';

$usuarioActual = requerirAutenticacion();
if (!tieneRol($usuarioActual, ['SUPER'])) {
    http_response_code(403);
    die('Acceso denegado. Solo el rol SUPER puede gestionar despliegues.');
}

$pdo = obtenerDB();
$csrfToken = obtenerCsrfToken();

$cfgSyncActual = obtenerConfigSyncNube();
$tokenSugerido = $cfgSyncActual['token'] !== ''
    ? $cfgSyncActual['token']
    : bin2hex(random_bytes(16));

// Valores por defecto o conservados en sesión (sin guardar contraseñas en BD)
$form = $_SESSION['deploy_form'] ?? [
    'db_host'         => 'localhost',
    'db_port'         => 3306,
    'db_name'         => '',
    'db_user'         => '',
    'ftp_host'        => 'ftp.inversionesvallecillo.com',
    'ftp_port'        => 21,
    'ftp_user'        => '',
    'ftp_path'        => '/public_html',
    'ftp_ssl'         => 0,
    'sync_remote_url' => $cfgSyncActual['url'] ?: 'https://inversionesvallecillo.com/bascula/api_sync.php',
    'sync_token'      => $tokenSugerido,
];

if (empty($form['sync_remote_url'])) {
    $form['sync_remote_url'] = 'https://inversionesvallecillo.com/bascula/api_sync.php';
}
if (empty($form['sync_token'])) {
    $form['sync_token'] = $tokenSugerido;
}

$mensajeExito = null;
$mensajeError = null;

/**
 * Construye el contenido PHP de web/config/local.php para el servidor remoto (Hostinger).
 */
function generarContenidoLocalPhp(string $host, int $port, string $name, string $user, string $pass, string $syncToken): string
{
    return "<?php\n"
        . "/**\n"
        . " * Configuración privada de entorno remoto (Hostinger) generada por Despliegue / Nube.\n"
        . " */\n"
        . "declare(strict_types=1);\n\n"
        . "define('DB_HOST', " . var_export($host, true) . ");\n"
        . "define('DB_PORT', " . $port . ");\n"
        . "define('DB_NAME', " . var_export($name, true) . ");\n"
        . "define('DB_USER', " . var_export($user, true) . ");\n"
        . "define('DB_PASS', " . var_export($pass, true) . ");\n"
        . "define('SYNC_TOKEN', " . var_export($syncToken, true) . ");\n";
}

/**
 * Guarda en esta PC de producción el archivo privado web/config/sync_remoto.php.
 */
function guardarConfigSyncLocal(string $url, string $token): void
{
    $contenido = "<?php\n"
        . "/**\n"
        . " * Configuración local de sincronización hacia Hostinger (excluido de Git).\n"
        . " */\n"
        . "declare(strict_types=1);\n\n"
        . "if (!defined('SYNC_REMOTE_URL')) define('SYNC_REMOTE_URL', " . var_export($url, true) . ");\n"
        . "if (!defined('SYNC_TOKEN')) define('SYNC_TOKEN', " . var_export($token, true) . ");\n";

    file_put_contents(dirname(__DIR__) . '/config/sync_remoto.php', $contenido);
}

/**
 * Genera y envía al navegador un respaldo .sql completo de la base de datos activa.
 */
function descargarRespaldoSql(PDO $pdo): void
{
    $nombreArchivo = 'respaldo_bascula_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');

    echo "-- Respaldo generado por TYTOne Báscula (" . date('Y-m-d H:i:s') . ")\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n";
    echo "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
    echo "SET NAMES utf8mb4;\n\n";

    $tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tablas as $tabla) {
        $tablaEsc = str_replace('`', '``', (string)$tabla);
        $createRow = $pdo->query("SHOW CREATE TABLE `{$tablaEsc}`")->fetch(PDO::FETCH_NUM);
        echo "DROP TABLE IF EXISTS `{$tablaEsc}`;\n";
        echo ($createRow[1] ?? '') . ";\n\n";

        $stmtRows = $pdo->query("SELECT * FROM `{$tablaEsc}`");
        while ($row = $stmtRows->fetch(PDO::FETCH_ASSOC)) {
            $cols = array_map(fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', array_keys($row));
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($row));
            echo "INSERT INTO `{$tablaEsc}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

/**
 * Copia recursivamente un directorio a una carpeta temporal de empaquetado excluyendo archivos locales privados.
 */
function copiarDirectorioStaging(string $origen, string $destino): void
{
    if (!is_dir($destino)) {
        mkdir($destino, 0775, true);
    }
    $items = scandir($origen);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
            continue;
        }
        if (in_array($item, ['local.php', 'sync_remoto.php'], true) || str_ends_with($item, '.log')) {
            continue;
        }
        $rutaOrig = $origen . DIRECTORY_SEPARATOR . $item;
        $rutaDest = $destino . DIRECTORY_SEPARATOR . $item;
        if (is_dir($rutaOrig)) {
            copiarDirectorioStaging($rutaOrig, $rutaDest);
        } else {
            copy($rutaOrig, $rutaDest);
        }
    }
}

/**
 * Elimina recursivamente una carpeta temporal.
 */
function eliminarDirectorioTemp(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items !== false) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? eliminarDirectorioTemp($p) : @unlink($p);
        }
    }
    @rmdir($dir);
}

/**
 * Construye el archivo ZIP listo para descomprimir directamente en /public_html de Hostinger.
 * Funciona tanto con ZipArchive como con PowerShell Compress-Archive (sin tocar php.ini).
 */
function construirZipDespliegue(string $webRoot, string $localPhpContent): ?string
{
    $stageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bascula_stage_' . bin2hex(random_bytes(4));
    mkdir($stageDir, 0775, true);

    // Estructura lista para /public_html de inversionesvallecillo.com:
    // - /public_html/index.php -> redirige a /bascula/login.php
    // - /public_html/bascula/  -> archivos públicos (login.php, index.php, api_sync.php, etc.)
    // - /public_html/src/      -> protegido por .htaccess
    // - /public_html/config/   -> protegido por .htaccess + local.php inyectado
    copiarDirectorioStaging($webRoot . DIRECTORY_SEPARATOR . 'public', $stageDir . DIRECTORY_SEPARATOR . 'bascula');
    copiarDirectorioStaging($webRoot . DIRECTORY_SEPARATOR . 'src', $stageDir . DIRECTORY_SEPARATOR . 'src');
    copiarDirectorioStaging($webRoot . DIRECTORY_SEPARATOR . 'config', $stageDir . DIRECTORY_SEPARATOR . 'config');

    file_put_contents($stageDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'local.php', $localPhpContent);

    $htaccessDeny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
    file_put_contents($stageDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.htaccess', $htaccessDeny);
    file_put_contents($stageDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . '.htaccess', $htaccessDeny);

    $rootIndex = "<?php\nheader('Location: bascula/login.php');\nexit;\n";
    file_put_contents($stageDir . DIRECTORY_SEPARATOR . 'index.php', $rootIndex);

    $zipTemp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bascula_deploy_' . bin2hex(random_bytes(4)) . '.zip';

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipTemp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($stageDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                $rutaReal = $item->getPathname();
                $relativa = str_replace('\\', '/', substr($rutaReal, strlen($stageDir) + 1));
                if ($item->isDir()) {
                    $zip->addEmptyDir($relativa);
                } else {
                    $zip->addFile($rutaReal, $relativa);
                }
            }
            $zip->close();
        }
    } else {
        // Fallback limpio con PowerShell en Windows sin modificar php.ini
        $cmd = sprintf(
            'powershell -NoProfile -Command "Compress-Archive -Path %s -DestinationPath %s -Force"',
            escapeshellarg($stageDir . DIRECTORY_SEPARATOR . '*'),
            escapeshellarg($zipTemp)
        );
        @shell_exec($cmd);
    }

    eliminarDirectorioTemp($stageDir);
    return (is_file($zipTemp) && filesize($zipTemp) > 0) ? $zipTemp : null;
}

/**
 * Sube un directorio completo por FTP de forma recursiva.
 */
function subirDirectorioFtp($conn, string $dirLocal, string $dirRemoto): void
{
    @ftp_mkdir($conn, $dirRemoto);
    $items = scandir($dirLocal);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || str_starts_with($item, '.')) {
            continue;
        }
        $rutaLocal = $dirLocal . DIRECTORY_SEPARATOR . $item;
        $rutaRemota = rtrim($dirRemoto, '/') . '/' . $item;

        if (is_dir($rutaLocal)) {
            subirDirectorioFtp($conn, $rutaLocal, $rutaRemota);
        } else {
            if (in_array($item, ['local.php', 'sync_remoto.php'], true) && basename($dirLocal) === 'config') {
                continue;
            }
            if (!@ftp_put($conn, $rutaRemota, $rutaLocal, FTP_BINARY)) {
                throw new RuntimeException('No se pudo subir el archivo: ' . $rutaRemota);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? '')) {
        $mensajeError = 'Token CSRF inválido o sesión expirada. Recarga la página e intenta de nuevo.';
    } else {
        $accion = trim((string)($_POST['accion'] ?? ''));

        $dbHost  = trim((string)($_POST['db_host'] ?? 'localhost'));
        $dbPort  = max(1, min(65535, (int)($_POST['db_port'] ?? 3306)));
        $dbName  = trim((string)($_POST['db_name'] ?? ''));
        $dbUser  = trim((string)($_POST['db_user'] ?? ''));
        $dbPass  = (string)($_POST['db_pass'] ?? '');

        $ftpHost = trim((string)($_POST['ftp_host'] ?? ''));
        $ftpPort = max(1, min(65535, (int)($_POST['ftp_port'] ?? 21)));
        $ftpUser = trim((string)($_POST['ftp_user'] ?? ''));
        $ftpPass = (string)($_POST['ftp_pass'] ?? '');
        $ftpPath = '/' . ltrim(trim((string)($_POST['ftp_path'] ?? '/public_html')), '/');
        $ftpSsl  = isset($_POST['ftp_ssl']) ? 1 : 0;

        $syncUrl   = trim((string)($_POST['sync_remote_url'] ?? 'https://inversionesvallecillo.com/bascula/api_sync.php'));
        $syncToken = trim((string)($_POST['sync_token'] ?? $tokenSugerido));

        $_SESSION['deploy_form'] = $form = [
            'db_host'         => $dbHost,
            'db_port'         => $dbPort,
            'db_name'         => $dbName,
            'db_user'         => $dbUser,
            'ftp_host'        => $ftpHost,
            'ftp_port'        => $ftpPort,
            'ftp_user'        => $ftpUser,
            'ftp_path'        => $ftpPath,
            'ftp_ssl'         => $ftpSsl,
            'sync_remote_url' => $syncUrl,
            'sync_token'      => $syncToken,
        ];

        if ($accion === 'guardar_sync_local') {
            if ($syncUrl === '' || $syncToken === '') {
                $mensajeError = 'Indica la URL remota de sincronización y el Token Secreto.';
            } else {
                guardarConfigSyncLocal($syncUrl, $syncToken);
                $mensajeExito = 'Configuración local de sincronización guardada en web/config/sync_remoto.php. Las pesadas nuevas se enviarán automáticamente a ' . $syncUrl;
            }
        } elseif ($accion === 'sincronizar_ahora') {
            if ($syncUrl !== '' && $syncToken !== '') {
                guardarConfigSyncLocal($syncUrl, $syncToken);
            }
            $resSync = sincronizarTodoConNube($pdo);
            if ($resSync['ok']) {
                $detalles = [];
                foreach (($resSync['resumen'] ?? []) as $tbl => $cnt) {
                    $detalles[] = "{$tbl}: {$cnt}";
                }
                $mensajeExito = '☁️ ' . $resSync['mensaje'] . (!empty($detalles) ? ' (' . implode(', ', $detalles) . ')' : '');
            } else {
                $mensajeError = 'No se pudo sincronizar con Hostinger: ' . $resSync['mensaje'];
            }
        } elseif ($accion === 'probar_mysql') {
            if ($dbHost === '' || $dbName === '' || $dbUser === '') {
                $mensajeError = 'Completa Host, Base de datos y Usuario MySQL para probar la conexión.';
            } else {
                try {
                    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
                    $pdoRemoto = new PDO($dsn, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 4,
                    ]);
                    $ver = $pdoRemoto->query('SELECT VERSION()')->fetchColumn();
                    $mensajeExito = "Conexión MySQL exitosa con {$dbHost}:{$dbPort} (Servidor: {$ver}).";
                } catch (Throwable $e) {
                    $mensajeError = 'No se pudo conectar al MySQL remoto: ' . $e->getMessage();
                }
            }
        } elseif ($accion === 'descargar_sql') {
            descargarRespaldoSql($pdo);
        } elseif ($accion === 'generar_zip') {
            if ($dbHost === '' || $dbName === '' || $dbUser === '') {
                $mensajeError = 'Ingresa Host, Base de datos y Usuario MySQL de Hostinger para generar el paquete.';
            } else {
                if ($syncUrl !== '' && $syncToken !== '') {
                    guardarConfigSyncLocal($syncUrl, $syncToken);
                }
                $localPhp = generarContenidoLocalPhp($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $syncToken);
                $zipTemp = construirZipDespliegue(dirname(__DIR__), $localPhp);

                if (!$zipTemp) {
                    $mensajeError = 'No se pudo crear el paquete ZIP de despliegue.';
                } else {
                    $nombreZip = 'bascula_hostinger_' . date('Ymd_His') . '.zip';
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $nombreZip . '"');
                    header('Content-Length: ' . filesize($zipTemp));
                    readfile($zipTemp);
                    @unlink($zipTemp);
                    exit;
                }
            }
        } elseif ($accion === 'subir_ftp') {
            if (!function_exists('ftp_connect')) {
                $mensajeError = 'La extensión FTP de PHP no está habilitada. Usa "Generar y Descargar ZIP".';
            } elseif ($ftpHost === '' || $ftpUser === '' || $ftpPass === '') {
                $mensajeError = 'Completa Host, Usuario y Contraseña FTP para subir al servidor.';
            } elseif ($dbHost === '' || $dbName === '' || $dbUser === '') {
                $mensajeError = 'Completa también los datos de Base de Datos MySQL para generar config/local.php.';
            } else {
                if ($syncUrl !== '' && $syncToken !== '') {
                    guardarConfigSyncLocal($syncUrl, $syncToken);
                }
                $conn = $ftpSsl && function_exists('ftp_ssl_connect')
                    ? @ftp_ssl_connect($ftpHost, $ftpPort, 10)
                    : @ftp_connect($ftpHost, $ftpPort, 10);

                if (!$conn || !@ftp_login($conn, $ftpUser, $ftpPass)) {
                    if ($conn) @ftp_close($conn);
                    $mensajeError = 'No se pudo autenticar en el servidor FTP. Verifica host, usuario y contraseña.';
                } else {
                    try {
                        @ftp_pasv($conn, true);
                        $webRoot = dirname(__DIR__);
                        $destBase = rtrim($ftpPath, '/');
                        if ($destBase === '') $destBase = '/';

                        subirDirectorioFtp($conn, $webRoot . '/public', $destBase . '/bascula');
                        subirDirectorioFtp($conn, $webRoot . '/src', $destBase . '/src');
                        subirDirectorioFtp($conn, $webRoot . '/config', $destBase . '/config');

                        $tmpLocal = tempnam(sys_get_temp_dir(), 'local_cfg_');
                        file_put_contents($tmpLocal, generarContenidoLocalPhp($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $syncToken));
                        if (!@ftp_put($conn, $destBase . '/config/local.php', $tmpLocal, FTP_BINARY)) {
                            @unlink($tmpLocal);
                            throw new RuntimeException('No se pudo subir config/local.php al servidor remoto.');
                        }
                        @unlink($tmpLocal);

                        $tmpIdx = tempnam(sys_get_temp_dir(), 'root_idx_');
                        file_put_contents($tmpIdx, "<?php\nheader('Location: bascula/login.php');\nexit;\n");
                        @ftp_put($conn, $destBase . '/index.php', $tmpIdx, FTP_BINARY);
                        @unlink($tmpIdx);

                        @ftp_close($conn);
                        $mensajeExito = "Despliegue completado por FTP en {$ftpHost}{$destBase}.";
                    } catch (Throwable $e) {
                        @ftp_close($conn);
                        $mensajeError = 'Error durante la subida FTP: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

$totalPendientesSync = 0;
try {
    $totalPendientesSync = (int)$pdo->query('SELECT COUNT(*) FROM t_sincronizacion_pendiente WHERE enviado_at IS NULL')->fetchColumn();
} catch (Throwable $e) {
    $totalPendientesSync = 0;
}

renderizarEncabezado('Despliegue / Nube', 'despliegue');
?>

<div style="max-width: 1020px; margin: 0 auto;">
    <div style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
        <div>
            <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
                ADMINISTRACIÓN &middot; HOSTINGER (INVERSIONESVALLECILLO.COM)
            </span>
            <h1 style="font-size: 1.85rem; font-weight: 800; margin: 0.2rem 0 0.25rem 0; color: var(--text-main);">
                Despliegue y Sincronización en la Nube
            </h1>
            <p style="margin: 0; font-size: 0.88rem; color: var(--text-muted);">
                Prepara el paquete web para <strong>inversionesvallecillo.com</strong> y sincroniza automáticamente las pesadas locales hacia la nube (modo solo consulta).
            </p>
        </div>
        <div style="background: <?= $totalPendientesSync > 0 ? '#fef3c7' : '#ecfdf5' ?>; border: 1px solid <?= $totalPendientesSync > 0 ? '#fcd34d' : '#a7f3d0' ?>; color: <?= $totalPendientesSync > 0 ? '#92400e' : '#047857' ?>; padding: 0.5rem 0.9rem; border-radius: 8px; font-size: 0.84rem; font-weight: 700;">
            Cola local pendiente: <?= $totalPendientesSync ?> evento(s)
        </div>
    </div>

    <?php if ($mensajeExito): ?>
        <div class="alert alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($mensajeError): ?>
        <div class="alert alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- GUÍA RÁPIDA DE 3 PASOS PARA HOSTINGER -->
    <div class="card" style="padding: 1rem 1.35rem; margin-bottom: 1rem; background: #eff6ff; border: 1px solid #bfdbfe;">
        <div style="font-weight: 800; font-size: 0.9rem; color: #1e3a8a; margin-bottom: 0.45rem;">
            📋 Pasos en Hostinger para <code>inversionesvallecillo.com</code>:
        </div>
        <ol style="margin: 0; padding-left: 1.25rem; font-size: 0.84rem; color: #1e40af; line-height: 1.55;">
            <li>En el panel de Hostinger (<strong>Bases de datos &rarr; Gestión de MySQL</strong>), crea una nueva base de datos y copia aquí abajo su <strong>Nombre de BD</strong>, <strong>Usuario</strong> y <strong>Contraseña</strong>.</li>
            <li>Haz clic en <strong>🗄️ Descargar Respaldo SQL</strong> e impórtalo en el <strong>phpMyAdmin</strong> de Hostinger.</li>
            <li>Haz clic en <strong>📦 Generar y Descargar ZIP</strong>, súbelo al Administrador de Archivos de Hostinger dentro de <code>public_html</code> y descomprímelo. Luego presiona <strong>☁️ Sincronizar Ahora con Hostinger</strong>.</li>
        </ol>
    </div>

    <form method="POST" action="despliegue.php" class="card" style="padding: 1.5rem 1.75rem; border-radius: 12px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <h3 style="margin: 0 0 0.85rem 0; font-size: 1.05rem; font-weight: 800; color: var(--text-main);">
            1. Base de Datos MySQL en Hostinger (se inyecta en <code>config/local.php</code> del ZIP)
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)); gap: 0.85rem; margin-bottom: 1.25rem;">
            <div>
                <label class="form-label" for="db_host">Host MySQL (en Hostinger)</label>
                <input type="text" id="db_host" name="db_host" class="form-control" required value="<?= htmlspecialchars((string)$form['db_host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="localhost">
            </div>
            <div>
                <label class="form-label" for="db_port">Puerto</label>
                <input type="number" id="db_port" name="db_port" class="form-control" required value="<?= (int)$form['db_port'] ?>">
            </div>
            <div>
                <label class="form-label" for="db_name">Nombre Base de Datos</label>
                <input type="text" id="db_name" name="db_name" class="form-control" value="<?= htmlspecialchars((string)$form['db_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="u123456789_bascula">
            </div>
            <div>
                <label class="form-label" for="db_user">Usuario MySQL</label>
                <input type="text" id="db_user" name="db_user" class="form-control" value="<?= htmlspecialchars((string)$form['db_user'], ENT_QUOTES, 'UTF-8') ?>" placeholder="u123456789_admin">
            </div>
            <div>
                <label class="form-label" for="db_pass">Contraseña MySQL</label>
                <input type="password" id="db_pass" name="db_pass" class="form-control" autocomplete="new-password" placeholder="Contraseña BD Hostinger">
            </div>
        </div>

        <h3 style="margin: 1rem 0 0.85rem 0; font-size: 1.05rem; font-weight: 800; color: var(--text-main); border-top: 1px solid var(--border-color); padding-top: 1.1rem;">
            2. Sincronización Automática Planta &rarr; Hostinger (HTTPS)
        </h3>
        <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 0.85rem; margin-bottom: 1.25rem;">
            <div>
                <label class="form-label" for="sync_remote_url">URL Receptor en Hostinger (<code>api_sync.php</code>)</label>
                <input type="url" id="sync_remote_url" name="sync_remote_url" class="form-control" value="<?= htmlspecialchars((string)$form['sync_remote_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://inversionesvallecillo.com/bascula/api_sync.php">
            </div>
            <div>
                <label class="form-label" for="sync_token">Token Secreto de Sincronización</label>
                <input type="text" id="sync_token" name="sync_token" class="form-control" style="font-family: monospace;" value="<?= htmlspecialchars((string)$form['sync_token'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <h3 style="margin: 1rem 0 0.85rem 0; font-size: 1.05rem; font-weight: 800; color: var(--text-main); border-top: 1px solid var(--border-color); padding-top: 1.1rem;">
            3. Servidor FTP / FTPS (Opcional — si prefieres subir por FTP en lugar de ZIP)
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)); gap: 0.85rem; margin-bottom: 1.25rem;">
            <div>
                <label class="form-label" for="ftp_host">Host FTP</label>
                <input type="text" id="ftp_host" name="ftp_host" class="form-control" value="<?= htmlspecialchars((string)$form['ftp_host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="ftp.inversionesvallecillo.com">
            </div>
            <div>
                <label class="form-label" for="ftp_port">Puerto FTP</label>
                <input type="number" id="ftp_port" name="ftp_port" class="form-control" value="<?= (int)$form['ftp_port'] ?>">
            </div>
            <div>
                <label class="form-label" for="ftp_user">Usuario FTP</label>
                <input type="text" id="ftp_user" name="ftp_user" class="form-control" value="<?= htmlspecialchars((string)$form['ftp_user'], ENT_QUOTES, 'UTF-8') ?>" placeholder="u123456789">
            </div>
            <div>
                <label class="form-label" for="ftp_pass">Contraseña FTP</label>
                <input type="password" id="ftp_pass" name="ftp_pass" class="form-control" autocomplete="new-password" placeholder="Contraseña FTP">
            </div>
            <div>
                <label class="form-label" for="ftp_path">Carpeta Pública Destino</label>
                <input type="text" id="ftp_path" name="ftp_path" class="form-control" value="<?= htmlspecialchars((string)$form['ftp_path'], ENT_QUOTES, 'UTF-8') ?>" placeholder="/public_html">
            </div>
            <div style="display: flex; align-items: flex-end; padding-bottom: 0.4rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600; font-size: 0.88rem; cursor: pointer;">
                    <input type="checkbox" name="ftp_ssl" value="1" <?= !empty($form['ftp_ssl']) ? 'checked' : '' ?>>
                    Usar FTPS (SSL)
                </label>
            </div>
        </div>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; border-top: 1px solid var(--border-color); padding-top: 1.15rem;">
            <button type="submit" name="accion" value="generar_zip" class="btn btn-primary" style="background: #0b57d0; font-weight: 700;">
                📦 Generar y Descargar ZIP (para Hostinger)
            </button>
            <button type="submit" name="accion" value="descargar_sql" class="btn btn-secondary" style="font-weight: 700;">
                🗄️ Descargar Respaldo SQL
            </button>
            <button type="submit" name="accion" value="guardar_sync_local" class="btn btn-secondary" style="font-weight: 700;">
                💾 Guardar Config. Sync en esta PC
            </button>
            <button type="submit" name="accion" value="sincronizar_ahora" class="btn btn-primary" style="background: #047857; font-weight: 700;">
                ☁️ Sincronizar Ahora con Hostinger
            </button>
            <button type="submit" name="accion" value="subir_ftp" class="btn btn-secondary" style="font-weight: 600;">
                🚀 Subir por FTP
            </button>
        </div>
    </form>
</div>

<?php
renderizarPie();
