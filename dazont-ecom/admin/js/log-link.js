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
		// A message, never a BADGE: the mark that hides a long explanation is
		// sixteen pixels across, and a link appended inside it ran across the
		// sentence beside it.
		if ($el.hasClass('dze-why') || $el.closest('.dze-why').length) { return; }
		// Nor on a LANGUAGE block. A feed that Google refused draws the same
		// badge the rest of the plugin draws — a flag, its code, a cross —
		// and the badge already carries what Google said, on its own tooltip.
		// A link bolted onto it read "FR ✗ see the log ↗" on the events
		// screen: three words of ours beside two characters of state, on a
		// row that can carry five languages.
		if ($el.hasClass('dze-lang') || $el.closest('.dze-lang').length) { return; }
		if ($el.find('.dze-logl').length) { return; }
		// No message yet: an empty red box has nothing to explain.
		if (!String( $el.text() ).trim()) { return; }
		linkTabs(el);
		$el.append(
			$('<a class="dze-logl"></a>')
				.attr({ href: cfg.url, target: '_blank', rel: 'noopener noreferrer', title: cfg.title || '' })
				.text(cfg.label || 'log ↗')
		);
	}

	// ---- A SCREEN NAMED IN A MESSAGE IS A WAY TO THAT SCREEN ----
	//
	// "Ici tu vas aussi ajouter directement le lien vers settings." Nine
	// messages in this plugin end in "raise it under Settings → General", and
	// every one of them left the reader to go and find that page. The link is
	// put on THE WORDS THAT NAME IT — never a "click here" added at the end —
	// and it is done here, once, by reading the tabs the server hands over,
	// rather than in the ninety places that write such a sentence.
	//
	// Only OUR tabs are matched, so "Klaviyo → Settings → API keys" and
	// "WPML → Settings → Custom Fields Translation" stay plain text: they name
	// another product's screen, which this plugin cannot open. The arrow in
	// front is the test for that.
	var TABS = (function () {
		var map = cfg.settings || {}, out = [];
		for (var name in map) {
			if (Object.prototype.hasOwnProperty.call(map, name) && name && map[name]) {
				out.push({ name: String(name), url: String(map[name]) });
			}
		}
		// Longest first: "General" must not win inside a longer tab name.
		return out.sort(function (a, b) { return b.name.length - a.name.length; });
	}());

	function linkTabs(el) {
		if (!TABS.length || !el) { return; }
		var pre = String(cfg.prefix || 'Settings') + ' \u2192 ';
		var walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
		var nodes = [], n;
		while ((n = walker.nextNode())) {
			// A stretch already linked is left alone — by us or by anybody.
			if (!$(n.parentNode).closest('a').length) { nodes.push(n); }
		}
		for (var i = 0; i < nodes.length; i++) {
			var node = nodes[i], text = node.nodeValue || '';
			for (var t = 0; t < TABS.length; t++) {
				var phrase = pre + TABS[t].name;
				var at = text.indexOf(phrase);
				if (at < 0) { continue; }
				// "Klaviyo → Settings → …" is another product's page.
				if (at >= 2 && '\u2192 ' === text.slice(at - 2, at)) { continue; }
				var a = document.createElement('a');
				a.className = 'dze-setl';
				a.href = TABS[t].url;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				a.title = cfg.setTitle || '';
				a.textContent = phrase;
				var tail = node.splitText(at);
				tail.nodeValue = tail.nodeValue.slice(phrase.length);
				tail.parentNode.insertBefore(a, tail);
				break; // one node, one link: the sentence says it once.
			}
		}
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
