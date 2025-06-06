<?php
/**
 * File Access Handler for Protected Media
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
    public function __construct()
    {
        add_action( 'template_redirect', [ $this, 'maybe_handle_file_request' ], 1 );
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
        $uploads_dir                  = wp_upload_dir();
        $full_requested_file_path     = trailingslashit( $uploads_dir[ 'basedir' ] ) . $requested_file_path_relative;

        $real_base_dir  = realpath( $uploads_dir[ 'basedir' ] );
        $real_file_path = realpath( $full_requested_file_path );

        if ( false === $real_file_path || strpos( $real_file_path, $real_base_dir ) !== 0 || !is_readable( $real_file_path ) )
        {
            $this->deny_access( null, 'File not found, invalid path, or not readable.', 'invalid_path' );
            return;
        }

        $file_url      = trailingslashit( $uploads_dir[ 'baseurl' ] ) . $requested_file_path_relative;
        $attachment_id = attachment_url_to_postid( $file_url );

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

        $is_protected = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true );
        if ( !$is_protected )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'unprotected_library' );
            return;
        }

        // --- Access Control Logic ---
        $current_user_id    = get_current_user_id();
        $user_data          = $current_user_id ? get_userdata( $current_user_id ) : false;
        $current_user_roles = $user_data ? (array)$user_data->roles : [];

        // Check user/role lists... (abbreviated for relevance)
        if ( $this->check_user_role_rules( $attachment_id, $current_user_id, $current_user_roles ) )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'user_role_allow' );
            return;
        }

        // Bot Detection
        $allow_bots_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_allow_bots_for_file', true );
        $allow_bots_global   = (bool)get_option( PML_PREFIX . '_settings_allow_bots', true );
        $should_allow_bots   = !( 'no' === $allow_bots_override ) && ( ( 'yes' === $allow_bots_override || $allow_bots_global ) );
        if ( $should_allow_bots && class_exists( 'PML_Bot_Detector' ) && PML_Bot_Detector::is_verified_bot() )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'bot_access' );
            return;
        }

        // Token Validation
        if ( !empty( $access_token ) && class_exists( 'PML_Token_Manager' ) )
        {
            $token_status = PML_Token_Manager::validate_token( $access_token, $attachment_id );

            // If token is expired or used up, update its status in the DB immediately.
            // This makes the system self-correcting if the cron job hasn't run.
            if ( 'expired' === $token_status || 'used_limit_reached' === $token_status )
            {
                PML_Token_Manager::update_token_fields( $access_token, [ 'status' => $token_status ], [ '%s' ] );
            }

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
            }
            else
            {
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
            return;
        }

        $this->deny_access( $attachment_id, 'Access to this file is restricted.', 'restricted_default' );
    }

    private function check_user_role_rules( int $attachment_id, int $user_id, array $roles ): bool
    {
        // This is a simplified placeholder for the existing user/role check logic
        // to keep the main handler readable. The full priority order would be here.
        $global_user_allow = get_option( PML_PREFIX . '_global_user_allow_list', [] );
        if ( $user_id > 0 && in_array( $user_id, $global_user_allow ) )
        {
            return true;
        }
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

        $disposition = 'attachment'; // Default to download
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

        readfile( $file_path );
        exit;
    }

    private function deny_access( ?int $attachment_id, string $log_reason = '', string $error_slug = 'general_denial' )
    {
        error_log( PML_PLUGIN_NAME . ": Access Denied. Reason: " . $log_reason );

        $redirect_url = get_option( PML_PREFIX . '_settings_default_redirect_url', home_url( '/' ) );
        if ( $attachment_id > 0 )
        {
            $file_specific_redirect = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_redirect_url', true );
            if ( !empty( $file_specific_redirect ) )
            {
                $redirect_url = $file_specific_redirect;
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
