/**
 * Access Lens - Media Library JavaScript
 * Handles UI enhancements and interactions specific to the Media Library views (List & Grid).
 */
jQuery( document ).ready( function( $ ) // Cannot convert document.ready to arrow function if it uses `this` internally, $ is passed as arg
{

    const params = typeof pml_media_params !== 'undefined' ? pml_media_params :
                   ( typeof pml_admin_params !== 'undefined' ? pml_admin_params : {} );

    if ( $.isEmptyObject( params ) || !params.ajax_url )
    {
        console.error( 'PML Media JS Error: Essential parameters not defined or ajax_url missing.' );
        return;
    }

    const PLUGIN_PREFIX = params.plugin_prefix || 'pml';
    const PLUGIN_NAME   = params.plugin_name || 'Protected Media Links';
    // Arrow function for i18n fallback is fine
    const i18n          = wp.i18n || { __: s => s, _n: (s1, s2, n) => (n > 1 ? s2 : s1), sprintf: (fmt, ...args) => fmt.replace(/%([sd%])/g, (m, p) => (p === '%' ? '%' : args.shift())) };


    // Common Select2 Initialization for Attachment Edit Page (Full Meta Box)
    if ( $( 'body' ).hasClass( 'post-type-attachment' ) && $( 'form#post' ).length )
    {
        if ( typeof $.fn.select2 === 'function' && params.search_users_nonce && params.user_select_placeholder )
        {
            // This function itself is not a callback that would benefit from arrow func's `this`
            const initializeAttachmentUserSelect2 = function( selector )
            {
                const $element = $( selector );
                if ( !$element.length )
                {
                    return;
                }
                $element.select2( {
                    ajax               : {
                        url            : params.ajax_url,
                        dataType       : 'json',
                        delay          : 300,
                        // `data` function in Select2 ajax often uses `this` referring to Select2 instance, keep as function
                        data           : function( sParams )
                        {
                            return {
                                action      : `${PLUGIN_PREFIX}_search_users`,
                                _ajax_nonce : params.search_users_nonce,
                                q           : sParams.term,
                                page        : sParams.page || 1,
                            };
                        },
                        // `processResults` can be arrow if `this` isn't Select2 specific
                        processResults : ( data, sParams ) =>
                        {
                            sParams.page = sParams.page || 1;
                            if ( data && data.success && data.data && data.data.items !== undefined )
                            {
                                return {
                                    results    : data.data.items,
                                    pagination : { more : ( sParams.page * 10 ) < data.data.total_count },
                                };
                            }
                            return { results : [] };
                        },
                        cache          : true,
                    },
                    placeholder        : $element.data( 'placeholder' ) || params.user_select_placeholder,
                    minimumInputLength : 2,
                    width              : '100%',
                    allowClear         : true,
                    multiple           : true,
                    escapeMarkup       : markup => markup, // Arrow function is fine
                    templateResult     : data => { // Arrow function is fine
                        if ( data.loading ) return data.text;
                        return `<div class="select2-result-repository clearfix"><div class="select2-result-repository__title">${data.text || 'Invalid item'}</div></div>`;
                    },
                    templateSelection  : data => data.text || data.id, // Arrow function is fine
                } );
            };
            initializeAttachmentUserSelect2( `#${PLUGIN_PREFIX}_user_allow_list_select_full` );
            initializeAttachmentUserSelect2( `#${PLUGIN_PREFIX}_user_deny_list_select_full` );

            // jQuery `each` or `on` often use `this` for element context, keep as function for `$(this)`
            $( 'select.pml-role-select-media-meta' ).select2( {
                width       : '100%',
                placeholder : $( this ).data( 'placeholder' ) || i18n.__( 'Select roles...' ),
            } );
        }
    }

    if ( !$( 'body' ).hasClass( 'upload-php' ) && !( typeof wp !== 'undefined' && typeof wp.media !== 'undefined' ) )
    {
        return;
    }

    // === List View: Toggle Protection ===
    // jQuery event handler, `this` refers to the clicked element. Keep as function.
    $( '#wpbody-content' ).on( 'click', '.pml-toggle-protection', function( e )
    {
        e.preventDefault();
        const $link = $( this );
        if ( $link.hasClass( 'disabled' ) ) return;

        const $cellContent = $link.closest( '.pml-status-column-content' );
        const attachmentId  = $cellContent.data( 'attachment-id' );
        const currentAction = $link.data( 'action' );
        const originalHTML = $link.html();
        $link.html( `<span class="spinner is-active" style="float:none; vertical-align:middle; margin-right: 5px;"></span>${i18n.__( 'Updating...' )}` ).addClass( 'disabled' );

        $.post( params.ajax_url, {
            action        : `${PLUGIN_PREFIX}_toggle_protection_status`,
            nonce         : params.toggle_nonce,
            attachment_id : attachmentId,
            new_action    : currentAction,
        } )
            .done( response => { // Arrow function for AJAX callback
                if ( response.success ) {
                    $cellContent.find( '.pml-status-text' )
                        .text( response.data.status_text )
                        .removeClass( 'is-protected is-unprotected' )
                        .addClass( response.data.is_protected ? 'is-protected' : 'is-unprotected' );
                    $link.data( 'action', response.data.toggle_action )
                        .attr( 'title', response.data.toggle_text )
                        .html( `<span class="dashicons ${response.data.toggle_icon}"></span> ${response.data.toggle_text}` );
                } else {
                    if ( typeof PML_Admin_Utils !== 'undefined' && PML_Admin_Utils.showAdminNotice ) {
                        PML_Admin_Utils.showAdminNotice( response.data.message || params.text_error, 'error' );
                    }
                    $link.html( originalHTML );
                }
            } )
            .fail( () => { // Arrow function for AJAX callback
                if ( typeof PML_Admin_Utils !== 'undefined' && PML_Admin_Utils.showAdminNotice ) {
                    PML_Admin_Utils.showAdminNotice( params.text_error, 'error' );
                }
                $link.html( originalHTML );
            } )
            .always( () => { $link.removeClass( 'disabled' ); } ); // Arrow function for AJAX callback
    } );

    // === List View: Quick Edit Modal ===
    let $pmlQuickEditModal;

    function initPMLQuickEditModal()
    {
        if ( !$pmlQuickEditModal || !$pmlQuickEditModal.length )
        {
            $pmlQuickEditModal = $(`
                <div id="pml-quick-edit-modal" class="pml-modal" role="dialog" aria-modal="true" aria-labelledby="pml-modal-title">
                    <div class="pml-modal-overlay" tabindex="-1"></div>
                    <div class="pml-modal-content">
                        <div class="pml-modal-header">
                            <h2 id="pml-modal-title" class="pml-modal-title">${params.text_quick_edit_pml}</h2>
                            <button type="button" class="pml-modal-close button-link">
                                <span class="dashicons dashicons-no-alt"></span>
                                <span class="screen-reader-text">${i18n.__( 'Close' )}</span>
                            </button>
                        </div>
                        <div class="pml-modal-body"></div>
                        <div class="pml-modal-footer">
                            <button type="button" class="button button-secondary pml-modal-cancel">${i18n.__( 'Cancel' )}</button>
                            <button type="button" class="button button-primary pml-modal-save">${i18n.__( 'Save Changes' )}</button>
                            <span class="spinner"></span>
                        </div>
                    </div>
                </div>
            `).appendTo( 'body' );

            // jQuery event handler, `this` might be used or its good practice to keep for consistency
            $pmlQuickEditModal.on( 'click', '.pml-modal-close, .pml-modal-cancel, .pml-modal-overlay', function( e )
            {
                e.preventDefault();
                $pmlQuickEditModal.removeClass( 'is-visible' );
            } );
            // jQuery event handler for document keydown
            $( document ).on( 'keydown', function( e )
            {
                if ( e.key === 'Escape' && $pmlQuickEditModal.hasClass( 'is-visible' ) )
                {
                    $pmlQuickEditModal.removeClass( 'is-visible' );
                }
            } );

            // jQuery event handler, `this` refers to the save button
            $pmlQuickEditModal.on( 'click', '.pml-modal-save', function()
            {
                const $form = $pmlQuickEditModal.find( 'form' );
                if ( !$form.length ) return;

                const $saveButton = $( this ); // `this` is the clicked button
                const $spinner    = $pmlQuickEditModal.find( '.pml-modal-footer .spinner' );
                const attachmentId = $form.data( 'attachment-id' );
                const rawFormData  = $form.serializeArray();
                const settingsDataPayload = {};

                // $.each callback, `this` refers to the current item in iteration. Keep as function.
                $.each( rawFormData, function( i, field ) {
                    const keyMatch = field.name.match( /^pml_settings\[(.*?)\]$/ );
                    if ( keyMatch && keyMatch[ 1 ] ) {
                        settingsDataPayload[ keyMatch[ 1 ] ] = field.value;
                    }
                } );

                $spinner.addClass( 'is-active' );
                $saveButton.prop( 'disabled', true );
                $pmlQuickEditModal.find( '.pml-modal-cancel' ).prop( 'disabled', true );

                $.post( params.ajax_url, {
                    action        : `${PLUGIN_PREFIX}_save_quick_edit_form`,
                    nonce         : params.save_form_nonce,
                    attachment_id : attachmentId,
                    pml_settings  : settingsDataPayload,
                } )
                    .done( response => { // Arrow function for AJAX callback
                        if ( response.success ) {
                            $pmlQuickEditModal.removeClass( 'is-visible' );
                            if ( typeof PML_Admin_Utils !== 'undefined' && PML_Admin_Utils.showAdminNotice ) {
                                PML_Admin_Utils.showAdminNotice( response.data.message || 'Settings saved.', 'success' );
                            }
                        } else if ( typeof PML_Admin_Utils !== 'undefined' && PML_Admin_Utils.showAdminNotice ) {
                            PML_Admin_Utils.showAdminNotice( response.data.message || params.text_error, 'error', $pmlQuickEditModal.find( '.pml-modal-body' ) );
                        }
                    } )
                    .fail( () => { // Arrow function for AJAX callback
                        if ( typeof PML_Admin_Utils !== 'undefined' && PML_Admin_Utils.showAdminNotice ) {
                            PML_Admin_Utils.showAdminNotice( params.text_error, 'error', $pmlQuickEditModal.find( '.pml-modal-body' ) );
                        }
                    } )
                    .always( () => { // Arrow function for AJAX callback
                        $spinner.removeClass( 'is-active' );
                        $pmlQuickEditModal.find( '.pml-modal-save, .pml-modal-cancel' ).prop( 'disabled', false );
                    } );
            } );
        }
    }

    if ( $( 'body' ).hasClass( 'upload-php' ) ) { initPMLQuickEditModal(); }

    // jQuery event handler, `this` refers to the clicked trigger. Keep as function.
    $( '#wpbody-content' ).on( 'click', '.pml-quick-edit-trigger', function( e )
    {
        e.preventDefault();
        if ( !$pmlQuickEditModal || !$pmlQuickEditModal.length ) {
            initPMLQuickEditModal();
        }
        const attachmentId = $( this ).data( 'attachment-id' );
        const $modalBody    = $pmlQuickEditModal.find( '.pml-modal-body' );
        const $modalTitle   = $pmlQuickEditModal.find( '.pml-modal-title' );
        const attachmentTitle = $( this ).closest( 'tr' ).find( '.title .row-title' ).text() ||
                                $( this ).closest( '.attachment-preview' ).find( '.title' ).text() || 'Item';

        $modalTitle.text( i18n.sprintf( i18n.__( 'Quick Edit PML: %s', 'protected-media-links' ), attachmentTitle ) );
        $modalBody.html( `<p class="pml-loading-indicator"><span class="spinner is-active"></span> ${params.text_loading}</p>` );
        $pmlQuickEditModal.addClass( 'is-visible' );

        $.post( params.ajax_url, {
            action        : `${PLUGIN_PREFIX}_get_quick_edit_form`,
            nonce         : params.get_form_nonce,
            attachment_id : attachmentId,
        })
            .done( response => { // Arrow function for AJAX callback
                if ( response.success && response.data.form_html ) {
                    $modalBody.html( `<form data-attachment-id="${attachmentId}">${response.data.form_html}</form>` );
                } else {
                    $modalBody.html( `<p class="pml-error-text">${response.data.message || params.text_error}</p>` );
                }
            } )
            .fail( () => { // Arrow function for AJAX callback
                $modalBody.html( `<p class="pml-error-text">${params.text_error}</p>` );
            } );
    } );


    // === Grid View: Attachment Details Sidebar Integration ===
    if ( typeof wp !== 'undefined' && typeof wp.media !== 'undefined' )
    {
        if ( wp.media.view.Attachment.Details.TwoColumn )
        {
            if (wp.media.view.Attachment.Details.TwoColumn.prototype.pmlExtendedGrid) {
                return;
            }
            wp.media.view.Attachment.Details.TwoColumn.prototype.pmlExtendedGrid = true;

            const originalAttachmentDetailsRender = wp.media.view.Attachment.Details.TwoColumn.prototype.render;
            // Backbone view methods - `this` is critical. Do NOT convert to arrow.
            wp.media.view.Attachment.Details.TwoColumn = wp.media.view.Attachment.Details.TwoColumn.extend( {
                render                : function() {
                    originalAttachmentDetailsRender.apply( this, arguments );
                    const self = this; // Capture Backbone `this` for use in deferred function
                    // _.defer callback. `this` inside might not be the Backbone view if it was an arrow func.
                    // Using self ensures we refer to the Backbone view.
                    _.defer( function() {
                        self.addPMLSettingsSectionToGrid();
                    } );
                    return this;
                },
                addPMLSettingsSectionToGrid : function() { // Backbone method
                    if ( !this.model || !this.model.get( 'id' ) ) return;

                    const attachmentId = this.model.get( 'id' );
                    const $sidebar = this.$el.find( '.attachment-info .settings' ); // `this.$el` is Backbone specific

                    if ( $sidebar.length && !$sidebar.find( '.pml-grid-settings-section' ).length ) {
                        const $pmlSection = $(`
                            <div class="pml-grid-settings-section">
                                <div class="pml-grid-status-toggle">
                                    <label class="name">${params.plugin_name || 'PML'} Status</label>
                                    <span class="pml-status-text-grid"></span>
                                    <button type="button" class="button button-small pml-toggle-protection-grid" data-attachment-id="${attachmentId}">
                                    </button>
                                </div>
                                <div class="pml-grid-trigger-wrapper">
                                    <button type="button" class="button button-link pml-manage-grid-settings" data-attachment-id="${attachmentId}">
                                        ${params.text_manage_pml || 'Manage PML'} <span class="dashicons dashicons-arrow-down-alt2"></span>
                                    </button>
                                </div>
                                <div class="pml-grid-form-container" style="display:none;"></div>
                            </div>
                        `);
                        $sidebar.append( $pmlSection );

                        // This function is fine as an arrow function as it doesn't use `this` internally
                        // and captures `params` and `$pmlSection` from outer scope.
                        const updateGridToggleUI = ( isProtectedParam, initialLoad = false ) => {
                            const $statusTextSpan = $pmlSection.find( '.pml-status-text-grid' );
                            const $toggleButton = $pmlSection.find( '.pml-toggle-protection-grid' );
                            const statusText = isProtectedParam ? params.text_protected : params.text_unprotected;
                            const toggleText   = isProtectedParam ? params.text_toggle_unprotect : params.text_toggle_protect;
                            const toggleAction = isProtectedParam ? 'unprotect' : 'protect';
                            const toggleIcon = isProtectedParam ? 'dashicons-unlock' : 'dashicons-lock';

                            $statusTextSpan.text( statusText )
                                .removeClass( 'is-protected is-unprotected' )
                                .addClass( isProtectedParam ? 'is-protected' : 'is-unprotected' );

                            $toggleButton.data( 'action', toggleAction )
                                .attr( 'title', toggleText )
                                .html( `<span class="dashicons ${toggleIcon}"></span> ${toggleText}` );
                        };

                        const $toggleButtonForInit = $pmlSection.find( '.pml-toggle-protection-grid' );
                        $toggleButtonForInit.html(`<span class="spinner is-active" style="float:none; vertical-align:middle; margin-right:3px;"></span>${i18n.__( 'Loading...' )}`);

                        // Storing `this` (Backbone view instance) for use in AJAX callbacks
                        const viewInstance = this;

                        $.post( params.ajax_url, {
                            action        : `${PLUGIN_PREFIX}_get_quick_edit_form`,
                            nonce         : params.get_form_nonce,
                            attachment_id : attachmentId,
                        } ).done( function( response ) { // Keep as function for `this` if used, or use viewInstance
                            if ( response.success && typeof response.data.is_protected !== 'undefined' ) {
                                updateGridToggleUI( response.data.is_protected, true );
                                if ( viewInstance.model ) { // Use captured viewInstance
                                    viewInstance.model.set( `${PLUGIN_PREFIX}_is_protected`, response.data.is_protected ? '1' : '0' );
                                }
                            } else {
                                updateGridToggleUI( false, true );
                                console.error("PML Grid: Error fetching initial status or is_protected missing.", response);
                            }
                        } ).fail( () => { // Arrow function ok
                            updateGridToggleUI( false, true );
                            console.error("PML Grid: AJAX failed to fetch initial status.");
                        } ).always( () => { // Arrow function ok
                            if ($toggleButtonForInit.find('.spinner.is-active').length) {
                                updateGridToggleUI(false, true); // Call with a defined state
                            }
                        } );

                        // jQuery event handlers, `this` refers to the element. Keep as functions.
                        $pmlSection.on( 'click', '.pml-toggle-protection-grid', function( e ) {
                            e.stopPropagation();
                            const $button = $( this );
                            if ( $button.hasClass( 'disabled' ) || $button.find('.spinner.is-active').length ) return;

                            const attachmentIdFromButton  = $button.data( 'attachment-id' );
                            const currentActionFromButton = $button.data( 'action' );
                            const originalHTMLButton = $button.html();

                            $button.html( `<span class="spinner is-active" style="float:none; vertical-align:middle; margin-right:3px;"></span>${i18n.__( 'Updating...' )}` ).addClass( 'disabled' );

                            $.post( params.ajax_url, {
                                action        : `${PLUGIN_PREFIX}_toggle_protection_status`,
                                nonce         : params.toggle_nonce,
                                attachment_id : attachmentIdFromButton,
                                new_action    : currentActionFromButton,
                            } )
                                .done( function( response ) { // Keep as function for viewInstance or explicit this binding.
                                    if ( response.success ) {
                                        updateGridToggleUI( response.data.is_protected );
                                        // Try to get the view instance from data attribute if not using .bind(this) on done.
                                        const currentViewInstance = $button.closest('.attachment-details').data('backboneView') || viewInstance;
                                        if (currentViewInstance && currentViewInstance.model) {
                                            currentViewInstance.model.set( `${PLUGIN_PREFIX}_is_protected`, response.data.is_protected ? '1' : '0' );
                                        }
                                    } else {
                                        $button.html( originalHTMLButton );
                                        if ( typeof PML_Admin_Utils !== 'undefined' && PML_Admin_Utils.showAdminNotice ) {
                                            PML_Admin_Utils.showAdminNotice( response.data.message || params.text_error, 'error', $pmlSection.find( '.pml-grid-form-container' ) );
                                        }
                                    }
                                } )
                                .fail( () => { // Arrow function ok
                                    $button.html( originalHTMLButton );
                                    if ( typeof PML_Admin_Utils !== 'undefined' && PML_Admin_Utils.showAdminNotice ) {
                                        PML_Admin_Utils.showAdminNotice( params.text_error, 'error', $pmlSection.find( '.pml-grid-form-container' ) );
                                    }
                                } )
                                .always( () => { // Arrow function ok
                                    $button.removeClass( 'disabled' );
                                    if ($button.find('.spinner.is-active').length) {
                                        const isProtectedNow = $pmlSection.find('.pml-status-text-grid').hasClass('is-protected');
                                        updateGridToggleUI(isProtectedNow);
                                    }
                                } );
                        } );

                        $pmlSection.on( 'click', '.pml-manage-grid-settings', function( e ) {
                            e.stopPropagation();
                            const $button        = $( this );
                            const $formContainer = $pmlSection.find( '.pml-grid-form-container' );
                            const $indicator     = $button.find( '.dashicons' );
                            const isVisible      = $formContainer.is( ':visible' );

                            $button.toggleClass( 'expanded', !isVisible );
                            $indicator.toggleClass( 'dashicons-arrow-down-alt2 dashicons-arrow-up-alt2' );

                            if ( isVisible ) {
                                $formContainer.slideUp( 200 );
                            } else {
                                $formContainer.html( `<p class="pml-loading-indicator"><span class="spinner is-active"></span> ${params.text_loading}</p>` ).slideDown( 200 );
                                $.post( params.ajax_url, {
                                    action        : `${PLUGIN_PREFIX}_get_quick_edit_form`,
                                    nonce         : params.get_form_nonce,
                                    attachment_id : attachmentId,
                                })
                                    .done( response => { // Arrow function ok
                                        if ( response.success && response.data.form_html ) {
                                            $formContainer.html( `
                                            <form data-attachment-id="${attachmentId}">
                                                ${response.data.form_html}
                                                <div class="pml-grid-form-actions">
                                                    <button type="button" class="button button-primary button-small pml-save-grid-settings">
                                                        ${i18n.__( 'Save Additional', 'protected-media-links' )}
                                                    </button>
                                                    <span class="spinner"></span>
                                                </div>
                                            </form>
                                        `);
                                        } else {
                                            $formContainer.html( `<p class="pml-error-text">${response.data.message || params.text_error}</p>` );
                                        }
                                    } )
                                    .fail( () => { // Arrow function ok
                                        $formContainer.html( `<p class="pml-error-text">${params.text_error}</p>` );
                                    } );
                            }
                        } );

                        $pmlSection.on( 'click', '.pml-save-grid-settings', function( e ) {
                            e.stopPropagation();
                            const $saveButton = $( this );
                            const $form       = $saveButton.closest( 'form' );
                            const $spinner    = $saveButton.siblings( '.spinner' );
                            const attachmentIdForm = $form.data( 'attachment-id' );
                            const rawFormData = $form.serializeArray();
                            const settingsDataPayload = {};

                            // $.each callback, `this` refers to current item. Keep as function.
                            $.each( rawFormData, function( i, field ) {
                                const keyMatch = field.name.match( /^pml_settings\[(.*?)\]$/ );
                                if ( keyMatch && keyMatch[ 1 ] && keyMatch[ 1 ] !== ( `${PLUGIN_PREFIX}_is_protected` ) ) {
                                    settingsDataPayload[ keyMatch[ 1 ] ] = field.value;
                                }
                            } );

                            $spinner.addClass( 'is-active' );
                            $saveButton.prop( 'disabled', true );
                            $pmlSection.find( '.pml-saved-feedback' ).remove();

                            $.post( params.ajax_url, {
                                action        : `${PLUGIN_PREFIX}_save_quick_edit_form`,
                                nonce         : params.save_form_nonce,
                                attachment_id : attachmentIdForm,
                                pml_settings  : settingsDataPayload,
                            })
                                .done( response => { // Arrow function ok
                                    const feedbackClass = response.success ? 'success' : 'error';
                                    const feedbackMsg = response.success ? i18n.__( 'Saved!', 'protected-media-links' ) : ( response.data.message || params.text_error );
                                    $saveButton.after( `<span class="pml-saved-feedback ${feedbackClass}">${feedbackMsg}</span>` );
                                    setTimeout( () => { $pmlSection.find( '.pml-saved-feedback' ).fadeOut( function() { $( this ).remove(); } ); }, 3000 );
                                } )
                                .fail( () => { // Arrow function ok
                                    $saveButton.after( `<span class="pml-saved-feedback error">${params.text_error}</span>` );
                                    setTimeout( () => { $pmlSection.find( '.pml-saved-feedback' ).fadeOut( function() { $( this ).remove(); } ); }, 3000 );
                                } )
                                .always( () => { // Arrow function ok
                                    $spinner.removeClass( 'is-active' );
                                    $saveButton.prop( 'disabled', false );
                                } );
                        } );
                    }
                }
            } );
        }
    }
} );
