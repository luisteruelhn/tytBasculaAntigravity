---
name: crear-copia-agents-skills
description: Estandar global para crear siempre una copia de las skills globales dentro de .agents\skills\ en cada proyecto y dejarlo documentado en AGENTS.md y en seed.
---

# Crear una copia de `.agents\skills\` siempre

## Objetivo
Garantizar que todo proyecto tenga una copia local y portable de las skills globales dentro de `.agents\skills\`, lista para revisarse y usarse en cualquier PC.

## Reglas de ejecución
1. **Crear copia en `.agents\skills\`:** Al iniciar un proyecto o detectar que falta, crear `.agents\skills\` en la raíz del repositorio y copiar allí las skills globales desde `C:\Users\luist\.gemini\config\skills\` (sin sobrescribir skills específicas del proyecto).
2. **Dejarlo en `AGENTS.md`:** Asegurar que `AGENTS.md` contemple `.agents\skills\` como parte de la configuración del proyecto.
3. **Dejarlo en `seed`:** Cuando el proyecto maneje scripts de inicialización o `seed`, incluir o preservar la referencia a `.agents\skills\` para mantener la portabilidad al clonar o revisar en otra PC.
