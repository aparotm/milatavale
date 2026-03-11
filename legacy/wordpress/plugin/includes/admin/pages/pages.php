<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Pages {

    public static function render_movimientos(string $scope, string $title): void {
        // Guard anti-duplicado
        static $rendered = [
            'pendiente_retiro' => 'Pendiente de retiro',
            'retirado' => 'Retirado',
        ];
        $key = 'mov_' . $scope . '_' . ($_GET['page'] ?? '');
        if (!empty($rendered[$key])) { return; }
        $rendered[$key] = true;

        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

        $kpis = MLV2_Admin_Query::get_kpis($scope, $_REQUEST);
        $table = new MLV2_Admin_Table($scope);
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($title) . '</h1>';

        $mlv_msg = isset($_GET['mlv_msg']) ? sanitize_text_field(wp_unslash($_GET['mlv_msg'])) : '';
        if ($mlv_msg === 'reversa_ok') {
            echo '<div class="notice notice-success"><p>Reversa registrada correctamente.</p></div>';
        } elseif ($mlv_msg === 'reversa_lote_ok') {
            echo '<div class="notice notice-success"><p>Reversa de lote registrada correctamente.</p></div>';
        } elseif ($mlv_msg === 'monto_ok') {
            echo '<div class="notice notice-success"><p>Monto actualizado correctamente.</p></div>';
        } elseif ($mlv_msg === 'regularizacion_ok') {
            echo '<div class="notice notice-success"><p>Regularización histórica registrada correctamente.</p></div>';
        }

        $mlv_err = isset($_GET['mlv_err']) ? sanitize_text_field(wp_unslash($_GET['mlv_err'])) : '';
        if ($mlv_err !== '') {
            $err_map = [
                'motivo_reversa' => 'Debes ingresar un motivo para la reversa.',
                'reversa_existente' => 'El movimiento ya tiene una reversa activa.',
                'reversa_error' => 'No se pudo registrar la reversa.',
                'lote_incentivo_no_encontrado' => 'No se encontró un lote de incentivo activo para reversar.',
                'lote_incentivo_ya_reversado' => 'El lote de incentivo ya tiene reversas activas.',
                'reversa_lote_error' => 'Ocurrió un error al reversar el lote de incentivo.',
            ];
            if (isset($err_map[$mlv_err])) {
                echo '<div class="notice notice-error"><p>' . esc_html($err_map[$mlv_err]) . '</p></div>';
            }
        }

        // Vistas: Activos / Papelera
        $view = isset($_GET['mlv_view']) ? sanitize_text_field(wp_unslash($_GET['mlv_view'])) : '';
        $base_url = remove_query_arg(['mlv_view'], (string)wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $url_activos = esc_url( add_query_arg(['mlv_view' => ''], $base_url) );
        $url_trash   = esc_url( add_query_arg(['mlv_view' => 'trash'], $base_url) );

        echo '<div style="margin:6px 0 12px 0;">';
        echo '<a href="' . $url_activos . '" class="' . ($view === 'trash' ? '' : 'current') . '">Activos</a>';
        echo ' | ';
        echo '<a href="' . $url_trash . '" class="' . ($view === 'trash' ? 'current' : '') . '">Papelera</a>';
        echo '</div>';
        echo '<div style="margin:8px 0 12px 0; color:#646970;">Las correcciones se hacen con <strong>Reversar</strong> o <strong>Ajustes contables</strong> para mantener trazabilidad.</div>';

        echo '<div style="display:flex; gap:12px; margin:12px 0; flex-wrap:wrap;">';
        self::kpi_box('Saldo disponible', '$' . number_format_i18n((int)($kpis['saldo'] ?? 0)));
        self::kpi_box('Generado por reciclaje', '$' . number_format_i18n((int)($kpis['ingresos_reciclaje'] ?? 0)));
        self::kpi_box('Generado por incentivos', '$' . number_format_i18n((int)($kpis['ingresos_incentivo'] ?? 0)));
        self::kpi_box('Canjeado', '$' . number_format_i18n((int)($kpis['gastos_totales'] ?? 0)));
        self::kpi_box('Latas', (string)($kpis['total_latas'] ?? 0));
        self::kpi_box('Movimientos', (string)($kpis['movimientos_operacion'] ?? 0));
        echo '</div>';

        echo '<form method="get" style="margin: 12px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page'] ?? '') . '"/>';
        if (!empty($view)) {
            echo '<input type="hidden" name="mlv_view" value="' . esc_attr($view) . '"/>';
        }

        self::filters_movimientos($scope);

        submit_button('Filtrar', 'secondary', '', false);
        echo ' ';

        $export_url = add_query_arg(array_merge($_GET, $_REQUEST, [
            'action' => 'mlv2_export_csv',
            'scope'  => $scope,
        ]), admin_url('admin-post.php'));

        $export_url = wp_nonce_url($export_url, 'mlv2_export_csv');
        echo '<a class="button" href="' . esc_url($export_url) . '">Exportar CSV</a>';
        echo '</form>';

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page'] ?? '') . '"/>';

        if (!empty($view)) {
            echo '<input type="hidden" name="mlv_view" value="' . esc_attr($view) . '"/>';
        }

        foreach (['mlv_view','estado','desde','hasta','cliente_id','local_codigo','gestor_id','legacy_case','obs_contains','mlv_pp'] as $k) {
            if (!empty($_GET[$k])) {
                echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($_GET[$k]))) . '"/>';
            }
        }

        $table->search_box('Buscar', 'mlv2_search');
        $table->display();
        echo '</form>';

        echo '</div>';
    }

    public static function render_usuarios(string $role, string $title): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

        $table = new MLV2_Admin_Users_Table($role);
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($title) . '</h1>';

        echo '<form method="get" style="margin: 12px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page'] ?? '') . '"/>';

        // Almacenes: filtro por comuna. Clientes/Gestores: filtro por local asignado.
        $local  = isset($_GET['local_codigo']) ? sanitize_text_field(wp_unslash($_GET['local_codigo'])) : '';
        $comuna = isset($_GET['comuna']) ? sanitize_text_field(wp_unslash($_GET['comuna'])) : '';
        $desde  = isset($_GET['desde']) ? sanitize_text_field(wp_unslash($_GET['desde'])) : '';
        $hasta  = isset($_GET['hasta']) ? sanitize_text_field(wp_unslash($_GET['hasta'])) : '';

        $label_local = ($role === 'um_almacen') ? 'Comuna' : 'Local Asignado';

        $locales = MLV2_Admin_Query::get_locales_disponibles();
        $locales_labels = MLV2_Admin_Query::get_locales_labels($locales);
        $comunas = MLV2_Admin_Query::get_comunas_disponibles();
echo '<label style="margin-right:6px;">Desde</label>';
        echo '<input type="date" name="desde" value="' . esc_attr($desde) . '" style="margin-right:10px;"/>';
        echo '<label style="margin-right:6px;">Hasta</label>';
        echo '<input type="date" name="hasta" value="' . esc_attr($hasta) . '" style="margin-right:10px;"/>';

        echo '<label style="margin-right:6px;">' . esc_html($label_local) . '</label>';
        if ($role === 'um_almacen') {
            echo '<select name="comuna" style="margin-right:10px; min-width:220px;">';
            echo '<option value="">(Todas)</option>';
            foreach ($comunas as $c) {
                echo '<option value="' . esc_attr($c) . '"' . selected($comuna, $c, false) . '>' . esc_html($c) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<select name="local_codigo" style="margin-right:10px; min-width:220px;">';
            echo '<option value="">(Todos)</option>';
            foreach ($locales as $lc) {
                $nombre = $locales_labels[$lc] ?? 'Local desconocido';
                echo '<option value="' . esc_attr($lc) . '"' . selected($local, $lc, false) . '>' . esc_html($nombre) . '</option>';
            }
            echo '</select>';
        }

        submit_button('Filtrar', 'secondary', '', false);
        echo ' ';
        echo ' ';

        $export_url = add_query_arg(array_merge($_GET, $_REQUEST, [
            'action' => 'mlv2_export_users_csv',
            'role'   => $role,
        ]), admin_url('admin-post.php'));
        $export_url = wp_nonce_url($export_url, 'mlv2_export_users_csv');
        echo '<a class="button" href="' . esc_url($export_url) . '">Exportar CSV</a>';

        echo '</form>';

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page'] ?? '') . '"/>';
        if ($role === 'um_almacen') {
            if ($comuna !== '') {
                echo '<input type="hidden" name="comuna" value="' . esc_attr($comuna) . '"/>';
            }
        } else {
            if ($local !== '') {
                echo '<input type="hidden" name="local_codigo" value="' . esc_attr($local) . '"/>';
            }
        }
        $table->search_box('Buscar', 'mlv2_users_search');
        $table->display();
        echo '</form>';

        echo '</div>';
    }

    private static function kpi_box(string $label, string $value, string $note = ''): void {
        echo '<div style="background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:12px 14px; min-width:160px;">';
        echo '<div style="font-size:12px; color:#646970; margin-bottom:6px;">' . esc_html($label) . '</div>';
        echo '<div style="font-size:22px; font-weight:700;">' . esc_html($value) . '</div>';
        if ($note) {
            echo '<div style="font-size:12px; color:#646970; margin-top:6px; line-height:1.2;">' . esc_html($note) . '</div>';
        }
        echo '</div>';
    }

    private static function filters_movimientos(string $scope): void {
        $estado = isset($_GET['estado']) ? sanitize_text_field(wp_unslash($_GET['estado'])) : 'all';
        $desde  = isset($_GET['desde']) ? sanitize_text_field(wp_unslash($_GET['desde'])) : '';
        $hasta  = isset($_GET['hasta']) ? sanitize_text_field(wp_unslash($_GET['hasta'])) : '';
        $legacy_case = isset($_GET['legacy_case']) ? sanitize_key(wp_unslash($_GET['legacy_case'])) : 'all';
        if ($legacy_case === '') $legacy_case = 'all';
        $obs_contains = isset($_GET['obs_contains']) ? sanitize_text_field(wp_unslash($_GET['obs_contains'])) : '';
        $mlv_pp = isset($_GET['mlv_pp']) ? (int) sanitize_text_field(wp_unslash($_GET['mlv_pp'])) : 20;
        if ($mlv_pp < 20) $mlv_pp = 20;
        if ($mlv_pp > 1000) $mlv_pp = 1000;

        // Etiquetas de negocio (sin exponer nombres internos)
        $estados = [
            'all' => 'Todos',
            'pendiente_retiro' => 'Pendiente de Retiro',
            'retirado' => 'Retirado',
        ];

        echo '<select name="estado" style="margin-right:8px;">';
        foreach ($estados as $k => $lbl) {
            $sel = selected($estado, $k, false);
            echo "<option value=\"" . esc_attr($k) . "\" {$sel}>" . esc_html($lbl) . "</option>";
        }
        echo '</select>';

        echo '<label style="margin-right:6px;">Desde</label>';
        echo '<input type="date" name="desde" value="' . esc_attr($desde) . '" style="margin-right:10px;"/>';

        echo '<label style="margin-right:6px;">Hasta</label>';
        echo '<input type="date" name="hasta" value="' . esc_attr($hasta) . '" style="margin-right:10px;"/>';

        if (in_array($scope, ['all'], true)) {
            $cliente_id = isset($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : 0;
            $clientes = MLV2_Admin_Query::get_clientes_dropdown();
            echo '<select name="cliente_id" style="margin-right:8px; min-width:220px;">';
            echo '<option value="0">Todos los clientes</option>';
            foreach ($clientes as $c) {
                $sel = selected($cliente_id, (int)$c['id'], false);
                echo '<option value="' . esc_attr((string)$c['id']) . '" ' . $sel . '>' . esc_html($c['label']) . '</option>';
            }
            echo '</select>';
        }

        if (in_array($scope, ['all'], true)) {
            $local = isset($_GET['local_codigo']) ? sanitize_text_field(wp_unslash($_GET['local_codigo'])) : '';
            $locales = MLV2_Admin_Query::get_locales_disponibles();
            
        $locales_labels = MLV2_Admin_Query::get_locales_labels($locales);
echo '<select name="local_codigo" style="margin-right:8px; min-width:180px;">';
            echo '<option value="">Todos los locales</option>';
            foreach ($locales as $lc) {
                $sel = selected($local, $lc, false);
                $nombre = $locales_labels[$lc] ?? $lc;
                echo '<option value="' . esc_attr($lc) . '" ' . $sel . '>' . esc_html($nombre) . '</option>';
            }
            echo '</select>';
        }

        if (in_array($scope, ['all'], true)) {
            $gestor_id = isset($_GET['gestor_id']) ? (int) $_GET['gestor_id'] : 0;
            $gestores = MLV2_Admin_Query::get_gestores_dropdown();
            echo '<select name="gestor_id" style="margin-right:8px; min-width:200px;">';
            echo '<option value="0">Todos los gestores</option>';
            foreach ($gestores as $g) {
                $sel = selected($gestor_id, (int)$g['id'], false);
                echo '<option value="' . esc_attr((string)$g['id']) . '" ' . $sel . '>' . esc_html($g['label']) . '</option>';
            }
            echo '</select>';
        }

        if (in_array($scope, ['all'], true)) {
            echo '<select name="legacy_case" style="margin-right:8px; min-width:220px;">';
            echo '<option value="all"' . selected($legacy_case, 'all', false) . '>Todos los casos</option>';
            echo '<option value="incentivo_simulado"' . selected($legacy_case, 'incentivo_simulado', false) . '>Legacy: incentivo simulado en latas</option>';
            echo '</select>';

            echo '<input type="text" name="obs_contains" value="' . esc_attr($obs_contains) . '" placeholder="Buscar en observación/detalle" style="margin-right:8px; min-width:240px;" />';
        }

        echo '<select name="mlv_pp" style="margin-right:8px; min-width:170px;">';
        $pp_opts = [20,50,100,200,500,1000];
        foreach ($pp_opts as $pp) {
            echo '<option value="' . (int)$pp . '"' . selected($mlv_pp, $pp, false) . '>Filas: ' . (int)$pp . '</option>';
        }
        echo '</select>';
    }
}
