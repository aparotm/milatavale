<?php
if (!defined('ABSPATH')) { exit; }

trait MLV2_Front_UI_Cliente_Trait {

    public static function cliente_info(): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_cliente') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        $u = get_userdata($uid);
        $local_nombre = self::resolve_local_nombre_for_user($uid);

        $rows = [
            'Nombre'         => $u ? ($u->display_name ?: $u->user_login) : '—',
            'Email'          => $u ? $u->user_email : '—',
            'RUT'            => (string)get_user_meta($uid, 'mlv_rut', true),
            'Teléfono'       => (string)get_user_meta($uid, 'mlv_telefono', true),
            'Local asignado' => ($local_nombre !== '' ? $local_nombre : '—'),
        ];

        $html  = self::section_header('Panel Cliente', 'Tu información personal y movimientos.');
        $html .= self::render_info_table_custom('Información', '', $rows);

        return self::wrap($html);
    }

    public static function cliente_almacen_asignado(): string {
        $must = self::must_login(); if ($must) return $must;
        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_cliente') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }
        $local = self::get_local_codigo($uid);
        $html  = self::section_header('Almacén asignado', 'Información del almacén asociado a tu local.');
        $html .= self::render_assigned_almacen_card($local);
        return self::wrap($html);
    }

    public static function cliente_kpis(): string {
        $must = self::must_login(); if ($must) return $must;
        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_cliente') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        $kpis = self::kpis_for_cliente($uid);

        $html  = self::section_header('Resumen', 'Tu monedero: generación, gastos y saldo actual.');
        $html .= '<div class="mlv2-kpis">';
        $html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($kpis['saldo'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Saldo disponible</div></div>';
        $html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($kpis['reciclaje'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Generado por reciclaje</div></div>';
        $html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($kpis['incentivo'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Generado por incentivos</div></div>';
        $html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($kpis['gastos'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Canjeado</div></div>';
        $html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::esc((string)($kpis['latas'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Latas</div></div>';
        $html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::esc((string)($kpis['registros'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Movimientos</div></div>';
        $html .= '</div>';

        return self::wrap($html);
    }


    public static function cliente_movimientos(): string {
        $must = self::must_login(); if ($must) return $must;
        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_cliente') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        // Filtro por local (modelo N-N)
        $locales = self::get_locales_for_cliente($uid);
        sort($locales);

        $selected_local = isset($_GET['mlv2_local']) ? sanitize_text_field(wp_unslash($_GET['mlv2_local'])) : '';
        if ($selected_local === 'all') $selected_local = '';
        if ($selected_local !== '' && !in_array($selected_local, $locales, true)) {
            $selected_local = '';
        }

        // UI filtro (solo si el cliente tiene 2+ locales asociados)
        $filter_ui = '';
        if (!empty($locales) && count($locales) > 1) {
            $filter_ui .= '<form class="mlv2-inline-form" method="get" action="">';
            // preservar otros query params
            foreach ($_GET as $k => $v) {
                $k = sanitize_key((string)$k);
                if ($k === 'mlv2_local') continue;
                if (is_array($v)) continue;
                $filter_ui .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($v))) . '">';
            }
            $filter_ui .= '<label class="mlv2-label" style="margin-right:8px;">Ver por almacén</label>';
            $filter_ui .= '<select name="mlv2_local" class="mlv2-select" onchange="this.form.submit()">';
            $filter_ui .= '<option value="all"' . ($selected_local === '' ? ' selected' : '') . '>Todos los almacenes</option>';

            foreach ($locales as $code) {
                $alm = self::find_almacen_by_local((string)$code);
                $nombre = '';
                if ($alm) {
                    $nombre = trim((string)get_user_meta($alm->ID, 'mlv_local_nombre', true));
                    if ($nombre === '') $nombre = ($alm->display_name ?: $alm->user_login);
                }
                $label = $nombre !== '' ? ($nombre . ' (' . $code . ')') : $code;
                $filter_ui .= '<option value="' . esc_attr($code) . '"' . ($selected_local === $code ? ' selected' : '') . '>' . esc_html($label) . '</option>';
            }
            $filter_ui .= '</select>';
            $filter_ui .= '</form>';
        }

        // KPIs por local seleccionado (no reemplaza KPIs globales)
        $local_kpis_html = '';
        // KPIs bajo el filtro: si no hay almacén seleccionado, mostrar totales (Todos los almacenes)
        $lk = ($selected_local !== '') ? self::kpis_for_cliente_local($uid, $selected_local) : self::kpis_for_cliente_total($uid);
        if (!empty($lk)) {
$local_kpis_html .= '<div class="mlv2-subkpis">';
            $local_kpis_html .= '<div class="mlv2-kpis">';
            $local_kpis_html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($lk['saldo'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Saldo disponible</div></div>';
            $local_kpis_html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($lk['reciclaje'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Generado por reciclaje</div></div>';
            $local_kpis_html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($lk['incentivo'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Generado por incentivos</div></div>';
            $local_kpis_html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($lk['gastos'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Canjeado</div></div>';
            $local_kpis_html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::esc((string)($lk['latas'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Latas</div></div>';
            $local_kpis_html .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::esc((string)($lk['registros'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Movimientos</div></div>';
            $local_kpis_html .= '</div>';
            $local_kpis_html .= '</div>';
        }

        // ✅ Paginación híbrida:
        // - Desktop: paginación clásica (links)
        // - Móvil: botón "Cargar más" (AJAX)
        $page = isset($_GET['mlv2_p']) ? max(1, (int) sanitize_text_field(wp_unslash($_GET['mlv2_p']))) : 1;
        $per_page = isset($_GET['mlv2_pp']) ? (int) sanitize_text_field(wp_unslash($_GET['mlv2_pp'])) : 15;
        if ($per_page < 5) $per_page = 5;
        if ($per_page > 100) $per_page = 100;
        $offset = ($page - 1) * $per_page;

        $mov_args = [
            'cliente_user_id'  => $uid,
            'limit'            => $per_page,
            'offset'           => $offset,
        ];
        if ($selected_local !== '') {
            $mov_args['local_codigo'] = $selected_local;
        }

        $total = self::movimientos_count($mov_args);
        $movs  = self::movimientos_query($mov_args);

        $html  = self::section_header('Tus movimientos', 'Se muestran ingresos (latas) y gastos registrados por el almacén.');
        $html .= $filter_ui;
        $html .= $local_kpis_html;
        $html .= self::card_open('Movimientos', '');
        $html .= '<div class="mlv2-table-wrap"><table class="mlv2-table mlv2-table--cards um-table">';
        $html .= '<thead><tr><th>Fecha</th><th>Tipo</th><th>Nombre Local</th><th>Tu RUT</th><th>Latas</th><th>Valor por lata</th><th>Evidencia</th><th>Monto</th><th>Saldo</th></tr></thead><tbody>';

        if (empty($movs)) {
            $html .= '<tr><td colspan="9">No hay movimientos.</td></tr>';
        } else {
            $saldo_start = null;
            if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'get_saldo_cliente')) {
                $saldo_start = (float) MLV2_Ledger::get_saldo_cliente($uid);
                // Restar la suma de los movimientos más recientes (offset) para obtener el saldo inicial de esta página.
                $sum_newer = self::movimientos_sum_newer_by_cliente($mov_args, $offset);
                if (isset($sum_newer[$uid])) {
                    $saldo_start = (float)$saldo_start - (float)$sum_newer[$uid];
                }
            }

            $html .= self::render_movimientos_rows_cliente($movs, $uid, $saldo_start);
        }

        $html .= '</tbody></table></div>';

        // Desktop: paginación clásica
        $html .= self::render_pagination_links($page, $per_page, $total, (array)$_GET, 'mlv2_p');

        // Móvil: botón Cargar más (AJAX)
        $html .= self::render_load_more('cliente', $page, $per_page, $total, [
            'local_codigo' => $selected_local,
        ]);
        $html .= self::card_close();

        return self::wrap($html);
    }

    public static function cliente_dashboard(): string {
        return self::cliente_info() . self::cliente_almacen_asignado() . self::cliente_kpis() . self::cliente_movimientos();
    }
}
