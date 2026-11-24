-- =============================================================================
-- Actualización Idempotente de Constraints de Redondeo Hacia Arriba (CEILING)
-- Proyecto: TYTOne Báscula Antigravity
--
-- Alinea chk_pesadas_pesos y chk_pesadas_descuentos en MariaDB/MySQL con el
-- redondeo hacia arriba (CEILING) usado en tara de sacos, %H y %D.
-- =============================================================================

ALTER TABLE t_pesadas DROP CONSTRAINT IF EXISTS chk_pesadas_pesos;
ALTER TABLE t_pesadas DROP CONSTRAINT IF EXISTS chk_pesadas_descuentos;

ALTER TABLE t_pesadas
    ADD CONSTRAINT chk_pesadas_pesos CHECK (
        peso_bruto_lb >= 0
        AND tara_vehiculo_lb >= 0
        AND peso_final_lb >= 0
        AND (peso_bruto_lb IS NULL OR tara_vehiculo_lb IS NULL OR peso_bruto_lb >= tara_vehiculo_lb)
        AND (
            peso_final_lb IS NULL
            OR peso_final_lb <= peso_bruto_lb - tara_vehiculo_lb - CASE
                WHEN calculo_libras_enteras THEN CEILING(ROUND(cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0), 4))
                ELSE cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0)
            END
        )
        AND calculo_libras_enteras IN (0, 1)
        AND (calculo_libras_enteras = 0 OR (peso_bruto_lb MOD 1 = 0 AND tara_vehiculo_lb MOD 1 = 0 AND peso_final_lb MOD 1 = 0))
    ),
    ADD CONSTRAINT chk_pesadas_descuentos CHECK (
        (base_descuentos_lb IS NULL AND descuento_humedad_lb IS NULL AND descuento_defectos_lb IS NULL)
        OR (
            base_descuentos_lb IS NOT NULL
            AND descuento_humedad_lb IS NOT NULL
            AND descuento_defectos_lb IS NOT NULL
            AND calculo_libras_enteras = 1
            AND estado IN ('CERRADA', 'ANULADA')
            AND segunda_pesada_at IS NOT NULL
            AND base_descuentos_lb >= 0
            AND descuento_humedad_lb >= 0
            AND descuento_defectos_lb >= 0
            AND base_descuentos_lb MOD 1 = 0
            AND descuento_humedad_lb MOD 1 = 0
            AND descuento_defectos_lb MOD 1 = 0
            AND ajuste_humedad_pct + descuento_calidad_pct <= 100
            AND base_descuentos_lb = peso_bruto_lb - tara_vehiculo_lb - CEILING(ROUND(cantidad_empaques * COALESCE(tara_unitaria_aplicada_lb, 0), 4))
            AND descuento_humedad_lb = CEILING(ROUND(base_descuentos_lb * ajuste_humedad_pct / 100, 4))
            AND descuento_defectos_lb = CEILING(ROUND(base_descuentos_lb * descuento_calidad_pct / 100, 4))
            AND peso_final_lb = base_descuentos_lb - descuento_humedad_lb - descuento_defectos_lb
        )
    );
