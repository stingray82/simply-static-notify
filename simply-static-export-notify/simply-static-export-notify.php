<?php
/**
 * Plugin Name:       Simply Static Export & Notify
 * Description:       Allow you to automatically export when saving post types and get discord notifications, including scheduled Posts
 * Tested up to:      6.8.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           1.0.4.2
 * Author:            reallyusefulplugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       simply-static-export-notify
 * Website:           https://reallyusefulplugins.com
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// Define plugin constants
define('rup_simply_static_export_notify_VERSION', '1.0.4.2');
define('rup_simply_static_export_notify_DIR', plugin_dir_path(__FILE__));
define('rup_simply_static_export_notify_URL', plugin_dir_url(__FILE__));

// Include functions
require_once rup_simply_static_export_notify_DIR . 'includes/functions.php';

// Run on activation
function rup_simply_static_export_notify_activate() {
    update_option('rup_simply_static_export_notify_activated', time());
}
register_activation_hook(__FILE__, 'rup_simply_static_export_notify_activate');

// Run on deactivation
function rup_simply_static_export_notify_deactivate() {
    delete_option('rup_simply_static_export_notify_activated');
}
register_deactivation_hook(__FILE__, 'rup_simply_static_export_notify_deactivate');


add_action( 'plugins_loaded', function() {
    $updater_config = [
        'plugin_file' => plugin_basename( __FILE__ ),
        'slug'        => 'simply-static-export-notify',  // "rup-changelogger"
        'name'        => 'Simply Static Export & Notify',        // "Changelogger"
        'version'     => rup_simply_static_export_notify_VERSION,     // "1.01"
        'key'         => '7tfbdV9znHuZtzLfmctUg6',
        'server'      => 'https://updater.reallyusefulplugins.com/u/',
    ];

    require_once __DIR__ . '/includes/updater.php';
    $updater = new \UUPD\V1\UUPD_Updater_V1( $updater_config  );
} );
