"""Actualización específica XAMPP 3306, con respaldo y prueba aislada obligatorios."""
import argparse
import ast
from contextlib import contextmanager
from datetime import datetime
import hashlib
import json
from pathlib import Path
import secrets
import shutil
import subprocess
import time
import instalacion_local as installation

ROOT = Path(__file__).resolve().parents[1]
PRIVATE = ROOT / '.runtime/local'


@contextmanager
def paused_app():
    deadline = time.monotonic() + 90
    requested = False
    while True:
        try:
            with installation.exclusive():
                yield
                return
        except installation.AlreadyRunning:
            if not requested:
                (PRIVATE / 'detener').touch()
                print('Cierre solicitado solo a Báscula. XAMPP y la instancia de 3318 se conservan.', flush=True)
                requested = True
            if time.monotonic() > deadline:
                raise RuntimeError('La app no terminó de cerrar; no se actualiza la base.') from None
            time.sleep(.25)


def run(apply=False):
    with paused_app():
        config = json.loads((PRIVATE / 'instalacion.json').read_text(encoding='utf-8'))
        if not config.get('external_db') or config['ports']['db'] != 3306:
            raise RuntimeError('Este procedimiento solo admite XAMPP externo en 3306.')
        # Verificar identidad antes incluso del respaldo y de crear la base temporal.
        from configurar_xampp import diagnose_xampp
        _, _, report = diagnose_xampp(PRIVATE, config.get('xampp_folder') or str(Path(config['external_db_datadir']).parent.parent), 3306)
        if not report.get('identity'):
            raise RuntimeError('No se confirmó el datadir de XAMPP; no se actualiza.')
        folder = PRIVATE / 'actualizaciones' / ('20261113-migracion-xampp-' + datetime.now().strftime('%H%M%S') + '-' + secrets.token_hex(3))
        folder.mkdir(parents=True, exist_ok=False)
        shutil.copy2(PRIVATE / 'instalacion.json', folder / 'instalacion.json')
        shutil.copy2(ROOT / 'runtime/php/php.ini', folder / 'php.ini')
        migration = ROOT / 'scripts/actualizaciones/20261113-xampp-compatibilidad.sql'
        shutil.copy2(migration, folder / migration.name)
        credential = folder / 'cliente-temporal.cnf'
        password = config.get('external_db_admin_password', '')
        if any(c in password for c in '\r\n\x00'):
            raise RuntimeError('Formato de credencial administrativa no compatible con este respaldo.')
        escaped = password.replace('\\', '\\\\').replace('"', '\\"')
        credential.write_text('[client]\nhost=127.0.0.1\nport=3306\nuser=root\npassword="' + escaped + '"\n', encoding='utf-8')
        dump = folder / 'dbBascula-antes.sql'
        print('Respaldo privado: ' + str(folder), flush=True)
        try:
            with dump.open('wb') as stream:
                result = subprocess.run([str(ROOT / 'runtime/mariadb/bin/mysqldump.exe'), '--defaults-file=' + str(credential), '--single-transaction', '--routines', '--events', '--triggers', '--hex-blob', '--databases', 'dbBascula'], stdout=stream, stderr=subprocess.PIPE, timeout=180)
            if result.returncode or dump.stat().st_size < 100:
                raise RuntimeError('Falló el respaldo de la base; no se ejecuta la actualización.')
            with dump.open('rb') as check:
                check.seek(max(0, dump.stat().st_size - 4096))
                if b'Dump completed' not in check.read():
                    raise RuntimeError('El respaldo no contiene confirmación de finalización; no se actualiza.')
        finally:
            credential.unlink(missing_ok=True)
        manifest = {'backup_sha256': hashlib.sha256(dump.read_bytes()).hexdigest(), 'backup_bytes': dump.stat().st_size, 'migration_sha256': hashlib.sha256(migration.read_bytes()).hexdigest(), 'apply_requested': apply, 'base_commit': '66ccdfb'}
        (folder / 'manifiesto.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
        (folder / 'reversion.md').write_text('No ejecutar automáticamente. Con la app cerrada y autorización, restaurar este dump únicamente en la identidad XAMPP 3306 comprobada. El dump contiene datos privados. Restaurarlo tras operaciones nuevas perdería esas operaciones: conciliarlas antes. La cuenta bascula_web no existía; para revertir solo acceso, retirar únicamente bascula_web@127.0.0.1 si sigue siendo la creada por esta intervención. No tocar MariaDB 3318. La reversión exclusiva de código no elimina columnas nuevas ni exige restaurar datos.\n', encoding='utf-8')
        module = ast.parse((ROOT / 'scripts/instalacion_local.py').read_text(encoding='utf-8'))
        initializer = next(n for n in ast.walk(module) if isinstance(n, ast.FunctionDef) and n.name == 'initialize')
        grants = next(ast.literal_eval(n.value) for n in initializer.body if isinstance(n, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'grants' for t in n.targets))
        (folder / 'permisos-aplicados.sql').write_text(grants, encoding='utf-8')
        payload = {'run': str(folder), 'stage': 'bascula_validacion_' + secrets.token_hex(6), 'apply': apply, 'grants': grants}
        result = subprocess.run([str(ROOT / 'runtime/php/php.exe'), '-c', str(ROOT / 'runtime/php/php.ini'), str(ROOT / 'scripts/actualizar_xampp.php')], cwd=ROOT, input=json.dumps(payload), capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=240)
        print(result.stdout, end='', flush=True)
        if result.returncode:
            print(result.stderr, end='', flush=True)
            raise RuntimeError('Actualización no completada. Consulta el respaldo y el registro de fase; no reinicialices la instalación.')
        print('No se inicia la app automáticamente. Abre su iniciador para verificar el acceso.', flush=True)
        return folder


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--aplicar', action='store_true', help='Tras superar la copia temporal, aplica únicamente en XAMPP 3306')
    args = parser.parse_args()
    try:
        run(args.aplicar)
    except (RuntimeError, OSError, ValueError, subprocess.SubprocessError) as error:
        print(str(error) if isinstance(error, RuntimeError) else 'No se pudo completar la actualización; conservar respaldo y revisar permisos.')
        raise SystemExit(1)
