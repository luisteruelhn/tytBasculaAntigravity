<?php
/**
 * Portada institucional pública para Hostinger (inversionesvallecillo.com)
 * No contiene enlaces ni referencias al sistema privado de báscula.
 */
declare(strict_types=1);

$landing = __DIR__ . '/web/config/landing_hostinger.html';
if (is_file($landing)) {
    require $landing;
    exit;
}
echo 'Inversiones Vallecillo, SB';
