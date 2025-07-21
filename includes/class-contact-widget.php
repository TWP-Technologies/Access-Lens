<?php
/**
 * Outputs the TWP live chat contact widget on relevant admin pages.
 *
 * @package AccessLens
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class PML_Contact_Widget
{
    private string $widget_src;

    public function __construct()
    {
        $this->widget_src = 'https://cdn.bitrix24.com/b10446447/crm/site_button/loader_16_sg5q0x.js';
        add_action( 'admin_print_footer_scripts', [ $this, 'maybe_output_widget' ] );
    }

    public function maybe_output_widget(): void
    {
        $screen         = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_pml_screen  = $screen && isset( $screen->id ) && strpos( $screen->id, PML_PLUGIN_SLUG ) !== false;

        global $pagenow;
        $is_attachment_edit = ( 'post.php' === $pagenow && isset( $_GET['post'] ) && 'attachment' === get_post_type( absint( $_GET['post'] ) ) );

        if ( !$is_pml_screen && !$is_attachment_edit )
        {
            return;
        }

        $src = esc_url( $this->widget_src );
        ?>
        <script>
            (function(w,d,u){
                var s=d.createElement('script');s.async=true;s.src=u+'?'+(Date.now()/60000|0);
                var h=d.getElementsByTagName('script')[0];h.parentNode.insertBefore(s,h);
            })(window,document,'<?php echo $src; ?>');
        </script>
        <?php
    }
}
