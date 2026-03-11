<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Alerts {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mlv_alerts';
    }

    public static function add(int $user_id, string $type, string $message, string $ref_type = '', int $ref_id = 0): void {
        global $wpdb;
        if ($user_id <= 0) return;

        $type = sanitize_key($type);
        if (!in_array($type, ['ok','info','warn','error'], true)) $type = 'info';

        $wpdb->insert(self::table(), [
            'user_id'    => $user_id,
            'type'       => $type,
            'message'    => wp_kses_post($message),
            'ref_type'   => sanitize_key($ref_type),
            'ref_id'     => (int)$ref_id,
            'is_dismissed' => 0,
            'created_at' => current_time('mysql'),
        ], ['%d','%s','%s','%s','%d','%d','%s']);
    }

    /** @return array<int,array<string,mixed>> */
    public static function get_for_user(int $user_id, int $limit = 4): array {
        global $wpdb;
        $limit = max(1, min(20, $limit));
        $table = self::table();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,type,message,created_at FROM $table WHERE user_id=%d AND is_dismissed=0 ORDER BY id DESC LIMIT %d",
                $user_id,
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function dismiss(int $alert_id, int $user_id): bool {
        global $wpdb;
        if ($alert_id <= 0 || $user_id <= 0) return false;
        $table = self::table();
        $updated = $wpdb->update($table, [
            'is_dismissed' => 1,
            'dismissed_at' => current_time('mysql'),
        ], [
            'id' => $alert_id,
            'user_id' => $user_id,
        ], ['%d','%s'], ['%d','%d']);
        return $updated !== false && $updated > 0;
    }

    /**
     * Dismiss all alerts for a user linked to a reference.
     * Useful to "resolver" una alerta cuando el movimiento cambia de estado.
     */
    public static function dismiss_by_ref(int $user_id, string $ref_type, int $ref_id): bool {
        global $wpdb;
        $table = self::table();

        $ref_type = sanitize_text_field($ref_type);
        if ($user_id <= 0 || $ref_id <= 0 || $ref_type === '') return false;

        $updated = $wpdb->update(
            $table,
            [
                'is_dismissed' => 1,
                'dismissed_at' => current_time('mysql'),
            ],
            [
                'user_id' => $user_id,
                'ref_type' => $ref_type,
                'ref_id' => $ref_id,
                'is_dismissed' => 0,
            ],
            ['%d','%s'],
            ['%d','%s','%d','%d']
        );

        return ($updated !== false);
    }

}
