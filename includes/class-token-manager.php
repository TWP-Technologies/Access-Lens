<?php
/**
 * Token Management for Protected Media
 *
 * @package ProtectedMediaLinks
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * PML_Token_Manager Class.
 * Handles generation, storage, validation, and cleanup of access tokens.
 */
class PML_Token_Manager
{

    /**
     * Database table name for tokens.
     * Populated by init().
     *
     * @var string
     */
    public static string $table_name = '';

    /**
     * Initializes the token manager, primarily setting the table name.
     * This method should be called once, e.g., during plugin initialization.
     */
    public static function init()
    {
        global $wpdb;
        if ( empty( self::$table_name ) )
        { // Ensure it's set only once.
            self::$table_name = $wpdb->prefix . PML_PREFIX . '_access_tokens';
        }
    }

    /**
     * Generates the data array for a new token.
     *
     * @param int   $attachment_id The ID of the attachment to protect.
     * @param array $args          Additional arguments (user_id, user_email, ip_address, expires_in_seconds, max_uses).
     *
     * @return array Token data array.
     */
    public static function generate_token_data( int $attachment_id, array $args = [] ): array
    {
        $attachment_id = absint( $attachment_id );
        $defaults      = [
            'user_id'            => get_current_user_id(), // 0 if not logged in
            'user_email'         => null,
            'ip_address'         => isset( $_SERVER[ 'REMOTE_ADDR' ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ 'REMOTE_ADDR' ] ) ) : null,
            'expires_in_seconds' => null, // Will be determined by settings if null
            'max_uses'           => null, // Will be determined by settings if null
        ];
        $args          = wp_parse_args( $args, $defaults );

        // Determine token expiry duration.
        if ( is_null( $args[ 'expires_in_seconds' ] ) )
        {
            $expiry_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_token_expiry', true );
            if ( '' !== $expiry_override && is_numeric( $expiry_override ) )
            {
                $args[ 'expires_in_seconds' ] = (int)$expiry_override;
            }
            else
            {
                $args[ 'expires_in_seconds' ] = (int)get_option( PML_PREFIX . '_settings_default_token_expiry', 24 * HOUR_IN_SECONDS );
            }
        }

        // Determine token max uses.
        if ( is_null( $args[ 'max_uses' ] ) )
        {
            $max_uses_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_token_max_uses', true );
            if ( '' !== $max_uses_override && is_numeric( $max_uses_override ) )
            {
                $args[ 'max_uses' ] = (int)$max_uses_override;
            }
            else
            {
                $args[ 'max_uses' ] = (int)get_option( PML_PREFIX . '_settings_default_token_max_uses', 1 );
            }
        }

        $token_value = wp_generate_password( 40, false, false ); // 40-character strong token.
        $created_at  = current_time( 'mysql', true );            // UTC time.
        $expires_at  = null;
        if ( (int)$args[ 'expires_in_seconds' ] > 0 )
        {
            $expires_at = gmdate( 'Y-m-d H:i:s', time() + (int)$args[ 'expires_in_seconds' ] );
        }

        return [
            'token_value'   => $token_value,
            'attachment_id' => $attachment_id,
            'user_id'       => $args[ 'user_id' ] ? absint( $args[ 'user_id' ] ) : null,
            'user_email'    => $args[ 'user_email' ] ? sanitize_email( $args[ 'user_email' ] ) : null,
            'ip_address'    => $args[ 'ip_address' ] ? sanitize_text_field( $args[ 'ip_address' ] ) : null,
            'created_at'    => $created_at,
            'expires_at'    => $expires_at,
            'use_count'     => 0,
            'max_uses'      => absint( $args[ 'max_uses' ] ), // 0 for unlimited.
            'status'        => 'active',
        ];
    }

    /**
     * Stores a new token in the database.
     *
     * @param array $token_data Token data array from generate_token_data().
     *
     * @return string|false The token value if successful, false otherwise.
     */
    public static function store_token( array $token_data )
    {
        global $wpdb;
        if ( empty( self::$table_name ) )
        {
            self::init(); // Ensure table name is set.
        }

        $result = $wpdb->insert( self::$table_name, $token_data );
        return $result ? $token_data[ 'token_value' ] : false;
    }

    /**
     * Generates a tokenized access URL for a given attachment.
     *
     * @param int   $attachment_id The ID of the attachment.
     * @param array $token_args    Optional arguments for token generation.
     *
     * @return string|false The access URL if successful, false otherwise.
     */
    public static function generate_access_url( int $attachment_id, array $token_args = [] )
    {
        $attachment_id = absint( $attachment_id );
        if ( !$attachment_id || 'attachment' !== get_post_type( $attachment_id ) )
        {
            // Log error or return specific error object if more detail is needed.
            error_log( PML_PLUGIN_NAME . ': Attempted to generate access URL for invalid attachment ID: ' . $attachment_id );
            return false;
        }

        $token_data  = self::generate_token_data( $attachment_id, $token_args );
        $token_value = self::store_token( $token_data );

        if ( !$token_value )
        {
            error_log( PML_PLUGIN_NAME . ': Failed to store token for attachment ID: ' . $attachment_id );
            return false; // Failed to generate or store token.
        }

        $file_url = wp_get_attachment_url( $attachment_id );
        if ( !$file_url )
        {
            error_log( PML_PLUGIN_NAME . ': Could not get attachment URL for ID: ' . $attachment_id );
            return false; // Could not get attachment URL.
        }

        // The URL should point to the one intercepted by .htaccess/Nginx rules.
        // This means it should be the direct file URL, not a WordPress permalink.
        return add_query_arg( 'access_token', $token_value, $file_url );
    }

    /**
     * Validates an access token.
     *
     * @param string $token_value   The token string to validate.
     * @param int    $attachment_id The ID of the attachment the token is for.
     *
     * @return string Status of the token ('valid', 'not_found', 'expired', 'used_limit_reached', 'invalid_attachment', or other status).
     */
    public static function validate_token( string $token_value, int $attachment_id ): string
    {
        global $wpdb;
        if ( empty( self::$table_name ) )
        {
            self::init();
        }
        $token_value   = sanitize_text_field( $token_value );
        $attachment_id = absint( $attachment_id );

        $token_record = $wpdb->get_row(
            $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is class property.
                "SELECT * FROM " . self::$table_name . " WHERE token_value = %s", // Check token first
                $token_value,
            ),
        );

        if ( !$token_record )
        {
            return 'not_found';
        }

        // Verify attachment ID after finding the token
        if ( (int)$token_record->attachment_id !== $attachment_id )
        {
            return 'invalid_attachment'; // Token exists but not for this file
        }

        if ( 'active' !== $token_record->status )
        {
            return $token_record->status; // e.g., 'used', 'expired', 'revoked'
        }

        // Check expiry (uses UTC comparison as created_at and expires_at should be UTC).
        if ( !empty( $token_record->expires_at ) && strtotime( $token_record->expires_at . ' UTC' ) < time() )
        {
            self::update_token_status( $token_value, 'expired' );
            return 'expired';
        }

        // Check max uses (if max_uses is not 0 for unlimited).
        // The use_count is incremented *after* validation and *before* serving the file.
        // So, if use_count >= max_uses, it means the limit was already hit on a previous valid download.
        if ( (int)$token_record->max_uses > 0 && (int)$token_record->use_count >= (int)$token_record->max_uses )
        {
            // This state should ideally already be 'used' due to record_token_usage, but this is a safeguard.
            self::update_token_status( $token_value, 'used' ); // Ensure status is correct.
            return 'used_limit_reached';
        }

        return 'valid';
    }

    /**
     * Records the usage of a token.
     * Increments the use_count and updates status if max_uses is reached.
     * This should be called *after* successful validation and *before* serving the file.
     *
     * @param string $token_value The token string.
     *
     * @return bool True on success, false on failure.
     */
    public static function record_token_usage( string $token_value ): bool
    {
        global $wpdb;
        if ( empty( self::$table_name ) )
        {
            self::init();
        }
        $token_value = sanitize_text_field( $token_value );

        // Fetch current use_count and max_uses for the active token.
        // It's crucial this operates on an 'active' token that has just been validated.
        $token_record = $wpdb->get_row(
            $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is class property.
                "SELECT max_uses, use_count FROM " . self::$table_name . " WHERE token_value = %s AND status = 'active'",
                $token_value,
            ),
        );

        if ( !$token_record )
        {
            error_log( PML_PLUGIN_NAME . ': Attempted to record usage for non-existent or non-active token: ' . $token_value );
            return false; // Token not found or not active.
        }

        $new_use_count    = (int)$token_record->use_count + 1;
        $data_to_update   = [ 'use_count' => $new_use_count ];
        $format_to_update = [ '%d' ]; // For use_count

        // If max_uses is set (not 0 for unlimited) and new count reaches or exceeds it, mark as 'used'.
        if ( (int)$token_record->max_uses > 0 && $new_use_count >= (int)$token_record->max_uses )
        {
            $data_to_update[ 'status' ] = 'used';
            $format_to_update[]         = '%s'; // For status
        }

        $where        = [ 'token_value' => $token_value, 'status' => 'active' ]; // Ensure we only update an active token.
        $where_format = [ '%s', '%s' ];

        $updated = $wpdb->update( self::$table_name, $data_to_update, $where, $format_to_update, $where_format );
        return (bool)$updated;
    }

    /**
     * Updates the status of a specific token.
     *
     * @param string $token_value The token string.
     * @param string $new_status  The new status (e.g., 'expired', 'used', 'revoked').
     */
    public static function update_token_status( string $token_value, string $new_status )
    {
        global $wpdb;
        if ( empty( self::$table_name ) )
        {
            self::init();
        }
        // Define a list of allowed statuses to prevent arbitrary values.
        $allowed_statuses = [ 'active', 'expired', 'used', 'revoked' ]; // 'used_limit_reached' is an outcome, actual status is 'used'.
        if ( !in_array( $new_status, $allowed_statuses, true ) )
        {
            error_log( PML_PLUGIN_NAME . ': Attempted to update token with invalid status: ' . $new_status );
            return; // Invalid status.
        }

        $wpdb->update(
            self::$table_name,
            [ 'status' => $new_status ],
            [ 'token_value' => sanitize_text_field( $token_value ) ],
            [ '%s' ],  // Format for new_status
            [ '%s' ],  // Format for token_value
        );
    }

    /**
     * Cleans up tokens.
     * Updates status of expired tokens.
     * Optionally, deletes very old tokens (e.g., expired or used for more than X months).
     */
    public static function cleanup_tokens()
    {
        global $wpdb;
        if ( empty( self::$table_name ) )
        {
            self::init();
        }

        // Update status of active tokens that have passed their expiry date.
        $wpdb->query(
            $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is class property.
                "UPDATE " . self::$table_name . " SET status = 'expired'
				WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s",
                current_time( 'mysql', true ), // Current UTC time
            ),
        );

        // Optionally delete very old tokens if the setting is enabled.
        $delete_old_tokens_enabled = (bool)get_option( PML_PREFIX . '_settings_cleanup_delete_old_tokens', false );
        if ( $delete_old_tokens_enabled )
        {
            $age_months = (int)get_option( PML_PREFIX . '_settings_cleanup_delete_age_months', 6 );
            if ( $age_months > 0 )
            {
                // Calculate the date threshold for deletion.
                // Tokens created before this date AND not active will be deleted.
                $delete_threshold_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$age_months} months" ) );

                $wpdb->query(
                    $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is class property.
                        "DELETE FROM " . self::$table_name . "
						WHERE status != 'active' AND created_at < %s",
                        $delete_threshold_date,
                    ),
                );
            }
        }
    }
}
