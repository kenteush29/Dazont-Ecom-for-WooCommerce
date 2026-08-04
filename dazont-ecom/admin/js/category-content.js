/* global dzeCatContent, jQuery */
/**
 * Category description writer: the Description column opens a panel —
 * see the data used, write, read the rendered result, edit the HTML if
 * needed, apply to the category. Prompt editable in place.
 */
(function ($) {
	'use strict';

	var cfg = dzeCatContent, i18n = cfg.i18n;

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

	$(document).on('click', '.dze-cc-open', function () {
		var id = $(this).data('id');
		$('#dze-cc-body').html('<p><span class="dze-cx-spin"></span></p>');
		$('#dze-cc-modal').addClass('is-open');
		$.post(cfg.ajaxUrl, { action: 'dze_cc_panel', nonce: cfg.nonce, term: id })
			.done(function (res) {
				if (res && res.success) {
					$('#dze-cc-title').text(res.data.title);
					$('#dze-cc-body').html(res.data.html);
				} else {
					$('#dze-cc-body').text((res && res.data && res.data.message) || i18n.error);
				}
			})
			.fail(function () { $('#dze-cc-body').text(i18n.error); });
	});
	$(document).on('click', '.dze-hub-close', function () { $(this).closest('.dze-cx-modal').removeClass('is-open'); });
	$(document).on('click', '#dze-cc-modal', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });

	// Data / prompt / HTML panels stay collapsed until asked for.
	$(document).on('click', '.dze-cc-dtoggle', function () { $(this).closest('.dze-cc-box').find('.dze-cc-data').toggle(); });
	$(document).on('click', '.dze-cc-ptoggle', function () { $(this).closest('.dze-cc-box').find('.dze-cc-pwrap').toggle(); });
	$(document).on('click', '.dze-cc-htmltoggle', function () { $(this).closest('.dze-cc-box').find('.dze-cc-html').toggle(); });
	$(document).on('click', '.dze-cc-prestore', function () {
		$(this).closest('.dze-cc-box').find('.dze-cc-ptext').val(i18n.defaultPrompt);
	});
	$(document).on('click', '.dze-cc-psave', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_cc_save_prompt', nonce: $box.data('nonce'), prompt: $box.find('.dze-cc-ptext').val() })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (res && res.success) { $btn.text(i18n.savedPrompt); setTimeout(function () { $btn.text('💾 ' + i18n.savePrompt); }, 1800); }
				else { window.alert((res && res.data && res.data.message) || i18n.error); }
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	// Nothing is written to the category before Apply.
	$(document).on('click', '.dze-cc-gen', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-cc-status').css('color', '#646970').html('<span class="dze-cx-spin"></span> ' + esc(i18n.working));
		var $p = $box.find('.dze-cc-pwrap');
		$.post(cfg.ajaxUrl, {
			action: 'dze_cc_generate', nonce: $box.data('nonce'), term: $box.data('term'),
			prompt: $p.is(':visible') ? ($box.find('.dze-cc-ptext').val() || '') : ''
		})
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { $st.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.text('');
				$box.find('.dze-cc-preview').html(res.data.html);
				$box.find('.dze-cc-html').val(res.data.html);
				$box.find('.dze-cc-result').show();
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
	});

	// The HTML box is the source of truth when it has been opened and edited.
	$(document).on('input', '.dze-cc-html', function () {
		$(this).closest('.dze-cc-box').find('.dze-cc-preview').html($(this).val());
	});

	$(document).on('click', '.dze-cc-apply', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-cc-status').css('color', '#646970').html('<span class="dze-cx-spin"></span>');
		$.post(cfg.ajaxUrl, { action: 'dze_cc_apply', nonce: $box.data('nonce'), term: $box.data('term'), html: $box.find('.dze-cc-html').val() })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { $st.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#0a7040').text(i18n.applied);
				$('.dze-cc-open[data-id="' + $box.data('term') + '"] span').first()
					.text(res.data.words + ' words').css('color', '#0a7040');
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
	});

	$(document).on('click', '.dze-cc-discard', function () {
		var $box = $(this).closest('.dze-cc-box');
		$box.find('.dze-cc-result').hide();
		$box.find('.dze-cc-preview').empty();
		$box.find('.dze-cc-html').val('');
	});

}(jQuery));
