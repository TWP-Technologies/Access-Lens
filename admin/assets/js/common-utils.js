/**
 * Protected Media Links - Admin Common Utilities JavaScript
 *
 * Contains utility functions shared across different admin screens.
 */
const PML_Admin_Utils = ( function( $ )
{

    /**
     * Displays a temporary admin notice.
     *
     * @param {string} message The message to display.
     * @param {string} type    Type of notice ('success', 'error', 'warning', 'info'). Defaults to 'error'.
     * @param {jQuery|null} $container Optional jQuery object to prepend the notice to.
     * If null, tries to use standard WordPress notice areas.
     */
    function showPMLAdminNotice( message, type = 'error', $container = null )
    {
        const noticeClass = 'notice-' + type;
        const $notice     = $(
            '<div class="notice ' + noticeClass + ' is-dismissible pml-admin-ajax-notice">' +
            '<p>' + message + '</p>' +
            '<button type="button" class="notice-dismiss">' +
            '<span class="screen-reader-text">' +
            ( wp.i18n && wp.i18n.__ ? wp.i18n.__( 'Dismiss this notice.' ) : 'Dismiss this notice.' ) + '</span>' +
            '</button>' +
            '</div>',
        );

        if ( $container && $container.length )
        {
            // Clear previous notices in the same container to avoid stacking
            $container.find( '.pml-admin-ajax-notice' ).remove();
            $container.prepend( $notice );
        } else if ( $( '.wp-header-end' ).length )
        { // General admin notice area after header
            $( '.wp-header-end' ).after( $notice );
        } else if ( $( 'h1.wp-heading-inline' ).length )
        { // Fallback: after main page title
            $( 'h1.wp-heading-inline' ).first().after( $notice );
        } else
        {
            // Absolute fallback, should not be reached in WP admin
            // For critical errors where UI might be broken, an alert might be a last resort,
            // but generally, we want to avoid alerts.
            console.warn( 'PML Notice: Could not find a suitable container for the notice. Message: ' + message );
            return; // Don't use alert()
        }

        // Handle dismiss button
        $notice.find( '.notice-dismiss' ).on( 'click', function( e )
        {
            e.preventDefault();
            $( this ).parent().slideUp( 200, function()
            {
                $( this ).remove();
            } );
        } );

        // Auto-dismiss after a delay
        setTimeout( function()
        {
            $notice.slideUp( 300, function()
            {
                $( this ).remove();
            } );
        }, 7000 ); // 7 seconds
    }

    // Expose public methods
    return {
        showAdminNotice : showPMLAdminNotice,
    };

} )( jQuery );
