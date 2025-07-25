<?php
/**
 * Handles integration with WordPress Media Library views (List and Grid)
 * and Gutenberg media modals.
 *
 * @package AccessLens
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class PML_Media_Library_Integration
{

    public function __construct()
    {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media_library_scripts' ] );

        // List View specific hooks
        add_filter( 'manage_upload_columns', [ $this, 'add_pml_status_column' ] );
        add_action( 'manage_media_custom_column', [ $this, 'render_pml_status_column' ], 10, 2 );
        add_filter( 'bulk_actions-upload', [ $this, 'add_pml_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-upload', [ $this, 'handle_pml_bulk_actions' ], 10, 3 );
        add_filter( 'media_row_actions', [ $this, 'add_pml_quick_edit_row_action' ], 10, 2 );

        // AJAX handlers for traditional Media Library and Gutenberg integration
        add_action( 'wp_ajax_' . PML_PREFIX . '_get_quick_edit_form', [ $this, 'ajax_get_quick_edit_form' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_save_quick_edit_form', [ $this, 'ajax_save_quick_edit_form' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_toggle_protection_status', [ $this, 'ajax_toggle_protection_status' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_get_gutenberg_attachment_pml_data', [ $this, 'ajax_get_gutenberg_attachment_pml_data' ] );
    }

    /**
     * Enqueues scripts and styles for Media Library and Gutenberg enhancements.
     *
     * @param string $hook_suffix The current admin page.
     */
    public function enqueue_media_library_scripts( string $hook_suffix ): void
    {
        $is_media_page           = ( 'upload.php' === $hook_suffix );
        $is_attachment_edit_page = ( 'post.php' === $hook_suffix &&
                                     isset( $_GET[ 'post' ] ) &&
                                     'attachment' === get_post_type( absint( $_GET[ 'post' ] ) ) );

        $current_screen    = get_current_screen();
        $is_gutenberg_page = $current_screen && method_exists( $current_screen, 'is_block_editor' ) && $current_screen->is_block_editor();

        if ( !$is_media_page && !$is_attachment_edit_page && !$is_gutenberg_page )
        {
            return;
        }

        // Common admin assets
        wp_enqueue_style( PML_PLUGIN_SLUG . '-admin-common-css', PML_PLUGIN_URL . 'admin/assets/css/common.css', [ 'dashicons' ], PML_VERSION );
        wp_enqueue_script(
            PML_PLUGIN_SLUG . '-admin-common-utils-js',
            PML_PLUGIN_URL . 'admin/assets/js/common-utils.js',
            [ 'jquery', 'wp-i18n' ],
            PML_VERSION,
            true,
        );
        wp_set_script_translations( PML_PLUGIN_SLUG . '-admin-common-utils-js', 'access-lens-protected-media-links', PML_PLUGIN_DIR . 'languages' );

        // Assets for traditional Media Library views
        if ( $is_media_page || $is_attachment_edit_page )
        {
            wp_enqueue_style(
                PML_PLUGIN_SLUG . '-media-library-css',
                PML_PLUGIN_URL . 'admin/assets/css/media-library.css',
                [ PML_PLUGIN_SLUG . '-admin-common-css' ],
                PML_VERSION,
            );
            wp_enqueue_script(
                PML_PLUGIN_SLUG . '-media-library-js',
                PML_PLUGIN_URL . 'admin/assets/js/media-library.js',
                [ 'jquery', 'wp-util', PML_PLUGIN_SLUG . '-admin-common-utils-js', 'wp-i18n' ],
                PML_VERSION,
                true,
            );
            wp_set_script_translations( PML_PLUGIN_SLUG . '-media-library-js', 'access-lens-protected-media-links', PML_PLUGIN_DIR . 'languages' );

            $pml_media_params = [ /* Parameters for media-library.js */
                                  'ajax_url'                => admin_url( 'admin-ajax.php' ),
                                  'get_form_nonce'          => wp_create_nonce( PML_PREFIX . '_get_quick_edit_form_nonce' ),
                                  'plugin_name'             => PML_PLUGIN_NAME,
                                  'plugin_prefix'           => PML_PREFIX,
                                  'save_form_nonce'         => wp_create_nonce( PML_PREFIX . '_save_quick_edit_form_nonce' ),
                                  'search_users_nonce'      => wp_create_nonce( PML_PREFIX . '_search_users_nonce' ),
                                  'text_error'              => esc_html__( 'An error occurred. Please try again.', 'access-lens-protected-media-links' ),
                                  'text_loading'            => esc_html__( 'Loading...', 'access-lens-protected-media-links' ),
                                  'text_manage_pml'         => esc_html__( 'Manage Access Lens', 'access-lens-protected-media-links' ),
                                  'text_protected'          => esc_html__( 'Protected', 'access-lens-protected-media-links' ),
                                  'text_quick_edit_pml'     => esc_html__( 'Access Lens Quick Edit', 'access-lens-protected-media-links' ),
                                  'text_toggle_protect'     => esc_html__( 'Protect', 'access-lens-protected-media-links' ),
                                  'text_toggle_unprotect'   => esc_html__( 'Unprotect', 'access-lens-protected-media-links' ),
                                  'text_unprotected'        => esc_html__( 'Unprotected', 'access-lens-protected-media-links' ),
                                  'toggle_nonce'            => wp_create_nonce( PML_PREFIX . '_toggle_protection_nonce' ),
                                  'user_select_placeholder' => esc_html__( 'Search for users by name or email...', 'access-lens-protected-media-links' ),
            ];
            wp_localize_script( PML_PLUGIN_SLUG . '-media-library-js', 'pml_media_params', $pml_media_params );
        }

        // Select2 for full attachment edit page (used by media-library.js for meta box)
        if ( $is_attachment_edit_page )
        {
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
            wp_localize_script(
                PML_PLUGIN_SLUG . '-media-library-js',
                'pml_admin_params',
                [
                    // params for select2 in media-library.js
                    'ajax_url'                => admin_url( 'admin-ajax.php' ),
                    'search_users_nonce'      => wp_create_nonce( PML_PREFIX . '_search_users_nonce' ),
                    'user_select_placeholder' => esc_html__( 'Search for users by name or email...', 'access-lens-protected-media-links' ),
                    'plugin_prefix'           => PML_PREFIX,
                ],
            );
        }

        // Assets for Gutenberg Media Modal Integration
        if ( $is_gutenberg_page )
        {
            $gutenberg_script_asset_path = PML_PLUGIN_DIR . 'admin/assets/js/gutenberg-integration-react/gutenberg-integration.asset.php';
            $gutenberg_script_asset      = file_exists( $gutenberg_script_asset_path ) ? require( $gutenberg_script_asset_path )
                : [ 'dependencies' => [], 'version' => PML_VERSION ];

            wp_enqueue_style(
                PML_PLUGIN_SLUG . '-gutenberg-integration-css',
                PML_PLUGIN_URL . 'admin/assets/css/gutenberg-integration.css',
                [ PML_PLUGIN_SLUG . '-admin-common-css', 'wp-components' ],
                PML_VERSION,
            );

            wp_enqueue_script(
                PML_PLUGIN_SLUG . '-gutenberg-integration-js',
                PML_PLUGIN_URL . 'admin/assets/js/gutenberg-integration-react/gutenberg-integration.jsx.js',
                $gutenberg_script_asset[ 'dependencies' ],
                PML_VERSION, // gx todo - maybe convert to $gutenberg_script_asset[ 'version' ]
                true,
            );

            wp_set_script_translations( PML_PLUGIN_SLUG . '-gutenberg-integration-js', 'access-lens-protected-media-links', PML_PLUGIN_DIR . 'languages' );

            $global_allow_bots_setting   = get_option( PML_PREFIX . '_settings_allow_bots', '1' ) ? 'yes' : 'no';
            $global_default_redirect_url = get_option( PML_PREFIX . '_settings_default_redirect_url', home_url( '/' ) );

            if ( empty( $global_default_redirect_url ) )
            {
                $global_default_redirect_url = home_url( '/' );
            }

            wp_localize_script(
                PML_PLUGIN_SLUG . '-gutenberg-integration-js',
                'pml_gutenberg_params',
                [
                    // parameters for gutenberg-integration.js
                    'ajax_url'                                => admin_url( 'admin-ajax.php' ),
                    'get_gutenberg_attachment_pml_data_nonce' => wp_create_nonce(
                        PML_PREFIX . '_get_gutenberg_attachment_pml_data_nonce',
                    ),
                    'toggle_protection_nonce'                 => wp_create_nonce( PML_PREFIX . '_toggle_protection_nonce' ),
                    'save_quick_edit_form_nonce'              => wp_create_nonce( PML_PREFIX . '_save_quick_edit_form_nonce' ),
                    'plugin_prefix'                           => PML_PREFIX,
                    'text_error'                              => esc_html__( 'An error occurred. Please try again.', 'access-lens-protected-media-links' ),
                    'text_loading'                            => esc_html__( 'Loading PML settings...', 'access-lens-protected-media-links' ),
                    'text_saving'                             => esc_html__( 'Saving...', 'access-lens-protected-media-links' ),
                    'global_redirect_url_placeholder'         => esc_html__( 'Global default', 'access-lens-protected-media-links' ) .
                                                                 ' (' .
                                                                 esc_url( $global_default_redirect_url ) .
                                                                 ')',
                    'select_options_bot_access'               => [
                        [
                            'value' => '',
                            'label' => esc_html__( 'Use Global Setting', 'access-lens-protected-media-links' ) . ' (' . ( $global_allow_bots_setting === 'yes'
                                    ? esc_html__( 'Allow', 'access-lens-protected-media-links' )
                                    : esc_html__(
                                        'Block',
                                        'access-lens-protected-media-links',
                                    ) ) . ')',
                        ],
                        [ 'value' => 'yes', 'label' => esc_html__( 'Yes, Allow Bots for this file', 'access-lens-protected-media-links' ) ],
                        [ 'value' => 'no', 'label' => esc_html__( 'No, Block Bots for this file', 'access-lens-protected-media-links' ) ],
                    ],
                ],
            );
        }
    }

    /**
     * AJAX handler to fetch PML data for an attachment in the Gutenberg context.
     */
    public function ajax_get_gutenberg_attachment_pml_data(): void
    {
        check_ajax_referer( PML_PREFIX . '_get_gutenberg_attachment_pml_data_nonce', 'nonce' );
        $attachment_id = isset( $_POST[ 'attachment_id' ] ) ? absint( $_POST[ 'attachment_id' ] ) : 0;

        if ( !$attachment_id || !current_user_can( 'edit_post', $attachment_id ) || 'attachment' !== get_post_type( $attachment_id ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid attachment ID or insufficient permissions.', 'access-lens-protected-media-links' ) ], 403 );
        }

        $is_protected          = (bool)get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true );
        $redirect_url_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_redirect_url', true );
        $redirect_url_override = is_string( $redirect_url_override ) ? $redirect_url_override : '';
        $allow_bots_override   = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_allow_bots_for_file', true );

        if ( !in_array( $allow_bots_override, [ 'yes', 'no', '' ], true ) )
        {
            $allow_bots_override = '';
        }

        $user_allow_list = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_user_allow_list', true );
        $user_deny_list  = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_user_deny_list', true );
        $role_allow_list = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_role_allow_list', true );
        $role_deny_list  = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_role_deny_list', true );

        $attachment_edit_link = get_edit_post_link( $attachment_id, 'raw' );

        wp_send_json_success(
            [
                'is_protected' => $is_protected,
                'redirect_url' => $redirect_url_override,
                'allow_bots'   => $allow_bots_override,
                'list_counts'  => [
                    'user_allow' => count( is_array( $user_allow_list ) ? array_filter( $user_allow_list ) : [] ),
                    'user_deny'  => count( is_array( $user_deny_list ) ? array_filter( $user_deny_list ) : [] ),
                    'role_allow' => count( is_array( $role_allow_list ) ? array_filter( $role_allow_list ) : [] ),
                    'role_deny'  => count( is_array( $role_deny_list ) ? array_filter( $role_deny_list ) : [] ),
                ],
                'edit_link'    => sanitize_url( $attachment_edit_link ),
            ],
        );
    }

    public function add_pml_status_column( array $columns ): array
    {
        $new_columns = [];
        $inserted    = false;
        foreach ( $columns as $key => $value )
        {
            $new_columns[ $key ] = $value;
            if ( 'title' === $key || 'author' === $key )
            {
                if ( !isset( $new_columns[ PML_PREFIX . '_status' ] ) )
                { // Prevent duplicates on AJAX reloads
                    $new_columns[ PML_PREFIX . '_status' ] = esc_html__( 'Access Lens', 'access-lens-protected-media-links' );
                    $inserted                              = true;
                }
            }
        }
        if ( !$inserted && !isset( $new_columns[ PML_PREFIX . '_status' ] ) )
        {
            $new_columns[ PML_PREFIX . '_status' ] = esc_html__( 'Access Lens', 'access-lens-protected-media-links' );
        }
        return $new_columns;
    }

    public function render_pml_status_column( string $column_name, int $attachment_id ): void
    {
        if ( PML_PREFIX . '_status' !== $column_name )
        {
            return;
        }

        $is_protected  = (bool)get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true );
        $status_text   = $is_protected ? esc_html__( 'Protected', 'access-lens-protected-media-links' ) : esc_html__( 'Unprotected', 'access-lens-protected-media-links' );
        $toggle_text   = $is_protected ? esc_html__( 'Unprotect', 'access-lens-protected-media-links' ) : esc_html__( 'Protect', 'access-lens-protected-media-links' );
        $toggle_action = $is_protected ? 'unprotect' : 'protect';
        $toggle_icon   = $is_protected ? 'dashicons-unlock' : 'dashicons-lock';

        echo '<div class="pml-status-column-content" data-attachment-id="' . esc_attr( $attachment_id ) . '">';
        echo '<span class="pml-status-text ' . ( $is_protected ? 'is-protected' : 'is-unprotected' ) . '">' . esc_html( $status_text ) . '</span>';
        echo '<div class="pml-status-actions">';
        printf(
            '<a href="#" class="pml-toggle-protection" data-action="%s" title="%s"><span class="dashicons %s"></span> %s</a>',
            esc_attr( $toggle_action ),
            esc_attr( $toggle_text ),
            esc_attr( $toggle_icon ),
            esc_html( $toggle_text ),
        );
        echo '</div></div>';
    }

    public function add_pml_bulk_actions( array $bulk_actions ): array
    {
        $bulk_actions[ PML_PREFIX . '_protect_selected' ]   = esc_html__( 'Access Lens: Protect Selected', 'access-lens-protected-media-links' );
        $bulk_actions[ PML_PREFIX . '_unprotect_selected' ] = esc_html__( 'Access Lens: Unprotect Selected', 'access-lens-protected-media-links' );
        return $bulk_actions;
    }

    public function handle_pml_bulk_actions( string $redirect_to, string $action, array $post_ids ): string
    {
        if ( !current_user_can( 'edit_others_posts' ) )
        {
            return add_query_arg( PML_PREFIX . '_bulk_error', rawurlencode( __( 'Insufficient permissions.', 'access-lens-protected-media-links' ) ), $redirect_to );
        }

        $processed_count = 0;
        $valid_post_ids  = array_filter( array_map( 'absint', $post_ids ) );

        $meta_value_to_set = null;
        if ( PML_PREFIX . '_protect_selected' === $action )
        {
            $meta_value_to_set = '1';
        }
        elseif ( PML_PREFIX . '_unprotect_selected' === $action )
        {
            $meta_value_to_set = '0';
        }

        if ( null !== $meta_value_to_set )
        {
            check_admin_referer( 'bulk-media' );
            foreach ( $valid_post_ids as $post_id )
            {
                if ( 'attachment' === get_post_type( $post_id ) && current_user_can( 'edit_post', $post_id ) )
                {
                    update_post_meta( $post_id, '_' . PML_PREFIX . '_is_protected', $meta_value_to_set );
                    $processed_count++;
                }
            }
            if ( $processed_count > 0 )
            {
                $message     = ( '1' === $meta_value_to_set ) ? sprintf(
                    _n( '%d item protected.', '%d items protected.', $processed_count, 'access-lens-protected-media-links' ),
                    $processed_count,
                ) : sprintf( _n( '%d item unprotected.', '%d items unprotected.', $processed_count, 'access-lens-protected-media-links' ), $processed_count );
                $redirect_to = add_query_arg( PML_PREFIX . '_bulk_message', rawurlencode( $message ), $redirect_to );
                if ( method_exists( 'PML_Install', 'regenerate_htaccess_rules' ) )
                {
                    PML_Install::regenerate_htaccess_rules();
                }
            }
        }
        return $redirect_to;
    }

    public function add_pml_quick_edit_row_action( array $actions, WP_Post $post ): array
    {
        if ( 'attachment' === $post->post_type && current_user_can( 'edit_post', $post->ID ) )
        {
            if ( !isset( $actions[ PML_PREFIX . '_quick_edit' ] ) )
            { // Ensure unique key
                $actions[ PML_PREFIX . '_quick_edit' ] = sprintf(
                    '<a href="#" class="pml-quick-edit-trigger" data-attachment-id="%d" aria-label="%s">%s</a>',
                    esc_attr( $post->ID ),
                    esc_attr( sprintf( __( 'Quick edit Access Lens settings for %s', 'access-lens-protected-media-links' ), $post->post_title ) ),
                    esc_html__( 'Access Lens Quick Edit', 'access-lens-protected-media-links' ),
                );
            }
        }
        return $actions;
    }

    public function ajax_get_quick_edit_form(): void
    {
        check_ajax_referer( PML_PREFIX . '_get_quick_edit_form_nonce', 'nonce' );
        $attachment_id = isset( $_POST[ 'attachment_id' ] ) ? absint( $_POST[ 'attachment_id' ] ) : 0;

        if ( !$attachment_id || !current_user_can( 'edit_post', $attachment_id ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid attachment ID or insufficient permissions.', 'access-lens-protected-media-links' ) ], 403 );
        }

        $is_protected = (bool)get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true );

        if ( class_exists( 'PML_Media_Meta' ) && method_exists( 'PML_Media_Meta', 'render_quick_edit_form_fields' ) )
        {
            ob_start();
            PML_Media_Meta::render_quick_edit_form_fields( $attachment_id, false );
            $form_html = ob_get_clean();
            wp_send_json_success(
                [
                    'form_html'    => $form_html,
                    'is_protected' => $is_protected,
                ],
            );
        }
        else
        {
            wp_send_json_error( [ 'message' => esc_html__( 'PML_Media_Meta class or method not found.', 'access-lens-protected-media-links' ) ], 500 );
        }
    }

    public function ajax_save_quick_edit_form(): void
    {
        check_ajax_referer( PML_PREFIX . '_save_quick_edit_form_nonce', 'nonce' );
        $attachment_id     = isset( $_POST[ 'attachment_id' ] ) ? absint( $_POST[ 'attachment_id' ] ) : 0;
        $settings_data_raw = isset( $_POST[ 'pml_settings' ] ) && is_array( $_POST[ 'pml_settings' ] ) ? wp_unslash( $_POST[ 'pml_settings' ] ) : [];

        // Accommodate direct keys from Gutenberg save, not wrapped in 'pml_settings'
        if ( empty( $settings_data_raw ) && isset( $_POST[ 'attachment_id' ] ) )
        {
            $gutenberg_keys = [ PML_PREFIX . '_redirect_url', PML_PREFIX . '_allow_bots_for_file' ];
            foreach ( $gutenberg_keys as $key )
            {
                if ( isset( $_POST[ $key ] ) )
                {
                    $settings_data_raw[ $key ] = wp_unslash( $_POST[ $key ] );
                }
            }
        }

        if ( !$attachment_id || !current_user_can( 'edit_post', $attachment_id ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid attachment ID or insufficient permissions.', 'access-lens-protected-media-links' ) ], 403 );
        }

        if ( empty( $settings_data_raw ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'No settings data provided.', 'access-lens-protected-media-links' ) ], 400 );
        }

        // Normalize keys (remove 'pml_settings[]' wrapper if present)
        $settings_data_to_save = [];
        foreach ( $settings_data_raw as $key => $value )
        {
            $normalized_key                           = preg_replace( '/^pml_settings\[(.*?)\]$/', '$1', $key );
            $settings_data_to_save[ $normalized_key ] = $value;
        }

        if ( !class_exists( 'PML_Media_Meta' ) || !method_exists( 'PML_Media_Meta', 'save_quick_edit_data' ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'PML_Media_Meta class or method not found.', 'access-lens-protected-media-links' ) ], 500 );
        }

        $result = PML_Media_Meta::save_quick_edit_data( $attachment_id, $settings_data_to_save );

        if ( !$result )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to save PML settings.', 'access-lens-protected-media-links' ) ], 500 );
        }

        $is_protected_bool = (bool)get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true );
        wp_send_json_success(
            [
                // response data for ui updates
                'message'       => esc_html__( 'PML settings updated.', 'access-lens-protected-media-links' ),
                'is_protected'  => $is_protected_bool,
                'status_text'   => $is_protected_bool
                    ? esc_html__( 'Protected', 'access-lens-protected-media-links' )
                    : esc_html__(
                        'Unprotected',
                        'access-lens-protected-media-links',
                    ),
                'toggle_text'   => $is_protected_bool
                    ? esc_html__( 'Unprotect', 'access-lens-protected-media-links' )
                    : esc_html__(
                        'Protect',
                        'access-lens-protected-media-links',
                    ),
                'toggle_action' => $is_protected_bool ? 'unprotect' : 'protect',
                'toggle_icon'   => $is_protected_bool ? 'dashicons-unlock' : 'dashicons-lock',
            ],
        );
    }

    public function ajax_toggle_protection_status(): void
    {
        check_ajax_referer( PML_PREFIX . '_toggle_protection_nonce', 'nonce' );
        $attachment_id = isset( $_POST[ 'attachment_id' ] ) ? absint( $_POST[ 'attachment_id' ] ) : 0;
        $new_action    = isset( $_POST[ 'new_action' ] ) ? sanitize_key( $_POST[ 'new_action' ] ) : ''; // 'protect' or 'unprotect'

        // Handle boolean 'is_protected' from Gutenberg
        if ( empty( $new_action ) && isset( $_POST[ 'is_protected' ] ) )
        {
            $is_protected_from_gutenberg = filter_var( $_POST[ 'is_protected' ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
            if ( $is_protected_from_gutenberg !== null )
            {
                $new_action = $is_protected_from_gutenberg ? 'protect' : 'unprotect';
            }
        }

        if ( !$attachment_id || !current_user_can( 'edit_post', $attachment_id ) || !in_array( $new_action, [ 'protect', 'unprotect' ], true ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request or insufficient permissions.', 'access-lens-protected-media-links' ) ], 403 );
        }

        $new_status_value = ( 'protect' === $new_action ) ? '1' : '0';
        $old_value       = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true );
        update_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', $new_status_value );
        $status_changed   = (string)$old_value !== $new_status_value;
        $is_protected_bool = ( '1' === $new_status_value );
        if ( $status_changed && method_exists( 'PML_Install', 'regenerate_htaccess_rules' ) )
        {
            PML_Install::regenerate_htaccess_rules();
        }

        wp_send_json_success(
            [
                // Response data for UI updates
                'message'       => $is_protected_bool
                    ? esc_html__( 'Attachment protected.', 'access-lens-protected-media-links' )
                    : esc_html__(
                        'Attachment unprotected.',
                        'access-lens-protected-media-links',
                    ),
                'is_protected'  => $is_protected_bool,
                'status_text'   => $is_protected_bool ? esc_html__( 'Protected', 'access-lens-protected-media-links' ) : esc_html__( 'Unprotected', 'access-lens-protected-media-links' ),
                'toggle_text'   => $is_protected_bool ? esc_html__( 'Unprotect', 'access-lens-protected-media-links' ) : esc_html__( 'Protect', 'access-lens-protected-media-links' ),
                'toggle_action' => $is_protected_bool ? 'unprotect' : 'protect',
                'toggle_icon'   => $is_protected_bool ? 'dashicons-unlock' : 'dashicons-lock',
            ],
        );
    }
}

