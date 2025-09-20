<?php
/**
 * Headless authentication utilities.
 *
 * Replicates WordPress auth cookie validation without loading
 * the full user API.
 *
 * @package AccessLens
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
     * This version is modified to check for both secure and non-secure auth cookies,
     * making it robust regardless of the current request's SSL status. It also
     * returns the cookie's scheme ('auth', 'secure_auth', or 'logged_in').
     *
     * @return array|null Parsed cookie elements including scheme, or null on failure.
     */
    private function parse_auth_cookie(): ?array
    {
        $cookie = '';
        $scheme = '';

        $cookie_candidates = [
            'secure_auth' => SECURE_AUTH_COOKIE,
            'auth'        => AUTH_COOKIE,
            'logged_in'   => LOGGED_IN_COOKIE,
        ];

        foreach ( $cookie_candidates as $candidate_scheme => $cookie_name ) {
            if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
                continue;
            }

            $candidate_value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );

            if ( '' === $candidate_value ) {
                continue;
            }

            $cookie = $candidate_value;
            $scheme = $candidate_scheme;
            break;
        }

        if ( '' === $cookie ) {
            // No authentication cookie was found at all.
            return null;
        }

        if ( 3 !== substr_count( $cookie, '|' ) ) {
            return null;
        }

        $cookie_elements = explode( '|', $cookie );
        if ( count( $cookie_elements ) !== 4 ) {
            return null;
        }

        return [
            'username'   => $cookie_elements[0],
            'expiration' => (int) $cookie_elements[1],
            'token'      => $cookie_elements[2],
            'hmac'       => $cookie_elements[3],
            'scheme'     => $scheme,
        ];
    }

    /**
     * Validates the parsed auth cookie elements.
     *
     * Replicates wp_validate_auth_cookie() from WordPress core.
     *
     * @param array $elements Parsed cookie elements, including the 'scheme'.
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

        if ( str_starts_with( $user->user_pass, '$P$' ) || str_starts_with( $user->user_pass, '$2y$' ) ) {
            // Retain previous behaviour of phpass or vanilla bcrypt hashed passwords.
            $pass_frag = substr( $user->user_pass, 8, 4 );
        } else {
            // Otherwise, use a substring from the end of the hash to avoid dealing with potentially long hash prefixes.
            $pass_frag = substr( $user->user_pass, -4 );
        }

        // Generate the key using the username from the cookie and the correct scheme.
        $key       = wp_hash( $elements['username'] . '|' . $pass_frag . '|' . $elements['expiration'] . '|' . $elements['token'], $elements['scheme'] );
        $algo      = 'sha256';
        // Generate the final hash using the username from the cookie.
        $hash      = hash_hmac( $algo, $elements['username'] . '|' . $elements['expiration'] . '|' . $elements['token'], $key );

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

        $verifier = hash( 'sha256', $elements['token'] );
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
