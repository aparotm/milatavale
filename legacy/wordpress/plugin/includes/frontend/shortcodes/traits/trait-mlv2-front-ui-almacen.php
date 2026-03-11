<?php
if (!defined('ABSPATH')) { exit; }

trait MLV2_Front_UI_Almacen_Trait {

    public static function almacen_info(): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_almacen') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        $u = get_userdata($uid);

        // ✅ Almacén: NO inventar fallback, si está vacío mostramos —
        $local_nombre = trim((string)get_user_meta($uid, 'mlv_local_nombre', true));
        $almacen_label = ($local_nombre !== '') ? $local_nombre : '—';

        $comuna    = trim((string)get_user_meta($uid, 'mlv_local_comuna', true));
        $direccion = trim((string)get_user_meta($uid, 'mlv_local_direccion', true));
        $hours     = (string)get_user_meta($uid, 'mlv_local_hours', true);

        $rows = [
            'Almacén'             => $almacen_label,
            'Nombre'              => $u ? ($u->display_name ?: $u->user_login) : '—',
            'Email'               => $u ? $u->user_email : '—',
            'RUT'                 => (string)get_user_meta($uid, 'mlv_rut', true),
            'Teléfono'            => (string)get_user_meta($uid, 'mlv_telefono', true),
            'Comuna'              => ($comuna !== '' ? $comuna : '—'),
            'Dirección'           => ($direccion !== '' ? $direccion : '—'),
            'Horario de atención' => method_exists(__CLASS__, 'format_local_hours_pretty')
                ? self::format_local_hours_pretty($hours)
                : '—',
        ];

        $html  = self::section_header('Panel Almacén', 'Datos del almacén y representante (usuario).');

        // ✅ usar tabla custom si existe, si no, usa la vieja
        if (method_exists(__CLASS__, 'render_info_table_custom')) {
            $html .= self::render_info_table_custom('Información', '', $rows);
        } else {
            $html .= self::card_open('Información', '');
            $html .= '<div class="mlv2-table-wrap"><table class="mlv2-info-table um-table"><tbody>';
            foreach ($rows as $k => $v) {
                $html .= '<tr><th>' . esc_html($k) . '</th><td>' . esc_html($v ?: '—') . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
            $html .= self::card_close();
        }

        // ✅ NO mostramos la caja “Datos del almacén / Editar...”
        return self::wrap($html);
    }

    public static function almacen_clientes(): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_almacen') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        $local = method_exists(__CLASS__, 'get_local_codigo') ? self::get_local_codigo($uid) : '';

        $html  = self::section_header('Clientes', 'Listado de clientes asociados a tu local.');

        // ✅ Guard: si faltan funciones internas, NO reventar
        if (!method_exists('MLV2_Front_UI', 'get_clientes_by_local') || !method_exists('MLV2_Front_UI', 'render_clientes_table')) {
            $html .= '<div class="mlv2-alert mlv2-alert--warn"><strong>Sección temporalmente desactivada:</strong> faltan funciones internas de listado de clientes.</div>';
            return self::wrap($html);
        }

        $clientes = self::get_clientes_by_local($local);

        // Filtro por cliente (mismo patrón que [mlv_cliente_movimientos] pero invertido)
        $ids = [];
        foreach ($clientes as $c) { if (isset($c->ID)) { $ids[] = (int)$c->ID; } }
        $selected_cliente = isset($_GET['mlv2_cliente']) ? (int) sanitize_text_field(wp_unslash($_GET['mlv2_cliente'])) : 0;
        if ($selected_cliente > 0 && !in_array($selected_cliente, $ids, true)) {
            $selected_cliente = 0;
        }

        $filter_ui = '';
        if (!empty($clientes) && count($clientes) > 1) {
            $filter_ui .= '<form class="mlv2-inline-form" method="get" action="">';
            foreach ($_GET as $k => $v) {
                $k = sanitize_key((string)$k);
                if ($k === 'mlv2_cliente') continue;
                if (is_array($v)) continue;
                $filter_ui .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($v))) . '">';
            }
            $filter_ui .= '<label class="mlv2-label" style="margin-right:8px;">Ver por cliente</label>';
            $filter_ui .= '<select name="mlv2_cliente" class="mlv2-select" onchange="this.form.submit()">';
            $filter_ui .= '<option value="0"' . ($selected_cliente === 0 ? ' selected' : '') . '>Todos los clientes</option>';

            foreach ($clientes as $u) {
                $cid = (int)$u->ID;
                $name = $u->display_name ?: $u->user_login;
                $rut  = (string) get_user_meta($cid, 'mlv_rut', true);
                if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','format')) { $rut = MLV2_RUT::format($rut); }
                $label = $name . ($rut !== '' ? (' — ' . $rut) : '');
                $filter_ui .= '<option value="' . esc_attr((string)$cid) . '"' . ($selected_cliente === $cid ? ' selected' : '') . '>' . esc_html($label) . '</option>';
            }
            $filter_ui .= '</select>';
            $filter_ui .= '</form>';
        }

        $html .= $filter_ui;
        $html .= self::render_clientes_table($clientes, $local, $selected_cliente);
        return self::wrap($html);
    }

    public static function almacen_clientes_acciones($atts = []): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_almacen') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        $atts = shortcode_atts([
            'gasto_url' => home_url('/registro-gasto/'),
            'latas_url' => home_url('/registro-latas/'),
        ], (array)$atts, 'mlv_almacen_clientes_acciones');

        $gasto_base = esc_url_raw((string)$atts['gasto_url']);
        $latas_base = esc_url_raw((string)$atts['latas_url']);

        $local = method_exists(__CLASS__, 'get_local_codigo') ? self::get_local_codigo($uid) : '';
        if ($local === '') {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>Falta configuración del local.</strong> No existe <code>mlv_local_codigo</code> en este usuario.</div>');
        }

        $clientes = self::get_clientes_by_local($local);
        if (!empty($clientes)) {
            $almacen_rut_norm = class_exists('MLV2_RUT') ? MLV2_RUT::normalize((string)get_user_meta($uid, 'mlv_rut', true)) : '';
            $clientes = array_values(array_filter($clientes, function($c) use ($uid, $almacen_rut_norm) {
                if (!isset($c->ID)) return false;
                if ((int)$c->ID === (int)$uid) return false;
                if ($almacen_rut_norm !== '' && class_exists('MLV2_RUT')) {
                    $rut_norm = MLV2_RUT::normalize((string)get_user_meta((int)$c->ID, 'mlv_rut', true));
                    if ($rut_norm !== '' && $rut_norm === $almacen_rut_norm) return false;
                }
                return true;
            }));
        }

        $html  = self::section_header('Clientes del local', 'Busca clientes y registra gasto/latas con cliente preseleccionado.');
        if (empty($clientes)) {
            $html .= '<div class="mlv2-alert mlv2-alert--warn"><strong>No hay clientes asociados</strong> a este local.</div>';
            return self::wrap($html);
        }

        $cliente_ids = [];
        foreach ($clientes as $c) {
            $cid = (int)($c->ID ?? 0);
            if ($cid > 0) { $cliente_ids[] = $cid; }
        }

        $stats = [];
        if (!empty($cliente_ids)) {
            global $wpdb;
            $table = MLV2_DB::table_movimientos();
            $ph = implode(',', array_fill(0, count($cliente_ids), '%d'));
            $sql = "
                SELECT
                    cliente_user_id,
                    COALESCE(SUM(CASE WHEN monto_calculado > 0 AND origen_saldo='reciclaje' AND clasificacion_mov='operacion' THEN monto_calculado ELSE 0 END),0) AS reciclaje,
                    COALESCE(SUM(CASE WHEN origen_saldo='incentivo' THEN monto_calculado ELSE 0 END),0) AS incentivo,
                    COALESCE(SUM(CASE WHEN monto_calculado < 0 AND clasificacion_mov='operacion' THEN ABS(monto_calculado) ELSE 0 END),0) AS canjeado,
                    COALESCE(SUM(CASE WHEN monto_calculado > 0 AND origen_saldo='reciclaje' THEN cantidad_latas ELSE 0 END),0) AS latas,
                    COALESCE(SUM(monto_calculado),0) AS saldo
                FROM {$table}
                WHERE deleted_at IS NULL
                  AND local_codigo = %s
                  AND cliente_user_id IN ({$ph})
                GROUP BY cliente_user_id
            ";
            $params = array_merge([$local], $cliente_ids);
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
            foreach ((array)$rows as $r) {
                $cid = (int)($r['cliente_user_id'] ?? 0);
                if ($cid <= 0) { continue; }
                $stats[$cid] = [
                    'reciclaje' => (int)($r['reciclaje'] ?? 0),
                    'incentivo' => (int)($r['incentivo'] ?? 0),
                    'canjeado' => (int)($r['canjeado'] ?? 0),
                    'latas' => (int)($r['latas'] ?? 0),
                    'saldo' => (int)($r['saldo'] ?? 0),
                ];
            }
        }

        $table_id  = 'mlv2-clientes-table-' . wp_generate_password(6, false, false);
        $empty_id  = 'mlv2-clientes-empty-' . wp_generate_password(6, false, false);

        $html .= '<div class="mlv2-card um">';
        $html .= '<style>
            #' . esc_attr($table_id) . ' td[data-label="Acciones"]{white-space:nowrap;}
            #' . esc_attr($table_id) . ' td[data-label="Acciones"]{min-width:190px;}
            #' . esc_attr($table_id) . ' .mlv2-actions{display:flex;gap:8px;justify-content:flex-end;align-items:center;flex-wrap:nowrap;}
            #' . esc_attr($table_id) . ' .mlv2-actions .um-button{
                white-space:nowrap;
                display:inline-flex;
                align-items:center;
                justify-content:center;
                line-height:1;
                width:auto !important;
                min-width:84px;
                max-width:none !important;
                padding:9px 12px;
                border-radius:10px;
                overflow:visible !important;
            }
            #' . esc_attr($table_id) . ' .mlv2-actions .um-button{
                font-size:12px;
                min-height:33px !important;
                min-width:0;
                padding:0 26px !important;
            }
        </style>';
        $html .= '<div class="mlv2-table-wrap"><table id="' . esc_attr($table_id) . '" class="mlv2-table mlv2-table--cards um-table">';
        $html .= '<thead><tr>';
        $html .= '<th>Nombre completo</th><th>RUT</th><th>Teléfono</th><th>Ingresos reciclaje</th><th>Ingresos incentivos</th><th>Canjeado</th><th>Saldo</th><th>Latas</th><th>Acciones</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($clientes as $c) {
            $cid = (int)($c->ID ?? 0);
            if ($cid <= 0) { continue; }
            $nombre = (string)($c->display_name ?: $c->user_login);
            $rut = (string) get_user_meta($cid, 'mlv_rut', true);
            if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT', 'format')) {
                $rut = MLV2_RUT::format($rut);
            }
            $tel = (string) get_user_meta($cid, 'mlv_telefono', true);

            $s = $stats[$cid] ?? ['reciclaje' => 0, 'incentivo' => 0, 'canjeado' => 0, 'latas' => 0, 'saldo' => 0];
            $q = trim(strtolower($nombre . ' ' . $rut . ' ' . $tel));

            $gasto_url = add_query_arg(['mlv2_cliente_id' => $cid], $gasto_base);
            $latas_url = add_query_arg(['mlv2_cliente_id' => $cid], $latas_base);

            $html .= '<tr data-search="' . esc_attr($q) . '">';
            $html .= '<td data-label="Nombre completo">' . esc_html($nombre) . '</td>';
            $html .= '<td data-label="RUT">' . esc_html($rut !== '' ? $rut : '—') . '</td>';
            $html .= '<td data-label="Teléfono">' . esc_html($tel !== '' ? $tel : '—') . '</td>';
            $html .= '<td data-label="Ingresos reciclaje">' . esc_html(self::money((float)$s['reciclaje'])) . '</td>';
            $html .= '<td data-label="Ingresos incentivos">' . esc_html(self::money((float)$s['incentivo'])) . '</td>';
            $html .= '<td data-label="Canjeado">' . esc_html(self::money((float)$s['canjeado'])) . '</td>';
            $html .= '<td data-label="Saldo">' . esc_html(self::money((float)$s['saldo'])) . '</td>';
            $html .= '<td data-label="Latas">' . esc_html((string)$s['latas']) . '</td>';
            $html .= '<td data-label="Acciones">';
            $html .= '<div class="mlv2-actions">';
            $html .= '<a class="um-button um-alt" href="' . esc_url($gasto_url) . '">+ Gasto</a>';
            $html .= '<a class="um-button" href="' . esc_url($latas_url) . '">+ Latas</a>';
            $html .= '</div>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        $html .= '<p id="' . esc_attr($empty_id) . '" class="mlv2-muted" style="display:none;">No hay resultados para tu búsqueda.</p>';
        $html .= '</div>';

        $html .= '<script>(function(){';
        $html .= 'var table=document.getElementById(' . wp_json_encode($table_id) . ');';
        $html .= 'var empty=document.getElementById(' . wp_json_encode($empty_id) . ');';
        $html .= 'var root=table?table.closest(".mlv2-card"):null;';
        $html .= 'var input=root?root.querySelector("input[type=\'search\']"):null;';
        $html .= 'if(!input||!table){return;}';
        $html .= 'var rows=table.querySelectorAll("tbody tr");';
        $html .= 'function run(){var q=(input.value||"").toLowerCase().trim();var vis=0;rows.forEach(function(r){var hay=(r.getAttribute("data-search")||"");var ok=(q===""||hay.indexOf(q)!==-1);r.style.display=ok?"":"none";if(ok){vis++;}});if(empty){empty.style.display=vis===0?"":"none";}}';
        $html .= 'input.addEventListener("input",run);run();';
        $html .= '})();</script>';

        return self::wrap($html);
    }

    public static function almacen_kpis(): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_almacen') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        $local_code = trim((string) get_user_meta($uid, 'mlv_local_codigo', true));
        if ($local_code === '') {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>Falta configuración:</strong> este usuario no tiene <code>mlv_local_codigo</code>.</div>');
        }

        if (!method_exists(__CLASS__, 'kpis_for_local_code')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No disponible:</strong> faltan KPIs internos.</div>');
        }

        $kpis = self::kpis_for_local_code($local_code);

        $html  = self::section_header('Resumen', 'Monedero del local (acumulado).');
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


    public static function almacen_movimientos(): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_almacen') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        // ✅ Guard: si faltan métodos de movimientos, no reventar
        if (!method_exists(__CLASS__, 'movimientos_query') || !method_exists(__CLASS__, 'render_movimientos_table_almacen')) {
            return self::wrap(self::section_header('Tus movimientos', '') . '<div class="mlv2-alert mlv2-alert--warn"><strong>No disponible:</strong> faltan funciones internas de movimientos.</div>');
        }

        if (method_exists(__CLASS__, 'handle_almacen_retirado_post')) {
            self::handle_almacen_retirado_post($uid);
        }

        $html  = self::section_header('Tus movimientos', 'Marca <strong>Retirado</strong> cuando el gestor ya retiró las latas.');

        

        // Filtro por cliente (mismo look/flujo que "Ver por almacén" del panel de cliente)
        $local_codigo = trim(self::get_local_codigo($uid));
        $clientes = self::get_clientes_by_local($local_codigo);

        $ids = [];
        foreach ($clientes as $c) { if (isset($c->ID)) { $ids[] = (int)$c->ID; } }

        $selected_cliente = isset($_GET['mlv2_cliente']) ? (int) sanitize_text_field(wp_unslash($_GET['mlv2_cliente'])) : 0;
        if ($selected_cliente > 0 && !in_array($selected_cliente, $ids, true)) {
            $selected_cliente = 0;
        }

        $filter_ui = '';
        if (!empty($clientes) && count($clientes) > 1) {
            $filter_ui .= '<form class="mlv2-inline-form" method="get" action="">';
            // preservar otros query params
            foreach ($_GET as $k => $v) {
                $k = sanitize_key((string)$k);
                if ($k === 'mlv2_cliente') continue;
                if (is_array($v)) continue;
                $filter_ui .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($v))) . '">';
            }
            $filter_ui .= '<label class="mlv2-label" style="margin-right:8px;">Ver por cliente</label>';
            $filter_ui .= '<select name="mlv2_cliente" class="mlv2-select" onchange="this.form.submit()">';
            $filter_ui .= '<option value="0"' . ($selected_cliente === 0 ? ' selected' : '') . '>Todos los clientes</option>';

            foreach ($clientes as $c) {
                $cid = (int)($c->ID ?? 0);
                if ($cid <= 0) continue;

                $nombre = trim((string)($c->display_name ?? ''));
                if ($nombre === '') { $nombre = $c->user_login ?? ('ID ' . $cid); }

                $rut = (string) get_user_meta($cid, 'mlv_rut', true);
                if ($rut === '') { $rut = (string) get_user_meta($cid, 'mlv_rut_norm', true); }
                if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','format')) {
                    $rut = MLV2_RUT::format($rut);
                }

                $label = $rut ? ($nombre . ' (' . $rut . ')') : $nombre;
                $filter_ui .= '<option value="' . esc_attr((string)$cid) . '"' . ($selected_cliente === $cid ? ' selected' : '') . '>' . esc_html($label) . '</option>';
            }

            $filter_ui .= '</select>';
            $filter_ui .= '</form>';
        }

        // SubKPIs debajo del filtro:
        // - Si hay cliente seleccionado: KPIs de ese cliente (en este local)
        // - Si NO hay cliente ("Todos los clientes"): KPIs totales del local
        $subkpis = '';
        if ($local_codigo !== '') {
            $lk = [];
            $is_total_local = false;

            if ($selected_cliente > 0) {
                $lk = self::kpis_for_cliente_local($selected_cliente, $local_codigo);
            } elseif (method_exists(__CLASS__, 'kpis_for_local_code')) {
                $lk = self::kpis_for_local_code($local_codigo);
                $is_total_local = true;
            }

            if (!empty($lk)) {
                $subkpis .= '<div class="mlv2-subkpis">';
                $subkpis .= '<div class="mlv2-kpis">';
                $subkpis .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($lk['saldo'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Saldo disponible</div></div>';
                $subkpis .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($lk['reciclaje'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Generado por reciclaje</div></div>';
                $subkpis .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($lk['incentivo'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Generado por incentivos</div></div>';
                $subkpis .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($lk['gastos'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Canjeado</div></div>';
                $subkpis .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::esc((string)($lk['latas'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Latas</div></div>';
                $subkpis .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::esc((string)($lk['registros'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Movimientos</div></div>';
                $subkpis .= '</div>';
                $subkpis .= '</div>';
            }
        }

        $html .= $filter_ui;
        $html .= $subkpis;

        // ✅ Paginación híbrida:
        // - Desktop: paginación clásica (links)
        // - Móvil: botón "Cargar más" (AJAX)
        $page = isset($_GET['mlv2_p']) ? max(1, (int) sanitize_text_field(wp_unslash($_GET['mlv2_p']))) : 1;
        $per_page = isset($_GET['mlv2_pp']) ? (int) sanitize_text_field(wp_unslash($_GET['mlv2_pp'])) : 15;
        if ($per_page < 5) $per_page = 5;
        if ($per_page > 100) $per_page = 100;
        $offset = ($page - 1) * $per_page;

        $mov_args = [
            'local_codigo' => $local_codigo,
            'limit' => $per_page,
            'offset' => $offset,
        ];
        if ($selected_cliente > 0) {
            $mov_args['cliente_user_id'] = $selected_cliente;
        }

        $total = self::movimientos_count($mov_args);
        $movs  = self::movimientos_query($mov_args);
        if ($selected_cliente <= 0) {
            $movs = self::aggregate_local_incentives($movs);
        }

        // Saldos iniciales por cliente para esta página (saldo actual - suma de los "newer" del offset)
        $saldo_running = [];
        if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'get_saldo_cliente')) {
            $uids2 = [];
            foreach ($movs as $r) {
                $cid = (int)($r['cliente_user_id'] ?? 0);
                if ($cid > 0) { $uids2[$cid] = true; }
            }
            foreach (array_keys($uids2) as $cid) {
                $saldo_running[$cid] = (float) MLV2_Ledger::get_saldo_cliente($cid);
            }
            $sum_newer = self::movimientos_sum_newer_by_cliente($mov_args, $offset);
            foreach ($sum_newer as $cid => $s) {
                if (isset($saldo_running[$cid])) {
                    $saldo_running[$cid] = (float)$saldo_running[$cid] - (float)$s;
                }
            }
        }

        $footer  = self::render_pagination_links($page, $per_page, $total, (array)$_GET, 'mlv2_p');
        $footer .= self::render_load_more('almacen', $page, $per_page, $total, [
            'cliente_user_id' => (string)$selected_cliente,
        ]);

        $html .= self::render_movimientos_table_almacen($movs, $uid, [
            'title' => 'Historial',
            'subtitle' => 'Check se guarda automáticamente al marcar/desmarcar.',
            'muted_states' => [],
            'saldo_running' => $saldo_running,
            'footer_html' => $footer,
        ]);

        return self::wrap($html);
    }

    

    public static function almacen_gestores(): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        if (!in_array('um_almacen', $roles, true) && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--error"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        $local_codigo = trim(self::get_local_codigo($uid));
        if ($local_codigo === '') {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn">Tu usuario no tiene <code>mlv_local_codigo</code>.</div>');
        }

        $q = new WP_User_Query([
            'role' => 'um_gestor',
            'number' => 200,
            'fields' => ['ID','display_name','user_email'],
            'meta_query' => [
                [
                    'key' => 'mlv_local_codigo',
                    'value' => $local_codigo,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        $users = (array) $q->get_results();

        $html = '<h3 class="mlv2-h3">Gestores del local</h3>';
        $html .= '<div class="mlv2-card"><div class="mlv2-card__body">';

        if (!$users) {
            $html .= '<div class="mlv2-muted">No hay gestores asignados a este local.</div>';
            $html .= '</div></div>';
            return self::wrap($html);
        }

        $html .= '<div class="mlv2-table-wrap"><table class="mlv2-table">';
        $html .= '<thead><tr><th>Gestor</th><th>RUT</th><th>Email</th><th>Teléfono</th></tr></thead><tbody>';

        foreach ($users as $u) {
            $gid = (int) $u->ID;
            $name = $u->display_name ? $u->display_name : ('Gestor #' . $gid);
            $email = $u->user_email ? '<a href="mailto:' . esc_attr($u->user_email) . '">' . esc_html($u->user_email) . '</a>' : '—';
            $tel = (string) get_user_meta($gid, 'mlv_telefono', true);
            $tel_html = $tel !== '' ? esc_html($tel) : '—';

            $html .= '<tr>';
            $html .= '<td data-label="Gestor">' . esc_html($name) . '</td>';
            $rut = (string) get_user_meta($gid, 'mlv_rut', true);
            if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','format')) { $rut = MLV2_RUT::format($rut); }
            $html .= '<td data-label="RUT">' . esc_html($rut ?: '—') . '</td>';
            $html .= '<td data-label="Email">' . $email . '</td>';
            $html .= '<td data-label="Teléfono">' . $tel_html . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        $html .= '</div></div>';

        return self::wrap($html);
    }

public static function almacen_dashboard(): string {
        return self::almacen_info() . self::almacen_kpis() . self::almacen_clientes() . self::almacen_movimientos();
    }
}
