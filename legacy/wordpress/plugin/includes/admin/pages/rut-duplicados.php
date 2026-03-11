<?php
if (!defined('ABSPATH')) { exit; }

add_action('admin_post_mlv2_merge_clientes', function () {
    if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

    $primary = isset($_POST['primary_id']) ? (int)$_POST['primary_id'] : 0;
    $secondary = isset($_POST['secondary_id']) ? (int)$_POST['secondary_id'] : 0;
    check_admin_referer('mlv2_merge_clientes_' . $primary . '_' . $secondary);

    if ($primary <= 0 || $secondary <= 0 || $primary === $secondary) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_rut_duplicados','mlv_err'=>'ids_invalidos'], admin_url('admin.php')));
        exit;
    }

    global $wpdb;
    $mov_table = MLV2_DB::table_movimientos();
    $ca_table = MLV2_DB::table_clientes_almacenes();
    $alerts_table = MLV2_Alerts::table();

    // Mover movimientos al principal
    $wpdb->update($mov_table, ['cliente_user_id' => $primary], ['cliente_user_id' => $secondary], ['%d'], ['%d']);

    // Mover relaciones N-N (sin duplicar)
    $locals = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT local_codigo FROM {$ca_table} WHERE cliente_user_id=%d",
        $secondary
    ));
    foreach ((array)$locals as $lc) {
        $lc = trim((string)$lc);
        if ($lc === '') continue;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$ca_table} (cliente_user_id, local_codigo, created_by_user_id, created_at)
             VALUES (%d, %s, %d, %s)",
            $primary,
            $lc,
            (int)get_current_user_id(),
            current_time('mysql')
        ));
    }
    $wpdb->delete($ca_table, ['cliente_user_id' => $secondary], ['%d']);

    // Mover alertas
    $wpdb->update($alerts_table, ['user_id' => $primary], ['user_id' => $secondary], ['%d'], ['%d']);

    // Marcar secundario
    update_user_meta($secondary, 'mlv_merged_into', $primary);

    if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
        MLV2_Ledger::recalc_saldo_cliente($primary);
        MLV2_Ledger::recalc_saldo_cliente($secondary);
    }

    if (class_exists('MLV2_Audit')) {
        MLV2_Audit::add('cliente_merge', 'cliente', $primary, null, [
            'primary' => $primary,
            'secondary' => $secondary,
        ]);
    }

    wp_safe_redirect(add_query_arg(['page'=>'mlv2_rut_duplicados','mlv_ok'=>'merged'], admin_url('admin.php')));
    exit;
});

final class MLV2_Admin_Rut_Duplicados {

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

        $ok = isset($_GET['mlv_ok']) ? sanitize_text_field(wp_unslash($_GET['mlv_ok'])) : '';
        $err = isset($_GET['mlv_err']) ? sanitize_text_field(wp_unslash($_GET['mlv_err'])) : '';

        global $wpdb;
        $um = $wpdb->usermeta;
        $mov_table = MLV2_DB::table_movimientos();

        $dups = $wpdb->get_results(
            "SELECT meta_value AS rut_norm, COUNT(*) AS n
             FROM {$um}
             WHERE meta_key='mlv_rut_norm' AND meta_value <> ''
             GROUP BY meta_value
             HAVING COUNT(*) > 1
             ORDER BY COUNT(*) DESC",
            ARRAY_A
        );

        echo '<div class="wrap">';
        echo '<h1>RUT duplicados</h1>';
        if ($ok === 'merged') { echo '<div class="notice notice-success"><p>Fusión realizada.</p></div>'; }
        if ($err) { echo '<div class="notice notice-error"><p>Ocurrió un error: ' . esc_html($err) . '</p></div>'; }

        if (empty($dups)) {
            echo '<p>No se encontraron duplicados.</p>';
            echo '</div>';
            return;
        }

        foreach ($dups as $d) {
            $rut_norm = (string)($d['rut_norm'] ?? '');
            if ($rut_norm === '') continue;

            $users = get_users([
                'meta_key' => 'mlv_rut_norm',
                'meta_value' => $rut_norm,
                'number' => 10,
                'fields' => ['ID','display_name','user_email','user_login'],
            ]);

            echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:12px 14px;margin:12px 0;">';
            echo '<h3>RUT: ' . esc_html(MLV2_RUT::format($rut_norm)) . ' (' . esc_html((string)$d['n']) . ')</h3>';
            echo '<table class="widefat striped" style="margin-top:8px;">';
            echo '<thead><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Teléfono</th><th>Saldo</th><th>Movimientos</th><th>Último movimiento</th></tr></thead><tbody>';

            foreach ($users as $u) {
                $uid = (int)$u->ID;
                $tel = (string)get_user_meta($uid, 'mlv_telefono', true);
                $saldo = (int)get_user_meta($uid, 'mlv_saldo', true);
                $count = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$mov_table} WHERE cliente_user_id=%d AND deleted_at IS NULL",
                    $uid
                ));
                $last = (string)$wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(created_at) FROM {$mov_table} WHERE cliente_user_id=%d AND deleted_at IS NULL",
                    $uid
                ));

                echo '<tr>';
                echo '<td>' . esc_html((string)$uid) . '</td>';
                echo '<td>' . esc_html($u->display_name ?: $u->user_login) . '</td>';
                echo '<td>' . esc_html($u->user_email) . '</td>';
                echo '<td>' . esc_html($tel ?: '—') . '</td>';
                echo '<td>$' . esc_html(number_format_i18n($saldo)) . '</td>';
                echo '<td>' . esc_html((string)$count) . '</td>';
                echo '<td>' . esc_html($last ?: '—') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            if (count($users) >= 2) {
                $p = (int)$users[0]->ID;
                $s = (int)$users[1]->ID;

                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
                echo '<input type="hidden" name="action" value="mlv2_merge_clientes">';
                echo '<input type="hidden" name="primary_id" value="' . esc_attr((string)$p) . '">';
                echo '<input type="hidden" name="secondary_id" value="' . esc_attr((string)$s) . '">';
                wp_nonce_field('mlv2_merge_clientes_' . $p . '_' . $s);
                submit_button('Fusionar (dry-run manual)', 'secondary', '', false);
                echo '<p class="description">Fusiona el segundo usuario en el primero. No borra el secundario.</p>';
                echo '</form>';
            }

            echo '</div>';
        }

        echo '</div>';
    }
}
