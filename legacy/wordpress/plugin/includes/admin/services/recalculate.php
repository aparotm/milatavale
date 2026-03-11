<?php
if (!defined('ABSPATH')) { exit; }

add_action('admin_post_mlv2_recalculate_all', 'mlv2_handle_recalculate_all');

function mlv2_handle_recalculate_all() {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado');
    }

    check_admin_referer('mlv2_recalculate_all');

    global $wpdb;
    $table = MLV2_DB::table_movimientos();
    $price = MLV2_Pricing::get_price_per_lata();

    if ($price <= 0) {
        wp_redirect(add_query_arg('mlv2_res', 'price_zero', wp_get_referer()));
        exit;
    }

// 0) ✅ Reparación integrada (sin plugins auxiliares)
// Históricamente, algunos gastos se insertaron sin columna `tipo='gasto'` (quedaban en DEFAULT 'ingreso'),
// y luego el recálculo los pisaba a 0 (porque cantidad_latas=0). Eso rompe KPIs y el listado admin.
// Aquí reparamos:
// - Si el JSON `detalle` indica gasto (detalle.tipo='gasto' o detalle.gasto existe), forzamos tipo='gasto'
// - Si monto_calculado está en 0/NULL/positivo, lo reconstruimos desde detalle.gasto.monto (negativo)
$candidatos = $wpdb->get_results(
    "SELECT id, tipo, monto_calculado, detalle
     FROM {$table}
     WHERE deleted_at IS NULL
       AND (detalle LIKE '%\"gasto\"%' OR detalle LIKE '%\"tipo\":\"gasto\"%' OR monto_calculado < 0)",
    ARRAY_A
);

foreach ((array)$candidatos as $r) {
    $id = (int)($r['id'] ?? 0);
    if ($id <= 0) continue;

    $tipo_raw = strtolower((string)($r['tipo'] ?? ''));
    $monto_db = (int)($r['monto_calculado'] ?? 0);

    $detalle = json_decode($r['detalle'] ?? '', true);
    if (!is_array($detalle)) { $detalle = []; }

    $tipo_det = strtolower((string)($detalle['tipo'] ?? ''));

    $is_gasto = false;
    if ($tipo_det === 'gasto') $is_gasto = true;
    elseif (!empty($detalle['gasto']) && is_array($detalle['gasto'])) $is_gasto = true;
    elseif ($monto_db < 0) $is_gasto = true;

    if (!$is_gasto) continue;

    $updates = [];
    $formats = [];
    $where = ['id' => $id];
    $where_formats = ['%d'];

    // Asegurar tipo='gasto' en BD
    if ($tipo_raw !== 'gasto') {
        $updates['tipo'] = 'gasto';
        $formats[] = '%s';
    }

    // Reconstruir monto si está pisado (0/NULL/positivo)
    if ($monto_db >= 0) {
        $monto = null;

        if (!empty($detalle['gasto']['monto'])) {
            $monto = $detalle['gasto']['monto'];
        } elseif (!empty($detalle['monto'])) {
            $monto = $detalle['monto'];
        } elseif (!empty($detalle['amount'])) {
            $monto = $detalle['amount'];
        }

        if ($monto !== null) {
            if (is_int($monto)) {
                $num = $monto;
            } elseif (is_float($monto)) {
                $num = (int) round($monto);
            } else {
                $s = (string)$monto;
                // deja solo dígitos y signo
                $s = preg_replace('/[^0-9\-]/', '', $s);
                $num = ($s === '' || $s === '-') ? 0 : (int)$s;
            }
            $num = abs((int)$num);
            if ($num > 0) {
                $updates['monto_calculado'] = -$num;
                $formats[] = '%d';
            }
        }
    }

    if (!empty($updates)) {
        $wpdb->update($table, $updates, $where, $formats, $where_formats);
    }
}


    // 1) Recalcular monto_calculado SOLO para ingresos.
    // Importante: los gastos no se deben recalcular con (latas * price),
    // porque normalmente tienen cantidad_latas=0 y se terminarían dejando
    // en 0, rompiendo KPIs y reconstrucción de saldo.
    $rows = $wpdb->get_results(
        "SELECT id, cantidad_latas, detalle
         FROM {$table}
         WHERE 1=1 AND deleted_at IS NULL AND tipo = 'ingreso'
           AND origen_saldo = 'reciclaje'
           AND clasificacion_mov = 'operacion'",
        ARRAY_A
    );

    foreach ((array)$rows as $r) {
        $detalle = json_decode($r['detalle'] ?? '', true);
        if (!is_array($detalle)) { $detalle = []; }

        // Latas: usamos la fila; si existe validado_admin (histórico) lo respetamos.
        if (!empty($detalle['validado_admin']['cantidad_latas'])) {
            $latas = (int)$detalle['validado_admin']['cantidad_latas'];
        } else {
            $latas = (int)($r['cantidad_latas'] ?? 0);
        }
        if ($latas < 0) { $latas = 0; }

        $monto = $latas * $price;

        $wpdb->update(
            $table,
            ['monto_calculado' => $monto],
            ['id' => (int)$r['id']],
            ['%d'],
            ['%d']
        );
    }

    // 2) Recalcular saldos (modelo actual: crédito inmediato)
    $clientes = $wpdb->get_results(
        "SELECT cliente_user_id, COALESCE(SUM(monto_calculado),0) AS saldo
         FROM {$table}
         WHERE 1=1 AND deleted_at IS NULL AND cliente_user_id > 0
         GROUP BY cliente_user_id",
        ARRAY_A
    );

    // (Opcional) reset a 0 solo para los que aparecen en consolidado
    foreach ((array)$clientes as $c) {
        update_user_meta((int)$c['cliente_user_id'], 'mlv_saldo', (int)$c['saldo']);
    }

    wp_redirect(add_query_arg('mlv2_res', 'recalculated', wp_get_referer()));
    exit;
}
