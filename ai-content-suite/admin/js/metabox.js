/* global aicsMetabox, jQuery */
(function ($) {
	'use strict';

	var cfg  = aicsMetabox;
	var i18n = cfg.i18n;

	// ---- Generate button ----
	$(document).on('click', '.aics-btn-generate', function () {
		var $row     = $(this).closest('.aics-gen-row');
		var $btn     = $(this);
		var $apply   = $row.find('.aics-btn-apply');
		var $preview = $row.find('.aics-preview-area');
		var $status  = $row.find('.aics-gen-status');
		var slot     = $row.data('slot');

		$btn.prop('disabled', true).text(i18n.generating);
		$apply.hide();
		$status.text('').css('color', '#999');

		$.post(cfg.ajaxUrl, {
			action  : 'aics_generate_field',
			nonce   : cfg.nonce,
			post_id : cfg.postId,
			slot    : slot,
		})
		.done(function (res) {
			if (res.success) {
				$preview.val(res.data.text).show();
				$apply.show();
				$status.text(
					res.data.model + ' — ' +
					res.data.usage.input_tokens + ' in / ' +
					res.data.usage.output_tokens + ' out'
				);
			} else {
				$preview.hide();
				$status.text(i18n.error + ' ' + res.data.message).css('color', '#c0392b');
			}
		})
		.fail(function () {
			$status.text(i18n.error + ' Request failed.').css('color', '#c0392b');
		})
		.always(function () {
			$btn.prop('disabled', false).text(i18n.generate);
		});
	});

	// ---- Apply button ----
	$(document).on('click', '.aics-btn-apply', function () {
		var $row       = $(this).closest('.aics-gen-row');
		var $btn       = $(this);
		var $status    = $row.find('.aics-gen-status');
		var slot       = $row.data('slot');
		var targetId   = $row.data('target-id');
		var targetType = $row.data('target-type');
		var value      = $row.find('.aics-preview-area').val();

		$btn.prop('disabled', true).text(i18n.applying);

		$.post(cfg.ajaxUrl, {
			action  : 'aics_apply_field',
			nonce   : cfg.nonce,
			post_id : cfg.postId,
			slot    : slot,
			value   : value,
		})
		.done(function (res) {
			if (res.success) {
				$status.text(i18n.applied).css('color', '#0a7040');
				$btn.hide();
				if (targetId) {
					liveUpdateField(targetId, targetType, value);
				}
			} else {
				$status.text(i18n.error + ' ' + res.data.message).css('color', '#c0392b');
				$btn.prop('disabled', false).text(i18n.apply);
			}
		})
		.fail(function () {
			$status.text(i18n.error + ' Request failed.').css('color', '#c0392b');
			$btn.prop('disabled', false).text(i18n.apply);
		});
	});

	// ---- Live-update the page field after apply ----
	function liveUpdateField(id, type, value) {
		switch (type) {
			case 'input':
				$('#' + id).val(value).trigger('change');
				break;

			case 'tinymce':
				// Try TinyMCE visual editor first, fall back to plain textarea.
				if (typeof tinyMCE !== 'undefined' && tinyMCE.get(id)) {
					tinyMCE.get(id).setContent(value);
				}
				$('#' + id).val(value);
				break;

			case 'acf':
				// ACF text/textarea: input id is "acf-{field_key}".
				var $acfInput = $('#acf-' + id);
				if ($acfInput.length) {
					$acfInput.val(value).trigger('change');
				} else if (typeof tinyMCE !== 'undefined' && tinyMCE.get(id)) {
					// wysiwyg ACF field uses the field_key as editor id.
					tinyMCE.get(id).setContent(value);
				}
				break;

			case 'rankmath':
				$('#' + id).val(value).trigger('input').trigger('change');
				break;
		}
	}

}(jQuery));
