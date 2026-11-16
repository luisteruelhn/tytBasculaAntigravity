-- Aplicación exclusiva mediante actualizar_xampp.py, con respaldo y prueba temporal.
-- Sin USE, DROP TABLE, TRUNCATE ni cambios a pesos/usuarios existentes.
CREATE TABLE t_configuracion_bascula (
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

ALTER TABLE t_pesadas
    ADD COLUMN solicitud_primera CHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    ADD COLUMN huella_primera CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    ADD COLUMN solicitud_segunda CHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    ADD COLUMN huella_segunda CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    ADD COLUMN responsable_id INT UNSIGNED,
    ADD COLUMN origen_bruto ENUM('SIMULADO', 'MANUAL', 'INDICADOR'),
    ADD COLUMN origen_tara ENUM('SIMULADO', 'MANUAL', 'INDICADOR'),
    ADD COLUMN calculo_libras_enteras BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN base_descuentos_lb DECIMAL(12,3),
    ADD COLUMN descuento_humedad_lb DECIMAL(12,3),
    ADD COLUMN descuento_defectos_lb DECIMAL(12,3),
    ADD COLUMN observaciones_segunda TEXT,
    ADD UNIQUE KEY uq_pesadas_solicitud (empresa_id, solicitud_primera),
    ADD UNIQUE KEY uq_pesadas_solicitud_segunda (empresa_id, solicitud_segunda),
    ADD CONSTRAINT fk_pesadas_responsable FOREIGN KEY (empresa_id, responsable_id) REFERENCES t_usuarios (empresa_id, id),
    ADD CONSTRAINT chk_pesadas_origen CHECK (
        (origen_bruto IS NULL OR peso_bruto_lb IS NOT NULL)
        AND (origen_tara IS NULL OR tara_vehiculo_lb IS NOT NULL)
        AND (solicitud_primera IS NULL) = (huella_primera IS NULL)
    ),
    ADD CONSTRAINT chk_pesadas_segunda_solicitud CHECK (
        (solicitud_segunda IS NULL) = (huella_segunda IS NULL)
        AND (solicitud_segunda IS NULL OR segunda_pesada_at IS NOT NULL)
    ),
    ADD CONSTRAINT chk_pesadas_descuentos CHECK (
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
    ADD CONSTRAINT chk_pesadas_entero_flag CHECK (calculo_libras_enteras IN (0,1)),
    DROP CONSTRAINT chk_pesadas_pesos,
    ADD CONSTRAINT chk_pesadas_pesos CHECK (
        peso_bruto_lb >= 0 AND tara_vehiculo_lb >= 0 AND peso_final_lb >= 0
        AND (peso_bruto_lb IS NULL OR tara_vehiculo_lb IS NULL OR peso_bruto_lb >= tara_vehiculo_lb)
        AND (peso_final_lb IS NULL OR peso_final_lb <= peso_bruto_lb - tara_vehiculo_lb
             - CASE WHEN calculo_libras_enteras THEN ROUND(cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0), 0)
                    ELSE cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0) END)
        AND calculo_libras_enteras IN (0, 1)
        AND (calculo_libras_enteras = FALSE OR
             (MOD(peso_bruto_lb, 1) = 0 AND MOD(tara_vehiculo_lb, 1) = 0 AND MOD(peso_final_lb, 1) = 0))
    );

INSERT INTO t_configuracion_bascula (empresa_id) SELECT id FROM t_empresas;
