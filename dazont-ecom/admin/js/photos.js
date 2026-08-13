/* global jQuery, dzePhotosCfg */
/**
 * The product's photographs, drawn the same way wherever they are shown.
 *
 * The product screen and the bulk screen were each drawing this block their
 * own way, so an improvement made on one never reached the other — the bulk
 * panel still had 48px thumbnails with nothing written under them while the
 * product popup had sizes, shapes, a zoom and a reframe lane. One renderer
 * now, one set of handlers, and both screens get whatever is added next.
 *
 * Usage: dzePhotos.render($slot, images, { post: 123, ai: true, after: fn })
 *   images  what ajax_content_current returns: id, thumb, full, main, w, h, ratio
 *   post    the product the photographs belong to — the reframe writes to it
 *   ai      show the "remake with AI" button next to the main image
 *   after   called once photographs have been written to the product
 */
(function ($) {
	'use strict';
	if (window.dzePhotos) { return; }

	var cfg = window.dzePhotosCfg || {};
	var i18n = cfg.i18n || {};
	var handlers = {};

	function esc(t) { return $('<i></i>').text(t == null ? '' : t).html(); }
	function reason(x) {
		if (typeof x === 'string') { return x; }
		if (x && x.responseJSON && x.responseJSON.data && x.responseJSON.data.message) { return x.responseJSON.data.message; }
		if (x && x.status) { return 'HTTP ' + x.status; }
		return i18n.error || 'error';
	}

	function tile(im) {
		return $('<span class="dze-cb-nowshot"></span>')
			.toggleClass('is-main', !!im.main)
			.attr('data-id', im.id)
			.append(
				$('<img />').attr('src', im.thumb).attr('data-full', im.full || im.thumb).attr('alt', ''),
				// A catalogue is square or it is not: the shape is written under
				// each photograph rather than opened to be found out.
				$('<span class="dze-nowdim"></span>').text(
					(im.w && im.h) ? (im.w + '×' + im.h + (im.ratio ? ' · ' + im.ratio : '')) : ''
				),
				$('<span class="dze-nowtick">✓</span>'),
				// Where this photograph's resized version appears: directly
				// under the original, in its own column.
				$('<span class="dze-nowafter"></span>')
			);
	}

	function render($slot, imgs, opts) {
		opts = opts || {};
		imgs = imgs || [];
		if (!$slot || !$slot.length) { return; }
		if (!imgs.length) { $slot.empty(); return; }

		var main = imgs.filter(function (im) { return im.main; });
		var rest = imgs.filter(function (im) { return !im.main; });

		// The main image apart from the gallery, on the same line: same kind of
		// thing, different job.
		var $wrap = $('<div class="dze-nowblock"></div>');
		var $mainCol = $('<div class="dze-nowcol dze-nowcol-main"></div>')
			.append($('<span class="dze-nowcap"></span>').text(i18n.nowMain));
		var $g1 = $('<div class="dze-cb-nowgrid dze-zoomgroup"></div>');
		main.forEach(function (im) { $g1.append(tile(im)); });
		$mainCol.append($g1);
		$wrap.append($mainCol);

		if (rest.length) {
			var $restCol = $('<div class="dze-nowcol"></div>')
				.append($('<span class="dze-nowcap"></span>').text(i18n.nowGallery));
			var $g2 = $('<div class="dze-cb-nowgrid dze-zoomgroup"></div>');
			rest.forEach(function (im) { $g2.append(tile(im)); });
			$restCol.append($g2);
			$wrap.append($restCol);
		}

		// ONE menu of what can be done to these photographs. Reframing is not
		// something anybody does on every product, so it does not get a button
		// standing there for good: you pick the tool you need, when you need
		// it, and its controls appear under the menu.
		var ratios = (cfg.ratios || ['1:1']).map(function (r) {
			return '<option value="' + esc(r) + '">' + esc(r) + '</option>';
		}).join('');
		// Two named buttons: what each one does is written on it. "Do something
		// with these" asked the reader to open a menu to find out what the
		// screen could even do.
		var $bar = $('<p class="dze-nowbar"></p>').append(
			(opts.ai ? '<button type="button" class="button button-small dze-photo-ai">✦ ' + esc(i18n.btnAi) + '</button>' : '') +
			'<button type="button" class="button button-small dze-photo-rf">⤢ ' + esc(i18n.btnRf) + '</button>' +
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

		$slot.empty().addClass('dze-photos').attr('data-post', opts.post || 0)
			.append('<span class="dze-cb-nowlabel">' + esc(i18n.nowImages) + '</span>')
			.append($wrap).append($bar).append('<div class="dze-rf-out"></div>');
		if (opts.after) { $slot.data('dze-after', opts.after); }
	}

	// ---- Reframing: pick the photographs, pick the shape, look, accept ----
	function box(el) { return $(el).closest('.dze-photos'); }

	$(document).on('click', '.dze-photos .dze-photo-ai', function () {
		if (typeof handlers.ai === 'function') { handlers.ai(parseInt(box(this).attr('data-post'), 10) || 0); }
	});
	$(document).on('click', '.dze-photos .dze-photo-rf', function () {
		box(this).addClass('is-picking').find('.dze-rf-tools').show().end()
			.find('.dze-photo-rf').hide();
	});
	function rfReset($box) {
		$box.removeClass('is-picking')
			.find('.dze-cb-nowshot').removeClass('is-picked').end()
			.find('.dze-nowafter').empty().end()
			.find('.dze-rf-tools').hide().end()
			.find('.dze-photo-rf').show().end()
			.find('.dze-rf-out').empty();
	}
	$(document).on('click', '.dze-photos .dze-rf-cancel', function () { rfReset(box(this)); });
	$(document).on('click', '.dze-photos .dze-rf-all', function () {
		var $all = box(this).find('.dze-cb-nowshot');
		$all.toggleClass('is-picked', $all.filter('.is-picked').length !== $all.length);
	});
	$(document).on('click', '.dze-photos.is-picking .dze-cb-nowshot', function () {
		$(this).toggleClass('is-picked');
	});
	$(document).on('click', '.dze-photos .dze-rf-run', function () {
		var $box = box(this);
		var ids = $box.find('.dze-cb-nowshot.is-picked').map(function () {
			return parseInt($(this).data('id'), 10);
		}).get().filter(Boolean);
		var $st = $box.find('.dze-rf-state').removeClass('is-ko');
		if (!ids.length) { $st.addClass('is-ko').text(i18n.rfNone); return; }
		var $b = $(this).prop('disabled', true);
		$st.text(i18n.working);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_reframe_preview', nonce: cfg.nonce,
			ids: ids, ratio: $box.find('.dze-rf-ratio').val(), mode: $box.find('.dze-rf-mode').val()
		}).done(function (r) {
			$b.prop('disabled', false);
			if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
			$st.text('');
			drawResult($box, r.data);
		}).fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});

	// The resized version appears directly UNDER the photograph it was made
	// from, in the same column. Drawing the originals a second time to put
	// them next to their own copies made the block twice as long and the
	// comparison harder, not easier.
	function drawResult($box, d) {
		$box.find('.dze-nowafter').empty();
		var kept = 0;
		var errs = [];
		(d.items || []).forEach(function (it) {
			if (it.error) { errs.push(it.error); return; }
			kept++;
			$box.find('.dze-cb-nowshot[data-id="' + it.id + '"] .dze-nowafter').append(
				$('<span class="dze-nowarrow">↓</span>'),
				$('<img class="dze-rf-new" />').attr('src', it.after).attr('data-full', it.after).attr('alt', ''),
				$('<span class="dze-nowdim"></span>').text(it.w + '×' + it.h + ' · ' + (it.afterD || ''))
			);
		});
		var $out = $box.find('.dze-rf-out').empty();
		errs.forEach(function (m) { $out.append($('<p class="dze-rf-err"></p>').text(m)); });
		if (!kept) { return; }
		$out.append(
			$('<p class="dze-nowbar"></p>').append(
				'<button type="button" class="button button-primary dze-rf-apply">' + esc(i18n.rfApply) + '</button>' +
				'<label class="dze-rf-drop"><input type="checkbox" class="dze-rf-dropold" /> ' + esc(i18n.rfDropOld) + '</label>' +
				'<button type="button" class="button-link dze-rf-cancel">' + esc(i18n.discard) + '</button>' +
				'<span class="dze-rf-state2"></span>'
			)
		).data('ratio', d.ratio).data('mode', d.mode);
	}

	$(document).on('click', '.dze-photos .dze-rf-apply', function () {
		var $box = box(this), $out = $box.find('.dze-rf-out');
		var ids = $box.find('.dze-cb-nowshot').filter(function () {
			return $(this).find('.dze-rf-new').length > 0;
		}).map(function () { return parseInt($(this).data('id'), 10); }).get();
		if (!ids.length) { return; }
		var $b = $(this).prop('disabled', true);
		var $st = $out.find('.dze-rf-state2').removeClass('is-ko').text(i18n.applying);
		$.post(cfg.ajaxUrl, {
			action: 'dze_content_reframe_apply', nonce: cfg.nonce,
			post: parseInt($box.attr('data-post'), 10) || 0,
			ids: ids, ratio: $out.data('ratio'), mode: $out.data('mode'),
			drop_original: $out.find('.dze-rf-dropold').is(':checked') ? 1 : 0
		}).done(function (r) {
			$b.prop('disabled', false);
			if (!r || !r.success) { $st.addClass('is-ko').text((r && r.data && r.data.message) || i18n.error); return; }
			$st.text(i18n.applied);
			var after = $box.data('dze-after');
			if (typeof after === 'function') { after(); }
		}).fail(function (x) { $b.prop('disabled', false); $st.addClass('is-ko').text(reason(x)); });
	});

	// ---- Collapsible sections, wherever they are printed ----
	// The popup builds them in JavaScript, the bulk screen prints them in PHP;
	// one handler drives both, so the two dashboards behave identically and a
	// screen that adds a section gets the behaviour for free. Whoever wants to
	// remember the state listens for the event.
	function toggleSec($sec, on) {
		$sec.toggleClass('is-open', on);
		$sec.find('> .dze-sec-head').attr('aria-expanded', on ? 'true' : 'false')
			.find('.dze-sec-caret').text(on ? '▾' : '▸');
		$sec.find('> .dze-sec-body').toggle(on);
		$(document).trigger('dze:sec', [ $sec.attr('data-sec'), on ]);
	}
	$(document).on('click', '.dze-sec-head', function () {
		var $sec = $(this).closest('.dze-sec');
		toggleSec($sec, !$sec.hasClass('is-open'));
	});
	$(document).on('keydown', '.dze-sec-head', function (e) {
		if (e.key !== 'Enter' && e.key !== ' ') { return; }
		e.preventDefault();
		var $sec = $(this).closest('.dze-sec');
		toggleSec($sec, !$sec.hasClass('is-open'));
	});

	// How many functions a shut section is holding, written in its own heading:
	// a closed section said nothing about what it would run, so you had to open
	// all of them to find out what the button was about to do.
	function countSec($sec) {
		var $boxes = $sec.find('> .dze-sec-body input[type=checkbox]').filter(function () {
			return !$(this).closest('.dze-rf-tools, .dze-rf-out').length;
		});
		var total = $boxes.length;
		var on = $boxes.filter(':checked').length;
		$sec.find('> .dze-sec-head > .dze-sec-count').text(total ? (on + ' / ' + total) : '')
			.toggleClass('is-on', on > 0);
	}
	function countAll() { $('.dze-sec').each(function () { countSec($(this)); }); }
	$(document).on('change', '.dze-sec-body input[type=checkbox]', function () {
		countSec($(this).closest('.dze-sec'));
	});
	$(function () { countAll(); });
	// Sections drawn later — the popup builds its own — are counted when they
	// appear, without every screen having to remember to ask.
	if (window.MutationObserver) {
		var pending = null;
		$(function () {
			new MutationObserver(function () {
				window.clearTimeout(pending);
				pending = window.setTimeout(countAll, 80);
			}).observe(document.body, { childList: true, subtree: true });
		});
	}

	window.dzePhotos = {
		toggleSec: toggleSec,
		countSections: countAll,
		render: render,
		on: function (name, fn) { handlers[name] = fn; }
	};
}(jQuery));
