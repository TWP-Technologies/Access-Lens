<?php
if ( !defined( 'ABSPATH' ) && !defined( 'PML_ALLOW_DIRECT' ) )
{
    exit;
}
// Shared plugin constants for Access Lens.

const PML_PLUGIN_NAME     = 'Access Lens';
const PML_PLUGIN_SLUG     = 'protected-media-links';
const PML_PREFIX          = 'pml';
const PML_VERSION         = '1.1.0';
const PML_MIN_WP_VERSION  = '5.9';
const PML_MIN_PHP_VERSION = '7.4';
const PML_PLUGIN_FILE     = __DIR__ . '/protected-media-links.php';
//define( 'PML_PLUGIN_DIR', pml_plugin_dir_path() ); // defined at the end of this file
//define( 'PML_PLUGIN_URL', pml_plugin_dir_url() );  // defined at the end of this file
const PML_SELECT2_VERSION        = '4.0.13';
const PML_FLATPICKR_VERSION      = '4.6.13';
const PML_SELECT2_HANDLE         = 'pml-select2';
const PML_FLATPICKR_HANDLE       = 'pml-flatpickr';

// Prefix path for Nginx or LiteSpeed internal redirects.
// Define this in wp-config.php to enable X-Accel-Redirect or X-LiteSpeed-Location.
if ( !defined( 'PML_INTERNAL_REDIRECT_PREFIX' ) )
{
    define( 'PML_INTERNAL_REDIRECT_PREFIX', '' );
}

if ( !function_exists( 'pml_plugin_dir_path' ) )
{
    /**
     * Get the absolute path to the plugin directory.
     *
     * @return string Absolute path to the plugin directory.
     */
    function pml_plugin_dir_path()
    {
        // PML_PLUGIN_FILE is defined relative to this file's directory (__DIR__)
        // So dirname(PML_PLUGIN_FILE) gives the plugin's root directory path.
        return rtrim( dirname( PML_PLUGIN_FILE ), '/\\' ) . DIRECTORY_SEPARATOR;
    }
}

if ( !function_exists( 'pml_is_admin' ) )
{
    /**
     * Check if the current request is in the admin area.
     *
     * @return bool True if in admin area, false otherwise.
     */
    function pml_is_admin()
    {
        if ( isset( $GLOBALS[ 'current_screen' ] ) )
        {
            return $GLOBALS[ 'current_screen' ]->in_admin();
        }
        elseif ( defined( 'WP_ADMIN' ) )
        {
            return WP_ADMIN;
        }

        return false;
    }
}

if ( !function_exists( 'pml_plugin_dir_url' ) )
{
    /**
     * Get the URL to the plugin directory.
     * This implementation is designed for a minimal WordPress environment (e.g., SHORTINIT)
     * where standard URL functions might not be fully available or reliable due to
     * limited loaded dependencies. It constructs the URL based on ABSPATH, PML_PLUGIN_DIR,
     * and functions available in wp-includes/functions.php.
     *
     * @return string URL to the plugin directory, or empty string on failure.
     */
    function pml_plugin_dir_url()
    {
        if ( !defined( 'PML_PLUGIN_DIR' ) || !defined( 'ABSPATH' ) )
        {
            // Essential constants are missing.
            if ( pml_is_admin() || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) )
            {
                error_log( 'PML Error: PML_PLUGIN_DIR or ABSPATH not defined in pml_plugin_dir_url.' );
            }
            return '';
        }

        // PML_PLUGIN_DIR is the absolute path to the plugin directory, with a trailing slash.
        // Example: /var/www/html/wp-content/plugins/protected-media-links/
        $plugin_dir = PML_PLUGIN_DIR ?: pml_plugin_dir_path();

        // ABSPATH is the absolute path to the WordPress directory, usually with a trailing slash.
        // Example: /var/www/html/
        $base_path = ABSPATH;

        // Normalize paths for reliable string operations.
        // wp_normalize_path is available via wp-includes/functions.php
        $normalized_plugin_dir = wp_normalize_path( $plugin_dir );
        $normalized_base_path  = wp_normalize_path( $base_path );

        // Ensure the plugin directory is within the WordPress base path.
        // strpos() is used for prefix checking.
        if ( strpos( $normalized_plugin_dir, $normalized_base_path ) !== 0 )
        {
            // The plugin directory does not appear to be inside the WordPress installation path.
            if ( pml_is_admin() || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) )
            {
                error_log( 'PML Error: Plugin directory seems to be outside ABSPATH in pml_plugin_dir_url.' );
            }
            return '';
        }

        // Calculate the relative path of the plugin from the WordPress root.
        // substr() removes the base path part from the plugin directory path.
        $relative_plugin_path = substr( $normalized_plugin_dir, strlen( $normalized_base_path ) );
        // $relative_plugin_path will be like 'wp-content/plugins/protected-media-links/' (if ABSPATH ends with /)

        // Get the base URL for the WordPress installation.
        // wp_guess_url() is available in wp-includes/functions.php
        $site_url = wp_guess_url();

        // Combine the site URL with the relative plugin path.
        // trailingslashit() ensures $site_url ends with a slash.
        // ltrim() on $relative_plugin_path prevents double slashes if $relative_plugin_path somehow started with one.
        $url = trailingslashit( $site_url ) . ltrim( $relative_plugin_path, '/' );

        // Ensure the URL has the correct scheme (http/https).
        // set_url_scheme() is available in wp-includes/functions.php
        $url = set_url_scheme( $url );

        // The $url should now correctly represent the plugin directory URL with a trailing slash.
        return $url;
    }
}

define( 'PML_PLUGIN_DIR', pml_plugin_dir_path() );
define( 'PML_PLUGIN_URL', pml_plugin_dir_url() );