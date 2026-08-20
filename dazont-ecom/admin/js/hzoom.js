/* global jQuery */
/**
 * Zoom on product images, for the whole plugin.
 *
 * There used to be a hover preview here: a large floating image that appeared
 * under the cursor as it crossed any thumbnail. It fired while you were only
 * passing through, covered what you were reading, and there was no way to ask
 * for it or refuse it. It is gone; zooming is a click, on a button, below.
 */

/**
 * The zoom viewer, shared by every screen that shows product images.
 *
 * Hovering is fine to recognise a photograph, useless to judge one. Any grid
 * marked `.dze-zoomgroup` gets a small ⤢ button planted in the top-right of
 * each of its thumbnails; it opens the image full size, with ‹ › walking the
 * rest of the grid, the arrow keys, and Escape to close.
 *
 * Written here rather than handed to WordPress: the native media modal only
 * knows attachments, and a freshly generated image is not one yet — it is a
 * URL at the provider. One viewer that takes URLs works for both.
 */
(function ($) {
	'use strict';
	if (window.dzeZoomBound) { return; }
	window.dzeZoomBound = true;

	var i18n = (window.dzeZoomI18n || {});
	var list = [], at = 0, $box = null;

	function urlOf(img) { return $(img).data('full') || img.src || ''; }

	function draw() {
		if (!$box) { return; }
		$box.find('.dze-zoom-img').attr('src', list[at] || '');
		$box.find('.dze-zoom-count').text((at + 1) + ' / ' + list.length);
		$box.find('.dze-zoom-prev, .dze-zoom-next').toggle(list.length > 1);
	}
	function open(urls, index) {
		list = urls;
		at   = Math.max(0, Math.min(index, urls.length - 1));
		if (!$box) {
			$box = $('<div class="dze-zoom-back">' +
				'<button type="button" class="dze-zoom-close" aria-label="' + (i18n.close || 'Close') + '">&times;</button>' +
				'<button type="button" class="dze-zoom-prev" aria-label="' + (i18n.prev || 'Previous') + '">&#8249;</button>' +
				'<img class="dze-zoom-img" alt="" />' +
				'<button type="button" class="dze-zoom-next" aria-label="' + (i18n.next || 'Next') + '">&#8250;</button>' +
				'<span class="dze-zoom-count"></span>' +
			'</div>').appendTo('body');
			$box.on('click', function (e) { if (e.target === this) { close(); } });
			$box.on('click', '.dze-zoom-close', close);
			$box.on('click', '.dze-zoom-prev', function () { step(-1); });
			$box.on('click', '.dze-zoom-next', function () { step(1); });
		}
		$box.addClass('is-open');
		draw();
	}
	function step(d) {
		if (!list.length) { return; }
		at = (at + d + list.length) % list.length;
		draw();
	}
	function close() { if ($box) { $box.removeClass('is-open'); } }

	$(document).on('keydown', function (e) {
		if (!$box || !$box.hasClass('is-open')) { return; }
		if (e.key === 'Escape') { close(); }
		if (e.key === 'ArrowLeft') { step(-1); }
		if (e.key === 'ArrowRight') { step(1); }
	});

	// The button belongs to the grid, not to the markup that drew it: every
	// strip in the plugin gets the same one by tagging its container, and the
	// strips are redrawn constantly, so planting is repeated rather than done
	// once at load.
	function plant() {
		$('.dze-zoomgroup img[data-full]').each(function () {
			var $img = $(this), $cell = $img.parent();
			if ($cell.find('> .dze-zoom-btn').length) { return; }
			$cell.append('<button type="button" class="dze-zoom-btn" tabindex="-1" title="' +
				(i18n.zoom || 'See this image full size') + '">&#10530;</button>');
		});
	}
	$(function () {
		plant();
		if (window.MutationObserver) {
			var pending = null;
			new MutationObserver(function () {
				window.clearTimeout(pending);
				pending = window.setTimeout(plant, 60);
			}).observe(document.body, { childList: true, subtree: true });
		}
	});

	// What the viewer walks: the images of THIS grid, each one once.
	//
	// Two things used to put the same photograph in the list several times.
	// A grid nested inside another marked grid was read by both, so opening
	// the outer one walked the inner one's images a second time; and a
	// photograph shown twice on the same screen — the same URL in two tiles —
	// was collected twice. Both showed as "1 / 6" with the same picture coming
	// back under the arrows.
	function urlsOf($group) {
		var urls = [], seen = {};
		$group.find('img[data-full]').each(function () {
			if ($(this).closest('.dze-zoomgroup')[0] !== $group[0]) { return; }
			var u = urlOf(this);
			if (!u || seen[u]) { return; }
			seen[u] = 1;
			urls.push(u);
		});
		return urls;
	}

	// The viewer as a component: a screen that keeps its own list of images —
	// the sourcing grid, where one card holds a whole gallery — opens it with
	// that list instead of planting hidden thumbnails for this file to find.
	window.dzeZoom = { open: open, close: close };

	$(document).on('click', '.dze-zoom-btn', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $group = $(this).closest('.dze-zoomgroup');
		var me = urlOf($(this).parent().find('img[data-full]')[0] || {});
		var urls = urlsOf($group);
		open(urls, Math.max(0, urls.indexOf(me)));
	});
}(jQuery));
