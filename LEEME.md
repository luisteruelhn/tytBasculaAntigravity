# Instalación local permanente

## Configuración vigente en este equipo — XAMPP, 2026-11-12

La app utiliza ahora **MariaDB de XAMPP**, en `127.0.0.1:3306`, base `dbBascula`, archivos en `C:\xampp\mysql\data`. Este apartado sustituye las referencias anteriores al servidor propio en 3318.

1. Iniciar **MySQL** y **Apache** desde el panel de XAMPP.
2. Abrir **Iniciar Bascula solo PC.cmd**. App: http://127.0.0.1:8088/login.php.
3. Revisar la base en http://127.0.0.1/phpmyadmin/ y seleccionar **dbBascula**.
4. Para cerrar la app usar **Detener Bascula.cmd**. Esto no apaga MySQL ni Apache de XAMPP; administrarlos desde su panel.

Se trasladaron las 10 tablas conservando los datos, cuentas de aplicación y permisos. Se verificaron conteos, `mysqlcheck`, conexión PHP con la cuenta de la app, arranque/cierre de prueba, respaldo automático y respuestas HTTP de app/phpMyAdmin. No se modificaron otras bases. La app comprueba la identidad del datadir de XAMPP y respalda antes de iniciar. No inicializa ni apaga la base externa.

Respaldo previo al traslado y configuración anterior: `.runtime/local/actualizaciones/20261112-xampp`. La base anterior en `.runtime/local/data` se conserva detenida como copia histórica; no recibe nuevas operaciones. Los respaldos nuevos siguen en `.runtime/local/respaldos`. No ejecutar el preparador ni restaurar la copia anterior sobre la base vigente.

Pendientes: ingreso y revisión del usuario; acceso de red y pruebas físicas por acordar. Usar el lanzador solo PC en este equipo.

Carpeta autorizada: `K:\TYTOneBasculaLocal`. Desarrollo: `K:\TYTONEBascula`. No es una instalación en producción. Solo admite lectura simulada. MariaDB y FastAPI escuchan en 127.0.0.1; la web admite modo solo PC o una interfaz/subred privada autorizada explícitamente.

## Uso diario

1. Abrir `Iniciar Bascula.cmd`. Esperar LISTO; en la instalación actual se abre el navegador en http://192.168.5.81/login.php (HTTP, puerto 80). El móvil debe estar en la misma red. Para modo solo PC usar Iniciar Bascula solo PC.cmd, con http://127.0.0.1:8088/login.php; detener el modo anterior antes de cambiar.
2. El acceso inicial está en `.runtime/local/acceso-inicial.txt`, privado. Cambiar la contraseña desde Mi cuenta al ingresar y guardar la nueva en un lugar seguro. Si se exceden los intentos de login, esperar 1 minuto antes de reintentar.
3. Mantener la ventana de inicio abierta. Para cerrar, abrir `Detener Bascula.cmd` y esperar a que termine la ventana de inicio, o usar Ctrl+C en ella. Los datos se conservan. Evitar cerrar por la X o apagar el equipo durante una operación.
4. Para la primera prueba, completar los datos de empresa, crear un cliente/proveedor y producto/presentación. Usar Primera y Segunda con peso manual o simulado y revisar boleta, reporte y dashboard. Una nota guardada es permanente: usar datos de prueba identificables; no es una certificación del indicador físico.

## Preparación desde desarrollo

Ejecutar con el Python del proyecto, indicando datos del acceso inicial:

```powershell
.\.venv\Scripts\python.exe scripts/instalacion_local.py preparar --destino K:\TYTOneBasculaLocal --empresa "RAZÓN SOCIAL" --nombre "NOMBRE" --email "correo@example.com" --usuario admin
```

El destino debe ser nuevo y estar fuera del desarrollo. Copia app/web, generadores PDF/Excel y plantilla, binarios propios PHP/MariaDB ya disponibles y paquetes Python existentes. Crea otro entorno virtual sin instalar dependencias. Conserva la dependencia del Python 3.14 instalado en esta computadora: no es un paquete portable para otra PC. No copia demos, bases, PDF ni configuración privada del desarrollo. No instala servicios, tareas, drivers ni modifica XAMPP o Windows. El archivo `web/tyt-systems-logo.png` ajeno se excluye.

PHP desactiva OPcache en esta prueba local para evitar el fallo de handlers/ASLR observado al copiar PHP 8.5. PHP usa un php.ini propio con extensiones y carpetas temporales de esta instalación. Solo se sirve web/public. Puertos fijos: MariaDB 3318, FastAPI 8018 y PHP 8088; si están ocupados se bloquea el arranque, sin conectar ni detener procesos ajenos. Dos lanzadores simultáneos se excluyen mediante bloqueo de archivo de Windows.

## Datos, respaldo y esquema

- `.runtime/local/data`: directorio permanente MariaDB exclusivo; no borrar, sustituir ni copiar mientras el servidor esté abierto.
- `.runtime/local/instalacion.json`, `client.cnf` y `acceso-inicial.txt`: configuración y secretos privados; nunca publicar, enviar ni guardar en Git.
- `.runtime/local/respaldos`: dump SQL completo tras preparar y antes de habilitar la web en cada arranque; un fallo bloquea la web. Copiar periódicamente los respaldos a otro dispositivo: los de la misma unidad no protegen contra pérdida del disco. No hay borrado automático de respaldos.
- `.runtime/local/*-FECHA.log`: registros de cada arranque; `php-error.log`: errores PHP.
- `__pdfs`: documentos privados, fuera del directorio público. No forman parte del dump SQL; respaldarlos también si se desea conservar los archivos emitidos.

Se crea dbBascula **solo en la instancia nueva**, desde scripts/dbBascula.sql, con los cambios 001–006 integrados y taras de referencia del esquema. No se ejecutan migraciones ni se registra ficticiamente su aplicación en t_migraciones. La huella del esquema queda en instalacion.json y se comprueba antes de abrir la web. La cuenta SQL web tiene permisos por tabla/columna, sin DROP, DELETE, ALTER ni administración. Se crea una empresa y un usuario SUPER con contraseña aleatoria, sin cuentas demo. SUPER permite administrar la instalación y cambiar su contraseña.

Una inicialización interrumpida conserva sus archivos y bloquea la reinicialización. No se intenta reparar, migrar ni restaurar automáticamente una base. Para actualizar código o recuperar un respaldo, detener, identificar esta instancia y preparar un respaldo adicional; definir y verificar el procedimiento antes de ejecutar cambios. El preparador rechaza una carpeta existente y no sirve como actualizador.

## Verificación dirigida

Desde la instalación: `.\.venv\Scripts\python.exe scripts/instalacion_local.py verificar` arranca, respalda, comprueba API simulada/login y cierra limpiamente. No usar mientras ya esté abierta. Integración optativa desde desarrollo, únicamente con una instalación nueva detenida: definir `BASCULA_LOCAL_TEST_ROOT=K:\TYTOneBasculaLocal` y ejecutar `.\.venv\Scripts\python.exe -m unittest tests.test_instalacion_local -v`. Crea un cliente identificable, verifica persistencia tras reinicio y su inclusión en el dump, y elimina ese cliente; no crea pesadas. Los respaldos de esa verificación conservan el cliente de comprobación. El login HTTP no sustituye la verificación autenticada, la persistencia tras reinicio ni el cotejo de boletas.

Pendientes generales: prueba física acordada con báscula/impresora, integración serial, actualización/restauración asistidas y arranque como servicio si se requiere operación con sesión cerrada. El servidor PHP integrado es destinado a esta prueba local controlada; el acceso HTTP desde la red de confianza fue autorizado por el usuario. No publicar en Internet.

## Acceso móvil autorizado y nombre local — 2026-11-11

El usuario autorizó HTTP sin cifrar exclusivamente en su red de confianza. El lanzador actual usa `--red-local 192.168.5.81/24 --puerto-web 80`: enlaza PHP solo a esa interfaz y habilita HTTP para clientes 192.168.5.0/24 mediante BASCULA_HTTP_RED_LOCAL. Fuera de loopback y de la subred privada explícita se sigue exigiendo HTTPS; no se confían cabeceras proxy. Cookies HttpOnly/SameSite y controles de sesión/login se conservan. Por HTTP, contraseñas y datos no están cifrados; usar solo la red autorizada. Compartir PDF con el menú del móvil puede requerir HTTPS: descargar/adjuntar manualmente sigue disponible.

El puerto 80 debe estar libre; si está ocupado no se detiene ni sustituye su proceso. MariaDB 3318 y FastAPI 8018 siguen en loopback. El modo solo PC y la preparación sin --red-local conservan el acceso por 127.0.0.1:8088. --puerto-web permite elegir otro puerto sin cambiar el esquema ni el archivo privado de base. No se modifican firewall, Windows, XAMPP ni router.

Para **http://tytonebascula/login.php** falta registrar en el DNS local del router `tytonebascula -> 192.168.5.81` y reservar esa IP por DHCP para la interfaz Wi-Fi de esta PC. Una reserva DHCP por sí sola no garantiza la resolución del nombre. El modelo del router debe permitir nombres DNS locales y los móviles deben utilizar ese DNS. No se editó hosts ni se cambió el nombre de Windows. La dirección IP puede cambiar hasta completar la reserva; si cambia la interfaz, actualizar el argumento del lanzador tras verificar su subred. No abrir puertos WAN ni activar DMZ.

Se intentó abrir el panel 192.168.5.1 por HTTP y HTTPS; ambos rechazaron la conexión desde el navegador disponible. La configuración DNS/DHCP del router y la prueba desde un móvil siguen pendientes. Pruebas dirigidas: `python -m unittest tests.test_red_local -v`, más login HTTP 200, index sin sesión 303, rutas privadas 404 y API/DB inaccesibles por la interfaz Wi-Fi. Esta comprobación desde la PC no verifica el firewall ni la conexión del móvil.

## Arranque en este equipo — 2026-11-12

Se corrigió `.venv/pyvenv.cfg` para usar el Python 3.14 de `C:\Users\DellUser\AppData\Local\Programs\Python\Python314`. Copia original en `.runtime/local/actualizaciones/20261112-arranque-este-equipo/pyvenv.cfg.original`.

Se inició con `Iniciar Bascula solo PC.cmd`: http://127.0.0.1:8088/login.php. Login y salud de FastAPI devuelven HTTP 200; se generó el respaldo automático de arranque. No se reinicializó ni migró la base. No se abrió ningún puerto serial.

Pendientes: ingreso del usuario y comprobación de sus operaciones. El lanzador de red conserva la IP del equipo anterior; para uso aquí continuar con el lanzador solo PC hasta configurar explícitamente el acceso de red.

## 2026-11-12 — Lector serial sin cambios de configuración

Por autorización del usuario se corrigieron exclusivamente archivos de TYTOneBasculaLocal. El lector consulta GetCommState y conserva parámetros/tiempos existentes: se eliminaron SetCommState y SetCommTimeouts, también al cerrar. No envía bytes ni purga buffers. Consulta bytes recibidos y lee únicamente los disponibles, emitiendo avisos cada medio segundo cuando no hay datos. La pantalla muestra parámetros realmente consultados. Se retiró el mensaje que suponía un puente de eco como causa de ausencia de datos.

El transporte PHP ya no lee un pipe stderr vacío que podía bloquear el envío de avisos en Windows; stderr se dirige a NUL y se conservan los eventos de error controlados del lector. El cierre de proceso/árbol existente se conserva.

Verificación sin hardware: cuatro pruebas con kernel32 ficticio correctas (30 segundos simulados sin datos y con avisos, recepción, fallo de consulta, puerto ocupado). El objeto simulado no ofrece funciones de modificación. PHP con proceso lector falso entregó cuatro eventos en flujo antes del cierre. Lint PHP, sintaxis Python y JavaScript correctos. No se abrió ningún COM, no se cambiaron Windows/XAMPP/configuración serial ni datos. Sin reinicios de servicios.

Respaldo de tres archivos anteriores y ensayo ficticio en .runtime/local/actualizaciones/20261112-lector-sin-configurar. Pruebas repetibles: .venv/Scripts/python.exe scripts/test_lector_sin_configurar.py -v.

Pendiente: recargar la página con Ctrl+F5 y acordar la prueba real con el puerto libre del sistema anterior. La falta de eventos mantiene el aviso de estado no confirmado; no se ocultan fallos de comunicación. Esta modificación local deberá conservarse al actualizar desde desarrollo. Abrir un puerto sigue siendo acceso exclusivo durante la prueba; no se garantiza ausencia de efectos propios del controlador al abrir/cerrar.

## 2026-11-12 — IP del lanzador local

Actualizado únicamente Iniciar Bascula.cmd en esta instalación para --red-local 192.168.2.20/24 --puerto-web 8088. IP comprobada en el equipo, sin modificar Windows. Puerto 80 ocupado por Apache de XAMPP, conservado. Respaldo del lanzador en .runtime/local/actualizaciones/20261112-ip-192-168-2-20. App relanzada para http://192.168.2.20:8088/login.php. Sin cambios en el proyecto de desarrollo, configuración COM ni base de datos. Pendiente: acceso desde otro dispositivo de la red; no se modificó firewall/router.

## 2026-11-12 — Comparación de COM y actualización exclusiva de la base

Por solicitud del usuario, Confirmar se sustituyó por Comparar y actualizar la base. Al pulsar, se consulta GetCommState con apertura exclusiva breve, sin leer datos ni aplicar parámetros; se cierra antes de guardar. Se comparan baudios, bits, paridad, parada y flujo; solo esos cinco datos de la app se actualizan junto con confirmación y auditoría, manteniendo el modo actual. La respuesta muestra valores anteriores y nuevos o coincidencia. No se publica/activa la fuente de lectura al confirmar. No se deducen protocolo, unidades ni capacidades a partir del COM. COM4 se permite como dato consultado sin retirar la protección del lector operativo.

Puerto ocupado, consulta incompleta, cierre fallido o parámetros no representables bloquean la actualización, sin suponer valores. Se mantienen rol, CSRF, revisión, transacción y empresa. El checkbox autoriza consulta y actualización solo de la base. La comparación puede repetirse incluso si ya estaba confirmada. El archivo de consulta faltante se creó dentro de scripts.

Verificación: seis pruebas Python simuladas correctas, comparación PHP sintética con coincidencia/diferencias y rechazos correcta, sintaxis PHP/Python correcta. No se abrió hardware ni se actualizó la base real durante el desarrollo. Sin cambios fuera de TYTOneBasculaLocal, sin reinicios. Respaldo del formulario original en .runtime/local/actualizaciones/20261112-confirmacion-solo-base.

Pendiente: recargar, finalizar cualquier escucha y pulsar Comparar y actualizar la base con el puerto libre. La apertura/cierre tiene los efectos propios del controlador; el código no llama a setters ni cambia señales. El lector operativo conserva sus restricciones anteriores; esta acción no activa lectura real.

### Corrección de consulta aislada — 2026-11-12

Se reprodujo ModuleNotFoundError al ejecutar leer_configuracion_com.py con -I, igual que PHP. La dependencia DCB ahora se carga mediante importlib desde la ruta fija del lector hermano. Añadida prueba de regresión del CLI aislado con puerto INVALIDO (rechazado antes de acceder a kernel32). Siete pruebas correctas; sin abrir COM ni modificar la base. Pendiente: repetir Comparar y actualizar la base desde la pantalla con el puerto libre. Cambios exclusivamente locales.

## 2026-11-12 — Selección explícita de lectura real

Añadido Usar lectura real, separado de Comparar y actualizar la base. Requiere rol administrador, CSRF, revisión vigente, estación PRINCIPAL, parámetros confirmados, protocolo LP7510_PC o alias C18=4, libras y declaración de puerto libre. Cambia solo el modo de la app a SERIAL y conserva parámetros. La comparación sigue sin activar lectura. Se admite COM4 en lector operativo por solicitud expresa de usar lectura real, siempre con puerto libre; el lector compartido conserva la configuración existente sin setters ni transmisiones. Sin cambios de configuración COM, Windows o XAMPP.

Verificación sin hardware: siete pruebas Python del lector/consulta, validaciones PHP de activación/comparación y ensayo de FuenteSerial con proceso ficticio para COM4 y ambos nombres del protocolo (20 lb), correctos. Sintaxis PHP/Python correcta. No se activó SERIAL en la base ni se abrió COM real durante la verificación. App reiniciada para cargar el cambio de API; selección pendiente del usuario desde el nuevo botón. Respaldo de tres archivos en .runtime/local/actualizaciones/20261112-boton-lectura-real. Exclusivamente TYTOneBasculaLocal.

Uso: finalizar prueba en vivo, comparar/confirmar si hace falta, verificar que el programa anterior liberó el puerto, marcar la casilla y pulsar Usar lectura real. Volver a Inicio para consultar. Pesadas requieren guardado explícito. Pendiente: comprobación del usuario en Inicio/Primera/Segunda y actualización futura del proyecto de desarrollo por separado.

## 2026-11-12 — Lectura operativa: normalizar bits de parada provenientes de SQL

Se diagnosticó que la prueba en vivo recibía 1 del formulario y funcionaba, pero el lector operativo recibía 1.0 (DECIMAL SQL) y rechazaba parámetros antes de abrir el puerto. Confirmado en configuración publicada y traceback del servicio. FuenteSerial ahora normaliza 1.0→1 y 2.0→2 exclusivamente en memoria, sin modificar base ni COM.

Se incorporó una prueba de regresión que ejecuta FuenteSerial junto al lector real con kernel32 ficticio para ambos valores decimales: recibe 40 lb y cierra correctamente. Ocho pruebas Python y validaciones PHP correctas. La interfaz ahora transmite el motivo controlado de error desde la API usando textContent, en lugar de ocultarlo detrás de Reconectando. Sintaxis PHP/JS correcta.

Reinicio de la app únicamente para cargar el cambio; Windows, XAMPP y configuración COM intactos. Respaldo de archivos en .runtime/local/actualizaciones/20261112-diagnostico-peso-vivo. No se iniciaron pruebas independientes sobre COM; las solicitudes de la pantalla del usuario mantienen el modo real autorizado. Pendiente: usuario verifica peso en Inicio/Primera/Segunda sin prueba diagnóstica simultánea.
