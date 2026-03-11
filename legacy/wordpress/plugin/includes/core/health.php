<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Health {
    public static function clear_cache(): void {
        delete_transient('mlv2_health_critical_v1');
    }

    public static function run_checks(): array {
        global $wpdb;

        $mov = class_exists('MLV2_DB') ? MLV2_DB::table_movimientos() : $wpdb->prefix . 'mlv_movimientos';
        $ca  = class_exists('MLV2_DB') ? MLV2_DB::table_clientes_almacenes() : $wpdb->prefix . 'mlv_clientes_almacenes';
        $al  = class_exists('MLV2_DB') ? MLV2_DB::table_alerts() : $wpdb->prefix . 'mlv_alerts';
        $au  = $wpdb->prefix . 'mlv_audit_log';

        $checks = [];

        $checks[] = self::check_table_exists($mov, 'Tabla movimientos');
        $checks[] = self::check_table_exists($ca, 'Tabla clientes_almacenes');
        $checks[] = self::check_table_exists($al, 'Tabla alerts');
        $checks[] = self::check_table_exists($au, 'Tabla audit_log');

        $required_cols = ['tipo','cliente_user_id','local_codigo','monto_calculado','origen_saldo','mov_ref_id','is_system_adjustment','clasificacion_mov','incentivo_batch_id','estado','deleted_at'];
        foreach ($required_cols as $col) {
            $checks[] = self::check_column_exists($mov, $col, 'Columna movimientos.' . $col);
        }

        $required_idx = [
            'idx_estado',
            'idx_local',
            'idx_cliente',
            'idx_deleted',
            'idx_created_by',
            'idx_cliente_deleted',
            'idx_local_deleted_created',
            'idx_origen_saldo',
            'idx_mov_ref_id',
            'idx_clasificacion_mov',
            'idx_incentivo_batch_id',
        ];
        foreach ($required_idx as $idx) {
            $checks[] = self::check_index_exists($mov, $idx, 'Indice movimientos.' . $idx);
        }

        $checks[] = self::check_anomaly_count(
            "SELECT COUNT(*) FROM {$mov} WHERE deleted_at IS NULL AND tipo='gasto' AND monto_calculado >= 0",
            0,
            'Gastos con monto no negativo'
        );
        $checks[] = self::check_anomaly_count(
            "SELECT COUNT(*) FROM {$mov} WHERE deleted_at IS NULL AND tipo='ingreso' AND monto_calculado < 0",
            0,
            'Ingresos con monto negativo'
        );
        $checks[] = self::check_anomaly_count(
            "SELECT COUNT(*) FROM {$mov} WHERE deleted_at IS NULL AND (local_codigo IS NULL OR local_codigo='')",
            0,
            'Movimientos sin local_codigo'
        );
        $checks[] = self::check_anomaly_count(
            "SELECT COUNT(*) FROM {$mov} WHERE deleted_at IS NULL AND cliente_user_id <= 0",
            0,
            'Movimientos sin cliente_user_id valido'
        );

        $ok = 0;
        $warn = 0;
        foreach ($checks as $c) {
            if (($c['status'] ?? '') === 'ok') $ok++;
            else $warn++;
        }

        return [
            'generated_at' => current_time('mysql'),
            'ok' => $ok,
            'warn' => $warn,
            'checks' => $checks,
        ];
    }

    public static function has_critical_issues_cached(int $ttl = 60): bool {
        $ttl = max(10, (int)$ttl);
        $cache_key = 'mlv2_health_critical_v1';
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['value']) && isset($cached['ts'])) {
            return (bool) $cached['value'];
        }

        $report = self::run_checks();
        $value = self::has_critical_issues($report);
        set_transient($cache_key, ['value' => (int)$value, 'ts' => time()], $ttl);
        return $value;
    }

    public static function has_critical_issues(array $report): bool {
        $checks = (array)($report['checks'] ?? []);
        foreach ($checks as $c) {
            $status = (string)($c['status'] ?? '');
            if ($status !== 'warn') continue;
            $label = (string)($c['label'] ?? '');
            if (
                strpos($label, 'Tabla ') === 0 ||
                strpos($label, 'Columna ') === 0 ||
                strpos($label, 'Indice ') === 0
            ) {
                return true;
            }
        }
        return false;
    }

    private static function check_table_exists(string $table, string $label): array {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        $ok = ($exists === $table);
        return [
            'label' => $label,
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? 'OK' : 'No existe: ' . $table,
        ];
    }

    private static function check_column_exists(string $table, string $column, string $label): array {
        global $wpdb;
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        $ok = !empty($col);
        return [
            'label' => $label,
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? 'OK' : 'Falta columna',
        ];
    }

    private static function check_index_exists(string $table, string $index, string $label): array {
        global $wpdb;
        $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name=%s", $index));
        $ok = !empty($idx);
        return [
            'label' => $label,
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? 'OK' : 'Falta indice',
        ];
    }

    private static function check_anomaly_count(string $sql, int $expected, string $label): array {
        global $wpdb;
        $n = (int) $wpdb->get_var($sql);
        $ok = ($n === $expected);
        return [
            'label' => $label,
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? 'OK' : ('Detectados: ' . $n),
        ];
    }
}
