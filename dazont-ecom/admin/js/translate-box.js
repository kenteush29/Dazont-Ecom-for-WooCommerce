/**
 * "Translate with Dazont Ecom", planted inside WPML's own Language box.
 *
 * The box WPML already prints on an edit screen is where somebody goes to
 * think about languages, so that is where the button belongs — never a meta
 * box of our own beside it.
 *
 * It OPENS the work, it never runs it: on a product the popup that is already
 * on the page, on everything else the Translations screen with this one object
 * ticked. A control that spends money the moment it is pressed is the fault
 * this plugin has paid for twice.
 */
(function ($) {
	'use strict';

	var cfg = window.dzeTrBox || {};
	if (!cfg.label) { return; }

	// WPML's markup is WPML's. Ask for the containers it is known by, in the
	// order a language box is actually built, and fall back to WordPress's own
	// Publish box — which every edit screen has — rather than giving up
	// quietly, because a control that disappears is a function that does not
	// exist.
	function home() {
		var tries = ['#icl_div .inside', '#icl_div', '.wpml-translation-editor-box .inside',
			'#icl_document_translations', '.icl_box_paragraph'];
		for (var i = 0; i < tries.length; i++) {
			var $x = $(tries[i]).first();
			if ($x.length) { return $x; }
		}
		var $side = $('#submitdiv .inside, #submitdiv').first();
		return $side.length ? $side : $('#edittag, #post').first();
	}

	$(function () {
		var $where = home();
		if (!$where.length || $('#dze-tr-box').length) { return; }
		var $p = $('<p id="dze-tr-box" class="dze-tr-box"></p>');
		// ONE OPENER FOR THESE POPUPS, and it is the hub's. The class and the
		// data attribute ARE the gesture: the handler lives in one place and a
		// button written next year needs to know nothing.
		var $b = cfg.popup
			? $('<button type="button" class="button dze-hub-btn" data-modal="dze-tr-modal"></button>')
			: $('<a class="button"></a>').attr('href', cfg.url || '#');
		$b.text(cfg.label).attr('title', cfg.tip || '');
		$p.append($b);
		$where.append($p);
	});
})(jQuery);
