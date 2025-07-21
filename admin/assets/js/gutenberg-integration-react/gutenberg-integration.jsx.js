/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "@wordpress/components":
/*!************************************!*\
  !*** external ["wp","components"] ***!
  \************************************/
/***/ ((module) => {

module.exports = window["wp"]["components"];

/***/ }),

/***/ "@wordpress/element":
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
/***/ ((module) => {

module.exports = window["wp"]["element"];

/***/ }),

/***/ "@wordpress/i18n":
/*!******************************!*\
  !*** external ["wp","i18n"] ***!
  \******************************/
/***/ ((module) => {

module.exports = window["wp"]["i18n"];

/***/ }),

/***/ "react/jsx-runtime":
/*!**********************************!*\
  !*** external "ReactJSXRuntime" ***!
  \**********************************/
/***/ ((module) => {

module.exports = window["ReactJSXRuntime"];

/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!*********************************************************************!*\
  !*** ./build/gutenberg-integration-react/gutenberg-integration.jsx ***!
  \*********************************************************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__);
/**
 * Access Lens - Gutenberg Media Modal Integration
 *
 * Integrates PML Quick Edit settings into the Gutenberg media modal's
 * attachment details sidebar using a React component.
 * Aims for UI and placement consistency with the Media Library Grid view PML section.
 */



 // Import sprintf

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
const PMLAttachmentSettings = ({
  attachment
}) => {
  if (!attachment || !attachment.id) {
    return null;
  }
  const attachment_id = attachment.id;
  const [isLoadingData, setIsLoadingData] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [isProtected, setIsProtected] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [redirectUrl, setRedirectUrl] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const [allowBots, setAllowBots] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const [listCounts, setListCounts] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)({
    user_allow: 0,
    user_deny: 0,
    role_allow: 0,
    role_deny: 0
  });
  const [editLink, setEditLink] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('#');
  const [isOverridesPanelOpen, setIsOverridesPanelOpen] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [isSavingToggle, setIsSavingToggle] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [isSavingOverrides, setIsSavingOverrides] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [errorMessage, setErrorMessage] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const [successMessage, setSuccessMessage] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    setIsLoadingData(true);
    setErrorMessage('');
    setSuccessMessage('');
    setIsOverridesPanelOpen(false);
    $.ajax({
      url: ajax_url,
      type: 'POST',
      data: {
        action: `${plugin_prefix}_get_gutenberg_attachment_pml_data`,
        nonce: get_gutenberg_attachment_pml_data_nonce,
        attachment_id: attachment_id
      },
      dataType: 'json',
      success: response => {
        if (response.success && response.data) {
          setIsProtected(response.data.is_protected || false);
          setRedirectUrl(response.data.redirect_url || '');
          setAllowBots(response.data.allow_bots || '');
          setListCounts(response.data.list_counts || {
            user_allow: 0,
            user_deny: 0,
            role_allow: 0,
            role_deny: 0
          });
          setEditLink(response.data.edit_link || '#');
        } else {
          setErrorMessage(response.data?.message || text_error);
        }
      },
      error: () => setErrorMessage(text_error),
      complete: () => setIsLoadingData(false)
    });
  }, [attachment_id]);
  const handleToggleChange = newIsProtectedValue => {
    setIsSavingToggle(true);
    setErrorMessage('');
    setSuccessMessage('');
    $.ajax({
      url: ajax_url,
      type: 'POST',
      data: {
        action: `${plugin_prefix}_toggle_protection_status`,
        nonce: toggle_protection_nonce,
        attachment_id: attachment_id,
        is_protected: newIsProtectedValue
      },
      dataType: 'json',
      success: response => {
        if (response.success) {
          setIsProtected(response.data.is_protected);
          setSuccessMessage(response.data.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Protection status updated.', 'protected-media-links'));
        } else {
          setErrorMessage(response.data?.message || text_error);
        }
      },
      error: () => {
        setErrorMessage(text_error);
      },
      complete: () => {
        setIsSavingToggle(false);
        setTimeout(() => setSuccessMessage(''), 3000);
      }
    });
  };
  const handleSaveOverrides = () => {
    setIsSavingOverrides(true);
    setErrorMessage('');
    setSuccessMessage('');
    $.ajax({
      url: ajax_url,
      type: 'POST',
      data: {
        action: `${plugin_prefix}_save_quick_edit_form`,
        nonce: save_quick_edit_form_nonce,
        attachment_id: attachment_id,
        [`${plugin_prefix}_redirect_url`]: redirectUrl,
        [`${plugin_prefix}_allow_bots_for_file`]: allowBots
      },
      dataType: 'json',
      success: response => {
        if (response.success) {
          setSuccessMessage(response.data?.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Overrides saved.', 'protected-media-links'));
        } else {
          setErrorMessage(response.data?.message || text_error);
        }
      },
      error: () => setErrorMessage(text_error),
      complete: () => {
        setIsSavingOverrides(false);
        setTimeout(() => setSuccessMessage(''), 3000);
      }
    });
  };
  if (isLoadingData) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("div", {
      className: "pml-gutenberg-loading settings",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Spinner, {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("span", {
        children: text_loading || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Loading PML settings...', 'protected-media-links')
      })]
    });
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.Fragment, {
    children: [errorMessage && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Notice, {
      status: "error",
      isDismissible: true,
      onRemove: () => setErrorMessage(''),
      children: errorMessage
    }), successMessage && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Notice, {
      status: "success",
      isDismissible: false,
      onRemove: () => setSuccessMessage(''),
      children: successMessage
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("div", {
      className: "pml-grid-status-toggle",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("label", {
        className: "name",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Access Lens Status', 'protected-media-links')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("span", {
        className: `pml-status-text-grid ${isProtected ? 'is-protected' : 'is-unprotected'}`,
        children: isProtected ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Protected', 'protected-media-links') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Unprotected', 'protected-media-links')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
        className: `button button-small pml-toggle-protection-grid ${isSavingToggle ? 'is-busy' : ''}`,
        onClick: () => handleToggleChange(!isProtected),
        disabled: isSavingToggle,
        "aria-live": "polite",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Icon, {
          icon: isProtected ? 'unlock' : 'lock',
          className: "pml-button-icon"
        }), isProtected ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Unprotect', 'protected-media-links') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Protect', 'protected-media-links'), isSavingToggle && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Spinner, {})]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("div", {
      className: "pml-grid-trigger-wrapper",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
        isLink: true,
        onClick: () => setIsOverridesPanelOpen(!isOverridesPanelOpen),
        "aria-expanded": isOverridesPanelOpen,
        className: "pml-manage-grid-settings",
        children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Manage PML', 'protected-media-links'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Icon, {
          icon: isOverridesPanelOpen ? 'arrow-up-alt2' : 'arrow-down-alt2'
        })]
      })
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("div", {
      className: `pml-grid-form-container ${isOverridesPanelOpen ? 'is-open' : ''}`,
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("div", {
        className: "pml-gutenberg-form-field",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.TextControl, {
          label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Unauthorized Redirect URL Override', 'protected-media-links'),
          value: redirectUrl,
          onChange: setRedirectUrl,
          placeholder: global_redirect_url_placeholder || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Global default', 'protected-media-links'),
          help: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Leave blank to use global default.', 'protected-media-links'),
          disabled: isSavingOverrides,
          type: "url"
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("div", {
        className: "pml-gutenberg-form-field",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.SelectControl, {
          label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Bot Access Override', 'protected-media-links'),
          value: allowBots,
          options: select_options_bot_access || [],
          onChange: setAllowBots,
          help: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Override global bot access setting for this file.', 'protected-media-links'),
          disabled: isSavingOverrides
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("div", {
        className: "pml-gutenberg-form-actions",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Button, {
          className: "button button-primary button-small",
          onClick: handleSaveOverrides,
          isBusy: isSavingOverrides,
          disabled: isSavingOverrides || isSavingToggle,
          children: isSavingOverrides ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Saving...', 'protected-media-links') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Save Overrides', 'protected-media-links')
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("div", {
        className: "pml-meta-field-group pml-quick-edit-advanced-link",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("hr", {
          className: "pml-meta-hr-dashed"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("p", {
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("a", {
            href: editLink,
            target: "_blank",
            rel: "noopener noreferrer",
            children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Advanced Settings (User/Role Lists, Token Params)...', 'protected-media-links'), "\xA0 ", /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_1__.Icon, {
              icon: "external",
              size: 16
            }), " "]
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("p", {
          className: "description pml-field-description",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.sprintf)(/* translators: 1: User Allow count, 2: User Deny count, 3: Role Allow count, 4: Role Deny count */
          (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_2__.__)('Current lists: User Allow (%1$d), User Deny (%2$d), Role Allow (%3$d), Role Deny (%4$d).', 'protected-media-links'), listCounts.user_allow, listCounts.user_deny, listCounts.role_allow, listCounts.role_deny)
        })]
      })]
    })]
  });
};
function initializePMLGutenbergIntegration() {
  if (typeof wp === 'undefined' || !wp.media?.view?.Attachment?.Details) return;
  if (wp.media.view.Attachment.Details.prototype.pmlExtended) return;
  const OriginalAttachmentDetails = wp.media.view.Attachment.Details;
  const injectPMLSettings = function () {
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
        (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.render)(/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(PMLAttachmentSettings, {
          attachment: this.model.toJSON()
        }), $pmlMountPoint[0]);
      }
    }
  };
  wp.media.view.Attachment.Details = OriginalAttachmentDetails.extend({
    pmlExtended: true,
    render: function () {
      OriginalAttachmentDetails.prototype.render.apply(this, arguments);
      setTimeout(() => injectPMLSettings.call(this), 0);
      return this;
    }
  });
  if (wp.media.view.Attachment.Details.TwoColumn && !wp.media.view.Attachment.Details.TwoColumn.prototype.pmlExtended) {
    const OriginalTwoColumn = wp.media.view.Attachment.Details.TwoColumn;
    wp.media.view.Attachment.Details.TwoColumn = OriginalTwoColumn.extend({
      pmlExtended: true,
      render: function () {
        OriginalTwoColumn.prototype.render.apply(this, arguments);
        setTimeout(() => injectPMLSettings.call(this), 0);
        return this;
      }
    });
  }
}
$(document).ready(function () {
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
})();

/******/ })()
;
//# sourceMappingURL=gutenberg-integration.jsx.js.map