CREATE DATABASE IF NOT EXISTS `dbBascula`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `dbBascula`;

CREATE TABLE IF NOT EXISTS t_empresas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    razon_social VARCHAR(150) NOT NULL,
    nombre_comercial VARCHAR(150),
    rtn VARCHAR(20),
    direccion VARCHAR(255),
    logo VARCHAR(255),
    activo BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS t_usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    usuario VARCHAR(80) NOT NULL,
    email VARCHAR(254) NOT NULL,
    clave_hash VARCHAR(255) NOT NULL,
    rol ENUM('SUPER', 'ADMINISTRADOR', 'SUPERVISOR', 'OPERADOR', 'REMOTO') NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_usuarios_usuario (usuario),
    UNIQUE KEY uq_usuarios_email (empresa_id, email),
    UNIQUE KEY uq_usuarios_empresa_id (empresa_id, id),
    FOREIGN KEY (empresa_id) REFERENCES t_empresas (id)
) ENGINE=InnoDB;

-- Configuración de la estación; no activa conexiones seriales.
CREATE TABLE IF NOT EXISTS t_configuracion_bascula (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    estacion VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PRINCIPAL',
    modo ENUM('SIMULADO', 'SERIAL') NOT NULL DEFAULT 'SIMULADO',
    puerto_com VARCHAR(6) CHARACTER SET ascii COLLATE ascii_bin,
    baudios INT UNSIGNED,
    bits_datos TINYINT UNSIGNED,
    paridad ENUM('N', 'E', 'O', 'M', 'S'),
    bits_parada DECIMAL(2,1),
    control_flujo ENUM('NINGUNO', 'RTS_CTS', 'XON_XOFF', 'DSR_DTR'),
    protocolo VARCHAR(80),
    unidad_indicador ENUM('lb', 'kg'),
    gemini_api_key VARCHAR(255) DEFAULT NULL,
    timeout_lectura_ms INT UNSIGNED NOT NULL DEFAULT 1000,
    intervalo_lectura_ms INT UNSIGNED NOT NULL DEFAULT 500,
    max_trama_bytes SMALLINT UNSIGNED NOT NULL DEFAULT 512,
    capacidad_max_lb DECIMAL(12,3),
    division_lb DECIMAL(10,3),
    parametros_confirmados BOOLEAN NOT NULL DEFAULT FALSE,
    actualizado_por INT UNSIGNED,
    creada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_configuracion_empresa_estacion (empresa_id, estacion),
    FOREIGN KEY (empresa_id) REFERENCES t_empresas (id),
    FOREIGN KEY (empresa_id, actualizado_por) REFERENCES t_usuarios (empresa_id, id),
    CONSTRAINT chk_configuracion_estacion CHECK (
        estacion REGEXP '^[A-Z0-9][A-Z0-9_-]{0,49}$' AND estacion NOT REGEXP '[^A-Z0-9_-]'
    ),
    CONSTRAINT chk_configuracion_puerto CHECK (
        puerto_com IS NULL OR (puerto_com REGEXP '^COM[1-9][0-9]{0,2}$' AND puerto_com NOT REGEXP '[^A-Z0-9]')
    ),
    CONSTRAINT chk_configuracion_opciones CHECK (
        modo IN ('SIMULADO', 'SERIAL')
        AND (paridad IS NULL OR paridad IN ('N', 'E', 'O', 'M', 'S'))
        AND (control_flujo IS NULL OR control_flujo IN ('NINGUNO', 'RTS_CTS', 'XON_XOFF', 'DSR_DTR'))
        AND (unidad_indicador IS NULL OR unidad_indicador IN ('lb', 'kg'))
    ),
    CONSTRAINT chk_configuracion_baudios CHECK (baudios IS NULL OR baudios BETWEEN 50 AND 4000000),
    CONSTRAINT chk_configuracion_bits CHECK (bits_datos IS NULL OR bits_datos BETWEEN 5 AND 8),
    CONSTRAINT chk_configuracion_parada CHECK (bits_parada IS NULL OR bits_parada IN (1, 1.5, 2)),
    CONSTRAINT chk_configuracion_protocolo CHECK (protocolo IS NULL OR CHAR_LENGTH(TRIM(protocolo)) BETWEEN 1 AND 80),
    CONSTRAINT chk_configuracion_tiempos CHECK (
        timeout_lectura_ms BETWEEN 50 AND 60000 AND intervalo_lectura_ms BETWEEN 50 AND 60000
        AND max_trama_bytes BETWEEN 16 AND 4096
    ),
    CONSTRAINT chk_configuracion_capacidad CHECK (
        (capacidad_max_lb IS NULL OR capacidad_max_lb > 0)
        AND (division_lb IS NULL OR division_lb > 0)
        AND (capacidad_max_lb IS NULL OR division_lb IS NULL OR division_lb <= capacidad_max_lb)
    ),
    CONSTRAINT chk_configuracion_confirmada CHECK (parametros_confirmados IN (0, 1)),
    CONSTRAINT chk_configuracion_serial CHECK (
        (modo = 'SIMULADO' AND parametros_confirmados = 0)
        OR (
            puerto_com IS NOT NULL AND baudios IS NOT NULL AND bits_datos IS NOT NULL
            AND paridad IS NOT NULL AND bits_parada IS NOT NULL AND control_flujo IS NOT NULL
            AND protocolo IS NOT NULL AND unidad_indicador IS NOT NULL
            AND parametros_confirmados = 1
        )
    )
) ENGINE=InnoDB;

INSERT INTO t_configuracion_bascula (empresa_id)
SELECT id FROM t_empresas
ON DUPLICATE KEY UPDATE empresa_id = VALUES(empresa_id);

CREATE TABLE IF NOT EXISTS t_Clie_prov (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    rtn VARCHAR(20),
    telefono VARCHAR(30),
    direccion VARCHAR(255),
    es_cliente BOOLEAN NOT NULL DEFAULT FALSE,
    es_proveedor BOOLEAN NOT NULL DEFAULT FALSE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_clie_prov_empresa_id (empresa_id, id),
    FOREIGN KEY (empresa_id) REFERENCES t_empresas (id),
    CONSTRAINT chk_clie_prov_rol CHECK (
        es_cliente IN (0, 1) AND es_proveedor IN (0, 1)
        AND (es_cliente = TRUE OR es_proveedor = TRUE)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS t_categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(150) DEFAULT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uk_empresa_codigo (empresa_id, codigo),
    KEY idx_empresa (empresa_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS t_presentaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    descripcion VARCHAR(150) DEFAULT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uk_empresa_codigo (empresa_id, codigo),
    KEY idx_empresa (empresa_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS t_productos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    categoria_id INT UNSIGNED DEFAULT NULL,
    nombre VARCHAR(100) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_productos_nombre (empresa_id, nombre),
    KEY idx_categoria (categoria_id),
    FOREIGN KEY (empresa_id) REFERENCES t_empresas (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS t_producto_presentaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_presentaciones_nombre (producto_id, nombre),
    FOREIGN KEY (producto_id) REFERENCES t_productos (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS t_taras (
    idempaque INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Las taras unitarias conservan su precisión; el redondeo se aplica a su total en el cálculo.
    taralbs DECIMAL(10,3) NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    CONSTRAINT chk_taras_peso CHECK (taralbs >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS t_pesadas (
    idpesada BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    tipo_operacion ENUM('COMPRA', 'VENTA') NOT NULL,
    id_compra BIGINT UNSIGNED,
    id_venta BIGINT UNSIGNED,
    estado ENUM('ABIERTA', 'CERRADA', 'ANULADA') NOT NULL DEFAULT 'ABIERTA',
    clie_prov_id INT UNSIGNED NOT NULL,
    presentacion_id INT UNSIGNED NOT NULL,
    -- Las solicitudes nuevas son idempotentes; NULL conserva compatibilidad con registros anteriores.
    solicitud_primera CHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    huella_primera CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    solicitud_segunda CHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    huella_segunda CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    placa VARCHAR(20),
    motorista VARCHAR(150),
    licencia VARCHAR(30),
    origen VARCHAR(150),
    destino VARCHAR(150),
    entrada_camion_at DATETIME,
    salida_camion_at DATETIME,
    fecha_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    abierto_por INT UNSIGNED NOT NULL,
    responsable_id INT UNSIGNED,
    primera_pesada_at DATETIME,
    segunda_pesada_at DATETIME,
    tipo_primera_pesada ENUM('BRUTO', 'TARA'),
    peso_bruto_lb DECIMAL(12,3),
    bruto_capturado_por INT UNSIGNED,
    origen_bruto ENUM('SIMULADO', 'MANUAL', 'INDICADOR'),
    tara_vehiculo_lb DECIMAL(12,3),
    tara_capturada_por INT UNSIGNED,
    origen_tara ENUM('SIMULADO', 'MANUAL', 'INDICADOR'),
    idempaque INT UNSIGNED,
    cantidad_empaques INT UNSIGNED NOT NULL DEFAULT 0,
    tara_unitaria_aplicada_lb DECIMAL(10,3),
    humedad_medida_pct DECIMAL(5,2),
    limite_humedad_pct DECIMAL(5,2),
    ajuste_humedad_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    descuento_calidad_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    peso_final_lb DECIMAL(12,3),
    calculo_libras_enteras BOOLEAN NOT NULL DEFAULT FALSE,
    base_descuentos_lb DECIMAL(12,3),
    descuento_humedad_lb DECIMAL(12,3),
    descuento_defectos_lb DECIMAL(12,3),
    observaciones TEXT,
    observaciones_segunda TEXT,
    guia_remision VARCHAR(30) NULL,
    motivo_traslado VARCHAR(80) NULL,
    motivo_traslado_otro VARCHAR(120) NULL,
    transportista_rtn VARCHAR(20) NULL,
    marca_vehiculo VARCHAR(60) NULL,
    emitida_at DATETIME,
    emitida_por INT UNSIGNED,
    UNIQUE KEY uq_pesadas_compra (empresa_id, id_compra),
    UNIQUE KEY uq_pesadas_venta (empresa_id, id_venta),
    UNIQUE KEY uq_pesadas_solicitud (empresa_id, solicitud_primera),
    UNIQUE KEY uq_pesadas_solicitud_segunda (empresa_id, solicitud_segunda),
    KEY idx_pesadas_pendientes (empresa_id, estado, primera_pesada_at, idpesada),
    KEY idx_pesadas_emision (empresa_id, emitida_at),
    FOREIGN KEY (empresa_id) REFERENCES t_empresas (id),
    FOREIGN KEY (empresa_id, clie_prov_id) REFERENCES t_Clie_prov (empresa_id, id),
    FOREIGN KEY (presentacion_id) REFERENCES t_producto_presentaciones (id),
    FOREIGN KEY (empresa_id, abierto_por) REFERENCES t_usuarios (empresa_id, id),
    CONSTRAINT fk_pesadas_responsable FOREIGN KEY (empresa_id, responsable_id) REFERENCES t_usuarios (empresa_id, id),
    FOREIGN KEY (empresa_id, bruto_capturado_por) REFERENCES t_usuarios (empresa_id, id),
    FOREIGN KEY (empresa_id, tara_capturada_por) REFERENCES t_usuarios (empresa_id, id),
    FOREIGN KEY (empresa_id, emitida_por) REFERENCES t_usuarios (empresa_id, id),
    FOREIGN KEY (idempaque) REFERENCES t_taras (idempaque),
    CONSTRAINT chk_pesadas_origen CHECK (
        (origen_bruto IS NULL OR peso_bruto_lb IS NOT NULL)
        AND (origen_tara IS NULL OR tara_vehiculo_lb IS NOT NULL)
        AND (solicitud_primera IS NULL) = (huella_primera IS NULL)
    ),
    CONSTRAINT chk_pesadas_segunda_solicitud CHECK (
        (solicitud_segunda IS NULL) = (huella_segunda IS NULL)
        AND (solicitud_segunda IS NULL OR segunda_pesada_at IS NOT NULL)
    ),
    CONSTRAINT chk_pesadas_consecutivo CHECK (
        (tipo_operacion = 'COMPRA' AND id_compra IS NOT NULL AND id_compra > 0 AND id_venta IS NULL)
        OR (tipo_operacion = 'VENTA' AND id_venta IS NOT NULL AND id_venta > 0 AND id_compra IS NULL)
    ),
    CONSTRAINT chk_pesadas_capturas CHECK (
        (peso_bruto_lb IS NULL) = (bruto_capturado_por IS NULL)
        AND (tara_vehiculo_lb IS NULL) = (tara_capturada_por IS NULL)
        AND (primera_pesada_at IS NULL) = (tipo_primera_pesada IS NULL)
        AND (primera_pesada_at IS NOT NULL) = (peso_bruto_lb IS NOT NULL OR tara_vehiculo_lb IS NOT NULL)
        AND (segunda_pesada_at IS NOT NULL) = (peso_bruto_lb IS NOT NULL AND tara_vehiculo_lb IS NOT NULL)
        AND (tipo_primera_pesada <> 'BRUTO' OR peso_bruto_lb IS NOT NULL)
        AND (tipo_primera_pesada <> 'TARA' OR tara_vehiculo_lb IS NOT NULL)
        AND (segunda_pesada_at IS NULL OR segunda_pesada_at >= primera_pesada_at)
    ),
    CONSTRAINT chk_pesadas_pesos CHECK (
        peso_bruto_lb >= 0 AND tara_vehiculo_lb >= 0 AND peso_final_lb >= 0
        AND (peso_bruto_lb IS NULL OR tara_vehiculo_lb IS NULL OR peso_bruto_lb >= tara_vehiculo_lb)
        AND (peso_final_lb IS NULL OR peso_final_lb <= peso_bruto_lb - tara_vehiculo_lb
             - CASE WHEN calculo_libras_enteras THEN ROUND(cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0), 0)
                    ELSE cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0) END)
        AND calculo_libras_enteras IN (0, 1)
        AND (calculo_libras_enteras = FALSE OR
             (MOD(peso_bruto_lb, 1) = 0 AND MOD(tara_vehiculo_lb, 1) = 0 AND MOD(peso_final_lb, 1) = 0))
    ),
    CONSTRAINT chk_pesadas_descuentos CHECK (
        (base_descuentos_lb IS NULL AND descuento_humedad_lb IS NULL AND descuento_defectos_lb IS NULL)
        OR (base_descuentos_lb IS NOT NULL AND descuento_humedad_lb IS NOT NULL AND descuento_defectos_lb IS NOT NULL
            AND calculo_libras_enteras=TRUE AND estado IN ('CERRADA','ANULADA') AND segunda_pesada_at IS NOT NULL
            AND base_descuentos_lb>=0 AND descuento_humedad_lb>=0 AND descuento_defectos_lb>=0
            AND MOD(base_descuentos_lb,1)=0 AND MOD(descuento_humedad_lb,1)=0 AND MOD(descuento_defectos_lb,1)=0
            AND ajuste_humedad_pct+descuento_calidad_pct<=100
            AND base_descuentos_lb=peso_bruto_lb-tara_vehiculo_lb-ROUND(cantidad_empaques*COALESCE(tara_unitaria_aplicada_lb,0),0)
            AND descuento_humedad_lb=ROUND(base_descuentos_lb*ajuste_humedad_pct/100,0)
            AND descuento_defectos_lb=ROUND(base_descuentos_lb*descuento_calidad_pct/100,0)
            AND peso_final_lb=base_descuentos_lb-descuento_humedad_lb-descuento_defectos_lb)
    ),
    CONSTRAINT chk_pesadas_empaque CHECK (
        (idempaque IS NULL AND cantidad_empaques = 0 AND tara_unitaria_aplicada_lb IS NULL)
        OR (idempaque IS NOT NULL AND cantidad_empaques > 0
            AND tara_unitaria_aplicada_lb IS NOT NULL AND tara_unitaria_aplicada_lb >= 0)
    ),
    CONSTRAINT chk_pesadas_porcentajes CHECK (
        (humedad_medida_pct IS NULL OR humedad_medida_pct BETWEEN 0 AND 100)
        AND (limite_humedad_pct IS NULL OR limite_humedad_pct BETWEEN 0 AND 100)
        AND ajuste_humedad_pct BETWEEN 0 AND 100
        AND descuento_calidad_pct BETWEEN 0 AND 100
    ),
    CONSTRAINT chk_pesadas_emision CHECK (
        (estado = 'ABIERTA' AND emitida_at IS NULL AND emitida_por IS NULL AND peso_final_lb IS NULL)
        OR (estado IN ('CERRADA', 'ANULADA') AND segunda_pesada_at IS NOT NULL
            AND emitida_at IS NOT NULL AND emitida_at >= segunda_pesada_at
            AND emitida_por IS NOT NULL AND peso_final_lb IS NOT NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS t_pesada_anulaciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    idpesada BIGINT UNSIGNED NOT NULL,
    anulado_por INT UNSIGNED NOT NULL,
    anulado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    motivo VARCHAR(500) NOT NULL,
    idpesada_reemplazo BIGINT UNSIGNED,
    UNIQUE KEY uq_anulaciones_pesada (idpesada),
    KEY idx_anulaciones_fecha (anulado_at),
    FOREIGN KEY (idpesada) REFERENCES t_pesadas (idpesada),
    FOREIGN KEY (anulado_por) REFERENCES t_usuarios (id),
    FOREIGN KEY (idpesada_reemplazo) REFERENCES t_pesadas (idpesada),
    CONSTRAINT chk_anulaciones_motivo CHECK (CHAR_LENGTH(TRIM(motivo)) > 0),
    CONSTRAINT chk_anulaciones_reemplazo CHECK (idpesada_reemplazo <> idpesada)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS t_sincronizacion_pendiente (
    id_evento CHAR(36) PRIMARY KEY,
    entidad VARCHAR(64) NOT NULL,
    entidad_id VARCHAR(64) NOT NULL,
    accion VARCHAR(20) NOT NULL,
    datos JSON NOT NULL,
    creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    intentos INT UNSIGNED NOT NULL DEFAULT 0,
    enviado_at DATETIME,
    KEY idx_sincronizacion_pendientes (enviado_at, creado_at)
) ENGINE=InnoDB;

INSERT INTO t_taras (idempaque, taralbs, nombre) VALUES
    (1, 0.25, 'Saco de 0.25 lb'),
    (2, 0.50, 'Saco de 0.50 lb'),
    (3, 1.00, 'Saco de 1.00 lb'),
    (4, 1.50, 'Saco de 1.50 lb'),
    (5, 2.00, 'Saco de 2.00 lb')
ON DUPLICATE KEY UPDATE idempaque = t_taras.idempaque;
