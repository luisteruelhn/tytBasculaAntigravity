# TYTOne Báscula (`tytBasculaAntigravity`) — Sistema de Pesaje Electrónico Industrial

Sistema de gestión de báscula camionera e industrial ultra-ligero, seguro y de alto rendimiento, diseñado para operar **100% local en planta (offline-first)** sobre **XAMPP** (Apache + MariaDB) y un microservicio **FastAPI** en Python portable para lectura directa de indicadores físicos RS-232, con soporte móvil en red local Wi-Fi (QR offline) y espejo opcional de **solo consulta** en la nube (Hostinger).

---

## 1. Arquitectura General del Sistema

```text
+-----------------------------------------------------------------------+
|               OPERADORES EN PLANTA (PC Y MÓVILES LAN WI-FI)           |
|   PC Cabina (localhost)  |  Celular / Tablet Patio (QR Offline LAN)   |
+-----------------------------------+-----------------------------------+
                                    | HTTP Local (:80) / AJAX
                                    v
+-----------------------------------------------------------------------+
|                       XAMPP - SERVIDOR WEB APACHE                     |
|           http://localhost/bascula/  |  http://<IP-LAN>/bascula/      |
|                                                                       |
|  PHP 8 Nativo (strict_types=1, cero frameworks pesados)               |
|  - Módulos: Inicio/Monitor, 1ra/2da Pesada, Pesada Única, Boletas,    |
|    Guía de Remisión, Reportes (Imprimir/PDF/Excel), Catálogos, IA     |
|  - Seguridad: PDO preparado, CSRF, RBAC (5 roles), Sesiones blindadas |
|  - Puentes locales: api_peso_local.php, api_bascula_control.php       |
+-----------------+-----------------------------------+-----------------+
                  |                                   |
       PDO SQL    |                                   | Loopback HTTP
  127.0.0.1:3306  v                                   v 127.0.0.1:8018
+-----------------------------+     +-----------------------------------+
|       XAMPP - MARIADB       |     |       MICROSERVICIO FASTAPI       |
|       Base: dbBascula       |     |    (Python Portable / runtime)    |
|         (12 tablas)         |     |                                   |
|                             |     | - Lector Serial Win32 (LP7510/C18)|
| - t_empresas   - t_pesadas  |     | - Fuente predeterminada: REAL     |
| - t_usuarios   - t_taras    |     | - Restauración limpia de DCB COM  |
| - t_clie_prov  - t_productos|     | - Selector Real / Simulado        |
+--------------+--------------+     +-----------------+-----------------+
               |                                      | RS-232 / USB COM
  Sync HTTPS   | (Offline-First / Cola)               v
               v                    +-----------------------------------+
+-----------------------------+     |     INDICADOR FÍSICO BÁSCULA      |
|   ESPEJO NUBE (HOSTINGER)   |     |   (COM4, 9600 8N1, C18=4 / lb)    |
|    Solo Consulta / REMOTO   |     +-----------------------------------+
+-----------------------------+
```

---

## 2. Pila Tecnológica y Componentes

1. **Aplicación Web (PHP 8 + Apache de XAMPP):**
   * **Ruta local:** `http://localhost/bascula/` (servido desde `web/public` mediante enlace *Junction* en `C:\xampp\htdocs\bascula`).
   * **Interfaz:** Diseño industrial responsivo (escritorio, pantallas táctiles de cabina y teléfonos móviles), Modo Claro predeterminado con selector a Modo Oscuro, display digital de alta visibilidad y código QR generado en SVG 100% offline.
2. **Microservicio de Hardware (FastAPI + Python Portable):**
   * **Intérprete aislado:** `runtime/python/python.exe` (CPython 3.14 embebido; no requiere instalar Python en Windows ni modificar el `PATH`).
   * **Escucha exclusiva en loopback:** `http://127.0.0.1:8018` (nunca se expone a la red).
   * **Endpoints principales:**
     * `GET /api/v1/salud`: Estado del servicio, `modo`, `puerto_com`, `lector_activo` y `pausado_manual`.
     * `GET /api/v1/peso`: Peso actual en libras (`lb`), estabilidad (`estable`: `ST`/`US`), unidad y origen (`real`/`simulado`).
     * `GET /api/v1/puertos`: Lista de puertos `COM` disponibles en Windows.
     * `POST /api/v1/configurar_serial`: Fija `puerto_com` y `baudios` en memoria.
     * `POST /api/v1/modo`: Alterna entre `real` y `simulado` conservando intactos los parámetros seriales en MariaDB.
     * `POST /api/v1/lector/detener` y `POST /api/v1/lector/iniciar`: Pausan o reanudan el lector serial para liberar el puerto `COM` bajo demanda o durante pruebas en vivo SSE.
3. **Base de Datos (MariaDB en XAMPP):**
   * **Conexión local:** `127.0.0.1:3306`, base `dbBascula` (`utf8mb4_unicode_ci`, 12 tablas con integridad referencial y validaciones `CHECK`).
4. **Espejo en la Nube (Hostinger — Solo Lectura):**
   * Sincronización automática *offline-first* (`web/src/sync_nube.php` → `web/public/api_sync.php`) con cola de respaldo en `t_sincronizacion_pendiente` cuando no hay internet en planta.

---

## 3. Estructura del Repositorio

```text
TYTOneBasculaAntigravity/
├── .agents/skills/        # Estándares portables de arquitectura, seguridad y hardware
├── api/                   # Microservicio FastAPI de hardware serial (:8018)
│   ├── main.py            # Endpoints HTTP y control de ciclo de vida del lector
│   ├── serial_reader.py   # Lector Win32 directo con restauración segura de DCB
│   └── simulator.py       # Simulador de peso para desarrollo y pruebas
├── runtime/               # Runtimes portables locales (excluidos de Git)
│   └── python/            # CPython 3.14 embebido con FastAPI/Uvicorn verificados
├── scripts/               # Esquemas SQL, migraciones idempotentes y utilidades
│   ├── dbBascula.sql                      # Esquema base completo (instalación limpia)
│   ├── actualizar_bd_pc_bascula.sql       # Migración segura idempotente para producción
│   ├── actualizar_chk_redondeo_arriba.sql # Constraints CEILING para liquidación
│   ├── sincronizar_catalogos_maestros.php # Semilla idempotente de catálogos maestros
│   └── probar_lectura_serial.py           # Diagnóstico serial aislado de solo lectura
├── web/                   # Aplicación Web PHP 8
│   ├── config/
│   │   ├── db.php                 # Conexión PDO y zona horaria (America/Tegucigalpa)
│   │   ├── local.example.php      # Plantilla para credenciales locales/remotas
│   │   └── landing_hostinger.html # Portada institucional discreta en dominio público
│   ├── src/
│   │   ├── auth.php       # Sesiones, CSRF, RBAC, límite de intentos y detección LAN
│   │   ├── layout.php     # Sidebar industrial, cabecera/pie y sync con FastAPI
│   │   └── sync_nube.php  # Cliente HTTPS de sincronización hacia Hostinger
│   └── public/            # Único directorio público web (/bascula)
│       ├── index.php               # Inicio, monitor en vivo, Arrancar/Parar COM y QR
│       ├── login.php / logout.php  # Autenticación segura y cierre de sesión
│       ├── primera_pesada.php      # Entrada, buscadores rápidos y ⚡ Pesada Única
│       ├── segunda_pesada.php      # Salida, descuentos (%H/%D) y cierre de boleta
│       ├── pesadas.php             # Historial, reimpresión y anulación auditada
│       ├── boleta.php              # Boleta oficial (2 copias Carta / 1 copia PDF)
│       ├── guia_remision.php       # Guía de Remisión para ventas (15 motivos)
│       ├── reportes.php            # Reportes jerárquicos (Imprimir, PDF, Excel)
│       ├── dashboard.php           # Indicadores ejecutivos en libras y quintales (QQ)
│       ├── clientes.php            # Catálogo de Clientes y Proveedores
│       ├── categorias.php          # Categorías de producto
│       ├── productos.php           # Productos y vínculo de presentaciones
│       ├── presentaciones.php      # Taras de empaque / sacos
│       ├── presentaciones_tipo.php # Catálogo maestro obligatorio (SACOS, A GRANEL)
│       ├── empresa.php             # Razón social, RTN, dirección, contacto y logo
│       ├── usuarios.php / mi_clave.php # Gestión RBAC y cambio de contraseña
│       ├── config_bascula.php      # Parámetros COM, Real/Simulado, prueba SSE e IA
│       ├── despliegue.php          # Respaldo SQL, paquete ZIP y sync manual Nube
│       ├── api_peso_local.php      # Puente autenticado LAN hacia FastAPI (:8018)
│       ├── api_bascula_control.php # Arranque / detención del lector COM desde Inicio
│       ├── api_sync.php            # Receptor HTTPS en Hostinger (X-Bascula-Token)
│       ├── api_sync_pendientes.php # Reintento automático de cola pendiente
│       ├── api_chat.php            # Asistente TYTOne de solo lectura (Gemini)
│       ├── serial_prueba.php       # Stream SSE para diagnóstico en vivo del puerto COM
│       └── assets/                 # CSS industrial, tema Claro/Oscuro, JS y QR SVG
├── AGENTS.md              # Reglas operativas principales y acuerdos del usuario
├── IMPLEMENTACION.md      # Guía maestra paso a paso para futuras implementaciones
├── desplieguehostinger.md # Manual específico de configuración en Hostinger hPanel
├── SEED_GEMINI.md         # Diccionario de las 12 tablas, 5 roles RBAC y respaldos
├── PYTHON_PORTABLE.md     # Documentación y verificación del runtime Python portable
└── BITACORA_GEMINI.md     # Historial cronológico de cambios y despliegues
```

---

## 4. Reglas Operativas, Cálculos y Seguridad

* **Fuente predeterminada Real (`SERIAL`):** El sistema arranca siempre apuntando al indicador físico real sin alterar configuraciones de Windows. Cambiar a **Simulado** es exclusivo para pruebas y conserva intactos los parámetros reales confirmados en base de datos.
* **Cálculos de Liquidación y Redondeo:**
  * Peso bruto y tara vehicular en **libras enteras (`lb`)**; conversión a quintales en `QQ = lb / 100` con 2 decimales.
  * La tara de sacos (`cantidad × tara_unitaria`), el descuento por humedad (`%H`) y el descuento por defectos (`%D`) aplican **redondeo hacia arriba al entero superior (`CEILING`)**, validado tanto en interfaz como en constraints de MariaDB.
* **Formatos de Impresión y Exportación:**
  * **Boleta (`boleta.php`) y Guía de Remisión (`guia_remision.php`):** Impresión física optimizada con **2 copias por hoja Carta** separadas por línea de corte (`✂`), y botón **Descargar PDF** que genera **1 copia individual limpia**.
  * **Reportes (`reportes.php`):** Incluye por defecto la tríada **Imprimir**, **Descargar PDF** y **Generar Excel** (`.xls`).
* **Roles y Seguridad (RBAC):**
  * Roles soportados: `SUPER`, `ADMINISTRADOR`, `SUPERVISOR`, `OPERADOR` y `REMOTO` (este último restringido estrictamente a consulta en UI y backend).
  * Consultas 100% preparadas con PDO, validación CSRF en todo `POST`, cookies `HttpOnly` / `SameSite=Strict`, límite de intentos de login contra fuerza bruta y bloqueo `.htaccess` en carpetas internas.

---

## 5. Guía de Ejecución Rápida (Uso Diario en Planta)

1. **Iniciar XAMPP:**
   * Abrir `C:\xampp\xampp-control.exe` y asegurarse de que **Apache** (`:80`) y **MySQL** (`:3306`) estén en ejecución.
2. **Activar Lectura de Báscula (`FastAPI :8018`):**
   * **Desde el navegador (recomendado):** Entrar a `http://localhost/bascula/` y pulsar **▶️ Arrancar Lectura** en la pantalla de **Inicio** (o **⏹️ Parar Lectura** si se necesita liberar el puerto `COM` para otro programa).
   * **Por consola / acceso directo:**
     ```cmd
     runtime\python\python.exe -B -m uvicorn api.main:app --host 127.0.0.1 --port 8018
     ```
3. **Acceso desde PC o Móvil en Patio (Wi-Fi LAN):**
   * **PC de cabina:** `http://localhost/bascula/`
   * **Celular o Tablet (mismo Wi-Fi local):** Pulsar **📱 Abrir en celular (QR)** en **Inicio** y escanear el código QR (ej. `http://192.168.2.20/bascula/`). Permite ver el peso real en vivo y registrar Primera/Segunda Pesada sin internet ni abrir el puerto `8018` fuera de loopback.

---

## 6. Documentación de Referencia

* **[IMPLEMENTACION.md](IMPLEMENTACION.md):** Guía completa e integral para instalar desde cero, migrar bases existentes sin pérdida de datos, configurar hardware serial y poner en producción nuevas estaciones.
* **[desplieguehostinger.md](desplieguehostinger.md):** Paso a paso detallado en Hostinger hPanel y sincronización por Git o `.zip`.
* **[SEED_GEMINI.md](SEED_GEMINI.md):** Inventario de las 12 tablas de `dbBascula`, roles RBAC, formato de trama serial y comandos de respaldo/restauración.
* **[PYTHON_PORTABLE.md](PYTHON_PORTABLE.md):** Verificación e integridad del paquete portable `runtime/python`.
* **[AGENTS.md](AGENTS.md) / [AGENTS_GEMINI.md](AGENTS_GEMINI.md):** Reglas de arquitectura, seguridad y acuerdos permanentes del proyecto.
* **[BITACORA_GEMINI.md](BITACORA_GEMINI.md):** Registro cronológico de todas las intervenciones y commits.
