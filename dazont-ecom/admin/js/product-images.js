/* global dzeImg, jQuery, wp */
(function ($) {
	'use strict';

	var cfg  = dzeImg;
	var i18n = cfg.i18n;

	var $box = $('.dze-img-box');
	if (!$box.length) { return; }

	var productId = parseInt($box.data('product'), 10) || 0;
	var refs = [];        // [{ id, url }]
	var currentId = 0;    // temp attachment id awaiting accept/discard
	var frame = null;

	// ---- Situation-dependent fields ----
	function syncFields() {
		var sit = $('#dze-img-situation').val();
		$('.dze-img-when-recolor').toggle(sit === 'recolor');
		$('.dze-img-when-custom').toggle(sit === 'custom');
	}
	$('#dze-img-situation').on('change', syncFields);
	syncFields();

	// ---- Reference image picker (WP Media) ----
	function renderRefs() {
		var $wrap = $('#dze-img-refs').empty();
		refs.forEach(function (r, i) {
			$wrap.append(
				'<span class="dze-img-ref"><img src="' + r.url + '" alt="" />' +
				'<button type="button" data-i="' + i + '" aria-label="remove">&times;</button></span>'
			);
		});
		$('.dze-img-clear-refs').toggle(refs.length > 0);
	}

	$('#dze-img-pick').on('click', function (e) {
		e.preventDefault();
		if (frame) { frame.open(); return; }
		frame = wp.media({
			title: i18n.pickRef,
			button: { text: i18n.pickRef },
			library: { type: 'image' },
			multiple: true
		});
		frame.on('select', function () {
			refs = [];
			frame.state().get('selection').each(function (att) {
				var j = att.toJSON();
				var url = (j.sizes && j.sizes.thumbnail && j.sizes.thumbnail.url) || j.url;
				refs.push({ id: j.id, url: url });
			});
			renderRefs();
		});
		frame.open();
	});

	$(document).on('click', '#dze-img-refs .dze-img-ref button', function () {
		refs.splice(parseInt($(this).data('i'), 10), 1);
		renderRefs();
	});
	$('.dze-img-clear-refs').on('click', function () { refs = []; renderRefs(); });

	// ---- Generate ----
	function busy(on, msg) {
		$('#dze-img-generate, .dze-img-regen, .dze-img-accept').prop('disabled', on);
		$('#dze-img-status').text(msg || '');
	}

	function generate() {
		var sit = $('#dze-img-situation').val();
		if (sit === 'recolor' && !$.trim($('#dze-img-target').val())) {
			$('#dze-img-status').text(i18n.needTarget); return;
		}
		var data = {
			action: 'dze_img_generate',
			nonce: cfg.nonce,
			product: productId,
			situation: sit,
			target: $('#dze-img-target').val() || '',
			custom: $('#dze-img-custom').val() || '',
			refs: refs.map(function (r) { return r.id; })
		};
		busy(true, i18n.generating);
		$.post(cfg.ajaxUrl, data).done(function (res) {
			busy(false, '');
			if (!res.success) {
				$('#dze-img-status').text((res.data && res.data.message) || i18n.error);
				return;
			}
			currentId = res.data.id;
			$('#dze-img-preview-img').attr('src', res.data.url);
			$('.dze-img-reload-note').hide();
			$('.dze-img-result-actions').show();
			$('#dze-img-result').show();
		}).fail(function () { busy(false, i18n.error); });
	}

	$('#dze-img-generate').on('click', generate);
	$(document).on('click', '.dze-img-regen', function () {
		// Discard the pending one first so we don't leave orphans.
		if (currentId) {
			$.post(cfg.ajaxUrl, { action: 'dze_img_discard', nonce: cfg.nonce, id: currentId });
			currentId = 0;
		}
		generate();
	});

	// ---- Accept / Discard ----
	$(document).on('click', '.dze-img-accept', function () {
		if (!currentId) { return; }
		var mode = $(this).data('mode') === 'featured' ? 'featured' : 'gallery';
		busy(true, '');
		$.post(cfg.ajaxUrl, {
			action: 'dze_img_accept', nonce: cfg.nonce,
			id: currentId, product: productId, mode: mode
		}).done(function (res) {
			busy(false, '');
			if (!res.success) {
				$('#dze-img-status').text((res.data && res.data.message) || i18n.error);
				return;
			}
			currentId = 0;
			$('#dze-img-status').text(i18n.accepted);
			$('.dze-img-result-actions').hide();
			$('.dze-img-reload-note').show();
		}).fail(function () { busy(false, i18n.error); });
	});

	$(document).on('click', '.dze-img-discard', function () {
		if (currentId) {
			$.post(cfg.ajaxUrl, { action: 'dze_img_discard', nonce: cfg.nonce, id: currentId });
			currentId = 0;
		}
		$('#dze-img-result').hide();
		$('#dze-img-status').text('');
	});

}(jQuery));
