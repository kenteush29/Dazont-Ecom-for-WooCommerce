/* global dzeKlav, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeKlav;
	var i18n = cfg.i18n;

	// ---- Settings: read the account, fill the pickers ----
	$(document).on('click', '#dze-klav-refresh', function () {
		var $b = $(this), $m = $('#dze-klav-refresh-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.loading);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_load', nonce: cfg.nonce })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				fill($('#dze-klav-tpl'), res.data.templates);
				fill($('#dze-klav-inc'), res.data.audiences);
				fill($('#dze-klav-exc'), res.data.audiences);
				$m.css('color', '#0a7040').text(res.data.message);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});

	// Keeps what was already chosen: a refresh must not silently unselect it.
	function fill($sel, map) {
		if (!$sel.length) { return; }
		var chosen = $sel.val();
		var first  = $sel.find('option').first();
		$sel.empty().append(first);
		$.each(map || {}, function (id, label) {
			$sel.append($('<option/>').attr('value', id).text(label));
		});
		if (chosen && $sel.find('option[value="' + chosen + '"]').length) { $sel.val(chosen); }
	}

	// ---- Events screen: one popup, opened from the row ----
	function varsRow(marker, value) {
		return $('<div class="dze-klav-var"/>')
			.append($('<code/>').text(marker))
			.append($('<input type="text" class="regular-text dze-klav-v"/>').attr('data-marker', marker).val(value));
	}

	$(document).on('click', '.dze-klav-open', function (e) {
		e.preventDefault();
		var $cell = $(this).closest('.dze-klav-cell');
		var vars  = $cell.data('vars') || {};
		$('#dze-klav-rule').val($cell.data('rule'));
		$('#dze-klav-name').val($cell.data('name') || $cell.data('title') || '');
		$('#dze-klav-subject').val($cell.data('title') || '');
		$('#dze-klav-preview').val('');
		$('#dze-klav-when').val($cell.data('when') || '');
		$('#dze-klav-write-msg').text('');
		$('#dze-klav-status').text('');
		var $box = $('#dze-klav-vars').empty();
		$.each(vars, function (marker, value) { $box.append(varsRow(marker, value)); });
		$('#dze-klav-modal').css('display', 'flex');
	});

	function close() { $('#dze-klav-modal').hide(); }
	$(document).on('click', '#dze-klav-cancel', close);
	$(document).on('click', '#dze-klav-modal', function (e) { if (e.target === this) { close(); } });

	// ---- Subject + preview text, written from the promotion ----
	$(document).on('click', '#dze-klav-write', function () {
		var $b = $(this), $m = $('#dze-klav-write-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.writing);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_subject', nonce: cfg.nonce, rule: $('#dze-klav-rule').val() })
			.done(function (res) {
				$b.prop('disabled', false);
				if (res && res.success) {
					$('#dze-klav-subject').val(res.data.subject);
					if (res.data.preview) { $('#dze-klav-preview').val(res.data.preview); }
					$m.text('');
					return;
				}
				$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});

	// ---- Create the draft ----
	$(document).on('click', '#dze-klav-go', function () {
		var $b = $(this), $s = $('#dze-klav-status');
		var rule = $('#dze-klav-rule').val();
		var subject = $.trim($('#dze-klav-subject').val() || '');
		if (!subject) {
			$s.css('color', '#b32d2e').text(i18n.subject);
			$('#dze-klav-subject').trigger('focus');
			return;
		}
		var vars = {};
		$('#dze-klav-vars .dze-klav-v').each(function () {
			vars[$(this).data('marker')] = $(this).val() || '';
		});
		$b.prop('disabled', true);
		$s.css('color', '#646970').text(i18n.creating);
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_draft',
			nonce: cfg.nonce,
			rule: rule,
			name: $('#dze-klav-name').val(),
			subject: subject,
			preview: $('#dze-klav-preview').val(),
			datetime: $('#dze-klav-when').val(),
			vars: JSON.stringify(vars)
		})
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$s.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				// The row must read like the shop stands now, not like it did
				// when the page was opened.
				var $cell = $('.dze-klav-cell[data-rule="' + rule + '"]');
				$cell.find('.dze-klav-open').text(i18n.again);
				if (!$cell.find('.dze-klav-link').length) {
					$cell.prepend(
						$('<a class="dze-klav-link" target="_blank" rel="noopener noreferrer"/>')
							.attr('href', res.data.url).text(i18n.open)
					);
					$cell.find('.dze-klav-link').after(' <span style="color:#999;">|</span> ');
				} else {
					$cell.find('.dze-klav-link').attr('href', res.data.url);
				}
				$cell.find('.dze-klav-msg')
					.css('color', res.data.warning ? '#b26a00' : '#0a7040')
					.text(res.data.warning || i18n.made);
				$s.css('color', '#0a7040').text(i18n.made);
				window.open(res.data.url, '_blank', 'noopener');
				close();
			})
			.fail(function () {
				$b.prop('disabled', false);
				$s.css('color', '#b32d2e').text(i18n.error);
			});
	});

}(jQuery));
