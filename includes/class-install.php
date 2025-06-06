<?php
/**
 * Plugin Installation, Activation, Deactivation, Uninstall
 * Version: 1.2.0 (Feature: Token Management on Media Edit Page)
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
    /**
     * Stores the current database version of this plugin.
     * This should be updated when the database schema changes.
     */
    const CURRENT_DB_VERSION = '1.1.0';

    public static function activate()
    {
        if ( class_exists( 'PML_Token_Manager' ) )
        {
            PML_Token_Manager::init();
        }
        else
        {
            error_log(
                PML_PLUGIN_NAME . ' Error: PML_Token_Manager class not found during activation. Token table might not be initialized correctly.',
            );

            if ( is_admin() )
            {
                add_action(
                    'admin_notices',
                    function () {
                        echo sprintf(
                            "<div class=\"notice notice-error\"><p>%s</p></div>",
                            esc_html__(
                                'Protected Media Links: Token Manager initialization failed. Please check the plugin files.',
                                'protected-media-links',
                            ),
                        );
                    },
                );
            }
        }

        self::create_database_table();
        self::run_database_upgrades();
        self::set_default_options();
        self::manage_htaccess_rules();

        $cleanup_enabled = get_option( PML_PREFIX . '_settings_cleanup_tokens_enabled', true ); // Default to true if not set
        if ( $cleanup_enabled )
        {
            if ( !wp_next_scheduled( PML_PREFIX . '_daily_token_cleanup_hook' ) )
            {
                wp_schedule_event( time(), 'daily', PML_PREFIX . '_daily_token_cleanup_hook' );
            }
        }
        else
        {
            wp_clear_scheduled_hook( PML_PREFIX . '_daily_token_cleanup_hook' );
        }

        flush_rewrite_rules();

        if ( !self::are_htaccess_rules_present() )
        {
            set_transient( PML_PREFIX . '_admin_notice_htaccess_needed', true, MINUTE_IN_SECONDS * 5 );
        }

        // Set the initial DB version if it's a fresh install
        if ( false === get_option( PML_PREFIX . '_db_version' ) )
        {
            update_option( PML_PREFIX . '_db_version', self::CURRENT_DB_VERSION );
        }
    }

    public static function deactivate()
    {
        self::manage_htaccess_rules( false );
        wp_clear_scheduled_hook( PML_PREFIX . '_daily_token_cleanup_hook' );
        flush_rewrite_rules();
    }

    public static function uninstall()
    {
        global $wpdb;
        // Ensure Token Manager table name is available if init wasn't run
        $table_name = $wpdb->prefix . PML_PREFIX . '_access_tokens';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name construction is controlled.
        $wpdb->query( "DROP TABLE IF EXISTS `$table_name`" );

        $options_to_delete = [
            PML_PREFIX . '_settings_default_redirect_url',
            PML_PREFIX . '_settings_default_token_expiry',
            PML_PREFIX . '_settings_default_token_max_uses',
            PML_PREFIX . '_settings_allow_bots',
            PML_PREFIX . '_settings_bot_user_agents',
            PML_PREFIX . '_settings_verified_bot_domains',
            PML_PREFIX . '_settings_cleanup_tokens_enabled',
            PML_PREFIX . '_settings_cleanup_delete_old_tokens',
            PML_PREFIX . '_settings_cleanup_delete_age_months',
            PML_PREFIX . '_settings_bot_dns_cache_ttl',
            PML_PREFIX . '_settings_handle_unmanaged_files',
            PML_PREFIX . '_global_user_allow_list',
            PML_PREFIX . '_global_user_deny_list',
            PML_PREFIX . '_global_role_allow_list',
            PML_PREFIX . '_global_role_deny_list',
            PML_PREFIX . '_db_version',
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

        // PML_Token_Manager::init() should have been called by activate() before this.
        // If not, use a direct construction.
        $table_name = !empty( PML_Token_Manager::$table_name ) ? PML_Token_Manager::$table_name : $wpdb->prefix . PML_PREFIX . '_access_tokens';

        $charset_collate = $wpdb->get_charset_collate();

        // Schema definition includes the new 'last_used_at' column
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
            last_used_at DATETIME NULL DEFAULT NULL COMMENT 'UTC timestamp of the last successful token usage',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			UNIQUE KEY token_value (token_value),
			KEY attachment_id (attachment_id),
			KEY status (status),
			KEY expires_at (expires_at),
            KEY last_used_at (last_used_at)
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
            PML_PREFIX . '_settings_default_redirect_url'      => home_url( '/' ),
            PML_PREFIX . '_settings_default_token_expiry'      => 24 * HOUR_IN_SECONDS,
            PML_PREFIX . '_settings_default_token_max_uses'    => 1,
            PML_PREFIX . '_settings_allow_bots'                => '1',
            PML_PREFIX . '_settings_bot_user_agents'           => implode( "\n", PML_Settings::get_default_bot_user_agents() ),
            PML_PREFIX . '_settings_verified_bot_domains'      => implode( "\n", PML_Settings::get_default_bot_domains() ),
            PML_PREFIX . '_settings_cleanup_tokens_enabled'    => '1', // Default to enabled
            PML_PREFIX . '_settings_cleanup_delete_old_tokens' => '0', // Default to not delete
            PML_PREFIX . '_settings_cleanup_delete_age_months' => 6, // Default age if deletion is enabled
            PML_PREFIX . '_settings_bot_dns_cache_ttl'         => HOUR_IN_SECONDS,
            PML_PREFIX . '_settings_handle_unmanaged_files'    => 'serve_publicly', // Default behavior for non-library files
            PML_PREFIX . '_global_user_allow_list'             => [],
            PML_PREFIX . '_global_user_deny_list'              => [],
            PML_PREFIX . '_global_role_allow_list'             => [ 'administrator' ],
            PML_PREFIX . '_global_role_deny_list'              => [],
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
            require_once ABSPATH . 'wp-admin/includes/misc.php'; // extract_from_markers is in misc.php
        }

        $htaccess_file = get_home_path() . '.htaccess';
        $marker_name   = PML_PLUGIN_NAME;

        if ( !file_exists( $htaccess_file ) )
        {
            if ( !is_writable( get_home_path() ) )
            {
                error_log( PML_PLUGIN_NAME . ' Htaccess Error: Cannot create .htaccess, home path not writable.' );
                return false;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
            if ( false === file_put_contents( $htaccess_file, '' ) )
            {
                error_log( PML_PLUGIN_NAME . ' Htaccess Error: Failed to create empty .htaccess file.' );
                return false;
            }
        }

        if ( !is_writable( $htaccess_file ) )
        {
            error_log( PML_PLUGIN_NAME . ' Htaccess Error: .htaccess file is not writable at ' . $htaccess_file );
            // Set transient for admin notice
            set_transient( PML_PREFIX . '_admin_notice_htaccess_writable', true, MINUTE_IN_SECONDS * 5 );
            return false;
        }

        $rules = [];
        if ( $add )
        {
            $rules = [
                'RewriteEngine On',
                'RewriteCond %{REQUEST_FILENAME} -f', // Only apply if the requested file exists
                'RewriteRule ^wp-content/uploads/(.*)$ index.php?' . PML_PREFIX . '_media_request=$1 [QSA,L]',
            ];
        }

        // insert_with_markers will remove the block if $rules is empty.
        $result = insert_with_markers( $htaccess_file, $marker_name, $rules );
        if ( !$result && $add )
        {
            error_log( PML_PLUGIN_NAME . ' Htaccess Error: insert_with_markers failed to add rules.' );
            set_transient( PML_PREFIX . '_admin_notice_htaccess_needed', true, MINUTE_IN_SECONDS * 5 );
        }
        elseif ( !$result && !$add )
        {
            error_log( PML_PLUGIN_NAME . ' Htaccess Error: insert_with_markers failed to remove rules.' );
        }

        return $result;
    }

    public static function are_htaccess_rules_present(): bool
    {
        if ( !function_exists( 'extract_from_markers' ) )
        {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $htaccess_file = get_home_path() . '.htaccess';
        if ( !file_exists( $htaccess_file ) )
        {
            return false;
        }

        $existing_rules = extract_from_markers( $htaccess_file, PML_PLUGIN_NAME );
        return !empty( $existing_rules );
    }

    /**
     * Runs database upgrade routines.
     * This should be hooked to 'plugins_loaded' or similar early action.
     */
    public static function run_database_upgrades()
    {
        $installed_db_version = get_option( PML_PREFIX . '_db_version', '1.0.0' ); // Default to 1.0.0 if not set

        if ( !version_compare( $installed_db_version, self::CURRENT_DB_VERSION, '<' ) )
        {
            return;
        }

        // Upgrade to 1.1.0: Add 'last_used_at' column
        if ( version_compare( $installed_db_version, '1.1.0', '<' ) )
        {
            self::upgrade_to_db_version_1_1_0();
            update_option( PML_PREFIX . '_db_version', '1.1.0' );
            $installed_db_version = '1.1.0'; // Update for subsequent checks if any
        }

        // Future upgrades would go here in similar if blocks
        // if ( version_compare( $installed_db_version, '1.2.0', '<' ) ) {
        // self::upgrade_to_db_version_1_2_0();
        // update_option( PML_PREFIX . '_db_version', '1.2.0' );
        // $installed_db_version = '1.2.0';
        // }

        // Ensure the final version stored is the absolute current one from the constant
        update_option( PML_PREFIX . '_db_version', self::CURRENT_DB_VERSION );
    }

    /**
     * Database upgrade logic for version 1.1.0.
     * Adds the `last_used_at` column to the tokens table.
     */
    private static function upgrade_to_db_version_1_1_0()
    {
        global $wpdb;
        $table_name  = !empty( PML_Token_Manager::$table_name ) ? PML_Token_Manager::$table_name : $wpdb->prefix . PML_PREFIX . '_access_tokens';
        $column_name = 'last_used_at';

        if ( !self::db_column_exists( $table_name, $column_name ) )
        {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is controlled.
            $result = $wpdb->query(
                "ALTER TABLE `$table_name` ADD COLUMN `$column_name` DATETIME NULL DEFAULT NULL COMMENT 'UTC timestamp of the last successful token usage' AFTER `max_uses`;",
            );
            if ( false === $result )
            {
                error_log(
                    PML_PLUGIN_NAME . " Error: Failed to add '$column_name' column to '$table_name' table. DB Error: " . $wpdb->last_error,
                );
            }
            else
            {
                error_log( PML_PLUGIN_NAME . " Success: Added '$column_name' column to '$table_name' table." );
            }
        }
    }

    /**
     * Checks if a database column exists in a table.
     *
     * @param string $table_name  The name of the table.
     * @param string $column_name The name of the column.
     *
     * @return bool True if the column exists, false otherwise.
     */
    public static function db_column_exists( string $table_name, string $column_name ): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $column = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `$table_name` LIKE %s", // Table name is interpolated, but comes from controlled source
                $column_name,
            ),
        );
        return !empty( $column );
    }
}
