<?php
/**
 * Módulo de Despliegue y Sincronización Remota (Hostinger / cPanel / VPS)
 * Proyecto: tytBasculaAntigravity
 *
 * Permite configurar las generales del servidor remoto, probar conexión MySQL,
 * exportar respaldo .sql, generar el paquete .zip configurado con config/local.php
 * o subir archivos vía FTP/FTPS sin exponer credenciales en GitHub.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/layout.php';

$usuarioActual = requerirAutenticacion();
if (!tieneRol($usuarioActual, ['SUPER'])) {
    http_response_code(403);
    die('Acceso denegado. Solo el rol SUPER puede gestionar despliegues.');
}

$pdo = obtenerDB();
$csrfToken = obtenerCsrfToken();

// Valores por defecto o conservados en sesión (sin guardar contraseñas en BD)
$form = $_SESSION['deploy_form'] ?? [
    'db_host'  => 'localhost',
    'db_port'  => 3306,
    'db_name'  => '',
    'db_user'  => '',
    'ftp_host' => '',
    'ftp_port' => 21,
    'ftp_user' => '',
    'ftp_path' => '/public_html',
    'ftp_ssl'  => 0,
];

$mensajeExito = null;
$mensajeError = null;

/**
 * Construye el contenido PHP de web/config/local.php de forma segura usando var_export.
 */
function generarContenidoLocalPhp(string $host, int $port, string $name, string $user, string $pass): string
{
    return "<?php\n"
        . "/**\n"
        . " * Configuración privada de entorno remoto generada por Despliegue / Nube.\n"
        . " */\n"
        . "declare(strict_types=1);\n\n"
        . "define('DB_HOST', " . var_export($host, true) . ");\n"
        . "define('DB_PORT', " . $port . ");\n"
        . "define('DB_NAME', " . var_export($name, true) . ");\n"
        . "define('DB_USER', " . var_export($user, true) . ");\n"
        . "define('DB_PASS', " . var_export($pass, true) . ");\n";
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
    echo "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

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
 * Agrega un directorio recursivamente a un ZipArchive excluyendo archivos sensibles o temporales.
 */
function agregarDirectorioAlZip(ZipArchive $zip, string $dirBase, string $prefijoZip): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dirBase, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $item) {
        $rutaReal = $item->getPathname();
        $relativa = str_replace('\\', '/', substr($rutaReal, strlen($dirBase) + 1));

        // Excluir archivos privados locales o temporales
        if ($relativa === 'local.php' || str_ends_with($relativa, '.log') || str_starts_with($relativa, '.')) {
            continue;
        }

        $destino = ltrim($prefijoZip . '/' . $relativa, '/');
        if ($item->isDir()) {
            $zip->addEmptyDir($destino);
        } else {
            $zip->addFile($rutaReal, $destino);
        }
    }
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
            if ($item === 'local.php' && basename($dirLocal) === 'config') {
                continue; // Se sube aparte con las credenciales del formulario
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

        $_SESSION['deploy_form'] = $form = [
            'db_host'  => $dbHost,
            'db_port'  => $dbPort,
            'db_name'  => $dbName,
            'db_user'  => $dbUser,
            'ftp_host' => $ftpHost,
            'ftp_port' => $ftpPort,
            'ftp_user' => $ftpUser,
            'ftp_path' => $ftpPath,
            'ftp_ssl'  => $ftpSsl,
        ];

        if ($accion === 'probar_mysql') {
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
            if (!class_exists('ZipArchive')) {
                $mensajeError = 'La extensión ZipArchive de PHP no está habilitada en este servidor.';
            } elseif ($dbHost === '' || $dbName === '' || $dbUser === '') {
                $mensajeError = 'Ingresa al menos Host, Base de datos y Usuario MySQL para generar el paquete.';
            } else {
                $zipTemp = tempnam(sys_get_temp_dir(), 'bascula_deploy_');
                $zip = new ZipArchive();
                if ($zip->open($zipTemp, ZipArchive::OVERWRITE) !== true) {
                    $mensajeError = 'No se pudo crear el archivo ZIP temporal.';
                } else {
                    $webRoot = dirname(__DIR__);
                    agregarDirectorioAlZip($zip, $webRoot . '/public', 'public');
                    agregarDirectorioAlZip($zip, $webRoot . '/src', 'src');
                    agregarDirectorioAlZip($zip, $webRoot . '/config', 'config');

                    // Inyectar config/local.php con las credenciales del servidor destino
                    $localPhp = generarContenidoLocalPhp($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
                    $zip->addFromString('config/local.php', $localPhp);
                    $zip->close();

                    $nombreZip = 'bascula_deploy_' . date('Ymd_His') . '.zip';
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

                        subirDirectorioFtp($conn, $webRoot . '/public', $destBase . '/public');
                        subirDirectorioFtp($conn, $webRoot . '/src', $destBase . '/src');
                        subirDirectorioFtp($conn, $webRoot . '/config', $destBase . '/config');

                        // Subir config/local.php generado en memoria
                        $tmpLocal = tempnam(sys_get_temp_dir(), 'local_cfg_');
                        file_put_contents($tmpLocal, generarContenidoLocalPhp($dbHost, $dbPort, $dbName, $dbUser, $dbPass));
                        if (!@ftp_put($conn, $destBase . '/config/local.php', $tmpLocal, FTP_BINARY)) {
                            @unlink($tmpLocal);
                            throw new RuntimeException('No se pudo subir config/local.php al servidor remoto.');
                        }
                        @unlink($tmpLocal);
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

renderizarEncabezado('Despliegue / Nube', 'despliegue');
?>

<div style="max-width: 1000px; margin: 0 auto;">
    <div style="margin-bottom: 1.5rem;">
        <span style="font-size: 0.78rem; font-weight: 800; color: #1e40af; letter-spacing: 1.5px; text-transform: uppercase;">
            ADMINISTRACIÓN &middot; SOLO SUPER
        </span>
        <h1 style="font-size: 2rem; font-weight: 800; margin: 0.2rem 0 0.25rem 0; color: var(--text-main);">
            Despliegue y Sincronización Remota
        </h1>
        <p style="margin: 0; font-size: 0.9rem; color: var(--text-muted);">
            Configura las credenciales del servidor destino (Hostinger, cPanel, InfinityFree o VPS) para generar el paquete listo para producción o desplegar por FTP sin guardar contraseñas en GitHub.
        </p>
    </div>

    <?php if ($mensajeExito): ?>
        <div class="alert alert-success" style="margin-bottom: 1.25rem;"><?= htmlspecialchars($mensajeExito, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($mensajeError): ?>
        <div class="alert alert-danger" style="margin-bottom: 1.25rem;"><?= htmlspecialchars($mensajeError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- PRESETS RÁPIDOS -->
    <div class="card" style="padding: 1rem 1.5rem; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
        <span style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">Plantillas rápidas de servidor:</span>
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <button type="button" class="btn btn-secondary" onclick="aplicarPreset('hostinger')">Hostinger (hPanel)</button>
            <button type="button" class="btn btn-secondary" onclick="aplicarPreset('cpanel')">cPanel / VPS</button>
            <button type="button" class="btn btn-secondary" onclick="aplicarPreset('infinityfree')">InfinityFree</button>
        </div>
    </div>

    <form method="POST" action="despliegue.php" class="card" style="padding: 1.75rem 2rem; border-radius: 12px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <h3 style="margin: 0 0 1rem 0; font-size: 1.1rem; font-weight: 800; color: var(--text-main);">
            1. Base de Datos MySQL Remota (para <code>config/local.php</code>)
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div>
                <label class="form-label" for="db_host">Host MySQL</label>
                <input type="text" id="db_host" name="db_host" class="form-control" required value="<?= htmlspecialchars((string)$form['db_host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="localhost">
            </div>
            <div>
                <label class="form-label" for="db_port">Puerto</label>
                <input type="number" id="db_port" name="db_port" class="form-control" required value="<?= (int)$form['db_port'] ?>">
            </div>
            <div>
                <label class="form-label" for="db_name">Nombre Base de Datos</label>
                <input type="text" id="db_name" name="db_name" class="form-control" value="<?= htmlspecialchars((string)$form['db_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="u123456_bascula">
            </div>
            <div>
                <label class="form-label" for="db_user">Usuario MySQL</label>
                <input type="text" id="db_user" name="db_user" class="form-control" value="<?= htmlspecialchars((string)$form['db_user'], ENT_QUOTES, 'UTF-8') ?>" placeholder="u123456_admin">
            </div>
            <div>
                <label class="form-label" for="db_pass">Contraseña MySQL</label>
                <input type="password" id="db_pass" name="db_pass" class="form-control" autocomplete="new-password" placeholder="Contraseña BD remota">
            </div>
        </div>

        <h3 style="margin: 1.25rem 0 1rem 0; font-size: 1.1rem; font-weight: 800; color: var(--text-main); border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
            2. Servidor FTP / FTPS (Opcional — solo si subes directo)
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div>
                <label class="form-label" for="ftp_host">Host FTP</label>
                <input type="text" id="ftp_host" name="ftp_host" class="form-control" value="<?= htmlspecialchars((string)$form['ftp_host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="ftp.tudominio.com">
            </div>
            <div>
                <label class="form-label" for="ftp_port">Puerto FTP</label>
                <input type="number" id="ftp_port" name="ftp_port" class="form-control" value="<?= (int)$form['ftp_port'] ?>">
            </div>
            <div>
                <label class="form-label" for="ftp_user">Usuario FTP</label>
                <input type="text" id="ftp_user" name="ftp_user" class="form-control" value="<?= htmlspecialchars((string)$form['ftp_user'], ENT_QUOTES, 'UTF-8') ?>" placeholder="u123456">
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

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
            <button type="submit" name="accion" value="generar_zip" class="btn btn-primary" style="background: #0b57d0; font-weight: 700;">
                📦 Generar y Descargar ZIP
            </button>
            <button type="submit" name="accion" value="descargar_sql" class="btn btn-secondary" style="font-weight: 600;">
                🗄️ Descargar Respaldo SQL
            </button>
            <button type="submit" name="accion" value="probar_mysql" class="btn btn-secondary" style="font-weight: 600;">
                🔌 Probar MySQL Remoto
            </button>
            <button type="submit" name="accion" value="subir_ftp" class="btn btn-secondary" style="font-weight: 600;">
                🚀 Subir por FTP
            </button>
        </div>

        <p style="margin: 1rem 0 0 0; font-size: 0.82rem; color: var(--text-muted);">
            <strong>Flujo recomendado con GitHub:</strong> Trabaja tus pruebas en la rama <code>main</code>. Cuando estés listo para producción, fusiona hacia la rama <code>produccion</code> conectada a Hostinger y coloca el archivo <code>config/local.php</code> una sola vez en el servidor.
        </p>
    </form>
</div>

<script>
function aplicarPreset(tipo) {
    const dbHost = document.getElementById('db_host');
    const dbPort = document.getElementById('db_port');
    const ftpPath = document.getElementById('ftp_path');
    if (tipo === 'hostinger') {
        dbHost.value = 'localhost';
        dbPort.value = '3306';
        ftpPath.value = '/public_html';
    } else if (tipo === 'cpanel') {
        dbHost.value = 'localhost';
        dbPort.value = '3306';
        ftpPath.value = '/public_html';
    } else if (tipo === 'infinityfree') {
        dbHost.value = 'sql301.infinityfree.com';
        dbPort.value = '3306';
        ftpPath.value = '/htdocs';
    }
}
</script>

<?php
renderizarPie();
