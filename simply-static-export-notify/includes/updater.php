<?php
/**
 * Universal Updater Drop-In for Plugins & Themes
 * Encapsulated in UUPD_Updater – safe to include multiple times.
 *
 * Usage (plugins):
 *
 *   1) Copy this file to `inc/updater.php` inside your plugin.
 *
 *   2) In your **main plugin file** (e.g. `my-plugin.php`), do something like:
 *
 *        add_action( 'plugins_loaded', function() {
 *            // Build one single config array:
 *            $updater_config = [
 *                'plugin_file' => plugin_basename( __FILE__ ),        // e.g. "my-plugin/my-plugin.php"
 *                'slug'        => 'my-plugin-slug',                   // must match your updater server’s slug
 *                'name'        => 'My Plugin Name',                   // human-readable name
 *                'version'     => MY_PLUGIN_VERSION,                   // define this somewhere above
 *                'key'         => 'YourSecretKeyHere',                // secret key
 *                'server'      => 'https://updater.my-server.com/u/',  // your updater endpoint
 *                // 'textdomain' is optional; if omitted, it defaults to 'slug'
 *                'textdomain'  => 'my-plugin-textdomain',              // for translating row-meta links
 *            ];
 *
 *            // Load the drop-in (this file):
 *            require_once __DIR__ . '/inc/updater.php';
 *
 *            // Call our one helper to do both:
 *            //   1) instantiate UUPD_Updater_V1
 *            //   2) add “View details” + “Check for updates” links that clear the cache transient
 *            uupd_register_updater_and_manual_check( $updater_config );
 *        }, 1 );
 *
 *
 * Usage (themes):
 *
 *   1) Copy this file to `inc/updater.php` inside your theme.
 *
 *   2) In your theme’s `functions.php`, do:
 *
 *        add_action( 'after_setup_theme', function() {
 *            $updater_config = [
 *                // No plugin_file for themes; WP treats it as a theme update.
 *                'slug'       => 'my-theme-folder',            // match your theme folder & textdomain
 *                'name'       => 'My Theme Name',              // human-readable theme name
 *                'version'    => '1.0.0',                      // match your style.css Version header
 *                'key'        => 'YourSecretKeyHere',
 *                'server'     => 'https://updater.my-server.com/u/',
 *                // 'textdomain' is optional for themes as well
 *                'textdomain' => 'my-theme-textdomain',        // for translating row-meta links
 *            ];
 *
 *            require_once get_stylesheet_directory() . '/inc/updater.php';
 *            // On theme boot, register updater + manual-check links:
 *            add_action( 'admin_init', function() use ( $updater_config ) {
 *                uupd_register_updater_and_manual_check( $updater_config );
 *            } );
 *        } );
 *
 *
 * 3) To enable debug logging, anywhere in your code add:
 *
 *       add_filter( 'updater_enable_debug', fn( $e ) => true );
 *
 * 4) In your `wp-config.php`, turn on:
 *
 *       define( 'WP_DEBUG',     true );
 *       define( 'WP_DEBUG_LOG', true );
 *
 * Now your drop-in will:
 *  - Fetch metadata from `/u/?action=get_metadata&slug={slug}&key={key}`
 *  - Cache it for 1 hour (transient `upd_{slug}`)
 *  - Inject plugin or theme updates accordingly
 *  - Populate the “View details” modal (Thickbox) with your changelog/details
 *  - **Plus** add two row-meta links under the plugin (or theme) row:
 *      • “View details” → opens WP’s plugin-information Thickbox  
 *      • “Check for updates” → clears the cached transient (`upd_{slug}`),  
 *        optionally forces `wp_update_plugins()` (or `wp_update_themes()`),  
 *        then redirects back to Plugins (or Themes) so you see the fresh data.
 */

namespace UUPD\V1;

if ( ! class_exists( __NAMESPACE__ . '\UUPD_Updater_V1' ) ) {

    class UUPD_Updater_V1 {

        /** @var array Configuration settings */
        private $config;

        /**
         * Constructor.
         *
         * @param array $config {
         *   @type string 'slug'        Plugin or theme slug.
         *   @type string 'name'        Human-readable name.
         *   @type string 'version'     Current version.
         *   @type string 'key'         Your secret key.
         *   @type string 'server'      Base URL of your updater endpoint.
         *   @type string 'plugin_file' (optional) plugin_basename(__FILE__) for plugins.
         * }
         */
        public function __construct( array $config ) {
            $this->config = $config;
            $this->register_hooks();
        }

        /** Attach update and info filters for plugin or theme. */
        private function register_hooks() {
            if ( ! empty( $this->config['plugin_file'] ) ) {
                add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'plugin_update' ] );
                add_filter( 'site_transient_update_plugins',     [ $this, 'plugin_update' ] ); // WP 6.8
                add_filter( 'plugins_api',                       [ $this, 'plugin_info' ], 10, 3 );
            } else {
                add_filter( 'pre_set_site_transient_update_themes', [ $this, 'theme_update' ] );
                add_filter( 'site_transient_update_themes',          [ $this, 'theme_update' ] ); // WP 6.8
                add_filter( 'themes_api',                            [ $this, 'theme_info' ], 10, 3 );
            }
        }

        /** Fetch metadata JSON from remote server and cache it. */
        private function fetch_remote() {
            $c    = $this->config;
            $slug = rawurlencode( $c['slug'] );
            $key  = rawurlencode( $c['key'] );
            $host = rawurlencode( wp_parse_url( untrailingslashit( home_url() ), PHP_URL_HOST ) );
            $url  = untrailingslashit( $c['server'] )
                  . "/?action=get_metadata&slug={$slug}&key={$key}&domain={$host}";

            $this->log( "→ Fetching metadata: {$url}" );
            $resp = wp_remote_get( $url, [
                'timeout' => 15,
                'headers' => [ 'Accept' => 'application/json' ],
            ] );

            if ( is_wp_error( $resp ) ) {
                return $this->log( '✗ HTTP error: ' . $resp->get_error_message() );
            }

            $code = wp_remote_retrieve_response_code( $resp );
            $body = wp_remote_retrieve_body( $resp );
            $this->log( "← HTTP {$code}: " . trim( $body ) );
            if ( 200 !== (int) $code ) {
                return;
            }

            $meta = json_decode( $body );
            if ( ! $meta ) {
                return $this->log( '✗ JSON decode failed' );
            }

            set_transient( 'upd_' . $c['slug'], $meta, 6 * HOUR_IN_SECONDS );
            $this->log( "✓ Cached metadata '{$c['slug']}' → v" . ( $meta->version ?? 'unknown' ) );
        }

        /** Handle plugin update injection. */
        public function plugin_update( $trans ) {
            if ( ! is_object( $trans ) || ! isset( $trans->checked ) || ! is_array( $trans->checked ) ) {
                return $trans;
            }

            $c    = $this->config;
            $file = $c['plugin_file'];

            $this->log( "→ Plugin-update hook for '{$c['slug']}'" );

            $current = $trans->checked[ $file ] ?? $c['version'];
            $meta    = get_transient( 'upd_' . $c['slug'] );

            if ( false === $meta ) {
                $this->fetch_remote();
                $meta = get_transient( 'upd_' . $c['slug'] );
            }

            // If no update is available, inject to 'no_update':
            if ( ! $meta || version_compare( $meta->version ?? '0.0.0', $current, '<=' ) ) {
                $trans->no_update[ $file ] = (object) [
                    'id'            => $file,
                    'slug'          => $c['slug'],
                    'plugin'        => $file,
                    'new_version'   => $current,
                    'url'           => $meta->homepage ?? '',
                    'package'       => '',
                    'icons'         => isset( $meta->icons ) ? (array) $meta->icons   : [],
                    'banners'       => isset( $meta->banners ) ? (array) $meta->banners : [],
                    'tested'        => $meta->tested   ?? '',
                    'requires'      => $meta->requires ?? $meta->min_wp_version ?? '',
                    'requires_php'  => $meta->requires_php ?? '',
                    'compatibility' => new \stdClass(),
                ];
                return $trans;
            }

            // If an update is available, inject full metadata to 'response'
            $this->log( "✓ Injecting plugin update v" . ( $meta->version ?? 'unknown' ) );
            $trans->response[ $file ] = (object) [
                'id'            => $file,
                'name'          => $c['name'],
                'slug'          => $c['slug'],
                'plugin'        => $file,
                'new_version'   => $meta->version ?? $c['version'],
                'package'       => $meta->download_url ?? '',
                'url'           => $meta->homepage ?? '',
                'tested'        => $meta->tested ?? '',
                'requires'      => $meta->requires ?? $meta->min_wp_version ?? '',
                'requires_php'  => $meta->requires_php ?? '',
                'sections'      => isset( $meta->sections ) ? (array) $meta->sections : [],
                'icons'         => isset( $meta->icons ) ? (array) $meta->icons   : [],
                'banners'       => isset( $meta->banners ) ? (array) $meta->banners : [],
                'compatibility' => new \stdClass(),
            ];

            // Remove any stale 'no_update'.
            if ( isset( $trans->no_update[ $file ] ) ) {
                unset( $trans->no_update[ $file ] );
            }

            return $trans;
        }

        /** Provide plugin information for the details popup. */
        public function plugin_info( $res, $action, $args ) {
            $c = $this->config;
            if ( 'plugin_information' !== $action || $args->slug !== $c['slug'] ) {
                return $res;
            }

            $meta = get_transient( 'upd_' . $c['slug'] );
            if ( ! $meta ) {
                return $res;
            }

            // Build sections array (description, installation, faq, screenshots, changelog…)
            $sections = [];
            if ( isset( $meta->sections ) ) {
                foreach ( (array) $meta->sections as $key => $content ) {
                    $sections[ $key ] = $content;
                }
            }

            return (object) [
                'name'            => $c['name'],
                'title'           => $c['name'],               // Popup title
                'slug'            => $c['slug'],
                'version'         => $meta->version        ?? '',
                'author'          => $meta->author         ?? '',
                'author_homepage' => $meta->author_homepage ?? '',
                'requires'        => $meta->requires       ?? $meta->min_wp_version ?? '',
                'tested'          => $meta->tested         ?? '',
                'requires_php'    => $meta->requires_php   ?? '',   // “Requires PHP: x.x or higher”
                'last_updated'    => $meta->last_updated   ?? '',
                'download_link'   => $meta->download_url   ?? '',
                'homepage'        => $meta->homepage       ?? '',
                'sections'        => $sections,
                'icons'           => isset( $meta->icons )   ? (array) $meta->icons   : [],
                'banners'         => isset( $meta->banners ) ? (array) $meta->banners : [],
                'screenshots'     => isset( $meta->screenshots ) 
                                       ? (array) $meta->screenshots 
                                       : [],
            ];
        }

        /** Handle theme update injection. */
        public function theme_update( $trans ) {
            if ( ! is_object( $trans ) || ! isset( $trans->checked ) || ! is_array( $trans->checked ) ) {
                return $trans;
            }
            $c       = $this->config;
            $slug    = $c['slug'];
            $current = $trans->checked[ $slug ] ?? wp_get_theme( $slug )->get( 'Version' );

            $meta = get_transient( 'upd_' . $slug );
            if ( false === $meta ) {
                $this->fetch_remote();
                $meta = get_transient( 'upd_' . $slug );
            }

            // Base theme information
            $base_info = [
                'theme'       => $slug,
                'url'         => $meta->homepage ?? '',
                'requires'    => $meta->requires ?? '',
                'requires_php'=> $meta->requires_php ?? '',
                'screenshot'  => $meta->screenshot ?? ''
            ];

            // If no update available, register in no_update
            if ( ! $meta || version_compare( $meta->version ?? '0.0.0', $current, '<=' ) ) {
                $trans->no_update[ $slug ] = (object) array_merge( $base_info, [
                    'new_version' => $current,
                    'package'     => ''
                ] );
                return $trans;
            }

            // If update is available, register in response
            $trans->response[ $slug ] = (object) array_merge( $base_info, [
                'new_version' => $meta->version ?? $current,
                'package'     => $meta->download_url ?? ''
            ] );

            // Remove from no_update if we're adding to response
            if ( isset( $trans->no_update[ $slug ] ) ) {
                unset( $trans->no_update[ $slug ] );
            }

            return $trans;
        }

        /** Provide theme information for the details popup. */
        public function theme_info( $res, $action, $args ) {
            $c = $this->config;
            if ( 'theme_information' !== $action || $args->slug !== $c['slug'] ) {
                return $res;
            }

            $meta = get_transient( 'upd_' . $c['slug'] );
            if ( ! $meta ) {
                return $res;
            }

            // Safely extract changelog HTML
            if ( isset( $meta->changelog_html ) ) {
                $changelog = $meta->changelog_html;
            } elseif ( isset( $meta->sections ) ) {
                if ( is_array( $meta->sections ) ) {
                    $changelog = $meta->sections['changelog'] ?? '';
                } elseif ( is_object( $meta->sections ) ) {
                    $changelog = $meta->sections->changelog ?? '';
                } else {
                    $changelog = '';
                }
            } else {
                $changelog = '';
            }

            return (object) [
                'name'          => $c['name'],
                'slug'          => $c['slug'],
                'version'       => $meta->version ?? '',
                'tested'        => $meta->tested ?? '',
                'requires'      => $meta->min_wp_version ?? '',
                'sections'      => [ 'changelog' => $changelog ],
                'download_link' => $meta->download_url ?? '',
                'icons'         => isset( $meta->icons )   ? (array) $meta->icons   : [],
                'banners'       => isset( $meta->banners ) ? (array) $meta->banners : [],
            ];
        }

        /** Optional debug logger. */
        private function log( $msg ) {
            if ( apply_filters( 'updater_enable_debug', false ) ) {
                error_log( "[Updater] {$msg}" );
            }
        }

        /**
         * NEW STATIC HELPER: register everything (was the global function before).
         *
         * @param array $config  Same structure you passed to the old uupd_register_updater_and_manual_check().
         */
        public static function register( array $config ) {
            // 1) Instantiate the updater class:
            new self( $config );

            // 2) Add the “Check for updates” link under the plugin row:
            $our_file   = $config['plugin_file'];   // e.g. "simply-static-export-notify/simply-static-export-notify.php"
            $slug       = $config['slug'];          // e.g. "simply-static-export-notify"
            $textdomain = ! empty( $config['textdomain'] ) ? $config['textdomain'] : $slug;

            add_filter(
                'plugin_row_meta',
                function( array $links, string $file, array $plugin_data ) use ( $our_file, $slug, $textdomain ) {
                    if ( $file === $our_file ) {
                        $nonce     = wp_create_nonce( 'uupd_manual_check_' . $slug );
                        $check_url = admin_url( sprintf(
                            'admin.php?action=uupd_manual_check&slug=%s&_wpnonce=%s',
                            rawurlencode( $slug ),
                            $nonce
                        ) );

                        $links[] = sprintf(
                            '<a href="%s">%s</a>',
                            esc_url( $check_url ),
                            esc_html__( 'Check for updates', $textdomain )
                        );
                    }
                    return $links;
                },
                10,
                3 // Must request all three args: ($links, $file, $plugin_data).
            );

            // 3) Hook up the manual‐check listener:
            add_action( 'admin_action_uupd_manual_check', function() use ( $slug, $config ) {
            // 1) Grab the requested slug and normalize it.
            $request_slug = isset( $_REQUEST['slug'] ) ? sanitize_key( wp_unslash( $_REQUEST['slug'] ) ) : '';

            // 2) If the incoming 'slug' doesn’t match this plugin’s slug, bail out early:
            if ( $request_slug !== $slug ) {
                return; 
            }

            // 3) Only users who can update plugins/themes should proceed.
            if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_themes' ) ) {
                wp_die( __( 'Cheatin’ uh?' ) );
            }

            // 4) Verify the nonce for this slug.
            $nonce     = isset( $_REQUEST['_wpnonce'] ) ? wp_unslash( $_REQUEST['_wpnonce'] ) : '';
            $checkname = 'uupd_manual_check_' . $slug;
            if ( ! wp_verify_nonce( $nonce, $checkname ) ) {
                wp_die( __( 'Security check failed.' ) );
            }

            // 5) It’s our plugin’s “manual check,” so clear the transient and force WP to fetch again.
            delete_transient( 'upd_' . $slug );

            if ( ! empty( $config['plugin_file'] ) ) {
                wp_update_plugins();
                $redirect = wp_get_referer() ?: admin_url( 'plugins.php' );
            } else {
                wp_update_themes();
                $redirect = wp_get_referer() ?: admin_url( 'themes.php' );
            }

            wp_safe_redirect( $redirect );
            exit;
        } );

        }
    }
}
