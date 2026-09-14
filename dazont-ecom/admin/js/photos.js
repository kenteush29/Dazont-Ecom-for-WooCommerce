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
				// The colour it belongs to, when it belongs to one: without it
				// a strip of eight photographs of the same shoe gives no clue
				// why three of them are blue.
				(im.variation ? $('<span class="dze-nowvar"></span>').text(im.variation) : ''),
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
		var vars = imgs.filter(function (im) { return !im.main && im.variation; });
		var rest = imgs.filter(function (im) { return !im.main && !im.variation; });

		// The main image apart from the gallery, on the same line: same kind of
		// thing, different job.
		// ONE SET UNDER THE ARROWS. "Main image est séparé de galery et de
		// variations. Il faut fermer l'écran et le réouvrir à chaque fois entre
		// les uns et les autres." Three COLUMNS is right on the screen — they
		// are three different jobs — but they are photographs of ONE product,
		// so the zoom group is the block that holds all three rather than each
		// grid: closing the viewer to reach the next photograph is the shop
		// doing the machinery's work.
		var $wrap = $('<div class="dze-nowblock dze-zoomgroup"></div>');
		var $mainCol = $('<div class="dze-nowcol dze-nowcol-main"></div>')
			.append($('<span class="dze-nowcap"></span>').text(i18n.nowMain));
		var $g1 = $('<div class="dze-cb-nowgrid"></div>');
		main.forEach(function (im) { $g1.append(tile(im)); });
		$mainCol.append($g1);
		$wrap.append($mainCol);

		if (rest.length) {
			var $restCol = $('<div class="dze-nowcol"></div>')
				.append($('<span class="dze-nowcap"></span>').text(i18n.nowGallery));
			var $g2 = $('<div class="dze-cb-nowgrid"></div>');
			rest.forEach(function (im) { $g2.append(tile(im)); });
			$restCol.append($g2);
			$wrap.append($restCol);
		}
		// Then the ones the variations carry, in their own column: same kind of
		// thing, and the reason there are three photographs of a shoe nobody
		// put in the gallery.
		if (vars.length) {
			var $varCol = $('<div class="dze-nowcol"></div>')
				.append($('<span class="dze-nowcap"></span>').text(i18n.nowVars || ''));
			var $g3 = $('<div class="dze-cb-nowgrid"></div>');
			vars.forEach(function (im) { $g3.append(tile(im)); });
			$varCol.append($g3);
			$wrap.append($varCol);
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


	// ---- WHICH PHOTOGRAPH IS THE SUBJECT ----
	//
	// The product's own photographs, offered as the subject of what is made,
	// plus what was pasted in. It lived in the toolbox alone, so the BULK
	// screen — which has the same paste box — had no picker at all and posted
	// no answer: a request carrying pasted photographs and nothing else is read
	// by the server as "the pasted one leads", and a supplier shot came back as
	// the product, colours included. Filled here, once, for both screens: two
	// copies of this loop is how two screens start offering different answers.
	//
	// @param {jQuery} $s      the select to fill
	// @param {Array}  images  what the product holds today
	// @param {number} pasted  how many photographs were handed in
	// @param {Object} words   subjMainOpt / subjOne / subjPasteOpt / subjPasteOptN
	function subjects($s, images, pasted, words) {
		if (!$s || !$s.length) { return; }
		var was = String($s.val() || '0');
		$s.empty().append($('<option value="0"></option>').text(words.subjMainOpt || ''));
		var n = 0;
		(images || []).forEach(function (im) {
			if (im.main) { return; }
			n++;
			$s.append($('<option></option>').val(im.id).text(
				im.variation ? String(im.variation) : (words.subjOne || 'Photograph') + ' ' + n
			));
		});
		// WHAT WAS ADDED FROM OUTSIDE IS AN ANSWER TOO — the answer the screen
		// cannot give is the answer nobody can give.
		if (pasted) {
			$s.append($('<option value="paste"></option>').text(
				1 === pasted ? (words.subjPasteOpt || '') : (words.subjPasteOptN || words.subjPasteOpt || '')
			));
		}
		// A choice that no longer exists falls back to the main photograph
		// rather than sending an id nothing answers for.
		$s.val($s.find('option[value="' + was + '"]').length ? was : '0');
	}

	// WHAT THE PHOTOGRAPHS HANDED IN ARE FOR. "Il faut donner l'autorisation de
	// copier les images additionnelles externes. Ce sont des images souvent
	// uniques mais qui doivent être retravaillées." The plugin answered that
	// on its own, invisibly and in capitals — a handed-in photograph was a
	// SETTING and taking a colour, a pattern or an object from it was
	// forbidden — so the one thing a unique shot is handed in for could not be
	// asked for at all. It is a question, and it is the owner's.
	//
	// Shown only where it can act: nothing handed in, or the handed-in set IS
	// the subject, and there is nothing to answer.
	//
	// @param {jQuery} $line  the label wrapping the select
	// @param {number} pasted how many photographs were handed in
	// @param {string} pick   the subject picker's own answer
	// @param {Object} words  refsSet / refsCopy
	function refsUse($line, pasted, pick, words) {
		if (!$line || !$line.length) { return; }
		var $s = $line.find('.dze-cx-refspick');
		if (!$s.find('option').length) {
			$s.append($('<option value="set"></option>').text(words.refsSet || ''))
				.append($('<option value="copy"></option>').text(words.refsCopy || ''));
		}
		$line.toggle(!!pasted && 'paste' !== String(pick || '0'));
	}

	// What a picker's answer means on the wire, in ONE place: "main photograph"
	// and a chosen one both say the product is image 1, and only "paste" leaves
	// the pasted set leading. A default that sends nothing is not an answer.
	function subjectInto(data, pick, use) {
		if ('paste' === String(pick || '0')) { return data; }
		data.base_main = 1;
		var subj = parseInt(pick, 10) || 0;
		if (subj) { data.src_id = subj; }
		// The same rule for the second answer: it POSTS what it says, on its
		// default as much as on the other one.
		data.refs_use = 'copy' === String(use || '') ? 'copy' : 'set';
		return data;
	}

	window.dzePhotos = {
		// The blocks live in hub.js now — one machinery for every screen. These
		// two names stay so nothing that already calls them has to change.
		toggleSec: function ($sec, on) { return window.dzeHub.toggleSec($sec, on); },
		countSections: function () { return window.dzeHub.count(); },
		render: render,
		subjects: subjects,
		refsUse: refsUse,
		subjectInto: subjectInto,
		on: function (name, fn) { handlers[name] = fn; }
	};
}(jQuery));
