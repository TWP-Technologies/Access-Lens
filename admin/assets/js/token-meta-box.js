/**
 * Protected Media Links - Token Management Meta Box JavaScript
 * Handles UI interactions for the PML Access Tokens meta box on the attachment edit page.
 */
jQuery( document ).ready( function( $ )
{
    const app_container_id = '#pml-token-manager-app';
    const $app_container   = $( app_container_id );

    if ( !$app_container.length )
    {
        return;
    }

    const attachment_id = $app_container.data( 'attachment-id' );
    const params        = typeof pml_token_meta_box_params !== 'undefined' ? pml_token_meta_box_params : {};
    const pml_prefix    = params.pml_prefix;

    if ( !attachment_id || $.isEmptyObject( params ) || !params.ajax_url )
    {
        console.error( 'PML Token Manager: Essential parameters missing (attachmentId, ajax_url).', params );
        $app_container.find( '#pml-tokens-list-wrapper' )
            .html( `<p class='pml-error-text'>${ params.text_error || 'Initialization error.' }</p>` );
        return;
    }

    let current_page             = 1;
    const tokens_per_page        = 10;
    let helper_text_ajax_request = null;

    function show_admin_notice( message, type, $target_container )
    {
        if ( typeof PML_Admin_Utils !== 'undefined' && PML_Admin_Utils.show_admin_notice )
        {
            PML_Admin_Utils.show_admin_notice( message, type, $target_container.parent() );
        } else
        {
            const $feedback_el = $( '<div class="pml-feedback"></div>' ).addClass( type ).html( message );
            $target_container.html( $feedback_el ).show();
            setTimeout( () => $feedback_el.fadeOut( 500, () => $feedback_el.remove() ), 5000 );
        }
    }

    function format_date_time( utc_date_time_string )
    {
        if ( !utc_date_time_string || utc_date_time_string === '0000-00-00 00:00:00' )
        {
            return params.i18n?.never_expires || 'Never';
        }
        try
        {
            const date = new Date( utc_date_time_string + 'Z' );
            if ( isNaN( date.getTime() ) )
            {
                return utc_date_time_string;
            }
            return new Intl.DateTimeFormat( [], {
                year         : 'numeric',
                month        : 'short',
                day          : 'numeric',
                hour         : 'numeric',
                minute       : '2-digit',
                timeZoneName : 'short',
            } ).format( date );
        } catch ( e )
        {
            return utc_date_time_string;
        }
    }

    function format_relative_time( ms )
    {
        if ( ms < 0 )
        {
            return 'in the past';
        }

        const seconds = Math.round( ms / 1000 );
        const minutes = Math.round( ms / ( 1000 * 60 ) );
        const hours   = Math.round( ms / ( 1000 * 60 * 60 ) );
        const days    = Math.round( ms / ( 1000 * 60 * 60 * 24 ) );
        const months  = Math.round( ms / ( 1000 * 60 * 60 * 24 * 30.44 ) );
        const years   = Math.round( ms / ( 1000 * 60 * 60 * 24 * 365.25 ) );

        if ( seconds < 60 )
        {
            return 'in less than a minute';
        } else if ( minutes < 60 )
        {
            return `in ${ minutes } minute${ minutes > 1 ? 's' : '' }`;
        } else if ( hours < 24 )
        {
            return `in ${ hours } hour${ hours > 1 ? 's' : '' }`;
        } else if ( days < 30 )
        {
            return `in ${ days } day${ days > 1 ? 's' : '' }`;
        } else if ( months < 12 )
        {
            return `in ${ months } month${ months > 1 ? 's' : '' }`;
        } else
        {
            return `in ${ years } year${ years > 1 ? 's' : '' }`;
        }
    }

    function fetch_and_render_tokens( page = 1 )
    {
        current_page        = page;
        const $list_wrapper = $app_container.find( '#pml-tokens-list-wrapper' );
        $list_wrapper.html( `<p class='pml-loading-text'><span class='spinner is-active' style='margin-right:5px;'></span>${ params.text_loading ||
                                                                                                                             'Loading tokens...' }</p>` );

        $.post( params.ajax_url, {
            action        : `${ pml_prefix }_fetch_attachment_tokens`,
            nonce         : params.nonce_fetch_tokens,
            attachment_id : attachment_id,
            page          : current_page,
            per_page      : tokens_per_page,
        } ).done( function( response )
        {
            if ( response.success && response.data )
            {
                params.i18n = { ...params.i18n, ...response.data.i18n };
                render_tokens_grid( response.data.tokens );
                render_pagination( response.data.pagination );
                if ( response.data.tokens.length === 0 && response.data.pagination.total_items === 0 )
                {
                    $list_wrapper.append( `<p>${ params.i18n?.no_tokens_found || 'No tokens found for this file.' }</p>` );
                }
            } else
            {
                const error_msg = response.data?.message || params.text_error;
                $list_wrapper.html( `<p class='pml-error-text'>${ error_msg }</p>` );
            }
        } ).fail( function()
        {
            $list_wrapper.html( `<p class='pml-error-text'>${ params.text_error || 'AJAX request failed.' }</p>` );
        } );
    }

    function render_tokens_grid( tokens )
    {
        const $list_wrapper = $app_container.find( '#pml-tokens-list-wrapper' );
        $list_wrapper.empty();
        if ( !tokens || tokens.length === 0 )
        {
            return;
        }

        let grid_html = `
            <div class='pml-token-grid-header'>
                <div>#</div>
                <div>Token</div>
                <div>Status</div>
                <div>Created By</div>
                <div>Times Used</div>
                <div></div>
            </div>
            <div class='pml-token-grid-body'>
        `;

        tokens.forEach( ( token, index ) =>
        {
            const row_number = ( current_page - 1 ) * tokens_per_page + index + 1;
            const usage_text = token.max_uses > 0 ? `${ token.use_count } / ${ token.max_uses }` :
                               `${ token.use_count } / ${ params.i18n?.unlimited_uses || 'Unlimited' }`;
            const at_limit   = params.effective_max_uses > 0 && token.max_uses >= params.effective_max_uses;
            const edit_title = at_limit
                               ? 'Max uses is at the file\'s limit. Increase the file or global limit to allow more uses.'
                               : 'Click to edit max uses';

            grid_html += `
                <div class='pml-token-entry' data-token-id='${ token.id }' data-token-value='${ token.token_value }' data-use-count='${ token.use_count }' data-max-uses='${ token.max_uses }'>
                    <div class='pml-token-entry-cell cell-number'>${ row_number }</div>
                    
                    <div class='pml-token-cell token-value'>
                        <code class='pml-token-value-cell-text'>${ token.token_value }</code>
                        <button type='button' class='pml-copy-token-link' title='${ params.i18n?.action_copy_link ||
                                                                                    'Copy Token Link' }' data-token-link='${ token.attachment_url_with_token }'>
                            <span class='dashicons dashicons-admin-links'></span>
                            <span class='pml-copied-text'>Copied</span>
                        </button>
                    </div>
                    <div class='pml-token-cell token-status'>
                        <span class='pml-token-status status-${ token.status.toLowerCase() }'>${ params.i18n?.[ `status_${ token.status.toLowerCase() }` ] ||
                                                                                                 token.status }</span>
                    </div>
                    <div class='pml-token-cell token-user'>${ token.user_display_name }</div>
                    <div class='pml-token-cell token-usage'>
                        <div class='editable-max-uses ${ at_limit ? 'at-limit' : '' }' title='${ edit_title }'>
                            <span class='usage-display'>${ usage_text }</span>
                            <div class='usage-edit-form' style='display:none;'>
                                <input type='number' class='max-uses-input' value='${ token.max_uses }' min='${ token.use_count }' max='${ params.effective_max_uses >
                                                                                                                                           0 ?
                                                                                                                                           params.effective_max_uses :
                                                                                                                                           '' }' size='4'>
                                <button type='button' class='button-link-blue save-max-uses'><span class='dashicons dashicons-yes-alt'></span></button>
                                <button type='button' class='button-link-grey cancel-max-uses'><span class='dashicons dashicons-no'></span></button>
                            </div>
                        </div>
                    </div>
                    
                    <div class='pml-token-entry-cell cell-actions'>
                        ${ token.status === 'active' ?
                           `<button type='button' class='button button-secondary pml-revoke-token' data-token-id='${ token.id }'><span class='dashicons dashicons-controls-pause'></span> Revoke</button>` :
                           '' }
                        ${ ( token.status === 'revoked' || token.status === 'expired' ) ?
                           `<button type='button' class='button button-secondary pml-reinstate-token' data-token-id='${ token.id }'><span class='dashicons dashicons-update'></span> Reinstate</button>` :
                           '' }
                        <button type='button' class='button button-link-delete pml-delete-token' data-token-id='${ token.id }'><span class='dashicons dashicons-trash'></span> Delete</button>
                        <span class='spinner'></span>
                    </div>

                    <div class='pml-token-date-info' data-raw-expires='${ token.expires_at_raw }'>
                        <span class='date-item'><strong>Created:</strong> ${ format_date_time( token.created_at_raw ) }</span>
                        <span class='date-item'><strong>Expires:</strong> ${ format_date_time( token.expires_at_raw ) }</span>
                        <span class='date-item'><strong>Last Used:</strong> ${ token.last_used_at_raw ?
                                                                               format_date_time( token.last_used_at_raw ) :
                                                                               ( params.i18n?.not_yet_used || 'Not yet' ) }</span>
                    </div>
                </div>
            `;
        } );

        grid_html += '</div>';
        $list_wrapper.html( grid_html );
    }

    function render_pagination( pagination )
    {
        const $list_wrapper = $app_container.find( '#pml-tokens-list-wrapper' );
        let pagination_html = '<div class="pml-tokens-pagination tablenav-pages">';

        if ( pagination.total_items > 0 )
        {
            pagination_html += `<span class='displaying-num'>${ pagination.total_items } item(s)</span>`;
        }

        if ( pagination.total_pages > 1 )
        {
            pagination_html += '<span class="pagination-links">';
            pagination_html += `<button type='button' class='button first-page' ${ pagination.current_page === 1 ? 'disabled' :
                                                                                   '' } data-page='1'>&laquo;</button>`;
            pagination_html += `<button type='button' class='button prev-page' ${ pagination.current_page === 1 ? 'disabled' :
                                                                                  '' } data-page='${ pagination.current_page -
                                                                                                     1 }'>&lsaquo;</button>`;
            pagination_html +=
                `<span class='paging-input'><span class='tablenav-paging-text'> ${ pagination.current_page } of <span class='total-pages'>${ pagination.total_pages }</span></span></span>`;
            pagination_html +=
                `<button type='button' class='button next-page' ${ pagination.current_page === pagination.total_pages ?
                                                                   'disabled' : '' } data-page='${ pagination.current_page +
                                                                                                   1 }'>&rsaquo;</button>`;
            pagination_html +=
                `<button type='button' class='button last-page' ${ pagination.current_page === pagination.total_pages ?
                                                                   'disabled' :
                                                                   '' } data-page='${ pagination.total_pages }'>&raquo;</button>`;
            pagination_html += '</span>';
        }
        pagination_html += '</div>';

        if ( $list_wrapper.find( '.pml-token-grid-body' ).length )
        {
            $list_wrapper.find( '.pml-token-grid-body' ).after( pagination_html );
        } else if ( pagination.total_items === 0 )
        {
            $list_wrapper.append( pagination_html );
        }
    }

    function initialize_token_generation_form( container_id, options = {} )
    {
        const {
                  on_submit,
                  on_cancel,
                  default_date,
                  include_max_uses = true,
              }          = options;
        const $container = $( '#' + container_id );

        let max_uses_html = '';
        if ( include_max_uses )
        {
            max_uses_html = `
                <div class='pml-form-field-group'>
                    <label for='pml-max-uses-input'>Maximum Uses</label>
                    <input type='number' id='pml-max-uses-input' name='pml_max_uses' class='small-text' value='${ params.effective_max_uses >
                                                                                                                  0 ?
                                                                                                                  params.effective_max_uses :
                                                                                                                  1 }' min='0' 
                           ${ params.effective_max_uses > 0 ? `max="${ params.effective_max_uses }"` : '' }>
                    <p class='pml-field-description'>Enter 0 for unlimited uses. Cannot exceed this file's limit of ${ params.effective_max_uses >
                                                                                                                       0 ?
                                                                                                                       params.effective_max_uses :
                                                                                                                       'unlimited' }.</p>
                </div>
            `;
        }

        let form_html = `
            ${ max_uses_html }
            <div class='pml-form-field-group'>
                <label>Expiry Options:</label>
                <div class='pml-inline-radio-group'>
                    <label><input type='radio' name='pml_expiry_type' value='default' checked> Use Default</label>
                    <label><input type='radio' name='pml_expiry_type' value='predefined'> Pre-defined Duration</label>
                    <label><input type='radio' name='pml_expiry_type' value='custom'> Custom Date/Time</label>
                </div>
            </div>
            <div class='pml-form-field-group pml-expiry-predefined-section' style='display:none;'>
                <label for='pml_predefined_duration'>Select Duration:</label>
                <select id='pml_predefined_duration' name='pml_predefined_duration_seconds'></select>
            </div>
            <div class='pml-form-field-group pml-expiry-custom-section' style='display:none;'>
                <div>
                    <label for='pml_custom_expiry_datetime'>Date & Time:</label>
                    <input type='text' id='pml_custom_expiry_datetime' name='pml_custom_expiry_datetime' class='flatpickr-input'>
                </div>
                <div style='margin-top:10px;'>
                    <label for='pml_custom_expiry_timezone'>Timezone:</label>
                    <select id='pml_custom_expiry_timezone' name='pml_custom_expiry_timezone' style='width:100%;'></select>
                </div>
                <div class='pml-field-description pml-expiry-helper-text' style='margin-top: 8px; line-height: 1.6;'></div>
            </div>
        `;

        if ( on_submit )
        {
            form_html += `<div class='pml-modal-footer'>
                <button type='button' class='button button-secondary pml-modal-cancel'>Cancel</button>
                <button type='button' class='button button-primary pml-modal-save'>Set Expiry & Reinstate</button>
                <span class='spinner'></span>
            </div>`;
        }

        $container.html( form_html );

        if ( params.default_token_expiry_options )
        {
            const $predefined_select = $container.find( '#pml_predefined_duration' );
            for ( const [ seconds, label ] of Object.entries( params.default_token_expiry_options ) )
            {
                $predefined_select.append( `<option value='${ seconds }'>${ label }</option>` );
            }
        }

        const $tz_select = $container.find( '#pml_custom_expiry_timezone' );
        if ( params.timezone_strings )
        {
            $tz_select.html( params.timezone_strings );
        }

        $container.find( '#pml_predefined_duration, #pml_custom_expiry_timezone' ).select2( { width : 'resolve' } );

        const $helper_text = $container.find( '.pml-expiry-helper-text' );

        const update_expiry_helper_text = () =>
        {
            if ( helper_text_ajax_request )
            {
                helper_text_ajax_request.abort();
            }

            if ( !$container.find( 'input[name="pml_expiry_type"][value="custom"]' ).is( ':checked' ) )
            {
                $helper_text.hide();
                return;
            }

            const flatpickr_instance = $container.find( '#pml_custom_expiry_datetime' )[ 0 ]?._flatpickr;
            if ( !flatpickr_instance || flatpickr_instance.selectedDates.length === 0 )
            {
                $helper_text.html( '' ).hide();
                return;
            }

            const selected_date_str = flatpickr_instance.formatDate( flatpickr_instance.selectedDates[ 0 ], 'Y-m-d H:i' );
            const selected_tz       = $container.find( '#pml_custom_expiry_timezone' ).val();
            const local_tz          = Intl.DateTimeFormat().resolvedOptions().timeZone;

            $helper_text.html( '<em>Calculating expiry...</em>' ).show();

            helper_text_ajax_request = $.post( params.ajax_url, {
                action      : `${ pml_prefix }_get_formatted_expiry_time`,
                nonce       : params.nonce_format_expiry,
                date_str    : selected_date_str,
                selected_tz : selected_tz,
                local_tz    : local_tz,
            } ).done( function( response )
            {
                if ( response.success && response.data )
                {
                    let helper_html = `<div><strong>Expires:</strong> ${ response.data.expires_in_selected_tz }</div>`;
                    if ( response.data.expires_in_local_tz )
                    {
                        helper_html += `<div>(Your timezone: ${ response.data.expires_in_local_tz })</div>`;
                    }
                    if ( response.data.relative_time )
                    {
                        helper_html +=
                            `<div style='margin-top: 4px;'><strong>Relative:</strong> ${ response.data.relative_time }</div>`;
                    }
                    $helper_text.html( helper_html );
                } else
                {
                    $helper_text.html( `<span style='color: #dc3232;'>Error: ${ response.data.message ||
                                                                                'Could not calculate expiry.' }</span>` );
                }
            } ).fail( function( xhr )
            {
                if ( xhr.statusText !== 'abort' )
                {
                    $helper_text.html( `<span style='color: #dc3232;'>Could not connect to server.</span>` );
                }
            } );
        };

        const flatpickr_default_date = default_date && new Date( default_date + 'Z' ) > new Date() ?
                                       new Date( default_date + 'Z' ) : new Date().fp_incr( 1 );

        const flatpickr_instance = flatpickr( $container.find( '#pml_custom_expiry_datetime' )[ 0 ], {
            enableTime  : true,
            altInput    : true,
            altFormat   : 'F j, Y h:i K',
            dateFormat  : 'Y-m-d H:i',
            minDate     : 'today',
            defaultDate : flatpickr_default_date,
            onChange    : function()
            {
                update_expiry_helper_text();
            },
        } );

        $container.find( '#pml_custom_expiry_timezone' ).on( 'change', update_expiry_helper_text );

        $container.find( 'input[name="pml_expiry_type"]' ).on( 'change', function()
        {
            const selected_type = $( this ).val();
            $container.find( '.pml-expiry-predefined-section' ).toggle( selected_type === 'predefined' );
            $container.find( '.pml-expiry-custom-section' ).toggle( selected_type === 'custom' );
            update_expiry_helper_text();
        } ).filter( ':checked' ).trigger( 'change' );

        if ( on_submit )
        {
            $container.find( '.pml-modal-save' ).on( 'click', on_submit );
        }
        if ( on_cancel )
        {
            $container.find( '.pml-modal-cancel' ).on( 'click', on_cancel );
        }

        update_expiry_helper_text();
    }

    $app_container.on( 'click', '.pml-tokens-pagination button', function( e )
    {
        e.preventDefault();
        const page = $( this ).data( 'page' );
        if ( page && page !== current_page )
        {
            fetch_and_render_tokens( page );
        }
    } );

    $app_container.on( 'click', '.pml-copy-token-link', function( e )
    {
        e.preventDefault();
        const $button = $( this );
        if ( $button.hasClass( 'pml-copied' ) )
        {
            return;
        }

        const token_link = $( this ).data( 'token-link' );
        navigator.clipboard.writeText( token_link ).then( () =>
        {
            $button.addClass( 'pml-copied' );
            setTimeout( () =>
            {
                $button.removeClass( 'pml-copied' );
            }, 2000 );
        } );
    } );

    $app_container.on( 'click', '#pml-generate-token-button', function()
    {
        const $button             = $( this );
        const $spinner            = $app_container.find( '#pml-generate-token-spinner' );
        const $feedback_container = $app_container.find( '#pml-generate-token-feedback' );
        $feedback_container.empty();
        $button.prop( 'disabled', true );
        $spinner.addClass( 'is-active' );

        const $form_container = $( `#${ pml_prefix }-generate-token-form-fields` );
        const expiry_type     = $form_container.find( 'input[name="pml_expiry_type"]:checked' ).val();

        let request_data = {
            action        : `${ pml_prefix }_generate_token`,
            nonce         : params.nonce_generate_token,
            attachment_id : attachment_id,
            expiry_type   : expiry_type,
            max_uses      : $form_container.find( '#pml-max-uses-input' ).val(),
        };

        if ( expiry_type === 'predefined' )
        {
            request_data.predefined_duration_seconds = $form_container.find( '#pml_predefined_duration' ).val();
        } else if ( expiry_type === 'custom' )
        {
            const flatpickr_instance         = $form_container.find( '#pml_custom_expiry_datetime' )[ 0 ]._flatpickr;
            request_data.custom_datetime_str =
                flatpickr_instance.formatDate( flatpickr_instance.selectedDates[ 0 ], 'Y-m-d H:i' );
            request_data.custom_timezone     = $form_container.find( '#pml_custom_expiry_timezone' ).val();
        }

        $.post( params.ajax_url, request_data )
            .done( function( response )
            {
                if ( response.success )
                {
                    show_admin_notice( response.data.message, 'success', $feedback_container );
                    fetch_and_render_tokens( 1 );
                } else
                {
                    show_admin_notice( response.data.message || params.text_error, 'error', $feedback_container );
                }
            } ).fail( function()
        {
            show_admin_notice( params.text_error, 'error', $feedback_container );
        } ).always( function()
        {
            $button.prop( 'disabled', false );
            $spinner.removeClass( 'is-active' );
        } );
    } );

    function handle_token_action( button, action_name, nonce, confirm_message )
    {
        const $button           = $( button );
        const $row              = $button.closest( '.pml-token-entry' );
        const token_id          = $row.data( 'token-id' );
        const $spinner          = $row.find( '.spinner' );
        const $actions_feedback = $app_container.find( '#pml-token-actions-feedback' );
        $actions_feedback.empty();

        if ( confirm_message && !confirm( confirm_message ) )
        {
            return;
        }

        $button.prop( 'disabled', true ).siblings( 'button' ).prop( 'disabled', true );
        $spinner.addClass( 'is-active' );

        const request_data = {
            action   : action_name,
            nonce    : nonce,
            token_id : token_id,
        };

        $.post( params.ajax_url, request_data ).done( function( response )
        {
            if ( response.success )
            {
                show_admin_notice( response.data.message, 'success', $actions_feedback );
                fetch_and_render_tokens( current_page );
            } else
            {
                show_admin_notice( response.data.message || params.text_error, 'error', $actions_feedback );
                $button.prop( 'disabled', false ).siblings( 'button' ).prop( 'disabled', false );
            }
        } ).fail( function()
        {
            show_admin_notice( params.text_error, 'error', $actions_feedback );
            $button.prop( 'disabled', false ).siblings( 'button' ).prop( 'disabled', false );
        } );
    }

    $app_container.on( 'click', '.pml-revoke-token', function( e )
    {
        e.preventDefault();
        handle_token_action( this, `${ pml_prefix }_revoke_token`, params.nonce_revoke_token );
    } );

    $app_container.on( 'click', '.pml-delete-token', function( e )
    {
        e.preventDefault();
        handle_token_action( this, `${ pml_prefix }_delete_token`, params.nonce_delete_token, params.text_confirm_delete_token );
    } );

    $app_container.on( 'click', '.pml-reinstate-token', function( e )
    {
        e.preventDefault();
        const $button        = $( this );
        const $row           = $button.closest( '.pml-token-entry' );
        const token_id       = $row.data( 'token-id' );
        const expires_at_raw = $row.find( '.pml-token-date-info' ).data( 'raw-expires' );

        const reinstate_action = ( new_expiry_data = {} ) =>
        {
            const $spinner          = $row.find( '.spinner' );
            const $actions_feedback = $app_container.find( '#pml-token-actions-feedback' );
            $actions_feedback.empty();
            $button.prop( 'disabled', true ).siblings( 'button' ).prop( 'disabled', true );
            $spinner.addClass( 'is-active' );

            const request_data = {
                action   : `${ pml_prefix }_reinstate_token`,
                nonce    : params.nonce_reinstate_token,
                token_id : token_id,
                ...new_expiry_data,
            };

            $.post( params.ajax_url, request_data ).done( function( response )
            {
                if ( response.success )
                {
                    show_admin_notice( response.data.message, 'success', $actions_feedback );
                    fetch_and_render_tokens( current_page );
                } else
                {
                    show_admin_notice( response.data.message || params.text_error, 'error', $actions_feedback );
                    $button.prop( 'disabled', false ).siblings( 'button' ).prop( 'disabled', false );
                }
            } ).fail( function()
            {
                show_admin_notice( params.text_error, 'error', $actions_feedback );
                $button.prop( 'disabled', false ).siblings( 'button' ).prop( 'disabled', false );
            } );
        };

        const modal_id = `pml-reinstate-modal-${ token_id }`;
        $( 'body' )
            .append( `<div id='${ modal_id }' class='pml-modal is-visible' role='dialog' aria-modal='true'><div class='pml-modal-overlay'></div><div class='pml-modal-content'><div class='pml-modal-header'><h2 class='pml-modal-title'>Set New Expiry</h2><button type='button' class='pml-modal-close'><span class='dashicons dashicons-no-alt'></span></button></div><div class='pml-modal-body'></div></div></div>` );

        const $modal        = $( `#${ modal_id }` );
        const $modal_body   = $modal.find( '.pml-modal-body' );
        const modal_form_id = `modal-form-container-${ token_id }`;
        $modal_body.attr( 'id', modal_form_id );

        const on_submit = () =>
        {
            const new_expiry_type = $modal_body.find( 'input[name="pml_expiry_type"]:checked' ).val();
            let expiry_data       = {
                new_expiry_type : new_expiry_type,
            };

            if ( new_expiry_type === 'predefined' )
            {
                expiry_data.new_predefined_duration_seconds = $modal_body.find( '#pml_predefined_duration' ).val();
            } else if ( new_expiry_type === 'custom' )
            {
                const flatpickr_instance            = $modal_body.find( '#pml_custom_expiry_datetime' )[ 0 ]._flatpickr;
                expiry_data.new_custom_datetime_str =
                    flatpickr_instance.formatDate( flatpickr_instance.selectedDates[ 0 ], 'Y-m-d H:i' );
                expiry_data.new_custom_timezone     = $modal_body.find( '#pml_custom_expiry_timezone' ).val();
            }
            reinstate_action( expiry_data );
            $modal.remove();
        };
        const on_cancel = () => $modal.remove();
        $modal.on( 'click', '.pml-modal-close, .pml-modal-overlay, .pml-modal-cancel', on_cancel );
        $modal.on( 'click', '.pml-modal-save', on_submit );

        initialize_token_generation_form(
            modal_form_id,
            {
                on_submit,
                on_cancel,
                default_date     : expires_at_raw,
                include_max_uses : false,
            },
        );
    } );

    $app_container.on( 'click', '.editable-max-uses .usage-display', function()
    {
        const $editable_container = $( this ).closest( '.editable-max-uses' );
        if ( $editable_container.hasClass( 'at-limit' ) )
        {
            return;
        }
        const $display = $( this );
        const $form    = $display.siblings( '.usage-edit-form' );
        $display.hide();
        $form.show();
        $form.find( '.max-uses-input' ).focus().select();
    } );

    $app_container.on( 'click', '.cancel-max-uses', function( e )
    {
        e.preventDefault();
        e.stopPropagation();
        const $form    = $( this ).closest( '.usage-edit-form' );
        const $display = $form.siblings( '.usage-display' );
        $form.hide();
        $display.show();
    } );

    $app_container.on( 'click', '.save-max-uses', function( e )
    {
        e.preventDefault();
        e.stopPropagation();
        const $button      = $( this );
        const $form        = $button.closest( '.usage-edit-form' );
        const $input       = $form.find( '.max-uses-input' );
        const new_max_uses = $input.val();

        const $entry   = $form.closest( '.pml-token-entry' );
        const token_id = $entry.data( 'token-id' );

        const $actions_feedback = $app_container.find( '#pml-token-actions-feedback' );
        $actions_feedback.empty();

        $.post( params.ajax_url, {
            action   : `${ pml_prefix }_update_token_max_uses`,
            nonce    : params.nonce_update_max_uses,
            token_id : token_id,
            max_uses : new_max_uses,
        } ).done( function( response )
        {
            if ( response.success )
            {
                show_admin_notice( response.data.message, 'success', $actions_feedback );
                fetch_and_render_tokens( current_page );
            } else
            {
                show_admin_notice( response.data.message || params.text_error, 'error', $actions_feedback );
            }
        } ).fail( function()
        {
            show_admin_notice( params.text_error, 'error', $actions_feedback );
        } );
    } );

    // --- Initial Load ---
    if ( $app_container.find( '#pml-tokens-list-wrapper' ).length )
    {
        fetch_and_render_tokens();
    }
    if ( $app_container.find( `#${ pml_prefix }-generate-token-form-fields` ).length )
    {
        initialize_token_generation_form( `${ pml_prefix }-generate-token-form-fields`, {
            default_date : new Date().fp_incr( 1 )
        } );
    }
} );
