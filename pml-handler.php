<?php
// PML Headless File Handler
// Bypasses full WordPress load for faster protected file serving.

// --- Phase 1: Minimal Bootstrap ---
if ( ! defined( 'PML_ALLOW_DIRECT' ) ) {
    define( 'PML_ALLOW_DIRECT', true );
}
require_once dirname( __FILE__ ) . '/pml-constants.php';
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
require_once ABSPATH . WPINC . '/compat.php';

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
require_once __DIR__ . "/includes/pml-headless-sanitization.php";
require_once __DIR__ . '/includes/pml-headless-helpers.php';
require_once __DIR__ . '/includes/class-pml-headless-auth.php';
require_once __DIR__ . '/includes/class-token-manager.php';
require_once __DIR__ . '/includes/utilities/bot-detector.php';

// Define Extra WP constants if not already defined.
pml_headless_define_constants( $wpdb );
wp_cookie_constants();

$token_manager = new PML_Token_Manager( $wpdb );
$bot_detector  = new PML_Bot_Detector( $wpdb );

// --- Phase 2: Input Sanitization & File Validation ---
$request_param = isset( $_GET['pml_media_request'] ) ? $_GET['pml_media_request'] : '';
$request_raw   = is_string( $request_param ) ? sanitize_text_field( $request_param ) : '';

$access_token_param = isset( $_GET['access_token'] ) ? $_GET['access_token'] : null;
$access_token       = is_string( $access_token_param ) ? sanitize_text_field( $access_token_param ) : null;

$remote_addr_raw           = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
$pml_sanitized_remote_addr = 'UNKNOWN';
if ( is_string( $remote_addr_raw ) && '' !== $remote_addr_raw ) {
    $validated_ip = filter_var( $remote_addr_raw, FILTER_VALIDATE_IP );
    if ( false !== $validated_ip ) {
        $pml_sanitized_remote_addr = $validated_ip;
    }
}

$server_software_raw           = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';
$pml_sanitized_server_software = is_string( $server_software_raw ) ? sanitize_text_field( $server_software_raw ) : '';
        serve_file( $real_file, 'Unmanaged Public File', $pml_sanitized_remote_addr, $pml_sanitized_server_software );
    }
    deny_access( null, 'unmanaged_restricted', $pml_sanitized_remote_addr );
}

$pml_meta = pml_headless_get_pml_meta( $attachment_id, $wpdb );
if ( empty( $pml_meta['pml_is_protected'] ) ) {
    serve_file( $real_file, 'Unmanaged Public File', $pml_sanitized_remote_addr, $pml_sanitized_server_software );
}

if ( $access_token ) {
    $token_status = $token_manager->validate_token( $access_token, $attachment_id );
    if ( 'valid' === $token_status && $token_manager->record_token_usage( $access_token ) ) {
        serve_file( $real_file, 'Valid Token', $pml_sanitized_remote_addr, $pml_sanitized_server_software );
    }
    if ( in_array( $token_status, [ 'expired', 'used_limit_reached' ], true ) ) {
        $token_manager->update_token_fields( $access_token, [ 'status' => $token_status ], [ '%s' ] );
    }
}

$auth        = new PML_Headless_Auth( $wpdb );
$current_user = $auth->get_current_user();
if ( $current_user && is_access_granted_by_user_role( $current_user, $pml_meta, $wpdb ) ) {
    serve_file( $real_file, 'Access Granted by User/Role Rule', $pml_sanitized_remote_addr, $pml_sanitized_server_software );
}

$bot = $bot_detector;
if ( $bot->is_verified_bot() ) {
    serve_file( $real_file, 'Verified Bot', $pml_sanitized_remote_addr, $pml_sanitized_server_software );
}

deny_access( $pml_meta, 'restricted_default', $pml_sanitized_remote_addr );

// --- Helper Functions ---
function serve_file( string $file_path, string $reason = 'Unknown', string $client_ip = 'UNKNOWN', string $server_software = '' ): void {
    // Log successful access for debugging and auditing purposes.
    $log_ip      = $client_ip ?: 'UNKNOWN';
    $log_message = sprintf(
        "[PML Access Granted] Served '%s' to IP %s. Reason: %s",
        basename( $file_path ),
        $log_ip,
        $reason
    );
    error_log( $log_message );

    // Add security and cache-control headers to prevent caching of tokenized links.
    // This is critical for enforcing use limits and expiry.
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Pragma: no-cache' );
    header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Frame-Options: SAMEORIGIN' );
    if ( ob_get_level() ) {
        @ob_end_clean();
    }
    $server = $server_software;
    if ( stripos( $server, 'nginx' ) !== false || stripos( $server, 'litespeed' ) !== false ) {
        $internal = defined( 'PML_INTERNAL_REDIRECT_PREFIX' ) ? trim( PML_INTERNAL_REDIRECT_PREFIX ) : '';
        if ( $internal ) {
            $upload_dir   = pml_headless_get_upload_dir( $GLOBALS['wpdb'] );
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

function deny_access( ?array $pml_meta, string $slug, string $client_ip = 'UNKNOWN' ): void {
    // Log denied access for debugging and auditing purposes.
    $log_ip      = $client_ip ?: 'UNKNOWN';
    $log_message = sprintf( '[PML Access Denied] Denied access for IP %s. Reason Code: %s', $log_ip, $slug );
    error_log( $log_message );

    $home_url_fallback = pml_headless_get_option( 'home', '/', $GLOBALS['wpdb'] );
    $default_url       = pml_headless_get_option( PML_PREFIX . '_settings_default_redirect_url', $home_url_fallback, $GLOBALS['wpdb'] );
    if ( $pml_meta && ! empty( $pml_meta['pml_redirect_url'] ) ) {
        $default_url = $pml_meta['pml_redirect_url'];
    }
    $redirect = $default_url ? pml_headless_sanitize_location( $default_url ) : '/';
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

