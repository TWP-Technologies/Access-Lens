<?php
/**
 * Headless helper functions for database access.
 *
 * @package AccessLens
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Sanitizes a single path segment without altering case.
 * Strips directory traversal and disallowed characters.
 *
 * @param string $segment Raw path segment.
 *
 * @return string Sanitized segment.
 *
 * @note can be used as an alternative to sanitize_file_name if needed
 */
function pml_headless_clean_segment( string $segment ): string
{
    $segment = str_replace( [ "\0", '\\' ], '', $segment );
    $segment = basename( $segment );

    if ( $segment === '.' || $segment === '..' )
    {
        return '';
    }

    return str_replace( '/', '', preg_replace( '/[^A-Za-z0-9._-]/u', '', $segment ) );
}

/**
 * Retrieves a WordPress option value with static caching.
 *
 * @param string $option_name The option name.
 * @param mixed  $default     Default value if the option does not exist.
 * @param wpdb   $wpdb        WordPress database object.
 *
 * @return mixed
 */
function pml_headless_get_option( string $option_name, $default, wpdb $wpdb )
{
    static $cache = [];
    if ( array_key_exists( $option_name, $cache ) )
    {
        return $cache[ $option_name ];
    }

    $sql   = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name );
    $value = $wpdb->get_var( $sql );

    if ( is_null( $value ) )
    {
        $cache[ $option_name ] = $default;
        return $default;
    }

    $cache[ $option_name ] = maybe_unserialize( $value );
    return $cache[ $option_name ];
}

/**
 * Retrieves all Access Lens metadata for a post.
 *
 * @param int  $post_id Post ID.
 * @param wpdb $wpdb    WordPress database object.
 *
 * @return array Associative array of meta values.
 */
function pml_headless_get_pml_meta( int $post_id, wpdb $wpdb ): array
{
    static $cache = [];
    if ( isset( $cache[ $post_id ] ) )
    {
        return $cache[ $post_id ];
    }

    $sql      = $wpdb->prepare(
        "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key LIKE %s",
        $post_id,
        $wpdb->esc_like( '_pml_' ) . '%',
    );
    $metadata = $wpdb->get_results( $sql, ARRAY_A );

    $result = [];
    foreach ( $metadata as $row )
    {
        $key            = substr( $row[ 'meta_key' ], 1 );
        $result[ $key ] = maybe_unserialize( $row[ 'meta_value' ] );
    }

    $cache[ $post_id ] = $result;
    return $result;
}

/**
 * Retrieves the attachment ID for a given uploads-relative path.
 *
 * @param string $relative_path Path relative to uploads directory.
 * @param wpdb   $wpdb          WordPress database object.
 *
 * @return int Attachment ID if found, otherwise 0.
 */
function pml_headless_get_attachment_id_from_path( string $relative_path, wpdb $wpdb ): int
{
    static $cache = [];
    if ( isset( $cache[ $relative_path ] ) )
    {
        return (int)$cache[ $relative_path ];
    }

    $sql = $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
        $relative_path,
    );
    $id  = (int)$wpdb->get_var( $sql );

    $cache[ $relative_path ] = $id;
    return $id;
}

/**
 * Retrieves a cached value from the options table.
 * Returns false if the value does not exist or is expired.
 *
 * @param string $cache_key Cache key.
 * @param wpdb   $wpdb      WordPress database object.
 *
 * @return mixed|false Cached value or false when not found/expired.
 */
function pml_headless_get_cache( string $cache_key, wpdb $wpdb )
{
    static $cache = [];
    if ( array_key_exists( $cache_key, $cache ) )
    {
        return $cache[ $cache_key ];
    }

    $sql = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $cache_key );
    $raw = $wpdb->get_var( $sql );

    if ( is_null( $raw ) )
    {
        $cache[ $cache_key ] = false;
        return false;
    }

    $data = maybe_unserialize( $raw );
    if ( !is_array( $data ) || !isset( $data[ 'value' ], $data[ 'expires_at' ] ) )
    {
        $cache[ $cache_key ] = false;
        return false;
    }

    if ( (int)$data[ 'expires_at' ] < time() )
    {
        $wpdb->delete( $wpdb->options, [ 'option_name' => $cache_key ] );
        $cache[ $cache_key ] = false;
        return false;
    }

    $cache[ $cache_key ] = $data[ 'value' ];
    return $data[ 'value' ];
}

/**
 * Stores a cached value in the options table.
 *
 * @param string $cache_key  Cache key.
 * @param mixed  $value      Value to store.
 * @param int    $expiration Expiration in seconds.
 * @param wpdb   $wpdb       WordPress database object.
 */
function pml_headless_set_cache( string $cache_key, $value, int $expiration, wpdb $wpdb ): void
{
    $expires_at = time() + max( 0, $expiration );
    $data       = maybe_serialize( [ 'value' => $value, 'expires_at' => $expires_at ] );

    $wpdb->query(
        $wpdb->prepare(
            "REPLACE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $cache_key,
            $data,
        ),
    );
}

/**
 * Retrieves upload directory information in a headless context.
 * This is a lightweight, SHORTINIT-safe replacement for wp_upload_dir().
 *
 * @param wpdb $wpdb WordPress database object.
 *
 * @return array{'basedir': string, 'baseurl': string} Information about the upload directory.
 */
function pml_headless_get_upload_dir( wpdb $wpdb ): array
{
    $siteurl     = pml_headless_get_option( 'siteurl', '', $wpdb );
    $upload_path = trim( pml_headless_get_option( 'upload_path', 'wp-content/uploads', $wpdb ) );

    if ( empty( $upload_path ) || 'wp-content/uploads' === $upload_path )
    {
        $basedir = WP_CONTENT_DIR . '/uploads';
        $baseurl = WP_CONTENT_URL . '/uploads';
    }
    elseif ( 0 === strpos( $upload_path, ABSPATH ) )
    {
        $basedir = $upload_path;
        $baseurl = str_replace( ABSPATH, $siteurl . '/', $upload_path );
    }
    else
    {
        $basedir = ABSPATH . $upload_path;
        $baseurl = $siteurl . '/' . $upload_path;
    }

    return [
        'basedir' => $basedir,
        'baseurl' => $baseurl,
    ];
}

/**
 * Defines critical WordPress directory and URL constants if not already defined.
 * This function is an adaptation of wp_plugin_directory_constants() for a
 * headless/SHORTINIT environment. It relies on pml_headless_get_option for 'siteurl'
 * and expects ABSPATH to be defined.
 *
 * @param wpdb $wpdb WordPress database object.
 */
function pml_headless_define_constants( wpdb $wpdb )
{
    // Define WP_CONTENT_URL if not already defined.
    // This constant conventionally does not have a trailing slash.
    if ( !defined( 'WP_CONTENT_URL' ) )
    {
        $siteurl = pml_headless_get_option( 'siteurl', '', $wpdb );
        if ( !empty( $siteurl ) )
        {
            define( 'WP_CONTENT_URL', rtrim( $siteurl, '/' ) . '/wp-content' );
        }
    }

    // Define WP_CONTENT_DIR if not already defined.
    // This constant conventionally does not have a trailing slash.
    // It's highly likely to be defined in wp-config.php if customized.
    // This provides a fallback based on ABSPATH, assuming a standard structure.
    if ( !defined( 'WP_CONTENT_DIR' ) )
    {
        if ( defined( 'ABSPATH' ) )
        {
            define( 'WP_CONTENT_DIR', rtrim( ABSPATH, '/\\' ) . '/wp-content' );
        }
    }

    // Define plugin and mu-plugin directories and URLs if their base constants are set.
    // These constants also conventionally do not have trailing slashes.
    if ( defined( 'WP_CONTENT_DIR' ) )
    {
        if ( !defined( 'WP_PLUGIN_DIR' ) )
        {
            define( 'WP_PLUGIN_DIR', rtrim( WP_CONTENT_DIR, '/\\' ) . '/plugins' );
        }
        if ( !defined( 'WPMU_PLUGIN_DIR' ) )
        {
            define( 'WPMU_PLUGIN_DIR', rtrim( WP_CONTENT_DIR, '/\\' ) . '/mu-plugins' );
        }
    }

    if ( defined( 'WP_CONTENT_URL' ) )
    {
        if ( !defined( 'WP_PLUGIN_URL' ) )
        {
            define( 'WP_PLUGIN_URL', rtrim( WP_CONTENT_URL, '/' ) . '/plugins' );
        }
        if ( !defined( 'WPMU_PLUGIN_URL' ) )
        {
            define( 'WPMU_PLUGIN_URL', rtrim( WP_CONTENT_URL, '/' ) . '/mu-plugins' );
        }
    }

    // Define deprecated relative path constants.
    // These are string literals, by convention relative to ABSPATH.
    if ( !defined( 'PLUGINDIR' ) )
    {
        define( 'PLUGINDIR', 'wp-content/plugins' );
    }
    if ( !defined( 'MUPLUGINDIR' ) )
    {
        define( 'MUPLUGINDIR', 'wp-content/mu-plugins' );
    }
}

if ( !function_exists( '__' ) )
{
    /**
     * Dummy translation function for headless context.
     * This is a placeholder to avoid errors when translation functions are called.
     *
     * @param string $text Text to translate.
     * @param array  $args Optional arguments for translation.
     *
     * @return string The original text.
     */
    function __( string $text, array $args = [] ): string
    {
        return $text;
    }
}