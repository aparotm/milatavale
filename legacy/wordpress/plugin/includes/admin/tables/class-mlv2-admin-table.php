<?php
if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class MLV2_Admin_Table extends WP_List_Table {

    private string $scope;
    private array $almacen_name_cache = [];

    public function __construct(string $scope) {
        $this->scope = $scope;

        parent::__construct([
            'singular' => 'movimiento',
            'plural'   => 'movimientos',
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        return [

            'cb' => '<input type="checkbox" />',
            'id'                 => 'ID',
            'created_at'         => 'Fecha',
            'tipo'              => 'Tipo',
            'detalle_mov'        => 'Detalle',
            'nombre_local'       => 'Nombre Local',
            'comuna'             => 'Comuna',
            'direccion'          => 'Dirección',
            'cliente'            => 'Cliente',
            'rut_cliente'        => 'RUT Cliente',
            'email_cliente'      => 'Email Cliente',
            'telefono_cliente'   => 'Teléfono Cliente',
            'cantidad_latas'     => 'Latas',
            'evidencia'          => 'Evidencia',
            'obs_almacen'        => 'Observaciones Almacenero',
            'ingreso'            => 'Ingreso',
            'gasto'              => 'Gasto',
            'saldo'              => 'Saldo',
            'email_almacenero'   => 'Email Almacenero',
            'rut_almacenero'     => 'RUT Almacenero',
            'telefono_almacenero'=> 'Teléfono Almacenero',
            // (pedido) se elimina la columna "Estado" de la vista/admin.
        ];
    }


    public function get_sortable_columns() {
        return [
            'id'         => ['id', false],
            'created_at' => ['created_at', true],
            'cantidad_latas' => ['cantidad_latas', false],
            'local_codigo' => ['local_codigo', false],
        ];
    }

    public function prepare_items() {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $per_page = isset($_REQUEST['mlv_pp']) ? (int) sanitize_text_field(wp_unslash($_REQUEST['mlv_pp'])) : 20;
        if ($per_page < 20) $per_page = 20;
        if ($per_page > 1000) $per_page = 1000;
        $paged    = max(1, (int) $this->get_pagenum());
        $offset   = ($paged - 1) * $per_page;

        [$where, $params] = MLV2_Admin_Query::build_where($this->scope, $_REQUEST);

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'created_at';
        $order   = isset($_REQUEST['order']) ? strtoupper(sanitize_key($_REQUEST['order'])) : 'DESC';
        if (!in_array($orderby, ['id','created_at','local_codigo','cantidad_latas'], true)) $orderby = 'created_at';
        if (!in_array($order, ['ASC','DESC'], true)) $order = 'DESC';

        $sql_count = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $sql_items = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare($sql_count, ...$params));
            $items_params = array_merge($params, [$per_page, $offset]);
            $rows = $wpdb->get_results($wpdb->prepare($sql_items, ...$items_params), ARRAY_A);
        } else {
            $total = (int) $wpdb->get_var($sql_count);
            $rows  = $wpdb->get_results($wpdb->prepare($sql_items, $per_page, $offset), ARRAY_A);
        }

        $this->items = is_array($rows) ? $rows : [];

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    private function decode_detalle(array $item): array {
        if (empty($item['detalle'])) return [];
        $d = json_decode((string)$item['detalle'], true);
        return is_array($d) ? $d : [];
    }

    private function is_incentivo_item(array $item): bool {
        $origen = (string)($item['origen_saldo'] ?? '');
        if ($origen === 'incentivo') return true;
        $detalle = $this->decode_detalle($item);
        return !empty($detalle['incentivo']);
    }

    private function get_incentivo_batch_id(array $item): string {
        $batch = trim((string)($item['incentivo_batch_id'] ?? ''));
        if ($batch !== '') return $batch;
        $detalle = $this->decode_detalle($item);
        $from_detalle = (string)($detalle['incentivo']['batch_id'] ?? '');
        return trim($from_detalle);
    }

    private function is_gasto_item(array $item): bool {
        if (class_exists('MLV2_Movimientos')) {
            return MLV2_Movimientos::is_gasto_row($item);
        }
        return ((int)($item['monto_calculado'] ?? 0) < 0);
    }

    private function is_reajuste_item(array $item): bool {
        $clasificacion = (string)($item['clasificacion_mov'] ?? '');
        if ($clasificacion === 'correccion') return true;

        $detalle = $this->decode_detalle($item);
        if (!empty($detalle['ajuste'])) return true;

        return false;
    }

    
    private function get_creator_user(int $user_id): ?WP_User {
        if ($user_id <= 0) return null;
        $u = get_userdata($user_id);
        return ($u instanceof WP_User) ? $u : null;
    }

    private function get_user_meta_cached(int $user_id, string $key): string {
        static $cache = [];
        if ($user_id <= 0) return '';
        $ck = $user_id . ':' . $key;
        if (isset($cache[$ck])) return $cache[$ck];
        $v = (string) get_user_meta($user_id, $key, true);
        $cache[$ck] = $v;
        return $v;
    }

    private function get_local_field_from_creator(int $creator_user_id, string $field_key): string {
        // field_key: mlv_local_nombre, mlv_local_comuna, mlv_local_direccion, mlv_local_hours, mlv_telefono
        return $this->get_user_meta_cached($creator_user_id, $field_key);
    }

    private function format_money(int $m): string {
        if ($m === 0) return '—';
        $sign = ($m < 0) ? '-' : '';
        return $sign . '$' . number_format_i18n(abs($m));
    }

    public function column_created_at($item) {
        $dt = (string)($item['created_at'] ?? '');
        if (!$dt) return '—';
        return esc_html(MLV2_Time::format_mysql_datetime($dt, 'Y-m-d H:i'));
    }

    public function column_id($item) {
        $id = (int)($item['id'] ?? 0);
        if ($id <= 0) {
            return '—';
        }

        $view = isset($_GET['mlv_view']) ? sanitize_text_field(wp_unslash($_GET['mlv_view'])) : '';
        $actions = [];
        $back = wp_get_referer();
        if (!$back) {
            $back = admin_url('admin.php?page=' . sanitize_key($_GET['page'] ?? 'mlv2_movimientos'));
        }

        // Acción extra: editar monto (solo en activos, y principalmente para gastos)
        if ($view !== 'trash') {
            $is_gasto = $this->is_gasto_item($item);
            if ($is_gasto) {
                $edit_url = add_query_arg([
                    'page' => 'mlv2_edit_movimiento',
                    'mov_id' => $id,
                ], admin_url('admin.php'));
                $actions['edit'] = '<a href="' . esc_url($edit_url) . '">Editar monto</a>';
            }

            // Reversa contable (trazabilidad)
            $rev_url = add_query_arg([
                'page' => 'mlv2_reverse_movimiento',
                'mov_id' => $id,
            ], admin_url('admin.php'));
            $actions['reverse'] = '<a href="' . esc_url($rev_url) . '">Reversar</a>';

            $batch_id = $this->get_incentivo_batch_id($item);
            if ($this->is_incentivo_item($item) && $batch_id !== '') {
                $rev_batch_url = add_query_arg([
                    'page' => 'mlv2_reverse_incentivo_batch',
                    'batch_id' => $batch_id,
                ], admin_url('admin.php'));
                $actions['reverse_batch'] = '<a href="' . esc_url($rev_batch_url) . '">Reversar lote</a>';
            }
        }

        if ($view === 'trash') {
            $url = add_query_arg([
                'action' => 'mlv2_restore_movimiento',
                'mov_id' => $id,
            ], admin_url('admin-post.php'));
            $url = wp_nonce_url($url, 'mlv2_restore_movimiento_' . $id);
            $actions['restore'] = '<a href="' . esc_url($url) . '">Restaurar</a>';
        } else {
            // Papelera solo en modo emergencia explícito
            if (isset($_GET['mlv_emergency']) && $_GET['mlv_emergency'] === '1') {
                $url = add_query_arg([
                    'action' => 'mlv2_trash_movimiento',
                    'mov_id' => $id,
                ], admin_url('admin-post.php'));
                $url = wp_nonce_url($url, 'mlv2_trash_movimiento_' . $id);
                $actions['trash'] = '<a href="' . esc_url($url) . '">Mover a papelera</a>';
            }
        }

        return (string)$id . $this->row_actions($actions);
    }

    public function column_nombre_local($item) {
        $creator = (int)($item['created_by_user_id'] ?? 0);
        $name = $this->get_local_field_from_creator($creator, 'mlv_local_nombre');
        if ($name === '') {
            $code = (string)($item['local_codigo'] ?? '');
            $name = $this->get_almacen_nombre_by_local($code);
        }
        return $name !== '' ? esc_html($name) : '—';
    }

    public function column_comuna($item) {
        $creator = (int)($item['created_by_user_id'] ?? 0);
        $v = $this->get_local_field_from_creator($creator, 'mlv_local_comuna');
        return $v !== '' ? esc_html($v) : '—';
    }

    public function column_direccion($item) {
        $creator = (int)($item['created_by_user_id'] ?? 0);
        $v = $this->get_local_field_from_creator($creator, 'mlv_local_direccion');
        return $v !== '' ? esc_html($v) : '—';
    }

    public function column_email_cliente($item) {
        $uid = (int)($item['cliente_user_id'] ?? 0);
        if ($uid <= 0) return '—';
        $u = get_userdata($uid);
        if (!$u) return '—';
        $email = (string)$u->user_email;
        if ($email === '') return '—';
        return '<a href="mailto:' . esc_attr($email) . '" target="_blank" rel="noopener">' . esc_html($email) . '</a>';
    }

    public function column_telefono_cliente($item) {
        $uid = (int)($item['cliente_user_id'] ?? 0);
        $tel = $uid > 0 ? (string)get_user_meta($uid,'mlv_telefono',true) : (string)($item['cliente_telefono'] ?? '');
        $tel = trim($tel);
        if ($tel === '') return '—';
        $digits = preg_replace('/\D+/', '', $tel);
        // Chile default 56 if not present
        if ($digits !== '' && strpos($digits, '56') !== 0) $digits = '56' . $digits;
        $wa = 'https://wa.me/' . $digits;
        return '<a href="' . esc_url($wa) . '" target="_blank" rel="noopener">' . esc_html($tel) . '</a>';
    }

    
    public function column_ingreso($item) {
        $m = (int)($item['monto_calculado'] ?? 0);
        $tipo = (string)($item['tipo'] ?? '');
        if ($tipo === 'ingreso' || $m > 0) {
            return $this->format_money($m);
        }
        return '—';
    }

    public function column_gasto($item) {
        $m = (int)($item['monto_calculado'] ?? 0);
        $tipo = (string)($item['tipo'] ?? '');
        if ($tipo === 'gasto' || $m < 0) {
            return $this->format_money(abs($m));
        }
        // fallback: si el monto está en 0 pero el detalle dice "gasto", mostrar el monto del detalle
        $detalle = [];
        if (!empty($item['detalle'])) {
            $d = json_decode((string)$item['detalle'], true);
            if (is_array($d)) $detalle = $d;
        }
        $m2 = 0;
        if (isset($detalle['gasto']['monto'])) $m2 = (int)$detalle['gasto']['monto'];
        if ($m2 > 0) {
            return $this->format_money($m2);
        }
        return '—';
    }

    public function column_saldo($item) {
        $cliente_id = (int)($item['cliente_user_id'] ?? 0);
        if ($cliente_id <= 0) return '—';
        $saldo = (int)get_user_meta($cliente_id, 'mlv_saldo', true);
        return $this->format_money($saldo);
    }

public function column_monto($item) {
        $m = (int)($item['monto_calculado'] ?? 0);
        if ($m === 0) {
            // Mostrar monto estimado desde el registro (sin esperar consolidación).
            $latas = (int)($item['cantidad_latas'] ?? 0);
            if ($latas > 0 && class_exists('MLV2_Pricing') && method_exists('MLV2_Pricing','calcular_monto_por_latas')) {
                $m = (int) MLV2_Pricing::calcular_monto_por_latas($latas);
            }
        }
        return $this->format_money($m);
    }

    public function column_email_almacenero($item) {
        $uid = (int)($item['created_by_user_id'] ?? 0);
        if ($uid <= 0) return '—';
        $u = get_userdata($uid);
        if (!$u) return '—';
        $email = (string)$u->user_email;
        if ($email === '') return '—';
        return '<a href="mailto:' . esc_attr($email) . '" target="_blank" rel="noopener">' . esc_html($email) . '</a>';
    }

    public function column_telefono_almacenero($item) {
        $uid = (int)($item['created_by_user_id'] ?? 0);
        $tel = $uid > 0 ? (string)get_user_meta($uid,'mlv_telefono',true) : '';
        $tel = trim($tel);
        if ($tel === '') return '—';
        $digits = preg_replace('/\D+/', '', $tel);
        if ($digits !== '' && strpos($digits, '56') !== 0) $digits = '56' . $digits;
        $wa = 'https://wa.me/' . $digits;
        return '<a href="' . esc_url($wa) . '" target="_blank" rel="noopener">' . esc_html($tel) . '</a>';
    }

    private function tipo_registro_por_usuario(int $user_id): string {
        if ($user_id <= 0) return '—';
        $u = get_userdata($user_id);
        if (!$u || empty($u->roles)) return '—';

        if (in_array('um_almacen', (array)$u->roles, true)) return 'Almacén';
        if (in_array('um_gestor', (array)$u->roles, true)) return 'Gestor';
        if (in_array('um_cliente', (array)$u->roles, true)) return 'Cliente';
        if (in_array('administrator', (array)$u->roles, true)) return 'Admin';

        return '—';
    }

    private function get_almacen_nombre_by_local(string $local_codigo): string {
        $local_codigo = trim($local_codigo);
        if ($local_codigo === '') return '—';
        if (isset($this->almacen_name_cache[$local_codigo])) return $this->almacen_name_cache[$local_codigo];

        $q = new WP_User_Query([
            'role'   => 'um_almacen',
            'number' => 1,
            'meta_query' => [
                [
                    'key'     => 'mlv_local_codigo',
                    'value'   => $local_codigo,
                    'compare' => '=',
                ],
            ],
            'fields' => ['ID','display_name','user_login'],
        ]);
        $users = $q->get_results();
        if (is_array($users) && !empty($users)) {
            $u = $users[0];
            $nombre = (string) get_user_meta((int)$u->ID, 'mlv_local_nombre', true);
            $nombre = trim($nombre);
            if ($nombre === '') $nombre = $u->display_name ?: $u->user_login;
            $this->almacen_name_cache[$local_codigo] = $nombre;
            return $nombre;
        }

        $this->almacen_name_cache[$local_codigo] = '—';
        return '—';
    }

    public function column_tipo_registro($item) {
        $uid = (int)($item['created_by_user_id'] ?? 0);
        return esc_html($this->tipo_registro_por_usuario($uid));
    }

    public function column_almacen_nombre($item) {
        $local = (string)($item['local_codigo'] ?? '');
        return esc_html($this->get_almacen_nombre_by_local($local));
    }

    public function column_creado_por_nombre($item) {
        $uid = (int)($item['created_by_user_id'] ?? 0);
        if ($uid <= 0) return '—';
        $u = get_userdata($uid);
        return esc_html($u ? ($u->display_name ?: $u->user_login) : ('User #' . $uid));
    }

    public function column_cliente($item) {
        $uid = (int)($item['cliente_user_id'] ?? 0);

        if ($uid > 0) {
            $u = get_userdata($uid);
            $name = $u ? ($u->display_name ?: $u->user_login) : ('User #' . $uid);
            $url = esc_url(admin_url('user-edit.php?user_id=' . $uid));
            return '<a href="' . $url . '">' . esc_html($name) . '</a>';
        }

        // Sin usuario asociado, mostramos el RUT si viene en la fila.
        $rut = (string)($item['cliente_rut'] ?? '');
        $rutf = class_exists('MLV2_RUT') ? MLV2_RUT::format($rut) : $rut;
        return esc_html($rutf ?: '—');
    }

    public function column_rut_cliente($item) {
        $uid = (int)($item['cliente_user_id'] ?? 0);
        $rut = (string)($item['cliente_rut'] ?? '');
        if ($rut === '' && $uid > 0) {
            $rut = (string) get_user_meta($uid, 'mlv_rut', true);
            if ($rut === '') { $rut = (string) get_user_meta($uid, 'mlv_rut_norm', true); }
        }
        $rutf = class_exists('MLV2_RUT') ? MLV2_RUT::format($rut) : $rut;
        return $rutf !== '' ? esc_html($rutf) : '—';
    }

    public function column_rut_almacenero($item) {
        $uid = (int)($item['created_by_user_id'] ?? 0);
        if ($uid <= 0) return '—';
        $rut = (string) get_user_meta($uid, 'mlv_rut', true);
        if ($rut === '') { $rut = (string) get_user_meta($uid, 'mlv_rut_norm', true); }
        $rutf = class_exists('MLV2_RUT') ? MLV2_RUT::format($rut) : $rut;
        return $rutf !== '' ? esc_html($rutf) : '—';
    }

    public function column_monto_sugerido($item) {
        $latas = (int)($item['cantidad_latas'] ?? 0);
        $monto = MLV2_Pricing::calcular_monto_por_latas($latas);
        return $monto > 0 ? ('$' . number_format_i18n($monto)) : '—';
    }

    public function column_obs_almacen($item) {
        $detalle = $this->decode_detalle($item);

        // Compat: algunas versiones guardan 'observacion_almacen' o 'observaciones_almacen'
        $obs = '';
        if (!empty($detalle['observaciones_almacen'])) $obs = (string)$detalle['observaciones_almacen'];
        if ($obs === '' && !empty($detalle['observacion_almacen'])) $obs = (string)$detalle['observacion_almacen'];

        // Compat: a veces queda dentro de 'declarado'
        if ($obs === '' && !empty($detalle['declarado']['observacion'])) $obs = (string)$detalle['declarado']['observacion'];

        $obs = trim($obs);
        return $obs !== '' ? esc_html($obs) : '—';
    }

    public function column_evidencia($item) {
        $detalle = $this->decode_detalle($item);
        $url = class_exists('MLV2_Movimientos') ? MLV2_Movimientos::extract_evidencia_url($detalle) : '';

        if ($url === '') return '—';

        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Ver</a>';
    }


    public function column_latas_validadas($item) {
        $detalle = $this->decode_detalle($item);

        if (!empty($detalle['']['cantidad_latas'])) return (int)$detalle['']['cantidad_latas'];

        return (int)($item['cantidad_latas'] ?? 0);
    }

    public function column_monto_validado($item) {
        if (($item['estado'] ?? '') === '') {
            $m = (int)($item['monto_calculado'] ?? 0);
            return $m > 0 ? ('$' . number_format_i18n($m)) : '—';
        }
        return '—';
    }

    /**
     * ✅ Consolidado solo cuando admin valida.
     */
    public function column_monto_consolidado($item) {
        if (($item['estado'] ?? '') !== '') return '—';
        $m = (int)($item['monto_calculado'] ?? 0);
        return $m > 0 ? ('$' . number_format_i18n($m)) : '—';
    }

        public function column_acciones($item) {
    // Admin es solo lectura.
    return '—';
}


    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? esc_html((string)$item[$column_name]) : '—';
    }

    public function no_items() {
        echo 'No hay movimientos.';
    }


    public function column_total_cliente($item) {
        return $this->column_saldo($item);
    }


    public function column_estado($item) {
        if ($this->is_gasto_item($item)) return '—';
    $raw = (string)($item['estado'] ?? '');
    $estado = strtolower($raw);

    // Normalización: solo 2 estados visibles
    if (in_array($estado, ['retirado','aplicado'], true)) {
        return 'Retirado';
    }

    if (in_array($estado, ['pendiente_retiro','','','pendiente','pendiente de retiro'], true)) {
        return 'Pendiente de Retiro';
    }

    // fallback: cualquier otro estado se considera pendiente
    return 'Pendiente de Retiro';
}



public function column_tipo($item) {
        if ($this->is_incentivo_item($item)) return 'Incentivo';
        if ($this->is_reajuste_item($item)) return 'Reajuste';
        if ((string)($item['clasificacion_mov'] ?? '') === 'regularizacion_historica') return 'Regularización';
        return $this->is_gasto_item($item) ? 'Gasto' : 'Registro';
    }

    public function column_detalle_mov($item) {
        $detalle = $this->decode_detalle((array)$item);
        if (!empty($detalle['incentivo'])) {
            $tipo = (string)($detalle['incentivo']['tipo'] ?? '');
            $motivo = (string)($detalle['incentivo']['motivo'] ?? '');
            $extra = trim($tipo . ($motivo !== '' ? ' — ' . $motivo : ''));
            return esc_html($extra !== '' ? 'Incentivo: ' . $extra : 'Incentivo');
        }
        if (!empty($detalle['regularizacion'])) {
            $motivo = (string)($detalle['regularizacion']['motivo'] ?? '');
            return $motivo !== '' ? esc_html('Regularización: ' . $motivo) : 'Regularización';
        }
        if (!empty($detalle['ajuste'])) {
            $motivo = (string)($detalle['ajuste']['motivo'] ?? '');
            return $motivo !== '' ? esc_html('Ajuste: ' . $motivo) : 'Ajuste';
        }
        return '—';
    }
    protected function column_cb( $item ) {
        return '<input type="checkbox" name="mov_ids[]" value="' . esc_attr( $item['id'] ) . '" />';
    }

    protected function get_bulk_actions() {
        return [
            'trash'   => 'Mover a papelera',
            'restore' => 'Restaurar',
            'delete'  => 'Borrar definitivamente',
        ];
    }
}
