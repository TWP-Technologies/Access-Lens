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
        // Hook into template_redirect with a low priority to intercept file requests early.
        add_action( 'template_redirect', [ $this, 'maybe_handle_file_request' ], 1 );
    }

    /**
     * Main handler for intercepting and processing requests for files in wp-content/uploads/.
     * This method implements the core access control logic.
     */
    public function maybe_handle_file_request()
    {
        // Get the requested file path from the query variable set by .htaccess/Nginx.
        $requested_file_path_relative_raw = get_query_var( PML_PREFIX . '_media_request' );

        // If our query variable isn't set, this isn't a request for us to handle.
        if ( empty( $requested_file_path_relative_raw ) )
        {
            return;
        }

        // Sanitize the relative file path.
        // Prevents directory traversal by ensuring all parts of the path are valid file/directory names.
        $requested_file_path_relative = implode( '/', array_map( 'sanitize_file_name', explode( '/', $requested_file_path_relative_raw ) ) );

        // Get the access token if provided.
        $access_token_raw = get_query_var( 'access_token' );
        if ( empty( $access_token_raw ) && isset( $_GET[ 'access_token' ] ) )
        {
            $access_token_raw = sanitize_text_field( wp_unslash( $_GET[ 'access_token' ] ) );
        }
        $access_token = $access_token_raw;

        // Construct the full server path to the requested file.
        $uploads_dir              = wp_upload_dir();
        $full_requested_file_path = trailingslashit( $uploads_dir[ 'basedir' ] ) . $requested_file_path_relative;

        // Validate the file path to prevent directory traversal and ensure it's within the uploads directory.
        $real_base_dir  = realpath( $uploads_dir[ 'basedir' ] );
        $real_file_path = realpath( $full_requested_file_path );

        if ( false === $real_file_path || strpos( $real_file_path, $real_base_dir ) !== 0 || !is_readable( $real_file_path ) )
        {
            $this->deny_access( null, 'File not found, invalid path, or not readable.', 'invalid_path' );
            return; // Important to exit after deny_access.
        }

        // Try to get the attachment ID for the requested file.
        $file_url      = trailingslashit( $uploads_dir[ 'baseurl' ] ) . $requested_file_path_relative;
        $attachment_id = attachment_url_to_postid( $file_url );

        // Handle files not in Media Library but present in uploads directory.
        if ( !$attachment_id )
        {
            // gx todo - add handle unmanaged files checkbox in settings.
            $handle_unmanaged_setting = get_option( PML_PREFIX . '_settings_handle_unmanaged_files', 'serve_publicly' );
            if ( 'serve_publicly' === $handle_unmanaged_setting && file_exists( $full_requested_file_path ) )
            {
                // Serve the file directly without protection checks if it's not a media library item.
                $this->serve_file( $full_requested_file_path, 0, 'unmanaged_public' );
            }
            else
            {
                // Deny access if setting is 'deny_access' or file doesn't exist (already caught by realpath check).
                $this->deny_access( null, 'File is not managed by the Media Library or access is restricted.', 'unmanaged_restricted' );
            }
            return; // Important to exit.
        }

        // Check if the Media Library item is marked for protection.
        $is_protected = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true );
        if ( !$is_protected )
        {
            // If not protected, serve it directly.
            $this->serve_file( $full_requested_file_path, $attachment_id, 'unprotected_library' );
            return; // Important to exit.
        }

        // --- Access Control Logic for Protected Files ---
        $current_user_id    = get_current_user_id();
        $current_user_roles = [];
        if ( $current_user_id > 0 )
        {
            $user_data          = get_userdata( $current_user_id );
            $current_user_roles = (array)( $user_data->roles ?? [] );
        }

        // 1. Global User Allow List
        $global_user_allow_list = get_option( PML_PREFIX . '_global_user_allow_list', [] );
        if ( $current_user_id > 0 && !empty( $global_user_allow_list ) && in_array( $current_user_id, $global_user_allow_list ) )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'user_global_allow' );
            return;
        }

        // 2. Global User Deny List
        $global_user_deny_list = get_option( PML_PREFIX . '_global_user_deny_list', [] );
        if ( $current_user_id > 0 && !empty( $global_user_deny_list ) && in_array( $current_user_id, $global_user_deny_list ) )
        {
            $this->deny_access( $attachment_id, 'Access denied by global user deny list.', 'global_user_deny' );
            return;
        }

        // 3. Per-File User Allow List
        $per_file_user_allow_list = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_user_allow_list', true ) ?: [];
        if ( $current_user_id > 0 && !empty( $per_file_user_allow_list ) && in_array( $current_user_id, $per_file_user_allow_list ) )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'user_file_allow' );
            return;
        }

        // 4. Per-File User Deny List
        $per_file_user_deny_list = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_user_deny_list', true ) ?: [];
        if ( $current_user_id > 0 && !empty( $per_file_user_deny_list ) && in_array( $current_user_id, $per_file_user_deny_list, ) )
        {
            $this->deny_access( $attachment_id, 'Access denied by file-specific user deny list.', 'file_user_deny' );
            return;
        }

        // 5. Global Role Allow List
        $global_role_allow_list = get_option( PML_PREFIX . '_global_role_allow_list', [ 'administrator' ] );
        if ( $current_user_id > 0 &&
             !empty( $current_user_roles ) &&
             !empty( $global_role_allow_list ) &&
             !empty( array_intersect( $current_user_roles, $global_role_allow_list ) ) )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'user_global_role_allow' );
            return;
        }

        // 6. Global Role Deny List
        $global_role_deny_list = get_option( PML_PREFIX . '_global_role_deny_list', [] );
        if ( $current_user_id > 0 &&
             !empty( $current_user_roles ) &&
             !empty( $global_role_deny_list ) &&
             !empty( array_intersect( $current_user_roles, $global_role_deny_list ) ) )
        {
            $this->deny_access( $attachment_id, 'Access denied by global role deny list.', 'global_role_deny' );
            return;
        }

        // 7. Per-File Role Allow List
        $per_file_role_allow_list = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_role_allow_list', true ) ?: [];
        if ( $current_user_id > 0 &&
             !empty( $current_user_roles ) &&
             !empty( $per_file_role_allow_list ) &&
             !empty( array_intersect( $current_user_roles, $per_file_role_allow_list ) ) )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'user_file_role_allow' );
            return;
        }

        // 8. Per-File Role Deny List
        $per_file_role_deny_list = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_role_deny_list', true ) ?: [];
        if ( $current_user_id > 0 &&
             !empty( $current_user_roles ) &&
             !empty( $per_file_role_deny_list ) &&
             !empty( array_intersect( $current_user_roles, $per_file_role_deny_list ) ) )
        {
            $this->deny_access( $attachment_id, 'Access denied by file-specific role deny list.', 'file_role_deny' );
            return;
        }

        // 9. Bot Detection
        /**
         * @var 'yes'|'no'|'' $allow_bots_override 'yes' means allow bots for this file, 'no' means deny bots, '' means use global setting.
         */
        $allow_bots_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_allow_bots_for_file', true );
        $allow_bots_global   = (bool)get_option( PML_PREFIX . '_settings_allow_bots', true );

        $should_allow_bots = $allow_bots_global; // Start with global setting
        if ( 'yes' === $allow_bots_override )
        {
            $should_allow_bots = true;
        }
        elseif ( 'no' === $allow_bots_override )
        {
            $should_allow_bots = false;
        }
        // If $allow_bots_override is empty, $should_allow_bots remains the global setting.

        if ( $should_allow_bots && class_exists( 'PML_Bot_Detector' ) && PML_Bot_Detector::is_verified_bot() )
        {
            $this->serve_file( $full_requested_file_path, $attachment_id, 'bot_access' );
            return;
        }

        // 10. Token Validation
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
                    // Failed to record token usage, could be a concurrent request issue or DB error.
                    $this->deny_access( $attachment_id, 'Token usage recording failed.', 'token_usage_error' );
                }
            }
            else
            {
                // Provide a more specific reason based on token status.
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

        // 11. Fallback: Deny access if no other rule grants it.
        $this->deny_access( $attachment_id, 'Access to this file is restricted.', 'restricted_default' );
    }

    /**
     * Serves the requested file with appropriate headers.
     *
     * @param string $file_path     Full path to the file.
     * @param int    $attachment_id Attachment ID (0 if not a media library item).
     * @param string $context       Context of access (e.g., 'token_valid', 'bot_access', 'user_global_allow').
     */
    private function serve_file( string $file_path, int $attachment_id, string $context = 'unknown' )
    {
        $filename = basename( $file_path );

        // Try to get the original filename from attachment metadata if available.
        if ( $attachment_id > 0 )
        {
            $attached_file_meta = get_post_meta( $attachment_id, '_wp_attached_file', true );
            if ( $attached_file_meta )
            {
                $filename = basename( $attached_file_meta );
            }
        }

        $mime_type = mime_content_type( $file_path );
        if ( !$mime_type )
        { // Fallback if mime_content_type fails
            $mime_type = 'application/octet-stream';
        }

        // Clear output buffer if any content has already been sent.
        if ( ob_get_level() > 0 )
        {
            @ob_end_clean(); // Suppress errors if output buffering wasn't active.
        }

        // Set HTTP headers.
        status_header( 200 );
        header( 'Content-Type: ' . $mime_type );
        header( 'Content-Length: ' . filesize( $file_path ) );

        // Determine Content-Disposition based on context.
        // Bots and directly allowed users often benefit from 'inline' for indexing/viewing.
        // Token users typically expect a download ('attachment').
        if ( 'bot_access' === $context ||
             'unprotected_library' === $context ||
             'unmanaged_public' === $context ||
             strpos( $context, '_allow' ) !== false )
        {
            header( 'Content-Disposition: inline; filename="' . $filename . '"' );
            // For inline, allow caching more liberally for public/bot contexts.
            if ( 'bot_access' === $context || 'unprotected_library' === $context || 'unmanaged_public' === $context )
            {
                header( 'Cache-Control: public, max-age=' . HOUR_IN_SECONDS ); // Cache for 1 hour.
                header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + HOUR_IN_SECONDS ) . ' GMT' );
            }
            else
            {                                                                              // Allowed users (non-token) - private cache
                header( 'Cache-Control: private, max-age=' . ( 15 * MINUTE_IN_SECONDS ) ); // Cache for 15 mins
                header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + ( 15 * MINUTE_IN_SECONDS ) ) . ' GMT' );
            }
        }
        else // 'token_valid' or other contexts implying a specific download action.
        {
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            // For attachments via token, prevent caching to ensure token rules are always checked.
            header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );
            header( 'Pragma: no-cache' ); // HTTP 1.0.
            header( 'Expires: 0' );       // Proxies.
        }

        // Additional security headers.
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: SAMEORIGIN' ); // Or DENY if not meant to be framed.
        header( 'X-XSS-Protection: 1; mode=block' );
        // Consider Content-Security-Policy if applicable, though for file downloads it's less critical.

        // Serve the file in chunks to handle large files efficiently.
        $chunk_size = 1024 * 1024;               // 1MB chunks.
        $handle     = fopen( $file_path, 'rb' );

        if ( false === $handle )
        {
            status_header( 500 ); // Internal Server Error.
            // Log this error server-side.
            error_log( PML_PLUGIN_NAME . ": Could not open file for serving: " . $file_path );
            echo esc_html__( 'Error: Could not open file for serving. Please contact support.', PML_TEXT_DOMAIN );
            exit;
        }

        while ( !feof( $handle ) && !connection_aborted() )
        {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file content.
            echo fread( $handle, $chunk_size );
            @ob_flush(); // Flush PHP output buffer.
            @flush();    // Flush web server output buffer.
        }
        fclose( $handle );
        exit; // Ensure no further WordPress processing occurs.
    }

    /**
     * Handles denied access by redirecting the user.
     *
     * @param int|null $attachment_id Attachment ID if known, for per-file redirect URLs.
     * @param string   $log_reason    Internal reason for denial (for logging).
     * @param string   $error_slug    A short slug for the error type (can be passed in URL).
     */
    private function deny_access( ?int $attachment_id, string $log_reason = '', string $error_slug = 'general_denial' )
    {
        // Determine the redirect URL.
        $default_redirect_url = get_option( PML_PREFIX . '_settings_default_redirect_url', '' );
        if ( empty( $default_redirect_url ) )
        {
            $default_redirect_url = home_url( '/' ); // Fallback to home_url if option is empty
        }
        $redirect_url = $default_redirect_url;

        if ( $attachment_id > 0 )
        {
            $file_specific_redirect_url = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_redirect_url', true );
            if ( !empty( $file_specific_redirect_url ) && filter_var( $file_specific_redirect_url, FILTER_VALIDATE_URL ) )
            {
                $redirect_url = $file_specific_redirect_url;
            }
        }

        // Add an error query argument to the redirect URL.
        $redirect_url = add_query_arg( PML_PREFIX . '_error', $error_slug, $redirect_url );

        // Log the denial reason internally, especially if WP_DEBUG is on.
        if ( !empty( $log_reason ) )
        {
            // Construct a more detailed log message.
            $log_message = sprintf(
                "Access Denied for attachment ID %s. Reason: %s. Error Slug: %s. Request URI: %s. IP: %s. User Agent: %s",
                $attachment_id ?? 'N/A',
                $log_reason,
                $error_slug,
                esc_url_raw( $_SERVER[ 'REQUEST_URI' ] ?? 'N/A' ),
                sanitize_text_field( $_SERVER[ 'REMOTE_ADDR' ] ?? 'N/A' ),
                sanitize_text_field( $_SERVER[ 'HTTP_USER_AGENT' ] ?? 'N/A' ),
            );
            error_log( PML_PLUGIN_NAME . ': ' . $log_message );

            // Conditionally add a debug reason to the URL if WP_DEBUG is on and user is admin.
            // Be cautious with exposing detailed reasons directly in URLs.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) )
            {
                $redirect_url = add_query_arg( PML_PREFIX . '_debug_reason', urlencode( $log_reason ), $redirect_url );
            }
        }

        // Perform the redirect.
        if ( !headers_sent() )
        {
            wp_redirect( esc_url_raw( $redirect_url ), 302 ); // Use 302 Found for temporary redirect.
        }
        else
        {
            // Fallback if headers are already sent (less ideal).
            // Log this scenario as it indicates an issue elsewhere.
            error_log(
                PML_PLUGIN_NAME .
                ': Headers already sent, cannot redirect for access denial. Attachment: ' .
                ( $attachment_id ?? 'N/A' ) .
                '. Reason: ' .
                $log_reason,
            );
            echo '<script type="text/javascript">window.location.href = "' . esc_url_raw( $redirect_url ) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url_raw( $redirect_url ) . '"></noscript>';
        }
        exit; // Crucial to stop further script execution.
    }
}
