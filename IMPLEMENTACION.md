# Guía Maestra de Implementación, Migración y Puesta en Producción — TYTOne Báscula

Documento único y definitivo para implementar desde cero, actualizar o trasladar el sistema **TYTOne Báscula (`tytBasculaAntigravity`)** en una **PC de producción con báscula física** y su espejo de consulta en **Hostinger**.  
*(Repositorio oficial: `https://github.com/luisteruelhn/tytBasculaAntigravity.git` — rama `main` para desarrollo/pruebas y rama `produccion` para entregas estables).*

---

## 1. Reglas y Restricciones Inquebrantables

1. **Cero modificaciones al Sistema Operativo Windows:**
   * **Prohibido** alterar el Registro de Windows, el Administrador de Dispositivos, parámetros permanentes de puertos `COM` en Windows, reglas existentes del router o servicios de un sistema anterior en coexistencia.
   * La sesión serial aplica velocidad y formato (`9600, 8N1`) **únicamente en memoria** sobre el *handle* abierto y restaura el estado original (`DCB`) al cerrar.
2. **Fuente predeterminada Real (`SERIAL`):**
   * Toda instalación o actualización debe dejar el indicador físico real como fuente predeterminada (`t_configuracion_bascula.modo = 'SERIAL'` y `estado_servicio["modo"] = "real"` en FastAPI).
   * Alternar temporalmente a **Simulado** nunca borra ni sobrescribe el `puerto_com`, `baudios` ni `protocolo` confirmados en MariaDB.
3. **Archivos privados que NUNCA se deben sobrescribir al actualizar:**
   * `web/config/local.php` *(credenciales locales o remotas de BD)*
   * `web/config/sync_remoto.php` *(URL y `SYNC_TOKEN` hacia Hostinger)*
   * `.env` / `.env.*`
   * `web/public/assets/img/logos/` *(logos subidos por la empresa)*
   * `dbbackup/` *(respaldos SQL locales)*
   * `__pdfs/` *(documentos emitidos locales, si existen)*
   * `.runtime/`, `runtime/` y `.venv/` *(runtimes y estado privado de la PC)*

---

## 2. Arquitectura Operativa Confirmada

* **Estación Local en Planta (PC de Báscula):**
  * **Servidor Web:** Apache de XAMPP (`Listen 80`), publicado mediante enlace *Junction* `C:\xampp\htdocs\bascula` → `<RUTA_PROYECTO>\web\public`.
  * **Base de Datos:** MariaDB de XAMPP (`127.0.0.1:3306`, base `dbBascula`, **12 tablas**).
  * **Hardware Serial:** Microservicio FastAPI (`127.0.0.1:8018`) ejecutado con `runtime\python\python.exe`, conectado al indicador físico (ej. `COM4`, `9600, 8N1`, protocolo `C18 = 4` o `LP7510_PC`, unidad `lb`).
* **Dispositivos Móviles en Red Local (LAN Wi-Fi):**
  * Celulares y tablets en la misma subred RFC 1918 (`192.168.x.x`, `10.x.x.x`, `172.16-31.x.x`) se detectan con `esAccesoPlantaLocal()` y operan al 100% (peso real en vivo + captura de pesadas) a través de Apache (`:80`) usando el puente autenticado `web/public/api_peso_local.php`, sin abrir el puerto `8018` ni requerir internet.
  * Acceso rápido mediante código QR SVG 100% offline (`web/public/assets/js/qr_local.js`) en `index.php` y `despliegue.php`.
* **Nube en Hostinger (Ej. `inversionesvallecillo.com`):**
  * Opera exclusivamente en **modo solo consulta** (rol `REMOTO` o dominio público no-LAN), alimentado por sincronización HTTPS *offline-first* (`web/src/sync_nube.php` → `web/public/api_sync.php`).

---

## 3. Verificación de Carpetas, GitHub y Publicación en `htdocs`

### 3.1. Estructura Requerida en la Carpeta del Proyecto
Confirmar que la carpeta destino (ej. `K:\TYTOneBasculaAntigravity`, `C:\TYTOneBasculaAntigravity` o `Z:\TYTOneBasculaAntigravity`) contenga:
* `.agents/skills/` — Copia portable de las 12 skills obligatorias del proyecto.
* `api/` — `main.py`, `serial_reader.py`, `simulator.py`.
* `runtime/python/` — Runtime Python 3.14 embebido portable (se distribuye aparte de Git porque `runtime/` está en `.gitignore`; véase sección `3.3`).
* `scripts/` — `dbBascula.sql`, `actualizar_bd_pc_bascula.sql`, `actualizar_chk_redondeo_arriba.sql`, `sincronizar_catalogos_maestros.php`, `probar_lectura_serial.py`.
* `web/config/` — `db.php`, `local.example.php`, `landing_hostinger.html`.
* `web/src/` — `auth.php`, `layout.php`, `sync_nube.php`.
* `web/public/` — Todos los módulos operativos (`index.php`, `login.php`, `logout.php`, `mi_clave.php`, `primera_pesada.php`, `segunda_pesada.php`, `pesadas.php`, `boleta.php`, `guia_remision.php`, `reportes.php`, `dashboard.php`, `clientes.php`, `categorias.php`, `productos.php`, `presentaciones.php`, `presentaciones_tipo.php`, `empresa.php`, `usuarios.php`, `config_bascula.php`, `despliegue.php`, `api_peso_local.php`, `api_bascula_control.php`, `api_sync.php`, `api_sync_pendientes.php`, `api_chat.php`, `serial_prueba.php` y `assets/`).

### 3.2. Enlace en `C:\xampp\htdocs\bascula` (*Junction*)
1. **Revisar primero si ya existe una carpeta o enlace previo** en `htdocs`:
   ```powershell
   Get-Item "C:\xampp\htdocs\bascula" -ErrorAction SilentlyContinue | Select-Object FullName, Target, LinkType
   ```
2. Si existe una instalación anterior en carpeta física, **respaldarla sin borrarla**:
   ```powershell
   Rename-Item "C:\xampp\htdocs\bascula" "C:\xampp\htdocs\bascula_respaldo"
   ```
3. Crear el enlace directo (*Junction*) apuntando a `web\public` de la ruta real en esa PC:
   ```cmd
   mklink /J "C:\xampp\htdocs\bascula" "K:\TYTOneBasculaAntigravity\web\public"
   ```
4. Verificar permisos de escritura en `<RUTA_PROYECTO>\web\public\assets\img\logos\` para permitir la carga del logotipo de la empresa desde `empresa.php`.

### 3.3. Runtime Python Portable (`runtime/python`)
Como `runtime/` está excluido de Git, en una PC nueva se debe copiar la carpeta `runtime/python` (paquete verificado `runtime-python-3.14.9-windows-x64.zip`, ver [PYTHON_PORTABLE.md](PYTHON_PORTABLE.md)) y comprobar su integridad antes de iniciar servicios:
```cmd
runtime\python\python.exe -B scripts\verificar_runtime_python.py
```

---

## 4. Baseline de XAMPP y Configuración de `php.ini`

### 4.1. Versiones de Referencia Auditadas
* **Apache:** `2.4.58 (Win64)` — `Listen 80`, `DocumentRoot "C:/xampp/htdocs"`.
* **PHP:** `8.2+` (auditado en `8.5.12 ZTS Visual C++ 2022 x64` local y `8.3.36` en Hostinger).
* **MariaDB:** `10.4+` (auditado en `10.11.16-MariaDB Win64`), puerto `3306`, `character_set_server=utf8mb4`, `lower_case_table_names=1`, `sql_mode=NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`.

### 4.2. Extensiones y Parámetros en `C:\xampp\php\php.ini`
Confirmar que las siguientes directivas estén activas (sin `;` al inicio):
* `extension=pdo_mysql` — **Obligatoria** (todas las consultas PDO a MariaDB).
* `extension=mysqli` — Utilidades compatibles en XAMPP.
* `extension=mbstring` — **Obligatoria** (manejo UTF-8, tildes y `Ñ`).
* `extension=curl` — **Obligatoria** (sincronización HTTPS a Hostinger y Asistente IA `api_chat.php`).
* `extension=zip` — Recomendada para empaquetado nativo `.zip` en `despliegue.php` (aunque incluye respaldo automático si no está activa).
* `extension=fileinfo` — Validación MIME de imágenes/logos subidos.
* `extension=php_openssl.dll` y `extension=php_ftp.dll` — Conexiones TLS/HTTPS salientes.
* **Límites verificados:** `memory_limit=512M`, `upload_max_filesize=40M`, `post_max_size=40M`, `max_execution_time=120`.
* **Zona horaria:** Aunque `web/config/db.php` aplica `date_default_timezone_set('America/Tegucigalpa')` en toda petición web, se recomienda fijar `date.timezone=America/Tegucigalpa` en `C:\xampp\php\php.ini` para consistencia en scripts CLI (reiniciar Apache tras editar `php.ini`).

---

## 5. Base de Datos MariaDB (`dbBascula`) — Instalación y Migración Segura

### 5.1. Inventario Completo de Tablas (`12` Tablas Obligatorias)
1. `t_empresas` *(incluye `rtn`, `direccion`, `telefono`, `email`, `logo`)*
2. `t_usuarios` *(ENUM `rol`: `'SUPER'`, `'ADMINISTRADOR'`, `'SUPERVISOR'`, `'OPERADOR'`, `'REMOTO'`)*
3. `t_configuracion_bascula` *(estación `'PRINCIPAL'`, `modo = 'SERIAL'`, `puerto_com`, `baudios`, `protocolo`, `unidad_indicador`, `parametros_confirmados`, `gemini_api_key`)*
4. `t_clie_prov` *(clientes y proveedores con `codigo`, `rtn`, `telefono`, `email`, `direccion`, `ciudad`, `es_cliente`, `es_proveedor`)*
5. `t_categorias` *(semilla base: `CAFE`, `ACEITES`, `GRANOS BASICOS`, `COQUILLO`, `SALVADO`)*
6. `t_presentaciones` *(**obligatoria SÍ O SÍ** en MariaDB con clave única `(empresa_id, codigo)` y registros base `SACOS` y `GRANEL`)*
7. `t_productos` *(incluye `categoria_id` y `codigo`; nombres normalizados sin acentos como `CAFE HUMEDO`, `MAIZ`)*
8. `t_producto_presentaciones` *(vínculo de presentaciones permitidas por producto)*
9. `t_taras` *(taras estándar de empaque: `0.25`, `0.50`, `1.00`, `1.50`, `2.00` lb)*
10. `t_pesadas` *(incluye `observaciones_segunda`, campos de Guía de Remisión: `guia_remision`, `motivo_traslado`, `motivo_traslado_otro`, `transportista_rtn`, `marca_vehiculo`, y constraints `chk_pesadas_pesos` / `chk_pesadas_descuentos` con `CEILING`)*
11. `t_pesada_anulaciones` *(auditoría de boletas anuladas)*
12. `t_sincronizacion_pendiente` *(cola JSON *offline-first* con `id_evento`, `entidad`, `enviado_at`)*

### 5.2. Caso A: Instalación Limpia (Base de Datos Nueva desde Cero)
Solo cuando la PC **no tiene** una base `dbBascula` previa:
```powershell
C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root -e "CREATE DATABASE IF NOT EXISTS dbBascula CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content "scripts\dbBascula.sql" -Raw | C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root dbBascula
```
*(Opcional)* Para poblar las 5 categorías, 13+ productos y 37 clientes/proveedores maestros de referencia:
```powershell
C:\xampp\php\php.exe scripts\sincronizar_catalogos_maestros.php
```

### 5.3. Caso B: Actualización sobre Base Existente en Producción (Cero Pérdida de Datos)
**NUNCA** importar `dbBascula.sql` encima de una base productiva si contiene `DROP` o reinicios. Seguir estrictamente este orden:

1. **Respaldo obligatorio previo:**
   ```powershell
   New-Item -ItemType Directory -Force -Path dbbackup | Out-Null
   $fecha = Get-Date -Format "yyyyMMdd_HHmmss"
   C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -P 3306 -u root dbBascula > "dbbackup\respaldo_pre_sync_$fecha.sql"
   ```
2. **Ejecutar migración estructural idempotente (`scripts/actualizar_bd_pc_bascula.sql`):**
   ```powershell
   Get-Content "scripts\actualizar_bd_pc_bascula.sql" -Raw | C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root dbBascula
   ```
   *Conserva intactas todas las pesadas, clientes, productos y el `puerto_com` real configurado*, asegurando las 12 tablas, columnas `telefono`/`email` en `t_empresas`, `gemini_api_key`, `observaciones_segunda`, columnas de Guía de Remisión y el rol `'REMOTO'`.
3. **Asegurar constraints de redondeo hacia arriba (`CEILING`) en `t_pesadas`:**
   ```powershell
   Get-Content "scripts\actualizar_chk_redondeo_arriba.sql" -Raw | C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root dbBascula
   ```
4. **Verificación SQL posterior:**
   ```sql
   SHOW TABLES;
   SELECT id, estacion, modo, puerto_com, baudios, protocolo, unidad_indicador, parametros_confirmados FROM t_configuracion_bascula;
   SHOW COLUMNS FROM t_empresas WHERE Field IN ('rtn', 'telefono', 'email');
   SHOW COLUMNS FROM t_pesadas WHERE Field IN ('observaciones_segunda', 'guia_remision', 'motivo_traslado');
   ```

### 5.4. Credenciales de Conexión (`web/config/db.php` y `local.php`)
* Por defecto, `web/config/db.php` conecta a `127.0.0.1:3306`, base `dbBascula`, usuario `root` sin contraseña (soportando también variables `BASCULA_DB_HOST`, `BASCULA_DB_PORT`, `BASCULA_DB_NAME`, `BASCULA_DB_USER`, `BASCULA_DB_PASS`).
* Si la PC de destino usa contraseña en MySQL o credenciales distintas, copiar `web/config/local.example.php` a **`web/config/local.php`** y ajustar `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`.

---

## 6. Hardware Serial RS-232, FastAPI (`:8018`) y Notas Técnicas Críticas

### 6.1. Parámetros Físicos de Referencia (Indicador LP7510 / C18)
* **Estación:** `'PRINCIPAL'` | **Modo BD:** `'SERIAL'` | **Confirmado:** `parametros_confirmados = 1`
* **Puerto COM:** El propio de la PC física (ej. `'COM4'` en Inversiones Vallecillo; **no copiar a ciegas el COM de otra PC**).
* **Comunicación:** `9600` baudios, `8` bits de datos, paridad `'N'`, `1` bit de parada (`8N1`), flujo `'NINGUNO'`.
* **Protocolo y Trama:** `'C18 = 4'` o `'LP7510_PC'`, unidad `'lb'` (ej. `ST,GS,+  15420 lb\r\n`).

### 6.2. Notas Técnicas Críticas de Funcionamiento (Para Futuras Intervenciones)
1. **Por qué se guarda y restaura el `DCB` en memoria (`api/serial_reader.py`):**
   * En Windows, el controlador de un puerto `COM` puede reportar valores por defecto antiguos (ej. `1200 baudios, 7N1`) cuando ningún programa lo tiene abierto. Si se leen bytes sin fijar `9600, 8N1` en la sesión, llegan caracteres corruptos (`42 6C 52`).
   * Por ello, `_configurar_sesion_serial()` captura el `dcb_original` con `GetCommState`, aplica `self.baudios` (`9600`) y `8N1` mediante `SetCommState` **solo sobre el handle abierto**, y `_restaurar_sesion_serial()` restaura el `dcb_original` dentro del bloque `finally` al cerrar el puerto. **Nunca tocar el Administrador de Dispositivos.**
2. **Sincronización automática MariaDB → FastAPI (`web/src/layout.php`):**
   * `api/main.py` arranca ligero sin conectarse a MariaDB (`modo = "real"`, `puerto_com = None`).
   * Al abrir **Inicio**, **Primera Pesada** o **Segunda Pesada**, `sincronizarBasculaApi()` consulta `/api/v1/salud`; si detecta que FastAPI aún no tiene `puerto_com` configurado o el lector está inactivo (y `pausado_manual == false`), le envía automáticamente `/api/v1/configurar_serial` y `/api/v1/modo` con los datos de `t_configuracion_bascula`.
3. **Control manual y coexistencia con otro programa (`api_bascula_control.php`):**
   * En Windows, un puerto `COM` es de acceso exclusivo para un solo proceso a la vez.
   * El botón **▶️ Arrancar Lectura / ⏹️ Parar Lectura** en `index.php` levanta `:8018` en segundo plano si está apagado o invoca `POST /api/v1/lector/detener` (activando `pausado_manual = true`) para liberar `COM4` inmediatamente sin que `layout.php` lo reabra por accidente.
4. **Prueba en vivo SSE (`serial_prueba.php`) vs Lector continuo:**
   * Al pulsar **Iniciar prueba en vivo** en `config_bascula.php`, `serial_prueba.php` detiene temporalmente el lector de FastAPI (`/api/v1/lector/detener`), ejecuta `scripts/probar_lectura_serial.py` transmitiendo eventos JSON por `EventSource` (SSE), y al terminar reanuda el lector (`/api/v1/lector/iniciar`).
5. **Tolerancia de fin de línea en `parsear_trama_lp7510()`:**
   * Divide tramas con `re.split(b"[\r\n]+", ...)` y acepta `lb`, `lbs` o `kg` con espacios variables, ya que algunos indicadores envían solo `\r` en lugar de `\r\n`.

---

## 7. Acceso Móvil en Red Local (LAN Wi-Fi) y QR Offline

1. Confirmar que la PC de báscula esté conectada a la red privada local (Ethernet o Wi-Fi con perfil de red **Privado** en Windows) y que Apache de XAMPP escuche en `0.0.0.0:80` con regla entrante permitida en el Firewall de Windows para la red privada.
2. FastAPI (`127.0.0.1:8018`) y MariaDB (`127.0.0.1:3306`) **permanecen cerrados en loopback**.
3. En `index.php` (**📱 Abrir en celular (QR)**) o `despliegue.php`, `obtenerUrlRedLocal()` detecta la IPv4 LAN activa (ej. `http://192.168.2.20/bascula/`) y `qr_local.js` dibuja el QR en SVG sin internet.
4. Se recomienda asignar **reserva DHCP o IP fija** en el router local a la PC de báscula para que la URL del QR nunca cambie.

---

## 8. Sincronización y Espejo en Nube (Hostinger)

Resumen de integración (para el paso a paso ilustrado en hPanel ver **[desplieguehostinger.md](desplieguehostinger.md)**):
1. Crear sitio PHP vacío (`PHP 8.2+`) y base MySQL en Hostinger; importar respaldo inicial generado desde `http://localhost/bascula/despliegue.php`.
2. Si se crearán usuarios exclusivos directamente en la nube, ejecutar en `phpMyAdmin` de Hostinger:
   ```sql
   ALTER TABLE t_usuarios AUTO_INCREMENT = 1000;
   ```
3. Desplegar archivos en `public_html` mediante una de las dos vías:
   * **Vía Git (recomendada):** Conectar Hostinger (`Avanzado → Git`) al repo `tytBasculaAntigravity.git` en la rama **`produccion`** y colocar `public_html/web/config/local.php` con `DB_*` y `SYNC_TOKEN`. El `.htaccess` e `index.php` de la raíz muestran la portada institucional en `/` y sirven el sistema en `/bascula/`.
   * **Vía Paquete `.zip`:** Generar y descargar el `.zip` desde `despliegue.php` (inyecta automáticamente `config/local.php` y `.htaccess`) y extraerlo en `public_html`.
4. Verificar que el **`SYNC_TOKEN`** sea idéntico entre `web/config/sync_remoto.php` (PC local) y `local.php` (Hostinger).
5. En `despliegue.php` o en el botón **☁️ Sincronizar con la Nube** de `index.php`, probar el envío completo espejo (sincroniza catálogos, usuarios y pesadas; el timer automático de `layout.php` reintenta pendientes cada 5 minutos).

---

## 9. Automatización en el Inicio de Windows (`shell:startup`)

1. **Autoarranque de Apache y MySQL (Ya implementado y verificado en PC de producción):**
   * En lugar de depender de `C:\xampp\xampp-control.ini` (que requiere permisos elevados de Administrador y suele fallar al guardar en Windows con UAC), el inicio automático se realiza en `shell:startup` (`%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\`) mediante:
     * **`XAMPP Control.lnk`** → acceso directo a `C:\xampp\xampp-control.exe`.
     * **`Iniciar Apache y MySQL.vbs`** → lanza ambos motores en segundo plano sin ventana (`0`) al iniciar sesión:
       ```vbscript
       Set WshShell = CreateObject("WScript.Shell")
       WshShell.CurrentDirectory = "C:\xampp"
       WshShell.Run """C:\xampp\apache\bin\httpd.exe""", 0, False
       WshShell.Run """C:\xampp\mysql\bin\mysqld.exe"" --defaults-file=mysql\bin\my.ini --standalone", 0, False
       ```
2. **Autoarranque oculto de FastAPI (`:8018`) para el Corte Definitivo:**
   * *(Activar únicamente cuando el sistema anterior ya no se use en esa PC, para no disputar el puerto `COM` al encender).*
   * Presionar `Win + R` → escribir `shell:startup` → crear un acceso directo o script `.vbs`/`.cmd` que ejecute en `<RUTA_PROYECTO>`:
     ```powershell
     powershell.exe -NoProfile -WindowStyle Hidden -Command "Start-Process -FilePath 'K:\TYTOneBasculaAntigravity\runtime\python\python.exe' -ArgumentList '-B','-m','uvicorn','api.main:app','--host','127.0.0.1','--port','8018' -WorkingDirectory 'K:\TYTOneBasculaAntigravity' -WindowStyle Hidden"
     ```

---

## 10. Checklist Maestro de Verificación (12 Pruebas)

Antes de entregar la estación al operador, validar en orden:

- [ ] **1. Login y Tema:** Abrir `http://localhost/bascula/login.php`, confirmar carga en **Modo Claro** por defecto, alternancia a Modo Oscuro e inicio de sesión correcto.
- [ ] **2. Salud de FastAPI y Lectura Real:** Pulsar **▶️ Arrancar Lectura** (o verificar `http://127.0.0.1:8018/api/v1/salud`), confirmar badge `REAL` y recepción del peso real en `lb` desde el indicador físico en **Inicio**.
- [ ] **3. Diagnóstico SSE (`config_bascula.php`):** Probar **Iniciar prueba en vivo**, verificar recepción de tramas reales del `COM`, detenerla y comprobar que **Inicio** retoma la lectura continua automáticamente.
- [ ] **4. Primera Pesada (`primera_pesada.php`):** Probar buscador instantáneo de Cliente/Proveedor y Producto, sugerencia automática de dirección y de `CEIBITA SUR, SANTA BARBARA`, preselección de `SACOS` (en Café) y `A GRANEL` + toggle **⚡ Pesada Única** sugerido (en Aceite).
- [ ] **5. Segunda Pesada y Redondeo (`segunda_pesada.php`):** Seleccionar nota abierta, verificar preselección de saco (`0.50 lb` en Compra / `0.25 lb` en Venta), redondeo hacia arriba (`CEILING`) en tara de sacos, `%H` y `%D`, y bloque **Guía de Remisión** con los 15 motivos en `VENTA`.
- [ ] **6. Boleta Oficial (`boleta.php`):** Verificar logo a la izquierda, **RTN** bajo la dirección (en Compra y Venta), **Imprimir** en 1 sola hoja Carta con **2 copias** y línea de corte `✂`, y **Descargar PDF** con **1 sola boleta limpia**.
- [ ] **7. Guía de Remisión (`guia_remision.php`):** Abrir desde una venta cerrada, comprobar casilla `[✓]` del motivo, peso en QQ y en letras, 2 copias por hoja Carta al imprimir y 1 copia limpia en PDF.
- [ ] **8. Reportes (`reportes.php`):** Filtrar por **Categoría → Producto** y probar la tríada completa (**Imprimir**, **Descargar PDF** y **Generar Excel**).
- [ ] **9. Acceso Móvil LAN y QR Offline:** Escanear **📱 Abrir en celular (QR)** desde un teléfono en el mismo Wi-Fi y comprobar lectura de peso en vivo y ergonomía móvil (indicador arriba y recuadro de peso fijado visible).
- [ ] **10. Rol `REMOTO` (Solo Consulta):** Ingresar con usuario de rol `REMOTO` (ej. `mariajose`) y verificar bloqueo total de pesaje/edición y visualización del último peso registrado.
- [ ] **11. Sincronización Nube:** Pulsar **☁️ Sincronizar con la Nube** y confirmar que la pesada se refleja en `https://inversionesvallecillo.com/bascula/` y que `t_sincronizacion_pendiente` queda con `0` pendientes sin enviar.
- [ ] **12. Pie de página (`layout.php`):** Confirmar leyenda `TYTOne Santa Bárbara Cel:+504 95173632 © 2026 — Sistema de Pesaje Electrónico Industrial`.

---

## 11. Protocolo Rápido para Futuras Actualizaciones Manuales en Planta

Cada vez que se lleve una actualización nueva a la PC de producción:
1. **Respaldar primero:** `mysqldump` de `dbBascula` hacia `dbbackup/` y verificar que `web/config/local.php`, `web/config/sync_remoto.php` y `web/public/assets/img/logos/` estén resguardados.
2. **Revisar estado activo:** Confirmar el `puerto_com` y `modo = 'SERIAL'` actuales en `t_configuracion_bascula` antes de tocar archivos.
3. **Actualizar código:** Traer cambios desde la rama `produccion` (`git pull` o copia controlada excluyendo los archivos privados de la Sección 1.3).
4. **Aplicar migraciones idempotentes (si hubo cambios SQL):** Ejecutar únicamente scripts incrementales seguros (`actualizar_bd_pc_bascula.sql` / nuevos `ALTER` idempotentes), nunca un dump destructivo.
5. **Validar sintaxis y lectura real:** Correr `php -l` sobre archivos modificados, confirmar `modo = 'SERIAL'` en la base y verificar lectura real en `index.php`.
6. **Reversión segura (en caso de contingencia):** Detener `:8018`, volver al commit anterior en Git (o restaurar `C:\xampp\htdocs\bascula_respaldo`) sin sobrescribir `dbBascula` salvo que la migración puntual lo exija (considerando siempre las pesadas registradas en el intermedio).
