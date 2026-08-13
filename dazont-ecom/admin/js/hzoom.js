/* global jQuery */
/**
 * Hover zoom, shared by every screen that shows small thumbnails: an
 * <img class="dze-hzoom" data-full="…"> shows its full version floating next
 * to the cursor. It lets a list stay dense — 60 px thumbnails you can actually
 * inspect — instead of trading half the screen for legibility.
 *
 * Driven by mouseover on the document rather than mouseenter/mouseleave on
 * the images themselves. Our grids are redrawn under the cursor all the time
 * (clicking a thumbnail to select it rebuilds the whole strip), and a
 * thumbnail that is removed while hovered never fires its mouseleave — which
 * left the big picture stuck on screen with nothing to dismiss it.
 *
 * Bound once per page, whoever loads it first.
 */
(function ($) {
	'use strict';
	if (window.dzeHzoomBound) { return; }
	window.dzeHzoomBound = true;

	var $pop = null,   // the floating preview
		el = null,     // the thumbnail it belongs to
		src = '';      // the image it is showing

	function hide() {
		if ($pop) { $pop.remove(); }
		$pop = null;
		el   = null;
		src  = '';
	}

	function show(node, url) {
		hide();
		el   = node;
		src  = url;
		$pop = $('<div class="dze-hzoom-pop"></div>')
			.append($('<img />').attr('src', url).attr('alt', ''))
			.appendTo('body');
	}

	function place(e) {
		if (!$pop) { return; }
		// The thumbnail may have been redrawn or removed since: no source in
		// the document, no preview.
		if (el && !document.body.contains(el)) { hide(); return; }
		var w = 360, h = 360;
		var left = (e.clientX + w + 30 > $(window).width()) ? e.clientX - w - 20 : e.clientX + 20;
		var top  = Math.max(10, Math.min(e.clientY - h / 2, $(window).height() - h - 10));
		$pop.css({ left: left + 'px', top: top + 'px' });
	}

	$(document).on('mouseover', function (e) {
		var node = $(e.target).closest('img.dze-hzoom')[0];
		if (!node) { hide(); return; }
		var url = $(node).data('full') || node.src;
		if (!url) { hide(); return; }
		// Same thumbnail, still there: keep the preview as it is.
		if ($pop && node === el && url === src) { return; }
		show(node, url);
		place(e);
	});

	$(document).on('mousemove', place);

	// Anything that moves the page or takes the focus away dismisses it: a
	// preview left behind by a scroll is exactly the bug this replaces.
	$(document).on('click', hide);
	$(document).on('mouseleave', hide);
	$(window).on('blur', hide);
	// Capture phase: scroll does not bubble, and the grids that need this live
	// inside scrollable panels (the toolbox modal), not on the page itself.
	document.addEventListener('scroll', hide, true);
	$(document).on('keydown', function (e) { if (e.key === 'Escape') { hide(); } });
}(jQuery));

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

	$(document).on('click', '.dze-zoom-btn', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $group = $(this).closest('.dze-zoomgroup');
		var urls = [], me = urlOf($(this).parent().find('img[data-full]')[0] || {});
		$group.find('img[data-full]').each(function () { urls.push(urlOf(this)); });
		open(urls, Math.max(0, urls.indexOf(me)));
	});
}(jQuery));
