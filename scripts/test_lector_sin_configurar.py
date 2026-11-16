"""Pruebas sin hardware: kernel32 sustituido por un objeto simulado."""
import ctypes
import json
from pathlib import Path
import subprocess
import sys
import time
import unittest
from unittest.mock import patch
import probar_lectura_serial as reader
from leer_configuracion_com import consultar
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from app.services.fuente import FuenteSerial


class Function:
    def __init__(self, callback):
        self.callback = callback

    def __call__(self, *args):
        return self.callback(*args)


class Kernel:
    def __init__(self, payload=b'', failure=False, open_failure=False):
        self.payload = payload
        self.reads = 0
        self.closes = 0
        self.CreateFileW = Function(lambda *args: ctypes.c_void_p(-1).value if open_failure else 42)
        self.GetCommState = Function(self.settings)
        self.ClearCommError = Function(lambda h, e, s: self.status(h, e, s, failure))
        self.ReadFile = Function(self.read)
        self.CloseHandle = Function(self.close)
        # No setters exist: any attempt to change COM settings fails this test.

    def settings(self, handle, pointer):
        dcb = pointer._obj
        dcb.BaudRate, dcb.ByteSize, dcb.Parity, dcb.StopBits = 4800, 7, 2, 2
        return True

    def status(self, handle, errors, status, failure):
        status._obj.cbInQue = len(self.payload)
        return not failure

    def read(self, handle, buffer, amount, count, overlapped):
        assert amount <= len(self.payload)
        self.reads += 1
        data = self.payload[:amount]
        ctypes.memmove(buffer, data, len(data))
        count._obj.value = len(data)
        self.payload = self.payload[amount:]
        return True

    def close(self, handle):
        self.closes += 1
        return True


class ReaderTests(unittest.TestCase):
    def test_lector_operativo_acepta_decimales_de_mariadb(self):
        for stop in ('1.0', '2.0'):
            with self.subTest(bits_parada=stop):
                kernel = Kernel(b'ST,GS,+     40lb\r\n')
                config = dict(puerto_com='COM4', baudios=9600, bits_datos=8,
                              paridad='N', bits_parada=stop, control_flujo='NINGUNO',
                              protocolo='C18 = 4', unidad_indicador='lb', timeout_lectura_ms=1000)
                path = unittest.mock.Mock()
                path.read_text.return_value = json.dumps(config)
                with patch.object(reader.ctypes, 'WinDLL', return_value=kernel):
                    source = FuenteSerial(path, config)
                    try:
                        deadline = time.monotonic() + 2
                        while source.sample is None and not source.stopped and time.monotonic() < deadline:
                            time.sleep(.01)
                        sample = source.leer()
                        self.assertEqual(sample.peso_lb, 40)
                        self.assertEqual(sample.origen, 'serial')
                    finally:
                        source.close()
                        source.thread.join(timeout=2)
                self.assertEqual(kernel.closes, 1)

    def test_consulta_cli_aislada_como_php_sin_abrir_puerto(self):
        script = Path(__file__).with_name('leer_configuracion_com.py')
        result = subprocess.run([sys.executable, '-I', str(script), 'INVALIDO'],
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(json.loads(result.stdout)['ok'])
        self.assertEqual(result.stderr, '')

    def test_consulta_actual_sin_setters_ni_lectura(self):
        kernel = Kernel()
        result = consultar('COM4', kernel)
        self.assertTrue(result['ok'])
        self.assertEqual(result['valores']['baudios'], '4800')
        self.assertEqual(result['valores']['paridad'], 'E')
        self.assertEqual(kernel.reads, 0)
        self.assertEqual(kernel.closes, 1)

    def test_consulta_puerto_ocupado(self):
        kernel = Kernel(open_failure=True)
        self.assertFalse(consultar('COM4', kernel)['ok'])
        self.assertEqual(kernel.reads, 0)
        self.assertEqual(kernel.closes, 0)

    def exercise(self, kernel):
        now = [0.0]
        events, states = [], []

        def sleep(seconds):
            now[0] += seconds

        config = dict(puerto_com='COM4', baudios='9600', bits_datos='8', paridad='N',
                      bits_parada='1', control_flujo='NINGUNO')
        with patch.object(reader.ctypes, 'WinDLL', return_value=kernel), \
                patch.object(reader.time, 'monotonic', side_effect=lambda: now[0]), \
                patch.object(reader.time, 'sleep', side_effect=sleep), \
                patch.object(reader.ctypes, 'get_last_error', return_value=5):
            reader.run(config, output=lambda *args: events.append((now[0], args)), state_output=states.append)
        return events, states

    def test_no_data_keeps_heartbeat_for_30_seconds_without_read_or_setters(self):
        kernel = Kernel()
        events, states = self.exercise(kernel)
        beats = [t for t, args in events if 'todavía no llegan' in args[1]]
        self.assertGreater(len(beats), 50)
        self.assertGreater(beats[-1], 29)
        self.assertLess(max(b - a for a, b in zip(beats, beats[1:])), .6)
        self.assertEqual(kernel.reads, 0)
        self.assertEqual(kernel.closes, 1)
        self.assertEqual(states[-1], 'cerrado')
        settings = next(s for s in states if isinstance(s, dict))['parametros']
        self.assertIn('4800 baudios · 7 bits · paridad E · parada 2', settings)

    def test_reads_only_available_bytes(self):
        kernel = Kernel(b'ST,GS,100 lb\r\n')
        events, _ = self.exercise(kernel)
        self.assertTrue(any(len(args) == 3 and args[2] == b'ST,GS,100 lb\r\n' for _, args in events))
        self.assertEqual(kernel.reads, 1)
        self.assertEqual(kernel.closes, 1)

    def test_query_failure_closes_handle(self):
        kernel = Kernel(failure=True)
        events, states = self.exercise(kernel)
        self.assertTrue(any(args[0] is False for _, args in events))
        self.assertEqual(kernel.closes, 1)
        self.assertEqual(states[-1], 'cerrado')

    def test_busy_port_does_not_read_or_close_invalid_handle(self):
        kernel = Kernel(open_failure=True)
        events, states = self.exercise(kernel)
        self.assertEqual(kernel.reads, 0)
        self.assertEqual(kernel.closes, 0)
        self.assertNotIn('abierto', states)
        self.assertTrue(any(args[0] is False for _, args in events))


if __name__ == '__main__':
    unittest.main()
