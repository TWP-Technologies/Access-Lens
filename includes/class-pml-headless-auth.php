<?php
/**
 * Headless authentication utilities.
 *
 * Replicates WordPress auth cookie validation without loading
 * the full user API.
 *
 * @package ProtectedMediaLinks
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PML_Headless_Auth
{
    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private wpdb $wpdb;

    /**
     * Cached current user object for the duration of the request.
     *
     * @var object|false|null
     */
    private static $current_user = null;

    /**
     * Constructor.
     *
     * @param wpdb $wpdb_instance WordPress database object.
     */
    public function __construct( wpdb $wpdb_instance )
    {
        $this->wpdb = $wpdb_instance;
    }

    /**
     * Retrieves the current user data based on the auth cookie.
     *
     * @return object|null Object with id and roles or null if not logged in.
     */
    public function get_current_user(): ?object
    {
        if ( null !== self::$current_user ) {
            return self::$current_user ?: null;
        }

        $cookie_elements = $this->parse_auth_cookie();
        if ( ! $cookie_elements ) {
            self::$current_user = false;
            return null;
        }

        if ( ! $this->validate_auth_cookie( $cookie_elements ) ) {
            self::$current_user = false;
            return null;
        }

        self::$current_user = $this->fetch_user_data_by_login( $cookie_elements['username'] );
        return self::$current_user;
    }

    /**
     * Parses the logged-in authentication cookie.
     *
     * @return array|null Parsed cookie elements or null on failure.
     */
    private function parse_auth_cookie(): ?array
    {
        if ( empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
            return null;
        }

        $cookie          = $_COOKIE[ LOGGED_IN_COOKIE ];
        $cookie_elements = explode( '|', $cookie );
        if ( count( $cookie_elements ) !== 4 ) {
            return null;
        }

        return [
            'username'   => $cookie_elements[0],
            'expiration' => (int) $cookie_elements[1],
            'token'      => $cookie_elements[2],
            'hmac'       => $cookie_elements[3],
        ];
    }

    /**
     * Validates the parsed auth cookie elements.
     *
     * Replicates wp_validate_auth_cookie() from WordPress core.
     *
     * @param array $elements Parsed cookie elements.
     *
     * @return bool True if valid, false otherwise.
     */
    private function validate_auth_cookie( array $elements ): bool
    {
        if ( $elements['expiration'] < time() ) {
            return false;
        }

        $user = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT ID, user_login, user_pass FROM {$this->wpdb->users} WHERE user_login = %s",
                $elements['username']
            )
        );

        if ( ! $user ) {
            return false;
        }

        $pass_frag = substr( $user->user_pass, 8, 4 );
        $key       = wp_hash( $user->user_login . '|' . $pass_frag . '|' . $elements['expiration'] . '|' . $elements['token'], 'auth' );
        $algo      = 'sha256';
        $hash      = hash_hmac( $algo, $user->user_login . '|' . $elements['expiration'] . '|' . $elements['token'], $key );

        if ( ! hash_equals( $hash, $elements['hmac'] ) ) {
            return false;
        }

        // After successfully validating the HMAC, validate the session token
        // against the database. This replicates WP_Session_Tokens::verify()
        // without loading the user API.

        $session_tokens_meta = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT meta_value FROM {$this->wpdb->usermeta} WHERE user_id = %d AND meta_key = 'session_tokens'",
                $user->ID
            )
        );

        if ( empty( $session_tokens_meta ) ) {
            return false;
        }

        $sessions = maybe_unserialize( $session_tokens_meta );
        if ( ! is_array( $sessions ) || empty( $sessions ) ) {
            return false;
        }

        $verifier = wp_hash( $elements['token'], 'nonce' );
        if ( ! isset( $sessions[ $verifier ] ) ) {
            return false;
        }

        // Ensure the individual session token itself has not expired.
        if ( empty( $sessions[ $verifier ]['expiration'] ) || $sessions[ $verifier ]['expiration'] < time() ) {
            return false;
        }

        return true;
    }

    /**
     * Fetches basic user data and roles by login name.
     *
     * @param string $username User login.
     *
     * @return object|null Object with id and roles or null if not found.
     */
    private function fetch_user_data_by_login( string $username ): ?object
    {
        $user_id = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT ID FROM {$this->wpdb->users} WHERE user_login = %s",
                $username
            )
        );

        if ( ! $user_id ) {
            return null;
        }

        $capabilities = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT meta_value FROM {$this->wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
                $user_id,
                $this->wpdb->prefix . 'capabilities'
            )
        );

        $roles = [];
        if ( $capabilities ) {
            $caps = maybe_unserialize( $capabilities );
            if ( is_array( $caps ) ) {
                $roles = array_keys( array_filter( $caps ) );
            }
        }

        return (object) [
            'id'    => $user_id,
            'roles' => $roles,
        ];
    }
}

