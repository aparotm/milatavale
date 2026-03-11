<?php
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/traits/trait-mlv2-front-ui-shared.php';
require_once __DIR__ . '/traits/trait-mlv2-front-ui-core.php';
require_once __DIR__ . '/traits/trait-mlv2-front-ui-registro.php';
require_once __DIR__ . '/traits/trait-mlv2-front-ui-gasto.php';
require_once __DIR__ . '/traits/trait-mlv2-front-ui-cliente.php';
require_once __DIR__ . '/traits/trait-mlv2-front-ui-almacen.php';
require_once __DIR__ . '/traits/trait-mlv2-front-ui-gestor.php';

final class MLV2_Front_UI {
    use MLV2_Front_UI_Shared_Trait;
    use MLV2_Front_UI_Core_Trait;
    use MLV2_Front_UI_Registro_Trait;
    use MLV2_Front_UI_Gasto_Trait;
    use MLV2_Front_UI_Cliente_Trait;
    use MLV2_Front_UI_Almacen_Trait;
    use MLV2_Front_UI_Gestor_Trait;

    public static function money($amount) {
        $amount = floatval($amount);
        return '$' . number_format($amount, 0, ',', '.');
    }



    public static function client_label($user_id) {
        $u = get_user_by('id',$user_id);
        if(!$u) return '';
        $rut = get_user_meta($user_id,'mlv_rut',true);
        return esc_html($u->display_name . ' – ' . $rut);
    }

}
