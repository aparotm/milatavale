<?php
if (!defined('ABSPATH')) { exit; }

trait MLV2_Front_UI_Gestor_Trait {

    public static function gestor_info(): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_gestor') && !current_user_can('manage_options')) {
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

        $html  = self::section_header('Panel Gestor', 'Información del usuario gestor.');
        $html .= self::render_info_table_custom('Información', '', $rows);

        return self::wrap($html);
    }

    public static function gestor_almacen_asignado(): string {
        $must = self::must_login(); if ($must) return $must;
        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_gestor') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }
        $local = self::get_local_codigo($uid);
        $html  = self::section_header('Almacén del local', 'Información del almacén asociado al local del gestor.');
        $html .= self::render_assigned_almacen_card($local);
        return self::wrap($html);
    }

    // El resto del trait queda exactamente como lo tienes (almacenes disponibles, etc.)
    // No lo toco para no arriesgar lógica operativa.

    
    public static function gestor_almacenes_disponibles(): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_gestor') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>No tienes permisos</strong> para ver esta sección.</div>');
        }

        $local_code = trim((string) get_user_meta($uid, 'mlv_local_codigo', true));

        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        // Si el gestor tiene local asignado, filtramos por su local. Si no, mostramos todo.
        $where = "WHERE estado='pendiente_retiro' AND deleted_at IS NULL";
        $params = [];
        if ($local_code !== '') {
            $where .= " AND local_codigo=%s";
            $params[] = $local_code;
        }

        // Resumen por local (retiros disponibles)
        $sql = "SELECT local_codigo,
                       COUNT(*) AS registros,
                       COALESCE(SUM(cantidad_latas),0) AS latas,
                       COALESCE(SUM(CASE WHEN monto_calculado>0 THEN monto_calculado ELSE 0 END),0) AS monto,
                       MAX(created_at) AS ultima
                FROM {$table}
                {$where}
                GROUP BY local_codigo
                ORDER BY ultima DESC
                LIMIT 50";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $rows = is_array($rows) ? $rows : [];

        $html  = self::section_header('Retiros disponibles', 'Movimientos en estado <strong>Pendiente de retiro</strong>.');
        $html .= self::card_open('Locales con retiros', ($local_code !== '' ? 'Filtrado por tu local.' : 'Vista general.'));

        if (empty($rows)) {
            $html .= '<div class="mlv2-alert mlv2-alert--info">No hay retiros pendientes por ahora.</div>';
            $html .= self::card_close();
            return self::wrap($html);
        }

        $html .= '<div class="mlv2-table-wrap"><table class="mlv2-table um-table">';
        $html .= '<thead><tr><th>Local</th><th>Retiros</th><th>Latas</th><th>Monto</th><th>Último registro</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            $lc   = (string)($r['local_codigo'] ?? '');
            $regs = (int)($r['registros'] ?? 0);
            $lats = (int)($r['latas'] ?? 0);
            $mon  = (float)($r['monto'] ?? 0);
            $ult  = (string)($r['ultima'] ?? '');

            $html .= '<tr>';
            $html .= '<td data-label="Local"><code>' . self::esc($lc) . '</code></td>';
            $html .= '<td data-label="Retiros">' . self::esc((string)$regs) . '</td>';
            $html .= '<td data-label="Latas">' . self::esc((string)$lats) . '</td>';
            $html .= '<td data-label="Monto">' . self::money($mon) . '</td>';
            $html .= '<td data-label="Último registro">' . self::esc($ult) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        $html .= self::card_close();

        return self::wrap($html);
    }




}