<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Regularizacion {

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

        $msg = '';
        $err = '';

        if (!empty($_POST['mlv2_regularizacion_submit'])) {
            check_admin_referer('mlv2_regularizacion_historica');

            $modo = sanitize_text_field(wp_unslash($_POST['modo_regularizacion'] ?? 'cliente'));
            $rut = sanitize_text_field(wp_unslash($_POST['cliente_rut'] ?? ''));
            $cliente_id = (int)($_POST['cliente_id'] ?? 0);
            $locales_sel = isset($_POST['locales_seleccion']) && is_array($_POST['locales_seleccion']) ? array_map('sanitize_text_field', wp_unslash($_POST['locales_seleccion'])) : [];
            $tipo_reg = sanitize_text_field(wp_unslash($_POST['tipo_regularizacion'] ?? ''));
            $latas = (int)($_POST['cantidad_latas'] ?? 0);
            $valor_por_lata = (int)($_POST['valor_por_lata'] ?? 0);
            $monto_total = (int)($_POST['monto_total'] ?? 0);
            $motivo = sanitize_text_field(wp_unslash($_POST['motivo'] ?? ''));
            $caso_ref = sanitize_text_field(wp_unslash($_POST['caso_referencia'] ?? ''));
            $fecha_ref = sanitize_text_field(wp_unslash($_POST['fecha_referencia'] ?? ''));
            $origen_saldo = sanitize_text_field(wp_unslash($_POST['origen_saldo'] ?? 'reciclaje'));
            $confirm = isset($_POST['confirmo_regularizacion']) ? 1 : 0;

            if ($confirm !== 1) {
                $err = 'Debes confirmar que es una regularización histórica.';
            } elseif ($motivo === '') {
                $err = 'El motivo es obligatorio.';
            } elseif ($modo === 'cliente') {
                if ($cliente_id <= 0 && $rut !== '' && class_exists('MLV2_Ledger')) {
                    $cliente_id = (int) MLV2_Ledger::find_cliente_by_rut($rut);
                }
                if ($cliente_id <= 0 && $err === '') {
                    $err = 'Cliente no encontrado. Usa RUT o selector.';
                }
            } elseif ($modo === 'local') {
                $locales_sel = array_values(array_filter(array_map('trim', (array)$locales_sel)));
                if (empty($locales_sel)) {
                    $err = 'Debes seleccionar al menos un local.';
                }
            }

            if ($err === '') {
                if (!in_array($tipo_reg, ['latas_preexistentes','saldo_preexistente','ajuste_excepcional'], true)) {
                    $err = 'Tipo de regularización inválido.';
                }
            }

            if ($err === '') {
                if ($tipo_reg === 'latas_preexistentes') {
                    if ($latas <= 0) { $err = 'Debes indicar cantidad de latas.'; }
                    if ($valor_por_lata <= 0) { $err = 'Debes indicar valor por lata.'; }
                    $monto_total = $latas * $valor_por_lata;
                    $origen_saldo = 'reciclaje';
                } else {
                    if ($monto_total <= 0) { $err = 'Debes indicar monto total.'; }
                }
            }

            if ($err === '') {
                global $wpdb;
                $table = MLV2_DB::table_movimientos();

                $detalle = [
                    'tipo' => 'regularizacion_historica',
                    'origen' => $origen_saldo,
                    'clasificacion' => 'regularizacion_historica',
                    'regularizacion_historica' => true,
                    'regularizacion' => [
                        'tipo' => $tipo_reg,
                        'motivo' => $motivo,
                        'caso_referencia' => $caso_ref,
                        'fecha_referencia' => $fecha_ref,
                        'creado_por' => (int)get_current_user_id(),
                    ],
                    'hist' => [
                        [
                            'ts' => current_time('mysql'),
                            'actor_user_id' => (int)get_current_user_id(),
                            'actor_role' => 'administrator',
                            'accion' => 'regularizacion_historica_admin',
                            'estado' => 'retirado',
                            'payload' => [
                                'monto' => $monto_total,
                                'tipo' => $tipo_reg,
                            ],
                        ],
                    ],
                ];

                $now = current_time('mysql');
                $total_movs = 0;
                $targets = [];
                if ($modo === 'cliente') {
                    $targets[] = [
                        'local' => self::get_primary_local_for_cliente($cliente_id),
                        'clientes' => [$cliente_id],
                    ];
                } else {
                    foreach ($locales_sel as $lc) {
                        $clientes_ids = self::get_clientes_ids_by_local($lc);
                        if (empty($clientes_ids)) {
                            $err = 'El local ' . esc_html($lc) . ' no tiene clientes asociados.';
                            break;
                        }
                        $targets[] = ['local' => $lc, 'clientes' => $clientes_ids];
                    }
                }

                if ($err === '') {
                    foreach ($targets as $t) {
                        $lc = (string)($t['local'] ?? '');
                        $clientes_ids = (array)($t['clientes'] ?? []);
                        $count = count($clientes_ids);
                        if ($count <= 0) { continue; }

                        $dist_monto = [];
                        $dist_latas = [];
                        if ($tipo_reg === 'latas_preexistentes') {
                            $base_l = intdiv($latas, $count);
                            $rest_l = $latas - ($base_l * $count);
                            foreach ($clientes_ids as $idx => $cid) {
                                $latas_cli = $base_l + ($idx < $rest_l ? 1 : 0);
                                $dist_latas[$cid] = $latas_cli;
                                $dist_monto[$cid] = $latas_cli * $valor_por_lata;
                            }
                        } else {
                            $base = intdiv($monto_total, $count);
                            $rest = $monto_total - ($base * $count);
                            foreach ($clientes_ids as $idx => $cid) {
                                $dist_monto[$cid] = $base + ($idx < $rest ? 1 : 0);
                                $dist_latas[$cid] = 0;
                            }
                        }

                        foreach ($clientes_ids as $cid) {
                            $monto_cli = (int)($dist_monto[$cid] ?? 0);
                            if ($monto_cli <= 0 && $tipo_reg !== 'latas_preexistentes') { continue; }

                            $detalle['regularizacion']['modo'] = $modo;
                            $detalle['regularizacion']['local'] = $lc;

                            $insert = [
                                'tipo' => 'ingreso',
                                'cliente_user_id' => $cid,
                                'cliente_rut' => (string) get_user_meta($cid, 'mlv_rut', true),
                                'cliente_telefono' => (string) get_user_meta($cid, 'mlv_telefono', true),
                                'local_codigo' => $lc,
                                'cantidad_latas' => ($tipo_reg === 'latas_preexistentes' ? (int)($dist_latas[$cid] ?? 0) : 0),
                                'valor_por_lata' => ($tipo_reg === 'latas_preexistentes' ? $valor_por_lata : 0),
                                'monto_calculado' => ($tipo_reg === 'latas_preexistentes' ? (int)($dist_latas[$cid] ?? 0) * $valor_por_lata : $monto_cli),
                                'origen_saldo' => $origen_saldo,
                                'mov_ref_id' => null,
                                'is_system_adjustment' => 1,
                                'clasificacion_mov' => 'regularizacion_historica',
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
                            }
                        }
                    }
                }

                if ($err === '' && $total_movs <= 0) {
                    $err = 'No se pudo guardar la regularización.';
                }

                if ($err === '') {
                    if (class_exists('MLV2_Audit')) {
                        MLV2_Audit::add('movimiento_regularizacion_historica_create', 'movimiento', 0, null, [
                            'modo' => $modo,
                            'monto' => $monto_total,
                            'tipo_regularizacion' => $tipo_reg,
                            'locales' => $locales_sel,
                            'movimientos' => $total_movs,
                        ]);
                    }
                    wp_safe_redirect(add_query_arg(['page'=>'mlv2_movimientos','mlv_msg'=>'regularizacion_ok'], admin_url('admin.php')));
                    exit;
                }
            }
        }

        $clientes = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_clientes_dropdown() : [];
        $locales = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_locales_disponibles() : [];
        $locales_labels = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_locales_labels($locales) : [];
        $price = class_exists('MLV2_Pricing') ? MLV2_Pricing::get_price_per_lata() : 0;

        echo '<div class="wrap">';
        echo '<h1>Regularización histórica</h1>';
        echo '<p class="description">Permite cargar saldos o latas de periodos anteriores sin afectar la operación diaria. No genera retiro físico.</p>';
        if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
        if ($err) { echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>'; }

        echo '<form method="post">';
        wp_nonce_field('mlv2_regularizacion_historica');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Modo</th>';
        echo '<td><label><input type="radio" name="modo_regularizacion" value="cliente" checked> Cliente</label> &nbsp; '
           . '<label><input type="radio" name="modo_regularizacion" value="local"> Repartir por local(es)</label></td></tr>';
        echo '<tr><th scope="row"><label for="cliente_rut">Cliente por RUT</label></th>';
        echo '<td><input type="text" id="cliente_rut" name="cliente_rut" class="regular-text" placeholder="12.345.678-9"></td></tr>';

        echo '<tr><th scope="row"><label for="cliente_id">O seleccionar cliente</label></th>';
        echo '<td><select id="cliente_id" name="cliente_id" class="regular-text">';
        echo '<option value="0">— Seleccionar —</option>';
        foreach ($clientes as $c) {
            echo '<option value="' . esc_attr((string)$c['id']) . '">' . esc_html($c['label']) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr id="mlv2-reg-row-locales"><th scope="row"><label for="locales_seleccion">Locales (reparto)</label></th>';
        echo '<td><select id="locales_seleccion" name="locales_seleccion[]" class="regular-text" multiple size="6">';
        foreach ($locales as $lc) {
            $lbl = $locales_labels[$lc] ?? $lc;
            $count = self::count_clientes_by_local($lc);
            $label = $lbl . ' (' . $count . ' clientes)';
            echo '<option value="' . esc_attr($lc) . '">' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">Selecciona uno o más locales para repartir la regularización entre sus clientes.</p></td></tr>';

        echo '<tr><th scope="row"><label for="tipo_regularizacion">Tipo de regularización</label></th>';
        echo '<td><select id="tipo_regularizacion" name="tipo_regularizacion" class="regular-text">';
        echo '<option value="latas_preexistentes">Latas preexistentes</option>';
        echo '<option value="saldo_preexistente">Saldo preexistente</option>';
        echo '<option value="ajuste_excepcional">Ajuste excepcional</option>';
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="cantidad_latas">Cantidad de latas</label></th>';
        echo '<td><input type="number" id="cantidad_latas" name="cantidad_latas" min="0" step="1" class="regular-text"></td></tr>';

        echo '<tr><th scope="row"><label for="valor_por_lata">Valor por lata</label></th>';
        echo '<td><input type="number" id="valor_por_lata" name="valor_por_lata" min="0" step="1" class="regular-text" value="' . esc_attr((string)$price) . '"></td></tr>';

        echo '<tr><th scope="row"><label for="monto_total">Monto total</label></th>';
        echo '<td><input type="number" id="monto_total" name="monto_total" min="0" step="1" class="regular-text"></td></tr>';

        echo '<tr><th scope="row"><label for="origen_saldo">Origen saldo</label></th>';
        echo '<td><select id="origen_saldo" name="origen_saldo" class="regular-text">';
        echo '<option value="reciclaje">Reciclaje</option>';
        echo '<option value="incentivo">Incentivo</option>';
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="motivo">Motivo</label></th>';
        echo '<td><input type="text" id="motivo" name="motivo" class="regular-text" required></td></tr>';

        echo '<tr><th scope="row"><label for="caso_referencia">Caso/Referencia</label></th>';
        echo '<td><input type="text" id="caso_referencia" name="caso_referencia" class="regular-text" placeholder="Hugo, Jonathan, etc."></td></tr>';

        echo '<tr><th scope="row"><label for="fecha_referencia">Fecha de referencia</label></th>';
        echo '<td><input type="date" id="fecha_referencia" name="fecha_referencia" class="regular-text"></td></tr>';

        echo '<tr><th scope="row">Confirmación</th>';
        echo '<td><label><input type="checkbox" name="confirmo_regularizacion" value="1" required> Confirmo que esta carga es una regularización histórica y no una operación normal</label></td></tr>';

        echo '</table>';

        submit_button('Registrar regularización', 'primary', 'mlv2_regularizacion_submit');
        echo '</form>';
        $js = <<<'JS'
(function(){
    function qs(id){ return document.getElementById(id); }
    function setVisible(el, show){ if(!el) return; el.style.display = show ? "table-row" : "none"; }
    function sync(){
        var modo = document.querySelector("input[name='modo_regularizacion']:checked");
        var isLocal = modo && modo.value === "local";
        setVisible(qs("mlv2-reg-row-locales"), isLocal);
        setVisible(qs("cliente_rut") ? qs("cliente_rut").closest("tr") : null, !isLocal);
        setVisible(qs("cliente_id") ? qs("cliente_id").closest("tr") : null, !isLocal);
    }
    document.querySelectorAll("input[name='modo_regularizacion']").forEach(function(r){ r.addEventListener("change", sync); });
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
