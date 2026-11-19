---
name: surgical-code-editing
description: >-
  Reglas y mejores prácticas para aplicar cambios de código quirúrgicos, ligeros y con cero regresiones en cualquier proyecto.
  Úsalo cuando se pidan cambios puntuales, solución de bugs o refactorizaciones mínimas en PHP, JavaScript, Python o backend/frontend.
---

# Surgical Code Editing (Modificación Quirúrgica y Ligera)

Esta skill define la metodología universal para modificar proyectos de manera precisa, eficiente y segura sin inflar código ni romper funciones existentes.

## 1. Principio Fundamental: Cero Rodeos y Cero Regresiones
- **Localización precisa**: Identificar exactamente el archivo y la línea afectada antes de editar.
- **Intervención mínima**: Modificar solo lo estrictamente necesario para cumplir el requerimiento.
- **Prohibido refactorizar sin permiso**: No renombrar variables existentes, no reestructurar carpetas ni cambiar patrones arquitectónicos si el usuario solo pidió una corrección o ajuste puntual.

## 2. Ciclo de Ejecución Quirúrgica
1. **Inspección puntual**: Leer las líneas afectadas del archivo objetivo (±20 líneas de contexto).
2. **Edición exacta**: Usar reemplazos únicos con el menor impacto colateral posible.
3. **Verificación de sintaxis de inmediato**:
   - PHP: `php -l <archivo.php>`
   - JavaScript/Node: `node --check <archivo.js>` o comprobación de sintaxis en V8.
   - Python: `python -m py_compile <archivo.py>`
4. **Validación funcional**: Si se tocó interfaz gráfica o interacción JS, comprobar que no haya errores de consola o de renderizado.

## 3. Manejo de Scripts y Dependencias
- Preferir siempre soluciones nativas y ligeras antes de añadir librerías o dependencias pesadas.
- Si se requiere una librería de frontend (como generador de PDF, gráficas o exportación), cargarla limpiamente sin bloquear el renderizado ni inyectar scripts repetidos.
