<?php
if (!defined('ABSPATH')) exit;

class TKU_Elementor {
    public static function init() {
        // Elementor 3.5+:
        add_action('elementor/widgets/register', [__CLASS__, 'register_widgets']);
        // Legacy Elementor hook:
        add_action('elementor/widgets/widgets_registered', [__CLASS__, 'register_widgets']);
        add_action('elementor/elements/categories_registered', [__CLASS__, 'register_category']);
    }

    public static function register_category($elements_manager) {
        if (is_object($elements_manager) && method_exists($elements_manager, 'add_category')) {
            $elements_manager->add_category('tku', [
                'title' => 'Temetkezési Ügyintézés',
                'icon'  => 'fa fa-plug',
            ]);
        }
    }

    public static function register_widgets($widgets_manager = null) {
        // Ensure Elementor is available
        if (!$widgets_manager) {
            if (!class_exists('Elementor\\Plugin')) return;
            $widgets_manager = \Elementor\Plugin::instance()->widgets_manager;
        }
        if (!is_object($widgets_manager)) return;

        require_once TKU_PLUGIN_DIR . 'includes/elementor/widgets/class-tku-widget-start.php';
        require_once TKU_PLUGIN_DIR . 'includes/elementor/widgets/class-tku-widget-continue.php';
        require_once TKU_PLUGIN_DIR . 'includes/elementor/widgets/class-tku-widget-status.php';

        $widgets = [
            new \TKU_Widget_Start(),
            new \TKU_Widget_Continue(),
            new \TKU_Widget_Status(),
        ];

        foreach ($widgets as $w) {
            if (method_exists($widgets_manager, 'register')) {
                $widgets_manager->register($w);
            } elseif (method_exists($widgets_manager, 'register_widget_type')) {
                // Legacy Elementor
                $widgets_manager->register_widget_type($w);
            }
        }
    }
}
