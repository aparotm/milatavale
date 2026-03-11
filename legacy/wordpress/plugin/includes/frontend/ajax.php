<?php
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_mlv2_buscar_cliente', 'mlv2_ajax_buscar_cliente');

function mlv2_ajax_buscar_cliente() {
    MLV2_Security::require_role(['um_almacen','um_gestor','administrator']);
    check_ajax_referer('mlv2_ajax', 'nonce');

    $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
    $needle = MLV2_RUT::normalize($term);

    if ($needle === '') {
        wp_send_json([]);
    }

    // Búsqueda tolerante: en UM el RUT puede estar guardado con puntos/guión.
    // Usamos LIKE para acotar y luego comparamos normalizando.
    $like = substr($needle, 0, 8); // primeros dígitos, ayuda a acotar
    $like = $like ?: $needle;

    $users = get_users([
        'role' => 'um_cliente',
        'meta_query' => [
            'relation' => 'OR',
            [
                // Preferimos el normalizado si existe
                'key' => 'mlv_rut_norm',
                'value' => $like,
                'compare' => 'LIKE',
            ],
            [
                // Fallback para instalaciones antiguas sin rut_norm
                'key' => 'mlv_rut',
                'value' => $like,
                'compare' => 'LIKE',
            ],
        ],
        'number' => 50,
    ]);

    $out = [];
    $current_id = get_current_user_id();
    $current_rut_norm = '';
    if (class_exists('MLV2_RUT')) {
        $current_rut_norm = MLV2_RUT::normalize((string)get_user_meta($current_id, 'mlv_rut', true));
    }
    $current_roles = (array) (wp_get_current_user()->roles ?? []);
    foreach ($users as $u) {
        $stored = (string) get_user_meta($u->ID, 'mlv_rut', true);
        $stored_norm = (string) get_user_meta($u->ID, 'mlv_rut_norm', true);
        if ($stored_norm === '') {
            $stored_norm = MLV2_RUT::normalize($stored);
        }

        // Permitimos búsqueda parcial (por ejemplo, escribir algunos dígitos)
        if (strpos($stored_norm, $needle) === false) {
            continue;
        }

        // Bloquear autoingreso para almacén (mismo RUT)
        if (in_array('um_almacen', $current_roles, true) && $current_rut_norm !== '' && $stored_norm === $current_rut_norm) {
            continue;
        }

        $out[] = [
            'id' => $u->ID,
            'text' => $u->display_name . ' - ' . $stored,
        ];

        if (count($out) >= 10) {
            break;
        }
    }

    wp_send_json($out);
}

add_action('wp_ajax_mlv2_set_retirado', function () {
    // Roles allowed: almacén y admin
    MLV2_Security::require_role(['um_almacen','administrator']);
    check_ajax_referer('mlv2_ajax', 'nonce');

    $mov_id = isset($_POST['mov_id']) ? (int) sanitize_text_field(wp_unslash($_POST['mov_id'])) : 0;
    $value  = !empty($_POST['retirado']) ? 1 : 0;

    if ($mov_id <= 0) {
        wp_send_json_error(['message' => 'mov_id inválido'], 400);
    }

    global $wpdb;
    $table = MLV2_DB::table_movimientos();

    $mov = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND deleted_at IS NULL", $mov_id), ARRAY_A);
    if (!$mov) {
        wp_send_json_error(['message' => 'Movimiento no encontrado'], 404);
    }

    $uid = get_current_user_id();

    // El almacén solo puede editar movimientos creados por él (admin puede todo)
    if (!current_user_can('manage_options')) {
        if ((int)($mov['created_by_user_id'] ?? 0) !== $uid) {
            wp_send_json_error(['message' => 'No puedes editar este movimiento'], 403);
        }
    }

    $estado_actual = (string)($mov['estado'] ?? '');
    $nuevo_estado  = $estado_actual;

    // Flujo único: crédito inmediato
    // - Lo visible para gestores es: estado = pendiente_retiro
    // - Cuando el almacén marca retirado: estado = retirado (desaparece del panel del gestor)
    if ($value === 1) {
        $nuevo_estado = 'retirado';
    } else {
        // Permitir desmarcar (solo si estaba retirado)
        if ($estado_actual === 'retirado') {
            $nuevo_estado = 'pendiente_retiro';
        }
    }

    $patch = [
        '_accion' => ($value === 1 ? 'marcar_retirado' : 'desmarcar_retirado'),
        'retirado' => [
            'value'   => $value,
            'user_id' => $uid,
            'ts'      => current_time('mysql'),
        ],
    ];

    $ok = MLV2_Ledger::update_estado_y_detalle($mov_id, $nuevo_estado, $patch, $uid);
    if (!$ok) {
        wp_send_json_error(['message' => 'No se pudo guardar'], 500);
    }

wp_send_json_success([
        'mov_id' => $mov_id,
        'retirado' => $value,
        'estado' => $nuevo_estado,
    ]);
});


add_action('wp_ajax_mlv2_dismiss_alert', function () {
    check_ajax_referer('mlv2_ajax', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado'], 401);
    }
    $uid = get_current_user_id();
    $alert_id = isset($_POST['alert_id']) ? (int) sanitize_text_field(wp_unslash($_POST['alert_id'])) : 0;
    if ($alert_id <= 0) {
        wp_send_json_error(['message' => 'alert_id inválido'], 400);
    }
    if (!class_exists('MLV2_Alerts')) {
        wp_send_json_error(['message' => 'Sistema de alertas no disponible'], 500);
    }
    $ok = MLV2_Alerts::dismiss($alert_id, $uid);
    if (!$ok) {
        wp_send_json_error(['message' => 'No se pudo cerrar'], 400);
    }
    wp_send_json_success(['alert_id' => $alert_id]);
});

add_action('wp_ajax_mlv2_dismiss_alerts', function () {
    check_ajax_referer('mlv2_ajax', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado'], 401);
    }
    if (!class_exists('MLV2_Alerts')) {
        wp_send_json_error(['message' => 'Sistema de alertas no disponible'], 500);
    }

    $raw = isset($_POST['alert_ids']) ? sanitize_text_field(wp_unslash($_POST['alert_ids'])) : '';
    $ids = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', (string)$raw))));
    if (empty($ids)) {
        wp_send_json_error(['message' => 'alert_ids inválido'], 400);
    }

    $uid = get_current_user_id();
    $dismissed = 0;
    foreach ($ids as $id) {
        if ($id > 0 && MLV2_Alerts::dismiss($id, $uid)) {
            $dismissed++;
        }
    }

    wp_send_json_success(['dismissed' => $dismissed]);
});


// ============================================================
// Movimientos: paginación móvil ("Cargar más")
// ============================================================
add_action('wp_ajax_mlv2_load_more_movimientos', function () {
    check_ajax_referer('mlv2_ajax', 'nonce');
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado'], 401);
    }

    $context = isset($_POST['context']) ? sanitize_key(wp_unslash($_POST['context'])) : 'cliente';
    $page = isset($_POST['page']) ? (int) sanitize_text_field(wp_unslash($_POST['page'])) : 1;
    $per_page = isset($_POST['per_page']) ? (int) sanitize_text_field(wp_unslash($_POST['per_page'])): 15;
    if ($per_page < 5) $per_page = 5;
    if ($per_page > 100) $per_page = 100;

    $uid = get_current_user_id();
    $user = wp_get_current_user();
    $roles = (array) $user->roles;

    if ($context === 'almacen') {
        if (!in_array('um_almacen', $roles, true) && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No autorizado'], 403);
        }
    } else {
        // cliente por defecto
        if (!in_array('um_cliente', $roles, true) && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No autorizado'], 403);
        }
        $context = 'cliente';
    }

    if (!class_exists('MLV2_Front_UI') || !method_exists('MLV2_Front_UI', 'ajax_movimientos_page')) {
        wp_send_json_error(['message' => 'No disponible'], 500);
    }

    $filters = [
        'page' => max(1, $page),
        'per_page' => $per_page,
    ];

    if ($context === 'cliente') {
        $filters['local_codigo'] = isset($_POST['local_codigo']) ? sanitize_text_field(wp_unslash($_POST['local_codigo'])) : '';
    } else {
        $filters['cliente_user_id'] = isset($_POST['cliente_user_id']) ? (int) sanitize_text_field(wp_unslash($_POST['cliente_user_id'])) : 0;
    }

    $res = MLV2_Front_UI::ajax_movimientos_page($context, $filters);

    wp_send_json_success([
        'html' => (string)($res['html'] ?? ''),
        'has_more' => !empty($res['has_more']),
        'next_page' => (int)($res['next_page'] ?? ($page + 1)),
    ]);
});
