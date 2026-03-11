<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Reverse_Incentivo_Batch {

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

        $batch_id = isset($_GET['batch_id']) ? sanitize_text_field(wp_unslash($_GET['batch_id'])) : '';
        $batch_id = trim($batch_id);
        if ($batch_id === '') {
            echo '<div class="wrap"><h1>Reversar lote incentivo</h1><p>Falta batch_id.</p></div>';
            return;
        }

        global $wpdb;
        $table = MLV2_DB::table_movimientos();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, cliente_user_id, monto_calculado, created_at
                 FROM {$table}
                 WHERE incentivo_batch_id=%s
                   AND origen_saldo='incentivo'
                   AND clasificacion_mov='operacion'
                   AND is_system_adjustment=0
                   AND deleted_at IS NULL
                 ORDER BY id ASC",
                $batch_id
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            echo '<div class="wrap"><h1>Reversar lote incentivo</h1><p>No se encontraron movimientos activos para este lote.</p></div>';
            return;
        }

        $ids = array_map(static function ($r) { return (int)($r['id'] ?? 0); }, $rows);
        $ids = array_values(array_filter($ids));
        $already_reversed = 0;
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $params = array_merge([$batch_id], $ids);
            $sql = "SELECT COUNT(*) FROM {$table}
                    WHERE incentivo_batch_id=%s
                      AND clasificacion_mov='correccion'
                      AND is_system_adjustment=1
                      AND deleted_at IS NULL
                      AND mov_ref_id IN ({$placeholders})";
            $already_reversed = (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
        }

        $total = 0;
        foreach ($rows as $r) {
            $total += (int)($r['monto_calculado'] ?? 0);
        }

        $action_url = add_query_arg(['action' => 'mlv2_reverse_incentivo_batch'], admin_url('admin-post.php'));

        echo '<div class="wrap">';
        echo '<h1>Reversar lote incentivo</h1>';
        echo '<div style="max-width:760px; background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:16px;">';
        echo '<p><strong>Batch ID:</strong> ' . esc_html($batch_id) . '</p>';
        echo '<p><strong>Movimientos activos:</strong> ' . esc_html((string)count($rows)) . '</p>';
        echo '<p><strong>Monto total lote:</strong> ' . esc_html('$' . number_format_i18n($total)) . '</p>';
        echo '<p><strong>Reversas activas detectadas:</strong> ' . esc_html((string)$already_reversed) . '</p>';

        if ($already_reversed > 0) {
            echo '<div class="notice notice-warning inline"><p>Este lote ya tiene reversas activas. No se puede reversar de nuevo como lote completo.</p></div>';
        }

        echo '<form method="post" action="' . esc_url($action_url) . '">';
        wp_nonce_field('mlv2_reverse_incentivo_batch_' . $batch_id);
        echo '<input type="hidden" name="batch_id" value="' . esc_attr($batch_id) . '">';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="motivo">Motivo de reversa</label></th>';
        echo '<td><input name="motivo" id="motivo" type="text" class="regular-text" required></td></tr>';
        echo '</table>';
        submit_button('Reversar lote completo', 'primary', 'submit', false, $already_reversed > 0 ? ['disabled' => 'disabled'] : []);
        echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=mlv2_movimientos')) . '">Volver</a>';
        echo '</form>';
        echo '</div>';
        echo '<p style="max-width:760px; color:#646970;">La reversa por lote crea movimientos espejo por cada cliente del batch para mantener trazabilidad contable.</p>';
        echo '</div>';
    }
}
