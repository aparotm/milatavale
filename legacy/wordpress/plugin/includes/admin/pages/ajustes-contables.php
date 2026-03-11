<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Ajustes {

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

        $msg = '';
        $err = '';
        $bulk_report = [];
        $bulk_stats = null;
        $fill_rut_report = [];
        $fill_rut_stats = null;

        // Corrección masiva legacy desde CSV (botón wp-admin).
        if (!empty($_POST['mlv2_bulk_fix_submit'])) {
            check_admin_referer('mlv2_bulk_fix_csv');

            if (empty($_FILES['mlv2_bulk_csv']['tmp_name']) || !is_uploaded_file($_FILES['mlv2_bulk_csv']['tmp_name'])) {
                $err = 'Debes subir un archivo CSV válido.';
            } else {
                $csv_tmp = (string)$_FILES['mlv2_bulk_csv']['tmp_name'];
                $dry_run = !empty($_POST['bulk_dry_run']);
                $opts = [
                    'apply' => !$dry_run,
                    'motivo' => sanitize_text_field(wp_unslash($_POST['bulk_motivo'] ?? 'Ajuste latas por incentivos')),
                    'incentivo_tipo' => sanitize_text_field(wp_unslash($_POST['bulk_incentivo_tipo'] ?? 'Corrección')),
                    'incentivo_motivo' => sanitize_text_field(wp_unslash($_POST['bulk_incentivo_motivo'] ?? 'Reabono por corrección histórica')),
                    'obs_contains' => sanitize_text_field(wp_unslash($_POST['bulk_obs_contains'] ?? '')),
                ];

                $res = self::run_bulk_fix_from_csv($csv_tmp, $opts);
                $bulk_report = (array)($res['rows'] ?? []);
                $bulk_stats = (array)($res['stats'] ?? []);
                if (!empty($res['ok'])) {
                    $msg = (string)($res['message'] ?? 'Proceso masivo ejecutado.');
                } else {
                    $err = (string)($res['error'] ?? 'Error en corrección masiva.');
                }
            }
        }

        // Relleno masivo de RUT faltante en movimientos.
        if (!empty($_POST['mlv2_fill_rut_submit'])) {
            check_admin_referer('mlv2_fill_rut');

            $dry_run = !empty($_POST['fill_rut_dry_run']);
            $limit = max(0, (int)($_POST['fill_rut_limit'] ?? 0));
            $res = self::run_fill_missing_rut([
                'apply' => !$dry_run,
                'limit' => $limit,
            ]);
            $fill_rut_report = (array)($res['rows'] ?? []);
            $fill_rut_stats = (array)($res['stats'] ?? []);
            if (!empty($res['ok'])) {
                $msg = (string)($res['message'] ?? 'Relleno de RUT ejecutado.');
            } else {
                $err = (string)($res['error'] ?? 'Error en relleno de RUT.');
            }
        }

        if (!empty($_POST['mlv2_ajuste_submit'])) {
            check_admin_referer('mlv2_ajustes_contables');

            $modo = sanitize_text_field(wp_unslash($_POST['modo_ajuste'] ?? 'cliente'));
            $rut = sanitize_text_field(wp_unslash($_POST['cliente_rut'] ?? ''));
            $cliente_id = (int)($_POST['cliente_id'] ?? 0);
            $locales_sel = isset($_POST['locales_seleccion']) && is_array($_POST['locales_seleccion']) ? array_map('sanitize_text_field', wp_unslash($_POST['locales_seleccion'])) : [];
            $tipo = sanitize_text_field(wp_unslash($_POST['tipo_ajuste'] ?? ''));
            $monto = (int)($_POST['monto'] ?? 0);
            $origen_saldo = sanitize_text_field(wp_unslash($_POST['origen_saldo'] ?? 'ajuste'));
            $clasificacion = sanitize_text_field(wp_unslash($_POST['clasificacion'] ?? 'correccion'));
            $motivo = sanitize_text_field(wp_unslash($_POST['motivo'] ?? ''));
            $observacion = sanitize_text_field(wp_unslash($_POST['observacion'] ?? ''));

            $ajustar_latas_equiv = !empty($_POST['ajustar_latas_equiv']);
            $reabono_incentivo_inmediato = !empty($_POST['reabono_incentivo_inmediato']);
            $incentivo_tipo = sanitize_text_field(wp_unslash($_POST['incentivo_tipo'] ?? 'Corrección'));
            $incentivo_motivo = sanitize_text_field(wp_unslash($_POST['incentivo_motivo'] ?? ''));

            $price_per_lata = 0;

            if ($monto <= 0 || $motivo === '') {
                $err = 'Monto y motivo son obligatorios.';
            } else {
                if ($modo === 'cliente') {
                    if ($cliente_id <= 0 && $rut !== '' && class_exists('MLV2_Ledger')) {
                        $cliente_id = (int) MLV2_Ledger::find_cliente_by_rut($rut);
                    }
                    if ($cliente_id <= 0) {
                        $err = 'Cliente no encontrado. Usa RUT o selector.';
                    }
                } else {
                    $locales_sel = array_values(array_filter(array_map('trim', (array)$locales_sel)));
                    if (empty($locales_sel)) {
                        $err = 'Debes seleccionar al menos un local.';
                    }
                }
            }

            if ($err === '') {
                if (!in_array($tipo, ['abonar','descontar'], true)) {
                    $err = 'Tipo de ajuste inválido.';
                }
                if (!in_array($origen_saldo, ['reciclaje','incentivo','ajuste'], true)) {
                    $origen_saldo = 'ajuste';
                }
                if (!in_array($clasificacion, ['correccion','regularizacion_historica'], true)) {
                    $clasificacion = 'correccion';
                }
            }

            if ($err === '' && $ajustar_latas_equiv) {
                $price_per_lata = class_exists('MLV2_Pricing') ? (int)MLV2_Pricing::get_price_per_lata() : 0;
                if ($price_per_lata <= 0) {
                    $err = 'No se puede ajustar latas: el valor por lata debe ser mayor a 0.';
                }
            }

            if ($err === '' && $reabono_incentivo_inmediato && $tipo !== 'descontar') {
                $err = 'El reabono inmediato de incentivo solo aplica cuando el ajuste es de tipo "Descontar saldo".';
            }
            if ($err === '' && $reabono_incentivo_inmediato && $incentivo_motivo === '') {
                $incentivo_motivo = 'Reabono por corrección: ' . $motivo;
            }

            if ($err === '') {
                $signo = ($tipo === 'descontar') ? -1 : 1;
                $monto_calc = $signo * $monto;

                global $wpdb;
                $table = MLV2_DB::table_movimientos();
                $now = current_time('mysql');

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

                // Prepara plan de inserción para validar antes de escribir.
                $plan = [];
                if ($err === '') {
                    foreach ($targets as $t) {
                        $lc = (string)($t['local'] ?? '');
                        $clientes_ids = (array)($t['clientes'] ?? []);
                        $count = count($clientes_ids);
                        if ($count <= 0) continue;

                        $base = intdiv(abs($monto_calc), $count);
                        $rest = abs($monto_calc) - ($base * $count);

                        foreach ($clientes_ids as $idx => $cid) {
                            $monto_cli = $base + ($idx < $rest ? 1 : 0);
                            if ($monto_cli <= 0) continue;
                            $monto_cli = $signo * $monto_cli;

                            $cantidad_latas = 0;
                            if ($ajustar_latas_equiv) {
                                $abs_cli = abs($monto_cli);
                                if (($abs_cli % $price_per_lata) !== 0) {
                                    $err = 'No se puede convertir el monto a latas exactas para el cliente #' . (int)$cid . '. Ajusta monto/locales o desactiva "Ajustar latas equivalentes".';
                                    break 2;
                                }
                                $latas_abs = intdiv($abs_cli, $price_per_lata);
                                $cantidad_latas = ($monto_cli < 0) ? -$latas_abs : $latas_abs;
                            }

                            $detalle = [
                                'tipo' => 'ajuste',
                                'origen' => $origen_saldo,
                                'clasificacion' => $clasificacion,
                                'ajuste' => [
                                    'tipo' => 'manual',
                                    'motivo' => $motivo,
                                    'signo' => ($signo > 0 ? '+' : '-'),
                                    'observacion' => $observacion,
                                    'creado_por' => (int)get_current_user_id(),
                                    'modo' => $modo,
                                    'local' => $lc,
                                    'ajuste_latas_equiv' => $ajustar_latas_equiv ? 1 : 0,
                                    'valor_por_lata' => $ajustar_latas_equiv ? $price_per_lata : 0,
                                ],
                                'hist' => [
                                    [
                                        'ts' => current_time('mysql'),
                                        'actor_user_id' => (int)get_current_user_id(),
                                        'actor_role' => 'administrator',
                                        'accion' => 'ajuste_manual_admin',
                                        'estado' => 'retirado',
                                        'payload' => [
                                            'monto' => abs($monto_cli),
                                            'tipo' => $tipo,
                                            'latas' => $cantidad_latas,
                                        ],
                                    ],
                                ],
                            ];

                            $plan[] = [
                                'cliente_id' => (int)$cid,
                                'local_codigo' => $lc,
                                'monto_calculado' => (int)$monto_cli,
                                'cantidad_latas' => (int)$cantidad_latas,
                                'detalle_json' => wp_json_encode($detalle, JSON_UNESCAPED_UNICODE),
                            ];
                        }
                    }
                }

                if ($err === '' && empty($plan)) {
                    $err = 'No se pudo generar ningún movimiento con los parámetros entregados.';
                }

                if ($err === '') {
                    $total_movs = 0;
                    $total_inc = 0;
                    $touched_clientes = [];
                    $batch_reabono = $reabono_incentivo_inmediato ? ('inc_fix_' . wp_generate_uuid4()) : '';

                    $wpdb->query('START TRANSACTION');

                    foreach ($plan as $row) {
                        $cid = (int)$row['cliente_id'];
                        $lc = (string)$row['local_codigo'];
                        $monto_cli = (int)$row['monto_calculado'];

                        $insert = [
                            'tipo' => ($monto_cli >= 0 ? 'ingreso' : 'gasto'),
                            'cliente_user_id' => $cid,
                            'cliente_rut' => (string) get_user_meta($cid, 'mlv_rut', true),
                            'cliente_telefono' => (string) get_user_meta($cid, 'mlv_telefono', true),
                            'local_codigo' => $lc,
                            'cantidad_latas' => (int)$row['cantidad_latas'],
                            'valor_por_lata' => $ajustar_latas_equiv ? (int)$price_per_lata : 0,
                            'monto_calculado' => $monto_cli,
                            'origen_saldo' => $origen_saldo,
                            'mov_ref_id' => null,
                            'is_system_adjustment' => 1,
                            'clasificacion_mov' => $clasificacion,
                            'estado' => 'retirado',
                            'detalle' => (string)$row['detalle_json'],
                            'created_by_user_id' => (int)get_current_user_id(),
                            'validated_by_user_id' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $ok = $wpdb->insert($table, $insert);
                        if (!$ok) {
                            $err = 'No se pudo guardar el ajuste contable.';
                            break;
                        }
                        $total_movs++;
                        $adjust_id = (int)$wpdb->insert_id;
                        $touched_clientes[$cid] = true;

                        // Reabono inmediato como incentivo para evitar ventana de saldo bajo.
                        if ($reabono_incentivo_inmediato) {
                            $monto_inc = abs($monto_cli);
                            if ($monto_inc <= 0) continue;

                            $detalle_inc = [
                                'tipo' => 'incentivo',
                                'origen' => 'incentivo',
                                'clasificacion' => 'operacion',
                                'incentivo' => [
                                    'monto' => $monto_inc,
                                    'tipo' => ($incentivo_tipo !== '' ? $incentivo_tipo : 'Corrección'),
                                    'motivo' => $incentivo_motivo,
                                    'creado_por' => (int)get_current_user_id(),
                                    'modo' => 'cliente',
                                    'batch_id' => $batch_reabono,
                                    'correccion_inmediata' => 1,
                                    'linked_adjustment_id' => $adjust_id,
                                ],
                                'hist' => [
                                    [
                                        'ts' => current_time('mysql'),
                                        'actor_user_id' => (int)get_current_user_id(),
                                        'actor_role' => 'administrator',
                                        'accion' => 'registrar_incentivo_correccion_inmediata',
                                        'estado' => 'retirado',
                                        'payload' => [
                                            'monto' => $monto_inc,
                                            'linked_adjustment_id' => $adjust_id,
                                        ],
                                    ],
                                ],
                            ];

                            $insert_inc = [
                                'tipo' => 'ingreso',
                                'cliente_user_id' => $cid,
                                'cliente_rut' => (string) get_user_meta($cid, 'mlv_rut', true),
                                'cliente_telefono' => (string) get_user_meta($cid, 'mlv_telefono', true),
                                'local_codigo' => $lc,
                                'cantidad_latas' => 0,
                                'valor_por_lata' => 0,
                                'monto_calculado' => $monto_inc,
                                'origen_saldo' => 'incentivo',
                                'mov_ref_id' => $adjust_id,
                                'is_system_adjustment' => 1,
                                'clasificacion_mov' => 'operacion',
                                'incentivo_batch_id' => $batch_reabono,
                                'estado' => 'retirado',
                                'detalle' => wp_json_encode($detalle_inc, JSON_UNESCAPED_UNICODE),
                                'created_by_user_id' => (int)get_current_user_id(),
                                'validated_by_user_id' => null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];

                            $ok_inc = $wpdb->insert($table, $insert_inc);
                            if (!$ok_inc) {
                                $err = 'No se pudo guardar el incentivo inmediato de corrección.';
                                break;
                            }
                            $total_inc++;
                        }
                    }

                    if ($err !== '') {
                        $wpdb->query('ROLLBACK');
                    } else {
                        $wpdb->query('COMMIT');

                        foreach (array_keys($touched_clientes) as $cid2) {
                            if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
                                MLV2_Ledger::recalc_saldo_cliente((int)$cid2);
                            }
                        }

                        if (class_exists('MLV2_Audit')) {
                            MLV2_Audit::add('movimiento_manual_adjustment_create', 'movimiento', 0, null, [
                                'modo' => $modo,
                                'monto' => $monto_calc,
                                'origen_saldo' => $origen_saldo,
                                'clasificacion' => $clasificacion,
                                'locales' => $locales_sel,
                                'movimientos_ajuste' => $total_movs,
                                'ajuste_latas_equiv' => $ajustar_latas_equiv ? 1 : 0,
                                'valor_por_lata' => $price_per_lata,
                                'reabono_incentivo_inmediato' => $reabono_incentivo_inmediato ? 1 : 0,
                                'movimientos_incentivo' => $total_inc,
                                'batch_reabono' => $batch_reabono,
                            ]);
                        }

                        $msg = $reabono_incentivo_inmediato
                            ? 'Corrección registrada: ajuste contable + incentivo inmediato.'
                            : 'Ajuste contable registrado correctamente.';
                    }
                }
            }
        }

        $clientes = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_clientes_dropdown() : [];

        echo '<div class="wrap">';
        echo '<h1>Ajustes contables</h1>';
        echo '<p class="description">Permite corregir saldos con abonos o descuentos. Puedes aplicarlo a un cliente o repartirlo entre clientes de uno o varios locales.</p>';
        if ($msg) { echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>'; }
        if ($err) { echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>'; }

        echo '<form method="post">';
        wp_nonce_field('mlv2_ajustes_contables');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Modo</th>';
        echo '<td><label><input type="radio" name="modo_ajuste" value="cliente" checked> Cliente</label> &nbsp; '
           . '<label><input type="radio" name="modo_ajuste" value="local"> Repartir por local(es)</label></td></tr>';
        echo '<tr><th scope="row"><label for="cliente_rut">Cliente por RUT</label></th>';
        echo '<td><input type="text" id="cliente_rut" name="cliente_rut" class="regular-text" placeholder="12.345.678-9"></td></tr>';

        echo '<tr><th scope="row"><label for="cliente_id">O seleccionar cliente</label></th>';
        echo '<td><select id="cliente_id" name="cliente_id" class="regular-text">';
        echo '<option value="0">— Seleccionar —</option>';
        foreach ($clientes as $c) {
            echo '<option value="' . esc_attr((string)$c['id']) . '">' . esc_html($c['label']) . '</option>';
        }
        echo '</select></td></tr>';

        $locales = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_locales_disponibles() : [];
        $locales_labels = class_exists('MLV2_Admin_Query') ? MLV2_Admin_Query::get_locales_labels($locales) : [];
        echo '<tr id="mlv2-aj-row-locales"><th scope="row"><label for="locales_seleccion">Locales (reparto)</label></th>';
        echo '<td><select id="locales_seleccion" name="locales_seleccion[]" class="regular-text" multiple size="6">';
        foreach ($locales as $lc) {
            $lbl = $locales_labels[$lc] ?? $lc;
            $count = self::count_clientes_by_local($lc);
            $label = $lbl . ' (' . $count . ' clientes)';
            echo '<option value="' . esc_attr($lc) . '">' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">Selecciona uno o más locales para repartir el ajuste entre sus clientes.</p></td></tr>';

        echo '<tr><th scope="row"><label for="tipo_ajuste">Tipo</label></th>';
        echo '<td><select id="tipo_ajuste" name="tipo_ajuste" class="regular-text">';
        echo '<option value="abonar">Abonar saldo</option>';
        echo '<option value="descontar">Descontar saldo</option>';
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="monto">Monto</label></th>';
        echo '<td><input type="number" id="monto" name="monto" min="1" step="1" class="regular-text" required></td></tr>';

        echo '<tr><th scope="row"><label for="origen_saldo">Origen del saldo</label></th>';
        echo '<td><select id="origen_saldo" name="origen_saldo" class="regular-text">';
        echo '<option value="reciclaje">Reciclaje</option>';
        echo '<option value="incentivo">Incentivo</option>';
        echo '<option value="ajuste" selected>Ajuste</option>';
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="clasificacion">Clasificación</label></th>';
        echo '<td><select id="clasificacion" name="clasificacion" class="regular-text">';
        echo '<option value="correccion">Corrección</option>';
        echo '<option value="regularizacion_historica">Regularización histórica</option>';
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="motivo">Motivo</label></th>';
        echo '<td><input type="text" id="motivo" name="motivo" class="regular-text" required placeholder="Ej: Ajuste latas por incentivos"></td></tr>';

        echo '<tr><th scope="row"><label for="observacion">Observación</label></th>';
        echo '<td><input type="text" id="observacion" name="observacion" class="regular-text"></td></tr>';

        echo '<tr><th scope="row">Opciones de corrección</th><td>';
        echo '<label><input type="checkbox" id="ajustar_latas_equiv" name="ajustar_latas_equiv" value="1"> Ajustar latas equivalentes al monto</label>';
        echo '<p class="description">Convierte monto a latas usando el valor por lata vigente. Requiere equivalencia exacta.</p>';
        echo '<label><input type="checkbox" id="reabono_incentivo_inmediato" name="reabono_incentivo_inmediato" value="1"> Reabonar incentivo inmediatamente (misma operación)</label>';
        echo '<p class="description">Úsalo para corrección legacy: descuenta ajuste y crea incentivo compensatorio al instante.</p>';
        echo '</td></tr>';

        echo '<tr id="mlv2-aj-row-inc-tipo"><th scope="row"><label for="incentivo_tipo">Tipo incentivo (reabono)</label></th>';
        echo '<td><select id="incentivo_tipo" name="incentivo_tipo" class="regular-text">';
        $tipos_inc = ['Corrección','Campaña','Desafío','Premio','Bono reciclaje','Cumplimiento','Alianza','Otro'];
        foreach ($tipos_inc as $ti) {
            echo '<option value="' . esc_attr($ti) . '">' . esc_html($ti) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr id="mlv2-aj-row-inc-motivo"><th scope="row"><label for="incentivo_motivo">Motivo incentivo (reabono)</label></th>';
        echo '<td><input type="text" id="incentivo_motivo" name="incentivo_motivo" class="regular-text" placeholder="Ej: Reabono por corrección histórica"></td></tr>';

        echo '</table>';

        submit_button('Registrar ajuste', 'primary', 'mlv2_ajuste_submit');
        echo '</form>';

        echo '<hr style="margin:22px 0;">';
        echo '<h2>Corrección masiva legacy (CSV)</h2>';
        echo '<p class="description">Sube un CSV exportado desde Movimientos para ejecutar en lote la corrección: descuento (latas+monto) + incentivo compensatorio.</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('mlv2_bulk_fix_csv');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="mlv2_bulk_csv">Archivo CSV</label></th>';
        echo '<td><input type="file" id="mlv2_bulk_csv" name="mlv2_bulk_csv" accept=".csv,text/csv" required>';
        echo '<p class="description">Debe incluir columnas: ID, Ingreso, Cantidad de Latas, Observaciones Almacen.</p></td></tr>';

        echo '<tr><th scope="row"><label for="bulk_obs_contains">Filtrar observación contiene</label></th>';
        echo '<td><input type="text" id="bulk_obs_contains" name="bulk_obs_contains" class="regular-text" placeholder="ej: incentivos pasados"></td></tr>';

        echo '<tr><th scope="row"><label for="bulk_motivo">Motivo ajuste</label></th>';
        echo '<td><input type="text" id="bulk_motivo" name="bulk_motivo" class="regular-text" value="Ajuste latas por incentivos" required></td></tr>';

        echo '<tr><th scope="row"><label for="bulk_incentivo_tipo">Tipo incentivo</label></th>';
        echo '<td><select id="bulk_incentivo_tipo" name="bulk_incentivo_tipo" class="regular-text">';
        $tipos_inc = ['Corrección','Campaña','Desafío','Premio','Bono reciclaje','Cumplimiento','Alianza','Otro'];
        foreach ($tipos_inc as $ti) {
            echo '<option value="' . esc_attr($ti) . '">' . esc_html($ti) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="bulk_incentivo_motivo">Motivo incentivo</label></th>';
        echo '<td><input type="text" id="bulk_incentivo_motivo" name="bulk_incentivo_motivo" class="regular-text" value="Reabono por corrección histórica"></td></tr>';

        echo '<tr><th scope="row">Modo</th><td><label><input type="checkbox" name="bulk_dry_run" value="1" checked> Simular (dry-run, no guarda cambios)</label></td></tr>';
        echo '</table>';
        submit_button('Ejecutar corrección masiva', 'secondary', 'mlv2_bulk_fix_submit');
        echo '</form>';

        if (is_array($bulk_stats) && !empty($bulk_stats)) {
            echo '<h3>Resultado masivo</h3>';
            echo '<p><strong>Filas:</strong> ' . (int)($bulk_stats['rows'] ?? 0)
                . ' | <strong>Candidatas:</strong> ' . (int)($bulk_stats['candidates'] ?? 0)
                . ' | <strong>Procesadas:</strong> ' . (int)($bulk_stats['processed'] ?? 0)
                . ' | <strong>Omitidas:</strong> ' . (int)($bulk_stats['skipped'] ?? 0)
                . ' | <strong>Errores:</strong> ' . (int)($bulk_stats['errors'] ?? 0)
                . ' | <strong>Ajustes:</strong> ' . (int)($bulk_stats['adjustments'] ?? 0)
                . ' | <strong>Incentivos:</strong> ' . (int)($bulk_stats['incentives'] ?? 0)
                . '</p>';
        }

        if (!empty($bulk_report)) {
            echo '<div style="max-height:320px; overflow:auto; border:1px solid #dcdcde; background:#fff;">';
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
            foreach (array_slice($bulk_report, 0, 300) as $r) {
                echo '<tr>';
                echo '<td>' . esc_html((string)($r['id'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)($r['status'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)($r['detail'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<hr style="margin:22px 0;">';
        echo '<h2>Rellenar RUT faltante (sin tocar montos/latas)</h2>';
        echo '<p class="description">Completa <code>cliente_rut</code> en movimientos activos donde viene vacío, usando el RUT del usuario cliente (<code>mlv_rut</code>/<code>mlv_rut_norm</code>).</p>';
        echo '<form method="post">';
        wp_nonce_field('mlv2_fill_rut');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="fill_rut_limit">Límite (opcional)</label></th>';
        echo '<td><input type="number" id="fill_rut_limit" name="fill_rut_limit" min="0" step="1" class="small-text" value="0"> <span class="description">0 = sin límite</span></td></tr>';
        echo '<tr><th scope="row">Modo</th><td><label><input type="checkbox" name="fill_rut_dry_run" value="1" checked> Simular (dry-run, no guarda cambios)</label></td></tr>';
        echo '</table>';
        submit_button('Ejecutar relleno de RUT', 'secondary', 'mlv2_fill_rut_submit');
        echo '</form>';

        if (is_array($fill_rut_stats) && !empty($fill_rut_stats)) {
            echo '<h3>Resultado relleno RUT</h3>';
            echo '<p><strong>Filas evaluadas:</strong> ' . (int)($fill_rut_stats['rows'] ?? 0)
                . ' | <strong>Candidatas:</strong> ' . (int)($fill_rut_stats['candidates'] ?? 0)
                . ' | <strong>Procesadas:</strong> ' . (int)($fill_rut_stats['processed'] ?? 0)
                . ' | <strong>Omitidas:</strong> ' . (int)($fill_rut_stats['skipped'] ?? 0)
                . ' | <strong>Errores:</strong> ' . (int)($fill_rut_stats['errors'] ?? 0)
                . '</p>';
        }

        if (!empty($fill_rut_report)) {
            echo '<div style="max-height:320px; overflow:auto; border:1px solid #dcdcde; background:#fff;">';
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Cliente</th><th>RUT</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
            foreach (array_slice($fill_rut_report, 0, 400) as $r) {
                echo '<tr>';
                echo '<td>' . esc_html((string)($r['id'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)($r['cliente_user_id'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)($r['rut'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)($r['status'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)($r['detail'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        $js = <<<'JS'
(function(){
    function qs(id){ return document.getElementById(id); }
    function setVisible(el, show){ if(!el) return; el.style.display = show ? "table-row" : "none"; }
    function sync(){
        var modo = document.querySelector("input[name='modo_ajuste']:checked");
        var isLocal = modo && modo.value === "local";
        setVisible(qs("mlv2-aj-row-locales"), isLocal);
        setVisible(qs("cliente_rut") ? qs("cliente_rut").closest("tr") : null, !isLocal);
        setVisible(qs("cliente_id") ? qs("cliente_id").closest("tr") : null, !isLocal);

        var withInc = !!(qs("reabono_incentivo_inmediato") && qs("reabono_incentivo_inmediato").checked);
        setVisible(qs("mlv2-aj-row-inc-tipo"), withInc);
        setVisible(qs("mlv2-aj-row-inc-motivo"), withInc);
    }
    document.querySelectorAll("input[name='modo_ajuste']").forEach(function(r){ r.addEventListener("change", sync); });
    if (qs("reabono_incentivo_inmediato")) qs("reabono_incentivo_inmediato").addEventListener("change", sync);
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

    private static function norm_header(string $h): string {
        $h = strtolower(trim($h));
        $h = str_replace(['á','é','í','ó','ú','ñ'], ['a','e','i','o','u','n'], $h);
        return $h;
    }

    private static function to_int($v): int {
        if (is_int($v)) return $v;
        if (is_float($v)) return (int)round($v);
        $s = trim((string)$v);
        $s = str_replace(['.', ' '], '', $s);
        $s = str_replace(',', '.', $s);
        if ($s === '' || $s === '-') return 0;
        if (strpos($s, '.') !== false) {
            return (int) round((float)$s);
        }
        return (int)$s;
    }

    private static function run_bulk_fix_from_csv(string $csv_tmp, array $opts): array {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $apply = !empty($opts['apply']);
        $motivo = trim((string)($opts['motivo'] ?? 'Ajuste latas por incentivos'));
        $inc_tipo = trim((string)($opts['incentivo_tipo'] ?? 'Corrección'));
        $inc_motivo = trim((string)($opts['incentivo_motivo'] ?? ('Reabono por corrección histórica: ' . $motivo)));
        $obs_contains = trim((string)($opts['obs_contains'] ?? ''));
        $actor_id = (int)get_current_user_id();
        $now = current_time('mysql');
        $batch_id = 'inc_fix_' . wp_generate_uuid4();

        $fh = fopen($csv_tmp, 'r');
        if (!$fh) {
            return ['ok' => false, 'error' => 'No se pudo leer el CSV.'];
        }
        $headers = fgetcsv($fh);
        if (!is_array($headers) || empty($headers)) {
            fclose($fh);
            return ['ok' => false, 'error' => 'CSV sin cabecera.'];
        }

        $map = [];
        foreach ($headers as $i => $h) {
            $map[self::norm_header((string)$h)] = $i;
        }
        $need = ['id','ingreso','cantidad de latas'];
        foreach ($need as $k) {
            if (!isset($map[self::norm_header($k)])) {
                fclose($fh);
                return ['ok' => false, 'error' => 'Falta columna requerida: ' . $k];
            }
        }

        $rows_report = [];
        $stats = ['rows'=>0,'candidates'=>0,'processed'=>0,'skipped'=>0,'errors'=>0,'adjustments'=>0,'incentives'=>0];
        $touched_clients = [];
        $fatal = '';

        if ($apply) {
            $wpdb->query('START TRANSACTION');
        }

        while (($row = fgetcsv($fh)) !== false) {
            $stats['rows']++;
            $id = self::to_int($row[$map[self::norm_header('id')]] ?? '');
            $ingreso = self::to_int($row[$map[self::norm_header('ingreso')]] ?? '');
            $latas = self::to_int($row[$map[self::norm_header('cantidad de latas')]] ?? '');
            $obs = '';
            $obs_idx = $map[self::norm_header('observaciones almacen')] ?? null;
            if ($obs_idx !== null) $obs = trim((string)($row[$obs_idx] ?? ''));

            $rep = ['id'=>$id,'status'=>'','detail'=>''];

            if ($id <= 0 || $ingreso <= 0) {
                $stats['skipped']++;
                $rep['status'] = 'skip';
                $rep['detail'] = 'id/ingreso invalido';
                $rows_report[] = $rep;
                continue;
            }
            if ($obs_contains !== '' && stripos($obs, $obs_contains) === false) {
                $stats['skipped']++;
                $rep['status'] = 'skip';
                $rep['detail'] = 'obs no coincide';
                $rows_report[] = $rep;
                continue;
            }
            $stats['candidates']++;

            $orig = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
            if (!$orig) {
                $stats['errors']++;
                $rep['status'] = 'error';
                $rep['detail'] = 'id no existe';
                $rows_report[] = $rep;
                if ($apply) { $fatal = 'id no existe'; break; }
                continue;
            }
            if (!empty($orig['deleted_at'])) {
                $stats['skipped']++;
                $rep['status'] = 'skip';
                $rep['detail'] = 'en papelera';
                $rows_report[] = $rep;
                continue;
            }

            $already = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE mov_ref_id=%d
                   AND clasificacion_mov='correccion'
                   AND is_system_adjustment=1
                   AND deleted_at IS NULL
                 LIMIT 1",
                $id
            ));
            if ($already > 0) {
                $stats['skipped']++;
                $rep['status'] = 'skip';
                $rep['detail'] = 'ya corregido';
                $rows_report[] = $rep;
                continue;
            }

            $cid = (int)($orig['cliente_user_id'] ?? 0);
            if ($cid <= 0) {
                $stats['errors']++;
                $rep['status'] = 'error';
                $rep['detail'] = 'cliente invalido';
                $rows_report[] = $rep;
                if ($apply) { $fatal = 'cliente invalido'; break; }
                continue;
            }

            $valor_por_lata = (int)($orig['valor_por_lata'] ?? 0);
            if ($valor_por_lata <= 0 && $latas > 0 && ($ingreso % $latas) === 0) {
                $valor_por_lata = (int)($ingreso / $latas);
            }
            $adj_latas = 0;
            if ($latas > 0) {
                $adj_latas = 0 - abs($latas);
            } elseif ($valor_por_lata > 0 && ($ingreso % $valor_por_lata) === 0) {
                $adj_latas = 0 - (int)($ingreso / $valor_por_lata);
            }

            if (!$apply) {
                $stats['processed']++;
                $rep['status'] = 'dry-run';
                $rep['detail'] = 'ok (simulado)';
                $rows_report[] = $rep;
                continue;
            }

            $detalle_adj = [
                'tipo' => 'ajuste',
                'origen' => 'ajuste',
                'clasificacion' => 'correccion',
                'ajuste' => [
                    'tipo' => 'legacy_bulk_fix',
                    'motivo' => $motivo,
                    'observacion' => 'Bulk fix desde wp-admin',
                    'legacy_source_mov_id' => $id,
                    'legacy_source_obs' => $obs,
                    'creado_por' => $actor_id,
                ],
            ];

            $ok_adj = $wpdb->insert($table, [
                'tipo' => 'gasto',
                'cliente_user_id' => $cid,
                'cliente_rut' => self::resolve_cliente_rut_for_insert($orig, $cid),
                'cliente_telefono' => (string)($orig['cliente_telefono'] ?? ''),
                'local_codigo' => (string)($orig['local_codigo'] ?? ''),
                'cantidad_latas' => $adj_latas,
                'valor_por_lata' => max(0, $valor_por_lata),
                'monto_calculado' => 0 - abs($ingreso),
                'origen_saldo' => 'ajuste',
                'mov_ref_id' => $id,
                'is_system_adjustment' => 1,
                'clasificacion_mov' => 'correccion',
                'estado' => 'retirado',
                'detalle' => wp_json_encode($detalle_adj, JSON_UNESCAPED_UNICODE),
                'created_by_user_id' => $actor_id,
                'validated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!$ok_adj) {
                $stats['errors']++;
                $rep['status'] = 'error';
                $rep['detail'] = 'fallo ajuste';
                $rows_report[] = $rep;
                $fatal = 'fallo ajuste';
                break;
            }
            $adj_id = (int)$wpdb->insert_id;
            $stats['adjustments']++;

            $detalle_inc = [
                'tipo' => 'incentivo',
                'origen' => 'incentivo',
                'clasificacion' => 'operacion',
                'incentivo' => [
                    'monto' => abs($ingreso),
                    'tipo' => $inc_tipo,
                    'motivo' => $inc_motivo,
                    'modo' => 'cliente',
                    'batch_id' => $batch_id,
                    'linked_adjustment_id' => $adj_id,
                    'legacy_source_mov_id' => $id,
                ],
            ];

            $ok_inc = $wpdb->insert($table, [
                'tipo' => 'ingreso',
                'cliente_user_id' => $cid,
                'cliente_rut' => self::resolve_cliente_rut_for_insert($orig, $cid),
                'cliente_telefono' => (string)($orig['cliente_telefono'] ?? ''),
                'local_codigo' => (string)($orig['local_codigo'] ?? ''),
                'cantidad_latas' => 0,
                'valor_por_lata' => 0,
                'monto_calculado' => abs($ingreso),
                'origen_saldo' => 'incentivo',
                'mov_ref_id' => $adj_id,
                'is_system_adjustment' => 1,
                'clasificacion_mov' => 'operacion',
                'incentivo_batch_id' => $batch_id,
                'estado' => 'retirado',
                'detalle' => wp_json_encode($detalle_inc, JSON_UNESCAPED_UNICODE),
                'created_by_user_id' => $actor_id,
                'validated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (!$ok_inc) {
                $stats['errors']++;
                $rep['status'] = 'error';
                $rep['detail'] = 'fallo incentivo';
                $rows_report[] = $rep;
                $fatal = 'fallo incentivo';
                break;
            }

            $stats['processed']++;
            $stats['incentives']++;
            $touched_clients[$cid] = true;
            $rep['status'] = 'ok';
            $rep['detail'] = 'ajuste+' . $adj_id . ', incentivo+' . (int)$wpdb->insert_id;
            $rows_report[] = $rep;
        }

        fclose($fh);

        if ($apply && $fatal !== '') {
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'error' => 'Error en ejecución masiva: ' . $fatal . '. Se hizo rollback completo.', 'rows' => $rows_report, 'stats' => $stats];
        }

        if ($apply) {
            foreach (array_keys($touched_clients) as $cid) {
                if (class_exists('MLV2_Ledger') && method_exists('MLV2_Ledger','recalc_saldo_cliente')) {
                    MLV2_Ledger::recalc_saldo_cliente((int)$cid);
                }
            }
            $wpdb->query('COMMIT');
        }

        $mode = $apply ? 'aplicado' : 'simulado';
        $message = 'Proceso masivo ' . $mode . ': ' . (int)$stats['processed'] . ' filas procesadas, ' . (int)$stats['skipped'] . ' omitidas, ' . (int)$stats['errors'] . ' errores.';
        return ['ok' => true, 'message' => $message, 'rows' => $rows_report, 'stats' => $stats];
    }

    private static function run_fill_missing_rut(array $opts): array {
        global $wpdb;
        $table = MLV2_DB::table_movimientos();

        $apply = !empty($opts['apply']);
        $limit = max(0, (int)($opts['limit'] ?? 0));
        $now = current_time('mysql');

        $sql = "SELECT id, cliente_user_id, cliente_rut FROM {$table}
                WHERE deleted_at IS NULL
                  AND (cliente_rut IS NULL OR TRIM(cliente_rut) = '')";
        if ($limit > 0) {
            $rows = $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY id ASC LIMIT %d', $limit), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($sql . ' ORDER BY id ASC', ARRAY_A);
        }

        if (!is_array($rows)) {
            return ['ok' => false, 'error' => 'No se pudieron leer movimientos para relleno de RUT.'];
        }

        $rows_report = [];
        $stats = ['rows' => count($rows), 'candidates' => 0, 'processed' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $cid = (int)($row['cliente_user_id'] ?? 0);
            $rep = ['id' => $id, 'cliente_user_id' => $cid, 'rut' => '', 'status' => '', 'detail' => ''];

            if ($id <= 0 || $cid <= 0) {
                $stats['errors']++;
                $rep['status'] = 'error';
                $rep['detail'] = 'id/cliente invalido';
                $rows_report[] = $rep;
                continue;
            }

            $stats['candidates']++;
            $rut = trim((string)get_user_meta($cid, 'mlv_rut', true));
            if ($rut === '') {
                $rut = trim((string)get_user_meta($cid, 'mlv_rut_norm', true));
            }

            if ($rut === '') {
                $stats['errors']++;
                $rep['status'] = 'error';
                $rep['detail'] = 'usuario sin RUT en usermeta';
                $rows_report[] = $rep;
                continue;
            }

            if (class_exists('MLV2_RUT')) {
                if (method_exists('MLV2_RUT', 'normalize')) {
                    $norm = (string)MLV2_RUT::normalize($rut);
                    if ($norm !== '') $rut = $norm;
                }
                if (method_exists('MLV2_RUT', 'format')) {
                    $fmt = (string)MLV2_RUT::format($rut);
                    if ($fmt !== '') $rut = $fmt;
                }
            }

            $rep['rut'] = $rut;

            if (!$apply) {
                $stats['processed']++;
                $rep['status'] = 'dry-run';
                $rep['detail'] = 'ok (simulado)';
                $rows_report[] = $rep;
                continue;
            }

            $ok = $wpdb->update(
                $table,
                ['cliente_rut' => $rut, 'updated_at' => $now],
                ['id' => $id],
                ['%s', '%s'],
                ['%d']
            );

            if ($ok === false) {
                $stats['errors']++;
                $rep['status'] = 'error';
                $rep['detail'] = 'fallo update';
                $rows_report[] = $rep;
                continue;
            }

            $stats['processed']++;
            $rep['status'] = 'ok';
            $rep['detail'] = 'rut actualizado';
            $rows_report[] = $rep;
        }

        if ($apply && class_exists('MLV2_Audit')) {
            MLV2_Audit::add('movimiento_fill_missing_rut', 'movimiento', 0, null, [
                'rows' => (int)$stats['rows'],
                'candidates' => (int)$stats['candidates'],
                'processed' => (int)$stats['processed'],
                'skipped' => (int)$stats['skipped'],
                'errors' => (int)$stats['errors'],
                'limit' => $limit,
            ]);
        }

        $mode = $apply ? 'aplicado' : 'simulado';
        $message = 'Relleno de RUT ' . $mode . ': ' . (int)$stats['processed'] . ' filas procesadas, ' . (int)$stats['errors'] . ' errores.';
        return ['ok' => true, 'message' => $message, 'rows' => $rows_report, 'stats' => $stats];
    }

    private static function resolve_cliente_rut_for_insert(array $orig, int $cliente_id): string {
        $rut = trim((string)($orig['cliente_rut'] ?? ''));
        if ($rut === '' && $cliente_id > 0) {
            $rut = trim((string)get_user_meta($cliente_id, 'mlv_rut', true));
            if ($rut === '') {
                $rut = trim((string)get_user_meta($cliente_id, 'mlv_rut_norm', true));
            }
        }
        if ($rut !== '' && class_exists('MLV2_RUT') && method_exists('MLV2_RUT', 'format')) {
            $rut = (string)MLV2_RUT::format($rut);
        }
        return $rut;
    }
}
