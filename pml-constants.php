<?php
// Shared plugin constants for Protected Media Links.

const PML_PLUGIN_NAME     = 'Protected Media Links';
const PML_PLUGIN_SLUG     = 'protected-media-links';
const PML_TEXT_DOMAIN     = 'protected-media-links';
const PML_PREFIX          = 'pml';
const PML_VERSION         = '1.1.0';
const PML_DB_VERSION      = '1.0.0';
const PML_MIN_WP_VERSION  = '5.8';
const PML_MIN_PHP_VERSION = '7.4';
const PML_PLUGIN_FILE     = __DIR__ . '/protected-media-links.php';
define( 'PML_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PML_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
const PML_SELECT2_VERSION = '4.0.13';

// Prefix path for Nginx or LiteSpeed internal redirects.
// Define this in wp-config.php to enable X-Accel-Redirect or X-LiteSpeed-Location.
if ( ! defined( 'PML_INTERNAL_REDIRECT_PREFIX' ) ) {
    define( 'PML_INTERNAL_REDIRECT_PREFIX', '' );
}
