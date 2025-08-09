<?php

/**
 * Plugin Name:       WP Data Bridge
 * Description:       WordPress multisite-compatible plugin that exports posts, custom post types, pages, featured images, and users to CSV format.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            WP Data Bridge Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-data-bridge
 * Network:           true
 *
 * @package WpDataBridge
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_DATA_BRIDGE_VERSION', '1.0.0');
define('WP_DATA_BRIDGE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_DATA_BRIDGE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_DATA_BRIDGE_UPLOAD_DIR', wp_upload_dir()['basedir'] . '/wp-data-bridge/');

class WP_Data_Bridge
{

    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init()
    {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies()
    {
        require_once WP_DATA_BRIDGE_PLUGIN_DIR . 'app/class-exporter.php';
        require_once WP_DATA_BRIDGE_PLUGIN_DIR . 'app/class-importer.php';
        require_once WP_DATA_BRIDGE_PLUGIN_DIR . 'app/class-csv-generator.php';
        require_once WP_DATA_BRIDGE_PLUGIN_DIR . 'app/class-admin.php';
        require_once WP_DATA_BRIDGE_PLUGIN_DIR . 'app/class-file-handler.php';
    }

    private function init_hooks()
    {
        if (is_multisite()) {
            add_action('network_admin_menu', [$this, 'add_network_admin_menu']);
        } else {
            add_action('admin_menu', [$this, 'add_admin_menu']);
        }

        WP_Data_Bridge_Admin::register_ajax_hooks();
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        $file_handler = new WP_Data_Bridge_File_Handler();
        $file_handler->register_download_handler();

        add_action('wp_data_bridge_cleanup_files', [WP_Data_Bridge_File_Handler::class, 'handle_scheduled_cleanup']);

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function add_network_admin_menu()
    {
        add_menu_page(
            __('WP Data Bridge', 'wp-data-bridge'),
            __('Data Bridge', 'wp-data-bridge'),
            'manage_network_options',
            'wp-data-bridge',
            [WP_Data_Bridge_Admin::class, 'display_admin_page'],
            'dashicons-database-export',
            30
        );
    }

    public function add_admin_menu()
    {
        add_menu_page(
            __('WP Data Bridge', 'wp-data-bridge'),
            __('Data Bridge', 'wp-data-bridge'),
            'manage_options',
            'wp-data-bridge',
            [WP_Data_Bridge_Admin::class, 'display_admin_page'],
            'dashicons-database-export',
            30
        );
    }

    public function enqueue_admin_scripts($hook)
    {
        if (strpos($hook, 'wp-data-bridge') === false) {
            return;
        }

        wp_enqueue_script(
            'wp-data-bridge-admin',
            WP_DATA_BRIDGE_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WP_DATA_BRIDGE_VERSION,
            true
        );

        wp_enqueue_style(
            'wp-data-bridge-admin',
            WP_DATA_BRIDGE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WP_DATA_BRIDGE_VERSION
        );

        wp_localize_script('wp-data-bridge-admin', 'wpDataBridge', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_data_bridge_nonce'),
            'strings' => [
                'exporting' => __('Exporting data...', 'wp-data-bridge'),
                'complete' => __('Export complete!', 'wp-data-bridge'),
                'error' => __('Export failed. Please try again.', 'wp-data-bridge'),
            ]
        ]);
    }

    public function activate()
    {
        if (!wp_mkdir_p(WP_DATA_BRIDGE_UPLOAD_DIR)) {
            wp_die(__('Could not create upload directory for WP Data Bridge.', 'wp-data-bridge'));
        }

        $htaccess_file = WP_DATA_BRIDGE_UPLOAD_DIR . '.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "deny from all\n");
        }

        WP_Data_Bridge_File_Handler::schedule_cleanup();
    }

    public function deactivate() {}
}

WP_Data_Bridge::get_instance();
