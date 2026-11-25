# SEED — Esquema de Base de Datos y Datos Iniciales — Gemini

Guía de referencia para la base de datos `dbBascula` en MariaDB (XAMPP).

---

## 1. Conexión a Base de Datos
* **Motor**: MariaDB 10.4+ / MySQL 8.0+
* **Host**: `127.0.0.1`
* **Puerto**: `3306`
* **Base de datos**: `dbBascula`
* **Charset**: `utf8mb4` / `utf8mb4_unicode_ci`
* **Ubicación de datos en XAMPP**: `C:\xampp\mysql\data\dbbascula\`

---

## 2. Inventario de Tablas Operativas

| Tabla | Propósito | Llave Primaria / Claves |
|---|---|---|
| `t_empresas` | Datos de la empresa emisora (Razón social, RTN, logo) | `id` (AUTO_INCREMENT) |
| `t_usuarios` | Usuarios del sistema y credenciales hash | `id`, `empresa_id`, `rol` |
| `t_configuracion_bascula` | Ajustes de puerto COM, baudios, paridad, modo (SERIAL/SIMULADO) | `id`, `empresa_id`, `estacion` |
| `t_pesadas` | Transacciones de pesaje (primera y segunda pesada, bruto, tara, neto) | `id`, `empresa_id`, `numero_boleta` |
| `t_clie_prov` | Catálogo de Clientes y Proveedores (`codigo`, `nombre`, `rtn`, `telefono`, `email`, `direccion`, `ciudad`, `observaciones`) | `id`, `empresa_id` |
| `t_categorias` | Catálogo de categorías de producto (`CAFE`, `ACEITES`, `GRANOS BASICOS`, `COQUILLO`, `SALVADO`) | `id`, `empresa_id`, `codigo` |
| `t_productos` | Catálogo maestro de productos (`categoria_id`, `codigo`, `nombre`, `observaciones`) | `id`, `empresa_id`, `categoria_id` |
| `t_producto_presentaciones` | Presentaciones/empaques vinculadas por producto | `id`, `producto_id` |
| `t_presentaciones` | Catálogo maestro de presentaciones/taras de empaque (SACOS, A GRANEL) | `id`, `empresa_id`, `codigo` |
| `t_taras` | Taras predeterminadas por placa de vehículo | `id`, `empresa_id`, `placa` |
| `t_pesada_anulaciones` | Registro de auditoría de boletas anuladas y motivo | `id`, `pesada_id` |
| `t_sincronizacion_pendiente` | Cola offline-first de eventos pendientes de envío hacia Hostinger | `id_evento` (CHAR 36), `entidad`, `enviado_at` |

---

## 3. Roles de Usuario y Permisos (RBAC)

1. **SUPER**:
   - Acceso total a configuración multempresa y administración global.
2. **ADMINISTRADOR**:
   - Gestión de usuarios de su empresa, clientes/proveedores, productos, taras y configuración serial de la báscula.
3. **SUPERVISOR**:
   - Autorización de anulaciones de pesadas, reimpresión y consulta de reportes consolidados.
4. **OPERADOR**:
   - Operación diaria: captura de Primera Pesada (entrada) y Segunda Pesada (salida), emisión de boletas y captura manual cuando corresponda.
5. **REMOTO**:
   - Acceso exclusivo de **solo lectura / consulta** (Dashboard, Boletas e Historial, Guías de Remisión, Reportes, Catálogos en lectura y Asistente TYTOne), sin permisos de pesaje ni edición.

---

## 4. Parámetros Seriales del Indicador (Modo Real)

El indicador de pesaje industrial (LP7510 / C18) se configura en `t_configuracion_bascula` con los siguientes valores típicos:
* **Protocolo**: `LP7510_PC` o `C18 = 4` (trama continua de peso).
* **Unidad**: `lb` (Libras).
* **Formato de trama continua**:
  `ST,GS,+  15420 lb\r\n` (ST = Estable, US = Inestable, GS = Bruto).
* **Parámetros seriales típicos**:
  - Baudios: `9600` (o `4800` / `2400` según el indicador físico).
  - Bits de datos: `8`
  - Paridad: `N` (Ninguna)
  - Bits de parada: `1`
  - Control de flujo: `NINGUNO`

---

## 5. Procedimiento de Respaldo y Restauración en XAMPP

### Generar respaldo SQL rápido:
```cmd
C:\xampp\mysql\bin\mysqldump.exe -u root dbBascula > K:\TYTOneBasculaAntigravity\dbbackup\dbBascula_respaldo_%date:~-4%%date:~3,2%%date:~0,2%.sql
```

### Restaurar base de datos:
```cmd
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS dbBascula CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:\xampp\mysql\bin\mysql.exe -u root dbBascula < K:\TYTOneBasculaAntigravity\scripts\dbBascula.sql
```
