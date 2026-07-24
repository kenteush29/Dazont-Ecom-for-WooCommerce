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
			lang: $('#dze-mai-lang').val() || '',
			countries: $('#dze-mai-countries').val() || ''
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
			email_subject: $row.find('.dze-f-subject').val()
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
	function openModal(data) {
		$('#dze-ev-id').val(data.id || '');
		$('#dze-ev-name').val(data.title || '');
		$('#dze-ev-percent').val(data.percent || 10);
		$('#dze-ev-start').val(data.start || '');
		$('#dze-ev-end').val(data.end || '');
		$('#dze-ev-subject').val(data.subject || '');
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
			langs: $r.data('langs'), subject: $r.data('subject')
		});
	});
	$(document).on('click', '.dze-mai-new-event', function () { openModal({}); });
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
			email_subject: $('#dze-ev-subject').val()
		};
		if (pushGmc) {
			payload.push_gmc = 1;
			payload.gmc_targets = $('.dze-ev-gmc:checked').map(function () { return $(this).val(); }).get();
		}
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
