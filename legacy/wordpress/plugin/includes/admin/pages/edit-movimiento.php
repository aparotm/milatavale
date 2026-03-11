<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Editor simple de movimientos (enfocado en gastos) para evitar tener que tocar BD a mano.
 * Permite ajustar el monto de un gasto y recalcula el saldo del cliente.
 */
final class MLV2_Admin_Edit_Movimiento {

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
        global $wpdb;

        $mov_id = isset($_GET['mov_id']) ? (int) $_GET['mov_id'] : 0;
        if ($mov_id <= 0) {
            echo '<div class="wrap"><h1>Editar movimiento</h1><p>Falta mov_id.</p></div>';
            return;
        }

        $table = MLV2_DB::table_movimientos();
        $mov = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $mov_id), ARRAY_A);
        if (!$mov) {
            echo '<div class="wrap"><h1>Editar movimiento</h1><p>Movimiento no encontrado.</p></div>';
            return;
        }

        $tipo = (string)($mov['tipo'] ?? '');
        $monto = (int)($mov['monto_calculado'] ?? 0);
        $cliente_id = (int)($mov['cliente_user_id'] ?? 0);
        $cliente = $cliente_id > 0 ? get_userdata($cliente_id) : null;

        // Intentar leer monto real desde detalle si existe
        $detalle = self::json_decode_assoc((string)($mov['detalle'] ?? ''));
        $monto_detalle = null;
        if (is_array($detalle)) {
            if (isset($detalle['gasto']['monto'])) {
                $monto_detalle = (int) $detalle['gasto']['monto'];
            } elseif (isset($detalle['monto'])) {
                $monto_detalle = (int) $detalle['monto'];
            }
        }

        $monto_abs = abs($monto);
        if ($monto_abs === 0 && is_int($monto_detalle) && $monto_detalle > 0) {
            $monto_abs = $monto_detalle;
        }

        $saldo_actual = null;
        if ($cliente_id > 0 && class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
            // No forzar escritura aquí; solo mostrar el saldo actual según usermeta
            $saldo_actual = (int) get_user_meta($cliente_id, 'mlv_saldo', true);
        }

        $action_url = add_query_arg([
            'action' => 'mlv2_update_movimiento_monto',
        ], admin_url('admin-post.php'));

        echo '<div class="wrap">';
        echo '<h1>Editar movimiento</h1>';

        if (!empty($_GET['mlv_msg'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['mlv_msg']));
            echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
        }
        if (!empty($_GET['mlv_err'])) {
            $err = sanitize_text_field(wp_unslash($_GET['mlv_err']));
            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
        }

        echo '<div style="max-width:720px; background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:16px;">';
        echo '<p><strong>ID:</strong> ' . esc_html((string)$mov_id) . '</p>';
        echo '<p><strong>Tipo:</strong> ' . esc_html($tipo ?: '—') . '</p>';
        echo '<p><strong>Cliente:</strong> ' . ($cliente ? esc_html($cliente->display_name . ' (#' . $cliente_id . ')') : '—') . '</p>';
        if ($saldo_actual !== null) {
            echo '<p><strong>Saldo actual (snapshot):</strong> $' . esc_html(number_format_i18n($saldo_actual)) . '</p>';
        }
        echo '<p><strong>Monto actual:</strong> ' . esc_html($monto < 0 ? '-' : '') . '$' . esc_html(number_format_i18n($monto_abs)) . '</p>';

        // Form
        echo '<form method="post" action="' . esc_url($action_url) . '">';
        wp_nonce_field('mlv2_update_movimiento_monto_' . $mov_id);
        echo '<input type="hidden" name="mov_id" value="' . esc_attr((string)$mov_id) . '"/>';

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="mlv2_new_amount">Nuevo monto (positivo)</label></th>';
        echo '<td><input name="new_amount" id="mlv2_new_amount" type="number" min="1" step="1" value="' . esc_attr((string)$monto_abs) . '" class="regular-text"/></td></tr>';
        echo '<tr><th scope="row"><label for="mlv2_reason">Motivo (opcional)</label></th>';
        echo '<td><input name="reason" id="mlv2_reason" type="text" value="" class="regular-text" placeholder="Ej: Gasto duplicado / Cliente equivocado / Error de digitación"/></td></tr>';
        echo '</table>';

        submit_button('Guardar cambios', 'primary');
        echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=mlv2_movimientos')) . '">Volver</a>';
        echo '</form>';
        echo '</div>';

        echo '<p style="max-width:720px; color:#646970;">Esto modifica el monto del movimiento y luego recalcula el saldo del cliente a partir del ledger. Queda registro en auditoría.</p>';
        echo '</div>';
    }

    private static function json_decode_assoc(string $json): ?array {
        $json = trim($json);
        if ($json === '') return null;
        $d = json_decode($json, true);
        return is_array($d) ? $d : null;
    }
}

// Guardar cambios
add_action('admin_post_mlv2_update_movimiento_monto', function () {
    if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
    $mov_id = isset($_POST['mov_id']) ? (int) $_POST['mov_id'] : 0;
    if ($mov_id <= 0) { wp_die('mov_id inválido'); }
    check_admin_referer('mlv2_update_movimiento_monto_' . $mov_id);

    $new_amount = isset($_POST['new_amount']) ? (int) $_POST['new_amount'] : null;
    if ($new_amount === null || $new_amount <= 0) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_edit_movimiento','mov_id'=>$mov_id,'mlv_err'=>'Monto inválido'], admin_url('admin.php')));
        exit;
    }
    $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

    global $wpdb;
    $table = MLV2_DB::table_movimientos();

    $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $mov_id), ARRAY_A);
    if (!$before) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_edit_movimiento','mov_id'=>$mov_id,'mlv_err'=>'Movimiento no encontrado'], admin_url('admin.php')));
        exit;
    }
    if (!empty($before['deleted_at'])) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_edit_movimiento','mov_id'=>$mov_id,'mlv_err'=>'Movimiento en papelera'], admin_url('admin.php')));
        exit;
    }

    // Si es gasto (o debería serlo), guardamos siempre como negativo.
    $tipo = (string)($before['tipo'] ?? '');
    $is_gasto = ($tipo === 'gasto') || ((int)($before['monto_calculado'] ?? 0) < 0);
    $new_monto = $is_gasto ? (0 - abs($new_amount)) : $new_amount;

    $detalle = trim((string)($before['detalle'] ?? ''));
    $d = $detalle !== '' ? json_decode($detalle, true) : null;
    if (is_array($d)) {
        if ($is_gasto) {
            $d['tipo'] = 'gasto';
            if (!isset($d['gasto']) || !is_array($d['gasto'])) { $d['gasto'] = []; }
            $d['gasto']['monto'] = abs($new_amount);
        }
        if (!isset($d['history']) || !is_array($d['history'])) { $d['history'] = []; }
        $d['history'][] = [
            'ts' => current_time('mysql'),
            'by' => (int) get_current_user_id(),
            'action' => 'edit_monto',
            'before' => (int)($before['monto_calculado'] ?? 0),
            'after' => (int)$new_monto,
            'reason' => $reason,
        ];
        $detalle = wp_json_encode($d, JSON_UNESCAPED_UNICODE);
    }

    $now = current_time('mysql');
    $upd = [
        'monto_calculado' => (int)$new_monto,
        'updated_at' => $now,
    ];
    $fmt = ['%d','%s'];
    if ($detalle !== (string)($before['detalle'] ?? '')) {
        $upd['detalle'] = $detalle;
        $fmt[] = '%s';
    }
    if ($is_gasto && $tipo !== 'gasto') {
        $upd['tipo'] = 'gasto';
        $fmt[] = '%s';
    }

    // Orden de formatos debe seguir el orden de $upd
    $formats = [];
    foreach ($upd as $k => $_v) {
        if ($k === 'monto_calculado') $formats[] = '%d';
        elseif ($k === 'updated_at') $formats[] = '%s';
        else $formats[] = '%s';
    }

    $wpdb->update($table, $upd, ['id' => $mov_id], $formats, ['%d']);

    $after = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $mov_id), ARRAY_A);
    if (class_exists('MLV2_Audit')) {
        MLV2_Audit::add('movimiento_edit_monto', 'movimiento', $mov_id, $before, $after);
    }

    // Recalcular saldo del cliente
    $cid = (int)($before['cliente_user_id'] ?? 0);
    if ($cid > 0 && class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
        MLV2_Ledger::recalc_saldo_cliente($cid);
    }

    wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_msg'=>'monto_ok'], admin_url('admin.php')));
    exit;
});
