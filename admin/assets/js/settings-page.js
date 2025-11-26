/**
 * Access Lens - Settings Page JavaScript
 *
 * Handles UI enhancements and interactions specific to the PML settings page.
 */
jQuery(document).ready(function ($) {

    // Ensure pml_admin_params is available
    if (typeof pml_admin_params === 'undefined') {
        console.error('PML Settings JS Error: pml_admin_params not defined.');
        return;
    }

    // --- Select2 Initialization for Global Settings ---
    function initializeGlobalUserSelect2(selector, ajaxAction, nonce, placeholder) {
        const $element = $(selector);
        if (!$element.length || typeof $.fn.select2 !== 'function') {
            // console.warn('PML Settings: Select2 function not found or selector "' + selector + '" not present.');
            return;
        }
        $element.select2({
            ajax: {
                url: pml_admin_params.ajax_url,
                dataType: 'json',
                delay: 300,
                data: function (params) {
                    return {
                        action: ajaxAction,
                        _ajax_nonce: nonce,
                        q: params.term,
                        page: params.page || 1,
                    };
                },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    if (data && data.success && data.data && data.data.items !== undefined) {
                        return {
                            results: data.data.items,
                            pagination: { more: (params.page * 10) < data.data.total_count },
                        };
                    } else if (data && data.data && data.data.message) {
                        console.error('PML User Search Error:', data.data.message);
                        return {
                            results: [
                                {
                                    id: '',
                                    text: pml_admin_params.error_searching_users || 'Error searching users.',
                                },
                            ],
                        };
                    }
                    console.error('PML User Search Error: Unexpected response format.', data);
                    return {
                        results: [
                            {
                                id: '',
                                text: pml_admin_params.error_searching_users || 'Error searching users.',
                            },
                        ],
                    };
                },
                cache: true,
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error('PML User Search AJAX Error:', textStatus, errorThrown);
                    $element.empty()
                        .append($(
                            '<option>',
                            {
                                value: '',
                                text: pml_admin_params.error_ajax_failed || 'AJAX request failed.',
                            },
                        ))
                        .trigger('change');
                },
            },
            placeholder: placeholder,
            minimumInputLength: 2,
            width: '100%',
            allowClear: true,
            multiple: true,
            escapeMarkup: function (markup) {
                return markup;
            },
            templateResult: function (data) {
                if (data.loading) {
                    return data.text;
                }
                return '<div class="select2-result-repository clearfix"><div class="select2-result-repository__title">' +
                    (data.text || 'Invalid item') + '</div></div>';
            },
            templateSelection: function (data) {
                return data.text || data.id;
            },
        });
    }

    if ($('#pml_global_user_allow_list_select').length) {
        initializeGlobalUserSelect2(
            '#pml_global_user_allow_list_select',
            'pml_search_users',
            pml_admin_params.search_users_nonce,
            pml_admin_params.user_select_placeholder,
        );
    }
    if ($('#pml_global_user_deny_list_select').length) {
        initializeGlobalUserSelect2(
            '#pml_global_user_deny_list_select',
            'pml_search_users',
            pml_admin_params.search_users_nonce,
            pml_admin_params.user_select_placeholder,
        );
    }
    if (typeof $.fn.select2 === 'function' && $('select.pml-role-select').length) {
        $('select.pml-role-select').each(function () {
            const $select = $(this);
            $select.select2({
                width: '100%',
                placeholder: $select.data('placeholder') ||
                    (wp.i18n && wp.i18n.__ ? wp.i18n.__('Select roles...') : 'Select roles...'),
            });
        });
    }

    // --- Collapsible Tip Functionality ---
    $('.pml-collapsible-tip-trigger').on('click keypress', function (e) {
        if (e.type === 'keypress' && (e.which !== 13 && e.which !== 32)) { // Enter or Space
            return;
        }
        e.preventDefault();

        const $trigger = $(this);
        const $content = $trigger.next('.pml-collapsible-content');
        const $indicator = $trigger.find('.pml-collapsible-indicator');
        const isExpanded = $trigger.attr('aria-expanded') === 'true';

        $content.slideToggle(200);
        $trigger.attr('aria-expanded', !isExpanded);
        $trigger.parent('.pml-collapsible-tip').toggleClass('expanded', !isExpanded);

        $indicator.toggleClass('dashicons-arrow-up-alt2 dashicons-arrow-down-alt2');
    });

    // --- Auto-expand priorities tip if URL parameter is present ---
    const url_params_for_tip = new URLSearchParams(window.location.search);
    if (url_params_for_tip.has('open-priorities-tip')) {
        const $priority_tip_trigger = $('.pml-collapsible-tip-trigger[aria-controls="pml-priority-details"]');
        if ($priority_tip_trigger.length) {
            const $priority_tip_content = $priority_tip_trigger.next('.pml-collapsible-content');
            const $priority_tip_indicator = $priority_tip_trigger.find('.pml-collapsible-indicator');
            const $priority_tip_parent = $priority_tip_trigger.parent('.pml-collapsible-tip');

            // Only expand if not already expanded
            if ($priority_tip_trigger.attr('aria-expanded') !== 'true') {
                $priority_tip_content.show(); // Show immediately, no animation
                $priority_tip_trigger.attr('aria-expanded', 'true');
                $priority_tip_parent.addClass('expanded');
                $priority_tip_indicator.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            }
        }
    }

    // --- Add Default Bot Strings Functionality ---
    $('.pml-add-defaults-button').on('click', function () {
        const $button = $(this);
        const targetTextareaId = $button.data('target-textarea');
        const dataType = $button.data('type'); // 'user_agents' or 'domains'
        const mode = $button.data('mode'); // 'extended', 'paranoid', 'ai', or undefined (for default)
        const $textarea = $('#' + targetTextareaId);

        if (!$textarea.length) {
            console.warn('PML: Target textarea not found for defaults button: #' + targetTextareaId);
            return;
        }

        let defaultsToAdd = [];
        if (dataType === 'user_agents') {
            if (mode === 'extended' && pml_admin_params.extended_bot_user_agents) {
                defaultsToAdd = pml_admin_params.extended_bot_user_agents;
            } else if (mode === 'paranoid' && pml_admin_params.paranoid_bot_user_agents) {
                defaultsToAdd = pml_admin_params.paranoid_bot_user_agents;
            } else if (mode === 'ai' && pml_admin_params.ai_bot_user_agents) {
                defaultsToAdd = pml_admin_params.ai_bot_user_agents;
            } else if (pml_admin_params.default_bot_user_agents) {
                defaultsToAdd = pml_admin_params.default_bot_user_agents;
            }
        } else if (dataType === 'domains') {
            if (mode === 'extended' && pml_admin_params.extended_bot_domains) {
                defaultsToAdd = pml_admin_params.extended_bot_domains;
            } else if (mode === 'paranoid' && pml_admin_params.paranoid_bot_domains) {
                defaultsToAdd = pml_admin_params.paranoid_bot_domains;
            } else if (mode === 'ai' && pml_admin_params.ai_bot_domains) {
                defaultsToAdd = pml_admin_params.ai_bot_domains;
            } else if (pml_admin_params.default_bot_domains) {
                defaultsToAdd = pml_admin_params.default_bot_domains;
            }
        }

        if (defaultsToAdd.length === 0) {
            return;
        }

        const currentContent = $textarea.val();
        const existingLines = currentContent.split('\n').map(function (line) {
            return line.trim().toLowerCase(); // Normalize for comparison
        }).filter(function (line) {
            return line.length > 0; // Remove empty lines
        });

        const newContent = [];
        let addedCount = 0;
        defaultsToAdd.forEach(function (item) {
            if (existingLines.indexOf(item.trim().toLowerCase()) === -1) {
                newContent.push(item.trim()); // Add the original casing, not the lowercased one
                addedCount++;
            }
        });

        if (newContent.length > 0) {
            const contentToAppend = newContent.join('\n');
            if (currentContent.trim().length > 0) { // If textarea already has content
                $textarea.val(currentContent + '\n' + contentToAppend);
            } else { // If textarea is empty
                $textarea.val(contentToAppend);
            }
            $textarea.trigger('input'); // Trigger input event for any listeners

            if (typeof PML_Admin_Utils !== 'undefined' && typeof PML_Admin_Utils.showAdminNotice === 'function') {
                PML_Admin_Utils.showAdminNotice(
                    (wp.i18n && wp.i18n.sprintf && wp.i18n._n ? wp.i18n.sprintf(wp.i18n._n(
                        '%d default entry added.',
                        '%d default entries added.',
                        addedCount,
                        'protected-media-links',
                    ), addedCount) : addedCount + ' default entries added.'),
                    'success',
                    $button.closest('td'), // Show notice within the table cell
                );
            }
        } else {
            if (typeof PML_Admin_Utils !== 'undefined' && typeof PML_Admin_Utils.showAdminNotice === 'function') {
                PML_Admin_Utils.showAdminNotice(
                    (wp.i18n && wp.i18n.__ ? wp.i18n.__('All default entries are already present.', 'protected-media-links') :
                        'All default entries are already present.'),
                    'info',
                    $button.closest('td'),
                );
            }
        }
    });



    // --- Open Select2 Button Functionality ---
    $('.pml-open-select2-button').on('click', function () {
        const targetSelector = $(this).data('target-select');
        const $targetSelect = $(targetSelector);

        if ($targetSelect.length && $targetSelect.data('select2')) {
            $targetSelect.select2('open');
        }
    });

});
