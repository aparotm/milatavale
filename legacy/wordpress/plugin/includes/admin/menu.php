<?php
if (!defined('ABSPATH')) { exit; }

if (defined('MLV2_ADMIN_MENU_LOADED')) { return; }
define('MLV2_ADMIN_MENU_LOADED', true);

require_once __DIR__ . '/services/class-mlv2-admin-query.php';
require_once __DIR__ . '/tables/class-mlv2-admin-table.php';
require_once __DIR__ . '/tables/class-mlv2-admin-users-table.php';
require_once __DIR__ . '/pages/pages.php';
require_once __DIR__ . '/pages/edit-movimiento.php';
require_once __DIR__ . '/pages/reverse-movimiento.php';
require_once __DIR__ . '/pages/reverse-incentivo-batch.php';
require_once __DIR__ . '/pages/incentivos.php';
require_once __DIR__ . '/pages/regularizacion-historica.php';
require_once __DIR__ . '/pages/ajustes-contables.php';
require_once __DIR__ . '/pages/diagnostico.php';
require_once __DIR__ . '/services/export-csv.php';
require_once __DIR__ . '/pages/settings.php';
require_once __DIR__ . '/services/recalculate.php';

add_action('admin_post_mlv2_repair_now', ['MLV2_Admin_Diagnostico', 'handle_repair']);
add_action('admin_post_mlv2_export_diagnostico_csv', ['MLV2_Admin_Diagnostico', 'export_csv']);
add_action('admin_post_mlv2_export_diagnostico_json', ['MLV2_Admin_Diagnostico', 'export_json']);

if (!function_exists('mlv2_decode_detalle_safe')) {
    function mlv2_decode_detalle_safe(array $row): array {
        if (empty($row['detalle'])) { return []; }
        $tmp = json_decode((string)$row['detalle'], true);
        return is_array($tmp) ? $tmp : [];
    }
}

if (!function_exists('mlv2_build_reverse_insert_from_original')) {
    function mlv2_build_reverse_insert_from_original(array $orig, string $motivo, array $extra = []): array {
        $mov_id = (int)($orig['id'] ?? 0);
        $monto_orig = (int)($orig['monto_calculado'] ?? 0);
        $origen_saldo = (string)($orig['origen_saldo'] ?? 'reciclaje');
        $detalle_orig = mlv2_decode_detalle_safe($orig);
        $batch_id = isset($orig['incentivo_batch_id']) ? trim((string)$orig['incentivo_batch_id']) : '';

        $cantidad_latas = (int)($orig['cantidad_latas'] ?? 0);
        if ($origen_saldo === 'reciclaje' && $cantidad_latas !== 0) {
            $cantidad_latas = 0 - abs($cantidad_latas);
        } else {
            $cantidad_latas = 0;
        }

        $detalle = [
            'tipo' => 'ajuste',
            'origen' => $origen_saldo,
            'clasificacion' => 'correccion',
            'ajuste' => array_merge([
                'tipo' => 'reversa',
                'motivo' => $motivo,
                'reversa_de_movimiento_id' => $mov_id,
                'solicitado_por' => (int)get_current_user_id(),
            ], $extra),
            'orig' => [
                'id' => $mov_id,
                'monto' => $monto_orig,
            ],
            'hist' => [
                [
                    'ts' => current_time('mysql'),
                    'actor_user_id' => (int)get_current_user_id(),
                    'actor_role' => 'administrator',
                    'accion' => 'reversa_admin',
                    'estado' => 'retirado',
                    'payload' => ['motivo' => $motivo],
                ],
            ],
        ];
        if (!empty($batch_id)) {
            $detalle['orig']['incentivo_batch_id'] = $batch_id;
        }
        if (!empty($detalle_orig['incentivo']) && is_array($detalle_orig['incentivo'])) {
            $detalle['incentivo'] = $detalle_orig['incentivo'];
        }

        return [
            'tipo' => ($monto_orig > 0 ? 'gasto' : 'ingreso'),
            'cliente_user_id' => (int)($orig['cliente_user_id'] ?? 0),
            'cliente_rut' => (string)($orig['cliente_rut'] ?? ''),
            'cliente_telefono' => (string)($orig['cliente_telefono'] ?? ''),
            'local_codigo' => (string)($orig['local_codigo'] ?? ''),
            'cantidad_latas' => $cantidad_latas,
            'valor_por_lata' => (int)($orig['valor_por_lata'] ?? 0),
            'monto_calculado' => (0 - $monto_orig),
            'origen_saldo' => $origen_saldo,
            'mov_ref_id' => $mov_id,
            'is_system_adjustment' => 1,
            'clasificacion_mov' => 'correccion',
            'incentivo_batch_id' => ($batch_id !== '' ? $batch_id : null),
            'estado' => 'retirado',
            'detalle' => wp_json_encode($detalle, JSON_UNESCAPED_UNICODE),
            'created_by_user_id' => (int)get_current_user_id(),
            'validated_by_user_id' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
    }
}

// Reversa de movimiento (contable)
add_action('admin_post_mlv2_reverse_movimiento', function () {
    if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

    $mov_id = isset($_POST['mov_id']) ? (int) $_POST['mov_id'] : 0;
    if ($mov_id <= 0) { wp_die('mov_id inválido'); }
    check_admin_referer('mlv2_reverse_movimiento_' . $mov_id);

    $motivo = isset($_POST['motivo']) ? sanitize_text_field(wp_unslash($_POST['motivo'])) : '';
    if ($motivo === '') {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'motivo_reversa'], admin_url('admin.php')));
        exit;
    }

    global $wpdb;
    $table = MLV2_DB::table_movimientos();
    $orig = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $mov_id), ARRAY_A);
    if (!$orig) { wp_die('Movimiento no encontrado'); }
    if (!empty($orig['deleted_at'])) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'movimiento_en_papelera'], admin_url('admin.php')));
        exit;
    }
    if ((int)($orig['is_system_adjustment'] ?? 0) === 1) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'movimiento_ajuste_no_reversible'], admin_url('admin.php')));
        exit;
    }

    // Evitar doble reversa activa
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE mov_ref_id=%d AND clasificacion_mov='correccion' AND is_system_adjustment=1 AND deleted_at IS NULL LIMIT 1",
        $mov_id
    ));
    if ($exists) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'reversa_existente'], admin_url('admin.php')));
        exit;
    }

    $monto_orig = (int)($orig['monto_calculado'] ?? 0);
    if ($monto_orig === 0) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'monto_cero'], admin_url('admin.php')));
        exit;
    }

    $cliente_id = (int)($orig['cliente_user_id'] ?? 0);
    $insert = mlv2_build_reverse_insert_from_original($orig, $motivo);

    $ok = $wpdb->insert($table, $insert);
    if (!$ok) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'reversa_error'], admin_url('admin.php')));
        exit;
    }

    if ($cliente_id > 0 && class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
        MLV2_Ledger::recalc_saldo_cliente($cliente_id);
    }
    if (class_exists('MLV2_Audit')) {
        MLV2_Audit::add('movimiento_reverse', 'movimiento', $mov_id, $orig, ['reversa_id' => (int)$wpdb->insert_id]);
    }

    $back = wp_get_referer();
    $back = admin_url('admin.php?page=mlv2_movimientos&mlv_msg=reversa_ok');
    wp_safe_redirect($back);
    exit;
});

// Reversa contable por lote de incentivo (batch)
add_action('admin_post_mlv2_reverse_incentivo_batch', function () {
    if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

    $batch_id = isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '';
    $batch_id = trim($batch_id);
    if ($batch_id === '') { wp_die('batch_id inválido'); }
    check_admin_referer('mlv2_reverse_incentivo_batch_' . $batch_id);

    $motivo = isset($_POST['motivo']) ? sanitize_text_field(wp_unslash($_POST['motivo'])) : '';
    if ($motivo === '') {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'motivo_reversa'], admin_url('admin.php')));
        exit;
    }

    global $wpdb;
    $table = MLV2_DB::table_movimientos();

    $orig_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE incentivo_batch_id=%s
               AND origen_saldo='incentivo'
               AND clasificacion_mov='operacion'
               AND is_system_adjustment=0
               AND deleted_at IS NULL
             ORDER BY id ASC",
            $batch_id
        ),
        ARRAY_A
    );

    if (empty($orig_rows)) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'lote_incentivo_no_encontrado'], admin_url('admin.php')));
        exit;
    }

    $orig_ids = array_map(static function ($r) { return (int)($r['id'] ?? 0); }, $orig_rows);
    $orig_ids = array_values(array_filter($orig_ids));
    if (empty($orig_ids)) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'lote_incentivo_no_encontrado'], admin_url('admin.php')));
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($orig_ids), '%d'));
    $sql_exists = "SELECT COUNT(*) FROM {$table}
                   WHERE clasificacion_mov='correccion'
                     AND is_system_adjustment=1
                     AND deleted_at IS NULL
                     AND mov_ref_id IN ({$placeholders})";
    $exists = (int) $wpdb->get_var($wpdb->prepare($sql_exists, ...$orig_ids));
    if ($exists > 0) {
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'lote_incentivo_ya_reversado'], admin_url('admin.php')));
        exit;
    }

    $wpdb->query('START TRANSACTION');
    $ok = true;
    $created = 0;
    $clientes = [];
    $inserted_ids = [];
    foreach ($orig_rows as $orig) {
        $insert = mlv2_build_reverse_insert_from_original($orig, $motivo, [
            'reversa_lote_incentivo_batch_id' => $batch_id,
        ]);
        $res = $wpdb->insert($table, $insert);
        if (!$res) {
            $ok = false;
            break;
        }
        $created++;
        $inserted_ids[] = (int)$wpdb->insert_id;
        $cid = (int)($orig['cliente_user_id'] ?? 0);
        if ($cid > 0) { $clientes[$cid] = true; }
    }

    if (!$ok || $created !== count($orig_rows)) {
        $wpdb->query('ROLLBACK');
        wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_err'=>'reversa_lote_error'], admin_url('admin.php')));
        exit;
    }
    $wpdb->query('COMMIT');

    foreach (array_keys($clientes) as $cid) {
        if ($cid > 0 && class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
            MLV2_Ledger::recalc_saldo_cliente((int)$cid);
        }
    }

    if (class_exists('MLV2_Audit')) {
        MLV2_Audit::add('movimiento_reverse_batch', 'movimiento', 0, null, [
            'incentivo_batch_id' => $batch_id,
            'motivo' => $motivo,
            'movimientos_originales' => count($orig_rows),
            'movimientos_reversa' => $created,
            'reversa_ids' => $inserted_ids,
        ]);
    }

    wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_msg'=>'reversa_lote_ok'], admin_url('admin.php')));
    exit;
});

// Papelera de movimientos: acciones individuales
add_action('admin_post_mlv2_trash_movimiento', function () {
    if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
    $mov_id = isset($_GET['mov_id']) ? (int) $_GET['mov_id'] : 0;
    if ($mov_id <= 0) { wp_die('mov_id inválido'); }
    check_admin_referer('mlv2_trash_movimiento_' . $mov_id);

    global $wpdb;
    $table = MLV2_DB::table_movimientos();

    $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $mov_id), ARRAY_A);
    if (!$before) { wp_die('Movimiento no encontrado'); }

    $now = current_time('mysql');
    $uid = (int) get_current_user_id();

    $wpdb->update(
        $table,
        ['deleted_at' => $now, 'deleted_by' => $uid, 'updated_at' => $now],
        ['id' => $mov_id],
        ['%s','%d','%s'],
        ['%d']
    );

    // Recalcular saldo del cliente si aplica (mantiene KPIs consistentes)
    $cid = (int)($before['cliente_user_id'] ?? 0);
    if ($cid > 0 && class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
        MLV2_Ledger::recalc_saldo_cliente($cid);
    }

    $after = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $mov_id), ARRAY_A);
    if (class_exists('MLV2_Audit')) {
        MLV2_Audit::add('movimiento_trash', 'movimiento', $mov_id, $before, $after);
    }

    $back = wp_get_referer();
    if (!$back) { $back = admin_url('admin.php?page=mlv2_movimientos'); }
    wp_safe_redirect($back);
    exit;
});

add_action('admin_post_mlv2_restore_movimiento', function () {
    if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
    $mov_id = isset($_GET['mov_id']) ? (int) $_GET['mov_id'] : 0;
    if ($mov_id <= 0) { wp_die('mov_id inválido'); }
    check_admin_referer('mlv2_restore_movimiento_' . $mov_id);

    global $wpdb;
    $table = MLV2_DB::table_movimientos();

    $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $mov_id), ARRAY_A);
    if (!$before) { wp_die('Movimiento no encontrado'); }

    $now = current_time('mysql');
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table} SET deleted_at=NULL, deleted_by=NULL, updated_at=%s WHERE id=%d",
            $now,
            $mov_id
        )
    );

    // Recalcular saldo del cliente si aplica (mantiene KPIs consistentes)
    $cid = (int)($before['cliente_user_id'] ?? 0);
    if ($cid > 0 && class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
        MLV2_Ledger::recalc_saldo_cliente($cid);
    }

    $after = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $mov_id), ARRAY_A);
    if (class_exists('MLV2_Audit')) {
        MLV2_Audit::add('movimiento_restore', 'movimiento', $mov_id, $before, $after);
    }

    $back = wp_get_referer();
    if (!$back) { $back = admin_url('admin.php?page=mlv2_movimientos&mlv_view=trash'); }
    wp_safe_redirect($back);
    exit;
});

/**
 * Guardar local asignado (si lo estás usando)
 */
add_action('admin_post_mlv2_set_user_local', function () {
    if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
    check_admin_referer('mlv2_set_user_local');

    $user_id = isset($_REQUEST['user_id']) ? (int) $_REQUEST['user_id'] : 0;
    $local   = isset($_REQUEST['mlv_local_codigo']) ? sanitize_text_field(wp_unslash($_REQUEST['mlv_local_codigo'])) : '';
    $local   = trim($local);

    if ($user_id > 0) {
        update_user_meta($user_id, 'mlv_local_codigo', $local);
    }

    $back = wp_get_referer();
    if (!$back) $back = admin_url('admin.php?page=mlv2_gestores');
    wp_safe_redirect($back);
    exit;
});




// Nota: la desvinculación usuario↔local fue deshabilitada por diseño (solo informativo).


/**
 * Menú
 */
add_action('admin_menu', function () {

    add_menu_page(
        'Mi Lata Vale',
        'Mi Lata Vale',
        'manage_options',
        'mlv2_movimientos',
        function () { MLV2_Admin_Pages::render_movimientos('all', 'Movimientos'); },
        'dashicons-clipboard',
        26
    );

    add_submenu_page('mlv2_movimientos','Movimientos','Movimientos','manage_options','mlv2_movimientos', function () {
        MLV2_Admin_Pages::render_movimientos('all', 'Movimientos');
    });

    add_submenu_page('mlv2_movimientos','Almacenes','Almacenes','manage_options','mlv2_almacenes', function () {
        MLV2_Admin_Pages::render_usuarios('um_almacen', 'Almacenes');
    });

    add_submenu_page('mlv2_movimientos','Clientes','Clientes','manage_options','mlv2_clientes', function () {
        MLV2_Admin_Pages::render_usuarios('um_cliente', 'Clientes');
    });

    add_submenu_page('mlv2_movimientos','Gestores','Gestores','manage_options','mlv2_gestores', function () {
        MLV2_Admin_Pages::render_usuarios('um_gestor', 'Gestores');
    });

    add_submenu_page('mlv2_movimientos','Incentivos','Incentivos','manage_options','mlv2_incentivos', ['MLV2_Admin_Incentivos','render']);
    add_submenu_page('mlv2_movimientos','Regularización histórica','Regularización histórica','manage_options','mlv2_regularizacion_historica', ['MLV2_Admin_Regularizacion','render']);
    add_submenu_page('mlv2_movimientos','Ajustes contables','Ajustes contables','manage_options','mlv2_ajustes_contables', ['MLV2_Admin_Ajustes','render']);

    // Página interna para editar un movimiento (especialmente gastos)
    add_submenu_page(
        null,
        'Editar movimiento',
        'Editar movimiento',
        'manage_options',
        'mlv2_edit_movimiento',
        ['MLV2_Admin_Edit_Movimiento','render']
    );

    add_submenu_page(
        null,
        'Reversar movimiento',
        'Reversar movimiento',
        'manage_options',
        'mlv2_reverse_movimiento',
        ['MLV2_Admin_Reverse_Movimiento','render']
    );

    add_submenu_page(
        null,
        'Reversar lote incentivo',
        'Reversar lote incentivo',
        'manage_options',
        'mlv2_reverse_incentivo_batch',
        ['MLV2_Admin_Reverse_Incentivo_Batch','render']
    );

    add_submenu_page('mlv2_movimientos','Ajustes','Ajustes','manage_options','mlv2_settings', ['MLV2_Admin_Settings','render']);
    add_submenu_page('mlv2_movimientos','Diagnostico','Diagnostico','manage_options','mlv2_diagnostico', ['MLV2_Admin_Diagnostico','render']);
});
