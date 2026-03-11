<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Settings {

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado');
        }

        // Guardar valor
        if (isset($_POST['mlv2_save_price'])) {
            check_admin_referer('mlv2_save_price');

            $price = (int) ($_POST['price_per_lata'] ?? 0);
            if ($price < 0) { $price = 0; }

            MLV2_Pricing::set_price_per_lata($price);

            wp_safe_redirect(add_query_arg('mlv2_res', 'saved', admin_url('admin.php?page=mlv2_settings')));
            exit;
        }

        // Guardar Turnstile
        if (isset($_POST['mlv2_save_turnstile'])) {
            check_admin_referer('mlv2_save_turnstile');

            $enabled = isset($_POST['mlv2_turnstile_enabled']) ? 1 : 0;
            $site    = isset($_POST['mlv2_turnstile_site_key']) ? sanitize_text_field(wp_unslash($_POST['mlv2_turnstile_site_key'])) : '';
            $secret  = isset($_POST['mlv2_turnstile_secret_key']) ? sanitize_text_field(wp_unslash($_POST['mlv2_turnstile_secret_key'])) : '';

            update_option('mlv2_turnstile_enabled', $enabled);
            update_option('mlv2_turnstile_site_key', trim($site));
            update_option('mlv2_turnstile_secret_key', trim($secret));
            update_option('mlv2_strict_mode_enabled', isset($_POST['mlv2_strict_mode_enabled']) ? 1 : 0);

            wp_safe_redirect(add_query_arg('mlv2_res', 'turnstile_saved', admin_url('admin.php?page=mlv2_settings')));
            exit;
        }

        $price = MLV2_Pricing::get_price_per_lata();
        $res = isset($_GET['mlv2_res']) ? sanitize_text_field(wp_unslash($_GET['mlv2_res'])) : '';

        $ts_enabled = (int) get_option('mlv2_turnstile_enabled', 0);
        $ts_site    = (string) get_option('mlv2_turnstile_site_key', '');
        $ts_secret  = (string) get_option('mlv2_turnstile_secret_key', '');
        $strict_mode = (int) get_option('mlv2_strict_mode_enabled', 0);

        ?>
        <div class="wrap">
            <h1>Ajustes – Mi Lata Vale</h1>

            <?php if ($res === 'saved'): ?>
                <div class="notice notice-success"><p>Valor por lata guardado.</p></div>
            <?php elseif ($res === 'recalculated'): ?>
                <div class="notice notice-success"><p>Recalculo completado correctamente.</p></div>
            <?php elseif ($res === 'price_zero'): ?>
                <div class="notice notice-warning"><p>El valor por lata está en 0. Si recalculas, los montos consolidados quedarán en 0.</p></div>
            <?php elseif ($res === 'turnstile_saved'): ?>
                <div class="notice notice-success"><p>Configuración Turnstile guardada.</p></div>
            <?php endif; ?>

            <h2>Valor por lata</h2>

            <form method="post">
                <?php wp_nonce_field('mlv2_save_price'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="price_per_lata">Valor monetario por lata</label></th>
                            <td>
                                <input type="number" id="price_per_lata" name="price_per_lata"
                                       value="<?php echo esc_attr($price); ?>" min="0" step="1">
                                <p class="description">
                                    Se usa como <strong>monto equivalente (informativo)</strong> y para consolidación del admin.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php
                // CLAVE: nombre del submit para que el if funcione
                submit_button('Guardar valor', 'primary', 'mlv2_save_price');
                ?>
            </form>

            <hr>

            <h2>Seguridad – Captcha (Cloudflare Turnstile)</h2>

            <p>
                Turnstile ayuda a bloquear bots en el login por RUT sin fricción grande.
                Necesitas crear un sitio en Cloudflare Turnstile y pegar <strong>Site Key</strong> y <strong>Secret Key</strong>.
            </p>

            <form method="post">
                <?php wp_nonce_field('mlv2_save_turnstile'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">Activar Turnstile</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mlv2_turnstile_enabled" value="1" <?php checked(1, $ts_enabled); ?>>
                                    Usar Turnstile en el login por RUT
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mlv2_turnstile_site_key">Site Key</label></th>
                            <td>
                                <input type="text" class="regular-text" id="mlv2_turnstile_site_key" name="mlv2_turnstile_site_key" value="<?php echo esc_attr($ts_site); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mlv2_turnstile_secret_key">Secret Key</label></th>
                            <td>
                                <input type="text" class="regular-text" id="mlv2_turnstile_secret_key" name="mlv2_turnstile_secret_key" value="<?php echo esc_attr($ts_secret); ?>">
                                <p class="description">Se guarda en la base de datos. No compartir públicamente.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Modo estricto</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mlv2_strict_mode_enabled" value="1" <?php checked(1, $strict_mode); ?>>
                                    Bloquear nuevos registros de latas/gastos cuando el Diagnostico detecta fallas estructurales criticas
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button('Guardar Turnstile', 'secondary', 'mlv2_save_turnstile'); ?>
            </form>

            <hr>

            <h2>Recalcular montos consolidados</h2>

            <p>
                Recalcula <strong>solo</strong> movimientos usando el valor actual por lata y reconstruye saldos.
            </p>
            <p><strong>⚠️ Esta acción no se puede deshacer.</strong></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('¿Seguro? Se recalcularán TODOS los montos consolidados.');">
                <?php wp_nonce_field('mlv2_recalculate_all'); ?>
                <input type="hidden" name="action" value="mlv2_recalculate_all">
                <?php submit_button('Recalcular todo', 'delete'); ?>
            </form>
        
        <hr />
        <h2>Auditoría</h2>
        <p>Descarga un registro completo e inmutable de todas las acciones del sistema.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mlv2_export_audit'); ?>
            <input type="hidden" name="action" value="mlv2_export_audit">
            <?php submit_button('Descargar log completo', 'secondary'); ?>
        </form>

        </div>
        <?php
    }
}
