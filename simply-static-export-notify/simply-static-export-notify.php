<?php
/**
 * Plugin Name:       Simply Static Export & Notify
 * Description:       Allow you to automatically export when saving post types and get discord notifications, including scheduled Posts
 * Tested up to:      6.8.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           1.0.4.7
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
define('rup_simply_static_export_notify_VERSION', '1.0.4.7');
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


// ──────────────────────────────────────────────────────────────────────────
//  Updater bootstrap (plugins_loaded priority 1):
// ──────────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    // 1) Load our universal drop-in. Because that file begins with "namespace UUPD\V1;",
    //    both the class and the helper live under UUPD\V1.
    require_once __DIR__ . '/includes/updater.php';

    // 2) Build a single $updater_config array:
    $updater_config = [
        'plugin_file' => plugin_basename( __FILE__ ),             // e.g. "simply-static-export-notify/simply-static-export-notify.php"
        'slug'        => 'simply-static-export-notify',           // must match your updater‐server slug
        'name'        => 'Simply Static Export & Notify',         // human‐readable plugin name
        'version'     => rup_simply_static_export_notify_VERSION, // same as the VERSION constant above
        'key'         => '7tfbdV9znHuZtzLfmctUg6',                 // your secret key for private updater
        'server'      => 'https://updater.reallyusefulplugins.com/u/',
        // 'textdomain' is omitted, so the helper will automatically use 'slug'
        //'textdomain'  => 'simply-static-export-notify',           // used to translate “Check for updates”
    ];

    // 3) Call the helper in the UUPD\V1 namespace:
    \UUPD\V1\uupd_register_updater_and_manual_check( $updater_config );
}, 1 );