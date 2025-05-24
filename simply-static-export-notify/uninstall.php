<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('rup_simply_static_export_notify_activated');
