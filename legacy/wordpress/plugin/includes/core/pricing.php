<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Pricing {

    // NO cambiamos la key para no perder el valor guardado
    const OPTION_PRICE_PER_LATA = 'mlv2_price_per_lata';

    // Default razonable SOLO si la opción no existe
    const DEFAULT_PRICE = 10;

    public static function get_price_per_lata(): int {
        $raw = get_option(self::OPTION_PRICE_PER_LATA, null);

        // Si la opción no existe todavía, la creamos una vez
        if ($raw === null) {
            update_option(self::OPTION_PRICE_PER_LATA, self::DEFAULT_PRICE);
            return self::DEFAULT_PRICE;
        }

        return max(0, (int) $raw);
    }

    public static function set_price_per_lata(int $value): void {
        update_option(self::OPTION_PRICE_PER_LATA, max(0, (int)$value));
    }

    // Monto equivalente (informativo / tablas)
    public static function calcular_monto_por_latas(int $latas): int {
        $latas = max(0, (int)$latas);
        return $latas * self::get_price_per_lata();
    }
}
