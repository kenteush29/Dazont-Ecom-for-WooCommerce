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
		if (typeof m.tpl !== 'undefined') { $('#dze-cb-tpl').val(m.tpl); }
		// The scene is remembered with the toolbox (same store): pick a support
		// once and every screen keeps shooting on it.
		if (typeof m.scene !== 'undefined' && $('#dze-cb-scene option[value="' + m.scene + '"]').length) {
			$('#dze-cb-scene').val(String(m.scene));
		}
		if (typeof m.imgn !== 'undefined') { $('#dze-cb-imgn').val(String(m.imgn)); }
	}());

	function persist() {
		var m = mem();
		m.bulkFields = $('.dze-cb-field:checked:not(:disabled)').map(function () { return $(this).val(); }).get();
		m.bulkPrice = $('#dze-cb-price').is(':checked');
		m.bulkImage = $('#dze-cb-image').is(':checked');
		m.tpl = $('#dze-cb-tpl').val();
		if ($('#dze-cb-scene').length) { m.scene = parseInt($('#dze-cb-scene').val(), 10); }
		if ($('#dze-cb-imgn').length) { m.imgn = parseInt($('#dze-cb-imgn').val(), 10); }
		saveMem(m);
	}
	$('.dze-cb-field, #dze-cb-price, #dze-cb-image, #dze-cb-tpl, #dze-cb-scene, #dze-cb-imgn').on('change', persist);

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
	function offerReview(id) { $row(id).find('.dze-cb-toggle').show(); }

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
		if (!review) { data.apply = 1; }
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
	function imageRequest(id, review) {
		var data = { action: 'dze_content_image', nonce: cfg.nonce, post: id, template: $('#dze-cb-tpl').val() };
		if (review) { data.mode = 'defer'; }
		var $sc = $('#dze-cb-scene');
		if ($sc.length) { data.scene = parseInt($sc.val(), 10); }
		return data;
	}
	function oneImage(id, review) {
		return $.post(cfg.ajaxUrl, imageRequest(id, review))
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				okCount++;
				if (review) {
					addShot(id, res.data.url);
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
	function imageTask(id, review, n) {
		var i = 0, d = $.Deferred();
		(function next() {
			if (stopped || i >= Math.max(1, n)) { d.resolve(); return; }
			i++;
			oneImage(id, review).always(next);
		})();
		return d;
	}

	// =====================================================================
	// Review: collapsed rows, opened one at a time
	// =====================================================================

	function bucket(id) {
		if (!results[id]) { results[id] = { texts: {}, shots: [], built: false, open: {} }; }
		return results[id];
	}
	function previewCell(id) {
		$('#dze-cb-applyall').show();
		return $('.dze-cb-preview[data-id="' + id + '"]').find('td');
	}
	function editorId(id, fid) { return 'dze-cb-ed-' + id + '-' + String(fid).replace(/[^a-zA-Z0-9_-]/g, ''); }
	function isRich(fid) { return !!(cfg.rich && cfg.rich[fid]); }
	function peek(html) {
		var t = $('<div>').html(html || '').text().replace(/\s+/g, ' ').trim();
		return t ? (t.length > 110 ? t.slice(0, 110) + '…' : t) : i18n.empty;
	}

	function storeTexts(id, fids, texts) {
		var b = bucket(id);
		fids.forEach(function (fid) {
			b.texts[fid] = texts[fid] || '';
			badge(id, fid, cfg.fields[fid] || fid);
		});
		offerReview(id);
	}
	function addShot(id, url) {
		if (!url) { return; }
		var b = bucket(id);
		b.shots.push(url);
		badge(id, 'img', i18n.imgBadge + ' ×' + b.shots.length);
		offerReview(id);
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
			html += '<div class="dze-cb-fblock" data-field="' + fid + '">' +
				'<div class="dze-cb-fhead" role="button" tabindex="0" aria-expanded="false">' +
					'<span class="dze-cb-fcaret">▸</span>' +
					'<span class="dze-cb-fname">' + esc(cfg.fields[fid] || fid) + '</span>' +
					'<span class="dze-cb-fpeek">' + esc(peek(b.texts[fid])) + '</span>' +
					'<span class="dze-cb-fstate"></span>' +
					'<button type="button" class="dze-cb-redo" data-field="' + fid + '" title="' + esc(i18n.redoOne) + '">↻</button>' +
				'</div>' +
				'<div class="dze-cb-fbody" style="display:none;"></div>' +
			'</div>';
		});
		html += '</div>' +
			'<p class="dze-cb-panelbar">' +
				(Object.keys(b.texts).length
					? '<button type="button" class="button button-small dze-cb-redoall">↻ ' + esc(i18n.redoAll) + '</button> ' : '') +
				'<button type="button" class="button button-small dze-cb-onemore">↻ ' + esc(i18n.oneMore) + '</button> ' +
				'<span class="dze-cb-panelstate"></span>' +
			'</p>' +
			'<div class="dze-cb-shots-slot"></div>';
		$cell.html(html);
		b.built = true;
		renderShots(id);
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

	function renderShots(id) {
		var b = bucket(id), $slot = previewCell(id).find('.dze-cb-shots-slot');
		if (!$slot.length || !b.shots.length) { return; }
		// Adding one more attempt redraws the strip: what was already ticked or
		// unticked, and where they were headed, survives the redraw.
		var $old = $slot.find('.dze-cb-shots');
		var dropped = {}, target = '';
		if ($old.length) {
			target = $old.find('.dze-cb-shottarget').val() || '';
			$old.find('.dze-cb-shot').not('.is-sel').each(function () { dropped[$(this).data('url')] = true; });
		}
		var $wrap = $('<div class="dze-cb-shots"><div class="dze-cb-shotgrid"></div>' +
			'<select class="dze-cb-shottarget">' +
				'<option value="gallery">' + esc(i18n.toGallery) + '</option>' +
				'<option value="main">' + esc(i18n.toMain) + '</option>' +
			'</select> <span class="dze-cb-shotstate"></span></div>');
		b.shots.forEach(function (url) {
			$wrap.find('.dze-cb-shotgrid').append(
				$('<div class="dze-cb-shot"><span class="dze-cb-shotcheck">✓</span></div>')
					.toggleClass('is-sel', !dropped[url])
					.attr('data-url', url)
					.append($('<img class="dze-hzoom" />').attr('src', url).attr('data-full', url).attr('alt', ''))
			);
		});
		if (target) { $wrap.find('.dze-cb-shottarget').val(target); }
		$slot.empty().append($wrap);
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

	$(document).on('click', '.dze-cb-toggle', function () {
		var $btn = $(this), id = $btn.closest('.dze-cb-row').data('id');
		var $prev = $('.dze-cb-preview[data-id="' + id + '"]');
		var open = $prev.is(':visible');
		if (!open) { buildPanel(id); }
		$prev.toggle(!open);
		$btn.attr('aria-expanded', open ? 'false' : 'true').find('.dze-cb-caret').text(open ? '▾' : '▴');
	});
	$(document).on('click', '.dze-cb-fhead', function (e) {
		if ($(e.target).closest('.dze-cb-redo').length) { return; }
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
	$(document).on('click', '.dze-cb-onemore', function () {
		var $btn = $(this).prop('disabled', true);
		var id = $btn.closest('.dze-cb-preview').data('id');
		var $state = $btn.closest('p').find('.dze-cb-panelstate').removeClass('is-ko').text(i18n.working);
		$.post(cfg.ajaxUrl, imageRequest(id, true))
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) {
					$state.addClass('is-ko').text(reason((res && res.data && res.data.message) || i18n.error));
					return;
				}
				addShot(id, res.data.url);
				$state.text('');
			})
			.fail(function (x) { $btn.prop('disabled', false); $state.addClass('is-ko').text(reason(x)); });
	});

	// =====================================================================
	// Applying what was kept
	// =====================================================================

	$('#dze-cb-applyall').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		// Everything generated is applied, opened panel or not: a product you
		// did not bother to review is a product you are happy with.
		var jobs = [], shots = [];
		Object.keys(results).forEach(function (id) {
			var b = results[id], $prev = $('.dze-cb-preview[data-id="' + id + '"]');
			Object.keys(b.texts).forEach(function (fid) {
				jobs.push({
					id: id,
					fid: fid,
					value: valueOf(id, fid),
					$f: $prev.find('.dze-cb-fblock[data-field="' + fid + '"]')
				});
			});
			if (b.shots.length) {
				var $w = $prev.find('.dze-cb-shots');
				// Never opened: every shot is kept. Opened: only the ticked ones.
				var urls = $w.length
					? $w.find('.dze-cb-shot.is-sel').map(function () { return $(this).data('url'); }).get()
					: b.shots.slice();
				if (urls.length) {
					shots.push({
						id: id,
						urls: urls,
						target: $w.length ? $w.find('.dze-cb-shottarget').val() : 'gallery',
						$w: $w.length ? $w : $prev
					});
				}
			}
		});
		// Images first: attaching is what the run was for, and a text failure
		// should not leave the kept shots behind.
		function attachNext(k) {
			if (k >= shots.length) { return runTexts(); }
			var sh = shots[k];
			sh.$w.find('.dze-cb-shotstate').removeClass('is-ko').text(i18n.working);
			$.post(cfg.ajaxUrl, { action: 'dze_content_image_attach', nonce: cfg.nonce, post: sh.id, urls: sh.urls, target: sh.target })
				.done(function (res) {
					if (res && res.success) {
						okCount++;
						sh.$w.find('.dze-cb-shotstate').text('✓ ' + sprintf(i18n.attached, res.data.attached));
						sh.$w.find('.dze-cb-shot').removeClass('is-sel');
					} else {
						koCount++;
						sh.$w.find('.dze-cb-shotstate').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					}
				})
				.fail(function (x) { koCount++; sh.$w.find('.dze-cb-shotstate').addClass('is-ko').text(reason(x)); })
				.always(function () { attachNext(k + 1); });
		}
		function runTexts() {
			var i = 0;
			(function next() {
				if (i >= jobs.length) {
					$btn.prop('disabled', false);
					$('#dze-cb-progress').text(sprintf(i18n.finished, okCount, koCount));
					return;
				}
				var j = jobs[i++];
				$.post(cfg.ajaxUrl, { action: 'dze_content_apply', nonce: cfg.nonce, post: j.id, field: j.fid, value: j.value })
					.done(function (res) {
						if (res && res.success) { okCount++; j.$f.find('.dze-cb-fstate').removeClass('is-ko').text('✓'); }
						else { koCount++; j.$f.find('.dze-cb-fstate').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error); }
					})
					.fail(function () { koCount++; j.$f.find('.dze-cb-fstate').addClass('is-ko').text(i18n.error); })
					.always(next);
			})();
		}
		attachNext(0);
	});

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
		var doImg = $('#dze-cb-image').is(':checked') && !$('#dze-cb-image').prop('disabled');
		var imgN = parseInt($('#dze-cb-imgn').val(), 10) || 1;
		reviewMode = $('input[name="dze-cb-mode"]:checked').val() !== 'direct';

		if (!fields.length && !doPrice && !doImg) {
			window.alert(i18n.noFields);
			return;
		}
		// A new run starts from a clean screen.
		$('.dze-cb-preview').hide().find('td').empty();
		$('.dze-cb-badges').empty();
		$('.dze-cb-toggle').hide().attr('aria-expanded', 'false').find('.dze-cb-caret').text('▾');
		$('#dze-cb-applyall').hide();
		results = {};
		state = {};

		var perProduct = (fields.length ? 1 : 0) + (doPrice ? 1 : 0) + (doImg ? imgN : 0);
		var tasks = [];
		$('.dze-cb-row').each(function () {
			var id = $(this).data('id');
			plan(id, perProduct);
			if (fields.length) { tasks.push(function () { paint(id, 'run'); return textAllTask(id, fields, reviewMode); }); }
			if (doPrice) { tasks.push(function () { paint(id, 'run'); return priceTask(id); }); }
			if (doImg) { tasks.push(function () { paint(id, 'run'); return imageTask(id, reviewMode, imgN); }); }
		});

		stopped = false; okCount = 0; koCount = 0; doneCount = 0;
		total = $('.dze-cb-row').length * perProduct;
		$('#dze-cb-sticky').show();
		$('.dze-cb-fill').css('width', 0);
		$('#dze-cb-stickypct').text('0%');
		$('#dze-cb-stickytext').text(i18n.working);
		$('#dze-cb-start').prop('disabled', true);
		$('#dze-cb-stop').show();

		(function next(i) {
			if (stopped || i >= tasks.length) {
				$('#dze-cb-start').prop('disabled', false);
				$('#dze-cb-stop').hide();
				$('#dze-cb-sticky').hide();
				$('#dze-cb-progress').text(stopped ? i18n.stopped : sprintf(i18n.finished, okCount, koCount));
				return;
			}
			tasks[i]().always(function () { next(i + 1); });
		})(0);
	});

}(jQuery));
