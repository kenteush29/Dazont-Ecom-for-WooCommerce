/* global dzeQueue, jQuery */
/**
 * Content to review screen: watch the items go by, review what came back, accept
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

	// From PHP, so a shop reads them in its own language and there is ONE
	// place they are written. Hard-coded here they were English on every shop.
	var LABELS = {
		queued: i18n.sQueued, running: i18n.sRunning, review: i18n.sReview,
		applied: i18n.sApplied, failed: i18n.sFailed, skipped: i18n.sSkipped
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
			// A row that has just arrived must SAY it has arrived. Queued, it
			// showed a bare cross and nothing else — the owner read it as an
			// empty, dead line and could not tell whether anything had
			// happened at all.
			if (r.status === 'running' || r.status === 'queued') {
				act.push('<span class="dze-cx-spin"></span>');
				act.push('<span class="description">' + esc(LABELS[r.status]) + '</span>');
			}
			if (r.status === 'review') {
				act.push('<button type="button" class="button button-small dze-q-open" data-id="' + r.id + '">' + esc(i18n.review) + '</button>');
				// Accept and refuse on the line, the same two symbols as the
				// products screen: reading the text before deciding is a choice,
				// not a toll on the way to the decision.
				act.push('<button type="button" class="dze-cb-yes dze-q-yes" data-id="' + r.id + '" title="' + esc(i18n.acceptOne) + '">✓</button>');
			} else if (r.status !== 'queued' && r.status !== 'running') {
				act.push('<button type="button" class="button button-small dze-q-retry" data-id="' + r.id + '">' + esc(i18n.retry) + '</button>');
			}
			// ONE cross, on every line, meaning the one thing it should: get rid
			// of this. On a finished text that is a refusal, anywhere else it is
			// a line dropped from the queue. The word "Remove" beside it said
			// the same thing twice.
			act.push('<button type="button" class="dze-cb-no dze-q-no" data-id="' + r.id + '" data-status="' + esc(r.status) +
				'" title="' + esc(r.status === 'review' ? i18n.refuseOne : i18n.dropOne) + '">✗</button>');
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
		// "Make another": the same job, run again. It is the retry the list
		// already has, pressed from the picture rather than from the row — one
		// action, not a second way of asking for the same thing.
		$(document).on('click', '.dze-q-again', function () {
			var $b = $(this).prop('disabled', true);
			paused = false;
			$.post(cfg.ajaxUrl, { action: 'dze_q_action', nonce: cfg.nonce, id: $b.data('id'), do: 'retry' })
				.always(function () {
					$b.prop('disabled', false);
					$('#dze-q-modal').removeClass('is-open');
					refresh();
				});
		});
		$(document).on('click', '.dze-q-retry', function () {
			var $b = $(this).prop('disabled', true);
			paused = false;
			$.post(cfg.ajaxUrl, { action: 'dze_q_action', nonce: cfg.nonce, id: $b.data('id'), do: 'retry' })
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
				// A photograph is looked at, not read. Its own review: the new
				// one big enough to judge, the ones the product already has
				// beside it, and the two answers. Nothing else — a text editor
				// under an image is a screen that has stopped meaning anything.
				if (d.image) {
					$('#dze-q-prompt').hide();
					$('#dze-q-body').html(
						'<div class="dze-q-shot">' +
							'<img src="' + esc(d.shot) + '" alt="" class="dze-hzoom" data-full="' + esc(d.shot) + '" ' +
								'style="display:block;max-width:100%;height:auto;border:1px solid #dcdcde;" />' +
						'</div>' +
						((d.has && d.has.length)
							? '<p class="description" style="margin:10px 0 4px;">' + esc(i18n.alreadyHas) + '</p>' +
								'<div class="dze-zoomgroup" style="display:flex;gap:6px;flex-wrap:wrap;">' +
								d.has.map(function (u) {
									return '<span><img src="' + esc(u) + '" alt="" class="dze-hzoom" data-full="' + esc(u) +
										'" style="width:64px;height:64px;object-fit:cover;border:1px solid #dcdcde;" /></span>';
								}).join('') + '</div>'
							: '') +
						'<p style="margin-top:12px;">' +
						'<button type="button" class="button button-primary dze-q-accept" data-id="' + d.id + '">' + esc(i18n.keepShot) + '</button> ' +
						'<button type="button" class="button dze-q-again" data-id="' + d.id + '">' + esc(i18n.againShot) + '</button> ' +
						'<button type="button" class="button dze-q-discard" data-id="' + d.id + '">' + esc(i18n.dropShot) + '</button> ' +
						(d.edit ? '<a class="button-link" style="margin-left:8px;" href="' + esc(d.edit) + '" target="_blank" rel="noopener noreferrer">' + esc(i18n.openProduct) + '</a> ' : '') +
						'<span class="dze-q-status description"></span></p>'
					);
					return;
				}
				// The prompt behind this job, next to its name.
				$('#dze-q-prompt').attr('data-prompt', d.prompt || '').toggle(!!d.prompt);
				// What the category holds today, one click away above the new
				// text — the same answer as the products screen, rather than two
				// word counts and a leap of faith.
				var current = d.current
					? '<div class="dze-cb-nowtext" id="dze-q-now" style="display:none;">' +
						'<span class="dze-cb-nowlabel">' + esc(i18n.nowText) + ' — ' +
							esc(sprintf(i18n.words, d.words[0])) + '</span>' +
						'<div class="dze-cb-nowbody">' + d.current + '</div></div>'
					: '';
				$('#dze-q-body').html(
					'<p class="dze-q-meta">' +
						'<span class="description">' + esc(sprintf(i18n.wordsTo, d.words[0], d.words[1])) + '</span> ' +
						// AND THE LINKS. On a linking pass the words do not
						// move at all, so the one figure on screen said
						// nothing about the only thing that changed.
						(d.links ? '<span class="description" style="margin-left:10px;">'
							+ esc(sprintf(i18n.linksTo, d.links[0], d.links[1])) + '</span> ' : '') +
						(d.current ? '<button type="button" class="button button-small" id="dze-q-nowbtn">' + esc(i18n.compare) + '</button>' : '') +
					'</p>' + current +
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

	// Accepting writes to the shop, so it always asks; refusing throws away
	// work already paid for, so it asks too.
	$(document).on('click', '.dze-q-yes', function () {
		if (!window.confirm(i18n.confirmOne)) { return; }
		var $b = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_q_decide', nonce: cfg.nonce, id: $b.data('id'), accept: 1, html: '' })
			.always(function () { $b.prop('disabled', false); refresh(); });
	});
	$(document).on('click', '.dze-q-no', function () {
		var $b = $(this), review = $b.data('status') === 'review';
		// A finished text is worth a question; an empty queued line is not.
		if (review && !window.confirm(i18n.confirmRefuse)) { return; }
		$b.prop('disabled', true);
		var data = review
			? { action: 'dze_q_decide', nonce: cfg.nonce, id: $b.data('id'), accept: 0, html: '' }
			: { action: 'dze_q_action', nonce: cfg.nonce, id: $b.data('id'), do: 'remove' };
		$.post(cfg.ajaxUrl, data).always(function () { $b.prop('disabled', false); refresh(); });
	});

	$(document).on('click', '#dze-q-nowbtn', function () {
		var $n = $('#dze-q-now').toggle();
		$(this).toggleClass('button-primary', $n.is(':visible'));
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
