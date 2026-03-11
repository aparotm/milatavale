<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Query {

    /**
     * $scope:
     *  - 'all' (Movimientos)
     *  - 'almacenes'
     *  - 'clientes'
     *  - 'gestores'
     */
    public static function build_where(string $scope, array $req): array {
        global $wpdb;

        $where = "1=1";
        $params = [];

        // Papelera: por defecto ocultamos movimientos eliminados.
        $view = isset($req['mlv_view']) ? sanitize_text_field(wp_unslash($req['mlv_view'])) : '';
        if ($view === 'trash') {
            $where .= " AND deleted_at IS NOT NULL";
        } else {
            $where .= " AND deleted_at IS NULL";
        }

        if ($scope === 'almacenes') {
            $ids = self::get_user_ids_by_role('um_almacen');
            if ($ids) {
                $where .= " AND created_by_user_id IN (" . implode(',', array_map('intval', $ids)) . ")";
            } else {
                $where .= " AND 1=0";
            }
        } elseif ($scope === 'gestores') {
            $where .= " AND 1=0"; // (no se usa esta vista como movimientos)
        } elseif ($scope === 'clientes') {
            $where .= " AND cliente_user_id > 0";
        }

        $estado = isset($req['estado']) ? sanitize_text_field(wp_unslash($req['estado'])) : '';
        if ($estado !== '' && $estado !== 'all') {
            $where .= " AND estado = %s";
            $params[] = $estado;
        }

        $desde = isset($req['desde']) ? sanitize_text_field(wp_unslash($req['desde'])) : '';
        $hasta = isset($req['hasta']) ? sanitize_text_field(wp_unslash($req['hasta'])) : '';

        if ($desde !== '') {
            $where .= " AND created_at >= %s";
            $params[] = $desde . " 00:00:00";
        }
        if ($hasta !== '') {
            $where .= " AND created_at <= %s";
            $params[] = $hasta . " 23:59:59";
        }

        $cliente_id = isset($req['cliente_id']) ? (int) $req['cliente_id'] : 0;
        if ($cliente_id > 0 && in_array($scope, ['all','clientes'], true)) {
            $where .= " AND cliente_user_id = %d";
            $params[] = $cliente_id;
        }

        $local = isset($req['local_codigo']) ? sanitize_text_field(wp_unslash($req['local_codigo'])) : '';
        if ($local !== '' && in_array($scope, ['all','almacenes'], true)) {
            $where .= " AND local_codigo = %s";
            $params[] = $local;
        }

        $search = isset($req['s']) ? sanitize_text_field(wp_unslash($req['s'])) : '';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (cliente_rut LIKE %s OR local_codigo LIKE %s OR estado LIKE %s OR detalle LIKE %s)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // Filtro para detectar casos legacy por observaciones/detalle.
        $legacy_case = isset($req['legacy_case']) ? sanitize_key(wp_unslash($req['legacy_case'])) : '';
        if ($legacy_case !== '' && $legacy_case !== 'all') {
            if ($legacy_case === 'incentivo_simulado') {
                $where .= " AND (
                    detalle LIKE %s
                    OR detalle LIKE %s
                    OR detalle LIKE %s
                    OR detalle LIKE %s
                    OR detalle LIKE %s
                )";
                $params[] = '%' . $wpdb->esc_like('incentivos pasados') . '%';
                $params[] = '%' . $wpdb->esc_like('gasto pasado') . '%';
                $params[] = '%' . $wpdb->esc_like('premio') . '%';
                $params[] = '%' . $wpdb->esc_like('latas pasadas') . '%';
                $params[] = '%' . $wpdb->esc_like('ajuste latas por incentivos') . '%';
            }
        }

        $obs_contains = isset($req['obs_contains']) ? sanitize_text_field(wp_unslash($req['obs_contains'])) : '';
        if ($obs_contains !== '') {
            $where .= " AND detalle LIKE %s";
            $params[] = '%' . $wpdb->esc_like($obs_contains) . '%';
        }

        return [$where, $params];
    }

    public static function get_kpis(string $scope, array $req): array {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        [$where, $params] = self::build_where($scope, $req);

        $sql = "
            SELECT
                COUNT(*) AS total_registros,
                COALESCE(SUM(CASE WHEN clasificacion_mov='operacion' AND NOT (origen_saldo='incentivo' AND monto_calculado>0) THEN 1 ELSE 0 END),0) AS movimientos_operacion,
                COALESCE(SUM(CASE WHEN monto_calculado > 0 AND origen_saldo='reciclaje' AND clasificacion_mov='operacion' THEN cantidad_latas ELSE 0 END),0) AS total_latas,
                COALESCE(SUM(CASE WHEN monto_calculado > 0 AND origen_saldo='reciclaje' AND clasificacion_mov='operacion' THEN monto_calculado ELSE 0 END),0) AS ingresos_reciclaje,
                COALESCE(SUM(CASE WHEN monto_calculado > 0 AND origen_saldo='incentivo' AND clasificacion_mov='operacion' THEN monto_calculado ELSE 0 END),0) AS ingresos_incentivo,
                COALESCE(SUM(CASE WHEN monto_calculado < 0 AND clasificacion_mov='operacion' THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos_totales,
                COALESCE(SUM(CASE WHEN clasificacion_mov='regularizacion_historica' THEN monto_calculado ELSE 0 END),0) AS regularizaciones,
                COALESCE(SUM(CASE WHEN clasificacion_mov='correccion' THEN monto_calculado ELSE 0 END),0) AS correcciones,
                COALESCE(SUM(monto_calculado),0) AS saldo
            FROM {$table}
            WHERE {$where}
        ";

        if ($params) {
            $row = $wpdb->get_row($wpdb->prepare($sql, ...$params), ARRAY_A);
        } else {
            $row = $wpdb->get_row($sql, ARRAY_A);
        }

        return [
            // Se mantiene por compatibilidad, aunque no se muestre siempre.
            'total_registros'  => (int)($row['total_registros'] ?? 0),
            'movimientos_operacion' => (int)($row['movimientos_operacion'] ?? 0),
            'total_latas'      => (int)($row['total_latas'] ?? 0),
            'ingresos_reciclaje' => (int)($row['ingresos_reciclaje'] ?? 0),
            'ingresos_incentivo' => (int)($row['ingresos_incentivo'] ?? 0),
            'gastos_totales'   => (int)($row['gastos_totales'] ?? 0),
            'regularizaciones' => (int)($row['regularizaciones'] ?? 0),
            'correcciones'     => (int)($row['correcciones'] ?? 0),
            'saldo'            => (int)($row['saldo'] ?? 0),
        ];
    }

    public static function get_locales_disponibles(int $limit = 500): array {
        // Solo locales existentes (almacenes activos). Esto evita que el filtro liste códigos históricos ya borrados.
        $users = get_users([
            'role'   => 'um_almacen',
            'fields' => 'ID',
            'number' => $limit,
        ]);

        $codes = [];
        foreach ((array)$users as $uid) {
            $lc = (string) get_user_meta((int)$uid, 'mlv_local_codigo', true);
            if ($lc !== '') $codes[] = $lc;
        }

        $codes = array_values(array_unique(array_map('strval', $codes)));
        sort($codes, SORT_NATURAL);
        return $codes;
    }


    /**
     * Comunas disponibles (según perfiles de almacenes).
     * Se usa para filtros de WP-Admin (Almacenes).
     */
    public static function get_comunas_disponibles(): array {
        $users = get_users([
            'role'   => 'um_almacen',
            'fields' => ['ID'],
            'number' => 5000,
        ]);

        $set = [];
        foreach ((array)$users as $u) {
            $uid = (int)($u->ID ?? 0);
            if ($uid <= 0) { continue; }
            $c = (string) get_user_meta($uid, 'mlv_local_comuna', true);
            $c = trim($c);
            if ($c === '') { continue; }
            $set[$c] = true;
        }

        $comunas = array_keys($set);
        sort($comunas, SORT_NATURAL | SORT_FLAG_CASE);
        return $comunas;
    }
    /**
     * Devuelve un mapa local_codigo => nombre visible (sin mostrar códigos si falta nombre).
     * Usa la meta de almacenes (mlv_local_nombre) como fuente principal.
     */
    public static function get_locales_labels(array $locales_codigos): array {
        $labels = [];

        // 1) Mapear desde almacenes registrados
        $almacenes = get_users([
            'role'   => 'um_almacen',
            'fields' => ['ID', 'display_name', 'user_login'],
            'number' => 5000,
        ]);

        $map = [];
        foreach ((array)$almacenes as $u) {
            $lc = (string) get_user_meta((int)$u->ID, 'mlv_local_codigo', true);
            $lc = trim($lc);
            if ($lc === '') { continue; }

            $nombre = (string) get_user_meta((int)$u->ID, 'mlv_local_nombre', true);
            $nombre = trim($nombre);
            if ($nombre === '') {
                $nombre = trim((string)($u->display_name ?? ''));
            }
            if ($nombre === '') {
                $nombre = 'Local';
            }
            // Primera coincidencia gana
            if (!isset($map[$lc])) {
                $map[$lc] = $nombre;
            }
        }

        // 2) Armar labels para los códigos solicitados
        foreach ((array)$locales_codigos as $lc) {
            $lc = trim((string)$lc);
            if ($lc === '') { continue; }
            $labels[$lc] = $map[$lc] ?? 'Local desconocido';
        }

        return $labels;
    }

    public static function get_clientes_dropdown(int $limit = 500): array {
        $users = get_users([
            'role'   => 'um_cliente',
            'number' => $limit,
            'orderby'=> 'display_name',
            'order'  => 'ASC',
            'fields' => ['ID','display_name'],
        ]);

        $out = [];
        foreach ($users as $u) {
            $uid = (int)$u->ID;
            $rut = (string) get_user_meta($uid, 'mlv_rut', true);
            $rut_fmt = class_exists('MLV2_RUT') ? MLV2_RUT::format($rut) : $rut;

            $saldo = (int) get_user_meta($uid, 'mlv_saldo', true);
            $locales = self::get_locales_for_user($uid);
            if (empty($locales)) {
                $legacy = trim((string) get_user_meta($uid, 'mlv_local_codigo', true));
                if ($legacy !== '') { $locales = [$legacy]; }
            }
            $count_locales = is_array($locales) ? count($locales) : 0;

            $label = trim($u->display_name . ($rut_fmt ? " — {$rut_fmt}" : ''));
            if ($count_locales > 0) {
                $label .= ' (' . $count_locales . ' ' . ($count_locales === 1 ? 'local' : 'locales') . ')';
            }
            $label .= ' ($' . number_format_i18n($saldo) . ')';

            $out[] = ['id' => $uid, 'label' => $label ?: ('Cliente #' . $uid)];
        }
        return $out;
    }

    public static function get_gestores_dropdown(int $limit = 200): array {
        $users = get_users([
            'role'   => 'um_gestor',
            'number' => $limit,
            'orderby'=> 'display_name',
            'order'  => 'ASC',
            'fields' => ['ID','display_name'],
        ]);
        $out = [];
        foreach ($users as $u) {
            $rut = (string) get_user_meta((int)$u->ID, 'mlv_rut', true);
            $rut_f = MLV2_RUT::format($rut);
            $name = $u->display_name ?: ('Gestor #' . (int)$u->ID);
            $label = $rut_f ? ($name . ' — ' . $rut_f) : $name;
            $out[] = ['id' => (int)$u->ID, 'label' => $label];
        }
        return $out;
    }



    /**
     * Locales asociados a un usuario (clientes/gestores) vía tabla N-N.
     */
    public static function get_locales_for_user(int $user_id): array {
        global $wpdb;
        $user_id = (int)$user_id;
        if ($user_id <= 0) return [];

        $table = MLV2_DB::table_clientes_almacenes();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT local_codigo FROM {$table} WHERE cliente_user_id=%d ORDER BY local_codigo ASC",
            $user_id
        ));
        $out = [];
        foreach ((array)$rows as $lc) {
            $lc = trim((string)$lc);
            if ($lc !== '') $out[] = $lc;
        }
        return $out;
    }

    /**
     * Usuarios (clientes/gestores) asociados a un local vía tabla N-N.
     */
    public static function get_user_ids_by_local(string $local_codigo): array {
        global $wpdb;
        $local_codigo = trim((string)$local_codigo);
        if ($local_codigo === '') return [];

        $table = MLV2_DB::table_clientes_almacenes();
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT cliente_user_id FROM {$table} WHERE local_codigo=%s",
            $local_codigo
        ));
        return array_values(array_filter(array_map('intval', (array)$ids)));
    }
    private static function get_user_ids_by_role(string $role): array {
        $users = get_users([
            'role'   => $role,
            'fields' => 'ID',
            'number' => 5000,
        ]);
        return array_map('intval', is_array($users) ? $users : []);
    }
}
