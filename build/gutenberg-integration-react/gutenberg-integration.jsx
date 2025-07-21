/**
 * Access Lens - Gutenberg Media Modal Integration
 * Version: 1.2.5
 *
 * Integrates PML Quick Edit settings into the Gutenberg media modal's
 * attachment details sidebar using a React component.
 * Aims for UI and placement consistency with the Media Library Grid view PML section.
 */

import { render, useEffect, useState } from '@wordpress/element';
import {
    TextControl,
    SelectControl,
    Spinner,
    Button,
    Notice,
    Icon
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n'; // Import sprintf

const $ = window.jQuery;

const {
          ajax_url,
          get_gutenberg_attachment_pml_data_nonce,
          toggle_protection_nonce,
          save_quick_edit_form_nonce,
          plugin_prefix,
          text_error,
          text_loading,
          global_redirect_url_placeholder,
          select_options_bot_access
      } = typeof pml_gutenberg_params !== 'undefined' ? pml_gutenberg_params : {};

const PMLAttachmentSettings = ({ attachment }) => {
    if (!attachment || !attachment.id) {
        return null;
    }

    const attachment_id = attachment.id;

    const [isLoadingData, setIsLoadingData] = useState(true);
    const [isProtected, setIsProtected] = useState(false);
    const [redirectUrl, setRedirectUrl] = useState('');
    const [allowBots, setAllowBots] = useState('');
    const [listCounts, setListCounts] = useState({ user_allow: 0, user_deny: 0, role_allow: 0, role_deny: 0 });
    const [editLink, setEditLink] = useState('#');
    const [isOverridesPanelOpen, setIsOverridesPanelOpen] = useState(false);
    const [isSavingToggle, setIsSavingToggle] = useState(false);
    const [isSavingOverrides, setIsSavingOverrides] = useState(false);
    const [errorMessage, setErrorMessage] = useState('');
    const [successMessage, setSuccessMessage] = useState('');

    useEffect(() => {
        setIsLoadingData(true);
        setErrorMessage('');
        setSuccessMessage('');
        setIsOverridesPanelOpen(false);

        $.ajax({
            url: ajax_url, type: 'POST',
            data: {
                action: `${plugin_prefix}_get_gutenberg_attachment_pml_data`,
                nonce: get_gutenberg_attachment_pml_data_nonce,
                attachment_id: attachment_id,
            },
            dataType: 'json',
            success: (response) => {
                if (response.success && response.data) {
                    setIsProtected(response.data.is_protected || false);
                    setRedirectUrl(response.data.redirect_url || '');
                    setAllowBots(response.data.allow_bots || '');
                    setListCounts(response.data.list_counts || { user_allow: 0, user_deny: 0, role_allow: 0, role_deny: 0 });
                    setEditLink(response.data.edit_link || '#');
                } else { setErrorMessage(response.data?.message || text_error); }
            },
            error: () => setErrorMessage(text_error),
            complete: () => setIsLoadingData(false)
        });
    }, [attachment_id]);

    const handleToggleChange = (newIsProtectedValue) => {
        setIsSavingToggle(true);
        setErrorMessage(''); setSuccessMessage('');
        $.ajax({
            url: ajax_url, type: 'POST',
            data: {
                action: `${plugin_prefix}_toggle_protection_status`,
                nonce: toggle_protection_nonce, attachment_id: attachment_id, is_protected: newIsProtectedValue,
            },
            dataType: 'json',
            success: (response) => {
                if (response.success) {
                    setIsProtected(response.data.is_protected);
                    setSuccessMessage(response.data.message || __('Protection status updated.', 'protected-media-links'));
                } else {
                    setErrorMessage(response.data?.message || text_error);
                }
            },
            error: () => { setErrorMessage(text_error); },
            complete: () => { setIsSavingToggle(false); setTimeout(() => setSuccessMessage(''), 3000); }
        });
    };

    const handleSaveOverrides = () => {
        setIsSavingOverrides(true);
        setErrorMessage(''); setSuccessMessage('');
        $.ajax({
            url: ajax_url, type: 'POST',
            data: {
                action: `${plugin_prefix}_save_quick_edit_form`, nonce: save_quick_edit_form_nonce,
                attachment_id: attachment_id,
                [`${plugin_prefix}_redirect_url`]: redirectUrl,
                [`${plugin_prefix}_allow_bots_for_file`]: allowBots,
            },
            dataType: 'json',
            success: (response) => {
                if (response.success) {
                    setSuccessMessage(response.data?.message || __('Overrides saved.', 'protected-media-links'));
                } else { setErrorMessage(response.data?.message || text_error); }
            },
            error: () => setErrorMessage(text_error),
            complete: () => { setIsSavingOverrides(false); setTimeout(() => setSuccessMessage(''), 3000); }
        });
    };

    if (isLoadingData) {
        return (
            <div className="pml-gutenberg-loading settings">
                <Spinner />
                <span>{text_loading || __('Loading PML settings...', 'protected-media-links')}</span>
            </div>
        );
    }

    return (
        <>
            {errorMessage && <Notice status="error" isDismissible={true} onRemove={() => setErrorMessage('')}>{errorMessage}</Notice>}
            {successMessage && <Notice status="success" isDismissible={false} onRemove={() => setSuccessMessage('')}>{successMessage}</Notice>}

            <div className="pml-grid-status-toggle">
                <label className="name">{__('Access Lens Status', 'protected-media-links')}</label>
                <span className={`pml-status-text-grid ${isProtected ? 'is-protected' : 'is-unprotected'}`}>
                    {isProtected ? __('Protected', 'protected-media-links') : __('Unprotected', 'protected-media-links')}
                </span>
                <Button
                    className={`button button-small pml-toggle-protection-grid ${isSavingToggle ? 'is-busy' : ''}`}
                    onClick={() => handleToggleChange(!isProtected)}
                    disabled={isSavingToggle}
                    aria-live="polite"
                >
                    <Icon icon={isProtected ? 'unlock' : 'lock'} className="pml-button-icon" />
                    {isProtected ? __('Unprotect', 'protected-media-links') : __('Protect', 'protected-media-links')}
                    {isSavingToggle && <Spinner />}
                </Button>
            </div>

            <div className="pml-grid-trigger-wrapper">
                <Button
                    isLink
                    onClick={() => setIsOverridesPanelOpen(!isOverridesPanelOpen)}
                    aria-expanded={isOverridesPanelOpen}
                    className="pml-manage-grid-settings"
                >
                    {__('Manage PML', 'protected-media-links')}
                    <Icon icon={isOverridesPanelOpen ? 'arrow-up-alt2' : 'arrow-down-alt2'} />
                </Button>
            </div>

            <div className={`pml-grid-form-container ${isOverridesPanelOpen ? 'is-open' : ''}`}>
                <div className="pml-gutenberg-form-field">
                    <TextControl
                        label={__('Unauthorized Redirect URL Override', 'protected-media-links')}
                        value={redirectUrl}
                        onChange={setRedirectUrl}
                        placeholder={global_redirect_url_placeholder || __('Global default', 'protected-media-links')}
                        help={__('Leave blank to use global default.', 'protected-media-links')}
                        disabled={isSavingOverrides}
                        type="url"
                    />
                </div>
                <div className="pml-gutenberg-form-field">
                    <SelectControl
                        label={__('Bot Access Override', 'protected-media-links')}
                        value={allowBots}
                        options={select_options_bot_access || []}
                        onChange={setAllowBots}
                        help={__('Override global bot access setting for this file.', 'protected-media-links')}
                        disabled={isSavingOverrides}
                    />
                </div>
                <div className="pml-gutenberg-form-actions">
                    <Button
                        className="button button-primary button-small"
                        onClick={handleSaveOverrides}
                        isBusy={isSavingOverrides}
                        disabled={isSavingOverrides || isSavingToggle}
                    >
                        {isSavingOverrides ? __('Saving...', 'protected-media-links') : __('Save Overrides', 'protected-media-links')}
                    </Button>
                </div>

                {/* Advanced Settings Link Section */}
                <div className="pml-meta-field-group pml-quick-edit-advanced-link">
                    <hr className="pml-meta-hr-dashed" />
                    <p>
                        <a href={editLink} target="_blank" rel="noopener noreferrer">
                            {__('Advanced Settings (User/Role Lists, Token Params)...', 'protected-media-links')}
                            &nbsp; {/* Non-breaking space for better spacing */}
                            <Icon icon="external" size={16} /> {/* Using WP Dashicon via Icon component */}
                        </a>
                    </p>
                    <p className="description pml-field-description">
                        {sprintf(
                            /* translators: 1: User Allow count, 2: User Deny count, 3: Role Allow count, 4: Role Deny count */
                            __('Current lists: User Allow (%1$d), User Deny (%2$d), Role Allow (%3$d), Role Deny (%4$d).', 'protected-media-links'),
                            listCounts.user_allow,
                            listCounts.user_deny,
                            listCounts.role_allow,
                            listCounts.role_deny
                        )}
                    </p>
                </div>
            </div>
        </>
    );
};

function initializePMLGutenbergIntegration() {
    if (typeof wp === 'undefined' || !wp.media?.view?.Attachment?.Details) return;
    if (wp.media.view.Attachment.Details.prototype.pmlExtended) return;

    const OriginalAttachmentDetails = wp.media.view.Attachment.Details;

    const injectPMLSettings = function() {
        if (this.el && this.model && this.model.get('id')) {
            const pmlWrapperClass = 'pml-grid-settings-section';
            const pmlMountPointClass = `pml-react-mount-point-${this.cid}`;
            const $mediaSidebar = $(this.el).closest('.media-modal-content').find('.media-sidebar');
            if (!$mediaSidebar.length) return;

            $mediaSidebar.find(`.${pmlWrapperClass}`).remove();
            const $pmlSectionWrapper = $(`<div class="${pmlWrapperClass}"></div>`);
            $pmlSectionWrapper.append(`<div class="${pmlMountPointClass}"></div>`);
            $mediaSidebar.append($pmlSectionWrapper);

            const $pmlMountPoint = $mediaSidebar.find(`.${pmlMountPointClass}`);
            if ($pmlMountPoint.length) {
                render(<PMLAttachmentSettings attachment={this.model.toJSON()} />, $pmlMountPoint[0]);
            }
        }
    };

    wp.media.view.Attachment.Details = OriginalAttachmentDetails.extend({
        pmlExtended: true,
        render: function() {
            OriginalAttachmentDetails.prototype.render.apply(this, arguments);
            setTimeout(() => injectPMLSettings.call(this), 0);
            return this;
        }
    });

    if (wp.media.view.Attachment.Details.TwoColumn && !wp.media.view.Attachment.Details.TwoColumn.prototype.pmlExtended) {
        const OriginalTwoColumn = wp.media.view.Attachment.Details.TwoColumn;
        wp.media.view.Attachment.Details.TwoColumn = OriginalTwoColumn.extend({
            pmlExtended: true,
            render: function() {
                OriginalTwoColumn.prototype.render.apply(this, arguments);
                setTimeout(() => injectPMLSettings.call(this), 0);
                return this;
            }
        });
    }
}

$(document).ready(function() {
    if (typeof pml_gutenberg_params === 'undefined') return;
    let initAttempts = 0;
    const maxInitAttempts = 20;
    function tryInitializePMLMedia() {
        if (typeof wp !== 'undefined' && wp.media?.view?.Attachment?.Details) {
            initializePMLGutenbergIntegration();
        } else if (initAttempts < maxInitAttempts) {
            initAttempts++;
            setTimeout(tryInitializePMLMedia, 500);
        }
    }
    tryInitializePMLMedia();
});
