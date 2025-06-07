<?php
// PML Headless File Handler
// Bypasses full WordPress load for faster protected file serving.

// --- Phase 1: Minimal Bootstrap ---
define( 'SHORTINIT', true );

// Locate wp-config.php from typical plugin directory structures.
$wp_config_path = dirname( __FILE__, 3 ) . '/wp-config.php';
if ( ! file_exists( $wp_config_path ) ) {
    $wp_config_path = dirname( __FILE__, 4 ) . '/wp-config.php';
}
if ( ! file_exists( $wp_config_path ) ) {
    http_response_code( 503 );
    error_log( 'PML Handler: Could not locate wp-config.php.' );
    exit( 'Configuration error.' );
}
require_once $wp_config_path;

require_once ABSPATH . WPINC . '/functions.php';
require_once ABSPATH . WPINC . '/class-wp-error.php';
require_once ABSPATH . WPINC . '/plugin.php';
require_once ABSPATH . WPINC . '/wp-db.php';
require_once ABSPATH . WPINC . '/pluggable.php';

// Instantiate $wpdb when not provided by wp-config.php.
if ( ! isset( $wpdb ) ) {
    $wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
}

// Provide a minimal sanitize_text_field replacement if not loaded.
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        $filtered = is_string( $str ) ? $str : '';
        $filtered = trim( preg_replace( '/[\r\n\t]+/', ' ', $filtered ) );
        $filtered = strip_tags( $filtered );
        return $filtered;
    }
}

// Load plugin helpers.
require_once __DIR__ . '/includes/pml-headless-helpers.php';
require_once __DIR__ . '/includes/class-pml-headless-auth.php';
require_once __DIR__ . '/includes/class-token-manager.php';
require_once __DIR__ . '/includes/utilities/bot-detector.php';

// --- Phase 2: Input Sanitization & File Validation ---
$request_raw = isset( $_GET['pml_media_request'] ) ? $_GET['pml_media_request'] : '';
$access_token = isset( $_GET['access_token'] ) ? sanitize_text_field( $_GET['access_token'] ) : null;

$path_segments = array_map( 'sanitize_file_name', explode( '/', $request_raw ) );
$relative_path = implode( '/', array_filter( $path_segments ) );

$upload_dir = wp_upload_dir();
$full_path  = trailingslashit( $upload_dir['basedir'] ) . $relative_path;

$real_base = realpath( $upload_dir['basedir'] );
$real_file = realpath( $full_path );
if ( false === $real_file || strpos( $real_file, $real_base ) !== 0 || ! is_readable( $real_file ) ) {
    deny_access( null, 'invalid_path' );
}

// --- Phase 3: Access Control ---
$attachment_id = pml_headless_get_attachment_id_from_path( $relative_path, $wpdb );
if ( ! $attachment_id ) {
    $handle_unmanaged = pml_headless_get_option( PML_PREFIX . '_settings_handle_unmanaged_files', 'serve_publicly', $wpdb );
    if ( 'serve_publicly' === $handle_unmanaged ) {
        serve_file( $real_file );
    }
    deny_access( null, 'unmanaged_restricted' );
}

$pml_meta = pml_headless_get_pml_meta( $attachment_id, $wpdb );
if ( empty( $pml_meta['pml_is_protected'] ) ) {
    serve_file( $real_file );
}

if ( $access_token ) {
    $token_status = PML_Token_Manager::validate_token( $access_token, $attachment_id );
    if ( 'valid' === $token_status && PML_Token_Manager::record_token_usage( $access_token ) ) {
        serve_file( $real_file );
    }
    if ( in_array( $token_status, [ 'expired', 'used_limit_reached' ], true ) ) {
        PML_Token_Manager::update_token_fields( $access_token, [ 'status' => $token_status ], [ '%s' ] );
    }
}

$auth        = new PML_Headless_Auth( $wpdb );
$current_user = $auth->get_current_user();
if ( $current_user && is_access_granted_by_user_role( $current_user, $pml_meta, $wpdb ) ) {
    serve_file( $real_file );
}

$bot = new PML_Bot_Detector();
if ( $bot::is_verified_bot() ) {
    serve_file( $real_file );
}

deny_access( $pml_meta, 'restricted_default' );

// --- Helper Functions ---
function serve_file( string $file_path ): void {
    if ( ob_get_level() ) {
        @ob_end_clean();
    }
    $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
    if ( stripos( $server, 'nginx' ) !== false || stripos( $server, 'litespeed' ) !== false ) {
        $internal = '';
        if ( $internal ) {
            $upload_dir   = wp_upload_dir();
            $rel_path     = str_replace( trailingslashit( $upload_dir['basedir'] ), '', $file_path );
            $redirect     = trailingslashit( rtrim( $internal, '/' ) ) . $rel_path;
            if ( stripos( $server, 'nginx' ) !== false ) {
                header( 'X-Accel-Redirect: ' . $redirect );
            } else {
                header( 'X-LiteSpeed-Location: ' . $redirect );
            }
            exit;
        }
    }
    if ( strpos( $server, 'Apache' ) !== false && function_exists( 'apache_get_modules' ) && in_array( 'mod_xsendfile', apache_get_modules(), true ) ) {
        header( 'X-Sendfile: ' . $file_path );
        exit;
    }
    header( 'Content-Type: ' . ( mime_content_type( $file_path ) ?: 'application/octet-stream' ) );
    header( 'Content-Length: ' . filesize( $file_path ) );
    readfile( $file_path );
    exit;
}

function deny_access( ?array $pml_meta, string $slug ): void {
    $default_url = pml_headless_get_option( PML_PREFIX . '_settings_default_redirect_url', home_url( '/' ), $GLOBALS['wpdb'] );
    if ( $pml_meta && ! empty( $pml_meta['pml_redirect_url'] ) ) {
        $default_url = $pml_meta['pml_redirect_url'];
    }
    $redirect = $default_url ? $default_url : '/';
    if ( ! headers_sent() ) {
        header( 'Location: ' . $redirect );
    }
    exit;
}

function is_access_granted_by_user_role( object $user, array $pml_meta, wpdb $wpdb ): bool {
    $global_user_allow = pml_headless_get_option( PML_PREFIX . '_global_user_allow_list', [], $wpdb );
    if ( in_array( $user->id, (array) $global_user_allow, true ) ) {
        return true;
    }
    $global_user_deny = pml_headless_get_option( PML_PREFIX . '_global_user_deny_list', [], $wpdb );
    if ( in_array( $user->id, (array) $global_user_deny, true ) ) {
        return false;
    }
    if ( ! empty( $pml_meta['pml_user_allow_list'] ) && in_array( $user->id, (array) $pml_meta['pml_user_allow_list'], true ) ) {
        return true;
    }
    if ( ! empty( $pml_meta['pml_user_deny_list'] ) && in_array( $user->id, (array) $pml_meta['pml_user_deny_list'], true ) ) {
        return false;
    }
    $global_role_allow = pml_headless_get_option( PML_PREFIX . '_global_role_allow_list', [], $wpdb );
    if ( ! empty( array_intersect( $user->roles, (array) $global_role_allow ) ) ) {
        return true;
    }
    $global_role_deny = pml_headless_get_option( PML_PREFIX . '_global_role_deny_list', [], $wpdb );
    if ( ! empty( array_intersect( $user->roles, (array) $global_role_deny ) ) ) {
        return false;
    }
    if ( ! empty( $pml_meta['pml_role_allow_list'] ) && ! empty( array_intersect( $user->roles, (array) $pml_meta['pml_role_allow_list'] ) ) ) {
        return true;
    }
    if ( ! empty( $pml_meta['pml_role_deny_list'] ) && ! empty( array_intersect( $user->roles, (array) $pml_meta['pml_role_deny_list'] ) ) ) {
        return false;
    }
    return false;
}

