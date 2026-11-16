"""Construye Python privado desde el ZIP oficial y paquetes CPython 3.14 ya instalados.

Herramienta de preparación; la PC de destino solo necesita la carpeta resultante.
No instala paquetes, modifica PATH, crea venv ni accede a la base o al hardware.
"""
import argparse
import base64
import csv
import hashlib
from importlib.metadata import distributions
import json
from pathlib import Path
import shutil
import zipfile

ROOT = Path(__file__).resolve().parents[1]
DEPENDENCIES = {
    'annotated-doc': '0.0.5', 'annotated-types': '0.8.0', 'anyio': '4.16.0',
    'charset-normalizer': '3.5.2', 'click': '8.5.1', 'fastapi': '0.162.2',
    'h11': '0.16.0', 'idna': '3.20', 'opentelemetry-api': '1.45.2',
    'pillow': '13.0.0', 'pydantic': '2.14.3', 'pydantic-core': '2.50.4',
    'reportlab': '5.1.0', 'starlette': '1.10.0', 'typing-extensions': '4.16.0',
    'typing-inspection': '0.4.5', 'uvicorn': '0.54.0',
}


def normalized(name):
    return name.lower().replace('_', '-').replace('.', '-')


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def build(archive, packages, destination):
    reference = json.loads((ROOT / 'python-runtime.lock.json').read_text(encoding='utf-8'))
    if reference['dependencies'] != DEPENDENCIES:
        raise ValueError('Las versiones del constructor no coinciden con el lock aprobado.')
    if digest(archive) != reference['python']['sha256']:
        raise ValueError('El ZIP Python no coincide con el SHA-256 aprobado.')
    if destination.exists():
        raise ValueError('El destino debe ser nuevo; no se reemplaza un runtime existente.')
    installed = {normalized(d.metadata['Name']): d for d in distributions(path=[str(packages)])}
    selected = []
    # Validar todo antes de escribir; RECORD identifica exclusivamente archivos de paquetes.
    for name, version in DEPENDENCIES.items():
        dist = installed.get(name)
        if dist is None or dist.version != version:
            raise ValueError(f'Falta {name}=={version} en los paquetes de origen.')
        wheel = dist.read_text('WHEEL') or ''
        if not ('Tag: py3-none-any' in wheel or 'cp314-cp314-win_amd64' in wheel):
            raise ValueError(f'Wheel incompatible con CPython 3.14 Windows x64: {name}')
        record = dist.read_text('RECORD')
        if not record:
            raise ValueError(f'Falta RECORD para verificar {name}')
        for entry in csv.reader(record.splitlines()):
            relative, expected, _size = entry
            path = Path(relative)
            if '..' in path.parts or path.is_absolute() or '__pycache__' in path.parts or path.suffix == '.pyc':
                continue  # Scripts de pip/venv y cachés no forman parte del runtime.
            source = packages / path
            if expected:
                algorithm, encoded = expected.split('=', 1)
                if algorithm != 'sha256':
                    raise ValueError(f'Algoritmo RECORD no soportado: {name}')
                actual = base64.urlsafe_b64encode(hashlib.sha256(source.read_bytes()).digest()).decode().rstrip('=')
                if actual != encoded:
                    raise ValueError(f'Archivo modificado respecto a RECORD: {relative}')
            elif path.name != 'RECORD':
                # Archivos añadidos por pip sin hash se omiten (INSTALLER, REQUESTED, direct_url).
                continue
            selected.append((source, path))
    with zipfile.ZipFile(archive) as zipped:
        if 'python314._pth' not in zipped.namelist():
            raise ValueError('Se requiere el ZIP embebible Python 3.14 Windows x64.')
        for member in zipped.namelist():
            if '..' in Path(member).parts or Path(member).is_absolute():
                raise ValueError('Ruta insegura en el ZIP.')
        destination.mkdir(parents=True)
        zipped.extractall(destination)
    for source, relative in selected:
        target = destination / 'Lib/site-packages' / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)
    # _pth ignora PYTHONPATH y el registro de Windows. No ejecutar sitecustomize ni .pth externos.
    (destination / 'python314._pth').write_text(
        'python314.zip\n.\nLib/site-packages\n../../\n../../scripts\n', encoding='ascii')
    files = {p.relative_to(destination).as_posix(): digest(p)
             for p in sorted(destination.rglob('*')) if p.is_file()}
    manifest = {'python': reference['python'], 'dependencies': DEPENDENCIES, 'files': files}
    (destination / 'runtime-manifest.json').write_text(
        json.dumps(manifest, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    print(f'Runtime preparado: {destination} ({len(files)} archivos verificados).')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--python-zip', type=Path, required=True)
    parser.add_argument('--site-packages', type=Path, required=True)
    parser.add_argument('--destino', type=Path, default=ROOT / 'runtime/python')
    args = parser.parse_args()
    build(args.python_zip.resolve(), args.site_packages.resolve(), args.destino.resolve())
