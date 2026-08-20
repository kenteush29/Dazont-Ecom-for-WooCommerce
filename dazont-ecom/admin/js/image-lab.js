/* global jQuery, dzeLab */
/**
 * The image lab.
 *
 * One prompt, a few images to work from, a result you keep or throw away. It
 * deliberately holds nothing: sources travel inside the request and are never
 * written to the site, results live at the provider until you ask for one to
 * join the library.
 */
(function ($) {
	'use strict';

	var cfg = window.dzeLab || {};
	var i18n = cfg.i18n || {};
	var MEM = 'dzeLabPrompt';

	// What is on the bench: pasted images as data URIs, library ones as ids.
	var srcs = [];

	function esc(s) { return $('<i></i>').text(s == null ? '' : s).html(); }
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%\d\$s|%s/g, function () { return args[i++]; });
	}
	function reason(x) {
		if (typeof x === 'string' && x) { return x; }
		if (x && x.responseJSON && x.responseJSON.data && x.responseJSON.data.message) { return x.responseJSON.data.message; }
		if (x && x.status) { return 'HTTP ' + x.status; }
		return i18n.error || 'error';
	}
	function say(text, bad) {
		$('#dze-lab-state').toggleClass('is-ko', !!bad).text(text || '');
	}

	// ---- The prompt is remembered: a bench you come back to ----
	$(function () {
		try {
			var kept = window.localStorage.getItem(MEM);
			if (kept && !$('#dze-lab-prompt').val()) { $('#dze-lab-prompt').val(kept); }
		} catch (e) {}
	});
	$(document).on('change', '#dze-lab-prompt', function () {
		try { window.localStorage.setItem(MEM, $(this).val() || ''); } catch (e) {}
	});

	// ---- The images to work from ----
	function drawSrcs() {
		var html = srcs.map(function (s, i) {
			return '<span class="dze-lab-src">' +
				'<img src="' + esc(s.thumb || s.data) + '" data-full="' + esc(s.full || s.data) + '" alt="" />' +
				'<button type="button" class="dze-lab-srcdrop" data-i="' + i + '" title="' + esc(i18n.remove) + '">&times;</button>' +
			'</span>';
		}).join('');
		$('#dze-lab-srcs').html(html).toggleClass('dze-zoomgroup', srcs.length > 0);
		$('#dze-lab-drop').toggle(srcs.length < (cfg.max || 4));
	}
	function addPasted(dataUri) {
		if (srcs.length >= (cfg.max || 4)) { return; }
		srcs.push({ data: dataUri });
		drawSrcs();
	}
	$(document).on('click', '.dze-lab-srcdrop', function () {
		srcs.splice(parseInt($(this).data('i'), 10), 1);
		drawSrcs();
	});
	$(document).on('click', '.dze-lab-browse', function (e) {
		e.preventDefault();
		e.stopPropagation();
		$('.dze-lab-file').trigger('click');
	});
	$(document).on('change', '.dze-lab-file', function () {
		Array.prototype.forEach.call(this.files || [], function (file) {
			if (!/^image\//.test(file.type)) { return; }
			var fr = new FileReader();
			fr.onload = function () { addPasted(String(fr.result)); };
			fr.readAsDataURL(file);
		});
		this.value = '';
	});
	$(document).on('paste', '#dze-lab-drop, #dze-lab-prompt', function (e) {
		var items = (e.originalEvent.clipboardData || {}).items || [];
		for (var i = 0; i < items.length; i++) {
			if (0 === String(items[i].type).indexOf('image/')) {
				var fr = new FileReader();
				fr.onload = function () { addPasted(String(fr.result)); };
				fr.readAsDataURL(items[i].getAsFile());
				e.preventDefault();
				return;
			}
		}
	});
	$(document).on('dragover', '#dze-lab-drop', function (e) { e.preventDefault(); $(this).addClass('is-over'); });
	$(document).on('dragleave', '#dze-lab-drop', function () { $(this).removeClass('is-over'); });
	$(document).on('drop', '#dze-lab-drop', function (e) {
		e.preventDefault();
		$(this).removeClass('is-over');
		Array.prototype.forEach.call((e.originalEvent.dataTransfer || {}).files || [], function (file) {
			if (!/^image\//.test(file.type)) { return; }
			var fr = new FileReader();
			fr.onload = function () { addPasted(String(fr.result)); };
			fr.readAsDataURL(file);
		});
	});
	var frame = null;
	$(document).on('click', '.dze-lab-lib', function (e) {
		e.preventDefault();
		e.stopPropagation();
		if (!window.wp || !wp.media) { return; }
		frame = wp.media({ title: i18n.libTitle, button: { text: i18n.use }, library: { type: 'image' }, multiple: true });
		frame.on('select', function () {
			frame.state().get('selection').each(function (att) {
				if (srcs.length >= (cfg.max || 4)) { return; }
				var a = att.toJSON();
				srcs.push({
					id: a.id,
					thumb: (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url),
					full: a.url
				});
			});
			drawSrcs();
		});
		frame.open();
	});

	// ---- The run ----
	function draw(url) {
		var $out = $('#dze-lab-out');
		// Newest first: the one you are judging is the one at the top left.
		$out.prepend(
			'<figure class="dze-lab-res" data-url="' + esc(url) + '">' +
				'<img src="' + esc(url) + '" data-full="' + esc(url) + '" alt="" />' +
				'<figcaption>' +
					'<a class="button button-small" href="' + esc(url) + '" target="_blank" rel="noopener" download>' + esc(i18n.download) + '</a> ' +
					'<button type="button" class="button button-small button-primary dze-lab-keep">' + esc(i18n.keep) + '</button> ' +
					'<span class="dze-lab-resstate"></span>' +
				'</figcaption>' +
			'</figure>'
		);
		var n = $out.find('.dze-lab-res').length;
		$('#dze-lab-outcap').text(sprintf(i18n.tries, n));
		$('#dze-lab-outwrap').show();
	}
	$(document).on('click', '#dze-lab-run', function () {
		var prompt = $('#dze-lab-prompt').val() || '';
		if (!prompt.trim()) { say(i18n.noPrompt, true); return; }
		var $b = $(this).prop('disabled', true);
		say(i18n.working);
		try { window.localStorage.setItem(MEM, prompt); } catch (e) {}
		$.post(cfg.ajaxUrl, {
			action: 'dze_lab_generate', nonce: cfg.nonce, prompt: prompt,
			pasted: srcs.filter(function (s) { return !!s.data; }).map(function (s) { return s.data; }),
			ids: srcs.filter(function (s) { return !!s.id; }).map(function (s) { return s.id; })
		})
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { say((r && r.data && r.data.message) || i18n.error, true); return; }
				say('');
				$('#dze-lab-run').text(i18n.again);
				draw(r.data.url);
			})
			.fail(function (x) { $b.prop('disabled', false); say(reason(x), true); });
	});

	// ---- Keeping one ----
	$(document).on('click', '.dze-lab-keep', function () {
		var $fig = $(this).closest('.dze-lab-res');
		var $st = $fig.find('.dze-lab-resstate').removeClass('is-ko').text('…');
		var $b = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, {
			action: 'dze_lab_keep', nonce: cfg.nonce,
			url: $fig.attr('data-url'),
			// The library entry is named after what was asked for.
			name: ($('#dze-lab-prompt').val() || '').split('\n')[0]
		})
			.done(function (r) {
				if (!r || !r.success) { $b.prop('disabled', false); $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
				$st.text(i18n.kept);
				if (r.data.edit) {
					$st.append(' <a href="' + esc(r.data.edit) + '" target="_blank" rel="noopener">#' + esc(r.data.id) + '</a>');
				}
			})
			.fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});

	$(drawSrcs);
}(jQuery));
