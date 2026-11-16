# Aplicación PHP: login e inicio

## Probar sin configurar una base permanente

Esta prueba se ejecuta en la computadora de desarrollo y no constituye un despliegue en la PC de la báscula. El usuario confirmó que está trabajando en otra computadora y todavía no se ha realizado ningún despliegue. El comando necesita el código y los binarios locales indicados abajo; no instala ni prepara automáticamente otra computadora.

Desde la raíz del proyecto:

```powershell
.\.venv\Scripts\python.exe scripts/probar_web.py
```

El acceso directo **Báscula - Demo** ejecuta este comando con `--abrir`. Prepara **admin / admin**, **operario / operario** y **super / super**. El login pide usuario y contraseña y obtiene la empresa desde la cuenta.

El comando reutiliza la infraestructura aislada de pruebas e inicia MariaDB, PHP y FastAPI en puertos locales libres. En el inicio, comprobar la lectura automática y abrir **Configuración** con la cuenta administradora; comprobar también **Cerrar sesión**. Estas cuentas se preparan exclusivamente en la base temporal de la demostración.

Mantener la terminal abierta durante la prueba. Al terminar, pulsar Enter en ella para detener los servicios propios. Cada ejecución crea una base nueva con sus cuentas de prueba; los archivos permanecen en `.runtime/web-test-*`, excluidos de Git. Requiere los binarios descritos en la sección Pruebas.

## Inicio local

Desde la raíz `K:\TYTONEBascula`:

```powershell
& C:\xampp\php\php.exe -S 127.0.0.1:8080 -t web/public web/public/router.php
```

Abrir <http://127.0.0.1:8080/login.php>. Detener con `Ctrl+C`. Este servidor es exclusivamente de desarrollo: no modifica ni necesita iniciar Apache. El **único directorio público** es `web/public`; nunca servir la raíz del repositorio ni `web/`.

El formulario se puede visualizar sin base configurada. Para autenticar usuarios se requiere una instancia propia con el esquema `scripts/dbBascula.sql`, una empresa activa y un usuario activo de esa empresa en `t_usuarios`. La cuenta debe tener `clave_hash` generado con `password_hash`, nunca una contraseña en texto plano. No hay usuarios ni contraseñas predeterminados, registro público o recuperación de contraseña implementados.

Copiar `web/config/local.example.php` a `web/config/local.php` y completar las credenciales de la base exclusiva. `local.php` está excluido de Git. Usa los permisos SQL de la [guía de Super y empresas](../scripts/SUPER_Y_EMPRESAS.md), incluyendo inserción de empresas, usuarios y configuración, y los permisos del catálogo.

También pueden configurarse variables de entorno, que tienen prioridad sobre el archivo:

| Variable | Uso |
| --- | --- |
| `BASCULA_DB_HOST` | Host de la instancia propia. |
| `BASCULA_DB_PORT` | Puerto de esa instancia. |
| `BASCULA_DB_NAME` | Base exclusiva, normalmente `dbBascula`. |
| `BASCULA_DB_USER` | Cuenta de aplicación con permisos mínimos. |
| `BASCULA_DB_PASSWORD` | Contraseña de esa cuenta. |
| `BASCULA_API_URL` | URL local del simulador, por defecto `http://127.0.0.1:8000`. Solo admite `http://127.0.0.1:PUERTO`. |
| `BASCULA_RUNTIME_DIR` | Carpeta privada para sesiones e intentos. Predeterminada: `.runtime/web` del proyecto. |

No se cargan archivos `.env` automáticamente. PHP necesita las extensiones `pdo_mysql`, `mbstring` y `curl`, verificadas en el entorno actual. La cuenta que ejecuta PHP necesita escribir en su carpeta privada de runtime; limitar su lectura a esa cuenta y administradores al desplegar. No se han modificado permisos de Windows durante este trabajo.

Para consultar pesos desde el index, iniciar además FastAPI en otra terminal:

```powershell
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8000
```

## Flujo y controles implementados

- Login por usuario y contraseña. La cuenta identifica la empresa. Consultas preparadas con PDO, comprobación de hash y mensajes genéricos para credenciales incorrectas o cuentas inactivas.
- Validación en servidor de campos obligatorios, tipos escalares, UTF-8 y tamaños; límite de 8 KiB por formulario. El navegador complementa estas comprobaciones.
- Sesión con identificador renovado al autenticar, cookie `HttpOnly`/`SameSite=Strict` y `Secure` bajo HTTPS. Caduca tras 20 minutos sin actividad o 8 horas desde el ingreso.
- Index y consulta de lecturas requieren sesión. Cada petición protegida vuelve a verificar el usuario, su rol, la empresa y su estado en la base.
- Tokens CSRF para login y logout. Cerrar sesión requiere POST y elimina la sesión y la cookie.
- Límite persistente de 5 intentos por usuario y 30 por IP en ventanas de 15 minutos, incluyendo intentos correctos. No depende de la cookie. Usa archivos con bloqueo en runtime; cambiar cookie no reinicia el contador. Revisar límites y retención de estos archivos antes de un despliegue con varios operadores o instancias.
- HTML escapado, CSP sin scripts ni estilos externos, protección de marcos, política sin caché y respuestas genéricas ante fallos internos. Los logs propios no incluyen cuerpos, contraseñas ni mensajes de PDO.
- `lectura.php` comprueba sesión, consulta un destino local fijo con tiempo máximo y sin redirecciones, y valida la respuesta del simulador. Si falla, la pantalla borra el peso anterior y muestra el error.
- El inicio consulta la lectura al cargar y programa la siguiente consulta un segundo después de completar la anterior. Espera como máximo cinco segundos, muestra **Sin lectura** y reintenta si hay un fallo. Al ocultar la pestaña cancela la petición y pausa el indicador; al volver consulta de nuevo. Una sesión vencida dirige al login.
- `configuracion_serial.php` permite editar estaciones existentes solo a administradores de su empresa. Valida datos en servidor, usa CSRF y transacciones con revisión del contenido para evitar sobrescribir cambios de otra sesión. Guardar retira la confirmación y vuelve al modo simulado. El botón de Windows solo carga los valores predeterminados para revisarlos; no abre el puerto ni guarda. Véase la [guía de configuración](../scripts/CONFIGURACION_BASCULA.md).
- `clientes-proveedores.php` permite buscar por nombre, RTN o teléfono dentro de la empresa autenticada. Administradores pueden crear, editar y desactivar registros. Supervisores y operadores pueden consultar. Se admite un registro con ambos tipos. La cuenta web requiere `SELECT, INSERT, UPDATE` en `t_Clie_prov`.
- En **Usuarios**, Super selecciona la empresa de la cuenta nueva y un Administrador utiliza la suya. El usuario es único en toda la instalación y el correo es único por empresa. La contraseña requiere confirmación y puede ser corta. Se guarda con Argon2id, disponible en el PHP de XAMPP verificado. Se mantiene el límite técnico de 1024 bytes que acepta el login. Las cuentas anteriores siguen funcionando mediante `password_verify`. La recuperación por correo queda pendiente.
- Super ve en **Usuarios** el listado ordenado por empresa, en páginas de 50 filas, y el enlace **Cambiar mi contraseña**. El cambio requiere la clave actual, valida CSRF, limita intentos y modifica únicamente su cuenta. Renueva la sesión actual y revoca las otras sesiones de esa cuenta mediante una huella del hash. La cuenta SQL requiere `UPDATE (clave_hash)` en `t_usuarios`. Las sesiones anteriores a esta versión deben volver a ingresar.

- **Editar** en Usuarios abre la cuenta con nombre, usuario, correo, rol y estado. Super administra todas las empresas; Administrador lista y edita exclusivamente su empresa y no puede modificar cuentas Super. La empresa de una cuenta existente se conserva para mantener las referencias de su historial. Una nueva contraseña es opcional: vacía conserva la actual, y si se cambia exige confirmación, admite claves cortas y revoca las sesiones anteriores de esa cuenta. Al cambiar su propia contraseña, un Administrador mantiene la sesión actual renovada. Las cuentas Super conservan rol/estado y cambian su propia clave desde Mi contraseña. Nadie puede desactivarse ni quitarse su propio rol desde esta edición. Se validan CSRF, token, duplicados y revisión del registro para detectar cambios simultáneos. La cuenta SQL requiere `SELECT, INSERT, UPDATE (nombre, usuario, email, rol, activo, clave_hash)` sobre `t_usuarios`; en demos nuevas los permisos ya se incluyen. No hay cambios de tablas ni migración SQL.
- **Empresas** permite a Super crear una empresa con su configuración inicial y abrir el alta de su primer usuario. Consulta la [guía de Super y empresas](../scripts/SUPER_Y_EMPRESAS.md) para preparar la primera cuenta, los permisos SQL y la migración.

La interfaz usa el logo TYT Systems y una paleta de azul (`#0864c9`), azul oscuro (`#060e1c`) y grises metálicos, con fondos claros. Los formularios y el menú se adaptan a pantallas pequeñas; el menú mantiene visible la opción Configuración, deshabilitada para roles sin permiso.

El acceso HTTP sin TLS solo está permitido desde loopback. Antes de habilitar una red real se requiere despliegue HTTPS y revisión de proxy, sesiones, límites de acceso y permisos. No se confían cabeceras de proxy. El servidor PHP integrado no es un servidor de producción. FastAPI permanece como servicio local simulado independiente, sin autenticación propia; no exponerlo a la red.

## Productos y presentaciones

La demo incluye los siete productos solicitados desde la imagen: ACEITE, CAFE CORRIENTE, CAFE GUACUCO, CAFE HÚMEDO, CAFE NATURAL, CAFE ORO SHG y CAFE RESACA. Se cargan para su empresa desde `scripts/productos_referencia.sql`; el script requiere establecer `@empresa_productos_referencia` en la misma conexión, omite nombres existentes y conserva sus estados. Por solicitud posterior, agrega **SACOS** a los productos activos de esa empresa, sin duplicar presentaciones existentes. No es una migración ni se ejecuta automáticamente sobre bases permanentes. No cambia el esquema `dbBascula.sql`.

En **Administración → Productos**, busca por nombre o crea un producto. Abre un producto para modificar su nombre o estado y administrar sus presentaciones. **Agregar presentación** abre el alta y **Editar** permite cambiar el nombre o desactivarla. Los registros permanecen guardados al desactivar. Cada producto y presentación pertenece al catálogo de la empresa de la sesión.

Super y Administrador pueden modificar. Los demás perfiles consultan el catálogo y las presentaciones. La cuenta SQL requiere `SELECT, INSERT, UPDATE` sobre `t_productos` y `t_producto_presentaciones`. Se utiliza el esquema existente. El guardado valida CSRF, nombres, duplicados y revisiones para detectar cambios simultáneos.

## Taras de empaque

En **Administración → Taras de empaque**, consulta el peso exacto de cada empaque o saco vacío. Super puede crear y editar registros con hasta tres decimales. **La tara unitaria no se redondea**: en Segunda pesada se multiplica por la cantidad y se redondea la tara total al entero más cercano, con .5 hacia arriba, antes de descontarla. Las notas históricas mantienen sus datos originales. Ver [regla, esquema y migración 005](../scripts/LIBRAS_ENTERAS.md).

Este catálogo es compartido por las empresas de la instalación según el esquema actual. Los demás perfiles lo consultan. Se conservan los registros y sus identificadores; el cambio del catálogo mantiene la tara unitaria aplicada que ya guarda cada pesada. La cuenta SQL requiere `SELECT, INSERT, UPDATE (nombre, taralbs)` sobre `t_taras`.

## Datos de la empresa

Abre **Empresa** en el menú de administrador para completar razón social, nombre comercial, RTN y dirección. La razón social es obligatoria. Se actualiza la empresa de la sesión y se detectan cambios simultáneos. La cuenta SQL requiere `SELECT` y `UPDATE (razon_social, nombre_comercial, rtn, direccion)` sobre `t_empresas`. Reinicia una demo abierta para recibir estos permisos en su base temporal.

## Pruebas

Las pruebas del ciclo automático del navegador se ejecutan con `node --test tests/test_live_weight.js`. Cubren inicio, consultas consecutivas sin solapamiento, errores, espera máxima, pausa, respuestas antiguas y expiración de sesión.

```powershell
.\.venv\Scripts\python.exe -m unittest discover -s tests -v
```

Las pruebas web inicializan MariaDB en una carpeta nueva `.runtime/web-test-*`, enlazada exclusivamente a `127.0.0.1` en un puerto libre. Usan empresas y contraseñas aleatorias y una cuenta SQL con los permisos limitados de la aplicación. Ejecutan PHP y FastAPI en puertos locales temporales y detienen sus procesos y MariaDB al finalizar. Los archivos aislados se conservan excluidos de Git.

Por defecto utilizan PHP de `C:\xampp\php\php.exe` y los binarios previamente disponibles en `.runtime/mariadb-package/mariadb-10.11.16-winx64/bin`. En otro equipo indicar `BASCULA_TEST_PHP` y `BASCULA_TEST_MYSQL_BIN`. Si no están disponibles, las pruebas web se marcan omitidas; las pruebas FastAPI siguen ejecutándose. No se descargan ni instalan binarios automáticamente.

Se comprueban acceso/salida, renovación y atributos de cookie, CSRF, expiración, aislamiento entre empresas, revocación por inactividad de cuentas, escape HTML, consultas ante inyección SQL, límite de intentos con cookies nuevas, formularios inválidos o sobredimensionados, archivos privados y lectura real del simulador vía PHP. También se verifican restricciones y migración de la configuración de báscula, y la ejecución real de `desplegar.ps1` con Windows PowerShell: respaldo, historial, aplicación única y conservación de ajustes. Esta última prueba usa configuración/respaldos sintéticos en una carpeta temporal externa al checkout, como exige el script; elimina su credencial al terminar y conserva los respaldos para diagnóstico. No cambia la política de ejecución ni las rutas de módulos del equipo.

## Primera pesada

Disponible desde Inicio y Trabajo. Permite fijar lectura estable simulada o peso manual, elegir bruto/tara primero y guardar la nota abierta en la base de datos de la empresa. Incluye compra/venta, persona, producto/presentación, responsable y vehículo. Ver [flujo, esquema y migración](../scripts/PRIMERA_PESADA.md). Las pruebas del navegador se ejecutan con `node --test tests/test_live_weight.js tests/test_first_weighing.js`.

## Segunda pesada

Disponible en Trabajo, Inicio y después de guardar Primera. Busca notas pendientes por placa, número y cliente/proveedor, con filtro compra/venta. Recupera la primera captura, determina el peso faltante y permite fijarlo en modo simulado o manual. Muestra bruto, tara, neto, tara de empaques y peso final; al guardar cierra la nota con origen, fecha, operador y observaciones independientes. Ver [flujo, cálculo, esquema, migración 004 y permisos](../scripts/SEGUNDA_PESADA.md). Las pruebas JavaScript se ejecutan con `node --test tests/test_live_weight.js tests/test_first_weighing.js tests/test_second_weighing.js`.

Segunda permite ingresar **Descuento por humedad (%)** y **Descuento por defectos (%)**, ambos sobre el neto sin sacos, con redondeo independiente a libras enteras. Muestra cada descuento, final y QQ (100 lb por quintal, dos decimales), y guarda los datos aplicados para consultarlos después. Ver [reglas, validación, esquema, migración 006 y permisos](../scripts/DESCUENTOS_Y_QUINTALES.md).

Pendientes: base permanente, anulaciones/cierre diario desde la interfaz, liquidación monetaria y consumo de la configuración desde el lector. Reportes por fechas y boleta imprimible/PDF ya están implementados. La conexión serial continúa pendiente.

## Reportes y boletas

**Consultas → Reportes y boletas** abre el reporte por rango de cierre con totales. Cada número abre su boleta, también accesible desde el cierre de Segunda. Incluye imprimir/reimprimir, PDF real guardado en `__pdfs` y compartir/adjuntar por WhatsApp. Super selecciona empresa; los demás perfiles consultan la suya. Sin cambios de esquema ni permisos SQL. PHP necesita `proc_open`, acceso a `.venv/Scripts/python.exe`, ReportLab instalado desde `requirements.txt` y escritura en `__pdfs`. Ver [guía completa](../scripts/REPORTES_Y_BOLETAS.md).

Pruebas JavaScript: `node --test tests/test_reports.js tests/test_live_weight.js tests/test_first_weighing.js tests/test_second_weighing.js`. La suite Python existente incorpora cinco pruebas de reportes sobre MariaDB aislada.

### Reportes por cliente/proveedor

En Reportes y boletas, elegir fechas, Tipo de persona y opcionalmente un Cliente / proveedor. Todos permite consultar el conjunto del tipo seleccionado. El catálogo incluye inactivos con historial. Los filtros y totales se conservan en impresión, PDF, WhatsApp y al volver desde una boleta. Super cambia de empresa desde el selector; los demás perfiles consultan únicamente la propia. Ver [reglas de clasificación y verificación](../scripts/REPORTES_Y_BOLETAS.md#filtros-por-clienteproveedor--2026-11-11).

## Dashboard

Acceso desde Trabajo y desde Inicio; utiliza la conexión existente a MariaDB. Comparte diseño, validación de fechas, formatos y reglas de reportes. Incluye filtros por empresa para Super, tarjetas, compras/ventas agrupadas y ordenadas alfabéticamente por producto, y evolución diaria. Cada producto suma todas sus presentaciones y muestra cantidad, libras finales y QQ. Pendientes actuales no dependen del rango de cierres. Ver [guía completa](../scripts/DASHBOARD.md). Sin cambios de instalación, dependencias ni migraciones.

### Enlace directo a WhatsApp — 2026-11-11

Usar Descargar PDF y después Abrir WhatsApp Web en computadora, o Abrir WhatsApp en móvil/tableta. Se abre un enlace con el título y filtros del documento como texto preparado; elegir el destinatario, adjuntar el PDF descargado y confirmar en WhatsApp. Abrir el enlace no genera PDF ni adjunta archivos automáticamente. Sustituye al menú nativo de compartir y mantiene los PDF privados en __pdfs.

### Prueba de adjunto PDF

En reportes y boletas: Probar compartir PDF → Compartir PDF preparado → elegir WhatsApp/contacto y confirmar. Usa el archivo PDF real y conserva sus filtros; al prepararlo se guarda en __pdfs. Requiere un navegador que admita compartir archivos y contexto HTTPS/localhost. Sin soporte, se informa y queda la descarga manual. Cancelar permite reintentar. Ver [guía de prueba](../scripts/REPORTES_Y_BOLETAS.md).

Probar compartir PDF y sus mensajes aparecen únicamente en dispositivos móviles/tabletas; en escritorio permanecen ocultos. El flujo de compartir el adjunto se conserva.

### Reimpresión de pesadas completas

Consultas → Reimprimir pesadas, con accesos en Inicio y Segunda pesada, lista las notas cerradas con ambas capturas. Buscar por número, placa o persona, opcionalmente compra/venta; incluye todo el historial en páginas de 50. Ver / reimprimir abre la boleta en otra pestaña: pulsar Imprimir / reimprimir. No cambia datos ni calcula nuevamente los descuentos. Super selecciona empresa; los demás perfiles solo la propia. Ver [guía](../scripts/REPORTES_Y_BOLETAS.md).

Al pulsar Guardar y cerrar nota en Segunda pesada, el cierre confirmado redirige a la boleta en la misma pestaña. Usar Imprimir / reimprimir para abrir el diálogo del navegador; errores de validación conservan el formulario y no abren la boleta.

### Organización del menú lateral — 2026-11-11

- General: Inicio y Dashboard.
- Trabajo: Primera pesada y Segunda pesada.
- Consultas: Reimprimir pesadas y Reportes y boletas.
- Administración: Clientes y proveedores, Productos y Taras de empaque para todos los perfiles según sus permisos de consulta/edición existentes; Usuarios para Super/Administrador y Empresas solo para Super.
- Mi empresa y Mi cuenta mantienen sus opciones y permisos anteriores. El menú conserva su desplazamiento vertical y la opción activa.

### Peso manual en Primera y Segunda

Solo admite dígitos del 0 al 9, con valores de 0 a 999999999. No escribir puntos, comas, espacios, signos ni exponentes. Un pegado inválido se rechaza completo conservando el último valor válido y exige fijar de nuevo el peso; nunca se transforma `1.5` en `15`. La validación se repite en el servidor. Las taras unitarias mantienen sus decimales.

La boleta muestra los números del desglose ampliados y Total QQ con mayor tamaño, negrita y destaque burdeos en pantalla, impresión y PDF. Los números largos se conservan completos y los cálculos no cambian. Ver [guía](../scripts/REPORTES_Y_BOLETAS.md).

## Reporte completo y Excel

El reporte incluye desglose y ficha operativa por pesada. Imprimir y PDF usan Carta horizontal; la boleta individual sigue vertical. Generar Excel descarga hojas Reporte y Datos con 42 columnas, filtros, paneles congelados y totales. No requiere dependencias nuevas ni migración. Ver scripts/REPORTES_Y_BOLETAS.md.
