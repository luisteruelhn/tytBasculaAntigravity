---
name: print-and-reporting
description: >-
  Estandar de diseño y depuración para reportes imprimibles en navegador, descarga de PDF limpios y exportacion a Excel en proyectos web (PHP, HTML, JS).
  Úsalo al crear o corregir vistas de reportes, facturas, tickets, tablas de totales y descargas en PDF/Excel. Exige incluir por defecto la triada de exportacion (Imprimir, PDF, Excel).
---

# Print & Reporting Standard

Guía y estándares técnicos universales para reportes en pantalla, impresión física (`window.print`), descarga directa en PDF (`html2pdf` o print-to-pdf) y exportación a Excel.

---

## 1. Regla Mandatoria: Tríada de Exportación por Defecto
**Cada vez que se cree o modifique un módulo o formulario de reportes en cualquier proyecto:**
- **Deben incluirse de forma predeterminada y sin esperar a que el usuario lo solicite los siguientes 3 botones de acción:**
  1. 🖨️ **Imprimir**: Ejecuta `imprimirReporte()` (`window.print()`) enviando el documento limpio a la impresora o vista previa del navegador.
  2. 📄 **Descargar PDF**: Descarga directa de archivo `.pdf` en formato Carta (*Landscape* o *Portrait* según convenga) con corte limpio de páginas.
  3. 📊 **Generar Excel**: Exportación nativa y ligera (`.xlsx` o `.xls` XML estructurado con estilos y encabezados) conservando los mismos filtros activos de la consulta.
- **Excepción / Confirmación**: Si en un formulario muy específico se duda si se requiere alguno de estos formatos, **se debe consultar explícitamente al usuario antes de omitirlo** (ej. *"¿Deseas incluir la exportación a Excel y descarga PDF en esta vista o solo consulta en pantalla?"*). Nunca omitirlos en silencio.

---

## 2. Separación de Capas (Pantalla vs. Impresión)
Un reporte web siempre debe tener dos estados claramente definidos:
1. **Estado Interactivo en Pantalla**:
   - Muestra formularios, selectores de fecha, filtros dependientes, migas de pan y botones de acción.
2. **Estado Documento Imprimible / PDF**:
   - Todo elemento de navegación, filtros, botones y widgets flotantes (como chats o menús) deben llevar la clase `.no-print`.
   - `@media print`:
     ```css
     @media print {
         .no-print, nav, sidebar, form, .chat-widget, button, [class*="chat-"] {
             display: none !important;
         }
         body {
             background: #ffffff !important;
             color: #000000 !important;
         }
         @page {
             size: letter landscape; /* o portrait según el caso */
             margin: 0.8cm;
         }
     }
     ```

---

## 3. Descarga Directa en PDF con html2pdf
Para descargas en el cliente sin requerir librerías pesadas en backend:
- Cargar la librería estáticamente en el `<head>` o al final del `<body>`.
- Opciones recomendadas para evitar cortes y desbordes:
  ```javascript
  const opt = {
      margin:       [6, 6, 6, 6],
      filename:     'Reporte_' + new Date().toISOString().slice(0, 10) + '.pdf',
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { scale: 2, useCORS: true, logging: false },
      jsPDF:        { unit: 'mm', format: 'letter', orientation: 'landscape' },
      pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
  };
  ```
- **Fallback obligatorio**: Si la librería falla o el usuario no tiene conexión para el CDN, invocar automáticamente `window.print()` para que el usuario pueda usar la opción nativa "Guardar como PDF".

---

## 4. Jerarquías y Subtotales
- Nivel 1: Tipo de operación (ej. Compras / Ventas).
- Nivel 2: Categorías / Agrupadores.
- Nivel 3: Detalle por registro / producto con sus boletas.
- Cada nivel debe calcular explícitamente:
  - Conteo de registros/pesadas.
  - Subtotales intermedios.
  - Balance o consolidado general al final.
