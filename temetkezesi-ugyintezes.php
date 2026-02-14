<?php
/**
 * Plugin Name: Temetkezési Ügyintézés (Elementor)
 * Description: Ügyindító + folytatható (tokenes) űrlap + admin státusz + státusz lekérdezés. Elementor widgetekkel és shortcode-okkal.
 * Version: 1.3.1
 * Author: TKU
 * Text Domain: tku
 */

if (!defined('ABSPATH')) exit;

define('TKU_VERSION', '1.3.1');
define('TKU_PLUGIN_FILE', __FILE__);
define('TKU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TKU_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once TKU_PLUGIN_DIR . 'includes/class-tku-db.php';
require_once TKU_PLUGIN_DIR . 'includes/class-tku-mail.php';
require_once TKU_PLUGIN_DIR . 'includes/class-tku-shortcodes.php';
require_once TKU_PLUGIN_DIR . 'includes/class-tku-admin.php';
require_once TKU_PLUGIN_DIR . 'includes/elementor/class-tku-elementor.php';

register_activation_hook(__FILE__, ['TKU_DB', 'activate']);
register_deactivation_hook(__FILE__, ['TKU_DB', 'deactivate']);

add_action('plugins_loaded', function() {
    TKU_DB::init();
    TKU_Mail::init();
    TKU_Shortcodes::init();
    if (is_admin()) {
        TKU_Admin::init();
    }
    TKU_Elementor::init();
});

add_action('wp_enqueue_scripts', function() {
    wp_register_style('tku-styles', TKU_PLUGIN_URL . 'assets/tku-styles.css', [], TKU_VERSION);
    wp_register_script('tku-forms', TKU_PLUGIN_URL . 'assets/tku-forms.js', ['jquery'], TKU_VERSION, true);
    wp_register_script('tku-ui', TKU_PLUGIN_URL . 'assets/tku-ui.js', [], TKU_VERSION, true);
});


// GDPR retention cron
add_action('tku_retention_cron', ['TKU_DB', 'run_retention_cron']);
