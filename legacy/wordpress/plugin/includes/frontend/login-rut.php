<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode [mlv_login_rut]
 * Login simple por RUT.
 * - UX: pide "Contraseña" pero en la práctica es repetir el RUT (no agrega seguridad real, solo fricción).
 * - Seguridad real: rate limit + honeypot + Cloudflare Turnstile (opcional, configurable en wp-admin).
 */

add_shortcode( 'mlv_login_rut', 'mlv2_render_login_rut' );

function mlv2_login_rut_norm( string $rut ): string {
    $rut = strtoupper( trim( $rut ) );
    if (class_exists('MLV2_RUT')) {
        $p = MLV2_RUT::parse($rut);
        return !empty($p['ok']) ? (string)$p['norm'] : '';
    }
    $rut = str_replace( [ '.', '-', ' ' ], '', $rut );
    return $rut;
}

function mlv2_login_rut_rate_key( string $rut_norm ): string {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    return 'mlv2_rut_login_' . md5( $ip . '|' . $rut_norm );
}

function mlv2_login_rut_rate_key_ip(): string {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    return 'mlv2_rut_login_ip_' . md5( $ip );
}

/**
 * Turnstile (opcional): verifica el token con Cloudflare.
 */
function mlv2_turnstile_is_enabled(): bool {
    return (int) get_option('mlv2_turnstile_enabled', 0) === 1
        && trim((string) get_option('mlv2_turnstile_site_key', '')) !== ''
        && trim((string) get_option('mlv2_turnstile_secret_key', '')) !== '';
}

function mlv2_turnstile_verify( string $token ): bool {
    $token = trim((string)$token);
    if ($token === '') return false;

    $secret = trim((string) get_option('mlv2_turnstile_secret_key', ''));
    if ($secret === '') return false;

    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

    $resp = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 8,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body'    => [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ],
    ]);

    if (is_wp_error($resp)) return false;
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return false;
    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);
    return is_array($data) && !empty($data['success']);
}

function mlv2_render_login_rut() {

    // Evitar ejecuciones dentro del wp-admin (editor, previsualizaciones, etc).
    // Si el shortcode se renderiza en el admin y el usuario está logueado (admin),
    // la redirección a /panel/ impedía editar la página.
    if ( is_admin() || wp_doing_ajax() || ( defined('REST_REQUEST') && REST_REQUEST ) ) {
        return '<div class="mlv2-wrap um"><div class="mlv2-card um"><div class="mlv2-alert mlv2-alert--info"><strong>[mlv_login_rut]</strong> Este formulario solo se muestra en el sitio (frontend).</div></div></div>';
    }

    if ( is_user_logged_in() ) {
        wp_safe_redirect( home_url( '/panel/' ) );
        exit;
    }

    $error = '';
    $turnstile_enabled = mlv2_turnstile_is_enabled();

    if ( isset( $_POST['mlv_login_rut_submit'] ) ) {

        // Nonce (anti-CSRF)
        $nonce_ok = isset( $_POST['mlv_login_rut_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mlv_login_rut_nonce'] ) ), 'mlv2_login_rut' );
        if ( ! $nonce_ok ) {
            $error = 'Solicitud inválida. Intenta nuevamente.';
        } elseif ( ! empty( $_POST['mlv_hp'] ) ) {
            // Honeypot: los humanos no ven este campo.
            $error = 'Solicitud inválida. Intenta nuevamente.';
        } elseif ( empty( $_POST['mlv_rut'] ) || empty( $_POST['mlv_rut_confirm'] ) ) {
            $error = 'Debes ingresar tu RUT y repetirlo.';
        } else {

            $rut_raw1  = sanitize_text_field( wp_unslash( $_POST['mlv_rut'] ) );
            $rut_raw2  = sanitize_text_field( wp_unslash( $_POST['mlv_rut_confirm'] ) );
            $rut_norm1 = mlv2_login_rut_norm( $rut_raw1 );
            $rut_norm2 = mlv2_login_rut_norm( $rut_raw2 );

            if ( $rut_norm1 === '' || $rut_norm2 === '' ) {
                $error = 'El RUT ingresado no es válido.';
            } elseif ( $rut_norm1 !== $rut_norm2 ) {
                $error = 'Los RUT ingresados no coinciden.';
            } else {

                // Captcha (Turnstile) si está habilitado
                if ( $turnstile_enabled ) {
                    $ts_token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'])) : '';
                    if ( $ts_token === '' || ! mlv2_turnstile_verify( $ts_token ) ) {
                        $error = 'No pudimos verificar que seas humano. Intenta nuevamente.';
                    }
                }

                if ( $error === '' ) {

                    // Rate limit (por IP)
                    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
                    $ip_key   = 'mlv2_lr_ip_' . md5($ip);
                    $rate_key = 'mlv2_lr_rl_' . md5($ip);

                    $blocked_until = (int) get_transient($ip_key);
                    if ( $blocked_until && time() < $blocked_until ) {
                        $error = 'Demasiados intentos. Intenta más tarde.';
                    } else {

                        $tries = (int) get_transient($rate_key);
                        $tries++;
                        set_transient($rate_key, $tries, 10 * MINUTE_IN_SECONDS);

                        // Bloqueo progresivo
                        if ( $tries >= 12 ) {
                            set_transient($ip_key, time() + 30 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS);
                            delete_transient($rate_key);
                            $error = 'Demasiados intentos. Intenta más tarde.';
                        } else {

                            global $wpdb;
                            $rut_norm = $rut_norm1;

                            // Busca usuario por meta mlv_rut_norm o mlv_rut (normalizando)
                            $user_id = $wpdb->get_var( $wpdb->prepare(
                                "
                                SELECT user_id FROM {$wpdb->usermeta}
                                WHERE meta_key IN ('mlv_rut_norm','mlv_rut')
                                AND REPLACE(REPLACE(REPLACE(UPPER(meta_value),'.',''),'-',''),' ','') = %s
                                LIMIT 1
                                ",
                                $rut_norm
                            ) );

                            if ( ! $user_id ) {
                                // Mensaje no enumerable
                                $error = 'RUT inválido o sin acceso.';
                            } else {

                                $user = get_user_by( 'id', (int) $user_id );

                                if ( ! $user ) {
                                    $error = 'RUT inválido o sin acceso.';
                                } else {

                                    $roles = (array) $user->roles;

                                    if ( in_array( 'um_cliente', $roles, true ) || in_array( 'um_almacen', $roles, true ) || in_array( 'um_gestor', $roles, true ) ) {

                                        // Éxito: limpiar rate limit
                                        delete_transient( $ip_key );
                                        delete_transient( $rate_key );

                                        wp_set_current_user( (int) $user_id );
                                        wp_set_auth_cookie( (int) $user_id );
                                        do_action( 'wp_login', $user->user_login, $user );

                                        // No agregamos alerta de "login_ok"; solo enviamos al panel.
                                        wp_safe_redirect( home_url( '/panel/' ) );
                                        exit;

                                    } else {
                                        $error = 'RUT inválido o sin acceso.';
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    ob_start();
    ?>
    <div class="mlv2-wrap um">
        <div class="mlv2-section-header">
            <h2 class="mlv2-h2">Ingresar</h2>
            <small class="mlv2-small">Escribe tu RUT dos veces (funciona como contraseña).</small>
        </div>

        <div class="mlv2-card um">
            <form method="post" class="um-form">
                <?php wp_nonce_field( 'mlv2_login_rut', 'mlv_login_rut_nonce' ); ?>

                <p>
                    <label for="mlv_rut"><strong>RUT</strong></label><br>
                    <input class="um-input" type="text" name="mlv_rut" id="mlv_rut" placeholder="12.345.678-9"
                           value="<?php echo isset($_POST['mlv_rut']) ? esc_attr( wp_unslash( $_POST['mlv_rut'] ) ) : ''; ?>"
                           required autocomplete="username">
                </p>

                <p>
                    <label for="mlv_rut_confirm"><strong>Contraseña</strong></label><br>
                    <span style="position:relative; display:block;">
                        <input class="um-input" type="password" name="mlv_rut_confirm" id="mlv_rut_confirm" placeholder="Contraseña"
                               value="<?php echo isset($_POST['mlv_rut_confirm']) ? esc_attr( wp_unslash( $_POST['mlv_rut_confirm'] ) ) : ''; ?>"
                               required autocomplete="current-password" style="padding-right:44px;">
                        <button
                            type="button"
                            id="mlv_toggle_password"
                            aria-label="Mostrar contraseña"
                            aria-pressed="false"
                            style="position:absolute; right:8px; top:50%; transform:translateY(-50%); border:0; background:transparent; padding:4px; cursor:pointer; line-height:0;"
                        >
                            <svg id="mlv_eye_open" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                            <svg id="mlv_eye_closed" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none;">
                                <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20C5 20 1 12 1 12a21.73 21.73 0 0 1 5.06-6.94"></path>
                                <path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.76 21.76 0 0 1-3.17 4.62"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                                <path d="M9.53 9.53a3.5 3.5 0 0 0 4.95 4.95"></path>
                            </svg>
                        </button>
                    </span>
                </p>
                <script>
                    (function(){
                        var input = document.getElementById('mlv_rut_confirm');
                        var btn = document.getElementById('mlv_toggle_password');
                        var eyeOpen = document.getElementById('mlv_eye_open');
                        var eyeClosed = document.getElementById('mlv_eye_closed');
                        if (!input || !btn) return;

                        btn.addEventListener('click', function(){
                            var show = input.type === 'password';
                            input.type = show ? 'text' : 'password';
                            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                            btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
                            if (eyeOpen && eyeClosed) {
                                eyeOpen.style.display = show ? 'none' : '';
                                eyeClosed.style.display = show ? '' : 'none';
                            }
                        });
                    })();
                </script>

                <!-- Honeypot (oculto) -->
                <div class="mlv2-hp" aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden;">
                    <label>Deja este campo vacío</label>
                    <input type="text" name="mlv_hp" value="" tabindex="-1" autocomplete="off">
                </div>

                <?php if ($turnstile_enabled): ?>
                    <?php $ts_site = trim((string) get_option('mlv2_turnstile_site_key', '')); ?>
                    <div style="margin:12px 0;">
                        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($ts_site); ?>"></div>
                    </div>
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                <?php endif; ?>

                <?php if ( $error ) : ?>
                    <div class="mlv2-alert mlv2-alert--warn" style="margin:12px 0;">
                        <div class="mlv2-alert__body"><?php echo esc_html( $error ); ?></div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="um-button um-alt" name="mlv_login_rut_submit">Ingresar</button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
