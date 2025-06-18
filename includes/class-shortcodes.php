<?php
/**
 * Handles plugin shortcodes.
 *
 * @package AccessLens
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * PML_Shortcodes Class.
 * Registers and processes all shortcodes for the plugin.
 * Implemented as a singleton to prevent multiple token generation on a single page load.
 */
final class PML_Shortcodes
{
    /**
     * The single instance of the class.
     * @var PML_Shortcodes|null
     */
    private static ?PML_Shortcodes $instance = null;

    /**
     * In-memory cache for generated links.
     * @var array
     */
    private static array $generated_links_cache = [];

    /**
     * Gets the single instance of the class.
     *
     * @note We use a singleton pattern to ensure that shortcodes are not processed multiple times on a single page load.
     * @return PML_Shortcodes
     */
    public static function get_instance(): PML_Shortcodes
    {
        if ( null === self::$instance )
        {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * Hooks into WordPress to register shortcodes.
     */
    private function __construct()
    {
        add_shortcode( 'pml_token_link', [ $this, 'handle_token_link_shortcode' ] );
    }

    /**
     * Handles the [pml_token_link] shortcode.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string The generated HTML link or raw URL, or an empty string on error.
     */
    public function handle_token_link_shortcode( $atts ): string
    {
        $atts = shortcode_atts(
            [
                'id'              => 0,
                'text'            => '',
                'duration'        => null,
                'max_uses'        => null,
                'protect'         => 'true',
                'html'            => 'true',
                'open_in_new_tab' => 'true',
                'class'           => '',
            ],
            $atts,
            'pml_token_link'
        );

        $cache_key = md5( serialize( $atts ) );

        if ( isset( $this->generated_links_cache[ $cache_key ] ) )
        {
            return $this->generated_links_cache[ $cache_key ];
        }

        $attachment_id = absint( $atts[ 'id' ] );
        if ( !$attachment_id || 'attachment' !== get_post_type( $attachment_id ) )
        {
            if ( current_user_can( 'manage_options' ) )
            {
                return '<!-- PML Shortcode Error: Invalid or missing attachment ID. -->';
            }
            return '';
        }

        if ( filter_var( $atts[ 'protect' ], FILTER_VALIDATE_BOOLEAN ) )
        {
            if ( !get_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', true ) )
            {
                update_post_meta( $attachment_id, '_' . PML_PREFIX . '_is_protected', '1' );
            }
        }

        $token_args = [];
        if ( isset( $atts[ 'duration' ] ) && is_numeric( $atts[ 'duration' ] ) )
        {
            $token_args[ 'expires_in_seconds' ] = absint( $atts[ 'duration' ] );
        }

        if ( isset( $atts[ 'max_uses' ] ) && is_numeric( $atts[ 'max_uses' ] ) )
        {
            $shortcode_max_uses = absint( $atts[ 'max_uses' ] );
            $token_args[ 'max_uses' ] = $shortcode_max_uses;

            $file_max_uses_override = get_post_meta( $attachment_id, '_' . PML_PREFIX . '_token_max_uses', true );

            if ( '' === $file_max_uses_override || $shortcode_max_uses > (int)$file_max_uses_override )
            {
                update_post_meta( $attachment_id, '_' . PML_PREFIX . '_token_max_uses', $shortcode_max_uses );
            }
        }

        $url = PML_Token_Manager::generate_access_url( $attachment_id, $token_args );

        if ( !$url )
        {
            if ( current_user_can( 'manage_options' ) )
            {
                return '<!-- PML Shortcode Error: Failed to generate access URL. -->';
            }
            return '';
        }

        $as_html = filter_var( $atts[ 'html' ], FILTER_VALIDATE_BOOLEAN );
        if ( !$as_html )
        {
            $output = esc_url( $url );
            $this->generated_links_cache[ $cache_key ] = $output;
            return $output;
        }

        $link_text = !empty( $atts[ 'text' ] ) ? esc_html( $atts[ 'text' ] ) : esc_html( get_the_title( $attachment_id ) );
        $css_class = !empty( $atts[ 'class' ] ) ? ' class="' . esc_attr( $atts[ 'class' ] ) . '"' : '';

        $target_attr = '';
        if ( filter_var( $atts[ 'open_in_new_tab' ], FILTER_VALIDATE_BOOLEAN ) )
        {
            $target_attr = ' target="_blank" rel="noopener"';
        }

        $output = sprintf( '<a href="%s"%s%s>%s</a>', esc_url( $url ), $target_attr, $css_class, $link_text );

        $this->generated_links_cache[ $cache_key ] = $output;

        return $output;
    }

    /**
     * Private clone method to prevent cloning of the instance.
     */
    private function __clone() {}

    /**
     * Private unserialize method to prevent unserializing of the instance.
     */
    public function __wakeup() {}
}
