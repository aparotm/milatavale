<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/shortcodes/class-mlv2-front-ui.php';
MLV2_Front_UI::init();



/**
 * Shortcode: [mlv_registro_cliente_almacen]
 * Visible solo para um_almacen / administrator.
 */
add_shortcode('mlv_registro_cliente_almacen', function() {
    if ( ! is_user_logged_in() ) {
        return '';
    }
    $u = wp_get_current_user();
    if ( ! in_array('um_almacen', (array) $u->roles, true) && ! current_user_can('administrator') ) {
        return '';
    }

    ob_start();

    // Mensajes flash por querystring (fallback).
    $err = isset($_GET['mlv_err']) ? sanitize_key(wp_unslash($_GET['mlv_err'])) : '';
    $ok  = isset($_GET['mlv_ok'])  ? sanitize_key(wp_unslash($_GET['mlv_ok']))  : '';

    $flash = '';
    if ($ok === 'cliente_creado' || $ok === 'cliente_registrado') {
        $flash = '<div class="mlv2-alert mlv2-alert--ok"><strong>Cliente registrado correctamente.</strong></div>';
    } elseif ($err !== '') {
        $map = [
            'rut_existe' => 'Este RUT ya existe.',
            'cliente_existe' => 'Este cliente ya existe.',
            'email_existe' => 'Este email ya está en uso.',
            'rut_pocos_digitos' => 'Tu RUT tiene muy pocos dígitos.',
            'rut_invalido' => 'Tu RUT no parece válido. Debe incluir dígito verificador.',
            'doble_rol_bloqueado' => 'No puedes registrar clientes con tu mismo RUT.',
            'faltan_datos' => 'Faltan datos obligatorios.',
            'nonce' => 'Sesión vencida. Recarga e intenta nuevamente.',
            'error' => 'No se pudo registrar. Intenta nuevamente.',
        ];
        $msg = $map[$err] ?? 'No se pudo registrar.';
        $flash = '<div class="mlv2-alert mlv2-alert--warn"><strong>Error:</strong> ' . esc_html($msg) . '</div>';
    }
    ?>
    <div class="mlv2-wrap um">
        <div class="mlv2-section-header">
            <h2 class="mlv2-h2"><?php echo esc_html('Registrar cliente'); ?></h2>
            <small class="mlv2-small"><?php echo wp_kses_post('Crea un nuevo cliente para tu almacén.'); ?></small>
        </div>

        <?php echo $flash; ?>

        <div class="mlv2-card um">
            <form method="post" class="um-form" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field('mlv_registro_cliente_almacen', 'mlv_nonce'); ?>
                <input type="hidden" name="action" value="mlv_registro_cliente_almacen">

                <p>
                    <label><strong>Nombre</strong></label><br>
                    <input class="um-input" type="text" name="nombre" required>
                </p>

                <p>
                    <label><strong>Apellido</strong></label><br>
                    <input class="um-input" type="text" name="apellido" required>
                </p>

                <p>
                    <label><strong>RUT</strong></label><br>
                    <input class="um-input" type="text" name="rut" required inputmode="text" placeholder="xx.xxx.xxx-x" aria-describedby="mlv2-rut-help">
                    <p class="mlv2-help">Escribe el RUT del cliente con puntos y guion (ej.: 12.345.678-5)</p>
                </p>

                <p>
                    <label><strong>Teléfono</strong></label><br>
                    <input class="um-input" type="text" name="telefono" required>
                </p>

                <p>
                    <label><strong>Email</strong> <small>(opcional)</small></label><br>
                    <input class="um-input" type="email" name="email">
                </p>

                <p>
                    <button type="submit" class="um-button um-alt">Registrar cliente</button>
                </p>

                <p class="mlv2-help">El cliente podrá ingresar con su <strong>RUT</strong> (la contraseña es el mismo RUT).</p>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
});
