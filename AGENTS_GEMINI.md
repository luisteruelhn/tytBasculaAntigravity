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
* **Espejo en Nube (Solo Consulta)**: Operación de pesaje 100% local/on-premise (offline-first). El servidor remoto en Hostinger (`inversionesvallecillo.com`) se utiliza únicamente como espejo HTTPS de **solo lectura** (sincronizado mediante `api_sync.php` y `sync_nube.php`), sin dependencias de servicios pesados de nube.

---

## 3. Estándares de Código y Diseño
* **Ultra-ligero y de alto rendimiento**:
  - Sin frameworks sobrecargados ni paquetes de Composer/NPM innecesarios.
  - PHP nativo moderno, modular, limpio y tipado (`declare(strict_types=1)`).
  - CSS nativo limpio, responsivo y estilo industrial moderno (display LED/digital de peso grande, claro y de alto contraste).
  - JavaScript nativo (Vanilla JS) sin dependencias pesadas.
* **Diseño 100% Responsivo (Mobile & Desktop)**:
  - Todas las pantallas y formularios deben adaptarse con total fluidez a cualquier dispositivo (teléfonos móviles, tablets, laptops y monitores táctiles industriales de cabina).
  - Tipografía fluida (`clamp()`) en displays numéricos de peso para evitar desbordamientos en pantallas pequeñas.
  - Botones y campos de entrada con tamaño táctil cómodo (mínimo 44px de altura).
* **Legibilidad y Mantenibilidad Humana**:
  - Código limpio, legible, bien estructurado y profusamente comentado donde la lógica de negocio lo requiera.
  - Nombres de funciones y variables claros, intuitivos y autoexplicativos.
  - Evitar trucos oscuros, expresiones regulares complejas sin documentar o código ofuscado.
  - Diseñado para que cualquier desarrollador humano pueda entenderlo, modificarlo y darle mantenimiento fácilmente.
* **Seguridad sin compromisos**:
  - Consultas SQL estrictamente preparadas con **PDO** (cero inyección SQL).
  - Protección **CSRF** en todos los formularios y endpoints de acción (POST).
  - Manejo seguro de sesiones (`HttpOnly`, `SameSite=Strict`, `cookie_secure` cuando aplique, regeneración de ID).
  - Control de Acceso Basado en Roles (**RBAC**): `SUPER`, `ADMINISTRADOR`, `SUPERVISOR`, `OPERADOR`, `REMOTO` (solo consulta).
  - Protección contra fuerza bruta en inicio de sesión.
  - Sanitización estricta de salidas contra XSS (`htmlspecialchars`).

---

## 4. Trazabilidad, Control de Versiones y Documentación
* **Compromisos Git (Commits continuos)**:
  - Crear un commit en Git inmediatamente después de cada cambio o conjunto coherente de modificaciones verificado.
  - Mensajes de commit claros, estructurados y descriptivos del cambio realizado.
* **Documentación permanente**:
  - Todo cambio, decisión, prueba y despliegue debe registrarse cronológicamente en:
    - `BITACORA_GEMINI.md`: Registro de intervenciones con fecha y hora.
    - `README.md`: Guía unificada de arquitectura, módulos y ejecución rápida.
    - `IMPLEMENTACION.md`: Guía maestra paso a paso para instalaciones, migraciones y puesta en producción.
    - `SEED_GEMINI.md`: Esquema de base de datos, datos iniciales y procedimientos de respaldo.

---

## 5. Regla Obligatoria de Catálogos de Pesaje: Presentaciones y Taras de Empaque
* **Inclusión OBLIGATORIA (SÍ O SÍ) en MariaDB**:
  - La tabla `t_presentaciones` (Taras de Empaque / Presentaciones) es **estrictamente obligatoria** y debe estar presente e integrada **SÍ O SÍ** en cualquier instalación, migración, respaldo o despliegue de la base de datos `dbBascula`.
  - **Motivo de la regla**: En las versiones de prueba previas este catálogo fue omitido o dejado ambiguo, lo cual impedía que el módulo de Primera y Segunda Pesada calculara con precisión los descuentos de empaque y las agrupaciones de producto en las boletas oficiales.
  - **Restricciones permanentes**:
    1. **NO usar archivos JSON sueltos** para presentaciones ni tipos de empaque.
    2. Debe residir siempre en la tabla `t_presentaciones` de MariaDB con clave única `(empresa_id, codigo)`.
    3. Debe incluir siempre al menos los registros base iniciales: `SACOS` (código `SACOS`) y `A GRANEL` (código `GRANEL`).
    4. Esta regla es un acuerdo vinculante y bajo ninguna circunstancia debe eliminarse ni ignorarse en futuras iteraciones.
