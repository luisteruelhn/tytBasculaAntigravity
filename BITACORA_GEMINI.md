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
