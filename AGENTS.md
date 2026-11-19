# Acuerdos del usuario

La fuente predeterminada de Báscula es el indicador real. Preservar este acuerdo al modificar código, crear usuarios/empresas o ejecutar esquemas. No cambiar a simulación sin solicitud explícita.
Antes de deshacer o revertir cualquier cambio previamente acordado, consultar al usuario y explicar la razón, el contexto completo, los efectos y las alternativas. Revisar primero la instalación activa y sus diferencias; no reemplazarla por versiones antiguas del workspace.
El selector debe cambiar efectivamente la fuente y mostrar Real o Simulado de acuerdo con ella. Alternar fuentes conserva los parámetros reales confirmados mientras no se editen. Dejar Real seleccionado al terminar comprobaciones.

## Regla de Ejecución Quirúrgica y Enfoque Ligero (Monobáscula)
1. **Acciones directas y quirúrgicas (CERO RODEOS)**:
   - Prohibido dar vueltas, sobreanalizar o inspeccionar archivos ajenos al cambio solicitado.
   - Ir de inmediato y en el primer paso al archivo y línea exacta a modificar.
   - Aplicar únicamente la instrucción puntual del usuario sin agregar elementos no solicitados.
2. **Arquitectura ultra-ligera y simplificada**:
   - El alcance prioritario e inmediato del sistema es para **una sola báscula física**.
   - Prohibido sobre-diseñar, crear abstracciones complejas, capas intermedias artificiales o proyectos "pesadotes".
   - Mantener el stack plano, rápido y directo: PHP nativo conciso + MariaDB limpio + FastAPI ligero de hardware.
   - Cada intervención debe ser mínima, precisa y funcional sin inflar el código ni desperdiciar tokens.


## Skills del Proyecto y Cumplimiento Obligatorio
- **Apego estricto a las skills (sin importar el modelo activo):** Toda intervención debe seguir las skills portables ubicadas en `.agents\skills\` (`surgical-code-editing`, `documentacionSkill`, `clean-architecture-anti-spaghetti`, `web-security-hardening`, `print-and-reporting`, `fastapi-lean-and-secure`, `github-produccion-flow`, `web-deploy-packager`, `crear-copia-agents-skills`, `bascula-sync-nube`, `serial-hardware-windows` y `mariadb-safe-migrations`).
- **Portabilidad:** Conservar `.agents\skills\` dentro del proyecto para mantener consistencia al cambiar de PC o entorno.
- **Trazabilidad:** Validar sintaxis (`php -l`), actualizar `BITACORA_GEMINI.md` ante hitos relevantes y realizar commits atómicos inmediatos en formato Conventional Commits por cada avance o corrección.
