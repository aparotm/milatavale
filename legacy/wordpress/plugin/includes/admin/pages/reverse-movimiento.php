<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Reverse_Movimiento {

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

        $mov_id = isset($_GET['mov_id']) ? (int) $_GET['mov_id'] : 0;
        if ($mov_id <= 0) {
            echo '<div class="wrap"><h1>Reversar movimiento</h1><p>Falta mov_id.</p></div>';
            return;
        }

        global $wpdb;
        $table = MLV2_DB::table_movimientos();
        $mov = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $mov_id), ARRAY_A);
        if (!$mov) {
            echo '<div class="wrap"><h1>Reversar movimiento</h1><p>Movimiento no encontrado.</p></div>';
            return;
        }

        $monto = (int)($mov['monto_calculado'] ?? 0);
        $cliente_id = (int)($mov['cliente_user_id'] ?? 0);
        $cliente = $cliente_id > 0 ? get_userdata($cliente_id) : null;

        $action_url = add_query_arg(['action' => 'mlv2_reverse_movimiento'], admin_url('admin-post.php'));

        echo '<div class="wrap">';
        echo '<h1>Reversar movimiento</h1>';
        echo '<div style="max-width:720px; background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:16px;">';
        echo '<p><strong>ID:</strong> ' . esc_html((string)$mov_id) . '</p>';
        echo '<p><strong>Cliente:</strong> ' . ($cliente ? esc_html($cliente->display_name . ' (#' . $cliente_id . ')') : '—') . '</p>';
        echo '<p><strong>Monto actual:</strong> ' . esc_html(($monto < 0 ? '-' : '') . '$' . number_format_i18n(abs($monto))) . '</p>';

        echo '<form method="post" action="' . esc_url($action_url) . '">';
        wp_nonce_field('mlv2_reverse_movimiento_' . $mov_id);
        echo '<input type="hidden" name="mov_id" value="' . esc_attr((string)$mov_id) . '">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="motivo">Motivo de reversa</label></th>';
        echo '<td><input name="motivo" id="motivo" type="text" class="regular-text" required></td></tr>';
        echo '</table>';
        submit_button('Reversar movimiento', 'primary');
        echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=mlv2_movimientos')) . '">Volver</a>';
        echo '</form>';
        echo '</div>';
        echo '<p style="max-width:720px; color:#646970;">La reversa crea un movimiento espejo y mantiene trazabilidad contable.</p>';
        echo '</div>';
    }
}
