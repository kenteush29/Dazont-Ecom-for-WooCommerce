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
	var res = { texts: {}, shots: [], open: {}, shotOf: {}, current: null };
	function reset() {
		// TinyMCE instances belong to the product they were opened on.
		Object.keys(res.open).forEach(function (fid) {
			try { if (window.wp && wp.editor) { wp.editor.remove(editorId(fid)); } } catch (e) {}
		});
		res = { texts: {}, shots: [], open: {}, shotOf: {}, current: null };
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
					'<button type="button" class="dze-prompt-peek" data-prompt="quick_main" title="' + esc(i18n.promptTip) + '">&#9998;</button>' +
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
					'<input type="url" id="dze-qm-url" placeholder="' + esc(i18n.qmUrl) + '" />' +
					'<label class="dze-qm-bglabel"><span>' + esc(i18n.qmBg) + '</span>' +
						'<select id="dze-qm-bg">' + bgOpts + '</select></label>' +
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
					'<p class="dze-cb-panelbar">' +
						'<button type="button" class="button button-primary dze-cx-applyone">' + esc(i18n.applyOne) + '</button> ' +
						'<button type="button" class="button button-small dze-cx-redoall">↻ ' + esc(i18n.redoAll) + '</button> ' +
						'<button type="button" class="button button-small dze-cx-onemore">↻ ' + esc(i18n.oneMore) + '</button> ' +
						'<button type="button" class="button-link dze-cx-drop">' + esc(i18n.discard) + '</button>' +
						'<span class="dze-cb-panelstate"></span>' +
					'</p>' +
					'<div class="dze-cb-shots-slot" id="dze-cx-shots"></div>' +
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
		if (cfg.pending && (Object.keys(cfg.pending.texts || {}).length || (cfg.pending.shots || []).length)) {
			hydrate(cfg.pending);
			cfg.pending = null;
		}
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
		var $g = $('<div class="dze-cb-nowgrid"></div>');
		imgs.forEach(function (im) {
			$g.append($('<span class="dze-cb-nowshot"></span>').toggleClass('is-main', !!im.main)
				.append($('<img class="dze-hzoom" />').attr('src', im.thumb).attr('data-full', im.full || im.thumb).attr('alt', '')));
		});
		$slot.empty().append('<span class="dze-cb-nowlabel">' + esc(i18n.nowImages) + '</span>').append($g);
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

	function drawShots() {
		var $slot = $('#dze-cx-shots');
		if (!res.shots.length) { $slot.empty(); return; }
		var $old = $slot.find('.dze-cb-shots'), dropped = {}, dest = {};
		$old.find('.dze-cb-shot').each(function () {
			var u = $(this).data('url');
			if (!$(this).hasClass('is-sel')) { dropped[u] = true; }
			dest[u] = $(this).closest('.dze-cb-shotwrap').find('.dze-cb-shotdest').val();
		});
		var $wrap = $('<div class="dze-cb-shots"><div class="dze-cb-shotgrid"></div><span class="dze-cb-shotstate"></span></div>');
		res.shots.forEach(function (url) {
			var cur = dest[url] || 'gallery';
			$wrap.find('.dze-cb-shotgrid').append(
				$('<div class="dze-cb-shotwrap"></div>').append(
					$('<div class="dze-cb-shot"><span class="dze-cb-shotcheck">✓</span></div>')
						.toggleClass('is-sel', !dropped[url])
						.attr('data-url', url)
						.append($('<img class="dze-hzoom" />').attr('src', url).attr('data-full', url).attr('alt', '')),
					$('<select class="dze-cb-shotdest">' +
						'<option value="gallery"' + (cur === 'gallery' ? ' selected' : '') + '>' + esc(i18n.toGallery) + '</option>' +
						'<option value="gallery_first"' + (cur === 'gallery_first' ? ' selected' : '') + '>' + esc(i18n.toGalleryFirst) + '</option>' +
						'<option value="main"' + (cur === 'main' ? ' selected' : '') + '>' + esc(i18n.toMain) + '</option>' +
					'</select>')
				)
			);
		});
		$slot.empty().append($wrap);
		$('#dze-cx-result').show();
	}
	$(document).on('click', '#dze-cx-shots .dze-cb-shot', function () { $(this).toggleClass('is-sel'); });
	$(document).on('change', '#dze-cx-shots .dze-cb-shotdest', function () {
		if ($(this).val() !== 'main') { return; }
		var $me = $(this);
		$('#dze-cx-shots .dze-cb-shotdest').not($me).each(function () {
			if ($(this).val() === 'main') { $(this).val('gallery'); }
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
	$(document).on('click', '.dze-cx-redoall', function () {
		regenerate(Object.keys(res.texts), $('#dze-cx-result .dze-cb-panelstate'));
	});
	$(document).on('click', '.dze-cx-onemore', function () {
		var $btn = $(this).prop('disabled', true);
		var $st = $('#dze-cx-result .dze-cb-panelstate').removeClass('is-ko').text(i18n.working);
		genImage(tplUsed()[0] || '0')
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
			// Applied and settled: the result panel has nothing left to offer.
			window.setTimeout(function () {
				reset();
				loadCurrent().then(drawCurrentImages);
			}, 900);
			$.post(cfg.ajaxUrl, { action: 'dze_content_pending_clear', nonce: cfg.nonce, post: PID });
			$('.dze-content-open[data-id="' + PID + '"]').find('.dze-content-waiting').remove();
			res.current = null;
			loadCurrent().then(drawCurrentImages);
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
		if (qmPaste) { $('#dze-qm-url').val(''); }
	}
	function qmReadFile(file) {
		if (!file || !/^image\//.test(file.type)) { return; }
		var fr = new FileReader();
		fr.onload = function () { qmShowSource(String(fr.result)); };
		fr.readAsDataURL(file);
	}
	// Ctrl+V anywhere in the popup while the lane is on screen, and a file
	// dropped on the box. Both end up in the same place.
	$(document).on('paste', '#dze-cx-modal', function (e) {
		var items = (e.originalEvent && e.originalEvent.clipboardData && e.originalEvent.clipboardData.items) || [];
		for (var i = 0; i < items.length; i++) {
			if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
				e.preventDefault();
				qmReadFile(items[i].getAsFile());
				return;
			}
		}
	});
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
			url: $('#dze-qm-url').val() || '',
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
	$(document).on('keydown', '#dze-qm-url, #dze-qm-note', function (e) {
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
				'<span id="dze-one-peek"></span>' +
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

	function openOne(fid, mode) {
		oneBuild();
		one = { fid: fid, mode: mode || 'text', value: '', url: '' };
		var label = mode === 'image' ? i18n.qmTitle : (cfg.fields[fid] || fid);
		$('#dze-one-title').text(label);
		$('#dze-one-peek').html('<button type="button" class="dze-prompt-peek" data-prompt="' +
			(mode === 'image' ? 'quick_main' : 'content_' + esc(fid)) + '" title="' + esc(i18n.promptTip) + '">&#9998;</button>');
		$('#dze-one-prompt').val(mode === 'image' ? (cfg.quickPrompt || '') : ((cfg.prompts && cfg.prompts[fid]) || ''));
		$('#dze-one-instrwrap').prop('open', false);
		$('#dze-one-savestate').text('');
		$('#dze-one-state').removeClass('is-ko').text('');
		$('#dze-one-apply').hide().text(i18n.oneApply);
		$('#dze-one-gen').text(mode === 'image' ? i18n.oneMain : i18n.oneGen);
		$('#dze-one-body').html(mode === 'image' ? oneImageBody() : '');
		$('#dze-one').addClass('is-open');
		if (mode === 'text') { oneShowBefore(fid); }
	}

	function oneImageBody() {
		return '<div class="dze-one-img">' +
			'<div class="dze-qm-drop" id="dze-one-drop" tabindex="0">' +
				'<span class="dze-qm-dropmsg">' + esc(i18n.qmPaste) + '</span>' +
				'<img id="dze-one-src" alt="" style="display:none;" />' +
			'</div>' +
			'<p class="dze-qm-bar">' +
				'<input type="url" id="dze-one-url" placeholder="' + esc(i18n.qmUrl) + '" />' +
				'<label class="dze-qm-bglabel"><span>' + esc(i18n.qmBg) + '</span><select id="dze-one-bg">' + bgOptions() + '</select></label>' +
			'</p>' +
			'<div class="dze-qm-pair" id="dze-one-pair" style="display:none;">' +
				'<figure><figcaption>' + esc(i18n.qmNow) + '</figcaption><img id="dze-one-old" class="dze-hzoom" alt="" /></figure>' +
				'<figure><figcaption>' + esc(i18n.qmNew) + '</figcaption><img id="dze-one-new" class="dze-hzoom" alt="" /></figure>' +
			'</div>' +
		'</div>';
	}
	function bgOptions() {
		var list = cfg.backdrops || [];
		return list.map(function (b, i) {
			return '<option value="' + b.id + '"' + (i === 0 ? ' selected' : '') + '>' + esc(b.name) + '</option>';
		}).join('') + '<option value="0"' + (list.length ? '' : ' selected') + '>' + esc(i18n.qmBgNone) + '</option>';
	}

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
				url: $('#dze-one-url').val() || '', paste: one.paste || '',
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
					$('#dze-one-gen').text(i18n.qmAgain);
					$('#dze-one-apply').show().text(i18n.qmUse);
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
				items: [ { url: one.url, target: 'main' } ]
			}).done(done).fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
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
		if (one.mode === 'image') { data.ptype = 'quick'; } else { data.ptype = 'field'; data.field = one.fid; }
		$.post(cfg.ajaxUrl, data)
			.done(function (r) { $st.text((r && r.success) ? i18n.oneSaved : ((r && r.data && r.data.message) || i18n.error)); })
			.fail(function () { $st.text(i18n.error); });
	});

	// Paste or drop straight into the small popup too.
	$(document).on('paste', '#dze-one', function (e) {
		var items = (e.originalEvent && e.originalEvent.clipboardData && e.originalEvent.clipboardData.items) || [];
		for (var i = 0; i < items.length; i++) {
			if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
				e.preventDefault();
				var fr = new FileReader();
				fr.onload = function () {
					one.paste = String(fr.result);
					$('#dze-one-src').attr('src', one.paste).show();
					$('#dze-one-drop').addClass('has-img').find('.dze-qm-dropmsg').text(i18n.qmPasted);
				};
				fr.readAsDataURL(items[i].getAsFile());
				return;
			}
		}
	});

	// ---- The buttons themselves, on the blocks WordPress already shows ----
	function plantButtons() {
		if (!PID || $('.dze-one-btn').length) { return; }
		var anchors = cfg.anchors || {};
		var placed = {};
		Object.keys(anchors).forEach(function (fid) {
			var $box = $(anchors[fid]);
			if (!$box.length) { return; }
			var $target = $box.find('> .inside').first();
			if (!$target.length) { $target = $box; }
			$target.prepend('<p class="dze-one-plant"><button type="button" class="button button-small dze-one-btn" data-field="' +
				esc(fid) + '">✦ ' + esc(i18n.oneWrite) + '</button></p>');
			placed[fid] = 1;
		});
		// The main image box gets the image lane.
		var $img = $('#postimagediv > .inside');
		if ($img.length) {
			$img.prepend('<p class="dze-one-plant"><button type="button" class="button button-small dze-one-btn" data-mode="image">✦ ' +
				esc(i18n.oneMain) + '</button></p>');
		}
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
		openOne($b.data('field') || '', $b.data('mode') || 'text');
	});

	// POD hands its result over to this strip.
	window.dzeContentAddToGallery = function (url) {
		build();
		res.shots.push(url);
		drawShots();
	};
	window.dzeContentOpen = function () { open(); };

}(jQuery));
