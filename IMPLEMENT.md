# Guía de Implementación Local — TYTOne Báscula

Guía detallada paso a paso para implementar, configurar y verificar el sistema en el entorno **local** (PC de báscula con XAMPP + `Z:\TYTOneBasculaAntigravity`).  
*(Nota: El despliegue web/nube se gestionará aparte vía GitHub usando la rama `produccion`).*

---

## 1. Verificación de Carpetas (`Z:\TYTOneBasculaAntigravity` y `htdocs`)

### 1.1. Carpeta del Proyecto (`Z:\TYTOneBasculaAntigravity`)
Confirmar que existan los directorios principales completos:
- `api/` — Servicio ligero FastAPI para lectura del indicador serial RS232.
- `scripts/` — Esquema SQL (`dbBascula.sql`) y utilidades de configuración/seed.
- `web/config/` — `db.php` y `local.example.php`.
- `web/src/` — `auth.php` y `layout.php`.
- `web/public/` — Interfaz web completa:
  - `index.php`, `login.php`, `logout.php`, `mi_clave.php`
  - `primera_pesada.php`, `segunda_pesada.php`, `pesadas.php`
  - `boleta.php`, `guia_remision.php`, `reportes.php`, `dashboard.php`
  - `clientes.php`, `categorias.php`, `productos.php`, `presentaciones.php`, `presentaciones_tipo.php`
  - `empresa.php`, `usuarios.php`, `config_bascula.php`, `despliegue.php`, `api_chat.php`
  - `assets/` (CSS, JS e imágenes, incluyendo `assets/img/logos/`).

### 1.2. Publicación en `htdocs` (XAMPP)
Confirmar que `C:\xampp\htdocs\bascula` apunte a `Z:\TYTOneBasculaAntigravity\web\public` (mediante *Junction* de Windows) o contenga la estructura equivalente:
- Verificar en PowerShell:
  ```powershell
  Get-Item "C:\xampp\htdocs\bascula" | Select-Object FullName, Target, LinkType
  ```
- Si no existe el enlace y se desea crear como Junction (apuntando directo a `Z:\TYTOneBasculaAntigravity\web\public`):
  ```cmd
  mklink /J "C:\xampp\htdocs\bascula" "Z:\TYTOneBasculaAntigravity\web\public"
  ```
- Confirmar permisos de escritura en `Z:\TYTOneBasculaAntigravity\web\public\assets\img\logos\` para poder subir el logo de la empresa.

---

## 2. Servicios de XAMPP y Extensiones de PHP

### 2.1. Servicios Activos
En el panel de control de XAMPP, iniciar y dejar en ejecución:
- **Apache** (puerto `80` o el asignado).
- **MySQL / MariaDB** (puerto `3306`).

### 2.2. Extensiones Obligatorias en `C:\xampp\php\php.ini`
Verificar que **no** estén comentadas (sin `;` al inicio):
- `extension=pdo_mysql` — Obligatoria para todas las consultas seguras a MariaDB.
- `extension=mbstring` — Obligatoria para manejo de tildes/UTF-8 (`CAFÉ`, `GUÍA`, `Ñ`).
- `extension=curl` — Obligatoria para el **Asistente TYTOne** (`api_chat.php` → Google Gemini).
- `extension=zip` — Necesaria para generar paquetes `.zip` desde **Despliegue / Nube**.
- `extension=fileinfo` — Validación segura de archivos/imágenes subidas.
- `extension=ftp` *(opcional, solo si se usa subida directa por FTP)*.

*(Si se edita `php.ini`, reiniciar Apache en XAMPP para aplicar cambios).*

---

## 3. Base de Datos MariaDB Local

### 3.1. Base de datos nueva
1. Crear la base de datos `dbBascula` (`utf8mb4_unicode_ci`).
2. Importar el esquema completo desde `scripts/dbBascula.sql`.

### 3.2. Actualización sobre una base de datos existente
Ejecutar en phpMyAdmin o consola MySQL para asegurar que existan las columnas recientes (Guía de Remisión y Asistente IA):
```sql
ALTER TABLE t_pesadas
    ADD COLUMN IF NOT EXISTS guia_remision VARCHAR(30) NULL AFTER observaciones_segunda,
    ADD COLUMN IF NOT EXISTS motivo_traslado VARCHAR(80) NULL AFTER guia_remision,
    ADD COLUMN IF NOT EXISTS motivo_traslado_otro VARCHAR(120) NULL AFTER motivo_traslado,
    ADD COLUMN IF NOT EXISTS transportista_rtn VARCHAR(20) NULL AFTER motivo_traslado_otro,
    ADD COLUMN IF NOT EXISTS marca_vehiculo VARCHAR(60) NULL AFTER transportista_rtn;
```
Y verificar en `t_configuracion_bascula` la columna `gemini_api_key` si se usará el Asistente TYTOne.

---

## 4. Configuración de Conexión (`web/config/db.php` y `local.php`)
- Por defecto, `web/config/db.php` conecta a `127.0.0.1:3306`, base `dbBascula`, usuario `root`, sin contraseña.
- Si en el equipo local la contraseña de `root` o el nombre de BD es distinto:
  1. Copiar `web/config/local.example.php` como **`web/config/local.php`**.
  2. Ajustar `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASS`.

---

## 5. Servicio de Báscula / Hardware Serial (FastAPI + Python)
1. Confirmar que el entorno Python (`.venv` o `runtime/python`) tenga instaladas las dependencias (`fastapi`, `uvicorn`, `pyserial`).
2. Iniciar el lector serial en `api/` y verificar en **Configuración Báscula** (`config_bascula.php`):
   - **Fuente predeterminada:** **Indicador Real** (no cambiar a simulación salvo prueba explícita).
   - Parámetros físicos del indicador: Puerto `COM`, baudios (`9600`), bits de datos (`8`), paridad (`N`), bits de parada (`1`).
   - Si se usa el Asistente IA, guardar la `gemini_api_key` en esta misma pantalla.

---

## 6. Pruebas de Verificación Local (Checklist Paso a Paso)
Ejecutar en orden antes de dar por terminada la implementación:

1. **Acceso:** Abrir `http://localhost/bascula/login.php` e iniciar sesión.
2. **Monitor en vivo:** Confirmar en `index.php` que el peso en vivo responde desde el indicador real.
3. **Primera Pesada (`primera_pesada.php`):**
   - Seleccionar `VENTA`, cliente, producto y confirmar que **Origen / Punto de partida** sugiere `CEIBITA SUR`.
4. **Segunda Pesada (`segunda_pesada.php`):**
   - Seleccionar la venta abierta y verificar que aparece el bloque **Guía de Remisión** (`guia_remision`, listbox `motivo_traslado` con los 15 ítems, `marca_vehiculo`, `transportista_rtn`).
   - Cerrar la nota.
5. **Boleta (`boleta.php`):**
   - Verificar logo a la izquierda de la empresa.
   - **Imprimir Boletas:** debe salir en **1 sola hoja Carta** con 2 copias y línea de corte con tijera al centro.
   - **Descargar PDF:** debe descargar **1 sola boleta limpia** en PDF.
6. **Guía de Remisión (`guia_remision.php`):**
   - Entrar desde el botón **🚚 Guía de Remisión** en la boleta de venta.
   - Verificar casilla `[✓]` en el motivo seleccionado, datos del bien en QQ, peso en letras y botones **Imprimir** / **Descargar PDF**.
7. **Reportes (`reportes.php`):**
   - Probar filtros de **Categoría** → **Producto** (solo muestra productos de la categoría elegida).
   - Probar **Imprimir**, **Descargar PDF** y **Generar Excel**.
8. **Asistente TYTOne (si aplica):**
   - Visible solo para `SUPER` / `ADMINISTRADOR`; probar consulta de pesadas y teléfonos de clientes.
