/* global dzeContent, jQuery, tinymce */
/**
 * The product toolbox: ONE product, the same flow as the bulk screen.
 *
 * Generate → look at it → change it if needed → accept. That is all a product
 * page needs, and it is exactly what the bulk screen does, so it looks and
 * behaves the same here: shut drawers with the first line showing, a real
 * WordPress editor when one is opened, the current content one click away, an
 * image strip where each result says where it goes.
 *
 * Prompt writing, validation and the price table live in Settings, where the
 * whole registry is. Keeping copies of them here made this popup a second
 * settings screen with five tabs, which is what it should never have been.
 */
(function ($) {
	'use strict';

	var cfg = dzeContent, i18n = cfg.i18n;
	var MEM = 'dzeContentMem';

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%\d\$s|%s/g, function () { return args[i++]; });
	}
	function mem() { try { return JSON.parse(localStorage.getItem(MEM) || '{}'); } catch (e) { return {}; } }
	function saveMem(o) { try { localStorage.setItem(MEM, JSON.stringify(o)); } catch (e) {} }
	function reason(msg) {
		if (typeof msg === 'string' && msg) { return msg; }
		if (msg && msg.status) { return 'HTTP ' + msg.status + (msg.statusText ? ' ' + msg.statusText : ''); }
		return i18n.error;
	}

	// The product being worked on. On an edit screen it is that product; from
	// the products list it is the row that was clicked, so it is a variable,
	// not a constant, and everything below reads it at call time.
	var PID = cfg.postId || 0;
	// Everything the popup is holding right now.
	var res = { texts: {}, shots: [], shotTpl: {}, open: {}, shotOf: {}, current: null };
	function reset() {
		// TinyMCE instances belong to the product they were opened on.
		Object.keys(res.open).forEach(function (fid) {
			try { if (window.wp && wp.editor) { wp.editor.remove(editorId(fid)); } } catch (e) {}
		});
		res = { texts: {}, shots: [], shotTpl: {}, open: {}, shotOf: {}, current: null };
		$('#dze-cx-drawers').empty();
		$('#dze-cx-shots').empty();
		$('#dze-cx-nowshots').empty();
		$('#dze-cx-result').hide();
		$('#dze-cx-runstate').empty();
	}

	// =====================================================================
	// Image prompt rows: one, plus a + while there is another prompt to pick
	// =====================================================================

	// A quiet "see the instructions" next to whatever is about to be generated:
	// a result you cannot trace back to a prompt is a result you cannot fix.
	function promptBtn(id) {
		if (!id) { return ''; }
		return '<button type="button" class="dze-prompt-peek" data-prompt="content_' + esc(id) +
			'" title="' + esc(i18n.promptTip) + '">&#9998;</button>';
	}

	function tplUsed() {
		return $('#dze-cx-tplrows .dze-cx-tpl').map(function () { return $(this).val(); }).get();
	}
	// A row is a whole order: this prompt, on that scene, so many times. The
	// scene and the count used to stand beside the FIRST row as if they were
	// the run's own settings — a second prompt then ran on a scene nobody had
	// chosen for it, and the screen showed no way to choose one.
	function tplJobs() {
		return $('#dze-cx-tplrows .dze-tplrow').map(function () {
			var $r = $(this);
			return {
				tpl: String($r.find('.dze-cx-tpl').val()),
				scene: $r.find('.dze-tpl-scene').length ? parseInt($r.find('.dze-tpl-scene').val(), 10) : -1,
				n: parseInt($r.find('.dze-tpl-n').val(), 10) || 1,
				target: $r.find('.dze-tpl-target').val() || 'gallery'
			};
		}).get();
	}
	// WHICH prompt made this image. One generated in this visit remembers the
	// row that ordered it; one restored from the waiting list remembers the
	// prompt's id instead, and asking for it again used to fall back to "the
	// first row" — which is how ↻ on a gallery shot came back as a main image
	// made by another prompt entirely.
	function tplOfShot(url) {
		var tpl = res.shotTpl ? res.shotTpl[url] : undefined;
		if (tpl !== undefined && tpl !== null && tpl !== '') { return String(tpl); }
		var rid = res.shotRecipe ? res.shotRecipe[url] : '';
		var found = null;
		if (rid) {
			(cfg.templates || []).forEach(function (t, i) {
				if (null === found && String(t.id) === String(rid)) { found = String(i); }
			});
		}
		return found;
	}
	function tplForTarget(target) {
		var found = null;
		tplJobs().forEach(function (j) {
			if (null === found && j.target === target) { found = String(j.tpl); }
		});
		if (null !== found) { return found; }
		var first = tplJobs()[0];
		return first ? String(first.tpl) : '0';
	}
	function jobFor(tpl) {
		var found = null;
		tplJobs().forEach(function (j) { if (!found && String(j.tpl) === String(tpl)) { found = j; } });
		return found || { tpl: String(tpl), scene: defaultScene(), n: 1, target: 'gallery' };
	}
	function defaultScene() {
		var m = mem();
		var scenes = cfg.scenes || [];
		var cur = (m.scene !== undefined && m.scene !== null) ? parseInt(m.scene, 10) : (cfg.sceneDef === undefined ? -1 : cfg.sceneDef);
		if (cur >= scenes.length) { cur = scenes.length ? 0 : -1; }
		return cur;
	}
	function sceneSelect(cur) {
		var scenes = cfg.scenes || [];
		if (!scenes.length) { return ''; }
		if (cur === undefined || cur === null || isNaN(cur)) { cur = defaultScene(); }
		return '<select class="dze-tpl-scene" title="' + esc(i18n.sceneHelp) + '">' +
			'<option value="-1"' + (cur < 0 ? ' selected' : '') + '>' + esc(i18n.noScene) + '</option>' +
			scenes.map(function (s, i) {
				return '<option value="' + i + '"' + (cur === i ? ' selected' : '') + '>' + esc(s.name) + '</option>';
			}).join('') + '</select>';
	}
	function targetSelect(cur) {
		var opts = [ [ 'main', i18n.toMain ], [ 'gallery_first', i18n.toGalleryFirst ], [ 'gallery', i18n.toGallery ] ];
		return '<select class="dze-tpl-target" title="' + esc(i18n.putHelp) + '">' +
			opts.map(function (o) {
				return '<option value="' + o[0] + '"' + (cur === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
			}).join('') + '</select>';
	}
	function nSelect(cur) {
		cur = parseInt(cur, 10) || 1;
		return '<select class="dze-tpl-n" title="' + esc(i18n.attemptsHelp) + '">' +
			[1, 2, 3, 4].map(function (n) {
				return '<option value="' + n + '"' + (cur === n ? ' selected' : '') + '>× ' + n + '</option>';
			}).join('') + '</select>';
	}
	function tplRow(sel, scene, n, target) {
		var opts = cfg.templates.map(function (t, i) {
			return '<option value="' + i + '"' + (String(sel) === String(i) ? ' selected' : '') + '>' +
				esc(t.name) + (t.valid ? '' : ' — ' + esc(i18n.notValid)) + '</option>';
		}).join('');
		var cur = cfg.templates[parseInt(sel, 10)] || cfg.templates[0] || {};
		return '<span class="dze-tplrow"><select class="dze-cx-tpl">' + opts + '</select>' +
			promptBtn(cur.id) +
			sceneSelect(scene) + nSelect(n) + targetSelect(target || cur.target || 'gallery') +
			'<span class="dze-tplbtns">' +
			'<button type="button" class="button button-small dze-cx-tpladd" title="' + esc(i18n.addPrompt) + '">+</button>' +
			'<button type="button" class="button button-small dze-cx-tpldel" title="' + esc(i18n.delPrompt) + '">−</button></span></span>';
	}
	// The column names, printed once above the rows rather than repeated on
	// each of them.
	function tplHead() {
		return '<span class="dze-tplhead"><span>' + esc(i18n.template) + '</span><span></span>' +
			((cfg.scenes || []).length ? '<span>' + esc(i18n.scene) + '</span>' : '') +
			'<span>' + esc(i18n.attempts) + '</span><span>' + esc(i18n.putIt) + '</span><span></span></span>';
	}
	// A + that cannot add anything is a lie: it only shows while an unused
	// prompt is left, and the row it creates lands on one of those.
	function syncTplRows() {
		var $rows = $('#dze-cx-tplrows .dze-tplrow');
		var room = $rows.length < cfg.templates.length;
		$rows.each(function (i) {
			$(this).find('.dze-cx-tpladd').toggle(room && i === $rows.length - 1);
			$(this).find('.dze-cx-tpldel').toggle($rows.length > 1);
		});
	}
	function firstFreeTpl() {
		var used = tplUsed();
		for (var i = 0; i < cfg.templates.length; i++) {
			if (used.indexOf(String(i)) < 0) { return i; }
		}
		return 0;
	}
	$(document).on('change', '#dze-cx-tplrows .dze-tpl-target', function () { $(this).data('touched', 1); });
	$(document).on('click', '.dze-cx-tpladd', function () {
		$('#dze-cx-tplrows').append(tplRow(firstFreeTpl(), defaultScene(), 1));
		syncTplRows();
		remember();
	});
	$(document).on('click', '.dze-cx-tpldel', function () {
		$(this).closest('.dze-tplrow').remove();
		syncTplRows();
		remember();
	});
	// Two rows on the same prompt would generate the same thing twice without
	// saying so: the duplicate falls back to a free one.
	$(document).on('change', '.dze-cx-tpl', function () {
		var $me = $(this), v = $me.val(), seen = false;
		$('#dze-cx-tplrows .dze-cx-tpl').each(function () {
			if (this === $me[0]) { seen = true; return; }
			if (seen && $(this).val() === v) { $(this).val(String(firstFreeTpl())); }
		});
		var used = {}, dupe = false;
		$('#dze-cx-tplrows .dze-cx-tpl').each(function () {
			if (used[$(this).val()]) { dupe = true; }
			used[$(this).val()] = 1;
		});
		if (dupe) { $me.val(String(firstFreeTpl())); }
		// The peek button follows the prompt the row now points at, and so does
		// the destination unless this row was told otherwise by hand.
		$('#dze-cx-tplrows .dze-tplrow').each(function () {
			var t = cfg.templates[parseInt($(this).find('.dze-cx-tpl').val(), 10)] || {};
			$(this).find('.dze-prompt-peek').attr('data-prompt', 'content_' + (t.id || ''));
			var $tg = $(this).find('.dze-tpl-target');
			if (!$tg.data('touched')) { $tg.val(t.target || 'gallery'); }
		});
		remember();
	});

	function remember() {
		var m = mem();
		m.auto = {
			fields: $('.dze-cx-f:checked').map(function () { return $(this).val(); }).get(),
			price: $('#dze-cx-doprice').is(':checked') ? 1 : 0,
			img: $('#dze-cx-doimg').is(':checked') ? 1 : 0,
			tpls: tplJobs()
		};
		// The scene of the first row is the one every screen starts from: pick
		// a support once and the toolbox, the bulk screen and the next popup
		// all open on it.
		var first = tplJobs()[0];
		if (first && !isNaN(first.scene)) { m.scene = first.scene; }
		saveMem(m);
	}

	// =====================================================================
	// The popup
	// =====================================================================

	// One shut section per kind of work: a title you click, a caret, a body.
	// Which ones you left open is remembered, because a habit is a habit.
	function sec(id, title, openByDefault, body) {
		var m = mem();
		var open = (m.sec && m.sec[id] !== undefined) ? !!m.sec[id] : !!openByDefault;
		return '<section class="dze-sec' + (open ? ' is-open' : '') + '" data-sec="' + id + '">' +
			'<h3 class="dze-sec-head" role="button" tabindex="0" aria-expanded="' + (open ? 'true' : 'false') + '">' +
				'<span class="dze-sec-caret">' + (open ? '▾' : '▸') + '</span>' + esc(title) +
				'<span class="dze-sec-count"></span>' +
			'</h3>' +
			'<div class="dze-sec-body"' + (open ? '' : ' style="display:none;"') + '>' + body + '</div>' +
		'</section>';
	}
	// Opening and closing a section is handled once, in photos.js, for every
	// screen that prints one. Here we only remember which ones were left open.
	$(document).on('dze:sec', function (e, id, on) {
		if (!id) { return; }
		var m = mem();
		m.sec = m.sec || {};
		m.sec[id] = on ? 1 : 0;
		saveMem(m);
	});
	function toggleSec($sec, on) {
		if (window.dzePhotos) { window.dzePhotos.toggleSec($sec, on); }
	}

	// ---- What the price recalculation would actually do ----
	// "Recalculate from the cost" says nothing about which cost, which table or
	// which variation. This shows the lot, before anything is written.
	$(document).on('click', '#dze-cx-pricepv', function () {
		var $b = $(this).prop('disabled', true);
		var $box = $('#dze-cx-pricebox').show().html('<span class="dze-cx-spin"></span>');
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_price_preview', nonce: cfg.nonce, post: PID,
			cost: $('#dze-cx-cost').val() || ''
		})
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $box.html('<p class="is-ko">' + esc((r && r.data && r.data.message) || i18n.error) + '</p>'); return; }
				var d = r.data, html = '';
				html += '<p class="description">' + esc(d.explain) + '</p>';
				if (d.table && d.table.length) {
					html += '<table class="dze-pricepv"><thead><tr>' +
						'<th>' + esc(i18n.pvFrom) + '</th><th>' + esc(i18n.pvTo) + '</th><th>' + esc(i18n.pvMult) + '</th>' +
						'</tr></thead><tbody>' +
						d.table.map(function (row) {
							return '<tr' + (row.hit ? ' class="is-hit"' : '') + '><td>' + esc(row.min) + '</td><td>' + esc(row.max) + '</td><td>× ' + esc(row.mult) + '</td></tr>';
						}).join('') + '</tbody></table>';
				}
				if (d.rows && d.rows.length) {
					html += '<table class="dze-pricepv"><thead><tr>' +
						'<th>' + esc(i18n.pvWhat) + '</th><th>' + esc(i18n.pvCost) + '</th>' +
						'<th>' + esc(i18n.pvNow) + '</th><th>' + esc(i18n.pvNew) + '</th>' +
						'</tr></thead><tbody>' +
						d.rows.map(function (row) {
							return '<tr><td>' + esc(row.name) + '</td><td>' + esc(row.cost) + '</td>' +
								'<td>' + esc(row.now) + '</td><td><strong>' + esc(row.next) + '</strong></td></tr>';
						}).join('') + '</tbody></table>';
				}
				$box.html(html);
			})
			.fail(function (x) { $b.prop('disabled', false); $box.html('<p class="is-ko">' + esc(reason(x)) + '</p>'); });
	});

	function build() {
		if ($('#dze-cx-modal').length) { return; }
		var m = mem(), au = m.auto || {};

		var checks = Object.keys(cfg.fields).map(function (fid) {
			var on = au.fields ? au.fields.indexOf(fid) >= 0 : true;
			// A prompt that is not validated carries the same padlock as on the
			// bulk screen — the two screens must not describe the same prompt
			// differently. It stays usable HERE, though: trying a prompt on one
			// product, with the result in front of you before anything is
			// written, is precisely how you decide to validate it. What the
			// padlock announces is that bulk will refuse it.
			var ok = !cfg.validated || cfg.validated[fid];
			return '<span class="dze-cb-checkline"><label class="dze-cb-check' + (ok ? '' : ' is-locked') + '"' +
				(ok ? '' : ' title="' + esc(i18n.notValidHere) + '"') + '>' +
				'<input type="checkbox" class="dze-cx-f" value="' + fid + '"' + (on ? ' checked' : '') + ' />' +
				'<span>' + esc(cfg.fields[fid]) + (ok ? '' : ' 🔒') + '</span></label>' + promptBtn(fid) + '</span>';
		}).join('');

		var blockers = (cfg.blockers && cfg.blockers.length)
			? '<div class="dze-cx-blocked"><strong>' + esc(i18n.blocked) + '</strong><ul>' +
				cfg.blockers.map(function (b) {
					return '<li>' + esc(b.text) + ' <a href="' + esc(b.url) + '" target="_blank" rel="noopener">' + esc(b.label) + '</a></li>';
				}).join('') + '</ul></div>'
			: '';


		$('body').append(
		'<div class="dze-cx-modal" id="dze-cx-modal"><div class="dze-cx-dialog">' +
			'<div class="dze-cx-head"><h2>' + esc(i18n.toolbox) + '</h2>' +
				'<span id="dze-cx-who" class="dze-cx-who">' + esc(cfg.product.title || '') + '</span>' +
				'<button type="button" class="button dze-cx-close">' + esc(i18n.close) + '</button></div>' +
			'<div class="dze-cx-body">' +
				blockers +
				// Grouped by KIND, one shut section each: text with text, images
				// with images, price on its own. Everything open at once is how
				// this popup became impossible to read.
				'<div class="dze-cb-controls">' +

					// ---- TEXT ----
					sec('text', i18n.text, true,
						'<div class="dze-cb-checks is-col">' + checks + '</div>'
					) +

					// ---- IMAGES ---- the main image lane and the extra shots
					// belong to the same subject and now live together.
					sec('img', i18n.image, false,
						// What the product already carries, right where images are
						// worked on — it was floating under the results panel,
						// which is not where you look for it.
						'<div class="dze-cb-nowshots" id="dze-cx-nowshots"></div>' +
						(cfg.templates.length ?
						'<div class="dze-cb-sub">' +
							'<label class="dze-cb-check"><input type="checkbox" id="dze-cx-doimg"' + (au.img ? ' checked' : '') + ' />' +
							'<span>' + esc(i18n.genImgOpt) + '</span></label>' +
							'<div class="dze-cb-opts">' +
								'<div class="dze-tplgrid' + ((cfg.scenes || []).length ? '' : ' has-noscene') + '">' + tplHead() +
									'<span class="dze-tplrows" id="dze-cx-tplrows"></span>' +
								'</div>' +
							'</div>' +
						'</div>' : '')
					) +

					// ---- VARIATIONS ---- one image per colour, written to every
					// size of that colour. Only on a product that has any.
					(cfg.product.variable ? sec('var', i18n.varTitle, false,
						'<p class="description">' + esc(i18n.varIntro) + '</p>' +
						'<div id="dze-cx-varbox" class="dze-cb-sub"></div>'
					) : '') +

					// ---- PRICE ---- shut, and it says what it will do before
					// it does it: the table it reads, and every variation it
					// would rewrite, with the figures.
					sec('price', i18n.price, false,
						'<label class="dze-cb-check"><input type="checkbox" id="dze-cx-doprice"' + (au.price ? ' checked' : '') + ' />' +
						'<span>' + esc(i18n.priceOpt) + '</span></label>' +
						'<div class="dze-cb-opts"><label><span>' + esc(i18n.costLabel) + '</span>' +
						'<input type="number" step="0.01" id="dze-cx-cost" value="' + esc(cfg.product.price) + '" /></label>' +
						'<button type="button" class="button button-small" id="dze-cx-pricepv">' + esc(i18n.pricePreview) + '</button>' +
						// The table this reads from, one click away from where it is
						// used — not hunted for in a settings tab.
						(cfg.priceUrl ? '<a class="dze-cx-priceedit" href="' + esc(cfg.priceUrl) + '" target="_blank" rel="noopener">' + esc(i18n.pvEdit) + ' →</a>' : '') +
						'</div>' +
						'<div class="dze-cx-pricebox" id="dze-cx-pricebox" style="display:none;"></div>'
					) +

					'<p class="dze-cb-actions">' +
						'<button type="button" class="button button-primary button-hero" id="dze-cx-run">' + esc(i18n.launch) + '</button>' +
						'<span class="dze-cx-state" id="dze-cx-runstate"></span>' +
					'</p>' +
					'<div class="dze-cx-prog" id="dze-cx-prog" style="display:none;">' +
						'<div class="dze-cb-bar"><div class="dze-cb-fill"></div></div>' +
						'<p><strong id="dze-cx-progcount"></strong> ' +
							'<span id="dze-cx-progstep"></span> ' +
							'<span id="dze-cx-progtime" class="description"></span></p>' +
					'</div>' +
				'</div>' +
				'<div id="dze-cx-result" class="dze-cx-result" style="display:none;">' +
					'<div class="dze-cb-prev" id="dze-cx-drawers"></div>' +
					'<div class="dze-cb-shots-slot" id="dze-cx-shots"></div>' +
					'<p class="dze-cb-panelbar">' +
						'<button type="button" class="button button-primary dze-cx-applyone">' + esc(i18n.applyOne) + '</button> ' +
						'<button type="button" class="button-link dze-cx-drop">' + esc(i18n.discard) + '</button>' +
						'<span class="dze-cb-panelstate"></span>' +
					'</p>' +
				'</div>' +
			'</div>' +
		'</div></div>');

		// What was remembered may be the old shape — a plain prompt index, from
		// the days when the scene and the count belonged to the run. It is read
		// as a row with the run's old settings, so nothing is lost on the way.
		var saved = Array.isArray(au.tpls) && au.tpls.length ? au.tpls : [ 0 ];
		saved.forEach(function (v) {
			var row = (v && typeof v === 'object') ? v : { tpl: v, scene: defaultScene(), n: au.imgn || 1 };
			$('#dze-cx-tplrows').append(tplRow(row.tpl, row.scene, row.n, row.target));
		});
		syncTplRows();
		// The section may open with the popup, remembered from last time: it
		// gets its list without waiting for a click that will not come.
		if ($('.dze-sec[data-sec="var"]').hasClass('is-open')) { loadVariations(''); }
		$(document).on('change', '.dze-cx-f, #dze-cx-doprice, #dze-cx-doimg, .dze-tpl-scene, .dze-tpl-n, .dze-tpl-target', remember);
	}

	function open(pid) {
		build();
		var target = parseInt(pid, 10) || cfg.postId || 0;
		var switching = target !== PID;
		PID = target;
		$('#dze-cx-modal').addClass('is-open');
		if (switching) {
			reset();
			// A product we were not opened on: ask the server who it is, what it
			// costs and what is already waiting on it, then arm the popup.
			$('#dze-cx-runstate').html('<span class="dze-cx-spin"></span>');
			loadCurrent().then(function (cur) {
				$('#dze-cx-runstate').empty();
				$('#dze-cx-who').text(cur.title || '');
				if (cur.cost) { $('#dze-cx-cost').val(cur.cost); }
				drawCurrentImages();
				if (cur.pending && (Object.keys(cur.pending.texts || {}).length || (cur.pending.shots || []).length)) {
					hydrate(cur.pending);
				}
			});
			return;
		}
		// Same product, popup reopened: what is waiting is asked for again. The
		// snapshot taken when the page loaded does not know about the image
		// generated two minutes ago, which is how one vanished on closing.
		res.current = null;
		loadCurrent().then(function (cur) {
			drawCurrentImages();
			if (cur.pending && (Object.keys(cur.pending.texts || {}).length || (cur.pending.shots || []).length)) {
				hydrate(cur.pending);
			}
		});
	}
	$(document).on('click', '#dze-cx-open-auto', function () { open(cfg.postId); });
	// From the products list: one chip per row, same popup.
	$(document).on('click', '.dze-content-open', function () { open($(this).data('id')); });
	$(document).on('click', '.dze-cx-close', function () { $('#dze-cx-modal').removeClass('is-open'); });
	$(document).on('click', '#dze-cx-modal', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });

	// =====================================================================
	// Drawers — same as the bulk panel
	// =====================================================================

	function editorId(fid) { return 'dze-cx-ed-' + String(fid).replace(/[^a-zA-Z0-9_-]/g, ''); }
	function isRich(fid) { return !!(cfg.rich && cfg.rich[fid]); }
	function peek(html) {
		var t = $('<div>').html(html || '').text().replace(/\s+/g, ' ').trim();
		return t ? (t.length > 110 ? t.slice(0, 110) + '…' : t) : i18n.empty;
	}
	function editorGet(eid) {
		if (window.tinymce && tinymce.get(eid) && !tinymce.get(eid).isHidden()) { return tinymce.get(eid).getContent(); }
		return $('#' + eid).val() || '';
	}
	function valueOf(fid) {
		return res.open[fid] ? editorGet(editorId(fid)) : (res.texts[fid] || '');
	}

	function drawDrawers() {
		var html = '';
		Object.keys(res.texts).forEach(function (fid) {
			var c = res.shotOf[fid];
			html += '<div class="dze-cb-fblock" data-field="' + fid + '">' +
				'<div class="dze-cb-fhead" role="button" tabindex="0" aria-expanded="false">' +
					// Accepting is not all or nothing: untick a block and it is
					// simply not written — the images, or the other texts, still
					// are. Same gesture as the tick on a generated image.
					'<input type="checkbox" class="dze-cb-fkeep" checked title="' + esc(i18n.keepHelp) + '" />' +
					'<span class="dze-cb-fcaret">▸</span>' +
					(c && c.thumb ? '<img class="dze-cb-fshot dze-hzoom" src="' + esc(c.thumb) + '" data-full="' + esc(c.full || c.thumb) + '" alt="" title="' + esc(c.feature || '') + '" />' : '') +
					'<span class="dze-cb-fname">' + esc(cfg.fields[fid] || fid) + '</span>' +
					'<span class="dze-cb-fpeek">' + esc(peek(res.texts[fid])) + '</span>' +
					'<span class="dze-cb-fstate"></span>' +
					'<button type="button" class="button button-small dze-cx-now" data-field="' + fid + '" title="' + esc(i18n.compareHelp) + '">' + esc(i18n.compare) + '</button>' +
					'<button type="button" class="button button-small dze-cx-redo" data-field="' + fid + '" title="' + esc(i18n.redoOne) + '">↻ ' + esc(i18n.redoShort) + '</button>' +
					promptBtn(fid) +
				'</div>' +
				'<div class="dze-cb-fbody" style="display:none;"></div>' +
			'</div>';
		});
		$('#dze-cx-drawers').html(html);
		$('#dze-cx-result').show();
	}

	function openField(fid, on) {
		var $b = $('#dze-cx-drawers .dze-cb-fblock[data-field="' + fid + '"]');
		var $body = $b.find('.dze-cb-fbody');
		$b.toggleClass('is-open', on);
		$b.find('.dze-cb-fhead').attr('aria-expanded', on ? 'true' : 'false');
		$b.find('.dze-cb-fcaret').text(on ? '▾' : '▸');
		if (!on) {
			if (res.open[fid]) { res.texts[fid] = editorGet(editorId(fid)); }
			$b.find('.dze-cb-fpeek').text(peek(res.texts[fid]));
			$body.hide();
			return;
		}
		$body.show();
		if (res.open[fid]) { return; }
		res.open[fid] = true;
		var eid = editorId(fid);
		$body.html(isRich(fid)
			? '<textarea id="' + eid + '" class="dze-cb-ed"></textarea>'
			: '<textarea id="' + eid + '" class="dze-cb-plain" rows="3"></textarea>');
		$('#' + eid).val(res.texts[fid] || '');
		if (isRich(fid) && window.wp && wp.editor && wp.editor.initialize) {
			try { wp.editor.remove(eid); } catch (e) {}
			wp.editor.initialize(eid, {
				tinymce: { wpautop: true, toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo', height: 220 },
				quicktags: true, mediaButtons: false
			});
		}
	}
	// Dropping a block greys the whole line, so what will NOT be written is
	// readable without opening anything.
	$(document).on('change', '#dze-cx-drawers .dze-cb-fkeep', function (e) {
		e.stopPropagation();
		$(this).closest('.dze-cb-fblock').toggleClass('is-dropped', !$(this).is(':checked'));
	});
	$(document).on('click', '#dze-cx-drawers .dze-cb-fhead', function (e) {
		if ($(e.target).closest('.dze-cx-redo, .dze-cx-now, .dze-cb-fkeep, .dze-prompt-peek').length) { return; }
		var $b = $(this).closest('.dze-cb-fblock');
		openField($b.data('field'), !$b.hasClass('is-open'));
	});
	$(document).on('keydown', '#dze-cx-drawers .dze-cb-fhead', function (e) {
		if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $(this).trigger('click'); }
	});

	// ---- What the product says today ----
	function loadCurrent() {
		if (res.current) { return $.Deferred().resolve(res.current); }
		return $.post(cfg.ajaxUrl, { action: 'dze_content_current', nonce: cfg.nonce, post: PID })
			.then(function (r) {
				res.current = (r && r.success) ? r.data : { texts: {}, images: [] };
				return res.current;
			}, function () { res.current = { texts: {}, images: [] }; return res.current; });
	}
	function drawCurrentImages() {
		// One renderer for both screens: admin/js/photos.js. The product screen
		// adds the AI button, because it has a popup to open.
		if (!window.dzePhotos) { return; }
		window.dzePhotos.render($('#dze-cx-nowshots'), (res.current && res.current.images) || [], {
			post: PID,
			ai: true,
			after: function () {
				res.current = null; // the product's photographs changed.
				loadCurrent().then(function () { drawCurrentImages(); oneDrawSources(); });
			}
		});
	}
	if (window.dzePhotos) {
		window.dzePhotos.on('ai', function () { openOne('', 'image', 'main'); });
	}
	$(document).on('click', '.dze-cx-now', function (e) {
		e.stopPropagation();
		var $btn = $(this), fid = $btn.data('field');
		var $b = $btn.closest('.dze-cb-fblock');
		if ($b.hasClass('is-comparing')) {
			$b.removeClass('is-comparing').find('.dze-cb-nowtext').remove();
			$btn.removeClass('button-primary');
			return;
		}
		if (!$b.hasClass('is-open')) { openField(fid, true); }
		$btn.addClass('button-primary');
		$b.addClass('is-comparing');
		$b.find('.dze-cb-fbody').prepend('<div class="dze-cb-nowtext"><span class="dze-cb-nowlabel">' + esc(i18n.nowText) + '</span><div class="dze-cb-nowbody">…</div></div>');
		loadCurrent().then(function (cur) {
			var val = (cur.texts || {})[fid] || '';
			$b.find('.dze-cb-nowbody').html(val ? val : esc(i18n.empty));
		});
	});

	// =====================================================================
	// Images: a strip, each with its own destination
	// =====================================================================

	// The image IS the control: where it goes and a fresh attempt are written
	// on the picture itself. The destination used to be said twice, once as a
	// caption and once as a dropdown right under it, on a thumbnail too small
	// to judge the photograph.
	function shotCard(url, cur) {
		var tpl  = res.shotTpl[url];
		var name = (cfg.templates[parseInt(tpl, 10)] || {}).name || '';
		return $('<div class="dze-cb-shotwrap"></div>').attr('data-url', url).append(
			$('<div class="dze-cb-shot"><span class="dze-cb-shotcheck">✓</span>' +
				'<button type="button" class="dze-cb-shotdrop" title="' + esc(i18n.shotDrop) + '">&times;</button></div>')
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
	function destLabel(v) {
		v = String(v || '');
		// A variation image says which colour it is for, not "gallery".
		if (0 === v.indexOf('variation:')) {
			var value = v.split('::')[1] || '';
			var g = (vars.groups || []).filter(function (x) { return x.key === v.slice(10); })[0];
			return sprintf(i18n.toVariation, g ? g.label : value);
		}
		return v === 'main' ? i18n.toMain : (v === 'gallery_first' ? i18n.toGalleryFirst : i18n.toGallery);
	}
	function isVariation(v) { return 0 === String(v || '').indexOf('variation:'); }
	function drawShots() {
		var $slot = $('#dze-cx-shots');
		// The same image can reach the strip twice — restored from an earlier
		// visit and generated again in this one. It is one image either way.
		res.shots = res.shots.filter(function (u, i) { return res.shots.indexOf(u) === i; });
		if (!res.shots.length) { $slot.empty(); return; }
		var $old = $slot.find('.dze-cb-shots'), dropped = {}, dest = {};
		$old.find('.dze-cb-shot').each(function () {
			var u = $(this).data('url');
			if (!$(this).hasClass('is-sel')) { dropped[u] = true; }
			dest[u] = $(this).find('.dze-cb-shotdest').val();
		});
		// "One more image" said nothing about WHICH image: one button per
		// recipe in use, named after it, so the style asked for is the style
		// written on the button.
		var seen = {}, more = '';
		tplUsed().forEach(function (t) {
			if (seen[t]) { return; }
			seen[t] = 1;
			var nm = (cfg.templates[parseInt(t, 10)] || {}).name || '';
			more += '<button type="button" class="button button-small dze-cx-onemore" data-tpl="' + esc(t) + '">' +
				'+ ' + esc(nm || i18n.oneMore) + '</button> ';
		});
		var $wrap = $('<div class="dze-cb-shots">' +
			'<div class="dze-cb-shothead"><span class="dze-cb-nowlabel">' + esc(i18n.shotsLabel) + '</span>' +
				more + oldMainPicker() + '</div>' +
			'<div class="dze-cb-shotgrid dze-zoomgroup"></div><span class="dze-cb-shotstate"></span></div>');
		res.shots.forEach(function (url) {
			$wrap.find('.dze-cb-shotgrid').append(
				shotCard(url, dest[url] || (res.shotTarget && res.shotTarget[url]) || 'gallery')
					.find('.dze-cb-shot').toggleClass('is-sel', !dropped[url]).end()
			);
		});
		$slot.empty().append($wrap);
		syncOldMain($slot);
		$('#dze-cx-result').show();
	}
	// What becomes of the image that holds the main slot today, asked where the
	// decision is made and only when it arises: it shows the moment one of
	// these shots is headed for the main image, and nowhere else. The choice
	// existed in the small popup and nowhere else, so accepting a main image
	// from here always pushed the old one into the gallery.
	function oldMainPicker() {
		return '<label class="dze-cb-oldmain" style="display:none;"><span>' + esc(i18n.oldMain) + '</span>' +
			'<select class="dze-cb-oldsel">' +
				'<option value="1">' + esc(i18n.oldKeep) + '</option>' +
				'<option value="0">' + esc(i18n.oldDrop) + '</option>' +
			'</select></label>';
	}
	function syncOldMain($scope) {
		var $box = $scope && $scope.length ? $scope : $('#dze-cx-shots');
		var main = $box.find('.dze-cb-shotdest').filter(function () { return 'main' === $(this).val(); }).length > 0;
		$box.find('.dze-cb-oldmain').toggle(main);
	}
	function keepOld($scope) {
		var $sel = ($scope && $scope.length ? $scope : $('#dze-cx-shots')).find('.dze-cb-oldsel');
		return ($sel.length && '0' === $sel.val()) ? 0 : 1;
	}
	$(document).on('click', '#dze-cx-shots .dze-cb-shot', function () { $(this).toggleClass('is-sel'); });
	// One click walks the three destinations. Only one image can be the main
	// one, so claiming it sends the previous claimant back to the gallery.
	$(document).on('click', '#dze-cx-shots .dze-cb-shotpos', function (e) {
		e.stopPropagation();
		var $in = $(this).closest('.dze-cb-shot').find('.dze-cb-shotdest');
		// An image made for one colour belongs to that colour: there is nothing
		// to cycle through.
		if (isVariation($in.val())) { return; }
		var order = [ 'gallery', 'gallery_first', 'main' ];
		var next = order[(order.indexOf($in.val()) + 1) % order.length];
		$in.val(next);
		$(this).text(destLabel(next));
		if ('main' !== next) { syncOldMain($('#dze-cx-shots')); return; }
		var $me = $in;
		$('#dze-cx-shots .dze-cb-shotdest').not($me).each(function () {
			if ($(this).val() === 'main') {
				$(this).val('gallery');
				$(this).closest('.dze-cb-shot').find('.dze-cb-shotpos').text(destLabel('gallery'));
			}
		});
		syncOldMain($('#dze-cx-shots'));
	});
	// A fresh attempt at THIS image, with the recipe that made it: the new one
	// takes its place in the strip instead of piling up next to it.
	$(document).on('click', '#dze-cx-shots .dze-cb-shotredo', function (e) {
		e.stopPropagation();
		var $btn = $(this).prop('disabled', true);
		var $card = $btn.closest('.dze-cb-shot');
		var url = $card.data('url');
		// Where this image was headed decides nothing about its look, but it
		// says which prompt made it when nothing else does.
		var dest = $card.find('.dze-cb-shotdest').val() || (res.shotTarget && res.shotTarget[url]) || 'gallery';
		var tpl = tplOfShot(url);
		if (null === tpl) { tpl = tplForTarget(dest); }
		var $st = $('#dze-cx-shots .dze-cb-shotstate').removeClass('is-ko').text(i18n.working);
		$card.addClass('is-busy');
		$.post(cfg.ajaxUrl, imageRequest(tpl, undefined, dest))
			.done(function (r) {
				if (!r || !r.success) {
					$btn.prop('disabled', false); $card.removeClass('is-busy');
					$st.addClass('is-ko').text(reason((r && r.data && r.data.message) || i18n.error));
					return;
				}
				var i = res.shots.indexOf(url);
				if (i >= 0) { res.shots[i] = r.data.url; } else { res.shots.push(r.data.url); }
				res.shotTpl[r.data.url] = tpl;
				delete res.shotTpl[url];
				res.shotTarget = res.shotTarget || {};
				res.shotTarget[r.data.url] = r.data.target || dest;
				delete res.shotTarget[url];
				// The prompt follows the new attempt, so a second ↻ still knows
				// what it is remaking.
				res.shotRecipe = res.shotRecipe || {};
				if (res.shotRecipe[url]) { res.shotRecipe[r.data.url] = res.shotRecipe[url]; }
				delete res.shotRecipe[url];
				$st.text('');
				drawShots();
				flagWaiting();
			})
			.fail(function (x) {
				$btn.prop('disabled', false); $card.removeClass('is-busy');
				$st.addClass('is-ko').text(reason(x));
			});
	});

	// =====================================================================
	// Generating
	// =====================================================================

	function status(text, bad) {
		$('#dze-cx-runstate').toggleClass('is-ko', !!bad).html(text || '');
	}
	function imageRequest(tpl, scene, target) {
		var job  = jobFor(tpl);
		var data = { action: 'dze_content_image', nonce: cfg.nonce, post: PID, template: tpl, mode: 'defer', stash: 1 };
		if (scene === undefined) { scene = job.scene; }
		if ((cfg.scenes || []).length) { data.scene = scene; }
		// Where it goes travels with the order, so the strip knows without
		// being told again and the choice survives a closed tab.
		data.target = target || job.target;
		return data;
	}
	function genImage(tpl, scene) {
		return $.post(cfg.ajaxUrl, imageRequest(tpl, scene))
			.then(function (r) {
				if (!r.success) { throw (r.data && r.data.message) || i18n.error; }
				res.shots.push(r.data.url);
				res.shotTpl[r.data.url] = tpl;
				res.shotTarget = res.shotTarget || {};
				if (r.data.target) { res.shotTarget[r.data.url] = r.data.target; }
				drawShots();
				flagWaiting();
			});
	}
	function genTexts(fids) {
		return $.post(cfg.ajaxUrl, { action: 'dze_content_text_all', nonce: cfg.nonce, post: PID, fields: fids, stash: 1 })
			.then(function (r) {
				if (!r.success) { throw (r.data && r.data.message) || i18n.error; }
				res.shotOf = r.data.companions || {};
				Object.keys(r.data.texts || {}).forEach(function (fid) {
					res.texts[fid] = r.data.texts[fid] || '';
					delete res.open[fid];
				});
				drawDrawers();
				flagWaiting();
			});
	}
	// The row that opened the popup learns that it now holds something.
	function flagWaiting() {
		var $chip = $('.dze-content-open[data-id="' + PID + '"]');
		if ($chip.length && !$chip.find('.dze-content-waiting').length) {
			$chip.append('<span class="dze-content-waiting">' + esc(i18n.toReview) + '</span>');
		}
	}

	// ---- Running, with a count and a clock ----
	// A spinner says "something is happening" and nothing else. On calls that
	// take a minute and a half each, what you need is how many steps there are,
	// which one is running, and how long it has been going.
	var clock = null;
	function progress(done, total, label, started) {
		$('#dze-cx-prog').show();
		var pct = total ? Math.round(100 * done / total) : 0;
		$('#dze-cx-prog .dze-cb-fill').css('width', pct + '%');
		$('#dze-cx-progcount').text(sprintf(i18n.stepN, done, total));
		$('#dze-cx-progstep').text(label || '');
		if (started) {
			var secs = Math.round((Date.now() - started) / 1000);
			$('#dze-cx-progtime').text(sprintf(i18n.elapsed, secs));
		}
	}

	$(document).on('click', '#dze-cx-run', function () {
		var $btn = $(this).prop('disabled', true);
		var fids = $('.dze-cx-f:checked').map(function () { return $(this).val(); }).get();
		var doPrice = $('#dze-cx-doprice').is(':checked');
		var doImg = $('#dze-cx-doimg').is(':checked') && cfg.templates.length;
		remember();
		if (!fids.length && !doPrice && !doImg) {
			$btn.prop('disabled', false);
			status(esc(i18n.nothingSel), true);
			return;
		}

		// The whole plan is known before the first call, so the count is a real
		// count and not a guess that grows as it goes.
		var steps = [], errs = [];
		if (fids.length) {
			steps.push({
				label: sprintf(i18n.stepTexts, fids.length),
				run: function () { return genTexts(fids); }
			});
		}
		if (doPrice) {
			steps.push({
				label: i18n.stepPrice,
				run: function () {
					return $.post(cfg.ajaxUrl, { action: 'dze_content_price', nonce: cfg.nonce, post: PID, cost: $('#dze-cx-cost').val() })
						.then(function (r) {
							if (!r.success) { throw (r.data && r.data.message) || i18n.error; }
							// Deterministic maths, applied on the spot; only a
							// simple product has that field, never a range.
							if (!r.data.variations) { $('#_regular_price').val(r.data.regular); }
						});
				}
			});
		}
		if (doImg) {
			tplJobs().forEach(function (job) {
				var name = (cfg.templates[parseInt(job.tpl, 10)] || {}).name || '';
				for (var k = 0; k < job.n; k++) {
					(function (attempt) {
						steps.push({
							label: job.n > 1
								? sprintf(i18n.stepImageN, name, attempt, job.n)
								: sprintf(i18n.stepImage, name),
							run: function () { return genImage(job.tpl, job.scene); }
						});
					}(k + 1));
				}
			});
		}

		var started = Date.now(), done = 0;
		status('');
		progress(0, steps.length, steps[0].label, started);
		window.clearInterval(clock);
		clock = window.setInterval(function () { progress(done, steps.length, null, started); }, 1000);

		(function next(i) {
			if (i >= steps.length) {
				window.clearInterval(clock);
				$btn.prop('disabled', false);
				progress(steps.length, steps.length, i18n.stepDone, started);
				loadCurrent().then(drawCurrentImages);
				if (errs.length) { status(esc(errs.join(' · ')), true); }
				return;
			}
			progress(done, steps.length, steps[i].label, started);
			steps[i].run()
				.always(function () {
					done++;
					next(i + 1);
				})
				.then(null, function (m) { errs.push(reason(m)); return $.Deferred().resolve(); });
		}(0));
	});

	// ---- Writing it again ----
	function regenerate(fids, $state) {
		var edited = fids.filter(function (fid) {
			return res.open[fid] && editorGet(editorId(fid)) !== (res.texts[fid] || '');
		});
		if (edited.length && !window.confirm(sprintf(i18n.confirmRedo, edited.length))) { return; }
		$state.removeClass('is-ko').text(i18n.working);
		$.post(cfg.ajaxUrl, { action: 'dze_content_text_all', nonce: cfg.nonce, post: PID, fields: fids, stash: 1 })
			.done(function (r) {
				if (!r || !r.success) { $state.addClass('is-ko').text(reason((r && r.data && r.data.message) || i18n.error)); return; }
				fids.forEach(function (fid) {
					res.texts[fid] = (r.data.texts || {})[fid] || '';
					var eid = editorId(fid);
					$('#dze-cx-drawers .dze-cb-fblock[data-field="' + fid + '"]').find('.dze-cb-fpeek').text(peek(res.texts[fid]));
					if (res.open[fid]) {
						if (window.tinymce && tinymce.get(eid) && !tinymce.get(eid).isHidden()) { tinymce.get(eid).setContent(res.texts[fid]); }
						else { $('#' + eid).val(res.texts[fid]); }
					}
				});
				$state.text('✓');
				window.setTimeout(function () { $state.text(''); }, 2000);
			})
			.fail(function (x) { $state.addClass('is-ko').text(reason(x)); });
	}
	$(document).on('click', '.dze-cx-redo', function (e) {
		e.stopPropagation();
		regenerate([ $(this).data('field') ], $(this).closest('.dze-cb-fhead').find('.dze-cb-fstate'));
	});
	$(document).on('click', '.dze-cx-onemore', function () {
		var $btn = $(this).prop('disabled', true);
		var $st = $('#dze-cx-shots .dze-cb-shotstate').removeClass('is-ko').text(i18n.working);
		var tpl = $btn.data('tpl');
		genImage(tpl === undefined ? (tplUsed()[0] || '0') : String(tpl))
			.always(function () { $btn.prop('disabled', false); })
			.then(function () { $st.text(''); }, function (m) { $st.addClass('is-ko').text(reason(m)); });
	});

	// ---- Accepting ----
	$(document).on('click', '.dze-cx-applyone', function () {
		var $btn = $(this).prop('disabled', true);
		var $st = $('#dze-cx-result .dze-cb-panelstate').removeClass('is-ko').text(i18n.applying);
		var items = [];
		$('#dze-cx-shots .dze-cb-shot.is-sel').each(function () {
			items.push({
				url: $(this).data('url'),
				target: $(this).closest('.dze-cb-shotwrap').find('.dze-cb-shotdest').val() || 'gallery'
			});
		});
		// Only the blocks still ticked are written; the rest is simply dropped.
		var fids = Object.keys(res.texts).filter(function (fid) {
			var $k = $('#dze-cx-drawers .dze-cb-fblock[data-field="' + fid + '"]').find('.dze-cb-fkeep');
			return !$k.length || $k.is(':checked');
		});
		var ok = 0, ko = 0;
		if (!fids.length && !items.length) {
			$btn.prop('disabled', false);
			$st.addClass('is-ko').text(i18n.nothingKept);
			return;
		}

		function texts(i) {
			if (i >= fids.length) { return finish(); }
			var fid = fids[i];
			$.post(cfg.ajaxUrl, { action: 'dze_content_apply', nonce: cfg.nonce, post: PID, field: fid, value: valueOf(fid) })
				.done(function (r) {
					var $s = $('#dze-cx-drawers .dze-cb-fblock[data-field="' + fid + '"]').find('.dze-cb-fstate');
					if (r && r.success) { ok++; $s.removeClass('is-ko').text('✓'); }
					else { ko++; $s.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); }
				})
				.fail(function () { ko++; })
				.always(function () { texts(i + 1); });
		}
		function finish() {
			$btn.prop('disabled', false);
			if (ko) { $st.addClass('is-ko').text(sprintf(i18n.partial, ok, ok + ko)); return; }
			$st.text(i18n.applied);
			// Only what was just written stops waiting. An image generated and
			// left undecided is still there the next time the popup opens.
			$.post(cfg.ajaxUrl, {
				action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID,
				fields: fids, shots: items.map(function (it) { return it.url; })
			}).always(function () {
				res.shots = res.shots.filter(function (u) {
					return items.every(function (it) { return it.url !== u; });
				});
				res.texts = {};
				$('#dze-cx-drawers').empty();
				drawShots();
				if (!res.shots.length) { $('#dze-cx-result').hide(); }
				loadCurrent().then(drawCurrentImages);
			});
			$('.dze-content-open[data-id="' + PID + '"]').find('.dze-content-waiting').remove();
			res.current = null;
		}
		if (items.length) {
			$.post(cfg.ajaxUrl, {
				action: 'dze_content_image_attach', nonce: cfg.nonce, post: PID, items: items,
				keep_old: keepOld($('#dze-cx-shots')),
				// The prompt that made them names the files it produced.
				recipe: (items.length && (
					(res.shotRecipe && res.shotRecipe[items[0].url]) ||
					(res.shotTpl && res.shotTpl[items[0].url])
				)) || ''
			})
				.done(function (r) {
					if (r && r.success) {
						ok++;
						$('#dze-cx-shots .dze-cb-shot').removeClass('is-sel');
						res.shots = [];
						drawShots();
						// Same as the small popup: the WordPress boxes behind
						// are brought up to date without a page reload.
						refreshBoxes();
						res.current = null;
						loadCurrent().then(drawCurrentImages);
					} else { ko++; }
				})
				.fail(function () { ko++; })
				.always(function () { texts(0); });
		} else {
			texts(0);
		}
	});

	$(document).on('click', '.dze-cx-drop', function () {
		if (!window.confirm(i18n.confirmDrop)) { return; }
		$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID });
		$('.dze-content-open[data-id="' + PID + '"]').find('.dze-content-waiting').remove();
		var keep = res.current;
		reset();
		res.current = keep;
		drawCurrentImages();
	});

	// ---- Content left waiting from an earlier visit ----
	function hydrate(waiting) {
		res.texts = waiting.texts || {};
		res.shotOf = waiting.companions || {};
		res.shots = (waiting.shots || []).slice();
		// What each waiting image was made for, and by which prompt.
		res.shotTarget = waiting.targets || {};
		res.shotRecipe = waiting.recipes || {};
		res.open = {};
		if (Object.keys(res.texts).length) { drawDrawers(); }
		drawShots();
		loadCurrent().then(drawCurrentImages);
	}

	// =====================================================================
	// One block at a time, from the block itself
	// =====================================================================
	//
	// The big popup is for working through a whole product. Most of the time
	// the job is smaller than that: this description, that image. So every
	// block WordPress already shows carries its own button, and the button
	// opens a popup with that one function in it — read the instructions,
	// change them for one run if you want, write, compare, save.

	var one = { fid: '', mode: 'text', value: '', tries: [], keep: {} };

	function oneBuild() {
		if ($('#dze-one').length) { return; }
		$('body').append(
		'<div class="dze-cx-modal" id="dze-one"><div class="dze-cx-dialog dze-one-dialog">' +
			'<div class="dze-cx-head">' +
				'<h2 id="dze-one-title"></h2>' +
				'<button type="button" class="button dze-hub-close" style="margin-left:auto;">' + esc(i18n.close) + '</button>' +
			'</div>' +
			'<div class="dze-cx-body">' +
				'<div id="dze-one-body"></div>' +
			'</div>' +
			// The buttons live in the dialog's footer, not at the end of the
			// scroll: what you do with what you are looking at must not depend
			// on how much of it there is.
			'<div class="dze-cx-foot">' +
				'<p class="dze-qm-bar" id="dze-one-dest" style="display:none;">' +
				'<label class="dze-qm-bglabel"><span>' + esc(i18n.imgWhere) + '</span>' +
					'<select id="dze-one-target">' +
						'<option value="main">' + esc(i18n.toMain) + '</option>' +
						'<option value="gallery_first">' + esc(i18n.toGalleryFirst) + '</option>' +
						'<option value="gallery">' + esc(i18n.toGallery) + '</option>' +
					'</select></label>' +
				// Taking the main slot decides the fate of the image that held
				// it. It was always pushed into the gallery; on a product whose
				// old main image is a supplier shot you are replacing, that is
				// the last place you want it.
				'<label class="dze-qm-bglabel" id="dze-one-oldwrap"><span>' + esc(i18n.oldMain) + '</span>' +
					'<select id="dze-one-oldmain">' +
						'<option value="1">' + esc(i18n.oldKeep) + '</option>' +
						'<option value="0">' + esc(i18n.oldDrop) + '</option>' +
					'</select></label>' +
				'<label id="dze-one-replacewrap" style="display:none;"><input type="checkbox" id="dze-one-replace" /> ' + esc(i18n.imgReplace) + '</label>' +
				'</p>' +
				'<p class="dze-one-bar">' +
					'<label class="dze-qm-bglabel" id="dze-one-nwrap" style="display:none;"><span>' + esc(i18n.howMany) + '</span>' +
					'<select id="dze-one-n">' +
						'<option value="1">1</option><option value="2">2</option>' +
						'<option value="3">3</option><option value="4">4</option>' +
					'</select></label> ' +
				'<button type="button" class="button button-primary" id="dze-one-gen"></button> ' +
					'<button type="button" class="button button-primary" id="dze-one-apply" style="display:none;"></button> ' +
					'<span class="dze-cx-state" id="dze-one-state"></span>' +
				'</p>' +
			'</div>' +
		'</div></div>');
		$(document).on('click', '#dze-one', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });
	}

	// The instructions, built fresh every time the popup opens. It used to be
	// one node created once and MOVED between the top of the popup and the
	// recipe card — but opening the popup rewrites its body, which destroyed
	// the node on the way. From the second opening on there was nothing left
	// to open, which is why the pencil on a gallery recipe did nothing.
	function instrBlock() {
		return '<details class="dze-one-instr" id="dze-one-instrwrap">' +
			'<summary>' + esc(i18n.oneInstr) + '</summary>' +
			'<p class="description">' + esc(i18n.oneInstrH) + '</p>' +
			'<p class="dze-one-tabs">' +
				'<button type="button" class="button button-small is-sel" data-pane="prompt">' + esc(i18n.panePrompt) + '</button>' +
				'<button type="button" class="button button-small" data-pane="data">' + esc(i18n.paneData) + '</button>' +
			'</p>' +
			'<div class="dze-one-pane" data-pane="data" style="display:none;">' +
				'<pre class="dze-prompt-text" id="dze-one-data"></pre>' +
				'<p class="description">' + esc(i18n.paneDataH) + '</p>' +
			'</div>' +
			'<div class="dze-one-pane" data-pane="prompt">' +
				'<textarea id="dze-one-prompt" rows="7" class="large-text code"></textarea>' +
				// The prompt's own settings, editable here and not only in the
				// settings screen: reading them there while writing the prompt
				// here is what made the toolbox a read-only cousin.
				'<div id="dze-one-psets"></div>' +
				'<p><button type="button" class="button-link" id="dze-one-saveprompt">&#128190; ' + esc(i18n.oneSave) + '</button> ' +
					'<span class="description" id="dze-one-savestate"></span></p>' +
			'</div>' +
		'</details>';
	}

	// What this prompt receives, and how it pairs with a photograph. Same two
	// settings as the card in Settings → Prompts, on the same row.
	function promptSettings(rowId) {
		var row = (cfg.rowcfg && cfg.rowcfg[rowId]) || {};
		var have = row.inputs || [];
		var opts = cfg.inputOpts || {};
		var boxes = Object.keys(opts).map(function (k) {
			return '<label class="dze-ps-in"><input type="checkbox" class="dze-ps-input" value="' + esc(k) + '"' +
				(have.indexOf(k) >= 0 ? ' checked' : '') + ' /><span>' + esc(opts[k]) + '</span></label>';
		}).join('');
		var pair = '';
		if ('image' !== row.type) {
			pair = '<details class="dze-ps-pair"' + (row.img_meta ? ' open' : '') + '>' +
				'<summary>' + esc(i18n.psPair) +
					'<span class="dze-pr-pairstate' + (row.img_meta ? ' is-on' : '') + '">' +
					(row.img_meta ? esc(sprintf(i18n.psOn, row.img_meta)) : esc(i18n.psOff)) + '</span>' +
				'</summary>' +
				'<p class="description">' + esc(i18n.psPairH) + '</p>' +
				'<p class="dze-ps-line"><label><span>' + esc(i18n.psKey) + '</span>' +
					'<input type="text" id="dze-one-imgmeta" list="dze-one-metakeys" value="' + esc(row.img_meta || '') + '" placeholder="_bloc_image_1" /></label>' +
					'<datalist id="dze-one-metakeys">' +
						(cfg.metaKeys || []).map(function (k) { return '<option value="' + esc(k) + '"></option>'; }).join('') +
					'</datalist></p>' +
				'<p class="description" style="margin-bottom:4px;">' + esc(i18n.psRules) + '</p>' +
				'<textarea id="dze-one-imgrules" rows="3" class="large-text code" placeholder="' +
					esc(cfg.imgRulesDef || '') + '">' + esc(row.img_rules || '') + '</textarea>' +
			'</details>';
		}
		return '<details class="dze-ps-data">' +
				'<summary>' + esc(i18n.psData) + ' (' + have.length + ')</summary>' +
				'<div class="dze-ps-ins">' + boxes + '</div>' +
			'</details>' + pair;
	}
	function oneFillSettings(rowId) {
		$('#dze-one-psets').html(rowId ? promptSettings(rowId) : '');
	}

	function openOne(fid, mode, scope) {
		oneBuild();
		one = { fid: fid, mode: mode || 'text', value: '', tries: [], keep: {}, scope: scope || 'main' };
		var label = mode === 'image'
			? (one.scope === 'gallery' ? i18n.oneGallery : i18n.qmTitle)
			: (cfg.fields[fid] || fid);
		$('#dze-one-title').text(label);

		$('#dze-one-state').removeClass('is-ko').text('');
		// The decision bar belongs to a result: there is none yet.
		$('#dze-one-dest, #dze-one-pair').hide();
		$('#dze-one-apply').hide().text(i18n.oneApply);
		$('#dze-one-gen').text(mode === 'image'
			? (one.scope === 'gallery' ? i18n.imgRun : i18n.oneMain)
			: i18n.oneGen);
		$('#dze-one-body').html(mode === 'image' ? oneImageBody() : instrBlock());
		$('#dze-one-prompt').val(mode === 'image' ? (cfg.quickPrompt || '') : ((cfg.prompts && cfg.prompts[fid]) || ''));
		if ('image' !== mode) { oneFillSettings(fid); }
		// Asking for several at once is only offered where several make sense.
		$('#dze-one-nwrap').toggle('image' === mode);
		$('#dze-one').addClass('is-open');
		if (mode === 'image') {
			one.srcId = 0; one.paste = '';
			oneDrawRecipes();
			oneSetRecipe(oneRecipes()[0] ? String(oneRecipes()[0].id) : '');
			oneDrawSources();
		}
		if (mode === 'text') { oneShowBefore(fid); }
	}

	// The image workshop, as three plain questions asked in the order they get
	// answered: what are we making, from which photograph, on which surface.
	// It used to open on two dropdowns, a paste box and a URL field before it
	// said what any of it was for.
	function oneImageBody() {
		return '<div class="dze-one-img">' +
			'<input type="hidden" id="dze-one-recipe" value="" />' +
			'<input type="hidden" id="dze-one-bg" value="' + esc(String(defaultBg())) + '" />' +

			'<div class="dze-step">' +
				'<p class="dze-step-q"><span class="dze-step-n">1</span>' + esc(i18n.stepWhat) + '</p>' +
				'<div class="dze-one-recipes" id="dze-one-recipes"></div>' +
				// The instructions belong to the recipe above them, so they are
				// moved here rather than kept in a bar of their own at the top
				// of the popup, which said the same thing twice.
				instrBlock() +
			'</div>' +

			'<div class="dze-step">' +
				'<p class="dze-step-q"><span class="dze-step-n">2</span>' + esc(i18n.stepFrom) + '</p>' +
				'<div class="dze-one-srcs" id="dze-one-srcs"></div>' +
				'<div id="dze-one-elsewrap" style="display:none;">' +
					'<div class="dze-qm-drop" id="dze-one-drop" tabindex="0">' +
						'<span class="dze-qm-dropmsg">' + esc(i18n.qmPaste) + '</span>' +
					'<button type="button" class="button button-small dze-qm-browse">' + esc(i18n.qmBrowse) + '</button>' +
					'<input type="file" accept="image/*" class="dze-qm-file" hidden />' +
						'<img id="dze-one-src" alt="" style="display:none;" />' +
					'</div>' +
				'</div>' +
			'</div>' +

			'<div class="dze-step" id="dze-one-bgstep">' +
				'<p class="dze-step-q"><span class="dze-step-n">3</span>' + esc(i18n.stepBg) + '</p>' +
				'<div class="dze-one-bgs" id="dze-one-bgs"></div>' +
			'</div>' +

			'<div class="dze-qm-pair dze-zoomgroup" id="dze-one-pair" style="display:none;">' +
				'<figure id="dze-one-oldfig"><figcaption id="dze-one-oldcap"></figcaption>' +
					'<img id="dze-one-old" alt="" /></figure>' +
				'<div class="dze-one-tries">' +
					'<figcaption id="dze-one-trycap"></figcaption>' +
					'<div class="dze-one-trygrid" id="dze-one-trygrid"></div>' +
				'</div>' +
			'</div>' +
		'</div>';
	}
	function defaultBg() {
		var list = cfg.backdrops || [];
		return list.length ? list[0].id : 0;
	}

	// Question 1: one card per recipe, named, with its own ✎ to read and edit
	// the instructions behind it. A dropdown hid both the choice and the fact
	// that there was one.
	// Only the recipes that write where this box shows: the featured image, or
	// the gallery. A recipe's own destination decides, so adding one in the
	// settings puts it under the right box by itself.
	// The opening sentence of a prompt, as a caption: enough to tell two
	// recipes apart without opening either.
	function firstLine(prompt) {
		var t = String(prompt || '').replace(/\s+/g, ' ').trim();
		var stop = t.indexOf('. ');
		if (stop > 20) { t = t.slice(0, stop + 1); }
		return t.length > 120 ? t.slice(0, 120) + '…' : t;
	}
	function oneRecipes() {
		var tpls = (cfg.templates || []);
		return tpls.filter(function (t) {
			return one.scope === 'gallery' ? t.target !== 'main' : t.target === 'main';
		});
	}
	function oneDrawRecipes() {
		var cur = $('#dze-one-recipe').val() || '';
		var cards = oneRecipes();
		if (!cards.length) {
			$('#dze-one-recipes').html('<span class="description">' + esc(i18n.noRecipes) + '</span>');
			return;
		}
		var html = '';
		cards.forEach(function (t) {
			// A name alone did not say what the recipe does, and the difference
			// between two of them was only readable by opening both prompts.
			var what = firstLine(t.prompt);
			html += '<button type="button" class="dze-one-recipe' + (String(t.id) === String(cur) ? ' is-sel' : '') + '" ' +
				'data-id="' + esc(String(t.id)) + '">' +
				'<span class="dze-one-recipetxt">' +
					'<span class="dze-one-recipename">' + esc(t.name) + '</span>' +
					(what ? '<span class="dze-one-recipewhat">' + esc(what) + '</span>' : '') +
				'</span>' +
				'<span class="dze-one-recipepen" title="' + esc(i18n.promptTip) + '">&#9998;</span></button>';
		});
		$('#dze-one-recipes').html(html);
	}
	$(document).on('click', '.dze-one-recipe', function (e) {
		var pen = $(e.target).hasClass('dze-one-recipepen');
		oneSetRecipe($(this).data('id') === undefined ? '' : String($(this).data('id')));
		// The pencil picks the recipe AND opens its instructions: reading them
		// is how you tell whether this is the right recipe.
		if (pen) {
			var $w = $('#dze-one-instrwrap');
			$w.prop('open', !$w.prop('open'));
		}
	});
	function oneSetRecipe(v) {
		$('#dze-one-recipe').val(v);
		$('.dze-one-recipe').removeClass('is-sel').filter(function () {
			return String($(this).data('id')) === String(v);
		}).addClass('is-sel');
		var t = (cfg.templates || []).filter(function (x) { return String(x.id) === String(v); })[0];
		$('#dze-one-prompt').val(t ? t.prompt : '');
		$('#dze-one-target').val((t && t.target === 'main') ? 'main' : 'gallery');
		// A surface is what the MAIN image is shot on. The other recipes work
		// on a photograph that already has its own, so the question is folded
		// away rather than asked every time for nothing.
		var mainish = !!(t && t.target === 'main');
		$('#dze-one-bgstep').toggle(!!mainish);
		if (!mainish) { $('#dze-one-bg').val('0'); }
		else if (!$('#dze-one-bg').val() || $('#dze-one-bg').val() === '0') { $('#dze-one-bg').val(String(defaultBg())); }
		oneDrawBgs();
		oneFillSettings(v);
		if ($('.dze-one-pane[data-pane="data"]').is(':visible')) { oneLoadData(); }
	}

	// Question 3: the surfaces, as pictures. "None" first, then the shelf, then
	// the button that adds one from the media library.
	function oneDrawBgs() {
		var $slot = $('#dze-one-bgs');
		if (!$slot.length) { return; }
		var cur = String($('#dze-one-bg').val() || '0');
		var html = '<button type="button" class="dze-one-bg' + ('0' === cur ? ' is-sel' : '') + '" data-id="0">' +
			'<span class="dze-one-bgnone">' + esc(i18n.qmBgNone) + '</span></button>';
		(cfg.backdrops || []).forEach(function (b) {
			html += '<button type="button" class="dze-one-bg' + (String(b.id) === cur ? ' is-sel' : '') + '" data-id="' + b.id + '">' +
				(b.thumb ? '<img src="' + esc(b.thumb) + '" alt="" />' : '') +
				'<span class="dze-one-bgname">' + esc(b.name) + '</span></button>';
		});
		html += '<button type="button" class="dze-one-bg dze-one-bgadd dze-bg-add" data-for="dze-one-bg" title="' +
			esc(i18n.bgAdd) + '">+</button>';
		$slot.html(html);
	}
	$(document).on('click', '.dze-one-bg', function () {
		if ($(this).hasClass('dze-one-bgadd')) { return; }
		$('#dze-one-bg').val(String($(this).data('id')));
		$('.dze-one-bg').removeClass('is-sel');
		$(this).addClass('is-sel');
	});

	// Question 2: the product's own photographs, to pick the one being worked
	// on. An image from somewhere else is a rarer case, kept behind a link.
	function oneSrcStrip(imgs) {
		// "Every photograph", then the product's own, then — always, whether or
		// not the product has any — a tile for an image that is not on the
		// product yet. It is drawn with the popup rather than waiting for the
		// product to be read, so it is never missing.
		var html = '<button type="button" class="dze-one-srcpick is-sel" data-id="0">' + esc(i18n.imgAll) + '</button>';
		(imgs || []).forEach(function (im) {
			if (!im.id) { return; }
			html += '<button type="button" class="dze-one-srcpick" data-id="' + im.id + '">' +
				'<img class="dze-hzoom" src="' + esc(im.thumb) + '" data-full="' + esc(im.full || im.thumb) + '" alt="" /></button>';
		});
		html += '<button type="button" class="dze-one-srcpick dze-one-srcnew" data-id="new">' +
			'<img id="dze-one-newthumb" alt="" style="display:none;" />' +
			'<span class="dze-one-newmsg">&#43; ' + esc(i18n.stepElse) + '</span></button>';
		return html;
	}
	function oneDrawSources() {
		var $slot = $('#dze-one-srcs').addClass('dze-zoomgroup');
		if (!$slot.length) { return; }
		$slot.html(oneSrcStrip([]));
		loadCurrent().then(function (cur) {
			// Something was chosen while the product was loading: leave it be.
			if (one.srcId || one.paste) { return; }
			$slot.html(oneSrcStrip(cur.images || []));
		});
	}
	$(document).on('click', '.dze-one-tabs button', function () {
		var pane = $(this).data('pane');
		$('.dze-one-tabs button').removeClass('is-sel');
		$(this).addClass('is-sel');
		$('.dze-one-pane').each(function () { $(this).toggle($(this).data('pane') === pane); });
		if ('data' === pane) { oneLoadData(); }
	});
	function oneLoadData() {
		var row = (one.mode === 'image') ? ($('#dze-one-recipe').val() || cfg.mainRecipe || '') : one.fid;
		var $out = $('#dze-one-data').text('…');
		$.post(cfg.ajaxUrl, { action: 'dze_content_inputs', nonce: cfg.nonce, post: PID, row: row })
			.done(function (r) {
				$out.text((r && r.success) ? r.data.text : ((r && r.data && r.data.message) || i18n.error));
			})
			.fail(function (x) { $out.text(reason(x)); });
	}
	$(document).on('click', '.dze-one-srcpick', function () {
		$('.dze-one-srcpick').removeClass('is-sel');
		$(this).addClass('is-sel');
		var raw = String($(this).data('id'));
		var outside = 'new' === raw;
		var id = outside ? 0 : (parseInt(raw, 10) || 0);
		one.srcId = id;
		// The box to paste into belongs to that tile: it is on screen when the
		// tile is chosen, and out of the way the rest of the time.
		$('#dze-one-elsewrap').toggle(outside);
		if (!outside) { oneShowPasted(''); }
		else { $('#dze-one-drop').trigger('focus'); }
		// Only a photograph of the product can be retired by its own remake.
		$('#dze-one-replacewrap').toggle(!!id);
		if (!id) { $('#dze-one-replace').prop('checked', false); }
	});
	// One place that says "this is the image we work from", whether it arrived
	// by Ctrl+V, by drag and drop, or by its address.
	function oneShowPasted(dataUri) {
		one.paste = dataUri || '';
		$('#dze-one-src').attr('src', one.paste).toggle(!!one.paste);
		$('#dze-one-drop').toggleClass('has-img', !!one.paste)
			.find('.dze-qm-dropmsg').text(one.paste ? i18n.qmPasted : i18n.qmPaste);
		$('#dze-one-newthumb').attr('src', one.paste).toggle(!!one.paste);
		$('.dze-one-srcnew .dze-one-newmsg').toggle(!one.paste);
	}
	function oneReadFile(file) {
		if (!file || !/^image\//.test(file.type)) { return; }
		var fr = new FileReader();
		fr.onload = function () {
			$('.dze-one-srcpick').removeClass('is-sel');
			$('.dze-one-srcnew').addClass('is-sel');
			one.srcId = 0;
			$('#dze-one-elsewrap').show();
			$('#dze-one-replacewrap').hide();
			oneShowPasted(String(fr.result));
		};
		fr.readAsDataURL(file);
	}
	// Choosing a file from the computer is the same road as pasting one: it is
	// read in the browser and travels as bytes inside the request. Nothing is
	// uploaded to the media library, so the site stores nothing for an image
	// that is only a source.
	$(document).on('click', '.dze-qm-browse', function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).closest('.dze-qm-drop').find('.dze-qm-file').trigger('click');
	});
	$(document).on('change', '.dze-qm-file', function () {
		var file = this.files && this.files[0];
		this.value = '';
		if (!file) { return; }
		if (file) { oneReadFile(file); }
	});
	$(document).on('dragover', '#dze-one-drop', function (e) { e.preventDefault(); $(this).addClass('is-over'); });
	$(document).on('dragleave drop', '#dze-one-drop', function () { $(this).removeClass('is-over'); });
	$(document).on('drop', '#dze-one-drop', function (e) {
		e.preventDefault();
		var dt = e.originalEvent && e.originalEvent.dataTransfer;
		if (dt && dt.files && dt.files.length) { oneReadFile(dt.files[0]); }
	});

	// What the product says today, above what was just written: the same
	// before/after as everywhere else in the plugin.
	function oneShowBefore(fid) {
		// Appended, never .html(): the instructions are already in this body and
		// replacing it would throw them away — the exact bug that made the
		// pencil dead on the second opening.
		$('#dze-one-body').find('.dze-cb-nowtext').remove();
		$('#dze-one-body').append('<div class="dze-cb-nowtext"><span class="dze-cb-nowlabel">' + esc(i18n.oneBefore) +
			'</span><div class="dze-cb-nowbody" id="dze-one-before"><span class="dze-cx-spin"></span></div></div>');
		loadCurrent().then(function (cur) {
			var v = (cur.texts || {})[fid] || '';
			$('#dze-one-before').html(v ? $('<div>').html(v).html() : esc(i18n.empty));
		});
	}

	var ONE_ED = 'dze-one-ed';
	function oneShowResult(text) {
		one.value = text;
		var $wrap = $('<div class="dze-one-after"><span class="dze-cb-nowlabel">' + esc(i18n.oneAfter) + '</span></div>');
		$wrap.append('<textarea id="' + ONE_ED + '" class="dze-cb-ed"></textarea>');
		$('#dze-one-body').append($wrap);
		$('#' + ONE_ED).val(text);
		if (window.wp && wp.editor && wp.editor.initialize) {
			try { wp.editor.remove(ONE_ED); } catch (e) {}
			wp.editor.initialize(ONE_ED, {
				tinymce: { wpautop: true, toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo', height: 220 },
				quicktags: true, mediaButtons: false
			});
		}
		$('#dze-one-gen').text(i18n.oneRedo);
		$('#dze-one-apply').show();
	}

	$(document).on('click', '#dze-one-gen', function () {
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-one-state').removeClass('is-ko').text(i18n.generating);
		var prompt = $('#dze-one-prompt').val() || '';

		if (one.mode === 'image') {
			// Asking for several at once is one request after another, not four
			// at the same time: the provider is billed per image and answers in
			// its own time, and a burst of parallel calls is how a run trips the
			// budget guard halfway through.
			var want = Math.max(1, parseInt($('#dze-one-n').val(), 10) || 1);
			var made = 0;
			var shoot = function () {
				$st.text(want > 1 ? sprintf(i18n.tryN, made + 1, want) : i18n.generating);
				$.post(cfg.ajaxUrl, {
					action: 'dze_content_quick_main', nonce: cfg.nonce, post: PID,
					paste: one.paste || '',
					src_id: one.srcId || 0, recipe: $('#dze-one-recipe').val() || '',
					bg: $('#dze-one-bg').val() || 0, prompt: prompt
				})
					.done(function (r) {
						if (!r || !r.success) {
							$b.prop('disabled', false);
							$st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error);
							return;
						}
						made++;
						// Every attempt is paid for: none of them is thrown away
						// behind the next one. They line up and you compare.
						one.tries = one.tries || [];
						one.tries.push(r.data.url);
						// A fresh attempt arrives kept: the common case is to
						// take what you just asked for, and unticking is one
						// click when it is not.
						one.keep[r.data.url] = true;
						// What the new image should be judged against depends on
						// what is being made: the main image is replacing the main
						// image, a gallery shot is not — there it is the photograph
						// it was worked from that means something.
						var ref = oneReference(r.data.main || '');
						$('#dze-one-oldcap').text(ref.caption);
						$('#dze-one-old').attr('src', ref.url).attr('data-full', ref.url)
							.closest('figure').toggle(!!ref.url);
						oneDrawTries();
						$('#dze-one-pair').show();
						$('#dze-one-dest').show();
						$('#dze-one-oldwrap').toggle('main' === ($('#dze-one-target').val() || 'main'));
						$('#dze-one-gen').text(i18n.qmAgain);
						if (made < want) { shoot(); return; }
						$b.prop('disabled', false);
						$st.text('');
					})
					.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
			};
			shoot();
			return;
		}

		$.post(cfg.ajaxUrl, {
			action: 'dze_content_text', nonce: cfg.nonce, post: PID, field: one.fid, prompt: prompt
		})
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
				$st.text('');
				$('#dze-one-body .dze-one-after').remove();
				oneShowResult(r.data.text || '');
			})
			.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});

	// =====================================================================
	// Variation images: one photograph per colour, not one per variation
	// =====================================================================

	// A product sold in three colours and five sizes is fifteen variations and
	// three photographs. The groups come from the server, which knows which
	// attribute changes what the product looks like and which groups have no
	// photograph of their own — the gap this fills.
	var vars = { attr: '', groups: [], loaded: false };

	function varTemplates() {
		return (cfg.templates || []).map(function (t, i) { return { i: i, t: t }; })
			.filter(function (o) { return 'variation' === o.t.target; });
	}
	function loadVariations(attr) {
		var $box = $('#dze-cx-varbox');
		if (!$box.length) { return; }
		$box.html('<p class="description">' + esc(i18n.working) + '</p>');
		$.post(cfg.ajaxUrl, { action: 'dze_content_variations', nonce: cfg.nonce, post: PID, attr: attr || '' })
			.done(function (r) {
				if (!r || !r.success) { $box.html('<p class="is-ko">' + esc((r && r.data && r.data.message) || i18n.error) + '</p>'); return; }
				vars = { attr: r.data.attr, label: r.data.label, choices: r.data.choices || [], groups: r.data.groups || [], loaded: true };
				drawVariations();
			})
			.fail(function (x) { $box.html('<p class="is-ko">' + esc(reason(x)) + '</p>'); });
	}
	function drawVariations() {
		var $box = $('#dze-cx-varbox');
		if (!$box.length) { return; }
		if (!vars.groups.length) { $box.html('<p class="description">' + esc(i18n.varNone) + '</p>'); return; }
		var tpls = varTemplates();
		if (!tpls.length) {
			$box.html('<p class="description">' + esc(i18n.varNoPrompt) + '</p>');
			return;
		}
		// Which attribute the groups are built on: the guess is shown and can
		// be changed, rather than being silently right or silently wrong.
		var by = '';
		if ((vars.choices || []).length > 1) {
			by = '<label><span>' + esc(i18n.varGroupBy) + '</span>' +
				'<select id="dze-cx-varattr">' + vars.choices.map(function (c) {
					return '<option value="' + esc(c.key) + '"' + (c.key === vars.attr ? ' selected' : '') + '>' + esc(c.label) + '</option>';
				}).join('') + '</select></label> ';
		}
		var rows = vars.groups.map(function (g) {
			var none = !g.with;
			return '<label class="dze-var-row' + (none ? ' is-empty' : '') + '">' +
				'<input type="checkbox" class="dze-cx-var" value="' + esc(g.key) + '"' + (none ? ' checked' : '') + ' />' +
				(g.thumb ? '<img src="' + esc(g.thumb) + '" data-full="' + esc(g.thumb) + '" alt="" />' : '<span class="dze-var-nothumb">—</span>') +
				'<span class="dze-var-name">' + esc(g.label) + '</span>' +
				'<span class="dze-var-state">' + esc(none ? i18n.varHasNone : sprintf(i18n.varCount, g.total, g.with)) + '</span>' +
				'</label>';
		}).join('');
		// The same three questions as everywhere else — which prompt, on which
		// scene, how many — as one row of labelled selects, like the Reviews
		// block right above it.
		$box.html(
			'<div class="dze-cb-opts">' +
				'<label><span>' + esc(i18n.template) + '</span>' +
					'<select id="dze-cx-vartpl">' + tpls.map(function (o) {
						return '<option value="' + o.i + '">' + esc(o.t.name) + '</option>';
					}).join('') + '</select></label>' +
				((cfg.scenes || []).length
					? '<label><span>' + esc(i18n.scene) + '</span>' + sceneSelect(defaultScene()).replace('dze-tpl-scene', 'dze-var-scene') + '</label>'
					: '') +
				'<label><span>' + esc(i18n.attempts) + '</span>' + nSelect(1).replace('dze-tpl-n', 'dze-var-n') + '</label>' +
				(by ? by : '') +
			'</div>' +
			'<div class="dze-var-list dze-zoomgroup">' + rows + '</div>' +
			'<p class="dze-nowbar">' +
				'<button type="button" class="button button-primary" id="dze-cx-varrun">' + esc(i18n.varRun) + '</button> ' +
				'<button type="button" class="button-link" id="dze-cx-varmissing">' + esc(i18n.varMissing) + '</button>' +
				'<span class="dze-var-state2"></span>' +
			'</p>'
		);
	}
	$(document).on('change', '#dze-cx-varattr', function () { loadVariations($(this).val()); });
	// The list is read when the section is opened, not when the popup is: it
	// walks the variations of the product.
	$(document).on('dze:sec', function (e, which, on) {
		if ('var' === which && on && !vars.loaded) { loadVariations(''); }
	});
	$(document).on('click', '#dze-cx-varmissing', function () {
		var empty = {};
		(vars.groups || []).forEach(function (g) { if (!g.with) { empty[g.key] = 1; } });
		$('.dze-cx-var').each(function () { $(this).prop('checked', !!empty[$(this).val()]); });
	});
	// One request per group, one after another: each one is a paid generation
	// and the provider answers in its own time.
	$(document).on('click', '#dze-cx-varrun', function () {
		var keys = $('.dze-cx-var:checked').map(function () { return $(this).val(); }).get();
		var $st = $('.dze-var-state2').removeClass('is-ko');
		if (!keys.length) { $st.addClass('is-ko').text(i18n.nothingSel); return; }
		var $b = $(this).prop('disabled', true);
		var tpl = $('#dze-cx-vartpl').val() || '0';
		var scene = $('.dze-var-scene').length ? parseInt($('.dze-var-scene').val(), 10) : -1;
		var n = parseInt($('.dze-var-n').val(), 10) || 1;
		var jobs = [];
		keys.forEach(function (k) { for (var i = 0; i < n; i++) { jobs.push(k); } });
		var i = 0;
		(function next() {
			if (i >= jobs.length) {
				$b.prop('disabled', false);
				$st.text('');
				// The groups now have images waiting: read them again so the
				// list says so.
				loadVariations(vars.attr);
				return;
			}
			var key = jobs[i++];
			$st.text(sprintf(i18n.tryN, i, jobs.length));
			var data = imageRequest(tpl, scene);
			data.variation = key;
			$.post(cfg.ajaxUrl, data)
				.done(function (r) {
					if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); $b.prop('disabled', false); return; }
					res.shots.push(r.data.url);
					res.shotTpl[r.data.url] = tpl;
					res.shotTarget = res.shotTarget || {};
					res.shotTarget[r.data.url] = r.data.target || '';
					drawShots();
					flagWaiting();
					$('#dze-cx-result').show();
					next();
				})
				.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
		}());
	});

	// The image the new one is put next to. Replacing the main image means
	// comparing with the main image; adding a gallery shot means comparing with
	// the photograph it was made from — and with nothing at all when it was
	// made from every photograph of the product at once.
	function oneReference(mainUrl) {
		if ('gallery' !== one.scope) {
			return { url: mainUrl, caption: i18n.qmNow };
		}
		if (one.paste) { return { url: one.paste, caption: i18n.qmSource }; }
		if (one.srcId) {
			var $img = $('.dze-one-srcpick.is-sel img').first();
			var u = $img.attr('data-full') || $img.attr('src') || '';
			if (u) { return { url: u, caption: i18n.qmSource }; }
		}
		return { url: '', caption: '' };
	}
	// The attempts, oldest first, the kept ones ticked. Several can be kept at
	// once: two good versions of the same shot are two photographs the product
	// can use, and paying for both only to throw one away is a waste the screen
	// used to impose. The zoom button of the shared viewer walks them full
	// size, which is the only way to judge two versions of the same image.
	function oneKept() {
		return (one.tries || []).filter(function (u) { return one.keep[u]; });
	}
	function oneDrawTries() {
		var $g = $('#dze-one-trygrid').empty();
		(one.tries || []).forEach(function (u, i) {
			$g.append(
				$('<button type="button" class="dze-one-try"></button>')
					.toggleClass('is-sel', !!one.keep[u])
					.attr('data-url', u)
					.append(
						$('<img />').attr('src', u).attr('data-full', u).attr('alt', ''),
						$('<span class="dze-one-trynum"></span>').text(i + 1),
						$('<span class="dze-one-trytick">✓</span>')
					)
			);
		});
		$('#dze-one-trycap').text(
			(one.tries || []).length > 1 ? sprintf(i18n.tryPick, (one.tries || []).length) : i18n.qmNew
		);
		oneApplyLabel();
	}
	// The button says what it is about to do, including when that is "keep
	// none of these": an attempt refused has to leave the waiting list too,
	// otherwise the product sits in the bulk screen for good.
	function oneApplyLabel() {
		var n = oneKept().length;
		$('#dze-one-apply').show().text(
			n ? (n > 1 ? sprintf(i18n.oneApplyN, n) : i18n.oneApply) : i18n.oneDropAll
		);
	}
	$(document).on('change', '#dze-one-target', function () {
		$('#dze-one-oldwrap').toggle('main' === $(this).val());
	});
	$(document).on('click', '.dze-one-try', function () {
		var u = String($(this).data('url'));
		one.keep[u] = !one.keep[u];
		$(this).toggleClass('is-sel', !!one.keep[u]);
		oneApplyLabel();
	});

	// The featured-image box and the product gallery, updated where they are.
	// The featured box comes back as WordPress's own markup from WordPress's
	// own function; the gallery items are cloned from the ones WooCommerce
	// already drew, so neither box is re-implemented here.
	function refreshBoxes() {
		$.post(cfg.ajaxUrl, { action: 'dze_content_boxes', nonce: cfg.nonce, post: PID })
			.done(function (r) {
				if (!r || !r.success) { return; }
				if (r.data.thumb_html) { $('#postimagediv .inside').html(r.data.thumb_html); }
				var $list = $('#product_images_container ul.product_images');
				if ($list.length) {
					var $model = $list.find('li.image').first();
					var $add = $list.find('li.add_product_images').detach();
					if ($model.length) {
						var tpl = $model[0].outerHTML;
						$list.find('li.image').remove();
						(r.data.gallery || []).forEach(function (im) {
							var $li = $(tpl).attr('data-attachment_id', im.id);
							$li.find('img').attr('src', im.thumb).removeAttr('srcset').removeAttr('sizes');
							$list.append($li);
						});
					}
					if ($add.length) { $list.append($add); }
				}
				$('#product_image_gallery').val(r.data.gallery_ids || '');
			});
	}
	$(document).on('click', '#dze-one-apply', function () {
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-one-state').removeClass('is-ko').text(i18n.applying);
		var done = function (r) {
			$b.prop('disabled', false);
			if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
			$st.text(i18n.applied);
			// The boxes behind the popup are brought up to date in place. They
			// used to be refreshed by reloading the whole product page, which
			// costs the scroll position, the open panels and any unsaved text —
			// for a picture. Working on several images meant paying that once
			// per image.
			refreshBoxes();
			res.current = null;
			loadCurrent().then(function () { drawCurrentImages(); oneDrawSources(); });
		};
		if (one.mode === 'image') {
			var kept = oneKept();
			var all  = (one.tries || []).slice();
			// Deciding is deciding for the whole strip: what is ticked is
			// written to the product, what is not is refused — and BOTH leave
			// the waiting list. They used to be left behind, one attempt per
			// generation, so a product worked on from its own page stayed
			// flagged "to review" on the bulk screen for ever.
			var settle = function (r, msg) {
				$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: all })
					.always(function () {
						one.tries = [];
						one.keep = {};
						oneDrawTries();
						$('#dze-one-pair').hide();
						$('#dze-one-dest').hide();
						$('#dze-one-apply').hide();
						$('#dze-one-gen').text(one.scope === 'gallery' ? i18n.imgRun : i18n.oneMain);
						done(r);
						if (msg) { $st.text(msg); }
					});
			};
			if (!kept.length) {
				// Refusing is a decision like any other, and it throws away
				// work already paid for: the bulk screen asks before it does,
				// so this asks in the same words.
				if (!window.confirm(i18n.confirmDrop)) { $b.prop('disabled', false); $st.text(''); return; }
				settle({ success: true }, i18n.dropped);
				return;
			}
			// Only one image can hold the main slot; the others asked for in the
			// same breath join the gallery rather than fighting over it.
			var want = $('#dze-one-target').val() || 'main';
			var items = kept.map(function (u, i) {
				return { url: u, target: (i && 'gallery' !== want) ? 'gallery' : want };
			});
			$.post(cfg.ajaxUrl, {
				action: 'dze_content_image_attach', nonce: cfg.nonce, post: PID,
				recipe: $('#dze-one-recipe').val() || cfg.mainRecipe || '',
				items: items,
				keep_old: $('#dze-one-oldmain').val() === '0' ? 0 : 1,
				replace: $('#dze-one-replace').is(':checked') ? (one.srcId || 0) : 0
			}).done(function (r) {
				if (!r || !r.success) { done(r); return; }
				settle(r);
			}).fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
			return;
		}
		var val = (window.tinymce && tinymce.get(ONE_ED) && !tinymce.get(ONE_ED).isHidden())
			? tinymce.get(ONE_ED).getContent() : ($('#' + ONE_ED).val() || one.value);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_apply', nonce: cfg.nonce, post: PID, field: one.fid, value: val
		}).done(done).fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});

	// The instructions, kept for good when they are right.
	$(document).on('click', '#dze-one-saveprompt', function () {
		var $st = $('#dze-one-savestate').text('…');
		var data = { action: 'dze_content_save_prompt', nonce: cfg.nonce, prompt: $('#dze-one-prompt').val() || '' };
		data.ptype = 'field';
		data.field = (one.mode === 'image')
			? ($('#dze-one-recipe').val() || cfg.mainRecipe || '')
			: one.fid;
		// The settings shown next to the text are saved with it: one button,
		// one row, nothing left behind in a screen you did not open.
		data.inputs = $('#dze-one-psets .dze-ps-input:checked').map(function () { return this.value; }).get();
		if ($('#dze-one-imgmeta').length) {
			data.img_meta = $('#dze-one-imgmeta').val() || '';
			data.img_rules = $('#dze-one-imgrules').val() || '';
		}
		$.post(cfg.ajaxUrl, data)
			.done(function (r) { $st.text((r && r.success) ? i18n.oneSaved : ((r && r.data && r.data.message) || i18n.error)); })
			.fail(function () { $st.text(i18n.error); });
	});

	// Ctrl+V is bound on the document, not on the popup: a paste event only
	// fires on what has the focus, and the focus is usually nowhere in
	// particular — which is why pasting used to do nothing until you had
	// clicked inside the box first. Whichever popup is open takes the image.
	$(document).on('paste', function (e) {
		var items = (e.originalEvent && e.originalEvent.clipboardData && e.originalEvent.clipboardData.items) || [];
		var file = null;
		for (var i = 0; i < items.length; i++) {
			if (items[i].kind === 'file' && /^image\//.test(items[i].type)) { file = items[i].getAsFile(); break; }
		}
		if (!file) { return; }
		if ($('#dze-one').hasClass('is-open') && 'image' === one.mode) {
			e.preventDefault();
			oneReadFile(file);
		}
	});

	// A background prepared outside WordPress is kept from here: the native
	// media picker, then it joins the list the settings show — no second place
	// to store one, and no trip to the settings screen to start using it.
	var bgFrame = null;
	$(document).on('click', '.dze-bg-add', function () {
		if (!window.wp || !wp.media) { return; }
		var target = $(this).data('for');
		bgFrame = wp.media({
			title: i18n.bgPick, library: { type: 'image' },
			button: { text: i18n.bgUse }, multiple: false
		});
		bgFrame.on('select', function () {
			var a = bgFrame.state().get('selection').first().toJSON();
			$.post(cfg.ajaxUrl, { action: 'dze_content_bg_add', nonce: cfg.nonce, id: a.id, name: a.title || '' })
				.done(function (r) {
					if (!r || !r.success) { return; }
					cfg.backdrops = cfg.backdrops || [];
					if (!r.data.already) {
						cfg.backdrops.push({ id: r.data.id, name: r.data.name, thumb: r.data.thumb });
						if ($('#' + target).is('select')) {
							$('#' + target).prepend($('<option></option>').val(r.data.id).text(r.data.name));
						}
					}
					$('#' + target).val(r.data.id);
					if ('dze-one-bg' === target) { oneDrawBgs(); }
				});
		});
		bgFrame.open();
	});

	// ---- The buttons themselves, on the blocks WordPress already shows ----
	function plantButtons() {
		if (!PID || $('.dze-one-btn').length) { return; }
		var anchors = cfg.anchors || {};
		var placed = {};
		var byBox = {};
		Object.keys(anchors).forEach(function (fid) {
			if (!anchors[fid] || !$(anchors[fid]).length) { return; }
			(byBox[anchors[fid]] = byBox[anchors[fid]] || []).push(fid);
		});
		Object.keys(byBox).forEach(function (sel) {
			var $box = $(sel), fids = byBox[sel];
			var $target = $box.find('> .inside').first();
			if (!$target.length) { $target = $box; }
			// One button when the box holds one prompt; named buttons when it
			// holds several, so "write this" says which "this".
			var html = fids.map(function (fid) {
				placed[fid] = 1;
				var label = fids.length > 1 ? cfg.fields[fid] : i18n.oneWrite;
				return '<button type="button" class="button button-small dze-one-btn" data-field="' + esc(fid) + '">✦ ' + esc(label) + '</button>';
			}).join(' ');
			$target.prepend('<p class="dze-one-plant">' + html + '</p>');
		});
		// The image boxes: the featured image and the gallery both open the
		// workshop — it is the same tool, and the gallery is where a supplier
		// photograph needing a remake actually sits.
		// Each box offers the recipes that write INTO it, and no others: the
		// featured-image box was showing every image prompt of the shop,
		// gallery remakes included.
		[ { sel: '#postimagediv', scope: 'main', label: i18n.oneMain },
		  { sel: '#woocommerce-product-images', scope: 'gallery', label: i18n.oneGallery } ].forEach(function (box) {
			var $box = $(box.sel + ' > .inside');
			if (!$box.length) { return; }
			$box.prepend('<p class="dze-one-plant"><button type="button" class="button button-small dze-one-btn" ' +
				'data-mode="image" data-scope="' + box.scope + '">✦ ' + esc(box.label) + '</button></p>');
		});
		// Whatever writes somewhere we cannot point at — custom blocks, SEO
		// fields — is listed in the hub box instead of being unreachable.
		var rest = Object.keys(cfg.fields).filter(function (fid) { return !placed[fid]; });
		if (rest.length && $('.dze-hub').length) {
			$('.dze-hub').append('<p class="dze-hub-rest"><span class="description">' + esc(i18n.oneOthers) + '</span> ' +
				rest.map(function (fid) {
					return '<button type="button" class="button-link dze-one-btn" data-field="' + esc(fid) + '">' + esc(cfg.fields[fid]) + '</button>';
				}).join(' · ') + '</p>');
		}
	}
	$(function () { plantButtons(); });
	$(document).on('click', '.dze-one-btn', function () {
		var $b = $(this);
		openOne($b.data('field') || '', $b.data('mode') || 'text', $b.data('scope') || '');
	});

	// One image thrown away: off the screen and out of the waiting list, so it
	// does not come back the next time the popup opens.
	$(document).on('click', '.dze-cb-shotdrop', function (e) {
		e.stopPropagation();
		var url = $(this).closest('.dze-cb-shot').data('url');
		res.shots = res.shots.filter(function (u) { return u !== url; });
		$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: [ url ] });
		drawShots();
		if (!res.shots.length && !Object.keys(res.texts).length) { $('#dze-cx-result').hide(); }
	});

	// POD hands its result over to this strip.
	window.dzeContentAddToGallery = function (url) {
		build();
		res.shots.push(url);
		drawShots();
	};
	window.dzeContentOpen = function () { open(); };

}(jQuery));
