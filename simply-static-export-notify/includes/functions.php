<?php
if (!defined('ABSPATH')) {
    exit;
}

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
        // Load saved settings
        $defaults = [
            'debug'               => false,
            'interval'            => 300,
            'post_types'          => ['post','page'],
            'destination_domain'  => home_url(),
            'discord_webhook'     => '',
            'message_template'    => 'Export Complete – {site_url}',
            'logo_url'            => plugin_dir_url( dirname( __FILE__ ) ) . 'assets/img/simplystatic-logo.png',
        ];
        $this->settings = wp_parse_args(
            get_option( $this->option_name, [] ),
            $defaults
        );

        // Admin menu & settings
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ], 99 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Cron hook
        add_action( 'ss_export_notify_cron', [ $this, 'run_export' ] );

        // Schedule on post save
        add_action( 'wp_after_insert_post', [ $this, 'maybe_schedule_export' ], 10, 4 );

        // Listen for Simply Static complete
        add_action( 'ss_completed', [ $this, 'notify_discord' ], 10 );
    }

                            public function add_admin_menu() {
        // Submenu under Simply Static "Generate"
        add_submenu_page(
            'simply-static-generate',  // parent slug
            'Export/Notify',           // page title
            'Export/Notify',           // menu title
            'manage_options',          // capability
            'ss-export-notify',        // menu slug
            [ $this, 'settings_page' ] // callback
        );
    }
public function register_settings() {
        register_setting( $this->option_group, $this->option_name );

        add_settings_section(
            'ss_general_section',
            'General Settings',
            '__return_false',
            $this->option_group
        );

        add_settings_field(
            'debug',
            'Enable Debug Logging',
            [ $this, 'field_debug' ],
            $this->option_group,
            'ss_general_section'
        );
        add_settings_field(
            'interval',
            'Export Interval (seconds)',
            [ $this, 'field_interval' ],
            $this->option_group,
            'ss_general_section'
        );
        add_settings_field(
            'post_types',
            'Post Types',
            [ $this, 'field_post_types' ],
            $this->option_group,
            'ss_general_section'
        );
        add_settings_field(
            'destination_domain',
            'Destination Domain',
            [ $this, 'field_destination_domain' ],
            $this->option_group,
            'ss_general_section'
        );
        add_settings_field(
            'discord_webhook',
            'Discord Webhook URL',
            [ $this, 'field_discord_webhook' ],
            $this->option_group,
            'ss_general_section'
        );
        add_settings_field(
            'logo_url',
            'Notification Logo URL',
            [ $this, 'field_logo_url' ],
            $this->option_group,
            'ss_general_section'
        );
        add_settings_field(
            'message_template',
            'Message Template',
            [ $this, 'field_message_template' ],
            $this->option_group,
            'ss_general_section'
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Export & Notify Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_group );
                do_settings_sections( $this->option_group );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    // Field callbacks
    public function field_debug() {
        printf(
            '<input type="checkbox" name="%1$s[debug]" value="1" %2$s />',
            esc_attr($this->option_name),
            checked(1, $this->settings['debug'], false)
        );
    }
    public function field_interval() {
        printf(
            '<input type="number" name="%1$s[interval]" value="%2$d" min="0" />',
            esc_attr($this->option_name),
            absint($this->settings['interval'])
        );
    }
    public function field_post_types() {
        $types = get_post_types([], 'objects');
        foreach ($types as $type) {
            printf(
                '<label><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s /> %2$s</label><br>',
                esc_attr($this->option_name),
                esc_attr($type->name),
                in_array($type->name, $this->settings['post_types'], true) ? 'checked' : ''
            );
        }
    }
    public function field_destination_domain() {
        printf(
            '<input type="text" class="regular-text" name="%1$s[destination_domain]" value="%2$s" />',
            esc_attr($this->option_name),
            esc_attr($this->settings['destination_domain'])
        );
    }
    public function field_discord_webhook() {
        printf(
            '<input type="url" class="regular-text" name="%1$s[discord_webhook]" value="%2$s" />',
            esc_attr($this->option_name),
            esc_attr($this->settings['discord_webhook'])
        );
    }
    public function field_logo_url() {
        printf(
            '<input type="url" class="regular-text" name="%1$s[logo_url]" value="%2$s" />',
            esc_attr($this->option_name),
            esc_attr($this->settings['logo_url'])
        );
    }
    public function field_message_template() {
        printf(
            '<textarea name="%1$s[message_template]" rows="3" cols="50">%2$s</textarea><p class="description">Use {site_url} and {logo_url} placeholders.</p>',
            esc_attr($this->option_name),
            esc_textarea($this->settings['message_template'])
        );
    }

    // Logging
    private function log( $msg ) {
        if ( $this->settings['debug'] ) {
            error_log( "[ss_export_notify] " . $msg );
        }
    }

    public function maybe_schedule_export( $post_id, $post, $update, $post_before ) {
        if ( wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ) {
            return;
        }
        if ( 'publish' !== $post->post_status ) {
            return;
        }
        if ( ! in_array($post->post_type, $this->settings['post_types'], true) ) {
            return;
        }
        $hook = 'ss_export_notify_cron';
        if ( wp_next_scheduled($hook) ) {
            $this->log('Export already scheduled');
            return;
        }
        $when = time() + intval($this->settings['interval']);
        wp_schedule_single_event( $when, $hook );
        $this->log('Scheduled export at ' . date('Y-m-d H:i:s', $when));
    }

    public function run_export() {
        $this->log('Running static export');
        if ( class_exists('Simply_Static\\Plugin') ) {
            $plugin = Simply_Static\Plugin::instance();
            $plugin->run_static_export();
            $this->log('Export executed');
        } else {
            $this->log('Simply Static plugin not found');
        }
    }

    public function notify_discord() {
        $webhook = esc_url_raw( $this->settings['discord_webhook'] );
        if ( empty( $webhook ) ) {
            $this->log( 'No webhook configured' );
            return;
        }
        $site    = rtrim( $this->settings['destination_domain'], '/' );
        $site_url = get_home_url();
        $status  = get_option( 'simply_static_status_message', 'Export finished!' );
        $message = str_replace(
            ['{site_url}', '{logo_url}'],
            [$site, esc_url( $this->settings['logo_url'] )],
            $this->settings['message_template']
        );

        // Append site identifier to status
        $full_status = sprintf( '%s from %s', $status, $site_url );

        $embed = [
            'title'       => $message,
            'url'         => $site,
            'description' => $full_status,
            'color'       => 0x00cc66,
        ];
        $payload = [
            'username'   => 'Static Export Bot',
            'avatar_url' => esc_url( $this->settings['logo_url'] ),
            'embeds'     => [ $embed ],
        ];

        wp_remote_post( $webhook, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 5,
        ] );

        $this->log( 'Discord notification sent: ' . $message . ' - ' . $full_status );
    }

}

SS_Export_Notify_Plugin::instance();