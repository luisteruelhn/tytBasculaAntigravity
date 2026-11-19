---
name: web-deploy-packager
description: >-
  Metodologia para empaquetar, verificar y sincronizar aplicaciones web hacia hosting compartido o servidores en la nube (InfinityFree, cPanel, Hostinger, VPS).
  Usa esta skill al preparar despliegues en ZIP, sincronizar bases de datos o resolver diferencias entre entorno local y remoto.
---

# Web Deploy Packager

Estándar para empaquetado, verificación y despliegue rápido de proyectos web hacia hosting compartido (cPanel, InfinityFree, Hostinger, etc.).

## 1. Verificación Pre-Despliegue
Antes de empaquetar o subir archivos a producción:
1. **Validación de sintaxis**:
   - Ejecutar `php -l` en los archivos PHP modificados para asegurar 0 errores de sintaxis.
2. **Revisión de rutas relativas y permisos**:
   - Evitar rutas absolutas locales como `C:/xampp/...` o `K:/...`.
   - Usar siempre `__DIR__` o rutas relativas compatibles con entornos Linux/Unix en hosting.

## 2. Separación de Entornos (Local vs. Producción)
- **Base de datos y credenciales**:
  - Aislar la conexión en un archivo de configuración claro (`db.php` o `.env`).
  - En local: `localhost / 127.0.0.1` con usuario `root` o local.
  - En remoto: Servidor MySQL del proveedor (ej. `sql301.infinityfree.com`) con base de datos asignada.

## 3. Generación del Paquete ZIP
- Estructurar la carpeta de despliegue reflejando la raíz pública del servidor (`public_html` o `htdocs`).
- Crear el archivo `.zip` limpio excluyendo carpetas innecesarias (`.git`, archivos temporales, logs de prueba).
- Mantener sincronizado el archivo modificado tanto en local como en la carpeta de distribución remota (`deploy_remoto/`).
