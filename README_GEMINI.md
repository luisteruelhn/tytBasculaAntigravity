# tytBasculaAntigravity — Sistema de Gestión de Báscula Electrónica

Sistema de pesaje camionero e industrial de alto rendimiento, ligero y seguro, diseñado para operar localmente sobre **XAMPP** (Apache + MariaDB) con soporte de lectura de indicadores de báscula mediante un microservicio **FastAPI** (Python portable).

---

## 1. Arquitectura del Sistema

```
+-------------------------------------------------------------------+
|                        NAVEGADOR WEB                              |
|           (Operador / Supervisor / Administrador)                 |
+---------------------------------+---------------------------------+
                                  | HTTP / AJAX (JSON)
                                  v
+-------------------------------------------------------------------+
|                     XAMPP - SERVIDOR WEB APACHE                   |
|                   http://localhost/bascula/                       |
|                                                                   |
|   PHP 8 (Nativo, sin dependencias pesadas, ultra rápido)          |
|   - Módulos: Login, Dashboard, 1ra Pesada, 2da Pesada, Boletas    |
|   - Seguridad: CSRF, PDO parametrizado, RBAC, Sesiones blindadas  |
+-----------------+-------------------------------+-----------------+
                  |                               |
       PDO SQL    |                               | cURL / Fetch Loopback
                  v                               v
+-----------------------------+   +---------------------------------+
|     XAMPP - MARIADB         |   |    MICROSERVICIO FASTAPI        |
|    127.0.0.1:3306           |   |       127.0.0.1:8018            |
|    Base: dbBascula          |   |   (Python Portable / runtime)   |
|                             |   |                                 |
| - t_empresas   - t_pesadas  |   | - Lector Serial COM (LP7510/C18)|
| - t_usuarios   - t_taras    |   | - Simulador de peso para dev    |
| - t_clie_prov  - t_productos|   | - Selector Real / Simulado      |
+-----------------------------+   +----------------+----------------+
                                                   | RS-232 / USB COM
                                                   v
                                  +---------------------------------+
                                  |    INDICADOR FÍSICO BÁSCULA     |
                                  |  (Modo Real en producción)      |
                                  +---------------------------------+
```

---

## 2. Componentes y Tecnologías

1. **Frontend & Backend Web (PHP 8 + Apache)**:
   * **Ruta de acceso**: `http://localhost/bascula/`
   * **Estilo visual**: CSS moderno con diseño industrial (display digital de peso de alta legibilidad, verde/rojo para estabilidad, atajos rápidos de teclado).
   * **Sin frameworks inflados**: Código limpio, rápido, sin bloatware de paquetes externos.
2. **Microservicio de Hardware (FastAPI / Python)**:
   * **Puerto local**: `8018`
   * **Intérprete**: `runtime/python/python.exe` (embebido, no requiere instalar Python en Windows).
   * **Endpoints**:
     * `GET /api/v1/peso`: Devuelve peso bruto en libras, estado de estabilidad (`ST`/`US`), unidad y modo (`real`/`simulado`).
     * `GET /api/v1/puertos`: Detección de puertos COM disponibles en Windows.
     * `POST /api/v1/modo`: Alterna entre Real y Simulado sin tocar la configuración serial guardada.
3. **Persistencia (MariaDB en XAMPP)**:
   * **Puerto**: `3306`
   * **Base de datos**: `dbBascula`

---

## 3. Estructura de Directorios

```
tytBasculaAntigravity/
├── api/                   # Microservicio FastAPI (Python)
│   ├── main.py            # Servidor FastAPI
│   ├── serial_reader.py   # Lector Win32 directo del puerto COM
│   └── simulator.py       # Generador de pesos simulados para desarrollo
├── web/                   # Aplicación Web PHP
│   ├── config/
│   │   └── db.php         # Conexión PDO a MariaDB XAMPP
│   ├── src/
│   │   ├── auth.php       # Funciones de autenticación, CSRF y control de roles
│   │   ├── layout.php     # Plantilla base, barra de navegación y pie
│   │   └── bascula.php    # Integración con el microservicio de peso
│   └── public/            # Carpeta servida por Apache (enlace /bascula)
│       ├── index.php      # Dashboard y monitor de peso en vivo
│       ├── login.php      # Formulario de inicio de sesión
│       ├── logout.php     # Cierre de sesión
│       ├── primera_pesada.php  # Registro de entrada
│       ├── segunda_pesada.php  # Cierre de pesada y cálculo de neto
│       ├── config_bascula.php  # Parámetros del indicador y selector Real/Simulado
│       └── assets/
│           ├── css/style.css   # Estilos industriales y responsivos
│           └── js/pesaje.js    # Actualización en vivo del display de peso
├── runtime/               # Runtimes portables (Python 3.14 con FastAPI)
├── AGENTS_GEMINI.md       # Acuerdos y reglas del usuario
├── BITACORA_GEMINI.md     # Registro de acciones y cambios cronológicos
├── SEED_GEMINI.md         # Catálogo de tablas, credenciales y procedimientos
├── README_GEMINI.md       # Este documento
└── .gitignore
```

---

## 4. Guía de Ejecución Rápida

1. **Iniciar XAMPP**:
   * Abrir `xampp-control.exe`.
   * Iniciar **Apache** y **MySQL**.
2. **Iniciar Microservicio de Báscula (FastAPI)**:
   ```cmd
   runtime\python\python.exe -m uvicorn api.main:app --host 127.0.0.1 --port 8018
   ```
3. **Abrir en el Navegador (PC o Celular en Red Local)**:
   * **En la PC de báscula**: `http://localhost/bascula/`
   * **Desde Celular / Tablet (mismo Wi-Fi LAN)**: Escanear el botón **📱 Abrir en celular (QR)** en **Inicio** o la tarjeta QR en **Despliegue / Nube** (ej. `http://192.168.2.20/bascula/`). El peso en tiempo real se sirve por Apache mediante `api_peso_local.php` sin abrir el puerto `8018` fuera de loopback ni depender de internet.
