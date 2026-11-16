"""Captura manual real en fixture aislado: DB/API/COM deliberadamente ausentes."""
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = ROOT / 'runtime/php/php.exe'


class ManualWithoutIndicatorTests(unittest.TestCase):
    def test_manual_bruto_and_tara_without_reading_service(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            for name in ('web/public/capturar_peso.php','web/src/primera.php','web/src/serial.php','web/public/lectura.php'):
                target = root / name
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(ROOT / name, target)
            (root / 'web/src/bootstrap.php').write_text('''<?php
function require_method($methods) {}
function require_user() { return ['id'=>1,'empresa_id'=>1]; }
function valid_csrf() { return true; }
function config() { return ['timezone'=>'America/Tegucigalpa']; }
function db() { throw new RuntimeException('La captura manual no debe consultar fuente/DB en este paso'); }
function runtime_dir() { throw new RuntimeException('La captura manual no debe publicar fuente'); }
$_SESSION = ['credencial'=>'prueba','primera_firma_secreta'=>'secreto-de-prueba'];
$_POST = json_decode(stream_get_contents(STDIN), true);
$key = hash_hmac('sha256', $_SESSION['credencial'], $_SESSION['primera_firma_secreta']);
$_POST['firma'] = hash_hmac('sha256', 'solicitud:' . $_POST['solicitud'], $key);
''', encoding='utf-8')
            for kind in ('BRUTO','TARA'):
                with self.subTest(kind=kind):
                    result = subprocess.run([str(PHP), '-n', str(root/'web/public/capturar_peso.php')], input=json.dumps({'metodo':'MANUAL','tipo_primera':kind,'peso_manual':'1234','solicitud':'a'*32}), text=True, capture_output=True, timeout=5)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    data = json.loads(result.stdout)
                    self.assertEqual(data['origen'],'MANUAL')
                    self.assertEqual(data['peso_lb'],'1234')
                    self.assertIn('captura',data)
            result = subprocess.run([str(PHP), '-n', str(root/'web/public/lectura.php')], input=json.dumps({'solicitud':'a'*32}), text=True, capture_output=True, timeout=5)
            self.assertEqual(result.returncode, 0, result.stderr)
            error = json.loads(result.stdout)
            self.assertIn('Manual', error['error'])
            self.assertNotIn('Servicio temporalmente', error['error'])


if __name__ == '__main__':
    unittest.main()
