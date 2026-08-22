/* global jQuery */
/**
 * The box that takes photographs from OUTSIDE the shop, for the whole plugin.
 *
 * Three screens generate images from something the product does not have yet —
 * the one-function popup, the product toolbox and the bulk review panel — and
 * each of them needs the same thing: paste, drop or pick several files, see
 * what is in the set, take one back out, say which one is the subject. Written
 * once here rather than three times: three copies of this drift apart, and the
 * screen that gets fixed is never the one you are standing on.
 *
 * Usage:
 *   var box = window.dzePasteBox.mount($slot, { max: 12, maxBody: 9437184 });
 *   box.list();   // the data URIs, the first one being the subject
 *   box.add(uri); // from a document-level Ctrl+V, routed by the host screen
 *   box.clear();
 *
 * Nothing here is stored on the site: a photograph in this box travels inside
 * the generation request and is never written to the media library.
 */
(function ($) {
	'use strict';
	if (window.dzePasteBox) { return; }

	function esc(t) { return $('<i></i>').text(t == null ? '' : t).html(); }

	function mount($slot, opts) {
		opts = opts || {};
		var i18n = $.extend({}, window.dzePasteI18n || {}, opts.i18n || {});
		var max = parseInt(opts.max, 10) || 12;
		var maxBody = parseInt(opts.maxBody, 10) || 9437184;
		var list = [];

		$slot.html(
			'<div class="dze-qm-drop dze-pb" tabindex="0">' +
				'<span class="dze-qm-dropmsg"></span> ' +
				'<button type="button" class="button button-small dze-pb-browse"></button>' +
				'<input type="file" accept="image/*" multiple hidden class="dze-pb-file" />' +
				'<div class="dze-pb-list"></div>' +
				'<p class="dze-pb-help" style="display:none;"></p>' +
				'<span class="dze-pb-state"></span>' +
			'</div>'
		);
		var $box = $slot.find('.dze-pb');

		function say(msg, bad) {
			$box.find('.dze-pb-state').toggleClass('is-ko', !!bad).text(msg || '');
		}
		function weight() {
			return list.reduce(function (n, u) { return n + u.length; }, 0);
		}
		function draw() {
			var n = list.length;
			var $g = $box.find('.dze-pb-list').empty();
			list.forEach(function (u, i) {
				var $tile = $('<span class="dze-pb-tile"></span>').append(
					$('<img />').attr('src', u).attr('alt', ''),
					$('<button type="button" class="dze-pb-del"></button>')
						.attr('title', i18n.remove || '').attr('data-i', i).html('&times;')
				);
				if (0 === i) {
					// The first one is what the image is BUILT from, and it says
					// so rather than leaving the order to be guessed.
					$tile.append($('<span class="dze-pb-tag"></span>').text(i18n.workedFrom || ''));
				} else {
					$tile.append(
						$('<button type="button" class="dze-pb-first"></button>')
							.attr('title', i18n.first || '').attr('data-i', i).text('↑')
					);
				}
				$g.append($tile);
			});
			$box.toggleClass('has-img', n > 0);
			$box.find('.dze-qm-dropmsg').text(
				n ? (1 === n ? (i18n.added1 || '') : (i18n.addedN || '%s').replace('%s', n)) : (i18n.paste || '')
			);
			// The button says what it does at that moment: an upload while the
			// box is empty, one more image once something is in it.
			$box.find('.dze-pb-browse').toggle(n < max).text(n ? (i18n.addMore || '') : (i18n.upload || ''));
			$box.find('.dze-pb-help').toggle(n > 1).text(i18n.help || '');
			if (typeof opts.onChange === 'function') { opts.onChange(list.slice()); }
		}
		function add(uri) {
			if (!uri || list.length >= max) { return false; }
			// Weight, not count: what a request can carry is a number of bytes,
			// and a browser that posts more than the server accepts gets an
			// empty answer with nothing in it to read.
			if (list.length && (weight() + uri.length) > maxBody) {
				say(i18n.tooBig || '', true);
				return false;
			}
			list.push(String(uri));
			say('');
			draw();
			return true;
		}
		function readFile(file) {
			if (!file || !/^image\//.test(file.type)) { return; }
			var fr = new FileReader();
			fr.onload = function () { add(String(fr.result)); };
			fr.readAsDataURL(file);
		}

		// Bound on the box itself, never on the document: two of these can be
		// on one screen, and a delegated handler would have both of them
		// answering the same pick.
		$box.on('click', '.dze-pb-browse', function (e) {
			e.preventDefault();
			e.stopPropagation();
			$box.find('.dze-pb-file').trigger('click');
		});
		$box.on('change', '.dze-pb-file', function () {
			var files = Array.prototype.slice.call(this.files || []);
			this.value = '';
			files.forEach(readFile);
		});
		$box.on('click', '.dze-pb-del', function (e) {
			e.preventDefault();
			e.stopPropagation();
			list.splice(parseInt($(this).data('i'), 10) || 0, 1);
			draw();
		});
		$box.on('click', '.dze-pb-first', function (e) {
			e.preventDefault();
			e.stopPropagation();
			list.unshift(list.splice(parseInt($(this).data('i'), 10) || 0, 1)[0]);
			draw();
		});
		$box.on('dragover', function (e) { e.preventDefault(); $box.addClass('is-over'); });
		$box.on('dragleave drop', function () { $box.removeClass('is-over'); });
		$box.on('drop', function (e) {
			e.preventDefault();
			var dt = e.originalEvent && e.originalEvent.dataTransfer;
			Array.prototype.slice.call((dt && dt.files) || []).forEach(readFile);
		});
		$box.on('paste', function (e) {
			var items = (e.originalEvent && e.originalEvent.clipboardData && e.originalEvent.clipboardData.items) || [];
			for (var i = 0; i < items.length; i++) {
				if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
					e.preventDefault();
					readFile(items[i].getAsFile());
					return;
				}
			}
		});

		draw();
		return {
			el: $box,
			list: function () { return list.slice(); },
			count: function () { return list.length; },
			add: add,
			addFile: readFile,
			clear: function () { list = []; say(''); draw(); },
			say: say
		};
	}

	window.dzePasteBox = { mount: mount, esc: esc };
}(jQuery));
