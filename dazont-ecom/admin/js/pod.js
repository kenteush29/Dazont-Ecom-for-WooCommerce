/* global dzePod, jQuery, wp */
/**
 * POD side box: the DESIGN of a product, and nothing else.
 *
 * Pick the print file, enlarge it when it is too small to print, and hand it
 * to the image workshop — which is where every image of this shop is made,
 * with your own prompts, the blank products kept on the shared shelf, the
 * attempts side by side and the naming. This box used to carry a generator, a
 * prompt and a mockup of its own; three ways of doing what the workshop
 * already did, in a screen nobody could hold in their head.
 */
(function ($) {
	'use strict';

	var cfg = dzePod, i18n = cfg.i18n;
	var frame = null;

	function status(msg, color) {
		$('#dze-pod-status').css('color', color || '#646970').html(msg);
	}

	// ---- The design ----
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
						$('#dze-pod-clear, #dze-pod-upscale').show();
						$('#dze-pod-workshop').prop('disabled', false);
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
				$('#dze-pod-clear, #dze-pod-upscale').hide();
				$('#dze-pod-workshop').prop('disabled', true);
			});
	});

	// Upscale the stored design ×4 (fal ESRGAN) → becomes the print file.
	$('#dze-pod-upscale').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		status('<span class="dze-cx-spin"></span> ' + i18n.upscaling);
		$.post(cfg.ajaxUrl, { action: 'dze_pod_upscale', nonce: cfg.nonce, post: cfg.postId })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { status((res.data && res.data.message) || i18n.error, '#b32d2e'); return; }
				$('#dze-pod-design-preview').show().find('img').attr('src', res.data.thumb);
				$('#dze-pod-dims').text(res.data.dims);
				status(i18n.upscaled, '#00794b');
			})
			.fail(function () { $btn.prop('disabled', false); status(i18n.error, '#b32d2e'); });
	});

	// ---- Printing it ----
	// The workshop of Product content, with this design as the subject: one
	// lane, one review, one naming for every image of the shop.
	$('#dze-pod-workshop').on('click', function (e) {
		e.preventDefault();
		$(document).trigger('dze:image', [ { scope: 'main', src: 'design' } ]);
	});
}(jQuery));
