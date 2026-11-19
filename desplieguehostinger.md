# Manual de Despliegue y Sincronización en Hostinger (`inversionesvallecillo.com`)

**Fecha:** 2026-11-19  
**Proyecto:** `tytBasculaAntigravity`  
**Dominio en la nube:** `https://inversionesvallecillo.com/bascula/login.php`

---

## 1. Arquitectura General

* **PC de Planta (Local):** Opera todo el pesaje real (XAMPP Apache + MariaDB `dbbascula` + microservicio FastAPI `:8018` sobre `COM4` en `modo = 'SERIAL'`).
* **Hostinger (Nube):** Opera exclusivamente en **modo solo consulta** (reportes, boletas, guías de remisión, historial y catálogos en lectura).
* **Sincronización Offline-First (`HTTPS`):**
  * La PC de planta envía catálogos y pesadas por HTTPS (`POST`) a `https://inversionesvallecillo.com/bascula/api_sync.php` usando un `X-Bascula-Token` secreto (`SYNC_TOKEN`).
  * Si no hay internet en planta, el pesaje local **no se detiene** y se encola en `t_sincronizacion_pendiente` para enviarse después.

---

## 2. Archivos Clave del Despliegue

| Archivo | Función | ¿Viaja en Git? |
| :--- | :--- | :--- |
| `web/public/despliegue.php` | Formulario web (`SUPER`) para generar `.sql`, `.zip`, guardar config local y sincronizar | Sí |
| `web/public/api_sync.php` | Receptor HTTPS en Hostinger (`INSERT ... ON DUPLICATE KEY UPDATE`) | Sí |
| `web/src/sync_nube.php` | Cliente local conectado a `primera_pesada.php`, `segunda_pesada.php` y `t_sincronizacion_pendiente` | Sí |
| `web/config/sync_remoto.php` | Credenciales locales de envío (`SYNC_REMOTE_URL`, `SYNC_TOKEN`, campos del formulario) | **No** (`.gitignore`) |
| `config/local.php` *(en Hostinger)* | Credenciales MySQL de Hostinger (`DB_*`) + `SYNC_TOKEN` | **No** (`.gitignore`) |

---

## 3. Paso a Paso en Hostinger hPanel (Instalación Inicial)

### Paso 1: Crear el Sitio Web
1. En **Hostinger hPanel** → **Sitios web** → **Agregar sitio web** (*Create site*).
2. Seleccionar **Empezar vacío** (*Empty PHP/HTML website* — no WordPress ni creador con IA).
3. Elegir **Usar un dominio existente** → **`inversionesvallecillo.com`**.
4. Confirmar en **Avanzado → Configuración PHP** que el sitio use **PHP 8.2 o superior** (verificado con `PHP/8.3.36`).

### Paso 2: Crear la Base de Datos MySQL e Importar Tablas
1. En el menú izquierdo del sitio → **Bases de datos → Gestión**.
2. Crear la base de datos (ej. `dbbascula`) y usuario (ej. `bascula`) con contraseña segura.
3. Anotar los nombres completos con prefijo que asigna Hostinger (`u..._dbbascula`, `u..._bascula`) y la contraseña *(el Host MySQL es `localhost` y el puerto `3306`)*.
4. En tu PC local (`http://localhost/bascula/despliegue.php`), hacer clic en **🗄️ Descargar Respaldo SQL**.
5. En Hostinger → **Bases de datos → Gestión → Entrar a phpMyAdmin**:
   * Seleccionar la base de datos a la izquierda.
   * Ir a la pestaña **Importar**, elegir el `.sql` descargado y presionar **Importar**.
   * Verificar que aparezcan las **12 tablas**. *(Nota: si la base queda vacía sin importar, al intentar entrar dará `SQLSTATE[42S02]`).*

### Paso 3: Generar y Subir el Paquete `.zip`
1. En tu PC local (`http://localhost/bascula/despliegue.php`):
   * Llenar **1. Base de Datos MySQL en Hostinger** (`localhost`, `3306`, Nombre BD, Usuario MySQL, Contraseña MySQL).
   * Verificar **2. Sincronización Automática**:
     * URL: `https://inversionesvallecillo.com/bascula/api_sync.php`
     * **Token Secreto de Sincronización** (el mismo se inyecta en ambos lados).
   * Hacer clic en **📦 Generar y Descargar ZIP (para Hostinger)**.
   * *(Esto guarda la configuración local en `web/config/sync_remoto.php` y empaqueta el `.zip` con `config/local.php` y protección `.htaccess` en `config/` y `src/`)*.
2. En Hostinger → **Archivos → Administrador de archivos** → entrar a **`public_html`**:
   * Subir el archivo `bascula_hostinger_....zip`.
   * Extraerlo en `.` (si queda dentro de una subcarpeta como `public_html/public_html/`, mover su contenido a la raíz de `public_html`).
   * Confirmar que dentro de `public_html` queden directamente estos **4 elementos**:
     * `bascula/`
     * `config/` *(con `local.php` y `.htaccess`)*
     * `src/` *(con `.htaccess`)*
     * `index.php` *(redirige a `bascula/login.php`)*

---

## 4. Verificación y Uso Diario de Sincronización

1. **Acceso remoto:** Abrir **`https://inversionesvallecillo.com/bascula/login.php`** e iniciar sesión (por ejemplo con `maria.jose` rol `REMOTO`, o `super`).
2. **Sincronización manual completa:** En la PC local (`http://localhost/bascula/despliegue.php`), presionar **☁️ Sincronizar Ahora con Hostinger** para enviar todas las tablas (`t_empresas`, `t_categorias`, `t_presentaciones`, `t_productos`, `t_producto_presentaciones`, `t_taras`, `t_clie_prov`, `t_pesadas`, `t_pesada_anulaciones`).
3. **Sincronización automática:** Cada vez que en planta se guarda una **Primera Pesada** o se cierra una **Segunda Pesada**, se sincroniza automáticamente la pesada junto con `t_clie_prov` y los catálogos. Si no hay internet, queda en `t_sincronizacion_pendiente` para enviarse en el siguiente intento.

---

## 5. Cómo Actualizar Hostinger desde GitHub (Rama `produccion`)

* **Opción recomendada (1 clic en Hostinger → Avanzado → Git):**
  1. Antes de conectar Git por primera vez, guarda una copia de tu `public_html/config/local.php` actual (contiene `DB_*` y `SYNC_TOKEN`).
  2. En Hostinger hPanel → **Avanzado → Git**:
     * Repositorio: `https://github.com/luisteruelhn/tytBasculaAntigravity.git`
     * Rama: **`produccion`**
     * Directorio de instalación: dejar vacío (para `public_html`).
  3. Una vez clonado, coloca tu `local.php` dentro de **`public_html/web/config/local.php`** (al estar en `.gitignore`, los futuros **Deploy / Actualizar** de Hostinger nunca lo borrarán).
  4. El `.htaccess`, `index.php` y `robots.txt` de la raíz protegen automáticamente `api/`, `scripts/`, `web/config/` y `web/src/`, muestran la página institucional en `inversionesvallecillo.com/` y enrutan `/bascula/` hacia `web/public/`.
* **Opción alternativa (.zip):** Generar el `.zip` desde `http://localhost/bascula/despliegue.php` y extraerlo en `public_html`.
