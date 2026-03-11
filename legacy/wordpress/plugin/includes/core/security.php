<?php
if (!defined('ABSPATH')) { exit; }

final class MLV2_Security {

    public static function current_user_has_role(string $role): bool {
        $user = wp_get_current_user();
        if (!$user || empty($user->ID)) { return false; }
        return in_array($role, (array) $user->roles, true);
    }

    public static function require_role(array $roles): void {
        $user = wp_get_current_user();
        if (!$user || empty($user->ID)) { wp_die('No autenticado.'); }
        foreach ($roles as $r) {
            if (in_array($r, (array)$user->roles, true)) { return; }
        }
        wp_die('No autorizado.');
    }

    public static function verify_post_nonce(string $action, string $field = '_wpnonce'): void {
        $nonce = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die('Nonce inválido.');
        }
    }
}
