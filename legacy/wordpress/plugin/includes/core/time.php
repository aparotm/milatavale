<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Time {
    /**
     * Formatea un DATETIME MySQL (Y-m-d H:i:s) para mostrarlo en la zona horaria del sitio.
     * Asume que el valor viene en UTC (común en servidores); lo convierte a wp_timezone().
     * Si el parseo falla, retorna el string original.
     */
    public static function format_mysql_datetime(string $mysql_datetime, string $format = 'Y-m-d H:i'): string {
        $mysql_datetime = trim($mysql_datetime);
        if ($mysql_datetime === '') return '—';

        try {
            $utc = new DateTimeZone('UTC');
            $site_tz = wp_timezone();

            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $mysql_datetime, $utc);
            if (!$dt) {
                // intentar parseo flexible en UTC
                $dt = new DateTime($mysql_datetime, $utc);
            }
            $dt->setTimezone($site_tz);

            return wp_date($format, $dt->getTimestamp(), $site_tz);
        } catch (Throwable $e) {
            return $mysql_datetime;
        }
    }

    public static function format_user_registered(string $mysql_datetime, string $format = 'Y-m-d'): string {
        // user_registered normalmente viene en UTC en WP; aplicamos mismo criterio
        return self::format_mysql_datetime($mysql_datetime, $format);
    }
}
