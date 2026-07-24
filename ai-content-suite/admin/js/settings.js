/* global aicsSettings, jQuery */
(function ($) {
	'use strict';

	$('#aics-test-api').on('click', function () {
		var $btn = $(this), $spinner = $('#aics-test-spinner'), $result = $('#aics-test-result');
		$btn.prop('disabled', true);
		$spinner.addClass('is-active');
		$result.html('');
		$.post(aicsSettings.ajaxUrl, { action: 'aics_test_connection', nonce: aicsSettings.nonce })
		.done(function (res) {
			if (res.success) {
				$result.html('<div class="notice notice-success inline" style="margin:0"><p><strong>' + aicsSettings.i18n.success + '</strong> ' + escHtml(res.data.message) + ' <em>(' + escHtml(res.data.model) + ', ' + res.data.usage.input_tokens + ' in / ' + res.data.usage.output_tokens + ' out)</em></p></div>');
			} else {
				$result.html('<div class="notice notice-error inline" style="margin:0"><p><strong>' + aicsSettings.i18n.error + '</strong> ' + escHtml(res.data.message) + '</p></div>');
			}
		})
		.fail(function () { $result.html('<div class="notice notice-error inline" style="margin:0"><p>Request failed.</p></div>'); })
		.always(function () { $btn.prop('disabled', false); $spinner.removeClass('is-active'); });
	});

	$('#aics-clear-log').on('click', function () {
		var $btn = $(this);
		if (!confirm(aicsSettings.i18n.clearing)) return;
		$btn.prop('disabled', true).text(aicsSettings.i18n.clearing);
		$.post(aicsSettings.ajaxUrl, { action: 'aics_clear_log', nonce: aicsSettings.nonce })
		.done(function (res) {
			if (res.success) { $('table.aics-log-table').replaceWith('<p>' + aicsSettings.i18n.cleared + '</p>'); $btn.remove(); }
		})
		.always(function () { $btn.prop('disabled', false); });
	});

	$('#aics-reset-prompts').on('click', function () {
		var $btn = $(this);
		if (!confirm(aicsSettings.i18n.resetConfirm)) return;
		$btn.prop('disabled', true);
		$.post(aicsSettings.ajaxUrl, { action: 'aics_reset_prompts', nonce: aicsSettings.nonce })
		.done(function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				$btn.prop('disabled', false);
				alert((res.data && res.data.message) || 'Error');
			}
		})
		.fail(function () { $btn.prop('disabled', false); alert('Request failed.'); });
	});

	function escHtml(str) {
		return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}
}(jQuery));
