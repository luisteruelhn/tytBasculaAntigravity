"""Pruebas aisladas: sin base, COM ni servicios de la aplicación."""
import contextlib
import io
import json
from pathlib import Path
import socket
import tempfile
import threading
import time
import unittest
from unittest.mock import patch
from urllib.request import Request, build_opener, ProxyHandler
from urllib.error import HTTPError
from urllib.parse import urlencode, urlsplit
import configurar_web as c
import instalacion_local as supervisor

PORTS = {'db': 3306, 'api': 8018, 'web': 8088}
AVAILABLE = {'192.168.4.10/24': 'Ethernet'}


class ConfigurationTests(unittest.TestCase):
    def test_validation(self):
        for cidr in ('0.0.0.0', '8.8.8.8/24', '192.168.4.0/24', '192.168.4.255/24', '192.168.4.10/8', '::1'):
            with self.subTest(cidr=cidr), self.assertRaises(RuntimeError):
                c.validate({'interface': cidr, 'port': 8088}, PORTS, AVAILABLE)
        for port in (0, 65536, 3306, 8018, True, '8088'):
            with self.subTest(port=port), self.assertRaises(RuntimeError):
                c.validate({'interface': '127.0.0.1', 'port': port}, PORTS, AVAILABLE)
        with self.assertRaises(RuntimeError):
            c.validate({'interface': '192.168.4.10/25', 'port': 8088}, PORTS, AVAILABLE)
        network, port = c.validate({'interface': '192.168.4.10/24', 'port': 80}, PORTS, AVAILABLE)
        self.assertEqual(str(network.network), '192.168.4.0/24')
        self.assertEqual(port, 80)

    def test_occupied_port_is_preserved(self):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            listener.listen()
            port = listener.getsockname()[1]
            with self.assertRaisesRegex(RuntimeError, 'ocupado'):
                c.validate({'interface': '127.0.0.1', 'port': port}, PORTS, check_port=True)
            with socket.create_connection(('127.0.0.1', port), timeout=1):
                pass

    def test_settings_and_migration(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            with self.assertRaisesRegex(RuntimeError, 'Falta configurar'):
                c.read_settings(root, PORTS)
            self.assertEqual(c.read_settings(root, PORTS, local=True), (None, 8088))
            old = root / 'anterior.cmd'
            old.write_text('iniciar --red-local 192.168.4.10/24 --puerto-web 8099')
            value = c.legacy(root, PORTS, old)
            c.save_settings(root, value)
            self.assertEqual(c.read_settings(root, PORTS, local=True), (None, 8099))
            with patch.object(c, 'interfaces', return_value=AVAILABLE):
                self.assertEqual(str(c.read_settings(root, PORTS)[0]), '192.168.4.10/24')
            c.save_settings(root, {'interface': '127.0.0.1', 'port': 80})
            self.assertEqual(json.loads(next(root.glob('*.bak')).read_text()), value)
            self.assertEqual(c.read_settings(root, PORTS), (None, 80))
            (root / c.FILENAME).write_text('{broken')
            with self.assertRaises(RuntimeError):
                c.read_settings(root, PORTS)

    def test_launcher_configuration_without_services(self):
        with tempfile.TemporaryDirectory() as folder:
            private = Path(folder)
            (private / 'instalacion.json').write_text(json.dumps({'ports': PORTS}))
            c.save_settings(private, {'interface': '192.168.4.10/24', 'port': 8099})
            for local in (False, True):
                argv = ['instalacion_local.py', 'iniciar', '--abrir'] + (['--solo-pc'] if local else [])
                with patch.object(supervisor, 'PRIVATE', private), patch.object(supervisor.sys, 'argv', argv), patch.object(supervisor, 'Installation') as installation, patch.object(c, 'interfaces', return_value=AVAILABLE):
                    self.assertEqual(supervisor.main(), 0)
                    args = installation.return_value.run.call_args.args
                    self.assertEqual(args[:2], ('iniciar', True))
                    self.assertEqual(args[2], None if local else c.network('192.168.4.10/24'))
                    self.assertEqual(args[3], 8099)
                    installation.return_value.close.assert_called_once()
            (private / c.FILENAME).unlink()
            with patch.object(supervisor, 'PRIVATE', private), patch.object(supervisor.sys, 'argv', ['instalacion_local.py', 'iniciar']), patch.object(supervisor, 'Installation') as installation, contextlib.redirect_stderr(io.StringIO()):
                self.assertEqual(supervisor.main(), 1)
                installation.assert_not_called()

    def test_interface_detection_filters_and_keeps_prefix(self):
        rows = [{'IPAddress': '192.168.4.10', 'PrefixLength': 24, 'InterfaceAlias': 'Ethernet'},
                {'IPAddress': '8.8.8.8', 'PrefixLength': 24, 'InterfaceAlias': 'Public'},
                {'IPAddress': '169.254.1.1', 'PrefixLength': 16, 'InterfaceAlias': 'APIPA'}]
        result = type('Result', (), {'returncode': 0, 'stdout': json.dumps(rows).encode()})()
        with patch.object(c.subprocess, 'run', return_value=result):
            self.assertEqual(c.interfaces(), AVAILABLE)

    def test_installation_folder_validation_and_backup(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            private = root / '.runtime/local'
            private.mkdir(parents=True)
            original = {'root': 'C:/ruta-anterior', 'ports': PORTS, 'untouched': 'conservar'}
            config = private / 'instalacion.json'
            config.write_text(json.dumps(original))
            for invalid in ('relativa', str(root / 'ausente'), str(root.parent)):
                with self.assertRaises(RuntimeError):
                    c.save_installation_folder(private, invalid, root)
                self.assertEqual(json.loads(config.read_text()), original)
            with self.assertRaises(RuntimeError):
                c.save_installation_folder(private, str(root), root)
            for relative in ('scripts/instalacion_local.py', 'runtime/python/python.exe', 'web/public/login.php'):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.touch()
            import msvcrt
            with (private / 'ejecucion.lock').open('w+b') as lock:
                lock.write(b'0'); lock.flush(); lock.seek(0)
                msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
                try:
                    with self.assertRaisesRegex(RuntimeError, 'Cierra la app'):
                        c.save_installation_folder(private, str(root), root)
                finally:
                    lock.seek(0)
                    msvcrt.locking(lock.fileno(), msvcrt.LK_UNLCK, 1)
            self.assertEqual(json.loads(config.read_text()), original)
            c.save_installation_folder(private, str(root), root)
            expected = dict(original, root=str(root.resolve()))
            self.assertEqual(json.loads(config.read_text()), expected)
            self.assertEqual(json.loads(next(private.glob('instalacion.json.*.bak')).read_text()), original)
            c.save_installation_folder(private, str(root), root)
            self.assertEqual(len(list(private.glob('*.bak'))), 1)

    def run_http(self, action):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            private = root / '.runtime/local'
            private.mkdir(parents=True)
            original = {'root': 'C:/anterior', 'ports': PORTS, 'untouched': 'conservar'}
            (private / 'instalacion.json').write_text(json.dumps(original))
            for relative in ('scripts/instalacion_local.py', 'runtime/python/python.exe', 'web/public/login.php'):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.touch()
            output = io.StringIO()
            with patch.object(c, 'interfaces', return_value=AVAILABLE), contextlib.redirect_stdout(output):
                thread = threading.Thread(target=c.serve, args=(private, PORTS, {'interface': '127.0.0.1', 'port': 8088}, AVAILABLE), kwargs={'open_browser': False, 'lifetime': 3 if action == 'timeout' else 15})
                thread.start()
                try:
                    deadline = time.monotonic() + 2
                    while 'http://' not in output.getvalue() and time.monotonic() < deadline:
                        time.sleep(.01)
                    url = output.getvalue().split('Configurador temporal: ')[1].splitlines()[0]
                    parts = urlsplit(url)
                    origin = f'{parts.scheme}://{parts.netloc}'
                    opener = build_opener(ProxyHandler({}))
                    with opener.open(url, timeout=2) as response:
                        body = response.read().decode()
                        self.assertIn('IP estática', body)
                        self.assertIn('Guardar carpeta y cerrar', body)
                        self.assertIn('C:/anterior', body)
                        self.assertIn('192.168.4.10/24', body)
                        self.assertIn('127.0.0.1', body)
                    data = {'token': parts.path[1:], 'interface': '127.0.0.1', 'port': '8099', 'action': action, 'folder': str(root)}
                    for headers, changed in [({'Origin': 'https://externo.invalid'}, {}), ({'Origin': origin, 'Host': 'externo.invalid'}, {}), ({'Origin': origin}, {'token': 'incorrecto'}), ({}, {})]:
                        request = Request(url, data=urlencode(dict(data, **changed)).encode(), headers=headers)
                        with self.assertRaises(HTTPError) as error:
                            opener.open(request, timeout=2)
                        self.assertEqual(error.exception.code, 403)
                        error.exception.close()
                        self.assertFalse((private / c.FILENAME).exists())
                    if action != 'timeout':
                        with opener.open(Request(url, data=urlencode(data).encode(), headers={'Origin': origin}), timeout=2) as response:
                            self.assertEqual(response.status, 200)
                finally:
                    thread.join(17)
                self.assertFalse(thread.is_alive())
                self.assertEqual((private / c.FILENAME).exists(), action == 'save')
                expected = dict(original, root=str(root.resolve())) if action == 'save_root' else original
                self.assertEqual(json.loads((private / 'instalacion.json').read_text()), expected)
                with socket.socket() as sock:
                    self.assertNotEqual(sock.connect_ex(('127.0.0.1', parts.port)), 0)

    def test_http_save(self):
        self.run_http('save')

    def test_http_folder_save(self):
        self.run_http('save_root')

    def test_http_cancel(self):
        self.run_http('cancel')

    def test_http_timeout(self):
        self.run_http('timeout')


if __name__ == '__main__':
    unittest.main()
