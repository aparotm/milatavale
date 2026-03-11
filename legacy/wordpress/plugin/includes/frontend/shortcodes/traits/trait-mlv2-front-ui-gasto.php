<?php
if (!defined('ABSPATH')) { exit; }

trait MLV2_Front_UI_Gasto_Trait {

    public static function registro_gasto(): string {

        $must = self::must_login();
        if ($must) return $must;

        $uid = get_current_user_id();
        if (!self::user_has_role($uid, 'um_almacen') && !current_user_can('manage_options')) {
            return self::wrap('<div class="mlv2-alert mlv2-alert--warn"><strong>Este formulario es solo para almacenes.</strong></div>');
        }

        $local    = self::get_local_codigo($uid);
        $clientes = self::get_clientes_by_local($local);
        // Evitar que el almacén se registre a sí mismo como cliente
        $almacen_rut_norm = class_exists('MLV2_RUT') ? MLV2_RUT::normalize((string)get_user_meta($uid, 'mlv_rut', true)) : '';
        if (!empty($clientes)) {
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

        $prefill_cliente_id = isset($_GET['mlv2_cliente_id']) ? (int) sanitize_text_field(wp_unslash($_GET['mlv2_cliente_id'])) : 0;
        if ($prefill_cliente_id > 0) {
            $allowed_ids = array_map(static function($c) { return (int)($c->ID ?? 0); }, (array)$clientes);
            if (!in_array($prefill_cliente_id, $allowed_ids, true)) {
                $prefill_cliente_id = 0;
            }
        }

        // Mensajes
        $res = isset($_GET['mlv2_res']) ? sanitize_text_field(wp_unslash($_GET['mlv2_res'])) : '';
        $msg = '';
        if ($res === 'success') {
            $msg = '<div class="mlv2-alert mlv2-alert--ok"><strong>Gasto registrado correctamente.</strong></div>';
        } elseif ($res === 'strict_mode_block') {
            $msg = '<div class="mlv2-alert mlv2-alert--warn"><strong>Operacion bloqueada por modo estricto.</strong> Revisa el Diagnostico en wp-admin y corrige las fallas criticas.</div>';
        } elseif ($res === 'local_no_configurado') {
            $msg = '<div class="mlv2-alert mlv2-alert--warn"><strong>Falta configuración del local.</strong> Completa tu perfil de almacén antes de registrar gastos.</div>';
        } elseif ($res === 'saldo_insuficiente') {
            $msg = '<div class="mlv2-alert mlv2-alert--warn"><strong>Saldo insuficiente.</strong> El cliente no tiene saldo para ese gasto.</div>';
        } elseif ($res === 'error_monto') {
            $msg = '<div class="mlv2-alert mlv2-alert--warn"><strong>Falta monto.</strong> Ingresa un monto válido.</div>';
        } elseif ($res === 'error_cliente') {
            $msg = '<div class="mlv2-alert mlv2-alert--warn"><strong>Falta cliente.</strong> Selecciona un cliente.</div>';
        } elseif ($res === 'doble_rol_bloqueado') {
            $msg = '<div class="mlv2-alert mlv2-alert--warn"><strong>No puedes registrar clientes con tu mismo RUT.</strong></div>';
        } elseif ($res === 'error') {
            $msg = '<div class="mlv2-alert mlv2-alert--warn"><strong>Error.</strong> Intenta nuevamente.</div>';
        }

        // Paso (2 pasos server-side)
        $step = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mlv2_step'])) {
            $step = sanitize_text_field(wp_unslash($_POST['mlv2_step']));
        }

        $cliente_user_id = 0;
        $monto = 0;
        $observacion = '';
        $evidencia_url = '';

        if ($step === 'confirm') {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'mlv2_registro_gasto_preview')) {
                $step = '';
            } else {
                $cliente_user_id = isset($_POST['cliente_user_id']) ? (int) $_POST['cliente_user_id'] : 0;
                $monto           = isset($_POST['monto']) ? (int) $_POST['monto'] : 0;
                $observacion     = isset($_POST['observacion']) ? sanitize_text_field(wp_unslash($_POST['observacion'])) : '';

                // Evidencia (opcional): en el paso preview se sube y se pasa como URL al handler final
                if (!empty($_FILES['evidencia']['name']) && !empty($_FILES['evidencia']['tmp_name'])) {
                    $max_bytes = 5 * 1024 * 1024;
                    $size = isset($_FILES['evidencia']['size']) ? (int) $_FILES['evidencia']['size'] : 0;
                    if ($size <= $max_bytes) {
                        $allowed_mimes = [
                            'jpg'  => 'image/jpeg',
                            'jpeg' => 'image/jpeg',
                            'png'  => 'image/png',
                        ];
                        require_once ABSPATH . 'wp-admin/includes/file.php';

                        $file = $_FILES['evidencia'];
                        $check = function_exists('wp_check_filetype_and_ext')
                            ? wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes)
                            : null;

                        if (is_array($check) && !empty($check['ext']) && !empty($check['type'])) {
                            $uploaded = wp_handle_upload($file, ['test_form' => false, 'mimes' => $allowed_mimes]);
                            if (!empty($uploaded['url'])) {
                                $evidencia_url = (string) $uploaded['url'];
                            }
                        }
                    }
                } elseif (!empty($_POST['evidencia_url'])) {
                    $evidencia_url = esc_url_raw(wp_unslash($_POST['evidencia_url']));
                }
            }
        }

        // Etiqueta cliente
        $cliente_label = '';
        if ($cliente_user_id > 0) {
            $u = get_user_by('id', $cliente_user_id);
            if ($u) {
                $rut = (string) get_user_meta($cliente_user_id, 'mlv_rut', true);
                $cliente_label = ($u->display_name ?: $u->user_login);
                if ($rut !== '') $cliente_label .= ' — ' . $rut;
            }
        }

        ob_start();
        ?>
        <div class="mlv2-wrap um">

            <?php echo self::section_header('Registro de gasto', 'Descuenta saldo del cliente (monto en pesos).'); ?>
            <?php echo $msg; ?>

            <div class="mlv2-card um">

                <?php if ($step === 'confirm' && $cliente_user_id > 0 && $monto > 0): ?>

                    <div class="mlv2-alert mlv2-alert--warn"><strong>Confirma el gasto</strong></div>

                    <p><strong>Cliente:</strong> <?php echo esc_html($cliente_label ?: ('ID ' . (int)$cliente_user_id)); ?></p>
                    <p><strong>Monto:</strong> $<?php echo number_format((int)$monto, 0, ',', '.'); ?></p>
                    <?php
                    $saldo_actual = (int) (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','get_saldo_cliente') ? MLV2_Ledger::get_saldo_cliente($cliente_user_id) : 0);
                    $saldo_despues = (int) ($saldo_actual - (int)$monto);
                    ?>
                    <p><strong>Saldo del cliente:</strong> <?php echo esc_html(self::money((float)$saldo_actual)); ?></p>
                    <p><strong>Saldo después del gasto:</strong> <?php echo esc_html(self::money((float)$saldo_despues)); ?></p>

                    <?php if ($evidencia_url !== ''): ?>
                        <p><strong>Evidencia:</strong> <a href="<?php echo esc_url($evidencia_url); ?>" target="_blank" rel="noopener">Ver</a></p>
                    <?php endif; ?>

                    <?php if ($observacion !== ''): ?>
                        <p><strong>Motivo / detalle:</strong> <?php echo esc_html($observacion); ?></p>
                    <?php endif; ?>

                    <div class="mlv2-btn-row">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                            <input type="hidden" name="action" value="mlv2_registro_gasto">
                            <?php wp_nonce_field('mlv2_registro_gasto'); ?>
                            <input type="hidden" name="cliente_user_id" value="<?php echo (int) $cliente_user_id; ?>">
                            <input type="hidden" name="monto" value="<?php echo (int) $monto; ?>">
                            <input type="hidden" name="observacion" value="<?php echo esc_attr($observacion); ?>">
                            <input type="hidden" name="evidencia_url" value="<?php echo esc_attr($evidencia_url); ?>">
                            <button class="um-button um-alt" type="submit">Confirmar y descontar</button>
                        </form>

                        <a class="um-button" href="<?php echo esc_url(remove_query_arg(['mlv2_res'])); ?>">Volver</a>
                    </div>

                <?php else: ?>

                    <form class="um-form" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="mlv2_step" value="confirm">
                        <?php wp_nonce_field('mlv2_registro_gasto_preview'); ?>

                        <p>
                            <select class="um-input" name="cliente_user_id" required>
                                <option value="">— Selecciona un cliente —</option>
                                <?php if (!empty($clientes)): ?>
                                    <?php foreach ($clientes as $c): ?>
                                        <?php
                                        $rut = (string) get_user_meta($c->ID, 'mlv_rut', true);
                                    $label = ($c->display_name ?: $c->user_login);
                                    $rutf = class_exists('MLV2_RUT') ? MLV2_RUT::format($rut) : $rut;
                                    if ($rutf !== '') $label .= ' – ' . $rutf;

                                        $saldo_disp = 0;
                                        if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','get_saldo_cliente')) {
                                            $saldo_disp = (int) MLV2_Ledger::get_saldo_cliente($c->ID);
                                        }
                                        $label2 = $label . ' (Máximo: ' . self::format_money($saldo_disp) . ')';
                                        ?>
                                        <option value="<?php echo (int) $c->ID; ?>" data-saldo="<?php echo (int)$saldo_disp; ?>"<?php selected((int)$prefill_cliente_id, (int)$c->ID); ?>><?php echo esc_html($label2); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </p>

                        <p>
                            <label><strong>Monto del gasto ($)</strong></label><br>
                            <input class="um-input" type="number" name="monto" min="1" step="1" required>
                        </p>

                        <p>
                            <label><strong>Motivo / detalle</strong> <small>(opcional)</small></label><br>
                            <input class="um-input" type="text" name="observacion">
                        </p>

                        <p>
                            <label><strong>Evidencia</strong> <small>(opcional)</small></label><br>
                            <div class="mlv2-file">
                                <input id="mlv2-evidencia-gasto" class="mlv2-file__input" type="file" name="evidencia" accept="image/*">
                                <label for="mlv2-evidencia-gasto" class="um-button">Subir foto</label>
                                <span id="mlv2-evidencia-gasto-name" class="mlv2-file__name" aria-live="polite"></span>

                                <script>
                                (function(){
                                  function initMLV2PreviewGasto(){
                                    var input = document.getElementById('mlv2-evidencia-gasto');
                                    var span  = document.getElementById('mlv2-evidencia-gasto-name');
                                    if(!input || !span) return;

                                    // Hide the filename span forever (keeps UI consistent with ingreso)
                                    span.style.display = 'none';
                                    try{
                                      var obs = new MutationObserver(function(){
                                        span.style.display = 'none';
                                        span.textContent = '';
                                      });
                                      obs.observe(span,{childList:true,subtree:true,characterData:true});
                                    }catch(e){}

                                    // Create preview box if missing
                                    var box = document.getElementById('mlv2-preview-box-gasto');
                                    if(!box){
                                      box = document.createElement('div');
                                      box.id = 'mlv2-preview-box-gasto';
                                      box.style.marginTop = '10px';
                                      box.style.textAlign = 'center';
                                      var img = document.createElement('img');
                                      img.alt = 'Previsualización';
                                      img.style.maxWidth = '100%';
                                      img.style.borderRadius = '8px';
                                      img.style.boxShadow = '0 2px 6px rgba(0,0,0,.15)';
                                      box.appendChild(img);
                                      span.parentNode.insertBefore(box, span.nextSibling);
                                      box.style.display = 'none';
                                    }

                                    input.addEventListener('change', function(){
                                      var f = input.files && input.files[0];
                                      if(!f || !f.type || f.type.indexOf('image/') !== 0){
                                        if(box) box.style.display = 'none';
                                        return;
                                      }
                                      var r = new FileReader();
                                      r.onload = function(ev){
                                        var img = box.querySelector('img');
                                        img.src = ev.target.result;
                                        box.style.display = 'block';
                                      };
                                      r.readAsDataURL(f);
                                    });
                                  }

                                  if(document.readyState === 'loading'){
                                    document.addEventListener('DOMContentLoaded', initMLV2PreviewGasto);
                                  } else {
                                    initMLV2PreviewGasto();
                                  }
                                })();

                                // === Frontend image compression (safe) ===
                                (function(){
                                  const input = document.getElementById('mlv2-evidencia-gasto');
                                  if(!input) return;

                                  input.addEventListener('change', function(){
                                    const file = input.files && input.files[0];
                                    if(!file || !file.type || !file.type.startsWith('image/')) return;

                                    const img = new Image();
                                    const reader = new FileReader();
                                    reader.onload = function(ev){ img.src = ev.target.result; };

                                    img.onload = function(){
                                      const maxWidth = 1280;
                                      let width = img.width;
                                      let height = img.height;
                                      if(width > maxWidth){
                                        height = Math.round(height * (maxWidth / width));
                                        width = maxWidth;
                                      }
                                      const canvas = document.createElement('canvas');
                                      canvas.width = width;
                                      canvas.height = height;
                                      const ctx = canvas.getContext('2d');
                                      ctx.drawImage(img, 0, 0, width, height);

                                      canvas.toBlob(function(blob){
                                        if(!blob) return;
                                        const compressed = new File([blob], 'evidencia.jpg', {
                                          type: 'image/jpeg',
                                          lastModified: Date.now()
                                        });
                                        const dt = new DataTransfer();
                                        dt.items.add(compressed);
                                        input.files = dt.files;
                                      }, 'image/jpeg', 0.6);
                                    };

                                    reader.readAsDataURL(file);
                                  });
                                })();
                                </script>
                            </div>
                        </p>

                        <p>
                            <button class="um-button um-alt" type="submit">Revisar gasto</button>
                        </p>
                    </form>

                <?php endif; ?>

            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
