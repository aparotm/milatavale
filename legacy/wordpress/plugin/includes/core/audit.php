<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Audit {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mlv_audit_log';
    }

    private static function ip(): string {
        $ip = '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = (string)trim($parts[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = (string)$_SERVER['REMOTE_ADDR'];
        }
        $ip = trim((string)$ip);
        return substr($ip, 0, 64);
    }

    private static function role(): string {
        if (!is_user_logged_in()) return '';
        $u = wp_get_current_user();
        return (!empty($u->roles)) ? (string)$u->roles[0] : '';
    }

    private static function enc($v): ?string {
        if ($v === null) return null;
        if (is_string($v)) return $v;
        return wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function add(string $action, string $object_type, int $object_id = 0, $before = null, $after = null): bool {
        global $wpdb;
        $table = self::table();

        $user_id = is_user_logged_in() ? (int)get_current_user_id() : null;

        $data = [
            'user_id' => $user_id,
            'user_role' => self::role(),
            'ip' => self::ip(),
            'action' => $action,
            'object_type' => $object_type,
            'object_id' => max(0, (int)$object_id),
            'before_data' => self::enc($before),
            'after_data' => self::enc($after),
            'created_at' => current_time('mysql'),
        ];

        $formats = [
            ($user_id === null ? '%s' : '%d'),
            '%s','%s','%s','%s','%d','%s','%s','%s'
        ];

        $res = $wpdb->insert($table, $data, $formats);
        return ($res !== false);
    }
}
