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
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return str.replace(/%\d\$s|%s/g, function () { return args[i++]; });
	}

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

	// ---- SEMrush import, straight from this panel ----
	// Reuses the Sourcing Assistant endpoints: upload → column mapping → import.
	$(document).on('click', '.dze-cc-imtoggle', function () {
		$(this).closest('.dze-cc-box').find('.dze-cc-import').toggle();
	});

	$(document).on('change', '.dze-cc-file', function () {
		var $box = $(this).closest('.dze-cc-box');
		var file = this.files && this.files[0];
		if (!file) { return; }
		var $st = $box.find('.dze-cc-imstatus').css('color', '#646970').html('<span class="dze-cx-spin"></span> ' + esc(i18n.reading));
		var fd = new FormData();
		fd.append('action', 'dze_kw_upload');
		fd.append('nonce', cfg.kwNonce);
		fd.append('file', file);
		$.ajax({ url: cfg.ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false })
			.done(function (res) {
				if (!res || !res.success) { $st.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#646970').text(res.data.total + ' rows');
				$box.data('token', res.data.token);
				renderMapping($box, res.data.headers, res.data.guess);
			})
			.fail(function () { $st.css('color', '#b32d2e').text(i18n.error); });
	});

	// One select per column the importer understands, pre-set to its guess.
	function renderMapping($box, headers, guess) {
		var fields = [
			['keyword', i18n.colKeyword], ['volume', i18n.colVolume],
			['kd', i18n.colKd], ['cpc', i18n.colCpc], ['intent', i18n.colIntent]
		];
		var html = '';
		fields.forEach(function (f) {
			var sel = (guess && typeof guess[f[0]] !== 'undefined') ? guess[f[0]] : -1;
			html += '<label style="margin-right:12px;">' + esc(f[1]) + ' <select class="dze-cc-col" data-field="' + f[0] + '">' +
				'<option value="-1">' + esc(i18n.colNone) + '</option>';
			headers.forEach(function (h, i) {
				html += '<option value="' + i + '"' + (sel === i ? ' selected' : '') + '>' + esc(h) + '</option>';
			});
			html += '</select></label>';
		});
		$box.find('.dze-cc-mapfields').html(html);
		$box.find('.dze-cc-map').show();
	}

	$(document).on('click', '.dze-cc-doimport', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-cc-imstatus').css('color', '#646970').html('<span class="dze-cx-spin"></span> ' + esc(i18n.importing));
		var map = {};
		$box.find('.dze-cc-col').each(function () { map[$(this).data('field')] = parseInt($(this).val(), 10); });
		$.post(cfg.ajaxUrl, {
			action: 'dze_kw_import', nonce: cfg.kwNonce,
			cat: $box.data('term'), token: $box.data('token'), map: map
		})
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { $st.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#0a7040').text(sprintf(i18n.imported, res.data.imported, res.data.updated));
				// Reload the panel so the query pools reflect the new keywords.
				$('.dze-cc-open[data-id="' + $box.data('term') + '"]').trigger('click');
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
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
