<?php
/**
 * File Access Handler for Protected Media (Optimized for Performance)
 * This version includes:
 * - Avenue 1: In-memory caching for database lookups and an optimized check order.
 * - Avenue 2: Server-level file delivery offloading via X-Sendfile/X-Accel-Redirect.
 *
 * @package ProtectedMediaLinks
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class PML_File_Handler
{
    /**
     * @var array In-memory cache for attachment IDs, keyed by file path.
     */
    private static array $attachment_id_cache = [];

    /**
     * @var array In-memory cache for attachment metadata, keyed by attachment ID.
     */
    private static array $attachment_meta_cache = [];

    /**
     * @var array In-memory cache for bot detection results, keyed by IP address.
     */
    private static array $bot_detection_cache = [];

    /**
     * @var array In-memory cache for current user data (ID and roles).
     */
    private static array $user_role_cache = [];

    public function __construct()
    {
        add_action( 'template_redirect', [ $this, 'maybe_handle_file_request' ], 5 );
    }

    public function maybe_handle_file_request()
    {
        $requested_file_path_relative_raw = get_query_var( PML_PREFIX . '_media_request' );
        if ( empty( $requested_file_path_relative_raw ) )
        {
            return;
        }

        $requested_file_path_relative = implode( '/', array_map( 'sanitize_file_name', explode( '/', $requested_file_path_relative_raw ) ) );
        $access_token                 = isset( $_GET[ 'access_token' ] ) ? sanitize_text_field( wp_unslash( $_GET[ 'access_token' ] ) ) : null;
        $uploads_dir                  = wp_get_upload_dir();
        $full_requested_file_path     = trailingslashit( $uploads_dir[ 'basedir' ] ) . $requested_file_path_relative;

        $real_base_dir  = realpath( $uploads_dir[ 'basedir' ] );
        $real_file_path = realpath( $full_requested_file_path );
        if ( false === $real_file_path || strpos( $real_file_path, $real_base_dir ) !== 0 || !is_readable( $real_file_path ) )
        {
            $this->deny_access( null, 'File not found, invalid path, or not readable.', 'invalid_path' );
            return;
        }

        $attachment_id = $this->get_attachment_id_from_path( $requested_file_path_relative, $uploads_dir );

        if ( !$attachment_id )
        {
            $handle_unmanaged_setting = get_option( PML_PREFIX . '_settings_handle_unmanaged_files', 'serve_publicly' );
            if ( 'serve_publicly' === $handle_unmanaged_setting )
            {
                $this->serve_file( $full_requested_file_path, 0, 'unmanaged_public' );
            }
            else
            {
                $this->deny_access( null, 'File not managed by Media Library.', 'unmanaged_restricted' );
            }
            return;
        }

        $pml_meta = $this->get_cached_attachment_meta( $attachment_id );
        if ( empty( $pml_meta[ 'is_protected' ] ) )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'unprotected_library' );
            return;
        }

        if ( !empty( $access_token ) && class_exists( 'PML_Token_Manager' ) )
        {
            $token_status = PML_Token_Manager::validate_token( $access_token, $attachment_id );
            if ( 'valid' === $token_status )
            {
                if ( PML_Token_Manager::record_token_usage( $access_token ) )
                {
                    $this->serve_file( $full_requested_file_path, $attachment_id, 'token_valid' );
                }
                else
                {
                    $this->deny_access( $attachment_id, 'Token usage recording failed.', 'token_usage_error' );
                }

                return;
            }
        }

        if ( $this->is_access_granted_by_user_role( $attachment_id, $pml_meta ) )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'user_role_allow' );
            return;
        }

        if ( $this->is_access_granted_by_bot( $attachment_id, $pml_meta ) )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'bot_access' );
            return;
        }

        if ( !empty( $access_token ) )
        {
            $token_status = PML_Token_Manager::validate_token( $access_token, $attachment_id );
            if ( 'expired' === $token_status || 'used_limit_reached' === $token_status )
            {
                PML_Token_Manager::update_token_fields( $access_token, [ 'status' => $token_status ], [ '%s' ] );
            }
            $reason_map    = [
                'not_found'          => 'Access token not found.',
                'expired'            => 'Access token has expired.',
                'used_limit_reached' => 'Access token has reached its usage limit.',
                'invalid_attachment' => 'Access token is not valid for this file.',
                'revoked'            => 'Access token has been revoked.',
            ];
            $denial_reason = $reason_map[ $token_status ] ?? 'Invalid access token.';
            $this->deny_access( $attachment_id, $denial_reason, 'token_' . $token_status );
        }
        else
        {
            $this->deny_access( $attachment_id, 'Access to this file is restricted.', 'restricted_default' );
        }
    }

    private function get_attachment_id_from_path( string $relative_path, array $uploads_dir ): int
    {
        if ( isset( self::$attachment_id_cache[ $relative_path ] ) )
        {
            return self::$attachment_id_cache[ $relative_path ];
        }
        $file_url                                    = trailingslashit( $uploads_dir[ 'baseurl' ] ) . $relative_path;
        $attachment_id                               = attachment_url_to_postid( $file_url );
        self::$attachment_id_cache[ $relative_path ] = $attachment_id;
        return $attachment_id;
    }

    private function get_cached_attachment_meta( int $attachment_id ): array
    {
        if ( isset( self::$attachment_meta_cache[ $attachment_id ] ) )
        {
            return self::$attachment_meta_cache[ $attachment_id ];
        }
        $all_meta                                      = get_post_meta( $attachment_id );
        $pml_meta                                      = [
            'is_protected'        => $all_meta[ '_' . PML_PREFIX . '_is_protected' ][ 0 ] ?? false,
            'redirect_url'        => $all_meta[ '_' . PML_PREFIX . '_redirect_url' ][ 0 ] ?? null,
            'allow_bots_for_file' => $all_meta[ '_' . PML_PREFIX . '_allow_bots_for_file' ][ 0 ] ?? null,
            'user_allow_list'     => maybe_unserialize( $all_meta[ '_' . PML_PREFIX . '_user_allow_list' ][ 0 ] ?? [] ),
            'user_deny_list'      => maybe_unserialize( $all_meta[ '_' . PML_PREFIX . '_user_deny_list' ][ 0 ] ?? [] ),
            'role_allow_list'     => maybe_unserialize( $all_meta[ '_' . PML_PREFIX . '_role_allow_list' ][ 0 ] ?? [] ),
            'role_deny_list'      => maybe_unserialize( $all_meta[ '_' . PML_PREFIX . '_role_deny_list' ][ 0 ] ?? [] ),
        ];
        self::$attachment_meta_cache[ $attachment_id ] = $pml_meta;
        return $pml_meta;
    }

    private function is_access_granted_by_user_role( int $attachment_id, array $pml_meta ): bool
    {
        if ( empty( self::$user_role_cache ) )
        {
            $user_id               = get_current_user_id();
            self::$user_role_cache = [ 'id' => $user_id, 'roles' => $user_id ? (array)get_userdata( $user_id )->roles : [], ];
        }
        $current_user_id = self::$user_role_cache[ 'id' ];
        if ( !$current_user_id )
        {
            return false;
        }
        $current_user_roles = self::$user_role_cache[ 'roles' ];
        $global_user_allow  = get_option( PML_PREFIX . '_global_user_allow_list', [] );
        if ( in_array( $current_user_id, $global_user_allow ) )
        {
            return true;
        }
        $global_user_deny = get_option( PML_PREFIX . '_global_user_deny_list', [] );
        if ( in_array( $current_user_id, $global_user_deny ) )
        {
            return false;
        }
        if ( !empty( $pml_meta[ 'user_allow_list' ] ) && in_array( $current_user_id, $pml_meta[ 'user_allow_list' ] ) )
        {
            return true;
        }
        if ( !empty( $pml_meta[ 'user_deny_list' ] ) && in_array( $current_user_id, $pml_meta[ 'user_deny_list' ] ) )
        {
            return false;
        }
        $global_role_allow = get_option( PML_PREFIX . '_global_role_allow_list', [] );
        if ( !empty( array_intersect( $current_user_roles, $global_role_allow ) ) )
        {
            return true;
        }
        $global_role_deny = get_option( PML_PREFIX . '_global_role_deny_list', [] );
        if ( !empty( array_intersect( $current_user_roles, $global_role_deny ) ) )
        {
            return false;
        }
        if ( !empty( $pml_meta[ 'role_allow_list' ] ) && !empty( array_intersect( $current_user_roles, $pml_meta[ 'role_allow_list' ] ) ) )
        {
            return true;
        }
        if ( !empty( $pml_meta[ 'role_deny_list' ] ) && !empty( array_intersect( $current_user_roles, $pml_meta[ 'role_deny_list' ] ) ) )
        {
            return false;
        }
        return false;
    }

    private function is_access_granted_by_bot( int $attachment_id, array $pml_meta ): bool
    {
        $allow_bots_override = $pml_meta[ 'allow_bots_for_file' ];
        $allow_bots_global   = (bool)get_option( PML_PREFIX . '_settings_allow_bots', true );
        $should_allow_bots   = !( 'no' === $allow_bots_override ) && ( ( 'yes' === $allow_bots_override ) || $allow_bots_global );
        if ( !$should_allow_bots )
        {
            return false;
        }
        $ip_address = isset( $_SERVER[ 'REMOTE_ADDR' ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ 'REMOTE_ADDR' ] ) ) : '';
        if ( empty( $ip_address ) )
        {
            return false;
        }
        if ( isset( self::$bot_detection_cache[ $ip_address ] ) )
        {
            return self::$bot_detection_cache[ $ip_address ];
        }
        if ( class_exists( 'PML_Bot_Detector' ) && PML_Bot_Detector::is_verified_bot() )
        {
            self::$bot_detection_cache[ $ip_address ] = true;
            return true;
        }
        self::$bot_detection_cache[ $ip_address ] = false;
        return false;
    }

    private function serve_file( string $file_path, int $attachment_id, string $context = 'unknown' )
    {
        $filename = basename( $file_path );
        if ( $attachment_id > 0 && ( $attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true ) ) )
        {
            $filename = basename( $attached_file );
        }
        $mime_type = mime_content_type( $file_path ) ?: 'application/octet-stream';

        if ( ob_get_level() > 0 )
        {
            @ob_end_clean();
        }

        status_header( 200 );
        header( 'Content-Type: ' . $mime_type );
        header( 'Content-Length: ' . filesize( $file_path ) );
        $disposition = 'attachment';
        if ( in_array( $context, [ 'bot_access', 'unprotected_library', 'unmanaged_public', 'user_role_allow' ] ) )
        {
            $disposition = 'inline';
        }
        header( 'Content-Disposition: ' . $disposition . '; filename="' . $filename . '"' );
        if ( 'token_valid' === $context )
        {
            header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
        }
        else
        {
            header( 'Cache-Control: public, max-age=' . HOUR_IN_SECONDS );
            header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + HOUR_IN_SECONDS ) . ' GMT' );
        }
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' );

        // --- Avenue 2: Server-Level Offloading ---
        $server_software = $_SERVER[ 'SERVER_SOFTWARE' ] ?? '';

        // Nginx (X-Accel-Redirect) or LiteSpeed (X-LiteSpeed-Location)
        if ( stripos( $server_software, 'nginx' ) !== false || stripos( $server_software, 'litespeed' ) !== false )
        {
            // This advanced method requires a corresponding 'internal' location block in the server config.
            // e.g., location /pml-secure-files/ { internal; alias /path/to/wp-content/uploads/; }
            $internal_prefix = apply_filters( 'pml_internal_redirect_prefix', '' );
            if ( !empty( $internal_prefix ) )
            {
                $uploads_dir   = wp_get_upload_dir();
                $relative_path = str_replace( trailingslashit( $uploads_dir[ 'basedir' ] ), '', $file_path );
                $redirect_path = trailingslashit( rtrim( $internal_prefix, '/' ) ) . $relative_path;

                if ( stripos( $server_software, 'nginx' ) !== false )
                {
                    header( 'X-Accel-Redirect: ' . $redirect_path );
                }
                else
                {
                    header( 'X-LiteSpeed-Location: ' . $redirect_path );
                }
                exit;
            }
        }

        // Apache (mod_xsendfile)
        if ( strpos( $server_software, 'Apache' ) !== false &&
             function_exists( 'apache_get_modules' ) &&
             in_array( 'mod_xsendfile', apache_get_modules(), true ) )
        {
            header( 'X-Sendfile: ' . $file_path );
            exit;
        }

        // Fallback: Let PHP serve the file if no offloading method is available.
        @readfile( $file_path );
        exit;
    }

    private function deny_access( ?int $attachment_id, string $log_reason = '', string $error_slug = 'general_denial' )
    {
        error_log( PML_PLUGIN_NAME . ": Access Denied. Reason: " . $log_reason );
        $redirect_url = get_option( PML_PREFIX . '_settings_default_redirect_url', home_url( '/' ) );
        if ( $attachment_id > 0 )
        {
            $pml_meta = $this->get_cached_attachment_meta( $attachment_id );
            if ( !empty( $pml_meta[ 'redirect_url' ] ) )
            {
                $redirect_url = $pml_meta[ 'redirect_url' ];
            }
        }
        $redirect_url = add_query_arg( PML_PREFIX . '_error', $error_slug, $redirect_url );
        if ( !headers_sent() )
        {
            wp_redirect( esc_url_raw( $redirect_url ) );
        }
        exit;
    }
}
