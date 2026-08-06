/* global dzeQueue, jQuery */
/**
 * Writing queue screen: watch the items go by, review what came back, accept
 * or discard. The work itself happens in the background — this page only asks
 * the server where it is up to.
 */
(function ($) {
	'use strict';

	var cfg = dzeQueue, i18n = cfg.i18n, timer = null, EDITOR = 'dze-q-editor';

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

	var LABELS = {
		queued: 'waiting', running: 'writing…', review: 'to review',
		applied: 'saved', failed: 'failed', skipped: 'discarded'
	};
	var COLORS = {
		queued: '#646970', running: '#2271b1', review: '#8a6d00',
		applied: '#0a7040', failed: '#b32d2e', skipped: '#787c82'
	};

	function draw(res) {
		var rows = res.rows || [], c = res.counts || {};
		var $b = $('#dze-q-table tbody').empty();
		if (!rows.length) {
			$b.append('<tr><td colspan="4">' + esc('Nothing in the queue.') + '</td></tr>');
		}
		rows.forEach(function (r) {
			var act = '';
			if (r.status === 'review') {
				act = '<button type="button" class="button button-small dze-q-open" data-id="' + r.id + '">Review</button>';
			} else if (r.status === 'failed') {
				act = '<span class="description">' + esc(r.error || '') + '</span>';
			} else if (r.status === 'running') {
				act = '<span class="dze-cx-spin"></span>';
			}
			$b.append(
				'<tr><td><strong>' + esc(r.label) + '</strong></td><td>' + esc(r.kind) + '</td>' +
				'<td style="color:' + (COLORS[r.status] || '#000') + ';">' + esc(LABELS[r.status] || r.status) + '</td>' +
				'<td>' + act + '</td></tr>'
			);
		});
		$('#dze-q-counts').text(
			(c.queued || 0) + ' waiting · ' + (c.review || 0) + ' to review · ' +
			(c.applied || 0) + ' saved · ' + (c.failed || 0) + ' failed'
		);
		// Keep watching only while there is something moving.
		if (timer && !(c.queued || c.running)) { stopWatch(); }
	}

	function refresh() {
		return $.post(cfg.ajaxUrl, { action: 'dze_q_status', nonce: cfg.nonce })
			.done(function (res) { if (res && res.success) { draw(res.data); } });
	}

	function startWatch() {
		if (timer) { return; }
		$('#dze-q-watch').text('Stop watching');
		timer = window.setInterval(refresh, 3000);
		refresh();
	}
	function stopWatch() {
		window.clearInterval(timer);
		timer = null;
		$('#dze-q-watch').text('Watch progress');
	}

	$(function () {
		refresh();
		$('#dze-q-watch').on('click', function () { return timer ? stopWatch() : startWatch(); });
		$('#dze-q-kick').on('click', function () {
			var $b = $(this).prop('disabled', true);
			// Takes one item, then hands over to the background worker.
			$.post(cfg.ajaxUrl, { action: 'dze_q_run', nonce: cfg.nonce })
				.always(function () { $b.prop('disabled', false); refresh(); });
			startWatch();
		});
		$('#dze-q-clear').on('click', function () {
			if (!window.confirm(i18n.confirm)) { return; }
			$.post(cfg.ajaxUrl, { action: 'dze_q_clear', nonce: cfg.nonce }).always(refresh);
		});
	});

	// ---- Review ----
	$(document).on('click', '.dze-q-open', function () {
		var id = $(this).data('id');
		$('#dze-q-body').html('<p><span class="dze-cx-spin"></span></p>');
		$('#dze-q-modal').addClass('is-open');
		$.post(cfg.ajaxUrl, { action: 'dze_q_review', nonce: cfg.nonce, id: id })
			.done(function (res) {
				if (!res || !res.success) { $('#dze-q-body').text(i18n.error); return; }
				var d = res.data;
				$('#dze-q-title').text(d.title);
				$('#dze-q-body').html(
					'<p class="description">' + esc(d.words[0] + ' words → ' + d.words[1] + ' words') + '</p>' +
					'<textarea id="' + EDITOR + '"></textarea>' +
					'<p style="margin-top:10px;">' +
					'<button type="button" class="button button-primary dze-q-accept" data-id="' + d.id + '">Accept and save</button> ' +
					'<button type="button" class="button dze-q-discard" data-id="' + d.id + '">Discard</button> ' +
					'<span class="dze-q-status description"></span></p>'
				);
				$('#' + EDITOR).val(d.html);
				if (window.wp && wp.editor && wp.editor.initialize) {
					try { wp.editor.remove(EDITOR); } catch (e) {}
					wp.editor.initialize(EDITOR, {
						tinymce: { wpautop: true, toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo' },
						quicktags: true, mediaButtons: false
					});
				}
			})
			.fail(function () { $('#dze-q-body').text(i18n.error); });
	});

	function editorGet() {
		if (window.tinymce && tinymce.get(EDITOR) && !tinymce.get(EDITOR).isHidden()) {
			return tinymce.get(EDITOR).getContent();
		}
		return $('#' + EDITOR).val() || '';
	}

	function decide(id, accept, $st) {
		$st.text(accept ? i18n.applying : '');
		$.post(cfg.ajaxUrl, {
			action: 'dze_q_decide', nonce: cfg.nonce, id: id,
			accept: accept ? 1 : 0, html: accept ? editorGet() : ''
		}).done(function (res) {
			if (!res || !res.success) { $st.text(i18n.error); return; }
			$st.text(accept ? i18n.applied : i18n.discarded);
			refresh();
			window.setTimeout(function () { $('#dze-q-modal').removeClass('is-open'); }, 600);
		}).fail(function () { $st.text(i18n.error); });
	}

	$(document).on('click', '.dze-q-accept', function () {
		decide($(this).data('id'), true, $(this).closest('p').find('.dze-q-status'));
	});
	$(document).on('click', '.dze-q-discard', function () {
		decide($(this).data('id'), false, $(this).closest('p').find('.dze-q-status'));
	});
	$(document).on('click', '.dze-hub-close', function () { $(this).closest('.dze-cx-modal').removeClass('is-open'); });
	$(document).on('click', '#dze-q-modal', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });

}(jQuery));
