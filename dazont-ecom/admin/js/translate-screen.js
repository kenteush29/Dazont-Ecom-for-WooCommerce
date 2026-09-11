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
						$('tr[data-ref="' + ref + '"]').not('.dze-tr-panel')
							.find('.dze-tr-openword').text(i18n.review);
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
	// The reading
	// =====================================================================

	function fieldBlock(lang, fid, label, source, made, current) {
		var id = 'dze-tr-f-' + lang + '-' + fid;
		return '<div class="dze-cb-fblock" data-lang="' + esc(lang) + '" data-field="' + esc(fid) + '">' +
			'<div class="dze-cb-fhead">' +
				'<label class="dze-cb-check" title="' + esc(i18n.keepHelp) + '">' +
					'<input type="checkbox" class="dze-tr-keep" checked /> <span>' + esc(label) + '</span>' +
				'</label>' +
			'</div>' +
			'<div class="dze-cb-fbody">' +
				'<p class="description">' + esc(i18n.source) + '</p>' +
				'<pre class="dze-tr-was">' + esc(source || i18n.empty) + '</pre>' +
				'<p class="description">' + esc(i18n.current) + '</p>' +
				'<pre class="dze-tr-now">' + esc(current || i18n.empty) + '</pre>' +
				'<textarea id="' + esc(id) + '" class="dze-tr-new" rows="6">' + esc(made) + '</textarea>' +
			'</div>' +
		'</div>';
	}

	// WHAT THE OBJECT HOLDS TODAY, when nothing is waiting on it. A PANEL
	// HOLDING NOTHING OFFERS NEITHER ACCEPT NOR REFUSE: a control that cannot
	// act is a control nobody trusts.
	function lookPanel($cell, d) {
		var html = '<div class="dze-tr-panelbox">';
		html += '<p><strong>' + esc(d.label) + '</strong> ';
		if (d.edit) {
			html += '<a href="' + esc(d.edit) + '" target="_blank" rel="noopener">' + esc(i18n.open) + ' &rarr;</a>';
		}
		html += '</p>';
		html += '<div class="dze-tr-lblock"><h3>' + esc(i18n.source) + '</h3>';
		var got = false;
		$.each(d.source || {}, function (fid, text) {
			got = true;
			html += '<p class="dze-cb-nowlabel">' + esc((d.labels || {})[fid] || fid) + '</p>' +
				'<pre class="dze-tr-was">' + esc(text) + '</pre>';
		});
		if (!got) { html += '<p class="description">' + esc(i18n.empty) + '</p>'; }
		html += '</div>';
		$.each(d.langs, function (code, one) {
			html += '<div class="dze-tr-lblock" data-lang="' + esc(code) + '">';
			html += '<h3>' + esc(one.name) + ' (' + esc(String(code).toUpperCase()) + ')</h3>';
			if (!one.exists) {
				html += '<p class="description">' + esc(i18n.holdsNone) + '</p>';
			} else {
				if (one.edit) {
					html += '<p><a href="' + esc(one.edit) + '" target="_blank" rel="noopener">' + esc(i18n.open) + ' &rarr;</a></p>';
				}
				$.each(one.current || {}, function (fid, text) {
					html += '<p class="dze-cb-nowlabel">' + esc((d.labels || {})[fid] || fid) + '</p>' +
						'<pre class="dze-tr-now">' + esc(text) + '</pre>';
				});
			}
			html += '</div>';
		});
		$cell.html(html + '</div>');
	}

	function draw($cell, ref, d) {
		if (d.look) { lookPanel($cell, d); return; }
		var html = '<div class="dze-tr-panelbox">';
		html += '<p><strong>' + esc(d.label) + '</strong> ';
		if (d.edit) {
			html += '<a href="' + esc(d.edit) + '" target="_blank" rel="noopener">' + esc(i18n.open) + ' &rarr;</a>';
		}
		html += '</p>';
		$.each(d.langs, function (code, one) {
			html += '<div class="dze-tr-lblock" data-lang="' + esc(code) + '">';
			html += '<h3>' + esc(one.name) + ' (' + esc(String(code).toUpperCase()) + ')</h3>';
			if (!one.exists) {
				html += '<p class="description">' + esc(i18n.willCreate) + '</p>';
			} else if (!one.mine) {
				html += '<div class="notice notice-warning inline"><p>' + esc(i18n.notMine) + '</p></div>';
			}
			$.each(one.texts, function (fid, made) {
				html += fieldBlock(code, fid, (d.labels || {})[fid] || fid,
					(d.source || {})[fid], made, (one.current || {})[fid]);
			});
			html += '</div>';
		});
		html += '<p class="dze-cb-panelbar">' +
			'<button type="button" class="button button-primary dze-tr-accept">' + esc(i18n.accept) + '</button> ' +
			'<button type="button" class="button dze-tr-refuse">' + esc(i18n.refuse) + '</button>' +
			'<span class="dze-cb-panelstate"></span>' +
		'</p></div>';
		$cell.html(html);
	}

	// ONE HANDLER FOR BOTH LISTS. The batch screen opens the same panel on the
	// same markup — "Look" for the object as it stands, "Review" once something
	// waits — so the row is found by what it CARRIES (a ref) rather than by
	// which of the two screens it happens to be on.
	$(document).on('click', '.dze-tr-open', function () {
		var $row = $(this).closest('tr[data-ref]');
		var ref = $row.data('ref');
		var $panel = $('.dze-tr-panel[data-ref="' + ref + '"]');
		if ($panel.is(':visible')) { $panel.hide(); return; }
		var $cell = $panel.find('td').first();
		$cell.html('<p class="description">' + esc(i18n.loading) + '</p>');
		$panel.show();
		post('dze_tr_panel', { ref: ref }).done(function (r) {
			if (!r || !r.success) { $cell.html('<p class="notice notice-error">' + esc(said(r)) + '</p>'); return; }
			draw($cell, ref, r.data);
		}).fail(function () {
			$cell.html('<p class="notice notice-error">' + esc(i18n.error) + '</p>');
		});
	});

	// Refuse, from the row and from inside the panel alike: the group form of
	// a row button, never a second path.
	$(document).on('click', '.dze-tr-refuse', function () {
		var $row = $(this).closest('tr[data-ref]').not('.dze-tr-panel');
		if (!$row.length) {
			$row = $('tr[data-ref="' + $(this).closest('.dze-tr-panel').data('ref') + '"]').not('.dze-tr-panel');
		}
		var ref = $row.data('ref');
		if (!window.confirm(i18n.confirmNo)) { return; }
		post('dze_tr_decide', { ref: ref, how: 'refuse' }).done(function (r) {
			if (!r || !r.success) { window.alert(said(r)); return; }
			// ON THE REVIEW LIST the row IS the waiting work, so it goes. On
			// the batch list the object is still there and still translatable:
			// the panel closes and the row goes back to "Look".
			$('.dze-tr-panel[data-ref="' + ref + '"]').hide().find('td').first().empty();
			if ($row.hasClass('dze-tr-wrow')) { $row.remove(); return; }
			$row.find('.dze-tr-openword').text(i18n.look);
		});
	});

	$(document).on('click', '.dze-tr-accept', function () {
		var $btn = $(this);
		var $panel = $btn.closest('.dze-tr-panel');
		var ref = $panel.data('ref');
		var keep = {};
		var any = false;
		$panel.find('.dze-cb-fblock').each(function () {
			var $b = $(this);
			if (!$b.find('.dze-tr-keep').is(':checked')) { return; }
			var lang = String($b.data('lang'));
			var fid = String($b.data('field'));
			keep[lang] = keep[lang] || {};
			keep[lang][fid] = $b.find('.dze-tr-new').val();
			any = true;
		});
		if (!any) { window.alert(i18n.tickFirst); return; }
		var $state = $panel.find('.dze-cb-panelstate');
		$btn.prop('disabled', true);
		$state.text(i18n.saving);
		post('dze_tr_decide', { ref: ref, how: 'accept', keep: keep }).done(function (r) {
			$btn.prop('disabled', false);
			if (!r || !r.success) { $state.text(said(r)); return; }
			// WHAT WAS WRITTEN, AND WHAT IS STILL WRONG WITH IT. "Written ✓"
			// over a variable product WooCommerce Multilingual has not built
			// the variations for is a page that renders as unavailable, with
			// the screen saying nothing about it.
			var warn = [];
			$.each(r.data.warnings || {}, function (lang, text) {
				warn.push(String(lang).toUpperCase() + ' — ' + text);
			});
			if (warn.length) {
				$panel.find('.dze-cb-panelbar').before(
					'<div class="notice notice-warning inline"><p>' + esc(warn.join(' ')) + '</p></div>');
			}
			// A ROW MENDED BY HALF STAYS, and says where it now stands: a row
			// that vanished on any decision would be a list that lies.
			if (r.data.left > 0) {
				$state.text(sprintf(i18n.someLeft, r.data.left));
				return;
			}
			$state.text(i18n.saved);
			var $r = $('tr[data-ref="' + ref + '"]').not('.dze-tr-panel');
			if ($r.hasClass('dze-tr-wrow')) { $r.remove(); $panel.remove(); return; }
			// The batch list keeps its row: the object is still there, it is
			// simply no longer holding anything to decide.
			$r.find('.dze-tr-openword').text(i18n.look);
			$panel.hide().find('td').first().empty();
		}).fail(function () {
			$btn.prop('disabled', false);
			$state.text(i18n.error);
		});
	});
}(jQuery));
