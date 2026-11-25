# Bitácora de Intervenciones y Cambios — Gemini / Antigravity

Registro cronológico y detallado de todas las actividades, decisiones técnicas y modificaciones en el proyecto **tytBasculaAntigravity**.

---

## 2026-11-16 — Sesión 01: Apertura y Definición de Arquitectura

### 1. Contexto y Diagnóstico Inicial
* **Hora local**: ~11:45 AM (America/Tegucigalpa).
* **Estado encontrado**:
  - Repositorio original limpio en espacio de trabajo pero con historial completo en Git (`TYTONEBASCULALocal`).
  - Base de datos operativa `dbbascula` verificada en `C:\xampp\mysql\data\dbbascula` con todas las tablas físicas intactas.
  - XAMPP instalado en `C:\xampp` (Apache y MariaDB disponibles).
  - Runtime de Python portable verificado funcional en `runtime/python/python.exe` (CPython 3.14 con FastAPI y Uvicorn listos).

### 2. Acuerdos y Decisiones con el Usuario
1. **Reinicio Limpio**:
   - Se acordó reconstruir el proyecto casi desde cero bajo un repositorio nuevo denominado **`tytBasculaAntigravity`**.
   - El historial git anterior fue respaldado de forma segura en `.git_old` y se inicializó un nuevo repositorio Git en rama `main`.
2. **Pila Tecnológica Seleccionada**:
   - **Frontend & Lógica**: PHP 8 nativo bajo servidor **Apache de XAMPP**.
   - **Base de Datos**: **MariaDB de XAMPP** (puerto 3306, `dbBascula`).
   - **Microservicio de Hardware**: **FastAPI** en Python portable (puerto 8018) para lectura del puerto COM serial y simulación.
   - **Exclusión de Nube**: Sin dependencias de Google Cloud ni servicios externos; operación 100% local on-premise.
3. **Criterios de Diseño y Calidad**:
   - Código y diseño **ultra-ligero** sin frameworks inflados (sin dependencias pesadas de terceros).
   - **Máxima seguridad**: Conexiones PDO preparadas (prevención de inyección SQL), tokens CSRF, sesiones con atributos estrictos, control de acceso por roles (RBAC).
   - **Acuerdo de Báscula**: La fuente predeterminada en producción es el **indicador Real**. Al ser este el equipo de desarrollo (sin indicador físico conectado), se habilita el modo **Simulado** para el desarrollo, garantizando que el selector conserve los parámetros seriales confirmados.

### 3. Acciones Ejecutadas
* Creación de los cuatro documentos de seguimiento y control:
  - `AGENTS_GEMINI.md`: Reglas y acuerdos del usuario (incluyendo legibilidad humana y commits continuos).
  - `README_GEMINI.md`: Arquitectura, componentes y guía de arranque.
  - `SEED_GEMINI.md`: Esquema de base de datos, inventario de tablas y roles.
  - `BITACORA_GEMINI.md`: Este registro de trazabilidad.
* Configuración de exclusiones en `.gitignore`.
* Enlace en XAMPP: Creado Junction `C:\xampp\htdocs\bascula` hacia `K:\TYTOneBasculaAntigravity\web\public`.
* Módulo 1: Control de Acceso, Autenticación y Diseño Responsivo:
  - Incorporado logo institucional `tytlogo.png` en `web/public/assets/img/tytlogo.png`.
  - Construido `web/public/login.php` con protección CSRF, sanitización, consulta preparada PDO a `t_usuarios` y verificación segura de hash.
  - Diseño temático de báscula camionera de rastras con ilustración vectorial de cabezal chato/cuadradito (Cabover) sobre plataforma de celdas de carga y display de peso.
  - Diseño 100% responsivo en `style.css` y `login.php`: tipografía fluida con `clamp()`, soporte de pantallas pequeñas (320px–480px), tablets y pantallas industriales, con tap-targets táctiles de mínimo 44px.
  - Soporte global de temas Modo Oscuro (Dark) y Modo Claro (Light) persistente en `localStorage` con `assets/js/theme.js`, variables CSS y botón de alternancia en barra de navegación y pantalla de login.
  - Microservicio FastAPI: añadido alias `/api/v1/lecturas/actual` además de `/api/v1/peso` y reactivado en puerto 8018.
  - Construido `web/public/logout.php` para cierre seguro de sesión y destrucción de cookies.
  - Validada sintaxis de todos los archivos con PHP 8 en XAMPP (cero errores).
  - Fortalecimiento de Validaciones y Logo en Navbar:
    * Incorporado el logo institucional `tytlogo.png` en la barra de navegación superior reemplazando el icono estático.
    * Robustecimiento de validaciones de login: límites de longitud (usuario máx 80, clave máx 1024), sanitización contra bytes nulos y caracteres de control.
    * Protección contra ataques de fuerza bruta (Rate Limiting) por IP y por cuenta con `verificarTasaLimite()` y `limpiarTasaLimite()`.
    * Protección contra ataques de enumeración por temporización (Timing Attacks) mediante hash señuelo en cuentas inexistentes.
* Menú Lateral (Sidebar Moderno Tipo Cockpit):
  - Reemplazado menú antiguo por sidebar industrial moderno con íconos vectoriales SVG tipo Feather/Lucide.
  - Colapsable en escritorio (modo compacto con persistencia en `localStorage`).
  - Menú drawer deslizante en tablets y móviles con backdrop blur.
  - Badges dinámicos de camiones pendientes en patio (Segunda Pesada).
  - Mini-tarjeta integrada al pie que replica el peso y modo de la báscula en tiempo real.
  - Perfil de usuario con avatar por iniciales y botón de cambio de tema (Dark/Light).
* Módulo Administrativo — Empresa:
  - Creado `web/public/empresa.php` para consultar y actualizar los datos de la empresa en `t_empresas`.
  - Validación estricta de longitudes (Razón Social obligatoria, RTN, Nombre Comercial, Dirección).
  - Actualización atómica en MariaDB y sincronización inmediata con la sesión activa del usuario.
  - Integrado enlace en el menú lateral bajo la sección Administración.
* Módulo Administrativo — Usuarios y Roles:
  - Creado `web/public/usuarios.php` con tabla interactiva de usuarios de la empresa y formulario de alta.
  - Creación con cifrado `password_hash` (BCrypt cost 12), validación de unicidad de usuario y correo.
  - Asignación de roles (`ADMINISTRADOR`, `SUPERVISOR`, `OPERADOR`).
  - Activación y desactivación rápida con protección contra auto-desactivación de la cuenta en uso.
  - Protección CSRF y control estricto de accesos.

* Módulo Administrativo — Modernización Visual y Permisos RBAC Granulares:
  - Rediseño visual SaaS industrial moderno para `web/public/empresa.php` y `web/public/usuarios.php` con secciones agrupadas, íconos SVG, badges temáticos, avatares y estilos pulidos en `style.css`.
  - Implementación de reglas estrictas de control de roles (RBAC):
    * **SUPER y ADMINISTRADOR**: Control total (pueden ver todos, crear, cambiar contraseñas de otros usuarios con modal/prompt y activar/desactivar).
    * **SUPERVISOR**: Puede ver a todos los usuarios de la empresa y registrar nuevos usuarios (roles OPERADOR o SUPERVISOR), pero NO puede modificar ni cambiar contraseñas de otros usuarios.
    * **OPERADOR**: Solo puede ver la información de su propia cuenta de usuario y tiene acceso directo a cambiar su propia contraseña personal.
  - Creación de `web/public/mi_clave.php` para cambio seguro de contraseña de cualquier usuario autenticado (con verificación de contraseña actual y requisitos de seguridad).
  - Enlace rápido a "Mi Contraseña" desde el pie del menú lateral en el perfil de usuario.

* Módulo Administrativo / Operativo — Clientes y Proveedores:
  - Creado `web/public/clientes.php` conectado a `t_clie_prov` en MariaDB.
  - Soporte de filtrado por tipo (Todos, Solo Clientes, Solo Proveedores) y buscador en tiempo real por nombre, RTN o teléfono.
  - Modal interactivo responsivo para alta y edición de registros con categorías de pesaje dual (Cliente para despachos/ventas, Proveedor para compras/entradas).
  - Reglas de activación y desactivación segura con protección CSRF.
  - Acceso optimizado para el pesaje diario: operadores pueden registrar rápidamente un nuevo cliente o proveedor sin salir de la operación de báscula.

* Módulo de Catálogo — Productos y Presentaciones:
  - Creado `web/public/productos.php` con la interfaz idéntica a la captura de pantalla de referencia del usuario (Título CATÁLOGO / Productos, subtítulo de empresa emisora, botón azul "Nuevo producto", buscador y tabla con columnas Producto, Presentaciones, Estado, Acción).
  - Modal flotante con soporte para dar de alta y editar productos junto a sus presentaciones múltiples (sacos, granel, cisternas, etc.).
  - Sembreados directamente en MariaDB (`t_productos` y `t_producto_presentaciones`) los productos de la imagen de referencia:
    * `ACEITE` (Presentación: GRANEL)
    * `CAFE CORRIENTE` (Presentación: SACOS)
    * `CAFE GUACUCO` (Presentación: SACOS)
    * `CAFE HÚMEDO` (Presentación: SACOS)
    * `CAFE NATURAL` (Presentación: SACOS)
    * `CAFE ORO SHG` (Presentación: SACOS)
    * `CAFE RESACA` (Presentación: SACOS)
  - Creado script de inserción `scripts/seed_productos.php`.

* Módulo de Catálogo — Presentaciones y Taras de Empaque (JSON Storage):
  - Creado `web/config/presentaciones.json` almacenando las presentaciones de pesaje sin sobrecargar tablas relacionales:
    * `SACOS` (Empaque en sacos de café/granos)
    * `A GRANEL` (Carga suelta a granel o cisterna)
  - Creado formulario interactivo `web/public/presentaciones.php` con listado, modal para registrar y editar `código`, `nombre`, `descripción` y estado `activo`.
  - Integrado en el menú lateral bajo **Administración -> Taras de empaque** (`presentaciones.php`) con ícono representativo.

* Módulo de Catálogo — Taras de Empaque y Presentaciones (Decisión de Arquitectura: MariaDB vs JSON):
  - **Justificación Técnica de por qué NO se usó JSON**:
    1. *Integridad en Boletas*: La presentación elegida (ej. "SACOS" o "A GRANEL") afecta directamente el cálculo del Peso Neto en `t_pesadas` y requiere agregaciones e informes SQL consistentes.
    2. *Concurrencia Segura*: MariaDB maneja aislamiento transaccional (`InnoDB`) evitando bloqueos de archivos (*file lock*) que ocurren en Windows al concurrir múltiples operadores.
    3. *Respaldos Atómicos*: Al generar un `mysqldump` unificado, las presentaciones se respaldan junto con las boletas y productos en un único archivo `.sql`, sin riesgo de perder un archivo JSON huérfano.
  - **Implementación**:
    * Creada tabla `t_presentaciones` con clave única `(empresa_id, codigo)`.
    * Sembradas las presentaciones maestras iniciales: `SACOS` y `A GRANEL`.
    * Creado script reproducible `scripts/crear_tabla_presentaciones.php`.
    * Desarrollada vista `web/public/presentaciones.php` con listado, buscador, y modal de alta/edición conectada a MariaDB.
    * Integrado enlace en la barra lateral bajo la sección de Administración -> "Taras de empaque".

* Módulo de Catálogo — Categorías de Producto y Vínculo en MariaDB:
  - Creada tabla `t_categorias` en MariaDB con clave única `(empresa_id, codigo)`.
  - Sembradas las categorías base:
    * `CAFE` (CAFÉ)
    * `ACEITES` (ACEITES)
    * `GRANOS_BASICOS` (GRANOS BÁSICOS)
  - Modificada la tabla `t_productos` agregando la columna `categoria_id` (con índice `idx_categoria`) y actualizados los productos existentes según su rubro.
  - Creado formulario administrativo [categorias.php](file:///k:/TYTOneBasculaAntigravity/web/public/categorias.php) con listado, buscador, conteo de productos por categoría y modal de creación/edición.
  - Actualizado [productos.php](file:///k:/TYTOneBasculaAntigravity/web/public/productos.php) para mostrar la columna de categoría y selector en su modal.
  - Integrado enlace en la barra lateral bajo la sección Administración -> **"Categorías"** (`categorias.php`).

### 4. Siguientes Pasos
1. Continuar con el siguiente módulo: Formulario de **Primera Pesada** (`primera_pesada.php`) con captura de peso en vivo, indicador LED, datos de operación, vehículo/motorista y pantalla de confirmación post-guardado.


---

## 2026-11-17 — Sesión 02: Reportes, Boleta Doble, Guía de Remisión, Pesada Única, Rol REMOTO y Despliegue

### 1. Reportes, Boletas y Guía de Remisión
* **Reportes (`web/public/reportes.php`)**:
  - Jerarquía completa **Operación (`COMPRA` / `VENTA`) → Categoría → Producto** con conteo de pesadas, subtotales intermedios, consolidado general por producto y gran total.
  - Filtros dinámicos de **Categoría** y **Producto** (reconstrucción JS limpia para compatibilidad entre navegadores).
  - Tríada obligatoria (**Imprimir**, **Descargar PDF** directo vía `html2pdf.js` y **Generar Excel** `.xls` XML nativo), ocultando encabezados web, filtros y widget de chat en `@media print`.
  - Rango inicial dinámico del mes en curso (`date('Y-m-01')` a `date('Y-m-d')`).
* **Boleta (`web/public/boleta.php`)**:
  - Logo de empresa posicionado a la izquierda.
  - Vista de impresión física con **2 boletas por hoja Carta** separadas por línea punteada responsiva con tijera (`✂`), y botón **Descargar PDF** generando **1 sola boleta individual limpia**.
* **Guía de Remisión para Ventas (`web/public/guia_remision.php`)**:
  - Agregadas columnas `guia_remision`, `motivo_traslado`, `motivo_traslado_otro`, `transportista_rtn` y `marca_vehiculo` en `t_pesadas` (`scripts/dbBascula.sql`).
  - Integrado bloque en `segunda_pesada.php` con los **15 motivos de traslado** cuando la operación es `VENTA` y sugerencia `CEIBITA SUR, SANTA BARBARA` en `primera_pesada.php`.
  - Reporte fiel con formato Carta doble en impresión y 1 copia limpia en PDF, accesible desde `boleta.php` y `pesadas.php`.

### 2. Pesada Única (Cierre en un solo paso)
* Integrado toggle **`⚡ Pesada Única`** en `primera_pesada.php` con liquidación directa (tara vehículo `0` por defecto, empaques, % humedad, % defectos y Guía de Remisión si es venta).
* Activación automática sugerida al seleccionar el producto **`ACEITE`**.

### 3. Seguridad, Roles y Asistente IA
* **Rol `REMOTO` (Solo Consulta) y usuario `maria.jose`**:
  - Agregado `'REMOTO'` al `ENUM` de `t_usuarios.rol` (`scripts/sql_remoto_usuarios.sql` y `scripts/actualizar_bd_pc_bascula.sql`).
  - Bloqueado acceso de escritura/pesaje en UI y backend (`primera_pesada.php`, `segunda_pesada.php`, `usuarios.php`, `config_bascula.php`, `clientes.php`, `categorias.php`, `productos.php` y botón `+ Nueva Primera Pesada` en `pesadas.php`).
  - En entorno remoto o rol `REMOTO`, el monitor en `index.php` y `layout.php` muestra el **peso de la última pesada registrada** y oculta diagnósticos del microservicio local (`FastAPI :8018`).
* **Asistente TYTOne (`web/public/api_chat.php`)**:
  - Acceso de solo lectura multi-tabla (incluyendo directorio con teléfonos de clientes/proveedores) habilitado para `SUPER`, `ADMINISTRADOR` y `REMOTO`.

### 4. Despliegue, Zona Horaria y Documentación
* Centralizada zona horaria oficial `America/Tegucigalpa` en `web/config/db.php` y corregido rango por defecto en `dashboard.php`.
* Creado módulo **`Despliegue / Nube`** (`web/public/despliegue.php` exclusivo `SUPER`) + soporte `web/config/local.php` (`local.example.php`) protegido en `.gitignore`.
* Creada rama **`produccion`** en Git y documentación operativa para planta en `IMPLEMENT.md` + script idempotente `scripts/actualizar_bd_pc_bascula.sql`.
* Limpieza de archivos temporales (`cookies.txt`) y exclusión de logos privados en `.gitignore`.

---

## 2026-11-18 / 2026-11-19 — Sesión 03: Personalización de Login, Pie de Página y Portabilidad de Skills

* **Login (`web/public/login.php`)**:
  - Rediseñada ilustración SVG de rastra sobre báscula con furgón largo en franjas azules y cabezal compacto con llamas fantasma (*ghost flames*) azules sutiles de flujo frente → atrás.
  - Corregido contraste del título del panel izquierdo en modo claro (`#f8fafc`) y actualizado texto a `TRUCK SCALE`.
* **Pie de página (`web/src/layout.php`)**:
  - Actualizado mensaje del footer a `TYTOne Santa Bárbara Cel:+504 95173632 © 2026 — Sistema de Pesaje Electrónico Industrial`.
* **Portabilidad y gobernanza (`.agents/skills/`, `AGENTS.md`)**:
  - Copiadas las skills globales dentro de `.agents/skills/` y registrado el cumplimiento obligatorio de skills y commits atómicos en `AGENTS.md`.
* **Auditoría DB/XAMPP y Guía de Sincronización (`IMPLEMENT.md`, `scripts/`)**:
  - Detectadas y agregadas columnas `telefono` y `email` faltantes en `t_empresas` (`scripts/dbBascula.sql` y `scripts/actualizar_bd_pc_bascula.sql`), semilla base de `t_presentaciones` (`SACOS`, `GRANEL`) y `observaciones_segunda` idempotente en `t_pesadas`.
  - Documentado baseline real de XAMPP (Apache 2.4.58, PHP 8.5.12, MariaDB 10.11.16), inventario de 12 tablas y protocolo paso a paso de sincronización manual en `IMPLEMENT.md`.

---

## 2026-11-19 — Sesión 04: Puesta en Marcha en PC de Producción (Local + Respaldo + Esquema BD)

* **Respaldo previo obligatorio**:
  - Generado respaldo completo de `dbbascula` en `dbbackup/respaldo_pre_sync_20261119.sql` (53,292 bytes) antes de aplicar cambios.
* **Actualización segura de esquema (`scripts/actualizar_bd_pc_bascula.sql`)**:
  - Ejecutado script idempotente sobre `dbbascula` (pasando de 10 a las **12 tablas** completas).
  - Conservados intactos los datos previos (`4` pesadas, empresa `INVERSIONES VALLECILLO, SB`, usuario `admin`) y los parámetros físicos reales en `t_configuracion_bascula` (`modo = 'SERIAL'`, `puerto_com = 'COM4'`, `baudios = 9600`, `protocolo = 'C18 = 4'`).
  - Verificadas columnas nuevas en `t_empresas` (`telefono`, `email`), `t_pesadas` (`observaciones_segunda`, `guia_remision`, `motivo_traslado`, etc.), tablas `t_categorias` y `t_presentaciones`, y usuario `maria.jose` con rol `REMOTO`.
* **Validación de sintaxis PHP**:
  - Verificados todos los archivos `.php` bajo `web/` con `php -l` (cero errores).
* **Enlace en XAMPP y Lectura Serial Real (`COM4`)**:
  - Respaldada carpeta anterior en `C:\xampp\htdocs\bascula_respaldo` y creado *Junction* `C:\xampp\htdocs\bascula` → `k:\TYTOneBasculaAntigravity\web\public`.
  - Actualizado `api/serial_reader.py` con `_configurar_sesion_serial()` / `_restaurar_sesion_serial()` (DCB `9600, 8N1` solo en sesión) y soporte `\r`/`\n` + `lb`/`lbs`/`kg` en `parsear_trama_lp7510()`.
  - Actualizado `api/main.py` con `modo = "real"` por defecto, `puerto_com` en `/api/v1/salud`, y `/api/v1/lector/detener` + `/api/v1/lector/iniciar` (commit `74b1afe`).
  - Creado `web/public/serial_prueba.php` (SSE con `probar_lectura_serial.py`) y conectados `layout.php`, `config_bascula.php`, `index.php` y `pesaje.js` con FastAPI `:8018` (commit `9cd44ed`).
  - Verificado `http://localhost/bascula/login.php` (HTTP 200) y FastAPI `:8018` (`/api/v1/salud` en modo `real` y `/api/v1/puertos` detectando `COM4`).
  - Documentado cierre en `IMPLEMENTACION_FINAL.MD`.
* **Primera Pesada (`web/public/primera_pesada.php`)**:
  - Ajustada sugerencia de `CEIBITA SUR, SANTA BARBARA` (placeholder y datalist): se sugiere en **Origen** cuando la operación es `VENTA` y en **Destino** cuando es `COMPRA`.
  - Rediseñada la vista en un cockpit compacto de una sola pantalla alineado con Segunda Pesada: barra superior con switch `COMPRA`/`VENTA` + toggle `⚡ Pesada Única`, fila central `1.1fr / 1fr` (Captura + Indicador en vivo), tarjeta inferior unificada (4 columnas de operación, 5 columnas de vehículo/traslado, observaciones + botón guardar en una fila), desglose individual de descuentos en Pesada Única y panel `#avisoFaltantePrimera` con retroalimentación explícita de campos faltantes.
* **Nuevas Skills Portables (`.agents/skills/` y `AGENTS.md`)**:
  - Agregadas `bascula-sync-nube`, `serial-hardware-windows` y `mariadb-safe-migrations` para estandarizar sincronización offline-first a Hostinger, manejo seguro de `DCB`/`COM` sin tocar Windows y migraciones MariaDB idempotentes.
* **Segunda Pesada (`web/public/segunda_pesada.php`)**:
  - Resaltado el encabezado de la nota abierta con tipografía más grande y tarjeta de **Primera Pesada** con fondo celeste difuminado unificada al ancho exacto del cuadro del indicador (`1.1fr / 1fr`).
  - Agregada retroalimentación visual explícita (`#avisoFaltanteGuardar`) al intentar guardar sin haber fijado un peso complementario válido, cuando Bruto ≤ Tara o si falta especificar el motivo de traslado `"Otros (especificar)"`.
  - Redistribuida la vista de cierre de `segunda_pesada.php` en un cockpit compacto de 2 columnas (`1.1fr / 1fr`) con desglose individual de descuentos (Humedad y Calidad) y primera pesada apilada bajo tara fijada.
* **Boletas e Historial (`web/public/pesadas.php` y `segunda_pesada.php`)**:
  - Corregido `TypeError: htmlspecialchars(): Argument #1 ($string) must be of type string, null given` en registros con `placa`, `persona_nombre`, `producto_nombre` o `presentacion_nombre` en `NULL`.
* **Sincronización Offline-First Local → Hostinger (`inversionesvallecillo.com`)**:
  - Creado receptor HTTPS `web/public/api_sync.php` autenticado con `X-Bascula-Token` (`SYNC_TOKEN`) e inserción idempotente (`ON DUPLICATE KEY UPDATE`).
  - Creado cliente local `web/src/sync_nube.php` conectado con `t_sincronizacion_pendiente` e integrado automáticamente al guardar `primera_pesada.php` y `segunda_pesada.php`.
  - Actualizado `web/public/despliegue.php` con generación de paquete `.zip` listo para `/public_html` de `inversionesvallecillo.com` (compatible con o sin `ZipArchive`), inyección automática de `config/local.php` (`DB_*` + `SYNC_TOKEN`), protección `.htaccess` y botón **☁️ Sincronizar Ahora con Hostinger**.
  - Agregada página institucional discreta `web/config/landing_hostinger.html` (como `index.php` raíz en Hostinger sin enlaces al sistema), `robots.txt` y `<meta name="robots" content="noindex, nofollow">` en `layout.php` y `login.php`.
  - Incluida la tabla `t_usuarios` en `api_sync.php` y `sync_nube.php` para sincronizar usuarios, roles y contraseñas desde la PC local hacia Hostinger.
  - Agregados `.htaccess`, `index.php` y `robots.txt` en la raíz del repositorio para permitir despliegue directo en Hostinger desde Git (`produccion`) manteniendo `inversionesvallecillo.com/` institucional, `/bascula/` enrutado a `web/public/` y carpetas internas bloqueadas.
  - Agregado reintento automático cada 5 minutos (`api_sync_pendientes.php` + `procesarPendientesSyncNube()` + timer en `layout.php`) para enviar pesadas pendientes de `t_sincronizacion_pendiente` apenas regrese el internet en planta.
  - Agregado botón **☁️ Sincronizar con la Nube** en `web/public/index.php` (solo entorno local) conectado a `api_sync_pendientes.php?modo=todo`.
* **Control de Lectura Serial desde Inicio (`2026-11-24`)**:
  - Agregado `pausado_manual` en `api/main.py` y respetado en `web/src/layout.php` (`sincronizarBasculaApi`) para no reabrir el puerto `COM` tras pausarlo.
  - Creado `web/public/api_bascula_control.php` y botón **▶️ Arrancar Lectura / ⏹️ Parar Lectura** en `web/public/index.php` para levantar `:8018` bajo demanda o liberar `COM4` al sistema anterior con un clic.
* **Cálculos de Liquidación y Boleta (`primera_pesada.php`, `segunda_pesada.php`, `boleta.php`)**:
  - Aplicado redondeo hacia arriba (`ceil` / `Math.ceil`) en tara de sacos, descuento por humedad (`%H`) y descuento por defectos (`%D`).
  - Ajustada la tabla de `boleta.php` al formato `Bruto | Tara | Neto | Sacos | Tara Sacos | Neto (con QQ) | %H | %D | Total QQ` (sin columna `Total lb`, sin libras bajo `%H`/`%D` y sin leyendas fijas de descuento/tara al pie, manteniendo `reportes.php` en su formato original).
  - Actualizados los constraints `chk_pesadas_pesos` y `chk_pesadas_descuentos` en `t_pesadas` (`scripts/actualizar_chk_redondeo_arriba.sql`, respaldo previo `dbbackup/respaldo_pre_chk_20261124.sql`) para validar con `CEILING(ROUND(..., 4))` en lugar de `ROUND(..., 0)`.
* **Indicador de Modo en Vivo (`primera_pesada.php`, `segunda_pesada.php`, `layout.php`)**:
  - Corregido `#liveModoTexto` para reflejar dinámicamente `Modo real` o `Modo simulado` según `data.origen` de FastAPI (`:8018`), e incluida la sincronización automática al cargar `primera_pesada.php` y `segunda_pesada.php`.
* **Sugerencia de Empaque en Segunda Pesada (`segunda_pesada.php`)**:
  - Preseleccionado automáticamente el saco de `0.25 lb` en operaciones de `VENTA` y el saco de `0.50 lb` en operaciones de `COMPRA` dentro del selector `Empaque o saco`, asegurando consistencia con `chk_pesadas_empaque` cuando `cantidad_empaques` es `0`.
* **Acceso Móvil por Red Local (LAN Wi-Fi) con Peso en Tiempo Real y Código QR Offline (`2026-11-24`, ~02:13 PM)**:
  - **Diagnóstico de red y puertos**: Confirmada IP LAN `192.168.2.20` (Ethernet), Apache XAMPP escuchando en `0.0.0.0:80` con *Junction* `/bascula`, reglas entrantes activas en Firewall de Windows (perfil Privado) y microservicio FastAPI aislado en `127.0.0.1:8018`.
  - **Detección de Red Local vs Nube (`web/src/auth.php`, `web/src/layout.php`, `web/public/index.php`)**: Creadas `esAccesoPlantaLocal()` y `obtenerUrlRedLocal()` para reconocer `localhost`/`127.0.0.1` e IPs privadas RFC 1918 (`192.168.x.x`, `10.x.x.x`, `172.16-31.x.x`) como planta local, manteniendo el modo solo consulta únicamente en dominios remotos (`inversionesvallecillo.com` / rol `REMOTO`).
  - **Puente ligero de peso en vivo (`web/public/api_peso_local.php`)**: Endpoint PHP autenticado (con `session_write_close()` inmediato para polling ágil cada `350-400 ms`) que enlaza Apache `:80` con FastAPI `127.0.0.1:8018` sin exponer puertos adicionales ni modificar el modo `Real` predeterminado del indicador.
  - **Integración en vistas de operación**: Conectados `pesaje.js`, `index.php`, `primera_pesada.php`, `segunda_pesada.php`, `layout.php`, `api_bascula_control.php` y `api_sync_pendientes.php` para lectura continua y captura de pesadas desde teléfonos/tablets en patio.
  - **Código QR 100% Offline (`web/public/assets/js/qr_local.js`)**: Generador nativo en SVG (ISO/IEC 18004, sin internet ni CDNs) integrado en el botón discreto **📱 Abrir en celular (QR)** en `index.php` y en la tarjeta de acceso LAN en `despliegue.php` (commit `da040c1`).
  - **Ergonomía Móvil en Primera y Segunda Pesada (`style.css`, `primera_pesada.php`, `segunda_pesada.php`)**: Exclusivo bajo `@media (max-width: 768px)` sin alterar escritorio: el **Indicador en vivo** se posiciona primero (`order: -1`) arriba del botón de fijar peso en la primera pantalla sin deslizar, se compactan encabezados móviles, al pulsar **Fijar peso** la vista se desplaza manteniendo visible arriba el recuadro de **Peso Fijado** y el botón flotante de **Asistente TYTOne** pasa a ícono circular discreto (commits `7d94ade`, `a0a7beb`, `a8abbf0`).
* **Sincronización y Carga de Catálogos Maestros (`2026-11-24`, ~02:46 PM)**:
  - Generado respaldo previo obligatorio `dbbackup/respaldo_pre_catalogos_20261124.sql` (`34,501` bytes) antes de tocar tablas.
  - Creado y ejecutado script idempotente `scripts/sincronizar_catalogos_maestros.php` (y actualizados `scripts/dbBascula.sql`, `scripts/actualizar_bd_pc_bascula.sql` y `SEED_GEMINI.md`):
    - **Categorías (`t_categorias`)**: 5 activas (`CAFE`, `ACEITES`, `GRANOS BASICOS`, `COQUILLO`, `SALVADO`).
    - **Productos (`t_productos`)**: 7 productos existentes actualizados con su `categoria_id` y `codigo` (incluyendo normalización de `CAFE HÚMEDO`) + 6 productos nuevos creados (`CAFE STOCK`, `FRIJOL`, `MAÍZ`, `CAFE SECO`, `COQUILLO`, `SALVADO`) con sus presentaciones asociadas en `t_producto_presentaciones` sin romper FKs de `t_pesadas`.
    - **Clientes / Proveedores (`t_clie_prov`)**: Insertados los 37 registros maestros (`CONSUMIDOR FINAL` + 36 clientes/proveedores con su código, teléfono, correo, dirección, ciudad y observaciones), habilitados tanto para `COMPRA` como para `VENTA` (`es_cliente = 1`, `es_proveedor = 1`), conservando intacto `INVERSIONES VALLECILLO` y el indicador serial en `SERIAL` / `COM4` / `9600` (commit `84a4edb`).
* **Depuración de Pesadas de Prueba (`2026-11-24`, ~02:49 PM)**:
  - Generado respaldo previo `dbbackup/respaldo_pre_limpieza_pesadas_20261124.sql` y eliminadas por solicitud explícita del usuario las 14 pesadas de prueba anteriores (junto con su registro en `t_pesada_anulaciones` y eventos huérfanos en `t_sincronizacion_pendiente`), conservando únicamente **`Compra #13`** (`idpesada = 15`, estado `ABIERTA`, `50,460 lb`).
* **Sugerencia de Dirección y Productos sin Acentos + Sync Nube (`2026-11-24`, ~05:05 PM)**:
  - **`web/public/primera_pesada.php`**: Al seleccionar proveedor/cliente se sugiere automáticamente su `direccion` en `Origen / Punto de partida` (en `COMPRA`) o en `Destino` (en `VENTA`), manteniendo `CEIBITA SUR, SANTA BARBARA` para el extremo de planta.
  - **Productos sin acentos (`t_productos`)**: Normalizados en `dbbascula`, `scripts/sincronizar_catalogos_maestros.php` y `scripts/seed_productos.php` (`CAFE HUMEDO`, `MAIZ`).
  - **Sincronización completa espejo (`api_sync.php`, `sync_nube.php`)**: Agregado parámetro `reemplazar_pesadas` en sincronización manual completa para purgar en Hostinger las pesadas eliminadas en planta local y reflejar exactamente los catálogos y `Compra #13` (commit `2534761`).
* **Buscador y Sugerencia de Presentación en Primera Pesada (`web/public/primera_pesada.php`)**:
  - Agregado `inicializarBuscadorSelect()` en Vanilla JS para filtrar al instante **Cliente / Proveedor** y **Producto** (ignorando mayúsculas y acentos) manteniendo intacto el `<select>` oculto, la sugerencia automática de dirección y el filtro `COMPRA`/`VENTA` (commit `d9d12c6`).
  - Preseleccionada automáticamente la presentación **`SACOS`** al elegir cualquier producto tipo `CAFE` y **`A GRANEL`** al elegir `ACEITE` en `filtrarPresentaciones()` (commit `d490bf8`).
* **RTN en Boleta de Compra y Venta (`web/public/boleta.php`)**:
  - Agregado el **RTN** de la empresa (`t_empresas.rtn`) en el encabezado de la boleta oficial (aplica tanto para `COMPRA` como para `VENTA`), ubicado justo debajo de la dirección (commit `43453a1`).
* **Tema Claro Predeterminado (`web/public/assets/js/theme.js`, `web/src/layout.php`)**:
  - Cambiado el tema por defecto de `'dark'` a `'light'` (Modo Claro) manteniendo la opción de alternar manualmente a Modo Oscuro desde el botón superior/sidebar.
* **Depuración y Alineación Documental (`2026-11-25`)**:
  - Eliminado el borrador sin seguimiento `IMPLEMENTACION.MD` (con secciones duplicadas y superado por `IMPLEMENTACION_FINAL.MD`).
  - Actualizado `SEED_GEMINI.md` completando las 12 tablas (`t_sincronizacion_pendiente`) y el 5.º rol `REMOTO`.
  - Actualizado `AGENTS_GEMINI.md` documentando el espejo HTTPS de solo lectura en Hostinger y el rol `REMOTO` en RBAC.
  - Actualizado el árbol de archivos de `README_GEMINI.md` reemplazando la referencia obsoleta `web/src/bascula.php` por `sync_nube.php`, `api_peso_local.php` y `api_bascula_control.php`.
  - **Unificación definitiva de guías (`README.md` e `IMPLEMENTACION.md`)**:
    - Consolidados `README.md`, `README_GEMINI.md`, `LEEME.md` y `web/README.md` en un único **`README.md`** principal actualizado a la arquitectura real (XAMPP + FastAPI `:8018` + LAN QR Offline + Espejo Hostinger), eliminando los duplicados históricos para evitar confusiones.
    - Consolidados íntegramente `IMPLEMENT.md` e `IMPLEMENTACION_FINAL.MD` en una única guía maestra **`IMPLEMENTACION.md`** (conservando el 100% de reglas Windows/COM/DCB, baseline XAMPP/`php.ini`, instalación limpia y migración idempotente de las 12 tablas + constraints `CEILING`, diagnóstico SSE, red LAN QR, sincronización Hostinger, autoarranque de corte definitivo, checklist de 12 pruebas y protocolo de actualización/reversión).
    - Verificado y documentado en `IMPLEMENTACION.md` el mecanismo real de autoarranque ya activo en `shell:startup` (`Iniciar Apache y MySQL.vbs` + `XAMPP Control.lnk`), evitando la dependencia de escritura UAC sobre `C:\xampp\xampp-control.ini`.
* **Favicon Oficial TYTOne (`web/public/favicon.ico`)**:
  - Convertido el logotipo oficial `tytlogo.png` a icono Windows multi-resolución 32-bit ARGB (`16x16`, `32x32`, `48x48`, `64x64`, `256x256`) con el nombre estándar `favicon.ico` en `web/public/favicon.ico` (y actualizado en `C:\xampp\htdocs\favicon.ico` con respaldo previo `favicon.xampp.bak`), enlazado en `layout.php`, `login.php`, `boleta.php` y `guia_remision.php`.
* **Sincronización Automática Total (Pesadas + Anulaciones + Catálogos / Administración)**:
  - Agregado `sincronizarCatalogosAutomatico()` y `encolarCatalogosPendientes()` en `web/src/sync_nube.php`, integrando también `entidad = 'catalogos'` y `t_pesada_anulaciones` en `sincronizarPesadaAutomatica()` y `procesarPendientesSyncNube()` (reintento automático cada 5 minutos si no hay internet).
  - Agregada limpieza espejo de `t_producto_presentaciones` (`reemplazar_producto_presentaciones`) en `web/public/api_sync.php`.
  - Conectado disparo automático inmediato al guardar/editar/alternar estado en `clientes.php`, `categorias.php`, `productos.php`, `presentaciones.php`, `presentaciones_tipo.php`, `empresa.php`, `usuarios.php` y `mi_clave.php`.
