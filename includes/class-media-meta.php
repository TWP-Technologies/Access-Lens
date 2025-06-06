<?php
/**
 * Media Library Meta Box for Protection Settings
 *
 * @package ProtectedMediaLinks
 */
// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class PML_Media_Meta
{
    private string $meta_box_id;
    private string $nonce_action;
    private string $nonce_name;

    public function __construct()
    {
        $this->meta_box_id  = PML_PREFIX . '_media_protection_meta_box';
        $this->nonce_action = PML_PREFIX . '_save_media_protection_meta';
        $this->nonce_name   = PML_PREFIX . '_media_protection_nonce';

        add_action( 'add_meta_boxes_attachment', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_attachment', [ $this, 'save_meta_data' ], 10, 1 ); // Bulk / quick edit
        add_action( 'edit_attachment', [ $this, 'save_meta_data' ] );             // Media-edit form
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_attachment_edit_scripts' ] );
    }

    public function enqueue_attachment_edit_scripts( string $hook_suffix ): void
    {
        global $pagenow;
        $is_attachment_edit_page = ( 'post.php' === $pagenow &&
                                     isset( $_GET[ 'post' ] ) &&
                                     'attachment' === get_post_type( absint( $_GET[ 'post' ] ) ) );

        if ( !$is_attachment_edit_page )
        {
            return;
        }

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
            [ 'jquery', 'select2', PML_PLUGIN_SLUG . '-admin-common-utils-js', 'wp-i18n' ],
            PML_VERSION,
            true,
        );
        wp_set_script_translations( PML_PLUGIN_SLUG . '-media-library-js', PML_TEXT_DOMAIN, PML_PLUGIN_DIR . 'languages' );

        $pml_admin_params_for_select2 = [
            'ajax_url'                => admin_url( 'admin-ajax.php' ),
            'search_users_nonce'      => wp_create_nonce( PML_PREFIX . '_search_users_nonce' ),
            'user_select_placeholder' => esc_html__( 'Search for users by name or email...', PML_TEXT_DOMAIN ),
            'plugin_prefix'           => PML_PREFIX,
        ];
        wp_localize_script( PML_PLUGIN_SLUG . '-media-library-js', 'pml_admin_params', $pml_admin_params_for_select2 );
    }

    public function add_meta_box( WP_Post $post )
    {
        add_meta_box(
            $this->meta_box_id,
            esc_html__( 'Media Protection Settings (PML)', PML_TEXT_DOMAIN ),
            [ $this, 'render_full_meta_box_content' ],
            'attachment',
            'normal',
            'high',
        );
    }

    public function render_full_meta_box_content( WP_Post $post )
    {
        wp_nonce_field( $this->nonce_action, $this->nonce_name );
        $attachment_id = $post->ID;

        echo '<div class="' . esc_attr( PML_PLUGIN_SLUG ) . '-media-meta pml-settings-form-fields">';
        // Render the "quick edit" fields which are the primary ones (Protect, Redirect, Bot Access)
        // The true flag indicates it's the full meta box context.
        self::render_quick_edit_form_fields( $attachment_id, true );

        echo '<hr class="pml-meta-hr">';
        echo '<div class="pml-tip-wrapper" style="display: flex; align-items: center; gap: 4px;">';
        echo '<h4>' . esc_html__( 'Advanced Access Control (Overridden by Global)', PML_TEXT_DOMAIN ) . '</h4>';
        // take to global settings priorities tip
        echo '<span></span><a href="' .
             esc_url( admin_url( 'admin.php?page=' . PML_PLUGIN_SLUG . '&open-priorities-tip=1' ) ) .
             '" target="_blank" rel="noopener noreferrer">' .
             esc_html__( 'Learn about Global Priorities', PML_TEXT_DOMAIN ) .
             '</a>';
        echo '</div>';
        // Per-file User Lists
        $user_allow_list_ids = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_user_allow_list', true );
        $user_allow_list_ids = is_array( $user_allow_list_ids ) ? $user_allow_list_ids : [];
        $user_deny_list_ids  = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_user_deny_list', true );
        $user_deny_list_ids  = is_array( $user_deny_list_ids ) ? $user_deny_list_ids : [];
        ?>
        <div class="pml-meta-field-group">
            <label for="<?php echo esc_attr( PML_PREFIX ); ?>_user_allow_list_select_full"><?php esc_html_e(
                    'Allow Specific Users:',
                    PML_TEXT_DOMAIN,
                ); ?></label>
            <select id="<?php echo esc_attr( PML_PREFIX ); ?>_user_allow_list_select_full"
                    name="<?php echo esc_attr( PML_PREFIX ); ?>_user_allow_list[]"
                    multiple="multiple"
                    class="pml-user-select-ajax widefat"
                    data-placeholder="<?php esc_attr_e( 'Search users to allow...', PML_TEXT_DOMAIN ); ?>">
                <?php foreach ( $user_allow_list_ids as $user_id ) : $user = get_userdata( $user_id );
                    if ( $user ) : ?>
                        <option value="<?php echo esc_attr( $user_id ); ?>" selected="selected"><?php echo esc_html(
                                $user->display_name . ' (' . $user->user_login . ')',
                            ); ?></option>
                    <?php endif; endforeach; ?>
            </select>
            <p class="description pml-field-description"><?php esc_html_e(
                    'These users can access this file; overridden by global user allow/deny lists.',
                    PML_TEXT_DOMAIN,
                ); ?></p>
        </div>
        <div class="pml-meta-field-group">
            <label for="<?php echo esc_attr( PML_PREFIX ); ?>_user_deny_list_select_full"><?php esc_html_e(
                    'Deny Specific Users:',
                    PML_TEXT_DOMAIN,
                ); ?></label>
            <select id="<?php echo esc_attr( PML_PREFIX ); ?>_user_deny_list_select_full"
                    name="<?php echo esc_attr( PML_PREFIX ); ?>_user_deny_list[]"
                    multiple="multiple"
                    class="pml-user-select-ajax widefat"
                    data-placeholder="<?php esc_attr_e( 'Search users to deny...', PML_TEXT_DOMAIN ); ?>">
                <?php foreach ( $user_deny_list_ids as $user_id ) : $user = get_userdata( $user_id );
                    if ( $user ) : ?>
                        <option value="<?php echo esc_attr( $user_id ); ?>" selected="selected"><?php echo esc_html(
                                $user->display_name . ' (' . $user->user_login . ')',
                            ); ?></option>
                    <?php endif; endforeach; ?>
            </select>
            <p class="description pml-field-description"><?php esc_html_e(
                    'These users will be denied access; overridden by global user allow/deny lists.',
                    PML_TEXT_DOMAIN,
                ); ?></p>
        </div>
        <?php

        // Per-file Role Lists
        $role_allow_list = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_role_allow_list', true );
        $role_allow_list = is_array( $role_allow_list ) ? $role_allow_list : [];
        $role_deny_list  = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_role_deny_list', true );
        $role_deny_list  = is_array( $role_deny_list ) ? $role_deny_list : [];

        $all_roles_editable = [];
        $editable_roles     = get_editable_roles();
        foreach ( $editable_roles as $role_slug => $role_details )
        {
            $all_roles_editable[ $role_slug ] = translate_user_role( $role_details[ 'name' ] );
        }
        asort( $all_roles_editable );
        ?>
        <div class="pml-meta-field-group">
            <label for="<?php echo esc_attr( PML_PREFIX ); ?>_role_allow_list_select_full"><?php esc_html_e(
                    'Allow Specific Roles:',
                    PML_TEXT_DOMAIN,
                ); ?></label>
            <select id="<?php echo esc_attr( PML_PREFIX ); ?>_role_allow_list_select_full"
                    name="<?php echo esc_attr( PML_PREFIX ); ?>_role_allow_list[]"
                    multiple="multiple"
                    class="pml-role-select-media-meta widefat"
                    data-placeholder="<?php esc_attr_e( 'Select roles to allow...', PML_TEXT_DOMAIN ); ?>">
                <?php foreach ( $all_roles_editable as $slug => $name ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected(
                        in_array( $slug, (array)$role_allow_list, true ),
                    ); ?>><?php echo esc_html( $name . ' (' . $slug . ')' ); ?></option>
                <?php endforeach; ?>
            </select>
            <p class='description pml-field-description'><?php esc_html_e(
                    'These roles can access this file; overridden by global and per-file user allow/deny lists.',
                    PML_TEXT_DOMAIN,
                ); ?></p>
        </div>
        <div class="pml-meta-field-group">
            <label for="<?php echo esc_attr( PML_PREFIX ); ?>_role_deny_list_select_full"><?php esc_html_e(
                    'Deny Specific Roles:',
                    PML_TEXT_DOMAIN,
                ); ?></label>
            <select id="<?php echo esc_attr( PML_PREFIX ); ?>_role_deny_list_select_full"
                    name="<?php echo esc_attr( PML_PREFIX ); ?>_role_deny_list[]"
                    multiple="multiple"
                    class="pml-role-select-media-meta widefat"
                    data-placeholder="<?php esc_attr_e( 'Select roles to deny...', PML_TEXT_DOMAIN ); ?>">
                <?php foreach ( $all_roles_editable as $slug => $name ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected(
                        in_array( $slug, (array)$role_deny_list, true ),
                    ); ?>><?php echo esc_html( $name . ' (' . $slug . ')' ); ?></option>
                <?php endforeach; ?>
            </select>
            <p class='description pml-field-description'><?php esc_html_e(
                    'These roles will be denied access; overridden by global and per-file user allow/deny lists.',
                    PML_TEXT_DOMAIN,
                ); ?></p>
        </div>
        <?php
        // Token specific overrides
        $token_expiry_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_token_expiry', true );
        $max_uses_override     = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_token_max_uses', true );

        $global_token_expiry_options = [];
        if ( class_exists( 'PML_Settings' ) && method_exists( 'PML_Settings', 'get_token_expiry_options' ) )
        {
            $global_token_expiry_options = PML_Settings::get_token_expiry_options();
        }
        else
        {
            error_log( PML_PLUGIN_NAME . ' Error: Could not retrieve token expiry options from PML_Settings for full meta box.' );
        }
        ?>
        <hr class="pml-meta-hr"><h4><?php esc_html_e( 'Token Parameter Overrides', PML_TEXT_DOMAIN ); ?></h4>
        <div class="pml-meta-field-group">
            <label for="<?php echo esc_attr( PML_PREFIX ); ?>_token_expiry_override_full"><?php esc_html_e(
                    'Token Expiry Override:',
                    PML_TEXT_DOMAIN,
                ); ?></label>
            <select id="<?php echo esc_attr( PML_PREFIX ); ?>_token_expiry_override_full"
                    name="<?php echo esc_attr( PML_PREFIX ); ?>_token_expiry"
                    class="widefat">
                <option value="" <?php selected( (string)$token_expiry_override, '' ); ?>><?php esc_html_e(
                        'Use Global Setting',
                        PML_TEXT_DOMAIN,
                    ); ?> (<?php echo esc_html( PML_Core::format_duration( get_option( PML_PREFIX . '_settings_default_token_expiry' ) ) ); ?>)
                </option>
                <?php foreach ( $global_token_expiry_options as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected(
                        (string)$token_expiry_override,
                        (string)$value,
                    ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="pml-meta-field-group">
            <label for="<?php echo esc_attr( PML_PREFIX ); ?>_token_max_uses_override_full"><?php esc_html_e(
                    'Token Max Uses Override:',
                    PML_TEXT_DOMAIN,
                ); ?></label>
            <input type="number"
                   id="<?php echo esc_attr( PML_PREFIX ); ?>_token_max_uses_override_full"
                   name="<?php echo esc_attr( PML_PREFIX ); ?>_token_max_uses"
                   value="<?php echo esc_attr( $max_uses_override ); ?>"
                   class="small-text"
                   min="0"
                   step="1"
                   placeholder="<?php esc_attr_e( 'Global', PML_TEXT_DOMAIN ); ?> (<?php echo esc_attr(
                       get_option( PML_PREFIX . '_settings_default_token_max_uses', 1 ),
                   ); ?>)"/>
            <p class="description pml-field-description"><?php esc_html_e(
                    'Enter 0 for unlimited uses. Leave blank to use global default.',
                    PML_TEXT_DOMAIN,
                ); ?></p>
        </div>
        <?php
        echo '</div>';
    }

    /**
     * Renders the minimal set of PML fields for quick edit forms (AJAX loaded) or the top part of the full meta box.
     *
     * @param int  $attachment_id            The ID of the attachment.
     * @param bool $is_full_meta_box_context Is this being rendered in the full meta box (true) or quick edit (false)?
     *                                       If true, the "Protect this file?" checkbox is included.
     *                                       If false (quick edit for grid/list), "Protect this file?" is omitted.
     */
    public static function render_quick_edit_form_fields( int $attachment_id, bool $is_full_meta_box_context = false )
    {
        $is_protected          = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true );
        $redirect_url_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_redirect_url', true );
        $allow_bots_override   = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_allow_bots_for_file', true );

        // Field names are prefixed for AJAX quick edit forms to be grouped under 'pml_settings' in $_POST.
        // For the full meta box, they are direct POST keys.
        $field_name_is_protected = $is_full_meta_box_context ? PML_PREFIX . '_is_protected' : 'pml_settings[' . PML_PREFIX . '_is_protected]';
        $field_name_redirect_url = $is_full_meta_box_context ? PML_PREFIX . '_redirect_url' : 'pml_settings[' . PML_PREFIX . '_redirect_url]';
        $field_name_allow_bots   = $is_full_meta_box_context ? PML_PREFIX . '_allow_bots_for_file'
            : 'pml_settings[' .
                                                                                                     PML_PREFIX .
                                                                                                     '_allow_bots_for_file]';

        // Use unique IDs for quick edit form fields to avoid conflicts if rendered on same page as full meta box
        $id_suffix = $is_full_meta_box_context ? '_full' : '_qef'; // _qef for Quick Edit Form
        ?>
        <div class="pml-quick-edit-fields">
            <?php // Only show "Protect this file?" in the full meta box context. Grid/List view has its own main toggle.
            if ( $is_full_meta_box_context ) : ?>
                <div class="pml-meta-field-group">
                    <label class="pml-checkbox-label">
                        <input type="checkbox"
                               id="<?php echo esc_attr( PML_PREFIX . '_is_protected' . $id_suffix ); ?>"
                               name="<?php echo esc_attr( $field_name_is_protected ); ?>"
                               value="1" <?php checked( $is_protected, '1' ); ?> />
                        <?php esc_html_e( 'Protect this file?', PML_TEXT_DOMAIN ); ?>
                    </label>
                    <p class="description pml-field-description"><?php esc_html_e(
                            'Enable protection to restrict direct access based on defined rules.',
                            PML_TEXT_DOMAIN,
                        ); ?></p>
                </div>
            <?php endif; ?>

            <div class="pml-meta-field-group">
                <label for="<?php echo esc_attr( PML_PREFIX ); ?>_redirect_url_override<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e(
                        'Unauthorized Redirect URL (Override):',
                        PML_TEXT_DOMAIN,
                    ); ?></label>
                <input type="url"
                       id="<?php echo esc_attr( PML_PREFIX ); ?>_redirect_url_override<?php echo esc_attr( $id_suffix ); ?>"
                       name="<?php echo esc_attr( $field_name_redirect_url ); ?>"
                       value="<?php echo esc_url( $redirect_url_override ); ?>"
                       class="widefat"
                       placeholder="<?php esc_attr_e( 'Global default', PML_TEXT_DOMAIN ); ?> (<?php echo esc_url(
                           get_option( PML_PREFIX . '_settings_default_redirect_url', home_url( '/' ) ),
                       ); ?>)"/>
                <?php if ( $is_full_meta_box_context ): ?>
                    <p class="description pml-field-description"><?php esc_html_e(
                            'If unauthorized, redirect to this URL. Leave blank for global default.',
                            PML_TEXT_DOMAIN,
                        ); ?></p>
                <?php endif; ?>
            </div>

            <div class="pml-meta-field-group">
                <label for="<?php echo esc_attr( PML_PREFIX ); ?>_allow_bots_override<?php echo esc_attr( $id_suffix ); ?>"><?php esc_html_e(
                        'Bot Access Override:',
                        PML_TEXT_DOMAIN,
                    ); ?></label>
                <select id="<?php echo esc_attr( PML_PREFIX ); ?>_allow_bots_override<?php echo esc_attr( $id_suffix ); ?>"
                        name="<?php echo esc_attr( $field_name_allow_bots ); ?>"
                        class="widefat">
                    <option value="" <?php selected( $allow_bots_override, '' ); ?>>
                        <?php esc_html_e( 'Use Global Setting', PML_TEXT_DOMAIN ); ?>
                        (<?php echo get_option( PML_PREFIX . '_settings_allow_bots' ) ? esc_html__( 'Currently: Allow Bots', PML_TEXT_DOMAIN )
                            : esc_html__( 'Currently: Block Bots', PML_TEXT_DOMAIN ); ?>)
                    </option>
                    <option value="yes" <?php selected( $allow_bots_override, 'yes' ); ?>><?php esc_html_e(
                            'Yes, Allow Bots for this file',
                            PML_TEXT_DOMAIN,
                        ); ?></option>
                    <option value="no" <?php selected( $allow_bots_override, 'no' ); ?>><?php esc_html_e(
                            'No, Block Bots for this file',
                            PML_TEXT_DOMAIN,
                        ); ?></option>
                </select>
            </div>

            <?php if ( !$is_full_meta_box_context ) :
                $full_edit_link = get_edit_post_link( $attachment_id, 'raw' );
                ?>
                <div class="pml-meta-field-group pml-quick-edit-advanced-link">
                    <hr class="pml-meta-hr-dashed">
                    <p>
                        <a href="<?php echo esc_url( $full_edit_link ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Advanced Settings (User/Role Lists, Token Params)...', PML_TEXT_DOMAIN ); ?>
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </p>
                    <p class="description pml-field-description">
                        <?php
                        $user_allow_list_ids_qef = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_user_allow_list', true );
                        $user_deny_list_ids_qef  = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_user_deny_list', true );
                        $role_allow_list_qef     = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_role_allow_list', true );
                        $role_deny_list_qef      = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_role_deny_list', true );

                        $user_allow_count = count( is_array( $user_allow_list_ids_qef ) ? $user_allow_list_ids_qef : [] );
                        $user_deny_count  = count( is_array( $user_deny_list_ids_qef ) ? $user_deny_list_ids_qef : [] );
                        $role_allow_count = count( is_array( $role_allow_list_qef ) ? $role_allow_list_qef : [] );
                        $role_deny_count  = count( is_array( $role_deny_list_qef ) ? $role_deny_list_qef : [] );

                        printf(
                            esc_html__( 'Current lists: User Allow (%d), User Deny (%d), Role Allow (%d), Role Deny (%d).', PML_TEXT_DOMAIN ),
                            $user_allow_count,
                            $user_deny_count,
                            $role_allow_count,
                            $role_deny_count,
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_meta_data( int $post_id )
    {
        error_log( 'PML SAVE: save_meta_data called for post ID: ' . $post_id );
        // Verify nonce (this is for the full attachment edit page save action)
        if ( !isset( $_POST[ $this->nonce_name ] ) ||
             !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $this->nonce_name ] ) ), $this->nonce_action ) )
        {
            return;
        }
        // Check if the current user has permission to edit the post.
        if ( !current_user_can( 'edit_post', $post_id ) )
        {
            return;
        }
        // Don't save if it's an autosave.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
        {
            return;
        }
        // Check post type.
        if ( 'attachment' !== get_post_type( $post_id ) )
        {
            return;
        }

        // --- Save "Quick Edit" equivalent fields (present at top of full meta box) ---
        // These fields are submitted directly with PML_PREFIX names.
        $is_protected_value = isset( $_POST[ PML_PREFIX . '_is_protected' ] ) ? '1' : '0';
        update_post_meta( $post_id, '_' . PML_PREFIX . '_is_protected', $is_protected_value );

        $redirect_url_value = isset( $_POST[ PML_PREFIX . '_redirect_url' ] ) ? esc_url_raw( wp_unslash( $_POST[ PML_PREFIX . '_redirect_url' ] ) )
            : '';
        if ( !empty( $redirect_url_value ) )
        {
            update_post_meta( $post_id, '_' . PML_PREFIX . '_redirect_url', $redirect_url_value );
        }
        else
        {
            delete_post_meta( $post_id, '_' . PML_PREFIX . '_redirect_url' );
        }

        $allow_bots_value = isset( $_POST[ PML_PREFIX . '_allow_bots_for_file' ] ) ? sanitize_key(
            wp_unslash( $_POST[ PML_PREFIX . '_allow_bots_for_file' ] ),
        ) : '';
        if ( in_array( $allow_bots_value, [ 'yes', 'no', '' ], true ) )
        {
            if ( $allow_bots_value === '' )
            { // "Use Global" means delete the meta
                delete_post_meta( $post_id, '_' . PML_PREFIX . '_allow_bots_for_file' );
            }
            else
            {
                update_post_meta( $post_id, '_' . PML_PREFIX . '_allow_bots_for_file', $allow_bots_value );
            }
        }

        // --- Save Advanced List Fields ---
        $list_keys = [
            PML_PREFIX . '_user_allow_list',
            PML_PREFIX . '_user_deny_list',
            PML_PREFIX . '_role_allow_list',
            PML_PREFIX . '_role_deny_list',
        ];
        foreach ( $list_keys as $post_key )
        {
            $meta_key     = '_' . $post_key;
            $is_user_list = strpos( $post_key, '_user_' ) !== false;

            if ( isset( $_POST[ $post_key ] ) && is_array( $_POST[ $post_key ] ) )
            {
                $raw_list       = wp_unslash( $_POST[ $post_key ] );
                $sanitized_list = array_map( $is_user_list ? 'absint' : 'sanitize_key', $raw_list );
                $filtered_list  = array_unique( array_filter( $sanitized_list ) ); // Remove empty values and duplicates
                if ( !empty( $filtered_list ) )
                {
                    update_post_meta( $post_id, $meta_key, $filtered_list );
                }
                else
                {
                    delete_post_meta( $post_id, $meta_key ); // If list becomes empty, delete meta
                }
            }
            else
            {
                // If the key is not in POST (e.g. all items removed from Select2), save an empty array or delete.
                // Deleting is cleaner for "no override".
                delete_post_meta( $post_id, $meta_key );
            }
        }

        // --- Save Token Parameter Overrides ---
        $token_expiry_value = isset( $_POST[ PML_PREFIX . '_token_expiry' ] ) ? sanitize_text_field(
            wp_unslash( $_POST[ PML_PREFIX . '_token_expiry' ] ),
        ) : '';
        if ( $token_expiry_value === '' || ( is_numeric( $token_expiry_value ) && (int)$token_expiry_value >= 0 ) )
        {
            if ( $token_expiry_value === '' )
            {
                delete_post_meta( $post_id, '_' . PML_PREFIX . '_token_expiry' );
            }
            else
            {
                update_post_meta( $post_id, '_' . PML_PREFIX . '_token_expiry', $token_expiry_value );
            }
        }

        $max_uses_value = isset( $_POST[ PML_PREFIX . '_token_max_uses' ] ) ? sanitize_text_field(
            wp_unslash( $_POST[ PML_PREFIX . '_token_max_uses' ] ),
        ) : '';
        if ( $max_uses_value === '' || ( is_numeric( $max_uses_value ) && (int)$max_uses_value >= 0 ) )
        {
            if ( $max_uses_value === '' )
            {
                delete_post_meta( $post_id, '_' . PML_PREFIX . '_token_max_uses' );
            }
            else
            {
                update_post_meta( $post_id, '_' . PML_PREFIX . '_token_max_uses', $max_uses_value );
            }
        }
    }

    public static function save_quick_edit_data( int $attachment_id, array $settings_data ): bool
    {
        if ( !current_user_can( 'edit_post', $attachment_id ) || 'attachment' !== get_post_type( $attachment_id ) )
        {
            return false;
        }

        // This function now only saves fields present in the quick edit form:
        // - pml_redirect_url
        // - pml_allow_bots_for_file
        // The 'is_protected' status is handled by a separate AJAX toggle action (_toggle_protection_status).

        $redirect_url_key = PML_PREFIX . '_redirect_url';
        if ( array_key_exists( $redirect_url_key, $settings_data ) )
        {
            $redirect_url_value = esc_url_raw( $settings_data[ $redirect_url_key ] );
            if ( !empty( $redirect_url_value ) )
            {
                update_post_meta( $attachment_id, '_' . $redirect_url_key, $redirect_url_value );
            }
            else
            {
                delete_post_meta( $attachment_id, '_' . $redirect_url_key );
            }
        }

        $allow_bots_key = PML_PREFIX . '_allow_bots_for_file';
        if ( array_key_exists( $allow_bots_key, $settings_data ) )
        {
            $allow_bots_value = sanitize_key( $settings_data[ $allow_bots_key ] );
            if ( in_array( $allow_bots_value, [ 'yes', 'no', '' ], true ) )
            {
                if ( $allow_bots_value === '' )
                { // "Use Global"
                    delete_post_meta( $attachment_id, '_' . $allow_bots_key );
                }
                else
                {
                    update_post_meta( $attachment_id, '_' . $allow_bots_key, $allow_bots_value );
                }
            }
        }

        do_action( PML_PREFIX . '_after_quick_edit_save', $attachment_id, $settings_data );
        return true;
    }
}
