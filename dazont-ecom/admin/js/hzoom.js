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
