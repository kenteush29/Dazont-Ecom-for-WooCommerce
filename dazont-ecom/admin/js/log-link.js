/* global jQuery */
/**
 * Every failure this plugin puts on screen carries a way to the log.
 *
 * A message that says what broke is half the answer; the other half is what
 * the service actually replied, when, and how often — and that is written down
 * under Settings → Health. Asking the owner to remember that page exists, and
 * to find it while he is in the middle of something, is asking him to give up.
 * So the link is where the failure is.
 *
 * It is added by watching for the class every failure in this plugin already
 * carries — `is-ko` — rather than by touching the hundred places that set one.
 * A hundred call sites is a hundred chances to forget it, and the one that is
 * forgotten is the one somebody needed.
 *
 * The link is appended once per element: writing a new message into that
 * element wipes it, and it comes straight back.
 */
(function ($) {
	'use strict';

	var cfg = window.dzeLogLink || {};
	if (!cfg.url) { return; }

	function tag(el) {
		if (!el || 1 !== el.nodeType) { return; }
		var $el = $(el);
		if (!$el.hasClass('is-ko')) { return; }
		if ($el.find('.dze-logl').length) { return; }
		// No message yet: an empty red box has nothing to explain.
		if (!String( $el.text() ).trim()) { return; }
		$el.append(
			$('<a class="dze-logl"></a>')
				.attr({ href: cfg.url, target: '_blank', rel: 'noopener noreferrer', title: cfg.title || '' })
				.text(cfg.label || 'log ↗')
		);
	}

	function sweep(root) {
		tag(root);
		$(root).find('.is-ko').each(function () { tag(this); });
	}

	$(function () {
		sweep(document.body);
		if (!window.MutationObserver) { return; }
		new window.MutationObserver(function (records) {
			for (var i = 0; i < records.length; i++) {
				var r = records[i];
				// A message written into an element that is already failing is
				// a childList change on that element — the text node is
				// replaced, and our link with it.
				tag(r.target);
				for (var j = 0; j < r.addedNodes.length; j++) {
					sweep(r.addedNodes[j]);
				}
			}
		}).observe(document.body, {
			subtree: true,
			childList: true,
			attributes: true,
			attributeFilter: ['class']
		});
	});
}(jQuery));
