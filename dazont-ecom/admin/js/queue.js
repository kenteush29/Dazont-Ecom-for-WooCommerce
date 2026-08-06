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
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%\d\$s|%s/g, function () { return args[i++]; });
	}

	var LABELS = {
		queued: 'waiting', running: 'writing…', review: 'to review',
		applied: 'saved', failed: 'failed', skipped: 'discarded'
	};
	var COLORS = {
		queued: '#646970', running: '#2271b1', review: '#8a6d00',
		applied: '#0a7040', failed: '#b32d2e', skipped: '#787c82'
	};

	// Ticks survive a refresh: the list redraws every few seconds while the
	// queue runs, and losing a selection mid-review would be maddening.
	var sel = {};

	function selection() {
		return Object.keys(sel).filter(function (k) { return sel[k]; });
	}
	function drawBulk() {
		var n = selection().length;
		$('#dze-q-bulkbar').toggle(n > 0);
		$('#dze-q-selcount').text(sprintf(i18n.selected, n));
	}

	function draw(res) {
		var rows = res.rows || [], c = res.counts || {};
		var $b = $('#dze-q-table tbody').empty();
		if (!rows.length) {
			$b.append('<tr><td colspan="5">' + esc(i18n.empty) + '</td></tr>');
		}
		rows.forEach(function (r) {
			// Every row can be acted on: a run that went wrong is retried or
			// dropped from here, never left with a spinner and no way out.
			var act = [];
			if (r.status === 'review') {
				act.push('<button type="button" class="button button-small dze-q-open" data-id="' + r.id + '">' + esc(i18n.review) + '</button>');
			}
			if (r.status === 'running') {
				act.push('<span class="dze-cx-spin"></span>');
			}
			if (r.status !== 'review') {
				act.push('<button type="button" class="button button-small dze-q-retry" data-id="' + r.id + '">' + esc(i18n.retry) + '</button>');
			}
			act.push('<button type="button" class="button-link dze-q-remove" data-id="' + r.id + '" style="color:#b32d2e;">' + esc(i18n.remove) + '</button>');
			var state = esc(LABELS[r.status] || r.status);
			if (r.status === 'running' && r.progress) { state += ' <span class="description">' + esc(r.progress) + '</span>'; }
			if (r.status === 'failed' && r.error) { state += '<br /><span class="description">' + esc(r.error) + '</span>'; }
			$b.append(
				'<tr><th scope="row" class="check-column"><input type="checkbox" class="dze-q-pick" value="' + r.id +
					'" data-status="' + esc(r.status) + '"' + (sel[r.id] ? ' checked' : '') + ' /></th>' +
				'<td><strong>' + esc(r.label) + '</strong></td><td>' + esc(r.kind) + '</td>' +
				'<td style="color:' + (COLORS[r.status] || '#000') + ';">' + state + '</td>' +
				'<td>' + act.join(' ') + '</td></tr>'
			);
		});
		var parts = [];
		if (c.queued) { parts.push(sprintf(i18n.cWaiting, c.queued)); }
		if (c.running) { parts.push(sprintf(i18n.cWriting, c.running)); }
		if (c.review) { parts.push(sprintf(i18n.cReview, c.review)); }
		if (c.applied) { parts.push(sprintf(i18n.cSaved, c.applied)); }
		if (c.failed) { parts.push(sprintf(i18n.cFailed, c.failed)); }
		$('#dze-q-counts').text(parts.length ? parts.join(' · ') : i18n.idle);

		// Pause is only offered while there is something to pause.
		var moving = (c.queued || c.running);
		$('#dze-q-pause').toggle(!!moving).text(paused ? i18n.resume : i18n.pause);

		// Clearing is only offered when there is something finished to clear.
		var finished = (c.applied || 0) + (c.failed || 0) + (c.skipped || 0);
		$('#dze-q-clear').toggle(finished > 0).text(sprintf(i18n.clearN, finished));

		drawBulk();
		// The page drives the queue on its own while anything is moving.
		if (moving && !paused) { startWatch(); } else { stopWatch(); }
	}

	var busy = false;
	function refresh() {
		if (paused) { return $.Deferred().resolve(); }
		return $.post(cfg.ajaxUrl, { action: 'dze_q_status', nonce: cfg.nonce })
			.done(function (res) {
				if (!res || !res.success) { return; }
				draw(res.data);
				// While this page is open it is the engine: one step per tick,
				// never two at once, so nothing waits on cron.
				var c = res.data.counts || {};
				if (!paused && !busy && (c.queued || c.running)) {
					busy = true;
					$.post(cfg.ajaxUrl, { action: 'dze_q_run', nonce: cfg.nonce })
						.always(function () { busy = false; refresh(); });
				}
			});
	}

	var paused = false;
	function startWatch() {
		if (timer) { return; }
		timer = window.setInterval(refresh, 3000);
	}
	function stopWatch() {
		window.clearInterval(timer);
		timer = null;
	}

	$(function () {
		refresh();
		$('#dze-q-pause').on('click', function () {
			paused = !paused;
			$(this).text(paused ? i18n.resume : i18n.pause);
			if (!paused) { refresh(); }
		});
		$(document).on('click', '.dze-q-retry', function () {
			var $b = $(this).prop('disabled', true);
			paused = false;
			$.post(cfg.ajaxUrl, { action: 'dze_q_action', nonce: cfg.nonce, id: $b.data('id'), do: 'retry' })
				.always(function () { $b.prop('disabled', false); refresh(); });
		});
		$(document).on('click', '.dze-q-remove', function () {
			var $b = $(this).prop('disabled', true);
			$.post(cfg.ajaxUrl, { action: 'dze_q_action', nonce: cfg.nonce, id: $b.data('id'), do: 'remove' })
				.always(function () { $b.prop('disabled', false); refresh(); });
		});
		$(document).on('change', '.dze-q-pick', function () {
			sel[this.value] = this.checked;
			drawBulk();
		});
		$(document).on('change', '#dze-q-all', function () {
			var on = this.checked;
			$('.dze-q-pick').each(function () {
				this.checked = on;
				sel[this.value] = on;
			});
			drawBulk();
		});
		$(document).on('click', '.dze-q-bulk', function () {
			var todo = $(this).data('do'), ids = selection();
			if (!ids.length) { return; }
			if (todo === 'accept' && !window.confirm(sprintf(i18n.confirmAccept, ids.length))) { return; }
			if ((todo === 'remove' || todo === 'discard') && !window.confirm(sprintf(i18n.confirmDrop, ids.length))) { return; }
			var $b = $('.dze-q-bulk').prop('disabled', true);
			$('#dze-q-bulkstatus').html('<span class="dze-cx-spin"></span>');
			$.post(cfg.ajaxUrl, { action: 'dze_q_bulk', nonce: cfg.nonce, do: todo, ids: ids })
				.done(function (res) {
					$b.prop('disabled', false);
					$('#dze-q-bulkstatus').text((res && res.data && res.data.message) || i18n.error);
					sel = {};
					$('#dze-q-all').prop('checked', false);
					refresh();
				})
				.fail(function () { $b.prop('disabled', false); $('#dze-q-bulkstatus').text(i18n.error); });
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
