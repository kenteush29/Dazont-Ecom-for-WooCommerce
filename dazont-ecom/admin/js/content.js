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
	function tplRow(sel) {
		var opts = cfg.templates.map(function (t, i) {
			return '<option value="' + i + '"' + (String(sel) === String(i) ? ' selected' : '') + '>' +
				esc(t.name) + (t.valid ? '' : ' — ' + esc(i18n.notValid)) + '</option>';
		}).join('');
		var cur = cfg.templates[parseInt(sel, 10)] || cfg.templates[0] || {};
		return '<span class="dze-tplrow"><select class="dze-cx-tpl">' + opts + '</select>' +
			promptBtn(cur.id) +
			'<button type="button" class="button button-small dze-cx-tpladd" title="' + esc(i18n.addPrompt) + '">+</button>' +
			'<button type="button" class="button button-small dze-cx-tpldel" title="' + esc(i18n.delPrompt) + '">−</button></span>';
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
	$(document).on('click', '.dze-cx-tpladd', function () {
		$('#dze-cx-tplrows').append(tplRow(firstFreeTpl()));
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
		// The peek button follows the prompt the row now points at.
		$('#dze-cx-tplrows .dze-tplrow').each(function () {
			var t = cfg.templates[parseInt($(this).find('.dze-cx-tpl').val(), 10)] || {};
			$(this).find('.dze-prompt-peek').attr('data-prompt', 'content_' + (t.id || ''));
		});
		remember();
	});

	function remember() {
		var m = mem();
		m.auto = {
			fields: $('.dze-cx-f:checked').map(function () { return $(this).val(); }).get(),
			price: $('#dze-cx-doprice').is(':checked') ? 1 : 0,
			img: $('#dze-cx-doimg').is(':checked') ? 1 : 0,
			tpls: tplUsed(),
			imgn: parseInt($('#dze-cx-imgn').val(), 10) || 1
		};
		if ($('#dze-cx-scene').length) { m.scene = parseInt($('#dze-cx-scene').val(), 10); }
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
			'</h3>' +
			'<div class="dze-sec-body"' + (open ? '' : ' style="display:none;"') + '>' + body + '</div>' +
		'</section>';
	}
	function toggleSec($sec, on) {
		$sec.toggleClass('is-open', on);
		$sec.find('> .dze-sec-head').attr('aria-expanded', on ? 'true' : 'false')
			.find('.dze-sec-caret').text(on ? '▾' : '▸');
		$sec.find('> .dze-sec-body').toggle(on);
		var m = mem();
		m.sec = m.sec || {};
		m.sec[$sec.data('sec')] = on ? 1 : 0;
		saveMem(m);
	}
	$(document).on('click', '.dze-sec-head', function () {
		var $sec = $(this).closest('.dze-sec');
		toggleSec($sec, !$sec.hasClass('is-open'));
	});
	$(document).on('keydown', '.dze-sec-head', function (e) {
		if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $(this).trigger('click'); }
	});

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
		var scenes = cfg.scenes || [];
		var sceneCur = (m.scene !== undefined && m.scene !== null) ? parseInt(m.scene, 10) : (cfg.sceneDef === undefined ? -1 : cfg.sceneDef);
		if (sceneCur >= scenes.length) { sceneCur = scenes.length ? 0 : -1; }

		var checks = Object.keys(cfg.fields).map(function (fid) {
			var on = au.fields ? au.fields.indexOf(fid) >= 0 : true;
			return '<span class="dze-cb-checkline"><label class="dze-cb-check"><input type="checkbox" class="dze-cx-f" value="' + fid + '"' + (on ? ' checked' : '') + ' />' +
				'<span>' + esc(cfg.fields[fid]) + '</span></label>' + promptBtn(fid) + '</span>';
		}).join('');

		var sceneSel = scenes.length
			? '<label><span>' + esc(i18n.scene) + '</span><select id="dze-cx-scene">' +
				'<option value="-1"' + (sceneCur < 0 ? ' selected' : '') + '>' + esc(i18n.noScene) + '</option>' +
				scenes.map(function (s, i) {
					return '<option value="' + i + '"' + (sceneCur === i ? ' selected' : '') + '>' + esc(s.name) + '</option>';
				}).join('') + '</select></label>'
			: '';

		// The surfaces this shop shoots on. The first one is the default: a
		// catalogue is consistent because everything lands on the same plate.
		var bgList = cfg.backdrops || [];
		var bgOpts = bgList.map(function (b, i) {
			return '<option value="' + b.id + '"' + (i === 0 ? ' selected' : '') + '>' + esc(b.name) + '</option>';
		}).join('') + '<option value="0"' + (bgList.length ? '' : ' selected') + '>' + esc(i18n.qmBgNone) + '</option>';

		var blockers = (cfg.blockers && cfg.blockers.length)
			? '<div class="dze-cx-blocked"><strong>' + esc(i18n.blocked) + '</strong><ul>' +
				cfg.blockers.map(function (b) {
					return '<li>' + esc(b.text) + ' <a href="' + esc(b.url) + '" target="_blank" rel="noopener">' + esc(b.label) + '</a></li>';
				}).join('') + '</ul></div>'
			: '';

		// The fast lane, kept whole but now living inside the Images section.
		var qmLane =
			'<div class="dze-qm" id="dze-qm">' +
				'<div class="dze-qm-head">' +
					'<h4>' + esc(i18n.qmTitle) + '</h4>' +
					'<button type="button" class="dze-prompt-peek" data-prompt="content_' + esc(cfg.mainRecipe || '') + '" title="' + esc(i18n.promptTip) + '">&#9998;</button>' +
					'<span class="description">' + esc(i18n.qmHelp) + '</span>' +
				'</div>' +
				// Three ways in, in the order they are actually used: paste the
				// image, paste its address, or use the product's own photographs.
				'<div class="dze-qm-drop" id="dze-qm-drop" tabindex="0">' +
					'<span class="dze-qm-dropmsg">' + esc(i18n.qmPaste) + '</span>' +
					'<img id="dze-qm-src" alt="" style="display:none;" />' +
					'<button type="button" class="dze-qm-srcdel" id="dze-qm-srcdel" style="display:none;" title="' + esc(i18n.qmClear) + '">&times;</button>' +
				'</div>' +
				'<p class="dze-qm-bar">' +
					'<label class="dze-qm-bglabel"><span>' + esc(i18n.qmBg) + '</span>' +
						'<select id="dze-qm-bg">' + bgOpts + '</select></label>' +
					'<button type="button" class="button button-small dze-bg-add" data-for="dze-qm-bg" title="' + esc(i18n.bgAdd) + '">+</button>' +
				'</p>' +
				'<p class="dze-qm-bar">' +
					'<input type="text" id="dze-qm-note" placeholder="' + esc(i18n.qmNote) + '" />' +
					'<button type="button" class="button button-primary" id="dze-qm-run">' + esc(i18n.qmRun) + '</button>' +
					'<span class="dze-cx-state" id="dze-qm-state"></span>' +
				'</p>' +
				'<div class="dze-qm-out" id="dze-qm-out" style="display:none;">' +
					'<div class="dze-qm-pair">' +
						'<figure><figcaption>' + esc(i18n.qmNow) + '</figcaption><img id="dze-qm-old" class="dze-hzoom" alt="" /></figure>' +
						'<figure><figcaption>' + esc(i18n.qmNew) + '</figcaption><img id="dze-qm-shot" class="dze-hzoom" alt="" /></figure>' +
					'</div>' +
					'<p>' +
						'<button type="button" class="button button-primary" id="dze-qm-use">' + esc(i18n.qmUse) + '</button> ' +
						'<button type="button" class="button" id="dze-qm-again">' + esc(i18n.qmAgain) + '</button>' +
						'<span class="dze-cx-state" id="dze-qm-usestate"></span>' +
					'</p>' +
				'</div>' +
			'</div>';

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
						qmLane +
						(cfg.templates.length ?
						'<div class="dze-cb-sub">' +
							'<label class="dze-cb-check"><input type="checkbox" id="dze-cx-doimg"' + (au.img ? ' checked' : '') + ' />' +
							'<span>' + esc(i18n.genImgOpt) + '</span></label>' +
							'<div class="dze-cb-opts">' +
								'<label><span>' + esc(i18n.template) + '</span><span class="dze-tplrows" id="dze-cx-tplrows"></span></label>' +
								sceneSel +
								'<label><span>' + esc(i18n.attempts) + '</span><select id="dze-cx-imgn">' +
									[1, 2, 3, 4].map(function (n) { return '<option value="' + n + '"' + ((au.imgn || 1) === n ? ' selected' : '') + '>× ' + n + '</option>'; }).join('') +
								'</select></label>' +
							'</div>' +
						'</div>' : '')
					) +

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

		var saved = Array.isArray(au.tpls) && au.tpls.length ? au.tpls : [ 0 ];
		saved.forEach(function (v) { $('#dze-cx-tplrows').append(tplRow(v)); });
		syncTplRows();
		$(document).on('change', '.dze-cx-f, #dze-cx-doprice, #dze-cx-doimg, #dze-cx-imgn, #dze-cx-scene', remember);
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
		var imgs = (res.current && res.current.images) || [];
		var $slot = $('#dze-cx-nowshots');
		if (!imgs.length) { $slot.empty(); return; }
		// The main image apart from the gallery, on the same line: they are the
		// same kind of thing and they do not have the same job. Each says its
		// size and its shape, because that is what you look at before deciding
		// anything about a catalogue.
		function tile(im) {
			return $('<span class="dze-cb-nowshot"></span>')
				.toggleClass('is-main', !!im.main)
				.attr('data-id', im.id)
				.append(
					$('<img class="dze-hzoom" />').attr('src', im.thumb).attr('data-full', im.full || im.thumb).attr('alt', ''),
					$('<span class="dze-nowdim"></span>').text(
						(im.w && im.h) ? (im.w + '×' + im.h + (im.ratio ? ' · ' + im.ratio : '')) : ''
					),
					$('<span class="dze-nowtick">✓</span>')
				);
		}
		var main = imgs.filter(function (im) { return im.main; });
		var rest = imgs.filter(function (im) { return !im.main; });
		var $wrap = $('<div class="dze-nowblock"></div>');
		var $mainCol = $('<div class="dze-nowcol dze-nowcol-main"></div>').append(
			$('<span class="dze-nowcap"></span>').text(i18n.nowMain).append(
				$('<button type="button" class="button button-small dze-now-ai"></button>').text('✦ ' + i18n.nowAi)
			)
		);
		var $grid = $('<div class="dze-cb-nowgrid dze-zoomgroup"></div>');
		main.forEach(function (im) { $grid.append(tile(im)); });
		$mainCol.append($grid);
		var $restCol = $('<div class="dze-nowcol"></div>').append($('<span class="dze-nowcap"></span>').text(i18n.nowGallery));
		var $grid2 = $('<div class="dze-cb-nowgrid dze-zoomgroup"></div>');
		rest.forEach(function (im) { $grid2.append(tile(im)); });
		$restCol.append($grid2);
		$wrap.append($mainCol);
		if (rest.length) { $wrap.append($restCol); }
		// One button for every photograph: it turns the tiles into checkboxes
		// and brings out the shape to reframe them to.
		var ratios = (cfg.ratios || ['1:1']).map(function (r) {
			return '<option value="' + esc(r) + '">' + esc(r) + '</option>';
		}).join('');
		var $bar = $('<p class="dze-nowbar"></p>').append(
			'<button type="button" class="button button-small dze-rf-start">⤢ ' + esc(i18n.rfStart) + '</button>' +
			'<span class="dze-rf-tools" style="display:none;">' +
				'<button type="button" class="button-link dze-rf-all">' + esc(i18n.rfAll) + '</button>' +
				'<label><span>' + esc(i18n.rfShape) + '</span><select class="dze-rf-ratio">' + ratios + '</select></label>' +
				'<label><span>' + esc(i18n.rfHow) + '</span><select class="dze-rf-mode">' +
					'<option value="pad">' + esc(i18n.rfPad) + '</option>' +
					'<option value="crop">' + esc(i18n.rfCrop) + '</option>' +
				'</select></label>' +
				'<button type="button" class="button button-small button-primary dze-rf-run">' + esc(i18n.rfRun) + '</button>' +
				'<button type="button" class="button-link dze-rf-cancel">' + esc(i18n.cancel) + '</button>' +
				'<span class="dze-rf-state"></span>' +
			'</span>'
		);
		$slot.empty()
			.append('<span class="dze-cb-nowlabel">' + esc(i18n.nowImages) + '</span>')
			.append($wrap).append($bar).append('<div class="dze-rf-out"></div>');
	}

	// ---- Reframing: pick the photographs, pick the shape, look, accept ----
	$(document).on('click', '.dze-rf-start', function () {
		$('#dze-cx-nowshots').addClass('is-picking').find('.dze-rf-tools').show();
		$(this).hide();
	});
	$(document).on('click', '.dze-rf-cancel', function () {
		$('#dze-cx-nowshots').removeClass('is-picking')
			.find('.dze-cb-nowshot').removeClass('is-picked').end()
			.find('.dze-rf-tools').hide().end()
			.find('.dze-rf-start').show().end()
			.find('.dze-rf-out').empty();
	});
	$(document).on('click', '.dze-rf-all', function () {
		var $all = $('#dze-cx-nowshots .dze-cb-nowshot');
		var on = $all.filter('.is-picked').length !== $all.length;
		$all.toggleClass('is-picked', on);
	});
	$(document).on('click', '#dze-cx-nowshots.is-picking .dze-cb-nowshot', function () {
		$(this).toggleClass('is-picked');
	});
	function rfPicked() {
		return $('#dze-cx-nowshots .dze-cb-nowshot.is-picked').map(function () {
			return parseInt($(this).data('id'), 10);
		}).get().filter(Boolean);
	}
	$(document).on('click', '.dze-rf-run', function () {
		var ids = rfPicked();
		var $st = $('#dze-cx-nowshots .dze-rf-state').removeClass('is-ko');
		if (!ids.length) { $st.addClass('is-ko').text(i18n.rfNone); return; }
		var ratio = $('#dze-cx-nowshots .dze-rf-ratio').val();
		var mode = $('#dze-cx-nowshots .dze-rf-mode').val();
		var $b = $(this).prop('disabled', true);
		$st.text(i18n.working);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_reframe_preview', nonce: cfg.nonce,
			ids: ids, ratio: ratio, mode: mode
		}).done(function (r) {
			$b.prop('disabled', false);
			if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
			$st.text('');
			rfDrawResult(r.data);
		}).fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});
	// Before and after, side by side, and nothing is written until it is
	// accepted — the same bargain as every other generation in the plugin.
	function rfDrawResult(d) {
		var $out = $('#dze-cx-nowshots .dze-rf-out').empty();
		var $g = $('<div class="dze-rf-pairs"></div>');
		(d.items || []).forEach(function (it) {
			if (it.error) {
				$g.append($('<p class="dze-rf-err"></p>').text(it.error));
				return;
			}
			$g.append($('<div class="dze-rf-pair"></div>').attr('data-id', it.id).append(
				$('<figure></figure>').append(
					$('<img />').attr('src', it.before).attr('alt', ''),
					$('<figcaption></figcaption>').text(i18n.qmNow + ' · ' + (it.beforeD || ''))
				),
				$('<figure></figure>').append(
					$('<img />').attr('src', it.after).attr('alt', ''),
					$('<figcaption></figcaption>').text(i18n.qmNew + ' · ' + it.w + '×' + it.h + ' · ' + (it.afterD || ''))
				)
			));
		});
		$out.append($g).append(
			$('<p class="dze-nowbar"></p>').append(
				'<button type="button" class="button button-primary dze-rf-apply">' + esc(i18n.rfApply) + '</button>' +
				'<label class="dze-rf-drop"><input type="checkbox" class="dze-rf-dropold" /> ' + esc(i18n.rfDropOld) + '</label>' +
				'<button type="button" class="button-link dze-rf-cancel">' + esc(i18n.discard) + '</button>' +
				'<span class="dze-rf-state2"></span>'
			)
		).data('ratio', d.ratio).data('mode', d.mode);
	}
	$(document).on('click', '.dze-rf-apply', function () {
		var $out = $('#dze-cx-nowshots .dze-rf-out');
		var ids = $out.find('.dze-rf-pair').map(function () { return parseInt($(this).data('id'), 10); }).get();
		if (!ids.length) { return; }
		var $b = $(this).prop('disabled', true);
		var $st = $out.find('.dze-rf-state2').removeClass('is-ko').text(i18n.applying);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_reframe_apply', nonce: cfg.nonce, post: PID,
			ids: ids, ratio: $out.data('ratio'), mode: $out.data('mode'),
			drop_original: $out.find('.dze-rf-dropold').is(':checked') ? 1 : 0
		}).done(function (r) {
			$b.prop('disabled', false);
			if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
			$st.text(i18n.applied);
			res.current = null; // the product's photographs changed.
			loadCurrent().then(function () {
				drawCurrentImages();
				oneDrawSources();
			});
		}).fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});
	// The main image's own AI button, next to it rather than in a block of
	// its own: same popup as the featured-image box opens.
	$(document).on('click', '.dze-now-ai', function () {
		openOne('', 'image', 'main');
	});
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
		return v === 'main' ? i18n.toMain : (v === 'gallery_first' ? i18n.toGalleryFirst : i18n.toGallery);
	}
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
				more + '</div>' +
			'<div class="dze-cb-shotgrid dze-zoomgroup"></div><span class="dze-cb-shotstate"></span></div>');
		res.shots.forEach(function (url) {
			$wrap.find('.dze-cb-shotgrid').append(
				shotCard(url, dest[url] || 'gallery').find('.dze-cb-shot').toggleClass('is-sel', !dropped[url]).end()
			);
		});
		$slot.empty().append($wrap);
		$('#dze-cx-result').show();
	}
	$(document).on('click', '#dze-cx-shots .dze-cb-shot', function () { $(this).toggleClass('is-sel'); });
	// One click walks the three destinations. Only one image can be the main
	// one, so claiming it sends the previous claimant back to the gallery.
	$(document).on('click', '#dze-cx-shots .dze-cb-shotpos', function (e) {
		e.stopPropagation();
		var $in = $(this).closest('.dze-cb-shot').find('.dze-cb-shotdest');
		var order = [ 'gallery', 'gallery_first', 'main' ];
		var next = order[(order.indexOf($in.val()) + 1) % order.length];
		$in.val(next);
		$(this).text(destLabel(next));
		if ('main' !== next) { return; }
		var $me = $in;
		$('#dze-cx-shots .dze-cb-shotdest').not($me).each(function () {
			if ($(this).val() === 'main') {
				$(this).val('gallery');
				$(this).closest('.dze-cb-shot').find('.dze-cb-shotpos').text(destLabel('gallery'));
			}
		});
	});
	// A fresh attempt at THIS image, with the recipe that made it: the new one
	// takes its place in the strip instead of piling up next to it.
	$(document).on('click', '#dze-cx-shots .dze-cb-shotredo', function (e) {
		e.stopPropagation();
		var $btn = $(this).prop('disabled', true);
		var $card = $btn.closest('.dze-cb-shot');
		var url = $card.data('url');
		var tpl = res.shotTpl[url];
		if (tpl === undefined) { tpl = tplUsed()[0] || '0'; }
		var $st = $('#dze-cx-shots .dze-cb-shotstate').removeClass('is-ko').text(i18n.working);
		$card.addClass('is-busy');
		$.post(cfg.ajaxUrl, imageRequest(tpl))
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
	function imageRequest(tpl) {
		var data = { action: 'dze_content_image', nonce: cfg.nonce, post: PID, template: tpl, mode: 'defer', stash: 1 };
		var $sc = $('#dze-cx-scene');
		if ($sc.length) { data.scene = parseInt($sc.val(), 10); }
		return data;
	}
	function genImage(tpl) {
		return $.post(cfg.ajaxUrl, imageRequest(tpl))
			.then(function (r) {
				if (!r.success) { throw (r.data && r.data.message) || i18n.error; }
				res.shots.push(r.data.url);
				res.shotTpl[r.data.url] = tpl;
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
		var n = parseInt($('#dze-cx-imgn').val(), 10) || 1;
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
			var list = tplUsed();
			list.forEach(function (tpl, ti) {
				var name = (cfg.templates[parseInt(tpl, 10)] || {}).name || '';
				for (var k = 0; k < n; k++) {
					(function (attempt) {
						steps.push({
							label: n > 1
								? sprintf(i18n.stepImageN, name, attempt, n)
								: sprintf(i18n.stepImage, name),
							run: function () { return genImage(tpl); }
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
			$.post(cfg.ajaxUrl, { action: 'dze_content_image_attach', nonce: cfg.nonce, post: PID, items: items })
				.done(function (r) {
					if (r && r.success) {
						ok++;
						$('#dze-cx-shots .dze-cb-shot').removeClass('is-sel');
						res.shots = [];
						drawShots();
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
		res.open = {};
		if (Object.keys(res.texts).length) { drawDrawers(); }
		drawShots();
		loadCurrent().then(drawCurrentImages);
	}

	// ---- The fast lane ----
	// One call, one image, and the click after it puts the image in place. The
	// old main is not lost: attaching as "main" pushes it to the front of the
	// gallery, which is what the attach endpoint does for every image.
	var qmUrl = '';
	// An image pasted or dropped into the lane, held as a data URI until it is
	// sent. Nothing is uploaded to the media library on the way.
	var qmPaste = '';

	function qmShowSource(dataUri) {
		qmPaste = dataUri || '';
		$('#dze-qm-src').attr('src', qmPaste).toggle(!!qmPaste);
		$('#dze-qm-srcdel').toggle(!!qmPaste);
		$('#dze-qm-drop').toggleClass('has-img', !!qmPaste)
			.find('.dze-qm-dropmsg').text(qmPaste ? i18n.qmPasted : i18n.qmPaste);
	}
	function qmReadFile(file) {
		if (!file || !/^image\//.test(file.type)) { return; }
		var fr = new FileReader();
		fr.onload = function () { qmShowSource(String(fr.result)); };
		fr.readAsDataURL(file);
	}
	// A file dropped on the box. Ctrl+V is handled once for both popups,
	// further down, on the document.
	$(document).on('dragover', '#dze-qm-drop', function (e) {
		e.preventDefault();
		$(this).addClass('is-over');
	});
	$(document).on('dragleave drop', '#dze-qm-drop', function () { $(this).removeClass('is-over'); });
	$(document).on('drop', '#dze-qm-drop', function (e) {
		e.preventDefault();
		var dt = e.originalEvent && e.originalEvent.dataTransfer;
		if (dt && dt.files && dt.files.length) { qmReadFile(dt.files[0]); }
	});
	$(document).on('click', '#dze-qm-srcdel', function (e) {
		e.stopPropagation();
		qmShowSource('');
	});

	function qmRun() {
		var $b = $('#dze-qm-run').prop('disabled', true);
		var $st = $('#dze-qm-state').removeClass('is-ko').text(i18n.qmWorking);
		$('#dze-qm-usestate').text('');
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_quick_main', nonce: cfg.nonce, post: PID,
			paste: qmPaste,
			bg: $('#dze-qm-bg').val() || 0,
			note: $('#dze-qm-note').val() || ''
		})
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
				$st.text('');
				qmUrl = r.data.url;
				$('#dze-qm-shot').attr('src', qmUrl).attr('data-full', qmUrl);
				$('#dze-qm-old').attr('src', r.data.main || '').attr('data-full', r.data.main || '')
					.closest('figure').toggle(!!r.data.main);
				$('#dze-qm-out').show();
			})
			.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	}
	$(document).on('click', '#dze-qm-run, #dze-qm-again', qmRun);
	$(document).on('keydown', '#dze-qm-note', function (e) {
		if (e.key === 'Enter') { e.preventDefault(); qmRun(); }
	});

	$(document).on('click', '#dze-qm-use', function () {
		if (!qmUrl) { return; }
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-qm-usestate').removeClass('is-ko').text(i18n.applying);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_image_attach', nonce: cfg.nonce, post: PID,
			items: [ { url: qmUrl, target: 'main' } ]
		})
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
				$st.text(i18n.applied);
				$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: [ qmUrl ] });
				// The product page behind the popup still shows the old main.
				window.setTimeout(function () { window.location.reload(); }, 900);
			})
			.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});

	// =====================================================================
	// One block at a time, from the block itself
	// =====================================================================
	//
	// The big popup is for working through a whole product. Most of the time
	// the job is smaller than that: this description, that image. So every
	// block WordPress already shows carries its own button, and the button
	// opens a popup with that one function in it — read the instructions,
	// change them for one run if you want, write, compare, save.

	var one = { fid: '', mode: 'text', value: '', url: '' };

	function oneBuild() {
		if ($('#dze-one').length) { return; }
		$('body').append(
		'<div class="dze-cx-modal" id="dze-one"><div class="dze-cx-dialog dze-one-dialog">' +
			'<div class="dze-cx-head">' +
				'<h2 id="dze-one-title"></h2>' +
				'<button type="button" class="button dze-hub-close" style="margin-left:auto;">' + esc(i18n.close) + '</button>' +
			'</div>' +
			'<div class="dze-cx-body">' +
				'<details class="dze-one-instr" id="dze-one-instrwrap">' +
					'<summary>' + esc(i18n.oneInstr) + '</summary>' +
					'<p class="description">' + esc(i18n.oneInstrH) + '</p>' +
					'<textarea id="dze-one-prompt" rows="7" class="large-text code"></textarea>' +
					'<p><button type="button" class="button-link" id="dze-one-saveprompt">&#128190; ' + esc(i18n.oneSave) + '</button> ' +
						'<span class="description" id="dze-one-savestate"></span></p>' +
				'</details>' +
				'<div id="dze-one-body"></div>' +
				'<p class="dze-one-bar">' +
					'<button type="button" class="button button-primary" id="dze-one-gen"></button> ' +
					'<button type="button" class="button button-primary" id="dze-one-apply" style="display:none;"></button> ' +
					'<span class="dze-cx-state" id="dze-one-state"></span>' +
				'</p>' +
			'</div>' +
		'</div></div>');
		$(document).on('click', '#dze-one', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });
	}

	function openOne(fid, mode, scope) {
		oneBuild();
		one = { fid: fid, mode: mode || 'text', value: '', url: '', scope: scope || 'main' };
		var label = mode === 'image'
			? (one.scope === 'gallery' ? i18n.oneGallery : i18n.qmTitle)
			: (cfg.fields[fid] || fid);
		$('#dze-one-title').text(label);
		$('#dze-one-prompt').val(mode === 'image' ? (cfg.quickPrompt || '') : ((cfg.prompts && cfg.prompts[fid]) || ''));
		$('#dze-one-instrwrap').prop('open', false);
		$('#dze-one-savestate').text('');
		$('#dze-one-state').removeClass('is-ko').text('');
		$('#dze-one-apply').hide().text(i18n.oneApply);
		$('#dze-one-gen').text(mode === 'image'
			? (one.scope === 'gallery' ? i18n.imgRun : i18n.oneMain)
			: i18n.oneGen);
		$('#dze-one-body').html(mode === 'image' ? oneImageBody() : '');
		// One editor, moved to where the thing it edits is named.
		if (mode === 'image') { $('#dze-one-instrslot').append($('#dze-one-instrwrap')); }
		else { $('#dze-one-body').before($('#dze-one-instrwrap')); }
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
				'<div id="dze-one-instrslot"></div>' +
			'</div>' +

			'<div class="dze-step">' +
				'<p class="dze-step-q"><span class="dze-step-n">2</span>' + esc(i18n.stepFrom) + '</p>' +
				'<div class="dze-one-srcs" id="dze-one-srcs"></div>' +
				'<div id="dze-one-elsewrap" style="display:none;">' +
					'<div class="dze-qm-drop" id="dze-one-drop" tabindex="0">' +
						'<span class="dze-qm-dropmsg">' + esc(i18n.qmPaste) + '</span>' +
						'<img id="dze-one-src" alt="" style="display:none;" />' +
					'</div>' +
				'</div>' +
			'</div>' +

			'<div class="dze-step" id="dze-one-bgstep">' +
				'<p class="dze-step-q"><span class="dze-step-n">3</span>' + esc(i18n.stepBg) + '</p>' +
				'<div class="dze-one-bgs" id="dze-one-bgs"></div>' +
			'</div>' +

			'<div class="dze-qm-pair" id="dze-one-pair" style="display:none;">' +
				'<figure><figcaption>' + esc(i18n.qmNow) + '</figcaption><img id="dze-one-old" class="dze-hzoom" alt="" /></figure>' +
				'<figure><figcaption>' + esc(i18n.qmNew) + '</figcaption><img id="dze-one-new" class="dze-hzoom" alt="" /></figure>' +
			'</div>' +
			'<p class="dze-qm-bar" id="dze-one-dest" style="display:none;">' +
				'<label class="dze-qm-bglabel"><span>' + esc(i18n.imgWhere) + '</span>' +
					'<select id="dze-one-target">' +
						'<option value="main">' + esc(i18n.toMain) + '</option>' +
						'<option value="gallery_first">' + esc(i18n.toGalleryFirst) + '</option>' +
						'<option value="gallery">' + esc(i18n.toGallery) + '</option>' +
					'</select></label>' +
				'<label id="dze-one-replacewrap" style="display:none;"><input type="checkbox" id="dze-one-replace" /> ' + esc(i18n.imgReplace) + '</label>' +
			'</p>' +
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
		$('#dze-one-body').html('<div class="dze-cb-nowtext"><span class="dze-cb-nowlabel">' + esc(i18n.oneBefore) +
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
			$.post(cfg.ajaxUrl, {
				action: 'dze_content_quick_main', nonce: cfg.nonce, post: PID,
				paste: one.paste || '',
				src_id: one.srcId || 0, recipe: $('#dze-one-recipe').val() || '',
				bg: $('#dze-one-bg').val() || 0, prompt: prompt
			})
				.done(function (r) {
					$b.prop('disabled', false);
					if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
					$st.text('');
					one.url = r.data.url;
					$('#dze-one-new').attr('src', one.url).attr('data-full', one.url);
					$('#dze-one-old').attr('src', r.data.main || '').attr('data-full', r.data.main || '')
						.closest('figure').toggle(!!r.data.main);
					$('#dze-one-pair').show();
					$('#dze-one-dest').show();
					$('#dze-one-gen').text(i18n.qmAgain);
					$('#dze-one-apply').show().text(i18n.oneApply);
				})
				.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
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

	$(document).on('click', '#dze-one-apply', function () {
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-one-state').removeClass('is-ko').text(i18n.applying);
		var done = function (r) {
			$b.prop('disabled', false);
			if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
			$st.text(i18n.applied);
			// The block behind the popup still shows the old value.
			window.setTimeout(function () { window.location.reload(); }, 800);
		};
		if (one.mode === 'image') {
			$.post(cfg.ajaxUrl, {
				action: 'dze_content_image_attach', nonce: cfg.nonce, post: PID,
				items: [ { url: one.url, target: $('#dze-one-target').val() || 'main' } ],
				replace: $('#dze-one-replace').is(':checked') ? (one.srcId || 0) : 0
			}).done(function (r) {
				if (r && r.success) {
					$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID, shots: [ one.url ] });
				}
				done(r);
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
		if (one.mode === 'image') {
			data.ptype = 'field';
			data.field = $('#dze-one-recipe').val() || cfg.mainRecipe || '';
		} else {
			data.ptype = 'field';
			data.field = one.fid;
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
		} else if ($('#dze-cx-modal').hasClass('is-open') && $('#dze-qm-drop').length) {
			e.preventDefault();
			qmReadFile(file);
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
