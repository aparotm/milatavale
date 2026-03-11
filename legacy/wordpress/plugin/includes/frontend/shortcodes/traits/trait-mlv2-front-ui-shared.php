<?php
if (!defined('ABSPATH')) { exit; }

trait MLV2_Front_UI_Shared_Trait {

    private static function must_login() {
        if (!is_user_logged_in()) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>Debes iniciar sesión</strong> para ver esta sección.</div>');
        }
        return '';
    }


    public static function panel_alert(): string {
        $must = self::must_login(); if ($must) return $must;

        $uid = get_current_user_id();
        // Alertas persistidas en BD
        $alerts = [];
        if (class_exists('MLV2_Alerts')) {
            $alerts = MLV2_Alerts::get_for_user($uid, 4);
        }

        // Alertas "flash" via querystring (compatibilidad hacia atrás)
        $res = isset($_GET['mlv2_res']) ? sanitize_key(wp_unslash($_GET['mlv2_res'])) : '';
        if ($res !== '') {
            $map = [
                'movimiento_ingresado'    => ['ok', 'Movimiento ingresado', 'Tu registro de latas fue guardado correctamente.'],
                'perfil_actualizado'     => ['ok', 'Datos actualizados', 'Los datos de tu perfil/local se actualizaron correctamente.'],
                // 'login_ok' eliminado: no mostrar alerta automática al iniciar sesión.
                'cliente_registrado'     => ['ok', 'Cliente registrado', 'Has registrado al cliente correctamente.'],
                'cliente_registro_error' => ['error', 'No se pudo registrar', 'Revisa los datos e intenta nuevamente.'],
            ];
            if (!empty($map[$res])) {
                [$type, $title, $msg] = $map[$res];
                array_unshift($alerts, [
                    'id' => 0,
                    'type' => $type,
                    'message' => '<strong>' . self::esc($title) . ':</strong> ' . self::esc($msg),
                    'created_at' => current_time('mysql'),
                ]);
            }
        }

        // Flash moderno (registro cliente, etc.)
        $ok = isset($_GET['mlv_ok']) ? sanitize_key(wp_unslash($_GET['mlv_ok'])) : '';
        $err = isset($_GET['mlv_err']) ? sanitize_key(wp_unslash($_GET['mlv_err'])) : '';
        if ($ok === 'cliente_creado' || $ok === 'cliente_registrado') {
            $n = isset($_GET['mlv_nombre']) ? sanitize_text_field(wp_unslash($_GET['mlv_nombre'])) : '';
            $msg = $n !== '' ? ('Has registrado a <strong>' . self::esc($n) . '</strong> correctamente.') : 'Has registrado al cliente correctamente.';
            array_unshift($alerts, [
                'id' => 0,
                'type' => 'ok',
                'message' => $msg,
                'created_at' => current_time('mysql'),
            ]);
        } elseif ($err !== '') {
            $map_err = [
                'cliente_existe' => 'Ese cliente ya existe.',
                'rut_existe' => 'Ese RUT ya existe.',
                'email_existe' => 'Ese email ya está en uso.',
                'faltan_datos' => 'Faltan datos obligatorios.',
                'nonce' => 'Sesión vencida. Recarga y prueba de nuevo.',
                'no_autorizado' => 'No autorizado.',
                'doble_rol_bloqueado' => 'No puedes registrar clientes con tu mismo RUT.',
                'error' => 'No se pudo completar la acción.',
            ];
            $msg = $map_err[$err] ?? 'No se pudo completar la acción.';
            array_unshift($alerts, [
                'id' => 0,
                'type' => 'error',
                'message' => '<strong>Error:</strong> ' . self::esc($msg),
                'created_at' => current_time('mysql'),
            ]);
        }


        if (empty($alerts)) return '';

        // Máximo 4 visibles
        $alerts = array_slice($alerts, 0, 4);

        
        // Mostrar solo una vez: las alertas guardadas en BD se marcan como "dismissed" al renderizar el panel.
        if (class_exists('MLV2_Alerts')) {
            foreach ($alerts as $a) {
                $aid = (int)($a['id'] ?? 0);
                if ($aid > 0) {
                    // Si falla, no rompemos la UI.
                    MLV2_Alerts::dismiss($aid, $uid);
                }
            }
        }

$html = '<div class="mlv2-alerts">';
        foreach ($alerts as $a) {
            $type = isset($a['type']) ? sanitize_key($a['type']) : 'info';
            $cls = 'mlv2-alert--info';
            if ($type === 'ok') $cls = 'mlv2-alert--ok';
            else if ($type === 'warn') $cls = 'mlv2-alert--warn';
            else if ($type === 'error') $cls = 'mlv2-alert--error';

            $id = (int)($a['id'] ?? 0);
            $msg = (string)($a['message'] ?? '');
            // Si viene desde BD puede traer HTML permitido; si viene desde query ya viene compuesto.
            $msg_html = ($id > 0) ? wp_kses_post($msg) : $msg;

            $html .= '<div class="mlv2-alert ' . esc_attr($cls) . '"' . ($id > 0 ? ' data-alert-id="' . esc_attr((string)$id) . '"' : '') . '>';
            $html .= '<button type="button" class="mlv2-alert__close" aria-label="Cerrar">×</button>';
            $html .= '<div class="mlv2-alert__body">' . $msg_html . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return self::wrap($html);
    }



    private static function wrap(string $html): string {
        return '<div class="mlv2-wrap um">' . $html . '</div>';
    }

    private static function user_has_role(int $user_id, string $role): bool {
        $u = get_userdata($user_id);
        if (!$u) return false;
        return in_array($role, (array)$u->roles, true);
    }

    private static function esc($v): string { return esc_html((string)$v); }

    private static function format_money(int $m): string {
        if ($m <= 0) return '—';
        return '$' . number_format_i18n($m);
    }

    private static function get_local_codigo(int $user_id): string {
        return (string) get_user_meta($user_id, 'mlv_local_codigo', true);
    }


    /**
     * Retorna los códigos de local asociados a un cliente (modelo N-N).
     *
     * - Fuente de verdad: tabla wp_mlv_clientes_almacenes.
     * - Fallback: meta mlv_local_codigo (compatibilidad legacy).
     */
    private static function get_locales_for_cliente(int $cliente_id): array {
        $out = [];

        if ($cliente_id <= 0) return $out;

        // Modelo N-N
        if (class_exists('MLV2_DB')) {
            global $wpdb;
            $table = MLV2_DB::table_clientes_almacenes();
            $codes = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT local_codigo FROM {$table} WHERE cliente_user_id=%d",
                $cliente_id
            ));
            if (is_array($codes)) {
                foreach ($codes as $c) {
                    $c = sanitize_text_field((string)$c);
                    if ($c !== '') $out[$c] = true;
                }
            }
        }

        // Fallback legacy (un solo local en meta)
        $legacy = trim((string)get_user_meta($cliente_id, 'mlv_local_codigo', true));
        if ($legacy !== '') $out[$legacy] = true;

        return array_keys($out);
    }

    private static function get_local_nombre(int $user_id): string {
        return (string) get_user_meta($user_id, 'mlv_local_nombre', true);
    }

    private static function get_meta_str(int $user_id, string $key): string {
        return (string) get_user_meta($user_id, $key, true);
    }

    private static function get_um_account_url(): string {
        if (function_exists('um_get_core_page')) {
            $u = um_get_core_page('account');
            if (!empty($u)) return (string) $u;
        }
        return (string) admin_url('profile.php');
    }

    private static function parse_horario_dias(string $dias_csv): array {
        $dias_csv = trim($dias_csv);
        if ($dias_csv === '') return [];
        $parts = preg_split('/\s*,\s*/', $dias_csv);
        $out = [];
        foreach ($parts as $p) {
            $n = (int) preg_replace('/[^0-9]/', '', (string)$p);
            if ($n >= 1 && $n <= 7) $out[$n] = true;
        }
        return array_keys($out);
    }

    private static function time_to_minutes(string $hhmm): ?int {
        $hhmm = trim($hhmm);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) return null;
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h < 0 || $h > 23 || $i < 0 || $i > 59) return null;
        return $h * 60 + $i;
    }

    private static function almacen_abierto_ahora(int $almacen_id): ?bool {
        $dias = self::parse_horario_dias(self::get_meta_str($almacen_id, 'mlv_horario_dias'));
        $apertura = self::time_to_minutes(self::get_meta_str($almacen_id, 'mlv_horario_apertura'));
        $cierre   = self::time_to_minutes(self::get_meta_str($almacen_id, 'mlv_horario_cierre'));

        if (empty($dias) || $apertura === null || $cierre === null) return null;

        $ts = (int) current_time('timestamp');
        $dow = (int) wp_date('N', $ts); // 1..7
        if (!in_array($dow, $dias, true)) return false;

        $now_minutes = (int) wp_date('G', $ts) * 60 + (int) wp_date('i', $ts);

        if ($cierre <= $apertura) return null; // overnight no soportado en fase 1
        return ($now_minutes >= $apertura && $now_minutes <= $cierre);
    }

    /**
     * Horario LEGACY (si lo sigues usando en listados antiguos)
     */
    private static function format_horario(int $almacen_id): string {
        $txt = trim(self::get_meta_str($almacen_id, 'mlv_horario_texto'));
        if ($txt !== '') return $txt;

        $ap = trim(self::get_meta_str($almacen_id, 'mlv_horario_apertura'));
        $ci = trim(self::get_meta_str($almacen_id, 'mlv_horario_cierre'));
        if ($ap !== '' && $ci !== '') return $ap . '–' . $ci;

        return 'Horario no informado';
    }

    private static function sanitize_phone_digits(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone);
        return (string) $digits;
    }

    private static function render_whatsapp_link(string $phone_raw): string {
        $phone_raw = trim((string)$phone_raw);
        if ($phone_raw === '') return '—';

        $digits = self::sanitize_phone_digits($phone_raw);
        if ($digits === '') return self::esc($phone_raw);

        $wa = 'https://wa.me/' . rawurlencode($digits);
        return '<a class="mlv2-link" href="' . esc_url($wa) . '" target="_blank" rel="noopener">' . self::esc($phone_raw) . '</a>';
    }


    private static function render_mailto_link(string $email_raw): string {
        $email_raw = trim((string)$email_raw);
        if ($email_raw === '') return '—';
        $email = sanitize_email($email_raw);
        if ($email === '') return self::esc($email_raw);
        return '<a class="mlv2-link" href="mailto:' . esc_attr($email) . '" target="_blank" rel="noopener">' . self::esc($email_raw) . '</a>';
    }

    private static function find_almacen_by_local(string $local_codigo): ?WP_User {
        if ($local_codigo === '') return null;

        // Nuevo modelo N-N: clientes asociados por tabla de relacion
        $ids_rel = [];
        if (class_exists('MLV2_DB')) {
            global $wpdb;
            $table_ca = MLV2_DB::table_clientes_almacenes();
            $ids_rel = $wpdb->get_col($wpdb->prepare("SELECT cliente_user_id FROM $table_ca WHERE local_codigo = %s", $local_codigo));
            $ids_rel = array_values(array_unique(array_map('intval', (array)$ids_rel)));
        }

        $q = new WP_User_Query([
            'role' => 'um_almacen',
            'number' => 1,
            'meta_query' => [
                [
                    'key' => 'mlv_local_codigo',
                    'value' => $local_codigo,
                    'compare' => '=',
                ],
            ],
        ]);
        $res = $q->get_results();
        if (is_array($res) && !empty($res)) return $res[0];
        return null;
    }

    private static function find_almacen_by_nombre_fallback(string $nombre): ?WP_User {
        $nombre = trim((string)$nombre);
        if ($nombre === '') return null;

        $q = new WP_User_Query([
            'role' => 'um_almacen',
            'number' => 1,
            'meta_query' => [
                [
                    'key' => 'mlv_local_nombre',
                    'value' => $nombre,
                    'compare' => '=',
                ],
            ],
        ]);
        $res = $q->get_results();
        if (is_array($res) && !empty($res)) return $res[0];

        $q2 = new WP_User_Query([
            'role' => 'um_almacen',
            'number' => 1,
            'search' => $nombre,
            'search_columns' => ['display_name','user_login'],
        ]);
        $res2 = $q2->get_results();
        if (is_array($res2) && !empty($res2)) return $res2[0];

        return null;
    }

    private static function user_label(int $user_id): string {
        if ($user_id <= 0) return '—';
        $u = get_userdata($user_id);
        if (!$u) return '—';

        $name = $u->display_name ?: $u->user_login;
        $rut  = (string) get_user_meta($user_id, 'mlv_rut', true);
        $rutf = class_exists('MLV2_RUT') ? MLV2_RUT::format($rut) : $rut;

        if ($rutf !== '') {
            return $name . ' – ' . $rutf;
        }
        return $name;
    }

    private static function user_info_rows(int $user_id): array {
        $u = get_userdata($user_id);
        return [
            'Nombre'   => $u ? ($u->display_name ?: $u->user_login) : '—',
            'Email'    => $u ? $u->user_email : '—',
            'RUT'      => (string) get_user_meta($user_id, 'mlv_rut', true),
            'Teléfono' => (string) get_user_meta($user_id, 'mlv_telefono', true),
        ];
    }

    private static function resolve_local_nombre_for_user(int $user_id): string {
        // Preferir siempre el nombre actual del almacén asociado al código local.
        // Esto evita que un cliente/gestor se quede con un nombre antiguo guardado en su propio meta.
        $local_val = trim(self::get_local_codigo($user_id));
        if ($local_val !== '') {
            $alm = self::find_almacen_by_local($local_val);
            if ($alm) {
                $n = trim((string)get_user_meta($alm->ID, 'mlv_local_nombre', true));
                if ($n !== '') return $n;
                return $alm->display_name ?: $alm->user_login;
            }

            $alm2 = self::find_almacen_by_nombre_fallback($local_val);
            if ($alm2) {
                $n = trim((string)get_user_meta($alm2->ID, 'mlv_local_nombre', true));
                if ($n !== '') return $n;
                return $alm2->display_name ?: $alm2->user_login;
            }
        }

        // Fallback final: lo que el usuario tenga guardado (puede estar desactualizado).
        $local_nombre = trim(self::get_local_nombre($user_id));
        if ($local_nombre !== '') return $local_nombre;

        return '';
    }

    private static function kpis_for_cliente(int $cliente_id): array {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN clasificacion_mov='operacion' AND NOT (origen_saldo='incentivo' AND monto_calculado>0) THEN 1 ELSE 0 END),0) AS registros,
                COALESCE(SUM(CASE WHEN monto_calculado>0 AND origen_saldo='reciclaje' THEN cantidad_latas ELSE 0 END),0) AS latas,
                COALESCE(SUM(CASE WHEN monto_calculado>0 AND origen_saldo='reciclaje' AND clasificacion_mov='operacion' THEN monto_calculado ELSE 0 END),0) AS reciclaje,
                COALESCE(SUM(CASE WHEN origen_saldo='incentivo' THEN monto_calculado ELSE 0 END),0) AS incentivo,
                COALESCE(SUM(CASE WHEN monto_calculado<0 AND clasificacion_mov='operacion' THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos,
                COALESCE(SUM(monto_calculado),0) AS saldo_calc
             FROM {$table}
             WHERE 1=1 AND deleted_at IS NULL AND cliente_user_id=%d",
            $cliente_id
        ), ARRAY_A);

        $saldo = (int)($row['saldo_calc'] ?? 0);

        return [
            'registros' => (int)($row['registros'] ?? 0),
            'latas'     => (int)($row['latas'] ?? 0),
            'reciclaje' => (int)($row['reciclaje'] ?? 0),
            'incentivo' => (int)($row['incentivo'] ?? 0),
            'gastos'    => (int)($row['gastos'] ?? 0),
            'saldo'     => $saldo,
            'saldo_calc' => (int)($row['saldo_calc'] ?? 0),
        ];
    }


    /**
     * KPIs del cliente filtrados por local.
     * Nota: el "saldo" aquí es el saldo neto generado en ese local (ingresos - gastos),
     * no reemplaza el saldo total del monedero.
     */
    private static function kpis_for_cliente_local(int $cliente_id, string $local_codigo): array {
        $local_codigo = trim((string)$local_codigo);
        if ($cliente_id <= 0 || $local_codigo === '') {
            return ['registros'=>0,'latas'=>0,'reciclaje'=>0,'incentivo'=>0,'gastos'=>0,'saldo'=>0];
        }

        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN clasificacion_mov='operacion' AND NOT (origen_saldo='incentivo' AND monto_calculado>0) THEN 1 ELSE 0 END),0) AS registros,
                COALESCE(SUM(CASE WHEN monto_calculado>0 AND origen_saldo='reciclaje' THEN cantidad_latas ELSE 0 END),0) AS latas,
                COALESCE(SUM(CASE WHEN monto_calculado>0 AND origen_saldo='reciclaje' AND clasificacion_mov='operacion' THEN monto_calculado ELSE 0 END),0) AS reciclaje,
                COALESCE(SUM(CASE WHEN origen_saldo='incentivo' THEN monto_calculado ELSE 0 END),0) AS incentivo,
                COALESCE(SUM(CASE WHEN monto_calculado<0 AND clasificacion_mov='operacion' THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos,
                COALESCE(SUM(monto_calculado),0) AS saldo_calc
             FROM {$table}
             WHERE 1=1 AND deleted_at IS NULL AND cliente_user_id=%d AND local_codigo=%s",
            $cliente_id,
            $local_codigo
        ), ARRAY_A);

        $reciclaje = (int)($row['reciclaje'] ?? 0);
        $incentivo = (int)($row['incentivo'] ?? 0);
        $gastos = (int)($row['gastos'] ?? 0);
        $saldo_local = (int)($row['saldo_calc'] ?? 0);

        return [
            'registros' => (int)($row['registros'] ?? 0),
            'latas'     => (int)($row['latas'] ?? 0),
            'reciclaje' => $reciclaje,
            'incentivo' => $incentivo,
            'gastos'    => $gastos,
            'saldo'     => $saldo_local,
        ];
    }
    private static function kpis_for_cliente_total(int $cliente_id): array {
        if ($cliente_id <= 0) {
            return ['registros'=>0,'latas'=>0,'reciclaje'=>0,'incentivo'=>0,'gastos'=>0,'saldo'=>0];
        }

        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN clasificacion_mov='operacion' AND NOT (origen_saldo='incentivo' AND monto_calculado>0) THEN 1 ELSE 0 END),0) AS registros,
                COALESCE(SUM(CASE WHEN monto_calculado>0 AND origen_saldo='reciclaje' THEN cantidad_latas ELSE 0 END),0) AS latas,
                COALESCE(SUM(CASE WHEN monto_calculado>0 AND origen_saldo='reciclaje' AND clasificacion_mov='operacion' THEN monto_calculado ELSE 0 END),0) AS reciclaje,
                COALESCE(SUM(CASE WHEN origen_saldo='incentivo' THEN monto_calculado ELSE 0 END),0) AS incentivo,
                COALESCE(SUM(CASE WHEN monto_calculado<0 AND clasificacion_mov='operacion' THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos,
                COALESCE(SUM(monto_calculado),0) AS saldo_calc
             FROM {$table}
             WHERE 1=1 AND deleted_at IS NULL AND cliente_user_id=%d",
            $cliente_id
        ), ARRAY_A);

        $reciclaje = (int)($row['reciclaje'] ?? 0);
        $incentivo = (int)($row['incentivo'] ?? 0);
        $gastos = (int)($row['gastos'] ?? 0);
        $saldo = (int)($row['saldo_calc'] ?? 0);

        return [
            'registros' => (int)($row['registros'] ?? 0),
            'latas'     => (int)($row['latas'] ?? 0),
            'reciclaje' => $reciclaje,
            'incentivo' => $incentivo,
            'gastos'    => $gastos,
            'saldo'     => $saldo,
        ];
    }

    private static function kpis_for_created_by(int $user_id): array {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS registros,
                COALESCE(SUM(cantidad_latas),0) AS latas,
                COALESCE(SUM(monto_calculado),0) AS monto
             FROM {$table}
             WHERE 1=1 AND deleted_at IS NULL AND created_by_user_id=%d",
            $user_id
        ), ARRAY_A);

        return [
            'registros' => (int)($row['registros'] ?? 0),
            'latas'     => (int)($row['latas'] ?? 0),
            'monto'     => (int)($row['monto'] ?? 0),
        ];
    }

    /**
     * KPIs del monedero por local (código de local).
     * - latas/monto: solo ingresos
     * - gastos: solo gastos
     * - saldo: suma neta desde ledger en ese local
     */
    private static function kpis_for_local_code(string $local_code): array {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN clasificacion_mov='operacion' AND NOT (origen_saldo='incentivo' AND monto_calculado>0) THEN 1 ELSE 0 END),0) AS registros,
                COALESCE(SUM(CASE WHEN monto_calculado>0 AND origen_saldo='reciclaje' THEN cantidad_latas ELSE 0 END),0) AS latas,
                COALESCE(SUM(CASE WHEN monto_calculado>0 AND origen_saldo='reciclaje' AND clasificacion_mov='operacion' THEN monto_calculado ELSE 0 END),0) AS reciclaje,
                COALESCE(SUM(CASE WHEN origen_saldo='incentivo' THEN monto_calculado ELSE 0 END),0) AS incentivo,
                COALESCE(SUM(CASE WHEN monto_calculado<0 AND clasificacion_mov='operacion' THEN ABS(monto_calculado) ELSE 0 END),0) AS gastos
             FROM {$table}
             WHERE 1=1 AND deleted_at IS NULL AND local_codigo=%s",
            $local_code
        ), ARRAY_A);

        // Saldo del local por ledger (fuente de verdad): suma neta de movimientos vigentes del local.
        $saldo_row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(monto_calculado),0) AS saldo
             FROM {$table}
             WHERE deleted_at IS NULL AND local_codigo=%s",
            $local_code
        ), ARRAY_A);

        return [
            'registros' => (int)($row['registros'] ?? 0),
            'latas'  => (int)($row['latas'] ?? 0),
            'reciclaje'  => (int)($row['reciclaje'] ?? 0),
            'incentivo'  => (int)($row['incentivo'] ?? 0),
            'gastos' => (int)($row['gastos'] ?? 0),
            'saldo'  => (int)($saldo_row['saldo'] ?? 0),
        ];
    }


    private static function decode_detalle(?string $json): array {
        if (empty($json)) return [];
        $d = json_decode((string)$json, true);
        return is_array($d) ? $d : [];
    }

    private static function mov_tipo_y_extra(array $mov, array $detalle, bool $is_gasto): array {
        if ($is_gasto) {
            return ['label' => 'Gasto', 'extra' => ''];
        }
        $origen = (string)($mov['origen_saldo'] ?? '');
        if ($origen === 'incentivo' || !empty($detalle['incentivo'])) {
            $tipo = trim((string)($detalle['incentivo']['tipo'] ?? ''));
            $motivo = trim((string)($detalle['incentivo']['motivo'] ?? ''));
            $extra = '';
            if ($tipo !== '' && $motivo !== '') $extra = $tipo . ' — ' . $motivo;
            elseif ($tipo !== '') $extra = $tipo;
            elseif ($motivo !== '') $extra = $motivo;
            return ['label' => 'Incentivo', 'extra' => $extra];
        }
        return ['label' => 'Ingreso', 'extra' => ''];
    }

    /**
     * Cuenta movimientos según filtros (para paginación).
     */
    private static function movimientos_count(array $args): int {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $where = ['1=1', 'deleted_at IS NULL'];
        $params = [];

        if (!empty($args['cliente_user_id'])) { $where[] = "cliente_user_id=%d"; $params[] = (int)$args['cliente_user_id']; }
        if (!empty($args['created_by_user_id'])) { $where[] = "created_by_user_id=%d"; $params[] = (int)$args['created_by_user_id']; }
        if (!empty($args['local_codigo'])) { $where[] = "local_codigo=%s"; $params[] = (string)$args['local_codigo']; }

        if (!empty($args['estado_in']) && is_array($args['estado_in'])) {
            $in = array_values(array_filter($args['estado_in']));
            if (!empty($in)) {
                $place = implode(',', array_fill(0, count($in), '%s'));
                $where[] = "estado IN ($place)";
                foreach ($in as $st) $params[] = (string)$st;
            }
        }

        if (!empty($args['estado_not_in']) && is_array($args['estado_not_in'])) {
            $not_in = array_values(array_filter($args['estado_not_in']));
            if (!empty($not_in)) {
                $place = implode(',', array_fill(0, count($not_in), '%s'));
                $where[] = "estado NOT IN ($place)";
                foreach ($not_in as $st) $params[] = (string)$st;
            }
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);
        $n = !empty($params) ? (int)$wpdb->get_var($wpdb->prepare($sql, ...$params)) : (int)$wpdb->get_var($sql);
        return max(0, $n);
    }

    /**
     * Suma (por cliente) el monto_calculado de los N movimientos más recientes del mismo filtro.
     * Útil para calcular el saldo inicial de una página (offset) al paginar en ORDER BY id DESC.
     */
    private static function movimientos_sum_newer_by_cliente(array $args, int $offset): array {
        if ($offset <= 0) return [];
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $where = ['1=1', 'deleted_at IS NULL'];
        $params = [];

        if (!empty($args['cliente_user_id'])) { $where[] = "cliente_user_id=%d"; $params[] = (int)$args['cliente_user_id']; }
        if (!empty($args['created_by_user_id'])) { $where[] = "created_by_user_id=%d"; $params[] = (int)$args['created_by_user_id']; }
        if (!empty($args['local_codigo'])) { $where[] = "local_codigo=%s"; $params[] = (string)$args['local_codigo']; }

        if (!empty($args['estado_in']) && is_array($args['estado_in'])) {
            $in = array_values(array_filter($args['estado_in']));
            if (!empty($in)) {
                $place = implode(',', array_fill(0, count($in), '%s'));
                $where[] = "estado IN ($place)";
                foreach ($in as $st) $params[] = (string)$st;
            }
        }
        if (!empty($args['estado_not_in']) && is_array($args['estado_not_in'])) {
            $not_in = array_values(array_filter($args['estado_not_in']));
            if (!empty($not_in)) {
                $place = implode(',', array_fill(0, count($not_in), '%s'));
                $where[] = "estado NOT IN ($place)";
                foreach ($not_in as $st) $params[] = (string)$st;
            }
        }

        // Subquery con LIMIT offset (los más recientes fuera de la página actual)
        $offset = max(0, (int)$offset);
        $inner = "SELECT cliente_user_id, monto_calculado FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT {$offset}";
        $sql = "SELECT cliente_user_id, COALESCE(SUM(CAST(monto_calculado AS SIGNED)),0) AS s FROM ({$inner}) t GROUP BY cliente_user_id";

        $rows = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $map = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $cid = (int)($r['cliente_user_id'] ?? 0);
                if ($cid <= 0) continue;
                $map[$cid] = (int)($r['s'] ?? 0);
            }
        }
        return $map;
    }

    private static function movimientos_query(array $args): array {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $where = ['1=1', 'deleted_at IS NULL'];
        $params = [];

        if (!empty($args['cliente_user_id'])) { $where[] = "cliente_user_id=%d"; $params[] = (int)$args['cliente_user_id']; }
        if (!empty($args['created_by_user_id'])) { $where[] = "created_by_user_id=%d"; $params[] = (int)$args['created_by_user_id']; }
        if (!empty($args['local_codigo'])) { $where[] = "local_codigo=%s"; $params[] = (string)$args['local_codigo']; }

        if (!empty($args['estado_in']) && is_array($args['estado_in'])) {
            $in = array_values(array_filter($args['estado_in']));
            if (!empty($in)) {
                $place = implode(',', array_fill(0, count($in), '%s'));
                $where[] = "estado IN ($place)";
                foreach ($in as $st) $params[] = (string)$st;
            }
        }

        // Excluir estados (útil para mostrar movimientos sin anulado/rechazado, etc.)
        if (!empty($args['estado_not_in']) && is_array($args['estado_not_in'])) {
            $not_in = array_values(array_filter($args['estado_not_in']));
            if (!empty($not_in)) {
                $place = implode(',', array_fill(0, count($not_in), '%s'));
                $where[] = "estado NOT IN ($place)";
                foreach ($not_in as $st) $params[] = (string)$st;
            }
        }

        $limit = !empty($args['limit']) ? (int)$args['limit'] : 50;
        if ($limit < 1) $limit = 50;
        if ($limit > 250) $limit = 250;

        $offset = !empty($args['offset']) ? (int)$args['offset'] : 0;
        if ($offset < 0) $offset = 0;

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
        $rows = !empty($params) ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Agrupa incentivos "por local" en una sola fila para la vista "Todos los clientes".
     */
    private static function aggregate_local_incentives(array $rows): array {
        if (empty($rows)) return $rows;

        $out = [];
        $groups = [];

        foreach ($rows as $r) {
            $detalle = self::decode_detalle($r['detalle'] ?? '');
            $is_local = !empty($detalle['incentivo']['modo']) && $detalle['incentivo']['modo'] === 'local';
            if (!$is_local) {
                $out[] = $r;
                continue;
            }

            $local = (string)($r['local_codigo'] ?? '');
            $tipo = (string)($detalle['incentivo']['tipo'] ?? '');
            $motivo = (string)($detalle['incentivo']['motivo'] ?? '');
            $pozo = (int)($detalle['incentivo']['pozo_total'] ?? 0);
            $created_at = (string)($r['created_at'] ?? '');
            $key = md5($local . '|' . $created_at . '|' . $tipo . '|' . $motivo . '|' . $pozo);

            if (!isset($groups[$key])) {
                $g = $r;
                $g['_mlv_agg'] = true;
                $g['_mlv_clientes_total'] = (int)($detalle['incentivo']['clientes_total'] ?? 0);
                $g['monto_calculado'] = $pozo > 0 ? $pozo : (int)($r['monto_calculado'] ?? 0);
                $groups[$key] = $g;
            } else {
                $groups[$key]['monto_calculado'] += (int)($r['monto_calculado'] ?? 0);
                $groups[$key]['_mlv_clientes_total'] += 1;
            }
        }

        if (!empty($groups)) {
            $out = array_merge($out, array_values($groups));
        }

        usort($out, function($a, $b){
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });
        return $out;
    }

    /**
     * Render de paginación (desktop). En móvil, este bloque se oculta por CSS.
     */
    private static function render_pagination_links(int $page, int $per_page, int $total, array $keep_query = [], string $page_param = 'mlv2_p'): string {
        $page = max(1, (int)$page);
        $per_page = max(1, (int)$per_page);
        $total_pages = (int) ceil($total / $per_page);
        if ($total_pages <= 1) return '';

        // Ventana de páginas (compacta)
        $win = 2;
        $start = max(1, $page - $win);
        $end = min($total_pages, $page + $win);

        $build_url = function(int $p) use ($keep_query, $page_param) {
            $qs = $keep_query;
            $qs[$page_param] = (string)$p;
            // Sanitizar valores
            $qs2 = [];
            foreach ($qs as $k => $v) {
                $k = sanitize_key((string)$k);
                if (is_array($v)) continue;
                $qs2[$k] = sanitize_text_field((string)$v);
            }
            return add_query_arg($qs2, '');
        };

        $out  = '<nav class="mlv2-pagination" aria-label="Paginación">';
        $out .= '<a class="mlv2-page" href="' . esc_url($build_url(max(1, $page - 1))) . '"' . ($page <= 1 ? ' aria-disabled="true"' : '') . '>Anterior</a>';

        if ($start > 1) {
            $out .= '<a class="mlv2-page" href="' . esc_url($build_url(1)) . '">1</a>';
            if ($start > 2) $out .= '<span class="mlv2-ellipsis">…</span>';
        }

        for ($p = $start; $p <= $end; $p++) {
            $cls = 'mlv2-page' . ($p === $page ? ' is-active' : '');
            $out .= '<a class="' . esc_attr($cls) . '" href="' . esc_url($build_url($p)) . '">' . (int)$p . '</a>';
        }

        if ($end < $total_pages) {
            if ($end < $total_pages - 1) $out .= '<span class="mlv2-ellipsis">…</span>';
            $out .= '<a class="mlv2-page" href="' . esc_url($build_url($total_pages)) . '">' . (int)$total_pages . '</a>';
        }

        $out .= '<a class="mlv2-page" href="' . esc_url($build_url(min($total_pages, $page + 1))) . '"' . ($page >= $total_pages ? ' aria-disabled="true"' : '') . '>Siguiente</a>';
        $out .= '</nav>';
        return $out;
    }

    /**
     * Render del botón "Cargar más" (móvil). En desktop se oculta por CSS.
     */
    private static function render_load_more(string $context, int $page, int $per_page, int $total, array $payload = []): string {
        $total_pages = (int) ceil(max(0, $total) / max(1, $per_page));
        if ($total_pages <= 1) return '';
        $next = $page + 1;
        if ($next > $total_pages) return '';

        $data = array_merge([
            'context' => $context,
            'page' => (string)$page,
            'per_page' => (string)$per_page,
            'total_pages' => (string)$total_pages,
        ], $payload);

        $attrs = '';
        foreach ($data as $k => $v) {
            $k = preg_replace('/[^a-z0-9_\-]/i', '', (string)$k);
            $attrs .= ' data-' . esc_attr($k) . '="' . esc_attr((string)$v) . '"';
        }

        $out  = '<div class="mlv2-loadmore"' . $attrs . '>';
        $out .= '<button type="button" class="mlv2-btn mlv2-btn--secondary mlv2-loadmore-btn">Cargar más</button>';
        $out .= '<div class="mlv2-loadmore-status" aria-live="polite"></div>';
        $out .= '</div>';
        return $out;
    }

    /**
     * Endpoint helper (AJAX) para devolver filas HTML de movimientos.
     * Devuelve: ['html'=>string,'has_more'=>bool,'next_page'=>int]
     */
    public static function ajax_movimientos_page(string $context, array $filters): array {
        $context = $context === 'almacen' ? 'almacen' : 'cliente';

        $page = isset($filters['page']) ? (int)$filters['page'] : 1;
        $per_page = isset($filters['per_page']) ? (int)$filters['per_page'] : 15;
        $page = max(1, $page);
        if ($per_page < 5) $per_page = 5;
        if ($per_page > 100) $per_page = 100;
        $offset = ($page - 1) * $per_page;

        $uid = get_current_user_id();

        if ($context === 'cliente') {
            $local = isset($filters['local_codigo']) ? sanitize_text_field((string)$filters['local_codigo']) : '';

            $args = [
                'cliente_user_id' => $uid,
                'limit' => $per_page,
                'offset' => $offset,
            ];
            if ($local !== '') $args['local_codigo'] = $local;

            $total = self::movimientos_count($args);
            $rows  = self::movimientos_query($args);

            // Saldo inicial de esta página: saldo actual - suma de movimientos más recientes (offset)
            $saldo_start = null;
            if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'get_saldo_cliente')) {
                $saldo_start = (float) MLV2_Ledger::get_saldo_cliente($uid);
                $sum_newer = self::movimientos_sum_newer_by_cliente($args, $offset);
                if (isset($sum_newer[$uid])) {
                    $saldo_start = (float)$saldo_start - (float)$sum_newer[$uid];
                }
            }

            $html = self::render_movimientos_rows_cliente($rows, $uid, $saldo_start);
            $has_more = ($offset + count($rows)) < $total;
            return [
                'html' => $html,
                'has_more' => $has_more,
                'next_page' => $has_more ? ($page + 1) : $page,
            ];
        }

        // almacén
        $selected_cliente = isset($filters['cliente_user_id']) ? (int)$filters['cliente_user_id'] : 0;
        $local_codigo = trim((string) get_user_meta($uid, 'mlv_local_codigo', true));
        $args = [
            'limit' => $per_page,
            'offset' => $offset,
        ];
        if ($local_codigo !== '') { $args['local_codigo'] = $local_codigo; }
        if ($selected_cliente > 0) $args['cliente_user_id'] = $selected_cliente;

        $total = self::movimientos_count($args);
        $rows  = self::movimientos_query($args);
        if ($selected_cliente <= 0) {
            $rows = self::aggregate_local_incentives($rows);
        }

        // Saldo inicial por cliente: saldo actual - suma de "newer" (offset)
        $saldo_running = [];
        if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'get_saldo_cliente')) {
            // clientes presentes en esta página
            $uids = [];
            foreach ($rows as $r) {
                $cid = (int)($r['cliente_user_id'] ?? 0);
                if ($cid > 0) $uids[$cid] = true;
            }
            foreach (array_keys($uids) as $cid) {
                $saldo_running[$cid] = (float) MLV2_Ledger::get_saldo_cliente($cid);
            }
            $sum_newer = self::movimientos_sum_newer_by_cliente($args, $offset);
            foreach ($sum_newer as $cid => $s) {
                if (isset($saldo_running[$cid])) {
                    $saldo_running[$cid] = (float)$saldo_running[$cid] - (float)$s;
                }
            }
        }

        $html = self::render_movimientos_rows_almacen($rows, $saldo_running, []);
        $has_more = ($offset + count($rows)) < $total;
        return [
            'html' => $html,
            'has_more' => $has_more,
            'next_page' => $has_more ? ($page + 1) : $page,
        ];
    }

    /**
     * Render solo de filas para tabla de Cliente (para paginación / AJAX).
     */
    private static function render_movimientos_rows_cliente(array $movs, int $uid, $saldo_start = null): string {
        if (empty($movs)) return '';

        $saldo_running = $saldo_start;
        if ($saldo_running === null && class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'get_saldo_cliente')) {
            $saldo_running = (float) MLV2_Ledger::get_saldo_cliente($uid);
        }

        $out = '';
        foreach ($movs as $mov) {
            $detalle = self::decode_detalle($mov['detalle'] ?? '');
            $evid = class_exists('MLV2_Movimientos') ? MLV2_Movimientos::extract_evidencia_url($detalle) : '';
            $latas = (int)($mov['cantidad_latas'] ?? 0);

            $is_gasto = class_exists('MLV2_Movimientos')
                ? MLV2_Movimientos::is_gasto_row($mov, $detalle)
                : ((int)($mov['monto_calculado'] ?? 0) < 0);

            $tipo = $is_gasto ? 'gasto' : 'ingreso';
            $tipo_info = self::mov_tipo_y_extra($mov, $detalle, $is_gasto);
            $tipo_label = $tipo_info['label'];
            $tipo_extra = $tipo_info['extra'];

            $monto_effective = class_exists('MLV2_Movimientos')
                ? MLV2_Movimientos::monto_efectivo($mov, $detalle)
                : (int)($mov['monto_calculado'] ?? 0);

            if ($monto_effective === 0) $monto_label = '—';
            elseif ($monto_effective < 0) $monto_label = '-$' . number_format_i18n(abs($monto_effective));
            else $monto_label = '$' . number_format_i18n($monto_effective);

            $local_codigo = (string)($mov['local_codigo'] ?? '');
            $alm = $local_codigo !== '' ? self::find_almacen_by_local($local_codigo) : null;
            $local_nombre = $alm ? trim((string)get_user_meta($alm->ID, 'mlv_local_nombre', true)) : '';
            if ($local_nombre === '' && $alm) $local_nombre = ($alm->display_name ?: $alm->user_login);

            $rut_cell = (string) get_user_meta($uid, 'mlv_rut', true);
            if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','format')) { $rut_cell = MLV2_RUT::format($rut_cell); }

            $vpl = (int)($mov['valor_por_lata'] ?? 0);
            if ($tipo === 'gasto') {
                $vpl_cell = '—';
            } elseif ($vpl > 0) {
                $vpl_cell = self::money((float)$vpl);
            } else {
                $mc = (int)($mov['monto_calculado'] ?? 0);
                $vpl_cell = ($latas > 0 && $mc > 0 && ($mc % $latas) === 0) ? self::money((float)($mc / $latas)) : '—';
            }

            $saldo_cell = '—';
            if ($saldo_running !== null) {
                $saldo_cell = self::format_money((int)$saldo_running);
            }

            $out .= '<tr class="mlv2-mov mlv2-mov--' . esc_attr($tipo) . '">';
            $out .= '<td data-label="Fecha">' . self::esc($mov['created_at'] ?? '') . '</td>';
            $tipo_cell = '<div class="mlv2-mov-type"><div>' . self::esc($tipo_label) . '</div>';
            if ($tipo_extra !== '') {
                $tipo_cell .= '<small class="mlv2-small">' . self::esc($tipo_extra) . '</small>';
            }
            $tipo_cell .= '</div>';
            $out .= '<td data-label="Tipo">' . $tipo_cell . '</td>';
            $out .= '<td data-label="Nombre Local">' . self::esc($local_nombre ?: '—') . '</td>';
            $out .= '<td data-label="Tu RUT">' . self::esc($rut_cell ?: '—') . '</td>';
            $out .= '<td data-label="Latas">' . self::esc(($tipo === 'gasto') ? '—' : $latas) . '</td>';
            $out .= '<td data-label="Valor por lata">' . $vpl_cell . '</td>';
            $out .= '<td data-label="Evidencia">' . (($tipo === 'gasto') ? '—' : ($evid ? '<a class="mlv2-link" href="' . esc_url($evid) . '" target="_blank" rel="noopener">Ver</a>' : '—')) . '</td>';
            $out .= '<td data-label="Monto">' . self::esc($monto_label) . '</td>';
            $out .= '<td data-label="Saldo">' . self::esc($saldo_cell) . '</td>';
            $out .= '</tr>';

            if ($saldo_running !== null) {
                $saldo_running = (float)$saldo_running - (float)$monto_effective;
            }
        }

        return $out;
    }

    /**
     * Render solo de filas para tabla de Almacén (para paginación / AJAX).
     */
    private static function render_movimientos_rows_almacen(array $rows, array &$saldo_running, array $muted_states): string {
        if (empty($rows)) return '';

        $out = '';
        foreach ($rows as $mov) {
            $estado = (string)($mov['estado'] ?? '');
            $muted = in_array($estado, (array)$muted_states, true);

            $detalle = self::decode_detalle($mov['detalle'] ?? '');
            $evid = class_exists('MLV2_Movimientos') ? MLV2_Movimientos::extract_evidencia_url($detalle) : '';
            $latas = (int)($mov['cantidad_latas'] ?? 0);

            $is_gasto = class_exists('MLV2_Movimientos')
                ? MLV2_Movimientos::is_gasto_row($mov, $detalle)
                : ((int)($mov['monto_calculado'] ?? 0) < 0);

            $tipo = $is_gasto ? 'gasto' : 'ingreso';
            $tipo_info = self::mov_tipo_y_extra($mov, $detalle, $is_gasto);
            $tipo_label = $tipo_info['label'];
            $tipo_extra = $tipo_info['extra'];

            $monto_effective = class_exists('MLV2_Movimientos')
                ? MLV2_Movimientos::monto_efectivo($mov, $detalle)
                : (int)($mov['monto_calculado'] ?? 0);

            if ($monto_effective === 0) $monto_label = '—';
            elseif ($monto_effective < 0) $monto_label = '-$' . number_format_i18n(abs($monto_effective));
            else $monto_label = '$' . number_format_i18n($monto_effective);

            $cliente_uid = (int)($mov['cliente_user_id'] ?? 0);
            $cliente = '';
            if ($cliente_uid > 0) {
                $cu = get_userdata($cliente_uid);
                if ($cu) $cliente = trim((string)($cu->display_name ?: $cu->user_login));
            }
            if ($cliente === '') $cliente = (string)($mov['cliente_rut'] ?? '—');
            if (!empty($mov['_mlv_agg'])) {
                $cnt = (int)($mov['_mlv_clientes_total'] ?? 0);
                $cliente = $cnt > 0 ? ($cnt . ' clientes') : 'Clientes';
            }

            $cliente_rut_cell = '';
            if ($cliente_uid > 0) $cliente_rut_cell = (string) get_user_meta($cliente_uid, 'mlv_rut', true);
            if ($cliente_rut_cell === '') $cliente_rut_cell = (string)($mov['cliente_rut'] ?? '');
            if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','format')) { $cliente_rut_cell = MLV2_RUT::format($cliente_rut_cell); }
            if (!empty($mov['_mlv_agg'])) {
                $cliente_rut_cell = '—';
            }

            $vpl = (int)($mov['valor_por_lata'] ?? 0);
            if ($tipo === 'gasto') {
                $vpl_cell = '—';
            } elseif ($vpl > 0) {
                $vpl_cell = self::money((float)$vpl);
            } else {
                $mc = (int)($mov['monto_calculado'] ?? 0);
                $vpl_cell = ($latas > 0 && $mc > 0 && ($mc % $latas) === 0) ? self::money((float)($mc / $latas)) : '—';
            }

            $tipo_safe = in_array($tipo, ['ingreso','gasto'], true) ? $tipo : 'ingreso';
            $row_classes = ['mlv2-mov', 'mlv2-mov--' . $tipo_safe];
            if ($muted) $row_classes[] = 'mlv2-row--muted';

            $saldo_cell = '—';
            if (empty($mov['_mlv_agg']) && $cliente_uid > 0 && isset($saldo_running[$cliente_uid])) {
                $saldo_cell = self::format_money((int)$saldo_running[$cliente_uid]);
            }

            $out .= '<tr class="' . esc_attr(implode(' ', $row_classes)) . '">';
            $out .= '<td data-label="Fecha">' . self::esc($mov['created_at'] ?? '') . '</td>';
            $tipo_cell = '<div class="mlv2-mov-type"><div>' . self::esc($tipo_label) . '</div>';
            if ($tipo_extra !== '') {
                $tipo_cell .= '<small class="mlv2-small">' . self::esc($tipo_extra) . '</small>';
            }
            $tipo_cell .= '</div>';
            $out .= '<td data-label="Tipo">' . $tipo_cell . '</td>';
            $out .= '<td data-label="Cliente">' . self::esc($cliente) . '</td>';
            $out .= '<td data-label="RUT">' . self::esc($cliente_rut_cell ?: '—') . '</td>';
            $out .= '<td data-label="Latas">' . self::esc(($tipo === 'gasto') ? '—' : $latas) . '</td>';
            $out .= '<td data-label="Valor por lata">' . $vpl_cell . '</td>';
            $out .= '<td data-label="Evidencia">' . (($tipo === 'gasto') ? '—' : ($evid ? '<a class="mlv2-link" href="' . esc_url($evid) . '" target="_blank" rel="noopener">Ver</a>' : '—')) . '</td>';
            $out .= '<td data-label="Monto">' . self::esc($monto_label) . '</td>';
            $out .= '<td data-label="Saldo">' . self::esc($saldo_cell) . '</td>';
            $out .= '</tr>';

            if (!empty($mov['_mlv_agg'])) {
                continue;
            }
            if ($cliente_uid > 0 && isset($saldo_running[$cliente_uid])) {
                $saldo_running[$cliente_uid] = (float)$saldo_running[$cliente_uid] - (float)$monto_effective;
            }
        }

        return $out;
    }

    private static function available_latas_por_local(string $local_codigo): int {
        if ($local_codigo === '') return 0;

        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(cantidad_latas),0) AS latas
             FROM {$table}
             WHERE local_codigo=%s
               AND deleted_at IS NULL",
            $local_codigo
        ), ARRAY_A);

        return (int)($row['latas'] ?? 0);
    }

    private static function handle_almacen_retirado_post(int $almacen_id): ?string {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return null;
        if (empty($_POST['mlv2_almacen_action']) || $_POST['mlv2_almacen_action'] !== 'set_retirado') return null;

        if (!self::user_has_role($almacen_id, 'um_almacen') && !current_user_can('manage_options')) {
            status_header(403);
            return '<div class="mlv2-alert mlv2-alert--warn"><strong>No autorizado.</strong></div>';
        }

        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'mlv2_almacen_set_retirado')) {
            status_header(400);
            return '<div class="mlv2-alert mlv2-alert--warn"><strong>Nonce inválido.</strong></div>';
        }

        $mov_id = isset($_POST['mov_id']) ? (int)$_POST['mov_id'] : 0;
        if ($mov_id <= 0) return null;

        $value = !empty($_POST['retirado']) ? 1 : 0;

        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $mov = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND deleted_at IS NULL", $mov_id), ARRAY_A);
        if (!$mov) return null;

        if ((int)($mov['created_by_user_id'] ?? 0) !== $almacen_id) {
            status_header(403);
            return '<div class="mlv2-alert mlv2-alert--warn"><strong>No puedes editar este movimiento.</strong></div>';
        }

        $patch = [
            'retirado' => [
                'value' => $value,
                'user_id' => $almacen_id,
                'ts' => current_time('mysql'),
            ],
        ];

        $estado_actual = (string)($mov['estado'] ?? '');
$nuevo_estado  = $estado_actual;

if ($value === 1) {
    $nuevo_estado = 'retirado';
} else {
    // Solo permitir volver a pendiente si era un ingreso (latas)
    if ($estado_actual === 'retirado') {
        $nuevo_estado = 'pendiente_retiro';
    }
}

$ok = MLV2_Ledger::update_estado_y_detalle($mov_id, $nuevo_estado, $patch, $almacen_id);
        if (!$ok) {
            status_header(500);
            return '<div class="mlv2-alert mlv2-alert--warn"><strong>No se pudo guardar.</strong> Intenta nuevamente.</div>';
        }

        return null;
    }

    private static function section_header(string $h2, string $desc = ''): string {
        $out  = '<div class="mlv2-section-header">';
        $out .= '<h2 class="mlv2-h2">' . self::esc($h2) . '</h2>';
        if ($desc !== '') {
            $out .= '<small class="mlv2-small">' . wp_kses_post($desc) . '</small>';
        }
        $out .= '</div>';
        return $out;
    }

    private static function card_open(string $h3, string $desc = ''): string {
        $out  = '<div class="mlv2-card um">';
        $out .= '<div class="mlv2-card__header">';
        $out .= '<h3 class="mlv2-h3">' . self::esc($h3) . '</h3>';
        if ($desc !== '') $out .= '<small class="mlv2-small">' . wp_kses_post($desc) . '</small>';
        $out .= '</div>';
        return $out;
    }

    private static function card_close(): string {
        return '</div>';
    }

    private static function render_user_info_table(int $user_id, string $role_label, string $subtitle = ''): string {
        $rows = self::user_info_rows($user_id);

        $desc = '<strong>' . self::esc($role_label) . '</strong>';
        if ($subtitle !== '') $desc .= ' · ' . self::esc($subtitle);

        $out  = self::card_open('Información', $desc);
        $out .= '<div class="mlv2-table-wrap">';
        $out .= '<table class="mlv2-info-table um-table">';
        $out .= '<tbody>';
        foreach ($rows as $k => $v) {
            $out .= '<tr>';
            $out .= '<th>' . self::esc($k) . '</th>';
            $out .= '<td>' . self::esc($v ?: '—') . '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table></div>';

        $out .= self::card_close();
        return $out;
    }

    private static function render_local_asignado_card(int $user_id): string {
        $local_nombre = self::resolve_local_nombre_for_user($user_id);
        $local_val = trim(self::get_local_codigo($user_id));

        $out = self::card_open('Local asignado', $local_nombre !== '' ? '<strong>' . self::esc($local_nombre) . '</strong>' : '');

        if ($local_nombre === '') {
            if ($local_val === '') {
                $out .= '<div class="mlv2-alert mlv2-alert--warn"><strong>No asignado:</strong> tu usuario no tiene <code>mlv_local_codigo</code>.</div>';
            } else {
                $out .= '<div class="mlv2-alert mlv2-alert--warn"><strong>No se pudo resolver el local:</strong> el valor guardado no coincide con ningún almacén.</div>';
                $out .= '<div class="mlv2-note"><small>Valor guardado en <code>mlv_local_codigo</code>: <strong>' . self::esc($local_val) . '</strong></small></div>';
            }
        }

        $out .= self::card_close();
        return $out;
    }

    private static function render_assigned_almacen_card(string $local_codigo): string {
        $alm = self::find_almacen_by_local($local_codigo);

        $out = self::card_open('Almacén asignado', 'Se determina por tu <strong>mlv_local_codigo</strong>.');
        if ($local_codigo === '') {
            $out .= '<div class="mlv2-alert mlv2-alert--warn"><strong>No asignado:</strong> no tienes <code>mlv_local_codigo</code>.</div>';
            $out .= self::card_close();
            return $out;
        }

        $alm_name = $alm ? ($alm->display_name ?: $alm->user_login) : '—';
        $alm_phone = $alm ? (string)get_user_meta($alm->ID, 'mlv_telefono', true) : '';
        $alm_email = $alm ? $alm->user_email : '';
        $alm_local_nombre = $alm ? (string)get_user_meta($alm->ID, 'mlv_local_nombre', true) : '';

        $out .= '<div class="mlv2-table-wrap"><table class="mlv2-info-table um-table"><tbody>';
        $out .= '<tr><th>Local</th><td>' . self::esc($alm_local_nombre ?: '—') . '</td></tr>';
        $out .= '<tr><th>Representante</th><td>' . self::esc($alm_name) . '</td></tr>';
        $out .= '<tr><th>Teléfono</th><td>' . self::render_whatsapp_link($alm_phone) . '</td></tr>';
        $out .= '<tr><th>Email</th><td>' . self::render_mailto_link($alm_email) . '</td></tr>';
        $out .= '</tbody></table></div>';

        $out .= self::card_close();
        return $out;
    }

    private static function render_movimientos_table_almacen(array $rows, int $almacen_id, array $opts = []): string {
        $muted_states = $opts['muted_states'] ?? [];

        $out  = self::card_open(self::esc($opts['title'] ?? 'Movimientos'), $opts['subtitle'] ?? '');
        $out .= '<div class="mlv2-table-wrap">';
        $out .= '<table class="mlv2-table um-table">';
        $out .= '<thead><tr>';
        $out .= '<th>Fecha</th><th>Tipo</th><th>Cliente</th><th>RUT</th><th>Latas</th><th>Valor por lata</th><th>Evidencia</th><th>Monto</th><th>Saldo</th>';
        $out .= '</tr></thead><tbody>';

        if (empty($rows)) {
            $out .= '<tr><td colspan="9">No hay movimientos.</td></tr>';
        } else {
            // Permite inyectar saldos iniciales por cliente (útil para paginación).
            $saldo_running = [];
            if (!empty($opts['saldo_running']) && is_array($opts['saldo_running'])) {
                $saldo_running = $opts['saldo_running'];
            } elseif (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'get_saldo_cliente')) {
                $uids = [];
                foreach ($rows as $r) {
                    $cid = (int)($r['cliente_user_id'] ?? 0);
                    if ($cid > 0) { $uids[$cid] = true; }
                }
                foreach (array_keys($uids) as $cid) {
                    $saldo_running[$cid] = (float) MLV2_Ledger::get_saldo_cliente($cid);
                }
            }

            $out .= self::render_movimientos_rows_almacen($rows, $saldo_running, (array)$muted_states);
        }

        $out .= '</tbody></table></div>';

        // Footer opcional (paginación / load more, etc.)
        if (!empty($opts['footer_html'])) {
            $out .= (string)$opts['footer_html'];
        }

        $out .= self::card_close();
        return $out;
    }

    // ============================================================
    // ✅ NUEVO: Horario bonito desde mlv_local_hours (JSON)
    // ============================================================
    private static function format_local_hours_pretty(string $hours_json): string {
        $hours_json = trim($hours_json);
        if ($hours_json === '') return '—';

        $data = json_decode($hours_json, true);
        if (!is_array($data)) return '—';

        $labels = [
            'mon' => 'Lunes',
            'tue' => 'Martes',
            'wed' => 'Miércoles',
            'thu' => 'Jueves',
            'fri' => 'Viernes',
            'sat' => 'Sábado',
            'sun' => 'Domingo',
        ];

        $lines = [];
        foreach ($labels as $k => $label) {
            $ranges = $data[$k] ?? [];
            if (!is_array($ranges) || empty($ranges)) {
                $lines[] = $label . ': Cerrado';
                continue;
            }

            // UI actual: 1 tramo
            $r = is_array($ranges[0] ?? null) ? $ranges[0] : null;
            $start = is_array($r) ? (string)($r['start'] ?? '') : '';
            $end   = is_array($r) ? (string)($r['end'] ?? '') : '';

            $start = preg_match('/^\d{2}:\d{2}$/', $start) ? $start : '—';
            $end   = preg_match('/^\d{2}:\d{2}$/', $end) ? $end : '—';

            $lines[] = $label . ': ' . $start . ' - ' . $end . ' hrs.';
        }

        // Escapamos cada línea, pero mantenemos <br>
        return implode('<br>', array_map('esc_html', $lines));
    }

    // ============================================================
    // ✅ NUEVO: Tabla info custom (misma estética) + soporta <br>
    // ============================================================
    private static function render_info_table_custom(string $title, string $desc, array $rows): string {
        $out  = self::card_open($title, $desc);
        $out .= '<div class="mlv2-table-wrap">';
        $out .= '<table class="mlv2-info-table um-table"><tbody>';

        foreach ($rows as $k => $v) {
            $out .= '<tr>';
            $out .= '<th>' . self::esc((string)$k) . '</th>';

            $vv = (string)$v;
            if (strpos($vv, '<br>') !== false) {
                $out .= '<td>' . wp_kses_post($vv) . '</td>';
            } else {
                $out .= '<td>' . self::esc($vv !== '' ? $vv : '—') . '</td>';
            }

            $out .= '</tr>';
        }

        $out .= '</tbody></table></div>';
        $out .= self::card_close();
        return $out;
    }

        /**
     * ============================================================
     * ✅ Clientes asociados a un local (para el Panel Almacén)
     * ============================================================
     */
    private static function get_clientes_by_local(string $local_codigo): array {
        $local_codigo = trim($local_codigo);
        if ($local_codigo === '') return [];
        static $cache = [];
        if (isset($cache[$local_codigo])) {
            return $cache[$local_codigo];
        }

        // Intentar resolver también el nombre del local (por compatibilidad antigua)
        $alm = self::find_almacen_by_local($local_codigo);
        $local_nombre = $alm ? trim((string)get_user_meta($alm->ID, 'mlv_local_nombre', true)) : '';
        if ($local_nombre === '' && $alm) {
            $local_nombre = $alm->display_name ?: $alm->user_login;
        }

        $meta_or = [
            'relation' => 'OR',
            [
                'key'     => 'mlv_local_codigo',
                'value'   => $local_codigo,
                'compare' => '=',
            ],
        ];

        // Compatibilidad: algunos usuarios tenían guardado el nombre del local en lugar del código
        if ($local_nombre !== '') {
            $meta_or[] = [
                'key'     => 'mlv_local_codigo',
                'value'   => $local_nombre,
                'compare' => '=',
            ];
        }


        // Nuevo modelo N-N: clientes asociados por tabla de relacion
        $ids_rel = [];
        if (class_exists('MLV2_DB')) {
            global $wpdb;
            $table_ca = MLV2_DB::table_clientes_almacenes();
            $ids_rel = $wpdb->get_col($wpdb->prepare("SELECT cliente_user_id FROM $table_ca WHERE local_codigo = %s", $local_codigo));
            $ids_rel = array_values(array_unique(array_map('intval', (array)$ids_rel)));
        }

        $q = new WP_User_Query([
            'role'       => 'um_cliente',
            'number'     => 250,
            'meta_query' => $meta_or,
            'orderby'    => 'ID',
            'order'      => 'DESC',
        ]);

        $users = $q->get_results();
        $users = is_array($users) ? $users : [];

        if (!empty($ids_rel)) {
            $q2 = new WP_User_Query([
                'role'    => 'um_cliente',
                'include' => $ids_rel,
                'number'  => max(250, count($ids_rel)),
                'orderby' => 'ID',
                'order'   => 'DESC',
            ]);
            $users2 = $q2->get_results();
            if (is_array($users2)) {
                $map = [];
                foreach ($users as $u) { $map[$u->ID] = $u; }
                foreach ($users2 as $u) { $map[$u->ID] = $u; }
                $users = array_values($map);
            }
        }

        usort($users, static function($a, $b) {
            return ((int)($b->ID ?? 0)) <=> ((int)($a->ID ?? 0));
        });

        $cache[$local_codigo] = $users;
        return $users;
    }

    /**
     * Tabla de clientes para el Panel Almacén.
     * - Muestra KPIs por cliente (filtrados por local)
     * - Permite seleccionar un cliente (via GET) para ver sub-KPIs y movimientos
     */
    private static function render_clientes_table(array $clientes, string $local_codigo, int $selected_cliente_id = 0): string {
        $local_codigo = trim((string)$local_codigo);

        $out  = self::card_open('Tus clientes', 'Generado, canjeado y consolidado por cliente (en este local).');

        // SubKPIs: siempre mostrar debajo del filtro.
        // - Si hay cliente seleccionado: KPIs del cliente (en este local)
        // - Si NO hay cliente ("Todos los clientes"): KPIs totales del local
        if ($local_codigo !== '') {
            $selected_kpis = [];
            $is_total_local = false;
            if ($selected_cliente_id > 0) {
                $selected_kpis = self::kpis_for_cliente_local($selected_cliente_id, $local_codigo);
            } elseif (method_exists(__CLASS__, 'kpis_for_local_code')) {
                $selected_kpis = self::kpis_for_local_code($local_codigo);
                $is_total_local = true;
            }

            if (!empty($selected_kpis)) {
                $out .= '<div class="mlv2-subkpis" style="margin-top:14px;">';
                $out .= '<div class="mlv2-kpis">';
                $gen = (float)(($selected_kpis['reciclaje'] ?? 0) + ($selected_kpis['incentivo'] ?? 0));
                $out .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money($gen) . '</strong><div class="mlv2-kpi__l">' . ($is_total_local ? 'Generado Total' : 'Generado') . '</div></div>';
                $out .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($selected_kpis['gastos'] ?? 0)) . '</strong><div class="mlv2-kpi__l">' . ($is_total_local ? 'Canjeado Total' : 'Canjeado') . '</div></div>';
                $out .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::money((float)($selected_kpis['saldo'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Saldo disponible</div></div>';
                $out .= '<div class="mlv2-kpi"><strong class="mlv2-kpi__v">' . self::esc((string)($selected_kpis['latas'] ?? 0)) . '</strong><div class="mlv2-kpi__l">Latas</div></div>';
                $out .= '</div>';
                $out .= '</div>';
            }
        }

        // Lista resumen (siempre)
        $out .= '<div class="mlv2-table-wrap">';
        $out .= '<table class="mlv2-table mlv2-table--cards um-table">';
        $out .= '<thead><tr><th>Cliente</th><th>Latas</th><th>Generado</th><th>Canjeado</th><th>Consolidado</th><th></th></tr></thead><tbody>';

        if (empty($clientes)) {
            $out .= '<tr><td colspan="6">No hay clientes asignados a este local.</td></tr>';
        } else {
            foreach ($clientes as $u) {
                $cid = (int)($u->ID ?? 0);
                if ($cid <= 0) continue;

                $name = $u->display_name ?: $u->user_login;
                $rut  = (string)get_user_meta($cid, 'mlv_rut', true);
                if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','format')) { $rut = MLV2_RUT::format($rut); }

                $k = self::kpis_for_cliente_local($cid, $local_codigo);
                $latas = (int)($k['latas'] ?? 0);
                $ing   = (int)(($k['reciclaje'] ?? 0) + ($k['incentivo'] ?? 0));
                $gas   = (int)($k['gastos'] ?? 0);
                $cons  = (int)($k['saldo'] ?? 0);

                // Link para seleccionar
                $qs = $_GET;
                $qs['mlv2_cliente'] = (string)$cid;
                $url = add_query_arg(array_map('sanitize_text_field', (array)$qs), '');

                $is_sel = ($selected_cliente_id === $cid);

                $cliente_label = self::esc($name) . ($rut !== '' ? '<div class="mlv2-muted" style="margin-top:2px;">' . self::esc($rut) . '</div>' : '');

                $out .= '<tr' . ($is_sel ? ' class="mlv2-row--active"' : '') . '>';
                $out .= '<td data-label="Cliente">' . wp_kses_post($cliente_label) . '</td>';
                $out .= '<td data-label="Latas">' . self::esc((string)$latas) . '</td>';
                $out .= '<td data-label="Generado">' . self::esc(self::format_money($ing)) . '</td>';
                $out .= '<td data-label="Canjeado">' . self::esc(self::format_money($gas)) . '</td>';
                $out .= '<td data-label="Consolidado">' . self::esc(self::format_money($cons)) . '</td>';
                $out .= '<td data-label="Acción">' . '<a class="mlv2-link" href="' . esc_url($url) . '">' . ($is_sel ? 'Viendo' : 'Ver') . '</a>' . '</td>';
                $out .= '</tr>';
            }
        }

        $out .= '</tbody></table>';
        $out .= '</div>';

        // Detalle (solo si hay cliente seleccionado)
        if ($selected_cliente_id > 0 && $local_codigo !== '' && method_exists(__CLASS__, 'movimientos_query')) {

            $movs = self::movimientos_query([
                'cliente_user_id' => $selected_cliente_id,
                'local_codigo'    => $local_codigo,
                'limit'           => 200,
            ]);

            // Para tabla estilo panel cliente: nombre local + rut cliente + valor por lata + saldo running
            $rut_cliente = (string) get_user_meta($selected_cliente_id, 'mlv_rut', true);
            if (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','format')) { $rut_cliente = MLV2_RUT::format($rut_cliente); }

            $alm = $local_codigo !== '' ? self::find_almacen_by_local($local_codigo) : null;
            $local_nombre = $alm ? trim((string)get_user_meta($alm->ID, 'mlv_local_nombre', true)) : '';
            if ($local_nombre === '' && $alm) $local_nombre = ($alm->display_name ?: $alm->user_login);

            $out .= self::card_open('Movimientos del cliente', 'Filtrados por tu local.');
            $out .= '<div class="mlv2-table-wrap">';
            $out .= '<table class="mlv2-table mlv2-table--cards um-table">';
            $out .= '<thead><tr><th>Fecha</th><th>Tipo</th><th>Nombre Local</th><th>Tu RUT</th><th>Latas</th><th>Valor por lata</th><th>Evidencia</th><th>Monto</th><th>Saldo</th></tr></thead><tbody>';

            if (empty($movs)) {
                $out .= '<tr><td colspan="9">No hay movimientos para este cliente en tu local.</td></tr>';
            } else {
                // Saldo actual (para mostrar saldo "después" de cada movimiento)
                $saldo_running = null;
                if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'get_saldo_cliente')) {
                    $saldo_running = (float) MLV2_Ledger::get_saldo_cliente($selected_cliente_id);
                }

                foreach ($movs as $mov) {
                    $detalle = self::decode_detalle($mov['detalle'] ?? '');
                    $evid = class_exists('MLV2_Movimientos') ? MLV2_Movimientos::extract_evidencia_url($detalle) : '';
                    $latas = (int)($mov['cantidad_latas'] ?? 0);

                    $is_gasto = class_exists('MLV2_Movimientos')
                        ? MLV2_Movimientos::is_gasto_row($mov, $detalle)
                        : ((int)($mov['monto_calculado'] ?? 0) < 0);

                    $tipo = $is_gasto ? 'gasto' : 'ingreso';
                    $tipo_label = $is_gasto ? 'Gasto' : 'Ingreso';

                    $monto_effective = class_exists('MLV2_Movimientos')
                        ? MLV2_Movimientos::monto_efectivo($mov, $detalle)
                        : (int)($mov['monto_calculado'] ?? 0);

                    $monto_label = $monto_effective === 0 ? '—' : ($monto_effective < 0
                        ? '-$' . number_format_i18n(abs($monto_effective))
                        : '$' . number_format_i18n($monto_effective)
                    );

                    // Valor por lata (trazabilidad)
                    $vpl = (int)($mov['valor_por_lata'] ?? 0);
                    if ($tipo === 'gasto') {
                        $vpl_cell = '—';
                    } elseif ($vpl > 0) {
                        $vpl_cell = self::money((float)$vpl);
                    } else {
                        $mc = (int)($mov['monto_calculado'] ?? 0);
                        $vpl_cell = ($latas > 0 && $mc > 0 && ($mc % $latas) === 0) ? self::money((float)($mc / $latas)) : '—';
                    }

                    $saldo_cell = '—';
                    if ($saldo_running !== null) {
                        $saldo_cell = self::format_money((int)$saldo_running);
                    }

                    $tipo_safe = (isset($tipo) && in_array($tipo, ['ingreso','gasto'], true)) ? $tipo : 'ingreso';
                    $out .= '<tr class="mlv2-mov mlv2-mov--' . esc_attr($tipo_safe) . '">';
                    $out .= '<td data-label="Fecha">' . self::esc((string)($mov['created_at'] ?? '')) . '</td>';
            $out .= '<td data-label="Tipo">' . self::esc($tipo_label) . '</td>';
                    $out .= '<td data-label="Nombre Local">' . self::esc($local_nombre ?: '—') . '</td>';
                    $out .= '<td data-label="Tu RUT">' . self::esc($rut_cliente ?: '—') . '</td>';
                    $out .= '<td data-label="Latas">' . self::esc(($tipo === 'gasto') ? '—' : (string)$latas) . '</td>';
                    $out .= '<td data-label="Valor por lata">' . $vpl_cell . '</td>';
                    $out .= '<td data-label="Evidencia">' . (($tipo === 'gasto') ? '—' : ($evid ? '<a class="mlv2-link" href="' . esc_url($evid) . '" target="_blank" rel="noopener">Ver</a>' : '—')) . '</td>';
                    $out .= '<td data-label="Monto">' . self::esc($monto_label) . '</td>';
                    $out .= '<td data-label="Saldo">' . self::esc($saldo_cell) . '</td>';
                    $out .= '</tr>';

                    // Ajustar saldo_running hacia atrás
                    if ($saldo_running !== null) {
                        $saldo_running = (float)$saldo_running - (float)$monto_effective;
                    }
                }
            }

            $out .= '</tbody></table>';
            $out .= '</div>';
            $out .= self::card_close();
        }

        $out .= self::card_close();
        return $out;
    }

}
