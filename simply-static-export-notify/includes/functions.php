<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SS_Export_Notify_Plugin {

    private static $instance;
    private $option_group = 'ss_export_notify_options';
    private $option_name  = 'ss_export_notify_settings';
    private $settings;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        $defaults = [
            'debug'              => false,
            'interval'           => 300,
            'post_types'         => [],
            'allowed_mime_types' => 'application/pdf,image/jpeg,image/png,image/webp,image/gif,image/svg+xml,text/plain,text/css,text/html,text/javascript,application/zip,application/x-zip-compressed,application/msword,application/vnd.ms-excel,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.presentationml.presentation,video/mp4,video/webm,video/ogg,audio/mpeg,audio/ogg',
            'destination_domain' => home_url(),
            'discord_webhook'    => '',
            'message_template'   => 'Export Complete – {site_url}',
            'logo_url'           => plugin_dir_url( dirname( __FILE__ ) ) . 'assets/img/simplystatic-logo.png',
            'log_location'       => WP_CONTENT_DIR . '/ss-export-notify-debug.txt',
        ];

        $this->settings = wp_parse_args( get_option( $this->option_name, [] ), $defaults );
        $this->settings['debug'] = filter_var( $this->settings['debug'], FILTER_VALIDATE_BOOLEAN );

        if ( ! $this->settings['debug'] && file_exists( $this->settings['log_location'] ) ) {
            @unlink( $this->settings['log_location'] );
        }

        add_action( 'admin_menu', [ $this, 'add_admin_menu' ], 99 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        add_action( 'wp_after_insert_post', [ $this, 'maybe_schedule_export' ], 10, 4 );
        add_action( 'elementor/editor/after_save', [ $this, 'handle_elementor_save' ] );
        add_action( 'add_attachment', [ $this, 'handle_media_upload' ] );
        add_action( 'ssen_run_delayed_export', [ $this, 'run_delayed_export' ] );
        add_action( 'ss_completed', [ $this, 'notify_discord' ], 10 );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'simply-static-generate',
            'Export/Notify',
            'Export/Notify',
            'manage_options',
            'ss-export-notify',
            [ $this, 'settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( $this->option_group, $this->option_name );

        add_settings_section( 'ss_general_section', 'General Settings', '__return_false', $this->option_group );

        add_settings_field( 'debug', 'Enable Debug Logging', [ $this, 'field_debug' ], $this->option_group, 'ss_general_section' );
        add_settings_field( 'interval', 'Export Interval (seconds)', [ $this, 'field_interval' ], $this->option_group, 'ss_general_section' );
        add_settings_field( 'post_types', 'Post Types', [ $this, 'field_post_types' ], $this->option_group, 'ss_general_section' );
        add_settings_field( 'allowed_mime_types', 'Allowed MIME Types', [ $this, 'field_allowed_mime_types' ], $this->option_group, 'ss_general_section' );
        add_settings_field( 'destination_domain', 'Destination Domain', [ $this, 'field_destination_domain' ], $this->option_group, 'ss_general_section' );
        add_settings_field( 'discord_webhook', 'Discord Webhook URL', [ $this, 'field_discord_webhook' ], $this->option_group, 'ss_general_section' );
        add_settings_field( 'logo_url', 'Notification Logo URL', [ $this, 'field_logo_url' ], $this->option_group, 'ss_general_section' );
        add_settings_field( 'message_template', 'Message Template', [ $this, 'field_message_template' ], $this->option_group, 'ss_general_section' );
        add_settings_field( 'log_location', 'Log File Location', [ $this, 'field_log_location' ], $this->option_group, 'ss_general_section' );
    }

    public function settings_page() {
        echo '<div class="wrap"><h1>Export &amp; Notify Settings</h1><form method="post" action="options.php">';
        settings_fields( $this->option_group );
        do_settings_sections( $this->option_group );
        submit_button();
        echo '</form></div>';
    }

    public function field_debug() {
        printf('<input type="checkbox" name="%1$s[debug]" value="1" %2$s />', esc_attr($this->option_name), checked(1, $this->settings['debug'], false));
    }

    public function field_interval() {
        printf('<input type="number" name="%1$s[interval]" value="%2$d" min="0" />', esc_attr($this->option_name), absint($this->settings['interval']));
    }

    public function field_post_types() {
        foreach ( get_post_types([], 'objects') as $type ) {
            printf('<label><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s /> %2$s</label><br>', esc_attr($this->option_name), esc_attr($type->name), in_array($type->name, $this->settings['post_types'], true) ? 'checked' : '');
        }
    }

    public function field_allowed_mime_types() {
        printf('<input type="text" class="regular-text" style="width: 100%%;" name="%1$s[allowed_mime_types]" value="%2$s" />', esc_attr($this->option_name), esc_attr($this->settings['allowed_mime_types']));
        echo '<p class="description">Comma-separated list of allowed MIME types.</p>';
    }

    public function field_destination_domain() {
        printf('<input type="text" class="regular-text" name="%1$s[destination_domain]" value="%2$s" />', esc_attr($this->option_name), esc_attr($this->settings['destination_domain']));
    }

    public function field_discord_webhook() {
        printf('<input type="url" class="regular-text" name="%1$s[discord_webhook]" value="%2$s" />', esc_attr($this->option_name), esc_attr($this->settings['discord_webhook']));
    }

    public function field_logo_url() {
        printf('<input type="url" class="regular-text" name="%1$s[logo_url]" value="%2$s" />', esc_attr($this->option_name), esc_attr($this->settings['logo_url']));
    }

    public function field_message_template() {
        printf('<textarea name="%1$s[message_template]" rows="3" cols="50">%2$s</textarea>', esc_attr($this->option_name), esc_textarea($this->settings['message_template']));
    }

    public function field_log_location() {
        printf('<input type="text" class="regular-text" name="%1$s[log_location]" value="%2$s" />', esc_attr($this->option_name), esc_attr($this->settings['log_location']));
        if ( $this->settings['debug'] && file_exists($this->settings['log_location']) ) {
            $url = esc_url( content_url( str_replace(WP_CONTENT_DIR, '', $this->settings['log_location']) ) );
            echo '<p><a href="' . $url . '" target="_blank">View debug log</a></p>';
        }
    }

    private function log($msg) {
        if ( $this->settings['debug'] && !empty($this->settings['log_location']) ) {
            file_put_contents($this->settings['log_location'], date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
        }
    }

    public function maybe_schedule_export($post_id, $post, $update, $post_before) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || $post->post_type === 'revision') {
            $this->log("Skipped export – post ID {$post_id} is a revision or autosave.");
            return;
        }

        if (! in_array($post->post_status, ['publish', 'private'], true)) {
            $this->log("Skipped export – post ID {$post_id} has status {$post->post_status}.");
            return;
        }

        $post_type = $post->post_type;
        if (! in_array($post_type, $this->settings['post_types'], true)) {
            $this->log("Skipped export – post type '{$post_type}' not enabled in settings.");
            return;
        }

        if ($post_type === 'attachment') {
            $mime = get_post_mime_type($post);
            $allowed = array_map('trim', explode(',', $this->settings['allowed_mime_types']));
            if (! in_array($mime, $allowed, true)) {
                $this->log("Skipped attachment – unsupported MIME type: {$mime}");
                return;
            }

            if (class_exists('\\simply_static_pro\\Single')) {
                update_option('simply-static-use-single', $post_id);
                \simply_static_pro\Single::get_instance()->prepare_single_export($post_id, false);
                \Simply_Static\Plugin::instance()->run_static_export();
                $this->log("Pro attachment export triggered for: {$post_id}");
            } else {
                $this->log("Skipped attachment export – Pro not available for: {$post_id}");
            }
            return;
        }

        $delay = time() + intval($this->settings['interval']);
        wp_schedule_single_event($delay, 'ssen_run_delayed_export', [$post_id]);
        $this->log("Scheduled export for post ID: {$post_id} at " . date('Y-m-d H:i:s', $delay));
    }

    public function run_delayed_export($post_id) {
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_status, ['publish', 'private'], true)) return;

        if (class_exists('\\Simply_Static\\Plugin')) {
            if (class_exists('\\simply_static_pro\\Single')) {
                \Simply_Static\Plugin::instance()->run_static_export(0, 'update');
                $this->log("Delayed Pro update export triggered for post ID: {$post_id}");
            } else {
                \Simply_Static\Plugin::instance()->run_static_export();
                $this->log("Delayed base full export triggered for post ID: {$post_id}");
            }
        }
    }

    public function notify_discord() {
        $webhook = esc_url_raw($this->settings['discord_webhook']);
        if (!$webhook) return;

        $site = rtrim($this->settings['destination_domain'], '/');
        $site_url = get_home_url();
        $status = get_option('simply_static_status_message', 'Export finished!');
        $message = str_replace(['{site_url}', '{logo_url}'], [$site, esc_url($this->settings['logo_url'])], $this->settings['message_template']);

        $payload = [
            'username' => 'Static Export Bot',
            'avatar_url' => esc_url($this->settings['logo_url']),
            'embeds' => [[
                'title' => $message,
                'url' => $site,
                'description' => "$status from $site_url",
                'color' => 0x00cc66
            ]],
        ];

        wp_remote_post($webhook, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body' => wp_json_encode($payload),
            'timeout' => 5
        ]);

        $this->log("Discord notification sent: $message");
    }

    public function handle_elementor_save($post) {
        $this->maybe_schedule_export($post->ID, $post, true, $post);
    }

    public function handle_media_upload($post_id) {
        $post = get_post($post_id);
        $this->maybe_schedule_export($post_id, $post, true, $post);
    }
}

SS_Export_Notify_Plugin::instance();
