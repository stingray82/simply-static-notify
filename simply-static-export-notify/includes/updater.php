<?php
/**
 * Universal Updater Drop‑In for Plugins & Themes
 * Encapsulated in UUPD_Updater – safe to include multiple times.
 *
 * Usage:
 *
 *   1) Copy this file to `inc/updater.php` inside your plugin or theme.
 *
 *   2) In a **plugin**, in your main plugin file (e.g. `rup-changelogger.php`), hard‑code your version
 *      and bootstrap on `plugins_loaded` + `admin_init` to avoid text‑domain warnings:
 *
 *        // Define your plugin version
 *        define( 'RUP_PLUGIN_VERSION', '1.0' );
 *
 *      // Register updater early enough for the Updates screen
 *        add_action( 'plugins_loaded', function() {
            $updater_config = [
                'plugin_file' => plugin_basename( __FILE__ ),
                'slug'        => 'rup-changelogger',  // "rup-changelogger"
                'name'        => 'Changelogger',        // "Changelogger"
                'version'     => RUP_PLUGIN_VERSION,     // "1.01"
                'key'         => 'YourSecretKeyHere',
                'server'      => 'https://updater.reallyusefulplugins.com/u/',
            ];
 *            require_once __DIR__ . '/inc/updater.php';
 *            new UUPD_Updater( $updater_config );
 *        } );
 *
 *   3) In a **theme**, in your theme’s `functions.php`, bootstrap on `after_setup_theme` + `admin_init`:
 *
 *        add_action( 'after_setup_theme', function() {
 *            $updater_config = [
 *                'slug'    => 'test-updater-theme',    // match your theme folder & Text Domain
 *                'name'    => 'Test Updater Theme',    // your theme’s human name
 *                'version' => '1.0.0',                 // match your style.css Version header
 *                'key'     => 'YourSecretKeyHere',
 *                'server'  => 'https://updater.reallyusefulplugins.com/u/',
 *            ];
 *            require get_stylesheet_directory() . '/inc/updater.php';
 *            // register update‐checker in time for WP Admin Updates
 *            add_action( 'admin_init', function() use ( $updater_config ) {
 *                new UUPD_Updater( $updater_config );
 *            } );
 *        } );
 *
 *   4) To enable logging of every HTTP call and injection step, add anywhere:
 *
 *        add_filter( 'updater_enable_debug', fn( $e ) => true );
 *
 *   5) In your `wp-config.php`, be sure to turn on WP_DEBUG_LOG:
 *
 *        define( 'WP_DEBUG',     true );
 *        define( 'WP_DEBUG_LOG', true );
 *
 * Now your updater will:
 *  - Fetch `/u/?action=get_metadata&slug={slug}&key={key}` on‐demand
 *  - Cache the JSON for 1 hour
 *  - Inject plugin updates if `plugin_file` is present
 *  - Inject theme updates otherwise
 *  - Populate the “View details” dialogs with your changelog
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
                add_filter( 'plugins_api',                         [ $this, 'plugin_info' ], 10, 3 );
            } else {
                add_filter( 'pre_set_site_transient_update_themes', [ $this, 'theme_update' ] );
                add_filter( 'themes_api',                           [ $this, 'theme_info' ], 10, 3 );
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
            $c      = $this->config;
            $file   = $c['plugin_file'];
            $this->log( "→ Plugin-update hook for '{$c['slug']}'" );
            $current = $trans->checked[ $file ] ?? $c['version'];

            $meta = get_transient( 'upd_' . $c['slug'] );
            if ( false === $meta ) {
                $this->fetch_remote();
                $meta = get_transient( 'upd_' . $c['slug'] );
            }

            if ( ! $meta || version_compare( $meta->version ?? '0.0.0', $current, '<=' ) ) {
                return $trans;
            }

            $this->log( "✓ Injecting plugin update v" . ( $meta->version ?? 'unknown' ) );

            $trans->response[ $file ] = (object) [
                'name'        => $c['name'],
                'slug'        => $c['slug'],
                'new_version' => $meta->version ?? $c['version'],
                'package'     => $meta->download_url ?? '',
                'tested'      => $meta->tested ?? '',
                'requires'    => $meta->requires       ?? $meta->min_wp_version ?? '',
                'sections'    => isset( $meta->sections ) ? (array) $meta->sections : [],
                'icons'       => isset( $meta->icons )    ? (array) $meta->icons    : [],
            ];

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
        'name'             => $c['name'],
        'title'            => $c['name'],                  // Popup title
        'slug'             => $c['slug'],
        'version'          => $meta->version        ?? '',
        'author'           => $meta->author         ?? '',
        'author_homepage'  => $meta->author_homepage ?? '',
        'requires'         => $meta->requires       ?? $meta->min_wp_version ?? '',
        'tested'           => $meta->tested         ?? '',
        'requires_php'     => $meta->requires_php   ?? '',   // “Requires PHP: x.x or higher”
        'last_updated'     => $meta->last_updated   ?? '',
        'download_link'    => $meta->download_url   ?? '',
        'homepage'         => $meta->homepage       ?? '',
        'sections'         => $sections,
        'icons'            => isset( $meta->icons )   ? (array) $meta->icons   : [],
        'banners'          => isset( $meta->banners ) ? (array) $meta->banners : [],
        'screenshots'      => isset( $meta->screenshots ) 
                                 ? (array) $meta->screenshots 
                                 : [],
    ];
}


        /** Handle theme update injection. */
        public function theme_update( $trans ) {
            $c       = $this->config;
            $slug    = $c['slug'];
            $current = $trans->checked[ $slug ] 
                     ?? wp_get_theme( $slug )->get( 'Version' );

            $meta = get_transient( 'upd_' . $slug );
            if ( false === $meta ) {
                $this->fetch_remote();
                $meta = get_transient( 'upd_' . $slug );
            }

            if ( ! $meta || version_compare( $meta->version ?? '0.0.0', $current, '<=' ) ) {
                return $trans;
            }

            $trans->response[ $slug ] = (object) [
                'theme'       => $slug,
                'new_version' => $meta->version ?? $current,
                'package'     => $meta->download_url ?? '',
                'url'         => $meta->homepage ?? '',
            ];

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
    }
}
