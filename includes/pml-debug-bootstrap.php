<?php
/**
 * Debug utilities for headless and standard execution contexts.
 *
 * @package AccessLens
 */

// Exit if accessed directly without the handler opt-in.
if ( ! defined( 'ABSPATH' ) && ! defined( 'PML_ALLOW_DIRECT' ) ) {
    exit;
}

if ( ! function_exists( 'pml_debug_mode_is_enabled' ) ) {
    /**
     * Determines whether WordPress debug mode is currently active.
     *
     * @return bool
     */
    function pml_debug_mode_is_enabled(): bool {
        return defined( 'WP_DEBUG' ) && WP_DEBUG;
    }
}

if ( ! function_exists( 'pml_get_debug_notice_cookie_name' ) ) {
    /**
     * Returns the cookie key used to store dismissals of the debug notice.
     *
     * @return string
     */
    function pml_get_debug_notice_cookie_name(): string {
        return 'pml_debug_notice_dismissed';
    }
}

if ( ! function_exists( 'pml_is_debug_notice_dismissed' ) ) {
    /**
     * Checks whether the debug mode notice has been dismissed for the session.
     *
     * @return bool
     */
    function pml_is_debug_notice_dismissed(): bool {
        return isset( $_COOKIE[ pml_get_debug_notice_cookie_name() ] );
    }
}

if ( ! function_exists( 'pml_ensure_wp_kses_loaded' ) ) {
    /**
     * Guarantees that wp_kses helpers are available in both production and debug contexts.
     */
    function pml_ensure_wp_kses_loaded(): void {
        static $pml_kses_loaded = false;

        if ( $pml_kses_loaded ) {
            return;
        }

        if ( function_exists( 'wp_kses' ) ) {
            $pml_kses_loaded = true;
            return;
        }

        if ( pml_debug_mode_is_enabled() ) {
            require_once ABSPATH . WPINC . '/formatting.php';
            require_once ABSPATH . WPINC . '/kses.php';
        } else {
            if ( ! function_exists( 'wp_kses' ) ) {
                /**
                 * Minimal wp_kses stand-in for headless requests.
                 *
                 * @param mixed $string            String to sanitize.
                 * @param array $allowed_html      Ignored in the stub.
                 * @param array $allowed_protocols Ignored in the stub.
                 *
                 * @return string
                 */
                function wp_kses( $string, $allowed_html = array(), $allowed_protocols = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
                    unset( $allowed_html, $allowed_protocols );

                    return strip_tags( is_string( $string ) ? $string : (string) $string );
                }
            }

            if ( ! function_exists( 'wp_kses_post' ) ) {
                /**
                 * Minimal wp_kses_post stand-in for headless requests.
                 *
                 * @param mixed $string String to sanitize.
                 *
                 * @return string
                 */
                function wp_kses_post( $string ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
                    return wp_kses( $string );
                }
            }

            if ( ! function_exists( 'wp_kses_data' ) ) {
                /**
                 * Minimal wp_kses_data stand-in for headless requests.
                 *
                 * @param mixed $string String to sanitize.
                 *
                 * @return string
                 */
                function wp_kses_data( $string ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
                    return wp_kses( $string );
                }
            }
        }

        $pml_kses_loaded = true;
    }
}

if ( ! function_exists( 'pml_register_debug_mode_notice_hooks' ) ) {
    /**
     * Hooks the debug mode notice into the WordPress admin when appropriate.
     */
    function pml_register_debug_mode_notice_hooks(): void {
        static $pml_notice_hooks_registered = false;

        if ( $pml_notice_hooks_registered ) {
            return;
        }

        if ( ! function_exists( 'add_action' ) ) {
            return;
        }

        if ( defined( 'SHORTINIT' ) && SHORTINIT ) {
            return;
        }

        if ( ! pml_debug_mode_is_enabled() ) {
            return;
        }

        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return;
        }

        if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }

        if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
            return;
        }

        if ( pml_is_debug_notice_dismissed() ) {
            return;
        }

        add_action( 'admin_notices', 'pml_debug_mode_admin_notice' );
        add_action( 'admin_footer', 'pml_debug_mode_admin_notice_script' );

        $pml_notice_hooks_registered = true;
    }
}

if ( ! function_exists( 'pml_debug_mode_admin_notice' ) ) {
    /**
     * Renders the debug mode admin notice.
     */
    function pml_debug_mode_admin_notice(): void {
        if ( pml_is_debug_notice_dismissed() ) {
            return;
        }

        $plugin_name = defined( 'PML_PLUGIN_NAME' ) ? PML_PLUGIN_NAME : 'Access Lens';

        $message = sprintf(
            /* translators: %s: Plugin name. */
            esc_html__( '%s detected that WordPress debug mode is enabled. Debug mode can slow down your site; disable it when not troubleshooting to maintain optimal SEO performance.', 'access-lens-protected-media-links' ),
            esc_html( $plugin_name )
        );
        ?>
        <div class="notice notice-warning is-dismissible pml-debug-mode-notice">
            <p><?php echo $message; ?></p>
        </div>
        <?php
    }
}

if ( ! function_exists( 'pml_debug_mode_admin_notice_script' ) ) {
    /**
     * Outputs the JavaScript required to dismiss the debug mode notice per session.
     */
    function pml_debug_mode_admin_notice_script(): void {
        if ( pml_is_debug_notice_dismissed() ) {
            return;
        }

        $cookie_name      = pml_get_debug_notice_cookie_name();
        $cookie_js_value  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $cookie_name ) : "'" . addslashes( $cookie_name ) . "'";
        $cookie_attributes = function_exists( 'wp_json_encode' ) ? wp_json_encode( 'path=/' ) : "'path=/'";
        ?>
        <script>
        ( function ( window, document ) {
            if ( ! document || ! window ) {
                return;
            }

            var cookieKey = <?php echo $cookie_js_value; ?>;
            var cookieAttributes = <?php echo $cookie_attributes; ?>;

            document.addEventListener( 'click', function ( event ) {
                if ( ! event ) {
                    return;
                }

                var target = event.target;

                if ( ! target ) {
                    return;
                }

                if ( ! target.classList || ! target.classList.contains( 'notice-dismiss' ) ) {
                    return;
                }

                var notice = target.closest ? target.closest( '.pml-debug-mode-notice' ) : null;

                if ( ! notice ) {
                    return;
                }

                document.cookie = cookieKey + '=1; ' + cookieAttributes + ';';
            } );
        }( window, document ) );
        </script>
        <?php
    }
}

if ( ! function_exists( 'pml_bootstrap_debug_utilities' ) ) {
    /**
     * Ensures debug utilities are initialized for the current request lifecycle.
     */
    function pml_bootstrap_debug_utilities(): void {
        pml_ensure_wp_kses_loaded();
        pml_register_debug_mode_notice_hooks();
    }
}
