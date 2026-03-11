<?php
/**
 * Plugin Name: Mi Lata Vale Ledger V2
 * Description: Sistema digital de campañas de reciclaje con monedero cerrado y validación operativa (V2).
 * Version: 2.3.18
 * Author: Mi Lata Vale
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('MLV2_VERSION')) define('MLV2_VERSION','2.3.18');
if (!defined('MLV2_PATH')) define('MLV2_PATH', plugin_dir_path(__FILE__));
if (!defined('MLV2_URL')) define('MLV2_URL', plugin_dir_url(__FILE__));

if (!function_exists('mlv2_enqueue_assets')) {
function mlv2_enqueue_assets(): void {
    $css_path = MLV2_PATH . "assets/css/mlv2-all.css";
    $js_path  = MLV2_PATH . "assets/js/front.js";

    $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : MLV2_VERSION;
    $js_ver  = file_exists($js_path)  ? (string) filemtime($js_path)  : MLV2_VERSION;

    // ✅ Un solo CSS con todo (front + responsive + force + inline trasladado)
    wp_enqueue_style('mlv2-all', MLV2_URL . 'assets/css/mlv2-all.css', [], $css_ver);

    // JS
    wp_enqueue_script('mlv2-front', MLV2_URL . 'assets/js/front.js', [], $js_ver, true);

    wp_localize_script('mlv2-front', 'MLV2', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('mlv2_ajax'),
    ]);

    // Debug opcional: ?mlv2css=1 (solo logueados) → activa badge via data-attribute (CSS en archivo)
    static $mlv2_debug_hooked = false;
    if (!$mlv2_debug_hooked && is_user_logged_in() && isset($_GET['mlv2css']) && $_GET['mlv2css'] === '1') {
        $mlv2_debug_hooked = true;

        add_filter('body_class', function ($classes) {
            $classes[] = 'mlv2-debug-css';
            return $classes;
        });

        $label = 'MLV2 CSS ' . $css_ver;

        add_action('wp_body_open', function () use ($label) {
            echo "<script>(function(){try{document.body&&document.body.setAttribute('data-mlv2-css-debug'," . json_encode($label) . ");}catch(e){}})();</script>";
        }, 99);

        add_action('wp_footer', function () use ($label) {
            echo "<script>(function(){try{document.body&&document.body.setAttribute('data-mlv2-css-debug'," . json_encode($label) . ");}catch(e){}})();</script>";
        }, 99);
    }
}
}

// Encolar tarde para que Avada/cachés no lo pisen
add_action("wp_enqueue_scripts", "mlv2_enqueue_assets", 999);

// Fallback: algunos templates/page builders omiten partes del pipeline normal.
// Si por cualquier razón el style no quedó encolado, lo forzamos en wp_head.
add_action('wp_head', function () {
    if (!wp_style_is('mlv2-all','enqueued')) {
        mlv2_enqueue_assets();
    }
}, 1);

require_once MLV2_PATH . 'includes/core/security.php';
require_once MLV2_PATH . 'includes/core/rut.php';
require_once MLV2_PATH . 'includes/core/db.php';
require_once MLV2_PATH . 'includes/core/time.php';

add_action('plugins_loaded', function(){ if (class_exists('MLV2_DB')) { MLV2_DB::maybe_install(); } });
require_once MLV2_PATH . 'includes/core/alerts.php';
require_once MLV2_PATH . 'includes/core/audit.php';
require_once MLV2_PATH . 'includes/core/pricing.php';
require_once MLV2_PATH . 'includes/core/ledger.php';
require_once MLV2_PATH . 'includes/core/movimientos.php';
require_once MLV2_PATH . 'includes/core/movement-service.php';
require_once MLV2_PATH . 'includes/core/health.php';
require_once MLV2_PATH . 'includes/core/validation.php';

require_once MLV2_PATH . 'includes/frontend/shortcodes.php';
require_once MLV2_PATH . 'includes/frontend/ajax.php';
require_once MLV2_PATH . 'includes/frontend/login-rut.php';

/**
 * ✅ Form universal de edición de perfil
 * Shortcode: [mlv_profile_form]
 */
require_once MLV2_PATH . 'includes/frontend/profile-form.php';

if (is_admin()) {
    require_once MLV2_PATH . 'includes/admin/menu.php';
    require_once MLV2_PATH . 'includes/admin/services/export-csv.php';
}

add_action('init', function () {
    if (class_exists('MLV2_Validation') && method_exists('MLV2_Validation', 'init')) {
        MLV2_Validation::init();
    }
}, 20);

/**
 * ✅ Gate: SOLO para almacenes al entrar a /panel/
 */
add_action('template_redirect', function () {
    if (!is_user_logged_in()) return;
    if (!is_page('panel')) return;

    $user = wp_get_current_user();
    if (!$user || empty($user->ID)) return;

    if (!in_array('um_almacen', (array)$user->roles, true)) return;

    if (!function_exists('mlv_is_almacen_profile_complete')) return;

    if (!mlv_is_almacen_profile_complete((int)$user->ID)) {
        wp_safe_redirect(home_url('/account/'));
        exit;
    }
});

/**
 * ✅ UM Integration + NORMALIZACIÓN “a prueba de UM”
 */
final class MLV2_UM_Integration {

    public static function init(): void {

        add_action('user_register', [__CLASS__, 'ensure_almacen_meta'], 20, 1);

        add_action('um_after_user_register', [__CLASS__, 'after_um_register'], 20, 2);
        add_action('um_after_user_updated', [__CLASS__, 'after_um_updated'], 20, 2);
        add_action('profile_update',        [__CLASS__, 'after_wp_profile_update'], 20, 2);

        add_filter('um_selectbox_options', [__CLASS__, 'filter_options_by_key'], 10, 2);
        add_filter('um_dropdown_options',  [__CLASS__, 'filter_options_by_key'], 10, 2);
        add_filter('um_field_options_select',   [__CLASS__, 'filter_field_options_select'], 10, 1);
        add_filter('um_field_options_dropdown', [__CLASS__, 'filter_field_options_select'], 10, 1);
        add_filter('um_select_dropdown_dynamic_options_mlv_local_codigo', [__CLASS__, 'dynamic_options'], 10, 1);
        add_filter('um_predefined_fields_hook', [__CLASS__, 'predefined_fields_hook'], 10, 1);
    }

    public static function after_um_register($user_id, $args = null): void {
        $user_id = (int)$user_id;
        if ($user_id <= 0) return;

        self::ensure_almacen_meta($user_id);
        self::ensure_rut_norm($user_id);

        self::force_save_local_from_post_if_missing($user_id);
        self::normalize_user_local_meta($user_id);
    }

    public static function after_um_updated($user_id, $args = null): void {
        $user_id = (int)$user_id;
        if ($user_id <= 0) return;

        self::ensure_rut_norm($user_id);
        self::force_save_local_from_post_if_missing($user_id);
        self::normalize_user_local_meta($user_id);
    }

    public static function after_wp_profile_update($user_id, $old_user_data): void {
        $user_id = (int)$user_id;
        if ($user_id <= 0) return;

        self::ensure_rut_norm($user_id);
        self::force_save_local_from_post_if_missing($user_id);
        self::normalize_user_local_meta($user_id);
    }

    /**
     * ✅ SOLO generar mlv_local_codigo para almacenes.
     * ❌ NO autocompletar mlv_local_nombre.
     */
    public static function ensure_almacen_meta(int $user_id): void {
        if ($user_id <= 0) return;

        $u = get_userdata($user_id);
        if (!$u) return;

        $roles = (array)($u->roles ?? []);
        if (!in_array('um_almacen', $roles, true)) return;

        $local_codigo = trim((string)get_user_meta($user_id, 'mlv_local_codigo', true));
        if ($local_codigo === '') {
            $generated = 'LOC-' . str_pad((string)$user_id, 6, '0', STR_PAD_LEFT);
            update_user_meta($user_id, 'mlv_local_codigo', $generated);
        }
    }

    public static function ensure_rut_norm(int $user_id): void {
        if ($user_id <= 0) return;
        if (!class_exists('MLV2_RUT')) return;

        $rut = (string)get_user_meta($user_id, 'mlv_rut', true);
        $rut_norm = (string)get_user_meta($user_id, 'mlv_rut_norm', true);
        if ($rut !== '' && $rut_norm === '') {
            $norm = MLV2_RUT::normalize($rut);
            if ($norm !== '') {
                update_user_meta($user_id, 'mlv_rut_norm', $norm);
            }
        }
    }

    private static function force_save_local_from_post_if_missing(int $user_id): void {

        $u = get_userdata($user_id);
        if (!$u) return;

        $roles = (array)($u->roles ?? []);
        if (in_array('um_almacen', $roles, true)) return;
        if (!in_array('um_cliente', $roles, true) && !in_array('um_gestor', $roles, true)) return;

        $current = trim((string)get_user_meta($user_id, 'mlv_local_codigo', true));
        if ($current !== '') return;

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

        $val = '';
        if (isset($_POST['mlv_local_codigo'])) {
            $val = $_POST['mlv_local_codigo'];
        }
        if (is_array($val)) $val = reset($val);
        $val = is_string($val) ? trim(sanitize_text_field(wp_unslash($val))) : '';

        if ($val === '') {
            foreach ($_POST as $k => $v) {
                if (!is_string($k)) continue;
                if (stripos($k, 'mlv_local') === false) continue;

                $vv = $v;
                if (is_array($vv)) $vv = reset($vv);
                $vv = is_string($vv) ? trim(sanitize_text_field(wp_unslash($vv))) : '';
                if ($vv !== '') { $val = $vv; break; }
            }
        }

        if ($val === '') return;
        update_user_meta($user_id, 'mlv_local_codigo', $val);
    }

    private static function normalize_user_local_meta(int $user_id): void {

        $u = get_userdata($user_id);
        if (!$u) return;

        $roles = (array)($u->roles ?? []);
        if (in_array('um_almacen', $roles, true)) return;
        if (!in_array('um_cliente', $roles, true) && !in_array('um_gestor', $roles, true)) return;

        $val = trim((string)get_user_meta($user_id, 'mlv_local_codigo', true));
        if ($val === '') return;

        if (preg_match('/^LOC-\d{6}$/', $val)) {
            $alm = self::find_almacen_by_codigo($val);
            if ($alm) {
                $nombre = trim((string)get_user_meta($alm->ID, 'mlv_local_nombre', true));
                if ($nombre === '') $nombre = $alm->display_name ?: $alm->user_login;
                update_user_meta($user_id, 'mlv_local_nombre', $nombre);
            }
            return;
        }

        $alm = self::find_almacen_by_nombre($val);
        if ($alm) {
            self::ensure_almacen_meta((int)$alm->ID);

            $codigo = trim((string)get_user_meta($alm->ID, 'mlv_local_codigo', true));
            $nombre = trim((string)get_user_meta($alm->ID, 'mlv_local_nombre', true));
            if ($nombre === '') $nombre = $alm->display_name ?: $alm->user_login;

            if ($codigo !== '') {
                update_user_meta($user_id, 'mlv_local_codigo', $codigo);
                update_user_meta($user_id, 'mlv_local_nombre', $nombre);
            }
        }
    }

    private static function find_almacen_by_codigo(string $codigo): ?WP_User {
        if ($codigo === '') return null;
        $q = new WP_User_Query([
            'role' => 'um_almacen',
            'number' => 1,
            'meta_query' => [
                ['key' => 'mlv_local_codigo', 'value' => $codigo, 'compare' => '='],
            ],
        ]);
        $res = $q->get_results();
        return (!empty($res) && is_array($res)) ? $res[0] : null;
    }

    private static function find_almacen_by_nombre(string $nombre): ?WP_User {
        if ($nombre === '') return null;

        $q = new WP_User_Query([
            'role' => 'um_almacen',
            'number' => 1,
            'meta_query' => [
                ['key' => 'mlv_local_nombre', 'value' => $nombre, 'compare' => '='],
            ],
        ]);
        $res = $q->get_results();
        if (!empty($res) && is_array($res)) return $res[0];

        $q2 = new WP_User_Query([
            'role' => 'um_almacen',
            'number' => 1,
            'search' => $nombre,
            'search_columns' => ['display_name','user_login'],
        ]);
        $res2 = $q2->get_results();
        if (!empty($res2) && is_array($res2)) return $res2[0];

        return null;
    }

    public static function filter_options_by_key($options, $key) {
        if ((string)$key !== 'mlv_local_codigo') return $options;
        return self::build_local_options_name_only();
    }

    public static function filter_field_options_select(array $options): array {
        foreach ($options as $v) {
            $vv = is_string($v) ? $v : '';
            if (stripos($vv, 'DUMMY') !== false || stripos($vv, 'Cargando locales') !== false) {
                return self::build_local_options_name_only();
            }
        }
        return $options;
    }

    public static function dynamic_options(array $options): array {
        return self::build_local_options_name_only();
    }

    public static function predefined_fields_hook($fields) {
        if (!is_array($fields)) return $fields;
        if (isset($fields['mlv_local_codigo']) && is_array($fields['mlv_local_codigo'])) {
            $fields['mlv_local_codigo']['options'] = self::build_local_options_name_only();
        }
        return $fields;
    }

    private static function build_local_options_name_only(): array {

        $options = ['' => 'Selecciona tu local'];

        $q = new WP_User_Query([
            'role'   => 'um_almacen',
            'number' => 9999,
            'orderby'=> 'ID',
            'order'  => 'ASC',
            'fields' => ['ID','display_name','user_login'],
        ]);

        $users = $q->get_results();
        if (!is_array($users) || empty($users)) return ['' => 'No hay locales disponibles'];

        $pairs = [];
        foreach ($users as $u) {
            $uid = (int)$u->ID;
            self::ensure_almacen_meta($uid);

            $codigo = trim((string)get_user_meta($uid, 'mlv_local_codigo', true));
            if ($codigo === '') continue;

            $nombre = trim((string)get_user_meta($uid, 'mlv_local_nombre', true));
            if ($nombre === '') $nombre = $u->display_name ?: $u->user_login;

            $pairs[] = ['codigo' => $codigo, 'nombre' => $nombre];
        }

        usort($pairs, fn($a,$b) => strcasecmp($a['nombre'], $b['nombre']));

        foreach ($pairs as $p) {
            $options[$p['codigo']] = $p['nombre'];
        }

        return (count($options) > 1) ? $options : ['' => 'No hay locales disponibles'];
    }
}
MLV2_UM_Integration::init();

register_activation_hook(__FILE__, ['MLV2_DB', 'activate']);
register_deactivation_hook(__FILE__, ['MLV2_DB', 'deactivate']);

/**
 * Helpers operativos compartidos (registro latas/gastos).
 */
if (!function_exists('mlv2_get_local_codigo_for_user')) {
    function mlv2_get_local_codigo_for_user(int $user_id): string {
        if ($user_id <= 0) return '';
        return trim((string) get_user_meta($user_id, 'mlv_local_codigo', true));
    }
}

if (!function_exists('mlv2_is_doble_rol_conflict')) {
    function mlv2_is_doble_rol_conflict(int $almacen_id, int $cliente_user_id): bool {
        if ($almacen_id <= 0 || $cliente_user_id <= 0) return false;
        if ($almacen_id === $cliente_user_id) return true;
        if (!class_exists('MLV2_RUT')) return false;

        $almacen_rut_norm = MLV2_RUT::normalize((string) get_user_meta($almacen_id, 'mlv_rut', true));
        $cliente_rut_norm = MLV2_RUT::normalize((string) get_user_meta($cliente_user_id, 'mlv_rut', true));
        return ($almacen_rut_norm !== '' && $cliente_rut_norm !== '' && $almacen_rut_norm === $cliente_rut_norm);
    }
}


// ===== Registro de gasto (autónomo, sin depender de MLV2_Validation) =====
if (!function_exists('mlv2_handle_registro_gasto_autonomo')) {

    add_action('admin_post_mlv2_registro_gasto', 'mlv2_handle_registro_gasto_autonomo');

    function mlv2_handle_registro_gasto_autonomo() {

        if (!defined('ABSPATH')) { exit; }

        // Seguridad / roles
        if (class_exists('MLV2_Security')) {
            MLV2_Security::require_role(['um_almacen','administrator']);
            MLV2_Security::verify_post_nonce('mlv2_registro_gasto');
        } else {
            // fallback mínimo
            if (!is_user_logged_in()) { wp_die('No autorizado'); }
        }

        $cliente_user_id = isset($_POST['cliente_user_id']) ? (int) $_POST['cliente_user_id'] : 0;
        $monto           = isset($_POST['monto']) ? (int) $_POST['monto'] : 0;
        $obs             = isset($_POST['observacion']) ? sanitize_text_field(wp_unslash($_POST['observacion'])) : '';
        $evidencia_url = isset($_POST['evidencia_url']) ? esc_url_raw(wp_unslash($_POST['evidencia_url'])) : '';

        if ($cliente_user_id <= 0) {
            wp_safe_redirect(add_query_arg('mlv2_res','error_cliente', wp_get_referer() ?: home_url('/')));
            exit;
        }
        if ($monto <= 0) {
            wp_safe_redirect(add_query_arg('mlv2_res','error_monto', wp_get_referer() ?: home_url('/')));
            exit;
        }

        // Regla de negocio: un gasto NO puede dejar saldo negativo
        if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'get_saldo_cliente')) {
            $saldo_actual = (float) MLV2_Ledger::get_saldo_cliente($cliente_user_id);
            if ($saldo_actual < (float) $monto) {
                wp_safe_redirect(add_query_arg('mlv2_res','saldo_insuficiente', wp_get_referer() ?: home_url('/')));
                exit;
            }
        }

        $almacen_id   = get_current_user_id();
        $local_codigo = class_exists('MLV2_Movement_Service')
            ? MLV2_Movement_Service::get_local_codigo($almacen_id)
            : (function_exists('mlv2_get_local_codigo_for_user')
                ? mlv2_get_local_codigo_for_user($almacen_id)
                : trim((string) get_user_meta($almacen_id, 'mlv_local_codigo', true)));
        if ($local_codigo === '') {
            wp_safe_redirect(add_query_arg('mlv2_res','local_no_configurado', wp_get_referer() ?: home_url('/')));
            exit;
        }

        // Bloquear autoingreso del administrador/dueño del almacén como cliente en su propio local
        $is_conflict = class_exists('MLV2_Movement_Service')
            ? MLV2_Movement_Service::is_doble_rol_conflict($almacen_id, $cliente_user_id)
            : (function_exists('mlv2_is_doble_rol_conflict')
                ? mlv2_is_doble_rol_conflict($almacen_id, $cliente_user_id)
                : ($cliente_user_id === $almacen_id));
        if ($is_conflict) {
            if (class_exists('MLV2_Audit')) {
                MLV2_Audit::add('movimiento_bloqueado_doble_rol', 'movimiento', 0, null, [
                    'almacen_id' => $almacen_id,
                    'cliente_id' => $cliente_user_id,
                    'local_codigo' => $local_codigo,
                ]);
            }
            wp_safe_redirect(add_query_arg('mlv2_res','doble_rol_bloqueado', wp_get_referer() ?: home_url('/')));
            exit;
        }

        $detalle = [
    'tipo' => 'gasto',
    'origen' => 'reciclaje',
    'clasificacion' => 'operacion',
    'gasto' => [
        'monto' => $monto,
        'observacion' => $obs,
        'evidencia_url' => $evidencia_url,
        'user_id' => $almacen_id,
        'ts' => current_time('mysql'),
    ],
    'hist' => [
        [
            'ts'            => current_time('mysql'),
            'actor_user_id' => $almacen_id,
            'actor_role'    => 'um_almacen',
            'accion'        => 'registrar_gasto',
            'payload'       => ['monto' => $monto, 'observacion' => $obs, 'evidencia_url' => $evidencia_url],
            'estado'        => 'retirado',
        ],
    ],
    'historial' => [
        [
            'ts'            => current_time('mysql'),
            'actor_user_id' => $almacen_id,
            'estado'        => 'retirado',
            'patch'         => ['accion' => 'registro_gasto'],
        ],
    ],
];

        $mov_id = class_exists('MLV2_Movement_Service')
            ? MLV2_Movement_Service::insert_gasto([
                'estado' => 'retirado',
                'local_codigo' => $local_codigo,
                'created_by_user_id' => $almacen_id,
                'cliente_user_id' => $cliente_user_id,
                'cliente_rut' => (string) get_user_meta($cliente_user_id, 'mlv_rut', true),
                'monto' => $monto,
                'origen_saldo' => 'reciclaje',
                'clasificacion_mov' => 'operacion',
                'detalle' => $detalle,
            ])
            : 0;

        if ($mov_id <= 0) {
            $res = 'error';
            if (class_exists('MLV2_Movement_Service') && method_exists('MLV2_Movement_Service', 'get_last_error')) {
                $err = (string) MLV2_Movement_Service::get_last_error();
                if ($err === 'strict_mode_block') {
                    $res = 'strict_mode_block';
                }
            }
            wp_safe_redirect(add_query_arg('mlv2_res', $res, wp_get_referer() ?: home_url('/')));
            exit;
        }

        // Mantener cache mlv_saldo consistente con la fuente de verdad (tabla movimientos)
        if (class_exists('MLV2_Movement_Service')) {
            MLV2_Movement_Service::recalc_cliente_saldo($cliente_user_id);
        } elseif (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
            MLV2_Ledger::recalc_saldo_cliente($cliente_user_id);
        }

        // Alertas
        if (class_exists('MLV2_Alerts')) {
            $local_nombre = (string) get_user_meta($almacen_id, 'mlv_local_nombre', true);
            if ($local_nombre === '') $local_nombre = $local_codigo;

            $msg_cliente = 'Se registró un gasto de $' . number_format((int)$monto, 0, ',', '.') . ' en el local "' . $local_nombre . '".';

            $cliente_user = get_user_by('id', $cliente_user_id);
            $cliente_nombre = $cliente_user ? ($cliente_user->display_name ?: $cliente_user->user_login) : ('Cliente #' . (int)$cliente_user_id);
            $cliente_rut = (string) get_user_meta($cliente_user_id, 'mlv_rut', true);
            $cliente_label = $cliente_nombre;
            if ($cliente_rut !== '') { $cliente_label .= ' — ' . $cliente_rut; }

            $msg_alm = 'Gasto registrado: $' . number_format((int)$monto, 0, ',', '.') . ' a ' . $cliente_label . '.';

            // Cliente (si existe)
            if ($cliente_user_id > 0) {
                MLV2_Alerts::add($cliente_user_id, 'warn', $msg_cliente, 'movimiento', $mov_id);
            }
            // Almacén
            MLV2_Alerts::add($almacen_id, 'ok', $msg_alm, 'movimiento', $mov_id);
        }

        wp_safe_redirect( home_url('/panel/') );
        exit;
    }
}
// ===== Fin registro de gasto autónomo =====




/**
 * Registro asistido de clientes por almacén (um_almacen).
 * Crea usuarios um_cliente sin password (genera una aleatoria) para que luego usen "recuperar contraseña".
 */

/**
 * Asocia un cliente existente a un local (tabla N-N). No duplica usuarios.
 */
function mlv2_associate_cliente_to_local(int $cliente_user_id, string $local_codigo, int $actor_user_id = 0): void {
    if ($cliente_user_id <= 0) return;
    $local_codigo = trim($local_codigo);
    if ($local_codigo === '') return;

    if (class_exists('MLV2_DB')) {
        global $wpdb;
        $table = MLV2_DB::table_clientes_almacenes();
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (cliente_user_id, local_codigo, created_by_user_id, created_at) VALUES (%d, %s, %d, %s)",
            $cliente_user_id,
            $local_codigo,
            $actor_user_id,
            current_time('mysql')
        ));
    }
}

add_action('admin_post_mlv_registro_cliente_almacen', 'mlv2_handle_registro_cliente_almacen');
function mlv2_handle_registro_cliente_almacen() {
    if ( ! is_user_logged_in() ) {
        wp_safe_redirect( add_query_arg('mlv_err', 'no_autorizado', home_url('/registro-cliente/')) );
        exit;
    }

    $actor = wp_get_current_user();
    if ( ! in_array('um_almacen', (array) $actor->roles, true) && ! current_user_can('administrator') ) {
        wp_safe_redirect( add_query_arg('mlv_err', 'no_autorizado', home_url('/registro-cliente/')) );
        exit;
    }

    if ( ! isset($_POST['mlv_nonce']) || ! wp_verify_nonce($_POST['mlv_nonce'], 'mlv_registro_cliente_almacen') ) {
        wp_safe_redirect( add_query_arg('mlv_err', 'nonce', home_url('/registro-cliente/')) );
        exit;
    }

    $nombre   = sanitize_text_field($_POST['nombre'] ?? '');
    $apellido = sanitize_text_field($_POST['apellido'] ?? '');
    $rut_raw  = sanitize_text_field($_POST['rut'] ?? '');
    $telefono = sanitize_text_field($_POST['telefono'] ?? '');
    $email_in = sanitize_email($_POST['email'] ?? '');

    if ( $nombre === '' || $apellido === '' || $rut_raw === '' || $telefono === '' ) {
        wp_safe_redirect( add_query_arg('mlv_err', 'faltan_datos', home_url('/registro-cliente/')) );
        exit;
    }

    // RUT: permitir varias escrituras pero estandarizar a 12.345.678-9
    $rut_norm  = $rut_raw;
    $rut_fmt   = $rut_raw;
    $rut_parse = null;
    if ( class_exists('MLV2_RUT') ) {
        $rut_parse = MLV2_RUT::parse($rut_raw);
        if ( empty($rut_parse['ok']) ) {
            $err = (string)($rut_parse['error'] ?? 'rut_invalido');
            $code = 'rut_invalido';
            if ( $err === 'pocos_digitos' ) { $code = 'rut_pocos_digitos'; }
            if ( $err === 'dv_invalido' ) { $code = 'rut_invalido'; }
            if ( $err === 'incompleto' ) { $code = 'rut_invalido'; }
            if ( $err === 'vacio' ) { $code = 'faltan_datos'; }
            wp_safe_redirect( add_query_arg('mlv_err', $code, home_url('/registro-cliente/')) );
            exit;
        }
        $rut_norm = (string)($rut_parse['norm'] ?? '');
        $rut_fmt  = (string)($rut_parse['formatted'] ?? $rut_raw);
    }

    // Bloquear auto-registro del dueño/administrador como cliente del mismo local
    $almacen_rut_norm = class_exists('MLV2_RUT') ? MLV2_RUT::normalize((string) get_user_meta($actor->ID, 'mlv_rut', true)) : '';
    if ($almacen_rut_norm !== '' && $rut_norm !== '' && $almacen_rut_norm === $rut_norm) {
        if (class_exists('MLV2_Audit')) {
            MLV2_Audit::add('cliente_bloqueado_doble_rol', 'cliente', 0, null, [
                'almacen_id' => (int)$actor->ID,
                'rut_norm' => $rut_norm,
            ]);
        }
        wp_safe_redirect( add_query_arg('mlv_err', 'doble_rol_bloqueado', home_url('/registro-cliente/')) );
        exit;
    }

    // Evitar duplicados por RUT (compat: buscamos en mlv_rut_norm, y también en mlv_rut)
    $existing = get_users([
        'meta_key'   => 'mlv_rut_norm',
        'meta_value' => $rut_norm,
        'number'     => 1,
        'fields'     => 'ID',
    ]);
    if ( empty($existing) ) {
        $existing = get_users([
            'meta_key'   => 'mlv_rut',
            'meta_value' => $rut_norm,
            'number'     => 1,
            'fields'     => 'ID',
        ]);
    }
    if ( empty($existing) ) {
        $existing = get_users([
            'meta_key'   => 'mlv_rut',
            'meta_value' => $rut_fmt,
            'number'     => 1,
            'fields'     => 'ID',
        ]);
    }
    if ( ! empty($existing) ) {
        $existing_id = (int) $existing[0];

        // Validar que sea cliente (o convertirlo a cliente si no tiene rol)
        $u = get_user_by('id', $existing_id);
        if (!$u) {
            wp_safe_redirect( add_query_arg('mlv_err', 'error', home_url('/registro-cliente/')) );
            exit;
        }

        // Bloquear auto-asociación por doble rol
        $almacen_rut_norm = class_exists('MLV2_RUT') ? MLV2_RUT::normalize((string) get_user_meta($actor->ID, 'mlv_rut', true)) : '';
        $cliente_rut_norm = class_exists('MLV2_RUT') ? MLV2_RUT::normalize((string) get_user_meta($existing_id, 'mlv_rut', true)) : '';
        if ($existing_id === (int)$actor->ID || ($almacen_rut_norm !== '' && $cliente_rut_norm !== '' && $almacen_rut_norm === $cliente_rut_norm)) {
            if (class_exists('MLV2_Audit')) {
                MLV2_Audit::add('cliente_bloqueado_doble_rol', 'cliente', $existing_id, null, [
                    'almacen_id' => (int)$actor->ID,
                ]);
            }
            wp_safe_redirect( add_query_arg('mlv_err', 'doble_rol_bloqueado', home_url('/registro-cliente/')) );
            exit;
        }

        // Si no es cliente, no lo asociamos automaticamente (evita que un almacenero "tome" un admin/gestor)
        $roles = (array) $u->roles;
        if ( ! in_array('um_cliente', $roles, true) ) {
            wp_safe_redirect( add_query_arg('mlv_err', 'rut_no_cliente', home_url('/registro-cliente/')) );
            exit;
        }

        // Asociar al local del almacenero (N-N) y redirigir con ok
        $local = (string) get_user_meta($actor->ID, 'mlv_local_codigo', true);
        if ( $local !== '' ) {
            mlv2_associate_cliente_to_local($existing_id, $local, (int)$actor->ID);
        }

        wp_safe_redirect( add_query_arg('mlv_res', 'cliente_agregado', home_url('/panel/')) );
        exit;
    }

    // Email opcional: si viene, validamos duplicado. Si no viene, generamos uno placeholder único.
    $email = $email_in;
    if ( $email !== '' && email_exists($email) ) {
        wp_safe_redirect( add_query_arg('mlv_err', 'email_existe', home_url('/registro-cliente/')) );
        exit;
    }
    if ( $email === '' ) {
        $base = strtolower(preg_replace('/[^0-9kK]/', '', $rut_norm));
        if ($base === '') { $base = 'user'; }
        $domain = 'milatavale.local';
        $email = $base . '@' . $domain;
        $i = 1;
        while (email_exists($email)) {
            $email = $base . '+' . $i . '@' . $domain;
            $i++;
            if ($i > 999) break;
        }
    }

    // user_login único basado en RUT (sin puntos/guion); si existe, agregar sufijo.
    $login = $rut_norm;
    if ( username_exists($login) ) {
        $login = $rut_norm . '_' . wp_generate_password(5, false);
    }

    // Password aleatoria (no se usa en flujo RUT, pero WP la requiere internamente)
    $password = wp_generate_password(18, true);

    $display_name = trim($nombre . ' ' . $apellido);

    $user_id = wp_insert_user([
        'user_login'   => $login,
        'user_pass'    => $password,
        'user_email'   => $email,
        'display_name' => $display_name,
        'first_name'   => $nombre,
        'last_name'    => $apellido,
        'role'         => 'um_cliente',
    ]);

    if ( is_wp_error($user_id) ) {
        wp_safe_redirect( add_query_arg('mlv_err', 'error', home_url('/registro-cliente/')) );
        exit;
    }

    // Guardar ambos: display (con puntos/guion) y norm (para búsquedas exactas)
    update_user_meta((int)$user_id, 'mlv_rut', $rut_fmt);
    update_user_meta((int)$user_id, 'mlv_rut_norm', $rut_norm);
    update_user_meta((int)$user_id, 'mlv_telefono', $telefono);

    if ( get_user_meta((int)$user_id, 'mlv_saldo', true) === '' ) {
        update_user_meta((int)$user_id, 'mlv_saldo', 0);
    }

    // Asociar al local del almacenero si existe
    $local = get_user_meta($actor->ID, 'mlv_local_codigo', true);
    if ( $local ) {
        update_user_meta((int)$user_id, 'mlv_local_codigo', $local);
    }

    if ( $local ) {
        mlv2_associate_cliente_to_local((int)$user_id, (string)$local, (int)$actor->ID);
    }

    // Alerta para el almacenero (puede mostrarse en /clientes/ o donde tengas [mlv_panel_alert])
    if (class_exists('MLV2_Alerts')) {
        $rut_f = (class_exists('MLV2_RUT') && method_exists('MLV2_RUT','format')) ? MLV2_RUT::format($rut_fmt) : $rut_fmt;
        MLV2_Alerts::add((int)$actor->ID, 'ok', 'Has registrado a <strong>' . esc_html($display_name) . '</strong> (' . esc_html($rut_f) . ') correctamente.', 'cliente', (int)$user_id);

        // Si lo ingresaron sin guion, avisar (pero no bloquear)
        if (is_array($rut_parse) && !empty($rut_parse['warnings']) && in_array('sin_guion', (array)$rut_parse['warnings'], true)) {
            MLV2_Alerts::add((int)$actor->ID, 'warn', 'Tip: ingresaste el RUT sin guión. Lo guardamos como <strong>' . esc_html($rut_f) . '</strong>.', 'rut', (int)$user_id);
        }
    }

    wp_safe_redirect( add_query_arg([
        'mlv_ok' => 'cliente_registrado',
        'mlv_nombre' => rawurlencode($display_name),
    ], home_url('/panel/')) );
    exit;
}




add_action('admin_post_mlv2_export_audit', function () {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado');
    }
    check_admin_referer('mlv2_export_audit');

    global $wpdb;

    $audit_table = $wpdb->prefix . 'mlv_audit_log';
    $mov_table   = class_exists('MLV2_DB') ? MLV2_DB::table_movimientos() : ($wpdb->prefix . 'mlv_movimientos');

    $audit_rows = $wpdb->get_results("SELECT * FROM {$audit_table} ORDER BY created_at ASC", ARRAY_A);
    $mov_rows   = $wpdb->get_results("SELECT * FROM {$mov_table} ORDER BY created_at ASC", ARRAY_A);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mlv_log_completo.csv');

    $out = fopen('php://output', 'w');

    // CSV con columnas unificadas (audit + movimientos)
    $headers = [
        'source',
        'record_id',
        'created_at',

        // contexto común
        'actor_user_id',
        'actor_role',
        'local_codigo',
        'cliente_user_id',

        // movimientos
        'mov_tipo',
        'cantidad_latas',
        'valor_por_lata',
        'monto_calculado',
        'estado',
        'evidencia_url',
        'observacion',
        'detalle_json',
        'deleted_at',
        'deleted_by',

        // audit
        'audit_action',
        'object_type',
        'object_id',
        'before_json',
        'after_json',
        'ip',
        'user_agent',
    ];

    fputcsv($out, $headers);

    // 1) Movimientos (latas + gastos)
    foreach ((array)$mov_rows as $r) {
        $detalle = [];
        if (!empty($r['detalle'])) {
            $tmp = json_decode((string)$r['detalle'], true);
            if (is_array($tmp)) { $detalle = $tmp; }
        }

        $evid = '';
        if (!empty($detalle['evidencia_url'])) {
            $evid = (string)$detalle['evidencia_url'];
        } elseif (!empty($detalle['declarado']['evidencia_url'])) {
            $evid = (string)$detalle['declarado']['evidencia_url'];
        }

        $obs = '';
        if (!empty($detalle['observacion'])) {
            $obs = (string)$detalle['observacion'];
        } elseif (!empty($detalle['declarado']['observacion'])) {
            $obs = (string)$detalle['declarado']['observacion'];
        }

        // Intentar resolver rol del actor (creador)
        $actor_id = (int)($r['created_by_user_id'] ?? 0);
        $actor_role = '';
        if ($actor_id > 0) {
            $u = get_userdata($actor_id);
            if ($u && !empty($u->roles) && is_array($u->roles)) {
                $actor_role = (string)($u->roles[0] ?? '');
            }
        }

        $row = [
            'source'        => 'movimiento',
            'record_id'     => (string)($r['id'] ?? ''),
            'created_at'    => (string)($r['created_at'] ?? ''),

            'actor_user_id' => (string)$actor_id,
            'actor_role'    => $actor_role,
            'local_codigo'  => (string)($r['local_codigo'] ?? ''),
            'cliente_user_id' => (string)($r['cliente_user_id'] ?? ''),

            'mov_tipo'      => (string)($r['tipo'] ?? ''),
            'cantidad_latas'=> (string)($r['cantidad_latas'] ?? ''),
            'valor_por_lata'=> (string)($r['valor_por_lata'] ?? ''),
            'monto_calculado'=> (string)($r['monto_calculado'] ?? ''),
            'estado'        => (string)($r['estado'] ?? ''),
            'evidencia_url' => $evid,
            'observacion'   => $obs,
            'detalle_json'  => (string)($r['detalle'] ?? ''),
            'deleted_at'    => (string)($r['deleted_at'] ?? ''),
            'deleted_by'    => (string)($r['deleted_by'] ?? ''),

            'audit_action'  => '',
            'object_type'   => '',
            'object_id'     => '',
            'before_json'   => '',
            'after_json'    => '',
            'ip'            => '',
            'user_agent'    => '',
        ];

        // escribir en orden de headers
        $line = [];
        foreach ($headers as $h) { $line[] = $row[$h] ?? ''; }
        fputcsv($out, $line);
    }

    // 2) Audit log (admin + sistema)
    foreach ((array)$audit_rows as $r) {
        $actor_id = (int)($r['user_id'] ?? 0);
        $actor_role = '';
        if ($actor_id > 0) {
            $u = get_userdata($actor_id);
            if ($u && !empty($u->roles) && is_array($u->roles)) {
                $actor_role = (string)($u->roles[0] ?? '');
            }
        }

        $row = [
            'source'        => 'audit',
            'record_id'     => (string)($r['id'] ?? ''),
            'created_at'    => (string)($r['created_at'] ?? ''),

            'actor_user_id' => (string)$actor_id,
            'actor_role'    => $actor_role,
            'local_codigo'  => '',
            'cliente_user_id' => '',

            'mov_tipo'      => '',
            'cantidad_latas'=> '',
            'valor_por_lata'=> '',
            'monto_calculado'=> '',
            'estado'        => '',
            'evidencia_url' => '',
            'observacion'   => '',
            'detalle_json'  => '',
            'deleted_at'    => '',
            'deleted_by'    => '',

            'audit_action'  => (string)($r['action'] ?? ''),
            'object_type'   => (string)($r['object_type'] ?? ''),
            'object_id'     => (string)($r['object_id'] ?? ''),
            'before_json'   => (string)($r['before_json'] ?? ''),
            'after_json'    => (string)($r['after_json'] ?? ''),
            'ip'            => (string)($r['ip'] ?? ''),
            'user_agent'    => (string)($r['user_agent'] ?? ''),
        ];

        $line = [];
        foreach ($headers as $h) { $line[] = $row[$h] ?? ''; }
        fputcsv($out, $line);
    }

    fclose($out);
    exit;
});
