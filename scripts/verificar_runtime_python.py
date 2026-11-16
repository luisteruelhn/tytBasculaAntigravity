"""Comprueba runtime, API simulada, PDF y Excel sin servicios persistentes, BD ni COM."""
import hashlib
from importlib.metadata import version
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from urllib.request import build_opener, ProxyHandler
import socket
import time
import zipfile
import io

ROOT = Path(__file__).resolve().parents[1]


def main():
    runtime = ROOT / 'runtime/python'
    assert Path(sys.executable).resolve() == (runtime / 'python.exe').resolve(), 'Ejecutar con el Python propio'
    assert sys.version_info[:2] == (3, 14) and sys.maxsize > 2**32
    assert sys.flags.isolated, 'Se requiere aislamiento _pth'
    assert str(ROOT) in sys.path, 'Falta la raíz del proyecto en _pth'
    import scripts.probar_lectura_serial  # También debe resolverse fuera del directorio actual.
    manifest = json.loads((runtime / 'runtime-manifest.json').read_text(encoding='utf-8'))
    for name, expected in manifest['files'].items():
        assert hashlib.sha256((runtime / name).read_bytes()).hexdigest() == expected, name
    for name, expected in manifest['dependencies'].items():
        assert version(name) == expected, name
    print('Runtime y 17 dependencias: versiones e integridad correctas.', flush=True)
    subprocess.run([sys.executable, '-B', str(ROOT / 'scripts/test_lector_sin_configurar.py'), '-v'], check=True)
    # Una API nueva, solo loopback y sin empresa: siempre simulador, sin configuración serial.
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    with tempfile.TemporaryFile() as log:
        http = build_opener(ProxyHandler({}))
        process = subprocess.Popen([sys.executable, '-B', '-m', 'uvicorn', 'app.main:app',
            '--host', '127.0.0.1', '--port', str(port)], cwd=ROOT, stdout=log, stderr=log)
        try:
            deadline = time.monotonic() + 15
            while True:
                try:
                    with http.open(f'http://127.0.0.1:{port}/api/v1/lecturas/actual', timeout=1) as response:
                        sample = json.load(response)
                    assert sample['origen'] == 'simulado' and sample['unidad'] == 'lb'
                    break
                except OSError:
                    if process.poll() is not None or time.monotonic() > deadline:
                        log.seek(0)
                        raise RuntimeError(log.read().decode(errors='replace'))
                    time.sleep(.1)
        finally:
            if process.poll() is None:
                process.terminate()
            process.wait(timeout=10)
    print('API HTTP simulada: correcta; proceso cerrado.', flush=True)
    data = dict(titulo='Prueba runtime', empresa='EMPRESA DE PRUEBA', subtitulo='Sin datos reales',
                datos_empresa=[], campos=[], cabeceras=['A'] * 7, filas=[], totales=[],
                notas=[], firmas=False, excel_filas=[], excel_cabeceras=['A'] * 42, detalles=[])
    payload = json.dumps(data).encode('utf-8')
    pdf = subprocess.run([sys.executable, '-I', str(ROOT / 'scripts/generar_pdf.py')],
                         input=payload, capture_output=True, check=True).stdout
    assert pdf.startswith(b'%PDF-') and b'%%EOF' in pdf
    data['totales'] = [''] * 17  # El contrato Excel siempre incluye sus columnas de totales.
    payload = json.dumps(data).encode('utf-8')
    excel = subprocess.run([sys.executable, '-I', str(ROOT / 'scripts/generar_excel.py')],
                           input=payload, capture_output=True, check=True).stdout
    with zipfile.ZipFile(io.BytesIO(excel)) as book:
        assert book.testzip() is None and 'xl/workbook.xml' in book.namelist()
    print('Generadores PDF/Excel por stdin/stdout: correctos. Sin guardar documentos.', flush=True)
    subprocess.run([sys.executable, '-B', str(ROOT / 'scripts/instalacion_local.py'), '--help'],
                   check=True, stdout=subprocess.DEVNULL)
    print('Lanzador Python: carga correcta. No se inició la instalación ni se tocó la base.', flush=True)


if __name__ == '__main__':
    main()
