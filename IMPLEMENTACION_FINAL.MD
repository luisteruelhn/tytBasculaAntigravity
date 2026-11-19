# IMPLEMENTACION_FINAL — Puesta en Marcha Local en PC de Producción

**Fecha:** 2026-11-19 (America/Tegucigalpa)  
**Repositorio:** `k:\TYTOneBasculaAntigravity` (rama `main`)  
**Último commit de referencia previo a hoy:** `e627ef7` — *docs(implement): documentar auditoria de DB y XAMPP con guia de sincronizacion manual para PC de produccion*

---

## 1. Arquitectura Operativa Confirmada

* **Operación 100% local en planta (esta PC):**
  * **Web:** Apache de XAMPP (`http://localhost/bascula/`), servido por enlace directo (*Junction*) `C:\xampp\htdocs\bascula` → `k:\TYTOneBasculaAntigravity\web\public`.
  * **Base de datos:** MariaDB de XAMPP (`127.0.0.1:3306`, base `dbbascula`, 12 tablas).
  * **Hardware serial:** Microservicio FastAPI (`127.0.0.1:8018`) sobre `runtime\python\python.exe`, conectado al indicador real en **`COM4`** (`9600, 8N1`, protocolo `C18 = 4`, fuente predeterminada **`SERIAL` / Real**).
* **Nube (Hostinger):** Reservada únicamente para **consulta remota** (rol `REMOTO`), sincronizando pesadas desde la PC local.
* **Integridad del sistema anterior:** No se modificó el registro de Windows, Administrador de Dispositivos, firewall ni puertos del sistema anterior.

---

## 2. Commits Realizados Hoy (`2026-11-19`)

| Commit | Descripción |
| :--- | :--- |
| `55df016` | `docs(bitacora): registrar respaldo y actualizacion segura de dbbascula en produccion` |
| `74b1afe` | `feat(api): modo real por defecto, manejo seguro de DCB en sesion y endpoints lector` |
| `9cd44ed` | `feat(web): prueba serial SSE en vivo, sincronizacion FastAPI y modo real predeterminado` |
| *(actual)* | `docs(implementacion): crear IMPLEMENTACION_FINAL.MD y actualizar bitacora` |

---

## 3. Detalle de Cambios Aplicados Hoy

### A. XAMPP y Base de Datos (`dbbascula`)
1. **Respaldo previo:** Generado en `dbbackup/respaldo_pre_sync_20261119.sql` (`53,292` bytes).
2. **Actualización idempotente (`scripts/actualizar_bd_pc_bascula.sql`):**
   * Esquema completado de 10 a **12 tablas** (`t_categorias`, `t_presentaciones`, columnas `telefono`/`email` en `t_empresas`, campos de Guía de Remisión y `observaciones_segunda` en `t_pesadas`, `gemini_api_key`, rol `REMOTO` y usuario `maria.jose`).
   * Conservados intactos: `4` pesadas existentes, empresa `INVERSIONES VALLECILLO, SB`, usuario `admin` (`SUPER`) y configuración física real (`modo = 'SERIAL'`, `puerto_com = 'COM4'`, `baudios = 9600`, `protocolo = 'C18 = 4'`).
3. **Publicación en `htdocs`:**
   * Carpeta anterior respaldada en `C:\xampp\htdocs\bascula_respaldo`.
   * Creado *Junction* `C:\xampp\htdocs\bascula` → `k:\TYTOneBasculaAntigravity\web\public`.

### B. Microservicio Hardware (`api/serial_reader.py` y `api/main.py`)
* **`api/serial_reader.py`:**
  * Agregados helpers `_configurar_sesion_serial()` y `_restaurar_sesion_serial()` para guardar el `DCB` original con `GetCommState`, aplicar `9600, 8N1` (`SetCommState`) únicamente durante la sesión abierta y restaurar el `DCB` original en `finally` al cerrar el puerto.
  * `parsear_trama_lp7510()` tolera finales de línea `\r` o `\n`, espacios opcionales y unidades `lb`/`lbs`/`kg`.
* **`api/main.py`:**
  * `estado_servicio["modo"]` inicializado en `"real"`.
  * `/api/v1/salud` incluye `"puerto_com"`.
  * Agregados `POST /api/v1/lector/detener` y `POST /api/v1/lector/iniciar` para liberar temporalmente `COM4` durante pruebas en vivo.

### C. Capa Web y Prueba Serial SSE (`web/`)
* **`web/public/serial_prueba.php`:** Nuevo endpoint SSE autenticado (bloquea `REMOTO`) que detiene temporalmente el lector de FastAPI, ejecuta `scripts/probar_lectura_serial.py` en solo lectura enviando parámetros por `stdin`, transmite eventos JSON al navegador y reanuda el lector al finalizar.
* **`web/src/layout.php`:** Agregados `notificarApiBascula()` y `sincronizarBasculaApi()` para sincronizar `modo`, `puerto_com` y `baudios` de MariaDB hacia FastAPI (`127.0.0.1:8018`) al cargar vistas con monitor de peso.
* **`web/public/config_bascula.php`:** Reemplazado el temporizador simulado de `iniciarPruebaEco()` / `detenerPruebaEco()` por `EventSource` real hacia `serial_prueba.php`, y sincronización inmediata con FastAPI al guardar o cambiar entre **Real** y **Simulado**.
* **`web/public/index.php` y `web/public/assets/js/pesaje.js`:** Badge inicial en `REAL` y visualización controlada de mensajes `datos.error` en el monitor.

---

## 4. Verificaciones Comprobadas

1. **Sintaxis Python (`ast.parse`):** `api/main.py` y `api/serial_reader.py` sin errores.
2. **Sintaxis PHP (`C:\xampp\php\php.exe -l`):** `serial_prueba.php`, `config_bascula.php`, `index.php` y `layout.php` sin errores.
3. **Apache (`http://localhost/bascula/login.php`):** Responde **HTTP 200**.
4. **FastAPI (`http://127.0.0.1:8018`):**
   * `/api/v1/salud` → `{"ok":true,"modo":"real","puerto_com":null,"lector_activo":false}`
   * `/api/v1/puertos` → `["COM1","COM3","COM4"]`

---

## 5. Pendientes para el Despliegue Final (Corte Definitivo)

1. **Arranque automático de FastAPI (`:8018`) al iniciar Windows:**
   * Agregar acceso directo en la carpeta de Inicio de Windows (`shell:startup`) para levantar `runtime\python\python.exe -B -m uvicorn api.main:app --host 127.0.0.1 --port 8018` automáticamente junto con XAMPP **únicamente cuando se retire el sistema anterior**, ya que `COM4` en Windows es de uso exclusivo para un solo programa a la vez.
2. **XAMPP Control (`C:\xampp\xampp-control.ini`):**
   * Confirmar en `Config → Autostart of modules` que **Apache** y **MySQL** estén marcados para arrancar solos al abrir XAMPP con Windows.
3. **Hostinger (`inversionesvallecillo.com`):**
   * En `phpMyAdmin`, confirmar `ALTER TABLE t_usuarios AUTO_INCREMENT = 1000;` si se crearán usuarios exclusivos de la nube.
   * Actualizar `public_html` con el `.zip` o rama `produccion` y verificar `SYNC_TOKEN` idéntico entre `public_html/config/local.php` y `web/config/sync_remoto.php`.
