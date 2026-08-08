/* global jQuery */
/**
 * Hover zoom, shared by every screen that shows small thumbnails: an
 * <img class="dze-hzoom" data-full="…"> shows its full version floating next
 * to the cursor. It lets a list stay dense — 60 px thumbnails you can actually
 * inspect — instead of trading half the screen for legibility.
 *
 * Bound once per page, whoever loads it first.
 */
(function ($) {
	'use strict';
	if (window.dzeHzoomBound) { return; }
	window.dzeHzoomBound = true;

	var $pop = null;

	$(document).on('mouseenter', 'img.dze-hzoom', function () {
		var src = $(this).data('full') || this.src;
		if (!src) { return; }
		$pop = $('<div class="dze-hzoom-pop"></div>').append($('<img />').attr('src', src).attr('alt', '')).appendTo('body');
	});
	$(document).on('mousemove', 'img.dze-hzoom', function (e) {
		if (!$pop) { return; }
		// Flip to the other side of the cursor when the popup would fall off.
		var w = 360, h = 360;
		var left = (e.clientX + w + 30 > $(window).width()) ? e.clientX - w - 20 : e.clientX + 20;
		var top = Math.max(10, Math.min(e.clientY - h / 2, $(window).height() - h - 10));
		$pop.css({ left: left + 'px', top: top + 'px' });
	});
	$(document).on('mouseleave', 'img.dze-hzoom', function () {
		if ($pop) { $pop.remove(); $pop = null; }
	});
}(jQuery));
