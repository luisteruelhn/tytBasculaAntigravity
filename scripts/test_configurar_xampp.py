"""Configuración XAMPP aislada; sin servicios, cuentas ni bases reales."""
import contextlib
import io
import json
import msvcrt
from pathlib import Path
import tempfile
import threading
import time
import unittest
from unittest.mock import patch, Mock
from urllib.request import build_opener, ProxyHandler, Request
from urllib.parse import urlencode, urlsplit
from urllib.error import HTTPError
import configurar_xampp as x
import configurar_web as c


class XamppTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name) / 'bascula'
        self.private = self.root / '.runtime/local'
        self.private.mkdir(parents=True)
        self.xampp = Path(self.temp.name) / 'Xampp con espacios'
        for name in ('xampp-control.exe','mysql/bin/mysqld.exe'):
            path = self.xampp / name
            path.parent.mkdir(parents=True,exist_ok=True)
            path.touch()
        (self.xampp / 'mysql/data').mkdir()
        (self.root / 'scripts').mkdir()
        (self.root / 'scripts/dbBascula.sql').write_text('CREATE TABLE IF NOT EXISTS t_empresas (\n id INT UNSIGNED\n) ENGINE=InnoDB;')
        self.config = {'root':str(self.root),'ports':{'db':3306,'api':8018,'web':8088},'web_password':'solo-prueba','preservar':'intacto'}
        self.path = self.private / 'instalacion.json'
        self.path.write_text(json.dumps(self.config))
        self.report = {'server':True,'datadir':str(self.xampp/'mysql/data'),'web_access':True,'schema':True,'missing_tables':[],'missing_columns':{}}

    def tearDown(self):
        self.temp.cleanup()

    def test_suggestions_use_server_section_not_client_or_password(self):
        self.assertEqual(x.suggest_xampp(str(self.xampp))['port'],3306)
        ini=self.xampp/'mysql/bin/my.ini'
        ini.write_text('[client]\nport=9999\npassword=no-mostrar\n[mysqld]\nport=3307 # servidor\n',encoding='utf-8')
        suggestion=x.suggest_xampp(str(self.xampp))
        self.assertEqual(suggestion['port'],3307)
        self.assertIn('[mysqld]',suggestion['source'])
        self.assertNotIn('no-mostrar',json.dumps(suggestion))
        self.assertEqual(json.loads(self.path.read_text()),self.config)
        ini.write_text('[mysqld]\nport=70000\n')
        self.assertEqual(x.suggest_xampp(str(self.xampp))['port'],3306)

    def test_validation(self):
        for folder in ('relativa', str(self.root)):
            with self.assertRaises(RuntimeError):
                x.validate_xampp(self.private,folder,3306)
        for port in (0,65536,True,'3306',8018,8088):
            with self.assertRaises(RuntimeError):
                x.validate_xampp(self.private,str(self.xampp),port)
        (self.private/'web-listen.json').write_text(json.dumps({'port':8099}))
        with self.assertRaises(RuntimeError):
            x.validate_xampp(self.private,str(self.xampp),8099)

    def test_diagnosis_requires_matching_identity_access_and_schema(self):
        for changed,ready in (({},True),({'datadir':str(self.root)},False),({'schema':False},False),({'web_access':False},False)):
            response = Mock(returncode=0,stdout=json.dumps(dict(self.report,**changed)))
            with patch.object(x.subprocess,'run',return_value=response):
                _,_,report=x.diagnose_xampp(self.private,str(self.xampp),3306)
                self.assertEqual(report['ready'],ready)
        self.assertEqual(json.loads(self.path.read_text()),self.config)

    def test_save_preserves_secrets_and_backs_up(self):
        report=dict(self.report,identity=True,ready=True)
        with patch.object(x,'diagnose_xampp',return_value=(self.xampp,self.config.copy(),report)):
            x.save_xampp(self.private,str(self.xampp),3307)
        saved=json.loads(self.path.read_text())
        self.assertEqual(saved['ports'],{'db':3307,'api':8018,'web':8088})
        self.assertEqual(saved['web_password'],self.config['web_password'])
        self.assertEqual(saved['preservar'],'intacto')
        self.assertTrue(saved['external_db'])
        self.assertEqual(json.loads(next(self.private.glob('*.bak')).read_text()),self.config)

    def test_invalid_database_never_saves(self):
        report=dict(self.report,identity=True,ready=False,schema=False,missing_tables=['t_configuracion_bascula'])
        with patch.object(x,'diagnose_xampp',return_value=(self.xampp,self.config.copy(),report)):
            with self.assertRaisesRegex(RuntimeError,'No se guardó'):
                x.save_xampp(self.private,str(self.xampp),3306)
        self.assertEqual(json.loads(self.path.read_text()),self.config)
        self.assertFalse(list(self.private.glob('*.bak')))

    def test_open_app_rejects_save_before_diagnosis(self):
        lock=self.private/'ejecucion.lock'
        with lock.open('w+b') as stream:
            stream.write(b'0');stream.flush();stream.seek(0)
            msvcrt.locking(stream.fileno(),msvcrt.LK_NBLCK,1)
            try:
                with patch.object(x,'diagnose_xampp') as diagnose:
                    with self.assertRaisesRegex(RuntimeError,'Cierra la app'):
                        x.save_xampp(self.private,str(self.xampp),3306)
                    diagnose.assert_not_called()
            finally:
                stream.seek(0);msvcrt.locking(stream.fileno(),msvcrt.LK_UNLCK,1)

    def test_error_output_is_sanitized(self):
        with patch.object(x.subprocess,'run',return_value=Mock(returncode=1,stdout='secreto-no-mostrar')):
            with self.assertRaises(RuntimeError) as error:
                x.diagnose_xampp(self.private,str(self.xampp),3306)
            self.assertNotIn('secreto',str(error.exception))

    def test_http_diagnosis_and_confirmed_save(self):
        output=io.StringIO()
        report=dict(self.report,identity=True,ready=True)
        with contextlib.redirect_stdout(output),patch.object(x,'diagnose_xampp',return_value=(self.xampp,self.config.copy(),report)) as diagnose:
            thread=threading.Thread(target=c.serve,args=(self.private,self.config['ports'],{'interface':'127.0.0.1','port':8088},{}),kwargs={'open_browser':False,'lifetime':15})
            thread.start()
            try:
                deadline=time.monotonic()+2
                while 'http://' not in output.getvalue() and time.monotonic()<deadline: time.sleep(.01)
                url=output.getvalue().split('Configurador temporal: ')[1].splitlines()[0]
                parsed=urlsplit(url);origin='http://'+parsed.netloc
                opener=build_opener(ProxyHandler({}))
                with opener.open(url,timeout=2) as response:
                    body=response.read().decode()
                    self.assertIn('Puerto MySQL',body)
                    self.assertIn('Usar sugerencia de XAMPP',body)
                    self.assertIn('Carpeta de XAMPP',body)
                    self.assertNotIn('solo-prueba',body)
                fields={'token':parsed.path[1:],'action':'test_db','xampp_folder':str(self.xampp),'db_port':'3307'}
                def post(values,source=origin):
                    return opener.open(Request(url,data=urlencode(values).encode(),headers={'Origin':source}),timeout=3)
                with self.assertRaises(HTTPError) as error: post(fields,'https://externo.invalid')
                self.assertEqual(error.exception.code,403);error.exception.close();diagnose.assert_not_called()
                with post(fields) as response: self.assertIn('compatible',response.read().decode())
                self.assertTrue(thread.is_alive())
                with self.assertRaises(HTTPError) as error: post(dict(fields,action='save_db'))
                self.assertEqual(error.exception.code,400);error.exception.close()
                self.assertEqual(json.loads(self.path.read_text()),self.config)
                with post(dict(fields,action='save_db',confirm_db='1')) as response: self.assertEqual(response.status,200)
            finally:
                thread.join(17)
            self.assertFalse(thread.is_alive())
            self.assertEqual(json.loads(self.path.read_text())['ports']['db'],3307)


if __name__=='__main__': unittest.main()
