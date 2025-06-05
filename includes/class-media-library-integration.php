<?php
/**
 * Handles integration with the WordPress Media Library views (List and Grid).
 * Adds custom columns, actions, and AJAX handlers for managing PML settings.
 *
 * @package ProtectedMediaLinks
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
        // Common hooks
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media_library_scripts' ] );

        // List View specific hooks
        add_filter( 'manage_upload_columns', [ $this, 'add_pml_status_column' ] );
        add_action( 'manage_media_custom_column', [ $this, 'render_pml_status_column' ], 10, 2 );
        add_filter( 'bulk_actions-upload', [ $this, 'add_pml_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-upload', [ $this, 'handle_pml_bulk_actions' ], 10, 3 );
        add_filter( 'media_row_actions', [ $this, 'add_pml_quick_edit_row_action' ], 10, 2 );

        // AJAX handlers
        add_action( 'wp_ajax_' . PML_PREFIX . '_get_quick_edit_form', [ $this, 'ajax_get_quick_edit_form' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_save_quick_edit_form', [ $this, 'ajax_save_quick_edit_form' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_toggle_protection_status', [ $this, 'ajax_toggle_protection_status' ] );
    }

    /**
     * Enqueues scripts and styles needed for Media Library enhancements.
     */
    public function enqueue_media_library_scripts( string $hook_suffix ): void
    {
        $is_media_page           = ( 'upload.php' === $hook_suffix );
        $is_attachment_edit_page = ( 'post.php' === $hook_suffix &&
                                     isset( $_GET[ 'post' ] ) &&
                                     'attachment' === get_post_type( absint( $_GET[ 'post' ] ) ) );

        if ( !$is_media_page && !$is_attachment_edit_page )
        {
            return;
        }

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
        }

        wp_enqueue_style(
            PML_PLUGIN_SLUG . '-admin-common-css',
            PML_PLUGIN_URL . 'admin/assets/css/common.css',
            [ 'dashicons' ],
            PML_VERSION,
        );
        wp_enqueue_style(
            PML_PLUGIN_SLUG . '-media-library-css',
            PML_PLUGIN_URL . 'admin/assets/css/media-library.css',
            [ PML_PLUGIN_SLUG . '-admin-common-css' ],
            PML_VERSION,
        );
        wp_enqueue_script(
            PML_PLUGIN_SLUG . '-admin-common-utils-js',
            PML_PLUGIN_URL . 'admin/assets/js/common-utils.js',
            [ 'jquery', 'wp-i18n' ],
            PML_VERSION,
            true,
        );
        wp_set_script_translations( PML_PLUGIN_SLUG . '-admin-common-utils-js', PML_TEXT_DOMAIN, PML_PLUGIN_DIR . 'languages' );

        wp_enqueue_script(
            PML_PLUGIN_SLUG . '-media-library-js',
            PML_PLUGIN_URL . 'admin/assets/js/media-library.js',
            [ 'jquery', 'wp-util', PML_PLUGIN_SLUG . '-admin-common-utils-js', 'wp-i18n' ],
            PML_VERSION,
            true,
        );
        wp_set_script_translations( PML_PLUGIN_SLUG . '-media-library-js', PML_TEXT_DOMAIN, PML_PLUGIN_DIR . 'languages' );

        $pml_media_params = [
            'ajax_url'                => admin_url( 'admin-ajax.php' ),
            'get_form_nonce'          => wp_create_nonce( PML_PREFIX . '_get_quick_edit_form_nonce' ),
            'plugin_name'             => PML_PLUGIN_NAME, // Pass the plugin name
            'plugin_prefix'           => PML_PREFIX, // Pass the prefix
            'save_form_nonce'         => wp_create_nonce( PML_PREFIX . '_save_quick_edit_form_nonce' ),
            'search_users_nonce'      => wp_create_nonce( PML_PREFIX . '_search_users_nonce' ),
            'text_error'              => esc_html__( 'An error occurred. Please try again.', PML_TEXT_DOMAIN ),
            'text_loading'            => esc_html__( 'Loading...', PML_TEXT_DOMAIN ),
            'text_manage_pml'         => esc_html__( 'Manage PML', PML_TEXT_DOMAIN ),
            'text_protected'          => esc_html__( 'Protected', PML_TEXT_DOMAIN ),
            'text_quick_edit_pml'     => esc_html__( 'Quick Edit PML', PML_TEXT_DOMAIN ),
            'text_toggle_protect'     => esc_html__( 'Protect', PML_TEXT_DOMAIN ),
            'text_toggle_unprotect'   => esc_html__( 'Unprotect', PML_TEXT_DOMAIN ),
            'text_unprotected'        => esc_html__( 'Unprotected', PML_TEXT_DOMAIN ),
            'toggle_nonce'            => wp_create_nonce( PML_PREFIX . '_toggle_protection_nonce' ),
            'user_select_placeholder' => esc_html__( 'Search for users by name or email...', PML_TEXT_DOMAIN ),
        ];
        wp_localize_script( PML_PLUGIN_SLUG . '-media-library-js', 'pml_media_params', $pml_media_params );

        // For Select2 on full attachment edit page, it might need pml_admin_params if logic is shared.
        // If pml-media-library.js handles Select2 for attachment edit, it should use pml_media_params.
        if ( $is_attachment_edit_page )
        {
            wp_localize_script(
                PML_PLUGIN_SLUG . '-media-library-js', // Localize against the script that needs it
                'pml_admin_params',                    // Use the same object name if pml-media-library.js expects it for Select2
                [
                    'ajax_url'                => admin_url( 'admin-ajax.php' ),
                    'search_users_nonce'      => wp_create_nonce( PML_PREFIX . '_search_users_nonce' ),
                    'user_select_placeholder' => esc_html__( 'Search for users by name or email...', PML_TEXT_DOMAIN ),
                ],
            );
        }
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
                $new_columns[ PML_PREFIX . '_status' ] = esc_html__( 'PML Status', PML_TEXT_DOMAIN );
                $inserted                              = true;
            }
        }
        if ( !$inserted )
        {
            $new_columns[ PML_PREFIX . '_status' ] = esc_html__( 'PML Status', PML_TEXT_DOMAIN );
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
        $status_text   = $is_protected ? esc_html__( 'Protected', PML_TEXT_DOMAIN ) : esc_html__( 'Unprotected', PML_TEXT_DOMAIN );
        $toggle_text   = $is_protected ? esc_html__( 'Unprotect', PML_TEXT_DOMAIN ) : esc_html__( 'Protect', PML_TEXT_DOMAIN );
        $toggle_action = $is_protected ? 'unprotect' : 'protect';

        echo '<div class="pml-status-column-content" data-attachment-id="' . esc_attr( $attachment_id ) . '">';
        echo '<span class="pml-status-text ' . ( $is_protected ? 'is-protected' : 'is-unprotected' ) . '">' . esc_html( $status_text ) . '</span>';
        echo '<div class="pml-status-actions">';
        printf(
            '<a href="#" class="pml-toggle-protection" data-action="%s" title="%s"><span class="dashicons %s"></span> %s</a>',
            esc_attr( $toggle_action ),
            esc_attr( $toggle_text ),
            esc_attr( $is_protected ? 'dashicons-unlock' : 'dashicons-lock' ),
            esc_html( $toggle_text ),
        );
        echo '</div>';
        echo '</div>';
    }

    public function add_pml_bulk_actions( array $bulk_actions ): array
    {
        $bulk_actions[ PML_PREFIX . '_protect_selected' ]   = esc_html__( 'PML: Protect Selected', PML_TEXT_DOMAIN );
        $bulk_actions[ PML_PREFIX . '_unprotect_selected' ] = esc_html__( 'PML: Unprotect Selected', PML_TEXT_DOMAIN );
        return $bulk_actions;
    }

    public function handle_pml_bulk_actions( string $redirect_to, string $action, array $post_ids ): string
    {
        if ( !current_user_can( 'edit_others_posts' ) )
        {
            return $redirect_to;
        }

        $processed_count = 0;

        if ( PML_PREFIX . '_protect_selected' === $action )
        {
            check_admin_referer( 'bulk-media' );
            foreach ( $post_ids as $post_id )
            {
                if ( 'attachment' === get_post_type( $post_id ) )
                {
                    update_post_meta( (int)$post_id, '_' . PML_PREFIX . '_is_protected', '1' );
                    $processed_count++;
                }
            }
            $redirect_to = add_query_arg(
                PML_PREFIX . '_bulk_message',
                sprintf( _n( '%d item protected.', '%d items protected.', $processed_count, PML_TEXT_DOMAIN ), $processed_count ),
                $redirect_to,
            );
        }
        elseif ( PML_PREFIX . '_unprotect_selected' === $action )
        {
            check_admin_referer( 'bulk-media' );
            foreach ( $post_ids as $post_id )
            {
                if ( 'attachment' === get_post_type( $post_id ) )
                {
                    update_post_meta( (int)$post_id, '_' . PML_PREFIX . '_is_protected', '0' );
                    $processed_count++;
                }
            }
            $redirect_to = add_query_arg(
                PML_PREFIX . '_bulk_message',
                sprintf( _n( '%d item unprotected.', '%d items unprotected.', $processed_count, PML_TEXT_DOMAIN ), $processed_count ),
                $redirect_to,
            );
        }
        return $redirect_to;
    }

    public function add_pml_quick_edit_row_action( array $actions, WP_Post $post ): array
    {
        if ( 'attachment' === $post->post_type && current_user_can( 'edit_post', $post->ID ) )
        {
            $actions[ PML_PREFIX . '_quick_edit' ] = sprintf(
                '<a href="#" class="pml-quick-edit-trigger" data-attachment-id="%d" aria-label="%s">%s</a>',
                esc_attr( $post->ID ),
                esc_attr( sprintf( __( 'Quick edit PML settings for %s', PML_TEXT_DOMAIN ), $post->post_title ) ),
                esc_html__( 'Quick Edit PML', PML_TEXT_DOMAIN ),
            );
        }
        return $actions;
    }

    public function ajax_get_quick_edit_form(): void
    {
        check_ajax_referer( PML_PREFIX . '_get_quick_edit_form_nonce', 'nonce' );
        $attachment_id = isset( $_POST[ 'attachment_id' ] ) ? absint( $_POST[ 'attachment_id' ] ) : 0;
        if ( !$attachment_id || !current_user_can( 'edit_post', $attachment_id ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid attachment ID or insufficient permissions.', PML_TEXT_DOMAIN ) ], 403 );
        }
        if ( class_exists( 'PML_Media_Meta' ) )
        {
            ob_start();
            PML_Media_Meta::render_quick_edit_form_fields( $attachment_id );
            $form_html = ob_get_clean();
            wp_send_json_success( [ 'form_html' => $form_html ] );
        }
        else
        {
            wp_send_json_error( [ 'message' => esc_html__( 'PML_Media_Meta class not found.', PML_TEXT_DOMAIN ) ], 500 );
        }
    }

    public function ajax_save_quick_edit_form(): void
    {
        check_ajax_referer( PML_PREFIX . '_save_quick_edit_form_nonce', 'nonce' );
        $attachment_id = isset( $_POST[ 'attachment_id' ] ) ? absint( $_POST[ 'attachment_id' ] ) : 0;
        $settings_data = isset( $_POST[ 'pml_settings' ] ) && is_array( $_POST[ 'pml_settings' ] ) ? wp_unslash( $_POST[ 'pml_settings' ] ) : [];

        if ( !$attachment_id || !current_user_can( 'edit_post', $attachment_id ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid attachment ID or insufficient permissions.', PML_TEXT_DOMAIN ) ], 403 );
        }
        if ( empty( $settings_data ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'No settings data provided.', PML_TEXT_DOMAIN ) ], 400 );
        }
        if ( class_exists( 'PML_Media_Meta' ) )
        {
            $result = PML_Media_Meta::save_quick_edit_data( $attachment_id, $settings_data );
            if ( $result )
            {
                // Use the passed plugin_prefix to construct the key for is_protected
                $is_protected_key = ( defined( 'PML_PREFIX' ) ? PML_PREFIX : 'pml' ) . '_is_protected';
                $is_protected     = (bool)( $settings_data[ $is_protected_key ] ?? '0' );

                wp_send_json_success(
                    [
                        'message'       => esc_html__( 'PML settings updated.', PML_TEXT_DOMAIN ),
                        'is_protected'  => $is_protected,
                        'status_text'   => $is_protected ? esc_html__( 'Protected', PML_TEXT_DOMAIN ) : esc_html__( 'Unprotected', PML_TEXT_DOMAIN ),
                        'toggle_text'   => $is_protected ? esc_html__( 'Unprotect', PML_TEXT_DOMAIN ) : esc_html__( 'Protect', PML_TEXT_DOMAIN ),
                        'toggle_action' => $is_protected ? 'unprotect' : 'protect',
                        'toggle_icon'   => $is_protected ? 'dashicons-unlock' : 'dashicons-lock',
                    ],
                );
            }
            else
            {
                wp_send_json_error( [ 'message' => esc_html__( 'Failed to save PML settings.', PML_TEXT_DOMAIN ) ], 500 );
            }
        }
        else
        {
            wp_send_json_error( [ 'message' => esc_html__( 'PML_Media_Meta class not found.', PML_TEXT_DOMAIN ) ], 500 );
        }
    }

    public function ajax_toggle_protection_status(): void
    {
        check_ajax_referer( PML_PREFIX . '_toggle_protection_nonce', 'nonce' );
        $attachment_id = isset( $_POST[ 'attachment_id' ] ) ? absint( $_POST[ 'attachment_id' ] ) : 0;
        $new_action    = isset( $_POST[ 'new_action' ] ) ? sanitize_key( $_POST[ 'new_action' ] ) : '';
        if ( !$attachment_id || !current_user_can( 'edit_post', $attachment_id ) || !in_array( $new_action, [ 'protect', 'unprotect' ] ) )
        {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request or insufficient permissions.', PML_TEXT_DOMAIN ) ], 403 );
        }
        $new_status_value = ( 'protect' === $new_action ) ? '1' : '0';
        update_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', $new_status_value );
        $is_protected = ( '1' === $new_status_value );
        wp_send_json_success(
            [
                'message'       => $is_protected
                    ? esc_html__( 'Attachment protected.', PML_TEXT_DOMAIN )
                    : esc_html__(
                        'Attachment unprotected.',
                        PML_TEXT_DOMAIN,
                    ),
                'is_protected'  => $is_protected,
                'status_text'   => $is_protected ? esc_html__( 'Protected', PML_TEXT_DOMAIN ) : esc_html__( 'Unprotected', PML_TEXT_DOMAIN ),
                'toggle_text'   => $is_protected ? esc_html__( 'Unprotect', PML_TEXT_DOMAIN ) : esc_html__( 'Protect', PML_TEXT_DOMAIN ),
                'toggle_action' => $is_protected ? 'unprotect' : 'protect',
                'toggle_icon'   => $is_protected ? 'dashicons-unlock' : 'dashicons-lock',
            ],
        );
    }
}
