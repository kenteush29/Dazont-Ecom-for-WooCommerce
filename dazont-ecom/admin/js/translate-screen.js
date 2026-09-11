/**
 * Dazont Ecom → WPML Translations: the batch, and the reading before it lands.
 *
 * Two presses and nothing else. "Translate the ticked" walks the list ONE
 * OBJECT AT A TIME — a run of forty products in one request either works or
 * times out, and says nothing while it decides which — so the bar can say
 * where it is. "Review" opens the object where it stands, beside what came
 * back and beside what the translation holds today; accept writes the ticked
 * fields and refuse throws the lot away.
 *
 * Every word on this screen comes from PHP: hard-coded here they were English
 * on every shop.
 */
(function ($) {
	'use strict';
	var cfg = window.dzeTrScreen || {};
	var i18n = cfg.i18n || {};

	function esc(s) {
		return $('<div/>').text(s == null ? '' : String(s)).html();
	}
	// A MISSING WORD IS NOT WORTH KILLING A HANDLER FOR: an unregistered
	// string used to throw on .replace and stop every line after it.
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str == null ? '' : str).replace(/%(\d+)\$s|%s/g, function (m, n) {
			return n ? String(args[parseInt(n, 10) - 1]) : String(args[i++]);
		});
	}
	function post(action, data) {
		return $.post(cfg.ajaxUrl, $.extend({ action: action, nonce: cfg.nonce }, data || {}));
	}
	function said(r) {
		return (r && r.data && r.data.message) ? r.data.message : i18n.error;
	}

	// =====================================================================
	// The batch
	// =====================================================================

	$(document).on('change', '#dze-tr-all', function () {
		$('.dze-tr-pickone').prop('checked', this.checked);
		bill();
	});
	$(document).on('click', '#dze-tr-selall', function () {
		$('.dze-tr-pickone, #dze-tr-all').prop('checked', true);
		bill();
	});
	$(document).on('click', '#dze-tr-selnone', function () {
		$('.dze-tr-pickone, #dze-tr-all').prop('checked', false);
		bill();
	});
	$(document).on('change', '.dze-tr-pickone, .dze-tr-lang', bill);

	// WHAT THE PRESS IS ABOUT TO DO, BESIDE THE PRESS. Every figure was already
	// on the screen — the ticked rows, the ticked languages — and they had
	// never been multiplied: a button reading "Translate" over forty rows and
	// five languages is two hundred calls nobody counted.
	function bill() {
		var rows = $('.dze-tr-pickone:checked').length;
		var langs = $('.dze-tr-lang:checked').length;
		var $b = $('#dze-tr-bill');
		if (!$b.length) { return; }
		$('#dze-tr-selcount').text(sprintf(i18n.nSelected, rows));
		$b.text(rows && langs ? sprintf(i18n.bill, rows, langs, rows * langs) : i18n.billNone);
	}
	$(bill);

	// WPML'S OWN GESTURE, ONE LANGUAGE AT A TIME: the plus makes the missing
	// translation, the arrows bring an out-of-date one back. It runs the SAME
	// job the batch button runs — one object, one language — because a second
	// engine beside it is how two screens start disagreeing.
	$(document).on('click', '.dze-tr-one', function () {
		var $b = $(this).prop('disabled', true);
		var $row = $b.closest('.dze-tr-row');
		var ref = $row.data('ref'), lang = String($b.data('lang') || '');
		if (!ref || !lang) { $b.prop('disabled', false); return; }
		var was = $b.html();
		$b.html(esc(i18n.sending));
		post('dze_tr_batch', { ref: ref, langs: [lang] }).done(function (r) {
			if (r && r.success) {
				var n = (r.data.done || []).length;
				$b.replaceWith('<span class="dze-tr-chip is-' + (n ? 'held' : 'done') + '">' +
					esc(n ? i18n.rowHeld : i18n.rowNothing) + '</span>');
				if (n) { $row.find('.dze-tr-openword').text(i18n.review); }
				if (n) { $('#dze-tr-sendstate').html(esc(sprintf(i18n.sent, 1)) +
					(cfg.reviewUrl ? ' <a href="' + esc(cfg.reviewUrl) + '">' + esc(i18n.goReview) + ' &rarr;</a>' : '')); }
				return;
			}
			$b.prop('disabled', false).html(was);
			$('#dze-tr-sendstate').text(said(r));
		}).fail(function () {
			$b.prop('disabled', false).html(was);
			$('#dze-tr-sendstate').text(i18n.error);
		});
	});

	$(document).on('click', '#dze-tr-send', function () {
		var $btn = $(this);
		var langs = $('.dze-tr-lang:checked').map(function () { return $(this).val(); }).get();
		var refs = $('.dze-tr-pickone:checked').map(function () {
			return $(this).closest('.dze-tr-row').data('ref');
		}).get();
		if (!langs.length) { window.alert(i18n.langFirst); return; }
		if (!refs.length) { window.alert(i18n.tickFirst); return; }

		var total = refs.length, done = 0, waiting = 0, spent = 0, stop = false;
		$btn.prop('disabled', true);
		// A RUN ON FORTY ROWS MUST BE STOPPABLE, like every other long press in
		// this plugin: it walks one object at a time, so stopping costs nothing
		// and leaves what has already come back exactly where it is.
		$('#dze-tr-stop').show().prop('disabled', false).off('click.dzetr').on('click.dzetr', function () {
			stop = true;
			$(this).prop('disabled', true);
		});
		$('#dze-tr-prog').show();
		$('#dze-tr-sendstate').text(i18n.sending);

		// WHAT HAPPENED TO **THIS** ROW, on the row itself. A batch that
		// finished and left every line exactly as it was is a press nobody can
		// tell worked: "Rien à jour sur la page. La je ne comprends pas quoi
		// faire en fait."
		function mark(ref, state, said) {
			$('.dze-tr-row').filter(function () { return String($(this).data('ref')) === String(ref); })
				.find('.dze-tr-state')
				.html('<span class="dze-tr-chip is-' + esc(state) + '">' + esc(said) + '</span>');
		}

		function step() {
			if (stop && refs.length) { refs.length = 0; }
			if (!refs.length) {
				$btn.prop('disabled', false);
				$('#dze-tr-stop').hide();
				$('#dze-tr-progstep').text(stop ? i18n.stopped : '');
				// A RUN THAT SPENT NOTHING SAYS SO. "Nothing had moved" and
				// "it failed" must never read the same — and a run that DID
				// produce something offers the way to it rather than naming a
				// tab and leaving the reader to find it.
				if (spent) {
					$('#dze-tr-sendstate').html(
						esc(sprintf(i18n.sent, waiting)) +
						(cfg.reviewUrl ? ' <a href="' + esc(cfg.reviewUrl) + '">' + esc(i18n.goReview) + ' &rarr;</a>' : '')
					);
				} else {
					$('#dze-tr-sendstate').text(i18n.nothingNew);
				}
				return;
			}
			var ref = refs.shift();
			post('dze_tr_batch', { ref: ref, langs: langs }).done(function (r) {
				done++;
				if (r && r.success) {
					var n = (r.data.done || []).length;
					if (n) { spent++; waiting++; }
					mark(ref, n ? 'held' : 'done', n ? i18n.rowHeld : i18n.rowNothing);
					// AND THE ROW'S OWN BUTTON SAYS WHAT IT NOW OPENS ON: Look
					// for the object as it stands, Review once there is work
					// waiting for a decision. A screen that reacts to its own
					// work is the rule, not a nicety.
					if (n) {
						$('tr[data-ref="' + ref + '"]').find('.dze-tr-openword').text(i18n.review);
					}
					$('#dze-tr-progstep').text(r.data.label || '');
				} else {
					mark(ref, 'missing', said(r));
					$('#dze-tr-progstep').text(said(r));
				}
			}).fail(function () {
				done++;
				mark(ref, 'missing', i18n.error);
				$('#dze-tr-progstep').text(i18n.error);
			}).always(function () {
				var pct = Math.round((done / total) * 100);
				$('#dze-tr-prog .dze-cb-fill').css('width', pct + '%');
				$('#dze-tr-progcount').text(sprintf(i18n.stepN, done, total));
				step();
			});
		}
		step();
	});

	// =====================================================================
	// THE TRANSLATION EDITOR — one screen per object, three presses
	//
	// "Je veux un seul écran pour chaque type de post. Comme le fait wpml !"
	// Translate it, read it field by field, save it. What stood here before was
	// a panel that unfolded inside a row AND a popup of ours on the product
	// page: two per-object surfaces for one job, which is how two screens start
	// disagreeing about one object and one of them loses text.
	// =====================================================================

	function editor() { return $('.dze-tr-editor'); }

	// 1. TRANSLATE IT. Only what has moved is sent — the module's whole value —
	// so a run that pays for nothing says so instead of looking broken.
	$(document).on('click', '#dze-tr-auto', function () {
		var $e = editor();
		if (!$e.length) { return; }
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-tr-autostate').removeClass('is-ko').text(i18n.sending);
		post('dze_tr_batch', { ref: $e.data('ref'), langs: [String($e.data('lang'))] })
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text(said(r)); return; }
				var texts = (r.data.texts || {})[String($e.data('lang'))] || {};
				var n = 0;
				$.each(texts, function (fid, text) {
					var $row = $e.find('.dze-tr-field[data-field="' + fid + '"]');
					if (!$row.length) { return; }
					$row.find('.dze-tr-new').val(text);
					n++;
				});
				// NOTHING MOVED IS AN ANSWER, and it is not a failure: WPML asks
				// again whenever a category is renamed, and this is the module
				// saying it cost nothing.
				$st.text(n ? sprintf(i18n.filled, n) : i18n.nothingNew);
			})
			.fail(function () { $b.prop('disabled', false); $st.addClass('is-ko').text(i18n.error); });
	});

	// 2. SAVE IT. What is on screen is what travels — never what the database
	// held when the page was opened.
	$(document).on('click', '#dze-tr-publish', function () {
		var $e = editor();
		if (!$e.length) { return; }
		var lang = String($e.data('lang'));
		var keep = {};
		keep[lang] = {};
		var any = false;
		$e.find('.dze-tr-field').each(function () {
			var $r = $(this);
			var v = String($r.find('.dze-tr-new').val() || '');
			if (!v.length) { return; }
			keep[lang][String($r.data('field'))] = v;
			any = true;
		});
		var $st = $('#dze-tr-publishstate').removeClass('is-ko');
		if (!any) { $st.addClass('is-ko').text(i18n.nothingToSave); return; }
		var $b = $(this).prop('disabled', true);
		$st.text(i18n.saving);
		post('dze_tr_decide', { ref: $e.data('ref'), how: 'accept', keep: keep })
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text(said(r)); return; }
				// WHAT WAS WRITTEN, AND WHAT IS STILL WRONG WITH IT. A variable
				// product whose variations WooCommerce Multilingual has not
				// built renders as unavailable, and the screen that just said
				// "Saved" must say that too.
				var warn = [];
				$.each(r.data.warnings || {}, function (code, text) { warn.push(text); });
				$e.find('.dze-tr-warn').remove();
				if (warn.length) {
					$e.find('.dze-cb-panelbar').before(
						'<div class="notice notice-warning inline dze-tr-warn"><p>' + esc(warn.join(' ')) + '</p></div>');
				}
				// THE STATE LINE IS THE LAST THING TO CHANGE, so it is a
				// truthful signal that the press is finished: set first, a gate
				// waiting on it reads a screen still working — and passes by
				// accident of timing, which is what a vacuous wait looks like.
				$st.text(i18n.saved);
			})
			.fail(function () { $b.prop('disabled', false); $st.addClass('is-ko').text(i18n.error); });
	});

	// 3. OR THROW IT AWAY. The translation is left exactly as it is.
	$(document).on('click', '#dze-tr-drop, .dze-tr-refuse', function () {
		var $e = editor();
		var ref = $e.length ? $e.data('ref') : $(this).closest('tr[data-ref]').data('ref');
		if (!ref || !window.confirm(i18n.confirmNo)) { return; }
		var $st = $('#dze-tr-publishstate');
		post('dze_tr_decide', { ref: ref, how: 'refuse' })
			.done(function (r) {
				if (!r || !r.success) { window.alert(said(r)); return; }
				if ($e.length) { $st.text(i18n.dropped); return; }
				$('tr[data-ref="' + ref + '"]').remove();
			});
	});
}(jQuery));
