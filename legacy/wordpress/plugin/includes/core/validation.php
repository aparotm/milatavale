<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Validation {

    public static function init(): void {
        add_action('admin_post_mlv2_registro_latas', [__CLASS__, 'handle_registro_latas_post']);
    }

    /**
     * Registro de latas por almacén
     * Crédito inmediato al cliente (FASE 1)
     */
    public static function handle_registro_latas_post(): void {
        MLV2_Security::require_role(['um_almacen','administrator']);
        MLV2_Security::verify_post_nonce('mlv2_registro_latas');

        $cliente_user_id = isset($_POST['cliente_user_id']) ? (int) $_POST['cliente_user_id'] : 0;
        $cliente_rut     = isset($_POST['cliente_rut']) ? sanitize_text_field(wp_unslash($_POST['cliente_rut'])) : '';
        $cantidad        = isset($_POST['cantidad_latas']) ? (int) $_POST['cantidad_latas'] : 0;
        $obs             = isset($_POST['observacion']) ? sanitize_text_field(wp_unslash($_POST['observacion'])) : '';

        if ($cantidad <= 0) {
            wp_safe_redirect(add_query_arg('mlv2_res', 'error', wp_get_referer() ?: home_url('/')));
            exit;
        }

        $almacen_id   = get_current_user_id();
        $local_codigo = class_exists('MLV2_Movement_Service')
            ? MLV2_Movement_Service::get_local_codigo($almacen_id)
            : (function_exists('mlv2_get_local_codigo_for_user')
                ? mlv2_get_local_codigo_for_user($almacen_id)
                : trim((string) get_user_meta($almacen_id, 'mlv_local_codigo', true)));
        if ($local_codigo === '') {
            wp_safe_redirect(add_query_arg('mlv2_res', 'local_no_configurado', wp_get_referer() ?: home_url('/')));
            exit;
        }

        // Resolver cliente
        if ($cliente_user_id > 0) {
            if ($cliente_rut === '') {
                $cliente_rut = (string) get_user_meta($cliente_user_id, 'mlv_rut', true);
            }
        } else {
            $cliente_user_id = MLV2_Ledger::find_cliente_by_rut($cliente_rut);
        }

        if (!$cliente_user_id) {
            wp_safe_redirect(add_query_arg('mlv2_res', 'cliente_no_encontrado', wp_get_referer() ?: home_url('/')));
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
            wp_safe_redirect(add_query_arg('mlv2_res', 'doble_rol_bloqueado', wp_get_referer() ?: home_url('/')));
            exit;
        }
        // Evidencia (opcional)
        // Seguridad: restringimos tipos y tamaño; evitamos uploads peligrosos.
        $evidencia_url = '';
        if (!empty($_FILES['evidencia']['name']) && !empty($_FILES['evidencia']['tmp_name'])) {
            // Límite 5MB
            $max_bytes = 5 * 1024 * 1024;
            $size = isset($_FILES['evidencia']['size']) ? (int) $_FILES['evidencia']['size'] : 0;
            if ($size > $max_bytes) {
                wp_safe_redirect(add_query_arg('mlv2_res', 'evidencia_muy_grande', wp_get_referer() ?: home_url('/')));
                exit;
            }

            $allowed_mimes = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                            ];

            require_once ABSPATH . 'wp-admin/includes/file.php';

            $file = $_FILES['evidencia'];
            $check = function_exists('wp_check_filetype_and_ext') ? wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes) : null;
            if (!is_array($check) || empty($check['ext']) || empty($check['type'])) {
                wp_safe_redirect(add_query_arg('mlv2_res', 'evidencia_tipo_invalido', wp_get_referer() ?: home_url('/')));
                exit;
            }

            $uploaded = wp_handle_upload($file, ['test_form' => false, 'mimes' => $allowed_mimes]);
            if (!empty($uploaded['url'])) {
                $evidencia_url = (string) $uploaded['url'];
            }
        } elseif (!empty($_POST['evidencia_url'])) {
            // Si viene desde el paso de confirmación, solo aceptamos URL sanitizada.
            $evidencia_url = esc_url_raw(wp_unslash($_POST['evidencia_url']));
        }

        $valor_por_lata = (int) (class_exists('MLV2_Pricing') ? MLV2_Pricing::get_price_per_lata() : 0);

        $monto = (int) ($cantidad * $valor_por_lata);
        $detalle = [
            'tipo' => 'ingreso',
            'origen' => 'reciclaje',
            'clasificacion' => 'operacion',
            'evidencia_url' => $evidencia_url,
            'declarado' => [
                'cantidad_latas' => $cantidad,
                'observacion'    => $obs,
                'evidencia_url'  => $evidencia_url,
                'user_id'        => $almacen_id,
                'ts'             => current_time('mysql'),
            ],

            // Crédito inmediato al cliente
            'credito_cliente' => [
                'applied'    => true,
                'applied_at' => current_time('mysql'),
                'user_id'    => $almacen_id,
                'monto'      => $monto,
            ],
    'hist' => [
        [
            'ts'            => current_time('mysql'),
            'actor_user_id' => $almacen_id,
            'actor_role'    => 'um_almacen',
            'accion'        => 'registrar_latas',
            'payload'       => [
                'cantidad_latas' => $cantidad,
                'monto'          => $monto,
                'evidencia_url'  => $evidencia_url,
                'observacion'    => $obs,
            ],
            'estado'        => 'pendiente_retiro',
        ],
    ],

    'historial' => [
        [
            'ts'            => current_time('mysql'),
            'actor_user_id' => $almacen_id,
            'estado'        => 'pendiente_retiro',
            'patch'         => ['accion' => 'registro_almacen'],
        ],
    ],
];

        $mov_id = class_exists('MLV2_Movement_Service')
            ? MLV2_Movement_Service::insert_ingreso([
                'estado' => 'pendiente_retiro',
                'local_codigo' => $local_codigo,
                'created_by_user_id' => $almacen_id,
                'cliente_user_id' => $cliente_user_id,
                'cliente_rut' => $cliente_rut,
                'cantidad_latas' => $cantidad,
                'valor_por_lata' => $valor_por_lata,
                'monto_calculado' => $monto,
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

        // Mantener snapshot consistente con la fuente de verdad (ledger)
        if (class_exists('MLV2_Movement_Service')) {
            MLV2_Movement_Service::recalc_cliente_saldo($cliente_user_id);
        } elseif (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger', 'recalc_saldo_cliente')) {
            MLV2_Ledger::recalc_saldo_cliente($cliente_user_id);
        }

        // Alerta al cliente
        if (class_exists('MLV2_Alerts')) {
            $almacen_user   = wp_get_current_user();
            $almacen_nombre = $almacen_user->display_name ?: ('Usuario #' . $almacen_id);
            $local_nombre   = (string) get_user_meta($almacen_id, 'mlv_local_nombre', true);
            if ($local_nombre === '') {
                $local_nombre = $local_codigo;
            }

            $msg = sprintf(
                '%s del local "%s" acaba de asignarte %d latas.',
                esc_html($almacen_nombre),
                esc_html($local_nombre),
                (int) $cantidad
            );

            MLV2_Alerts::add($cliente_user_id, 'info', $msg, 'movimiento', $mov_id);
        }

        wp_safe_redirect( add_query_arg('mlv2_res','movimiento_ingresado', home_url('/panel/') ) );
        exit;
    }

    /**
     * Validación admin (solo auditoría / corrección)
     * NO abona saldo
     */
    
}
