<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Servicio operativo para altas de movimientos.
 * Centraliza inserción contable y validaciones comunes de contexto.
 */
final class MLV2_Movement_Service {
    private static string $last_error = '';

    public static function get_last_error(): string {
        return self::$last_error;
    }

    private static function set_last_error(string $code): void {
        self::$last_error = sanitize_key($code);
    }

    private static function strict_mode_blocks_write(): bool {
        if ((int)get_option('mlv2_strict_mode_enabled', 0) !== 1) return false;
        if (!class_exists('MLV2_Health')) return false;
        return MLV2_Health::has_critical_issues_cached(60);
    }

    public static function get_local_codigo(int $almacen_id): string {
        if ($almacen_id <= 0) return '';
        if (function_exists('mlv2_get_local_codigo_for_user')) {
            return (string) mlv2_get_local_codigo_for_user($almacen_id);
        }
        return trim((string) get_user_meta($almacen_id, 'mlv_local_codigo', true));
    }

    public static function is_doble_rol_conflict(int $almacen_id, int $cliente_user_id): bool {
        if ($almacen_id <= 0 || $cliente_user_id <= 0) return false;
        if (function_exists('mlv2_is_doble_rol_conflict')) {
            return (bool) mlv2_is_doble_rol_conflict($almacen_id, $cliente_user_id);
        }
        return $almacen_id === $cliente_user_id;
    }

    public static function insert_ingreso(array $data): int {
        self::set_last_error('');
        if (!class_exists('MLV2_DB')) return 0;
        global $wpdb;

        if (self::strict_mode_blocks_write()) {
            self::set_last_error('strict_mode_block');
            return 0;
        }

        $local_codigo = trim((string)($data['local_codigo'] ?? ''));
        $cliente_user_id = (int)($data['cliente_user_id'] ?? 0);
        $created_by_user_id = (int)($data['created_by_user_id'] ?? 0);
        if ($local_codigo === '' || $cliente_user_id <= 0 || $created_by_user_id <= 0) {
            self::set_last_error('invalid_input');
            return 0;
        }

        $table = MLV2_DB::table_movimientos();
        $ok = $wpdb->insert(
            $table,
            [
                'tipo'               => 'ingreso',
                'created_at'         => current_time('mysql'),
                'updated_at'         => current_time('mysql'),
                'estado'             => (string)($data['estado'] ?? 'pendiente_retiro'),
                'local_codigo'       => $local_codigo,
                'created_by_user_id' => $created_by_user_id,
                'cliente_user_id'    => $cliente_user_id,
                'cliente_rut'        => (string)($data['cliente_rut'] ?? ''),
                'cantidad_latas'     => (int)($data['cantidad_latas'] ?? 0),
                'valor_por_lata'     => (int)($data['valor_por_lata'] ?? 0),
                'monto_calculado'    => (int)($data['monto_calculado'] ?? 0),
                'origen_saldo'       => (string)($data['origen_saldo'] ?? 'reciclaje'),
                'clasificacion_mov'  => (string)($data['clasificacion_mov'] ?? 'operacion'),
                'detalle'            => wp_json_encode((array)($data['detalle'] ?? []), JSON_UNESCAPED_UNICODE),
            ],
            ['%s','%s','%s','%s','%s','%d','%d','%s','%d','%d','%d','%s','%s','%s']
        );
        if (!$ok) {
            self::set_last_error('db_insert_failed');
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    public static function insert_gasto(array $data): int {
        self::set_last_error('');
        if (!class_exists('MLV2_DB')) return 0;
        global $wpdb;

        if (self::strict_mode_blocks_write()) {
            self::set_last_error('strict_mode_block');
            return 0;
        }

        $monto = abs((int)($data['monto'] ?? 0));
        $local_codigo = trim((string)($data['local_codigo'] ?? ''));
        $cliente_user_id = (int)($data['cliente_user_id'] ?? 0);
        $created_by_user_id = (int)($data['created_by_user_id'] ?? 0);
        if ($monto <= 0 || $local_codigo === '' || $cliente_user_id <= 0 || $created_by_user_id <= 0) {
            self::set_last_error('invalid_input');
            return 0;
        }

        $table = MLV2_DB::table_movimientos();
        $ok = $wpdb->insert(
            $table,
            [
                'tipo'                => 'gasto',
                'created_at'          => current_time('mysql'),
                'updated_at'          => current_time('mysql'),
                'estado'              => (string)($data['estado'] ?? 'retirado'),
                'local_codigo'        => $local_codigo,
                'created_by_user_id'  => $created_by_user_id,
                'cliente_user_id'     => $cliente_user_id,
                'cliente_rut'         => (string)($data['cliente_rut'] ?? ''),
                'cantidad_latas'      => 0,
                'monto_calculado'     => -$monto,
                'origen_saldo'        => (string)($data['origen_saldo'] ?? 'reciclaje'),
                'clasificacion_mov'   => (string)($data['clasificacion_mov'] ?? 'operacion'),
                'is_system_adjustment'=> 0,
                'mov_ref_id'          => null,
                'detalle'             => wp_json_encode((array)($data['detalle'] ?? []), JSON_UNESCAPED_UNICODE),
            ],
            ['%s','%s','%s','%s','%s','%d','%d','%s','%d','%d','%s','%s','%d','%d','%s']
        );
        if (!$ok) {
            self::set_last_error('db_insert_failed');
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    public static function recalc_cliente_saldo(int $cliente_user_id): void {
        if ($cliente_user_id <= 0) return;
        if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'recalc_saldo_cliente')) {
            MLV2_Ledger::recalc_saldo_cliente($cliente_user_id);
        }
    }
}
