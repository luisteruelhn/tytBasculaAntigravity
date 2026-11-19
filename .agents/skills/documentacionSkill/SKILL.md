---
name: documentacionSkill
description: >-
  Estandar mandatorio para documentacion continua, mantenimiento de bitacora, gestion de AGENTS.md, generacion de seeds de datos, comentarios claros de codigo y commits atomicos por cada avance, formulario o instruccion importante.
  Usalo en todos los proyectos para preservar la trazabilidad, reproducibilidad y control de versiones profesional.
---

# DocumentacionSkill: Bitácora, AGENTS.md, Seeds, Comentarios y Commits Atómicos

Esta skill establece las directivas obligatorias para mantener cualquier proyecto con documentación impecable, trazabilidad completa, memoria de acuerdos mediante AGENTS.md y capacidad de despliegue reproducible.

---

## 1. Commits Atómicos por Cada Avance Relevante
- **Regla Mandatoria**: **Por cada tarea, formulario terminado, bug corregido o instrucción importante del usuario, se DEBE crear un commit en Git.**
- **Formato Conventional Commits**:
  - `feat(...)`: Para nueva funcionalidad, formulario o vista nueva (ej. `feat(reportes): filtros de categoria y producto`).
  - `fix(...)`: Para solución de errores, advertencias o bugs (ej. `fix(reportes): corregir calculo de subtotal de operacion`).
  - `docs(...)`: Para bitácora, AGENTS.md, README o comentarios técnicos (ej. `docs: actualizar acuerdos en AGENTS.md`).
  - `refactor(...)`: Para optimización quirúrgica de código sin alterar su funcionamiento.
- **Principio**: Commits pequeños, descriptivos y frecuentes. Nunca acumular múltiples cambios dispares en un solo commit gigante.

---

## 2. Gestión Obligatoria de AGENTS.md (Acuerdos del Proyecto)
- En todo proyecto debe existir y mantenerse al día un archivo **`AGENTS.md`** en la raíz del repositorio.
- **Contenido del `AGENTS.md`**:
  - **Acuerdos del usuario**: Reglas de negocio fijadas (ej. fuente real de hardware predeterminada, restricciones de acceso).
  - **Pilas tecnológicas y arquitectura**: Versiones y librerías aprobadas (ej. PHP nativo, FastAPI, MariaDB).
  - **Reglas operativas del agente**: Directivas de ejecución quirúrgica, prevención de código espagueti y estándares acordados.
  - **Prohibición de regresiones**: Cada nuevo acuerdo explícito establecido con el usuario debe registrarse en `AGENTS.md` para que ningún agente posterior revierta o contradiga lo acordado.

---

## 3. Bitácora de Cambios Continua (Changelog / Registro)
- Mantener o actualizar un archivo de bitácora (ej. `CHANGELOG.md`, `BITACORA.md` o sección de cambios en la documentación del proyecto) tras hitos clave:
  - **Fecha y hora**.
  - **Módulo o archivo intervenido**.
  - **Descripción clara del cambio** y objetivo del requerimiento.
  - **Estado**: Funcional, probado y verificado.

---

## 4. Seeds y Esquemas de Base de Datos Reproducibles
- **Cada vez que se introduzca un cambio de esquema o datos maestros**:
  - Debe actualizarse el script de inicialización (`schema.sql`, `seed.sql` o migraciones equivalentes).
  - Incluir datos de prueba iniciales mínimos pero completos (usuarios con contraseñas hasheadas, empresas, categorías, productos y configuraciones base).
  - Garantizar que cualquier desarrollador o servidor nuevo pueda montar la base de datos completa ejecutando un solo comando de importación.

---

## 5. Comentarios Claros y Explicativos en el Código
- **Comentar el "Por qué", no lo evidente**:
  - Explicar reglas de negocio, cálculos críticos, fórmulas y casos de excepción (ej. por qué una división va entre 100, por qué se resta la tara de empaques o cómo opera un hook de hardware).
- **Docstrings y firmas tipadas**:
  - En PHP: Bloques PHPDoc `/** ... */` indicando `@param`, `@return` y excepciones lanzadas.
  - En Python: Docstrings PEP 257 en funciones, métodos y clases, con type-hints completos (`def calcular_peso(...) -> float:`).
  - En JavaScript: JSDoc en funciones clave de frontend y controladores de eventos.

---

## 6. Verificación Antes del Commit
Antes de ejecutar el commit:
1. Comprobar que no haya errores de sintaxis (`php -l`, compilación o linter).
2. Si se crearon nuevos acuerdos o directivas, verificar que queden reflejados en `AGENTS.md`.
3. Verificar que los archivos temporales estén limpios.
4. Ejecutar el `git commit` de inmediato sin esperar que el usuario lo solicite.
