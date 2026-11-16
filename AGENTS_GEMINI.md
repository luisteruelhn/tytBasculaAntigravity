# Acuerdos y Reglas del Usuario — Gemini / Antigravity

Documento de directrices y reglas obligatorias para el asistente en el proyecto **tytBasculaAntigravity**.

---

## 1. Principios de Báscula y Fuentes de Lectura
1. **La fuente predeterminada de Báscula es el indicador real**:
   - En despliegue y producción, la aplicación debe arrancar intentando escuchar el indicador físico real.
   - Preservar este acuerdo al modificar código, crear empresas, usuarios o esquemas.
   - No cambiar a simulación de manera permanente ni sin solicitud explícita del usuario.
2. **Entorno de desarrollo local**:
   - La computadora de desarrollo actual no cuenta con la báscula física conectada.
   - Se utiliza el modo **Simulado** para programar, probar y validar flujos en desarrollo.
   - El selector de fuente debe alternar efectivamente entre **Real** y **Simulado**.
   - Alternar a Simulado **conserva intactos los parámetros de comunicación serial confirmados**.
3. **Restricción de reversión**:
   - Antes de revertir o deshacer cualquier cambio previamente acordado, consultar al usuario explicando el contexto completo, la razón técnica, efectos y alternativas.

---

## 2. Pila Tecnológica y Arquitectura Aprobada
* **Frontend y Lógica de Negocio**: PHP 8 nativo bajo servidor web **Apache de XAMPP**.
* **Base de Datos**: **MariaDB de XAMPP** (`127.0.0.1:3306`, base de datos `dbBascula`).
* **Microservicio de Hardware / Báscula**: **FastAPI** en Python portable (`runtime/python/python.exe`), escuchando en loopback local (`http://127.0.0.1:8018`).
* **Exclusión de Nube**: Entorno 100% local/on-premise. No se utiliza Google Cloud ni dependencias externas en la nube.

---

## 3. Estándares de Código y Diseño
* **Ultra-ligero y de alto rendimiento**:
  - Sin frameworks sobrecargados ni paquetes de Composer/NPM innecesarios.
  - PHP nativo moderno, modular, limpio y tipado (`declare(strict_types=1)`).
  - CSS nativo limpio, responsivo y estilo industrial moderno (display LED/digital de peso grande, claro y de alto contraste).
  - JavaScript nativo (Vanilla JS) sin dependencias pesadas.
* **Seguridad sin compromisos**:
  - Consultas SQL estrictamente preparadas con **PDO** (cero inyección SQL).
  - Protección **CSRF** en todos los formularios y endpoints de acción (POST).
  - Manejo seguro de sesiones (`HttpOnly`, `SameSite=Strict`, `cookie_secure` cuando aplique, regeneración de ID).
  - Control de Acceso Basado en Roles (**RBAC**): `SUPER`, `ADMINISTRADOR`, `SUPERVISOR`, `OPERADOR`.
  - Protección contra fuerza bruta en inicio de sesión.
  - Sanitización estricta de salidas contra XSS (`htmlspecialchars`).

---

## 4. Trazabilidad y Documentación
Todo cambio, decisión, prueba y despliegue debe registrarse cronológicamente en:
* `BITACORA_GEMINI.md`: Registro de intervenciones con fecha y hora.
* `README_GEMINI.md`: Guía de arquitectura, instalación y ejecución.
* `SEED_GEMINI.md`: Esquema de base de datos, datos iniciales y procedimientos de respaldo.
