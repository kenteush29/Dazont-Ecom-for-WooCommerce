/* global dzeContent, jQuery, tinymce */
/**
 * AI Content metabox on the product edit screen: generate each text field with
 * Claude (result shown for review + copy/insert), and a "scene image" with
 * fal.ai that is appended to the product gallery.
 */
(function ($) {
	'use strict';

	var cfg = dzeContent, i18n = cfg.i18n;

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

	// Best-effort insert into the classic WooCommerce editors.
	function setEditor(id, html) {
		if (window.tinymce && tinymce.get(id) && !tinymce.get(id).isHidden()) {
			tinymce.get(id).setContent(html);
		} else {
			$('#' + id).val(html);
		}
	}

	$(document).on('click', '.dze-content-gen', function () {
		var $btn = $(this), field = $btn.data('field');
		var supplier = $('#dze-content-supplier').val() || '';
		var $out = $('#dze-content-out');
		$btn.prop('disabled', true);
		$out.html('<p><span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span>' + esc(i18n.generating) + '</p>');
		$.post(cfg.ajaxUrl, { action: 'dze_content_text', nonce: cfg.nonce, post: cfg.postId, field: field, supplier: supplier })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { $out.html('<p style="color:#b32d2e;">' + esc((res.data && res.data.message) || i18n.error) + '</p>'); return; }
				renderResult(field, res.data.text);
			})
			.fail(function () { $btn.prop('disabled', false); $out.html('<p style="color:#b32d2e;">' + esc(i18n.error) + '</p>'); });
	});

	function renderResult(field, text) {
		var $out = $('#dze-content-out');
		var actions = '<button type="button" class="button button-small dze-c-copy">' + esc(i18n.copy) + '</button>';
		if (field === 'description') { actions += ' <button type="button" class="button button-small dze-c-ins-desc">' + esc(i18n.insertDesc) + '</button>'; }
		if (field === 'short') { actions += ' <button type="button" class="button button-small dze-c-ins-short">' + esc(i18n.insertShort) + '</button>'; }
		$out.html(
			'<div style="border:1px solid #dcdcde;border-radius:4px;padding:10px;margin-top:8px;background:#fff;">' +
			'<textarea class="dze-c-text" rows="6" style="width:100%;font-family:monospace;">' + esc(text) + '</textarea>' +
			'<p style="margin:8px 0 0;">' + actions + '</p></div>'
		);
	}

	$(document).on('click', '.dze-c-copy', function () {
		var $btn = $(this), val = $('.dze-c-text').val();
		navigator.clipboard.writeText(val).then(function () { $btn.text(i18n.copied); setTimeout(function () { $btn.text(i18n.copy); }, 1500); });
	});
	$(document).on('click', '.dze-c-ins-desc', function () { setEditor('content', $('.dze-c-text').val()); });
	$(document).on('click', '.dze-c-ins-short', function () { setEditor('excerpt', $('.dze-c-text').val()); });

	// ---- Scene image (fal.ai) ----
	$('#dze-content-img').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		var $st = $('#dze-content-img-status').css('color', '#646970').text(i18n.imgWait);
		$('#dze-content-img-out').empty();
		$.post(cfg.ajaxUrl, { action: 'dze_content_image', nonce: cfg.nonce, post: cfg.postId })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { $st.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#00794b').text(i18n.imgAdded);
				if (res.data.url) { $('#dze-content-img-out').html('<img src="' + res.data.url + '" alt="" style="max-width:220px;height:auto;border:1px solid #dcdcde;border-radius:4px;margin-top:8px;" />'); }
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
	});

}(jQuery));
