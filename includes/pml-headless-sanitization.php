<?php
// Common sanitization helpers for Protected Media Links.

if ( ! function_exists( 'pml_headless_sanitize_location' ) ) {
    /**
     * Sanitize redirect URLs for header usage.
     *
     * @param string $url URL to sanitize.
     * @return string Sanitized URL without CR or LF characters.
     */
    function pml_headless_sanitize_location( string $url ): string {
        $url = filter_var( $url, FILTER_SANITIZE_URL );
        return str_replace( [ "\r", "\n" ], '', $url );
    }
}

