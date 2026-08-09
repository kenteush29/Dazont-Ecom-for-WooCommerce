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
				'<div class="dze-cb-controls">' +
					'<div class="dze-cb-block"><h3>' + esc(i18n.text) + '</h3>' +
						'<div class="dze-cb-checks">' + checks + '</div></div>' +
					'<div class="dze-cb-block"><h3>' + esc(i18n.price) + '</h3>' +
						'<label class="dze-cb-check"><input type="checkbox" id="dze-cx-doprice"' + (au.price ? ' checked' : '') + ' />' +
						'<span>' + esc(i18n.priceOpt) + '</span></label>' +
						'<div class="dze-cb-opts"><label><span>' + esc(i18n.costLabel) + '</span>' +
						'<input type="number" step="0.01" id="dze-cx-cost" value="' + esc(cfg.product.price) + '" /></label></div>' +
					'</div>' +
					(cfg.templates.length ?
					'<div class="dze-cb-block"><h3>' + esc(i18n.image) + '</h3>' +
						'<label class="dze-cb-check"><input type="checkbox" id="dze-cx-doimg"' + (au.img ? ' checked' : '') + ' />' +
						'<span>' + esc(i18n.genImgOpt) + '</span></label>' +
						'<div class="dze-cb-opts">' +
							'<label><span>' + esc(i18n.template) + '</span><span class="dze-tplrows" id="dze-cx-tplrows"></span></label>' +
							sceneSel +
							'<label><span>' + esc(i18n.attempts) + '</span><select id="dze-cx-imgn">' +
								[1, 2, 3, 4].map(function (n) { return '<option value="' + n + '"' + ((au.imgn || 1) === n ? ' selected' : '') + '>× ' + n + '</option>'; }).join('') +
							'</select></label>' +
						'</div>' +
					'</div>' : '') +
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
					'<div class="dze-cb-nowshots" id="dze-cx-nowshots"></div>' +
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
	$(document).on('click', '#dze-cx-drawers .dze-cb-fhead', function (e) {
		if ($(e.target).closest('.dze-cx-redo, .dze-cx-now').length) { return; }
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
		var fids = Object.keys(res.texts), ok = 0, ko = 0;

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

	// POD hands its result over to this strip.
	window.dzeContentAddToGallery = function (url) {
		build();
		res.shots.push(url);
		drawShots();
	};
	window.dzeContentOpen = function () { open(); };

}(jQuery));
