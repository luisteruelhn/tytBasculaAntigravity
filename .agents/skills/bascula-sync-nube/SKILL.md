---
name: bascula-sync-nube
description: >-
  Estandar para sincronizacion offline-first desde la PC local de bascula hacia
  la base de datos en la nube (Hostinger) para consulta remota de solo lectura.
---

# Sincronización Local → Nube (Hostinger)

## 1. Principio Rector: Local Primero
- **La operación de pesaje en planta NUNCA depende de Internet.**
- Toda pesada, boleta, guía de remisión y catálogo se guarda primero en `dbbascula` local (`127.0.0.1:3306`).
- Si la nube no responde o no hay conexión, la pesada se registra normalmente y queda en cola (`t_sincronizacion_pendiente`).

## 2. Envío Idempotente (Cero Duplicados)
- Usar clave natural única (`empresa_id` + `id_compra` / `id_venta` o `uuid_local`) mediante `INSERT ... ON DUPLICATE KEY UPDATE` o `UPSERT` controlado.
- Enviar en lotes pequeños con timeout corto (`3-5 s`) para no congelar la interfaz del operador.
- Registrar en `t_sincronizacion_pendiente` el estado (`PENDIENTE`, `ENVIADO`, `ERROR`), número de intentos y último mensaje de error.

## 3. Regla de Solo Consulta en Hostinger
- En el servidor remoto (`Hostinger`), la web opera exclusivamente para **consulta y reportes** (rol `REMOTO` o detección de host no-local).
- Bloquear en UI y en backend (`POST`) cualquier creación/edición de pesadas, usuarios o configuración serial cuando se acceda desde el entorno remoto.
- Nunca subir `web/config/local.php` local ni sobrescribir credenciales privadas de Hostinger.
