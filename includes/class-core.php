<?php
/**
 * Core Plugin Class
 *
 * @package ProtectedMediaLinks
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

final class PML_Core
{
    private static ?PML_Core $instance = null;

    public static function get_instance(): PML_Core
    {
        if ( null === self::$instance )
        {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
        $this->load_components();
    }

    private function init_hooks()
    {
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );

        // Hook for admin notices from PML_User_List_Actions
        if ( class_exists( 'PML_User_List_Actions' ) && method_exists( 'PML_User_List_Actions', 'display_user_list_admin_notices' ) )
        {
            add_action( 'admin_notices', [ 'PML_User_List_Actions', 'display_user_list_admin_notices' ] );
        }
        elseif ( class_exists( 'PML_User_List_Actions' ) )
        {
            error_log(
                PML_PLUGIN_NAME .
                ' Notice: PML_User_List_Actions::display_user_list_admin_notices method not found. Class map might be outdated or method signature changed.',
            );
        }

        // Hook for bulk action admin notices
        add_action( 'admin_notices', [ $this, 'display_bulk_action_admin_notices' ] );
    }

    private function load_components()
    {
        if ( class_exists( 'PML_Settings' ) )
        {
            new PML_Settings();
        }
        if ( class_exists( 'PML_Media_Meta' ) )
        {
            new PML_Media_Meta();
        }
        if ( class_exists( 'PML_File_Handler' ) )
        {
            new PML_File_Handler();
        }
        if ( class_exists( 'PML_User_List_Actions' ) && is_admin() )
        {
            new PML_User_List_Actions();
        }
        if ( class_exists( 'PML_Media_Library_Integration' ) && is_admin() )
        {
            new PML_Media_Library_Integration();
        }
    }

    public function register_query_vars( array $vars ): array
    {
        $vars[] = PML_PREFIX . '_media_request';
        $vars[] = 'access_token';
        return $vars;
    }

    /**
     * Displays admin notices for PML bulk actions.
     */
    public function display_bulk_action_admin_notices()
    {
        // Check if running in admin and if the specific query arg is set.
        if ( is_admin() && !empty( $_REQUEST[ PML_PREFIX . '_bulk_message' ] ) )
        {
            // Sanitize the message from the request.
            // wp_unslash is important as WordPress might add slashes.
            $message = sanitize_text_field( wp_unslash( $_REQUEST[ PML_PREFIX . '_bulk_message' ] ) );
            if ( $message )
            {
                // Use WordPress's standard notice HTML structure.
                printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
            }
            // It's good practice to remove the query arg after displaying the notice to prevent it from showing on subsequent page loads if the user refreshes or navigates.
            // However, this can be tricky if other plugins or WordPress core rely on these query args persisting for a bit.
            // For now, let it persist for the current request. If it becomes an issue, more complex state management (like transients) might be needed.
        }
    }

    /**
     * Helper function to format duration in seconds into a human-readable string.
     *
     * @param int $seconds Duration in seconds.
     *
     * @return string Human-readable duration.
     */
    public static function format_duration( int $seconds ): string
    {
        if ( $seconds == 0 )
        {
            return __( 'No Expiry', PML_TEXT_DOMAIN );
        }

        $days    = floor( $seconds / 86400 );
        $seconds %= 86400;
        $hours   = floor( $seconds / 3600 );
        $seconds %= 3600;
        $minutes = floor( $seconds / 60 );
        $seconds %= 60;

        $parts = [];
        if ( $days > 0 )
        {
            $parts[] = sprintf( _n( '%d day', '%d days', $days, PML_TEXT_DOMAIN ), $days );
        }
        if ( $hours > 0 )
        {
            $parts[] = sprintf( _n( '%d hour', '%d hours', $hours, PML_TEXT_DOMAIN ), $hours );
        }
        if ( $minutes > 0 )
        {
            $parts[] = sprintf( _n( '%d minute', '%d minutes', $minutes, PML_TEXT_DOMAIN ), $minutes );
        }
        if ( $seconds > 0 && empty( $parts ) )
        {
            $parts[] = sprintf( _n( '%d second', '%d seconds', $seconds, PML_TEXT_DOMAIN ), $seconds );
        } // Show seconds only if no larger units

        return empty( $parts ) ? __( 'Less than a minute', PML_TEXT_DOMAIN ) : implode( ', ', $parts );
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
    }
}
