<?php
/**
 * Plugin Settings Page
 *
 * @package AccessLens
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class PML_Settings
{
    private string $option_group;
    private string $option_name_prefix;

    public function __construct()
    {
        $this->option_group       = PML_PREFIX . '_settings_group';
        $this->option_name_prefix = PML_PREFIX . '_settings_';

        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        add_action( 'admin_notices', [ $this, 'display_server_config_needed_notice' ] );
    }

    /**
     * Enqueues scripts and styles specific to the PML Settings Page.
     */
    public function enqueue_admin_scripts( string $hook_suffix ): void
    {
        $main_settings_hook     = 'toplevel_page_' . PML_PLUGIN_SLUG;
        $shortcodes_hook_suffix = PML_PLUGIN_SLUG . '-shortcodes';

        if ( $hook_suffix !== $main_settings_hook && str_ends_with( $hook_suffix, $shortcodes_hook_suffix ) === false )
        {
            return;
        }

        // --- Common Assets for All PML Pages ---
        wp_enqueue_style(
            'select2',
            "https://cdnjs.cloudflare.com/ajax/libs/select2/" . PML_SELECT2_VERSION . "/css/select2.min.css",
            [],
            PML_SELECT2_VERSION,
        );
        wp_enqueue_script(
            'select2',
            "https://cdnjs.cloudflare.com/ajax/libs/select2/" . PML_SELECT2_VERSION . "/js/select2.min.js",
            [ 'jquery' ],
            PML_SELECT2_VERSION,
            true,
        );
        wp_enqueue_style( PML_PLUGIN_SLUG . '-admin-common-css', PML_PLUGIN_URL . 'admin/assets/css/common.css', [ 'dashicons' ], PML_VERSION );
        wp_enqueue_script(
            PML_PLUGIN_SLUG . '-admin-common-utils-js',
            PML_PLUGIN_URL . 'admin/assets/js/common-utils.js',
            [ 'jquery', 'wp-i18n' ],
            PML_VERSION,
            true,
        );
        wp_set_script_translations( PML_PLUGIN_SLUG . '-admin-common-utils-js', PML_TEXT_DOMAIN, PML_PLUGIN_DIR . 'languages' );

        // --- Assets for Main Settings Page ---
        if ( $hook_suffix === $main_settings_hook )
        {
            wp_enqueue_style(
                PML_PLUGIN_SLUG . '-settings-page-css',
                PML_PLUGIN_URL . 'admin/assets/css/settings-page.css',
                [ PML_PLUGIN_SLUG . '-admin-common-css', 'select2' ],
                PML_VERSION,
            );
            wp_enqueue_script(
                PML_PLUGIN_SLUG . '-settings-page-js',
                PML_PLUGIN_URL . 'admin/assets/js/settings-page.js',
                [ 'jquery', 'select2', PML_PLUGIN_SLUG . '-admin-common-utils-js', 'wp-i18n' ],
                PML_VERSION,
                true,
            );
            wp_set_script_translations( PML_PLUGIN_SLUG . '-settings-page-js', PML_TEXT_DOMAIN, PML_PLUGIN_DIR . 'languages' );

            wp_localize_script(
                PML_PLUGIN_SLUG . '-settings-page-js',
                'pml_admin_params',
                [
                    'ajax_url'                => admin_url( 'admin-ajax.php' ),
                    'search_users_nonce'      => wp_create_nonce( PML_PREFIX . '_search_users_nonce' ),
                    'user_select_placeholder' => esc_html__( 'Search for users...', PML_TEXT_DOMAIN ),
                    'confirm_remove_user'     => esc_html__( 'Are you sure you want to remove this user from the list?', PML_TEXT_DOMAIN ),
                    'error_searching_users'   => esc_html__( 'Error searching users. Please try again or contact support.', PML_TEXT_DOMAIN ),
                    'error_ajax_failed'       => esc_html__(
                        'An AJAX error occurred. Please check your connection or contact support.',
                        PML_TEXT_DOMAIN,
                    ),
                    'default_bot_user_agents' => $this->get_default_bot_user_agents(),
                    'default_bot_domains'     => $this->get_default_bot_domains(),
                ],
            );
        }

        // --- Assets for Shortcodes Page ---
        if ( str_ends_with( $hook_suffix, $shortcodes_hook_suffix ) )
        {
            wp_enqueue_style(
                PML_PLUGIN_SLUG . '-shortcodes-page-css',
                PML_PLUGIN_URL . 'admin/assets/css/shortcodes-page.css',
                [ 'wp-components' ],
                PML_VERSION,
            );
            wp_enqueue_script(
                PML_PLUGIN_SLUG . '-shortcode-generator-js',
                PML_PLUGIN_URL . 'admin/assets/js/shortcode-generator.js',
                [ 'jquery', 'select2', 'wp-i18n' ],
                PML_VERSION,
                true,
            );
            wp_set_script_translations( PML_PLUGIN_SLUG . '-shortcode-generator-js', PML_TEXT_DOMAIN, PML_PLUGIN_DIR . 'languages' );

            wp_localize_script(
                PML_PLUGIN_SLUG . '-shortcode-generator-js',
                'pml_shortcode_params',
                [
                    'ajax_url'                 => admin_url( 'admin-ajax.php' ),
                    'search_media_nonce'       => wp_create_nonce( 'pml_search_media_nonce' ),
                    'media_select_placeholder' => esc_html__( 'Search for a media file...', PML_TEXT_DOMAIN ),
                    'text_copied'              => esc_html__( 'Copied!', PML_TEXT_DOMAIN ),
                ],
            );
        }
    }

    public function add_settings_page()
    {
        // create the top-level menu page.
        add_menu_page(
            PML_PLUGIN_NAME,
            'Access Lens',
            'manage_options',
            PML_PLUGIN_SLUG,
            [ $this, 'render_settings_page' ],
            'dashicons-lock',
            10, // set next to media library menu
        );

        // add the "settings" sub-page.
        // the first sub-page with the same slug as the parent becomes the main link.
        add_submenu_page(
            PML_PLUGIN_SLUG,
            PML_PLUGIN_NAME . ' ' . esc_html__( 'Settings', PML_TEXT_DOMAIN ),
            esc_html__( 'Settings', PML_TEXT_DOMAIN ),
            'manage_options',
            PML_PLUGIN_SLUG,
            [ $this, 'render_settings_page' ],
        );

        // add the "shortcodes" sub-page.
        add_submenu_page(
            PML_PLUGIN_SLUG,
            esc_html__( 'Shortcodes', PML_TEXT_DOMAIN ) . ' - ' . PML_PLUGIN_NAME,
            esc_html__( 'Shortcodes', PML_TEXT_DOMAIN ),
            'manage_options',
            PML_PLUGIN_SLUG . '-shortcodes',
            [ $this, 'render_shortcodes_page' ],
        );
    }

    public function register_settings()
    {
        $settings_sections = $this->get_plugin_settings_sections_and_fields();

        foreach ( $settings_sections as $section_id => $section_data )
        {
            add_settings_section(
                $section_id,
                $section_data[ 'title' ],
                $section_data[ 'callback' ] ?? null,
                $this->option_group,
            );

            if ( !empty( $section_data[ 'fields' ] ) )
            {
                foreach ( $section_data[ 'fields' ] as $field_id => $field_args )
                {
                    $option_name = $this->option_name_prefix . $field_id;
                    $field_type  = $field_args[ 'type' ] ?? '';

                    if ( in_array( $field_type, [ 'user_list_manager', 'role_list_manager' ], true ) )
                    {
                        register_setting( $this->option_group, PML_PREFIX . '_global_user_allow_list', [ $this, 'sanitize_user_list' ] );
                        register_setting( $this->option_group, PML_PREFIX . '_global_user_deny_list', [ $this, 'sanitize_user_list' ] );
                        register_setting( $this->option_group, PML_PREFIX . '_global_role_allow_list', [ $this, 'sanitize_role_list' ] );
                        register_setting( $this->option_group, PML_PREFIX . '_global_role_deny_list', [ $this, 'sanitize_role_list' ] );

                        add_settings_field(
                            PML_PREFIX . '_' . $field_id . '_manager',
                            $field_args[ 'title' ],
                            $field_args[ 'render_callback' ],
                            $this->option_group,
                            $section_id,
                            [
                                'option_name_allow' => PML_PREFIX . '_global_' . ( $field_args[ 'list_type' ] ?? 'user' ) . '_allow_list',
                                'option_name_deny'  => PML_PREFIX . '_global_' . ( $field_args[ 'list_type' ] ?? 'user' ) . '_deny_list',
                                'field_args'        => $field_args[ 'args' ] ?? [],
                                'label_for'         => PML_PREFIX . '_' . $field_id . '_manager',
                            ],
                        );
                    }
                    else
                    {
                        register_setting(
                            $this->option_group,
                            $option_name,
                            $field_args[ 'sanitize_callback' ] ?? 'sanitize_text_field',
                        );

                        add_settings_field(
                            $option_name,
                            $field_args[ 'title' ],
                            $field_args[ 'render_callback' ],
                            $this->option_group,
                            $section_id,
                            [
                                'label_for'   => $option_name,
                                'option_name' => $option_name,
                                'field_args'  => $field_args[ 'args' ] ?? [],
                            ],
                        );
                    }
                }
            }
        }
    }

    private function get_plugin_settings_sections_and_fields(): array
    {
        $all_roles_editable = [];
        $editable_roles     = get_editable_roles();
        foreach ( $editable_roles as $role_slug => $role_details )
        {
            $all_roles_editable[ $role_slug ] = translate_user_role( $role_details[ 'name' ] );
        }
        asort( $all_roles_editable );

        return [
            $this->option_name_prefix . 'access_control_section'       => [
                'title'    => esc_html__( 'Global Access Control Lists', PML_TEXT_DOMAIN ),
                'callback' => [ $this, 'render_access_control_section_callback' ],
                'fields'   => [
                    'global_user_lists' => [
                        'title'           => esc_html__( 'Global User Lists', PML_TEXT_DOMAIN ),
                        'render_callback' => [ $this, 'render_user_list_manager_field' ],
                        'type'            => 'user_list_manager',
                        'list_type'       => 'user',
                    ],
                    'global_role_lists' => [
                        'title'           => esc_html__( 'Global Role Lists', PML_TEXT_DOMAIN ),
                        'render_callback' => [ $this, 'render_role_list_manager_field' ],
                        'type'            => 'role_list_manager',
                        'list_type'       => 'role',
                        'args'            => [ 'roles' => $all_roles_editable ],
                    ],
                ],
            ],
            $this->option_name_prefix . 'general_settings_section'     => [
                'title'    => esc_html__( 'General Protection Settings', PML_TEXT_DOMAIN ),
                'callback' => function () {
                    echo '<div class="pml-section-description-wrapper"><p>' .
                         esc_html__( 'Configure default behaviors for protected media links.', PML_TEXT_DOMAIN ) .
                         '</p></div>';
                },
                'fields'   => [
                    'default_redirect_url'   => [
                        'title'             => esc_html__( 'Default Unauthorized Redirect URL', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_text_field' ],
                        'sanitize_callback' => 'esc_url_raw',
                        'args'              => [
                            'description' => esc_html__(
                                'Global fallback URL for unauthorized access. Leave blank to redirect to homepage.',
                                PML_TEXT_DOMAIN,
                            ),
                            'input_type'  => 'url',
                            'placeholder' => home_url( '/' ),
                        ],
                    ],
                    'handle_unmanaged_files' => [
                        'title'             => esc_html__( 'Handle Non-Media Library Files in Uploads', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_select_field' ],
                        'sanitize_callback' => 'sanitize_key',
                        'args'              => [
                            'description' => esc_html__(
                                'How to handle direct requests (via pml_media_request) for files in wp-content/uploads/ that are not in the Media Library.',
                                PML_TEXT_DOMAIN,
                            ),
                            'options'     => [
                                'serve_publicly' => esc_html__( 'Serve Publicly (like a direct link)', PML_TEXT_DOMAIN ),
                                'deny_access'    => esc_html__( 'Deny Access (use default redirect)', PML_TEXT_DOMAIN ),
                            ],
                        ],
                    ],
                ],
            ],
            $this->option_name_prefix . 'token_settings_section'       => [
                'title'    => esc_html__( 'Access Token Settings', PML_TEXT_DOMAIN ),
                'callback' => function () {
                    echo '<div class="pml-section-description-wrapper"><p>' .
                         esc_html__( 'Set default parameters for access tokens generated by the plugin.', PML_TEXT_DOMAIN ) .
                         '</p></div>';
                },
                'fields'   => [
                    'default_token_expiry'   => [
                        'title'             => esc_html__( 'Default Token Expiry Duration', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_select_field' ],
                        'sanitize_callback' => 'intval',
                        'args'              => [
                            'description' => esc_html__( 'Default validity period for generated access tokens.', PML_TEXT_DOMAIN ),
                            'options'     => $this->get_token_expiry_options(),
                        ],
                    ],
                    'default_token_max_uses' => [
                        'title'             => esc_html__( 'Default Token Maximum Uses', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_number_field' ],
                        'sanitize_callback' => 'absint',
                        'args'              => [
                            'description' => esc_html__(
                                'Default maximum number of times a token can be used. Enter 0 for unlimited uses.',
                                PML_TEXT_DOMAIN,
                            ),
                            'min'         => 0,
                            'step'        => 1,
                            'placeholder' => '1',
                        ],
                    ],
                ],
            ],
            $this->option_name_prefix . 'bot_settings_section'         => [
                'title'    => esc_html__( 'Search Engine Bot Access', PML_TEXT_DOMAIN ),
                'callback' => function () {
                    echo '<div class="pml-section-description-wrapper"><p>' .
                         esc_html__( 'Configure how verified search engine bots access protected content for SEO indexing.', PML_TEXT_DOMAIN ) .
                         '</p></div>';
                },
                'fields'   => [
                    'allow_bots'           => [
                        'title'             => esc_html__( 'Globally Allow Bot Access', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_checkbox_field' ],
                        'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
                        'args'              => [
                            'description' => esc_html__(
                                'Allow verified search engine bots to access protected files. Can be overridden per file.',
                                PML_TEXT_DOMAIN,
                            ),
                        ],
                    ],
                    'bot_user_agents'      => [
                        'title'             => esc_html__( 'Bot User Agent Signatures', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_textarea_with_button_field' ],
                        'sanitize_callback' => [ $this, 'sanitize_textarea_lines' ],
                        'args'              => [
                            'description' => esc_html__(
                                'List of User-Agent strings (or parts) to identify potential bots. One per line. Case-insensitive.',
                                PML_TEXT_DOMAIN,
                            ),
                            'rows'        => 5,
                            'placeholder' => "googlebot\nbingbot\nslurp",
                            'button_id'   => 'pml_add_default_user_agents',
                            'button_text' => esc_html__( 'Add Default Search Engine UAs', PML_TEXT_DOMAIN ),
                            'data_type'   => 'user_agents',
                        ],
                    ],
                    'verified_bot_domains' => [
                        'title'             => esc_html__( 'Verified Bot Hostname Suffixes', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_textarea_with_button_field' ],
                        'sanitize_callback' => [ $this, 'sanitize_textarea_lines' ],
                        'args'              => [
                            'description' => esc_html__(
                                'Hostname suffixes used for rDNS/fDNS verification (e.g., .googlebot.com). One per line. Case-insensitive.',
                                PML_TEXT_DOMAIN,
                            ),
                            'rows'        => 5,
                            'placeholder' => ".googlebot.com\n.search.msn.com",
                            'button_id'   => 'pml_add_default_bot_domains',
                            'button_text' => esc_html__( 'Add Default Search Engine Domains', PML_TEXT_DOMAIN ),
                            'data_type'   => 'domains',
                        ],
                    ],
                    'bot_dns_cache_ttl'    => [
                        'title'             => esc_html__( 'Bot DNS Verification Cache TTL', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_select_field' ],
                        'sanitize_callback' => 'intval',
                        'args'              => [
                            'description' => esc_html__(
                                'How long to cache the results of bot DNS verification (rDNS/fDNS lookups). Shorter times are more accurate but increase server load.',
                                PML_TEXT_DOMAIN,
                            ),
                            'options'     => $this->get_cache_ttl_options(),
                        ],
                    ],
                ],
            ],
            $this->option_name_prefix . 'maintenance_settings_section' => [
                'title'    => esc_html__( 'Maintenance', PML_TEXT_DOMAIN ),
                'callback' => function () {
                    echo '<div class="pml-section-description-wrapper"><p>' .
                         esc_html__( 'Configure automated maintenance tasks for the plugin.', PML_TEXT_DOMAIN ) .
                         '</p></div>';
                },
                'fields'   => [
                    'cleanup_tokens_enabled'    => [
                        'title'             => esc_html__( 'Enable Daily Token Cleanup', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_checkbox_field' ],
                        'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
                        'args'              => [
                            'description' => esc_html__(
                                'Run a daily WP Cron job to update status of expired tokens and optionally delete very old ones.',
                                PML_TEXT_DOMAIN,
                            ),
                        ],
                    ],
                    'cleanup_delete_old_tokens' => [
                        'title'             => esc_html__( 'Delete Very Old Tokens', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_checkbox_field' ],
                        'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
                        'args'              => [
                            'description' => esc_html__(
                                'During daily cleanup, also delete tokens (status: used, expired, revoked) older than the specified age. This helps keep the database table lean.',
                                PML_TEXT_DOMAIN,
                            ),
                        ],
                    ],
                    'cleanup_delete_age_months' => [
                        'title'             => esc_html__( 'Age to Delete Old Tokens (Months)', PML_TEXT_DOMAIN ),
                        'render_callback'   => [ $this, 'render_number_field' ],
                        'sanitize_callback' => 'absint',
                        'args'              => [
                            'description' => esc_html__(
                                'Define how many months old a non-active token must be before it is deleted by the cleanup job. Only applies if "Delete Very Old Tokens" is enabled.',
                                PML_TEXT_DOMAIN,
                            ),
                            'min'         => 1,
                            'step'        => 1,
                            'placeholder' => '6',
                        ],
                    ],
                ],
            ],
            $this->option_name_prefix . 'server_config_section'        => [
                'title'    => esc_html__( 'Server Configuration', PML_TEXT_DOMAIN ),
                'callback' => [ $this, 'render_server_config_info_section' ],
                'fields'   => [],
            ],
        ];
    }

    public function render_access_control_section_callback()
    {
        echo '<div class="pml-section-description-wrapper">';
        echo '<p>' . esc_html__(
                'Define site-wide rules for allowing or denying access to protected media. These can be overridden on a per-file basis.',
                PML_TEXT_DOMAIN,
            ) . '</p>';
        ?>
        <div class="pml-priority-tip-alert notice notice-info inline pml-collapsible-tip">
            <div class="pml-collapsible-tip-trigger" tabindex="0" role="button" aria-expanded="false" aria-controls="pml-priority-details">
                <span class="dashicons dashicons-info-outline pml-tip-icon"></span>
                <h4><?php esc_html_e( 'Understanding Access Rule Priority', PML_TEXT_DOMAIN ); ?></h4>
                <span class="dashicons pml-collapsible-indicator dashicons-arrow-down-alt2"></span>
            </div>
            <div id="pml-priority-details" class="pml-collapsible-content" style="display: none;">
                <p><?php esc_html_e( 'Access is determined by the first matching rule in the following order:', PML_TEXT_DOMAIN ); ?></p>
                <ol>
                    <li><?php esc_html_e( 'Global User Allow List', PML_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Global User Deny List', PML_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Per-File User Allow List', PML_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Per-File User Deny List', PML_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Global Role Allow List', PML_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Global Role Deny List', PML_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Per-File Role Allow List', PML_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Per-File Role Deny List', PML_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Verified Bot Access (if enabled)', PML_TEXT_DOMAIN ); ?></li>
                    <li><?php esc_html_e( 'Valid Access Token', PML_TEXT_DOMAIN ); ?></li>
                </ol>
                <p>
                    <?php esc_html_e(
                        'For example, if a user is on the "Global User Allow List", they gain access regardless of other deny rules at lower priorities (like role-based or per-file deny). Conversely, a "Global User Deny" will block access even if a role they have is on an allow list. Per-file settings generally override global settings within the same category (user or role).',
                        PML_TEXT_DOMAIN,
                    ); ?>
                </p>
            </div>
        </div>
        <?php
        echo '</div>';
    }

    public static function get_token_expiry_options(): array
    {
        return [
            '3600'    => esc_html__( '1 Hour', PML_TEXT_DOMAIN ),
            '14400'   => esc_html__( '4 Hours', PML_TEXT_DOMAIN ),
            '86400'   => esc_html__( '1 Day (24 Hours)', PML_TEXT_DOMAIN ),
            '604800'  => esc_html__( '7 Days', PML_TEXT_DOMAIN ),
            '2592000' => esc_html__( '30 Days', PML_TEXT_DOMAIN ),
            '0'       => esc_html__( 'No Expiry (Not Recommended)', PML_TEXT_DOMAIN ),
        ];
    }

    private static function get_cache_ttl_options(): array
    {
        return [
            '300'   => esc_html__( '5 Minutes', PML_TEXT_DOMAIN ),
            '900'   => esc_html__( '15 Minutes', PML_TEXT_DOMAIN ),
            '3600'  => esc_html__( '1 Hour', PML_TEXT_DOMAIN ),
            '14400' => esc_html__( '4 Hours', PML_TEXT_DOMAIN ),
            '86400' => esc_html__( '24 Hours', PML_TEXT_DOMAIN ),
        ];
    }

    public static function get_default_bot_user_agents(): array
    {
        return [
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'sogou',
            'exabot',
            'facebot',
            'ia_archiver',
            'applebot',
            'ahrefsbot',
            'semrushbot',
            'mj12bot',
            'dotbot',
            'petalbot',
            'bytespider',
        ];
    }

    public static function get_default_bot_domains(): array
    {
        return [
            '.googlebot.com',
            '.google.com',
            '.search.msn.com',
            '.crawl.yahoo.net',
            '.baidu.com',
            '.yandex.com',
            '.sogou.com',
            '.exabot.com',
            '.applebot.apple.com',
            'ahrefs.com',
            'semrush.com',
            'mj12bot.com',
            'dotbot.org',
            'petalbot.com',
            'bytespider.com',
        ];
    }

    public function render_settings_page()
    {
        if ( !current_user_can( 'manage_options' ) )
        {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', PML_TEXT_DOMAIN ) );
        }
        ?>
        <div class="wrap pml-settings-wrap">
            <h1><?php echo esc_html( PML_PLUGIN_NAME ); ?><?php esc_html_e( 'Settings', PML_TEXT_DOMAIN ); ?></h1>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields( $this->option_group );
                do_settings_sections( $this->option_group );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_shortcodes_page()
    {
        if ( !current_user_can( 'manage_options' ) )
        {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', PML_TEXT_DOMAIN ) );
        }
        ?>
        <div class="wrap pml-shortcodes-wrap">
            <div class="pml-page-header">
                <h1><?php esc_html_e( 'Shortcode Generator', PML_TEXT_DOMAIN ); ?></h1>
                <p><?php esc_html_e(
                        'Use this tool to easily generate and copy shortcodes for use in your posts, pages, and widgets.',
                        PML_TEXT_DOMAIN,
                    ); ?></p>
            </div>

            <div class="pml-generator-grid">
                <div class="pml-generator-form-container">
                    <h2 class="title"><?php esc_html_e( 'Generator', PML_TEXT_DOMAIN ); ?></h2>
                    <form id="pml-shortcode-generator-form">
                        <fieldset>
                            <legend><?php esc_html_e( 'Core Link Settings', PML_TEXT_DOMAIN ); ?></legend>
                            <div class="form-field">
                                <label for="pml-media-id"><?php esc_html_e( 'Media File (Required)', PML_TEXT_DOMAIN ); ?></label>
                                <select id="pml-media-id" name="pml_media_id" style="width:100%;"></select>
                            </div>
                            <div class="form-field">
                                <label for="pml-text"><?php esc_html_e( 'Link Text (Optional)', PML_TEXT_DOMAIN ); ?></label>
                                <input type="text"
                                       id="pml-text"
                                       name="pml_text"
                                       placeholder="<?php esc_attr_e( 'Defaults to file title', PML_TEXT_DOMAIN ); ?>">
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend><?php esc_html_e( 'Token Behavior', PML_TEXT_DOMAIN ); ?></legend>
                            <div class="form-field">
                                <label for="pml-duration"><?php esc_html_e( 'Token Duration (Optional)', PML_TEXT_DOMAIN ); ?></label>
                                <select id="pml-duration" name="pml_duration">
                                    <option value=""><?php esc_html_e( 'Use Global Default', PML_TEXT_DOMAIN ); ?> (<?php echo esc_html(
                                            PML_Core::format_duration( get_option( PML_PREFIX . '_settings_default_token_expiry' ) ),
                                        ); ?>)
                                    </option>
                                    <?php foreach ( self::get_token_expiry_options() as $seconds => $label ) : ?>
                                        <option value="<?php echo esc_attr( $seconds ); ?>"><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-field">
                                <label for="pml-max-uses"><?php esc_html_e( 'Maximum Uses (Optional)', PML_TEXT_DOMAIN ); ?></label>
                                <input type="number"
                                       id="pml-max-uses"
                                       name="pml_max_uses"
                                       min="0"
                                       step="1"
                                       placeholder="<?php esc_attr_e( 'Uses file or global default', PML_TEXT_DOMAIN ); ?>">
                                <p class="description"><?php esc_html_e(
                                        'Enter 0 for unlimited. This will update the file\'s setting if set higher.',
                                        PML_TEXT_DOMAIN,
                                    ); ?></p>
                            </div>
                            <div class="form-field form-field-checkbox">
                                <label>
                                    <input type="checkbox" id="pml-protect" name="pml_protect" value="true" checked>
                                    <?php esc_html_e( 'Automatically protect this file if unprotected', PML_TEXT_DOMAIN ); ?>
                                </label>
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend><?php esc_html_e( 'Output Formatting', PML_TEXT_DOMAIN ); ?></legend>
                            <div class="form-field form-field-checkbox">
                                <label>
                                    <input type="checkbox" id="pml-html" name="pml_html" value="true" checked>
                                    <?php esc_html_e( 'Output as a full HTML link', PML_TEXT_DOMAIN ); ?>
                                </label>
                            </div>
                            <div id="pml-open-in-new-tab-wrapper" class="form-field form-field-checkbox">
                                <label>
                                    <input type="checkbox" id="pml-open_in_new_tab" name="pml_open_in_new_tab" value="true" checked>
                                    <?php esc_html_e( 'Open link in a new tab', PML_TEXT_DOMAIN ); ?>
                                </label>
                            </div>
                            <div class="form-field">
                                <label for="pml-class"><?php esc_html_e( 'CSS Class (Optional)', PML_TEXT_DOMAIN ); ?></label>
                                <input type="text"
                                       id="pml-class"
                                       name="pml_class"
                                       placeholder="<?php esc_attr_e( 'e.g., my-download-button', PML_TEXT_DOMAIN ); ?>">
                            </div>
                        </fieldset>
                    </form>
                </div>

                <div class="pml-generator-output-container">
                    <h2 class="title"><?php esc_html_e( 'Live Output', PML_TEXT_DOMAIN ); ?></h2>
                    <textarea id="pml-generated-shortcode" readonly rows="4"></textarea>
                    <div class="pml-copy-button-wrapper">
                        <button type="button" id="pml-copy-shortcode-button" class="button button-primary">
                            <span class="dashicons dashicons-admin-page"></span>
                            <?php esc_html_e( 'Copy Shortcode', PML_TEXT_DOMAIN ); ?>
                        </button>
                        <span class="pml-copy-feedback"></span>
                    </div>
                </div>
            </div>

            <div class="pml-shortcode-docs">
                <h2><?php esc_html_e( 'Shortcode Reference', PML_TEXT_DOMAIN ); ?></h2>
                <div class="pml-shortcode-reference">
                    <h3>[pml_token_link]</h3>
                    <p><?php esc_html_e( 'Generates a new token access link to a protected media file.', PML_TEXT_DOMAIN ); ?></p>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Attribute', PML_TEXT_DOMAIN ); ?></th>
                                <th><?php esc_html_e( 'Required', PML_TEXT_DOMAIN ); ?></th>
                                <th><?php esc_html_e( 'Description', PML_TEXT_DOMAIN ); ?></th>
                                <th><?php esc_html_e( 'Default Value', PML_TEXT_DOMAIN ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>id</code></td>
                                <td><strong><?php esc_html_e( 'Yes', PML_TEXT_DOMAIN ); ?></strong></td>
                                <td><?php esc_html_e( 'The ID of the media file from the Media Library.', PML_TEXT_DOMAIN ); ?></td>
                                <td><em>(None)</em></td>
                            </tr>
                            <tr>
                                <td><code>text</code></td>
                                <td>No</td>
                                <td><?php esc_html_e( 'The clickable text for the link. Ignored if html="false".', PML_TEXT_DOMAIN ); ?></td>
                                <td><?php esc_html_e( "The media file's title.", PML_TEXT_DOMAIN ); ?></td>
                            </tr>
                            <tr>
                                <td><code>duration</code></td>
                                <td>No</td>
                                <td><?php esc_html_e( "The token's validity period in seconds (e.g., 3600 for 1 hour).", PML_TEXT_DOMAIN ); ?></td>
                                <td><?php esc_html_e( 'The global default setting.', PML_TEXT_DOMAIN ); ?></td>
                            </tr>
                            <tr>
                                <td><code>max_uses</code></td>
                                <td>No</td>
                                <td><?php esc_html_e( 'The maximum number of times the token can be used.', PML_TEXT_DOMAIN ); ?></td>
                                <td><?php esc_html_e( "The file's override or global default.", PML_TEXT_DOMAIN ); ?></td>
                            </tr>
                            <tr>
                                <td><code>protect</code></td>
                                <td>No</td>
                                <td><?php esc_html_e( 'Automatically protect the media file if it isn\'t already.', PML_TEXT_DOMAIN ); ?></td>
                                <td><code>true</code></td>
                            </tr>
                            <tr>
                                <td><code>html</code></td>
                                <td>No</td>
                                <td><?php esc_html_e(
                                        'If "true", outputs a full <a> tag. If "false", outputs only the raw URL.',
                                        PML_TEXT_DOMAIN,
                                    ); ?></td>
                                <td><code>true</code></td>
                            </tr>
                            <tr>
                                <td><code>open_in_new_tab</code></td>
                                <td>No</td>
                                <td><?php esc_html_e(
                                        'If "true", adds target="_blank" to the link. Ignored if html="false".',
                                        PML_TEXT_DOMAIN,
                                    ); ?></td>
                                <td><code>true</code></td>
                            </tr>
                            <tr>
                                <td><code>class</code></td>
                                <td>No</td>
                                <td><?php esc_html_e(
                                        'Adds a custom CSS class to the <a> link element. Ignored if html="false".',
                                        PML_TEXT_DOMAIN,
                                    ); ?></td>
                                <td><em>(None)</em></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        <?php
    }

    public function render_text_field( array $args )
    {
        $option_name  = $args[ 'option_name' ];
        $option_value = get_option( $option_name, $args[ 'field_args' ][ 'placeholder' ] ?? '' );
        $input_type   = $args[ 'field_args' ][ 'input_type' ] ?? 'text';
        $placeholder  = $args[ 'field_args' ][ 'placeholder' ] ?? '';
        printf(
            '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" placeholder="%s" />',
            esc_attr( $input_type ),
            esc_attr( $args[ 'label_for' ] ),
            esc_attr( $option_name ),
            esc_attr( $option_value ),
            esc_attr( $placeholder ),
        );
        if ( isset( $args[ 'field_args' ][ 'description' ] ) )
        {
            printf( '<p class="description pml-field-description">%s</p>', wp_kses_post( $args[ 'field_args' ][ 'description' ] ) );
        }
    }

    public function render_number_field( array $args )
    {
        $option_name  = $args[ 'option_name' ];
        $option_value = get_option( $option_name, $args[ 'field_args' ][ 'placeholder' ] ?? 0 );
        $min          = $args[ 'field_args' ][ 'min' ] ?? 0;
        $step         = $args[ 'field_args' ][ 'step' ] ?? 1;
        $placeholder  = $args[ 'field_args' ][ 'placeholder' ] ?? '';
        printf(
            '<input type="number" id="%s" name="%s" value="%s" class="small-text" min="%s" step="%s" placeholder="%s" />',
            esc_attr( $args[ 'label_for' ] ),
            esc_attr( $option_name ),
            esc_attr( $option_value ),
            esc_attr( $min ),
            esc_attr( $step ),
            esc_attr( $placeholder ),
        );
        if ( isset( $args[ 'field_args' ][ 'description' ] ) )
        {
            printf( '<p class="description pml-field-description">%s</p>', wp_kses_post( $args[ 'field_args' ][ 'description' ] ) );
        }
    }

    public function render_select_field( array $args )
    {
        $option_name  = $args[ 'option_name' ];
        $option_value = get_option( $option_name );
        $options      = $args[ 'field_args' ][ 'options' ] ?? [];

        echo '<select id="' . esc_attr( $args[ 'label_for' ] ) . '" name="' . esc_attr( $option_name ) . '" class="pml-select-field">';
        foreach ( $options as $value => $label )
        {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $option_value, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        if ( isset( $args[ 'field_args' ][ 'description' ] ) )
        {
            printf( '<p class="description pml-field-description">%s</p>', wp_kses_post( $args[ 'field_args' ][ 'description' ] ) );
        }
    }

    public function render_checkbox_field( array $args )
    {
        $option_name  = $args[ 'option_name' ];
        $option_value = get_option( $option_name, '0' );
        $description  = $args[ 'field_args' ][ 'description' ] ?? '';

        echo '<label for="' . esc_attr( $args[ 'label_for' ] ) . '">';
        echo '<input type="checkbox" id="' .
             esc_attr( $args[ 'label_for' ] ) .
             '" name="' .
             esc_attr( $option_name ) .
             '" value="1" ' .
             checked( $option_value, '1', false ) .
             ' /> ';
        echo esc_html( $description );
        echo '</label>';
    }

    public function render_textarea_with_button_field( array $args )
    {
        $option_name  = $args[ 'option_name' ];
        $option_value = get_option( $option_name, '' );
        $field_args   = $args[ 'field_args' ];
        $rows         = $field_args[ 'rows' ] ?? 5;
        $cols         = $field_args[ 'cols' ] ?? 50;
        $placeholder  = $field_args[ 'placeholder' ] ?? '';
        $button_id    = $field_args[ 'button_id' ] ?? '';
        $button_text  = $field_args[ 'button_text' ] ?? esc_html__( 'Add Defaults', PML_TEXT_DOMAIN );
        $data_type    = $field_args[ 'data_type' ] ?? '';

        echo '<div class="pml-textarea-with-button">';
        if ( $button_id && $button_text )
        {
            printf(
                '<button type="button" id="%s" class="button button-secondary pml-add-defaults-button" data-target-textarea="%s" data-type="%s">%s</button>',
                esc_attr( $button_id ),
                esc_attr( $args[ 'label_for' ] ),
                esc_attr( $data_type ),
                esc_html( $button_text ),
            );
        }
        echo '<textarea id="' .
             esc_attr( $args[ 'label_for' ] ) .
             '" name="' .
             esc_attr( $option_name ) .
             '" rows="' .
             esc_attr( $rows ) .
             '" cols="' .
             esc_attr( $cols ) .
             '" class="large-text code" placeholder="' .
             esc_attr( $placeholder ) .
             '">' .
             esc_textarea( $option_value ) .
             '</textarea>';
        echo '</div>';

        if ( isset( $field_args[ 'description' ] ) )
        {
            printf( '<p class="description pml-field-description">%s</p>', wp_kses_post( $field_args[ 'description' ] ) );
        }
    }

    public function render_user_list_manager_field( array $args )
    {
        $allow_list_option_name = PML_PREFIX . '_global_user_allow_list';
        $deny_list_option_name  = PML_PREFIX . '_global_user_deny_list';

        $allow_list_ids = get_option( $allow_list_option_name, [] );
        $deny_list_ids  = get_option( $deny_list_option_name, [] );
        ?>
        <div class="pml-list-manager-container pml-user-list-manager">
            <fieldset>
                <legend class="pml-list-legend"><?php esc_html_e( 'Global User Allow List', PML_TEXT_DOMAIN ); ?></legend>
                <p class="pml-field-description"><?php esc_html_e(
                        'Users on this list will always be granted access to all protected media globally, overriding all other rules except per-file user deny lists.',
                        PML_TEXT_DOMAIN,
                    ); ?></p>
                <select id="pml_global_user_allow_list_select"
                        name="<?php echo esc_attr( $allow_list_option_name ); ?>[]"
                        multiple="multiple"
                        class="pml-user-select-ajax"
                        style="width:100%;"
                        data-placeholder="<?php esc_attr_e( 'Search and add users to allow list...', PML_TEXT_DOMAIN ); ?>">
                    <?php foreach ( $allow_list_ids as $user_id ) :
                        $user = get_userdata( $user_id );
                        if ( $user ) : ?>
                            <option value="<?php echo esc_attr( $user_id ); ?>" selected="selected"><?php echo esc_html(
                                    $user->display_name . ' (' . $user->user_login . ' - ' . $user->user_email . ')',
                                ); ?></option>
                        <?php endif;
                    endforeach; ?>
                </select>
            </fieldset>

            <fieldset>
                <legend class="pml-list-legend"><?php esc_html_e( 'Global User Deny List', PML_TEXT_DOMAIN ); ?></legend>
                <p class="pml-field-description"><?php esc_html_e(
                        'Users on this list will always be denied access globally, unless overridden by a per-file user allow list. This takes precedence over role-based allow rules.',
                        PML_TEXT_DOMAIN,
                    ); ?></p>
                <select id="pml_global_user_deny_list_select"
                        name="<?php echo esc_attr( $deny_list_option_name ); ?>[]"
                        multiple="multiple"
                        class="pml-user-select-ajax"
                        style="width:100%;"
                        data-placeholder="<?php esc_attr_e( 'Search and add users to deny list...', PML_TEXT_DOMAIN ); ?>">
                    <?php foreach ( $deny_list_ids as $user_id ) :
                        $user = get_userdata( $user_id );
                        if ( $user ) : ?>
                            <option value="<?php echo esc_attr( $user_id ); ?>" selected="selected"><?php echo esc_html(
                                    $user->display_name . ' (' . $user->user_login . ' - ' . $user->user_email . ')',
                                ); ?></option>
                        <?php endif;
                    endforeach; ?>
                </select>
            </fieldset>
            <p class="pml-field-description pml-tip"><?php printf(
                    wp_kses(
                        __(
                            '<strong>Tip:</strong> You can also manage these global user lists from the main WordPress <a href="%s">Users page</a> via row actions.',
                            PML_TEXT_DOMAIN,
                        ),
                        [ 'strong' => [], 'a' => [ 'href' => [] ] ],
                    ),
                    esc_url( admin_url( 'users.php' ) ),
                ); ?></p>
        </div>
        <?php
    }

    public function render_role_list_manager_field( array $args )
    {
        $allow_list_option_name = PML_PREFIX . '_global_role_allow_list';
        $deny_list_option_name  = PML_PREFIX . '_global_role_deny_list';
        $all_roles              = $args[ 'field_args' ][ 'roles' ] ?? [];

        $allow_list_slugs = get_option( $allow_list_option_name, [ 'administrator' ] );
        $deny_list_slugs  = get_option( $deny_list_option_name, [] );
        ?>
        <div class="pml-list-manager-container pml-role-list-manager">
            <fieldset>
                <legend class="pml-list-legend"><?php esc_html_e( 'Global Role Allow List', PML_TEXT_DOMAIN ); ?></legend>
                <p class="pml-field-description"><?php esc_html_e(
                        'Users with any of these roles will be granted access globally, unless denied by a user-specific rule or a per-file rule.',
                        PML_TEXT_DOMAIN,
                    ); ?></p>
                <select id="<?php echo esc_attr( $allow_list_option_name ); ?>_select"
                        name="<?php echo esc_attr( $allow_list_option_name ); ?>[]"
                        multiple="multiple"
                        class="pml-role-select"
                        style="width:100%;"
                        data-placeholder="<?php esc_attr_e( 'Select roles to add to allow list...', PML_TEXT_DOMAIN ); ?>">
                    <?php foreach ( $all_roles as $slug => $name ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( in_array( $slug, (array)$allow_list_slugs, true ) ); ?>>
                            <?php echo esc_html( $name ); ?> (<?php echo esc_html( $slug ); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </fieldset>

            <fieldset>
                <legend class="pml-list-legend"><?php esc_html_e( 'Global Role Deny List', PML_TEXT_DOMAIN ); ?></legend>
                <p class="pml-field-description"><?php esc_html_e(
                        'Users with any of these roles will be denied access globally, unless allowed by a user-specific rule or a per-file rule. This overrides global role allow.',
                        PML_TEXT_DOMAIN,
                    ); ?></p>
                <select id="<?php echo esc_attr( $deny_list_option_name ); ?>_select"
                        name="<?php echo esc_attr( $deny_list_option_name ); ?>[]"
                        multiple="multiple"
                        class="pml-role-select"
                        style="width:100%;"
                        data-placeholder="<?php esc_attr_e( 'Select roles to add to deny list...', PML_TEXT_DOMAIN ); ?>">
                    <?php foreach ( $all_roles as $slug => $name ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( in_array( $slug, (array)$deny_list_slugs, true ) ); ?>>
                            <?php echo esc_html( $name ); ?> (<?php echo esc_html( $slug ); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </fieldset>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function( $ )
            {
                if ( typeof $.fn.select2 === 'function' )
                {
                    $( 'select.pml-role-select' ).select2( {
                        width : '100%',
                    } );
                }
            } );
        </script>
        <?php
    }

    public function render_server_config_info_section()
    {
        echo '<div class="pml-section-content-wrapper">'; // Wrap content
        echo '<p>' . esc_html__(
                'For file protection to work, your web server must be configured to pass requests for uploaded files to WordPress. The plugin attempts to manage this automatically for Apache servers via the .htaccess file.',
                PML_TEXT_DOMAIN,
            ) . '</p>';

        $server_software = isset( $_SERVER[ 'SERVER_SOFTWARE' ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ 'SERVER_SOFTWARE' ] ) ) : 'Unknown';
        $is_apache       = strpos( strtolower( $server_software ), 'apache' ) !== false;
        $is_nginx        = strpos( strtolower( $server_software ), 'nginx' ) !== false;

        echo '<h4>' . esc_html__( 'Detected Server Software:', PML_TEXT_DOMAIN ) . ' <code>' . esc_html( $server_software ) . '</code></h4>';

        if ( $is_apache )
        {
            echo '<p>' . esc_html__(
                    'It appears you are running an Apache server. The plugin attempts to automatically update your .htaccess file.',
                    PML_TEXT_DOMAIN,
                ) . '</p>';
            echo '<h5>' . esc_html__( 'Required .htaccess Rules (for reference):', PML_TEXT_DOMAIN ) . '</h5>';
            echo '<p>' . esc_html__(
                    'These rules should be placed inside the # BEGIN Access Lens ... # END Access Lens markers in your .htaccess file, before the standard WordPress rules.',
                    PML_TEXT_DOMAIN,
                ) . '</p>';
            echo '<pre class="pml-code-block"><code>';
            if ( class_exists( 'PML_Install' ) ) {
                echo esc_html( PML_Install::get_htaccess_rules_snippet() );
            }
            echo '</code></pre>';
            if ( class_exists( 'PML_Install' ) && !PML_Install::are_htaccess_rules_present() )
            {
                echo '<p class="pml-warning">' . esc_html__(
                        'Warning: The required .htaccess rules may not be present. The rules shown above are generated dynamically; add them manually if needed.',
                        PML_TEXT_DOMAIN,
                    ) . '</p>';
            }
        }
        elseif ( $is_nginx )
        {
            echo '<p>' . esc_html__(
                    'It appears you are running an Nginx server. You will need to manually add rules to your Nginx configuration file for this plugin to work correctly.',
                    PML_TEXT_DOMAIN,
                ) . '</p>';
            echo '<h5>' . esc_html__( 'Example Nginx Configuration:', PML_TEXT_DOMAIN ) . '</h5>';
            echo '<p>' . esc_html__(
                    'Add the following within your server block. You may need to adjust it based on your specific Nginx setup (e.g., if WordPress is in a subdirectory or your PHP-FPM setup differs).',
                    PML_TEXT_DOMAIN,
                ) . '</p>';
            $nginx_rules = '';
            if ( class_exists( 'PML_Install' ) ) {
                $nginx_rules = PML_Install::regenerate_nginx_rules();
            }
            echo '<pre class="pml-code-block">' . esc_html( $nginx_rules ) . '</pre>';
            echo '<p>' .
                 esc_html__( 'After adding these rules, you must reload your Nginx configuration (e.g., sudo nginx -s reload).', PML_TEXT_DOMAIN ) .
                 '</p>';
        }
        else
        {
            echo '<p>' . esc_html__(
                    'Your server software could not be definitively identified as Apache or Nginx. You may need to manually configure rewrite rules for this plugin to function.',
                    PML_TEXT_DOMAIN,
                ) . '</p>';
        }
        echo '</div>';
    }

    public function sanitize_checkbox( $input ): string
    {
        return ( isset( $input ) && '1' === $input ) ? '1' : '0';
    }

    public function sanitize_user_list( $input_user_ids ): array
    {
        if ( empty( $input_user_ids ) || !is_array( $input_user_ids ) )
        {
            return [];
        }
        return array_unique( array_map( 'absint', $input_user_ids ) );
    }

    public function sanitize_role_list( $input_role_slugs ): array
    {
        if ( empty( $input_role_slugs ) || !is_array( $input_role_slugs ) )
        {
            return [];
        }
        $valid_roles     = array_keys( get_editable_roles() );
        $sanitized_roles = [];
        foreach ( $input_role_slugs as $slug )
        {
            $sanitized_slug = sanitize_key( $slug );
            if ( in_array( $sanitized_slug, $valid_roles, true ) )
            {
                $sanitized_roles[] = $sanitized_slug;
            }
        }
        return array_unique( $sanitized_roles );
    }

    public function sanitize_textarea_lines( $input ): string
    {
        if ( !is_string( $input ) )
        {
            return '';
        }
        $lines           = explode( "\n", $input );
        $sanitized_lines = array_map(
            function ( $line ) {
                return sanitize_text_field( trim( $line ) );
            },
            $lines,
        );
        $sanitized_lines = array_filter(
            $sanitized_lines,
            function ( $line ) {
                return !empty( $line );
            },
        );
        return implode( "\n", $sanitized_lines );
    }

    public function display_server_config_needed_notice()
    {
        $screen = get_current_screen();
        if ( !( $screen && ( $screen->id === 'settings_page_' . PML_PLUGIN_SLUG . '-settings' || $screen->id === 'plugins' ) ) )
        {
            return;
        }

        $htaccess_notice = get_transient( PML_PREFIX . '_admin_notice_htaccess_needed' );
        $nginx_notice    = get_transient( PML_PREFIX . '_admin_notice_nginx_config_needed' );

        if ( $htaccess_notice )
        {
            ?>
            <div class="notice notice-warning is-dismissible pml-admin-notice">
                <p>
                    <strong><?php echo esc_html( PML_PLUGIN_NAME ); ?>:</strong>
                    <?php
                    printf(
                        wp_kses(
                            __(
                                'For file protection to work correctly on your <strong>Apache server</strong>, dynamic rules must be present in your %2$s file. If automatic insertion failed, visit the <a href="%1$s">server configuration instructions</a> to copy the generated rules and add them manually.',
                                PML_TEXT_DOMAIN,
                            ),
                            [
                                'strong' => [],
                                'a'      => [ 'href' => [] ],
                                'code'   => [],
                            ],
                        ),
                        esc_url(
                            admin_url(
                                sprintf( "admin.php?page=%s#%sserver_config_section", PML_PLUGIN_SLUG, $this->option_name_prefix ),
                            ),
                        ),
                        '<code>.htaccess</code>',
                    );
                    ?>
                </p>
            </div>
            <?php
            delete_transient( PML_PREFIX . '_admin_notice_htaccess_needed' );
        }

        if ( $nginx_notice )
        {
            ?>
            <div class="notice notice-info is-dismissible pml-admin-notice">
                <p>
                    <strong><?php echo esc_html( PML_PLUGIN_NAME ); ?>:</strong>
                    <?php
                    printf(
                        wp_kses(
                            __(
                                'You appear to be using an <strong>Nginx server</strong>. This plugin generates a configuration snippet you must add to your Nginx config. See the <a href="%1$s">server configuration instructions</a> to copy the rules.',
                                PML_TEXT_DOMAIN,
                            ),
                            [
                                'strong' => [],
                                'a'      => [ 'href' => [] ],
                            ],
                        ),
                        esc_url(
                            admin_url(
                                sprintf( "admin.php?page=%s#%sserver_config_section", PML_PLUGIN_SLUG, $this->option_name_prefix ),
                            ),
                        ),
                    );
                    ?>
                </p>
            </div>
            <?php
            delete_transient( PML_PREFIX . '_admin_notice_nginx_config_needed' );
        }
    }
}
