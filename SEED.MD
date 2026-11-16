# SEED — Guía de cambios y aplicación en producción

Fecha de apertura: 2026-11-13 (America/Tegucigalpa).
Proyecto revisado: `K:\TYTONEBASCULA_LOCAL`.
Estado: revisión inicial; ningún cambio funcional ni despliegue realizado en esta revisión.

## Propósito y regla de trabajo

Este archivo será la guía permanente para preparar y aplicar los cambios en la PC de producción. Antes de cada intervención, leerlo junto con LEEME.md y bitacoraCodex.md. Registrar aquí cada cambio realizado, sus archivos, configuración necesaria, respaldo, comprobaciones, resultado y pendientes. Cada cambio importante, aunque sea pequeño, debe tener su propio commit. Registrar el commit de implementación y subirlo al repositorio después de verificarlo. Actualizar la entrada al terminar; no presentar como aplicado un paso solamente propuesto. No registrar contraseñas, cadenas de conexión privadas ni datos personales.

La solicitud inicial autorizó analizar y crear este documento. Posteriormente el usuario autorizó preparar Python propio, modificar los lanzadores, crear el repositorio y registrar los cambios mediante commits. Las intervenciones se realizarán según lo solicitado en cada momento; esta adaptación no autoriza por sí sola cambios en la base, red o hardware de producción.

## Fuentes revisadas y alcance

- `LEEME.md`: instalación, XAMPP, lanzadores, respaldos y correcciones locales del 12 de noviembre.
- `web/README.md`: funcionamiento, permisos, pesadas, catálogos, reportes, PDF y Excel; contiene antecedentes de desarrollo.
- `bitacoraCodex.md`: historial de correcciones seriales locales.
- `dbbackup/LEEME-RESTAURACION.md`: recuperación y advertencias de los SQL.
- `.runtime/local/actualizaciones/20261112-xampp/conteos-verificados.txt`: conteos históricos de diez tablas.
- Inspección de lanzadores, inventario de app/web/scripts, pyvenv.cfg y referencias de código del lector y supervisor de servicios.
- Se identificó documentación de terceros en runtime y .venv; corresponde a dependencias, no a instrucciones propias de despliegue. No se revisó exhaustivamente ese material. El archivo privado acceso-inicial.txt no se abrió.

No se ejecutaron aplicaciones, pruebas, consultas SQL ni comandos seriales. No se comprobó la operación física ni el estado actual de servicios. Los resultados de pruebas anteriores mencionados abajo pertenecen a la documentación histórica.

## Base de funcionamiento identificada

La interfaz PHP está en `web/public`, con lógica en `web/src`; Python/FastAPI gestiona las lecturas en `app`. Hay primera y segunda pesada, captura manual, empresas y usuarios, clientes/proveedores, productos/presentaciones, taras, dashboard, boletas, reimpresión y reportes PDF/Excel. Los generadores y plantilla están en scripts. Los documentos emitidos se guardan en `__pdfs`, fuera del directorio público.

La configuración vigente documentada usa MariaDB de XAMPP en `127.0.0.1:3306`, base `dbBascula`, datadir `C:\xampp\mysql\data`. El código contempla base externa, comprueba su identidad y no la inicializa ni la detiene. FastAPI usa el puerto 8018 y la web 8088 en los lanzadores actuales. No se abrió instalacion.json para confirmar su contenido privado.

Los lanzadores leídos indican:

- Solo PC: `scripts/instalacion_local.py iniciar --abrir`, dirección documentada `http://127.0.0.1:8088/login.php`.
- Red: `iniciar --abrir --red-local 192.168.2.20/24 --puerto-web 8088`. La IP corresponde a la última configuración documentada; no se verificó que pertenezca a esta PC.

La preparación original integraba cambios de esquema 001–006. Esto no demuestra qué migraciones están aplicadas en otra base. El preparador requiere destino nuevo y no es un actualizador.

## Cambios locales existentes que deben conservarse

Según LEEME.md y bitacoraCodex.md, el 2026-11-12 se implementaron:

1. Uso de MariaDB externa de XAMPP y respaldo previo al arranque; copia histórica de la base anterior conservada.
2. Lector serial que consulta GetCommState y lee bytes disponibles sin setters, transmisión ni purga; corrección del bloqueo por stderr en PHP.
3. Comparar y actualizar la base: consulta breve del COM, compara cinco parámetros y actualiza únicamente los datos de configuración de la app; no activa lectura real.
4. Corrección de importación aislada de leer_configuracion_com.py mediante importlib.
5. Botón independiente Usar lectura real, con permisos y confirmaciones; protocolo LP7510_PC o C18=4, unidad lb y estación PRINCIPAL. La apertura del COM requiere puerto libre y tiene efectos propios del controlador.
6. Normalización en memoria de bits de parada SQL 1.0/2.0 a 1/2 en FuenteSerial, y visualización del motivo controlado de error.
7. Lanzador de red actualizado a 192.168.2.20/24 y puerto 8088.

Estos cambios están documentados y se encontraron referencias compatibles en el código inspeccionado; no constituyen certificación funcional. Hay ensayos locales en scripts/test_lector_sin_configurar.py y scripts/test_comparacion_serial.php. No se ejecutaron en esta revisión.

## Diferencias documentales y asuntos por confirmar

- El usuario indica que el sistema funciona y servirá de base para producción. LEEME.md todavía lo describe como instalación local no productiva; web/README.md conserva pendientes ya superados según la bitácora. Identificar la PC y carpeta efectivas de producción antes de una intervención.
- Permanecen referencias antiguas a MariaDB 3318, red 192.168.5.81 y puerto 80. La sección XAMPP y las entradas posteriores las sustituyen para el equipo allí documentado; no aplicar esas direcciones por copia literal.
- Los nombres históricos TYTOneBasculaLocal/TYTONEBascula no coinciden con la ruta revisada TYTONEBASCULA_LOCAL. Comprobar rutas efectivas antes de ejecutar scripts.
- `.venv/pyvenv.cfg` depende de Python 3.14 en el perfil DellUser y conserva rutas históricas de creación. No asumir que este entorno es portable ni corregirlo sin comprobar el destino.
- web/README.md enlaza guías, migraciones, pruebas y utilidades que no figuran en el inventario actual de scripts. No ejecutar instrucciones que dependan de archivos ausentes; recuperar la versión correspondiente si un cambio los requiere.
- El respaldo completo de dbbackup corresponde al 2026-11-12; no representa necesariamente las operaciones actuales. Los conteos de esa fecha son referencia histórica, no valores exigibles hoy.
- Falta confirmar en el destino acceso de red, impresora, lectura real y ciclo completo Primera/Segunda/boleta. La afirmación del usuario de que funciona se conserva; esta revisión no vuelve a probarla.

## Procedimiento para futuros cambios

1. Definir el cambio solicitado y los archivos/configuración que afecta. Identificar PC, ruta, versión y base reales; revisar diferencias locales para conservar las correcciones seriales.
2. Preparar y registrar respaldo reciente de la base, archivos afectados y configuración privada. Incluir __pdfs cuando corresponda. Guardar los secretos de forma privada; registrar aquí solo su ubicación. No sobrescribir datos con el esquema de instalación ni con respaldos históricos.
3. Preparar el cambio y comprobaciones apropiadas fuera de la operación de pesaje. Si requiere SQL, documentar el SQL exacto, condiciones previas, permisos y recuperación antes de aplicarlo. No marcar migraciones ficticiamente.
4. En la intervención autorizada, coordinar la pausa de operaciones y detener únicamente los componentes necesarios de la app. Con base externa, no detener XAMPP ni procesos ajenos por rutina.
5. Aplicar solo archivos y ajustes descritos. Revisar Python, extensiones PHP, proc_open, generadores, plantilla Excel, carpetas privadas y permisos cuando el cambio los afecte. No copiar rutas, IP ni parámetros COM de otra PC sin verificar.
6. Verificar arranque y respaldo, login, funciones afectadas y conservación de datos. Si afecta pesadas, comprobar cálculos, persistencia, boleta y reportes con datos de prueba acordados. Una prueba física requiere puerto libre y coordinación; no competir con el programa anterior ni cambiar parámetros del indicador como efecto incidental.
7. Registrar resultados, errores, pendientes y forma concreta de reversión. Restaurar código y configuración solo con copias compatibles; una reversión de base requiere considerar las operaciones nuevas, no importar ciegamente un dump anterior.

## Plantilla obligatoria por intervención

### CAMBIO-AAAA-MM-DD-NN — Título

- Estado: propuesto / preparado / aplicado / verificado / revertido / pendiente.
- Solicitud y alcance autorizado:
- Equipo, carpeta y versión origen/destino:
- Problema y resultado esperado:
- Archivos modificados y descripción:
- Respaldo previo y ubicación:
- Cambios de base y permisos SQL, o «no requiere»:
- Configuración necesaria, valores sin secretos y si exige reinicio:
- Pasos/comandos exactos aplicados y orden:
- Pruebas ejecutadas y resultados observados:
- Validación del usuario y/o hardware pendiente:
- Reversión concreta y sus límites:
- Resultado final y siguientes pasos:

## Repositorio y trazabilidad para implementar

- Repositorio privado: https://github.com/luisteruelhn/TYTONEBASCULALocal.
- Rama de referencia: `main`.
- Copia de trabajo de desarrollo: `C:\Users\luist\OneDrive\Documents\ChatGPT\_BASCULA_`.
- Primer commit: `7a43680` — base existente de la aplicación, anterior al runtime Python propio.
- La carpeta original `K:\TYTONEBASCULA_LOCAL` no se actualiza automáticamente al cambiar este repositorio. El usuario confirmó que la aplicación activa está en otra PC; no modificar esa instalación durante la preparación local.
- Cada cambio importante, aunque sea pequeño, requiere un commit propio y un registro aquí con resultado de pruebas. Subir los commits al repositorio. Los commits posteriores de documentación referencian el hash ya existente de la implementación; el hash de la propia documentación se consulta con `git log -1 -- SEED.MD`.
- Git conserva código, documentación, esquema y plantilla Excel. No conserva `.runtime`, `.venv`, `runtime`, respaldos, documentos emitidos ni credenciales. Un clon no equivale a una instalación completa.

Antes de implementar: consultar `git log --oneline`, identificar el commit aprobado de código y descargar el paquete de runtime correspondiente. Comparar SHA-256 del paquete, verificar su contenido y guardar juntos respaldo de los archivos originales, hash de código de destino y configuración privada. Nunca usar `git clean`, `reset --hard` o una copia con borrado sobre una instalación con datos para aplicar una actualización.

La PC de producción puede recibir los archivos del commit aprobado sin instalar Git. Mantener sus ajustes propios de base, red y COM. Registrar en la entrada de implementación qué commit se aplicó realmente, en qué PC/carpeta, qué respaldo se creó y qué pruebas se completaron. Una preparación local no se marca como despliegue verificado en producción.

## Registro de intervenciones

### REV-2026-11-13-01 — Revisión inicial y creación de SEED.MD

- Estado: análisis documental realizado; guía creada.
- Solicitud: revisar el proyecto existente y preparar registro para cambios futuros en producción.
- Archivos funcionales modificados: ninguno.
- Base, configuración, servicios, red y COM: sin cambios.
- Comprobaciones: lectura documental, inventario y contraste limitado con código y lanzadores. Sin pruebas de ejecución.
- Pendiente: instrucciones del usuario para el primer cambio y comprobación del entorno de destino cuando corresponda.

### PRUEBA-2026-11-13-01 — Comprobación de la copia local

- Estado: comprobación parcial; prueba operativa completa pendiente.
- Contexto confirmado por el usuario: la aplicación está activa en otra PC. Las comprobaciones se hicieron sobre los archivos de K:\TYTONEBASCULA_LOCAL accesibles aquí; no se intervino la PC activa.
- PHP disponible: 8.5.12. Python disponible: 3.13.15.
- Ejecutado: php scripts/test_comparacion_serial.php. Resultado correcto en coincidencias, diferencias, preservación del protocolo, datos incompletos/no representables y condiciones para activar lectura real. Sin base ni COM real.
- Ejecutado: php -l sobre los 31 archivos PHP de web y scripts. Todos correctos.
- Ejecutado: análisis ast.parse de los 18 archivos Python de app y scripts. Todos con sintaxis correcta; sin generar bytecode.
- Intento de ejecutar .venv/Scripts/python.exe: bloqueado porque no existe C:\Users\DellUser\AppData\Local\Programs\Python\Python314\python.exe en esta PC.
- Intento con Python 3.13 disponible: scripts/test_lector_sin_configurar.py no llegó a ejecutar sus pruebas por ModuleNotFoundError de pydantic. No se instalaron paquetes ni se cambió el entorno.
- Archivos funcionales/configuración/base: sin cambios. No se iniciaron servicios, no se guardaron pesadas ni se abrió COM. Se actualizó únicamente este registro documental.
- Pendiente: preparar un entorno de pruebas compatible separado o ejecutar las comprobaciones en el equipo que tenga el entorno correcto. Login, lectura, ciclo de pesadas, PDF/Excel e impresión no quedaron validados por estas pruebas.
- Reversión: no requiere reversión funcional; esta entrada documenta los resultados.

### CAMBIO-2026-11-13-01 — Base inicial del repositorio

- Estado: preparación del primer commit del código existente, antes de adaptar Python.
- Repositorio privado: https://github.com/luisteruelhn/TYTONEBASCULALocal.
- Copia de trabajo versionada: C:\Users\luist\OneDrive\Documents\ChatGPT\_BASCULA_. Origen leído: K:\TYTONEBASCULA_LOCAL.
- Incluye app, web, scripts, plantilla Excel, lanzadores y documentación. Código funcional conservado sin cambios.
- Agregados README.md y .gitignore. Se excluyen .runtime, .venv, runtime, dbbackup, __pdfs, configuración privada y archivos generados.
- No se copiaron credenciales ni datos operativos. Revisión de patrones de tokens y claves privadas sin coincidencias. El SQL incluido es el esquema del proyecto, no un dump operativo.
- Validación funcional: se conservan los resultados de PRUEBA-2026-11-13-01; no se repitieron pruebas por esta copia documental y de código sin cambios.
- Sin cambios de base, servicios, red o COM. Sin intervención en la PC activa.
- Pendiente autorizado: preparar runtime Python propio y actualizar referencias en un cambio posterior.
- Reversión: esta copia versionada no sustituye ni modifica la instalación original.

### CAMBIO-2026-11-13-02 — Python propio y lanzadores sin dependencia del usuario

- Estado: preparado y verificado localmente; pendiente de implementar en la PC activa.
- Autorización: incorporar runtime privado, ajustar lanzadores y referencias internas; registrar cambios importantes mediante commits y documentar repositorio/implementación aquí.
- Python: CPython 3.14.9 embebible Windows x64, descargado de https://www.python.org/ftp/python/3.14.9/python-3.14.9-embed-amd64.zip. SHA-256 del ZIP oficial: `3c01d7cfc666f746a6f064f60fb2156ab0ec464d3d738ab796371a46a9bb24a2`.
- Paquetes: 17 dependencias necesarias conservadas en las versiones existentes, con etiquetas CPython 3.14/Windows x64 o Python puro, verificadas contra los hashes RECORD de 836 archivos. No se copiaron las herramientas de análisis de documentos ni pip. Versiones en python-runtime.lock.json; runtime completo con manifiesto de integridad de 890 archivos.
- Archivos: los tres lanzadores CMD; scripts/instalacion_local.py; web/src/reportes.php; web/src/serial.php; web/public/serial_prueba.php. Todos usan runtime/python/python.exe. Agregados constructor, verificador, lock y PYTHON_PORTABLE.md; README actualizado.
- El preparador copia el runtime completo, los lectores seriales y sus pruebas; usa los binarios propios PHP/MariaDB en runtime y la documentación existente, sin crear venv ni depender de archivos ausentes de desarrollo. El preparador no se ejecutó contra una base.
- Configuración _pth: rutas relativas a biblioteca estándar, site-packages, raíz del proyecto y scripts; sin import site. No requiere PATH, registro de Python, perfil de usuario, instalación global ni pyvenv.cfg.
- Paquete de entrega: `.runtime/dist/runtime-python-3.14.9-windows-x64.zip`, 25,288,114 bytes. SHA-256: `7826a0beea0eed5c7e51db384e75cc5539e8e8f174bcc45bd54740bd43eb50c5`. Contiene python/ para extraer dentro de runtime/.
- Verificación correcta: integridad y versiones; ocho pruebas seriales con kernel32 simulado; API HTTP simulada temporal en loopback y cierre de su proceso; generadores PDF/Excel por stdin/stdout; consultas seriales con argumento INVALIDO rechazado antes de acceder al hardware; PHP proc_open para PDF, Excel y consulta aislada; sintaxis PHP y Python y comparación serial PHP.
- Portabilidad correcta: paquete extraído en `.runtime/Prueba portable con espacios`, ejecutado desde un cwd externo, con PYTHONHOME/PYTHONPATH inválidos y PATH restringido a System32. Se repitió la comprobación completa. PHP proc_open también pasó en la ruta con espacios.
- El CMD de la copia trasladada ejecutó el supervisor y rechazó correctamente la ausencia de instalacion.json: no se creó ni copió una configuración privada para el ensayo.
- Limitaciones: no se validaron login con base, lectura física, pesadas reales ni impresión. No se ejecutó el preparador ni se reiniciaron servicios de la instalación existente. No se tocaron K:\TYTONEBASCULA_LOCAL ni la PC activa.
- Reversión: restaurar juntos el código anterior respaldado y su entorno anterior; conservar .venv hasta validar el cambio en destino. Esta adaptación no requiere restaurar base ni migración SQL.

#### Pasos de implementación de este cambio en producción

1. Identificar PC, carpeta y base efectivas; acordar pausa con el operador. Leer PYTHON_PORTABLE.md. Identificar el commit de implementación y paquete registrados abajo; no asumir que main permanecerá en la misma versión.
2. Detener exclusivamente la app usando el lanzador actual; confirmar cierre de sus procesos y liberación del COM. Si usa XAMPP externo, conservarlo activo. Crear respaldo reciente de base y archivos afectados/configuración privada; registrar ubicación y resultado. Conservar PDF y datos actuales.
3. Guardar copia de los tres CMD, scripts/instalacion_local.py, web/src/reportes.php, web/src/serial.php y web/public/serial_prueba.php antes de reemplazarlos. Guardar también runtime/python si ya existe. No mezclar runtimes ni borrar .venv.
4. Descargar el paquete del repositorio privado y cotejar SHA-256 con Get-FileHash -Algorithm SHA256. Extraer python/ en runtime/ del destino. Copiar los archivos de código de este commit y sus documentos/lock/pruebas. Mantener esquema SQL, configuración privada, php.ini, IP y parámetros COM propios del destino. No ejecutar inicializar ni preparar para aplicar esta actualización.
5. Ejecutar desde la raíz: runtime\python\python.exe -B scripts\verificar_runtime_python.py. Exigir resultado correcto. La prueba crea y cierra una API simulada temporal, no inicia la app permanente ni accede a la base/COM.
6. Arrancar con el CMD apropiado del destino conservando sus argumentos de red originales. Revisar respaldo automático, login, API y lectura de pantalla. Coordinar prueba física con el operador, comprobar PDF/Excel e impresión. Registrar qué se probó y cuáles operaciones quedaron pendientes.
7. Completar una nueva entrada aquí con PC/carpeta, commit realmente aplicado, hash del paquete, respaldo, configuración conservada/modificada, resultados y aceptación. Solo entonces marcar implementado/verificado en producción.

#### Referencias exactas del cambio Python

- Commit de implementación verificado: `4ad4a52b82d763207d564fbbad1a1356afb0c0b9` — https://github.com/luisteruelhn/TYTONEBASCULALocal/commit/4ad4a52b82d763207d564fbbad1a1356afb0c0b9.
- Base anterior: `7a43680` (primer commit). No mezclar el código de esta base con la adaptación sin revisar las referencias a .venv.
- Paquete publicado y verificado: tag `v0.1.0-python-portable`, apuntando al commit de implementación. Versión: https://github.com/luisteruelhn/TYTONEBASCULALocal/releases/tag/v0.1.0-python-portable. Descarga autenticada: https://github.com/luisteruelhn/TYTONEBASCULALocal/releases/download/v0.1.0-python-portable/runtime-python-3.14.9-windows-x64.zip. GitHub confirmó estado uploaded, 25,288,114 bytes y el mismo SHA-256 registrado aquí.
- SHA-256 de entrega: `7826a0beea0eed5c7e51db384e75cc5539e8e8f174bcc45bd54740bd43eb50c5`.
- Este registro de referencias se guarda en un commit documental posterior al de implementación. Consultar `git log -1 -- SEED.MD` para identificar el commit documental que contiene la guía vigente.

### DOC-2026-11-13-01 — Referencias de implementación y distribución

- Estado: repositorio y paquete de runtime publicados; documentación de implementación completada.
- Referencia de código: 4ad4a52b82d763207d564fbbad1a1356afb0c0b9. Este commit documental agrega el hash definitivo y la descarga verificada sin modificar código funcional.
- Incluye política de un commit por cambio importante, identificación del repositorio privado/main, primer commit, contenido excluido de Git y pasos de respaldo, aplicación, comprobación y reversión.
- No se repitieron pruebas funcionales por este cambio exclusivamente documental. No se intervino producción.

### CAMBIO-2026-11-13-03 — Configurador privado de IP y puerto web

- Estado: implementado y probado en desarrollo; pendiente de despliegue y aceptación en producción. Copia: `C:\Users\luist\OneDrive\Documents\ChatGPT\_BASCULA_`, main, base documental `d2e4182`, implementación Python previa `4ad4a52`. Árbol limpio al comenzar; sin diferencias respecto al estado esperado. No se intervino la PC activa.
- Alcance: configurar solo la escucha HTTP. Sin cambios de esquema, permisos SQL, base, COM, Windows, firewall, router o DNS. MariaDB/FastAPI conservan loopback; BASCULA_HTTP_RED_LOCAL conserva la subred privada seleccionada y las restricciones PHP existentes.
- Archivos: nuevo `scripts/configurar_web.py`, `Configurar Bascula.cmd`, pruebas `scripts/test_configurar_web.py`; supervisor y dos lanzadores de inicio actualizados. README/PYTHON_PORTABLE describen operación. El preparador para destinos nuevos distribuye el módulo/CMD y guarda sus argumentos de red/puerto en el archivo privado; no se ejecutó preparar ni inicializar.
- Tecnología comprobada: el runtime embebible no incluye tkinter. Formulario con biblioteca estándar, servidor temporal ligado únicamente a 127.0.0.1 y puerto aleatorio; ruta/token aleatorio, Host/Origin y origen de navegación comprobados, sin caché, sin marcos. Conexiones con timeout; cierre al guardar/cancelar, Ctrl+C o vencimiento a cinco minutos. Cerrar solo la pestaña no termina inmediatamente el proceso; usar Cancelar o esperar vencimiento.
- Configuración: `.runtime/local/web-listen.json`, JSON con `interface` (127.0.0.1 o IPv4/prefijo real) y `port` entero. Excluido por `.runtime/`; no contiene credenciales. Guardado atómico, copia anterior con fecha y sufijo aleatorio `.bak`. No modifica instalacion.json. Sin archivo, inicio normal bloquea e indica configurar; solo PC conserva el puerto de instalacion.json hasta crear el archivo. Con archivo, solo PC fuerza loopback y conserva el puerto guardado. Argumentos explícitos técnicos tienen prioridad por ejecución.
- Detección: Get-NetIPAddress de solo lectura, direcciones IPv4 Preferred, prefijo e interfaz reales; solo rangos RFC1918 y direcciones de host válidas. Se propone automáticamente la única IPv4 privada cuando no hay ajustes previos; varias requieren selección. Se recomienda IP estática o reserva DHCP, sin aplicarla desde la app. No se puede deducir cuál interfaz usan los otros equipos ni garantizar conectividad/firewall desde este formulario.
- Validación: IP/subred debe seguir disponible al guardar y al iniciar en red; puerto 1–65535 distinto de DB/API y enlace disponible. Puerto ocupado o restringido se rechaza; no se mata, sustituye ni reinicia ningún proceso. Si la app ya usa ese puerto, cerrar mediante su lanzador o escoger otro antes de guardar. La app abierta conserva su configuración hasta reiniciarse por el operador; no se reinicia automáticamente. La comprobación del puerto es puntual; el supervisor vuelve a comprobarlo al arrancar.
- Pruebas: `runtime\python\python.exe -B scripts\test_configurar_web.py`: ocho pruebas correctas (IP/subred/puerto, puerto ocupado con proceso conservado, migración/respaldos/configuración, conexión de ambos lanzadores con servicios simulados y bloqueo sin archivo, filtrado de interfaces, HTTP guardar/cancelar/vencimiento, rechazo Host/Origin/token ajenos y liberación del puerto). Sintaxis AST de los tres Python y `git diff --check` correctos. Detección real de interfaces correcta (cinco IPv4 privadas, sin registrar direcciones privadas aquí). HTTP y consulta Windows bloqueados en sandbox; pruebas repetidas correctamente con ejecución autorizada fuera del sandbox. Runtime privado existente sin cambios.
- Límites: no se inició la aplicación con base, ni se abrió COM, ni se verificó acceso físico desde otro equipo, login o firewall de producción. No se alteró configuración privada real en desarrollo; pruebas guardaron únicamente en carpetas temporales eliminadas al terminar.

#### Aplicación posterior en producción y traslado de ajustes

1. Identificar PC/carpeta y commit aprobado. Coordinar pausa. Conservar XAMPP externo; detener solamente la app con su lanzador cuando corresponda. Registrar respaldo reciente de base según el procedimiento habitual y respaldar archivos afectados, los CMD **originales**, instalacion.json y, si existe, web-listen.json; guardar fuera de archivos que se sustituyen. Conservar datos/PDF/COM y runtime. No ejecutar preparar/inicializar ni importar SQL.
2. Antes de reemplazar el CMD de red, respaldarlo por ejemplo como `Iniciar Bascula anterior.cmd` dentro de la carpeta privada de respaldo. Revisar sus argumentos si fue personalizado: el importador reconoce `--red-local IPv4/prefijo` y `--puerto-web entero` literales del lanzador conocido; no interpreta variables CMD ni ejecuta el archivo. Si usa variables u otra sintaxis, anotar los valores efectivos y elegirlos en el formulario. Sin argumento de puerto se propone el puerto web de instalacion.json. No copiar IP de esta PC por defecto.
3. Copiar únicamente los archivos del cambio indicado arriba y documentación. Mantener runtime/python v0.1.0-python-portable y toda configuración/datos existentes. Si ya hay web-listen.json válido, el configurador lo conserva como propuesta prioritaria; no lo reemplaza automáticamente.
4. Para trasladar los argumentos anteriores sin editar archivos, ejecutar desde la raíz (sustituir la ruta por la del CMD respaldado): `"Configurar Bascula.cmd" --importar-lanzador "RUTA\Iniciar Bascula anterior.cmd"`. El formulario propone esos valores; si esa IP/subred ya no está disponible, elegir una detectada válida. Verificar modo, puerto y URL antes de Guardar. Sin configuración anterior, abrir normalmente Configurar Bascula.cmd. El guardado crea el nuevo archivo, no reinicia la app. Si el puerto está ocupado, no detener procesos ajenos; elegir otro o coordinar el cierre de la app propia.
5. Abrir el lanzador apropiado. Comprobar URL, respaldo de arranque y login. En red, probar desde un cliente de la subred y confirmar las restricciones existentes; si falla, revisar con el responsable de red sin cambiar automáticamente firewall/DNS. Comprobar Solo PC y confirmar que DB/API siguen locales. No hace falta alterar datos para verificar este cambio. Registrar equipo, carpeta, commit, respaldo, modo/puerto elegido y pruebas efectivas en una nueva entrada.
6. Reversión: con la app propia detenida, restaurar supervisor y CMD originales respaldados juntos (incluidos sus argumentos de red), conservar el runtime Python anterior compatible y apartar el nuevo configurador si se desea. Restaurar web-listen.json desde su copia anterior para revertir solo ajustes. No restaurar base por un cambio exclusivo de escucha; no borrar datos ni usar reset/clean sobre producción.

- Respaldo de desarrollo: estado anterior recuperable desde `d2e4182`/Git para archivos versionados; no se modificaron datos ni configuración de instalación. Los respaldos reales de producción se crearán y registrarán en la intervención, no se dan por hechos aquí.
- Commit de implementación probado: `ba5199375700bdda8e1ffc2630a75429b30b67f8` — https://github.com/luisteruelhn/TYTONEBASCULALocal/commit/ba5199375700bdda8e1ffc2630a75429b30b67f8. Este registro definitivo se agrega en un commit documental posterior. Runtime requerido: versión privada `v0.1.0-python-portable`, sin cambios de paquete.

### DOC-2026-11-13-02 — Referencia definitiva del configurador web

- Estado: guía de implementación posterior completada; producción sin intervenir.
- Referencia funcional: `ba5199375700bdda8e1ffc2630a75429b30b67f8`. Se agrega únicamente el hash definitivo a CAMBIO-2026-11-13-03; se conservan las ocho pruebas correctas allí registradas, sin repetirlas por este ajuste documental.
- Para identificar este commit documental: `git log -1 -- SEED.MD`.

### APLICACION-2026-11-13-01 — Carpeta de trabajo K y actualización autorizada

- Estado: código y runtime aplicados y comprobados en `K:\TYTOneBascula_Local` por solicitud expresa del usuario. Esta pasa a ser la carpeta principal de trabajo del repositorio privado, rama main. La copia de C:\Users\luist\OneDrive\Documents\ChatGPT\_BASCULA_ fue el entorno de preparación anterior; no confundirlas en intervenciones futuras. La PC activa indicada previamente por el usuario está en otro equipo y no fue intervenida.
- Origen funcional: `ba5199375700bdda8e1ffc2630a75429b30b67f8`, guía `e6355b41d58d842e18e69fcd295a386b6ecda294`. Las diferencias funcionales de K se cotejaron con `7a43680`: eran exactamente la base anterior a Python privado, sin modificaciones locales adicionales en los siete archivos funcionales sustituidos.
- Respaldo efectivo: `K:\TYTOneBascula_Local\.runtime\local\actualizaciones\20261113-configurador-131235`. Contiene los ocho archivos originales reemplazados y `aplicacion.json` con lista de sustituidos/agregados. No se hizo respaldo nuevo de base porque no se ejecutó ni consultó la aplicación/base; no se presenta este paso como validación operativa de producción.
- Aplicado: archivos versionados faltantes/actualizados, Python privado completo (antes ausente) desde el runtime probado de desarrollo, sin mezclar runtimes. Conservados `.venv`, runtime PHP/MariaDB, datos, PDF y configuración privada existente. No se ejecutó preparar, inicializar, detener ni iniciar; sin SQL ni acceso COM.
- Migración de configuración: IP/prefijo y puerto del CMD original trasladados a `.runtime/local/web-listen.json` sin cambiar sus valores. El archivo queda privado y excluido de Git. El arranque comprobará que esa IP/subred siga disponible; si no, usar Configurar Bascula.cmd. No se cambiaron red, firewall, DNS, DB ni COM.
- Pruebas en K: verificar_runtime_python.py correcto (integridad/versiones, ocho pruebas seriales simuladas, API temporal y cierre, PDF/Excel sin documentos guardados, carga del supervisor); test_configurar_web.py, ocho pruebas correctas. No se validaron login, base, lectura física ni clientes de red. Pendiente del operador iniciar y comprobar funciones en esta carpeta cuando corresponda.
- Reversión: cerrar primero solo la app de esta carpeta si se hubiera iniciado; restaurar desde el respaldo los archivos listados como replaced en aplicacion.json. Apartar los agregados listados, runtime/python y web-listen.json si se vuelve a la versión original. Conservar `.venv` y su Python base compatible; no restaurar ni borrar base/PDF/configuraciones ajenas a este cambio. Los archivos nuevos pueden conservarse apartados para diagnóstico.
- Git: se crea metadata propia en K y se vincula a origin/main sin checkout ni limpieza de archivos. La documentación de esta aplicación se registra en un commit propio y se sube al mismo repositorio. Consultar `git log -1 -- SEED.MD` para su hash.


### CORRECCION-2026-11-13-01 — Identidad de carpeta local

- Estado: corregido y verificado en `K:\TYTOneBascula_Local`, por error de arranque reportado por el usuario. Base de código `090d5a2`; sin cambios funcionales de código.
- Causa: `.runtime/local/instalacion.json` conservaba root `K:\TYTOneBasculaLocal`, sin el guion bajo de la carpeta actual confirmada por el usuario.
- Respaldo privado efectivo: `K:\TYTOneBascula_Local\.runtime\local\actualizaciones\20261113-identidad-131832\instalacion.json`. Contiene configuración privada; no subir ni compartir.
- Aplicación: reemplazo atómico únicamente del campo root por la carpeta actual. Comparación estructural confirma todos los otros ajustes intactos. No se debilitó ni eliminó la comprobación de identidad.
- Prueba: constructor Installation ejecutado correctamente desde el Python privado de K, sin procesos ni registros de servicios iniciados. No se ejecutó iniciar/preparar/inicializar, no se consultó base ni COM. Login y operación quedan pendientes de abrir la app por el operador.
- SQL/red/permisos: no requiere; sin cambios. Reversión: con la app propia cerrada, restaurar el archivo privado respaldado si se necesita deshacer el ajuste; la ruta anterior volverá a bloquear el inicio en esta carpeta. No restaurar base por esta corrección.
- Configuración privada excluida de Git; este cambio local se registra mediante commit documental. Consultar `git log -1 -- SEED.MD` para su hash.


### CAMBIO-2026-11-13-04 — Carpeta de instalación desde el configurador

- Estado: implementado y probado en `K:\TYTOneBascula_Local`, main, base `6e09b49`; producción remota sin intervenir. Solicitud: personalizar la carpeta de instalación desde configuración de arranque.
- Archivos: scripts/configurar_web.py, scripts/test_configurar_web.py y README.md. El formulario muestra ruta detectada/registrada y campo editable con acción independiente Guardar carpeta y cerrar. Conserva el control de identidad del supervisor.
- Comportamiento: guarda únicamente root de instalacion.json, conserva todos los otros ajustes, crea respaldo privado instalacion.json.FECHA-SUFIJO.bak y reemplaza atómicamente. Acepta ruta absoluta existente que identifica esta misma carpeta y contiene supervisor, Python privado y login. Rechaza carpetas ajenas, rutas relativas/incompletas y app abierta mediante el bloqueo de ejecución; no detiene ni reinicia procesos. No permite redirigir una instalación hacia los datos de otra.
- Personalización/traslado: con la app cerrada, mover o copiar manualmente la instalación completa a la ubicación elegida, abrir allí Configurar Bascula.cmd y guardar su carpeta detectada. Revisar por separado identidad/ruta del datadir, php.ini y demás ajustes de traslado descritos en PYTHON_PORTABLE.md; este formulario no mueve archivos ni cambia esas rutas. El apartado funciona aunque falle la detección de interfaces o esté ocupado el puerto web; IP/puerto conservan su guardado independiente.
- Respaldo de código previo efectivo: `K:\TYTOneBascula_Local\.runtime\local\actualizaciones\20261113-carpeta-formulario-131941`, incluye configurador, pruebas, README y SEED anteriores. No se cambió la configuración privada real para estas pruebas; no se respaldó ni intervino base.
- Pruebas: runtime/python/python.exe -B scripts/test_configurar_web.py, diez pruebas correctas. Incluyen ruta absoluta/ajena/incompleta, bloqueo con app abierta, respaldo y preservación de otros campos, guardado HTTP de carpeta y las ocho pruebas previas de red/configuración/lanzadores. Se corrigió un PermissionError al leer un byte bloqueado detectado por la nueva prueba; consulta de tamaño del archivo evita esa lectura. Pruebas HTTP dan margen de 15 segundos para operaciones; vencimiento se ensaya aparte a tres segundos. AST correcto de los dos archivos Python y git diff --check correcto. Sin SQL/COM ni servicios de app reales.
- Implementación posterior: respaldar y sustituir los dos Python y README de este cambio (runtime sin cambios); no ejecutar preparar/inicializar. Abrir el configurador en la instalación destino y guardar carpeta solo si necesita corregirse. Probar inicio/login con el operador y registrar aceptación; no se realizó aquí.
- Reversión de código: restaurar los archivos respaldados con el configurador cerrado. Para revertir un ajuste de carpeta hecho por el operador, cerrar la app y restaurar instalacion.json desde la copia privada correspondiente; la ruta debe coincidir con la ubicación real para volver a arrancar. Sin migraciones SQL, cambios de firewall, IP de Windows ni COM. No restaurar base por este cambio.
- Commit de implementación probado: `5e388979bacebbaaeccb0ec8500fc9720a8e5f5b`. Referencia agregada mediante commit documental posterior; no requiere repetir pruebas por este registro de hash. Consultar `git log -1 -- SEED.MD` para el commit de documentación.


### CAMBIO-2026-11-13-05 — Escucha automática del COM con web disponible

- Estado: implementado y probado aisladamente en `K:\TYTOneBascula_Local`, main, base `4b539c9`. Solicitud: escuchar el COM seleccionado al lanzar la app y dar retroalimentación sin impedir cargar la página si el COM falla. Producción remota sin intervenir.
- Archivos: app/main.py (ciclo de vida), app/services/fuente.py (monitor, escucha continua y reintento), scripts/instalacion_local.py (publicación previa con fallo no fatal), nuevo scripts/publicar_fuentes_arranque.php (solo CLI, SELECT de estaciones PRINCIPAL y publicación privada), nuevo scripts/test_arranque_serial.py, README y este registro. Preparaciones nuevas incluyen el publicador; no se ejecutó preparar/inicializar.
- Respaldo efectivo de archivos existentes: `K:\TYTOneBascula_Local\.runtime\local\actualizaciones\20261113-auto-com-134526`. Conserva main.py, fuente.py, supervisor, README y SEED anteriores. Archivos nuevos identificados arriba; no había versiones previas que respaldar. No se modificó configuración privada real ni SQL en las pruebas.
- Configuración: usa la existente por empresa, estación PRINCIPAL, modo SERIAL y parametros_confirmados=1; no cambia automáticamente SIMULADO a SERIAL ni confirma parámetros. Mantiene validación de protocolo LP7510_PC/C18=4 y unidad lb y normalización de parada decimal. No modifica configuración del COM ni transmite datos; abrir el puerto tiene efectos propios del controlador.
- Arranque: después del respaldo habitual de base, el supervisor ejecuta PHP CLI con las mismas credenciales/entorno web para publicar la configuración actual mediante SELECT; retira archivos numéricos de empresas ya ausentes. No usa configuración publicada antigua para escucha automática si falla esta preparación: avisa y fija BASCULA_SERIAL_AUTO=0 para ese arranque. La web sigue iniciando y las consultas autenticadas pueden publicar la configuración actual como antes. No se eliminan comprobaciones de identidad, base ni respaldo obligatorio.
- API: monitor en segundo plano, revisa fuentes cada 0.5 segundos, inicia sin petición web, mantiene el COM sin cierre por inactividad de seis segundos; cierra lectores al cambiar de configuración, retirar fuente o terminar API. Fallos de un lector no detienen la API. Reintenta fuentes fallidas a partir de cinco segundos de su creación, sin reapertura en cada petición. Nunca cierra procesos ajenos ni convierte errores seriales en peso simulado.
- Retroalimentación: conserva la cadena API 503 -> lectura.php -> app.js, que muestra Sin lectura y detalle (puerto ocupado/desconectado, espera de datos, datos vencidos/formato inválido). La comprobación de salud HTTP es independiente del hardware; el navegador abre aunque no haya lectura. No se altera captura manual ni validación de pesadas.
- Pruebas: cinco pruebas nuevas con lector simulado (inicio sin consulta y sin cierre por inactividad; COM ocupado con detalle, ausencia de reaperturas inmediatas y reintento; salud ASGI HTTP 200 con COM fallido; SIMULADO/no confirmado/archivo inválido sin abrir COM; muestra válida y cambio a Simulado con cierre; fallo del publicador solo avisa). Ocho pruebas previas del lector con kernel32 simulado correctas. Sintaxis PHP del publicador correcta; AST y diff --check correctos. Sin acceso a COM, SQL real, app permanente ni datos de operación. La publicación contra base real y el ciclo físico quedan pendientes de aceptación con operador.
- Implementación posterior: con app propia cerrada, respaldar y copiar los archivos funcionales de este cambio y documentación; runtime/configuración/base sin cambios. No ejecutar preparar/inicializar ni migraciones SQL. Verificar primero COM libre y modo real confirmado; iniciar, confirmar página disponible sin datos y detalle, luego recepción física y cierre limpio al detener; coordinar con operador y no competir con otro programa. Registrar resultados y respaldo reales en destino.
- Reversión: detener solamente la app propia y restaurar main.py, fuente.py y supervisor desde respaldo, apartar publicador/prueba nuevos si se desea; conservar archivos privados, datos y runtime. La versión previa vuelve a iniciar lectura bajo petición y cerrar por inactividad. No restaurar base por este cambio; no modifica esquema/permisos, firewall ni red.
- Commit de implementación probado: `62f33044bc3a0aa09df4af3f6eefbdc8ff85240a`. Referencia fijada en commit documental posterior; consultar `git log -1 -- SEED.MD`. También se ejecutaron las diez pruebas de test_configurar_web.py, correctas, tras el cambio del supervisor.


### CAMBIO-2026-11-13-06 — Continuar o reiniciar desde el mismo iniciador

- Estado: implementado y probado en `K:\TYTOneBascula_Local`, main, base `7569c53`. Solicitud final: al segundo lanzamiento ofrecer continuar con la instancia abierta o reiniciarla, sin otro CMD. Producción remota sin intervenir.
- Archivos: scripts/instalacion_local.py, nueva prueba scripts/test_lanzador_existente.py, README y este registro. No se agregó otro lanzador. Respaldo de supervisor/README/SEED anterior: `K:\TYTOneBascula_Local\.runtime\local\actualizaciones\20261113-reabrir-135221`. No se modificaron configuraciones privadas reales ni servicios.
- Detección: bloqueo de ejecución de esta instalación antes de validar los ajustes para una instancia nueva; el archivo de estado por sí solo no basta. Se evita leer el byte bloqueado usando tamaño del archivo. Estado privado servidor-listo.json con carpeta, URL activa y puerto API, publicado tras comprobar salud y login, retirado al entrar/cerrar incluso si el cierre falla. Excluido de Git por .runtime. URL limitada a IPv4 loopback/RFC1918 y puerto válido; Continuar comprueba API y login sin proxy antes de abrir navegador. No cambia la configuración activa ni crea servicios/lectores nuevos.
- Menú en los dos iniciadores existentes cuando la instalación ya está abierta: C Continuar (predeterminado), R Reiniciar o S Salir. Sin stdin se toma C; Ctrl+C sale sin detener la instancia. Sin --abrir no hay menú interactivo. Si no hay estado válido o la instancia no responde, informa que sigue iniciando/cerrando o sin respuesta, sin abrir un destino arbitrario ni forzar un reinicio. Una instancia anterior a este cambio necesita cerrar/reabrir o elegir R para publicar estado nuevo.
- Reinicio voluntario: acción técnica reiniciar usa el marcador detener de la misma carpeta y espera liberación del bloqueo hasta 45 segundos. No termina procesos por fuerza desde la segunda invocación. Luego aplica el inicio normal con sus comprobaciones de puertos, identidad, respaldo y configuración del iniciador elegido; conserva --solo-pc o argumentos explícitos. La salida de servicios sigue el cierre existente del supervisor (solo procesos propios, XAMPP externo conservado). Un timeout no inicia otra instancia ni fuerza cierre; la solicitud detener permanece. Si hay procesos remanentes/puertos ocupados, se conserva el rechazo normal de arranque.
- Configuración necesaria: ninguna; runtime existente compatible. Continuar usa URL realmente activa incluso si se abre Solo PC o los ajustes guardados cambiaron. Solo un arranque nuevo aplica esos cambios. El reinicio interrumpe lectura y sesiones, por lo que el operador debe coordinarlo fuera de una pesada activa. No se valida ni captura peso como efecto del menú.
- Pruebas: nueve pruebas nuevas aisladas (bloqueo real simulado de archivo sin servicios, continuar sin configuración nueva, salir/opción inválida, argumentos de reinicio, URL activa y salud, estado ausente/corrupto/externo, servicio sin respuesta, espera/timeout sin forzar procesos, orden de nuevo arranque y limpieza). Diez pruebas previas de configurador/lanzadores correctas; AST y git diff --check correctos. No se abrió navegador real, COM ni base, ni se detuvo/reinició la aplicación real. Prueba de menú/continuidad física con operador pendiente.
- Implementación posterior: con app propia cerrada y archivos respaldados, sustituir supervisor y documentación (prueba opcional para verificar); no preparar/inicializar ni cambiar SQL/configuración de base. Iniciar una vez con el supervisor nuevo; segunda apertura C debe conservar servicio/COM y abrir la URL, S no debe alterar servicios. Probar R únicamente en pausa coordinada y comprobar respaldo/login/lectura al finalizar. Registrar equipo, respaldo y resultados reales.
- Reversión: cerrar solo la app propia, restaurar supervisor respaldado y documentación si se desea; apartar estado servidor-listo.json con app cerrada. El supervisor anterior vuelve a rechazar una segunda instancia sin menú. Mantener datos/runtime/ajustes; no requiere restaurar base ni cambiar permisos SQL, COM, IP de Windows o firewall.
- Commit de implementación probado: `058b71c9caa0f916f58257cebae51ffd97c3af5a`. Referencia fijada en commit documental posterior; consultar `git log -1 -- SEED.MD`.


### CAMBIO-2026-11-13-07 — Indicador real como fuente predeterminada

- Estado: implementado y probado en `K:\TYTOneBascula_Local`, main, base `a482f48`. Solicitud expresa: fuente predeterminada real. Reemplaza el comportamiento anterior que exigía seleccionar Usar lectura real tras el estado inicial SIMULADO; se mantiene simulación como elección explícita de pruebas.
- Archivos: web/src/serial.php, web/public/configuracion_serial.php, nueva scripts/test_fuente_predeterminada.php, README y este registro. Respaldo efectivo de los cuatro archivos existentes: `K:\TYTOneBascula_Local\.runtime\local\actualizaciones\20261113-real-default-135635`. No se cambió configuración privada real ni se accedió a la base/COM.
- Implementación: fuente efectiva SERIAL por defecto aunque el registro inicial SQL sea SIMULADO. Conserva parametros_confirmados y todos los demás parámetros; no confirma ni inventa COM. El esquema/check existentes no permiten SERIAL incompleto en almacenamiento, por eso no se cambia dbBascula.sql ni su hash y no se migra ni actualiza SQL para introducir el nuevo valor predeterminado. serial_fuente_actual y serial_publicar aplican la misma regla para mantener origen, firma de lectura y configuración API coherentes.
- Simulación explícita: botón Usar Simulado (solo pruebas) crea archivo privado simulado-ID.json en runtime_dir, con hash de revisión del registro, aislado por empresa. Solo un registro SIMULADO con elección válida se publica como simulador. Archivo ausente/corrupto o revisión cambiada implica real. Guardar/confirmar ajustes o seleccionar real retira la elección; publicaciones posteriores son reales. Elecciones anteriores a este cambio deben repetirse si se desean conservar como simulación. No se suben marcadores privados a Git.
- Seguridad funcional: con parámetros sin confirmar no se abre COM ni se devuelve simulación; muestra error de configuración sin impedir cargar la página. Al confirmar parámetros válidos, el monitor de la API intenta escuchar automáticamente la fuente real; mensajes del formulario actualizados para advertirlo. Puerto ocupado/ausente/sin datos conserva retroalimentación y reintentos existentes sin modificar parámetros ni enviar datos. No se cambia el endpoint técnico sin empresa usado por pruebas aisladas; las páginas autenticadas usan fuente por empresa.
- Pruebas: test_fuente_predeterminada.php correcto en temporal: real inicial, confirmación intacta, registro original intacto, publicación real de arranque, simulación solo explícita y publicada, cambios de revisión/empresa no heredan elección, archivo corrupto vuelve a real y fuente real confirmada conservada. test_comparacion_serial.php correcto (comparación y validaciones previas); sintaxis de los dos PHP funcionales y git diff --check correctos. Sin SQL real ni COM, sin arranque/reinicio real. Prueba física y aceptación pendientes con operador.
- Aplicación posterior: respaldar y copiar ambos PHP funcionales y documentación con la app propia cerrada o durante pausa coordinada; no preparar/inicializar ni importar SQL. Mantener configuración privada de DB/COM. Al reiniciar, configuración PRINCIPAL se publicará como real por defecto. Revisar parámetros y confirmar solo con COM libre; comprobar página sin señal y recepción física luego. Si se necesita simulador, elegirlo expresamente en esa instalación.
- Reversión: restaurar los dos PHP desde respaldo y apartar los marcadores simulado-ID.json creados por este cambio si procede, con app propia cerrada; reiniciar para republicar la fuente anterior. Conservar DB, datos, runtime, COM y ajustes existentes. No restaurar base por este cambio, que no requiere SQL/permisos ni cambios de red.
- Commit de implementación probado: `49c5e6886612a33e040a4980f5e250076d9fa720`. Referencia agregada en commit documental posterior; consultar `git log -1 -- SEED.MD`.


### CORRECCION-2026-11-13-02 — Sin indicador no bloquea captura manual; diagnóstico de acceso DB

- Estado: mejora de manejo de errores implementada y probada en `K:\TYTOneBascula_Local`, main, base `df04a28`; acceso operativo a base pendiente. Solicitud: no impedir ingreso manual cuando no comunica el indicador.
- Diagnóstico dirigido: el registro PHP efectivo informó PDOException; SELECT 1 usando el mismo entorno bascula_web del supervisor devolvió error de autenticación 1045. No es un error de COM. No existe web/config/local.php de respaldo en esta copia. PHP conserva rutas privadas del nombre anterior sin guion bajo, pero carga PDO/mysql, mbstring, curl y openssl; no se alteraron esas rutas ni credenciales en esta corrección. Se leyó solo la clasificación de errores y rutas relevantes, sin volcar secretos.
- Archivos: web/public/lectura.php distingue fallo serial de PDO; web/public/assets/app.js recuerda captura Manual ante falta de lectura; web/src/bootstrap.php explica errores de base sin mostrar detalles privados. Nueva scripts/test_manual_sin_indicador.py; README y registro. La captura manual existente ya evita serial_fuente_actual/serial_origen en su rama; no se cambió autenticación ni validación/persistencia de pesadas.
- Respaldo efectivo: `K:\TYTOneBascula_Local\.runtime\local\actualizaciones\20261113-manual-sin-com-140750`, incluye los cinco archivos existentes afectados. No se modificaron base, credenciales, COM ni configuración privada. Solo se ejecutaron dos SELECT 1 de diagnóstico (entorno de arranque y comprobación de configuración web existente), sin consultar ni guardar datos operativos.
- Pruebas: test_manual_sin_indicador.py correcto, ejecuta el endpoint PHP real en fixture temporal con autenticación simulada y sin DB/API/COM: captura firmada de bruto/tara manual de 1234 lb y respuesta acotada de falta de lectura que ofrece Manual. No acredita guardado con base, login real ni uso físico. Sintaxis de lectura.php y bootstrap.php, node --check de app.js y git diff --check correctos.
- Resultado/límite: falla serial queda en el panel y ofrece Manual. Para entrar y guardar de verdad sigue siendo necesario resolver el 1045 de MariaDB; no se eluden permisos ni se conecta con una cuenta administrativa para operar. Se solicita autorización específica para revisar/corregir únicamente acceso, pues el alcance inicial excluía cambiar configuración de base. Mientras no se resuelva, no presentar login/manual persistido como verificados.
- Aplicación posterior: respaldar y copiar los tres archivos funcionales y documentación; sin preparar/inicializar ni SQL. Recargar la página; revisar el panel sin COM y probar captura/guardado manual solo una vez restablecido el acceso DB y con datos de prueba coordinados. Registrar resultado real y no asumir que ausencia del COM causa errores PDO.
- Reversión: restaurar los tres archivos funcionales desde respaldo, conservar configuración y datos. No requiere restaurar base; el mensaje anterior vuelve a ser genérico y se pierde la indicación explícita de Manual ante error de lectura.
- Commit de implementación probado: `dfb643129e6521015303841ebf76534214fab9f3`. Referencia agregada en commit documental posterior; consultar `git log -1 -- SEED.MD`.


### DIAGNOSTICO-2026-11-13-01 — Dos destinos MariaDB distintos entre las carpetas

- Estado: causa de la diferencia de acceso identificada por comparación dirigida; elección de destino pendiente del usuario. Copia de trabajo `K:\TYTOneBascula_Local`, base `61df2ee`. La carpeta que el usuario confirmó que sí funciona es `K:\TYTOneBasculaLocal`, lanzador Iniciar Bascula solo PC.cmd. No se modificó esa instalación.
- Hallazgo: web/src/functions.php y web/public/login.php son idénticos en ambas carpetas, y el secreto web coincide (comparado sin imprimirlo). La carpeta sin guion bajo usa base propia en 3318; la copia actual conserva configuración de XAMPP externo en 3306. SELECT 1 como cuenta web funciona en 3318 y devuelve 1045 en 3306. Esto explica la diferencia; no atribuirla al COM ni dar por incorrecta la contraseña de login del usuario.
- Identidad confirmada mediante SELECT @@datadir y @@port: 3318 corresponde a `K:\TYTOneBasculaLocal\.runtime\local\data`; 3306 corresponde a `C:\xampp\mysql\data`. No son el mismo destino de datos. No se cambia automáticamente puerto, datadir ni credenciales para ocultar el error.
- Configuración de la copia actual comparada con su respaldo anterior a la corrección de identidad: solo cambió root; la configuración DB no fue alterada por los cambios de IP/puerto web, Python, fuente real o captura manual. El diagnóstico previo de 1045 era correcto, pero no bastaba para decidir reparar permisos en 3306 sin comparar el destino funcional.
- Pruebas de solo lectura: comparación de archivos/ajustes, dos SELECT 1 y dos consultas de identidad con las credenciales ya guardadas, sin mostrarlas. Sin acceso a filas de pesadas, modificación SQL, reinicio de servicios ni copia de bases. No se ejecutaron pruebas funcionales adicionales porque no cambió código.
- Pendiente: el usuario debe confirmar qué datos desea usar en la copia actual: destino funcional 3318 o XAMPP 3306. Con esa decisión se preparará el ajuste específico con respaldo, verificación de identidad y reversión. No operar ni reiniciar servicios de la carpeta funcional como efecto incidental. Si se elige 3318 como base externa, documentar que depende de la instancia de la otra carpeta y no constituye traslado autónomo de datos.
- Reversión: no hay cambios funcionales que revertir; solo se registra el diagnóstico en un commit documental.


### DIAGNOSTICO-2026-11-13-02 — XAMPP elegido; cuenta ausente y estructura anterior

- El usuario eligió conservar XAMPP 3306 y autorizó corregir su acceso. No se autorizó migrar datos a 3318. Se confirmó que no existe ningún usuario bascula_web en mysql.user de 3306. Se intentó crear únicamente bascula_web@127.0.0.1 con el secreto ya guardado y los GRANT mínimos definidos en Installation.initialize, sin inicializar aplicación ni modificar filas.
- El GRANT falló con 42S02 porque falta t_configuracion_bascula. Se ejecutó DROP USER para revertir la cuenta recién creada y se confirmó por consulta que quedó ausente. No permanecieron privilegios parciales ni se alteraron datos operativos. Respaldo del estado previo (cuenta ausente), permisos propuestos y SQL privado de reversión: `.runtime/local/actualizaciones/20261113-acceso-xampp-143413`. El SQL de reversión no debe ejecutarse de nuevo a ciegas: la cuenta nueva ya fue retirada.
- Comparación solo de metadatos: falta t_configuracion_bascula y doce columnas de t_pesadas: solicitud_primera, huella_primera, solicitud_segunda, huella_segunda, responsable_id, origen_bruto, origen_tara, calculo_libras_enteras, base_descuentos_lb, descuento_humedad_lb, descuento_defectos_lb y observaciones_segunda. Así, reparar solo autenticación no basta para garantizar lectura/manual/guardado con este código. No se importó el esquema ni se ejecutó preparar/inicializar. Se necesita actualización de estructura específica, con respaldo y validación separados, antes de dar esta base por compatible.
- Estado operativo: acceso no reparado todavía; pendiente autorización/aplicación de actualización de estructura y posterior creación de la cuenta mínima. No afirmar login ni guardado real verificados. La carpeta funcional 3318 sigue intacta.

### CAMBIO-2026-11-13-08 — Configuración y sugerencias de XAMPP en el formulario

- Estado: implementado y probado en `K:\TYTOneBascula_Local`, main, base `953029b`. Solicitud: integrar carpeta y puerto de XAMPP y sugerir su configuración predeterminada. No incluye control de servicios ni reparación automática de base.
- Archivos: scripts/configurar_web.py y scripts/instalacion_local.py; nuevos scripts/configurar_xampp.py, scripts/diagnosticar_xampp.php y scripts/test_configurar_xampp.py; README y registro. El preparador para carpetas nuevas distribuye los dos módulos funcionales adicionales y PHP diagnóstico. No se ejecutó preparar.
- Respaldo efectivo de archivos existentes: `.runtime/local/actualizaciones/20261113-config-xampp-143811`, con configurador/supervisor/README/SEED. Los módulos nuevos no sustituyen archivos anteriores. No se guardaron ajustes de XAMPP en la configuración privada real durante el desarrollo.
- Formulario: carpeta, puerto MySQL, Probar conexión y Guardar conexión y cerrar; confirmación explícita de usar esa base sin copiar datos. Sugiere carpeta habitual C:\xampp/puerto 3306 y lee solo port de [mysqld] de mysql/bin/my.ini o mysql/my.ini en la carpeta conocida; no recorre discos ni muestra otras directivas/secretos. Botón Usar sugerencia rellena campos y no guarda automáticamente; conserva configuración previa hasta decisión del operador.
- Validación: carpeta absoluta con panel, mysqld y mysql/data; puerto entero válido y distinto de web/FastAPI (incluido web-listen.json). Diagnóstico CLI PHP privado, destino fijo 127.0.0.1/dbBascula, SELECT 1, @@datadir e information_schema de nombres de tablas/columnas contra dbBascula.sql. No consulta filas de clientes/pesadas ni transmite secretos al navegador. Códigos de error limitados, sin mensajes SQL privados. Verifica correspondencia del datadir y acceso con credenciales existentes; no usa root como cuenta operativa de la web.
- Guardado: exige app cerrada mediante bloqueo, carpeta/identidad/acceso/estructura compatibles y confirmación de destino. Rechaza configuraciones no listas en lugar de ocultar el problema. Respalda instalacion.json con fecha/sufijo aleatorio y lo reemplaza atómicamente conservando secretos y todos los otros ajustes; cambia external_db=true, xampp_folder, external_db_datadir y ports.db solamente. No modifica my.ini, Windows, red, COM, cuentas, tablas ni servicios de XAMPP. El puerto debe estar previamente configurado en XAMPP; el formulario solo ajusta la conexión de Báscula.
- Pruebas: ocho nuevas aisladas correctas: carpetas/puertos/conflictos, identidad/acceso/estructura, guardado y respaldo con secretos conservados, rechazo de base incompatible, app abierta sin diagnóstico de guardado, errores sin secretos, HTTP protegido para diagnóstico/confirmación/guardado/cierre, sugerencia desde [mysqld] ignorando [client]/contraseña y puerto inválido. Diez pruebas del configurador previo y nueve del iniciador correctas. La regresión HTTP detectó que rechazar un POST sin consumir su cuerpo podía causar WinError10053 y ocultar el 403 en Windows; se corrigió consumiendo solo cuerpos declarados de hasta4096 bytes con timeout y se repitieron las diez pruebas correctamente. Sintaxis AST de los Python y PHP del diagnóstico y git diff --check correctos.
- Comprobación real de solo lectura: C:\xampp detectado desde su my.ini, puerto3306, servicio activo, datadir coincide; diagnóstico informa 1045 y estructura incompleta descritos arriba. No se abrió COM ni navegador real, ni se inició/detuvo la app o XAMPP, ni se guardaron pesadas. No presentar este diagnóstico como corrección del acceso pendiente.
- Aplicación posterior: respaldar/copy de los cuatro archivos funcionales y documentación, conservar runtime/configuración/datos; abrir Configurar Bascula.cmd, comprobar sugerencia y Probar conexión. Resolver por procedimiento separado acceso/estructura si se informa incompatibilidad. Para guardar nuevos valores, cerrar app, confirmar destino existente y guardar; luego iniciar con comprobaciones habituales. Nunca usar preparar/inicializar para actualizar una instalación con datos.
- Reversión: cerrar configurador/app propia, restaurar configurador y supervisor respaldados y apartar nuevos módulos funcionales si se desea. Si se guardó conexión, restaurar instalacion.json desde su copia anterior y verificar identidad de destino antes de arrancar. No restaurar base por este cambio de formulario; no altera esquema ni servicios.
- Commit de implementación probado: `0746a1f38b9d7c24c569a5393da31eecd8238559`. Referencia fijada en commit documental posterior; consultar `git log -1 -- SEED.MD`.


### CAMBIO-2026-11-13-09 — Actualización específica de XAMPP con prueba temporal

- Estado: código preparado y probado en copia temporal; aplicación a XAMPP principal se registrará aparte. Base de código `66ccdfb`, carpeta `K:\TYTOneBascula_Local`. El usuario autorizó respaldo, prueba temporal, actualización de estructura, creación de cuenta mínima y verificación, conservando pesadas y sin tocar 3318.
- Archivos nuevos: scripts/actualizar_xampp.py, scripts/actualizar_xampp.php y scripts/actualizaciones/20261113-xampp-compatibilidad.sql; README y este registro. No se cambió dbBascula.sql ni su hash de identidad, ni se ejecutaron preparar/inicializar. Código original recuperable desde Git; configuraciones respaldadas en carpeta privada indicada abajo.
- SQL exacto en el archivo específico: crea únicamente t_configuracion_bascula con validaciones/FK del esquema; añade doce columnas ausentes a t_pesadas, índices únicos de solicitudes, FK responsable y comprobaciones de origen/solicitudes/descuentos/flag entero; actualiza chk_pesadas_pesos para respetar la bandera de cálculo nuevo sin reinterpretar registros antiguos (FALSE por defecto); crea configuración PRINCIPAL sin COM ni confirmación para las empresas existentes. No cambia campos anteriores ni recálcula pesos, no borra tablas/filas ni importa esquema completo.
- Ejecutor: exclusivo CLI, destino fijo dbBascula/XAMPP3306, identidad/datadir comprobados; pausa solo el supervisor de esta carpeta mediante su marcador y bloqueo, nunca detiene XAMPP/3318. Respalda con mysqldump single-transaction/routines/events/triggers/hex-blob y valida terminación/hash; credencial temporal privada eliminada al finalizar el dump. Clona esquema y datos a bascula_validacion_ + 12 caracteres hex, compara hashes/filas de todos los campos originales antes/después, prueba ciclos reales manuales y borra únicamente esa base temporal con nombre validado. Repite comparación de datos/estructura antes de aplicar para detectar cambios concurrentes.
- Prueba temporal ejecutada: runtime\python\python.exe -B scripts\actualizar_xampp.py. Correcta: clon idéntico, migración sin alterar datos anteriores, primera/segunda manual BRUTO->TARA y TARA->BRUTO, final 3000 lb y reintentos idempotentes, sin lectura/API/COM. Todos los datos sintéticos se guardaron solo en temporal, que fue eliminada. Aún no se creó cuenta permanente ni se aplicó SQL principal en esta fase. Sintaxis PHP y AST correctos.
- Respaldo de esta fase: `K:\TYTOneBascula_Local\.runtime\local\actualizaciones\20261113-migracion-xampp-145604-522546`; dbBascula-antes.sql (16303 bytes), SHA-256 `ef14f20c3e90746730c39fd577746b09cc4bfe0dd779b023eba77599e6bdcd5c`. Incluye instalacion.json/php.ini privados, SQL exacto y su hash, hashes/conteos de tablas, resultado temporal y permisos SQL sin secretos. No subir ni compartir el dump/configuraciones. El dump no se restauró en producción; la copia temporal se creó mediante SHOW CREATE e INSERT SELECT para probar la migración.
- Configuración y acceso: conserva puerto3306/datadir/secretos actuales. Tras aplicar crea solo bascula_web@127.0.0.1 con secreto existente y los GRANT limitados ya definidos por Installation.initialize; no se usa root para operar la web. Si falla la creación de cuenta, retira solo la cuenta recién creada; no restaura datos automáticamente ni oculta DDL parcial. El control de estado impide repetir sobre una tabla/cuenta ya existentes.
- Aplicación autorizada siguiente: runtime\python\python.exe -B scripts\actualizar_xampp.py --aplicar. Hace respaldo nuevo, repite toda la prueba y solo después aplica. No reinicia automáticamente la app; el operador puede iniciarla tras terminar. Validar diagnóstico, login y captura; lectura física solo cuando se configure/confirme el COM con operador.
- Reversión: la carpeta contiene reversion.md. Con app propia cerrada, una reversión exclusiva de código conserva datos y columnas añadidas; retirar cuenta nueva solo si se desea revertir acceso. Restaurar el dump únicamente en identidad3306 comprobada, con autorización y tras conciliar operaciones nuevas (una restauración las perdería). No borrar columnas con información nueva ni restaurar a ciegas. No tocar 3318 ni migrar su base. El DDL de MariaDB confirma implícitamente: si hay fallo, revisar fase/estado y no asumir reversión transaccional.
- Commit de implementación probado: `8c18cd00ad32386b49f3f5c3a39f47c64693317d`. Referencia fijada en commit documental posterior; consultar `git log -1 -- SEED.MD`.


### APLICACION-2026-11-13-02 — Reparación aplicada en XAMPP3306

- Estado: estructura y acceso aplicados por autorización del usuario en esta copia local; lectura física y acceso con contraseña real del usuario pendientes de su comprobación. Implementación `8c18cd00ad32386b49f3f5c3a39f47c64693317d`. No se tocó la instalación ni los datos de 3318.
- Respaldo efectivo previo: `K:\TYTOneBascula_Local\.runtime\local\actualizaciones\20261113-migracion-xampp-145719-5595d2`; dbBascula-antes.sql, 16303 bytes, SHA-256 `9af34a3476aafdce8ebe663dd0fe8702f81f79887657a39a9d8a3771d103f471`. SQL aplicado SHA-256 `7e39f785d1f8e277d3dd7dc734e2f7d66efdcf8a600094b9b6d9de90fa04feed`. Incluye configuración privada y guía de reversión; no compartir ni subir a Git.
- Ejecutado: runtime\python\python.exe -B scripts\actualizar_xampp.py --aplicar. La copia temporal volvió a superar migración, hashes/conteos de campos originales y dos ciclos manuales idempotentes. Temporal eliminada. En XAMPP se añadió la tabla/columnas/índices/validaciones descritas en CAMBIO-09 y se creó únicamente la cuenta web local con permisos mínimos existentes. Comprobación posterior de hashes de todos los campos/registros anteriores idéntica, sin recálculo de pesos ni registros sintéticos en base real.
- Verificación: diagnóstico XAMPP informa servicio activo, datadir coincide, acceso correcto y estructura compatible. Servidor HTTP temporal solo loopback con entorno web real: GET login200, POST con usuario sintético inexistente401 (respuesta normal de credenciales incorrectas), sin503/1045; servidor propio cerrado. Consulta de login real preparada y ejecutada correctamente con cuenta limitada sin recuperar datos personales. No se usó contraseña del usuario ni se dio por validada su sesión personal. Test de captura manual sin indicador correcto. No se inició API ni se abrió COM.
- Configuración: puerto3306/datadir/secretos previos conservados. Nueva configuración PRINCIPAL queda sin COM ni parámetros confirmados, por lo que la fuente real muestra Sin lectura y permite Manual hasta configurar el indicador. No se cambió contraseña de usuarios de la app. No se reinició automáticamente la app ni XAMPP.
- Reversión: seguir reversion.md del respaldo con app propia cerrada y autorización. No restaurar dump tras operaciones nuevas sin conciliarlas; no borrar columnas con información nueva. Para revertir solo acceso, retirar exclusivamente la cuenta bascula_web@127.0.0.1 creada aquí, sin tocar la de3318. La reversión de código no exige borrar nuevas columnas ni restaurar pesadas. No repetir el actualizador específico sobre el estado ya actualizado.
- Pendiente del usuario: abrir el iniciador habitual, entrar con su cuenta y comprobar operación manual; coordinar recepción física cuando se confirme COM. No se harán cambios adicionales sin solicitud.

### 2026-11-13 — Preservar fuente real y acuerdos del usuario
La fuente predeterminada acordada es el indicador real. No sustituirla por simulación al crear cuentas, ejecutar esquemas o hacer otros cambios. Antes de revertir cualquier cambio acordado, consultar al usuario explicando razón, contexto completo, efectos y alternativas. Esta instrucción también se aplica a configuraciones y comportamiento existentes.
Se recuperó para la nueva empresa de super la configuración serial del respaldo del 12 de noviembre: COM4, 9600, 8N1, NINGUNO, C18=4, lb. No se restauraron otras tablas ni se modificaron parámetros de Windows o del indicador. Se corrigió la tarjeta de Inicio, que mostraba Simulador como texto fijo, para mostrar la fuente efectiva. Respaldo previo en .runtime/local/actualizaciones con sufijo fuente-real. Verificación física pendiente; no confundir configuración SERIAL con lectura recibida.


### 2026-11-13 — Selección efectiva Real / Simulado
Inicio muestra la fuente efectiva. El selector publica la fuente para la API; Simulado conserva en su marcador privado la confirmación anterior vinculada a la revisión exacta, permitiendo volver a Real sin perder parámetros. Cambios de parámetros invalidan esa confirmación. No se modificó el esquema ni los parámetros del COM. Se verifica ida y vuelta por HTTP y se conserva Real al terminar.

Verificación del selector: sintaxis PHP correcta en los tres archivos. Prueba HTTP autenticada Real → Simulado → Real correcta: en Simulado, tarjeta Simulado y respuesta con origen simulado; botón Real disponible; al volver, HTTP 200, tarjeta Real y lector esperando datos de COM4. Se cerró la sesión de prueba y quedó Real seleccionado. Instrucciones permanentes en AGENTS.md. No se confirmó recepción física de peso.
