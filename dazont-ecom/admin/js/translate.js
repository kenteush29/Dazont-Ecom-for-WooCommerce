/**
 * Translate popup: one language at a time, every field in one call, read
 * before it is written.
 *
 * The layout is the one the content toolbox uses — shut drawers, the first
 * line showing, a real editor when you open one — because it is the same
 * gesture: produce, compare, correct, accept.
 */
(function ($) {
	'use strict';

	var cfg = window.dzeTranslate || {};
	var i18n = cfg.i18n || {};
	var res = { texts: {}, source: {}, current: {}, labels: {}, open: {}, lang: '' };

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
	function peek(html) {
		var t = $('<div>').html(html || '').text().replace(/\s+/g, ' ').trim();
		return t ? (t.length > 110 ? t.slice(0, 110) + '…' : t) : i18n.empty;
	}
	function reason(x) {
		if (x && x.responseJSON && x.responseJSON.data && x.responseJSON.data.message) { return x.responseJSON.data.message; }
		return i18n.error;
	}
	function editorId(fid) { return 'dze-tr-ed-' + String(fid).replace(/[^a-zA-Z0-9_-]/g, ''); }
	function isRich(fid) { return fid === 'content' || fid === 'excerpt'; }
	function editorGet(eid) {
		if (window.tinymce) {
			var ed = window.tinymce.get(eid);
			if (ed && !ed.isHidden()) { return ed.getContent(); }
		}
		return $('#' + eid).val() || '';
	}
	function valueOf(fid) {
		return res.open[fid] ? editorGet(editorId(fid)) : (res.texts[fid] || '');
	}

	function draw() {
		var html = '';
		Object.keys(res.texts).forEach(function (fid) {
			html += '<div class="dze-cb-fblock" data-field="' + fid + '">' +
				'<div class="dze-cb-fhead" role="button" tabindex="0" aria-expanded="false">' +
					'<input type="checkbox" class="dze-cb-fkeep" checked title="' + esc(i18n.keepHelp || '') + '" />' +
					'<span class="dze-cb-fcaret">▸</span>' +
					'<span class="dze-cb-fname">' + esc(res.labels[fid] || fid) + '</span>' +
					'<span class="dze-cb-fpeek">' + esc(peek(res.texts[fid])) + '</span>' +
					'<span class="dze-cb-fstate"></span>' +
				'</div>' +
				'<div class="dze-cb-fbody" style="display:none;"></div>' +
			'</div>';
		});
		$('#dze-tr-drawers').html(html);
		$('#dze-tr-result').show();
	}

	// Open a drawer: the new text in an editor, and — side by side — what the
	// original says and what the translation says today. Judging a translation
	// without its source is guessing.
	function openField(fid, on) {
		var $b = $('#dze-tr-drawers .dze-cb-fblock[data-field="' + fid + '"]');
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
		$body.html(
			'<div class="dze-tr-cols">' +
				'<div class="dze-tr-col"><span class="dze-cb-nowlabel">' + esc(i18n.source) + '</span>' +
					'<div class="dze-cb-nowbody">' + (res.source[fid] || esc(i18n.empty)) + '</div></div>' +
				'<div class="dze-tr-col"><span class="dze-cb-nowlabel">' + esc(i18n.current) + '</span>' +
					'<div class="dze-cb-nowbody">' + (res.current[fid] || esc(i18n.empty)) + '</div></div>' +
			'</div>' +
			(isRich(fid)
				? '<textarea id="' + eid + '" class="dze-cb-ed"></textarea>'
				: '<textarea id="' + eid + '" class="dze-cb-plain" rows="3"></textarea>')
		);
		$('#' + eid).val(res.texts[fid] || '');
		if (isRich(fid) && window.wp && wp.editor && wp.editor.initialize) {
			try { wp.editor.remove(eid); } catch (e) {}
			wp.editor.initialize(eid, {
				tinymce: { wpautop: true, toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo', height: 220 },
				quicktags: true, mediaButtons: false
			});
		}
	}

	$(document).on('click', '#dze-tr-drawers .dze-cb-fhead', function (e) {
		if ($(e.target).closest('.dze-cb-fkeep').length) { return; }
		var $b = $(this).closest('.dze-cb-fblock');
		openField($b.data('field'), !$b.hasClass('is-open'));
	});
	$(document).on('keydown', '#dze-tr-drawers .dze-cb-fhead', function (e) {
		if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $(this).trigger('click'); }
	});
	$(document).on('change', '#dze-tr-drawers .dze-cb-fkeep', function (e) {
		e.stopPropagation();
		$(this).closest('.dze-cb-fblock').toggleClass('is-dropped', !$(this).is(':checked'));
	});

	$(document).on('click', '#dze-tr-run', function () {
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-tr-state').removeClass('is-ko').text(i18n.working);
		res = { texts: {}, source: {}, current: {}, labels: {}, open: {}, lang: $('#dze-tr-lang').val() };
		$('#dze-tr-result').hide();
		$('#dze-tr-open').hide();
		$('#dze-tr-applystate').text('');
		$.post(cfg.ajaxUrl, { action: 'dze_tr_preview', nonce: cfg.nonce, post: cfg.postId, lang: res.lang })
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
				$st.text('');
				res.texts = r.data.texts || {};
				res.source = r.data.source || {};
				res.current = r.data.current || {};
				res.labels = r.data.labels || {};
				// Said before the click that writes, not after.
				var warn = '';
				if (!r.data.exists) { warn = i18n.willCreate; }
				else if (!r.data.mine) { warn = i18n.notMine; }
				$('#dze-tr-warn').html(warn ? '<div class="notice notice-warning inline" style="margin:0 0 10px;"><p>' + esc(warn) + '</p></div>' : '');
				if (r.data.edit) { $('#dze-tr-open').attr('href', r.data.edit).show(); }
				draw();
			})
			.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});

	$(document).on('click', '#dze-tr-apply', function () {
		var payload = {};
		Object.keys(res.texts).forEach(function (fid) {
			var $k = $('#dze-tr-drawers .dze-cb-fblock[data-field="' + fid + '"]').find('.dze-cb-fkeep');
			if ($k.length && !$k.is(':checked')) { return; }
			payload[fid] = valueOf(fid);
		});
		if (!Object.keys(payload).length) { return; }
		if (!window.confirm(i18n.confirm)) { return; }
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-tr-applystate').removeClass('is-ko').text(i18n.applying);
		$.post(cfg.ajaxUrl, {
			action: 'dze_tr_apply', nonce: cfg.nonce, post: cfg.postId, lang: res.lang, texts: payload
		})
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
				$st.text(i18n.applied);
				if (r.data.edit) { $('#dze-tr-open').attr('href', r.data.edit).show(); }
			})
			.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});

}(jQuery));
