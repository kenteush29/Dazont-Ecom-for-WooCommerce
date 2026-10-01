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
	// %2$s IS THE SECOND ARGUMENT, wherever it stands. Filled in order of
	// appearance, « with %3$s · about %2$s » printed the price where the model
	// goes: « 1 photographs with $0.24 · about GPT Image 2.5 Sunburst ».
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%(\d+)\$s|%s/g, function (m, n) { return n ? args[parseInt(n, 10) - 1] : args[i++]; });
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
		if (typeof r === 'string' && String( r ).trim()) {
			return (i18n.badAnswer || '') + ' ' + String( $('<i></i>').html(r).text() ).trim().slice(0, 300);
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
		// AND WHAT THE LAST RUN WAS DOING. "Step 2 of 2 · 1s — quand je clique
		// sur un autre produit après avoir déjà édité un autre, ce texte reste
		// là." A progress line belongs to the run that wrote it; left on the
		// screen it describes work done to a different product.
		$('#dze-cx-prog').hide();
		$('#dze-cx-prog .dze-cb-fill').css('width', '0%');
		$('#dze-cx-progcount, #dze-cx-progstep, #dze-cx-progtime').empty();
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
		return found || { tpl: String(tpl), scene: sceneOf(tpl), n: 1, target: targetOf(tpl) };
	}
	// THE SCENE BELONGS TO THE PROMPT, like the destination beside it. It used
	// to be one answer for the whole shop, remembered from whatever was picked
	// last on any screen — so a prompt asking for a customer's own snapshot
	// arrived with a studio backdrop attached, and the appended sources block
	// then declares that image to be the background of the photograph. The
	// answer came back a white pack shot and nothing said why. The menu below
	// is a one-off for the run about to be launched; it is not remembered.
	function sceneOf(sel) {
		var t = cfg.templates[parseInt(sel, 10)] || {};
		var scenes = cfg.scenes || [];
		var i = (t.scene === undefined || t.scene === null) ? -1 : parseInt(t.scene, 10);
		if (isNaN(i) || i < 0 || i >= scenes.length) { return -1; }
		return i;
	}
	function targetOf(sel) {
		var t = cfg.templates[parseInt(sel, 10)] || {};
		return t.target || 'gallery';
	}
	function sceneSelect(cur) {
		var scenes = cfg.scenes || [];
		if (!scenes.length) { return ''; }
		if (cur === undefined || cur === null || isNaN(cur)) { cur = -1; }
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
		if (scene === undefined || scene === null || isNaN(parseInt(scene, 10))) { scene = sceneOf(sel); }
		var opts = cfg.templates.map(function (t, i) {
			return '<option value="' + i + '"' + (String(sel) === String(i) ? ' selected' : '') + '>' +
				esc(t.name) + (t.valid ? '' : ' — ' + esc(i18n.notValid)) + '</option>';
		}).join('');
		var cur = cfg.templates[parseInt(sel, 10)] || cfg.templates[0] || {};
		return '<span class="dze-tplrow"><select class="dze-cx-tpl">' + opts + '</select>' +
			promptBtn(cur.id) +
			sceneSelect(scene) + nSelect(n) + targetSelect(target || targetOf(sel)) +
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
		// A row added or taken away changes the bill. Both presses come through
		// here, so neither of them has to remember to say so.
		drawWillSpend();
	}
	function firstFreeTpl() {
		var used = tplUsed();
		for (var i = 0; i < cfg.templates.length; i++) {
			if (used.indexOf(String(i)) < 0) { return i; }
		}
		return 0;
	}
	$(document).on('click', '.dze-cx-tpladd', function () {
		$('#dze-cx-tplrows').append(tplRow(firstFreeTpl(), undefined, 1));
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
		// Which menus this press MOVED, so only those are re-derived below.
		// Identified by the element itself, never by an index: rows are added
		// and removed, and an index is a name that goes out of date.
		var moved = [], $rows = $('#dze-cx-tplrows .dze-cx-tpl');
		$rows.each(function () {
			if (this === $me[0]) { seen = true; return; }
			if (seen && $(this).val() === v) { $(this).val(String(firstFreeTpl())); moved.push(this); }
		});
		var used = {}, dupe = false;
		$rows.each(function () {
			if (used[$(this).val()]) { dupe = true; }
			used[$(this).val()] = 1;
		});
		if (dupe) { $me.val(String(firstFreeTpl())); }
		// THE ROW THAT CHANGED IS THE ROW THAT IS RE-DERIVED. A row pointed
		// at another prompt is another order, so its peek button, its
		// destination and its scene all follow the prompt it now points at —
		// but this used to walk EVERY row on the screen, so changing the
		// prompt on one line threw away the scene chosen by hand on the
		// others. Most prompts inherit the shop's default background, so what
		// it looked like was the menu snapping back to that background for no
		// reason anybody could see: "un truc change toujours Scene en
		// standard background". The menu on a row is a one-off for the run
		// about to be launched, and nothing but a press on that row's own
		// prompt menu may overwrite it.
		//
		// A row whose prompt the duplicate guard moved counts as changed too:
		// it is now pointing somewhere nobody chose, and its old scene belongs
		// to a prompt it no longer runs.
		$rows.filter(function () { return this === $me[0] || moved.indexOf(this) >= 0; })
			.closest('.dze-tplrow').each(function () {
				var $r = $(this), sel = $r.find('.dze-cx-tpl').val();
				var t = cfg.templates[parseInt(sel, 10)] || {};
				$r.find('.dze-prompt-peek').attr('data-prompt', 'content_' + (t.id || ''));
				$r.find('.dze-tpl-target').val(targetOf(sel));
				$r.find('.dze-tpl-scene').val(String(sceneOf(sel)));
			});
		remember();
	});

	function remember() {
		var m = mem();
		m.auto = {
			fields: $('.dze-cx-f:checked').map(function () { return $(this).val(); }).get(),
			price: $('#dze-cx-doprice').is(':checked') ? 1 : 0,
			img: $('#dze-cx-doimg').is(':checked') ? 1 : 0,
			// WHAT IS REMEMBERED IS THE ORDER, NOT ITS BACKGROUND. The scene
			// and the destination are the prompt's own and are read from it
			// every time the row is drawn; remembering the ones a row happened
			// to carry is how a destination the screen had filled in by itself
			// came back months later as a decision, sending a customer-snapshot
			// prompt onto the main image.
			tpls: tplJobs().map(function (j) { return { tpl: j.tpl, n: j.n }; })
		};
		saveMem(m);
	}

	// =====================================================================
	// The popup
	// =====================================================================

	// One shut section per kind of work: a title you click, a caret, a body.
	// Which ones you left open is remembered, because a habit is a habit.
	// ONE TICK PER BLOCK, AND IT LIVES IN THE BLOCK'S OWN TITLE. Images and
	// price each carried a second checkbox inside the body saying the same
	// thing as the section it was in — "sur le bloc image et prix, ça n'a pas
	// de sens d'avoir un double bouton". So the switch moved up into the
	// heading, where it is the block's one control; on the text block the same
	// tick means "all of them", which is what a list of nine prompts needs.
	// ONE BUILDER FOR A BLOCK, in hub.js — the shell every screen is made of.
	// What this popup remembers is which of ITS blocks were left open.
	function sec(id, title, openByDefault, body, tick) {
		var m = mem();
		var open = (m.sec && m.sec[id] !== undefined) ? !!m.sec[id] : !!openByDefault;
		return window.dzeHub.sec(id, title, open, body, tick);
	}
	// Opening and closing a section is handled once, in hub.js, for every
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
				// THE WAY TO THE PRODUCT ITSELF. Opened from the products list
				// or from a diagnostic line, the product is nowhere on the
				// screen — and some of the work belongs there: adding a
				// photograph from outside, checking what the page really says.
				// A new tab, so nothing here is lost by going to look.
				'<a id="dze-cx-edit" class="button" href="#" target="_blank" rel="noopener" style="display:none;">' +
					esc(i18n.openProduct || 'Open the product') + ' \u2197</a>' +
				'<button type="button" class="button dze-cx-close">' + esc(i18n.close) + '</button></div>' +
			'<div class="dze-cx-body">' +
				// Why this popup opened the way it did, when something opened
				// it FOR a reason — a diagnostic line saying this product is
				// two photographs short. Empty and hidden otherwise: the
				// product screen's own button has nothing to explain.
				// WHAT THIS PRODUCT IS SHORT OF, wherever the popup was opened
				// from. It used to arrive only with a press from a diagnostic
				// line, so the same product opened from its own page showed
				// nothing at all. One line per shortfall, and pressing a line
				// lays that one out below.
				'<div class="dze-cx-todo" id="dze-cx-todo" style="display:none;"></div>' +
				blockers +
				// Grouped by KIND, one shut section each: text with text, images
				// with images, price on its own. Everything open at once is how
				// this popup became impossible to read.
				'<div class="dze-cb-controls">' +

					// ---- TEXT ----
					sec('text', i18n.text, true,
						'<div class="dze-cb-checks is-col">' + checks + '</div>',
						{ all: true, tip: i18n.allTip }
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
								'</details>' +
								// What no photograph of this product shows. It
								// travels with every image made here, and it
								// was only editable in the one-function popup —
								// so a run started from this screen used a note
								// nobody could see, let alone write.
								'<details class="dze-cx-acc dze-cx-else" id="dze-cx-notewrap">' +
									'<summary>' + esc(i18n.noteTitle) + '</summary>' +
									'<p class="description">' + esc(i18n.noteHelp) + '</p>' +
									'<textarea id="dze-cx-note" rows="2" class="large-text" placeholder="' + esc(i18n.notePh) + '"></textarea>' +
								'</details>' +
							'</div>' +
						'</div>' : ''),
						cfg.templates.length ? { id: 'dze-cx-doimg', on: !!au.img, tip: i18n.genImgOpt } : null
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
						'<div class="dze-cb-opts"><label><span>' + esc(i18n.costLabel) + '</span>' +
						'<input type="number" step="0.01" id="dze-cx-cost" value="' + esc(cfg.product.price) + '" /></label>' +
						'<button type="button" class="button button-small" id="dze-cx-pricepv">' + esc(i18n.pricePreview) + '</button>' +
						// The table this reads from, one click away from where it is
						// used — not hunted for in a settings tab.
						(cfg.priceUrl ? '<a class="dze-cx-priceedit" href="' + esc(cfg.priceUrl) + '" target="_blank" rel="noopener">' + esc(i18n.pvEdit) + ' →</a>' : '') +
						'</div>' +
						'<div class="dze-cx-pricebox" id="dze-cx-pricebox" style="display:none;"></div>',
						{ id: 'dze-cx-doprice', on: !!au.price, tip: i18n.priceOpt }
					) +

					'<p class="dze-cb-actions">' +
						'<button type="button" class="button button-primary button-hero" id="dze-cx-run">' + esc(i18n.launch) + '</button>' +
						'<span class="dze-cx-state" id="dze-cx-runstate"></span>' +
						'<span class="dze-spend" title="' + esc(i18n.spendTip) + '"></span>' +
						// What THIS press is about to spend, beside what the
						// product has already cost. The two answer different
						// questions and the second one was never asked.
						'<span class="description" id="dze-cx-willspend" style="display:none;"></span>' +
					'</p>' +
					'<div class="dze-cx-prog" id="dze-cx-prog" style="display:none;">' +
						'<div class="dze-cb-bar"><div class="dze-cb-fill"></div></div>' +
						'<p><strong id="dze-cx-progcount"></strong> ' +
							'<span id="dze-cx-progstep"></span> ' +
							'<span id="dze-cx-progtime" class="description"></span></p>' +
					'</div>' +
				'</div>' +
				// WHAT WAS ASKED FOR THIS PRODUCT, folded away. The bulk panel
				// has carried it since the day it was written and the toolbox
				// never did — and the toolbox is where somebody stands when a
				// photograph comes back strange. Same markup, same renderer,
				// same answer from the server.
				'<div id="dze-cx-logwrap"></div>' +
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
			var row = (v && typeof v === 'object') ? v : { tpl: v, n: au.imgn || 1 };
			$('#dze-cx-tplrows').append(tplRow(row.tpl, undefined, row.n));
		});
		syncTplRows();
		$(document).on('change', '.dze-cx-f, #dze-cx-doprice, #dze-cx-doimg, .dze-tpl-scene, .dze-tpl-n, .dze-tpl-target', remember);
	}

	/**
	 * Arms the popup for the block somebody came to mend.
	 *
	 * Nothing is run and nothing is remembered: the popup opens on the section
	 * that matters, with the work already laid out, and the person looks at it
	 * and changes it before pressing anything. A press that starts work the
	 * moment it is clicked is a press nobody can steer — which is exactly what
	 * the diagnostic's own button used to be.
	 *
	 * @param {Object} want {section, field, shots:[{tpl,n,target}], why}
	 */
	// WHO OPENED THIS POPUP, and what for. A screen that opened it for a reason
	// is a screen that wants to know how it went — and wants to be left alone
	// rather than reloaded from under its owner. Kept outside `res`, which
	// reset() empties on every product change.
	var OPENED_FOR = null;
	function arm(want) {
		OPENED_FOR = ( want && want.section ) ? want : null;
		if (!want || !want.section) { markTodo(''); return; }
		$('#dze-cx-modal .dze-sec').each(function () {
			toggleSec($(this), $(this).data('sec') === want.section);
		});
		// EXACTLY WHAT WAS ASKED FOR, and nothing else. The remembered ticks
		// are the ones from the last run on the product screen, so pressing
		// "Make photographs…" on a diagnostic line opened a popup with every
		// text prompt ticked as well — "très inconfortable", and a press away
		// from rewriting a description nobody asked to touch.
		$('.dze-cx-f').prop('checked', false);
		$('#dze-cx-doimg, #dze-cx-doprice').prop('checked', false);
		if (want.field) {
			$('.dze-cx-f[value="' + want.field + '"]').prop('checked', true);
		}
		if ('price' === want.section) { $('#dze-cx-doprice').prop('checked', true); }
		if (want.shots && want.shots.length && $('#dze-cx-tplrows').length) {
			$('#dze-cx-doimg').prop('checked', true);
				$('#dze-cx-tplrows').empty();
			want.shots.forEach(function (row) {
				$('#dze-cx-tplrows').append(tplRow(row.tpl, undefined, row.n || 1, row.target));
			});
			syncTplRows();
		}
		// The line this popup opened on is marked in the list, so a popup that
		// came up armed says WHICH of the product's shortfalls it came up for.
		markTodo(want.check || '');
	}

	// ---- The product's own to-do list -------------------------------------
	// Read from the server for the product the popup is on, whoever opened it.
	// Every line says what is short and by how much — the sentence the problem
	// list prints on its own rows — and carries the arming that lays it out.
	var TODO = [];
	function markTodo(check) {
		$('#dze-cx-todo .dze-cx-todoline').each(function () {
			$(this).toggleClass('is-armed', !!check && $(this).data('check') === check);
		});
	}
	function drawTodo(rows) {
		TODO = rows || [];
		var $box = $('#dze-cx-todo');
		if (!$box.length) { return; }
		if (!TODO.length) {
			$box.html('<p class="dze-cx-todonone">' + esc(i18n.todoNone) + '</p>').show();
			return;
		}
		$box.html(
			'<p class="dze-cx-todohead">' + esc(i18n.todoTitle) + '</p>' +
			'<ul class="dze-cx-todolist">' + TODO.map(function (one, i) {
				var can = one.want && one.want.section;
				return '<li class="dze-cx-todoline" data-check="' + esc(one.check) + '" data-i="' + i + '">' +
					'<span class="dze-cx-todosaid">' + esc(one.said) + '</span>' +
					(can ? '<button type="button" class="button-link dze-cx-todogo">' + esc(i18n.todoOpen) + '</button>' : '') +
				'</li>';
			}).join('') + '</ul>'
		).show();
		markTodo(OPENED_FOR ? (OPENED_FOR.check || '') : '');
	}
	function loadTodo() {
		if (!PID || !cfg.diagTodo) { return; }
		$.post(cfg.ajaxUrl, { action: 'dze_diag_todo', nonce: cfg.diagNonce, post: PID })
			.done(function (r) { if (r && r.success) { drawTodo(r.data.rows); } })
			.fail(function () { $('#dze-cx-todo').hide().empty(); });
	}
	// A LINE LAYS ITSELF OUT; IT RUNS NOTHING. Same gesture as the diagnostic's
	// own button, and the same arming behind it.
	$(document).on('click', '.dze-cx-todogo', function () {
		var one = TODO[parseInt($(this).closest('.dze-cx-todoline').data('i'), 10)];
		if (one && one.want) { arm(one.want); }
	});

	// The link in the head, pointed at the product the popup is on. Hidden
	// when the server did not give one — a link to "#" is a broken promise.
	function productLink(cur) {
		var url = (cur && cur.edit) || '';
		$('#dze-cx-edit').attr('href', url || '#').toggle(!!url);
	}

	function open(pid, want) {
		build();
		var target = parseInt(pid, 10) || cfg.postId || 0;
		var switching = target !== PID;
		PID = target;
		$('#dze-cx-modal').addClass('is-open');
		arm(want);
		// The box that takes photographs from outside is part of the popup, and
		// it opens on THIS product's own photographs — never emptied, because
		// what was handed in belongs to the product and the run it was handed
		// in for may still be waiting for a decision.
		cxPasteBox();
		if (switching) {
			reset();
			// A product we were not opened on: ask the server who it is, what it
			// costs and what is already waiting on it, then arm the popup.
			$('#dze-cx-runstate').html('<span class="dze-cx-spin"></span>');
			loadCurrent().then(function (cur) {
				$('#dze-cx-runstate').empty();
				$('#dze-cx-who').text(cur.title || '');
				productLink(cur);
				// The note belongs to the product the popup is on, not to the
				// page it was loaded with — from the products list, that is a
				// different product on every row.
				cfg.note = cur.note || '';
				$('#dze-one-note, #dze-cx-note').val(cfg.note);
				$('#dze-cx-notewrap').prop('open', !!cfg.note.trim());
				drawSpend(cur.spend);
				if (cur.cost) { $('#dze-cx-cost').val(cur.cost); }
				drawCurrentImages();
				markWritten(cur.texts);
				loadTodo();
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
			productLink(cur);
			drawCurrentImages();
			markWritten(cur.texts);
			loadTodo();
			cfg.note = cur.note || '';
			$('#dze-one-note, #dze-cx-note').val(cfg.note);
			$('#dze-cx-notewrap').prop('open', !!cfg.note.trim());
			drawSpend(cur.spend);
			if (cur.pending && (Object.keys(cur.pending.texts || {}).length || (cur.pending.shots || []).length)) {
				hydrate(cur.pending);
			}
		});
	}
	$(document).on('click', '#dze-cx-open-auto', function () { open(cfg.postId); });
	// From the products list: one chip per row, same popup. And from the
	// content diagnostic, where the row knows WHICH block falls short and how
	// far, so the popup opens on it with the work already laid out.
	$(document).on('click', '.dze-content-open', function () {
		var $b = $(this), want = $b.data('want');
		if (typeof want === 'string') { try { want = JSON.parse(want); } catch (e) { want = null; } }
		open($b.data('id'), want);
	});
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

	// What this product has cost in images so far. Written from whatever the
	// server just said, and the server counts it after every generation — the
	// figure on screen is never one the browser worked out for itself.
	function drawSpend(spend) {
		if (!spend) { return; }
		cfg.spend = spend;
		$('.dze-spend').text(spend.label || '').toggle(!!(spend.label || ''));
	}

	// ---- What the product says today ----
	//
	// A field that already holds something is not generated, it is REWRITTEN —
	// and the screen says so before the button is pressed rather than after,
	// because the two are not the same decision: one fills a hole, the other
	// replaces work that is already live.
	function markWritten(texts) {
		texts = texts || {};
		$('.dze-cx-f').each(function () {
			var $f = $(this), has = !!String( String(texts[$f.val()] || '').replace(/<[^>]*>/g, '') ).trim();
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
		drawWillSpend();
	}
	// WHAT THIS PRESS IS ABOUT TO SPEND, before it is pressed. The photographs
	// block is rows, not ticks: three rows at four attempts is twelve calls to
	// fal for this one product, and the button said "Generate".
	// WHAT ONE PICTURE COSTS WITH THE MODEL IN FORCE, for the photographs it is
	// sent. « J'ai changé pour Sunburst, la data affichée est fausse » — one
	// figure for the whole page could neither follow the model nor count what
	// a product really sends: Nano Banana is billed per image, GPT Image and
	// FLUX also bill every photograph they are handed.
	function perImage(refs, price) {
		var p = price || cfg.imagePrice || null;
		if (!p) { return parseFloat(cfg.imageCost || 0) || 0; }
		var cap = parseInt(p.cap, 10) || 0;
		var n = Math.max(0, cap > 0 ? Math.min(cap, refs) : refs);
		return (parseFloat(p.base) || 0) + (parseFloat(p.perRef) || 0) * n;
	}
	// HOW MANY OF ITS OWN PHOTOGRAPHS the product sends, counted as the server
	// counts them: all of them up to the shop's figure, and three for a main
	// or a variation image. Until the product has answered, the shop's figure
	// — never the model's ceiling of sixteen, which priced two photographs
	// as sixteen and was never redrawn.
	function ownSources(target) {
		var own = (res.current && res.current.sources !== undefined)
			? (parseInt(res.current.sources, 10) || 0)
			: (parseInt(cfg.sourceCap, 10) || 10);
		target = String(target || '');
		if ('main' === target || 0 === target.indexOf('variation:')) { own = Math.min(own, parseInt(cfg.mainCap, 10) || 3); }
		return own;
	}
	// The photographs one image of THIS product is sent: its own, what was
	// pasted for the run, and the scene when the row has one.
	function refsFor(scene, target) {
		var pasted = cxPaste ? cxPaste.list().length : 0;
		return ownSources(target) + pasted + ((scene !== undefined && scene >= 0) ? 1 : 0);
	}
	function willSay(n, cost, model) {
		var single = 1 === n;
		if (!cost) { return (single && i18n.willMakeOne) ? i18n.willMakeOne : sprintf(i18n.willMake, n); }
		var money = '$' + cost.toFixed(2);
		var name = model || (cfg.imagePrice && cfg.imagePrice.model) || '';
		var fmt = (name && i18n.willCostWith)
			? ((single && i18n.willCostWithOne) || i18n.willCostWith)
			: ((single && i18n.willCostOne) || i18n.willCost);
		return sprintf(fmt, n, money, name);
	}
	// The price of the model this press will use: the one picked beside
	// Generate, or the shop's.
	function chosenPrice() {
		var k = $('#dze-one-model').val() || '';
		var hit = null;
		(cfg.imageModels || []).forEach(function (m) { if (m.key === k) { hit = m; } });
		return hit || cfg.imagePrice || null;
	}
	function drawWillSpend() {
		var $out = $('#dze-cx-willspend');
		if (!$out.length) { return; }
		var n = 0, cost = 0;
		// The same two conditions the press itself reads, and the same rows.
		if ($('#dze-cx-doimg').is(':checked') && cfg.templates.length) {
			tplJobs().forEach(function (job) {
				var k = Math.max(1, job.n);
				n    += k;
				cost += k * perImage(refsFor(job.scene, job.target));
			});
		}
		if (!n) { $out.text('').hide(); return; }
		var said = willSay(n, cost);
		var cap = parseInt(cfg.falPostCap, 10) || 0;
		if (cap > 0 && n > cap) { said += ' \u00b7 ' + sprintf(i18n.overCap, cap, n - cap); }
		$out.show().text(said);
	}
	$(document).on('change', '.dze-cx-f, #dze-cx-doimg, #dze-cx-doprice', runLabel);
	// A row added, removed or set to a different number of attempts changes the
	// bill, and those rows are drawn after the bar.
	$(document).on('change', '.dze-tpl-n, .dze-cx-tpl', drawWillSpend);

	function loadCurrent() {
		if (res.current) { return $.Deferred().resolve(res.current); }
		return $.post(cfg.ajaxUrl, { action: 'dze_content_current', nonce: cfg.nonce, post: PID })
			.then(function (r) {
				if (r && r.success) {
					res.current = r.data;
					// Both bills counted the product's photographs before it
					// had said how many it has: both are drawn again.
					drawWillSpend();
					oneWillSpend();
					return res.current;
				}
				// A refusal is not "this product has no photographs": it is a
				// failure, and it is said as one. Not cached either, so the
				// next opening asks again instead of showing the same silence.
				return { texts: {}, images: [], failed: (r && r.data && r.data.message) || i18n.error };
			}, function (x) { return { texts: {}, images: [], failed: reason(x) }; });
	}
	// The product's own photographs, offered as the subject of what is made.
	// Kept in step with the strip above it: a photograph deleted while the
	// popup is open must not stay on this list as a thing to work from.
	// WHAT WAS ASKED FOR THIS PRODUCT. Read WHEN THE FOLD IS OPENED, never
	// carried in the bundle that says what the product holds: that bundle is
	// kept for as long as the panel is open — rightly, since what a product
	// holds only changes when this screen changes it — and a log grows with
	// every run. Taken from it, the list a run had just added to went on
	// showing what it held before the panel was opened.
	function drawLog() {
		var $slot = $('#dze-cx-logwrap');
		if (!$slot.length) { return; }
		$slot.html('<details class="dze-cx-acc dze-cb-logbox"><summary>' +
			esc(i18n.askedFor) + '</summary><div class="dze-cb-logbody"></div></details>');
		// BOUND ON THE ELEMENT, NOT DELEGATED: `toggle` on a <details> does
		// not bubble, so a handler on the document never hears it and the
		// fold opens on an empty box for ever.
		$slot.find('.dze-cb-logbox').on('toggle', function () {
			var $box = $(this), $body = $box.find('.dze-cb-logbody');
			if (!$box.prop('open')) { return; }
			$body.text(i18n.working || '');
			$.post(cfg.ajaxUrl, { action: 'dze_content_log', nonce: cfg.nonce, post: PID })
				.then(function (r) {
					$body.html((r && r.success && r.data && r.data.log) || esc(i18n.error));
				}, function (x) { $body.text(reason(x)); });
		});
	}

	function drawCurrentImages() {
		// One renderer for both screens: admin/js/photos.js. The product screen
		// adds the AI button, because it has a popup to open.
		drawLog();
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
				// THE ONE IT REPLACES LEAVES THE WAITING LIST TOO: replaced on
				// screen and kept on the server, it came back on the next visit.
				if (url && url !== r.data.url) {
					$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: [ String(url) ] });
				}
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
	//
	// WHAT WAS HANDED IN BELONGS TO THE PRODUCT, NOT TO THE POPUP. The box
	// used to be emptied whenever the popup changed product and there was
	// nowhere for what it held to go — so from the products list or the
	// diagnostic, where the toolbox hops from row to row, a supplier's
	// photographs were gone the moment you looked at the next product, while
	// the images they had produced were still sitting there waiting for a yes
	// or a no. Coming back, the popup showed the pictures and nothing they
	// were made from, and the next order went out without them.
	//
	// It is a store per product now, exactly as the bulk screen keeps its own.
	// Nothing is written to the server: a photograph handed in for the run in
	// front of you is not a standing instruction, and a reload empties it.
	var cxPaste = null;
	var cxPasted = {};
	var cxPasteOn = 0;
	function cxPastedOf(pid) { return cxPasted[String(pid)] || []; }
	function cxPasteBox() {
		var $slot = $('#dze-cx-else');
		if (!$slot.length) { cxPaste = null; return null; }
		// Mounted again when the popup has moved to another product: the box
		// carries ONE product's photographs and has to be re-opened on the
		// store of whichever product it is showing.
		if (!cxPaste || !$.contains(document.body, cxPaste.el[0]) || cxPasteOn !== PID) {
			cxPasteOn = PID;
			cxPaste = window.dzePasteBox.mount($slot, {
				max: maxPasted(),
				maxBody: maxBody(),
				start: cxPastedOf(PID),
				// Every change writes it down, deletions included: the box's
				// own draw fires this, so a photograph taken out of it does
				// not come back the next time the popup opens on this product.
				onChange: function (l) {
					if (PID) { cxPasted[String(PID)] = (l || []).slice(); }
				}
			});
		}
		return cxPaste;
	}
	function imageRequest(tpl, scene, target) {
		var job  = jobFor(tpl);
		var data = { action: 'dze_content_image', nonce: cfg.nonce, post: PID, template: tpl, mode: 'defer', stash: 1 };
		// Whatever was handed to this run from outside the shop travels with
		// every image it makes.
		var outside = cxPaste ? cxPaste.list() : [];
		if (outside.length) { data.pastes = outside; }
		// WHICH PHOTOGRAPH IS THE SUBJECT, and the picker always answers.
		// On its default it sent nothing at all, and a request carrying
		// pasted photographs and nothing else is read by the server as "the
		// pasted one leads" — so the screen said the product's main image and
		// the run used the supplier's shot. "Main photograph" and a chosen one
		// both mean the product is image 1; what was added from outside is
		// then read for the place, the light and the styling.

		if (scene === undefined) { scene = job.scene; }
		if ((cfg.scenes || []).length) { data.scene = scene; }
		// Where it goes travels with the order, so the strip knows without
		// being told again and the choice survives a closed tab.
		data.target = target || job.target;
		// WHAT WAS TYPED FOR THIS RUN, read off the box when the request is
		// built. It is sent and never stored, so nothing steers the next run
		// but what somebody types into it then.
		var note = runNote();
		if (note) { data.note = note; }
		return data;
	}
	function genImage(tpl, scene) {
		return $.post(cfg.ajaxUrl, imageRequest(tpl, scene))
			.then(function (r) {
				if (!r || !r.success) { throw answerError(r); }
				drawSpend(r.data.spend);
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
					// The COUNTS are not sent: each text and each photograph
					// wrote itself down as it landed. Claiming them again here
					// would count every run twice.
					action: 'dze_content_logged', nonce: cfg.nonce, post: PID, unqueue: 1
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
					// WHICH MODEL MAKES THIS PRESS. « gpt vs nano banana » on one
					// product was a trip to the settings between two presses; the
					// shop's model is the one selected, the others are a click.
					'<label class="dze-qm-bglabel" id="dze-one-modelwrap" style="display:none;"><span>' + esc(i18n.oneModel || '') + '</span>' +
					'<select id="dze-one-model">' + (cfg.imageModels || []).map(function (m) {
						return '<option value="' + esc(m.key) + '"' + (m.own ? ' selected' : '') + '>' + esc(m.model) + '</option>';
					}).join('') + '</select></label> ' +
				'<button type="button" class="button button-primary" id="dze-one-gen"></button> ' +
					'<button type="button" class="button" id="dze-one-preview" style="display:none;" title="' + esc(i18n.previewTip) + '">' + esc(i18n.preview) + '</button> ' +
					'<button type="button" class="button button-primary" id="dze-one-apply" style="display:none;"></button> ' +
					'<span class="dze-cx-state" id="dze-one-state"></span>' +
					// What this product has already cost in images. Beside the
					// button that spends the next one, because that is where
					// the decision is taken: a product the model keeps getting
					// wrong is a product to stop paying for.
					'<span class="dze-spend" title="' + esc(i18n.spendTip) + '"></span>' +
					// AND WHAT THIS PRESS IS ABOUT TO SPEND. The lifetime figure
					// beside it answers a different question and was the only
					// one here, so this was the one image screen that let you
					// ask for four photographs without ever saying what four
					// would cost.
					'<span class="description" id="dze-one-willspend" style="display:none;"></span>' +
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
		// WHAT THE SERVER HOLDS, EVERY TIME IT OPENS. The copy kept from the
		// first opening brought back pictures thrown away since, and lost the
		// ones made since (« le bouton supprimer […] ne les supprime pas »).
		res.current = null;
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
		$('#dze-one-nwrap, #dze-one-modelwrap').toggle('image' === mode);
		$('#dze-one-preview').toggle('image' === mode);
		oneWillSpend();
		$('#dze-one').addClass('is-open');
		if (mode === 'image') {
			one.srcId = 0; one.srcIds = []; oneShowPasted('');
			$('#dze-one-note').val(cfg.note || '');
			$('#dze-one-notewrap').prop('open', !!(cfg.note || '').trim());
			oneDrawRecipes();
			oneSetRecipe(oneRecipes()[0] ? String(oneRecipes()[0].id) : '');
			oneDrawSources();
		}
		if (mode === 'text') { oneShowBefore(fid); }
		// The pictures waiting on the product are its bricks, under the
		// gallery: the popup only orders. A text found again opens here.
		if ('image' !== mode) { oneRestore(mode, fid); }
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
				// WHAT THE PICK MEANS, said under it: « je ne comprends pas
				// comment fonctionne la re-génération d'image basée sur ce qu'on
				// a ». Every photograph, or only the ones clicked, in that order.
				'<p class="dze-one-srcsaid description" id="dze-one-srcsaid"></p>' +
				// A strip with no photograph in it has to say WHY: an empty picker
				// beside a product whose main image is on screen reads as a bug,
				// and until now it was one — the read could fail and nothing said so.
				'<p class="dze-one-srcnote" id="dze-one-srcnote" style="display:none;margin:4px 0 0;font-size:12px;color:#646970;"></p>' +
				// Which photograph the model actually works FROM. The popup takes
				// that decision from three controls at once — what is picked in the
				// strip, what is pasted, and the tick box below — and until now it
				// took it in silence, so a run that came back looking like the main
				// image gave no clue why. It costs money to find that out twice.
				'<div id="dze-one-elsewrap" style="display:none;">' +
					// The box that takes photographs from outside the shop:
					// admin/js/paste-box.js — the same component the toolbox
					// and the bulk review panel mount.
					'<div id="dze-one-drop"></div>' +
					// An image from elsewhere is the subject, not the whole
					// brief: the product\'s own photographs say what its back,
					// its lining and its material look like, and they travel
					// with it unless you say otherwise — HERE. « Il est toujours
					// impossible d'utiliser les images externes comme unique
					// image à retravailler » : there was no way to say it at all.
					'<label class="dze-one-onlywrap" style="display:block;margin:6px 0 0;"><input type="checkbox" id="dze-one-onlypasted" /> ' + esc(i18n.onlyPasted) + '</label>' +

					// Which of the two is the SUBJECT. Pasting used to decide it
					// on its own — what you added became the thing to
					// photograph — so there was no way to say "keep this
					// product, exactly this one, and put it in that scene".

				'</div>' +
			'</div>' +

			// What no photograph of this product shows. Written once, sent with
			// every image made for it — the variations have their own, per
			// colour, and both travel together.
			'<details class="dze-one-instr" id="dze-one-notewrap">' +
				'<summary>' + esc(i18n.noteTitle) + '</summary>' +
				'<p class="description">' + esc(i18n.noteHelp) + '</p>' +
				'<textarea id="dze-one-note" rows="2" class="large-text" placeholder="' + esc(i18n.notePh) + '"></textarea>' +
			'</details>' +

			'<div class="dze-step" id="dze-one-bgstep">' +
				'<p class="dze-step-q"><span class="dze-step-n">3</span>' + esc(i18n.stepBg) + '</p>' +
				'<div class="dze-one-bgs" id="dze-one-bgs"></div>' +
			'</div>' +

			// WHAT WILL BE SENT, before anything is paid for: the pictures in
			// their order and the words exactly as the model reads them.
			'<div class="dze-one-preview dze-zoomgroup" id="dze-one-previewbox" style="display:none;"></div>' +
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
		// THE PROMPT'S OWN BACKGROUND, on screen. It was folded away for the
		// gallery prompts and sent as « None », while the bulk screen, the
		// queue and the automation used the background set on the prompt: one
		// prompt, two different orders depending on the button pressed. Every
		// prompt now opens on its own answer, and it can be changed here.
		$('#dze-one-bgstep').show();
		$('#dze-one-bg').val(String(t ? (t.bg || 0) : defaultBg()));
		oneDrawBgs();
		// How many photographs go depends on where the image lands: said
		// again for this prompt.
		oneSrcSaid();
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
				'<span class="dze-one-srcn" aria-hidden="true"></span>' +
				(im.variation ? '<span class="dze-one-srcvar">' + esc(im.variation) + '</span>' : '') +
				'</button>';
		});
		html += '<button type="button" class="dze-one-srcpick dze-one-srcnew" data-id="new">' +
			'<img id="dze-one-newthumb" alt="" style="display:none;" />' +
			'<span class="dze-one-newmsg">&#43; ' + esc(i18n.stepElse) + '</span></button>';
		return html;
	}
	// Why the strip holds no photograph — the product has none yet, or the
	// shop refused to say. Both used to look identical: two cards and nothing
	// else, next to a product whose main image was on screen.
	function oneSrcNote(cur) {
		var $note = $('#dze-one-srcnote');
		if (!$note.length) { return; }
		if (cur && cur.failed) {
			$note.show().addClass('is-ko').css('color', '#b32d2e').text(cur.failed);
			return;
		}
		if (!cur || !(cur.images || []).length) {
			$note.show().removeClass('is-ko').css('color', '#646970').text(i18n.noShots || '');
			return;
		}
		$note.hide().removeClass('is-ko').text('');
	}
	function oneDrawSources() {
		var $slot = $('#dze-one-srcs').addClass('dze-zoomgroup');
		if (!$slot.length) { return; }
		$slot.html(oneSrcStrip([]));
		loadCurrent().then(function (cur) {
			// Something was chosen while the product was loading: leave it be.
			if ((one.srcIds || []).length || onePastes().length) { return; }
			$slot.html(oneSrcStrip(cur.images || []));
			oneSrcNote(cur);
			oneSrcSaid();
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
	// SEVERAL PHOTOGRAPHS CAN BE PICKED, in order. « J'aimerais re-générer des
	// images basées sur l'image en pièce jointe » — one picture of the
	// gallery, or three of them: the first clicked is image 1, and nothing
	// that was not clicked travels. « Every photograph » puts the product's
	// ordinary set back.
	function oneSrcSaid() {
		var ids = one.srcIds || [];
		var $said = $('#dze-one-srcsaid');
		// Read BEFORE the tiles are redrawn: the « elsewhere » tile has no id,
		// and redrawing it with the others unselected it every time.
		var outside = $('.dze-one-srcnew').hasClass('is-sel');
		$('.dze-one-srcpick').not('.dze-one-srcnew').each(function () {
			var n = ids.indexOf(parseInt($(this).data('id'), 10) || -1);
			$(this).toggleClass('is-sel', n >= 0).find('.dze-one-srcn').text(n >= 0 && ids.length > 1 ? String(n + 1) : '');
		});
		$('.dze-one-srcpick[data-id="0"]').toggleClass('is-sel', !ids.length && !outside);
		// The real figure: a MAIN image is remade from the featured image and
		// two more, whatever the shop's figure for a gallery shot.
		var cap = cfg.sourceCap || 10;
		if ('main' === $('#dze-one-target').val()) { cap = Math.min(cap, cfg.mainCap || 3); }
		$said.text(outside ? ($('#dze-one-onlypasted').is(':checked') ? i18n.srcNewOnlySaid : i18n.srcNewSaid)
			: (!ids.length ? sprintf(i18n.srcAllSaid, cap)
			: (1 === ids.length ? i18n.srcOneSaid : sprintf(i18n.srcManySaid, ids.length))));
		one.srcId = ids.length ? ids[0] : 0;
		// Only ONE photograph of the product can be retired by its own remake.
		$('#dze-one-replacewrap').toggle(1 === ids.length);
		if (1 !== ids.length) { $('#dze-one-replace').prop('checked', false); }
		oneClearPreview();
		// What is sent is what is billed: the line follows the choice.
		oneWillSpend();
	}
	$(document).on('click', '.dze-one-srcpick', function () {
		var raw = String($(this).data('id'));
		var outside = 'new' === raw;
		var id = outside ? 0 : (parseInt(raw, 10) || 0);
		one.srcIds = one.srcIds || [];
		if (outside) {
			one.srcIds = [];
			$('.dze-one-srcpick').removeClass('is-sel');
			$(this).addClass('is-sel');
		} else {
			$('.dze-one-srcnew').removeClass('is-sel');
			if (!id) {
				one.srcIds = [];
			} else {
				var at = one.srcIds.indexOf(id);
				if (at >= 0) { one.srcIds.splice(at, 1); } else { one.srcIds.push(id); }
			}
		}
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
		oneSrcSaid();
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
	// Ticking « only these » changes what goes out: the sentence under the
	// strip says it, and a preview made before no longer does.
	$(document).on('change', '#dze-one-onlypasted', function () {
		oneSrcSaid();
		oneClearPreview();
	});
	function onePasteChanged(list) {
		// The tile that opened this box mirrors the set it holds.
		$('#dze-one-newthumb').attr('src', list[0] || '').toggle(list.length > 0);
		$('.dze-one-srcnew .dze-one-newmsg').toggle(!list.length);
		// A photograph pasted, dropped or taken out changes the order: what
		// was previewed for the last one no longer says what will be sent.
		oneClearPreview();
		// And the bill: each one is a photograph more for the models that
		// charge them.
		oneWillSpend();
	}
	// WHAT THIS PRESS WILL SPEND, in the same words the toolbox and the bulk
	// screen use, from the same price. A text press spends no picture and says
	// nothing rather than "0 photographs", which reads as a broken figure.
	// WHAT THIS PRESS SENDS, as the server will count it: the photographs
	// picked by hand, never cut; or the product's own set, three of them for
	// a main or a variation image; none of them when « only these » keeps the
	// pasted ones alone; then whatever was pasted.
	function oneRefs() {
		var ids = one.srcIds || [];
		var pasted = onePastes().length;
		var only = pasted > 0 && $('.dze-one-srcnew').hasClass('is-sel') && $('#dze-one-onlypasted').is(':checked');
		var own = only ? 0 : (ids.length ? ids.length : ownSources($('#dze-one-target').val() || 'gallery'));
		return own + pasted;
	}
	function oneWillSpend() {
		var $out = $('#dze-one-willspend');
		if (!$out.length) { return; }
		var n = ('image' === one.mode) ? Math.max(1, parseInt($('#dze-one-n').val(), 10) || 1) : 0;
		if (!n) { $out.text('').hide(); return; }
		var price = chosenPrice();
		var said = willSay(n, n * perImage(oneRefs(), price), price ? price.model : '');
		var cap = parseInt(cfg.falPostCap, 10) || 0;
		if (cap > 0 && n > cap) { said += ' \u00b7 ' + sprintf(i18n.overCap, cap, n - cap); }
		$out.show().text(said);
	}
	$(document).on('change', '#dze-one-n, #dze-one-model', oneWillSpend);

	// THE LINE THAT EXPLAINED WHICH PHOTOGRAPH WOULD LEAD IS GONE. It said
	// three different things depending on what had been pasted, because the
	// server had three lanes; there is one now — every photograph in the
	// request is this product — so there is nothing left to explain and a
	// sentence explaining a rule that no longer exists is worse than none.
	// WHAT WILL BE SENT, WITHOUT SENDING IT. « Je ne comprends toujours pas
	// comment fonctionne la re-génération d'image. » The server builds the
	// very order the Generate button would send — same photographs, same
	// notes, same words — and stops one step before the provider: nothing is
	// generated, nothing is paid for.
	$(document).on('click', '#dze-one-preview', function () {
		var $b = $(this).prop('disabled', true);
		var $box = $('#dze-one-previewbox').show().html('<p class="description">' + esc(i18n.previewing) + '</p>');
		var req = oneImageRequest($('#dze-one-prompt').val() || '');
		req.dry = 1;
		// What WILL be sent: the framings already made and the model picked
		// go into the preview exactly as into the order.
		req.aware = 1;
		req.model = $('#dze-one-model').val() || '';
		$.post(cfg.ajaxUrl, req).done(function (r) {
			if (!r || !r.success) {
				$box.html('<p class="dze-cx-state is-ko">' + esc((r && r.data && r.data.message) || i18n.error) + '</p>');
				return;
			}
			var d = r.data || {};
			var html = '<p class="dze-one-prevhead">' + esc(i18n.previewImgs) + '</p><div class="dze-one-previmgs">';
			(d.images || []).forEach(function (im) {
				html += '<figure class="dze-one-previmg">' +
					(im.thumb
						? '<img class="dze-hzoom" src="' + esc(im.thumb) + '" data-full="' + esc(im.full || im.thumb) + '" alt="" />'
						: '<span class="dze-one-prevnone" aria-hidden="true"></span>') +
					'<figcaption><strong>' + esc(sprintf(i18n.imageN, im.n)) + '</strong><br />' + esc(im.what || '') + '</figcaption>' +
					'</figure>';
			});
			html += '</div>' +
				'<p class="dze-one-prevhead">' + esc(i18n.previewText) + '</p>' +
				'<pre class="dze-one-prevtext">' + esc(d.prompt || '') + '</pre>' +
				(cfg.notesUrl
					? '<p class="description"><a href="' + esc(cfg.notesUrl) + '" target="_blank" rel="noopener">' + esc(i18n.previewEdit) + ' &#8599;</a></p>'
					: '');
			$box.html(html);
		}).fail(function (x) {
			$box.html('<p class="dze-cx-state is-ko">' + esc(reason(x)) + '</p>');
		}).always(function () { $b.prop('disabled', false); });
	});
	// A new recipe, a new background or a new note is a new order: what was
	// previewed for the last one is taken away rather than left to mislead.
	function oneClearPreview() { $('#dze-one-previewbox').hide().empty(); }
	$(document).on('click change', '.dze-one-recipe, .dze-one-bg, #dze-one-note, #dze-one-prompt', oneClearPreview);

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
		one.srcIds = [];
		$('#dze-one-elsewrap').show();
		$('#dze-one-replacewrap').hide();
		oneSrcSaid();
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
			// ONLY WHAT WAS HANDED IN, when the box says so — and only while the
			// tile that holds that box is the one picked.
			only_pasted: ($('.dze-one-srcnew').hasClass('is-sel') && $('#dze-one-onlypasted').is(':checked')) ? 1 : 0,
			src_ids: (one.srcIds || []).slice(), recipe: $('#dze-one-recipe').val() || '',
			bg: $('#dze-one-bg').val() || 0,
			// THE NOTE TRAVELS. The box was on screen, said « sent with the
			// images this run makes », and was never posted.
			note: String($('#dze-one-note').val() || ''),
			prompt: undefined === prompt ? ($('#dze-one-prompt').val() || '') : prompt
		};
	}

	$(document).on('click', '#dze-one-gen', function () {
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-one-state').removeClass('is-ko').text(i18n.generating);
		var prompt = $('#dze-one-prompt').val() || '';

		if (one.mode === 'image') {
			// ORDERED, NOT AWAITED. Each picture is a brick under the gallery
			// from the moment it is ordered; the popup closes and the page
			// shows them arrive, one after another. Waiting here for the
			// picture held a request open past the proxy's 60 seconds — the
			// « HTTP 504 error » — and the strip it filled was decided in one
			// batch that could throw away what the bulk screen had made.
			var want = Math.max(1, parseInt($('#dze-one-n').val(), 10) || 1);
			var price = chosenPrice();
			var order = oneImageRequest(prompt);
			order.async = 1;
			// The framings already made, in words, travel with the order.
			order.aware = 1;
			order.model = $('#dze-one-model').val() || '';
			for (var k = 0; k < want; k++) { wallOrder($.extend(true, {}, order), price ? price.model : ''); }
			$b.prop('disabled', false);
			$st.text('');
			$('#dze-one').removeClass('is-open');
			wallSay(sprintf(want > 1 ? (i18n.orderedN || '%s') : (i18n.ordered || ''), want));
			var $w = $('#dze-bricks');
			if ($w.length && $w[0].scrollIntoView) { $w[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); }
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
								sceneSelect(sceneOf(tpls[0].i)).replace('dze-tpl-scene', 'dze-var-scene') + '</label>'
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
		// The background follows the prompt here too, or this bar is the one
		// screen left applying somebody else's scene.
		$('.dze-var-scene').val(String(sceneOf($(this).val())));
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
				// The attempt it replaces leaves the product's waiting list: kept
				// there, it came back on the next visit.
				var dzeOld = vars.made[varGroup($row)];
				if (dzeOld && dzeOld !== r.data.url) {
					$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: [ String(dzeOld) ] });
				}
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
			// Image 1 — the first one CLICKED, not the first in the strip.
			var $img = $('.dze-one-srcpick[data-id="' + one.srcId + '"] img').first();
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
							.attr('title', i18n.shotRedo).text('↻'),
						// AND THE SAME CROSS. This was the third screen where a
						// bad attempt could only be unticked and left sitting in
						// the product's waiting list.
						$('<span class="dze-cb-shotdrop" role="button" tabindex="-1"></span>')
							.attr('title', i18n.shotDrop || '').html('&times;')
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
	// A NOTE IS FOR THE RUN IN FRONT OF YOU. It used to be saved on the product
	// the moment you left the box and sent with every image made for it
	// afterwards — so a correction typed once ("ne mets pas de ruban sur le
	// tshirt !") went out for ever, invisibly. It travels with the request
	// now; nothing is stored. The two boxes are still one line about one
	// product, so what is typed in either shows in both.
	$(document).on('change blur keyup', '#dze-one-note, #dze-cx-note', function () {
		var note = $(this).val() || '';
		if (note === (cfg.note || '')) { return; }
		cfg.note = note;
		$('#dze-one-note, #dze-cx-note').not(this).val(note);
	});
	// What the box holds when the request is built, read off the page rather
	// than from anything remembered.
	function runNote() {
		var $b = $('#dze-cx-note');
		if (!$b.length) { $b = $('#dze-one-note'); }
		return ($b.val() || '').toString();
	}
	$(document).on('change', '#dze-one-target', function () {
		$('#dze-one-oldwrap').toggle('main' === $(this).val());
		oneSrcSaid();
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
	// One attempt thrown away: off the strip and out of the waiting list.
	$(document).on('click', '.dze-one-try .dze-cb-shotdrop', function (e) {
		e.stopPropagation();
		var url = $(this).closest('.dze-one-try').data('url');
		one.tries = (one.tries || []).filter(function (u) { return u !== url; });
		delete one.keep[url];
		$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: [ url ] });
		oneDrawTries();
	});
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
		// OPENED FOR A REASON, so the reason answers for the page. "La page
		// s'est rechargée. Ça ne doit pas arriver." A reload is the crudest
		// possible answer to "did that work?" — it throws away a list of nine
		// hundred rows, the sort, the page and the scroll, to ask a question
		// about ONE of them. The popup closes itself and the screen that
		// opened it is told; what it does with its own row is its business.
		if (OPENED_FOR) {
			var pid = PID, why = OPENED_FOR;
			window.setTimeout(function () {
				$('#dze-cx-modal').removeClass('is-open');
				$(document).trigger('dze:applied', [ { id: pid, n: n, want: why } ]);
			}, 600);
			return;
		}
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
		// A screen that opened this popup keeps its own page: see pageWritten().
		if (OPENED_FOR) { return; }
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
				if (r.data.thumb_html) {
					$('#postimagediv .inside').html(r.data.thumb_html);
					// Its ✦ went with the old content: it comes back.
					plantImageButton('#postimagediv', 'main', i18n.oneMain);
				}
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
							action: 'dze_content_logged', nonce: cfg.nonce, post: PID, unqueue: 1
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
							action: 'dze_content_logged', nonce: cfg.nonce, post: PID, unqueue: 1
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
		plantImageButton('#postimagediv', 'main', i18n.oneMain);
		plantImageButton('#woocommerce-product-images', 'gallery', i18n.oneGallery);
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
	// One ✦ per image box, planted once: the box can be redrawn under it
	// (refreshBoxes()), and the button must come back with its content.
	function plantImageButton(sel, scope, label) {
		var $box = $(sel + ' > .inside');
		if (!$box.length || $box.find('.dze-one-btn[data-scope="' + scope + '"]').length) { return; }
		$box.prepend('<p class="dze-one-plant"><button type="button" class="button button-small dze-one-btn" ' +
			'data-mode="image" data-scope="' + scope + '">✦ ' + esc(label) + '</button></p>');
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

	// =====================================================================
	// THE WALL: every picture made for this product, laid on its page
	// =====================================================================
	//
	// « Chaque image générée doit être comme une nouvelle brique posée sur la
	// page produit. » A picture used to live in the popup's strip, decided in a
	// batch by one Apply button whose « Cancel » threw away everything waiting
	// on the product — the bulk screen's work included — and a strip the popup
	// kept in memory brought back what had been thrown away the next time it
	// opened (« le bouton supprimer […] ne les supprime pas »). Each picture is
	// now a brick under the product's gallery, from the second it is ordered
	// to the moment it is decided: ＋ files it in the gallery, ★ makes it the
	// main image, ✕ throws it away — each at once, one picture at a time, read
	// from the server and never from a copy.
	//
	// ONE PICTURE AT A TIME. The orders queue here and the next one leaves
	// when the last is done, because the last one's framing, in words, is what
	// tells the next not to repeat it (made_lines()) — three pictures ordered
	// together were three times the same three-quarter view.
	var wall = { shots: [], maps: { targets: {}, recipes: {}, models: {}, views: {} }, texts: {}, jobs: {}, queue: [], failed: [], busy: 0, added: 0, tick: null };

	function wallBuild() {
		if ($('#dze-bricks').length) { return true; }
		var $box = $('#woocommerce-product-images > .inside');
		if (!$box.length || !PID) { return false; }
		var $w = $('<div class="dze-bricks" id="dze-bricks" style="display:none;"></div>').append(
			$('<p class="dze-bricks-h"></p>').append(
				$('<strong></strong>').text(i18n.brickWall || ''), ' ',
				$('<span class="dze-bricks-n"></span>')
			),
			$('<div class="dze-bricks-grid dze-zoomgroup"></div>'),
			$('<p class="description dze-bricks-legend"></p>').text(i18n.brickLegend || ''),
			$('<p class="dze-bricks-say" role="status"></p>')
		);
		var $after = $box.find('#product_images_container');
		if ($after.length) { $after.after($w); } else { $box.append($w); }
		return true;
	}

	function shortModel(label) { return String(label || '').replace(/\s*\([^)]*\)\s*$/, ''); }
	function modelLabel(key) {
		var hit = '';
		(cfg.imageModels || []).forEach(function (m) { if (m.key === key) { hit = m.model; } });
		return hit || key || '';
	}

	// What the server holds — the only truth there is about what waits.
	function wallFrom(cur) {
		var p = (cur && cur.pending) || {};
		wall.shots = (p.shots || []).slice();
		wall.maps = {
			targets: $.extend({}, p.targets || {}), recipes: $.extend({}, p.recipes || {}),
			models: $.extend({}, p.models || {}), views: $.extend({}, p.views || {})
		};
		wall.texts = p.texts || {};
		((cur && cur.jobs) || []).forEach(function (j) {
			if (wall.jobs[j.id]) { return; }
			wall.jobs[j.id] = { model: j.model, key: j.key, t0: Date.now() - (parseInt(j.secs, 10) || 0) * 1000 };
			wallPoll(j.id, 0);
		});
	}

	function wallLoad() {
		if (!wallBuild()) { return $.Deferred().resolve(); }
		return $.post(cfg.ajaxUrl, { action: 'dze_content_current', nonce: cfg.nonce, post: PID })
			.then(function (r) {
				if (!r || !r.success) { return; }
				res.current = r.data;
				wallFrom(r.data);
				wallDraw();
				wallNext();
			});
	}

	function wallSay(msg, bad) {
		$('#dze-bricks .dze-bricks-say').toggleClass('is-ko', !!bad).text(msg || '');
	}

	function wallSecs(t0) { return Math.max(0, Math.round((Date.now() - t0) / 1000)); }

	function wallDraw() {
		var $w = $('#dze-bricks');
		if (!$w.length) { return; }
		var $g = $w.find('.dze-bricks-grid').empty();
		wall.shots.forEach(function (u) {
			var view = wall.maps.views[u] || '';
			var forMain = 'main' === (wall.maps.targets[u] || '');
			$g.append($('<div class="dze-brick"></div>').attr('data-url', u)
				.attr('title', view ? sprintf(i18n.brickView || '%s', view) : '')
				.append(
					$('<img alt="" />').attr('src', u).attr('data-full', u),
					forMain ? $('<span class="dze-brick-badge">★</span>').attr('title', i18n.brickForMain || '') : null,
					$('<span class="dze-brick-cap"></span>').text(shortModel(modelLabel(wall.maps.models[u] || ''))),
					$('<span class="dze-brick-acts"></span>').append(
						$('<button type="button" class="dze-brick-add">＋</button>').attr({ title: i18n.brickAdd, 'aria-label': i18n.brickAdd }),
						$('<button type="button" class="dze-brick-main">★</button>').attr({ title: i18n.brickMain, 'aria-label': i18n.brickMain }),
						$('<button type="button" class="dze-brick-throw">✕</button>').attr({ title: i18n.brickThrow, 'aria-label': i18n.brickThrow })
					)
				));
		});
		Object.keys(wall.jobs).forEach(function (id) {
			var j = wall.jobs[id];
			$g.append($('<div class="dze-brick is-making"></div>').attr('data-job', id).append(
				$('<span class="spinner is-active"></span>'),
				$('<span class="dze-brick-state"></span>').attr('data-t0', j.t0).text(sprintf(i18n.brickMaking || '%s', wallSecs(j.t0))),
				$('<span class="dze-brick-cap"></span>').text(shortModel(j.model || modelLabel(j.key)))
			));
		});
		if (wall.busy) {
			$g.append($('<div class="dze-brick is-making"></div>').append(
				$('<span class="spinner is-active"></span>'),
				$('<span class="dze-brick-state"></span>').text(i18n.brickOrdering || ''),
				$('<span class="dze-brick-cap"></span>').text(shortModel(wall.busy.model || ''))
			));
		}
		wall.queue.forEach(function (o) {
			$g.append($('<div class="dze-brick is-queued"></div>').append(
				$('<span class="dze-brick-state"></span>').text(i18n.brickQueued || ''),
				$('<span class="dze-brick-cap"></span>').text(shortModel(o.model || ''))
			));
		});
		wall.failed.forEach(function (f, i) {
			$g.append($('<div class="dze-brick is-failed"></div>').attr('data-fail', i).attr('title', f.msg || '').append(
				$('<span class="dze-brick-state"></span>').text(sprintf(i18n.brickFailed || '%s', f.msg || i18n.error)),
				$('<span class="dze-brick-cap"></span>').text(shortModel(f.model || '')),
				$('<span class="dze-brick-acts"></span>').append(
					$('<button type="button" class="dze-brick-dismiss">✕</button>').attr({ title: i18n.brickDismiss, 'aria-label': i18n.brickDismiss })
				)
			));
		});
		var n = wall.shots.length + Object.keys(wall.jobs).length + wall.queue.length + (wall.busy ? 1 : 0);
		$w.find('.dze-bricks-n').text(n ? '(' + n + ')' : '');
		$w.find('.dze-bricks-legend').toggle(wall.shots.length > 0);
		$w.toggle(n + wall.failed.length > 0 || '' !== $w.find('.dze-bricks-say').text());
		// The seconds of the pictures being made count on screen, without a
		// request: the server is only asked every few seconds.
		if (Object.keys(wall.jobs).length && !wall.tick) {
			wall.tick = setInterval(function () {
				$('#dze-bricks .is-making .dze-brick-state[data-t0]').each(function () {
					$(this).text(sprintf(i18n.brickMaking || '%s', wallSecs(parseInt($(this).attr('data-t0'), 10) || Date.now())));
				});
				if (!Object.keys(wall.jobs).length) { clearInterval(wall.tick); wall.tick = null; }
			}, 1000);
		}
	}

	// An order: kept in line until the picture before it is done.
	function wallOrder(req, model) {
		wall.queue.push({ req: req, model: model });
		wallBuild();
		wallSay('');
		wallDraw();
		wallNext();
	}

	function wallNext() {
		if (wall.busy || Object.keys(wall.jobs).length || !wall.queue.length) { return; }
		var o = wall.queue.shift();
		wall.busy = o;
		wallDraw();
		$.post(cfg.ajaxUrl, o.req)
			.done(function (r) {
				wall.busy = 0;
				var d = (r && r.data) || {};
				if (!r || !r.success || !d.job) {
					wall.failed.push({ model: o.model, msg: d.message || i18n.error });
					wallDraw();
					wallNext();
					return;
				}
				wall.jobs[d.job] = { model: d.model || o.model, key: d.key || '', t0: Date.now() };
				drawSpend(d.spend);
				wallDraw();
				wallPoll(d.job, 0);
			})
			.fail(function (x) {
				wall.busy = 0;
				// The order may have reached fal before the answer was lost: the
				// server files a job before it answers, so the wall is read again,
				// and only an order that left no job behind is said to have failed.
				var before = Object.keys(wall.jobs).length;
				wallLoad().always(function () {
					if (Object.keys(wall.jobs).length > before) { return; }
					wall.failed.push({ model: o.model, msg: reason(x) });
					wallDraw();
					wallNext();
				});
			});
	}

	// Asking after one picture, every few seconds, in calls that last a
	// moment whatever fal is doing.
	function wallPoll(id, misses) {
		setTimeout(function () {
			if (!wall.jobs[id]) { return; }
			$.post(cfg.ajaxUrl, { action: 'dze_content_job', nonce: cfg.nonce, post: PID, job: id })
				.done(function (r) {
					var d = (r && r.data) || {};
					if (r && r.success && d.running) { wallPoll(id, 0); return; }
					var was = wall.jobs[id] || {};
					delete wall.jobs[id];
					if (r && r.success && d.done && d.url) {
						if (wall.shots.indexOf(d.url) < 0) { wall.shots.push(d.url); }
						wall.maps.targets[d.url] = d.target || 'gallery';
						wall.maps.recipes[d.url] = d.recipe || '';
						wall.maps.models[d.url] = d.key || '';
						wall.maps.views[d.url] = d.view || '';
						drawSpend(d.spend);
						wallSay(i18n.brickArrived || '');
						wallDraw();
						wallNext();
						return;
					}
					if (d.gone && !d.error) {
						// Collected by another tab, or already filed: what the server
						// holds says where it is.
						wallLoad();
						return;
					}
					wall.failed.push({ model: was.model, msg: d.message || i18n.error });
					wallDraw();
					wallNext();
				})
				.fail(function (x) {
					// A look that failed is not an answer: look again — but not
					// for ever, and say what the last look got.
					if (misses < 20) { wallPoll(id, misses + 1); return; }
					var was = wall.jobs[id] || {};
					delete wall.jobs[id];
					wall.failed.push({ model: was.model, msg: sprintf(i18n.brickLost || '%s', reason(x)) });
					wallDraw();
					wallNext();
				});
		}, misses ? 5000 : 3000);
	}

	function wallGone(url) {
		wall.shots = wall.shots.filter(function (u) { return u !== url; });
		[ 'targets', 'recipes', 'models', 'views' ].forEach(function (k) { delete wall.maps[k][url]; });
		// The toolbox reads the same waiting list: its copy follows.
		if (res.current && res.current.pending && res.current.pending.shots) {
			res.current.pending.shots = res.current.pending.shots.filter(function (u) { return u !== url; });
		}
		wallDraw();
	}

	function wallEmpty() {
		return !wall.shots.length && !Object.keys(wall.jobs).length && !wall.queue.length && !wall.busy &&
			!Object.keys(wall.texts || {}).length;
	}

	// ＋ and ★: the picture is filed on the product, now, alone.
	$(document).on('click', '.dze-brick-add, .dze-brick-main', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $card = $(this).closest('.dze-brick');
		var url = String($card.data('url') || '');
		if (!url || $card.hasClass('is-busy')) { return; }
		var asMain = $(this).hasClass('dze-brick-main');
		$card.addClass('is-busy');
		wallSay(i18n.applying || '');
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_image_attach', nonce: cfg.nonce, post: PID,
			// The prompt that made THIS picture names its file.
			recipe: wall.maps.recipes[url] || cfg.mainRecipe || '',
			items: [ { url: url, target: asMain ? 'main' : 'gallery' } ],
			// The main image it replaces goes to the front of the gallery: an
			// unwanted one is removed there, like any other photograph.
			keep_old: 1,
			replace: 0
		}).done(function (r) {
			if (!r || !r.success) {
				$card.removeClass('is-busy');
				wallSay((r && r.data && r.data.message) || i18n.error, true);
				return;
			}
			wall.added++;
			wallGone(url);
			refreshBoxes();
			wallSay(asMain ? i18n.brickMainDone : i18n.brickAdded);
			// Placed, it recorded itself under Done (attach_file()): the screen
			// claims no count of its own. Once nothing waits on the product any
			// more, it also leaves the bulk list.
			if (wallEmpty()) {
				$.post(cfg.ajaxUrl, { action: 'dze_content_logged', nonce: cfg.nonce, post: PID, unqueue: 1 });
			}
		}).fail(function (x) {
			$card.removeClass('is-busy');
			wallSay(reason(x), true);
		});
	});

	// ✕: thrown away for good. It was never on the shop; it leaves the
	// product's waiting list on the server, so no screen brings it back.
	$(document).on('click', '.dze-brick-throw', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $card = $(this).closest('.dze-brick');
		var url = String($card.data('url') || '');
		// NEVER AN EMPTY LIST: the server reads « no photograph named » as
		// « throw away everything this product holds », bulk work included.
		if (!url || $card.hasClass('is-busy')) { return; }
		$card.addClass('is-busy');
		$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: [ url ] })
			.done(function (r) {
				if (!r || !r.success) {
					$card.removeClass('is-busy');
					wallSay((r && r.data && r.data.message) || i18n.error, true);
					return;
				}
				wallGone(url);
				wallSay(i18n.brickThrown || '');
				if (wall.added && wallEmpty()) {
					$.post(cfg.ajaxUrl, { action: 'dze_content_logged', nonce: cfg.nonce, post: PID, unqueue: 1 });
				}
			})
			.fail(function (x) {
				$card.removeClass('is-busy');
				wallSay(reason(x), true);
			});
	});

	// A failure said on its brick is dismissed there.
	$(document).on('click', '.dze-brick-dismiss', function (e) {
		e.preventDefault();
		var i = parseInt($(this).closest('.dze-brick').attr('data-fail'), 10);
		if (!isNaN(i)) { wall.failed.splice(i, 1); }
		wallDraw();
	});

	$(function () { wallLoad(); });

	// POD hands its result over to this strip.
	window.dzeContentAddToGallery = function (url) {
		build();
		res.shots.push(url);
		drawShots();
	};
	window.dzeContentOpen = function () { open(); };

}(jQuery));
