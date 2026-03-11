<?php
if (!defined('ABSPATH')) { exit; }

trait MLV2_Front_UI_Core_Trait {
public static function init() {
        // Full dashboards (LEGADO):
        // Se dejaron de registrar porque el sitio usa los bloques por separado.

        add_shortcode('mlv_panel_alert', [__CLASS__, 'panel_alert']);

        // Cliente blocks
        add_shortcode('mlv_cliente_info', [__CLASS__, 'cliente_info']);
        add_shortcode('mlv_cliente_almacen_asignado', [__CLASS__, 'cliente_almacen_asignado']);
        add_shortcode('mlv_cliente_kpis', [__CLASS__, 'cliente_kpis']);
        add_shortcode('mlv_cliente_movimientos', [__CLASS__, 'cliente_movimientos']);

        // Almacén blocks
        add_shortcode('mlv_almacen_info', [__CLASS__, 'almacen_info']);
        add_shortcode('mlv_almacen_clientes', [__CLASS__, 'almacen_clientes']);
        add_shortcode('mlv_almacen_clientes_acciones', [__CLASS__, 'almacen_clientes_acciones']);
        add_shortcode('mlv_almacen_kpis', [__CLASS__, 'almacen_kpis']);
        add_shortcode('mlv_almacen_movimientos', [__CLASS__, 'almacen_movimientos']);
        add_shortcode('mlv_almacen_gestores', [__CLASS__, 'almacen_gestores']);

        // Gestor blocks
        add_shortcode('mlv_gestor_info', [__CLASS__, 'gestor_info']);
        add_shortcode('mlv_gestor_almacen_asignado', [__CLASS__, 'gestor_almacen_asignado']);
        add_shortcode('mlv_gestor_almacenes_disponibles', [__CLASS__, 'gestor_almacenes_disponibles']);
        
        // Registro de latas
        add_shortcode('mlv_registro_latas', [__CLASS__, 'registro_latas']);
        add_shortcode('mlv_registro_gasto', [__CLASS__, 'registro_gasto']);
    }
}
