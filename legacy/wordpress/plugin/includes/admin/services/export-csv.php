<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Export Movimientos CSV (Admin)
 */
add_action('admin_post_mlv2_export_csv', function() {
    if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
    check_admin_referer('mlv2_export_csv');

    $scope = isset($_GET['scope']) ? sanitize_key($_GET['scope']) : 'all';
    if (!in_array($scope, ['all','almacenes','clientes','gestores'], true)) { $scope = 'all'; }

    global $wpdb;
    $table = MLV2_DB::table_movimientos();

    [$where, $params] = MLV2_Admin_Query::build_where($scope, $_GET);

    // Export CSV siempre ordenado de más antiguo a más nuevo (ignora orden de la tabla)
    $orderby = 'created_at';
    $order   = 'ASC';

    $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at ASC, id ASC";
    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mlv2_movimientos_' . gmdate('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');

    $headers = [
        'Nombre Almacen',
        'Fecha',
        'Rut Cliente',
        'Teléfono Cliente',
        'Cantidad de Latas',
        'evidencia',
        'nombre cliente',
        'apellido cliente',
        'ID',
        'Comuna',
        'Dirección',
        'Email Cliente',
        'Observaciones Almacen',
        'Ingreso',
        'Gasto',
        'Saldo',
        'email almacenero',
        'rut almacén',
        'teléfono almacén',
        'origen_saldo',
        'clasificacion_mov',
        'mov_ref_id',
        'is_system_adjustment',
        'tipo_ajuste',
        'motivo_ajuste',
        'regularizacion_historica',
    ];
    fputcsv($out, $headers);

    $fmt_phone = static function(string $tel): string {
        $tel = trim($tel);
        if ($tel === '') return '';
        // Forzar texto (evita notación científica / truncado en Excel)
        return "\t" . $tel;
    };

    foreach ((array)$rows as $r) {
        $detalle = [];
        if (!empty($r['detalle'])) {
            $d = json_decode((string)$r['detalle'], true);
            if (is_array($d)) { $detalle = $d; }
        }

        $creator_id = (int)($r['created_by_user_id'] ?? 0);
        $cliente_id = (int)($r['cliente_user_id'] ?? 0);

        $local_nombre = $creator_id ? (string)get_user_meta($creator_id,'mlv_local_nombre',true) : '';
        $local_comuna = $creator_id ? (string)get_user_meta($creator_id,'mlv_local_comuna',true) : '';
        $local_dir    = $creator_id ? (string)get_user_meta($creator_id,'mlv_local_direccion',true) : '';

        $cliente_nombre = '—';
        $cliente_first  = '';
        $cliente_last   = '';
        $cliente_rut    = (string)($r['cliente_rut'] ?? '');
        $cliente_email  = '—';
        $cliente_tel    = (string)($r['cliente_telefono'] ?? '');
        if ($cliente_id > 0) {
            $cu = get_userdata($cliente_id);
            if ($cu) {
                $cliente_nombre = $cu->display_name ?: ('Cliente #' . $cliente_id);
                $cliente_email  = (string)$cu->user_email;
            }
            $cliente_first = (string)get_user_meta($cliente_id,'first_name',true);
            $cliente_last  = (string)get_user_meta($cliente_id,'last_name',true);
            $cliente_tel = (string)get_user_meta($cliente_id,'mlv_telefono',true);
            if ($cliente_rut === '') {
                $cliente_rut = (string)get_user_meta($cliente_id,'mlv_rut',true);
                if ($cliente_rut === '') { $cliente_rut = (string)get_user_meta($cliente_id,'mlv_rut_norm',true); }
            }
        } elseif (!empty($r['cliente_rut'])) {
            // Sin usuario asociado, guardamos el RUT en su columna y dejamos el nombre vacío.
            $cliente_nombre = '—';
        $cliente_first  = '';
        $cliente_last   = '';
        }

        $cliente_rut_fmt = class_exists('MLV2_RUT') ? MLV2_RUT::format($cliente_rut) : $cliente_rut;

        $alm_email = '—';
        $alm_rut   = '';
        $alm_tel   = '';
        if ($creator_id > 0) {
            $au = get_userdata($creator_id);
            if ($au) { $alm_email = (string)$au->user_email; }
            $alm_rut = (string)get_user_meta($creator_id,'mlv_rut',true);
            if ($alm_rut === '') { $alm_rut = (string)get_user_meta($creator_id,'mlv_rut_norm',true); }
            $alm_tel = (string)get_user_meta($creator_id,'mlv_telefono',true);
        }

        $alm_rut_fmt = class_exists('MLV2_RUT') ? MLV2_RUT::format($alm_rut) : $alm_rut;

        $evidencia_url = class_exists('MLV2_Movimientos') ? MLV2_Movimientos::extract_evidencia_url($detalle) : '';

        // Mantener criterio consistente con la columna admin "Observaciones Almacenero".
        $obs = '';
        if (!empty($detalle['observaciones_almacen'])) {
            $obs = (string)$detalle['observaciones_almacen'];
        } elseif (!empty($detalle['observacion_almacen'])) {
            $obs = (string)$detalle['observacion_almacen'];
        } elseif (!empty($detalle['obs_almacen'])) {
            $obs = (string)$detalle['obs_almacen'];
        } elseif (!empty($detalle['declarado']['observacion'])) {
            $obs = (string)$detalle['declarado']['observacion'];
        } elseif (!empty($detalle['observacion'])) {
            $obs = (string)$detalle['observacion'];
        } elseif (!empty($detalle['gasto']['observacion'])) {
            $obs = (string)$detalle['gasto']['observacion'];
        }

        $tipo_ajuste = '';
        $motivo_ajuste = '';
        if (!empty($detalle['ajuste'])) {
            $tipo_ajuste = (string)($detalle['ajuste']['tipo'] ?? '');
            $motivo_ajuste = (string)($detalle['ajuste']['motivo'] ?? '');
        }
        $regularizacion_hist = (!empty($detalle['regularizacion_historica']) || (($r['clasificacion_mov'] ?? '') === 'regularizacion_historica')) ? '1' : '0';

        
        $mcalc = class_exists('MLV2_Movimientos') ? MLV2_Movimientos::monto_efectivo($r, $detalle) : (int)($r['monto_calculado'] ?? 0);
        $is_gasto = class_exists('MLV2_Movimientos') ? MLV2_Movimientos::is_gasto_row($r, $detalle) : ($mcalc < 0);
        $ingreso = 0;
        $gasto   = 0;

        if ($is_gasto) {
            $gasto = abs($mcalc);
            if ($gasto === 0 && !empty($detalle['gasto']['monto'])) {
                $gasto = abs((int)$detalle['gasto']['monto']);
            }
        } else {
            $ingreso = $mcalc > 0 ? $mcalc : 0;
        }

        $saldo = 0;
        if ($cliente_id > 0) { $saldo = (int)get_user_meta($cliente_id, 'mlv_saldo', true); }

        $line = [
            $local_nombre,
            MLV2_Time::format_mysql_datetime((string)($r['created_at'] ?? ''), 'Y-m-d H:i'),
            $cliente_rut_fmt,
            $fmt_phone($cliente_tel),
            (int)($r['cantidad_latas'] ?? 0),
            $evidencia_url,
            $cliente_first,
            $cliente_last,
            (int)($r['id'] ?? 0),
            $local_comuna,
            $local_dir,
            $cliente_email,
            $obs,
            $ingreso,
            $gasto,
            $saldo,
            $alm_email,
            $alm_rut_fmt,
            $fmt_phone($alm_tel),
            (string)($r['origen_saldo'] ?? ''),
            (string)($r['clasificacion_mov'] ?? ''),
            (string)($r['mov_ref_id'] ?? ''),
            (string)($r['is_system_adjustment'] ?? ''),
            $tipo_ajuste,
            $motivo_ajuste,
            $regularizacion_hist,
        ];
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
});

/**
 * Export Usuarios CSV (Admin)
 */
add_action('admin_post_mlv2_export_users_csv', function() {
    if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
    check_admin_referer('mlv2_export_users_csv');

    $role = isset($_GET['role']) ? sanitize_key($_GET['role']) : 'um_cliente';
    if (!in_array($role, ['um_cliente','um_almacen','um_gestor'], true)) { $role = 'um_cliente'; }

    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $local  = isset($_GET['local_codigo']) ? sanitize_text_field(wp_unslash($_GET['local_codigo'])) : '';
    $desde  = isset($_GET['desde']) ? sanitize_text_field(wp_unslash($_GET['desde'])) : '';
    $hasta  = isset($_GET['hasta']) ? sanitize_text_field(wp_unslash($_GET['hasta'])) : '';

    $args = [
        'role'   => $role,
        'number' => 5000,
        'orderby'=> 'user_registered',
        'order'  => 'ASC',
    ];

    if ($search !== '') {
        $args['search'] = '*' . $search . '*';
        $args['search_columns'] = ['user_login','display_name','user_email'];
    }

    // Filtro por local: en clientes/gestores se basa en la relación N-N (misma lógica que la tabla wp-admin)
    if ($local !== '' && $role !== 'um_almacen') {
        if (class_exists('MLV2_Admin_Query') && method_exists('MLV2_Admin_Query','get_user_ids_by_local')) {
            $ids = MLV2_Admin_Query::get_user_ids_by_local($local);
            $args['include'] = $ids ? $ids : [0];
        }
    }

    if ($desde !== '' || $hasta !== '') {
        $dq = ['inclusive' => true];
        if ($desde !== '') { $dq['after'] = $desde . ' 00:00:00'; }
        if ($hasta !== '') { $dq['before'] = $hasta . ' 23:59:59'; }
        $args['date_query'] = [$dq];
    }

    $users = get_users($args);

    global $wpdb;
    $mov_table = MLV2_DB::table_movimientos();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mlv2_' . $role . '_' . gmdate('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');

    // Headers per role: deben calzar con las columnas que se ven en wp-admin
    if ($role === 'um_almacen') {
        $headers = ['ID','Fecha de registro','Nombre Local','Comuna','Dirección','Latas','Ingresos Totales','Gastos Totales','Saldo','Nombre Completo','RUT','Email','Teléfono','Cantidad de Clientes Asignados'];
    } else {
        $headers = ['ID','Fecha de registro','Nombre Completo','RUT','Email','Teléfono','Locales','Latas','Ingresos Totales','Gastos Totales','Saldo'];
    }
    fputcsv($out, $headers);

    $fmt_phone = static function(string $tel): string {
        $tel = trim($tel);
        if ($tel === '') return '';
        // Forzar texto (evita notación científica / truncado en Excel)
        return "\t" . $tel;
    };

    foreach ((array)$users as $u) {
        if (!($u instanceof WP_User)) continue;

        // Totales consolidados
        $uid = (int)$u->ID;
        $totals = ['latas' => 0, 'ingresos' => 0, 'gastos' => 0, 'saldo' => 0];
        if ($uid > 0) {
            if ($role === 'um_cliente') {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT COALESCE(SUM(cantidad_latas),0) AS latas,
                            COALESCE(SUM(CASE WHEN monto_calculado>0 THEN monto_calculado ELSE 0 END),0) AS ingresos,
                            COALESCE(SUM(CASE WHEN monto_calculado<0 THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos,
                            COALESCE(SUM(monto_calculado),0) AS saldo
                     FROM {$mov_table} WHERE 1=1 AND cliente_user_id=%d AND deleted_at IS NULL",
                    $uid
                ), ARRAY_A);
                $totals = [
                        'latas' => (int)($row['latas'] ?? 0),
                        'ingresos' => (int)($row['ingresos'] ?? 0),
                        'gastos' => (int)($row['gastos'] ?? 0),
                        'saldo' => (int)($row['saldo'] ?? 0),
                    ];
            } elseif ($role === 'um_almacen') {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT COALESCE(SUM(cantidad_latas),0) AS latas,
                            COALESCE(SUM(CASE WHEN monto_calculado>0 THEN monto_calculado ELSE 0 END),0) AS ingresos,
                            COALESCE(SUM(CASE WHEN monto_calculado<0 THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos,
                            COALESCE(SUM(monto_calculado),0) AS saldo
                     FROM {$mov_table} WHERE 1=1 AND created_by_user_id=%d AND deleted_at IS NULL",
                    $uid
                ), ARRAY_A);
                $totals = [
                        'latas' => (int)($row['latas'] ?? 0),
                        'ingresos' => (int)($row['ingresos'] ?? 0),
                        'gastos' => (int)($row['gastos'] ?? 0),
                        'saldo' => (int)($row['saldo'] ?? 0),
                    ];
            } else {
                $local = trim((string)get_user_meta($uid,'mlv_local_codigo',true));
                if ($local !== '') {
                    $locals = preg_split('/[\s,]+/', $local);
                    $locals = array_values(array_filter(array_map('strval', (array)$locals)));
                    if ($locals) {
                        $ph = implode(',', array_fill(0, count($locals), '%s'));
                        $row = $wpdb->get_row($wpdb->prepare(
                            "SELECT COALESCE(SUM(cantidad_latas),0) AS latas,
                            COALESCE(SUM(CASE WHEN monto_calculado>0 THEN monto_calculado ELSE 0 END),0) AS ingresos,
                            COALESCE(SUM(CASE WHEN monto_calculado<0 THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos,
                            COALESCE(SUM(monto_calculado),0) AS saldo
                             FROM {$mov_table} WHERE 1=1 AND local_codigo IN ($ph) AND deleted_at IS NULL",
                            ...$locals
                        ), ARRAY_A);
                        $totals = [
                        'latas' => (int)($row['latas'] ?? 0),
                        'ingresos' => (int)($row['ingresos'] ?? 0),
                        'gastos' => (int)($row['gastos'] ?? 0),
                        'saldo' => (int)($row['saldo'] ?? 0),
                    ];
                    }
                }
            }
        }

        $local_codigo = (string)get_user_meta($u->ID,'mlv_local_codigo',true);
        $rut = (string)get_user_meta($u->ID,'mlv_rut',true);
        $telefono = (string)get_user_meta($u->ID,'mlv_telefono',true);

        if ($role === 'um_almacen') {
            $local_nombre = (string)get_user_meta($u->ID,'mlv_local_nombre',true);
            $local_comuna = (string)get_user_meta($u->ID,'mlv_local_comuna',true);
            $local_dir    = (string)get_user_meta($u->ID,'mlv_local_direccion',true);

            // Cantidad de clientes asignados (tabla N-N)
            $cant_cli = 0;
            $lc = trim((string)$local_codigo);
            if ($lc !== '' && class_exists('MLV2_DB')) {
                $table_ca = MLV2_DB::table_clientes_almacenes();
                $cant_cli = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT cliente_user_id) FROM {$table_ca} WHERE local_codigo=%s",
                    $lc
                ));
            }

            $line = [
                (int)$u->ID,
                $u->user_registered,
                $local_nombre,
                $local_comuna,
                $local_dir,
                (int)($totals['latas'] ?? 0),
                (int)($totals['ingresos'] ?? 0),
                (int)($totals['gastos'] ?? 0),
                (int)($totals['saldo'] ?? 0),
                $u->display_name,
                $rut,
                $u->user_email,
                $fmt_phone($telefono),
                $cant_cli,
            ];
        } else {
            // Locales asignados (tabla N-N); si no existe la clase helper, exportamos los códigos
            $locales = [];
            if (class_exists('MLV2_Admin_Query') && method_exists('MLV2_Admin_Query','get_locales_for_user')) {
                $locales = MLV2_Admin_Query::get_locales_for_user((int)$u->ID);
            } elseif (class_exists('MLV2_DB')) {
                $table_ca = MLV2_DB::table_clientes_almacenes();
                $locales = $wpdb->get_col($wpdb->prepare(
                    "SELECT local_codigo FROM {$table_ca} WHERE cliente_user_id=%d",
                    (int)$u->ID
                ));
            }
            $locales = array_values(array_filter(array_map('strval', (array)$locales)));
            $locales_str = '';
            if ($locales) {
                if (class_exists('MLV2_Admin_Query') && method_exists('MLV2_Admin_Query','get_locales_labels')) {
                    $labels = MLV2_Admin_Query::get_locales_labels($locales);
                    $parts = [];
                    foreach ($locales as $lc) { $parts[] = (string)($labels[$lc] ?? $lc); }
                    $locales_str = implode(', ', $parts);
                } else {
                    $locales_str = implode(', ', $locales);
                }
            } else {
                // legacy
                $locales_str = (string)$local_codigo;
            }

            $line = [
                (int)$u->ID,
                $u->user_registered,
                $u->display_name,
                $rut,
                $u->user_email,
                $fmt_phone($telefono),
                $locales_str,
                (int)($totals['latas'] ?? 0),
                (int)($totals['ingresos'] ?? 0),
                (int)($totals['gastos'] ?? 0),
                (int)($totals['saldo'] ?? 0),
            ];
        }

        fputcsv($out, $line);
    }

    fclose($out);
    exit;
});
