<?php
/**
 * Headless helper functions for database access.
 *
 * @package ProtectedMediaLinks
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) )
{
    exit;
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
 * Retrieves all Protected Media Links metadata for a post.
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
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
        $post_id,
        $wpdb->esc_like( '_pml_' ) . '%'
    );
    $metadata = $wpdb->get_results( $sql, ARRAY_A );

    $result = [];
    foreach ( $metadata as $row )
    {
        $key          = substr( $row['meta_key'], 1 );
        $result[ $key ] = maybe_unserialize( $row['meta_value'] );
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
        return (int) $cache[ $relative_path ];
    }

    $sql  = $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
        $relative_path
    );
    $id   = (int) $wpdb->get_var( $sql );

    $cache[ $relative_path ] = $id;
    return $id;
}
