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
* Módulo 1: Control de Acceso y Autenticación:
  - Incorporado logo institucional `tytlogo.png` en `web/public/assets/img/tytlogo.png`.
  - Construido `web/public/login.php` con protección CSRF, sanitización, consulta preparada PDO a `t_usuarios` y verificación segura de hash.
  - Construido `web/public/logout.php` para cierre seguro de sesión y destrucción de cookies.
  - Validada sintaxis de todos los archivos con PHP 8 en XAMPP (cero errores).

### 4. Siguientes Pasos
1. Crear el enlace directo (`Junction`) entre `web/public` y `C:\xampp\htdocs\bascula`.
2. Configurar la conexión PDO en PHP a la base de datos de XAMPP (`dbBascula`).
3. Construir el microservicio FastAPI (`api/main.py`) con endpoints `/api/v1/peso` (simulado/real), `/api/v1/puertos` y `/api/v1/modo`.
4. Construir la pantalla principal y módulo de pesaje en PHP.
