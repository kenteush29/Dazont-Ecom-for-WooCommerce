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
	});

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

		var total = refs.length, done = 0, waiting = 0, spent = 0;
		$btn.prop('disabled', true);
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
			if (!refs.length) {
				$btn.prop('disabled', false);
				$('#dze-tr-progstep').text('');
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

	function draw($cell, ref, d) {
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

	$(document).on('click', '.dze-tr-open', function () {
		var $row = $(this).closest('.dze-tr-wrow');
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
		var $row = $(this).closest('.dze-tr-wrow');
		if (!$row.length) {
			$row = $('.dze-tr-wrow[data-ref="' + $(this).closest('.dze-tr-panel').data('ref') + '"]');
		}
		var ref = $row.data('ref');
		if (!window.confirm(i18n.confirmNo)) { return; }
		post('dze_tr_decide', { ref: ref, how: 'refuse' }).done(function (r) {
			if (!r || !r.success) { window.alert(said(r)); return; }
			$('.dze-tr-panel[data-ref="' + ref + '"]').remove();
			$row.remove();
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
			// A ROW MENDED BY HALF STAYS, and says where it now stands: a row
			// that vanished on any decision would be a list that lies.
			if (r.data.left > 0) {
				$state.text(sprintf(i18n.someLeft, r.data.left));
				return;
			}
			$state.text(i18n.saved);
			$('.dze-tr-wrow[data-ref="' + ref + '"]').remove();
			$panel.remove();
		}).fail(function () {
			$btn.prop('disabled', false);
			$state.text(i18n.error);
		});
	});
}(jQuery));
