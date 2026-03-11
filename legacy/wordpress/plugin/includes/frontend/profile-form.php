<?php
if (!defined('ABSPATH')) exit;

function mlv_user_has_role($user, $role) {
  return $user && !empty($user->roles) && in_array($role, (array)$user->roles, true);
}

function mlv_sanitize_phone($phone) {
  $phone = sanitize_text_field($phone);
  $digits = preg_replace('/\D+/', '', (string)$phone);
  if ($digits === '') return '';

  // Chile: quitar prefijo 56 si existe
  if (strpos($digits, '56') === 0) {
    $digits = substr($digits, 2);
  }

  // Normalizar a móvil 9 dígitos comenzando con 9
  if (strlen($digits) === 8) {
    // a veces omiten el 9
    $digits = '9' . $digits;
  } elseif (strlen($digits) > 9) {
    // tomar últimos 9 dígitos como fallback
    $digits = substr($digits, -9);
  }

  if (strlen($digits) !== 9 || $digits[0] !== '9') {
    return '+56 ' . trim(preg_replace('/\s+/', ' ', $digits));
  }

  $a = substr($digits, 1, 4);
  $b = substr($digits, 5, 4);
  return '+56 9 ' . $a . ' ' . $b;
}

function mlv_phone_e164($phone_formatted) {
  $digits = preg_replace('/\D+/', '', (string)$phone_formatted);
  if ($digits === '') return '';
  if (strpos($digits, '56') === 0) $digits = substr($digits, 2);
  if (strlen($digits) === 8) $digits = '9' . $digits;
  elseif (strlen($digits) > 9) $digits = substr($digits, -9);
  if (strlen($digits) !== 9) return '';
  return '+56' . $digits;
}

function mlv_normalize_time($t) {
  $t = trim((string)$t);
  if ($t === '') return '';
  if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t;
  if (preg_match('/^\d{1}:\d{2}$/', $t)) return '0' . $t;
  return '';
}

function mlv_validate_hours_json($json) {
  $json = (string)$json;
  if (trim($json) === '') return [false, 'Horario inválido.'];

  $data = json_decode($json, true);
  if (!is_array($data)) return [false, 'Horario inválido.'];

  $days = ['mon','tue','wed','thu','fri','sat','sun'];
  foreach ($days as $d) {
    if (!array_key_exists($d, $data)) $data[$d] = [];
    if (!is_array($data[$d])) $data[$d] = [];
  }

  $any = false;

  foreach ($days as $day) {
    $ranges = $data[$day];
    if (!is_array($ranges)) $ranges = [];

    // solo 0 o 1 tramo (simple)
    if (count($ranges) > 1) $ranges = [$ranges[0]];

    if (count($ranges) === 0) {
      $data[$day] = [];
      continue;
    }

    $r = $ranges[0];
    if (!is_array($r) || !isset($r['start'], $r['end'])) return [false, 'Horario inválido.'];

    $start = mlv_normalize_time($r['start']);
    $end   = mlv_normalize_time($r['end']);
    if ($start === '' || $end === '') return [false, 'Horario inválido. Usa formato 24h (HH:MM).'];
    if ($start >= $end) return [false, 'La hora de inicio debe ser menor que la hora de término.'];

    $data[$day] = [['start'=>$start,'end'=>$end]];
    $any = true;
  }

  if (!$any) return [false, 'Debes indicar al menos un día de atención.'];

  return [true, wp_json_encode($data)];
}

/**
 * Comunas de Chile: se obtienen desde un JSON público (cacheado) para no hardcodear 346 comunas en el plugin.
 * Fuente (JSON): https://gist.github.com/juanbrujo/0fd2f4d126b3ce5a95a7dd1f28b3d8dd (raw comunas-regiones.json)
 */
function mlv_get_chile_regiones_comunas() {
  $cache_key = 'mlv_chile_regiones_comunas_v1';
  $cached = get_transient($cache_key);
  if (is_array($cached) && !empty($cached)) return $cached;

  $url = 'https://gist.githubusercontent.com/juanbrujo/0fd2f4d126b3ce5a95a7dd1f28b3d8dd/raw/b8575eb82dce974fd2647f46819a7568278396bd/comunas-regiones.json';
  $resp = wp_remote_get($url, ['timeout' => 8]);
  if (is_wp_error($resp)) return [];
  $body = wp_remote_retrieve_body($resp);
  if (!is_string($body) || trim($body) === '') return [];

  $data = json_decode($body, true);
  if (!is_array($data) || empty($data['regiones']) || !is_array($data['regiones'])) return [];

  // normaliza
  $out = [];
  foreach ($data['regiones'] as $r) {
    $name = isset($r['region']) ? (string)$r['region'] : '';
    $coms = isset($r['comunas']) && is_array($r['comunas']) ? $r['comunas'] : [];
    if ($name === '' || empty($coms)) continue;
    $out[] = ['region' => $name, 'comunas' => array_values(array_filter(array_map('strval', $coms)))];
  }

  if (!empty($out)) set_transient($cache_key, $out, 7 * DAY_IN_SECONDS);
  return $out;
}

function mlv_is_almacen_profile_complete($user_id) {
  $user_id = (int)$user_id;
  $nombre    = trim((string)get_user_meta($user_id, 'mlv_local_nombre', true));
  $comuna    = trim((string)get_user_meta($user_id, 'mlv_local_comuna', true));
  $direccion = trim((string)get_user_meta($user_id, 'mlv_local_direccion', true));
  $hours     = trim((string)get_user_meta($user_id, 'mlv_local_hours', true));

  if ($nombre === '' || $comuna === '' || $direccion === '' || $hours === '') return false;

  $decoded = json_decode($hours, true);
  if (!is_array($decoded)) return false;

  foreach ($decoded as $ranges) {
    if (is_array($ranges) && count($ranges) > 0) return true;
  }
  return false;
}

/**
 * ✅ AJAX save handler (evita form anidado con UM)
 */
add_action('wp_ajax_mlv_profile_save', function () {
  if (!is_user_logged_in()) {
    wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);
  }

  $nonce = $_POST['nonce'] ?? '';
  if (!wp_verify_nonce($nonce, 'mlv_profile_save_ajax')) {
    wp_send_json_error(['message' => 'Sesión inválida. Recarga e intenta nuevamente.'], 403);
  }

  $user = wp_get_current_user();
  $user_id = (int)$user->ID;

  // Datos comunes
  $telefono = mlv_sanitize_phone(wp_unslash($_POST['mlv_telefono'] ?? ''));
  if ($telefono === '') {
    wp_send_json_error(['message' => 'El teléfono es obligatorio.'], 422);
  }

  // Cliente / Gestor
  if (mlv_user_has_role($user, 'um_cliente') || mlv_user_has_role($user, 'um_gestor')) {
    update_user_meta($user_id, 'mlv_telefono', $telefono);
    update_user_meta($user_id, 'mlv_phone_e164', mlv_phone_e164($telefono));
    wp_send_json_success(['message' => 'Datos actualizados.', 'redirect' => add_query_arg('mlv2_res','perfil_actualizado', home_url('/panel/'))]);
  }

  // Almacén
  if (mlv_user_has_role($user, 'um_almacen')) {
    $local_nom = sanitize_text_field(wp_unslash($_POST['mlv_local_nombre'] ?? ''));
    $comuna    = sanitize_text_field(wp_unslash($_POST['mlv_local_comuna'] ?? ''));
    $direccion = sanitize_text_field(wp_unslash($_POST['mlv_local_direccion'] ?? ''));

    // OJO: hours puede venir con backslashes, siempre unslash
    $hours_raw = (string)wp_unslash($_POST['mlv_local_hours'] ?? '');

    if ($local_nom === '' || $comuna === '' || $direccion === '') {
      wp_send_json_error(['message' => 'Completa nombre del local, comuna y dirección.'], 422);
    }

    [$ok, $hours_or_err] = mlv_validate_hours_json($hours_raw);
    if (!$ok) {
      wp_send_json_error(['message' => $hours_or_err], 422);
    }

    update_user_meta($user_id, 'mlv_telefono', $telefono);
    update_user_meta($user_id, 'mlv_phone_e164', mlv_phone_e164($telefono));
    update_user_meta($user_id, 'mlv_local_nombre', $local_nom);
    update_user_meta($user_id, 'mlv_local_comuna', $comuna);
    update_user_meta($user_id, 'mlv_local_direccion', $direccion);
    update_user_meta($user_id, 'mlv_local_hours', $hours_or_err);

    wp_send_json_success(['message' => 'Perfil de almacén actualizado.', 'redirect' => add_query_arg('mlv2_res','perfil_actualizado', home_url('/panel/'))]);
  }

  wp_send_json_error(['message' => 'Rol no permitido.'], 403);
});

add_action('wp_ajax_nopriv_mlv_profile_save', function () {
  wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);
});

/**
 * ✅ Shortcode render (SIN <form> para evitar conflictos con UM)
 * Objetivo: que el markup/clases se vean como el form de gastos,
 * SIN tocar el editor de horarios.
 */
function mlv_profile_form_shortcode() {
  if (!is_user_logged_in()) return '<p>Debes iniciar sesión.</p>';
  $user = wp_get_current_user();

  $readonly_style = 'style="opacity:.6" disabled';

  $first = esc_html($user->first_name);
  $last  = esc_html($user->last_name);
  $email = esc_html($user->user_email);
  $rut   = esc_html((string)get_user_meta($user->ID, 'mlv_rut', true));

  $tel = esc_attr((string)get_user_meta($user->ID, 'mlv_telefono', true));
  $local_codigo = esc_html((string)get_user_meta($user->ID, 'mlv_local_codigo', true));

  // Datos de local
  $ln_db = (string)get_user_meta($user->ID, 'mlv_local_nombre', true);
  $co_db = (string)get_user_meta($user->ID, 'mlv_local_comuna', true);
  $di_db = (string)get_user_meta($user->ID, 'mlv_local_direccion', true);

  // Si venía autollenado con login/display_name, mostrar vacío para obligar a completar
  $fallbacks = [
    strtolower((string)$user->user_login),
    strtolower((string)$user->display_name),
  ];
  $ln = $ln_db;
  if (in_array(strtolower(trim($ln_db)), $fallbacks, true)) $ln = '';

  $ln = esc_attr($ln);
  $co = esc_attr($co_db);
  $di = esc_attr($di_db);

  // Horario
  $hours_db = (string)get_user_meta($user->ID, 'mlv_local_hours', true);
  if (trim($hours_db) === '') {
    $hours_db = wp_json_encode(['mon'=>[],'tue'=>[],'wed'=>[],'thu'=>[],'fri'=>[],'sat'=>[],'sun'=>[]]);
  }

  $nonce = wp_create_nonce('mlv_profile_save_ajax');
  $ajax_url = admin_url('admin-ajax.php');

  ob_start();
  ?>
  <div id="mlv-profile-root" class="mlv2-wrap um mlv2-profile"
       data-ajax-url="<?php echo esc_attr($ajax_url); ?>"
       data-nonce="<?php echo esc_attr($nonce); ?>">

    <div id="mlv-notice-ok" class="mlv-notice-success"></div>
    <div id="mlv-notice-err" class="mlv-notice-error"></div>

    <div class="mlv2-card um">
      <?php
        $is_cliente = mlv_user_has_role($user, 'um_cliente');
        $is_gestor  = mlv_user_has_role($user, 'um_gestor');
        $is_almacen = mlv_user_has_role($user, 'um_almacen');
      ?>

      <?php if ($is_cliente || $is_gestor): ?>
        <!-- ✅ Cliente/Gestor: estilo como gastos -->
        <div class="um-form">
          <p>
            <label>Nombre</label><br>
            <input class="um-input" type="text" value="<?php echo $first; ?>" <?php echo $readonly_style; ?>>
          </p>
          <p>
            <label>Apellidos</label><br>
            <input class="um-input" type="text" value="<?php echo $last; ?>" <?php echo $readonly_style; ?>>
          </p>
          <p>
            <label>Email</label><br>
            <input class="um-input" type="email" value="<?php echo $email; ?>" <?php echo $readonly_style; ?>>
          </p>
          <p>
            <label>RUT</label><br>
            <input class="um-input" type="text" value="<?php echo $rut; ?>" <?php echo $readonly_style; ?>>
          </p>

          <p>
            <label>Teléfono</label><br>
            <input class="um-input" type="text" id="mlv_telefono" value="<?php echo $tel; ?>" required>
          </p>

          <p>
            <button class="um-button um-alt mlv2-btn mlv2-btn--primary" id="mlv-save-btn" type="button">Actualizar Datos</button>
          </p>
        </div>

        <script>
          (function(){
            const root = document.getElementById('mlv-profile-root');
            const btn = document.getElementById('mlv-save-btn');
            const ok = document.getElementById('mlv-notice-ok');
            const err = document.getElementById('mlv-notice-err');

            function show(el, msg){ el.textContent = msg; el.style.display = 'block'; }

            btn.addEventListener('click', async function(){
              btn.disabled = true;
              ok.style.display='none'; err.style.display='none';

              const fd = new FormData();
              fd.append('action', 'mlv_profile_save');
              fd.append('nonce', root.dataset.nonce);
              fd.append('mlv_telefono', (document.getElementById('mlv_telefono')||{}).value || '');

              try{
                const res = await fetch(root.dataset.ajaxUrl, {method:'POST', body: fd});
                const json = await res.json();
                if (json && json.success) {
                  show(ok, json.data?.message || 'Datos actualizados.');
                  window.location.href = json.data?.redirect || '<?php echo esc_js(home_url('/panel/')); ?>';
                } else {
                  show(err, json.data?.message || 'No se pudo guardar.');
                }
              }catch(e){
                show(err, 'Error de red. Intenta nuevamente.');
              }finally{
                btn.disabled = false;
              }
            });
          })();
        </script>
      <?php endif; ?>

      <?php if ($is_almacen): ?>
        <!-- ✅ Almacén: inputs/botón con tags como gastos, horarios intactos -->
        <div class="um-form">
          <p>
            <label>Nombre del Local</label><br>
            <input class="um-input" type="text" id="mlv_local_nombre" value="<?php echo $ln; ?>" placeholder="Ej: Minimarket San Pedro" required>
          </p>

          <?php $regiones = mlv_get_chile_regiones_comunas(); ?>
          <p>
            <label>Comuna</label><br>
            <select id="mlv_local_comuna" class="um-input" required>
              <option value="">Selecciona una comuna…</option>
              <?php if (!empty($regiones)): ?>
                <?php foreach ($regiones as $r): $rname = (string)($r['region'] ?? ''); $coms = $r['comunas'] ?? []; ?>
                  <optgroup label="<?php echo esc_attr($rname); ?>">
                    <?php foreach ((array)$coms as $c): $c = (string)$c; $sel = ($c !== '' && $c === $co_db) ? 'selected' : ''; ?>
                      <option value="<?php echo esc_attr($c); ?>" <?php echo $sel; ?>><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              <?php else: ?>
                <option value="<?php echo esc_attr($co_db); ?>" selected><?php echo esc_html($co_db ?: '(sin comuna)'); ?></option>
              <?php endif; ?>
            </select>
          </p>

          <p>
            <label>Dirección del local</label><br>
            <input class="um-input" type="text" id="mlv_local_direccion" value="<?php echo $di; ?>" placeholder="Ej: Av. Principal 1234" required>
          </p>

          <p>
            <label>Teléfono</label><br>
            <input class="um-input" type="text" id="mlv_telefono" value="<?php echo $tel; ?>" required>
          </p>

          <h3>Horario de atención</h3>
          <!-- ✅ BLOQUE HORARIOS: EXACTAMENTE TU ORIGINAL -->
          <input type="hidden" id="mlv_local_hours" value="<?php echo esc_attr($hours_db); ?>">

          <div class="mlv-hours" id="mlv-hours-editor" data-initial="<?php echo esc_attr($hours_db); ?>">
            <div class="mlv-row" style="justify-content:flex-end;margin-top:0">
              <button type="button" class="mlv-btn" id="mlv-copy-mon-to-weekdays">Copiar horario del Lunes a L-V</button>
            </div>
            <div id="mlv-days"></div>
            <div class="mlv-error" id="mlv-hours-error" style="display:none;"></div>
          </div>

          <p>
            <button class="um-button um-alt mlv2-btn mlv2-btn--primary" id="mlv-save-btn" type="button">Actualizar Datos</button>
          </p>

          <!-- ✅ SCRIPT HORARIOS: EXACTAMENTE TU ORIGINAL -->
          <script>
          (function(){
            const root = document.getElementById('mlv-profile-root');
            const btn = document.getElementById('mlv-save-btn');
            const ok = document.getElementById('mlv-notice-ok');
            const err = document.getElementById('mlv-notice-err');

            const editor = document.getElementById('mlv-hours-editor');
            const daysWrap = document.getElementById('mlv-days');
            const hidden = document.getElementById('mlv_local_hours');
            const errBox = document.getElementById('mlv-hours-error');

            const DAY_LABELS = {mon:'Lunes',tue:'Martes',wed:'Miércoles',thu:'Jueves',fri:'Viernes',sat:'Sábado',sun:'Domingo'};
            const ORDER = ['mon','tue','wed','thu','fri','sat','sun'];

            function show(el, msg){ el.textContent = msg; el.style.display = 'block'; }
            function hide(el){ el.style.display = 'none'; el.textContent=''; }

            function baseData(){ return {mon:[],tue:[],wed:[],thu:[],fri:[],sat:[],sun:[]}; }

            function parseInitial(){
              try{
                const raw = editor.dataset.initial || hidden.value || '';
                const obj = raw ? JSON.parse(raw) : baseData();
                const out = baseData();
                for (const d of ORDER){
                  const ranges = Array.isArray(obj[d]) ? obj[d] : [];
                  if (ranges.length === 1 && ranges[0] && typeof ranges[0] === 'object'){
                    out[d] = [{start:String(ranges[0].start||''), end:String(ranges[0].end||'')}];
                  } else out[d] = [];
                }
                return out;
              }catch(e){ return baseData(); }
            }

            let data = parseInitial();

            // 24h options cada 30 min
            const TIMES = (function(){
              const arr = [];
              for (let h=0; h<24; h++){
                for (let m=0; m<60; m+=30){
                  arr.push(String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0'));
                }
              }
              return arr;
            })();

            function makeSelect(value){
              const sel = document.createElement('select');
              sel.className = 'mlv-select';
              TIMES.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t; opt.textContent = t;
                if (t === value) opt.selected = true;
                sel.appendChild(opt);
              });
              return sel;
            }

            function toJSON(){ hidden.value = JSON.stringify(data); }

            function openDay(day){ data[day] = [{start:'09:00', end:'18:00'}]; render(); }
            function closeDay(day){ data[day] = []; render(); }

            function copyMonToWeekdays(){
              if (data.mon.length !== 1){
                show(errBox, 'Primero configura el horario del Lunes para poder copiarlo.');
                return;
              }
              const mon = {start:data.mon[0].start, end:data.mon[0].end};
              ['tue','wed','thu','fri'].forEach(d => data[d] = [{start:mon.start, end:mon.end}]);
              render();
            }

            function validate(){
              const any = ORDER.some(d => data[d].length === 1);
              if (!any) return 'Debes indicar al menos un día de atención.';
              for (const d of ORDER){
                if (data[d].length === 0) continue;
                const r = data[d][0];
                if (!/^\d{2}:\d{2}$/.test(r.start) || !/^\d{2}:\d{2}$/.test(r.end)) return `Horario inválido en ${DAY_LABELS[d]}.`;
                if (r.start >= r.end) return `En ${DAY_LABELS[d]} la hora inicio debe ser menor que la hora fin.`;
              }
              return '';
            }

            function renderDay(day){
              const open = data[day].length === 1;

              const wrap = document.createElement('div'); wrap.className='mlv-day';
              const head = document.createElement('div'); head.className='mlv-day-head';

              const left = document.createElement('div');
              left.style.display='flex'; left.style.gap='10px'; left.style.alignItems='center';

              const title = document.createElement('div'); title.className='mlv-day-title'; title.textContent = DAY_LABELS[day];
              const badge = document.createElement('span'); badge.className='mlv-badge'; badge.textContent = open ? 'Abierto' : 'Cerrado';
              left.appendChild(title); left.appendChild(badge);

              const right = document.createElement('div');
              const b = document.createElement('button'); b.type='button'; b.className='mlv-btn';
              b.textContent = open ? 'Marcar como cerrado' : 'Abrir este día';
              b.addEventListener('click', ()=> open ? closeDay(day) : openDay(day));
              right.appendChild(b);

              head.appendChild(left); head.appendChild(right);
              wrap.appendChild(head);

              if (open){
                const row = document.createElement('div'); row.className='mlv-row';
                const lab1 = document.createElement('span'); lab1.className='mlv-label-sm'; lab1.textContent='De';
                const lab2 = document.createElement('span'); lab2.className='mlv-label-sm'; lab2.textContent='a';

                const startSel = makeSelect(data[day][0].start || '09:00');
                const endSel   = makeSelect(data[day][0].end || '18:00');

                startSel.addEventListener('change', e => { data[day][0].start = e.target.value; toJSON(); });
                endSel.addEventListener('change', e => { data[day][0].end = e.target.value; toJSON(); });

                row.appendChild(lab1); row.appendChild(startSel);
                row.appendChild(lab2); row.appendChild(endSel);
                wrap.appendChild(row);
              }

              return wrap;
            }

            function render(){
              hide(errBox);
              daysWrap.innerHTML='';
              ORDER.forEach(d => daysWrap.appendChild(renderDay(d)));
              toJSON();
            }

            document.getElementById('mlv-copy-mon-to-weekdays')?.addEventListener('click', copyMonToWeekdays);

            btn.addEventListener('click', async ()=>{
              hide(ok); hide(err); hide(errBox);

              const msg = validate();
              if (msg){ show(errBox, msg); return; }

              const fd = new FormData();
              fd.append('action','mlv_profile_save');
              fd.append('nonce', root.dataset.nonce);
              fd.append('mlv_telefono', document.getElementById('mlv_telefono').value || '');
              fd.append('mlv_local_nombre', document.getElementById('mlv_local_nombre').value || '');
              fd.append('mlv_local_comuna', document.getElementById('mlv_local_comuna').value || '');
              fd.append('mlv_local_direccion', document.getElementById('mlv_local_direccion').value || '');
              fd.append('mlv_local_hours', hidden.value || '');
              btn.disabled = true;

              try{
                const res = await fetch(root.dataset.ajaxUrl, {method:'POST', body: fd});
                const json = await res.json();

                if (json && json.success){
                  show(ok, json.data?.message || 'Guardado.');
                  window.location.href = json.data?.redirect || '<?php echo esc_js(home_url('/panel/')); ?>';
                } else {
                  show(err, json.data?.message || 'No se pudo guardar.');
                }
              }catch(e){
                show(err, 'Error de red. Intenta nuevamente.');
              }finally{
                btn.disabled = false;
              }
            });

            render();
          })();
          </script>
        </div>
      <?php endif; ?>

    </div>
  </div>
  <?php
  return ob_get_clean();
}

add_shortcode('mlv_profile_form', 'mlv_profile_form_shortcode');
