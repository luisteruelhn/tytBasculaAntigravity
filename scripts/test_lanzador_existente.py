"""Pruebas de segundo lanzamiento/reinicio sin servicios ni navegador reales."""
import contextlib
import io
import json
import msvcrt
from pathlib import Path
from types import SimpleNamespace
import tempfile
import unittest
from unittest.mock import patch, Mock
import instalacion_local as s


class LauncherTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.private = Path(self.temp.name)
        (self.private / 'instalacion.json').write_text(json.dumps({'ports': {'db':3306, 'api':8018, 'web':8088}}))
        self.private_patch = patch.object(s, 'PRIVATE', self.private)
        self.private_patch.start()

    def tearDown(self):
        self.private_patch.stop()
        self.temp.cleanup()

    @contextlib.contextmanager
    def held(self):
        with (self.private / 'ejecucion.lock').open('a+b') as lock:
            if not lock.seek(0, 2):
                lock.write(b'0'); lock.flush()
            lock.seek(0)
            msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
            try:
                yield
            finally:
                lock.seek(0)
                msvcrt.locking(lock.fileno(), msvcrt.LK_UNLCK, 1)

    def test_duplicate_offers_continue_without_config_or_services(self):
        # No hay web-listen: se reconoce la instancia antes de validar nuevos ajustes.
        with self.held(), patch('builtins.input', return_value=''), patch.object(s, 'open_existing', return_value=0) as existing, patch.object(s, 'Installation') as installation, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(s.main(['iniciar', '--abrir']), 0)
            existing.assert_called_once_with(True)
            installation.assert_not_called()
        self.assertFalse((self.private / 'detener').exists())

    def test_exit_and_invalid_option_do_not_stop_services(self):
        args = SimpleNamespace(abrir=True)
        with patch('builtins.input', side_effect=['otra', 'S']), patch.object(s, 'open_existing') as existing, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(s.choose_existing(args), 0)
            existing.assert_not_called()
        self.assertFalse((self.private / 'detener').exists())

    def test_restart_choice_preserves_launcher_arguments(self):
        args = SimpleNamespace(abrir=True, solo_pc=True, red_local=None, puerto_web=None)
        with patch('builtins.input', return_value='R'), patch.object(s, 'main', return_value=0) as main, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(s.choose_existing(args), 0)
            main.assert_called_once_with(['reiniciar', '--abrir', '--solo-pc'])

    def test_valid_active_url_opens_after_health_checks(self):
        state = {'root': str(s.ROOT), 'url': 'http://127.0.0.1:8099/login.php', 'api_port':8018}
        (self.private / 'servidor-listo.json').write_text(json.dumps(state))
        response = Mock()
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        response.status = 200
        response.read.return_value = b'{"estado":"ok","modo":"configurable"}'
        opener = Mock()
        opener.open.return_value = response
        with patch.object(s, 'build_opener', return_value=opener), patch.object(s.webbrowser, 'open') as browser, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(s.open_existing(True), 0)
            browser.assert_called_once_with(state['url'])
            self.assertEqual(opener.open.call_count, 2)

    def test_absent_corrupt_or_external_state_does_not_open_browser(self):
        for value in (None, '{broken', json.dumps({'root':str(s.ROOT), 'url':'http://8.8.8.8/login.php','api_port':8018})):
            path = self.private / 'servidor-listo.json'
            if value is None:
                path.unlink(missing_ok=True)
            else:
                path.write_text(value)
            with patch.object(s.webbrowser, 'open') as browser, patch.object(s, 'build_opener') as opener, contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(s.open_existing(True), 0)
                browser.assert_not_called()
                opener.assert_not_called()

    def test_unresponsive_server_keeps_services_untouched(self):
        (self.private / 'servidor-listo.json').write_text(json.dumps({'root':str(s.ROOT), 'url':'http://127.0.0.1:8088/login.php','api_port':8018}))
        with patch.object(s, 'build_opener') as opener, patch.object(s.webbrowser, 'open') as browser, contextlib.redirect_stdout(io.StringIO()):
            opener.return_value.open.side_effect = OSError('no responde')
            self.assertEqual(s.open_existing(True), 0)
            browser.assert_not_called()
        self.assertFalse((self.private / 'detener').exists())

    def test_restart_waits_for_lock_without_forcing_processes(self):
        @contextlib.contextmanager
        def busy_then_free():
            if attempts[0] == 0:
                attempts[0] += 1
                raise s.AlreadyRunning()
            yield
        attempts = [0]
        with patch.object(s, 'exclusive', side_effect=busy_then_free), patch.object(s.time, 'sleep'), patch.object(s, 'terminate_tree') as terminate, contextlib.redirect_stdout(io.StringIO()):
            s.request_restart()
            terminate.assert_not_called()
        self.assertTrue((self.private / 'detener').exists())

    def test_restart_timeout_does_not_force_or_start(self):
        with patch.object(s, 'exclusive', side_effect=s.AlreadyRunning), patch.object(s.time, 'monotonic', side_effect=[0,46]), patch.object(s, 'terminate_tree') as terminate, patch.object(s, 'Installation') as installation, contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, 'No terminó'):
                s.request_restart()
            terminate.assert_not_called()
            installation.assert_not_called()

    def test_restart_starts_after_wait_and_cleans_status(self):
        (self.private / 'web-listen.json').write_text(json.dumps({'interface':'127.0.0.1','port':8088}))
        events = []
        with patch.object(s, 'request_restart', side_effect=lambda: events.append('wait')), patch.object(s, 'Installation') as installation:
            installation.return_value.run.side_effect = lambda *args: events.append('run')
            self.assertEqual(s.main(['reiniciar','--abrir']),0)
            self.assertEqual(events, ['wait','run'])
            installation.return_value.close.assert_called_once()
        self.assertFalse((self.private / 'servidor-listo.json').exists())


if __name__ == '__main__':
    unittest.main()
