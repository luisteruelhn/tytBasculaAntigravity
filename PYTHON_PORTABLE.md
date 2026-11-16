# Python propio de TYTOne Báscula

## Uso en una instalación existente

La aplicación usa `runtime/python/python.exe`, con CPython 3.14 x64 embebible y 17 dependencias de versiones fijas. Los archivos `python-runtime.lock.json` y `runtime/python/runtime-manifest.json` identifican el intérprete y el contenido del paquete. El runtime conserva las licencias suministradas con Python y los paquetes.

No instalar Python en `C:\PythonBascula`, modificar PATH ni editar pyvenv.cfg. Tampoco copiar `.venv` del equipo anterior. La carpeta `runtime/python` se puede trasladar junto con el código a otra ruta de Windows x64 compatible. Las rutas de importación son relativas al runtime y están aisladas de PYTHONPATH, PYTHONHOME y paquetes de usuario mediante `python314._pth`.

1. Leer `SEED.MD` y comprobar la identidad de la instalación y de la base activa. Detener la app en una pausa acordada; conservar XAMPP cuando se use base externa.
2. Respaldar los archivos que se reemplazan y la configuración privada. No eliminar ni sobrescribir `.runtime`, `dbbackup`, `__pdfs`, `web/config/local.php` ni la base. Conservar `.venv` durante la validación para poder revertir el código anterior.
3. Copiar el paquete Python probado en `runtime/python` junto con los archivos del commit indicado en `SEED.MD`. El ZIP de distribución contiene la carpeta `python`; extraerla dentro de `runtime`. No mezclar archivos de dos runtimes: respaldar y apartar uno existente antes de sustituirlo.
4. Desde la raíz ejecutar:

   ```bat
   runtime\python\python.exe -B scripts\verificar_runtime_python.py
   ```

   Comprueba integridad, versiones, ocho pruebas seriales simuladas, API temporal en loopback, PDF, Excel y carga del supervisor. No abre COM, consulta SQL ni inicia la instalación permanente. La API temporal se cierra al finalizar.
5. Abrir `Iniciar Bascula solo PC.cmd` o el lanzador de red ya configurado para ese equipo. Comprobar respaldo de arranque, login, lectura y funciones habituales antes de reanudar operaciones. Las pruebas físicas y el guardado de notas se coordinan con el operador.

La portabilidad de Python no cambia la identidad de `.runtime/local/instalacion.json`, la ruta del datadir de XAMPP, php.ini, IP, puertos, impresora ni configuración COM. Un traslado completo de instalación a otra carpeta/PC debe comprobar esos ajustes por separado. No se relaja automáticamente la comprobación de identidad de la base.

## Distribución y repositorio

El código y las instrucciones están en el repositorio privado `luisteruelhn/TYTONEBASCULALocal`, rama `main`. `runtime/` permanece excluido de Git; un clon de código solo no contiene los binarios. Entregar también el [paquete de runtime v0.1.0-python-portable](https://github.com/luisteruelhn/TYTONEBASCULALocal/releases/tag/v0.1.0-python-portable), cuyo SHA-256 queda registrado en `SEED.MD`. La versión apunta al commit de implementación `4ad4a52b82d763207d564fbbad1a1356afb0c0b9`; requiere acceso al repositorio privado.

Una copia de trabajo sin `.runtime/local/instalacion.json` no es una instalación preparada: el lanzador informa de ello. No copiar secretos de otro equipo ni ejecutar `preparar` sobre una carpeta con datos para intentar solucionarlo.

## Reconstrucción del paquete (equipo de preparación)

Esta operación es para quien prepara la distribución, no para cada PC de destino. Se necesita un Python que ejecute biblioteca estándar, el ZIP oficial indicado en `python-runtime.lock.json` y un directorio `site-packages` con las versiones CPython 3.14 Windows x64 listadas en el mismo archivo. El constructor verifica el ZIP por SHA-256, las versiones, etiquetas de wheel y hashes RECORD; rechaza archivos de paquetes alterados y destinos existentes.

```powershell
python -B scripts/construir_runtime_python.py --python-zip RUTA\python-3.14.9-embed-amd64.zip --site-packages RUTA\Lib\site-packages
runtime\python\python.exe -B scripts\verificar_runtime_python.py
```

El constructor copia exclusivamente los 17 paquetes necesarios; no copia pip, el venv, el intérprete del usuario, herramientas de análisis de documentos ni datos de la aplicación. El resultado no necesita Internet ni instalaciones adicionales de Python en el destino. Comprobar requisitos de Windows y DLL del sistema en cada PC nueva.

Para empaquetar, comprimir la carpeta `runtime/python` conservando el nivel `python/`; registrar el SHA-256 del ZIP final y probar una extracción en otra ruta con espacios antes de entregarla. Si se cambia una dependencia, reconstruir en una carpeta nueva y repetir las comprobaciones.

## Reversión

Con la app detenida, restaurar juntos los archivos de código respaldados y su runtime compatible. La versión inicial usa `.venv`, por lo que necesita también el Python base de la instalación anterior. Este cambio no modifica esquema ni datos: no restaurar la base por una reversión exclusiva de Python.

La dirección web ya no se fija en los CMD. Abrir `Configurar Bascula.cmd` para seleccionar una IPv4 detectada o loopback y el puerto. Consultar README y el registro CAMBIO-2026-11-13-03 de SEED.MD para conservar los argumentos anteriores durante una actualización. El runtime v0.1.0-python-portable es suficiente: el formulario usa biblioteca estándar y no requiere tkinter ni paquetes adicionales.
