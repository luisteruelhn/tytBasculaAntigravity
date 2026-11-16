
## 2026-11-12 — Lector serial sin cambios de configuración

Por autorización del usuario se corrigieron exclusivamente archivos de TYTOneBasculaLocal. El lector consulta GetCommState y conserva parámetros/tiempos existentes: se eliminaron SetCommState y SetCommTimeouts, también al cerrar. No envía bytes ni purga buffers. Consulta bytes recibidos y lee únicamente los disponibles, emitiendo avisos cada medio segundo cuando no hay datos. La pantalla muestra parámetros realmente consultados. Se retiró el mensaje que suponía un puente de eco como causa de ausencia de datos.

El transporte PHP ya no lee un pipe stderr vacío que podía bloquear el envío de avisos en Windows; stderr se dirige a NUL y se conservan los eventos de error controlados del lector. El cierre de proceso/árbol existente se conserva.

Verificación sin hardware: cuatro pruebas con kernel32 ficticio correctas (30 segundos simulados sin datos y con avisos, recepción, fallo de consulta, puerto ocupado). El objeto simulado no ofrece funciones de modificación. PHP con proceso lector falso entregó cuatro eventos en flujo antes del cierre. Lint PHP, sintaxis Python y JavaScript correctos. No se abrió ningún COM, no se cambiaron Windows/XAMPP/configuración serial ni datos. Sin reinicios de servicios.

Respaldo de tres archivos anteriores y ensayo ficticio en .runtime/local/actualizaciones/20261112-lector-sin-configurar. Pruebas repetibles: .venv/Scripts/python.exe scripts/test_lector_sin_configurar.py -v.

Pendiente: recargar la página con Ctrl+F5 y acordar la prueba real con el puerto libre del sistema anterior. La falta de eventos mantiene el aviso de estado no confirmado; no se ocultan fallos de comunicación. Esta modificación local deberá conservarse al actualizar desde desarrollo. Abrir un puerto sigue siendo acceso exclusivo durante la prueba; no se garantiza ausencia de efectos propios del controlador al abrir/cerrar.

## 2026-11-12 — IP del lanzador local

Actualizado únicamente Iniciar Bascula.cmd en esta instalación para --red-local 192.168.2.20/24 --puerto-web 8088. IP comprobada en el equipo, sin modificar Windows. Puerto 80 ocupado por Apache de XAMPP, conservado. Respaldo del lanzador en .runtime/local/actualizaciones/20261112-ip-192-168-2-20. App relanzada para http://192.168.2.20:8088/login.php. Sin cambios en el proyecto de desarrollo, configuración COM ni base de datos. Pendiente: acceso desde otro dispositivo de la red; no se modificó firewall/router.

## 2026-11-12 — Comparación de COM y actualización exclusiva de la base

Por solicitud del usuario, Confirmar se sustituyó por Comparar y actualizar la base. Al pulsar, se consulta GetCommState con apertura exclusiva breve, sin leer datos ni aplicar parámetros; se cierra antes de guardar. Se comparan baudios, bits, paridad, parada y flujo; solo esos cinco datos de la app se actualizan junto con confirmación y auditoría, manteniendo el modo actual. La respuesta muestra valores anteriores y nuevos o coincidencia. No se publica/activa la fuente de lectura al confirmar. No se deducen protocolo, unidades ni capacidades a partir del COM. COM4 se permite como dato consultado sin retirar la protección del lector operativo.

Puerto ocupado, consulta incompleta, cierre fallido o parámetros no representables bloquean la actualización, sin suponer valores. Se mantienen rol, CSRF, revisión, transacción y empresa. El checkbox autoriza consulta y actualización solo de la base. La comparación puede repetirse incluso si ya estaba confirmada. El archivo de consulta faltante se creó dentro de scripts.

Verificación: seis pruebas Python simuladas correctas, comparación PHP sintética con coincidencia/diferencias y rechazos correcta, sintaxis PHP/Python correcta. No se abrió hardware ni se actualizó la base real durante el desarrollo. Sin cambios fuera de TYTOneBasculaLocal, sin reinicios. Respaldo del formulario original en .runtime/local/actualizaciones/20261112-confirmacion-solo-base.

Pendiente: recargar, finalizar cualquier escucha y pulsar Comparar y actualizar la base con el puerto libre. La apertura/cierre tiene los efectos propios del controlador; el código no llama a setters ni cambia señales. El lector operativo conserva sus restricciones anteriores; esta acción no activa lectura real.

### Corrección de consulta aislada — 2026-11-12

Se reprodujo ModuleNotFoundError al ejecutar leer_configuracion_com.py con -I, igual que PHP. La dependencia DCB ahora se carga mediante importlib desde la ruta fija del lector hermano. Añadida prueba de regresión del CLI aislado con puerto INVALIDO (rechazado antes de acceder a kernel32). Siete pruebas correctas; sin abrir COM ni modificar la base. Pendiente: repetir Comparar y actualizar la base desde la pantalla con el puerto libre. Cambios exclusivamente locales.

## 2026-11-12 — Selección explícita de lectura real

Añadido Usar lectura real, separado de Comparar y actualizar la base. Requiere rol administrador, CSRF, revisión vigente, estación PRINCIPAL, parámetros confirmados, protocolo LP7510_PC o alias C18=4, libras y declaración de puerto libre. Cambia solo el modo de la app a SERIAL y conserva parámetros. La comparación sigue sin activar lectura. Se admite COM4 en lector operativo por solicitud expresa de usar lectura real, siempre con puerto libre; el lector compartido conserva la configuración existente sin setters ni transmisiones. Sin cambios de configuración COM, Windows o XAMPP.

Verificación sin hardware: siete pruebas Python del lector/consulta, validaciones PHP de activación/comparación y ensayo de FuenteSerial con proceso ficticio para COM4 y ambos nombres del protocolo (20 lb), correctos. Sintaxis PHP/Python correcta. No se activó SERIAL en la base ni se abrió COM real durante la verificación. App reiniciada para cargar el cambio de API; selección pendiente del usuario desde el nuevo botón. Respaldo de tres archivos en .runtime/local/actualizaciones/20261112-boton-lectura-real. Exclusivamente TYTOneBasculaLocal.

Uso: finalizar prueba en vivo, comparar/confirmar si hace falta, verificar que el programa anterior liberó el puerto, marcar la casilla y pulsar Usar lectura real. Volver a Inicio para consultar. Pesadas requieren guardado explícito. Pendiente: comprobación del usuario en Inicio/Primera/Segunda y actualización futura del proyecto de desarrollo por separado.

## 2026-11-12 — Lectura operativa: normalizar bits de parada provenientes de SQL

Se diagnosticó que la prueba en vivo recibía 1 del formulario y funcionaba, pero el lector operativo recibía 1.0 (DECIMAL SQL) y rechazaba parámetros antes de abrir el puerto. Confirmado en configuración publicada y traceback del servicio. FuenteSerial ahora normaliza 1.0→1 y 2.0→2 exclusivamente en memoria, sin modificar base ni COM.

Se incorporó una prueba de regresión que ejecuta FuenteSerial junto al lector real con kernel32 ficticio para ambos valores decimales: recibe 40 lb y cierra correctamente. Ocho pruebas Python y validaciones PHP correctas. La interfaz ahora transmite el motivo controlado de error desde la API usando textContent, en lugar de ocultarlo detrás de Reconectando. Sintaxis PHP/JS correcta.

Reinicio de la app únicamente para cargar el cambio; Windows, XAMPP y configuración COM intactos. Respaldo de archivos en .runtime/local/actualizaciones/20261112-diagnostico-peso-vivo. No se iniciaron pruebas independientes sobre COM; las solicitudes de la pantalla del usuario mantienen el modo real autorizado. Pendiente: usuario verifica peso en Inicio/Primera/Segunda sin prueba diagnóstica simultánea.
