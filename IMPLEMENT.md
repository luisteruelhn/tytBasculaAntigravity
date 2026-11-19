# Guía de Implementación Local y Sincronización Manual — TYTOne Báscula

Guía detallada paso a paso para implementar, configurar, sincronizar manualmente y verificar el sistema en la **PC de producción con báscula física** (XAMPP + repositorio local).  
*(Repositorio GitHub de referencia: `https://github.com/luisteruelhn/tytBasculaAntigravity.git` — rama `main` para pruebas, `produccion` para entregas estables).*

---

## 1. Verificación de Carpetas, GitHub y `htdocs`

### 1.1. Carpeta del Proyecto y Ramas GitHub
Confirmar que la carpeta del proyecto (ej. `K:\TYTOneBasculaAntigravity` o `Z:\TYTOneBasculaAntigravity` según la unidad de esa PC) contenga la estructura completa y el repositorio clonado/sincronizado:
- `.agents/skills/` — Copia portable de las skills obligatorias del proyecto (viaja en el repositorio).
- `api/` — Servicio ligero FastAPI (`:8018`) para lectura del indicador serial RS232 (`main.py`, `serial_reader.py`, `simulator.py`).
- `scripts/` — Esquema SQL (`dbBascula.sql`), actualización idempotente (`actualizar_bd_pc_bascula.sql`) y utilidades.
- `web/config/` — `db.php` y `local.example.php`.
- `web/src/` — `auth.php` y `layout.php`.
- `web/public/` — Interfaz web completa:
  - `index.php`, `login.php`, `logout.php`, `mi_clave.php`
  - `primera_pesada.php`, `segunda_pesada.php`, `pesadas.php`
  - `boleta.php`, `guia_remision.php`, `reportes.php`, `dashboard.php`
  - `clientes.php`, `categorias.php`, `productos.php`, `presentaciones.php`, `presentaciones_tipo.php`
  - `empresa.php`, `usuarios.php`, `config_bascula.php`, `despliegue.php`, `api_chat.php`
  - `assets/` (CSS, JS e imágenes, incluyendo `assets/img/logos/`).

### 1.2. Archivos que NUNCA se deben sobrescribir al sincronizar manualmente
Antes de copiar archivos o hacer sincronización manual en la PC de producción, **resguardar y excluir**:
- `web/config/local.php` (credenciales privadas de BD si existen en ese equipo).
- `.env` / `.env.*`
- `web/public/assets/img/logos/` (logos subidos por la empresa).
- `dbbackup/` (respaldos SQL locales).
- `.runtime/` y `.venv/` (entorno Python local del hardware serial).

### 1.3. Publicación en `htdocs` (XAMPP)
En la PC de referencia auditada, `C:\xampp\htdocs\bascula` apunta por *Junction* a `K:\TYTOneBasculaAntigravity\web\public`.  
En la PC de producción, **verificar primero** hacia qué unidad/carpeta (`Z:\`, `C:\` o `K:\`) apunta actualmente `C:\xampp\htdocs\bascula`:
```powershell
Get-Item "C:\xampp\htdocs\bascula" | Select-Object FullName, Target, LinkType
```
- Si no existe el enlace y se desea crear como Junction hacia la ruta real de esa PC:
  ```cmd
  mklink /J "C:\xampp\htdocs\bascula" "<RUTA_PROYECTO>\web\public"
  ```
- Confirmar permisos de escritura en `<RUTA_PROYECTO>\web\public\assets\img\logos\` para poder subir el logo de la empresa.

---

## 2. Baseline Real de XAMPP (Auditado) y Extensiones PHP

### 2.1. Stack Verificado en XAMPP
Referencia comprobada en esta PC de desarrollo/pruebas:
- **Apache:** `2.4.58 (Win64)` — `Listen 80`, `ServerName localhost:80`, `DocumentRoot "C:/xampp/htdocs"`.
- **PHP:** `8.5.12 (ZTS Visual C++ 2022 x64)`.
- **MariaDB:** `10.11.16-MariaDB (Win64)` — puerto `3306`, `character_set_server=utf8mb4`, `lower_case_table_names=1`, `sql_mode=NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`.

En el panel de control de XAMPP de la PC de producción, iniciar y dejar en ejecución:
- **Apache** (puerto `80` o el asignado).
- **MySQL / MariaDB** (puerto `3306`).

### 2.2. Parámetros y Extensiones en `C:\xampp\php\php.ini`
Verificar que estén activos y sin `;` al inicio:
- `extension=pdo_mysql` — Obligatoria para todas las consultas PDO a MariaDB.
- `extension=mysqli` — Activa en XAMPP.
- `extension=mbstring` — Obligatoria para tildes/UTF-8 (`CAFÉ`, `GUÍA`, `Ñ`).
- `extension=curl` — Obligatoria para el **Asistente TYTOne** (`api_chat.php` → Google Gemini).
- `extension=zip` — Necesaria para generar paquetes `.zip` desde **Despliegue / Nube**.
- `extension=fileinfo` — Validación segura de archivos/imágenes subidas.
- `extension=php_openssl.dll` y `extension=php_ftp.dll` — Conexiones SSL/FTP si se usa subida remota.
- Límites verificados: `memory_limit=512M`, `upload_max_filesize=40M`, `post_max_size=40M`, `max_execution_time=120`.
- **Nota de Zona Horaria:** XAMPP trae `date.timezone=Europe/Berlin` por defecto en `php.ini`. Aunque `web/config/db.php` fuerza `date_default_timezone_set('America/Tegucigalpa')` en tiempo de ejecución web, se recomienda ajustar `date.timezone=America/Tegucigalpa` en `C:\xampp\php\php.ini` para consistencia en scripts CLI.

*(Si se edita `php.ini`, reiniciar Apache en XAMPP para aplicar cambios).*

---

## 3. Base de Datos MariaDB Local (`dbBascula`) y Sincronización Segura

### 3.1. Inventario de Tablas Verificado (`12` tablas)
Esquema completo validado en `dbBascula`:
1. `t_empresas` *(incluye `telefono` y `email` usados en `empresa.php`, `boleta.php` y `guia_remision.php`)*
2. `t_usuarios` *(ENUM `rol`: `SUPER`, `ADMINISTRADOR`, `SUPERVISOR`, `OPERADOR`, `REMOTO`)*
3. `t_configuracion_bascula` *(incluye `gemini_api_key`)*
4. `t_clie_prov`
5. `t_categorias` *(semilla base: `CAFE`, `ACEITES`, `GRANOS_BASICOS`)*
6. `t_presentaciones` *(semilla base: `SACOS`, `GRANEL`)*
7. `t_productos` *(incluye `categoria_id`)*
8. `t_producto_presentaciones`
9. `t_taras` *(5 taras estándar: `0.25`, `0.50`, `1.00`, `1.50`, `2.00` lb)*
10. `t_pesadas` *(incluye `observaciones_segunda` y campos de Guía de Remisión: `guia_remision`, `motivo_traslado`, `motivo_traslado_otro`, `transportista_rtn`, `marca_vehiculo`)*
11. `t_pesada_anulaciones`
12. `t_sincronizacion_pendiente`

### 3.2. Referencia de Parámetros de Báscula Observados (NO sobrescribir en producción)
En la BD auditada se observó:
- `estacion='PRINCIPAL'`, `modo='SERIAL'`, `puerto_com='COM9'`, `baudios=9600`, `bits_datos=8`, `paridad='N'`, `bits_parada=1.0`, `control_flujo='NINGUNO'`, `protocolo='C18 = 4'`, `unidad_indicador='lb'`, `parametros_confirmados=1`.
- **Regla crítica:** En la PC de producción, **conservar su propio `puerto_com`, protocolo y parámetros físicos**, manteniendo siempre `modo='SERIAL'` (indicador real).

### 3.3. Base de datos nueva (instalación limpia)
1. Crear la base de datos `dbBascula` (`utf8mb4_unicode_ci`).
2. Importar el esquema completo desde `scripts/dbBascula.sql`.

### 3.4. Actualización sobre una base de datos existente (PC de producción)
1. **Respaldo obligatorio previo:**
   ```powershell
   C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -P 3306 -u root dbBascula > dbbackup\respaldo_pre_sync.sql
   ```
2. **Ejecutar el script idempotente** **[scripts/actualizar_bd_pc_bascula.sql](scripts/actualizar_bd_pc_bascula.sql)**:
   ```powershell
   Get-Content "scripts\actualizar_bd_pc_bascula.sql" -Raw | C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3306 -u root dbBascula
   ```
   Este script **NO borra pesadas, clientes, productos ni altera el puerto COM / Indicador Real configurado**, y asegura:
   - Columnas `telefono` y `email` en `t_empresas`.
   - Tablas `t_categorias` y `t_presentaciones` con sus semillas base (si faltan).
   - Columna `categoria_id` en `t_productos`.
   - Columna `gemini_api_key` en `t_configuracion_bascula`.
   - Columnas `observaciones_segunda` y de **Guía de Remisión** en `t_pesadas` (`guia_remision`, `motivo_traslado`, `motivo_traslado_otro`, `transportista_rtn`, `marca_vehiculo`).
   - Rol `'REMOTO'` en `t_usuarios` y alta del usuario `maria.jose` (solo consulta).
3. **Verificación SQL posterior:**
   ```sql
   SHOW TABLES;
   SELECT id, estacion, modo, puerto_com, baudios, protocolo, unidad_indicador, parametros_confirmados FROM t_configuracion_bascula;
   SHOW COLUMNS FROM t_empresas WHERE Field IN ('telefono', 'email');
   SHOW COLUMNS FROM t_pesadas WHERE Field IN ('observaciones_segunda', 'guia_remision', 'motivo_traslado');
   ```

---

## 4. Configuración de Conexión (`web/config/db.php` y `local.php`)
- Por defecto, `web/config/db.php` conecta a `127.0.0.1:3306`, base `dbBascula`, usuario `root`, sin contraseña, y fija zona horaria `America/Tegucigalpa`.
- Si en la PC de producción la contraseña de `root` o el nombre de BD es distinto:
  1. Copiar `web/config/local.example.php` como **`web/config/local.php`**.
  2. Ajustar `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`.

---

## 5. Servicio de Báscula / Hardware Serial (FastAPI `:8018` + Python)
1. Confirmar que el entorno Python (`.venv` o `runtime/python`) tenga instaladas las dependencias (`fastapi`, `uvicorn`, `pyserial`).
2. Iniciar el microservicio en `api/` en el puerto `8018`:
   ```powershell
   .\.venv\Scripts\python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 8018
   ```
3. Verificar en **Configuración Báscula** (`config_bascula.php`):
   - **Fuente predeterminada:** **Indicador Real** (`SERIAL`, no cambiar a simulación salvo prueba explícita).
   - Parámetros físicos del indicador: Puerto `COM`, baudios (`9600`), bits de datos (`8`), paridad (`N`), bits de parada (`1`).
   - Si se usa el Asistente IA, confirmar que `gemini_api_key` siga guardada en esta pantalla.

---

## 6. Pruebas de Verificación Local (Checklist Paso a Paso)
Ejecutar en orden antes de dar por terminada la implementación:

1. **Acceso:** Abrir `http://localhost/bascula/login.php` e iniciar sesión (comprobar ilustración limpia en modo oscuro y claro).
2. **Monitor en vivo:** Confirmar en `index.php` que el peso en vivo responde desde el indicador real (`:8018`).
3. **Primera Pesada (`primera_pesada.php`):**
   - Seleccionar `VENTA` / `COMPRA` con el switch, cliente, producto y confirmar que **Origen / Punto de partida** sugiere `CEIBITA SUR, SANTA BARBARA` en `VENTA`.
   - Probar el modo **⚡ Pesada Única**: verificar que al seleccionar **ACEITE** se active automáticamente como sugerencia (pudiendo desmarcarse si aplica segunda pesada), permitiendo liquidar y cerrar en un solo paso.
4. **Segunda Pesada (`segunda_pesada.php`):**
   - Seleccionar la venta abierta y verificar que aparece el bloque **Guía de Remisión** (`guia_remision`, listbox `motivo_traslado` con los 15 ítems, `marca_vehiculo`, `transportista_rtn`).
   - Cerrar la nota.
5. **Boleta (`boleta.php`) e Historial (`pesadas.php`):**
   - Verificar logo a la izquierda y teléfono/email de la empresa.
   - En `pesadas.php`, verificar que en las ventas cerradas aparezca el botón **🚚 Guía Remisión**.
   - **Imprimir Boletas:** debe salir en **1 sola hoja Carta** con 2 copias y línea de corte con tijera al centro.
   - **Descargar PDF:** debe descargar **1 sola boleta limpia** en PDF.
6. **Guía de Remisión (`guia_remision.php`):**
   - Entrar desde el botón **🚚 Guía de Remisión** en la boleta o historial de venta.
   - Verificar casilla `[✓]` en el motivo seleccionado, datos del bien en QQ, peso en letras, **2 copias en 1 sola hoja Carta** con línea de corte al imprimir y **1 copia limpia** en PDF.
7. **Reportes (`reportes.php`):**
   - Probar filtros de **Categoría** → **Producto** (solo muestra productos de la categoría elegida).
   - Probar **Imprimir**, **Descargar PDF** y **Generar Excel**.
8. **Asistente TYTOne (si aplica):**
   - Visible solo para `SUPER` / `ADMINISTRADOR` / `REMOTO`; probar consulta de pesadas y teléfonos de clientes.
9. **Usuario Remoto (`maria.jose` / rol `REMOTO`):**
   - Probar login con `maria.jose` y verificar que solo tenga acceso a **Inicio**, **Consultas** y **Catálogos en modo lectura** (sin botones de pesaje ni edición/creación).
10. **Pie de página (`layout.php`):**
    - Confirmar texto `TYTOne Santa Bárbara Cel:+504 95173632 © 2026 — Sistema de Pesaje Electrónico Industrial`.

---

## 7. Protocolo de Sincronización Manual en PC de Producción
Orden exacto a seguir al llegar a la PC de planta:
- [ ] **1. Respaldar BD actual:** ejecutar `mysqldump` de `dbBascula` a `dbbackup\respaldo_pre_sync.sql` y respaldar `web\config\local.php` (si existe) y `web\public\assets\img\logos\`.
- [ ] **2. Revisar instalación activa:** verificar `C:\xampp\htdocs\bascula`, puerto `COM` en `t_configuracion_bascula` y diferencias locales antes de sobrescribir.
- [ ] **3. Sincronizar archivos:** actualizar el código desde `produccion` (Git o copia manual) **sin sobrescribir** `web/config/local.php`, `.env`, `web/public/assets/img/logos/`, `dbbackup/`, `.runtime/` ni `.venv/`.
- [ ] **4. Validar XAMPP / `php.ini`:** confirmar Apache + MySQL activos y extensiones (`pdo_mysql`, `mbstring`, `curl`, `zip`, `fileinfo`).
- [ ] **5. Actualizar esquema BD:** ejecutar `scripts\actualizar_bd_pc_bascula.sql` y correr la consulta de verificación de la sección `3.4`.
- [ ] **6. Validar hardware y pruebas:** confirmar `t_configuracion_bascula.modo = 'SERIAL'` con el `COM` real, servicio FastAPI `:8018` activo y completar la lista del Paso 6.
