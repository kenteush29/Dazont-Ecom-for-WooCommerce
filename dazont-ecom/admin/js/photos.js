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
				$('<span class="dze-nowtick">✓</span>')
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
		var $cap = $('<span class="dze-nowcap"></span>').text(i18n.nowMain);
		if (opts.ai) {
			$cap.append($('<button type="button" class="button button-small dze-now-ai"></button>').text('✦ ' + (i18n.nowAi || '')));
		}
		var $mainCol = $('<div class="dze-nowcol dze-nowcol-main"></div>').append($cap);
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

		$slot.empty().addClass('dze-photos').attr('data-post', opts.post || 0)
			.append('<span class="dze-cb-nowlabel">' + esc(i18n.nowImages) + '</span>')
			.append($wrap).append($bar).append('<div class="dze-rf-out"></div>');
		if (opts.after) { $slot.data('dze-after', opts.after); }
	}

	// ---- Reframing: pick the photographs, pick the shape, look, accept ----
	function box(el) { return $(el).closest('.dze-photos'); }

	$(document).on('click', '.dze-photos .dze-rf-start', function () {
		box(this).addClass('is-picking').find('.dze-rf-tools').show();
		$(this).hide();
	});
	$(document).on('click', '.dze-photos .dze-rf-cancel', function () {
		box(this).removeClass('is-picking')
			.find('.dze-cb-nowshot').removeClass('is-picked').end()
			.find('.dze-rf-tools').hide().end()
			.find('.dze-rf-start').show().end()
			.find('.dze-rf-out').empty();
	});
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

	// Before and after, side by side, and nothing is written until it is
	// accepted — the same bargain as every other generation in the plugin.
	function drawResult($box, d) {
		var $out = $box.find('.dze-rf-out').empty();
		var $g = $('<div class="dze-rf-pairs"></div>');
		(d.items || []).forEach(function (it) {
			if (it.error) { $g.append($('<p class="dze-rf-err"></p>').text(it.error)); return; }
			$g.append($('<div class="dze-rf-pair"></div>').attr('data-id', it.id).append(
				$('<figure></figure>').append(
					$('<img />').attr('src', it.before).attr('alt', ''),
					$('<figcaption></figcaption>').text((i18n.qmNow || '') + ' · ' + (it.beforeD || ''))
				),
				$('<figure></figure>').append(
					$('<img />').attr('src', it.after).attr('alt', ''),
					$('<figcaption></figcaption>').text((i18n.qmNew || '') + ' · ' + it.w + '×' + it.h + ' · ' + (it.afterD || ''))
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

	$(document).on('click', '.dze-photos .dze-rf-apply', function () {
		var $box = box(this), $out = $box.find('.dze-rf-out');
		var ids = $out.find('.dze-rf-pair').map(function () { return parseInt($(this).data('id'), 10); }).get();
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

	// The screen that shows the block says what its AI button opens; without
	// one the button is not drawn at all.
	$(document).on('click', '.dze-photos .dze-now-ai', function () {
		if (typeof handlers.ai === 'function') { handlers.ai(parseInt(box(this).attr('data-post'), 10) || 0); }
	});

	window.dzePhotos = {
		render: render,
		on: function (name, fn) { handlers[name] = fn; }
	};
}(jQuery));
