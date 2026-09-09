/* global jQuery */
/**
 * THE SHELL EVERY DAZONT SCREEN IS BUILT FROM.
 *
 * "Je veux que cette méthode soit la seule méthode standardisée sur tout le
 * shop : une façon de faire, avec différentes fonctions en fonction du type de
 * post... la mise à jour doit être popularisée aussi sur les autres types de
 * post."
 *
 * What is common to every screen that offers work lives here and nowhere else:
 * the collapsible blocks with their switch and their count, and the before /
 * after. What each screen puts INSIDE a block is its own business — a product
 * has photographs and a price, a category has a description and its links.
 *
 * It used to live in `photos.js`, which is the product photograph renderer, so
 * a category screen could only have blocks by loading the whole of the product
 * image code. One shape, one machinery, every screen; a fix here is a fix on
 * all of them, which is the whole point of it being here.
 */
(function ($) {
	'use strict';

	var Hub = window.dzeHub = window.dzeHub || {};

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

	/**
	 * One block: a heading carrying its own switch and its count, and a body.
	 *
	 * `tick` is the switch: { all: true } for a take-all over the boxes below,
	 * or { id, on, tip } for a block that is one thing. The PHP screens build
	 * the same markup in DZE_Content::sec_open() — same classes, same order —
	 * so this machinery drives both.
	 */
	Hub.sec = function (id, title, open, body, tick) {
		var box = tick
			? '<label class="dze-sec-tick" title="' + esc(tick.tip || '') + '">' +
				'<input type="checkbox"' + (tick.id ? ' id="' + tick.id + '"' : '') +
				(tick.all ? ' class="dze-sec-all"' : '') + (tick.on ? ' checked' : '') +
				(tick.disabled ? ' disabled' : '') + ' /></label>'
			: '';
		return '<section class="dze-sec' + (open ? ' is-open' : '') + '" data-sec="' + id + '">' +
			'<h3 class="dze-sec-head" role="button" tabindex="0" aria-expanded="' + (open ? 'true' : 'false') + '">' +
				'<span class="dze-sec-caret">' + (open ? '▾' : '▸') + '</span>' + box + esc(title) +
				'<span class="dze-sec-count"></span>' +
			'</h3>' +
			'<div class="dze-sec-body"' + (open ? '' : ' style="display:none;"') + '>' + body + '</div>' +
		'</section>';
	};

	/**
	 * Before and after, as two readable documents.
	 *
	 * BOTH of them: the category panel printed only the before and put the
	 * after's figures in its heading, so a screen where the new text lands
	 * somewhere else showed the old one and a nought. One renderer, so no screen
	 * can print half of it again.
	 *
	 * @param {object} d {before, after, words:[a,b], links:[a,b]}
	 * @param {object} w what each side is called, and what an empty one says.
	 */
	Hub.diff = function (d, w) {
		var line = function (label, count, html, empty) {
			return '<div class="dze-cb-nowtext">' +
				'<span class="dze-cb-nowlabel">' + esc(label) + ' — ' + esc(count) + '</span>' +
				'<div class="dze-cb-nowbody">' + (html || '<p>' + esc(empty) + '</p>') + '</div>' +
			'</div>';
		};
		var said = function (i) { return w.wl.replace('%1$s', d.words[i]).replace('%2$s', d.links[i]); };
		var made = d.words[1] || d.links[1] || (d.after || '').replace(/<[^>]*>/g, '').trim();
		return {
			html: line(w.before, said(0), d.before, w.wasEmpty) +
				line(w.after, made ? said(1) : w.none, d.after, w.none),
			// What the heading says: the state of what you would keep.
			said: made ? said(1) : w.none
		};
	};

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
	$(document).on('click', '.dze-sec-head', function (e) {
		// The tick in a heading is the block's own switch, not a way of opening
		// it: pressing it must not fold the section under your hand.
		if ($(e.target).closest('.dze-sec-tick').length) { return; }
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
			// A count of what the run will DO. An OPTION of a function is not
			// one of the things it does: "Keep the product's own photograph as
			// the subject" made the images section read 1 / 2 when there was
			// one photograph to make. A checkbox that only changes HOW
			// something runs carries .dze-sec-opt and is not counted.
			return !$(this).closest('.dze-rf-tools, .dze-rf-out, .dze-sec-opt').length;
		});
		var total = $boxes.length;
		var on = $boxes.filter(':checked').length;
		// A BLOCK WHOSE WORK IS ROWS, NOT TICKS. The photographs block is one
		// switch and a list of prompt rows; counting its checkboxes counted
		// the switch and said "1 / 1" whatever was laid out under it. What it
		// will DO is how many photographs those rows ask for.
		var $rows = $sec.find('> .dze-sec-body .dze-tplrow');
		if ($rows.length) {
			total = 0;
			$rows.each(function () {
				total += Math.max(1, parseInt($(this).find('.dze-tpl-n').val(), 10) || 1);
			});
			on = $sec.find('> .dze-sec-head .dze-sec-tick input').is(':checked') ? total : 0;
		}
		$sec.find('> .dze-sec-head > .dze-sec-count').text(total ? (on + ' / ' + total) : '')
			.toggleClass('is-on', on > 0);
	}
	/**
	 * The heading's own tick, kept in step with the boxes under it.
	 *
	 * A block whose switch says "on" while half its prompts are unticked is a
	 * screen that lies, so a partly-ticked block shows the browser's own
	 * in-between mark rather than a bare tick.
	 */
	function syncAll($sec) {
		var $all = $sec.find('> .dze-sec-head .dze-sec-all');
		if (!$all.length) { return; }
		var $boxes = $sec.find('> .dze-sec-body input[type=checkbox]').filter(function () {
			return !$(this).closest('.dze-rf-tools, .dze-rf-out, .dze-sec-opt').length;
		});
		var on = $boxes.filter(':checked').length;
		$all.prop('checked', $boxes.length > 0 && on === $boxes.length);
		$all.prop('indeterminate', on > 0 && on < $boxes.length);
	}
	$(document).on('change', '.dze-sec-all', function () {
		var $sec = $(this).closest('.dze-sec');
		var on = $(this).is(':checked');
		$sec.find('> .dze-sec-body input[type=checkbox]').filter(function () {
			return !$(this).closest('.dze-rf-tools, .dze-rf-out, .dze-sec-opt').length;
		}).prop('checked', on).trigger('change');
		countSec($sec);
	});
	function countAll() {
		$('.dze-sec').each(function () { countSec($(this)); syncAll($(this)); });
	}
	$(document).on('change', '.dze-sec-body input[type=checkbox]', function () {
		var $sec = $(this).closest('.dze-sec');
		countSec($sec);
		syncAll($sec);
	});
	// The heading's own switch changes what the block will do, so the figure
	// beside it follows — and so does a row's "how many".
	$(document).on('change', '.dze-sec-tick input, .dze-tpl-n', function () {
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
	Hub.toggleSec = toggleSec;
	Hub.count = countAll;
}(jQuery));
