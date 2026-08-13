/* global dzeContentBulk, jQuery, tinymce */
/**
 * Bulk generation runner.
 *
 * The screen is a LIST first: one line per product, one state symbol, a thin
 * progress bar, and green badges naming what was produced. Everything else —
 * the texts, the images, the decisions — lives behind "Review", closed until
 * asked for. A run of thirty products has to stay thirty lines, otherwise the
 * screen is unusable exactly when it matters most.
 *
 * What to generate is decided ONCE at the top of the page: fields, price,
 * image prompt, scene, how many attempts. Nothing is decided per row.
 */
(function ($) {
	'use strict';

	var cfg = dzeContentBulk, i18n = cfg.i18n;
	var MEM = 'dzeContentMem';

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
	function mem() { try { return JSON.parse(localStorage.getItem(MEM) || '{}'); } catch (e) { return {}; } }
	function saveMem(o) { try { localStorage.setItem(MEM, JSON.stringify(o)); } catch (e) {} }
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%\d\$s|%s/g, function () { return args[i++]; });
	}
	function reason(msg) {
		if (typeof msg === 'string' && msg) { return msg; }
		if (msg && msg.status) { return 'HTTP ' + msg.status + (msg.statusText ? ' ' + msg.statusText : ''); }
		return i18n.error;
	}
	function $row(id) { return $('.dze-cb-row[data-id="' + id + '"]'); }

	// ---- Remembered choices ----
	(function restore() {
		var m = mem();
		if (Array.isArray(m.bulkFields)) {
			$('.dze-cb-field:not(:disabled)').each(function () { this.checked = m.bulkFields.indexOf($(this).val()) >= 0; });
		}
		if (typeof m.bulkPrice !== 'undefined') { $('#dze-cb-price').prop('checked', !!m.bulkPrice); }
		if (typeof m.bulkImage !== 'undefined') { $('#dze-cb-image').prop('checked', !!m.bulkImage); }
		buildTplRows(Array.isArray(m.tpls) && m.tpls.length ? m.tpls : [ '' ]);
		// The scene is remembered with the toolbox (same store): pick a support
		// once and every screen keeps shooting on it.
		if (typeof m.scene !== 'undefined' && $('#dze-cb-scene option[value="' + m.scene + '"]').length) {
			$('#dze-cb-scene').val(String(m.scene));
		}
		if (typeof m.imgn !== 'undefined') { $('#dze-cb-imgn').val(String(m.imgn)); }
		if (typeof m.par !== 'undefined') { $('#dze-cb-par').val(String(m.par)); }
	}());

	function persist() {
		var m = mem();
		m.bulkFields = $('.dze-cb-field:checked:not(:disabled)').map(function () { return $(this).val(); }).get();
		m.bulkPrice = $('#dze-cb-price').is(':checked');
		m.bulkImage = $('#dze-cb-image').is(':checked');
		m.tpls = tpls();
		if ($('#dze-cb-scene').length) { m.scene = parseInt($('#dze-cb-scene').val(), 10); }
		if ($('#dze-cb-imgn').length) { m.imgn = parseInt($('#dze-cb-imgn').val(), 10); }
		if ($('#dze-cb-par').length) { m.par = parseInt($('#dze-cb-par').val(), 10); }
		saveMem(m);
	}
	$(document).on('change', '.dze-cb-field, #dze-cb-price, #dze-cb-image, .dze-cb-tpl, #dze-cb-scene, #dze-cb-imgn, #dze-cb-par, #dze-cb-reviews, #dze-cb-revn', persist);

	// Every block says what is ticked out of what it holds: "2 / 6" answers
	// "did I forget something?" without opening anything.
	function counts() {
		var pairs = [
			[ '.dze-cb-block:has(.dze-cb-field)', '.dze-cb-field', '.dze-cb-field:checked' ],
			[ '.dze-cb-block:has(#dze-cb-image)', '#dze-cb-image', '#dze-cb-image:checked' ],
			[ '.dze-cb-block:has(#dze-cb-price)', '#dze-cb-price', '#dze-cb-price:checked' ],
			[ '.dze-cb-block:has(#dze-cb-reviews)', '#dze-cb-reviews', '#dze-cb-reviews:checked' ]
		];
		pairs.forEach(function (p) {
			var $block = $(p[0]);
			if (!$block.length) { return; }
			var all = $block.find(p[1]).length, on = $block.find(p[2]).length;
			var $h = $block.find('h3');
			var $c = $h.find('.dze-cb-count');
			if (!$c.length) { $c = $('<span class="dze-cb-count"></span>').appendTo($h); }
			$c.text(on + ' / ' + all);
			$block.toggleClass('is-off', on === 0);
		});
	}
	$(document).on('change', '.dze-cb-field, #dze-cb-price, #dze-cb-image, #dze-cb-reviews', counts);
	$(counts);
	// One prompt by default, a + to add another when a product needs two kinds
	// of shot. Every row runs on every product of the list.
	function tplRow(value) {
		var $r = $($('#dze-cb-tpltpl').html());
		if (value !== '' && value !== undefined) { $r.find('.dze-cb-tpl').val(String(value)); }
		syncPeek($r);
		return $r;
	}
	// The peek button of a row always points at the prompt that row will run.
	function syncPeek($row) {
		var $s = $row.find('.dze-cb-tpl');
		$row.find('.dze-prompt-peek').attr('data-prompt', $s.find('option:selected').data('prompt') || '');
	}
	function buildTplRows(values) {
		var $wrap = $('#dze-cb-tplrows').empty();
		(values.length ? values : [ '' ]).forEach(function (v) { $wrap.append(tplRow(v)); });
		syncTplRows();
	}
	// A + that cannot add anything is a lie: it only shows while an unused
	// prompt is left, and − disappears when one row is left.
	function tplCount() { return $('#dze-cb-tpltpl').length ? $($('#dze-cb-tpltpl').html()).find('option').length : 0; }
	function syncTplRows() {
		var $rows = $('#dze-cb-tplrows .dze-tplrow');
		var room = $rows.length < tplCount();
		$rows.each(function (i) {
			$(this).find('.dze-tpl-add').toggle(room && i === $rows.length - 1);
			$(this).find('.dze-tpl-del').toggle($rows.length > 1);
		});
	}
	function firstFreeTpl() {
		var used = $('#dze-cb-tplrows .dze-cb-tpl').map(function () { return $(this).val(); }).get();
		var free = '';
		$($('#dze-cb-tpltpl').html()).find('option').each(function () {
			if (free === '' && used.indexOf($(this).val()) < 0) { free = $(this).val(); }
		});
		return free;
	}
	// Two rows on the same prompt would generate the same thing twice without
	// saying so: the duplicate falls back to a free one.
	$(document).on('change', '#dze-cb-tplrows .dze-cb-tpl', function () {
		var used = {}, $me = $(this);
		$('#dze-cb-tplrows .dze-cb-tpl').each(function () {
			var v = $(this).val();
			if (used[v] && this === $me[0]) { $me.val(firstFreeTpl()); }
			else if (used[v]) { $(this).val(firstFreeTpl()); }
			used[$(this).val()] = 1;
		});
		$('#dze-cb-tplrows .dze-tplrow').each(function () { syncPeek($(this)); });
	});
	$(document).on('click', '#dze-cb-tplrows .dze-tpl-add', function () {
		$('#dze-cb-tplrows').append(tplRow(firstFreeTpl()));
		syncTplRows();
		persist();
	});
	$(document).on('click', '#dze-cb-tplrows .dze-tpl-del', function () {
		$(this).closest('.dze-tplrow').remove();
		syncTplRows();
		persist();
	});
	function tpls() {
		var seen = {}, out = [];
		$('#dze-cb-tplrows .dze-cb-tpl').each(function () {
			var v = $(this).val();
			if (v !== null && !seen[v]) { seen[v] = 1; out.push(v); }
		});
		return out;
	}

	// =====================================================================
	// The list itself: take products out, or empty it
	// =====================================================================

	function picked() {
		return $('.dze-cb-pick:checked').map(function () { return $(this).val(); }).get();
	}
	function drawPicked() {
		var n = picked().length;
		$('#dze-cb-selcount').text(n ? sprintf(i18n.selected, n) : '');
		$('#dze-cb-unqueue').toggle(n > 0);
		refreshApplyBar();
	}
	$(document).on('change', '.dze-cb-pick', drawPicked);
	$(document).on('change', '#dze-cb-all', function () {
		$('.dze-cb-pick').prop('checked', this.checked);
		drawPicked();
	});
	function dropRows(ids) {
		ids.forEach(function (id) {
			$('.dze-cb-row[data-id="' + id + '"], .dze-cb-preview[data-id="' + id + '"]').remove();
			delete results[id];
			delete state[id];
		});
		drawPicked();
		if (!$('.dze-cb-row').length) { window.location.reload(); }
	}
	// The screen shows what the server holds, not what it hoped the server
	// would hold: rows only leave once the list has really been rewritten.
	function unqueue(ids, $b) {
		if ($b) { $b.prop('disabled', true); }
		$.post(cfg.ajaxUrl, { action: 'dze_content_bulk_list', nonce: cfg.nonce, do: 'remove', ids: ids })
			.done(function (res) {
				if (res && res.success) { dropRows(ids); }
				else { window.alert((res && res.data && res.data.message) || i18n.error); }
			})
			.fail(function (x) { window.alert(reason(x)); })
			.always(function () { if ($b) { $b.prop('disabled', false); } });
	}
	$('#dze-cb-unqueue').on('click', function () {
		var ids = picked();
		if (!ids.length) { return; }
		unqueue(ids, $(this));
	});
	$('#dze-cb-clearlist').on('click', function () {
		if (!window.confirm(i18n.confirmClear)) { return; }
		$.post(cfg.ajaxUrl, { action: 'dze_content_bulk_list', nonce: cfg.nonce, do: 'clear' })
			.always(function () { window.location.reload(); });
	});
	// One product out, from its own line.
	$(document).on('click', '.dze-cb-unqueue-one', function () {
		unqueue([ $(this).closest('.dze-cb-row').data('id') ], $(this));
	});

	// ---- A column of IDs, pasted from a spreadsheet ----
	// Anything that is not a digit separates: commas, tabs, line breaks, a
	// leading #. A spreadsheet column pastes as it comes.
	$('#dze-cb-pasteadd').on('click', function () {
		var $b = $(this), $st = $('#dze-cb-pastestate').removeClass('is-ko');
		var ids = ($('#dze-cb-pasteids').val() || '').split(/[^0-9]+/)
			.filter(function (v) { return v !== ''; })
			.map(function (v) { return parseInt(v, 10); });
		if (!ids.length) { $st.addClass('is-ko').text(i18n.pasteNone); return; }
		var replace = $('#dze-cb-pastereplace').is(':checked');
		if (replace && !window.confirm(i18n.pasteReplace)) { return; }
		$b.prop('disabled', true);
		$st.text(i18n.working);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_bulk_list', nonce: cfg.nonce, do: 'add', ids: ids, replace: replace ? 1 : 0
		})
			.done(function (res) {
				if (!res || !res.success) {
					$b.prop('disabled', false);
					$st.addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				var d = res.data;
				// Nothing is swallowed: what could not be added is named before
				// the page reloads on the new list.
				if (d.unknownN) {
					window.alert(sprintf(i18n.pasteUnknown, d.unknownN) + '\n\n' + d.unknown.join(', ') +
						(d.unknownN > d.unknown.length ? ' …' : ''));
				}
				// Always land on the selection itself, even when the paste was
				// done from the "waiting for a decision" view.
				window.location.href = cfg.listUrl || window.location.href;
			})
			.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});

	// =====================================================================
	// Per-product state: one symbol, one bar, one tooltip
	// =====================================================================

	var stopped = false, okCount = 0, koCount = 0, doneCount = 0, total = 0;
	var state = {};   // id => { total, done, notes: [], failed: bool }
	var results = {}; // id => { texts, shots, built, open }

	var SYMBOL = { wait: '○', run: '', ready: '✓', done: '✓', fail: '✗' };

	function plan(id, tasks) {
		state[id] = { total: tasks, done: 0, notes: [], failed: false };
		paint(id, 'wait');
	}
	function note(id, text, bad) {
		var st = state[id];
		if (!st) { return; }
		if (text) { st.notes.push(text); }
		if (bad) { st.failed = true; }
	}
	function step(id) {
		var st = state[id];
		if (!st) { return; }
		st.done++;
		paint(id, st.done >= st.total ? null : 'run');
	}
	// null = decide from what happened.
	function paint(id, force) {
		var st = state[id] || { total: 1, done: 0, notes: [], failed: false };
		var kind = force;
		if (!kind) { kind = st.failed ? 'fail' : (reviewMode ? 'ready' : 'done'); }
		var pct = st.total ? Math.round(100 * st.done / st.total) : 0;
		var $c = $row(id).find('.dze-cb-statuscell');
		var label = { wait: i18n.sWait, run: i18n.sRun, ready: i18n.sReady, done: i18n.sDone, fail: i18n.sFail }[kind];
		$c.find('.dze-cb-state')
			.removeClass('is-wait is-run is-ready is-done is-fail')
			.addClass('is-' + kind)
			.text(SYMBOL[kind])
			.attr('title', label + (st.notes.length ? ' — ' + st.notes.join(' · ') : ''));
		// The thin bar only exists while there is something to watch.
		var running = kind === 'run';
		$c.find('.dze-cb-rowbar').toggle(running).find('i').css('width', pct + '%');
		$c.find('.dze-cb-rowpct').toggle(running).text(pct + '%');
	}

	// One badge per piece of content actually produced, on the product line.
	function badge(id, key, label) {
		var $wrap = $row(id).find('.dze-cb-badges');
		var $b = $wrap.find('[data-k="' + key + '"]');
		if (!$b.length) { $b = $('<span class="dze-cb-badge" data-k="' + esc(key) + '"></span>').appendTo($wrap); }
		$b.html(esc(label) + ' <span class="dze-cb-badgecheck">✓</span>');
	}
	function offerReview(id) {
		$row(id).find('.dze-cb-toggle, .dze-cb-yes, .dze-cb-no').show();
	}
	function hideRowActions(id) {
		$row(id).find('.dze-cb-toggle, .dze-cb-yes, .dze-cb-no').hide();
	}
	// Accept and refuse, from the line. Accepting always asks first — it writes
	// to the shop; refusing asks too, because it throws away work already paid
	// for.
	$(document).on('click', '.dze-cb-yes', function () {
		if (!window.confirm(i18n.confirmOne)) { return; }
		applyProducts([ $(this).closest('.dze-cb-row').data('id') ], $(this));
	});
	$(document).on('click', '.dze-cb-no', function () {
		if (!window.confirm(i18n.confirmDrop)) { return; }
		var id = $(this).closest('.dze-cb-row').data('id');
		$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: id });
		delete results[id];
		$('.dze-cb-preview[data-id="' + id + '"]').hide().find('td').empty();
		$row(id).find('.dze-cb-badges').empty();
		hideRowActions(id);
		state[id] = { total: 1, done: 1, notes: [], failed: false };
		paint(id, 'wait');
		refreshApplyBar();
	});

	// ---- Global progress, pinned to the bottom of the window ----
	function progress(label) {
		doneCount++;
		var pct = total ? Math.round(100 * doneCount / total) : 0;
		$('.dze-cb-fill').css('width', pct + '%');
		$('#dze-cb-stickypct').text(pct + '%');
		$('#dze-cb-stickytext').text(sprintf(i18n.progress, doneCount, total, label || ''));
		$('#dze-cb-progress').text(sprintf(i18n.progress, doneCount, total, label || ''));
	}

	// =====================================================================
	// The three kinds of work
	// =====================================================================

	var reviewMode = true;

	function textAllTask(id, fids, review) {
		var data = { action: 'dze_content_text_all', nonce: cfg.nonce, post: id, fields: fids };
		// Reviewed content is kept on the product, not in this tab: a closed
		// window must not throw away what has just been paid for.
		if (review) { data.stash = 1; } else { data.apply = 1; }
		return $.post(cfg.ajaxUrl, data)
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				if (review) {
					storeTexts(id, fids, res.data.texts || {});
					note(id, fids.length + ' ' + i18n.tText.toLowerCase());
					return;
				}
				var r = res.data.results || {}, done = 0;
				fids.forEach(function (fid) {
					if (r[fid] === 'applied') { okCount++; done++; badge(id, fid, cfg.fields[fid] || fid); }
					else { koCount++; }
				});
				note(id, sprintf(i18n.partial, done, fids.length), done < fids.length);
			})
			.catch(function (msg) { koCount++; note(id, i18n.tText + ': ' + reason(msg), true); })
			.always(function () { step(id); progress(i18n.tText); });
	}

	function priceTask(id) {
		var cost = $row(id).find('.dze-cb-cost').val();
		return $.post(cfg.ajaxUrl, { action: 'dze_content_price', nonce: cfg.nonce, post: id, cost: cost })
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				okCount++;
				// A variable product reports the range and how many variations
				// were repriced — one figure would be a half-truth.
				var label = res.data.regular + (res.data.variations ? ' ×' + res.data.variations : '');
				note(id, i18n.tPrice + ' ' + label);
				badge(id, 'price', i18n.tPrice + ' ' + label);
			})
			.catch(function (msg) { koCount++; note(id, i18n.tPrice + ': ' + reason(msg), true); })
			.always(function () { step(id); progress(i18n.tPrice); });
	}

	// The prompt, the scene and the count come from the top of the page: one
	// decision for the run, not one per line.
	function imageRequest(id, review, tpl) {
		var data = { action: 'dze_content_image', nonce: cfg.nonce, post: id, template: tpl };
		if (review) { data.mode = 'defer'; data.stash = 1; }
		var $sc = $('#dze-cb-scene');
		if ($sc.length) { data.scene = parseInt($sc.val(), 10); }
		return data;
	}
	function oneImage(id, review, tpl) {
		return $.post(cfg.ajaxUrl, imageRequest(id, review, tpl))
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				okCount++;
				if (review) {
					addShot(id, res.data.url, tpl);
				} else {
					badge(id, 'img', i18n.imgBadge);
					if (res.data.url) { $row(id).find('.dze-cb-thumb img').attr('src', res.data.url).attr('data-full', res.data.url); }
				}
			})
			.catch(function (msg) { koCount++; note(id, i18n.tImage + ': ' + reason(msg), true); })
			.always(function () { step(id); progress(i18n.tImage); });
	}
	// N attempts on the same product, one after the other — the provider is
	// slow enough that firing four at once is how a run times out.
	// Every ticked prompt × every attempt, one after the other: the provider is
	// slow enough that firing them together is how a run times out.
	function imageTask(id, review, n, list) {
		var jobs = [], d = $.Deferred();
		list.forEach(function (tpl) {
			for (var k = 0; k < Math.max(1, n); k++) { jobs.push(tpl); }
		});
		var i = 0;
		(function next() {
			if (stopped || i >= jobs.length) { d.resolve(); return; }
			oneImage(id, review, jobs[i++]).always(next);
		})();
		return d;
	}

	// =====================================================================
	// Review: collapsed rows, opened one at a time
	// =====================================================================

	function bucket(id) {
		if (!results[id]) { results[id] = { texts: {}, shots: [], built: false, open: {}, shotOf: {} }; }
		return results[id];
	}
	function previewCell(id) {
		return $('.dze-cb-preview[data-id="' + id + '"]').find('td');
	}
	function editorId(id, fid) { return 'dze-cb-ed-' + id + '-' + String(fid).replace(/[^a-zA-Z0-9_-]/g, ''); }
	function isRich(fid) { return !!(cfg.rich && cfg.rich[fid]); }
	function peek(html) {
		var t = $('<div>').html(html || '').text().replace(/\s+/g, ' ').trim();
		return t ? (t.length > 110 ? t.slice(0, 110) + '…' : t) : i18n.empty;
	}

	function storeTexts(id, fids, texts, companions) {
		var b = bucket(id);
		b.shotOf = companions || {};
		fids.forEach(function (fid) {
			// A block written against a photograph that was not produced simply
			// is not there — the server drops it when the gallery is too thin.
			if (typeof texts[fid] === 'undefined') { return; }
			b.texts[fid] = texts[fid] || '';
			badge(id, fid, cfg.fields[fid] || fid);
		});
		offerReview(id);
		refreshApplyBar();
	}
	function addShot(id, url, tpl) {
		if (!url) { return; }
		var b = bucket(id);
		if (b.shots.indexOf(url) < 0) { b.shots.push(url); }
		b.shotTpl = b.shotTpl || {};
		if (tpl !== undefined) { b.shotTpl[url] = tpl; }
		badge(id, 'img', i18n.imgBadge + ' ×' + b.shots.length);
		offerReview(id);
		refreshApplyBar();
		if (b.built) { renderShots(id); }
	}

	// The panel is a list of shut drawers. Each one shows the field name and
	// the first line of what was written — enough to judge without opening —
	// and the editor is only started when the drawer is.
	function buildPanel(id) {
		var b = bucket(id), $cell = previewCell(id);
		if (b.built) { return; }
		var html = '<div class="dze-cb-prev">';
		Object.keys(b.texts).forEach(function (fid) {
			var comp = (b.shotOf || {})[fid];
			html += '<div class="dze-cb-fblock" data-field="' + fid + '">' +
				'<div class="dze-cb-fhead" role="button" tabindex="0" aria-expanded="false">' +
					// Accepting is not all or nothing: untick a block and it is
					// simply not written — the images, or the other texts, still
					// are. Same gesture as the tick on a generated image.
					'<input type="checkbox" class="dze-cb-fkeep" checked title="' + esc(i18n.keepHelp) + '" />' +
					'<span class="dze-cb-fcaret">▸</span>' +
					// The photograph this text was written against, so the pairing
					// is visible before anything is saved.
					(comp && comp.thumb
						? '<img class="dze-cb-fshot dze-hzoom" src="' + esc(comp.thumb) + '" data-full="' + esc(comp.full || comp.thumb) + '" alt="" title="' + esc(comp.feature || '') + '" />'
						: '') +
					'<span class="dze-cb-fname">' + esc(cfg.fields[fid] || fid) + '</span>' +
					'<span class="dze-cb-fpeek">' + esc(peek(b.texts[fid])) + '</span>' +
					'<span class="dze-cb-fstate"></span>' +
					'<button type="button" class="button button-small dze-cb-now" data-field="' + fid + '" title="' + esc(i18n.compareHelp) + '">' + esc(i18n.compare) + '</button>' +
					'<button type="button" class="button button-small dze-cb-redo" data-field="' + fid + '" title="' + esc(i18n.redoOne) + '">↻ ' + esc(i18n.redoShort) + '</button>' +
					// The instructions this text came out of, one click away.
					'<button type="button" class="dze-prompt-peek" data-prompt="content_' + esc(fid) + '" title="' + esc(i18n.promptTip) + '">&#9998;</button>' +
				'</div>' +
				'<div class="dze-cb-fbody" style="display:none;"></div>' +
			'</div>';
		});
		html += '</div>' +
			'<div class="dze-cb-nowshots"></div>' +
			'<p class="dze-cb-panelbar">' +
				(Object.keys(b.texts).length
					? '<button type="button" class="button button-small dze-cb-redoall">↻ ' + esc(i18n.redoAll) + '</button> ' : '') +
				oneMoreButtons() +
				'<button type="button" class="button button-small button-primary dze-cb-applyone">' + esc(i18n.applyOne) + '</button> ' +
				'<button type="button" class="button-link dze-cb-drop">' + esc(i18n.discard) + '</button>' +
				'<span class="dze-cb-panelstate"></span>' +
			'</p>' +
			'<div class="dze-cb-shots-slot"></div>';
		$cell.html(html);
		b.built = true;
		renderShots(id);
		// The gallery as it stands today, right under the new images: the only
		// way to judge whether a generated shot ADDS something.
		loadCurrent(id).then(function () { renderCurrentImages(id); });
	}

	function openField(id, fid, open) {
		var b = bucket(id);
		var $block = $('.dze-cb-preview[data-id="' + id + '"]').find('.dze-cb-fblock[data-field="' + fid + '"]');
		var $body = $block.find('.dze-cb-fbody');
		$block.toggleClass('is-open', open);
		$block.find('.dze-cb-fhead').attr('aria-expanded', open ? 'true' : 'false');
		$block.find('.dze-cb-fcaret').text(open ? '▾' : '▸');
		if (!open) {
			// Keep what is in the editor before it goes out of sight.
			if (b.open[fid]) { b.texts[fid] = editorGet(editorId(id, fid)); }
			$block.find('.dze-cb-fpeek').text(peek(b.texts[fid]));
			$body.hide();
			return;
		}
		$body.show();
		if (b.open[fid]) { return; }
		b.open[fid] = true;
		var eid = editorId(id, fid);
		$body.html(isRich(fid)
			? '<textarea id="' + eid + '" class="dze-cb-ed"></textarea>'
			: '<textarea id="' + eid + '" class="dze-cb-plain" rows="3"></textarea>');
		$('#' + eid).val(b.texts[fid] || '');
		if (isRich(fid) && window.wp && wp.editor && wp.editor.initialize) {
			try { wp.editor.remove(eid); } catch (e) {}
			wp.editor.initialize(eid, {
				tinymce: { wpautop: true, toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo', height: 220 },
				quicktags: true,
				mediaButtons: false
			});
		}
	}

	// Each generated image says where IT goes, on the image itself. One
	// destination for the batch could not express "this one is the main image,
	// that one goes second", which is the decision actually being made in
	// front of the strip.
	function destLabel(v) {
		return v === 'main' ? i18n.toMain : (v === 'gallery_first' ? i18n.toGalleryFirst : i18n.toGallery);
	}
	function shotCard(id, url, cur) {
		var b = bucket(id);
		var tpl = b.shotTpl ? b.shotTpl[url] : undefined;
		var name = (cfg.templates[parseInt(tpl, 10)] || {}).name || '';
		return $('<div class="dze-cb-shotwrap"></div>').attr('data-url', url).append(
			$('<div class="dze-cb-shot"><span class="dze-cb-shotcheck">✓</span></div>')
				.attr('data-url', url)
				.append(
					$('<img class="dze-hzoom" />').attr('src', url).attr('data-full', url).attr('alt', ''),
					$('<span class="dze-cb-shotbar"></span>').append(
						$('<button type="button" class="dze-cb-shotpos"></button>')
							.attr('title', i18n.shotPos).text(destLabel(cur)),
						$('<button type="button" class="dze-cb-shotredo">↻</button>')
							.attr('title', name ? sprintf(i18n.shotRedoOne, name) : i18n.shotRedo)
					),
					$('<input type="hidden" class="dze-cb-shotdest" />').val(cur)
				)
		);
	}
	function renderShots(id) {
		var b = bucket(id), $slot = previewCell(id).find('.dze-cb-shots-slot');
		// The same image can reach the strip twice — restored from an earlier
		// run and generated again in this one. It is one image either way.
		b.shots = b.shots.filter(function (u, i) { return b.shots.indexOf(u) === i; });
		if (!$slot.length || !b.shots.length) { return; }
		// Adding one more attempt redraws the strip: what was already ticked or
		// unticked, and where each was headed, survives the redraw.
		var $old = $slot.find('.dze-cb-shots');
		var dropped = {}, dest = {};
		if ($old.length) {
			$old.find('.dze-cb-shot').each(function () {
				var u = $(this).data('url');
				if (!$(this).hasClass('is-sel')) { dropped[u] = true; }
				dest[u] = $(this).find('.dze-cb-shotdest').val();
			});
		}
		var $wrap = $('<div class="dze-cb-shots"><div class="dze-cb-shotgrid dze-zoomgroup"></div>' +
			'<span class="dze-cb-shotstate"></span></div>');
		b.shots.forEach(function (url) {
			$wrap.find('.dze-cb-shotgrid').append(
				shotCard(id, url, dest[url] || 'gallery')
					.find('.dze-cb-shot').toggleClass('is-sel', !dropped[url]).end()
			);
		});
		$slot.empty().append($wrap);
	}
	// One click walks the three destinations. Only one image can be the main
	// one; claiming it moves the previous claimant back to the gallery instead
	// of letting the server arbitrate silently.
	$(document).on('click', '.dze-cb-shots .dze-cb-shotpos', function (e) {
		e.stopPropagation();
		var $in = $(this).closest('.dze-cb-shot').find('.dze-cb-shotdest');
		var order = [ 'gallery', 'gallery_first', 'main' ];
		var next = order[(order.indexOf($in.val()) + 1) % order.length];
		$in.val(next);
		$(this).text(destLabel(next));
		if ('main' !== next) { return; }
		var $me = $in;
		$me.closest('.dze-cb-shots').find('.dze-cb-shotdest').not($me).each(function () {
			if ($(this).val() === 'main') {
				$(this).val('gallery');
				$(this).closest('.dze-cb-shot').find('.dze-cb-shotpos').text(destLabel('gallery'));
			}
		});
	});
	// A fresh attempt at THIS image, with the recipe that made it.
	$(document).on('click', '.dze-cb-shots .dze-cb-shotredo', function (e) {
		e.stopPropagation();
		var $btn = $(this).prop('disabled', true);
		var $card = $btn.closest('.dze-cb-shot').addClass('is-busy');
		var id = $btn.closest('.dze-cb-preview').data('id');
		var b = bucket(id), url = $card.data('url');
		var tpl = b.shotTpl ? b.shotTpl[url] : undefined;
		if (tpl === undefined) { tpl = tpls()[0] !== undefined ? tpls()[0] : '0'; }
		var $st = $card.closest('.dze-cb-shots').find('.dze-cb-shotstate').removeClass('is-ko').text(i18n.working);
		$.post(cfg.ajaxUrl, imageRequest(id, true, tpl))
			.done(function (r) {
				if (!r || !r.success) {
					$btn.prop('disabled', false); $card.removeClass('is-busy');
					$st.addClass('is-ko').text(reason((r && r.data && r.data.message) || i18n.error));
					return;
				}
				var i = b.shots.indexOf(url);
				if (i >= 0) { b.shots[i] = r.data.url; } else { b.shots.push(r.data.url); }
				b.shotTpl = b.shotTpl || {};
				b.shotTpl[r.data.url] = tpl;
				delete b.shotTpl[url];
				$st.text('');
				renderShots(id);
			})
			.fail(function (x) {
				$btn.prop('disabled', false); $card.removeClass('is-busy');
				$st.addClass('is-ko').text(reason(x));
			});
	});

	// ---- What the product says today ----
	// Loaded when a panel opens, never with the list: it is one product's worth
	// of data, asked for at the moment somebody wants to compare.
	function loadCurrent(id) {
		var b = bucket(id);
		if (b.current) { return $.Deferred().resolve(b.current); }
		return $.post(cfg.ajaxUrl, { action: 'dze_content_current', nonce: cfg.nonce, post: id })
			.then(function (res) {
				b.current = (res && res.success) ? res.data : { texts: {}, images: [] };
				return b.current;
			}, function () { b.current = { texts: {}, images: [] }; return b.current; });
	}
	// The same block as the product screen, from the same renderer: sizes and
	// shapes under each photograph, the main image apart from the gallery, the
	// zoom, and the reframe lane. No AI button here — the bulk screen has no
	// per-product image popup to open.
	function renderCurrentImages(id) {
		var b = bucket(id), $slot = previewCell(id).find('.dze-cb-nowshots');
		if (!$slot.length || !b.current || !window.dzePhotos) { return; }
		window.dzePhotos.render($slot, b.current.images || [], {
			post: id,
			after: function () {
				b.current = null;
				loadCurrent(id).then(function () { renderCurrentImages(id); });
			}
		});
	}

	function editorGet(eid) {
		if (window.tinymce && tinymce.get(eid) && !tinymce.get(eid).isHidden()) { return tinymce.get(eid).getContent(); }
		return $('#' + eid).val() || '';
	}
	// What a field holds right now, opened or not.
	function valueOf(id, fid) {
		var b = bucket(id);
		return b.open[fid] ? editorGet(editorId(id, fid)) : (b.texts[fid] || '');
	}

	// Waiting content you no longer want. The only other way out was to accept
	// it, which is not a choice.
	$(document).on('click', '.dze-cb-drop', function () {
		if (!window.confirm(i18n.confirmDrop)) { return; }
		var id = $(this).closest('.dze-cb-preview').data('id');
		$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: id });
		delete results[id];
		$('.dze-cb-preview[data-id="' + id + '"]').hide().find('td').empty();
		$row(id).find('.dze-cb-badges').empty();
		$row(id).find('.dze-cb-toggle').hide();
		state[id] = { total: 1, done: 1, notes: [], failed: false };
		paint(id, 'wait');
		refreshApplyBar();
	});

	$(document).on('click', '.dze-cb-toggle', function () {
		var $btn = $(this), id = $btn.closest('.dze-cb-row').data('id');
		var $prev = $('.dze-cb-preview[data-id="' + id + '"]');
		var open = $prev.is(':visible');
		if (!open) { buildPanel(id); }
		$prev.toggle(!open);
		$btn.attr('aria-expanded', open ? 'false' : 'true').find('.dze-cb-caret').text(open ? '▾' : '▴');
	});
	// The current text, side by side with the new one, read-only.
	$(document).on('click', '.dze-cb-now', function (e) {
		e.stopPropagation();
		var $btn = $(this), fid = $btn.data('field');
		var id = $btn.closest('.dze-cb-preview').data('id');
		var $block = $btn.closest('.dze-cb-fblock');
		var $body = $block.find('.dze-cb-fbody');
		if ($block.hasClass('is-comparing')) {
			$block.removeClass('is-comparing').find('.dze-cb-nowtext').remove();
			$btn.removeClass('button-primary');
			return;
		}
		if (!$block.hasClass('is-open')) { openField(id, fid, true); }
		$btn.addClass('button-primary');
		$block.addClass('is-comparing');
		$body.prepend('<div class="dze-cb-nowtext"><span class="dze-cb-nowlabel">' + esc(i18n.nowText) + '</span><div class="dze-cb-nowbody">…</div></div>');
		loadCurrent(id).then(function (cur) {
			var val = (cur.texts || {})[fid] || '';
			$block.find('.dze-cb-nowbody').html(val ? $('<div>').html(val).html() : esc(i18n.empty));
		});
	});

	// Dropping a block greys the whole line, so what will NOT be written is
	// readable without opening anything.
	$(document).on('change', '.dze-cb-fkeep', function (e) {
		e.stopPropagation();
		var $block = $(this).closest('.dze-cb-fblock');
		$block.toggleClass('is-dropped', !$(this).is(':checked'));
		refreshApplyBar();
	});
	$(document).on('click', '.dze-cb-fhead', function (e) {
		if ($(e.target).closest('.dze-cb-redo, .dze-cb-now, .dze-cb-fkeep, .dze-prompt-peek').length) { return; }
		var $h = $(this), id = $h.closest('.dze-cb-preview').data('id');
		openField(id, $h.closest('.dze-cb-fblock').data('field'), !$h.closest('.dze-cb-fblock').hasClass('is-open'));
	});
	$(document).on('keydown', '.dze-cb-fhead', function (e) {
		if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $(this).trigger('click'); }
	});
	$(document).on('click', '.dze-cb-shot', function () { $(this).toggleClass('is-sel'); });

	// ---- Writing it again, from where you are reading it ----
	function edited(id, fid) {
		var b = bucket(id);
		return !!b.open[fid] && editorGet(editorId(id, fid)) !== (b.texts[fid] || '');
	}
	function setValue(id, fid, html) {
		var b = bucket(id);
		b.texts[fid] = html;
		var $block = $('.dze-cb-preview[data-id="' + id + '"]').find('.dze-cb-fblock[data-field="' + fid + '"]');
		$block.find('.dze-cb-fpeek').text(peek(html));
		if (!b.open[fid]) { return; }
		var eid = editorId(id, fid);
		if (window.tinymce && tinymce.get(eid) && !tinymce.get(eid).isHidden()) { tinymce.get(eid).setContent(html); }
		else { $('#' + eid).val(html); }
	}
	function regenerate(id, fids, $state) {
		var keep = fids.filter(function (fid) { return edited(id, fid); });
		if (keep.length && !window.confirm(sprintf(i18n.confirmRedo, keep.length))) { return; }
		$state.removeClass('is-ko').text(i18n.working);
		return $.post(cfg.ajaxUrl, { action: 'dze_content_text_all', nonce: cfg.nonce, post: id, fields: fids })
			.done(function (res) {
				if (!res || !res.success) {
					$state.addClass('is-ko').text(reason((res && res.data && res.data.message) || i18n.error));
					return;
				}
				var texts = res.data.texts || {};
				fids.forEach(function (fid) { setValue(id, fid, texts[fid] || ''); });
				$state.text('✓');
				window.setTimeout(function () { $state.text(''); }, 2000);
			})
			.fail(function (x) { $state.addClass('is-ko').text(reason(x)); });
	}
	$(document).on('click', '.dze-cb-redo', function (e) {
		e.stopPropagation();
		var $btn = $(this), id = $btn.closest('.dze-cb-preview').data('id');
		regenerate(id, [ $btn.data('field') ], $btn.closest('.dze-cb-fhead').find('.dze-cb-fstate'));
	});
	$(document).on('click', '.dze-cb-redoall', function () {
		var $btn = $(this), id = $btn.closest('.dze-cb-preview').data('id');
		regenerate(id, Object.keys(bucket(id).texts), $btn.closest('p').find('.dze-cb-panelstate'));
	});
	// "One more image" said nothing about WHICH image: one button per recipe in
	// use, named after it, so the style asked for is the style on the button.
	function oneMoreButtons() {
		var used = tpls(), out = '';
		if (!used.length) { used = [ '0' ]; }
		used.forEach(function (t) {
			var nm = (cfg.templates[parseInt(t, 10)] || {}).name || '';
			out += '<button type="button" class="button button-small dze-cb-onemore" data-tpl="' + esc(t) + '">' +
				'+ ' + esc(nm || i18n.oneMore) + '</button> ';
		});
		return out;
	}
	$(document).on('click', '.dze-cb-onemore', function () {
		var $btn = $(this).prop('disabled', true);
		var id = $btn.closest('.dze-cb-preview').data('id');
		var $state = $btn.closest('p').find('.dze-cb-panelstate').removeClass('is-ko').text(i18n.working);
		var tpl = $btn.data('tpl');
		if (tpl === undefined) { tpl = tpls()[0] !== undefined ? tpls()[0] : '0'; }
		tpl = String(tpl);
		$.post(cfg.ajaxUrl, imageRequest(id, true, tpl))
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) {
					$state.addClass('is-ko').text(reason((res && res.data && res.data.message) || i18n.error));
					return;
				}
				addShot(id, res.data.url, tpl);
				$state.text('');
			})
			.fail(function (x) { $btn.prop('disabled', false); $state.addClass('is-ko').text(reason(x)); });
	});

	// Whatever was left undecided last time is put back on screen as it was.
	(function restorePending() {
		var p = cfg.pending || {};
		Object.keys(p).forEach(function (id) {
			var waiting = p[id] || {};
			var texts = waiting.texts || {};
			var b = bucket(id);
			b.shotOf = waiting.companions || {};
			Object.keys(texts).forEach(function (fid) {
				b.texts[fid] = texts[fid] || '';
				badge(id, fid, cfg.fields[fid] || fid);
			});
			(waiting.shots || []).forEach(function (url) {
				if (b.shots.indexOf(url) < 0) { b.shots.push(url); }
			});
			if (b.shots.length) { badge(id, 'img', i18n.imgBadge + ' ×' + b.shots.length); }
			if (Object.keys(b.texts).length || b.shots.length) {
				offerReview(id);
				state[id] = { total: 1, done: 1, notes: [ i18n.fromEarlier ], failed: false };
				paint(id, 'ready');
			}
		});
	}());

	// =====================================================================
	// Applying what was kept
	// =====================================================================

	// Applying is a decision with three sizes: this product, the ticked ones,
	// or everything waiting. They live together in the list bar, next to the
	// other decisions about the list.
	function pendingIds() {
		return Object.keys(results).filter(function (id) {
			var b = results[id];
			return b && (Object.keys(b.texts).length || b.shots.length);
		});
	}
	function refreshApplyBar() {
		var waiting = pendingIds().length;
		var sel = picked().filter(function (id) { return results[id]; }).length;
		$('#dze-cb-applyall').toggle(waiting > 0).text(sprintf(i18n.applyAllN, waiting));
		$('#dze-cb-applysel').toggle(sel > 0).text(sprintf(i18n.applySelN, sel));
	}

	function applyProducts(ids, $btn) {
		var jobs = [], shots = [];
		ids.forEach(function (id) {
			var b = results[id];
			if (!b) { return; }
			var $prev = $('.dze-cb-preview[data-id="' + id + '"]');
			Object.keys(b.texts).forEach(function (fid) {
				var $f = $prev.find('.dze-cb-fblock[data-field="' + fid + '"]');
				// Unticked in the panel: this block is not written. A panel that
				// was never opened has no ticks at all, and keeps everything.
				var $keep = $f.find('.dze-cb-fkeep');
				if ($keep.length && !$keep.is(':checked')) { return; }
				jobs.push({ id: id, fid: fid, value: valueOf(id, fid), $f: $f });
			});
			if (b.shots.length) {
				var $w = $prev.find('.dze-cb-shots');
				// Never opened: every shot is kept, all to the gallery. Opened:
				// the ticked ones, each to the destination chosen under it.
				var items = [];
				if ($w.length) {
					$w.find('.dze-cb-shot.is-sel').each(function () {
						items.push({
							url: $(this).data('url'),
							target: $(this).closest('.dze-cb-shotwrap').find('.dze-cb-shotdest').val() || 'gallery'
						});
					});
				} else {
					b.shots.forEach(function (u) { items.push({ url: u, target: 'gallery' }); });
				}
				if (items.length) { shots.push({ id: id, items: items, $w: $w.length ? $w : $prev }); }
			}
		});
		if (!jobs.length && !shots.length) {
			window.alert(i18n.nothingKept);
			return;
		}
		if ($btn) { $btn.prop('disabled', true); }
		ids.forEach(function (id) { paint(id, 'run'); });

		// Images first: attaching is what the run was for, and a text failure
		// should not leave the kept shots behind.
		function attachNext(k) {
			if (k >= shots.length) { return runTexts(); }
			var sh = shots[k];
			sh.$w.find('.dze-cb-shotstate').removeClass('is-ko').text(i18n.applying);
			$.post(cfg.ajaxUrl, { action: 'dze_content_image_attach', nonce: cfg.nonce, post: sh.id, items: sh.items })
				.done(function (res) {
					if (res && res.success) {
						okCount++;
						sh.$w.find('.dze-cb-shotstate').text('✓ ' + sprintf(i18n.attached, res.data.attached));
						sh.$w.find('.dze-cb-shot').removeClass('is-sel');
					} else {
						koCount++;
						note(sh.id, (res && res.data && res.data.message) || i18n.error, true);
						sh.$w.find('.dze-cb-shotstate').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					}
				})
				.fail(function (x) {
					koCount++;
					note(sh.id, reason(x), true);
					sh.$w.find('.dze-cb-shotstate').addClass('is-ko').text(reason(x));
				})
				.always(function () { attachNext(k + 1); });
		}
		function runTexts() {
			var i = 0;
			(function next() {
				if (i >= jobs.length) { return done(); }
				var j = jobs[i++];
				$.post(cfg.ajaxUrl, { action: 'dze_content_apply', nonce: cfg.nonce, post: j.id, field: j.fid, value: j.value })
					.done(function (res) {
						if (res && res.success) { okCount++; j.$f.find('.dze-cb-fstate').removeClass('is-ko').text('✓'); }
						else {
							koCount++;
							note(j.id, (res && res.data && res.data.message) || i18n.error, true);
							j.$f.find('.dze-cb-fstate').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
						}
					})
					.fail(function () { koCount++; note(j.id, i18n.error, true); j.$f.find('.dze-cb-fstate').addClass('is-ko').text(i18n.error); })
					.always(next);
			})();
		}
		function done() {
			if ($btn) { $btn.prop('disabled', false); }
			ids.forEach(function (id) {
				var st = state[id];
				// Applied and settled: the product stops waiting, here and on
				// the server, and its line says so.
				if (!st || !st.failed) {
					delete results[id];
					$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: id });
					state[id] = { total: 1, done: 1, notes: [], failed: false };
					reviewModeWas = reviewMode;
					reviewMode = false;
					paint(id, 'done');
					reviewMode = reviewModeWas;
					$('.dze-cb-preview[data-id="' + id + '"]').hide();
					hideRowActions(id);
				} else {
					paint(id, 'fail');
				}
			});
			refreshApplyBar();
			$('#dze-cb-progress').text(sprintf(i18n.finished, okCount, koCount));
		}
		var reviewModeWas = reviewMode;
		attachNext(0);
	}

	$('#dze-cb-applyall').on('click', function () {
		var ids = pendingIds();
		if (!ids.length) { window.alert(i18n.nothingKept); return; }
		if (!window.confirm(sprintf(i18n.confirmAll, ids.length))) { return; }
		applyProducts(ids, $(this));
	});
	$('#dze-cb-applysel').on('click', function () {
		var ids = picked().filter(function (id) { return results[id]; });
		if (!ids.length) { window.alert(i18n.nothingKept); return; }
		applyProducts(ids, $(this));
	});
	// One product, from its own panel: no confirmation, it is one deliberate
	// click on one product you are looking at.
	$(document).on('click', '.dze-cb-applyone', function () {
		applyProducts([ $(this).closest('.dze-cb-preview').data('id') ], $(this));
	});

	function reviewsTask(id, count) {
		step(id, i18n.revBadge);
		return $.post(cfg.ajaxUrl, {
			action: 'dze_reviews_generate', nonce: cfg.revNonce,
			post: id, count: count, direct: 1
		})
			.then(function (r) {
				if (!r || !r.success) {
					note(id, (r && r.data && r.data.message) || i18n.error, true);
					return $.Deferred().reject().promise();
				}
				badge(id, 'reviews', i18n.revBadge + ' ×' + (r.data.created || 0));
				return true;
			}, function (x) {
				note(id, reason(x), true);
				return $.Deferred().reject().promise();
			});
	}

	// =====================================================================
	// The run
	// =====================================================================

	function stop() {
		stopped = true;
		$('#dze-cb-sticky').hide();
		$('#dze-cb-start').prop('disabled', false);
		$('#dze-cb-stop').hide();
	}
	$('#dze-cb-stop, #dze-cb-stickystop').on('click', stop);

	$('#dze-cb-start').on('click', function () {
		if (!cfg.validated) { return; }
		persist();
		var fields = $('.dze-cb-field:checked:not(:disabled)').map(function () { return $(this).val(); }).get();
		var doPrice = $('#dze-cb-price').is(':checked');
		var tplList = tpls();
		var doImg = $('#dze-cb-image').is(':checked') && !$('#dze-cb-image').prop('disabled') && tplList.length > 0;
		var imgN = parseInt($('#dze-cb-imgn').val(), 10) || 1;
		var doRev = $('#dze-cb-reviews').is(':checked');
		var revN = parseInt($('#dze-cb-revn').val(), 10) || 0;
		reviewMode = $('input[name="dze-cb-mode"]:checked').val() !== 'direct';

		if (!fields.length && !doPrice && !doImg && !doRev) {
			window.alert(i18n.noFields);
			return;
		}
		// A new run clears the screen of everything it is about to redo, and
		// leaves untouched what it is about to skip — that content is still
		// waiting for a decision.
		var keep = $('#dze-cb-force').is(':checked') ? [] : pendingIds().map(String);
		$('.dze-cb-row').each(function () {
			var rid = String($(this).data('id'));
			if (keep.indexOf(rid) >= 0) { return; }
			$('.dze-cb-preview[data-id="' + rid + '"]').hide().find('td').empty();
			$(this).find('.dze-cb-badges').empty();
			$(this).find('.dze-cb-toggle, .dze-cb-yes, .dze-cb-no').hide();
			$(this).find('.dze-cb-toggle').attr('aria-expanded', 'false').find('.dze-cb-caret').text('▾');
			delete results[rid];
			delete state[rid];
		});
		refreshApplyBar();

		var perProduct = (fields.length ? 1 : 0) + (doPrice ? 1 : 0) + (doImg ? imgN * tplList.length : 0) + (doRev ? 1 : 0);
		// A product already holding content nobody has decided on is left alone:
		// writing over it would charge for the same work twice and throw the
		// first result away. Redoing one on purpose is what its ↻ is for, and
		// the tick above forces the whole run.
		var force = $('#dze-cb-force').is(':checked');
		var waiting = force ? [] : pendingIds().map(String);
		var skipped = 0;

		// ONE job per product, its own steps in order inside it. Products are
		// independent, the steps of a product are not: a price recalculated
		// while its texts are still being written would be a race for nothing.
		var jobs = [];
		$('.dze-cb-row').each(function () {
			var id = $(this).data('id');
			if (waiting.indexOf(String(id)) >= 0) {
				skipped++;
				var st = state[id] || { total: 1, done: 1, notes: [], failed: false };
				st.notes = [ i18n.sSkipped ];
				state[id] = st;
				paint(id, 'ready');
				return;
			}
			plan(id, perProduct);
			jobs.push(function () {
				paint(id, 'run');
				var chain = $.Deferred().resolve().promise();
				if (fields.length) { chain = chain.then(function () { return textAllTask(id, fields, reviewMode); }); }
				if (doPrice) { chain = chain.then(function () { return priceTask(id); }); }
				if (doImg) { chain = chain.then(function () { return imageTask(id, reviewMode, imgN, tplList); }); }
				if (doRev) { chain = chain.then(function () { return reviewsTask(id, revN); }); }
				return chain;
			});
		});

		if (!jobs.length) {
			window.alert(i18n.allSkipped);
			return;
		}
		stopped = false; okCount = 0; koCount = 0; doneCount = 0;
		total = jobs.length * perProduct;
		$('#dze-cb-sticky').show();
		$('.dze-cb-fill').css('width', 0);
		$('#dze-cb-stickypct').text('0%');
		$('#dze-cb-stickytext').text(i18n.working);
		$('#dze-cb-start').prop('disabled', true);
		$('#dze-cb-stop').show();

		// A pool of workers rather than a queue of one: waiting for the provider
		// is what a run spends its time on, and there is nothing to gain by
		// waiting for one product before starting the next.
		var lanes = Math.max(1, parseInt($('#dze-cb-par').val(), 10) || 1);
		var cursor = 0, live = 0;
		function finish() {
			$('#dze-cb-start').prop('disabled', false);
			$('#dze-cb-stop').hide();
			$('#dze-cb-sticky').hide();
			$('#dze-cb-progress').text(
				(stopped ? i18n.stopped : sprintf(i18n.finished, okCount, koCount)) +
				(skipped ? ' · ' + sprintf(i18n.skippedN, skipped) : '')
			);
		}
		function pump() {
			if (stopped) { if (!live) { finish(); } return; }
			while (live < lanes && cursor < jobs.length) {
				live++;
				jobs[cursor++]().always(function () {
					live--;
					pump();
				});
			}
			if (!live && cursor >= jobs.length) { finish(); }
		}
		pump();
	});

}(jQuery));
