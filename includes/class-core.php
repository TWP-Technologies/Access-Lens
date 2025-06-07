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

        // Hook to run database upgrades on plugin load
        if ( class_exists( 'PML_Install' ) && method_exists( 'PML_Install', 'run_database_upgrades' ) )
        {
            add_action( 'plugins_loaded', [ 'PML_Install', 'run_database_upgrades' ], 5 ); // Run early
        }
    }

    private function load_components()
    {

        // Shortcodes page
        if ( class_exists( 'PML_Shortcodes' ) )
        {
            PML_Shortcodes::get_instance();
        }

        if ( !is_admin() )
        {
            return;
        }

        // Settings page
        if ( class_exists( 'PML_Settings' ) && is_admin() )
        {
            new PML_Settings();
        }

        // Media Edit Page PML Meta Box
        if ( class_exists( 'PML_Media_Meta' ) && is_admin() )
        {
            new PML_Media_Meta();
        }
        // Media Edit Page PML Token Meta Box
        if ( class_exists( 'PML_Token_Meta_Box' ) && is_admin() )
        {
            new PML_Token_Meta_Box();
        }
        // User list actions on the WP Users page
        if ( class_exists( 'PML_User_List_Actions' ) && is_admin() )
        {
            new PML_User_List_Actions();
        }
        // Media Library list/grid view integration
        if ( class_exists( 'PML_Media_Library_Integration' ) && is_admin() )
        {
            new PML_Media_Library_Integration();
        }
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
            $message = sanitize_text_field( wp_unslash( $_REQUEST[ PML_PREFIX . '_bulk_message' ] ) );
            if ( $message )
            {
                printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
            }
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

        $days    = floor( $seconds / DAY_IN_SECONDS );
        $seconds %= DAY_IN_SECONDS;
        $hours   = floor( $seconds / HOUR_IN_SECONDS );
        $seconds %= HOUR_IN_SECONDS;
        $minutes = floor( $seconds / MINUTE_IN_SECONDS );

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

        return empty( $parts ) ? __( 'Less than a minute', PML_TEXT_DOMAIN ) : implode( ', ', $parts );
    }

    private function __clone()
    {
    }

    public function __wakeup()
    {
    }
}
