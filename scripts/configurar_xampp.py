"""Ajustes de conexión a XAMPP externo; nunca administra servicios ni esquemas."""
from contextlib import contextmanager
import json
import msvcrt
import os
from pathlib import Path
import re
import secrets
import subprocess
import time


def suggest_xampp(folder=None):
    """Lee solo port de [mysqld]; nunca muestra otros valores de my.ini."""
    candidate = Path(folder or 'C:/xampp')
    suggestion = {'folder': str(candidate), 'port': 3306, 'source': 'valor predeterminado de XAMPP'}
    for relative in ('mysql/bin/my.ini', 'mysql/my.ini'):
        path = candidate / relative
        if not path.is_file():
            continue
        try:
            section = ''
            for line in path.read_text(encoding='utf-8-sig', errors='replace').splitlines():
                line = line.strip()
                if line.startswith(('#', ';')):
                    continue
                if line.startswith('[') and ']' in line:
                    section = line[1:line.index(']')].strip().lower()
                elif section == 'mysqld':
                    match = re.fullmatch(r'port\s*=\s*([0-9]{1,5})(?:\s*[#;].*)?', line, re.I)
                    if match and 1 <= int(match[1]) <= 65535:
                        suggestion.update(port=int(match[1]), source=relative + ' [mysqld]')
            return suggestion
        except OSError:
            break
    return suggestion


@contextmanager
def stopped_installation(private):
    with (private / 'ejecucion.lock').open('a+b') as lock:
        if os.fstat(lock.fileno()).st_size == 0:
            lock.write(b'0'); lock.flush()
        lock.seek(0)
        try:
            msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
        except OSError:
            raise RuntimeError('Cierra la app antes de guardar la conexión a XAMPP.') from None
        try:
            yield
        finally:
            lock.seek(0)
            msvcrt.locking(lock.fileno(), msvcrt.LK_UNLCK, 1)


def validate_xampp(private, folder, port):
    candidate = Path(folder.strip())
    if not candidate.is_absolute() or not candidate.is_dir():
        raise RuntimeError('Indica la carpeta absoluta donde está instalado XAMPP.')
    for relative in ('xampp-control.exe', 'mysql/bin/mysqld.exe', 'mysql/data'):
        if not (candidate / relative).exists():
            raise RuntimeError('La carpeta no contiene XAMPP completo (panel, MySQL y mysql/data).')
    config = json.loads((private / 'instalacion.json').read_text(encoding='utf-8'))
    ports = config['ports']
    web_port = ports['web']
    if (private / 'web-listen.json').exists():
        web_port = json.loads((private / 'web-listen.json').read_text(encoding='utf-8'))['port']
    if type(port) is not int or not 1 <= port <= 65535 or port in (ports['api'], ports['web'], web_port):
        raise RuntimeError('Puerto MySQL inválido o en conflicto con web/FastAPI.')
    return candidate.resolve(), config


def diagnose_xampp(private, folder, port):
    candidate, config = validate_xampp(private, folder, port)
    root = private.parent.parent
    schema = (root / 'scripts/dbBascula.sql').read_text(encoding='utf-8')
    expected = {}
    for table, definition in re.findall(r'CREATE TABLE IF NOT EXISTS (\w+)\s*\((.*?)\) ENGINE=', schema, re.S):
        expected[table.lower()] = [m.group(1).lower() for line in definition.splitlines() if (m := re.match(r'\s+(\w+)\s+(?:BIGINT|INT|SMALLINT|TINYINT|VARCHAR|CHAR|TEXT|DECIMAL|ENUM|BOOLEAN|TIMESTAMP|DATETIME|DATE)\b', line, re.I))]
    try:
        result = subprocess.run([str(root / 'runtime/php/php.exe'), '-c', str(root / 'runtime/php/php.ini'), str(root / 'scripts/diagnosticar_xampp.php')], cwd=root, input=json.dumps({'port': port, 'expected': expected}), text=True, encoding='utf-8', capture_output=True, timeout=12)
        if result.returncode:
            raise ValueError
        report = json.loads(result.stdout)
        if not isinstance(report, dict):
            raise ValueError
    except (OSError, ValueError, subprocess.SubprocessError):
        raise RuntimeError('No se pudo comprobar XAMPP con el PHP privado; revisa sus rutas y permisos.') from None
    report['identity'] = bool(report.get('datadir')) and Path(report['datadir']).resolve() == (candidate / 'mysql/data').resolve()
    report['ready'] = report.get('server') is True and report['identity'] and report.get('web_access') is True and report.get('schema') is True
    return candidate, config, report


def describe_report(report):
    lines = ['MySQL en loopback: ' + ('activo' if report.get('server') else 'sin respuesta'),
             'Carpeta de datos coincide: ' + ('sí' if report.get('identity') else 'no verificable o distinta'),
             'Acceso de Báscula: ' + ('correcto' if report.get('web_access') else 'rechazado o no disponible'),
             'Estructura requerida: ' + ('compatible' if report.get('schema') else 'incompleta o no verificable')]
    if report.get('web_code'):
        lines.append('Código de acceso: ' + str(report['web_code']))
    if report.get('admin_code'):
        lines.append('Código de verificación de identidad: ' + str(report['admin_code']))
    if report.get('missing_tables'):
        lines.append('Tablas ausentes: ' + ', '.join(report['missing_tables']))
    for table, columns in report.get('missing_columns', {}).items():
        lines.append('Columnas ausentes en ' + table + ': ' + ', '.join(columns))
    lines.append('No se modificaron cuentas, tablas, pesadas ni servicios de XAMPP.')
    return '\n'.join(lines)


def save_xampp(private, folder, port):
    with stopped_installation(private):
        candidate, config, report = diagnose_xampp(private, folder, port)
        if not report['ready']:
            raise RuntimeError('No se guardó la conexión: primero resuelve el acceso y la estructura de la base.\n' + describe_report(report))
        config['external_db'] = True
        config['xampp_folder'] = str(candidate)
        config['external_db_datadir'] = str(candidate / 'mysql/data')
        config['ports'] = dict(config['ports'], db=port)
        path = private / 'instalacion.json'
        backup = private / ('instalacion.json.' + time.strftime('%Y%m%d-%H%M%S') + '-' + secrets.token_hex(4) + '.bak')
        backup.write_bytes(path.read_bytes())
        temporary = private / ('instalacion.' + secrets.token_hex(8) + '.tmp')
        try:
            temporary.write_text(json.dumps(config, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
            temporary.replace(path)
        finally:
            temporary.unlink(missing_ok=True)
        return report
