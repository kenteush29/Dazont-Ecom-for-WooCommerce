/* global dzeMai, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeMai;
	var i18n = cfg.i18n;

	// ---- Generate suggestions ----
	$('#dze-mai-generate').on('click', function () {
		var $btn = $(this), $status = $('#dze-mai-gen-status');
		var start = $('#dze-mai-start').val();
		var end   = $('#dze-mai-end').val();
		if (!start || !end) {
			$status.css('color', '#b32d2e').text(i18n.needDates);
			return;
		}
		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.generating);
		$.post(cfg.ajaxUrl, {
			action: 'dze_mai_generate',
			nonce: cfg.nonce,
			start_date: start,
			end_date: end,
			lang: '',
			countries: ''
		})
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').text('✓ ' + res.data.message);
				if (res.data.count > 0) {
					// Reload to render the new suggestion rows server-side.
					window.location.reload();
				} else {
					$btn.prop('disabled', false);
				}
			} else {
				$status.css('color', '#b32d2e').text('✕ ' + ((res.data && res.data.message) || i18n.error));
				$btn.prop('disabled', false);
			}
		})
		.fail(function (xhr) {
			var extra = xhr && xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
			$status.css('color', '#b32d2e').text(i18n.error + extra + '. ' + (xhr && xhr.status === 0 ? 'Timed out or blocked.' : ''));
			$btn.prop('disabled', false);
		});
	});

	// The titles typed or corrected on a suggestion row.
	function rowI18n($row) {
		var out = {};
		$row.find('.dze-f-i18n').each(function () {
			var v = $.trim($(this).val() || '');
			if (v) { out[$(this).data('lang')] = v; }
		});
		return out;
	}

	// ---- Accept one suggestion (with its inline edits). Returns a promise. ----
	function acceptRow($row) {
		var $status = $row.find('.dze-mai-row-status');
		$row.find('.dze-mai-accept, .dze-mai-modify').prop('disabled', true);
		$status.css('color', '#666').text(i18n.accepting);
		return $.post(cfg.ajaxUrl, {
			action: 'dze_mai_save_event',
			nonce: cfg.nonce,
			id: $row.data('id'),
			title: $row.find('.dze-f-title').val(),
			percent: $row.find('.dze-f-percent').val(),
			start_date: $row.find('.dze-f-start').val(),
			end_date: $row.find('.dze-f-end').val(),
			languages: $row.find('.dze-f-langs').val(),
			// Reviewed on the row, so they travel with it — no second call.
			i18n: JSON.stringify(rowI18n($row)),
		})
		.done(function (res) {
			if (res.success) {
				$row.fadeOut(200, function () { $(this).remove(); });
			} else {
				$status.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error);
				$row.find('.dze-mai-accept, .dze-mai-modify').prop('disabled', false);
			}
		})
		.fail(function () {
			$status.css('color', '#b32d2e').text(i18n.error);
			$row.find('.dze-mai-accept, .dze-mai-modify').prop('disabled', false);
		});
	}

	// ---- Discard one suggestion. Returns a promise. ----
	function refuseRow($row) {
		return $.post(cfg.ajaxUrl, { action: 'dze_mai_refuse', nonce: cfg.nonce, id: $row.data('id') })
			.always(function () { $row.fadeOut(200, function () { $(this).remove(); }); });
	}

	// Single Accept → reload so the new event shows in the list below immediately.
	$(document).on('click', '.dze-mai-accept', function () {
		acceptRow($(this).closest('.dze-mai-row')).done(function (res) {
			if (res && res.success) { window.location.reload(); }
		});
	});

	$(document).on('click', '.dze-mai-refuse', function () {
		if (!window.confirm(i18n.confirmRef)) { return; }
		refuseRow($(this).closest('.dze-mai-row'));
	});

	// ---- Event editor popup (Accept & modify / New event) ----
	// ---- The title in the shop's other languages ----
	function i18nFields() { return $('.dze-ev-i18n-field'); }

	function setI18n(map) {
		map = map || {};
		i18nFields().each(function () {
			var v = map[$(this).data('lang')];
			if (typeof v === 'string') { $(this).val(v); }
		});
	}

	function getI18n() {
		var out = {};
		i18nFields().each(function () {
			var v = $.trim($(this).val() || '');
			if (v) { out[$(this).data('lang')] = v; }
		});
		return out;
	}

	function openModal(data) {
		$('#dze-ev-id').val(data.id || '');
		$('#dze-ev-name').val(data.title || '');
		i18nFields().val('');
		setI18n(data.i18n);
		$('#dze-ev-tr-status').text('');
		$('#dze-ev-i18n').prop('open', false);
		$('#dze-ev-percent').val(data.percent || 10);
		$('#dze-ev-start').val(data.start || '');
		$('#dze-ev-end').val(data.end || '');
		$('#dze-ev-langs').val(data.langs || '');
		$('#dze-ev-title').text(data.id ? i18n.modifyTitle : i18n.newTitle);
		$('.dze-ev-status').text('');
		$('#dze-ev-modal').css('display', 'flex');
	}
	function closeModal() { $('#dze-ev-modal').hide(); }

	$(document).on('click', '.dze-mai-modify', function () {
		var $r = $(this).closest('.dze-mai-row');
		openModal({
			id: $r.data('id'), title: $r.data('title'), percent: $r.data('percent'),
			start: $r.data('start'), end: $r.data('end'),
			langs: $r.data('langs'),
			// What the row already holds, corrections included.
			i18n: rowI18n($r)
		});
	});
	$(document).on('click', '.dze-mai-new-event', function () { openModal({}); });

	$(document).on('click', '#dze-ev-translate', function () {
		var $b = $(this), $st = $('#dze-ev-tr-status');
		var title = $.trim($('#dze-ev-name').val() || '');
		if (!title) {
			$st.css('color', '#b32d2e').text(i18n.titleFirst);
			$('#dze-ev-name').trigger('focus');
			return;
		}
		$b.prop('disabled', true);
		$st.css('color', '#646970').text(i18n.translating);
		$.post(cfg.ajaxUrl, { action: 'dze_mai_translate', nonce: cfg.nonce, title: title })
			.done(function (res) {
				$b.prop('disabled', false);
				if (res && res.success) {
					setI18n(res.data.i18n);
					// Shown, not saved: they go with the event when you save it.
					$('#dze-ev-i18n').prop('open', true);
					$st.css('color', '#0a7040').text(i18n.translated);
					return;
				}
				$st.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$st.css('color', '#b32d2e').text(i18n.error);
			});
	});
	$(document).on('click', '.dze-ev-cancel', function () { closeModal(); });
	$(document).on('click', '#dze-ev-modal', function (e) { if (e.target === this) { closeModal(); } });

	function saveModal(pushGmc) {
		var $status = $('.dze-ev-status');
		if (!$('#dze-ev-start').val() || !$('#dze-ev-end').val()) {
			$status.css('color', '#b32d2e').text(i18n.needDates);
			return;
		}
		$('.dze-ev-save, .dze-ev-save-gmc').prop('disabled', true);
		$status.css('color', '#666').text(i18n.saving);
		var payload = {
			action: 'dze_mai_save_event',
			nonce: cfg.nonce,
			id: $('#dze-ev-id').val(),
			title: $('#dze-ev-name').val(),
			percent: $('#dze-ev-percent').val(),
			start_date: $('#dze-ev-start').val(),
			end_date: $('#dze-ev-end').val(),
			languages: $('#dze-ev-langs').val() || '',
			i18n: JSON.stringify(getI18n()),
		};
		// A promotion is pushed to every Merchant Center account that is set up
		// — there is nothing to pick here: one event, one shop.
		if (pushGmc) { payload.push_gmc = 1; }
		$.post(cfg.ajaxUrl, payload).done(function (res) {
			if (res.success) { window.location.reload(); }
			else {
				$status.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error);
				$('.dze-ev-save, .dze-ev-save-gmc').prop('disabled', false);
			}
		}).fail(function () {
			$status.css('color', '#b32d2e').text(i18n.error);
			$('.dze-ev-save, .dze-ev-save-gmc').prop('disabled', false);
		});
	}
	$(document).on('click', '.dze-ev-save', function () { saveModal(false); });
	$(document).on('click', '.dze-ev-save-gmc', function () { saveModal(true); });

	// ---- Select all ----
	$(document).on('change', '#dze-mai-check-all', function () {
		$('#dze-mai-suggestions .dze-mai-cb').prop('checked', $(this).is(':checked'));
	});

	function selectedRows() {
		return $('#dze-mai-suggestions .dze-mai-cb:checked').closest('.dze-mai-row');
	}

	// ---- Bulk accept (reload once all are added) ----
	$(document).on('click', '.dze-mai-bulk-accept', function () {
		var $rows = selectedRows();
		if (!$rows.length) { return; }
		var $status = $('#dze-mai-bulk-status');
		$status.css('color', '#666').text(i18n.accepting + ' (' + $rows.length + ')');
		var jobs = $rows.map(function () { return acceptRow($(this)); }).get();
		$.when.apply($, jobs).always(function () { window.location.reload(); });
	});

	// ---- Bulk discard ----
	$(document).on('click', '.dze-mai-bulk-refuse', function () {
		var $rows = selectedRows();
		if (!$rows.length) { return; }
		if (!window.confirm(i18n.confirmRefBulk)) { return; }
		$rows.each(function () { refuseRow($(this)); });
	});

}(jQuery));
