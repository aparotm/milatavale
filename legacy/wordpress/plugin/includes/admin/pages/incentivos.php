<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Incentivos {

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

        $msg = '';
        $err = '';

        if (!empty($_POST['mlv2_incentivo_submit'])) {
            check_admin_referer('mlv2_registrar_incentivo');

            $modo = sanitize_text_field(wp_unslash($_POST['modo_incentivo'] ?? 'cliente'));
            $rut = sanitize_text_field(wp_unslash($_POST['cliente_rut'] ?? ''));
            $cliente_id = (int)($_POST['cliente_id'] ?? 0);
            $monto = (int)($_POST['monto'] ?? 0);
            $tipo = sanitize_text_field(wp_unslash($_POST['tipo'] ?? ''));
            $motivo = sanitize_text_field(wp_unslash($_POST['motivo'] ?? ''));
            $locales_sel = isset($_POST['locales_seleccion']) && is_array($_POST['locales_seleccion']) ? array_map('sanitize_text_field', wp_unslash($_POST['locales_seleccion'])) : [];

            if ($monto <= 0 || $motivo === '') {
                $err = 'Monto y motivo son obligatorios.';
            }

            if ($err === '' && $modo === 'cliente') {
                if ($cliente_id <= 0 && $rut !== '' && class_exists('MLV2_Ledger')) {
                    $cliente_id = (int) MLV2_Ledger::find_cliente_by_rut($rut);
                }
                if ($cliente_id <= 0) {
                    $err = 'Cliente no encontrado. Usa RUT o selector.';
                }
            }

            if ($err === '' && $modo === 'local') {
                $locales_sel = array_values(array_filter(array_map('trim', (array)$locales_sel)));
                if (empty($locales_sel)) {
                    $err = 'Debes seleccionar al menos un local.';
                }
            }

            if ($err === '') {
                global $wpdb;
                $table = MLV2_DB::table_movimientos();

                $now = current_time('mysql');

                if ($modo === 'cliente') {
                    $local_cliente = self::get_primary_local_for_cliente($cliente_id);
                    $detalle = [
                        'tipo' => 'incentivo',
                        'origen' => 'incentivo',
                        'clasificacion' => 'operacion',
                        'incentivo' => [
                            'monto' => $monto,
                            'tipo' => $tipo,
                            'motivo' => $motivo,
                            'creado_por' => (int)get_current_user_id(),
                        ],
                        'hist' => [
                            [
                                'ts' => current_time('mysql'),
                                'actor_user_id' => (int)get_current_user_id(),
                                'actor_role' => 'administrator',
                                'accion' => 'registrar_incentivo_admin',
                                'estado' => 'retirado',
                                'payload' => [
                                    'monto' => $monto,
                                    'tipo' => $tipo,
                                    'motivo' => $motivo,
                                ],
                            ],
                        ],
                    ];

                    $insert = [
                        'tipo' => 'ingreso',
                        'cliente_user_id' => $cliente_id,
                        'cliente_rut' => (string) get_user_meta($cliente_id, 'mlv_rut', true),
                        'cliente_telefono' => (string) get_user_meta($cliente_id, 'mlv_telefono', true),
                        'local_codigo' => $local_cliente,
                        'cantidad_latas' => 0,
                        'valor_por_lata' => 0,
                        'monto_calculado' => $monto,
                        'origen_saldo' => 'incentivo',
                        'mov_ref_id' => null,
                        'is_system_adjustment' => 0,
                        'clasificacion_mov' => 'operacion',
                        'estado' => 'retirado',
                        'detalle' => wp_json_encode($detalle, JSON_UNESCAPED_UNICODE),
                        'created_by_user_id' => (int)get_current_user_id(),
                        'validated_by_user_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $ok = $wpdb->insert($table, $insert);
                    if (!$ok) {
                        $err = 'No se pudo guardar el incentivo.';
                    } else {
                        if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
                            MLV2_Ledger::recalc_saldo_cliente($cliente_id);
                        }
                        if (class_exists('MLV2_Audit')) {
                            MLV2_Audit::add('movimiento_incentivo_create', 'movimiento', (int)$wpdb->insert_id, null, [
                                'cliente_id' => $cliente_id,
                                'monto' => $monto,
                                'modo' => 'cliente',
                            ]);
                        }
                        if (class_exists('MLV2_Alerts')) {
                            $msg_cliente = 'Se registró un incentivo de $' . number_format((int)$monto, 0, ',', '.') . '.';
                            MLV2_Alerts::add($cliente_id, 'info', $msg_cliente, 'movimiento', (int)$wpdb->insert_id);
                        }
                        $msg = 'Incentivo registrado correctamente.';
                    }
                } else {
                    $total_movs = 0;
                    $batch_id = 'inc_' . wp_generate_uuid4();
                    foreach ($locales_sel as $lc) {
                        $clientes_ids = self::get_clientes_ids_by_local($lc);
                        if (empty($clientes_ids)) {
                            $err = 'El local ' . esc_html($lc) . ' no tiene clientes asociados.';
                            break;
                        }

                        $count = count($clientes_ids);
                        $base = intdiv($monto, $count);
                        $resto = $monto - ($base * $count);

                        foreach ($clientes_ids as $idx => $cid) {
                            $monto_cli = $base + ($idx < $resto ? 1 : 0);
                            if ($monto_cli <= 0) continue;

                            $detalle = [
                                'tipo' => 'incentivo',
                                'origen' => 'incentivo',
                                'clasificacion' => 'operacion',
                                'incentivo' => [
                                    'monto' => $monto_cli,
                                    'tipo' => $tipo,
                                    'motivo' => $motivo,
                                    'creado_por' => (int)get_current_user_id(),
                                    'modo' => 'local',
                                    'pozo_total' => $monto,
                                    'clientes_total' => $count,
                                    'batch_id' => $batch_id,
                                ],
                                'hist' => [
                                    [
                                        'ts' => current_time('mysql'),
                                        'actor_user_id' => (int)get_current_user_id(),
                                        'actor_role' => 'administrator',
                                        'accion' => 'registrar_incentivo_admin',
                                        'estado' => 'retirado',
                                        'payload' => [
                                            'monto' => $monto_cli,
                                            'tipo' => $tipo,
                                            'motivo' => $motivo,
                                            'local' => $lc,
                                        ],
                                    ],
                                ],
                            ];

                            $insert = [
                                'tipo' => 'ingreso',
                                'cliente_user_id' => $cid,
                                'cliente_rut' => (string) get_user_meta($cid, 'mlv_rut', true),
                                'cliente_telefono' => (string) get_user_meta($cid, 'mlv_telefono', true),
                                'local_codigo' => $lc,
                                'cantidad_latas' => 0,
                                'valor_por_lata' => 0,
                                'monto_calculado' => $monto_cli,
                                'origen_saldo' => 'incentivo',
                                'mov_ref_id' => null,
                                'is_system_adjustment' => 0,
                                'clasificacion_mov' => 'operacion',
                                'incentivo_batch_id' => $batch_id,
                                'estado' => 'retirado',
                                'detalle' => wp_json_encode($detalle, JSON_UNESCAPED_UNICODE),
                                'created_by_user_id' => (int)get_current_user_id(),
                                'validated_by_user_id' => null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];

                            $ok = $wpdb->insert($table, $insert);
                            if ($ok) {
                                $total_movs++;
                                if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
                                    MLV2_Ledger::recalc_saldo_cliente($cid);
                                }
                                if (class_exists('MLV2_Alerts')) {
                                    $msg_cliente = 'Se registró un incentivo de $' . number_format((int)$monto_cli, 0, ',', '.') . '.';
                                    MLV2_Alerts::add($cid, 'info', $msg_cliente, 'movimiento', (int)$wpdb->insert_id);
                                }
                            }
                        }
                    }

                    if ($err === '') {
                        if (class_exists('MLV2_Audit')) {
                            MLV2_Audit::add('movimiento_incentivo_create', 'movimiento', 0, null, [
                                'modo' => 'local',
                                'locales' => $locales_sel,
                                'pozo_total' => $monto,
                                'batch_id' => $batch_id,
                                'movimientos' => $total_movs,
                            ]);
                        }
                        $msg = 'Incentivo por local registrado correctamente.';
                    }
                }
            }
        }

        $clientes = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_clientes_dropdown() : [];
        $locales = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_locales_disponibles() : [];
        $locales_labels = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_locales_labels($locales) : [];

        echo '<div class="wrap">';
        echo '<h1>Incentivos</h1>';
        echo '<p class="description">Los incentivos suman saldo sin retiro físico. Puedes asignar a un cliente específico o repartir un pozo entre clientes de uno o varios locales.</p>';
        if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
        if ($err) { echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>'; }

        echo '<form method="post">';
        wp_nonce_field('mlv2_registrar_incentivo');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Modo</th>';
        echo '<td><label><input type="radio" name="modo_incentivo" value="cliente" checked> Asignar a cliente</label> &nbsp; '
           . '<label><input type="radio" name="modo_incentivo" value="local"> Repartir por local(es)</label></td></tr>';

        echo '<tr id="mlv2-inc-row-cliente-rut"><th scope="row"><label for="cliente_rut">Cliente por RUT</label></th>';
        echo '<td><input type="text" id="cliente_rut" name="cliente_rut" class="regular-text" placeholder="12.345.678-9"></td></tr>';

        echo '<tr id="mlv2-inc-row-cliente-id"><th scope="row"><label for="cliente_id">O seleccionar cliente</label></th>';
        echo '<td><select id="cliente_id" name="cliente_id" class="regular-text">';
        echo '<option value="0">— Seleccionar —</option>';
        foreach ($clientes as $c) {
            echo '<option value="' . esc_attr((string)$c['id']) . '">' . esc_html($c['label']) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="monto">Monto incentivo</label></th>';
        echo '<td><input type="number" id="monto" name="monto" min="1" step="1" class="regular-text" required></td></tr>';

        echo '<tr><th scope="row"><label for="tipo">Tipo/Categoría</label></th>';
        echo '<td><select id="tipo" name="tipo" class="regular-text">';
        $tipos = ['Campaña','Desafío','Premio','Bono reciclaje','Cumplimiento','Alianza','Otro'];
        foreach ($tipos as $t) {
            echo '<option value="' . esc_attr($t) . '">' . esc_html($t) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="motivo">Motivo</label></th>';
        echo '<td><input type="text" id="motivo" name="motivo" class="regular-text" required></td></tr>';

        echo '<tr id="mlv2-inc-row-locales"><th scope="row"><label for="locales_seleccion">Locales (reparto)</label></th>';
        echo '<td><select id="locales_seleccion" name="locales_seleccion[]" class="regular-text" multiple size="6">';
        foreach ($locales as $lc) {
            $lbl = $locales_labels[$lc] ?? $lc;
            $count = self::count_clientes_by_local($lc);
            $label = $lbl . ' (' . $count . ' clientes)';
            echo '<option value="' . esc_attr($lc) . '">' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">Selecciona uno o más locales para repartir el incentivo entre sus clientes.</p></td></tr>';

        echo '</table>';

        submit_button('Registrar incentivo', 'primary', 'mlv2_incentivo_submit');
        echo '</form>';
        $js = <<<'JS'
(function(){
    function qs(id){ return document.getElementById(id); }
    function setVisible(el, show){ if(!el) return; el.style.display = show ? "table-row" : "none"; }
    function sync(){
        var modo = document.querySelector("input[name='modo_incentivo']:checked");
        var isLocal = modo && modo.value === "local";
        setVisible(qs("mlv2-inc-row-cliente-rut"), !isLocal);
        setVisible(qs("mlv2-inc-row-cliente-id"), !isLocal);
        setVisible(qs("mlv2-inc-row-locales"), isLocal);
    }
    document.querySelectorAll("input[name='modo_incentivo']").forEach(function(r){ r.addEventListener("change", sync); });
    sync();
})();
JS;
        echo '<script>' . $js . '</script>';
        echo '</div>';
    }

    private static function get_clientes_ids_by_local(string $local_codigo): array {
        $local_codigo = trim($local_codigo);
        if ($local_codigo === '') return [];

        $ids = [];
        if (class_exists('MLV2_DB')) {
            global $wpdb;
            $table = MLV2_DB::table_clientes_almacenes();
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT cliente_user_id FROM {$table} WHERE local_codigo=%s",
                $local_codigo
            ));
        }

        $q = new WP_User_Query([
            'role' => 'um_cliente',
            'fields' => 'ID',
            'number' => 5000,
            'meta_query' => [
                [
                    'key' => 'mlv_local_codigo',
                    'value' => $local_codigo,
                    'compare' => '=',
                ],
            ],
        ]);
        $legacy = $q->get_results();
        $merged = array_merge((array)$ids, (array)$legacy);
        $merged = array_values(array_unique(array_map('intval', $merged)));

        return $merged;
    }

    private static function count_clientes_by_local(string $local_codigo): int {
        $ids = self::get_clientes_ids_by_local($local_codigo);
        return count($ids);
    }

    private static function get_primary_local_for_cliente(int $cliente_id): string {
        $cliente_id = (int)$cliente_id;
        if ($cliente_id <= 0) return '';

        $locales = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_locales_for_user($cliente_id) : [];
        if (!empty($locales)) {
            $locales = array_values(array_filter(array_map('trim', (array)$locales)));
            if (!empty($locales)) return (string)$locales[0];
        }
        $legacy = trim((string) get_user_meta($cliente_id, 'mlv_local_codigo', true));
        return $legacy;
    }
}
