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
	// What an answer that is not a result actually said.
	//
	// A generation that fails on the server answers with a reason, and that
	// reason is shown. What used to be swallowed is the answer that is not
	// JSON at all — a PHP fatal, a notice printed by another plugin before our
	// output — which arrives as a string, has no .success, and was reported as
	// "Something went wrong." with nothing to act on. It is now quoted.
	function answerError(r) {
		if (r && r.data && r.data.message) { return r.data.message; }
		if (typeof r === 'string' && $.trim(r)) {
			return (i18n.badAnswer || '') + ' ' + $.trim($('<i></i>').html(r).text()).slice(0, 300);
		}
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
		// The same button, with the same word on it, as every other prompt in
		// the plugin: a lone pencil is a symbol you have to learn.
		return '<button type="button" class="dze-prompt-peek" data-prompt="content_' + esc(id) +
			'" title="' + esc(i18n.promptTip) + '">&#9998; ' + esc(i18n.promptWord) + '</button>';
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
								// Photographs the product does not have yet, sent
								// with every image this run makes: a supplier shot
								// pasted here is the subject, and this screen had
								// no way to hand one over at all.
								'<details class="dze-cx-acc dze-cx-else">' +
									'<summary>' + esc(i18n.stepElse) + '</summary>' +
									'<div id="dze-cx-else"></div>' +
									'<label class="dze-basemain" title="' + esc(i18n.baseMainTip) + '">' +
										'<input type="checkbox" id="dze-cx-basemain" /><span>' + esc(i18n.baseMain) + '</span></label>' +
								'</details>' +
							'</div>' +
						'</div>' : '')
					) +

					// ---- VARIATIONS ---- one image per colour, written to every
					// size of that colour. Only on a product that has any.
					(cfg.product.variable ? sec('var', i18n.varTitle, false,
						'<p class="description">' + esc(i18n.varIntro) + '</p>' +
						// ONE home for variation images: the same popup the
						// Variations panel opens. A second list here would be a
						// second thing to keep in step with the first.
						'<p><button type="button" class="button button-primary dze-var-open">' + esc(i18n.varOpen) + '</button></p>'
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
		$(document).on('change', '.dze-cx-f, #dze-cx-doprice, #dze-cx-doimg, .dze-tpl-scene, .dze-tpl-n, .dze-tpl-target', remember);
	}

	function open(pid) {
		build();
		var target = parseInt(pid, 10) || cfg.postId || 0;
		var switching = target !== PID;
		PID = target;
		$('#dze-cx-modal').addClass('is-open');
		// The box that takes photographs from outside is part of the popup: it
		// is mounted with it, and emptied when the popup changes product.
		cxPasteBox();
		if (switching) {
			if (cxPaste) { cxPaste.clear(); }
			reset();
			// A product we were not opened on: ask the server who it is, what it
			// costs and what is already waiting on it, then arm the popup.
			$('#dze-cx-runstate').html('<span class="dze-cx-spin"></span>');
			loadCurrent().then(function (cur) {
				$('#dze-cx-runstate').empty();
				$('#dze-cx-who').text(cur.title || '');
				if (cur.cost) { $('#dze-cx-cost').val(cur.cost); }
				drawCurrentImages();
				markWritten(cur.texts);
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
			markWritten(cur.texts);
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
	// Leaving a popup that wrote something this page cannot show: the reload is
	// taken care of, unless the page is carrying edits of its own.
	$(document).on('click', '.dze-cx-close, .dze-hub-close', function () { window.setTimeout(reloadIfIdle, 60); });
	$(document).on('click', '.dze-cx-modal', function (e) { if (e.target === this) { window.setTimeout(reloadIfIdle, 60); } });

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
		window.setTimeout(cxApplyLabel, 0);
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
	//
	// A field that already holds something is not generated, it is REWRITTEN —
	// and the screen says so before the button is pressed rather than after,
	// because the two are not the same decision: one fills a hole, the other
	// replaces work that is already live.
	function markWritten(texts) {
		texts = texts || {};
		$('.dze-cx-f').each(function () {
			var $f = $(this), has = !!$.trim(String(texts[$f.val()] || '').replace(/<[^>]*>/g, ''));
			$f.attr('data-written', has ? '1' : '0');
			var $line = $f.closest('.dze-cb-checkline');
			$line.find('.dze-cx-has').remove();
			if (has) {
				$line.find('.dze-cb-check > span').first()
					.append(' <span class="dze-cx-has" title="' + esc(i18n.writtenTip) + '">' + esc(i18n.written) + '</span>');
			}
		});
		runLabel();
	}
	// "Generate" while something is missing, "Regenerate" when everything
	// ticked is already there.
	function runLabel() {
		var $ticked = $('.dze-cx-f:checked'), all = $ticked.length > 0;
		$ticked.each(function () { if ('1' !== $(this).attr('data-written')) { all = false; } });
		if ($('#dze-cx-doimg').is(':checked') || $('#dze-cx-doprice').is(':checked')) { all = false; }
		$('#dze-cx-run').text(all ? i18n.relaunch : i18n.launch);
	}
	$(document).on('change', '.dze-cx-f, #dze-cx-doimg, #dze-cx-doprice', runLabel);

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
		window.setTimeout(cxApplyLabel, 0);
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
	// The toolbox's own box of photographs from outside, mounted with the
	// popup and read by every image it orders.
	var cxPaste = null;
	function cxPasteBox() {
		var $slot = $('#dze-cx-else');
		if (!$slot.length) { cxPaste = null; return null; }
		if (!cxPaste || !$.contains(document.body, cxPaste.el[0])) {
			cxPaste = window.dzePasteBox.mount($slot, { max: maxPasted(), maxBody: maxBody() });
		}
		return cxPaste;
	}
	function imageRequest(tpl, scene, target) {
		var job  = jobFor(tpl);
		var data = { action: 'dze_content_image', nonce: cfg.nonce, post: PID, template: tpl, mode: 'defer', stash: 1 };
		// Whatever was handed to this run from outside the shop travels with
		// every image it makes.
		var outside = cxPaste ? cxPaste.list() : [];
		if (outside.length) {
			data.pastes = outside;
			if ($('#dze-cx-basemain').is(':checked')) { data.base_main = 1; }
		}
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
				if (!r || !r.success) { throw answerError(r); }
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
				if (!r || !r.success) { throw answerError(r); }
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
							if (!r || !r.success) { throw answerError(r); }
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
	// What the button is about to write, counted the same way the click counts
	// it: the ticked images plus the ticked blocks of text. A button that says
	// "Apply to the product" leaves you counting the ticks yourself.
	function cxKeptCount() {
		var n = $('#dze-cx-shots .dze-cb-shot.is-sel').length;
		Object.keys(res.texts || {}).forEach(function (fid) {
			var $k = $('#dze-cx-drawers .dze-cb-fblock[data-field="' + fid + '"]').find('.dze-cb-fkeep');
			if (!$k.length || $k.is(':checked')) { n++; }
		});
		return n;
	}
	function cxApplyLabel() {
		var n = cxKeptCount();
		$('.dze-cx-applyone').prop('disabled', 0 === n).text(sprintf(i18n.applyOne, n));
	}
	// Every tick that changes what would be written keeps the button honest.
	$(document).on('change', '#dze-cx-drawers .dze-cb-fkeep', cxApplyLabel);
	$(document).on('click', '#dze-cx-shots .dze-cb-shot', function () { window.setTimeout(cxApplyLabel, 0); });
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

		rememberClean();
		function texts(i) {
			if (i >= fids.length) { return finish(); }
			var fid = fids[i], value = valueOf(fid);
			$.post(cfg.ajaxUrl, { action: 'dze_content_apply', nonce: cfg.nonce, post: PID, field: fid, value: value })
				.done(function (r) {
					var $s = $('#dze-cx-drawers .dze-cb-fblock[data-field="' + fid + '"]').find('.dze-cb-fstate');
					if (r && r.success) {
						ok++;
						$s.removeClass('is-ko').text('✓');
						// Written to the product AND to the page, so what is on
						// screen is what the shop holds.
						if (!applyToPage(fid, value)) { res.needsReload = true; }
					}
					else { ko++; $s.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); }
				})
				.fail(function () { ko++; })
				.always(function () { texts(i + 1); });
		}
		function finish() {
			$btn.prop('disabled', false);
			if (ko) { $st.addClass('is-ko').text(sprintf(i18n.partial, ok, ok + ko)); return; }
			$st.text('');
			// Deciding is deciding for the whole panel: what was ticked is
			// written, what was not is refused, and the product stops waiting.
			// Keeping the rest "for later" is what left products flagged to
			// review on the bulk screen after they had been dealt with here.
			$.post(cfg.ajaxUrl, {
				action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID
			}).always(function () {
				res.shots = [];
				res.texts = {};
				$('#dze-cx-drawers').empty();
				drawShots();
				$('#dze-cx-result').hide();
				loadCurrent().then(drawCurrentImages);
				// Finished here is finished everywhere: the product is recorded
				// under "Done" and leaves the bulk selection, instead of
				// sitting in that list looking exactly like a product nobody
				// has touched.
				$.post(cfg.ajaxUrl, {
					action: 'dze_content_logged', nonce: cfg.nonce, post: PID,
					texts: fids.length, images: items.length, unqueue: 1
				}).always(function () {
					// Only now: what the page does next can be a reload, and a
					// reload cancels whatever has not gone out yet.
					pageWritten(fids.length + items.length, $st.parent());
				});
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

	// pastes: the photographs from OUTSIDE the shop sent with this run. Several
	// of them, because three supplier shots of the same jacket — none of them
	// usable as it stands — say together what no single one of them says.
	var one = { fid: '', mode: 'text', value: '', tries: [], keep: {} };
	function maxPasted() { return parseInt(cfg.maxPasted, 10) || 12; }
	// What really limits a run is the WEIGHT of the request, not a count: three
	// photographs straight from a camera are heavier than a dozen supplier
	// shots. The shared box refuses the one that would not fit, with a
	// sentence, rather than leaving a server to answer an oversized POST with
	// an empty page.
	function maxBody() { return parseInt(cfg.maxBody, 10) || 9437184; }

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
	// The count in the summary is what that block SENDS. Drawn once and never
	// touched again, it went on saying five while the boxes under it said
	// three — and the only way to see the real number was to save and reload.
	$(document).on('change', '.dze-ps-data input[type=checkbox]', function () {
		var $d = $(this).closest('.dze-ps-data');
		$d.children('summary').text(i18n.psData + ' (' + $d.find('input[type=checkbox]:checked').length + ')');
	});

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
		// One word on the button that runs it, whatever it makes: Generate.
		$('#dze-one-gen').text(i18n.generate);
		$('#dze-one-body').html(mode === 'image' ? oneImageBody() : instrBlock());
		$('#dze-one-prompt').val(mode === 'image' ? (cfg.quickPrompt || '') : ((cfg.prompts && cfg.prompts[fid]) || ''));
		if ('image' !== mode) { oneFillSettings(fid); }
		// Asking for several at once is only offered where several make sense.
		$('#dze-one-nwrap').toggle('image' === mode);
		$('#dze-one').addClass('is-open');
		if (mode === 'image') {
			one.srcId = 0; oneShowPasted('');
			$('#dze-one-note').val(cfg.note || '');
			$('#dze-one-notewrap').prop('open', !!(cfg.note || '').trim());
			oneDrawRecipes();
			oneSetRecipe(oneRecipes()[0] ? String(oneRecipes()[0].id) : '');
			oneDrawSources();
		}
		if (mode === 'text') { oneShowBefore(fid); }
		oneRestore(mode, fid);
	}

	// What is still waiting on this product, found again.
	//
	// A generated image lives on the product until it is accepted or refused,
	// which is why the bulk screen can show it. This popup could not: closing
	// it — or a browser that crashed, or a page left for the night — meant
	// coming back to an empty strip in front of images that had been paid for
	// and were still there. It reads the same waiting set the bulk screen
	// reads, and puts it back on screen, unticked: found again is not the same
	// as decided.
	function oneRestore(mode, fid) {
		loadCurrent().then(function (cur) {
			var waiting = (cur && cur.pending) || {};
			if ('image' === mode) {
				var shots = (waiting.shots || []).filter(function (u) {
					return (one.tries || []).indexOf(u) < 0;
				});
				if (!shots.length) { return; }
				one.tries = shots.concat(one.tries || []);
				oneDrawTries();
				$('#dze-one-pair').show();
				$('#dze-one-dest').show();
				$('#dze-one-oldwrap').toggle('main' === ($('#dze-one-target').val() || 'main'));
				$('#dze-one-state').removeClass('is-ko').text(i18n.foundWaiting);
				return;
			}
			var text = (waiting.texts || {})[fid] || '';
			if ('' !== text) {
				oneShowResult(text);
				$('#dze-one-state').removeClass('is-ko').text(i18n.foundWaiting);
			}
		});
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
					// The box that takes photographs from outside the shop:
					// admin/js/paste-box.js — the same component the toolbox
					// and the bulk review panel mount.
					'<div id="dze-one-drop"></div>' +
					// An image from elsewhere is the subject, not the whole
					// brief: the product\'s own photographs say what its back,
					// its lining and its material look like, and they travel
					// with it unless you say otherwise.
					'<label class="dze-one-withprod"><input type="checkbox" id="dze-one-withprod" checked /> ' +
						esc(i18n.withProduct) + '</label>' +
					// Which of the two is the SUBJECT. Pasting used to decide it
					// on its own — what you added became the thing to
					// photograph — so there was no way to say "keep this
					// product, exactly this one, and put it in that scene".
					'<label class="dze-basemain" title="' + esc(i18n.baseMainTip) + '">' +
						'<input type="checkbox" id="dze-one-basemain" /><span>' + esc(i18n.baseMain) + '</span></label>' +
				'</div>' +
			'</div>' +

			// What no photograph of this product shows. Written once, sent with
			// every image made for it — the variations have their own, per
			// colour, and both travel together.
			'<details class="dze-one-instr" id="dze-one-notewrap">' +
				'<summary>' + esc(i18n.noteTitle) + '</summary>' +
				'<p class="description">' + esc(i18n.noteHelp) + '</p>' +
				'<textarea id="dze-one-note" rows="2" class="large-text" placeholder="' + esc(i18n.notePh) + '"></textarea>' +
				'<span class="dze-one-notestate"></span>' +
			'</details>' +

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
			// A photograph that belongs to a colour says so on the tile: it is
			// in this strip like the others, and picking it without knowing
			// which colour it is is how a black shoe came back blue.
			html += '<button type="button" class="dze-one-srcpick" data-id="' + im.id + '"' +
				(im.variation ? ' title="' + esc(im.variation) + '"' : '') + '>' +
				'<img class="dze-hzoom" src="' + esc(im.thumb) + '" data-full="' + esc(im.full || im.thumb) + '" alt="" />' +
				(im.variation ? '<span class="dze-one-srcvar">' + esc(im.variation) + '</span>' : '') +
				'</button>';
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
			if (one.srcId || onePastes().length) { return; }
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
		if (!outside) {
			oneShowPasted('');
		} else {
			// Picking the tile is what puts the box on screen: it has to be
			// mounted here, not only when something is dropped on it.
			var box = onePasteBox();
			if (box) { box.el.trigger('focus'); }
		}
		// Only a photograph of the product can be retired by its own remake.
		$('#dze-one-replacewrap').toggle(!!id);
		if (!id) { $('#dze-one-replace').prop('checked', false); }
	});
	// One place that says which photographs from outside we work from, whether
	// they arrived by Ctrl+V, by drag and drop, or from the computer. The FIRST
	// one is the subject; the ones after it are there to say what it does not
	// show.
	// The set being worked from lives in the shared box; this screen only says
	// when to empty it and what to do when it changes.
	var onePaste = null;
	function onePasteBox() {
		var $slot = $('#dze-one-drop');
		if (!$slot.length) { onePaste = null; return null; }
		if (!onePaste || !$.contains(document.body, onePaste.el[0])) {
			onePaste = window.dzePasteBox.mount($slot, {
				max: maxPasted(), maxBody: maxBody(), onChange: onePasteChanged
			});
		}
		return onePaste;
	}
	function onePastes() { return onePaste ? onePaste.list() : []; }
	function onePasteChanged(list) {
		// The tile that opened this box mirrors the set it holds.
		$('#dze-one-newthumb').attr('src', list[0] || '').toggle(list.length > 0);
		$('.dze-one-srcnew .dze-one-newmsg').toggle(!list.length);
	}
	function oneShowPasted(dataUri) {
		var box = onePasteBox();
		if (!box) { return; }
		box.clear();
		if (dataUri) { box.add(String(dataUri)); }
	}
	// A file dropped on the popup, or pasted with Ctrl+V, joins that same set —
	// and picks the "from elsewhere" tile on the way, because that is what it
	// means.
	function oneReadFile(file) {
		if (!file || !/^image\//.test(file.type)) { return; }
		$('.dze-one-srcpick').removeClass('is-sel');
		$('.dze-one-srcnew').addClass('is-sel');
		one.srcId = 0;
		$('#dze-one-elsewrap').show();
		$('#dze-one-replacewrap').hide();
		var box = onePasteBox();
		if (box) { box.addFile(file); }
	}

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
		$('#dze-one-gen').text(i18n.generate);
		$('#dze-one-apply').show();
	}

	// One order, read from the popup as it stands: the button that makes an
	// image and the ↻ that makes one again ask for exactly the same thing.
	function oneImageRequest(prompt) {
		return {
			action: 'dze_content_quick_main', nonce: cfg.nonce, post: PID,
			pastes: onePastes(),
			with_product: $('#dze-one-withprod').is(':checked') ? 1 : 0,
			base_main: $('#dze-one-basemain').is(':checked') ? 1 : 0,
			src_id: one.srcId || 0, recipe: $('#dze-one-recipe').val() || '',
			bg: $('#dze-one-bg').val() || 0,
			prompt: undefined === prompt ? ($('#dze-one-prompt').val() || '') : prompt
		};
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
				$.post(cfg.ajaxUrl, oneImageRequest(prompt))
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
						$('#dze-one-gen').text(i18n.generate);
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
	//
	// Three ways to fill it, on the same line, because the answer is not always
	// "generate one": the shop often already has the photograph, in the library
	// or on the desktop. A pasted one joins the library by the same road as a
	// generated one — the shop's file name, the shop's title, its alt text.
	// `made` holds what has been generated and not yet decided, BY GROUP: the
	// list is redrawn after every write, and a preview living only in the
	// markup was wiped by the redraw — saving one colour looked like it threw
	// the other one away. (The image itself was still on the product's waiting
	// list, but nobody should have to know that.)
	var vars = { attr: '', groups: [], made: {}, loaded: false };

	function varTemplates() {
		return (cfg.templates || []).map(function (t, i) { return { i: i, t: t }; })
			.filter(function (o) { return 'variation' === o.t.target; });
	}
	function varBuild() {
		if ($('#dze-var').length) { return; }
		var tpls = varTemplates();
		$('body').append(
		'<div class="dze-cx-modal" id="dze-var"><div class="dze-cx-dialog dze-one-dialog">' +
			'<div class="dze-cx-head"><h2>' + esc(i18n.varTitle) + '</h2>' +
				'<button type="button" class="button dze-hub-close" style="margin-left:auto;">' + esc(i18n.close) + '</button>' +
			'</div>' +
			'<div class="dze-cx-body"><div id="dze-var-body"></div></div>' +
			'<div class="dze-cx-foot">' +
				(tpls.length
					? '<p class="dze-qm-bar">' +
						'<label class="dze-qm-bglabel"><span>' + esc(i18n.template) + '</span>' +
							'<select id="dze-var-tpl">' + tpls.map(function (o) {
								return '<option value="' + o.i + '">' + esc(o.t.name) + '</option>';
							}).join('') + '</select></label>' +
						// The instructions are read and edited from here, exactly
						// as they are everywhere else a prompt is run.
						'<span id="dze-var-peek">' + promptBtn((tpls[0].t || {}).id) + '</span>' +
						((cfg.scenes || []).length
							? '<label class="dze-qm-bglabel"><span>' + esc(i18n.scene) + '</span>' +
								sceneSelect(defaultScene()).replace('dze-tpl-scene', 'dze-var-scene') + '</label>'
							: '') +
						'<button type="button" class="button button-primary" id="dze-var-run">' + esc(i18n.generate) + '</button>' +
						'<button type="button" class="button button-primary" id="dze-var-saveall" style="display:none;"></button>' +
						'<button type="button" class="button-link" id="dze-var-missing">' + esc(i18n.varMissing) + '</button>' +
						'<span class="dze-var-state2" id="dze-var-state"></span>' +
					'</p>'
					: '<p class="description">' + esc(i18n.varNoPrompt) + '</p>') +
			'</div>' +
		'</div></div>');
		$(document).on('click', '#dze-var', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });
	}
	$(document).on('click', '.dze-var-open', function () {
		varBuild();
		$('#dze-var').addClass('is-open');
		loadVariations(vars.attr || '');
	});

	function loadVariations(attr) {
		var $box = $('#dze-var-body');
		if (!$box.length) { return; }
		$box.html('<p class="description">' + esc(i18n.working) + '</p>');
		$.post(cfg.ajaxUrl, { action: 'dze_content_variations', nonce: cfg.nonce, post: PID, attr: attr || '' })
			.done(function (r) {
				if (!r || !r.success) { $box.html('<p class="is-ko">' + esc((r && r.data && r.data.message) || i18n.error) + '</p>'); return; }
				vars = {
					attr: r.data.attr, label: r.data.label, choices: r.data.choices || [],
					groups: r.data.groups || [], count: r.data.count || '', short: !!r.data.short,
					// Never dropped by a redraw.
					made: vars.made || {},
					loaded: true
				};
				drawVariations();
				// The counter in WooCommerce's own panel follows what was just
				// written, instead of waiting for the page to be loaded again.
				$('.dze-varbar .dze-varcount').text(vars.count).toggleClass('is-short', vars.short);
			})
			.fail(function (x) { $box.html('<p class="is-ko">' + esc(reason(x)) + '</p>'); });
	}
	function varRow(g) {
		var none = !g.with;
		return '<div class="dze-var-row' + (none ? ' is-empty' : '') + '" data-key="' + esc(g.key) + '">' +
			'<label class="dze-var-pick"><input type="checkbox" class="dze-cx-var" value="' + esc(g.key) + '"' + (none ? ' checked' : '') + ' /></label>' +
			// The thumbnail sits in its own cell: the shared zoom button is
			// planted in its parent, and with the image loose in the row that
			// parent was the row — so the button landed in the row's corner,
			// miles from the image it opens.
			(g.thumb
				? '<span class="dze-var-thumbwrap"><img class="dze-var-thumb" src="' + esc(g.thumb) + '" data-full="' + esc(g.full || g.thumb) + '" alt="" /></span>'
				: '<span class="dze-var-nothumb">—</span>') +
			'<span class="dze-var-name">' + esc(g.label) + '</span>' +
			'<span class="dze-var-state">' + esc(none ? i18n.varHasNone : sprintf(i18n.varCount, g.total, g.with)) + '</span>' +
			'<span class="dze-var-acts">' +
				'<button type="button" class="button button-small dze-var-note' + (g.note ? ' is-on' : '') + '" title="' + esc(i18n.varNoteHelp) + '">' + esc(i18n.varNote) + '</button> ' +
				// The photographs this product already has, one click away:
				// nine times out of ten the image a colour needs is already in
				// its own gallery, and going to fetch it through the whole
				// media library to find it again is the long way round.
				'<button type="button" class="button button-small dze-var-own" title="' + esc(i18n.varOwnHelp) + '">' + esc(i18n.varOwn) + '</button> ' +
				'<button type="button" class="button button-small dze-var-lib">' + esc(i18n.varLib) + '</button> ' +
				'<button type="button" class="button button-small dze-var-paste">' + esc(i18n.varPaste) + '</button> ' +
				(varTemplates().length ? '<button type="button" class="button button-small dze-var-gen">✦</button> ' : '') +
				(g.with ? '<button type="button" class="button-link dze-var-clear" title="' + esc(i18n.varClear) + '">&times;</button>' : '') +
			'</span>' +
			'<span class="dze-var-rowstate"></span>' +
			// What the owner knows about THIS colour, kept with the product and
			// sent with every image made for it.
			'<label class="dze-var-notebox"' + (g.note ? '' : ' style="display:none;"') + '>' +
				'<span>' + esc(i18n.varNoteLabel) + '</span>' +
				'<textarea class="dze-var-notetext" rows="2" placeholder="' + esc(i18n.varNotePh) + '">' + esc(g.note || '') + '</textarea>' +
			'</label>' +
			'<div class="dze-var-work"></div>' +
		'</div>';
	}
	function drawVariations() {
		var $box = $('#dze-var-body');
		if (!$box.length) { return; }
		if (!vars.groups.length) { $box.html('<p class="description">' + esc(i18n.varNone) + '</p>'); return; }
		var by = '';
		if ((vars.choices || []).length > 1) {
			by = '<div class="dze-cb-opts"><label><span>' + esc(i18n.varGroupBy) + '</span>' +
				'<select id="dze-var-attr">' + vars.choices.map(function (c) {
					return '<option value="' + esc(c.key) + '"' + (c.key === vars.attr ? ' selected' : '') + '>' + esc(c.label) + '</option>';
				}).join('') + '</select></label></div>';
		}
		$box.html(
			'<p class="description">' + esc(i18n.varIntro) + (vars.count ? ' <strong>' + esc(vars.count) + '</strong>' : '') + '</p>' + by +
			'<div class="dze-var-list dze-zoomgroup">' + vars.groups.map(varRow).join('') + '</div>'
		);
		varDrawMade();
	}
	function varTryHtml(url) {
		return '<div class="dze-var-try" data-url="' + esc(url) + '">' +
			'<span class="dze-var-tryimg"><img src="' + esc(url) + '" data-full="' + esc(url) + '" alt="" /></span>' +
			'<span class="dze-var-tryacts">' +
				'<button type="button" class="button button-small button-primary dze-var-keep">' + esc(i18n.oneApply) + '</button> ' +
				// The same ↻ as every other generated image: this one again,
				// with the prompt of the run, in its place.
				'<button type="button" class="button button-small dze-var-redo" title="' + esc(i18n.shotRedo) + '">↻</button> ' +
				'<button type="button" class="button-link dze-var-throw">' + esc(i18n.discard) + '</button>' +
			'</span>' +
		'</div>';
	}
	// Everything generated and still undecided, drawn back into its row after
	// each redraw, and counted in the footer.
	function varDrawMade() {
		Object.keys(vars.made || {}).forEach(function (key) {
			var $row = $('.dze-var-row[data-key="' + key + '"]');
			if ($row.length && !$row.find('.dze-var-try').length) {
				$row.find('.dze-var-work').html(varTryHtml(vars.made[key]));
			}
		});
		var n = Object.keys(vars.made || {}).length;
		$('#dze-var-saveall').toggle(n > 1).text(i18n.varSaveAll);
	}
	function varGroup($row) { return String($row.attr('data-key') || ''); }
	function varSay($row, text, bad) {
		$row.find('.dze-var-rowstate').first().toggleClass('is-ko', !!bad).text(text || '');
	}
	// Whatever fills a group, the list is read again afterwards: what a row
	// says about itself always comes from the product, never from what this
	// screen believes it just did.
	function varRefresh() {
		loadVariations(vars.attr);
		varReloadPanel();
	}
	// WooCommerce's own Variations panel is drawn from its own request: a
	// thumbnail written behind its back stays the old one on screen until it is
	// asked to read the variations again.
	function varReloadPanel() {
		var $panel = $('#variable_product_options .woocommerce_variations');
		if ($panel.length) { $panel.trigger('reload'); }
	}

	// The colour's name ticks its box, the way a label does everywhere else —
	// the tick box alone is a 13px target on a row that is otherwise inert.
	$(document).on('click', '.dze-var-name', function () {
		var $box = $(this).closest('.dze-var-row').find('.dze-cx-var');
		$box.prop('checked', !$box.prop('checked')).trigger('change');
	});
	$(document).on('change', '#dze-var-attr', function () { loadVariations($(this).val()); });
	$(document).on('change', '#dze-var-tpl', function () {
		var t = cfg.templates[parseInt($(this).val(), 10)] || {};
		$('#dze-var-peek').html(promptBtn(t.id));
	});
	$(document).on('click', '#dze-var-missing', function () {
		var empty = {};
		(vars.groups || []).forEach(function (g) { if (!g.with) { empty[g.key] = 1; } });
		$('.dze-cx-var').each(function () { $(this).prop('checked', !!empty[$(this).val()]); });
	});

	// ---- The photographs this product already has ----
	// Main image and gallery together, in the order the product holds them:
	// what a colour needs is usually one of them, and "which one" is a question
	// answered by looking, not by opening a library of four thousand files.
	$(document).on('click', '.dze-var-own', function () {
		var $row = $(this).closest('.dze-var-row');
		var $work = $row.find('.dze-var-work');
		if ($work.find('.dze-var-owns').length) { $work.empty(); return; }
		$work.html('<div class="dze-var-owns"><span class="description">…</span></div>');
		loadCurrent().then(function (cur) {
			var imgs = (cur.images || []).filter(function (im) { return im.id; });
			if (!imgs.length) {
				$work.html('<div class="dze-var-owns"><span class="description">' + esc(i18n.varOwnNone) + '</span></div>');
				return;
			}
			$work.html('<div class="dze-var-owns">' + imgs.map(function (im) {
				return '<button type="button" class="dze-var-ownpick" data-id="' + im.id + '" title="' +
					esc(im.main ? i18n.varOwnMain : i18n.varOwnPick) + '">' +
					'<img src="' + esc(im.thumb) + '" alt="" />' +
					(im.main ? '<span class="dze-var-ownmain">' + esc(i18n.varOwnMainTag) + '</span>' : '') +
					'</button>';
			}).join('') + '</div>');
		});
	});
	$(document).on('click', '.dze-var-ownpick', function () {
		var $row = $(this).closest('.dze-var-row');
		var id = $(this).data('id');
		varSay($row, i18n.applying);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_variation_assign', nonce: cfg.nonce,
			post: PID, group: varGroup($row), attachment: id
		})
			.done(function (r) {
				if (!r || !r.success) { varSay($row, (r && r.data && r.data.message) || i18n.error, true); return; }
				$row.find('.dze-var-work').empty();
				varRefresh();
				refreshBoxes();
			})
			.fail(function (x) { varSay($row, reason(x), true); });
	});

	// ---- The photograph the shop already has ----
	var varFrame = null;
	$(document).on('click', '.dze-var-lib', function () {
		var $row = $(this).closest('.dze-var-row');
		if (!window.wp || !wp.media) { return; }
		varFrame = wp.media({ title: i18n.varLibTitle, button: { text: i18n.bgUse }, library: { type: 'image' }, multiple: false });
		varFrame.on('select', function () {
			var att = varFrame.state().get('selection').first();
			if (!att) { return; }
			varSay($row, i18n.applying);
			$.post(cfg.ajaxUrl, {
				action: 'dze_content_variation_assign', nonce: cfg.nonce,
				post: PID, group: varGroup($row), attachment: att.id
			})
				.done(function (r) {
					if (!r || !r.success) { varSay($row, (r && r.data && r.data.message) || i18n.error, true); return; }
					varRefresh();
					refreshBoxes();
				})
				.fail(function (x) { varSay($row, reason(x), true); });
		});
		varFrame.open();
	});
	$(document).on('click', '.dze-var-note', function () {
		var $box = $(this).closest('.dze-var-row').find('.dze-var-notebox');
		$box.toggle();
		if ($box.is(':visible')) { $box.find('textarea').trigger('focus'); }
	});
	// Saved when you leave the box: one line typed once, kept with the product.
	$(document).on('change blur', '.dze-var-notetext', function () {
		var $row = $(this).closest('.dze-var-row');
		var note = $(this).val() || '';
		$row.find('.dze-var-note').toggleClass('is-on', '' !== note.trim());
		(vars.groups || []).forEach(function (g) { if (g.key === varGroup($row)) { g.note = note; } });
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_variation_note', nonce: cfg.nonce,
			post: PID, group: varGroup($row), note: note
		});
	});
	$(document).on('click', '.dze-var-clear', function () {
		var $row = $(this).closest('.dze-var-row');
		varSay($row, i18n.applying);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_variation_assign', nonce: cfg.nonce,
			post: PID, group: varGroup($row), attachment: 0
		})
			.done(function (r) {
				if (!r || !r.success) { varSay($row, (r && r.data && r.data.message) || i18n.error, true); return; }
				varRefresh();
			})
			.fail(function (x) { varSay($row, reason(x), true); });
	});

	// ---- The photograph on the desktop ----
	// Pasted, dropped or chosen from a folder. It travels as bytes inside the
	// request and joins the library named the way the shop names its files.
	$(document).on('click', '.dze-var-paste', function () {
		var $work = $(this).closest('.dze-var-row').find('.dze-var-work');
		if ($work.find('.dze-qm-drop').length) { $work.empty(); return; }
		$work.html(
			'<div class="dze-qm-drop" tabindex="0">' +
				'<span class="dze-qm-dropmsg">' + esc(i18n.qmPaste) + '</span>' +
				'<button type="button" class="button button-small dze-qm-browse">' + esc(i18n.qmBrowse) + '</button>' +
				'<input type="file" accept="image/*" class="dze-qm-file" hidden />' +
			'</div>'
		);
		$work.find('.dze-qm-drop').trigger('focus');
	});
	// A supplier photograph is rarely a shop photograph: it carries their logo,
	// a play button, the wrong shape. So a pasted image is not filed on sight —
	// it is shown, and you say what it is: the photograph itself, or the
	// subject of a clean one.
	function varShowPasted($row, dataUri) {
		$row.data('paste', dataUri);
		$row.find('.dze-var-work').html(
			'<div class="dze-var-try dze-var-src">' +
				'<span class="dze-var-tryimg"><img src="' + esc(dataUri) + '" alt="" /></span>' +
				'<span class="dze-var-tryacts">' +
					'<button type="button" class="button button-small dze-var-useas">' + esc(i18n.varUseAs) + '</button> ' +
					(varTemplates().length ? '<button type="button" class="button button-small button-primary dze-var-fromit">✦ ' + esc(i18n.varFromIt) + '</button> ' : '') +
					'<button type="button" class="button-link dze-var-throwsrc">' + esc(i18n.discard) + '</button>' +
				'</span>' +
			'</div>'
		);
	}
	$(document).on('click', '.dze-var-useas', function () {
		var $row = $(this).closest('.dze-var-row');
		varUpload($row, String($row.data('paste') || ''));
	});
	$(document).on('click', '.dze-var-fromit', function () {
		var $row = $(this).closest('.dze-var-row');
		varGenerate($row, String($row.data('paste') || ''));
	});
	$(document).on('click', '.dze-var-throwsrc', function () {
		var $row = $(this).closest('.dze-var-row');
		$row.removeData('paste').find('.dze-var-work').empty();
	});
	function varUpload($row, dataUri) {
		varSay($row, i18n.applying);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_variation_paste', nonce: cfg.nonce,
			post: PID, group: varGroup($row), data: dataUri,
			recipe: $('#dze-var-tpl').length ? ((cfg.templates[parseInt($('#dze-var-tpl').val(), 10)] || {}).id || '') : ''
		})
			.done(function (r) {
				if (!r || !r.success) { varSay($row, (r && r.data && r.data.message) || i18n.error, true); return; }
				$row.find('.dze-var-work').empty();
				varRefresh();
				refreshBoxes();
			})
			.fail(function (x) { varSay($row, reason(x), true); });
	}
	$(document).on('paste', '#dze-var .dze-qm-drop', function (e) {
		var items = (e.originalEvent.clipboardData || {}).items || [];
		var $row = $(this).closest('.dze-var-row');
		for (var i = 0; i < items.length; i++) {
			if (0 === String(items[i].type).indexOf('image/')) {
				var fr = new FileReader();
				fr.onload = function () { varShowPasted($row, String(fr.result)); };
				fr.readAsDataURL(items[i].getAsFile());
				e.preventDefault();
				return;
			}
		}
	});
	$(document).on('dragover', '#dze-var .dze-qm-drop', function (e) { e.preventDefault(); $(this).addClass('is-over'); });
	$(document).on('dragleave', '#dze-var .dze-qm-drop', function () { $(this).removeClass('is-over'); });
	$(document).on('drop', '#dze-var .dze-qm-drop', function (e) {
		e.preventDefault();
		$(this).removeClass('is-over');
		var file = ((e.originalEvent.dataTransfer || {}).files || [])[0];
		if (!file || !/^image\//.test(file.type)) { return; }
		var $row = $(this).closest('.dze-var-row');
		var fr = new FileReader();
		fr.onload = function () { varShowPasted($row, String(fr.result)); };
		fr.readAsDataURL(file);
	});
	// The variations popup keeps a box of its own: one photograph for one
	// colour, with its own two ways out (use it as it is, or make one from it).
	$(document).on('click', '#dze-var .dze-qm-browse', function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).closest('.dze-qm-drop').find('.dze-qm-file').trigger('click');
	});
	$(document).on('change', '#dze-var .dze-qm-file', function () {
		var file = this.files && this.files[0];
		this.value = '';
		if (!file || !/^image\//.test(file.type)) { return; }
		var $row = $(this).closest('.dze-var-row');
		var fr = new FileReader();
		fr.onload = function () { varShowPasted($row, String(fr.result)); };
		fr.readAsDataURL(file);
	});

	// ---- The photograph nobody has yet ----
	// Generated, shown in its row, kept or thrown away there. It is stashed on
	// the product like every other generated image, so a closed tab does not
	// lose what was paid for; deciding here settles it.
	function varGenerate($row, paste) {
		var tpl = $('#dze-var-tpl').val();
		if (tpl === undefined) { return $.Deferred().reject(i18n.varNoPrompt); }
		var d = $.Deferred();
		varSay($row, i18n.generating);
		var data = imageRequest(tpl, $('.dze-var-scene').length ? parseInt($('.dze-var-scene').val(), 10) : undefined);
		data.variation = varGroup($row);
		// A colour is built from ITS OWN photograph and from nothing else.
		//
		// imageRequest() carries whatever the toolbox was handed from outside,
		// which is right for a gallery shot and wrong here: it would take the
		// place of the photograph pasted on this line — the subject — and send
		// the model off on something else entirely. One colour, one source.
		data.pastes = paste ? [ paste ] : [];
		delete data.paste;
		$.post(cfg.ajaxUrl, data)
			.done(function (r) {
				if (!r || !r.success) { varSay($row, (r && r.data && r.data.message) || i18n.error, true); d.reject(); return; }
				varSay($row, '');
				vars.made[varGroup($row)] = r.data.url;
				$row.find('.dze-var-work').html(varTryHtml(r.data.url));
				varDrawMade();
				d.resolve();
			})
			.fail(function (x) { varSay($row, reason(x), true); d.reject(); });
		return d;
	}
	$(document).on('click', '.dze-var-gen', function () {
		varGenerate($(this).closest('.dze-var-row'));
	});
	$(document).on('click', '.dze-var-redo', function () {
		var $row = $(this).closest('.dze-var-row');
		varGenerate($row, $row.data('paste') || '');
	});
	$(document).on('click', '#dze-var-run', function () {
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-var-state').removeClass('is-ko');
		var rows = $('.dze-cx-var:checked').map(function () { return $(this).closest('.dze-var-row')[0]; }).get();
		if (!rows.length) { $b.prop('disabled', false); $st.addClass('is-ko').text(i18n.nothingSel); return; }
		var i = 0;
		(function next() {
			if (i >= rows.length) { $b.prop('disabled', false); $st.text(''); return; }
			$st.text(sprintf(i18n.tryN, i + 1, rows.length));
			// A colour with a photograph pasted on its line is BUILT from that
			// photograph. The run used to ignore it and work from the product's
			// own shots instead, which is how a set of pasted supplier images
			// came back as something nobody recognised.
			var $row = $(rows[i++]);
			varGenerate($row, String($row.data('paste') || '')).always(next);
		}());
	});
	// Saving one colour, or every colour at once: the same call either way, one
	// item per group. Only what is actually written leaves the waiting list.
	function varSave(keys, $where) {
		keys = keys.filter(function (k) { return vars.made[k]; });
		if (!keys.length) { return; }
		var urls = keys.map(function (k) { return vars.made[k]; });
		var items = keys.map(function (k) { return { url: vars.made[k], target: 'variation:' + k }; });
		if ($where) { varSay($where, i18n.applying); }
		$('#dze-var-saveall').prop('disabled', true);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_image_attach', nonce: cfg.nonce, post: PID,
			items: items,
			recipe: (cfg.templates[parseInt($('#dze-var-tpl').val(), 10)] || {}).id || ''
		})
			.done(function (r) {
				$('#dze-var-saveall').prop('disabled', false);
				if (!r || !r.success) {
					if ($where) { varSay($where, (r && r.data && r.data.message) || i18n.error, true); }
					else { $('#dze-var-state').addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); }
					return;
				}
				varForget(keys, urls);
				varRefresh();
			})
			.fail(function (x) {
				$('#dze-var-saveall').prop('disabled', false);
				if ($where) { varSay($where, reason(x), true); }
				else { $('#dze-var-state').addClass('is-ko').text(reason(x)); }
			});
	}
	// Decided, one way or the other: out of the row, and out of the product's
	// waiting list — the images that are still undecided stay exactly where
	// they are.
	function varForget(keys, urls) {
		keys.forEach(function (k) { delete vars.made[k]; });
		$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: urls });
		res.shots = (res.shots || []).filter(function (u) { return urls.indexOf(u) < 0; });
		drawShots();
	}
	$(document).on('click', '.dze-var-keep', function () {
		var $row = $(this).closest('.dze-var-row');
		varSave([ varGroup($row) ], $row);
	});
	$(document).on('click', '#dze-var-saveall', function () {
		varSave(Object.keys(vars.made || {}), null);
	});
	$(document).on('click', '.dze-var-throw', function () {
		var $row = $(this).closest('.dze-var-row');
		var key = varGroup($row);
		varForget([ key ], [ vars.made[key] || '' ]);
		$row.find('.dze-var-work').empty();
		varDrawMade();
	});

	// The image the new one is put next to. Replacing the main image means
	// comparing with the main image; adding a gallery shot means comparing with
	// the photograph it was made from — and with nothing at all when it was
	// made from every photograph of the product at once.
	function oneReference(mainUrl) {
		if ('gallery' !== one.scope) {
			return { url: mainUrl, caption: i18n.qmNow };
		}
		if (onePastes().length) { return { url: onePastes()[0], caption: i18n.qmSource }; }
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
						$('<span class="dze-one-trytick">✓</span>'),
						// Same ↻ as everywhere else: this attempt again, with the
						// same instructions, in its place. It was the one surface
						// where a bad attempt could only be unticked and left to
						// clutter the strip.
						$('<span class="dze-one-tryredo" role="button" tabindex="-1"></span>')
							.attr('title', i18n.shotRedo).text('↻')
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
			n ? (n > 1 ? i18n.oneApplyN : i18n.oneApply) : i18n.oneDropAll
		);
	}
	// Saved when you leave it: one line typed once, kept with the product.
	$(document).on('change blur', '#dze-one-note', function () {
		var note = $(this).val() || '';
		if (note === (cfg.note || '')) { return; }
		cfg.note = note;
		var $st = $('.dze-one-notestate').text('…');
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_variation_note', nonce: cfg.nonce,
			post: PID, group: '*', note: note
		})
			.done(function (r) { $st.text((r && r.success) ? i18n.noteSaved : ((r && r.data && r.data.message) || i18n.error)); })
			.fail(function () { $st.text(i18n.error); });
	});
	$(document).on('change', '#dze-one-target', function () {
		$('#dze-one-oldwrap').toggle('main' === $(this).val());
	});
	$(document).on('click', '.dze-one-try', function () {
		var u = String($(this).data('url'));
		one.keep[u] = !one.keep[u];
		$(this).toggleClass('is-sel', !!one.keep[u]);
		oneApplyLabel();
	});
	// This attempt again: the new one takes its place in the strip instead of
	// piling up next to it, and the one it replaces leaves the waiting list —
	// an attempt nobody will ever look at again is not a decision to take.
	$(document).on('click', '.dze-one-tryredo', function (e) {
		e.stopPropagation();
		var $card = $(this).closest('.dze-one-try').addClass('is-busy');
		var url = String($card.data('url'));
		var $st = $('#dze-one-state').removeClass('is-ko').text(i18n.generating);
		$.post(cfg.ajaxUrl, oneImageRequest())
			.done(function (r) {
				$card.removeClass('is-busy');
				if (!r || !r.success) {
					$st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error);
					return;
				}
				var i = (one.tries || []).indexOf(url);
				if (i >= 0) { one.tries[i] = r.data.url; } else { one.tries.push(r.data.url); }
				one.keep[r.data.url] = true;
				delete one.keep[url];
				$.post(cfg.ajaxUrl, {
					action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: [ url ]
				});
				$st.text('');
				oneDrawTries();
			})
			.fail(function (x) { $card.removeClass('is-busy'); $st.addClass('is-ko').text(reason(x)); });
	});

	// ---- What the page itself shows, after we have written to the database ----
	//
	// A generated text was saved and the page went on showing the old one. That
	// is not only a display gap: the product form still HELD the old value, so
	// pressing Update wrote it straight back over what had just been written.
	// So every result goes into the very field of the page it was saved into,
	// and what has no field on screen — SEO meta, custom blocks, attributes —
	// is named honestly with one button to reload.
	var pageWasClean = null;
	function postChanged() {
		try {
			return !!(window.wp && wp.autosave && wp.autosave.server && wp.autosave.server.postChanged());
		} catch (e) { return true; }
	}
	function rememberClean() {
		if (null === pageWasClean) { pageWasClean = !postChanged(); }
	}
	// Returns true when the page now shows it.
	function applyToPage(fid, value) {
		var dest = (cfg.dests || {})[fid] || '';
		if ('post_title' === dest) {
			var $t = $('#title');
			if (!$t.length) { return false; }
			$t.val(value).trigger('input').trigger('change');
			$('#title-prompt-text').addClass('screen-reader-text');
			return true;
		}
		if ('post_content' === dest) {
			var ed = window.tinymce && tinymce.get('content');
			if (ed && !ed.isHidden()) { ed.setContent(value); ed.fire('change'); return true; }
			var $c = $('#content');
			if ($c.length) { $c.val(value).trigger('change'); return true; }
			return false;
		}
		if ('post_excerpt' === dest) {
			var ed2 = window.tinymce && tinymce.get('excerpt');
			if (ed2 && !ed2.isHidden()) { ed2.setContent(value); ed2.fire('change'); return true; }
			var $e = $('#excerpt');
			if ($e.length) { $e.val(value).trigger('change'); return true; }
			return false;
		}
		// SEO meta, custom fields, attributes: written, but with no field of
		// their own on this page to write into.
		return false;
	}
	// Said once, where the work was done, with the only honest way out of it.
	// What happens on the PAGE once something has been written to the product.
	//
	// The popup used to leave a three-word state line in a panel it then hid,
	// and the page went on showing what the product no longer held: nothing
	// looked like it had happened. Now it says what was written, brings the
	// two image boxes up to date, and reloads the page when the page has
	// nothing of its own to lose. A page carrying unsaved edits is never
	// reloaded from under its owner: it gets the button instead.
	function pageWritten(n, $where) {
		$('#dze-cx-runstate').removeClass('is-ko').html(
			'<strong class="dze-cx-ok">' + esc(sprintf(i18n.written, n)) + '</strong>'
		);
		refreshBoxes();
		if (false !== pageWasClean) {
			$('#dze-cx-runstate').append(' <span class="description">' + esc(i18n.reloading) + '</span>');
			window.setTimeout(function () { window.location.reload(); }, 1400);
			return;
		}
		sayReload($where && $where.length ? $where : $('#dze-cx-runstate').parent());
	}
	function sayReload($where) {
		if (!$where || !$where.length || $where.find('.dze-reload').length) { return; }
		$where.append(
			$('<span class="dze-reload-wrap"></span>').append(
				$('<span class="dze-reload-msg"></span>').text(i18n.reloadWhy),
				$('<button type="button" class="button button-small dze-reload"></button>').text(i18n.reloadNow)
			)
		);
	}
	$(document).on('click', '.dze-reload', function () { window.location.reload(); });
	// Closing the popup on a page that has nothing unsaved: the reload is taken
	// care of rather than asked for. A page carrying edits is never reloaded
	// from under its owner.
	function reloadIfIdle() {
		if (!res.needsReload) { return; }
		// The only edits since we started are ours, and they are already in the
		// database: re-asking postChanged() here reads OUR own writes as unsaved
		// work and never reloads, which is how the page kept showing the old
		// text after accepting.
		if (false === pageWasClean) { return; }
		window.location.reload();
	}

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
					// The rows come from the server, drawn the way WooCommerce
					// draws them. They used to be cloned from a row already on
					// the page, which works until the gallery is empty: the
					// FIRST picture added to a product then appeared nowhere,
					// and only a reload showed it — the same gesture working or
					// not depending on what the product already had.
					var $add = $list.find('li.add_product_images').detach();
					$list.find('li.image').remove();
					$list.append(r.data.gallery_html || '');
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
			// Deciding is deciding for the whole strip: what is ticked is
			// written to the product, what is not is refused — and BOTH leave
			// the waiting list. They used to be left behind, one attempt per
			// generation, so a product worked on from its own page stayed
			// flagged "to review" on the bulk screen for ever.
			var settle = function (r, msg) {
				// Everything waiting on the product, not only this strip: a
				// decision taken here closes the product, and what was not
				// kept is refused. Leaving the rest for later is what had
				// products still flagged to review after being dealt with.
				$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID })
					.always(function () {
						$.post(cfg.ajaxUrl, {
							action: 'dze_content_logged', nonce: cfg.nonce, post: PID,
							texts: 0, images: kept.length, unqueue: 1
						});
						one.tries = [];
						one.keep = {};
						oneDrawTries();
						$('#dze-one-pair').hide();
						$('#dze-one-dest').hide();
						$('#dze-one-apply').hide();
						$('#dze-one-gen').text(i18n.generate);
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
		rememberClean();
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_apply', nonce: cfg.nonce, post: PID, field: one.fid, value: val
		}).done(function (r) {
			if (r && r.success && !applyToPage(one.fid, val)) {
				res.needsReload = true;
				sayReload($('#dze-one .dze-one-bar'));
			}
			if (r && r.success) {
				// The same rule as everywhere else: written is decided, the
				// product stops waiting and is filed under Done.
				$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID })
					.always(function () {
						$.post(cfg.ajaxUrl, {
							action: 'dze_content_logged', nonce: cfg.nonce, post: PID,
							texts: 1, images: 0, unqueue: 1
						});
						res.shots = [];
						res.texts = {};
					});
			}
			done(r);
		}).fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
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
			// "Write this with AI" only where the box itself says what "this"
			// is — the title, the description, the short description. Anywhere
			// else the button carries the name of what it writes, because a
			// button that does not say what it does is worse than no button.
			var plain = [ '#titlediv', '#postdivrich', '#postexcerpt' ].indexOf(sel) >= 0;
			var html = fids.map(function (fid) {
				placed[fid] = 1;
				var label = (fids.length > 1 || !plain) ? cfg.fields[fid] : i18n.oneWrite;
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
