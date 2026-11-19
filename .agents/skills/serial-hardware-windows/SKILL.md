---
name: serial-hardware-windows
description: >-
  Estandar para lectura serial RS232 de indicadores de bascula en Windows
  (LP7510, C18) sin modificar configuraciones del sistema operativo.
---

# Hardware Serial RS232 en Windows (`COM` / `DCB`)

## 1. Reglas Intocables de Entorno
- **Prohibido modificar Windows:** No alterar Registro, Administrador de Dispositivos, `mode COMx`, servicios ni puertos del sistema anterior.
- **Fuente predeterminada:** Mantener siempre **Indicador Real** (`modo = 'SERIAL'`) en `t_configuracion_bascula` y conservar `puerto_com`, `baudios` y `protocolo` de producción.

## 2. Manejo Seguro de `DCB` en Sesión (`kernel32.dll`)
- Al abrir el puerto (`CreateFileW` sobre `\\.\COMx`), guardar el `DCB` original con `GetCommState`.
- Aplicar `BaudRate`, `ByteSize=8`, `Parity=0`, `StopBits=0` con `SetCommState` **únicamente en memoria para esa sesión**.
- En el bloque `finally`, restaurar siempre el `DCB` original antes de `CloseHandle`.

## 3. Exclusividad del Puerto y Pruebas en Vivo
- En Windows un puerto `COM` es de acceso exclusivo:
  - Antes de ejecutar una prueba en vivo (`serial_prueba.php`), llamar a `POST /api/v1/lector/detener` en FastAPI (`:8018`).
  - Al finalizar o cerrar la prueba, llamar siempre a `POST /api/v1/lector/iniciar` en `finally`.
- Al parsear tramas (`LP7510` / `C18`), dividir por `[\r\n]+` y tolerar `lb`, `lbs` y `kg`.
