<?php
/**
 * Bot Detection Utility
 *
 * @package ProtectedMediaLinks
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

require_once __DIR__ . '/../pml-headless-helpers.php';

/**
 * PML_Bot_Detector Class.
 * Utility class for detecting verified search engine bots.
 * DNS lookup results are cached using a lightweight headless cache for speed.
 */
class PML_Bot_Detector
{

    /**
     * WordPress database instance.
     *
     * @var wpdb|null
     */
    private ?wpdb $wpdb = null;

    /**
     * Constructor to inject wpdb dependency.
     *
     * @param wpdb $wpdb_instance WordPress database object.
     */
    public function __construct( wpdb $wpdb_instance )
    {
        $this->wpdb = $wpdb_instance;
    }

    /**
     * Checks if the current request is from a verified search engine bot.
     * This method first checks the User-Agent string against a list of known bot signatures.
     * If a match is found, it performs a reverse DNS lookup on the request's IP address
     * and then a forward DNS lookup on the resolved hostname to confirm authenticity.
     * DNS lookup results are cached using WordPress transients.
     *
     * @return bool True if a verified bot is detected, false otherwise.
     */
    public function is_verified_bot(): bool
    {
        $user_agent = isset( $_SERVER[ 'HTTP_USER_AGENT' ] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER[ 'HTTP_USER_AGENT' ] ) ) ) : '';
        $ip_address = isset( $_SERVER[ 'REMOTE_ADDR' ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ 'REMOTE_ADDR' ] ) ) : '';

        if ( empty( $user_agent ) || empty( $ip_address ) || !filter_var( $ip_address, FILTER_VALIDATE_IP ) )
        {
            return false;
        }

        // Get bot signatures from plugin settings (includes defaults if custom is empty).
        $bot_signatures_raw   = pml_headless_get_option(
            PML_PREFIX . '_settings_bot_user_agents',
            implode( "\n", [ 'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot', 'applebot' ] ),
            $this->wpdb
        ); // Default list
        $bot_signatures_array = !empty( $bot_signatures_raw ) ? array_map( 'trim', explode( "\n", $bot_signatures_raw ) ) : [];
        $bot_signatures       = array_unique( array_filter( array_map( 'strtolower', $bot_signatures_array ) ) );

        $is_bot_ua_match = false;
        foreach ( $bot_signatures as $signature )
        {
            if ( !empty( $signature ) && strpos( $user_agent, $signature ) !== false )
            {
                $is_bot_ua_match = true;
                break;
            }
        }

        if ( !$is_bot_ua_match )
        {
            return false; // User-Agent does not match known bot signatures.
        }

        // --- DNS Verification with Caching ---
        $dns_cache_ttl         = (int) pml_headless_get_option( PML_PREFIX . '_settings_bot_dns_cache_ttl', 1 * HOUR_IN_SECONDS, $this->wpdb );
        $rdns_cache_key        = PML_PREFIX . '_rdns_cache_' . md5( $ip_address );
        $fdns_cache_key_prefix = PML_PREFIX . '_fdns_cache_'; // Hostname will be appended.

        // 1. Reverse DNS Lookup (rDNS)
        $hostname = pml_headless_get_cache( $rdns_cache_key, $this->wpdb );
        if ( false === $hostname ) // Not in cache or expired
        {
            $hostname = gethostbyaddr( $ip_address ); // This can be slow.
            if ( false === $hostname || $hostname === $ip_address )
            {
                // Cache negative result to avoid repeated failed lookups for a short period.
                pml_headless_set_cache( $rdns_cache_key, 'invalid_host', $dns_cache_ttl / 4, $this->wpdb );
                return false;
            }
            pml_headless_set_cache( $rdns_cache_key, $hostname, $dns_cache_ttl, $this->wpdb );
        }
        elseif ( 'invalid_host' === $hostname ) // Cached negative result
        {
            return false;
        }

        // Get verified domains from plugin settings (includes defaults if custom is empty).
        $verified_domains_raw   = pml_headless_get_option(
            PML_PREFIX . '_settings_verified_bot_domains',
            implode(
                "\n",
                [ '.googlebot.com', '.google.com', '.search.msn.com', '.crawl.yahoo.net', '.baidu.com', '.yandex.com', '.applebot.apple.com' ]
            ),
            $this->wpdb
        ); // Default list
        $verified_domains_array = !empty( $verified_domains_raw ) ? array_map( 'trim', explode( "\n", $verified_domains_raw ) ) : [];
        $verified_domains       = array_unique( array_filter( $verified_domains_array ) );

        $is_domain_suffix_match = false;
        foreach ( $verified_domains as $domain_suffix )
        {
            if ( !empty( $domain_suffix ) && substr( strtolower( $hostname ), -strlen( $domain_suffix ) ) === strtolower( $domain_suffix ) )
            {
                $is_domain_suffix_match = true;
                break;
            }
        }

        if ( !$is_domain_suffix_match )
        {
            return false; // Hostname does not end with a verified domain suffix.
        }

        // 2. Forward DNS Lookup (fDNS) for Confirmation
        $fdns_cache_key = $fdns_cache_key_prefix . md5( $hostname );
        $forward_ips    = pml_headless_get_cache( $fdns_cache_key, $this->wpdb );

        if ( false === $forward_ips ) // Not in cache or expired
        {
            $forward_ips = gethostbynamel( $hostname ); // Can also be slow.
            if ( !is_array( $forward_ips ) || empty( $forward_ips ) )
            {
                // Cache negative result (empty array for failure).
                pml_headless_set_cache( $fdns_cache_key, [], $dns_cache_ttl / 4, $this->wpdb );
                return false;
            }
            pml_headless_set_cache( $fdns_cache_key, $forward_ips, $dns_cache_ttl, $this->wpdb );
        }
        elseif ( empty( $forward_ips ) && is_array( $forward_ips ) ) // Cached negative result (empty array)
        {
            return false;
        }

        // Check if the original IP address is among the IPs resolved from the hostname.
        if ( in_array( $ip_address, $forward_ips, true ) )
        {
            return true; // Verified bot.
        }

        return false; // fDNS verification failed.
    }
}
