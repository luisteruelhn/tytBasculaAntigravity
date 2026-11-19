---
name: github-produccion-flow
description: >-
  Estandar obligatorio de Git y GitHub para mantener siempre dos ramas principales (main para desarrollo/pruebas y produccion para despliegue estable en servidores como Hostinger/VPS).
  Usalo al inicializar un repositorio, configurar despliegues con GitHub o preparar entregas estables a produccion.
---

# GitHub & Git Producción Flow

Esta skill establece el flujo estándar de ramas y protección de credenciales para cualquier repositorio en Git y GitHub, evitando que commits de mantenimiento o pruebas rompan entornos en vivo.

---

## 1. Regla Mandatoria de Dos Ramas (`main` + `produccion`)
Todo repositorio donde se trabaje **debe tener configuradas al menos estas dos ramas**:
1. **`main` (Trabajo diario, mantenimiento y pruebas)**:
   - Rama activa donde se aplican desarrollos, pruebas, correcciones y commits atómicos frecuentes.
   - **Nunca debe disparar despliegues automáticos hacia producción.**
2. **`produccion` (Código probado y estable)**:
   - Rama conectada al servidor de producción (Hostinger Git, webhook o GitHub Actions).
   - Solo recibe código mediante `merge` desde `main` una vez verificado y aprobado en local.

### Creación inicial de la rama `produccion`
Al iniciar un proyecto o detectar que un repositorio solo tiene `main`:
```bash
git branch produccion
# Y cuando el usuario autorice subir al remoto:
git push -u origin produccion
```

---

## 2. Flujo Seguro para Pasar Cambios a Producción
1. Confirmar que el código en `main` pasó todas las validaciones (`php -l`, tests, UI) y no tiene archivos temporales pendientes.
2. Promover cambios a `produccion` solo cuando el trabajo esté listo para salir a producción (o cuando el usuario lo indique explícitamente):
   ```bash
   git checkout produccion
   git merge --ff-only main
   # (Volver de inmediato a main para no hacer commits de prueba directo en produccion)
   git checkout main
   ```
3. **Regla estricta**: Nunca desarrollar ni hacer commits experimentales directamente sobre `produccion`. Siempre volver a `main`.

---

## 3. Protección de Credenciales Antes de Subir a GitHub
Antes de cualquier `push` o enlace con GitHub:
- Verificar en `.gitignore` que **nunca se versionen**:
  - Archivos privados de conexión o entorno (`local.php`, `.env`, `.env.*` salvo `.env.example`).
  - Carpetas generadas o respaldos (`deploy_remoto/`, `dbbackup/`, `*.zip`, `*.sql` de respaldos privados, `*.log`).
- Mantener una plantilla sin secretos (ej. `local.example.php` o `.env.example`) para documentar qué archivo crear una única vez en el servidor destino.
