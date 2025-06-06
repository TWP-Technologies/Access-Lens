<?php
/**
 * Token Management for Protected Media
 * Version: 1.2.0 (Feature: Token Management on Media Edit Page)
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
     * @param array $args          Additional arguments (user_id, user_email, ip_address, expires_in_seconds, max_uses, utc_expires_at).
     *                             If 'utc_expires_at' (YYYY-MM-DD HH:MM:SS format) is provided, it overrides 'expires_in_seconds'.
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
            'utc_expires_at'     => null, // Direct UTC expiry datetime string
        ];
        $args          = wp_parse_args( $args, $defaults );

        // Determine token expiry.
        $expires_at = null;
        if ( !empty( $args[ 'utc_expires_at' ] ) )
        {
            // Validate if the provided string is a valid future datetime.
            // For generation, we trust the input format if provided.
            $datetime_obj = date_create_from_format( 'Y-m-d H:i:s', $args[ 'utc_expires_at' ], new DateTimeZone( 'UTC' ) );
            if ( $datetime_obj && $datetime_obj->format( 'Y-m-d H:i:s' ) === $args[ 'utc_expires_at' ] )
            {
                // Check if it's in the future before assigning
                if ( self::is_datetime_in_future( $args[ 'utc_expires_at' ] ) )
                {
                    $expires_at = $args[ 'utc_expires_at' ];
                }
                else
                {
                    error_log( PML_PLUGIN_NAME . ": Attempted to generate token with past custom expiry: " . $args[ 'utc_expires_at' ] );
                    // Fallback to default or no expiry if past date given
                    // null so it falls to calculation or no expiry
                    $expires_at = null;
                }
            }
            else
            {
                error_log( PML_PLUGIN_NAME . ": Invalid utc_expires_at format provided: " . $args[ 'utc_expires_at' ] );
                $expires_at = null; // Invalid format
            }
        }

        // If 'expires_at' was not set by 'utc_expires_at' or was invalid, calculate from 'expires_in_seconds'.
        if ( is_null( $expires_at ) )
        {
            $expires_in_seconds_to_use = $args[ 'expires_in_seconds' ];
            if ( is_null( $expires_in_seconds_to_use ) )
            {
                $expiry_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_token_expiry', true );
                if ( '' !== $expiry_override && is_numeric( $expiry_override ) )
                {
                    $expires_in_seconds_to_use = (int)$expiry_override;
                }
                else
                {
                    $expires_in_seconds_to_use = (int)get_option( PML_PREFIX . '_settings_default_token_expiry', 24 * HOUR_IN_SECONDS );
                }
            }

            if ( (int)$expires_in_seconds_to_use > 0 )
            {
                $expires_at = gmdate( 'Y-m-d H:i:s', time() + (int)$expires_in_seconds_to_use );
            }
        }

        // Determine token max uses.
        $max_uses_to_use = $args[ 'max_uses' ];
        if ( is_null( $max_uses_to_use ) )
        {
            $max_uses_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_token_max_uses', true );
            if ( '' !== $max_uses_override && is_numeric( $max_uses_override ) )
            {
                $max_uses_to_use = (int)$max_uses_override;
            }
            else
            {
                $max_uses_to_use = (int)get_option( PML_PREFIX . '_settings_default_token_max_uses', 1 );
            }
        }

        $token_value = wp_generate_password( 40, false );
        $created_at  = current_time( 'mysql', true ); // UTC time.

        return [
            'token_value'   => $token_value,
            'attachment_id' => $attachment_id,
            'user_id'       => $args[ 'user_id' ] ? absint( $args[ 'user_id' ] ) : null,
            'user_email'    => $args[ 'user_email' ] ? sanitize_email( $args[ 'user_email' ] ) : null,
            'ip_address'    => $args[ 'ip_address' ] ? sanitize_text_field( $args[ 'ip_address' ] ) : null,
            'created_at'    => $created_at,
            'expires_at'    => $expires_at, // This is now correctly set
            'use_count'     => 0,
            'max_uses'      => absint( $max_uses_to_use ),
            'last_used_at'  => null, // New tokens haven't been used yet
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
            self::init();
        }

        $defaults_for_insert = [
            'token_value'   => '',
            'attachment_id' => 0,
            'user_id'       => null,
            'user_email'    => null,
            'ip_address'    => null,
            'created_at'    => current_time( 'mysql', true ),
            'expires_at'    => null,
            'use_count'     => 0,
            'max_uses'      => 1,
            'last_used_at'  => null,
            'status'        => 'active',
        ];

        $token_data_for_insert = wp_parse_args( $token_data, $defaults_for_insert );
        $result                = $wpdb->insert( self::$table_name, $token_data_for_insert );
        return $result ? $token_data_for_insert[ 'token_value' ] : false;
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
            error_log( PML_PLUGIN_NAME . ': Attempted to generate access URL for invalid attachment ID: ' . $attachment_id );
            return false;
        }

        $token_data  = self::generate_token_data( $attachment_id, $token_args );
        $token_value = self::store_token( $token_data );

        if ( !$token_value )
        {
            error_log( PML_PLUGIN_NAME . ': Failed to store token for attachment ID: ' . $attachment_id );
            return false;
        }

        $file_url = wp_get_attachment_url( $attachment_id );
        if ( !$file_url )
        {
            error_log( PML_PLUGIN_NAME . ': Could not get attachment URL for ID: ' . $attachment_id );
            return false;
        }

        return add_query_arg( 'access_token', $token_value, $file_url );
    }

    /**
     * Validates an access token.
     *
     * @param string $token_value   The token string to validate.
     * @param int    $attachment_id The ID of the attachment the token is for.
     *
     * @return string Status of the token ('valid', 'not_found', 'expired', 'used_limit_reached', 'invalid_attachment', 'revoked', or other status).
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
                "SELECT * FROM " . self::$table_name . " WHERE token_value = %s",
                $token_value,
            ),
        );

        if ( !$token_record )
        {
            return 'not_found';
        }

        if ( (int)$token_record->attachment_id !== $attachment_id )
        {
            return 'invalid_attachment';
        }

        if ( 'active' !== $token_record->status )
        {
            return $token_record->status;
        }

        if ( !empty( $token_record->expires_at ) && !self::is_datetime_in_future( $token_record->expires_at ) )
        {
            // Do not call update_token_status here to prevent recursion if validate_token is called by update_token_status.
            // The status update should happen in a separate cleanup process or when specifically handling an expired token.
            // The caller (e.g. File Handler) should trigger the status update.
            // For now, it is enough to return 'expired'. PML_File_Handler will call update_token_status.
            return 'expired';
        }

        if ( (int)$token_record->max_uses > 0 && (int)$token_record->use_count >= (int)$token_record->max_uses )
        {
            // Similar to expiry, status update should be handled by caller or cleanup.
            return 'used_limit_reached';
        }

        return 'valid';
    }

    /**
     * Records the usage of a token.
     * Increments the use_count, updates last_used_at, and updates status if max_uses is reached.
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

        $token_record = $wpdb->get_row(
            $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT max_uses, use_count FROM " . self::$table_name . " WHERE token_value = %s AND status = 'active'",
                $token_value,
            ),
        );

        if ( !$token_record )
        {
            error_log( PML_PLUGIN_NAME . ': Attempted to record usage for non-existent or non-active token: ' . $token_value );
            return false;
        }

        $new_use_count    = (int)$token_record->use_count + 1;
        $data_to_update   = [
            'use_count'    => $new_use_count,
            'last_used_at' => current_time( 'mysql', true ), // Record UTC timestamp of usage
        ];
        $format_to_update = [ '%d', '%s' ]; // for use_count, last_used_at

        if ( (int)$token_record->max_uses > 0 && $new_use_count >= (int)$token_record->max_uses )
        {
            $data_to_update[ 'status' ] = 'used';
            $format_to_update[]         = '%s'; // for status
        }

        $where        = [ 'token_value' => $token_value, 'status' => 'active' ];
        $where_format = [ '%s', '%s' ]; // for token_value, status

        $updated = $wpdb->update( self::$table_name, $data_to_update, $where, $format_to_update, $where_format );
        return (bool)$updated;
    }

    /**
     * Updates specific fields of a token.
     * Primarily used for status, but can update other fields like expires_at.
     *
     * @param string $token_value      The token string.
     * @param array  $data_to_update   Associative array of columns to update and their new values.
     *                                 Example: ['status' => 'revoked', 'expires_at' => '2025-12-31 23:59:59']
     * @param array  $format_to_update Array of formats for the $data_to_update values.
     *
     * @return bool True on success, false on failure or if data is empty.
     */
    public static function update_token_fields( string $token_value, array $data_to_update, array $format_to_update ): bool
    {
        global $wpdb;
        if ( empty( self::$table_name ) )
        {
            self::init();
        }

        if ( empty( $data_to_update ) )
        {
            error_log( PML_PLUGIN_NAME . ': Attempted to call update_token_fields with empty data for token: ' . $token_value );
            return false;
        }

        $token_value = sanitize_text_field( $token_value );

        // Basic validation for 'status' if it's being updated
        if ( isset( $data_to_update[ 'status' ] ) )
        {
            $allowed_statuses = [ 'active', 'expired', 'used', 'revoked' ];
            if ( !in_array( $data_to_update[ 'status' ], $allowed_statuses, true ) )
            {
                error_log( PML_PLUGIN_NAME . ': Attempted to update token with invalid status: ' . $data_to_update[ 'status' ] );
                return false;
            }

            // If setting to active, ensure expires_at (if provided in $data_to_update or existing) is valid
            if ( $data_to_update[ 'status' ] === 'active' )
            {
                $new_expires_at = $data_to_update[ 'expires_at' ] ?? null;
                if ( $new_expires_at === null )
                {
                    $current_token = self::get_token_by_value( $token_value ); // if not providing a new one, check existing
                    if ( $current_token )
                    {
                        $new_expires_at = $current_token->expires_at;
                    }
                }

                if ( !self::is_datetime_in_future( $new_expires_at ) )
                {
                    error_log(
                        sprintf(
                            "%s: Cannot set token %s to active with past or invalid expiry: %s",
                            PML_PLUGIN_NAME,
                            $token_value,
                            $new_expires_at ?? 'NULL',
                        ),
                    );
                    return false; // Prevent activating with past expiry
                }
            }
        }

        // Basic validation for 'expires_at' if it's being updated
        if ( isset( $data_to_update[ 'expires_at' ] ) && $data_to_update[ 'expires_at' ] !== null )
        {
            $datetime_obj = date_create_from_format( 'Y-m-d H:i:s', $data_to_update[ 'expires_at' ], new DateTimeZone( 'UTC' ) );
            if ( !$datetime_obj || $datetime_obj->format( 'Y-m-d H:i:s' ) !== $data_to_update[ 'expires_at' ] )
            {
                error_log( PML_PLUGIN_NAME . ": Invalid expires_at format for token update: " . $data_to_update[ 'expires_at' ] );
                return false; // Invalid format
            }

            // further check: if status is also being set to 'active', this date must be in future.
            if ( ( isset( $data_to_update[ 'status' ] ) && $data_to_update[ 'status' ] === 'active' ) ||
                 ( !isset( $data_to_update[ 'status' ] ) && self::get_token_by_value( $token_value )->status === 'active' ) )
            {
                if ( !self::is_datetime_in_future( $data_to_update[ 'expires_at' ] ) )
                {
                    error_log(
                        sprintf(
                            "%s: Cannot update active token %s with past or invalid expiry: %s",
                            PML_PLUGIN_NAME,
                            $token_value,
                            $data_to_update[ 'expires_at' ],
                        ),
                    );
                    return false;
                }
            }
        }

        $updated = $wpdb->update( self::$table_name, $data_to_update, [ 'token_value' => $token_value ], $format_to_update, [ '%s' ] );
        return (bool)$updated;
    }

    /**
     * Helper to get a token record by its value.
     *
     * @param string $token_value
     *
     * @return object|null Token record or null if not found.
     */
    public static function get_token_by_value( string $token_value ): ?object
    {
        global $wpdb;
        if ( empty( self::$table_name ) )
        {
            self::init();
        }
        
        $token_value = sanitize_text_field( $token_value );
        return $wpdb->get_row(
            $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM " . self::$table_name . " WHERE token_value = %s",
                $token_value,
            ),
        );
    }

    /**
     * Cleans up tokens.
     * Updates status of expired tokens.
     * Optionally, deletes very old tokens.
     */
    public static function cleanup_tokens()
    {
        global $wpdb;
        if ( empty( self::$table_name ) )
        {
            self::init();
        }

        $current_utc_time = current_time( 'mysql', true );

        // Update status of active tokens that have passed their expiry date.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "UPDATE " . self::$table_name . " SET status = 'expired'
				WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s",
                $current_utc_time,
            ),
        );

        $delete_old_tokens_enabled = (bool)get_option( PML_PREFIX . '_settings_cleanup_delete_old_tokens', false );
        if ( $delete_old_tokens_enabled )
        {
            $age_months = (int)get_option( PML_PREFIX . '_settings_cleanup_delete_age_months', 6 );
            if ( $age_months > 0 )
            {
                $delete_threshold_date = gmdate( 'Y-m-d H:i:s', strtotime( "-$age_months months", strtotime( $current_utc_time ) ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query(
                    $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        "DELETE FROM " . self::$table_name . "
						WHERE status != 'active' AND created_at < %s",
                        $delete_threshold_date,
                    ),
                );
            }
        }
    }

    /**
     * Checks if a given UTC datetime string is in the future.
     * Returns true if the datetime is null/empty (considered as no expiry) or is indeed in the future.
     *
     * @param string|null $datetime_utc_string The UTC datetime string (e.g., 'YYYY-MM-DD HH:MM:SS') or null.
     *
     * @return bool True if null/empty or future, false otherwise.
     */
    public static function is_datetime_in_future( ?string $datetime_utc_string ): bool
    {
        if ( empty( $datetime_utc_string ) )
        {
            return true; // No expiry is considered valid (future).
        }

        try
        {
            $current_time_utc = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
            $expiry_time_utc  = new DateTime( $datetime_utc_string, new DateTimeZone( 'UTC' ) );

            return $expiry_time_utc > $current_time_utc;
        } catch ( Exception $e )
        {
            // Log error if DateTime creation fails due to invalid format.
            error_log( PML_PLUGIN_NAME . ': Invalid datetime string for future check: ' . $datetime_utc_string . '. Error: ' . $e->getMessage() );
            return false; // Invalid format is not considered future.
        }
    }
}
