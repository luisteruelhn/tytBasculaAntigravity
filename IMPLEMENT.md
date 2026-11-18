# Guía de Implementación — TYTOne Báscula

Guía paso a paso para implementar y verificar el sistema en el equipo/servidor destino.

---

## Paso 1: Verificar Archivos del Proyecto (`Z:\TYTOneBasculaAntigravity` y `htdocs`)
1. Confirmar que el código completo esté en **`Z:\TYTOneBasculaAntigravity`** (o la carpeta raíz asignada) en la rama estable **`produccion`** (o desde el paquete generado en **Despliegue / Nube**).
2. Confirmar que **`htdocs`** (ej. `C:\xampp\htdocs\bascula` o `public_html` en hosting) apunte o contenga los archivos públicos necesarios:
   - `public/` (incluyendo `index.php`, `login.php`, `primera_pesada.php`, `segunda_pesada.php`, `boleta.php`, `guia_remision.php`, `reportes.php`, `pesadas.php`, `despliegue.php`, `api_chat.php`, `assets/`)
   - `src/` (`auth.php`, `layout.php`)
   - `config/` (`db.php`, `local.example.php` y `local.php`)
3. Verificar permisos de escritura en la carpeta de logos:
   - `web/public/assets/img/logos/`

---

## Paso 2: Extensiones de PHP y Servicios Requeridos
Verificar en `php.ini` (XAMPP / Servidor Web) que estén habilitadas las siguientes extensiones:
- `pdo_mysql` (conexión segura a MariaDB/MySQL)
- `mbstring` (manejo de tildes/UTF-8)
- `curl` (Asistente TYTOne / Google Gemini)
- `zip` (`ZipArchive` para generar paquetes en **Despliegue / Nube**)
- `ftp` *(opcional, solo si se usará subida directa por FTP)*

---

## Paso 3: Base de Datos (MariaDB / MySQL)
1. **Instalación nueva:** Importar `scripts/dbBascula.sql` (y seeds si aplica).
2. **Actualización sobre BD existente (cambios recientes):**
   Asegurar que `t_pesadas` tenga las columnas de **Guía de Remisión**:
   ```sql
   ALTER TABLE t_pesadas
       ADD COLUMN IF NOT EXISTS guia_remision VARCHAR(30) NULL AFTER observaciones_segunda,
       ADD COLUMN IF NOT EXISTS motivo_traslado VARCHAR(80) NULL AFTER guia_remision,
       ADD COLUMN IF NOT EXISTS motivo_traslado_otro VARCHAR(120) NULL AFTER motivo_traslado,
       ADD COLUMN IF NOT EXISTS transportista_rtn VARCHAR(20) NULL AFTER motivo_traslado_otro,
       ADD COLUMN IF NOT EXISTS marca_vehiculo VARCHAR(60) NULL AFTER transportista_rtn;
   ```
   *(Verificar también `gemini_api_key` en `t_configuracion_bascula` si se usa el Asistente TYTOne).*

---

## Paso 4: Configuración Privada de Entorno (`web/config/local.php`)
1. Copiar `web/config/local.example.php` como **`web/config/local.php`** (ignorado por Git).
2. Colocar las credenciales reales del servidor (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`).

---

## Paso 5: Hardware / Indicador Serial (Solo en PC de Báscula)
1. Verificar que el servicio FastAPI en `api/` esté activo y conectado al puerto COM real (fuente predeterminada: **Indicador Real**, no simulación).
2. Confirmar en **Configuración Báscula** (`config_bascula.php`) los parámetros COM y la `gemini_api_key` si aplica.

---

## Paso 6: Lista de Verificación Final (Checklist)
- [ ] Acceso y login correcto en `public/login.php`.
- [ ] Lectura de peso en vivo desde indicador real en `index.php`.
- [ ] Primera pesada: sugerencia `CEIBITA SUR` en **Origen / Punto de partida**.
- [ ] Segunda pesada (Venta): campos `guia_remision`, listbox `motivo_traslado`, `marca_vehiculo`, `transportista_rtn`.
- [ ] Emisión de **Boleta** (`boleta.php`: 2 copias en 1 hoja Carta al imprimir; 1 copia limpia en PDF).
- [ ] Emisión de **Guía de Remisión** (`guia_remision.php` solo en ventas cerradas, Imprimir + PDF).
- [ ] **Reportes** (`reportes.php`): filtros Operación/Categoría/Producto + Imprimir + PDF + Excel.
- [ ] **Despliegue / Nube** (`despliegue.php` visible solo para `SUPER`).
