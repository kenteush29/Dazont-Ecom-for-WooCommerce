/* global dzeVSplit, jQuery */
/**
 * Variation Split prototype: preview the products a split would create, then
 * create them as drafts. Product edit screen, variable products only.
 */
(function ($) {
	'use strict';

	var cfg = dzeVSplit, i18n = cfg.i18n;
	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

	$('#dze-vsplit-preview').on('click', function () {
		var attr = $('#dze-vsplit-attr').val();
		var $out = $('#dze-vsplit-out').html('<p>' + esc(i18n.working) + '</p>');
		$.post(cfg.ajaxUrl, { action: 'dze_vsplit_preview', nonce: cfg.nonce, post: cfg.postId, attr: attr })
			.done(function (res) {
				if (!res.success) { $out.html('<p style="color:#b32d2e;">' + esc((res.data && res.data.message) || i18n.error) + '</p>'); return; }
				var html = '<p>' + esc(i18n.willMake) + '</p><ul style="list-style:disc;margin-left:18px;">';
				res.data.items.forEach(function (t) { html += '<li>' + esc(t) + '</li>'; });
				html += '</ul>';
				$out.html(html);
				$('#dze-vsplit-run').show().data('count', res.data.items.length);
			})
			.fail(function () { $out.html('<p style="color:#b32d2e;">' + esc(i18n.error) + '</p>'); });
	});

	$('#dze-vsplit-run').on('click', function () {
		var $btn = $(this), n = $btn.data('count') || 0;
		if (!window.confirm(i18n.confirm.replace('%d', n))) { return; }
		var attr = $('#dze-vsplit-attr').val();
		var $out = $('#dze-vsplit-out').html('<p>' + esc(i18n.working) + '</p>');
		$btn.prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_vsplit_run', nonce: cfg.nonce, post: cfg.postId, attr: attr })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { $out.html('<p style="color:#b32d2e;">' + esc((res.data && res.data.message) || i18n.error) + '</p>'); return; }
				var html = '<p>' + esc(i18n.done) + '</p><ul style="list-style:disc;margin-left:18px;">';
				res.data.created.forEach(function (c) {
					html += '<li><a href="' + c.edit + '" target="_blank" rel="noopener">' + esc(c.title) + '</a></li>';
				});
				html += '</ul>';
				$out.html(html);
				$btn.hide();
			})
			.fail(function () { $btn.prop('disabled', false); $out.html('<p style="color:#b32d2e;">' + esc(i18n.error) + '</p>'); });
	});

}(jQuery));
