<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Conflictos_Doble_Rol {

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

        global $wpdb;
        $table_ca = MLV2_DB::table_clientes_almacenes();

        $almacenes = get_users([
            'role' => 'um_almacen',
            'fields' => ['ID','display_name','user_login'],
            'number' => 5000,
        ]);

        $alm_map = [];
        foreach ($almacenes as $a) {
            $rut_norm = class_exists('MLV2_RUT') ? MLV2_RUT::normalize((string)get_user_meta($a->ID, 'mlv_rut', true)) : '';
            $lc = (string)get_user_meta($a->ID, 'mlv_local_codigo', true);
            $alm_map[] = [
                'id' => (int)$a->ID,
                'name' => $a->display_name ?: $a->user_login,
                'rut_norm' => $rut_norm,
                'local_codigo' => $lc,
            ];
        }

        $conflicts = [];

        // Conflicto 1: mismo RUT como almacén y cliente
        foreach ($alm_map as $alm) {
            if ($alm['rut_norm'] === '') continue;
            $clients = get_users([
                'role' => 'um_cliente',
                'meta_key' => 'mlv_rut_norm',
                'meta_value' => $alm['rut_norm'],
                'number' => 50,
                'fields' => ['ID','display_name','user_login'],
            ]);
            foreach ($clients as $c) {
                $conflicts[] = [
                    'tipo' => 'Mismo RUT almacén/cliente',
                    'almacen_id' => $alm['id'],
                    'almacen_name' => $alm['name'],
                    'cliente_id' => (int)$c->ID,
                    'cliente_name' => $c->display_name ?: $c->user_login,
                    'local_codigo' => $alm['local_codigo'],
                ];
            }
        }

        // Conflicto 2: relación cliente-local donde cliente es el mismo almacén
        $rows = $wpdb->get_results(
            "SELECT cliente_user_id, local_codigo FROM {$table_ca}",
            ARRAY_A
        );
        foreach ((array)$rows as $r) {
            $cid = (int)($r['cliente_user_id'] ?? 0);
            $lc = (string)($r['local_codigo'] ?? '');
            foreach ($alm_map as $alm) {
                if ($alm['id'] === $cid && $alm['local_codigo'] === $lc) {
                    $conflicts[] = [
                        'tipo' => 'Cliente asociado a su propio local',
                        'almacen_id' => $alm['id'],
                        'almacen_name' => $alm['name'],
                        'cliente_id' => $cid,
                        'cliente_name' => $alm['name'],
                        'local_codigo' => $lc,
                    ];
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>Conflictos de doble rol</h1>';

        if (empty($conflicts)) {
            echo '<p>No se detectaron conflictos.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Tipo</th><th>Almacén</th><th>Cliente</th><th>Local</th><th>Acción sugerida</th></tr></thead><tbody>';
        foreach ($conflicts as $c) {
            echo '<tr>';
            echo '<td>' . esc_html($c['tipo']) . '</td>';
            echo '<td>' . esc_html($c['almacen_name'] . ' (#' . $c['almacen_id'] . ')') . '</td>';
            echo '<td>' . esc_html($c['cliente_name'] . ' (#' . $c['cliente_id'] . ')') . '</td>';
            echo '<td>' . esc_html($c['local_codigo'] ?: '—') . '</td>';
            echo '<td>Regularizar por admin / revisar</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}
