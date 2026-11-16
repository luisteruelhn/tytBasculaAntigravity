"""Instalación local separada, persistente con fuentes de peso configurables (Windows)."""
import argparse
from contextlib import contextmanager
from datetime import datetime
import hashlib
import ipaddress
import json
import msvcrt
import os
import re
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import time
from urllib.request import build_opener, ProxyHandler
import webbrowser

ROOT = Path(__file__).resolve().parents[1]
PRIVATE = ROOT / '.runtime/local'
PORTS = {'db': 3318, 'api': 8018, 'web': 8088}


def terminate_tree(process):
    """Cierra también el hijo que crea el ejecutable redirector del venv."""
    if process.poll() is None:
        subprocess.run([str(Path(os.environ.get('SystemRoot', 'C:/Windows')) / 'System32/taskkill.exe'),
                        '/PID', str(process.pid), '/T', '/F'], capture_output=True, timeout=10,
                       creationflags=subprocess.CREATE_NO_WINDOW)
        process.wait(timeout=15)


def lan_interface(value):
    try:
        interface = ipaddress.IPv4Interface(value)
        private = [ipaddress.IPv4Network(cidr) for cidr in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')]
        if '/' not in value or not any(interface.network.subnet_of(net) for net in private):
            raise ValueError
        if interface.ip in (interface.network.network_address, interface.network.broadcast_address):
            raise ValueError
        return interface
    except ValueError:
        raise argparse.ArgumentTypeError('Indica IPv4/prefijo de una interfaz privada, por ejemplo 192.168.5.81/24.') from None


def literal(value):
    return 'CONVERT(0x' + value.encode('utf-8').hex() + ' USING utf8mb4)'


def save_json(path, value):
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(value, indent=2, ensure_ascii=False), encoding='utf-8')
    temporary.replace(path)


class AlreadyRunning(RuntimeError):
    pass


@contextmanager
def exclusive():
    with (PRIVATE / 'ejecucion.lock').open('a+b') as lock:
        lock.seek(0)
        if os.fstat(lock.fileno()).st_size == 0:
            lock.write(b'0')
            lock.flush()
        lock.seek(0)
        try:
            msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
        except OSError:
            raise AlreadyRunning('La instalación ya está abierta; no se inicia otra instancia.') from None
        try:
            yield
        finally:
            lock.seek(0)
            msvcrt.locking(lock.fileno(), msvcrt.LK_UNLCK, 1)


def open_existing(open_browser):
    """Solo se llama cuando el bloqueo de esta instalación está ocupado."""
    try:
        state = json.loads((PRIVATE / 'servidor-listo.json').read_text(encoding='utf-8'))
        if Path(state['root']).resolve() != ROOT:
            raise ValueError
        url = state['url']
        match = re.fullmatch(r'http://([0-9.]+)(?::([0-9]{1,5}))?/login\.php', url)
        if not match:
            raise ValueError
        host = ipaddress.IPv4Address(match[1])
        if host != ipaddress.IPv4Address('127.0.0.1') and not any(host in ipaddress.IPv4Network(net) for net in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')):
            raise ValueError
        if not 1 <= int(match[2] or 80) <= 65535 or type(state['api_port']) is not int or not 1 <= state['api_port'] <= 65535:
            raise ValueError
        direct = build_opener(ProxyHandler({}))
        with direct.open(f'http://127.0.0.1:{state["api_port"]}/api/v1/salud', timeout=2) as response:
            if json.load(response) != {'estado': 'ok', 'modo': 'configurable'}:
                raise ValueError
        with direct.open(url, timeout=2) as response:
            if response.status != 200:
                raise ValueError
    except (OSError, ValueError, KeyError, TypeError):
        print('Báscula ya está abierta, pero aún está iniciando, cerrando o sin respuesta. Espera y vuelve a abrir el iniciador. No se inicia otra instancia.', flush=True)
        return 0
    print(f'Báscula ya está abierta: {url}. Se conserva la configuración activa.', flush=True)
    if open_browser:
        webbrowser.open(url)
    return 0


def choose_existing(args):
    if not args.abrir:
        return open_existing(False)
    print('Báscula ya está abierta. [C] Continuar y abrir la página (predeterminado), [R] Reiniciar, [S] Salir.', flush=True)
    while True:
        try:
            choice = input('Elige C, R o S [C]: ').strip().upper() or 'C'
        except EOFError:
            choice = 'C'
        except KeyboardInterrupt:
            print('Salida sin cambiar los servicios abiertos.')
            return 0
        if choice == 'C':
            return open_existing(True)
        if choice == 'S':
            return 0
        if choice == 'R':
            print('Se cerrarán las sesiones y la lectura de esta instalación para reiniciarla.', flush=True)
            command = ['reiniciar', '--abrir']
            if args.solo_pc:
                command.append('--solo-pc')
            if args.red_local is not None:
                command += ['--red-local', str(args.red_local)]
            if args.puerto_web is not None:
                command += ['--puerto-web', str(args.puerto_web)]
            return main(command)
        print('Opción inválida. Usa C para continuar, R para reiniciar o S para salir.')


def request_restart(timeout=45):
    deadline = time.monotonic() + timeout
    requested = False
    while True:
        try:
            with exclusive():
                return
        except AlreadyRunning:
            if not requested:
                (PRIVATE / 'detener').touch()
                print('Reinicio solicitado. Esperando el cierre normal de los servicios propios...', flush=True)
                requested = True
            if time.monotonic() >= deadline:
                raise RuntimeError('No terminó el cierre dentro de 45 segundos. No se fuerza ni se inicia otra instancia; revisa la ventana anterior. La solicitud de cierre sigue pendiente.') from None
            time.sleep(0.25)


def prepare(args):
    destination = Path(args.destino).resolve()
    if destination.exists() or destination == ROOT or ROOT in destination.parents:
        raise RuntimeError('El destino debe ser una carpeta nueva, fuera del desarrollo.')
    if not all((args.empresa, args.nombre, args.email, args.usuario)):
        raise RuntimeError('Indica empresa, nombre, email y usuario del acceso inicial.')
    php = ROOT / 'runtime/php'
    maria = ROOT / 'runtime/mariadb'
    for path in (php / 'php.exe', maria / 'bin/mariadb-install-db.exe', ROOT / 'runtime/python/python.exe'):
        if not path.is_file():
            raise RuntimeError('Faltan los binarios locales ya verificados.')
    if len(args.empresa) > 150 or len(args.nombre) > 150 or len(args.usuario) > 80 or len(args.email) > 254:
        raise RuntimeError('Los datos del acceso inicial superan los límites de la aplicación.')
    if any(any(ord(c) < 32 for c in value) for value in (args.empresa, args.nombre, args.usuario, args.email)):
        raise RuntimeError('Los datos iniciales contienen caracteres de control.')
    check = subprocess.run([str(php / 'php.exe'), '-r',
        'exit(filter_var(trim(stream_get_contents(STDIN)), FILTER_VALIDATE_EMAIL) ? 0 : 1);'],
        input=args.email, text=True, capture_output=True, timeout=10)
    if check.returncode:
        raise RuntimeError('Indica un correo válido.')
    print('Copiando aplicación y entornos propios al destino nuevo...', flush=True)
    # Una preparación interrumpida se conserva para diagnóstico; nunca se sobreescribe.
    destination.mkdir(parents=True)
    for directory in ('app', 'web'):
        shutil.copytree(ROOT / directory, destination / directory,
                        ignore=shutil.ignore_patterns('__pycache__', 'local.php', 'tyt-systems-logo.png'))
    (destination / 'scripts').mkdir()
    for name in ('instalacion_local.py', 'configurar_web.py', 'configurar_xampp.py', 'diagnosticar_xampp.php', 'publicar_fuentes_arranque.php', 'dbBascula.sql', 'generar_pdf.py', 'generar_excel.py', 'probar_lectura_serial.py', 'leer_configuracion_com.py', 'verificar_runtime_python.py', 'test_lector_sin_configurar.py', 'test_comparacion_serial.php'):
        shutil.copy2(ROOT / 'scripts' / name, destination / 'scripts' / name)
    shutil.copytree(ROOT / 'scripts/plantillas', destination / 'scripts/plantillas')
    shutil.copytree(php, destination / 'runtime/php', ignore=shutil.ignore_patterns('logs'))
    shutil.copytree(maria, destination / 'runtime/mariadb')
    shutil.copytree(ROOT / 'runtime/python', destination / 'runtime/python',
                    ignore=shutil.ignore_patterns('__pycache__'))
    private = destination / '.runtime/local'
    private.mkdir(parents=True)
    (private / 'tmp').mkdir()
    # PHP propio: no cargar rutas temporales ni configuración de XAMPP.
    ini = '\n'.join([
        'extension_dir="' + (destination / 'runtime/php/ext').as_posix() + '"',
        *['extension=' + ext for ext in ('pdo_mysql', 'mbstring', 'curl', 'openssl', 'fileinfo')],
        'date.timezone=America/Tegucigalpa', 'default_charset=UTF-8',
        'display_errors=Off', 'log_errors=On', 'opcache.enable=0', 'opcache.enable_cli=0',
        'error_log="' + (private / 'php-error.log').as_posix() + '"',
        'sys_temp_dir="' + (private / 'tmp').as_posix() + '"',
        'upload_tmp_dir="' + (private / 'tmp').as_posix() + '"',
        'session.save_path="' + (private / 'tmp').as_posix() + '"',
    ])
    (destination / 'runtime/php/php.ini').write_text(ini + '\n', encoding='utf-8')
    config = {'root': str(destination), 'ports': PORTS, 'ready': False,
              'root_password': secrets.token_urlsafe(32), 'web_password': secrets.token_urlsafe(32),
              'schema_sha256': hashlib.sha256((ROOT / 'scripts/dbBascula.sql').read_bytes()).hexdigest(),
              'migraciones_referencia': '001-006 (integradas en esquema nuevo; no ejecutadas)',
              'empresa': args.empresa, 'nombre': args.nombre, 'email': args.email, 'usuario': args.usuario}
    save_json(private / 'instalacion.json', config)
    command = [str(destination / 'runtime/python/python.exe'), str(destination / 'scripts/instalacion_local.py')]
    subprocess.run(command + ['inicializar'], check=True)
    from configurar_web import save_settings
    save_settings(private, {'interface': str(args.red_local) if args.red_local else '127.0.0.1',
                            'port': args.puerto_web if args.puerto_web is not None else PORTS['web']})
    for name in ('Iniciar Bascula.cmd', 'Iniciar Bascula solo PC.cmd', 'Detener Bascula.cmd', 'Configurar Bascula.cmd'):
        shutil.copy2(ROOT / name, destination / name)
    for name in ('LEEME.md', 'SEED.MD', 'PYTHON_PORTABLE.md', 'python-runtime.lock.json'):
        shutil.copy2(ROOT / name, destination / name)
    print('Instalación preparada en ' + str(destination))
    print('Abre Iniciar Bascula.cmd. Credenciales: .runtime/local/acceso-inicial.txt')


class Installation:
    def __init__(self):
        self.config = json.loads((PRIVATE / 'instalacion.json').read_text(encoding='utf-8'))
        if Path(self.config['root']).resolve() != ROOT:
            raise RuntimeError('La carpeta no coincide con la identidad de la instalación.')
        self.bin = ROOT / 'runtime/mariadb/bin'
        self.php = ROOT / 'runtime/php/php.exe'
        self.processes = []
        self.logs = []
        self.db_ready = False
        self.external_db = self.config.get('external_db', False)
        self.stamp = datetime.now().strftime('%Y%m%d-%H%M%S-%f')

    def sql(self, query):
        result = subprocess.run([str(self.bin / 'mysql.exe'), f'--defaults-file={PRIVATE / "client.cnf"}',
                                 '--default-character-set=utf8mb4', '--batch', '--raw', '--skip-column-names'],
                                input=query, encoding='utf-8', capture_output=True, timeout=20)
        if result.returncode:
            raise RuntimeError('Consulta de la instalación local fallida; se conserva la base.')
        return result.stdout

    def start(self, command, name, env=None):
        log = (PRIVATE / f'{name}-{self.stamp}.log').open('wb')
        self.logs.append(log)
        process = subprocess.Popen(command, cwd=ROOT, env=env, stdout=log, stderr=log,
                                   creationflags=subprocess.CREATE_NO_WINDOW)
        self.processes.append(process)

    def wait(self, callback):
        deadline = time.monotonic() + 35
        while True:
            if any(p.poll() is not None for p in self.processes):
                raise RuntimeError('Un servicio terminó; revisa los registros de .runtime/local.')
            try:
                return callback()
            except (OSError, RuntimeError, ValueError):
                if time.monotonic() > deadline:
                    raise RuntimeError('No respondió un servicio local; revisa sus registros.') from None
                time.sleep(0.2)

    def initialize(self):
        config = self.config
        schema = (ROOT / 'scripts/dbBascula.sql').read_text(encoding='utf-8')
        self.sql(schema)
        password = secrets.token_urlsafe(20)
        hashed = subprocess.run([str(self.php), '-c', str(ROOT / 'runtime/php/php.ini'), '-r',
            'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'],
            input=password, text=True, capture_output=True, check=True, timeout=10).stdout
        if not hashed.startswith('$2'):
            raise RuntimeError('No se pudo generar el hash del acceso inicial.')
        grants = '''
GRANT SELECT, INSERT, UPDATE (razon_social,nombre_comercial,rtn,direccion) ON dbBascula.t_empresas TO 'bascula_web'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE (nombre,usuario,email,rol,activo,clave_hash) ON dbBascula.t_usuarios TO 'bascula_web'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON dbBascula.t_Clie_prov TO 'bascula_web'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON dbBascula.t_productos TO 'bascula_web'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON dbBascula.t_producto_presentaciones TO 'bascula_web'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE (nombre,taralbs) ON dbBascula.t_taras TO 'bascula_web'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON dbBascula.t_configuracion_bascula TO 'bascula_web'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE (peso_bruto_lb,bruto_capturado_por,origen_bruto,tara_vehiculo_lb,tara_capturada_por,origen_tara,segunda_pesada_at,idempaque,cantidad_empaques,tara_unitaria_aplicada_lb,peso_final_lb,calculo_libras_enteras,observaciones_segunda,ajuste_humedad_pct,descuento_calidad_pct,base_descuentos_lb,descuento_humedad_lb,descuento_defectos_lb,solicitud_segunda,huella_segunda,estado,emitida_at,emitida_por) ON dbBascula.t_pesadas TO 'bascula_web'@'127.0.0.1';
'''
        self.sql("CREATE USER 'bascula_web'@'127.0.0.1' IDENTIFIED BY '" + config['web_password'] + "';" + grants)
        # Guardar antes de confirmar el alta para conservar el acceso si se interrumpe.
        (PRIVATE / 'acceso-inicial.txt').write_text(
            f"Usuario: {config['usuario']}\nContraseña inicial: {password}\nCambiar desde Mi cuenta al ingresar.\n",
            encoding='utf-8')
        self.sql(f'''USE dbBascula; START TRANSACTION;
INSERT INTO t_empresas (razon_social) VALUES ({literal(config['empresa'])});
SET @empresa=LAST_INSERT_ID();
INSERT INTO t_configuracion_bascula (empresa_id) VALUES (@empresa);
INSERT INTO t_usuarios (empresa_id,nombre,usuario,email,clave_hash,rol) VALUES
(@empresa,{literal(config['nombre'])},{literal(config['usuario'])},{literal(config['email'])},{literal(hashed)},'SUPER');
COMMIT;''')
        config['ready'] = True
        save_json(PRIVATE / 'instalacion.json', config)

    def backup(self):
        directory = PRIVATE / 'respaldos'
        directory.mkdir(exist_ok=True)
        partial = directory / f'dbBascula-{self.stamp}.sql.partial'
        with partial.open('wb') as stream:
            result = subprocess.run([str(self.bin / 'mysqldump.exe'), f'--defaults-file={PRIVATE / "client.cnf"}',
                '--single-transaction', '--routines', '--events', '--triggers', '--hex-blob',
                '--databases', 'dbBascula'], stdout=stream, stderr=subprocess.PIPE, timeout=120)
        if result.returncode or partial.stat().st_size < 100:
            raise RuntimeError('No se pudo respaldar; se bloquea el arranque web.')
        partial.rename(partial.with_suffix(''))

    def prepare_serial(self, env):
        try:
            publication = subprocess.run([str(self.php), '-c', str(ROOT / 'runtime/php/php.ini'),
                str(ROOT / 'scripts/publicar_fuentes_arranque.php')], cwd=ROOT, env=env,
                capture_output=True, timeout=10)
            if publication.returncode:
                raise RuntimeError('No se pudo cargar la configuración serial inicial')
        except (OSError, RuntimeError, subprocess.SubprocessError):
            env['BASCULA_SERIAL_AUTO'] = '0'
            print('AVISO: no se pudo preparar la escucha automática del COM. La web seguirá disponible; consulta Configuración de báscula y el estado de lectura.', flush=True)

    def run(self, action, open_browser, network=None, web_port=None):
        web_host = str(network.ip) if network else '127.0.0.1'
        ports = dict(self.config['ports'])
        if web_port is not None:
            if not 1 <= web_port <= 65535 or web_port in (ports['db'], ports['api']):
                raise RuntimeError('El puerto web debe ser válido y distinto de MariaDB/FastAPI.')
            ports['web'] = web_port
        web_url = f'http://{web_host}' + ('' if ports['web'] == 80 else f':{ports["web"]}')
        for service, port in ports.items():
            if service == 'db' and self.external_db:
                continue
            with socket.socket() as sock:
                address = web_host if service == 'web' else '127.0.0.1'
                try:
                    sock.bind((address, port))
                except OSError as error:
                    code = getattr(error, 'winerror', None) or error.errno
                    raise RuntimeError(f'No se puede iniciar {service} en {address}:{port} (error {code}). '
                                       'Comprueba que la instancia anterior terminó y que esa IP pertenece a este equipo.') from None
        data = PRIVATE / 'data'
        if action == 'inicializar':
            if self.external_db:
                raise RuntimeError('No se inicializa una base externa de XAMPP.')
            if self.config['ready'] or data.exists():
                raise RuntimeError('No se reinicializa una base existente, aunque esté incompleta.')
            result = subprocess.run([str(self.bin / 'mariadb-install-db.exe'), f'--datadir={data}',
                f'--password={self.config["root_password"]}', f'--port={ports["db"]}', '--silent'],
                capture_output=True, timeout=90)
            if result.returncode:
                raise RuntimeError('Falló la inicialización; conserva la carpeta para diagnóstico.')
        elif not self.config['ready'] or not (data / 'mysql').is_dir():
            raise RuntimeError('Instalación incompleta; no se crea otra base automáticamente.')
        (PRIVATE / 'client.cnf').write_text(
            f'[client]\nhost=127.0.0.1\nport={ports["db"]}\nuser=root\npassword="{self.config.get("external_db_admin_password", "") if self.external_db else self.config["root_password"]}"\n', encoding='utf-8')
        if not self.external_db:
            self.start([str(self.bin / 'mysqld.exe'), '--no-defaults', f'--basedir={self.bin.parent}',
                    f'--datadir={data}', '--bind-address=127.0.0.1', f'--port={ports["db"]}', '--console'], 'db')
        self.wait(lambda: self.sql('SELECT 1;'))
        self.db_ready = not self.external_db
        actual = self.sql('SELECT @@datadir, @@port;').strip().split('\t')
        expected_data = Path(self.config['external_db_datadir']) if self.external_db else data
        if Path(actual[0]).resolve() != expected_data.resolve() or int(actual[1]) != ports['db']:
            self.db_ready = False
            raise RuntimeError('La instancia no coincide con el directorio y puerto propios.')
        if action == 'inicializar':
            self.initialize()
            self.backup()
            return
        # Un cambio de esquema requiere procedimiento explícito; nunca migrar al arrancar.
        if hashlib.sha256((ROOT / 'scripts/dbBascula.sql').read_bytes()).hexdigest() != self.config['schema_sha256']:
            raise RuntimeError('El esquema cambió; se requiere revisar y respaldar antes de actualizar.')
        self.backup()
        env = {k: v for k, v in os.environ.items() if not k.startswith('BASCULA_')}
        env.update({'BASCULA_DB_HOST': '127.0.0.1', 'BASCULA_DB_PORT': str(ports['db']),
                    'BASCULA_DB_NAME': 'dbBascula', 'BASCULA_DB_USER': 'bascula_web',
                    'BASCULA_DB_PASSWORD': self.config['web_password'],
                    'BASCULA_API_URL': f'http://127.0.0.1:{ports["api"]}',
                    'BASCULA_TIMEZONE': 'America/Tegucigalpa', 'BASCULA_RUNTIME_DIR': str(PRIVATE / 'web')})
        if network:
            env['BASCULA_HTTP_RED_LOCAL'] = str(network.network)
        self.prepare_serial(env)
        self.start([str(ROOT / 'runtime/python/python.exe'), '-m', 'uvicorn', 'app.main:app',
                    '--host', '127.0.0.1', '--port', str(ports['api'])], 'api', env)
        self.start([str(self.php), '-c', str(ROOT / 'runtime/php/php.ini'), '-S', f'{web_host}:{ports["web"]}', '-t', str(ROOT / 'web/public'),
                    str(ROOT / 'web/public/router.php')], 'web', env)
        direct = build_opener(ProxyHandler({}))
        def healthy():
            with direct.open(f'http://127.0.0.1:{ports["api"]}/api/v1/salud', timeout=2) as response:
                if json.load(response) != {'estado': 'ok', 'modo': 'configurable'}:
                    raise RuntimeError('La API no confirma las fuentes configurables.')
            with direct.open(f'{web_url}/login.php', timeout=2) as response:
                if response.status != 200:
                    raise RuntimeError('Login no disponible.')
        self.wait(healthy)
        if action == 'iniciar':
            save_json(PRIVATE / 'servidor-listo.json', {'root': str(ROOT), 'url': f'{web_url}/login.php', 'api_port': ports['api']})
        print(f'LISTO: {web_url}/login.php — FUENTE CONFIGURABLE', flush=True)
        print('Datos permanentes. Para cerrar: Detener Bascula.cmd o Ctrl+C en esta ventana.', flush=True)
        if action == 'verificar':
            return
        if open_browser:
            webbrowser.open(f'{web_url}/login.php')
        stop = PRIVATE / 'detener'
        while not stop.exists():
            if any(p.poll() is not None for p in self.processes):
                raise RuntimeError('Un servicio se detuvo; se cerrarán los demás servicios propios.')
            time.sleep(0.5)

    def close(self):
        # Cerrar primero HTTP, luego esperar la salida real de MariaDB.
        close_errors = []
        for process in reversed(self.processes if self.external_db else self.processes[1:]):
            try:
                terminate_tree(process)
            except (OSError, subprocess.SubprocessError):
                close_errors.append(process.pid)
        try:
            if self.db_ready:
                result = subprocess.run([str(self.bin / 'mysqladmin.exe'),
                    f'--defaults-file={PRIVATE / "client.cnf"}', 'shutdown'],
                    capture_output=True, timeout=30)
                if result.returncode:
                    raise RuntimeError('MariaDB no confirmó el cierre; revisar antes de reiniciar.')
                self.processes[0].wait(timeout=35)
            elif not self.external_db and self.processes and self.processes[0].poll() is None:
                self.processes[0].terminate()
                self.processes[0].wait(timeout=15)
        finally:
            for log in self.logs:
                log.close()
        if close_errors:
            raise RuntimeError('Un servicio propio no confirmó el cierre; revisar sus procesos antes de reiniciar.')


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('accion', choices=['preparar', 'inicializar', 'iniciar', 'verificar', 'detener', 'reiniciar'])
    parser.add_argument('--destino', default='K:/TYTOneBasculaLocal')
    for field in ('empresa', 'nombre', 'email', 'usuario'):
        parser.add_argument('--' + field)
    parser.add_argument('--abrir', action='store_true')
    parser.add_argument('--solo-pc', action='store_true', help='Fuerza loopback y conserva el puerto web configurado')
    parser.add_argument('--red-local', type=lan_interface, help='IPv4/prefijo: habilita solo la web HTTP en esa subred privada')
    parser.add_argument('--puerto-web', type=int, help='Puerto HTTP opcional; 80 evita escribirlo en la dirección')
    args = parser.parse_args(argv)
    try:
        if args.accion == 'preparar':
            prepare(args)
            return 0
        if not (PRIVATE / 'instalacion.json').is_file():
            raise RuntimeError('Esta carpeta no es una instalación preparada.')
        if args.accion == 'detener':
            (PRIVATE / 'detener').touch()
            print('Cierre solicitado. Espera a que termine la ventana de Iniciar Báscula.')
            return 0
        if args.accion == 'reiniciar':
            request_restart()
            args.accion = 'iniciar'
        with exclusive():
            (PRIVATE / 'servidor-listo.json').unlink(missing_ok=True)
            if args.accion in ('iniciar', 'verificar'):
                from configurar_web import read_settings, validate
                if args.solo_pc and args.red_local:
                    raise RuntimeError('No combines --solo-pc con --red-local.')
                ports = json.loads((PRIVATE / 'instalacion.json').read_text(encoding='utf-8'))['ports']
                if args.red_local is not None or args.puerto_web is not None:
                    args.red_local, args.puerto_web = validate(
                        {'interface': str(args.red_local) if args.red_local else '127.0.0.1',
                         'port': args.puerto_web if args.puerto_web is not None else ports['web']}, ports)
                else:
                    args.red_local, args.puerto_web = read_settings(PRIVATE, ports, args.solo_pc)
            (PRIVATE / 'detener').unlink(missing_ok=True)
            installation = Installation()
            try:
                installation.run(args.accion, args.abrir, args.red_local, args.puerto_web)
            finally:
                try:
                    (PRIVATE / 'servidor-listo.json').unlink(missing_ok=True)
                finally:
                    installation.close()
        return 0
    except AlreadyRunning:
        if args.accion == 'iniciar':
            return choose_existing(args)
        print('La instalación ya está abierta; espera a que cierre antes de verificar o inicializar.', file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print('Servicios propios detenidos. Datos conservados.')
        return 0
    except (OSError, RuntimeError, subprocess.SubprocessError) as error:
        # No mostrar consultas ni configuraciones que contengan secretos.
        print(f'No se pudo completar la operación ({type(error).__name__}).', file=sys.stderr)
        if isinstance(error, RuntimeError):
            print(str(error), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
