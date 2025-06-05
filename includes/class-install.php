<?php
/**
 * Plugin Installation, Activation, Deactivation, Uninstall
 *
 * @package ProtectedMediaLinks
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class PML_Install
{
    public static function activate()
    {
        if ( class_exists( 'PML_Token_Manager' ) )
        {
            PML_Token_Manager::init();
        }
        else
        {
            // gx todo - handle this case, maybe throw an error or log it
        }

        self::create_database_table();
        self::set_default_options();
        self::manage_htaccess_rules( true );

        if ( get_option( PML_PREFIX . '_settings_cleanup_tokens' ) )
        {
            if ( !wp_next_scheduled( PML_PREFIX . '_daily_token_cleanup_hook' ) )
            {
                wp_schedule_event( time(), 'daily', PML_PREFIX . '_daily_token_cleanup_hook' );
            }
        }
        flush_rewrite_rules();

        // Set transient for htaccess notice if automatic update might have failed (check return of manage_htaccess_rules)
        if ( !self::are_htaccess_rules_present() )
        { // A new helper function to check
            set_transient( PML_PREFIX . '_admin_notice_htaccess_needed', true, MINUTE_IN_SECONDS * 5 );
        }
    }

    public static function deactivate()
    {
        self::manage_htaccess_rules( false ); // Attempt to remove rules
        wp_clear_scheduled_hook( PML_PREFIX . '_daily_token_cleanup_hook' );
        flush_rewrite_rules();
    }

    public static function uninstall()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . PML_PREFIX . '_access_tokens';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

        $options_to_delete = [
            PML_PREFIX . '_settings_default_redirect_url',
            PML_PREFIX . '_settings_default_token_expiry',
            PML_PREFIX . '_settings_default_token_max_uses',
            PML_PREFIX . '_settings_allow_bots',
            PML_PREFIX . '_settings_bot_user_agents',
            PML_PREFIX . '_settings_verified_bot_domains',
            PML_PREFIX . '_settings_cleanup_tokens',
            PML_PREFIX . '_global_user_allow_list',
            PML_PREFIX . '_global_user_deny_list',
            PML_PREFIX . '_global_role_allow_list',
            PML_PREFIX . '_global_role_deny_list',
        ];

        foreach ( $options_to_delete as $option_name )
        {
            delete_option( $option_name );
        }

        wp_clear_scheduled_hook( PML_PREFIX . '_daily_token_cleanup_hook' );
        flush_rewrite_rules();
    }

    private static function create_database_table()
    {
        global $wpdb;
        $table_name      = $wpdb->prefix . PML_PREFIX . '_access_tokens';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			token_value VARCHAR(64) NOT NULL,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			user_email VARCHAR(255) DEFAULT NULL,
			ip_address VARCHAR(100) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at DATETIME DEFAULT NULL,
			use_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
			max_uses INT(11) UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			UNIQUE KEY token_value (token_value),
			KEY attachment_id (attachment_id),
			KEY status (status),
			KEY expires_at (expires_at)
		) $charset_collate;";

        if ( !function_exists( 'dbDelta' ) )
        {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        dbDelta( $sql );
    }

    private static function set_default_options()
    {
        $default_options = [
            PML_PREFIX . '_settings_default_redirect_url'   => home_url( '/' ),
            PML_PREFIX . '_settings_default_token_expiry'   => 24 * HOUR_IN_SECONDS,
            PML_PREFIX . '_settings_default_token_max_uses' => 1,
            PML_PREFIX . '_settings_allow_bots'             => '1',
            PML_PREFIX . '_settings_bot_user_agents'        => '',
            PML_PREFIX . '_settings_verified_bot_domains'   => '',
            PML_PREFIX . '_settings_cleanup_tokens'         => '1',
            PML_PREFIX . '_global_user_allow_list'          => [],
            PML_PREFIX . '_global_user_deny_list'           => [],
            PML_PREFIX . '_global_role_allow_list'          => [ 'administrator' ], // Admin allowed by default
            PML_PREFIX . '_global_role_deny_list'           => [],
        ];

        foreach ( $default_options as $option_name => $default_value )
        {
            if ( false === get_option( $option_name ) )
            {
                add_option( $option_name, $default_value );
            }
        }
    }

    /**
     * Manages .htaccess rules for the plugin.
     *
     * @param bool $add True to add rules, false to remove.
     *
     * @return bool True on success, false on failure or if not applicable.
     */
    public static function manage_htaccess_rules( bool $add = true ): bool
    {
        if ( !function_exists( 'get_home_path' ) || !function_exists( 'insert_with_markers' ) || !function_exists( 'extract_from_markers' ) )
        {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $htaccess_file = get_home_path() . '.htaccess';
        $marker_name   = PML_PLUGIN_NAME;

        // Check if .htaccess file exists and is writable.
        if ( !file_exists( $htaccess_file ) )
        {
            // Attempt to create it if it doesn't exist and parent is writable.
            if ( !is_writable( get_home_path() ) )
            {
                return false; // Cannot create or write.
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
            if ( false === file_put_contents( $htaccess_file, '' ) )
            {
                return false; // Failed to create.
            }
        }

        if ( !is_writable( $htaccess_file ) )
        {
            return false; // Not writable.
        }

        $rules = [];
        if ( $add )
        {
            $rules = [
                'RewriteEngine On',
                'RewriteCond %{REQUEST_FILENAME} -f',
                'RewriteRule ^wp-content/uploads/(.*)$ index.php?' . PML_PREFIX . '_media_request=$1 [QSA,L]',
            ];
        }

        // insert_with_markers will remove the block if $rules is empty.
        return insert_with_markers( $htaccess_file, $marker_name, $rules );
    }

    /**
     * Checks if the plugin's .htaccess rules are present.
     *
     * @return bool True if rules are found, false otherwise.
     */
    public static function are_htaccess_rules_present(): bool
    {
        if ( !function_exists( 'extract_from_markers' ) )
        {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $htaccess_file = get_home_path() . '.htaccess';
        if ( !file_exists( $htaccess_file ) )
        {
            error_log( PML_PLUGIN_NAME . ' Error: .htaccess file does not exist.' );
            return false;
        }

        $existing_rules = extract_from_markers( $htaccess_file, PML_PLUGIN_NAME );
        error_log(
            PML_PLUGIN_NAME . ' Found ' .
            count( $existing_rules ) .
            ' existing rules in .htaccess for ' . PML_PLUGIN_NAME . '.',
        );
        return !empty( $existing_rules );
    }
}
