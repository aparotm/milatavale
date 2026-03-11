<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class MLV2_Admin_Users_Table extends WP_List_Table {

    private string $role;

    public function __construct(string $role) {
        $this->role = $role;

        parent::__construct([
            'singular' => 'usuario',
            'plural'   => 'usuarios',
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        if ($this->role === 'um_almacen') {
            return [
                'id'                 => 'ID',
                'registro'            => 'Fecha de registro',
                'local_nombre'        => 'Nombre Local',
                'local_comuna'        => 'Comuna',
                'local_direccion'     => 'Dirección',
                'latas_consolidadas'  => 'Latas',
                'ingresos_totales'    => 'Ingresos Totales',
                'gastos_totales'      => 'Gastos Totales',
                'saldo_consolidado'   => 'Saldo',
                'display_name'        => 'Nombre Completo',
                'rut'                => 'RUT',
                'email'              => 'Email',
                'telefono'           => 'Teléfono',
                'clientes_asignados'  => 'Cantidad de Clientes Asignados',
            ];
        }

        // Clientes y Gestores
        return [
            'id'                => 'ID',
            'registro'           => 'Fecha de registro',
            'display_name'       => 'Nombre Completo',
            'rut'              => 'RUT',
            'email'             => 'Email',
            'telefono'          => 'Teléfono',
            'locales'           => 'Locales',
            'latas_consolidadas' => 'Latas',
            'ingresos_totales'  => 'Ingresos Totales',
            'gastos_totales'    => 'Gastos Totales',
            'saldo_consolidado' => 'Saldo',
        ];
    }


    protected function get_hidden_columns() { return []; }

    public function get_sortable_columns() {
        return [
            'id'        => ['ID', false],
            'registro'  => ['registered', true],
            'display_name' => ['display_name', false],
            'email'     => ['user_email', false],
        ];
    }

    public function prepare_items() {
        $per_page = 30;
        $paged    = max(1, (int) $this->get_pagenum());

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'ID';
        $order   = isset($_REQUEST['order']) ? strtoupper(sanitize_key($_REQUEST['order'])) : 'DESC';
        if (!in_array($order, ['ASC','DESC'], true)) $order = 'DESC';

        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $local_filter  = isset($_REQUEST['local_codigo']) ? sanitize_text_field(wp_unslash($_REQUEST['local_codigo'])) : '';
        $comuna_filter = isset($_REQUEST['comuna']) ? sanitize_text_field(wp_unslash($_REQUEST['comuna'])) : '';
        $desde = isset($_REQUEST['desde']) ? sanitize_text_field(wp_unslash($_REQUEST['desde'])) : '';
        $hasta = isset($_REQUEST['hasta']) ? sanitize_text_field(wp_unslash($_REQUEST['hasta'])) : '';

        $args = [
            'role'    => $this->role,
            'number'  => $per_page,
            'paged'   => $paged,
            'orderby' => $orderby,
            'order'   => $order,
        ];

        if ($search !== '') {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login','user_email','display_name'];
        }

        // Filtros:
        // - Almacenes: por comuna
        // - Clientes / Gestores: por local_codigo
        if ($this->role === 'um_almacen') {
            if ($comuna_filter !== '') {
                $args['meta_query'] = [
                    [
                        'key'     => 'mlv_local_comuna',
                        'value'   => $comuna_filter,
                        'compare' => '=',
                    ],
                ];
            }
        } else {
            if ($local_filter !== '') {
                $ids = MLV2_Admin_Query::get_user_ids_by_local($local_filter);
                // WP_User_Query no soporta JOIN fácil a nuestra tabla N-N; usamos include().
                $args['include'] = $ids ? $ids : [0];
            }
        }

        if ($desde !== '' || $hasta !== '') {
            $dq = ['inclusive' => true];
            if ($desde !== '') { $dq['after'] = $desde . ' 00:00:00'; }
            if ($hasta !== '') { $dq['before'] = $hasta . ' 23:59:59'; }
            $args['date_query'] = [$dq];
        }

        $user_query = new WP_User_Query($args);
        $users = (array) $user_query->get_results();
        $total = (int) $user_query->get_total();

        $items = [];
        foreach ($users as $u) {
            $totals = $this->get_totales_consolidados((int)$u->ID);

            $local_codigo = (string) get_user_meta($u->ID, 'mlv_local_codigo', true);
            $locales_user = ($this->role === 'um_almacen') ? [] : MLV2_Admin_Query::get_locales_for_user((int)$u->ID);
            $local_nombre = (string) get_user_meta($u->ID, 'mlv_local_nombre', true);
            $local_comuna = (string) get_user_meta($u->ID, 'mlv_local_comuna', true);
            $local_dir    = (string) get_user_meta($u->ID, 'mlv_local_direccion', true);

            $items[] = [
                'id' => (int)$u->ID,
                'registro' => $u->user_registered,
                'local_nombre' => $local_nombre,
                'local_comuna' => $local_comuna,
                'local_direccion' => $local_dir,
                'display_name' => $u->display_name,
                'rut' => (string) get_user_meta($u->ID, 'mlv_rut', true),
                'email' => $u->user_email,
                'telefono' => (string) get_user_meta($u->ID, 'mlv_telefono', true),
                'local_codigo' => $local_codigo,
                'locales' => $locales_user,
                'latas_consolidadas' => (int)($totals['latas'] ?? 0),
                'ingresos_totales'   => (int)($totals['ingresos'] ?? 0),
                'gastos_totales'     => (int)($totals['gastos'] ?? 0),
                'saldo_consolidado'  => (int)($totals['saldo'] ?? 0),
                                'clientes_asignados' => ($this->role === 'um_almacen') ? $this->count_clientes_asignados($local_codigo) : 0,
            ];
        }

        $this->items = $items;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);

        $this->_column_headers = [$this->get_columns(), $this->get_hidden_columns(), $this->get_sortable_columns()];
    }

    protected function column_id($item) {
        $id = (int)$item['id'];
        $url = esc_url(admin_url('user-edit.php?user_id=' . $id));
        return '<a href="' . $url . '"><strong>' . esc_html((string)$id) . '</strong></a>';
    }

    protected function column_registro($item) {
        $dt = (string)($item['registro'] ?? '');
        if ($dt === '') return '—';
        return esc_html(MLV2_Time::format_user_registered($dt, 'Y-m-d'));
    }

    protected function column_email($item) {
        $email = (string)($item['email'] ?? '');
        if ($email === '') return '—';
        return '<a href="mailto:' . esc_attr($email) . '" target="_blank" rel="noopener">' . esc_html($email) . '</a>';
    }

    
    protected function column_rut($item) {
        $rut = trim((string)($item['rut'] ?? ''));
        if ($rut === '') return '—';
        if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','format')) {
            $rut = MLV2_RUT::format($rut);
        }
        return esc_html($rut);
    }

protected function column_telefono($item) {
        $tel = trim((string)($item['telefono'] ?? ''));
        if ($tel === '') return '—';
        $digits = preg_replace('/\D+/', '', $tel);
        if ($digits !== '' && strpos($digits, '56') !== 0) $digits = '56' . $digits;
        $wa = 'https://wa.me/' . $digits;
        return '<a href="' . esc_url($wa) . '" target="_blank" rel="noopener">' . esc_html($tel) . '</a>';
    }

    protected function column_monto_consolidado($item) {
        $m = (int)($item['monto_consolidado'] ?? 0);
        return $m > 0 ? ('$' . number_format_i18n($m)) : '—';
    }


    protected function column_saldo($item) {
        $m = (int)($item['saldo'] ?? 0);
        return '$' . esc_html(number_format_i18n($m));
    }

    
    /**
     * ✅ Dropdown que guarda por URL (no submit)
     */
    
    /**
     * Locales asociados (multi-almacén). No es un desplegable.
     * Admin puede desvincular (✕) desde aquí.
     */
    protected function column_locales($item) {
        $user_id = (int)($item['id'] ?? 0);
        if ($user_id <= 0) return '—';

        $locales = $item['locales'] ?? [];
        if (!is_array($locales)) { $locales = []; }
        $locales = array_values(array_filter(array_map('strval', $locales)));

        if (empty($locales)) {
            // Compat/legacy: si no hay relación N-N, mostramos el meta principal si existe.
            $lc = trim((string)($item['local_codigo'] ?? ''));
            if ($lc === '') return '—';
            $labels = MLV2_Admin_Query::get_locales_labels([$lc]);
            return esc_html($labels[$lc] ?? $lc);
        }

        $labels_map = MLV2_Admin_Query::get_locales_labels($locales);

        // Lista informativa (sin acciones)
        $parts = [];
        foreach ($locales as $lc) {
            $parts[] = $labels_map[$lc] ?? $lc;
        }
        return esc_html(implode(', ', $parts));
    }


    protected function column_default($item, $column_name) {
        if (!isset($item[$column_name])) return '—';
        $v = $item[$column_name];

        // formato estándar
        if (in_array($column_name, ['ingresos_totales','gastos_totales','saldo_consolidado'], true)) {
            $n = (int)$v;
            return esc_html('$' . number_format_i18n($n));
        }
        if ($column_name === 'latas_consolidadas') {
            return esc_html(number_format_i18n((int)$v));
        }
        return esc_html((string)$v);
    }

    private function count_clientes_asignados(string $local_codigo): int {
        global $wpdb;
        $local_codigo = trim($local_codigo);
        if ($local_codigo === '') return 0;
        $table = MLV2_DB::table_clientes_almacenes();
        $n = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT cliente_user_id) FROM {$table} WHERE local_codigo=%s",
            $local_codigo
        ));
        return (int)$n;
    }

    function get_totales_consolidados(int $user_id): array {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $user_id = (int)$user_id;
        if ($user_id <= 0) return ['latas' => 0, 'ingresos' => 0, 'gastos' => 0, 'saldo' => 0];

        if ($this->role === 'um_cliente') {
            $sql = $wpdb->prepare(
                "SELECT COALESCE(SUM(cantidad_latas),0) AS latas,
                        COALESCE(SUM(CASE WHEN monto_calculado>0 THEN monto_calculado ELSE 0 END),0) AS ingresos,
                        COALESCE(SUM(CASE WHEN monto_calculado<0 THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos,
                        COALESCE(SUM(monto_calculado),0) AS saldo
                 FROM {$table}
                 WHERE cliente_user_id=%d AND deleted_at IS NULL",
                $user_id
            );
        } elseif ($this->role === 'um_almacen') {
            $sql = $wpdb->prepare(
                "SELECT COALESCE(SUM(cantidad_latas),0) AS latas,
                        COALESCE(SUM(CASE WHEN monto_calculado>0 THEN monto_calculado ELSE 0 END),0) AS ingresos,
                        COALESCE(SUM(CASE WHEN monto_calculado<0 THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos,
                        COALESCE(SUM(monto_calculado),0) AS saldo
                 FROM {$table}
                 WHERE created_by_user_id=%d AND deleted_at IS NULL",
                $user_id
            );
        } else {
            // Gestor: suma por locales asociados (tabla N-N)
            $locals = MLV2_Admin_Query::get_locales_for_user($user_id);
            $locals = array_values(array_filter(array_map('strval', (array)$locals)));
            if ($locals) {
                $placeholders = implode(',', array_fill(0, count($locals), '%s'));
                $sql = $wpdb->prepare(
                    "SELECT COALESCE(SUM(cantidad_latas),0) AS latas,
                        COALESCE(SUM(CASE WHEN monto_calculado>0 THEN monto_calculado ELSE 0 END),0) AS ingresos,
                        COALESCE(SUM(CASE WHEN monto_calculado<0 THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos,
                        COALESCE(SUM(monto_calculado),0) AS saldo
                     FROM {$table}
                     WHERE local_codigo IN ($placeholders) AND deleted_at IS NULL",
                    ...$locals
                );
            } else {
                $sql = "SELECT 0 AS latas, 0 AS monto";
            }
        }

        $row = $wpdb->get_row($sql, ARRAY_A);
        return [
            'latas' => (int)($row['latas'] ?? 0),
            'ingresos' => (int)($row['ingresos'] ?? 0),
            'gastos' => (int)($row['gastos'] ?? 0),
            'saldo' => (int)($row['saldo'] ?? 0),
        ];
    }

    public function no_items() {
        echo 'No hay usuarios.';
    }
}
