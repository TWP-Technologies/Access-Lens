<?php
/**
 * Handles actions related to global user allow/deny lists from the WP Users page.
 *
 * @package AccessLens
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * PML_User_List_Actions Class.
 */
class PML_User_List_Actions
{

    /**
     * Option name for global user allow list.
     *
     * @var string
     */
    private string $allow_list_option;

    /**
     * Option name for global user deny list.
     *
     * @var string
     */
    private string $deny_list_option;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->allow_list_option = PML_PREFIX . '_global_user_allow_list';
        $this->deny_list_option  = PML_PREFIX . '_global_user_deny_list';

        add_filter( 'user_row_actions', [ $this, 'add_user_row_actions' ], 10, 2 );
        add_action( 'admin_action_' . PML_PREFIX . '_add_to_allow_list', [ $this, 'handle_add_to_allow_list' ] );
        add_action( 'admin_action_' . PML_PREFIX . '_add_to_deny_list', [ $this, 'handle_add_to_deny_list' ] );
        add_action( 'admin_action_' . PML_PREFIX . '_remove_from_lists', [ $this, 'handle_remove_from_lists' ] );
    }

    /**
     * Adds custom action links to the user row on the Users admin page.
     *
     * @param array   $actions An array of action links for each user.
     * @param WP_User $user    The user object.
     *
     * @return array Modified array of action links.
     */
    public function add_user_row_actions( array $actions, WP_User $user ): array
    {
        if ( !current_user_can( 'manage_options' ) )
        { // Or a more specific capability for managing this plugin.
            return $actions;
        }

        $user_id = $user->ID;
        $nonce   = wp_create_nonce( PML_PREFIX . '_user_list_action_nonce_' . $user_id );

        $allow_list = get_option( $this->allow_list_option, [] );
        $deny_list  = get_option( $this->deny_list_option, [] );

        $base_url = admin_url( 'users.php' ); // Or admin_url( 'admin.php' ) if using admin_post.

        if ( !in_array( $user_id, $allow_list, true ) )
        {
            $actions[ PML_PREFIX . '_add_allow' ] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(
                    add_query_arg(
                        [
                            'action'   => PML_PREFIX . '_add_to_allow_list',
                            'user_id'  => $user_id,
                            '_wpnonce' => $nonce,
                        ],
                        $base_url,
                    ),
                ),
                esc_html__( 'Add to PML Allow List', 'access-lens-protected-media-links' ),
            );
        }

        if ( !in_array( $user_id, $deny_list, true ) )
        {
            $actions[ PML_PREFIX . '_add_deny' ] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(
                    add_query_arg(
                        [
                            'action'   => PML_PREFIX . '_add_to_deny_list',
                            'user_id'  => $user_id,
                            '_wpnonce' => $nonce,
                        ],
                        $base_url,
                    ),
                ),
                esc_html__( 'Add to PML Deny List', 'access-lens-protected-media-links' ),
            );
        }

        if ( in_array( $user_id, $allow_list, true ) || in_array( $user_id, $deny_list, true ) )
        {
            $actions[ PML_PREFIX . '_remove_lists' ] = sprintf(
                '<a href="%s" style="color:#a00;">%s</a>', // Style for emphasis.
                esc_url(
                    add_query_arg(
                        [
                            'action'   => PML_PREFIX . '_remove_from_lists',
                            'user_id'  => $user_id,
                            '_wpnonce' => $nonce,
                        ],
                        $base_url,
                    ),
                ),
                esc_html__( 'Remove from PML Lists', 'access-lens-protected-media-links' ),
            );
        }

        return $actions;
    }

    /**
     * Handles adding a user to the global allow list.
     */
    public function handle_add_to_allow_list()
    {
        $this->handle_list_modification( 'allow', 'add' );
    }

    /**
     * Handles adding a user to the global deny list.
     */
    public function handle_add_to_deny_list()
    {
        $this->handle_list_modification( 'deny', 'add' );
    }

    /**
     * Handles removing a user from all global lists.
     */
    public function handle_remove_from_lists()
    {
        $this->handle_list_modification( 'allow', 'remove' );        // Remove from allow.
        $this->handle_list_modification( 'deny', 'remove', false );  // Remove from deny, don't redirect yet.
        $this->redirect_to_users_page( 'removed_from_lists' );       // Final redirect.
    }

    /**
     * Generic handler for modifying user lists.
     *
     * @param string $list_type 'allow' or 'deny'.
     * @param string $operation 'add' or 'remove'.
     * @param bool   $redirect  Whether to redirect after operation.
     */
    private function handle_list_modification( string $list_type, string $operation, bool $redirect = true )
    {
        $user_id = isset( $_GET[ 'user_id' ] ) ? (int)$_GET[ 'user_id' ] : 0;                                    // Input var okay.
        $nonce   = isset( $_GET[ '_wpnonce' ] ) ? sanitize_text_field( wp_unslash( $_GET[ '_wpnonce' ] ) ) : ''; // Input var okay.

        if ( !$user_id || !wp_verify_nonce( $nonce, PML_PREFIX . '_user_list_action_nonce_' . $user_id ) )
        {
            wp_die( esc_html__( 'Invalid nonce or user ID.', 'access-lens-protected-media-links' ) );
        }
        if ( !current_user_can( 'manage_options' ) )
        {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'access-lens-protected-media-links' ) );
        }

        $option_name = ( 'allow' === $list_type ) ? $this->allow_list_option : $this->deny_list_option;
        $list        = get_option( $option_name, [] );
        $list        = is_array( $list ) ? $list : []; // Ensure it's an array.

        $message_slug = '';

        if ( 'add' === $operation )
        {
            if ( !in_array( $user_id, $list, true ) )
            {
                $list[] = $user_id;
                // If adding to allow, remove from deny, and vice-versa.
                $other_option_name = ( 'allow' === $list_type ) ? $this->deny_list_option : $this->allow_list_option;
                $other_list        = get_option( $other_option_name, [] );
                $other_list        = is_array( $other_list ) ? $other_list : [];
                $key_in_other      = array_search( $user_id, $other_list, true );
                if ( false !== $key_in_other )
                {
                    unset( $other_list[ $key_in_other ] );
                    update_option( $other_option_name, array_values( $other_list ) );
                }
                $message_slug = 'added_to_' . $list_type . '_list';
            }
        }
        elseif ( 'remove' === $operation )
        {
            $key = array_search( $user_id, $list, true );
            if ( false !== $key )
            {
                unset( $list[ $key ] );
                $message_slug = 'removed_from_' . $list_type . '_list';
            }
        }

        update_option( $option_name, array_values( array_unique( $list ) ) );

        if ( $redirect )
        {
            $this->redirect_to_users_page( $message_slug );
        }
    }

    /**
     * Redirects back to the users page with an admin notice.
     *
     * @param string $message_slug Slug for the message to display.
     */
    private function redirect_to_users_page( string $message_slug = '' )
    {
        $redirect_url = admin_url( 'users.php' );
        if ( !empty( $message_slug ) )
        {
            $redirect_url = add_query_arg( PML_PREFIX . '_message', $message_slug, $redirect_url );
        }
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Displays admin notices on the Users page based on query args.
     * This should be hooked in PML_Core or PML_Settings if a global notice system is preferred.
     * For simplicity here, it's self-contained logic that would need a hook.
     */
    public static function display_user_list_admin_notices()
    {
        if ( !isset( $_GET[ PML_PREFIX . '_message' ] ) )
        {
            return;
        }

        $message_slug = sanitize_key( $_GET[ PML_PREFIX . '_message' ] );
        $message      = '';
        $type         = 'success';

        switch ( $message_slug )
        {
            case 'added_to_allow_list':
                $message = esc_html__( 'User added to the PML Global Allow List.', 'access-lens-protected-media-links' );
                break;
            case 'added_to_deny_list':
                $message = esc_html__( 'User added to the PML Global Deny List.', 'access-lens-protected-media-links' );
                break;
            case 'removed_from_allow_list':
                $message = esc_html__( 'User removed from the PML Global Allow List.', 'access-lens-protected-media-links' );
                break;
            case 'removed_from_deny_list':
                $message = esc_html__( 'User removed from the PML Global Deny List.', 'access-lens-protected-media-links' );
                break;
            case 'removed_from_lists':
                $message = esc_html__( 'User removed from all PML Global Lists.', 'access-lens-protected-media-links' );
                break;
            default:
                return; // No valid message slug.
        }

        if ( !empty( $message ) )
        {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr( $type ),
                esc_html( $message ),
            );
        }
    }
}