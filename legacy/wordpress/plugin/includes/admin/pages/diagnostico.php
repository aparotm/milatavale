<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Admin_Diagnostico {

    public static function handle_repair(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
        check_admin_referer('mlv2_repair_now');

        if (class_exists('MLV2_DB') && method_exists('MLV2_DB', 'maybe_install')) {
            MLV2_DB::maybe_install();
        }
        if (class_exists('MLV2_Health') && method_exists('MLV2_Health', 'clear_cache')) {
            MLV2_Health::clear_cache();
        } else {
            delete_transient('mlv2_health_critical_v1');
        }

        $status = 'repair_ok';
        if (class_exists('MLV2_Health')) {
            $report = MLV2_Health::run_checks();
            if (MLV2_Health::has_critical_issues($report)) {
                $status = 'repair_warn';
            }
        }

        wp_safe_redirect(add_query_arg(['page' => 'mlv2_diagnostico', 'mlv2_res' => $status], admin_url('admin.php')));
        exit;
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
        $res = isset($_GET['mlv2_res']) ? sanitize_text_field(wp_unslash($_GET['mlv2_res'])) : '';

        $report = class_exists('MLV2_Health') ? MLV2_Health::run_checks() : [
            'generated_at' => current_time('mysql'),
            'ok' => 0,
            'warn' => 1,
            'checks' => [[
                'label' => 'Sistema de health checks',
                'status' => 'warn',
                'detail' => 'Clase MLV2_Health no disponible',
            ]],
        ];

        echo '<div class="wrap">';
        echo '<h1>Diagnostico del sistema</h1>';
        echo '<p class="description">Chequeos anti-regresion para estructura de BD y consistencia minima de datos.</p>';
        if ($res === 'repair_ok') {
            echo '<div class="notice notice-success"><p>Reparacion ejecutada. No se detectan fallas estructurales criticas.</p></div>';
        } elseif ($res === 'repair_warn') {
            echo '<div class="notice notice-warning"><p>Reparacion ejecutada, pero persisten observaciones criticas. Revisa el detalle de checks.</p></div>';
        }

        echo '<div style="display:flex; gap:8px; align-items:center; margin:10px 0 14px 0; flex-wrap:wrap;">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">';
        echo '<input type="hidden" name="action" value="mlv2_repair_now">';
        wp_nonce_field('mlv2_repair_now');
        submit_button('Reparar ahora', 'secondary', '', false, ['onclick' => "return confirm('Esto ejecutara migraciones/indices y limpiara cache de diagnostico. Continuar?');"]);
        echo '</form>';

        $csv_url = wp_nonce_url(admin_url('admin-post.php?action=mlv2_export_diagnostico_csv'), 'mlv2_export_diagnostico_csv');
        $json_url = wp_nonce_url(admin_url('admin-post.php?action=mlv2_export_diagnostico_json'), 'mlv2_export_diagnostico_json');
        echo '<a class="button" href="' . esc_url($csv_url) . '">Exportar CSV</a>';
        echo '<a class="button" href="' . esc_url($json_url) . '">Exportar JSON</a>';
        echo '</div>';

        echo '<div style="display:flex; gap:12px; margin:12px 0; flex-wrap:wrap;">';
        echo '<div style="background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:12px 14px; min-width:160px;">';
        echo '<div style="font-size:12px; color:#646970; margin-bottom:6px;">Estado</div>';
        echo '<div style="font-size:22px; font-weight:700;">' . (($report['warn'] ?? 0) > 0 ? 'Con observaciones' : 'OK') . '</div>';
        echo '</div>';
        echo '<div style="background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:12px 14px; min-width:160px;">';
        echo '<div style="font-size:12px; color:#646970; margin-bottom:6px;">Checks OK</div>';
        echo '<div style="font-size:22px; font-weight:700;">' . (int)($report['ok'] ?? 0) . '</div>';
        echo '</div>';
        echo '<div style="background:#fff; border:1px solid #dcdcde; border-radius:10px; padding:12px 14px; min-width:160px;">';
        echo '<div style="font-size:12px; color:#646970; margin-bottom:6px;">Warnings</div>';
        echo '<div style="font-size:22px; font-weight:700;">' . (int)($report['warn'] ?? 0) . '</div>';
        echo '</div>';
        echo '</div>';

        echo '<p><strong>Generado:</strong> ' . esc_html((string)($report['generated_at'] ?? '')) . '</p>';

        echo '<table class="widefat striped" style="margin-top:8px;">';
        echo '<thead><tr><th>Check</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
        foreach ((array)($report['checks'] ?? []) as $c) {
            $status = (string)($c['status'] ?? 'warn');
            $badge = ($status === 'ok') ? 'OK' : 'WARN';
            echo '<tr>';
            echo '<td>' . esc_html((string)($c['label'] ?? '')) . '</td>';
            echo '<td><strong>' . esc_html($badge) . '</strong></td>';
            echo '<td>' . esc_html((string)($c['detail'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '</div>';
    }

    public static function export_csv(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
        check_admin_referer('mlv2_export_diagnostico_csv');

        $report = class_exists('MLV2_Health') ? MLV2_Health::run_checks() : ['generated_at' => current_time('mysql'), 'checks' => []];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=mlv2_diagnostico_' . gmdate('Ymd_His') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['generated_at', (string)($report['generated_at'] ?? '')]);
        fputcsv($out, []);
        fputcsv($out, ['check', 'status', 'detail']);
        foreach ((array)($report['checks'] ?? []) as $c) {
            fputcsv($out, [
                (string)($c['label'] ?? ''),
                (string)($c['status'] ?? ''),
                (string)($c['detail'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }

    public static function export_json(): void {
        if (!current_user_can('manage_options')) { wp_die('No autorizado'); }
        check_admin_referer('mlv2_export_diagnostico_json');

        $report = class_exists('MLV2_Health') ? MLV2_Health::run_checks() : ['generated_at' => current_time('mysql'), 'checks' => []];

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=mlv2_diagnostico_' . gmdate('Ymd_His') . '.json');
        echo wp_json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
