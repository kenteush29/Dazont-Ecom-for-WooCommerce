/* global dzePod, jQuery, wp */
/**
 * POD side box: pick a design (media modal), generate the printed-product
 * image with the dedicated POD prompt (hidden behind ✎), review the result,
 * attach as main image or gallery.
 */
(function ($) {
	'use strict';

	var cfg = dzePod, i18n = cfg.i18n;
	var results = [];   // generated URLs, click to select the keeper
	var selIdx = -1;
	var frame = null;

	function renderResults() {
		var $g = $('#dze-pod-results .dze-pod-grid').empty();
		results.forEach(function (u, i) {
			$('<img />').attr('src', u).toggleClass('is-sel', i === selIdx).attr('data-i', i).appendTo($g);
		});
		$('#dze-pod-results').toggle(results.length > 0);
	}
	$(document).on('click', '#dze-pod-results .dze-pod-grid img', function () {
		selIdx = parseInt($(this).attr('data-i'), 10);
		renderResults();
	});

	function status(msg, color) {
		$('#dze-pod-status').css('color', color || '#646970').html(msg);
	}

	$('#dze-pod-pick').on('click', function (e) {
		e.preventDefault();
		if (!frame) {
			frame = wp.media({ title: i18n.pickTitle, library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				$.post(cfg.ajaxUrl, { action: 'dze_pod_design', nonce: cfg.nonce, post: cfg.postId, attachment: att.id })
					.done(function (res) {
						if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
						$('#dze-pod-design-preview').show().find('img').attr('src', res.data.thumb);
						$('#dze-pod-pick').text(i18n.change);
						$('#dze-pod-clear').show();
						$('#dze-pod-generate').prop('disabled', false);
					})
					.fail(function () { window.alert(i18n.error); });
			});
		}
		frame.open();
	});

	$('#dze-pod-clear').on('click', function () {
		$.post(cfg.ajaxUrl, { action: 'dze_pod_design', nonce: cfg.nonce, post: cfg.postId, attachment: 0 })
			.done(function () {
				$('#dze-pod-design-preview').hide();
				$('#dze-pod-pick').text(i18n.upload);
				$('#dze-pod-clear').hide();
				$('#dze-pod-generate').prop('disabled', true);
			});
	});

	// Discreet prompt editor (✎), 💾 persists it.
	$('#dze-pod-prompt-toggle').on('click', function () {
		var $w = $('#dze-pod-pwrap');
		if (!$w.data('filled')) { $('#dze-pod-prompt-live').val(cfg.prompt || ''); $w.data('filled', 1); }
		$w.toggle();
	});
	$('#dze-pod-prompt-save').on('click', function () {
		var val = $('#dze-pod-prompt-live').val(), $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_pod_save_prompt', nonce: cfg.nonce, prompt: val })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (res.success) { cfg.prompt = val; $btn.text(i18n.savedPrompt); setTimeout(function () { $btn.text('💾 ' + i18n.savePrompt); }, 1800); }
				else { window.alert((res.data && res.data.message) || i18n.error); }
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	// One generation per click (the mockup makes the render deterministic
	// enough); successive runs accumulate in the grid so a retake can still be
	// compared with the previous one.
	$('#dze-pod-generate').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		var live = $('#dze-pod-pwrap').is(':visible') ? ($('#dze-pod-prompt-live').val() || '') : '';
		var custom = (live && live !== cfg.prompt) ? live : '';
		status('<span class="dze-cx-spin"></span> ' + i18n.working + (cfg.mockupSet ? '' : '<br />' + i18n.noMockup));
		$.post(cfg.ajaxUrl, { action: 'dze_pod_generate', nonce: cfg.nonce, post: cfg.postId, custom_prompt: custom })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { status((res.data && res.data.message) || i18n.error, '#b32d2e'); return; }
				results.push(res.data.url);
				selIdx = results.length - 1;
				renderResults();
				status(i18n.ready, '#00794b');
			})
			.fail(function () { $btn.prop('disabled', false); status(i18n.error, '#b32d2e'); });
	});

	// Hand the kept render to the Content image toolbox as a SOURCE: from there
	// the ✎ variant flow builds new images on top of it (UGC shots, scenes…).
	$('#dze-pod-tolab').on('click', function () {
		if (selIdx < 0 || !results[selIdx]) { return; }
		if (window.dzeContentAddToGallery) {
			window.dzeContentAddToGallery(results[selIdx]);
			if (window.dzeContentOpen) { window.dzeContentOpen('image'); }
		}
	});

	$('#dze-pod-attach').on('click', function () {
		if (selIdx < 0 || !results[selIdx]) { return; }
		var $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_pod_attach', nonce: cfg.nonce, post: cfg.postId, url: results[selIdx], target: $('#dze-pod-target').val() })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				status(i18n.attached, '#00794b');
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

}(jQuery));
