"""Arranque serial con lector simulado: nunca abre un COM real."""
import asyncio
import contextlib
import io
import json
import os
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest.mock import patch, Mock
from app.services.fuente import Fuentes, FuenteSerial
from app.main import create_app
from app.api.routes.lecturas import consultar_lectura
from fastapi import HTTPException
import instalacion_local as supervisor

CONFIG = dict(modo='SERIAL', parametros_confirmados=1, puerto_com='COM9', baudios=9600,
              bits_datos=8, paridad='N', bits_parada='1.0', control_flujo='NINGUNO',
              protocolo='LP7510_PC', unidad_indicador='lb', timeout_lectura_ms=1000)


def until(callback):
    deadline = time.monotonic() + 3
    while not callback():
        if time.monotonic() > deadline:
            raise AssertionError('No llegó el evento esperado')
        time.sleep(.01)


class AutoSerialTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.runtime = Path(self.temp.name)
        (self.runtime / 'fuentes').mkdir()
        self.path = self.runtime / 'fuentes/1.json'
        self.path.write_text(json.dumps(CONFIG))
        self.env = patch.dict(os.environ, BASCULA_RUNTIME_DIR=str(self.runtime), BASCULA_SERIAL_AUTO='1')
        self.env.start()

    def tearDown(self):
        self.env.stop()
        self.temp.cleanup()

    def test_start_listens_without_page_and_stays_until_close(self):
        entered = threading.Event()
        closed = threading.Event()
        def reader(settings, output, should_stop):
            self.assertEqual(settings['bits_parada'], '1')
            self.assertTrue(settings['continuo'])
            output(True, 'esperando')
            entered.set()
            try:
                while not should_stop():
                    output(True, 'esperando')
                    time.sleep(.01)
            finally:
                closed.set()
        manager = Fuentes()
        with patch('scripts.probar_lectura_serial.run', side_effect=reader) as run:
            try:
                manager.start()
                self.assertTrue(entered.wait(2))
                source = manager.sources[1]
                # Ya no hay cierre por seis segundos sin solicitudes web.
                source.used = time.monotonic() - 100
                time.sleep(.1)
                self.assertFalse(source.stopped)
                self.assertEqual(run.call_count, 1)
                with self.assertRaisesRegex(ValueError, 'Esperando datos'):
                    manager.leer(1, Mock())
            finally:
                manager.close()
            self.assertTrue(closed.wait(1))
            self.assertFalse(manager.monitor.is_alive())
            self.assertFalse(source.thread.is_alive())

    def test_busy_com_does_not_block_app_and_returns_feedback(self):
        def reader(settings, output, should_stop):
            output(False, 'COM9 ocupado: comprueba que esté conectado y libre')
        with patch('scripts.probar_lectura_serial.run', side_effect=reader) as run:
            app = create_app()
            async def scenario():
                async with app.router.lifespan_context(app):
                    until(lambda: 1 in app.state.fuentes.sources and app.state.fuentes.sources[1].stopped)
                    request = Mock(app=app)
                    for _ in range(3):
                        with self.assertRaises(HTTPException) as error:
                            consultar_lectura(request, app.state.simulador, 1)
                        self.assertEqual(error.exception.status_code, 503)
                        self.assertIn('ocupado', error.exception.detail)
                    self.assertEqual(run.call_count, 1)
                    app.state.fuentes.sources[1].created -= 6
                    with self.assertRaises(HTTPException):
                        consultar_lectura(request, app.state.simulador, 1)
                    until(lambda: run.call_count == 2)
                    messages = []
                    async def receive():
                        return {'type': 'http.request', 'body': b'', 'more_body': False}
                    async def send(message):
                        messages.append(message)
                    await app({'type': 'http', 'asgi': {'version': '3.0'}, 'http_version': '1.1', 'method': 'GET', 'scheme': 'http', 'path': '/api/v1/salud', 'raw_path': b'/api/v1/salud', 'query_string': b'', 'root_path': '', 'headers': [], 'server': ('127.0.0.1', 8018), 'client': ('127.0.0.1', 1)}, receive, send)
                    self.assertEqual(messages[0]['status'], 200)
            asyncio.run(scenario())

    def test_simulated_unconfirmed_and_bad_file_never_open_com(self):
        for value in (dict(CONFIG, modo='SIMULADO'), dict(CONFIG, parametros_confirmados=0)):
            self.path.write_text(json.dumps(value))
            with patch('scripts.probar_lectura_serial.run') as run:
                manager = Fuentes()
                try:
                    manager.start()
                    time.sleep(.1)
                    run.assert_not_called()
                finally:
                    manager.close()
        self.path.write_text('{broken')
        manager = Fuentes()
        with patch('scripts.probar_lectura_serial.run') as run:
            try:
                manager.start()
                time.sleep(.1)
                self.assertTrue(manager.monitor.is_alive())
                run.assert_not_called()
            finally:
                manager.close()

    def test_new_configuration_closes_old_reader(self):
        opened = threading.Event()
        closed = threading.Event()
        def reader(settings, output, should_stop):
            opened.set()
            try:
                while not should_stop():
                    output(True, 'escuchando', b'ST,GS,+ 123 lb\r\n')
                    time.sleep(.01)
            finally:
                closed.set()
        manager = Fuentes()
        with patch('scripts.probar_lectura_serial.run', side_effect=reader):
            try:
                manager.start()
                self.assertTrue(opened.wait(2))
                until(lambda: manager.sources[1].sample is not None)
                self.assertEqual(manager.leer(1, Mock()).peso_lb, 123)
                self.path.write_text(json.dumps(dict(CONFIG, modo='SIMULADO')))
                self.assertTrue(closed.wait(2))
                until(lambda: 1 not in manager.sources)
            finally:
                manager.close()

    def test_publication_failure_only_warns(self):
        instance = supervisor.Installation.__new__(supervisor.Installation)
        instance.php = Path('php.exe')
        for outcome in (Mock(returncode=1), OSError('sin PHP')):
            env = {}
            kwargs = {'side_effect': outcome} if isinstance(outcome, Exception) else {'return_value': outcome}
            with patch.object(supervisor.subprocess, 'run', **kwargs), contextlib.redirect_stdout(io.StringIO()) as output:
                instance.prepare_serial(env)
            self.assertEqual(env['BASCULA_SERIAL_AUTO'], '0')
            self.assertIn('web seguirá disponible', output.getvalue())


if __name__ == '__main__':
    unittest.main()
