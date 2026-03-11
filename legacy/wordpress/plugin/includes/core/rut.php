<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Utilidades para RUT chileno.
 *
 * - Acepta entradas con o sin puntos/guión.
 * - Valida largo mínimo y presencia de DV.
 * - Estandariza a formato: 12.345.678-9
 */
final class MLV2_RUT {
    /**
     * Calcula DV para un RUT numérico (sin DV).
     */
    public static function compute_dv(string $num): string {
        $num = preg_replace('/[^0-9]/', '', (string)$num);
        if ($num === '') return '';
        $sum = 0;
        $mult = 2;
        for ($i = strlen($num) - 1; $i >= 0; $i--) {
            $sum += ((int)$num[$i]) * $mult;
            $mult++;
            if ($mult > 7) $mult = 2;
        }
        $mod = 11 - ($sum % 11);
        if ($mod === 11) return '0';
        if ($mod === 10) return 'K';
        return (string)$mod;
    }

    public static function is_valid(string $rut_in): bool {
        $p = self::parse($rut_in);
        return !empty($p['ok']);
    }

    /**
     * Parseo tolerante.
     *
     * @return array{ok:bool, norm:string, formatted:string, error:string, warnings:array<int,string>}
     */
    public static function parse(string $rut_in): array {
        $raw = strtoupper(trim((string)$rut_in));
        $warnings = [];

        if ($raw === '') {
            return [
                'ok' => false,
                'norm' => '',
                'formatted' => '',
                'error' => 'vacio',
                'warnings' => [],
            ];
        }

        $has_hyphen = (strpos($raw, '-') !== false);

        // limpiamos espacios y puntos
        $clean = preg_replace('/\s+/', '', $raw);
        $clean = str_replace('.', '', $clean);

        $num_part = '';
        $dv_part  = '';

        if ($has_hyphen) {
            $parts = explode('-', $clean, 2);
            $num_part = $parts[0] ?? '';
            $dv_part  = $parts[1] ?? '';
        } else {
            // si no hay guion, asumimos que el último carácter es el DV
            $warnings[] = 'sin_guion';
            $dv_part  = substr($clean, -1);
            $num_part = substr($clean, 0, -1);
        }

        $num = preg_replace('/[^0-9]/', '', (string)$num_part);
        $dv  = strtoupper((string)preg_replace('/[^0-9K]/', '', (string)$dv_part));

        if ($num === '' || $dv === '') {
            return [
                'ok' => false,
                'norm' => '',
                'formatted' => '',
                'error' => 'incompleto',
                'warnings' => $warnings,
            ];
        }

        // Personas naturales suelen tener 7 a 8 dígitos (sin DV). Usamos 7 como mínimo práctico.
        if (strlen($num) < 7) {
            return [
                'ok' => false,
                'norm' => '',
                'formatted' => '',
                'error' => 'pocos_digitos',
                'warnings' => $warnings,
            ];
        }

        $dv_calc = self::compute_dv($num);
        if ($dv_calc === '' || $dv_calc !== $dv) {
            return [
                'ok' => false,
                'norm' => '',
                'formatted' => '',
                'error' => 'dv_invalido',
                'warnings' => $warnings,
            ];
        }

        $norm = $num . $dv;
        $formatted = self::format_from_norm($norm);

        return [
            'ok' => true,
            'norm' => $norm,
            'formatted' => $formatted,
            'error' => '',
            'warnings' => $warnings,
        ];
    }

    /**
     * Normaliza a "NNNNNNNNX" (sin puntos ni guion), por ejemplo 156403938.
     */
    public static function normalize(string $rut): string {
        $p = self::parse($rut);
        return $p['ok'] ? $p['norm'] : '';
    }

    /**
     * Formatea a "12.345.678-9".
     */
    public static function format(string $rut): string {
        $p = self::parse($rut);
        return $p['ok'] ? $p['formatted'] : '';
    }

    private static function format_from_norm(string $norm): string {
        $norm = strtoupper(trim($norm));
        $norm = preg_replace('/[^0-9K]/', '', $norm);
        if ($norm === '' || strlen($norm) < 2) {
            return (string)$norm;
        }

        $dv = substr($norm, -1);
        $num = substr($norm, 0, -1);

        $rev = strrev($num);
        $chunks = str_split($rev, 3);
        $num_dotted = strrev(implode('.', $chunks));

        return $num_dotted . '-' . $dv;
    }
}
