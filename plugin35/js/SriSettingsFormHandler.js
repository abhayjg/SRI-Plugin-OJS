/**
 * @file plugins/pubIds/sri/js/SriSettingsFormHandler.js
 *
 * Copyright (c) 2026 Scitekhub
 * Distributed under the GNU GPL v3. See docs/COPYING or <https://www.gnu.org/licenses/>.
 *
 * Handles the suffix-pattern field's conditional required state.
 * Extends $.pkp.controllers.form.AjaxFormHandler to submit the settings
 * form via AJAX.
 */
(function($) {
	'use strict';

	/**
	 * @constructor
	 * @extends {$.pkp.controllers.form.AjaxFormHandler}
	 *
	 * @param {jQueryObject} $form the wrapped HTML form element.
	 * @param {Object} options form options.
	 */
	$.pkp.plugins.pubIds = $.pkp.plugins.pubIds || {};
	$.pkp.plugins.pubIds.sri = $.pkp.plugins.pubIds.sri || {};
	$.pkp.plugins.pubIds.sri.js = $.pkp.plugins.pubIds.sri.js || {};

	$.pkp.plugins.pubIds.sri.js.SriSettingsFormHandler =
		function($form, options) {
			$.pkp.controllers.form.AjaxFormHandler.call(
				this, $form, options
			);
			this.accountStatusRequest_ = null;
			var self = this;

			// Bind radio-change events to toggle the suffix pattern field.
			$(':radio[name="sriSuffix"]', $form).click(
				this.callbackWrapper(this.updatePatternFormElementStatus_)
			);

			// Set the correct initial state on load.
			this.updatePatternFormElementStatus_();

			// Account status is fetched through the OJS server.
			$form.on('click.sriAccountStatus',
				'[data-sri-account-status-refresh]', function(event) {
					event.preventDefault();
					self.loadAccountStatus_();
				}
			);
			this.bind('formSubmitted', function() {
				self.loadAccountStatus_();
			});
			this.bind('pkpRemoveHandler', function() {
				self.destroyAccountStatusRequest_();
			});
		};
	$.pkp.classes.Helper.inherits(
		$.pkp.plugins.pubIds.sri.js.SriSettingsFormHandler,
		$.pkp.controllers.form.AjaxFormHandler
	);

	$.pkp.plugins.pubIds.sri.js.SriSettingsFormHandler.prototype
		.accountStatusRequest_ = null;

	/**
	 * Fetch the account status through the OJS component endpoint.
	 *
	 * @private
	 */
	$.pkp.plugins.pubIds.sri.js.SriSettingsFormHandler.prototype
		.loadAccountStatus_ = function() {
			var $form = this.getHtmlElement(),
				$status = $form.find('[data-sri-account-status]'),
				url, self, request;

			if (!$status.length) {
				return;
			}
			url = $status.attr('data-sri-account-status-url');
			if (!url) {
				return;
			}

			self = this;
			if (this.accountStatusRequest_) {
				this.accountStatusRequest_.abort();
			}

			$status.attr('aria-busy', 'true');
			$status.empty().append(
				$('<p></p>')
					.addClass('pkp_help')
					.attr('data-sri-account-status-message', '1')
					.text($status.attr('data-sri-account-status-loading') || '')
			);

			request = $.ajax({
				url: url,
				type: 'GET',
				dataType: 'json',
				cache: false,
				headers: {'X-Requested-With': 'XMLHttpRequest'}
			});
			this.accountStatusRequest_ = request;

			request.done(function(jsonData) {
				if (self.accountStatusRequest_ !== request) {
					return;
				}
				if (jsonData && jsonData.status === true &&
					typeof jsonData.content === 'string') {
					$status.html(jsonData.content);
				} else {
					self.showAccountStatusError_($status);
				}
			});
			request.fail(function(_xhr, textStatus) {
				if (textStatus !== 'abort' && self.accountStatusRequest_ === request) {
					self.showAccountStatusError_($status);
				}
			});
			request.always(function() {
				if (self.accountStatusRequest_ === request) {
					self.accountStatusRequest_ = null;
					$status.removeAttr('aria-busy');
				}
			});
		};

	/**
	 * Replace the status area with a safe, localized error state.
	 *
	 * @private
	 * @param {jQueryObject} $status account status container.
	 */
	$.pkp.plugins.pubIds.sri.js.SriSettingsFormHandler.prototype
		.showAccountStatusError_ = function($status) {
			var $message = $('<p></p>')
				.addClass('pkp_help')
				.css('color', '#b40000')
				.text($status.attr('data-sri-account-status-unavailable') || ''),
				$button = $('<button></button>')
				.attr('type', 'button')
				.addClass('pkpButton')
				.attr('data-sri-account-status-refresh', '1')
				.text($status.attr('data-sri-account-status-refresh-label') || '');

			$status.empty().append($message, $button);
		};

	/**
	 * Abort pending work and remove delegated handlers when OJS destroys the form.
	 *
	 * @private
	 */
	$.pkp.plugins.pubIds.sri.js.SriSettingsFormHandler.prototype
		.destroyAccountStatusRequest_ = function() {
			if (this.accountStatusRequest_) {
				this.accountStatusRequest_.abort();
				this.accountStatusRequest_ = null;
			}
			this.getHtmlElement().off('.sriAccountStatus');
		};

	/**
	 * Toggle the "required" class on the suffix pattern input to match
	 * the currently selected suffix mode radio button.
	 *
	 * @private
	 */
	$.pkp.plugins.pubIds.sri.js.SriSettingsFormHandler.prototype
		.updatePatternFormElementStatus_ = function() {
			var $form = this.getHtmlElement(),
				$patternField = $form.find(
					'[id^="sriPublicationSuffixPattern"]'
				).filter(':text'),
				$patternHelp = $patternField
					.closest('.pkp_form_section')
					.find('.pkp_help');

			if ($('input[name="sriSuffix"]:checked', $form).val() === 'pattern') {
				$patternField.addClass('required');
				$patternField.removeAttr('disabled');
				if ($patternHelp.length) {
					$patternHelp.show();
				}
			} else {
				$patternField.removeClass('required');
				if ($patternHelp.length) {
					$patternHelp.hide();
				}
			}
		};
})(jQuery);
