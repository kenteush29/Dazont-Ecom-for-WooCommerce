/* global dzeTrending, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeTrending;
	var i18n = cfg.i18n;

	$('#dze-trending-clear-cache').on('click', function () {
		var $btn    = $(this);
		var $status = $('#dze-trending-clear-status');

		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.clearing);

		$.post(cfg.ajaxUrl, { action: 'dze_trending_clear_cache', nonce: cfg.nonce })
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').removeClass('is-ko').text(i18n.cleared);
			} else {
				$status.css('color', '#c0392b').text((res.data && res.data.message) || i18n.error);
			}
		})
		.fail(function () {
			$status.css('color', '#c0392b').text(i18n.error);
		})
		.always(function () {
			$btn.prop('disabled', false);
		});
	});

}(jQuery));
