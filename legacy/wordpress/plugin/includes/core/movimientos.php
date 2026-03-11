<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Utilidades compartidas para normalizar lectura de movimientos.
 * Evita divergencias entre front/admin/export al interpretar gasto/ingreso.
 */
final class MLV2_Movimientos {

    public static function decode_detalle($detalle_raw): array {
        if (is_array($detalle_raw)) {
            return $detalle_raw;
        }
        if (!is_string($detalle_raw) || trim($detalle_raw) === '') {
            return [];
        }
        $decoded = json_decode($detalle_raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function is_gasto_row(array $mov, ?array $detalle = null): bool {
        $detalle = is_array($detalle) ? $detalle : self::decode_detalle($mov['detalle'] ?? '');
        $monto_db = isset($mov['monto_calculado']) ? (int)$mov['monto_calculado'] : 0;
        $tipo_raw = strtolower((string)($mov['tipo'] ?? ''));
        $tipo_det = strtolower((string)($detalle['tipo'] ?? ''));

        if ($tipo_det === 'gasto') return true;
        if (!empty($detalle['gasto'])) return true;
        if ($monto_db < 0) return true;
        if ($tipo_raw === 'gasto') return true;

        return false;
    }

    public static function monto_efectivo(array $mov, ?array $detalle = null): int {
        $detalle = is_array($detalle) ? $detalle : self::decode_detalle($mov['detalle'] ?? '');
        $latas = (int)($mov['cantidad_latas'] ?? 0);
        $monto_db = isset($mov['monto_calculado']) ? (int)$mov['monto_calculado'] : 0;
        $is_gasto = self::is_gasto_row($mov, $detalle);

        if ($monto_db === 0 && !$is_gasto && $latas > 0 && class_exists('MLV2_Pricing') && method_exists('MLV2_Pricing', 'calcular_monto_por_latas')) {
            $monto_db = (int) MLV2_Pricing::calcular_monto_por_latas($latas);
        }

        if ($is_gasto) {
            if ($monto_db === 0) {
                $monto_db = (int)($detalle['gasto']['monto'] ?? 0);
            }
            return -abs((int)$monto_db);
        }

        return abs((int)$monto_db);
    }

    public static function extract_evidencia_url(?array $detalle = null): string {
        $d = is_array($detalle) ? $detalle : [];
        $url = '';
        if (!empty($d['evidencia_url'])) $url = (string)$d['evidencia_url'];
        if ($url === '' && !empty($d['declarado']['evidencia_url'])) $url = (string)$d['declarado']['evidencia_url'];
        if ($url === '' && !empty($d['evidencia']['url'])) $url = (string)$d['evidencia']['url'];
        if ($url === '' && !empty($d['gasto']['evidencia_url'])) $url = (string)$d['gasto']['evidencia_url'];
        if ($url === '' && !empty($d['gasto']['evidencia']['url'])) $url = (string)$d['gasto']['evidencia']['url'];
        return $url;
    }
}
