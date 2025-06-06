/**
 * Protected Media Links - Media Library JavaScript
 * Version: 1.2.4
 * Handles UI enhancements and interactions specific to the Media Library views (List & Grid).
 */
jQuery( document ).ready( function( $ )
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

    // --- Common Select2 Initialization for Attachment Edit Page (Full Meta Box) ---
    if ( $( 'body' ).hasClass( 'post-type-attachment' ) && $( 'form#post' ).length )
    {
        if ( typeof $.fn.select2 === 'function' && params.search_users_nonce && params.user_select_placeholder )
        {
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
                        data           : function( sParams )
                        {
                            return {
                                action      : PLUGIN_PREFIX + '_search_users',
                                _ajax_nonce : params.search_users_nonce,
                                q           : sParams.term,
                                page        : sParams.page || 1,
                            };
                        },
                        processResults : function( data, sParams )
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
                    escapeMarkup       : function( markup )
                    {
                        return markup;
                    },
                    templateResult     : function( data )
                    {
                        if ( data.loading )
                        {
                            return data.text;
                        }
                        return '<div class="select2-result-repository clearfix"><div class="select2-result-repository__title">' +
                               ( data.text || 'Invalid item' ) + '</div></div>';
                    },
                    templateSelection  : function( data )
                    {
                        return data.text || data.id;
                    },
                } );
            };
            // Ensure IDs are unique if this script runs on pages with quick edit forms too
            initializeAttachmentUserSelect2( '#' + PLUGIN_PREFIX + '_user_allow_list_select_full' );
            initializeAttachmentUserSelect2( '#' + PLUGIN_PREFIX + '_user_deny_list_select_full' );

            $( 'select.pml-role-select-media-meta' ).select2( {
                width       : '100%',
                placeholder : $( this ).data( 'placeholder' ) ||
                              ( wp.i18n && wp.i18n.__ ? wp.i18n.__( 'Select roles...' ) : 'Select roles...' ),
            } );
        }
    }

    if ( !$( 'body' ).hasClass( 'upload-php' ) && !( typeof wp !== 'undefined' && typeof wp.media !== 'undefined' ) )
    {
        return;
    }

    // === List View: Toggle Protection ===
    $( '#wpbody-content' ).on( 'click', '.pml-toggle-protection', function( e )
    {
        e.preventDefault();
        const $link = $( this );
        if ( $link.hasClass( 'disabled' ) )
        {
            return;
        }

        const $cellContent  = $link.closest( '.pml-status-column-content' );
        const attachmentId  = $cellContent.data( 'attachment-id' );
        const currentAction = $link.data( 'action' );
        const originalHTML  = $link.html();
        $link.html( '<span class="spinner is-active" style="float:none; vertical-align:middle; margin-right: 5px;"></span>' +
                    ( wp.i18n && wp.i18n.__ ? wp.i18n.__( 'Updating...' ) : 'Updating...' ) ).addClass( 'disabled' );

        $.post( params.ajax_url, {
            action        : PLUGIN_PREFIX + '_toggle_protection_status',
            nonce         : params.toggle_nonce,
            attachment_id : attachmentId,
            new_action    : currentAction,
        } )
            .done( function( response )
            {
                if ( response.success )
                {
                    $cellContent.find( '.pml-status-text' )
                        .text( response.data.status_text )
                        .removeClass( 'is-protected is-unprotected' )
                        .addClass( response.data.is_protected ? 'is-protected' : 'is-unprotected' );
                    $link.data( 'action', response.data.toggle_action )
                        .attr( 'title', response.data.toggle_text )
                        .html( '<span class="dashicons ' + response.data.toggle_icon + '"></span> ' + response.data.toggle_text );
                } else
                {
                    if ( typeof PML_Admin_Utils !== 'undefined' &&
                         PML_Admin_Utils.showAdminNotice )
                    {
                        PML_Admin_Utils.showAdminNotice( response.data.message ||
                                                         params.text_error, 'error' );
                    }
                    $link.html( originalHTML );
                }
            } )
            .fail( function()
            {
                if ( typeof PML_Admin_Utils !== 'undefined' && PML_Admin_Utils.showAdminNotice )
                {
                    PML_Admin_Utils.showAdminNotice(
                        params.text_error,
                        'error',
                    );
                }
                $link.html( originalHTML );
            } )
            .always( function()
            {
                $link.removeClass( 'disabled' );
            } );
    } );

    // === List View: Quick Edit Modal ===
    let $pmlQuickEditModal;

    function initPMLQuickEditModal()
    {
        if ( !$pmlQuickEditModal || !$pmlQuickEditModal.length )
        {
            $pmlQuickEditModal = $(
                '<div id="pml-quick-edit-modal" class="pml-modal" role="dialog" aria-modal="true" aria-labelledby="pml-modal-title">' +
                '<div class="pml-modal-overlay" tabindex="-1"></div>' +
                '<div class="pml-modal-content">' +
                '<div class="pml-modal-header">' +
                '<h2 id="pml-modal-title" class="pml-modal-title">' + params.text_quick_edit_pml + '</h2>' +
                '<button type="button" class="pml-modal-close button-link"><span class="dashicons dashicons-no-alt"></span><span class="screen-reader-text">' +
                ( wp.i18n && wp.i18n.__ ? wp.i18n.__( 'Close' ) : 'Close' ) + '</span></button>' +
                '</div>' +
                '<div class="pml-modal-body"></div>' +
                '<div class="pml-modal-footer">' +
                '<button type="button" class="button button-secondary pml-modal-cancel">' +
                ( wp.i18n && wp.i18n.__ ? wp.i18n.__( 'Cancel' ) : 'Cancel' ) + '</button>' +
                '<button type="button" class="button button-primary pml-modal-save">' +
                ( wp.i18n && wp.i18n.__ ? wp.i18n.__( 'Save Changes' ) : 'Save Changes' ) + '</button>' +
                '<span class="spinner"></span>' +
                '</div>' +
                '</div>' +
                '</div>',
            ).appendTo( 'body' );

            // Corrected Close event handlers
            $pmlQuickEditModal.on( 'click', '.pml-modal-close, .pml-modal-cancel', function( e )
            {
                e.preventDefault();
                $pmlQuickEditModal.removeClass( 'is-visible' );
            } );
            $pmlQuickEditModal.on( 'click', '.pml-modal-overlay', function( e )
            {
                e.preventDefault();
                $pmlQuickEditModal.removeClass( 'is-visible' );
            } );
            $( document ).on( 'keydown', function( e )
            {
                if ( e.key === 'Escape' && $pmlQuickEditModal.hasClass( 'is-visible' ) )
                {
                    $pmlQuickEditModal.removeClass( 'is-visible' );
                }
            } );

            $pmlQuickEditModal.on( 'click', '.pml-modal-save', function()
            {
                const $form = $pmlQuickEditModal.find( 'form' );
                if ( !$form.length )
                {
                    return;
                }
                const $saveButton  = $( this );
                const $spinner     = $pmlQuickEditModal.find( '.pml-modal-footer .spinner' );
                const attachmentId = $form.data( 'attachment-id' );

                const rawFormData         = $form.serializeArray();
                const settingsDataPayload = {};
                // The quick edit form fields are named 'pml_settings[field_key]'
                // The PHP AJAX handler expects 'pml_settings' to be an array of these field_keys.
                $.each( rawFormData, function( i, field )
                {
                    const keyMatch = field.name.match( /^pml_settings\[(.*?)\]$/ );
                    if ( keyMatch && keyMatch[ 1 ] )
                    {
                        settingsDataPayload[ keyMatch[ 1 ] ] = field.value;
                    }
                } );
                // Ensure 'is_protected' is present if unchecked (as it's not in quick edit form anymore)
                // This value will be based on the main toggle, not a checkbox in this form.
                // For save, we only send fields that *are* in this form.
                // The `save_quick_edit_data` PHP function should only update fields it receives.

                $spinner.addClass( 'is-active' );
                $saveButton.prop( 'disabled', true );
                $pmlQuickEditModal.find( '.pml-modal-cancel' ).prop( 'disabled', true );

                $.post( params.ajax_url, {
                    action        : PLUGIN_PREFIX + '_save_quick_edit_form',
                    nonce         : params.save_form_nonce,
                    attachment_id : attachmentId,
                    pml_settings  : settingsDataPayload,
                } )
                    .done( function( response )
                    {
                        if ( response.success )
                        {
                            $pmlQuickEditModal.removeClass( 'is-visible' );
                            // Update the list view column for this item based on response
                            const $cellContent = $( '.pml-status-column-content[data-attachment-id="' + attachmentId + '"]' );
                            if ( $cellContent.length )
                            {
                                // The main protection status is updated by its own toggle,
                                // but if save_quick_edit_form returns it, we can sync.
                                // For now, assume only quick_edit specific fields change the UI here if needed.
                                // The toggle button already updates the status text.
                            }
                            if ( typeof PML_Admin_Utils !== 'undefined' &&
                                 PML_Admin_Utils.showAdminNotice )
                            {
                                PML_Admin_Utils.showAdminNotice(
                                    response.data.message || 'Settings saved.',
                                    'success',
                                );
                            }
                        } else if ( typeof PML_Admin_Utils !== 'undefined' &&
                                    PML_Admin_Utils.showAdminNotice )
                        {
                            PML_Admin_Utils.showAdminNotice(
                                response.data.message || params.text_error,
                                'error',
                                $pmlQuickEditModal.find( '.pml-modal-body' ),
                            );
                        }
                    } )
                    .fail( function()
                    {
                        if ( typeof PML_Admin_Utils !== 'undefined' &&
                             PML_Admin_Utils.showAdminNotice )
                        {
                            PML_Admin_Utils.showAdminNotice(
                                params.text_error,
                                'error',
                                $pmlQuickEditModal.find( '.pml-modal-body' ),
                            );
                        }
                    } )
                    .always( function()
                    {
                        $spinner.removeClass( 'is-active' );
                        $pmlQuickEditModal.find( '.pml-modal-save, .pml-modal-cancel' ).prop( 'disabled', false );
                    } );
            } );
        }
    }

    if ( $( 'body' ).hasClass( 'upload-php' ) )
    {
        initPMLQuickEditModal();
    }

    $( '#wpbody-content' ).on( 'click', '.pml-quick-edit-trigger', function( e )
    {
        e.preventDefault();
        if ( !$pmlQuickEditModal || !$pmlQuickEditModal.length )
        {
            initPMLQuickEditModal();
        }
        const attachmentId    = $( this ).data( 'attachment-id' );
        const $modalBody      = $pmlQuickEditModal.find( '.pml-modal-body' );
        const $modalTitle     = $pmlQuickEditModal.find( '.pml-modal-title' );
        const attachmentTitle = $( this ).closest( 'tr' ).find( '.title .row-title' ).text() ||
                                $( this ).closest( '.attachment-preview' ).find( '.title' ).text() || 'Item';
        $modalTitle.text( ( wp.i18n && wp.i18n.sprintf ?
                            wp.i18n.sprintf( wp.i18n.__( 'Quick Edit PML: %s', 'protected-media-links' ), attachmentTitle ) :
                            'Quick Edit PML: ' + attachmentTitle ) );
        $modalBody.html( '<p class="pml-loading-indicator"><span class="spinner is-active"></span> ' + params.text_loading +
                         '</p>' );
        $pmlQuickEditModal.addClass( 'is-visible' );
        $.post(
            params.ajax_url,
            {
                action        : PLUGIN_PREFIX + '_get_quick_edit_form',
                nonce         : params.get_form_nonce,
                attachment_id : attachmentId,
            },
        )
            .done( function( response )
            {
                if ( response.success )
                {
                    $modalBody.html( '<form data-attachment-id="' + attachmentId + '">' +
                                     response.data.form_html + '</form>' );
                } else
                {
                    $modalBody.html( '<p class="pml-error-text">' + ( response.data.message || params.text_error ) + '</p>' );
                }
            } )
            .fail( function()
            {
                $modalBody.html( '<p class="pml-error-text">' + params.text_error + '</p>' );
            } );
    } );

    // === Grid View: Attachment Details Sidebar Integration ===
    if ( typeof wp !== 'undefined' && typeof wp.media !== 'undefined' )
    {
        if ( wp.media.view.Attachment.Details.TwoColumn )
        {
            const originalAttachmentDetailsRender      = wp.media.view.Attachment.Details.TwoColumn.prototype.render;
            wp.media.view.Attachment.Details.TwoColumn = wp.media.view.Attachment.Details.TwoColumn.extend( {
                render                : function()
                {
                    originalAttachmentDetailsRender.apply( this, arguments );
                    const self = this;
                    _.defer( function()
                    {
                        self.addPMLSettingsSection();
                    } );
                    return this;
                },
                addPMLSettingsSection : function()
                {
                    if ( !this.model || !this.model.get( 'id' ) )
                    {
                        return;
                    }
                    const attachmentId = this.model.get( 'id' );
                    const $sidebar     = this.$el.find( '.attachment-info .settings' );

                    if ( $sidebar.length && !$sidebar.find( '.pml-grid-settings-section' ).length )
                    {
                        const $pmlSection = $(
                            `<div class='pml-grid-settings-section'>
                                <div class='pml-grid-status-toggle'>
                                    <label class='name'>${ PLUGIN_NAME } Status</label>
                                    <span class='pml-status-text-grid'></span>
                                    <button type='button' class='button button-small pml-toggle-protection-grid' data-attachment-id='${ attachmentId }'>
                                        <span class='spinner' style='float:none; vertical-align:middle; margin-right:3px; display: inline-flex; flex-direction: column; justify-content: center;'></span>
                                    </button>
                                </div>
                                <div class='pml-grid-trigger-wrapper'>
                                    <button type='button' class='button button-link pml-manage-grid-settings' data-attachment-id='${ attachmentId }'>
                                        ${ params.text_manage_pml }
                                        <span class='dashicons dashicons-arrow-down-alt2'></span>
                                    </button>
                                </div>
                                <div class='pml-grid-form-container' style='display:none;'></div>
                            </div>`,
                        );
                        $sidebar.append( $pmlSection );

                        const updateGridToggleUI = function( isProtected, initialLoad = false )
                        {
                            const $statusTextSpan = $pmlSection.find( '.pml-status-text-grid' );
                            const $toggleButton   = $pmlSection.find( '.pml-toggle-protection-grid' );
                            const statusText      = isProtected ? params.text_protected : params.text_unprotected;
                            const toggleText      = isProtected ? params.text_toggle_unprotect : params.text_toggle_protect;
                            const toggleAction    = isProtected ? 'unprotect' : 'protect';
                            const toggleIcon      = isProtected ? 'dashicons-unlock' : 'dashicons-lock';

                            $statusTextSpan.text( statusText )
                                .removeClass( 'is-protected is-unprotected' )
                                .addClass( isProtected ? 'is-protected' : 'is-unprotected' );
                            $toggleButton.data( 'action', toggleAction )
                                .attr( 'title', toggleText )
                                .html( '<span class="dashicons ' + toggleIcon + '"></span> ' + toggleText );
                            if ( initialLoad )
                            {
                                $toggleButton.find( '.spinner' ).removeClass( 'is-active' );
                            }
                        };

                        $pmlSection.find( '.pml-toggle-protection-grid .spinner' ).addClass( 'is-active' );
                        $.post( params.ajax_url, {
                            action        : PLUGIN_PREFIX + '_get_quick_edit_form',
                            nonce         : params.get_form_nonce,
                            attachment_id : attachmentId,
                        } ).done( function( response )
                        {
                            if ( response.success && response.data.form_html )
                            {
                                const tempForm         = $( '<div>' ).html( response.data.form_html );
                                const isProtectedValue = tempForm.find( 'input[name="pml_settings[' + PLUGIN_PREFIX +
                                                                        '_is_protected]"]' ).is( ':checked' );
                                updateGridToggleUI( isProtectedValue, true );
                                if ( this.model )
                                {
                                    this.model.set( PLUGIN_PREFIX + '_is_protected', isProtectedValue ? '1' : '0' );
                                } // Update Backbone model
                            } else
                            {
                                updateGridToggleUI( false, true ); // Default to unprotected on error
                            }
                        }.bind( this ) ).fail( function()
                        {
                            updateGridToggleUI( false, true ); // Default to unprotected on error
                        } ).always( function()
                        {
                            // Spinner removed by updateGridToggleUI logic implicitly if it clears button HTML
                            // Ensure spinner is explicitly removed if button HTML isn't fully replaced
                            if ( !$pmlSection.find( '.pml-toggle-protection-grid .spinner' ).hasClass( 'is-active' ) )
                            {
                                // If spinner still there but not active, it means button text was set.
                            } else
                            {
                                // Fallback if spinner was only thing
                                $pmlSection.find( '.pml-toggle-protection-grid' ).html( params.text_toggle_protect ); // Default text
                            }
                        } );

                        $pmlSection.on( 'click', '.pml-toggle-protection-grid', function( e )
                        {
                            e.stopPropagation();
                            const $button = $( this );
                            if ( $button.hasClass( 'disabled' ) )
                            {
                                return;
                            }
                            const attachmentId  = $button.data( 'attachment-id' );
                            const currentAction = $button.data( 'action' );
                            const originalHTML  = $button.html();
                            $button.html( '<span class="spinner is-active" style="float:none; vertical-align:middle;"></span>' )
                                .addClass( 'disabled' );

                            $.post( params.ajax_url, {
                                action        : PLUGIN_PREFIX + '_toggle_protection_status',
                                nonce         : params.toggle_nonce,
                                attachment_id : attachmentId,
                                new_action    : currentAction,
                            } )
                                .done( function( response )
                                {
                                    if ( response.success )
                                    {
                                        updateGridToggleUI( response.data.is_protected );
                                        if ( this.model )
                                        {
                                            this.model.set(
                                                PLUGIN_PREFIX + '_is_protected',
                                                response.data.is_protected ? '1' : '0',
                                            );
                                        }
                                    } else
                                    {
                                        if ( typeof PML_Admin_Utils !== 'undefined' &&
                                             PML_Admin_Utils.showAdminNotice )
                                        {
                                            PML_Admin_Utils.showAdminNotice(
                                                response.data.message || params.text_error,
                                                'error',
                                                $pmlSection.find( '.pml-grid-form-container' ),
                                            );
                                        }
                                        $button.html( originalHTML ); // Revert on error
                                    }
                                }.bind( this ) )
                                .fail( function()
                                {
                                    if ( typeof PML_Admin_Utils !== 'undefined' &&
                                         PML_Admin_Utils.showAdminNotice )
                                    {
                                        PML_Admin_Utils.showAdminNotice(
                                            params.text_error,
                                            'error',
                                            $pmlSection.find( '.pml-grid-form-container' ),
                                        );
                                    }
                                    $button.html( originalHTML ); // Revert on error
                                } )
                                .always( function()
                                {
                                    $button.removeClass( 'disabled' );
                                } );
                        } );

                        $pmlSection.on( 'click', '.pml-manage-grid-settings', function( e )
                        {
                            e.stopPropagation();
                            const $button        = $( this );
                            const $formContainer = $pmlSection.find( '.pml-grid-form-container' );
                            const $indicator     = $button.find( '.dashicons' );
                            const isVisible      = $formContainer.is( ':visible' );
                            $button.toggleClass( 'expanded', !isVisible );
                            if ( isVisible )
                            {
                                $formContainer.slideUp( 200 );
                                $indicator.removeClass( 'dashicons-arrow-up-alt2' ).addClass( 'dashicons-arrow-down-alt2' );
                            } else
                            {
                                $formContainer.html( '<p class="pml-loading-indicator"><span class="spinner is-active"></span> ' +
                                                     params.text_loading + '</p>' ).slideDown( 200 );
                                $indicator.removeClass( 'dashicons-arrow-down-alt2' ).addClass( 'dashicons-arrow-up-alt2' );
                                $.post(
                                    params.ajax_url,
                                    {
                                        action        : PLUGIN_PREFIX + '_get_quick_edit_form',
                                        nonce         : params.get_form_nonce,
                                        attachment_id : attachmentId,
                                    },
                                )
                                    .done( function( response )
                                    {
                                        if ( response.success )
                                        {
                                            $formContainer.html( '<form data-attachment-id="' + attachmentId +
                                                                 '">' + response.data.form_html +
                                                                 '<div class="pml-grid-form-actions"><button type="button" class="button button-primary button-small pml-save-grid-settings">' +
                                                                 ( wp.i18n && wp.i18n.__ ? wp.i18n.__(
                                                                     'Save Additional',
                                                                     'protected-media-links',
                                                                 ) : 'Save Additional' ) +
                                                                 '</button><span class="spinner"></span></div></form>' );
                                        } else
                                        {
                                            $formContainer.html( '<p class="pml-error-text">' +
                                                                 ( response.data.message || params.text_error ) + '</p>' );
                                        }
                                    } )
                                    .fail( function()
                                    {
                                        $formContainer.html( '<p class="pml-error-text">' + params.text_error + '</p>' );
                                    } );
                            }
                        } );

                        $pmlSection.on( 'click', '.pml-save-grid-settings', function( e )
                        {
                            e.stopPropagation();
                            const $saveButton         = $( this );
                            const $form               = $saveButton.closest( 'form' );
                            const $spinner            = $saveButton.siblings( '.spinner' );
                            const attachmentId        = $form.data( 'attachment-id' );
                            const rawFormData         = $form.serializeArray();
                            const settingsDataPayload = {};
                            $.each( rawFormData, function( i, field )
                            {
                                const keyMatch = field.name.match( /^pml_settings\[(.*?)\]$/ );
                                // Only include fields that are part of the quick edit form (redirect, bot access)
                                if ( keyMatch && keyMatch[ 1 ] && keyMatch[ 1 ] !== ( PLUGIN_PREFIX + '_is_protected' ) )
                                {
                                    settingsDataPayload[ keyMatch[ 1 ] ] = field.value;
                                }
                            } );
                            $spinner.addClass( 'is-active' );
                            $saveButton.prop( 'disabled', true );
                            $pmlSection.find( '.pml-saved-feedback' ).remove();
                            $.post(
                                params.ajax_url,
                                {
                                    action        : PLUGIN_PREFIX + '_save_quick_edit_form',
                                    nonce         : params.save_form_nonce,
                                    attachment_id : attachmentId,
                                    pml_settings  : settingsDataPayload,
                                },
                            )
                                .done( function( response )
                                {
                                    const feedbackClass = response.success ? 'success' : 'error';
                                    const feedbackMsg   = response.success ? ( wp.i18n && wp.i18n.__ ?
                                                                               wp.i18n.__( 'Saved!', 'protected-media-links' ) :
                                                                               'Saved!' ) :
                                                          ( response.data.message || params.text_error );
                                    $saveButton.after( '<span class="pml-saved-feedback ' + feedbackClass + '">' + feedbackMsg +
                                                       '</span>' );
                                    setTimeout( function()
                                    {
                                        $pmlSection.find( '.pml-saved-feedback' ).fadeOut( function()
                                        {
                                            $( this ).remove();
                                        } );
                                    }, 3000 );
                                } )
                                .fail( function()
                                {
                                    $saveButton.after( '<span class="pml-saved-feedback error">' + params.text_error +
                                                       '</span>' );
                                    setTimeout( function()
                                    {
                                        $pmlSection.find( '.pml-saved-feedback' ).fadeOut( function()
                                        {
                                            $( this ).remove();
                                        } );
                                    }, 3000 );
                                } )
                                .always( function()
                                {
                                    $spinner.removeClass( 'is-active' );
                                    $saveButton.prop( 'disabled', false );
                                } );
                        } );
                    }
                },
            } );
        }
    }
} );
