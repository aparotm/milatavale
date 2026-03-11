<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Ledger {

    public static function estados(): array {
        return ['pendiente_retiro','retirado'];
    }

    public static function insertar_ingreso(array $args): int {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $defaults = [
            'cliente_user_id' => 0,
            'cliente_rut' => '',
            'cliente_telefono' => '',
            'local_codigo' => '',
            'cantidad_latas' => 0,
            'monto_calculado' => 0,
            'estado' => 'pendiente_retiro',
            'detalle' => [],
            'created_by_user_id' => 0,
        ];
        $data = array_merge($defaults, $args);

        $detalle = wp_json_encode($data['detalle'], JSON_UNESCAPED_UNICODE);

        $now = current_time('mysql');

        $insert = [
            'tipo' => 'ingreso',
            'cliente_user_id' => (int)$data['cliente_user_id'],
            'cliente_rut' => $data['cliente_rut'],
            'cliente_telefono' => $data['cliente_telefono'],
            'local_codigo' => $data['local_codigo'],
            'cantidad_latas' => (int)$data['cantidad_latas'],
            'monto_calculado' => (int)($data['monto_calculado'] ?? 0),
            'origen_saldo' => 'reciclaje',
            'mov_ref_id' => null,
            'is_system_adjustment' => 0,
            'clasificacion_mov' => 'operacion',
            'estado' => $data['estado'],
            'detalle' => $detalle,
            'created_by_user_id' => (int)$data['created_by_user_id'],
            'validated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $ok = $wpdb->insert($table, $insert);
        if (!$ok) { return 0; }
        return (int)$wpdb->insert_id;
    }

    public static function get_movimiento(int $id): ?array {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        if (!$row) { return null; }
        $row['detalle'] = self::decode_detalle($row['detalle'] ?? '');
        return $row;
    }

    public static function decode_detalle(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Aplica un patch al detalle, agrega evento al historial y actualiza estado.
     * No asume nada del flujo, sólo registra.
     */
    public static function update_estado_y_detalle(int $id, string $estado, array $detalle_patch, int $actor_user_id): bool {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();
        $mov = self::get_movimiento($id);
        if (!$mov) { return false; }

        // No permitir cambios en movimientos enviados a papelera.
        if (!empty($mov['deleted_at'])) {
            return false;
        }

        $detalle = $mov['detalle'];

// Historial estándar (append-only)
$event = [
    'ts'            => current_time('mysql'),
    'actor_user_id' => $actor_user_id,
    'actor_role'    => (function_exists('wp_get_current_user') ? (wp_get_current_user()->roles[0] ?? '') : ''),
    'accion'        => ($detalle_patch['_accion'] ?? 'update_estado'),
    'estado'        => $estado,
    'payload'       => $detalle_patch,
];

// Nuevo contrato: detalle['hist']
$detalle['hist'] = $detalle['hist'] ?? [];
$detalle['hist'][] = $event;

// Back-compat: mantener 'historial' si existía / si front lo usa
$detalle['historial'] = $detalle['historial'] ?? [];
$detalle['historial'][] = [
    'ts' => $event['ts'],
    'actor_user_id' => $actor_user_id,
    'estado' => $estado,
    'patch' => $detalle_patch,
];

        // merge patch at top-level for declared/validated blocks
        foreach ($detalle_patch as $k => $v) {
            $detalle[$k] = $v;
        }

        $ok = $wpdb->update(
            $table,
            [
                'estado' => $estado,
                'detalle' => wp_json_encode($detalle, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s','%s','%s'],
            ['%d']
        );
        return $ok !== false;
    }

    /**
     * Compat: usado por Validation.
     * Simple wrapper sobre update_estado_y_detalle.
     */
    public static function apply_patch(int $id, array $detalle_patch, string $nuevo_estado, int $actor_user_id, array $hist_patch = []): bool {
        // Guardamos también un patch auxiliar en historial (si viene)
        $patch = $detalle_patch;
        if (!empty($hist_patch)) {
            $patch['_meta'] = $hist_patch;
        }
        return self::update_estado_y_detalle($id, $nuevo_estado, $patch, $actor_user_id);
    }

    /**
     * ✅ Método faltante (bug crítico).
     *
     * Aplica patch de admin + consolida monto en el movimiento + suma saldo.
     * Incluye idempotencia: NO vuelve a sumar si ya fue consolidado antes.
     *
     * @param int   $id
     * @param array $detalle_patch   ej: ['patch' => [...]]
     * @param int   $monto
     * @param int   $admin_user_id
     * @param array $hist_patch      ej: ['accion' => 'consolidacion_admin']
     */
    public static function apply_patch_and_consolidate(int $id, array $detalle_patch, int $monto, int $admin_user_id, array $hist_patch = []): bool {
    // Compatibilidad histórica: ya no existe validación/admin.
    // Conservamos el método para no romper llamadas antiguas, pero NO toca saldo ni monta estados especiales.
    $detalle_patch['_accion'] = $hist_patch['accion'] ?? 'apply_patch_compat';
    return self::apply_patch($id, $detalle_patch, 'pendiente_retiro', $admin_user_id, $hist_patch);
}

    /**
     * Saldo real del cliente (fuente de verdad: tabla de movimientos).
     *
     * Mantenemos mlv_saldo como cache (recalc) para compat, pero para
     * validaciones y UI usamos este cálculo para evitar inconsistencias.
     */
    public static function get_saldo_cliente(int $cliente_user_id): float {
        global $wpdb;
        $cliente_user_id = (int)$cliente_user_id;
        if ($cliente_user_id <= 0) {
            return 0.0;
        }

        if (!class_exists('MLV2_DB')) {
            return (float) get_user_meta($cliente_user_id, 'mlv_saldo', true);
        }

        $table = MLV2_DB::table_movimientos();
        // Si la tabla no existe por alguna razón, usar cache.
        if (!$table) {
            return (float) get_user_meta($cliente_user_id, 'mlv_saldo', true);
        }

        $saldo = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto_calculado),0) FROM {$table} WHERE cliente_user_id=%d AND deleted_at IS NULL",
            $cliente_user_id
        ));

        return (float) $saldo;
    }

    /**
     * Resta saldo si hay suficiente. Devuelve true si pudo, false si saldo insuficiente.
     */
    public static function restar_saldo_cliente(int $cliente_user_id, float $monto): bool {
        $monto = (float) $monto;
        if ($monto <= 0) return true;
        // Operación sobre cache para mantener compat con flujos antiguos.
        $actual = (float) get_user_meta($cliente_user_id, 'mlv_saldo', true);
        if ($actual < $monto) return false;
        update_user_meta($cliente_user_id, 'mlv_saldo', $actual - $monto);
        return true;
    }

public static function sumar_saldo_cliente(int $cliente_user_id, int $monto): void {
        $cliente_user_id = (int)$cliente_user_id;
        $monto = (int)$monto;
        $actual = (int) get_user_meta($cliente_user_id, 'mlv_saldo', true);
        update_user_meta($cliente_user_id, 'mlv_saldo', $actual + $monto);
    }


    /**
     * Recalcula el saldo del cliente desde el ledger (movimientos vigentes).
     * Mantiene consistente mlv_saldo cuando un admin envía movimientos a papelera o los restaura.
     */
    public static function recalc_saldo_cliente(int $cliente_user_id): int {
        global $wpdb;
        $cliente_user_id = (int)$cliente_user_id;
        if ($cliente_user_id <= 0) return 0;

        $table = MLV2_DB::table_movimientos();
        $saldo = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto_calculado),0) FROM {$table} WHERE cliente_user_id=%d AND deleted_at IS NULL",
            $cliente_user_id
        ));

        update_user_meta($cliente_user_id, 'mlv_saldo', $saldo);
        return $saldo;
    }

    /**
     * Utilidad (referenciada desde Validation en algunos flujos): encontrar cliente por RUT.
     * Si tu instalación no la tiene en otro archivo, esto evita errores fatales.
     */
    public static function find_cliente_by_rut(string $rut): int {
        $rut = trim((string)$rut);
        if ($rut === '') { return 0; }

        // Normalizar entrada
        $rut_norm = $rut;
        $rut_fmt  = $rut;
        if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','parse')) {
            $p = MLV2_RUT::parse($rut);
            if (!empty($p['ok'])) {
                $rut_norm = (string)($p['norm'] ?? $rut);
                $rut_fmt  = (string)($p['formatted'] ?? $rut);
            }
        }

        // Buscar primero por normalizado exacto (nuevo)
        $users = get_users([
            'meta_key' => 'mlv_rut_norm',
            'meta_value' => $rut_norm,
            'number' => 1,
            'fields' => 'ID',
        ]);

        // Fallback compatibilidad
        if (empty($users)) {
            $users = get_users([
                'meta_key' => 'mlv_rut',
                'meta_value' => $rut_norm,
                'number' => 1,
                'fields' => 'ID',
            ]);
        }
        if (empty($users) && $rut_fmt !== $rut_norm) {
            $users = get_users([
                'meta_key' => 'mlv_rut',
                'meta_value' => $rut_fmt,
                'number' => 1,
                'fields' => 'ID',
            ]);
        }

        if (empty($users)) { return 0; }
        return (int) $users[0];
    }
}
