/**
 * Protected Media Links - Shortcode Generator JavaScript
 *
 * Handles UI interactions for the interactive shortcode generator page.
 */
jQuery(document).ready(function($) {

    // Check if we are on the PML shortcodes page.
    const $shortcodeGeneratorForm = $('#pml-shortcode-generator-form');
    if (!$shortcodeGeneratorForm.length) {
        return;
    }

    // Ensure pml_shortcode_params is available.
    if (typeof pml_shortcode_params === 'undefined') {
        console.error('PML Shortcodes JS Error: pml_shortcode_params not defined.');
        return;
    }

    // --- Initialize Select2 for Media Search ---
    const $mediaSelect = $('#pml-media-id');
    if ($mediaSelect.length && typeof $.fn.select2 === 'function') {
        $mediaSelect.select2({
            ajax: {
                url: pml_shortcode_params.ajax_url,
                dataType: 'json',
                delay: 300,
                data: function(params) {
                    return {
                        action: 'pml_search_media',
                        _ajax_nonce: pml_shortcode_params.search_media_nonce,
                        q: params.term,
                        page: params.page || 1
                    };
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    if (data && data.success && data.data) {
                        return {
                            results: data.data.items,
                            pagination: {
                                more: (params.page * 10) < data.data.total_count
                            }
                        };
                    }
                    return { results: [] };
                },
                cache: true
            },
            placeholder: pml_shortcode_params.media_select_placeholder,
            minimumInputLength: 2,
            width: '100%',
            allowClear: true
        });
    }

    // --- References to form elements ---
    const $outputField = $('#pml-generated-shortcode');
    const $htmlCheckbox = $('#pml-html');
    const $newTabCheckbox = $('#pml-open_in_new_tab');
    const $newTabWrapper = $('#pml-open-in-new-tab-wrapper');

    // --- Function to generate the shortcode string ---
    function generateShortcode() {
        let shortcode = '[pml_token_link';

        const mediaId = $mediaSelect.val();
        if (!mediaId) {
            $outputField.val('Select a media file to generate the shortcode.');
            return;
        }
        shortcode += ` id="${mediaId}"`;

        const linkText = $('#pml-text').val().trim();
        if (linkText) {
            shortcode += ` text="${linkText}"`;
        }

        const duration = $('#pml-duration').val();
        if (duration) {
            shortcode += ` duration="${duration}"`;
        }

        const maxUses = $('#pml-max-uses').val();
        if (maxUses) {
            shortcode += ` max_uses="${maxUses}"`;
        }

        if (!$('#pml-protect').is(':checked')) {
            shortcode += ' protect="false"';
        }

        if (!$htmlCheckbox.is(':checked')) {
            shortcode += ' html="false"';
        }

        if ($htmlCheckbox.is(':checked') && !$newTabCheckbox.is(':checked')) {
            shortcode += ' open_in_new_tab="false"';
        }

        const cssClass = $('#pml-class').val().trim();
        if (cssClass) {
            shortcode += ` class="${cssClass}"`;
        }

        shortcode += ']';
        $outputField.val(shortcode);
    }

    // --- Event Listeners ---

    // Generate shortcode on any form input change.
    $shortcodeGeneratorForm.on('input change', 'input, select', generateShortcode);

    // Also handle changes specifically from Select2.
    $mediaSelect.on('change', generateShortcode);

    // Handle conditional logic for the "Open in new tab" checkbox.
    $htmlCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            $newTabCheckbox.prop('disabled', false);
            $newTabWrapper.removeClass('form-field-checkbox-disabled');
        } else {
            $newTabCheckbox.prop('disabled', true);
            $newTabWrapper.addClass('form-field-checkbox-disabled');
        }
    });

    // --- Copy to Clipboard button ---
    $('#pml-copy-shortcode-button').on('click', function() {
        const $button = $(this);
        const $feedback = $button.find('.pml-copy-feedback');

        $outputField.select();
        document.execCommand('copy');

        $feedback.text(pml_shortcode_params.text_copied).show();
        setTimeout(function() {
            $feedback.fadeOut();
        }, 2500);
    });

    // --- Initial State Setup ---
    generateShortcode();
    $htmlCheckbox.trigger('change'); // Trigger once on load to set initial state.
});
