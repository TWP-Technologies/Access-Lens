<?php
/**
 * Handles the "PML Access Tokens" meta box on the attachment edit page.
 *
 * @package ProtectedMediaLinks
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class PML_Token_Meta_Box
{
    private string $meta_box_id;

    public function __construct()
    {
        $this->meta_box_id = PML_PREFIX . '_access_tokens_meta_box';

        add_action( 'add_meta_boxes_attachment', [ $this, 'add_meta_box' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts_and_styles' ] );

        // AJAX Handlers
        add_action( 'wp_ajax_' . PML_PREFIX . '_fetch_attachment_tokens', [ $this, 'ajax_fetch_attachment_tokens' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_generate_token', [ $this, 'ajax_generate_token' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_revoke_token', [ $this, 'ajax_revoke_token' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_delete_token', [ $this, 'ajax_delete_token' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_reinstate_token', [ $this, 'ajax_reinstate_token' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_get_formatted_expiry_time', [ $this, 'ajax_get_formatted_expiry_time' ] );
        add_action( 'wp_ajax_' . PML_PREFIX . '_update_token_max_uses', [ $this, 'ajax_update_token_max_uses' ] );
    }

    private function _get_valid_timezone_string(string $tz_input): string {
        $tz_input = trim($tz_input);
        if (strpos($tz_input, 'UTC') === 0) {
            $offset_str = substr($tz_input, 3);
            if (empty($offset_str)) {
                return '+00:00';
            }

            $offset_val = (float)$offset_str;
            $hours = (int)$offset_val;
            $minutes = ($offset_val - $hours) * 60;

            return sprintf('%+03d:%02d', $hours, abs($minutes));
        }
        return $tz_input;
    }

    public function add_meta_box( WP_Post $post ): void
    {
        if ( 'attachment' !== $post->post_type )
        {
            return;
        }

        add_meta_box(
            $this->meta_box_id,
            esc_html__( 'PML Access Tokens', PML_TEXT_DOMAIN ),
            [ $this, 'render_meta_box_content' ],
            'attachment',
            'normal',
            'low',
        );
    }

    public function enqueue_scripts_and_styles( string $hook_suffix ): void
    {
        global $pagenow, $post;

        $is_attachment_edit_page = 'post.php' === $pagenow && isset( $_GET[ 'post' ] ) && $post && 'attachment' === $post->post_type;
        if ( !$is_attachment_edit_page )
        {
            return;
        }

        wp_enqueue_style( PML_PLUGIN_SLUG . '-admin-common-css', PML_PLUGIN_URL . 'admin/assets/css/common.css', [ 'dashicons' ], PML_VERSION );
        wp_enqueue_style( PML_PLUGIN_SLUG . '-token-meta-box-css', PML_PLUGIN_URL . 'admin/assets/css/token-meta-box.css', [ PML_PLUGIN_SLUG . '-admin-common-css' ], PML_VERSION );
        wp_enqueue_style( 'flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', [], '4.6.13' );
        wp_enqueue_style( 'select2', "https://cdnjs.cloudflare.com/ajax/libs/select2/" . PML_SELECT2_VERSION . "/css/select2.min.css", [], PML_SELECT2_VERSION );
        wp_enqueue_script( 'flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', [], '4.6.13', true );
        wp_enqueue_script( 'select2', "https://cdnjs.cloudflare.com/ajax/libs/select2/" . PML_SELECT2_VERSION . "/js/select2.min.js", [ 'jquery' ], PML_SELECT2_VERSION, true );
        wp_enqueue_script( PML_PLUGIN_SLUG . '-admin-common-utils-js', PML_PLUGIN_URL . 'admin/assets/js/common-utils.js', [ 'jquery', 'wp-i18n' ], PML_VERSION, true );
        wp_set_script_translations( PML_PLUGIN_SLUG . '-admin-common-utils-js', PML_TEXT_DOMAIN, PML_PLUGIN_DIR . 'languages' );
        wp_enqueue_script( PML_PLUGIN_SLUG . '-token-meta-box-js', PML_PLUGIN_URL . 'admin/assets/js/token-meta-box.js', [ 'jquery', 'wp-util', 'wp-i18n', 'flatpickr', 'select2', PML_PLUGIN_SLUG . '-admin-common-utils-js' ], PML_VERSION, true );
        wp_set_script_translations( PML_PLUGIN_SLUG . '-token-meta-box-js', PML_TEXT_DOMAIN, PML_PLUGIN_DIR . 'languages' );

        $max_uses_override = get_post_meta( $post->ID, '_' . PML_PREFIX . '_token_max_uses', true );
        $effective_max_uses = ( '' !== $max_uses_override && is_numeric($max_uses_override) )
            ? (int) $max_uses_override
            : (int) get_option( PML_PREFIX . '_settings_default_token_max_uses', 1 );

        wp_localize_script(
            PML_PLUGIN_SLUG . '-token-meta-box-js',
            'pml_token_meta_box_params',
            [
                'ajax_url'                     => admin_url( 'admin-ajax.php' ),
                'attachment_id'                => $post->ID,
                'nonce_fetch_tokens'           => wp_create_nonce( PML_PREFIX . '_fetch_attachment_tokens_nonce' ),
                'nonce_generate_token'         => wp_create_nonce( PML_PREFIX . '_generate_token_nonce' ),
                'nonce_revoke_token'           => wp_create_nonce( PML_PREFIX . '_revoke_token_nonce' ),
                'nonce_delete_token'           => wp_create_nonce( PML_PREFIX . '_delete_token_nonce' ),
                'nonce_reinstate_token'        => wp_create_nonce( PML_PREFIX . '_reinstate_token_nonce' ),
                'nonce_format_expiry'          => wp_create_nonce( PML_PREFIX . '_format_expiry_nonce'),
                'nonce_update_max_uses'        => wp_create_nonce( PML_PREFIX . '_update_max_uses_nonce'),
                'text_confirm_delete_token'    => esc_html__( 'Are you sure you want to permanently delete this token? This action cannot be undone.', PML_TEXT_DOMAIN ),
                'pml_prefix'                   => PML_PREFIX,
                'text_loading'                 => esc_html__( 'Loading...', PML_TEXT_DOMAIN ),
                'text_error'                   => esc_html__( 'An error occurred. Please try again.', PML_TEXT_DOMAIN ),
                'text_copied'                  => esc_html__( 'Copied!', PML_TEXT_DOMAIN ),
                'timezone_strings'             => wp_timezone_choice( wp_timezone_string(), get_user_locale() ),
                'default_token_expiry_options' => PML_Settings::get_token_expiry_options(),
                'user_date_format'             => get_option( 'date_format' ),
                'user_time_format'             => get_option( 'time_format' ),
                'user_timezone_string'         => wp_timezone_string(),
                'effective_max_uses'           => $effective_max_uses
            ],
        );
    }

    public function render_meta_box_content( WP_Post $post ): void
    {
        $attachment_id = $post->ID;
        $is_protected  = (bool) get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true );
        $prefix        = PML_PREFIX;

        echo "<div id='{$prefix}-token-manager-app' class='pml-token-manager-app' data-attachment-id='{$attachment_id}'>";

        if ( !$is_protected )
        {
            $url = sprintf( "%s#%s_media_protection_meta_box", get_edit_post_link( $attachment_id ), $prefix );
            echo "<div class='pml-notice pml-notice-info'>";
            printf( wp_kses( __( 'This file is not currently protected by PML. To manage access tokens, please <a href="%s">enable protection</a> in the "Media Protection Settings (PML)" meta box first.', PML_TEXT_DOMAIN ), [ 'a' => [ 'href' => [] ] ] ), esc_url( $url ) );
            echo '</div></div>';
            return;
        }

        $gen_title    = esc_html__( 'Generate New Token', PML_TEXT_DOMAIN );
        $create_btn   = esc_html__( 'Create Token', PML_TEXT_DOMAIN );
        $exist_title  = esc_html__( 'Existing Tokens', PML_TEXT_DOMAIN );
        $loading_text = esc_html__( 'Loading tokens...', PML_TEXT_DOMAIN );

        echo <<<EOT
            <h3>$gen_title</h3>
            <div class='$prefix-generate-token-form-wrapper'>
                <div id='$prefix-generate-token-form-fields'></div>
                <button type='button' id='$prefix-generate-token-button' class='button button-primary'>$create_btn</button>
                <span class='spinner' id='$prefix-generate-token-spinner'></span>
                <div id='$prefix-generate-token-feedback'></div>
            </div>
            <hr>
            <h3>$exist_title</h3>
            <div id='$prefix-tokens-list-wrapper'><p class='pml-loading-text'>$loading_text</p></div>
            <div id='$prefix-token-actions-feedback' style='margin-top: 10px;'></div>
        EOT;
        echo "</div>";
    }

    public function ajax_fetch_attachment_tokens(): void
    {
        check_ajax_referer( PML_PREFIX . '_fetch_attachment_tokens_nonce', 'nonce' );
        $attachment_id = isset( $_POST[ 'attachment_id' ] ) ? absint( $_POST[ 'attachment_id' ] ) : 0;
        $page          = isset( $_POST[ 'page' ] ) ? absint( $_POST[ 'page' ] ) : 1;
        $per_page      = isset( $_POST[ 'per_page' ] ) ? absint( $_POST[ 'per_page' ] ) : 10;

        if ( !$attachment_id || !current_user_can( 'edit_post', $attachment_id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request or insufficient permissions.', PML_TEXT_DOMAIN ) ], 403 );
        }

        // proactively update statuses for this attachment's tokens before fetching them
        if ( class_exists('PML_Token_Manager') ) {
            PML_Token_Manager::cleanup_tokens_for_attachment( $attachment_id );
        }

        global $wpdb;
        $table_name = PML_Token_Manager::$table_name;
        $offset     = ( $page - 1 ) * $per_page;

        $tokens = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE attachment_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", $attachment_id, $per_page, $offset ) );
        $total_tokens = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$table_name} WHERE attachment_id = %d", $attachment_id ) );

        $formatted_tokens = [];
        if ( $tokens ) {
            foreach ( $tokens as $token ) {
                $user_display_name = esc_html__( 'Guest', PML_TEXT_DOMAIN );
                if ( $token->user_id ) {
                    $user_data = get_userdata( $token->user_id );
                    if ( $user_data ) {
                        $user_edit_link    = get_edit_user_link( $token->user_id );
                        $user_display_name = '<a href="' . esc_url( $user_edit_link ) . '" target="_blank">' . esc_html( $user_data->display_name ) . ' (ID: ' . esc_html( $token->user_id ) . ')</a>';
                    } else {
                        $user_display_name = esc_html__( 'User ID: ', PML_TEXT_DOMAIN ) . esc_html( $token->user_id ) . esc_html__( ' (User not found)', PML_TEXT_DOMAIN );
                    }
                }
                $formatted_tokens[] = [
                    'id'                        => $token->id,
                    'token_value'               => esc_html( $token->token_value ),
                    'user_display_name'         => $user_display_name,
                    'created_at_raw'            => $token->created_at,
                    'expires_at_raw'            => $token->expires_at,
                    'last_used_at_raw'          => $token->last_used_at,
                    'use_count'                 => (int) $token->use_count,
                    'max_uses'                  => (int) $token->max_uses,
                    'status'                    => esc_html( $token->status ),
                    'attachment_url_with_token' => esc_url( add_query_arg( 'access_token', $token->token_value, wp_get_attachment_url( $attachment_id ) ) ),
                ];
            }
        }

        wp_send_json_success( [
                                  'tokens'     => $formatted_tokens,
                                  'pagination' => [
                                      'total_items'  => $total_tokens,
                                      'total_pages'  => ceil( $total_tokens / $per_page ),
                                      'current_page' => $page,
                                      'per_page'     => $per_page,
                                  ],
                                  'i18n'       => [
                                      'status_active'    => __( 'Active', PML_TEXT_DOMAIN ),
                                      'status_expired'   => __( 'Expired', PML_TEXT_DOMAIN ),
                                      'status_used'      => __( 'Used', PML_TEXT_DOMAIN ),
                                      'status_revoked'   => __( 'Revoked', PML_TEXT_DOMAIN ),
                                      'action_revoke'    => __( 'Revoke', PML_TEXT_DOMAIN ),
                                      'action_delete'    => __( 'Delete', PML_TEXT_DOMAIN ),
                                      'action_reinstate' => __( 'Reinstate', PML_TEXT_DOMAIN ),
                                      'action_copy_link' => __( 'Copy Link', PML_TEXT_DOMAIN ),
                                      'never_expires'    => __( 'Never', PML_TEXT_DOMAIN ),
                                      'not_yet_used'     => __( 'Not yet', PML_TEXT_DOMAIN ),
                                      'unlimited_uses'   => __( 'Unlimited', PML_TEXT_DOMAIN ),
                                  ],
                              ] );
    }

    public function ajax_generate_token(): void
    {
        check_ajax_referer( PML_PREFIX . '_generate_token_nonce', 'nonce' );
        $attachment_id = isset( $_POST[ 'attachment_id' ] ) ? absint( $_POST[ 'attachment_id' ] ) : 0;

        if ( !$attachment_id || !current_user_can( 'edit_post', $attachment_id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request or insufficient permissions.', PML_TEXT_DOMAIN ) ], 403 );
        }

        $expiry_type = isset( $_POST[ 'expiry_type' ] ) ? sanitize_key( $_POST[ 'expiry_type' ] ) : 'default';
        $token_args  = [];

        $token_args['max_uses'] = isset($_POST['max_uses']) && is_numeric($_POST['max_uses']) ? absint($_POST['max_uses']) : null;

        $max_uses_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_token_max_uses', true );
        $effective_max_uses = ( '' !== $max_uses_override && is_numeric($max_uses_override) )
            ? (int) $max_uses_override
            : (int) get_option( PML_PREFIX . '_settings_default_token_max_uses', 1 );

        if ($effective_max_uses > 0 && $token_args['max_uses'] > $effective_max_uses) {
            wp_send_json_error( [ 'message' => sprintf(esc_html__( 'Max uses cannot exceed the file limit of %d.', PML_TEXT_DOMAIN ), $effective_max_uses) ], 400 );
        }

        switch ( $expiry_type ) {
            case 'predefined':
                $token_args[ 'expires_in_seconds' ] = isset( $_POST[ 'predefined_duration_seconds' ] ) ? absint( $_POST[ 'predefined_duration_seconds' ] ) : null;
                break;

            case 'custom':
                $custom_datetime_str = isset( $_POST[ 'custom_datetime_str' ] ) ? sanitize_text_field( $_POST[ 'custom_datetime_str' ] ) : null;
                $custom_timezone     = isset( $_POST[ 'custom_timezone' ] ) ? sanitize_text_field( $_POST[ 'custom_timezone' ] ) : wp_timezone_string();

                if ( empty( $custom_datetime_str ) ) {
                    wp_send_json_error( [ 'message' => esc_html__( 'Custom date and time cannot be empty.', PML_TEXT_DOMAIN ) ], 400 );
                }

                try {
                    $valid_tz_string = $this->_get_valid_timezone_string($custom_timezone);
                    $user_tz_obj = new DateTimeZone( $valid_tz_string );
                    $datetime    = new DateTime( $custom_datetime_str, $user_tz_obj );
                    $datetime->setTimezone( new DateTimeZone( 'UTC' ) );
                    $utc_expires_at_str = $datetime->format( 'Y-m-d H:i:s' );

                    if ( !PML_Token_Manager::is_datetime_in_future( $utc_expires_at_str ) ) {
                        wp_send_json_error( [ 'message' => esc_html__( 'Custom expiry date must be in the future.', PML_TEXT_DOMAIN ) ], 400 );
                    }
                    $token_args[ 'utc_expires_at' ] = $utc_expires_at_str;
                } catch ( Exception $e ) {
                    wp_send_json_error( [ 'message' => esc_html__( 'Invalid custom date, time, or timezone.', PML_TEXT_DOMAIN ) . ' ' . $e->getMessage() ], 400 );
                }
                break;
        }

        $token_data = PML_Token_Manager::generate_token_data( $attachment_id, $token_args );
        $new_token  = PML_Token_Manager::store_token( $token_data );

        if ( $new_token ) {
            wp_send_json_success( [ 'message' => esc_html__( 'Token generated successfully.', PML_TEXT_DOMAIN ), 'token' => $new_token, ] );
        } else {
            global $wpdb;
            $db_error_message = !empty($wpdb->last_error) ? ' DB Error: ' . esc_html($wpdb->last_error) : '';
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to store the new token.', PML_TEXT_DOMAIN ) . $db_error_message ], 500 );
        }
    }

    public function ajax_revoke_token(): void
    {
        check_ajax_referer( PML_PREFIX . '_revoke_token_nonce', 'nonce' );
        $token_id = isset( $_POST[ 'token_id' ] ) ? absint( $_POST[ 'token_id' ] ) : 0;

        if ( !$token_id || !current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request or insufficient permissions.', PML_TEXT_DOMAIN ) ], 403 );
        }

        global $wpdb;
        $table_name = PML_Token_Manager::$table_name;
        $token      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $token_id ) );

        if ( !$token || !current_user_can( 'edit_post', $token->attachment_id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Token not found or permission denied.', PML_TEXT_DOMAIN ) ], 404 );
        }

        $updated = PML_Token_Manager::update_token_fields( $token->token_value, [ 'status' => 'revoked' ], [ '%s' ] );

        if ( $updated ) {
            wp_send_json_success( [ 'message' => esc_html__( 'Token has been revoked.', PML_TEXT_DOMAIN ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to revoke the token.', PML_TEXT_DOMAIN ) ], 500 );
        }
    }

    public function ajax_delete_token(): void
    {
        check_ajax_referer( PML_PREFIX . '_delete_token_nonce', 'nonce' );
        $token_id = isset( $_POST[ 'token_id' ] ) ? absint( $_POST[ 'token_id' ] ) : 0;

        if ( !$token_id || !current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request or insufficient permissions.', PML_TEXT_DOMAIN ) ], 403 );
        }

        global $wpdb;
        $table_name = PML_Token_Manager::$table_name;
        $token      = $wpdb->get_row( $wpdb->prepare( "SELECT attachment_id FROM {$table_name} WHERE id = %d", $token_id ) );

        if ( !$token || !current_user_can( 'edit_post', $token->attachment_id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Token not found or permission denied.', PML_TEXT_DOMAIN ) ], 404 );
        }

        $deleted = $wpdb->delete( $table_name, [ 'id' => $token_id ], [ '%d' ] );

        if ( $deleted ) {
            wp_send_json_success( [ 'message' => esc_html__( 'Token permanently deleted.', PML_TEXT_DOMAIN ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to delete the token.', PML_TEXT_DOMAIN ) ], 500 );
        }
    }

    public function ajax_reinstate_token(): void
    {
        check_ajax_referer( PML_PREFIX . '_reinstate_token_nonce', 'nonce' );
        $token_id = isset( $_POST[ 'token_id' ] ) ? absint( $_POST[ 'token_id' ] ) : 0;

        if ( !$token_id || !current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request or insufficient permissions.', PML_TEXT_DOMAIN ) ], 403 );
        }

        global $wpdb;
        $table_name = PML_Token_Manager::$table_name;
        $token      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $token_id ) );

        if ( !$token || !current_user_can( 'edit_post', $token->attachment_id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Token not found or permission denied.', PML_TEXT_DOMAIN ) ], 404 );
        }

        $new_expiry_type     = isset( $_POST[ 'new_expiry_type' ] ) ? sanitize_key( $_POST[ 'new_expiry_type' ] ) : null;
        $data_to_update      = [ 'status' => 'active' ];
        $format_to_update    = [ '%s' ];
        $utc_expires_at_str  = $token->expires_at;

        if ( $new_expiry_type ) {
            switch ( $new_expiry_type ) {
                case 'predefined':
                    $expires_in_seconds = isset( $_POST[ 'new_predefined_duration_seconds' ] ) ? absint( $_POST[ 'new_predefined_duration_seconds' ] ) : HOUR_IN_SECONDS;
                    $utc_expires_at_str = ( $expires_in_seconds > 0 ) ? gmdate( 'Y-m-d H:i:s', time() + $expires_in_seconds ) : null;
                    break;
                case 'custom':
                    $custom_datetime_str = isset( $_POST[ 'new_custom_datetime_str' ] ) ? sanitize_text_field( $_POST[ 'new_custom_datetime_str' ] ) : '';
                    $custom_timezone     = isset( $_POST[ 'new_custom_timezone' ] ) ? sanitize_text_field( $_POST[ 'new_custom_timezone' ] ) : wp_timezone_string();

                    if ( empty( $custom_datetime_str ) ) {
                        wp_send_json_error( [ 'message' => esc_html__( 'New custom expiry date cannot be empty.', PML_TEXT_DOMAIN ) ], 400 );
                    }

                    try {
                        $valid_tz_string = $this->_get_valid_timezone_string($custom_timezone);
                        $datetime = new DateTime( $custom_datetime_str, new DateTimeZone( $valid_tz_string ) );
                        $datetime->setTimezone( new DateTimeZone( 'UTC' ) );
                        $utc_expires_at_str = $datetime->format( 'Y-m-d H:i:s' );
                    } catch ( Exception $e ) {
                        wp_send_json_error( [ 'message' => esc_html__( 'Invalid new custom expiry date.', PML_TEXT_DOMAIN ) ], 400 );
                    }
                    break;
                case 'default':
                default:
                    $default_expiry_seconds = (int) get_option( PML_PREFIX . '_settings_default_token_expiry', 24 * HOUR_IN_SECONDS );
                    $utc_expires_at_str     = ( $default_expiry_seconds > 0 ) ? gmdate( 'Y-m-d H:i:s', time() + $default_expiry_seconds ) : null;
            }
        }

        if ( !PML_Token_Manager::is_datetime_in_future( $utc_expires_at_str ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'The new expiry date must be in the future.', PML_TEXT_DOMAIN ) ], 400 );
        }

        $data_to_update[ 'expires_at' ] = $utc_expires_at_str;
        $format_to_update[]             = '%s';

        $updated = PML_Token_Manager::update_token_fields( $token->token_value, $data_to_update, $format_to_update );

        if ( $updated ) {
            wp_send_json_success( [ 'message' => esc_html__( 'Token has been reinstated.', PML_TEXT_DOMAIN ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to reinstate the token.', PML_TEXT_DOMAIN ) ], 500 );
        }
    }

    public function ajax_get_formatted_expiry_time() {
        check_ajax_referer( PML_PREFIX . '_format_expiry_nonce', 'nonce' );

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $date_str = isset( $_POST['date_str'] ) ? sanitize_text_field( $_POST['date_str'] ) : '';
        $selected_tz_str = isset( $_POST['selected_tz'] ) ? sanitize_text_field( $_POST['selected_tz'] ) : '';
        $local_tz_str = isset( $_POST['local_tz'] ) ? sanitize_text_field( $_POST['local_tz'] ) : wp_timezone_string();

        if (empty($date_str) || empty($selected_tz_str)) {
            wp_send_json_error(['message' => 'Missing date or timezone.'], 400);
        }

        try {
            $valid_selected_tz_string = $this->_get_valid_timezone_string($selected_tz_str);
            $selected_tz_obj = new DateTimeZone($valid_selected_tz_string);
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Invalid timezone provided.'], 400);
            return;
        }

        $date_obj = new DateTime($date_str, $selected_tz_obj);

        $response = [];
        $date_format_string = get_option('date_format', 'F j, Y');
        $time_format_string = get_option('time_format', 'g:i a');
        $datetime_format = "{$date_format_string} {$time_format_string}";

        $response['expires_in_selected_tz'] = wp_date("{$datetime_format} T", $date_obj->getTimestamp(), $selected_tz_obj);

        if ($selected_tz_str !== $local_tz_str) {
            try {
                $valid_local_tz_string = $this->_get_valid_timezone_string($local_tz_str);
                $local_tz_obj = new DateTimeZone($valid_local_tz_string);
                $response['expires_in_local_tz'] = wp_date("{$datetime_format} T", $date_obj->getTimestamp(), $local_tz_obj);
            } catch (Exception $e) {
                $response['expires_in_local_tz'] = null;
            }
        }

        $response['relative_time'] = human_time_diff($date_obj->getTimestamp(), current_time('timestamp')) . ' from now';

        wp_send_json_success($response);
    }

    public function ajax_update_token_max_uses() {
        check_ajax_referer( PML_PREFIX . '_update_max_uses_nonce', 'nonce' );

        $token_id = isset( $_POST[ 'token_id' ] ) ? absint( $_POST[ 'token_id' ] ) : 0;
        $new_max_uses = isset($_POST['max_uses']) ? absint($_POST['max_uses']) : 0;

        if ( !$token_id || !current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid request or insufficient permissions.', PML_TEXT_DOMAIN ) ], 403 );
        }

        global $wpdb;
        $table_name = PML_Token_Manager::$table_name;
        $token = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $token_id ) );

        if ( !$token || !current_user_can( 'edit_post', $token->attachment_id ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Token not found or permission denied.', PML_TEXT_DOMAIN ) ], 404 );
        }

        $max_uses_override = get_post_meta( $token->attachment_id, '_' . PML_PREFIX . '_token_max_uses', true );
        $effective_max_uses = ( '' !== $max_uses_override && is_numeric($max_uses_override) )
            ? (int) $max_uses_override
            : (int) get_option( PML_PREFIX . '_settings_default_token_max_uses', 1 );

        if ($effective_max_uses > 0 && $new_max_uses > $effective_max_uses) {
            wp_send_json_error( [ 'message' => sprintf(esc_html__( 'Update failed. Max uses cannot exceed the file limit of %d.', PML_TEXT_DOMAIN ), $effective_max_uses) ], 400 );
        }

        if ($new_max_uses > 0 && $token->use_count >= $new_max_uses) {
            wp_send_json_error( [ 'message' => esc_html__( 'Update failed. New max uses must be greater than the current number of uses.', PML_TEXT_DOMAIN ) ], 400 );
        }

        $data_to_update = ['max_uses' => $new_max_uses];
        $format_to_update = ['%d'];

        $updated = PML_Token_Manager::update_token_fields($token->token_value, $data_to_update, $format_to_update);

        if ( $updated ) {
            wp_send_json_success( [ 'message' => esc_html__( 'Max uses updated successfully.', PML_TEXT_DOMAIN ) ] );
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to update max uses. The value may be the same as the current one.', PML_TEXT_DOMAIN ) ], 500 );
        }
    }
}
