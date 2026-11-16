"""Configuración privada de escucha HTTP; sin cambios de red ni servicios."""
import argparse
import ipaddress
import json
from pathlib import Path
import socket
import subprocess
import os
import re
import secrets
import time
import webbrowser
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs
import html

ROOT = Path(__file__).resolve().parents[1]
PRIVATE = ROOT / '.runtime/local'
FILENAME = 'web-listen.json'


def interfaces():
    command = '[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new(); Get-NetIPAddress -AddressFamily IPv4 -AddressState Preferred -ErrorAction Stop | Select-Object IPAddress,PrefixLength,InterfaceAlias | ConvertTo-Json -Compress'
    result = subprocess.run([str(Path(os.environ.get('SystemRoot', 'C:/Windows')) / 'System32/WindowsPowerShell/v1.0/powershell.exe'), '-NoProfile', '-NonInteractive', '-Command', command], capture_output=True, timeout=20)
    if result.returncode:
        raise RuntimeError('No se pudieron consultar las interfaces IPv4 de Windows.')
    rows = json.loads(result.stdout.decode('utf-8-sig') or '[]')
    if isinstance(rows, dict):
        rows = [rows]
    found = {}
    for row in rows:
        cidr = f'{row["IPAddress"]}/{row["PrefixLength"]}'
        try:
            network(cidr)
        except RuntimeError:
            continue
        found[cidr] = str(row['InterfaceAlias'])
    return found


def network(cidr):
    try:
        value = ipaddress.IPv4Interface(cidr)
        ranges = ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')
        if '/' not in cidr or not any(value.network.subnet_of(ipaddress.IPv4Network(n)) for n in ranges):
            raise ValueError
        if value.ip in (value.network.network_address, value.network.broadcast_address):
            raise ValueError
        return value
    except (ValueError, TypeError):
        raise RuntimeError('Selecciona una IPv4 privada válida con su subred real.') from None


def validate(value, ports, available=None, check_port=False):
    if not isinstance(value, dict) or set(value) != {'interface', 'port'}:
        raise RuntimeError('Configuración web inválida; abre Configurar Bascula.cmd.')
    cidr, port = value['interface'], value['port']
    if not isinstance(cidr, str) or type(port) is not int or not 1 <= port <= 65535:
        raise RuntimeError('El puerto web debe ser un entero entre 1 y 65535.')
    if port in (ports['db'], ports['api']):
        raise RuntimeError('El puerto web debe ser distinto de MariaDB y FastAPI.')
    selected = None if cidr == '127.0.0.1' else network(cidr)
    if selected is not None and cidr not in (interfaces() if available is None else available):
        raise RuntimeError('La IP/subred ya no está disponible en este equipo. Vuelve a seleccionarla.')
    if check_port:
        with socket.socket() as sock:
            if hasattr(socket, 'SO_EXCLUSIVEADDRUSE'):
                sock.setsockopt(socket.SOL_SOCKET, socket.SO_EXCLUSIVEADDRUSE, 1)
            try:
                sock.bind((str(selected.ip) if selected else cidr, port))
            except OSError:
                raise RuntimeError('No se puede usar esa IP y puerto: está ocupado o Windows lo restringe. Elige otro puerto; no se detendrá ningún proceso.') from None
    return selected, port


def read_settings(private, ports, local=False):
    path = private / FILENAME
    if not path.exists():
        if local:
            return None, ports['web']
        raise RuntimeError('Falta configurar la web. Abre Configurar Bascula.cmd para conservar o elegir IP y puerto.')
    try:
        value = json.loads(path.read_text(encoding='utf-8'))
    except (ValueError, OSError):
        raise RuntimeError('No se pudo leer la configuración web. Abre Configurar Bascula.cmd.') from None
    if local:
        value = dict(value, interface='127.0.0.1') if isinstance(value, dict) else value
    return validate(value, ports)


def legacy(root, ports, launcher=None):
    # Solo el lanzador conocido; no se ejecuta ni se inspeccionan archivos privados.
    text = (launcher or (root / 'Iniciar Bascula.cmd')).read_text(encoding='utf-8-sig')
    cidr = re.search(r'--red-local\s+([\d./]+)', text)
    port = re.search(r'--puerto-web\s+(\d+)', text)
    return {'interface': cidr[1] if cidr else '127.0.0.1', 'port': int(port[1]) if port else ports['web']}


def save_settings(private, value):
    path = private / FILENAME
    # Copia anterior única por guardado; reemplazo atómico en el mismo volumen.
    if path.exists():
        backup = private / (FILENAME + '.' + time.strftime('%Y%m%d-%H%M%S') + '-' + secrets.token_hex(4) + '.bak')
        backup.write_bytes(path.read_bytes())
    temporary = private / (FILENAME + '.' + secrets.token_hex(8) + '.tmp')
    try:
        temporary.write_text(json.dumps(value, indent=2) + '\n', encoding='utf-8')
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)


def save_installation_folder(private, folder, actual_root):
    """Reconoce la carpeta actual tras un traslado manual; no redirige datos."""
    candidate = Path(folder.strip())
    if not candidate.is_absolute() or not candidate.is_dir():
        raise RuntimeError('Indica una carpeta de instalación existente con ruta absoluta.')
    if not candidate.samefile(actual_root):
        raise RuntimeError('La ruta debe corresponder a esta instalación. Para usar otra carpeta, mueve o copia la instalación con la app cerrada y abre allí Configurar Bascula.cmd. No se mueven archivos desde este formulario.')
    for relative in ('scripts/instalacion_local.py', 'runtime/python/python.exe', 'web/public/login.php'):
        if not (candidate / relative).is_file():
            raise RuntimeError('La carpeta no contiene una instalación completa de Báscula.')
    import msvcrt
    with (private / 'ejecucion.lock').open('a+b') as lock:
        lock.seek(0)
        if os.fstat(lock.fileno()).st_size == 0:
            lock.write(b'0')
            lock.flush()
        lock.seek(0)
        try:
            msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
        except OSError:
            raise RuntimeError('Cierra la app con su lanzador antes de cambiar la carpeta de instalación.') from None
        try:
            path = private / 'instalacion.json'
            config = json.loads(path.read_text(encoding='utf-8'))
            config['root'] = str(candidate.resolve())
            if json.loads(path.read_text(encoding='utf-8')).get('root') == config['root']:
                return
            backup = private / ('instalacion.json.' + time.strftime('%Y%m%d-%H%M%S') + '-' + secrets.token_hex(4) + '.bak')
            backup.write_bytes(path.read_bytes())
            temporary = private / ('instalacion.' + secrets.token_hex(8) + '.tmp')
            try:
                temporary.write_text(json.dumps(config, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
                temporary.replace(path)
            finally:
                temporary.unlink(missing_ok=True)
        finally:
            lock.seek(0)
            msvcrt.locking(lock.fileno(), msvcrt.LK_UNLCK, 1)


def serve(private, ports, initial, available, open_browser=True, lifetime=300):
    token = secrets.token_urlsafe(32)
    actual_root = private.parent.parent.resolve()
    installation_path = private / 'instalacion.json'
    installation_config = json.loads(installation_path.read_text(encoding='utf-8')) if installation_path.exists() else {}
    registered_root = installation_config.get('root', '')
    xampp_folder = installation_config.get('xampp_folder') or (str(Path(installation_config['external_db_datadir']).parent.parent) if installation_config.get('external_db_datadir') else 'C:/xampp')
    from configurar_xampp import suggest_xampp
    xampp_suggestion = suggest_xampp(xampp_folder)
    done = False
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def reply(self, status, body):
            data = body.encode('utf-8')
            self.send_response(status)
            for key, value in [('Content-Type', 'text/html; charset=utf-8'), ('Content-Length', str(len(data))), ('Cache-Control', 'no-store'), ('X-Frame-Options', 'DENY'), ('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'")]:
                self.send_header(key, value)
            self.end_headers()
            self.wfile.write(data)

        def allowed(self):
            return self.headers.get('Host') == authority and self.path == '/' + token and self.headers.get('Origin') in (None, origin) and self.headers.get('Sec-Fetch-Site') in (None, 'none', 'same-origin')

        def do_GET(self):
            if not self.allowed():
                return self.reply(403, 'Solicitud rechazada.')
            options = {'127.0.0.1': 'Solo esta PC — 127.0.0.1'}
            options.update({key: f'Red local — {key} ({name})' for key, name in available.items()})
            if initial['interface'] not in options:
                options[initial['interface']] = initial['interface'] + ' — anterior, no disponible: selecciona otra'
            choices = ''.join(f'<option value="{html.escape(k, quote=True)}" {"selected" if k == initial["interface"] else ""}>{html.escape(v)}</option>' for k, v in options.items())
            self.reply(200, f'''<!doctype html><html lang="es"><meta charset="utf-8"><title>Configurar Báscula</title>
<style>body{{font:18px system-ui;max-width:720px;margin:3em auto;padding:1em}}label,output{{display:block;margin:1em 0}}button,input,select{{font:inherit;padding:.4em;max-width:100%}}</style>
<h1>Configurar Báscula</h1><p>Configura la carpeta de instalación y dónde escucha la web. No modifica Windows, firewall, base de datos ni COM.</p>
<h2>Carpeta de instalación</h2><p>Carpeta detectada: <strong>{html.escape(str(actual_root))}</strong><br>Carpeta registrada: {html.escape(str(registered_root))}</p>
<form method="post"><input type="hidden" name="token" value="{token}"><label>Carpeta de esta instalación <input name="folder" style="width:100%" required value="{html.escape(str(actual_root), quote=True)}"></label>
<p>Puedes corregir la ruta registrada después de un traslado manual. Debe ser la carpeta desde donde abriste este configurador. Para otra ubicación, cierra la app, traslada la instalación y abre el configurador desde esa carpeta. No mueve archivos ni cambia rutas de la base. La app debe estar cerrada para guardar esta ruta.</p><button name="action" value="save_root">Guardar carpeta y cerrar</button></form>
<h2>Base de datos XAMPP</h2><form method="post"><input type="hidden" name="token" value="{token}">
<label>Carpeta de XAMPP <input id="xampp-folder" name="xampp_folder" style="width:100%" required value="{html.escape(xampp_folder, quote=True)}"></label>
<label>Puerto MySQL <input id="db-port" name="db_port" type="number" min="1" max="65535" required value="{ports['db']}"></label>
<p>Sugerencia de XAMPP: {html.escape(xampp_suggestion['folder'])}, MySQL {xampp_suggestion['port']} ({html.escape(xampp_suggestion['source'])}). Valores habituales de una instalación nueva: C:\\xampp y 3306.</p>
<button type="button" id="use-xampp-suggestion" data-folder="{html.escape(xampp_suggestion['folder'], quote=True)}" data-port="{xampp_suggestion['port']}">Usar sugerencia de XAMPP</button>
<p>Conexión exclusivamente a 127.0.0.1, base dbBascula. Indica el puerto que ya usa XAMPP. Esto no cambia my.ini, no mueve bases ni inicia/detiene MySQL o Apache. Probar consulta solo estado, acceso y estructura, sin datos de pesadas.</p>
<label><input type="checkbox" name="confirm_db" value="1"> Confirmo usar la base existente de este XAMPP; no se copiarán datos desde otra instalación.</label>
<button name="action" value="test_db">Probar conexión</button> <button name="action" value="save_db">Guardar conexión y cerrar</button>
<p>Para guardar, la app debe estar cerrada y deben coincidir la carpeta de datos, el acceso y la estructura requerida. Si falta acceso o estructura, el diagnóstico lo indicará; no se repararán automáticamente.</p></form>
<h2>Dirección web</h2><p>Las opciones de red se detectan automáticamente en este equipo. Para acceso por red se recomienda una IP estática o una reserva DHCP administrada por el responsable de la red; así la dirección no cambiará. Este formulario no fija ni modifica la IP de Windows.</p>
<form method="post"><input type="hidden" name="token" value="{token}"><label>Acceso <select id="ip" name="interface">{choices}</select></label>
<label>Puerto web <input id="port" name="port" type="number" min="1" max="65535" required value="{initial['port']}"></label>
<output id="url"></output><p>Si la aplicación está abierta, el cambio se aplicará al reiniciarla. No se reiniciará automáticamente. Si el puerto está ocupado, cierra la app por su lanzador o elige otro puerto.</p>
<button name="action" value="save">Guardar y cerrar</button> <button name="action" value="cancel" formnovalidate>Cancelar y cerrar</button></form>
<p>Esta ventana vence en cinco minutos. Cierra también esta pestaña al terminar; cerrar solo la pestaña deja el configurador hasta su vencimiento.</p>
<script>const suggestion=document.getElementById('use-xampp-suggestion');suggestion.onclick=()=>{{document.getElementById('xampp-folder').value=suggestion.dataset.folder;document.getElementById('db-port').value=suggestion.dataset.port;}};const ip=document.getElementById('ip'),port=document.getElementById('port');function update(){{document.getElementById('url').textContent='Dirección: http://'+ip.value.split('/')[0]+(port.value==='80'?'':':'+port.value)+'/login.php';}}ip.onchange=port.oninput=update;update();</script></html>''')

        def do_POST(self):
            nonlocal done
            if not self.allowed() or self.headers.get('Origin') != origin:
                # Consumir cuerpos pequeños antes de cerrar evita RST de Windows
                # que puede ocultar la respuesta 403; nunca leer sin límite.
                try:
                    rejected_size = int(self.headers.get('Content-Length', '0'))
                    if 0 < rejected_size <= 4096:
                        self.rfile.read(rejected_size)
                except (ValueError, OSError):
                    pass
                return self.reply(403, 'Solicitud rechazada.')
            try:
                size = int(self.headers.get('Content-Length', '0'))
                if not 0 < size <= 4096 or self.headers.get('Content-Type') != 'application/x-www-form-urlencoded':
                    return self.reply(400, 'Solicitud inválida.')
                fields = parse_qs(self.rfile.read(size).decode('utf-8'), strict_parsing=True)
                if fields.get('token') != [token] or any(len(v) != 1 for v in fields.values()):
                    return self.reply(403, 'Solicitud rechazada.')
                if fields.get('action') == ['cancel']:
                    done = True
                    return self.reply(200, 'Cancelado. Puedes cerrar esta pestaña.')
                if fields.get('action') in (['test_db'], ['save_db']):
                    from configurar_xampp import diagnose_xampp, describe_report, save_xampp
                    try:
                        db_port = int(fields.get('db_port', [''])[0])
                    except ValueError:
                        raise RuntimeError('Indica un puerto MySQL entero entre 1 y 65535.') from None
                    folder = fields.get('xampp_folder', [''])[0]
                    if fields['action'] == ['save_db']:
                        if fields.get('confirm_db') != ['1']:
                            raise RuntimeError('Confirma que deseas usar la base existente de ese XAMPP; no se copiarán datos.')
                        report = save_xampp(private, folder, db_port)
                        done = True
                        return self.reply(200, 'Conexión guardada con respaldo. Abre la app para aplicar el ajuste. XAMPP y sus datos no se modificaron.')
                    _, _, report = diagnose_xampp(private, folder, db_port)
                    return self.reply(200, '<!doctype html><html lang="es"><meta charset="utf-8"><title>Diagnóstico XAMPP</title><h1>Diagnóstico XAMPP</h1><pre style="white-space:pre-wrap">' + html.escape(describe_report(report)) + '</pre><p><a href="/' + token + '">Volver a configuración</a></p></html>')
                if fields.get('action') == ['save_root']:
                    save_installation_folder(private, fields.get('folder', [''])[0], actual_root)
                    done = True
                    return self.reply(200, 'Carpeta guardada. Abre el lanzador desde esa carpeta. No se movieron archivos ni se iniciaron servicios. Puedes cerrar esta pestaña.')
                if fields.get('action') != ['save']:
                    return self.reply(400, 'Acción inválida.')
                try:
                    port = int(fields.get('port', [''])[0])
                except ValueError:
                    raise RuntimeError('Indica un puerto entero entre 1 y 65535.') from None
                value = {'interface': fields.get('interface', [''])[0], 'port': port}
                validate(value, ports, check_port=True)
                save_settings(private, value)
                done = True
                self.reply(200, 'Configuración guardada. Se aplicará al próximo inicio de la app. Puedes cerrar esta pestaña.')
            except (RuntimeError, ValueError, OSError) as error:
                message = str(error) if isinstance(error, RuntimeError) else 'No se pudo guardar. Comprueba los valores y permisos de la carpeta.'
                self.reply(400, html.escape(message) + ' Vuelve atrás para corregir o cancelar.')

    class LocalServer(HTTPServer):
        def get_request(self):
            connection, address = super().get_request()
            connection.settimeout(2)
            return connection, address

    with LocalServer(('127.0.0.1', 0), Handler) as server:
        server.timeout = 0.25
        authority = f'127.0.0.1:{server.server_port}'
        origin = 'http://' + authority
        url = origin + '/' + token
        print('Configurador temporal: ' + url, flush=True)
        if open_browser:
            webbrowser.open(url)
        deadline = time.monotonic() + lifetime
        while not done and time.monotonic() < deadline:
            server.handle_request()
    print('Configurador cerrado.', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--importar-lanzador', type=Path, help='Copia respaldada del CMD anterior; solo propone sus ajustes')
    args = parser.parse_args()
    try:
        path = PRIVATE / 'instalacion.json'
        if not path.is_file():
            raise RuntimeError('Esta carpeta no es una instalación preparada. No ejecutes preparar sobre una instalación con datos.')
        ports = json.loads(path.read_text(encoding='utf-8'))['ports']
        initial = legacy(ROOT, ports, args.importar_lanzador)
        if (PRIVATE / FILENAME).exists():
            try:
                candidate = json.loads((PRIVATE / FILENAME).read_text(encoding='utf-8'))
                validate(candidate, ports, available={candidate.get('interface', ''): ''})
                initial = candidate
            except (RuntimeError, ValueError, AttributeError):
                print('Configuración anterior inválida; selecciona nuevamente IP y puerto.')
        try:
            available = interfaces()
        except (RuntimeError, OSError, ValueError, subprocess.SubprocessError):
            available = {}
            print('No se pudieron detectar interfaces; puedes corregir la carpeta o seleccionar Solo esta PC.')
        if not (PRIVATE / FILENAME).exists() and not args.importar_lanzador and initial['interface'] == '127.0.0.1' and len(available) == 1:
            initial['interface'] = next(iter(available))
            print('Se propone la única IPv4 privada detectada; confirma el modo antes de guardar.')
        serve(PRIVATE, ports, initial, available)
        return 0
    except (RuntimeError, OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
        print(str(error) if isinstance(error, RuntimeError) else 'No se pudo abrir el configurador. Comprueba permisos y configuración de instalación.')
        return 1
    except KeyboardInterrupt:
        print('Configurador cancelado.')
        return 0


if __name__ == '__main__':
    raise SystemExit(main())
