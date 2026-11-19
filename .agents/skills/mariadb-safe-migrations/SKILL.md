---
name: mariadb-safe-migrations
description: >-
  Estandar obligatorio para respaldos previos y migraciones SQL idempotentes
  en MariaDB de produccion sin perdida de datos ni alteracion de configuracion fisica.
---

# Migraciones Seguras e Idempotentes en MariaDB

## 1. Respaldo Obligatorio Antes de Cualquier Cambio
- Antes de ejecutar cualquier `ALTER`, `UPDATE` o script de esquema en producción, generar respaldo completo en `dbbackup/`:
  ```powershell
  C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -P 3306 -u root dbbascula > dbbackup\respaldo_pre_sync_AAAAMMDD.sql
  ```

## 2. Scripts Idempotentes (Re-ejecutables)
- Todo script de actualización en `scripts/` debe poder ejecutarse múltiples veces sin fallar ni duplicar datos:
  - Tablas: `CREATE TABLE IF NOT EXISTS ...`
  - Columnas: `ALTER TABLE ... ADD COLUMN IF NOT EXISTS ...`
  - Catálogos base: `INSERT IGNORE INTO ...` o `WHERE NOT EXISTS (...)`.
- Mantener sincronizados `scripts/dbBascula.sql` (instalación limpia) y `scripts/actualizar_bd_pc_bascula.sql` (actualización sobre BD existente).

## 3. Datos Intocables en Producción
- Nunca sobrescribir ni truncar `t_pesadas`, `t_empresas`, `t_usuarios` existentes ni los parámetros físicos de `t_configuracion_bascula` (`modo`, `puerto_com`, `baudios`, `protocolo`).
- Validar siempre después con `SHOW TABLES;` y `SELECT` de verificación sobre `t_configuracion_bascula`.
