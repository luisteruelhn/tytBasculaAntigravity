---
name: clean-architecture-anti-spaghetti
description: >-
  Estandar mandatorio para preservar la consistencia, legibilidad y modularidad de los proyectos, eliminando y previniendo el codigo espagueti.
  Usalo en cualquier proyecto web, backend o frontend (PHP, Python, JS, SQL) para organizar responsabilidades, evitar archivos monstruo y mantener una arquitectura limpia y comprensible.
---

# Clean Architecture & Anti-Spaghetti Standard

Esta skill contiene las directivas obligatorias para mantener una base de código limpia, modular, consistente y libre de "código espagueti".

---

## 1. Principio Fundamental: Separación de Responsabilidades (SoC)
El código espagueti nace cuando en un mismo archivo se mezclan consultas SQL crudas, lógica de negocio compleja, cálculo matemático, manipulación de sesiones y etiquetas de presentación HTML/JS.

### Reglas de Capas:
1. **Capa de Datos (SQL / Base de Datos)**:
   - Las consultas SQL deben estar encapsuladas o agrupadas al inicio del script/servicio.
   - Prohibido intercalar consultas `PDO` o `ORM` en medio de bucles de renderizado HTML (problema N+1 y espagueti).
2. **Capa de Lógica / Procesamiento**:
   - Cálculos, clasificaciones, agrupaciones y normalizaciones se realizan antes de renderizar la vista.
   - Las funciones auxiliares de cálculo (ej. `sumarTotales()`, `calcularNeto()`) deben ser puras: reciben datos, retornan resultados, sin efectos secundarios invisibles.
3. **Capa de Presentación (Vista / HTML / JSON)**:
   - Las plantillas o vistas solo deben iterar variables ya preparadas y formatear valores (`number_format`, `htmlspecialchars`).
   - Evitar bloques gigantes de código PHP o lógica de negocio dentro del HTML.

---

## 2. Prevención de Archivos Monstruo (God Objects / Mega-scripts)
- **Límite de cohesión**: Si un archivo supera las 500-600 líneas con múltiples propósitos distintos, debe desacoplarse quirúrgicamente:
  - Extraer funciones reutilizables a archivos de utilidades (`src/helpers.php` o `utils.py`).
  - Separar componentes de interfaz repetitivos (`layout.php`, `header.php`, `sidebar.php`).
- **Nombres consistentes y descriptivos**:
  - Variables y funciones deben revelar su intención:
    - ❌ Mal: `$d`, `$res2`, `$x`, `$arr`, `$tmp`
    - ✔️ Bien: `$todasBoletas`, `$totalesPorCategoria`, `$filtroOperacion`, `$clienteProveedor`
  - Mantener la misma convención de nombres en todo el proyecto (ej. `camelCase` para variables locales/funciones o `snake_case` según el ecosistema).

---

## 3. No Repetir Código (Principio DRY - Don't Repeat Yourself)
- Si un bloque de código o consulta SQL idéntica se repite en más de dos lugares:
  - Convertirlo en una función o método centralizado.
  - Ejemplo: la fórmula de `peso_neto = max(0, bruto - tara_vehiculo - tara_empaques)` no debe escribirse con fórmulas distintas en pesaje, reportes y dashboard. Debe provenir de la misma definición.

---

## 4. Flujo de Control Limpio (Guard Clauses / Retornos Tempranos)
- Evitar el patrón "Pirámide de la Perdición" con anidamientos excesivos de `if / else`:
  - ❌ **Espagueti Anidado**:
    ```php
    if ($usuario) {
        if ($usuario['activo']) {
            if ($tokenValido) {
                // Código principal a 5 niveles de sangría
            }
        }
    }
    ```
  - ✔️ **Limpio (Guard Clauses)**:
    ```php
    if (!$usuario || !$usuario['activo']) {
        redirigirLogin();
    }
    if (!$tokenValido) {
        mostrarErrorAcceso();
    }
    // Código principal directo y legible en el nivel raíz
    ```

---

## 5. Consistencia Visual y Estilos (CSS / JS)
- No esparcir estilos `<style>` ni scripts `<script>` aleatoriamente por todo el documento.
- Los estilos específicos de impresión deben estar concentrados en un único bloque `@media print` al final o en la hoja de estilos global.
- Los scripts deben estar ubicados en su sección correspondiente, sin duplicar listeners `DOMContentLoaded` ni redeclarar funciones globales.
