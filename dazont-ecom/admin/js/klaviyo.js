/* global dzeKlav, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeKlav;
	var i18n = cfg.i18n;

	// ---- Settings: read the account, fill the pickers ----
	$(document).on('click', '#dze-klav-refresh', function () {
		var $b = $(this), $m = $('#dze-klav-refresh-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.loading);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_load', nonce: cfg.nonce })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				fill($('#dze-klav-inc'), res.data.audiences);
				fill($('#dze-klav-exc'), res.data.audiences);
				fill($('#dze-klav-th'), res.data.templates);
				$('#dze-klav-tpl-hint').text(cfg.i18n.pickedFrom);
				// The name saved beside each id is what the screen falls back
				// to once this list goes stale again, so it is kept in step
				// here and whenever the choice changes below.
				names();
				inactive = res.data.inactive || [];
				tools();
				$m.css('color', res.data.partial ? '#b26a00' : '#0a7040').text(res.data.message);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
			});
	});

	// Which exclusions Klaviyo is not maintaining, so the page can offer to
	// switch the chosen one back on instead of leaving it silently empty.
	var inactive = (cfg.inactive || []);

	// The chosen list, segment and template are settings; the menus they were
	// picked from are a cache. So the LABEL travels with the id in a hidden
	// field, and the screen never has to answer "RpAZid" because a twelve-hour
	// cache happened to expire.
	function names() {
		$.each({ '#dze-klav-inc': 'included', '#dze-klav-exc': 'excluded' }, function (sel, key) {
			var $s = $(sel);
			if (!$s.length) { return; }
			var text = String( $s.find('option:selected').text() ).trim();
			$('input[name="' + cfg.opt + '[' + key + '_name]"]').val($s.val() ? text : '');
		});
	}
	$(document).on('change', '#dze-klav-inc, #dze-klav-exc', names);

	function tools() {
		var chosen = $('#dze-klav-exc').val();
		$('#dze-klav-activate').toggle(!!chosen && ( inactive ).indexOf( chosen ) !== -1);
	}
	$(document).on('change', '#dze-klav-exc', function () {
		$('#dze-klav-seg-msg').text('');
		tools();
	});

	$(document).on('click', '#dze-klav-activate', function () {
		var $b = $(this), $m = $('#dze-klav-seg-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.working);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_activate', nonce: cfg.nonce, segment: $('#dze-klav-exc').val() })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				fill($('#dze-klav-inc'), res.data.audiences);
				fill($('#dze-klav-exc'), res.data.audiences);
				inactive = res.data.inactive || [];
				tools();
				$m.css('color', '#0a7040').removeClass('is-ko').text(res.data.message);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
			});
	});

	$(document).on('click', '#dze-klav-make-seg', function () {
		var $b = $(this), $m = $('#dze-klav-seg-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.working);
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_segment',
			nonce: cfg.nonce,
			weeks: $('#dze-klav-weeks').val()
		})
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				fill($('#dze-klav-inc'), res.data.audiences);
				fill($('#dze-klav-exc'), res.data.audiences);
				inactive = res.data.inactive || [];
				// Built for this field, so it lands in it — and still has to be
				// saved, like everything else on this page.
				$('#dze-klav-exc').val(res.data.id);
				tools();
				$m.css('color', '#0a7040').removeClass('is-ko').text(res.data.message + ' ' + i18n.thenSave);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
			});
	});

	// Keeps what was already chosen: a refresh must not silently unselect it.
	function fill($sel, map) {
		if (!$sel.length) { return; }
		var chosen = $sel.val();
		var first  = $sel.find('option').first();
		$sel.empty().append(first);
		$.each(map || {}, function (id, label) {
			$sel.append($('<option/>').attr('value', id).text(label));
		});
		if (chosen && $sel.find('option[value="' + chosen + '"]').length) { $sel.val(chosen); }
	}

	// ---- The promotion's emails: a list, and one editor under it ----
	//
	// Each email keeps its own fields in the form, so the page's Save button
	// keeps all of them. The editor is a WINDOW onto the selected one: opening
	// an email copies its fields in, and every keystroke copies them back. One
	// editor in the DOM, whatever the promotion carries.
	var current = null;
	// What the writing said the picture should show, kept until somebody asks
	// for it. Cleared when another email is opened: a description belongs to
	// the email it was written for.

	// A day written the way the shop writes days. The server sends its own
	// format and its own month names, so a row added here and a row drawn by
	// WordPress cannot read differently — which is what "2026-08-29" beside
	// "28/08/2026" on the same list was.
	function niceDay(iso) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || '').slice(0, 10));
		if (!m) { return String(iso || ''); }
		var y = m[1], mo = parseInt(m[2], 10), d = parseInt(m[3], 10),
			names = (cfg.months || [])[mo - 1] || {},
			pad = function (n) { return (n < 10 ? '0' : '') + n; },
			out = '';
		String(cfg.dateFmt || 'Y-m-d').split('').forEach(function (c, i, all) {
			if ('\\' === all[i - 1]) { out += c; return; }
			switch (c) {
				case 'd': out += pad(d); break;
				case 'j': out += d; break;
				case 'm': out += pad(mo); break;
				case 'n': out += mo; break;
				case 'Y': out += y; break;
				case 'y': out += y.slice(2); break;
				case 'F': out += names.F || mo; break;
				case 'M': out += names.M || mo; break;
				case '\\': break;
				default: out += c;
			}
		});
		return out;
	}

	function card(id)     { return $('.dze-mail[data-id="' + id + '"]'); }
	function ruleId()     { return $('#dze-klav-editor').data('rule'); }
	function body()       { return $('#dze-klav-e-body'); }
	function frame()      { return $('#dze-klav-e-iframe'); }
	function picture()    { return current ? card(current).find('.dze-f-picture') : $(); }

	// An <img> with no source, or with the mangled remains of the picture
	// marker, is a broken picture and nothing else. The server drops them on
	// the way out, so the preview drops them too — a preview that shows
	// something the email will not is worse than no preview.
	function sound(html) {
		return String(html).replace(/<img\b[^>]*>/gi, function (tag) {
			var src = tag.match(/\ssrc\s*=\s*("|')(.*?)\1/i);
			if (!src) { return ''; }
			var url = String( src[2] ).trim();
			return (!url || url.toLowerCase() === 'picture') ? '' : tag;
		});
	}

	function draw($frame, html) {
		var f = $frame[0];
		if (!f) { return; }
		f.setAttribute('sandbox', 'allow-same-origin');
		f.srcdoc = sound(html);
	}

	function assemble(shell, inner) {
		var at = shell.indexOf(cfg.mark);
		if (at === -1) { return inner; }
		return shell.slice(0, at) + inner + shell.slice(at + cfg.mark.length);
	}

	function readable(html) {
		return String(html)
			.split('{% unsubscribe %}').join(cfg.i18n.unsub)
			.split('{{ organization.name }}').join(cfg.shopName)
			.replace(/\{%[\s\S]*?%\}/g, '')
			.replace(/\{\{[\s\S]*?\}\}/g, '');
	}

	var pending = null;
	function render() {
		draw(frame(), assemble(cfg.shell, body().val() || ''));
	}

	// The thumbnails are the emails themselves, drawn small — the same HTML
	// that will be sent, so a card can never show something the email is not.
	function thumb(id) {
		var $c = card(id), html = String( $c.find('.dze-f-body').val() || '' ).trim();
		draw($c.find('.dze-mail-thumb iframe'), html ? assemble(cfg.shell, html) : '');
	}

	function view(which) {
		$('.dze-klav-tab').removeClass('is-on').filter('[data-tab="' + which + '"]').addClass('is-on');
		if (which === 'view') { body().hide(); frame().show(); render(); return; }
		// What Klaviyo itself makes of the blocks, drawn by Klaviyo. The fast
		// preview beside it is the browser putting the body inside a snapshot
		// of the frame: right enough to write against, and not the email.
		if (which === 'sent') {
			body().hide(); frame().show();
			if (!current) { return; }
			var $m = $('#dze-klav-write-msg');
			draw(frame(), '');
			$m.css('color', '#646970').removeClass('is-ko').text(cfg.i18n.asSent);
			$.post(cfg.ajaxUrl, {
				action: 'dze_klav_assent', nonce: cfg.nonce,
				rule: ruleId(), email: current, body: body().val() || ''
			})
				.done(function (res) {
					if (!res || !res.success) {
						$m.css('color', '#b32d2e').addClass('is-ko')
							.text((res && res.data && res.data.message) || i18n.error);
						view('view');
						return;
					}
					$m.text('');
					draw(frame(), res.data.html || '');
				})
				.fail(function () {
					$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
					view('view');
				});
			return;
		}
		frame().hide(); body().show();
	}
	$(document).on('click', '.dze-klav-tab', function () { view($(this).data('tab')); });

	// Editor → the email's own fields, on every keystroke.
	function typeName(kind) {
		var names = $('#dze-klav-editor').data('names') || {};
		return names[kind] || '';
	}

	function commit() {
		if (!current) { return; }
		var $c = card(current), kind = $('#dze-klav-e-type').val() || '', name = typeName(kind);
		$c.find('.dze-f-kind').val(kind);
		$c.find('.dze-f-subject').val($('#dze-klav-e-subject').val() || '');
		$c.find('.dze-f-preview').val($('#dze-klav-e-preview').val() || '');
		$c.find('.dze-f-when').val($('#dze-klav-e-when').val() || '');
		$c.find('.dze-f-body').val(body().val() || '');
		$c.find('.dze-mail-subject').text($('#dze-klav-e-subject').val() || '');
		$c.find('.dze-mail-preview').text($('#dze-klav-e-preview').val() || '');
		$c.find('.dze-mail-name').text(name || cfg.i18n.unnamed);
		$c.find('.dze-mail-when').contents().first().replaceWith(niceDay($('#dze-klav-e-when').val() || ''));
		markDupes();
		spacing();
		thumb(current);
	}
	$(document).on('input change', '#dze-klav-e-type, #dze-klav-e-subject, #dze-klav-e-preview, #dze-klav-e-when', commit);

	// Choosing the type also sets the day it falls on, from the type's own
	// rule. A day changed afterwards stays changed — nothing here runs again
	// until the menu is used a second time.
	$(document).on('change', '#dze-klav-e-type', function () {
		var map = $('#dze-klav-editor').data('when') || {}, day = map[$(this).val()];
		if (day) { $('#dze-klav-e-when').val(day); }
		commit();
		keepMeta();
	});
	$(document).on('change', '#dze-klav-e-when', function () { keepMeta(); });

	// The type and the day are kept the moment they are chosen, like every
	// other thing on this screen that CHANGES an email. They used to wait for
	// the event's Save, which lost them to any redraw — and, worse, left the
	// writing being told this email was still what it used to be.
	var keeping = null;
	function keepMeta() {
		if (!current) { return; }
		window.clearTimeout(keeping);
		keeping = window.setTimeout(function () {
			var id = current, $m = $('#dze-klav-e-kept');
			$.post(cfg.ajaxUrl, {
				action: 'dze_klav_meta', nonce: cfg.nonce, rule: ruleId(), email: id,
				kind: $('#dze-klav-e-type').val() || '',
				when: $('#dze-klav-e-when').val() || ''
			}).done(function (res) {
				if (!res || !res.success || !res.data || !res.data.kept || id !== current) { return; }
				$m.text(cfg.i18n.kept);
				window.setTimeout(function () { $m.text(''); }, 1600);
			});
		}, 400);
	}
	$(document).on('input', '#dze-klav-e-body', function () {
		commit();
		window.clearTimeout(pending);
		pending = window.setTimeout(render, 250);
	});

	// The editor, filled from ONE email's own fields. The row is what the
	// plugin writes into; this is the view of it. Called on opening, and again
	// whenever the email on screen is the one that has just been rewritten —
	// so a run that rewrites four emails does not have to open any of them.
	function fill(id) {
		var $c = card(id);
		if (!$c.length) { return; }
		// An email whose type the shop has since deleted falls back to the
		// first one rather than showing a menu with nothing selected.
		var $type = $('#dze-klav-e-type'), had = $c.find('.dze-f-kind').val() || '';
		if (!had || !$type.find('option').filter(function () { return this.value === had; }).length) {
			had = $type.find('option').first().val() || '';
		}
		$type.val(had);
		$('#dze-klav-e-subject').val($c.find('.dze-f-subject').val() || '');
		$('#dze-klav-e-preview').val($c.find('.dze-f-preview').val() || '');
		$('#dze-klav-e-when').val($c.find('.dze-f-when').val() || '');
		body().val($c.find('.dze-f-body').val() || '');
		drawHasPic();
		render();
	}

	function open(id) {
		var $c = card(id);
		if (!$c.length) { return; }
		current = id;
		$('.dze-mail').removeClass('is-on');
		$c.addClass('is-on');
		fill(id);
		$('#dze-klav-e-msg').text('');
		$('#dze-klav-e-kept').text('');
		$('#dze-klav-write-msg').text('').removeClass('is-ko');
		// The picture bench belongs to the email that is open: whether the
		// next writing makes its picture. WHAT the picture shows is one prompt
		// for the shop, not a sentence per email.
		$('#dze-klav-shot-out').hide();
		$('#dze-klav-shot-msg').text('').removeClass('is-ko');
		briefReset();
		$('#dze-mail-edit').show();
		view('view');
		// The editor sits UNDER the list, and a promotion with one email opens
		// on that email already: clicking Open then changed nothing anybody
		// could see, which reads exactly like a broken button. It goes to the
		// editor now, every time.
		var el = document.getElementById('dze-mail-edit');
		if (el && el.scrollIntoView) {
			el.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
	}

	$(document).on('click', '.dze-mail-open', function () { open($(this).closest('.dze-mail').data('id')); });

	// Scheduling, and unscheduling: the email goes out on its own day without
	// anybody opening Klaviyo, and a day chosen by mistake is undone from the
	// same button that chose it.
	$(document).on('click', '.dze-mail-sched', function () {
		var $b = $(this),
			$row = $b.closest('.dze-mail'),
			$msg = $row.find('.dze-mail-sched-msg'),
			undo = '1' === String($b.data('undo')),
			was = $b.text();
		$b.prop('disabled', true).text(cfg.i18n.working || 'Working…');
		$msg.css('color', '').text('');
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_schedule', nonce: cfg.nonce,
			rule: ruleId(), email: $row.data('id'), undo: undo ? 1 : 0
		}).done(function (r) {
			$b.prop('disabled', false);
			if (!r || !r.success) {
				$b.text(was);
				$msg.css('color', '#b32d2e').text((r && r.data && r.data.message) || (cfg.i18n.error || 'Something went wrong.'));
				return;
			}
			var on = !!(r.data && r.data.scheduled);
			// The row as the SERVER draws it. A scheduled campaign is locked
			// in Klaviyo, so its cell offers neither Update nor Translate
			// again; unscheduling unlocks it, and the row went on saying
			// "scheduled" with both buttons gone until somebody reloaded the
			// page. Redrawing the cell replaces this very button, so the
			// message is written into the row that came back, not the one
			// that has just been thrown away.
			if (r.data && r.data.state) {
				drawState($row, r.data);
			} else {
				$b.data('undo', on ? '1' : '0')
					.text(on ? (cfg.i18n.unschedule || 'Unschedule') : (cfg.i18n.schedule || 'Schedule it'));
			}
			$row.find('.dze-mail-sched-msg')
				.css('color', on ? '#00794b' : '#646970').text((r.data && r.data.message) || '');
		}).fail(function (xhr) {
			$b.prop('disabled', false).text(was);
			$msg.css('color', '#b32d2e').text((cfg.i18n.error || 'Something went wrong.') + why(xhr));
		});

		function why(xhr) {
			var st = xhr && xhr.status;
			return st ? ' (' + st + ')' : '';
		}
	});

	// The picker will not offer a day before tomorrow, but a date can still be
	// typed into it. Corrected where it is typed, and said — a field that
	// silently changes what somebody wrote is worse than one that refuses it.
	$(document).on('change', '#dze-klav-e-when', function () {
		var min = this.getAttribute('min') || '', v = this.value || '';
		if (!min || !v || v >= min) { return; }
		this.value = min;
		$('#dze-klav-e-kept').css('color', '#b26a00')
			.text(cfg.i18n.notBefore || 'The earliest an email can go out is tomorrow — moved.');
		$(this).trigger('input');
	});

	// The other languages, written by the shop rather than left to Klaviyo.
	// ONE request per language, driven from here, so the shop sees each one
	// land instead of watching a button for four minutes and wondering. A
	// single request doing all four is also a request that times out.
	// Translating ONE email, wherever it is asked for: the button on the row,
	// and the run that puts the whole promotion in Klaviyo. One function, so a
	// promotion translated in a batch cannot end up in a different state from
	// one translated by hand.
	//
	// What it ends with is NOT composed here. Every step writes its progress on
	// the row — that is live and belongs to the browser — but the final state
	// is the cell the server draws, the same one a reload would give. The row
	// used to say "Translated — 43 texts in FR, PL, ES" in the browser's own
	// words, and something else entirely after F5.
	function translateRow($b, $row, then) {
		var $said = $row.find('.dze-mail-langs'),
			rule = $('#dze-klav-editor').data('rule'),
			mail = $row.data('id'),
			was = $b.length ? $b.text() : '';

		if ($b.length) { $b.prop('disabled', true).text(cfg.i18nBusy || 'Translating…'); }

		function why(xhr) {
			var s = xhr && xhr.status;
			return s ? ' (' + s + (403 === s || 400 === s ? ' — reload the page and try again' : '') + ')' : '';
		}
		function done_(colour, text) {
			if ($b.length) {
				$b.prop('disabled', false);
				if ('#00794b' !== colour) { $b.text(was); }
			}
			if (text) { $said.css('color', colour).text(text); }
			if (then) { then('#00794b' === colour); }
		}

		$.post(cfg.ajaxUrl, { action: 'dze_klav_langs', nonce: cfg.nonce }).done(function (r) {
			var langs = (r && r.data && r.data.langs) || [], done = [], failed = [], msg = '';
			if (!langs.length) {
				done_('#b32d2e', cfg.i18nNone || 'No languages to translate into.');
				return;
			}

			// The languages are written AT THE SAME TIME. One after another was
			// one model call after another — minutes of somebody watching a
			// button — and they have nothing to say to each other. A few at a
			// time, not all of them: a shop with fifteen languages would ask
			// its own server for fifteen workers at once.
			var next = 0, live = 0, cap = Math.min(4, langs.length), tries = {};
			tell();
			while (live < cap) { start(); }

			function start() {
				if (next >= langs.length) { return; }
				ask(langs[next++]);
			}
			// One language, in a request of its own — and asked a SECOND time
			// when that request does not come back. The retry used to live in
			// PHP, inside the same request: two model calls of up to two
			// minutes each behind one HTTP call, which is how German came back
			// as "The translation did not finish. (504)" — the shop's own
			// server hanging up, with no message in it to read. Two short
			// requests instead of one long one, and the same second chance.
			function ask(lang) {
				live++;
				tries[lang] = (tries[lang] || 0) + 1;
				$.post(cfg.ajaxUrl, {
					action: 'dze_klav_i18n', nonce: cfg.nonce,
					rule: rule, email: mail, lang: lang
				}).done(function (one) {
					if (one && one.success) { done.push(lang); return; }
					if (tries[lang] < 2) { return retry(lang); }
					failed.push(lang);
					msg = msg || (one && one.data && one.data.message) || '';
				}).fail(function (xhr) {
					if (tries[lang] < 2) { return retry(lang); }
					failed.push(lang);
					// Named where it happened: this is the model writing one
					// language, not Klaviyo refusing the email.
					msg = msg || ((cfg.i18nWriteFail || 'Writing %s did not finish').replace('%s', lang.toUpperCase()) + why(xhr));
				}).always(function () {
					live--;
					tell();
					// Nothing is filed while a language is waiting to be asked
					// again: a run that saves at that moment files four of five
					// and calls the fifth lost.
					if (next < langs.length) { start(); } else if (!live && !waiting) { save(); }
				});
			}
			var waiting = 0;
			function retry(lang) {
				waiting++;
				window.setTimeout(function () { waiting--; ask(lang); }, 400);
			}

			// Said as it happens: which are in, which are still being written.
			function tell() {
				var waiting = langs.filter(function (l) {
					return -1 === ( done ).indexOf( l ) && -1 === ( failed ).indexOf( l );
				});
				$said.css('color', '').text(
					(cfg.i18nDoing || 'Writing %s… (%i of %n)')
						.replace('%s', waiting.join(', ').toUpperCase())
						.replace('%i', done.length + failed.length).replace('%n', langs.length)
					+ (done.length ? ' · ' + done.join(', ').toUpperCase() + ' ✓' : '')
				);
			}

			// Nothing has reached Klaviyo yet: every language goes in one call,
			// so four writers never race on the same campaign.
			function save() {
				if (!done.length) {
					done_('#b32d2e', msg || (cfg.i18nFail || 'The translation did not finish.'));
					return;
				}
				$said.css('color', '').text(cfg.i18nSaving || 'Filing them in Klaviyo…');
				$.post(cfg.ajaxUrl, {
					action: 'dze_klav_i18nsave', nonce: cfg.nonce, rule: rule, email: mail,
					// What did NOT come back, and what it said. Filed with the
					// rest, so the row still says it tomorrow instead of only
					// in the minute the run ended.
					failed: failed, why: msg
				}).done(function (r2) {
					if (!r2 || !r2.success) {
						done_('#b32d2e', (r2 && r2.data && r2.data.message) || (cfg.i18nFail || 'The translation did not finish.'));
						return;
					}
					// The row, as the page itself draws it — flags, notes and
					// buttons, all of it from the one function that builds the
					// cell. Nothing composed here.
					drawState($row, r2.data || {});
					done_('#00794b', '');
				}).fail(function (xhr) {
					done_('#b32d2e', (cfg.i18nFail || 'The translation did not finish.') + why(xhr));
				});
			}
		}).fail(function (xhr) {
			done_('#b32d2e', (cfg.i18nFail || 'The translation did not finish.') + why(xhr));
		});
	}

	$(document).on('click', '.dze-mail-i18n', function () {
		translateRow($(this), $(this).closest('.dze-mail'));
	});

	// A promotion holds as many emails as it deserves, not four. An id is
	// minted here and never reused, so two emails added in the same minute
	// cannot collide and overwrite one another on save.
	var minted = 0;
	function mintId() {
		minted += 1;
		return 'e' + Date.now().toString(36) + minted.toString(36);
	}

	// Two emails of the same type are handed the same brief and read alike.
	// Said on the row rather than left to be found in the inbox.
	function markDupes() {
		var count = {};
		$('.dze-mail').each(function () {
			var k = $(this).find('.dze-f-kind').val() || '';
			if (k) { count[k] = (count[k] || 0) + 1; }
		});
		$('.dze-mail').each(function () {
			var $c = $(this), k = $c.find('.dze-f-kind').val() || '', $w = $c.find('.dze-mail-what');
			$c.find('.dze-mail-dupe').remove();
			if (k && count[k] > 1 && $w.length) {
				$w.append($('<span class="dze-mail-dupe"></span>').text(cfg.i18n.sameType || ''));
			}
		});
	}

	// ---- Two emails too close together ----
	//
	// "J'ai un email qui part le 05 pour l'offre du back to school. L'offre du
	// patriot day suit juste derrière. Le warm-up est prévu le 06/09." Two
	// promotions know nothing about each other, and the reader is on ONE list:
	// he gets both. So the whole shop's calendar is judged here — the rows on
	// screen, whose days are being edited in their own fields, plus every
	// email of every other promotion, handed over with the page.
	//
	// Nothing is moved by itself. Which of two emails gives way is the shop's
	// decision, and a day changed behind somebody's back on a promotion he
	// built around a date is worse than the clash.
	function gap()   { return parseInt($('#dze-klav-editor').data('gap'), 10) || 0; }
	function others() {
		var got = $('#dze-klav-editor').data('calendar');
		return got && got.length ? got : [];
	}
	function daysApart(a, b) {
		var x = Date.parse(a + 'T00:00:00Z'), y = Date.parse(b + 'T00:00:00Z');
		if (isNaN(x) || isNaN(y)) { return null; }
		return Math.round(Math.abs(x - y) / 86400000);
	}
	// Every other email of the shop, with the row's own siblings among them.
	function around(skipId) {
		var out = [];
		(others() || []).forEach(function (one) {
			if (one && one.day) { out.push({ day: String(one.day), label: String(one.label || '') }); }
		});
		$('.dze-mail').each(function () {
			var $c = $(this), day = String($c.find('.dze-f-when').val() || '').slice(0, 10);
			if (!day || $c.data('id') === skipId) { return; }
			out.push({ day: day, label: $c.find('.dze-mail-name').text() || '' });
		});
		return out;
	}
	// The nearest one that is too close, or nothing.
	function tooClose(day, skipId) {
		var want = gap(), best = null;
		if (!day || want < 1) { return null; }
		around(skipId).forEach(function (one) {
			var apart = daysApart(day, one.day);
			if (null === apart || apart >= want) { return; }
			if (!best || apart < best.apart) { best = { apart: apart, day: one.day, label: one.label }; }
		});
		return best;
	}
	// One sentence, one set of holes, filled in its own order. Chaining the
	// replacements across two different sentences put the DAY where the name
	// of the other email belongs and then printed the day twice: "2 days from
	// 2026-09-08 (2026-09-08)".
	function clashText(hit) {
		if (!hit) { return ''; }
		if (0 === hit.apart) {
			return String(cfg.i18n.clashSame || 'Same day as %1$s (%2$s).')
				.replace('%1$s', hit.label).replace('%2$s', niceDay(hit.day));
		}
		if (1 === hit.apart) {
			return String(cfg.i18n.clashOne || '1 day from %1$s (%2$s).')
				.replace('%1$s', hit.label).replace('%2$s', niceDay(hit.day));
		}
		return String(cfg.i18n.clashNear || '%1$d days from %2$s (%3$s).')
			.replace('%1$d', hit.apart).replace('%2$s', hit.label).replace('%3$s', niceDay(hit.day));
	}
	// The first day, from the one asked for outwards, that clears everything.
	// Offered, never applied: one press away, and it is the shop pressing.
	function freeDay(day, skipId) {
		var min = $('#dze-klav-e-when').attr('min') || '', from = Date.parse(day + 'T00:00:00Z');
		if (isNaN(from)) { return ''; }
		for (var step = 1; step <= 60; step++) {
			for (var side = 0; side < 2; side++) {
				var when = new Date(from + (side ? step : -step) * 86400000).toISOString().slice(0, 10);
				if (min && when < min) { continue; }
				if (!tooClose(when, skipId)) { return when; }
			}
		}
		return '';
	}
	function spacing() {
		$('.dze-mail').each(function () {
			var $c = $(this), day = String($c.find('.dze-f-when').val() || '').slice(0, 10),
				hit = tooClose(day, $c.data('id'));
			$c.find('.dze-mail-clash').text(hit ? clashText(hit) : '').toggle(!!hit);
		});
		var $say = $('#dze-klav-e-clash'), $go = $('#dze-klav-e-free');
		if (!current || !$say.length) { return; }
		var mine = String($('#dze-klav-e-when').val() || '').slice(0, 10),
			hit  = tooClose(mine, current);
		if (!hit) { $say.hide().text(''); $go.hide(); return; }
		$say.text(clashText(hit) + ' ' + String(cfg.i18n.clashWant || 'Leave %d days between two emails.').replace('%d', gap())).show();
		var free = freeDay(mine, current);
		if (!free) { $go.hide(); return; }
		$go.text(String(cfg.i18n.moveTo || 'Move it to %s').replace('%s', niceDay(free))).data('day', free).show();
	}
	$(document).on('click', '#dze-klav-e-free', function () {
		var day = $(this).data('day');
		if (!day) { return; }
		$('#dze-klav-e-when').val(day);
		commit();
		keepMeta();
		spacing();
	});

	$(document).on('click', '#dze-mail-new', function () {
		var id = mintId(),
			html = $('#dze-mail-blank').html().split('__ID__').join(id),
			$c = $(String( html ).trim());
		// A new email is the promotion's announcement on its opening day —
		// most promotions need exactly that one. A SEQUENCE is the plan
		// prompt's decision, never this button's: it briefly guessed the next
		// unused moment here, which pushed a rhythm on promotions that only
		// wanted one email. If two emails end up the same type, the rows say
		// so and the owner picks the one to change.
		$c.find('.dze-f-when').val($('#dze-klav-editor').data('newday') || '');
		$c.find('.dze-f-kind').val($('#dze-klav-editor').data('newkind') || '');
		$('.dze-mail-list').append($c);
		open(id);
		commit();
	});


	// The plan: one prompt decides what the promotion deserves, the rows
	// appear, and the writing prompt is run on each of them afterwards. Two
	// requests of different shapes, never one long one — a host that cuts a
	// request off in the middle would otherwise cost the whole campaign.
	$(document).on('click', '#dze-mail-plan', function () {
		var $b = $(this), $m = $('#dze-mail-plan-msg');
		if ($('.dze-mail').length && !window.confirm(cfg.i18n.replan)) { return; }
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(cfg.i18n.planning);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_plan', nonce: cfg.nonce, rule: ruleId() })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				// The plan is already stored; the rows are redrawn from what it
				// returned so the page shows the same thing a reload would.
				$('.dze-mail-list').empty();
				$.each(res.data.emails, function (i, mail) {
					var html = $('#dze-mail-blank').html().split('__ID__').join(mail.id),
						$c = $(String( html ).trim());
					$c.find('.dze-f-when').val(mail.when);
					$c.find('.dze-f-kind').val(mail.kind || '');
					$c.find('.dze-mail-name').text(mail.name || cfg.i18n.unnamed);
					$c.find('.dze-mail-when').contents().first().replaceWith(niceDay(mail.when));
					$('.dze-mail-list').append($c);
				});
				current = null;
				$('#dze-mail-edit').hide();
				markDupes();
				spacing();
				var $first = $('.dze-mail').first();
				if ($first.length) { open($first.data('id')); }
				$m.css('color', '#0a7040').removeClass('is-ko').text(res.data.message);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
			});
	});

	// Writing every email, one request each, in order. The same endpoint one
	// button uses for one email: there is no second way to write one, so a
	// batch cannot drift from what a single click does.
	// What is happening TO THIS EMAIL, said on its own row. The batch loops
	// used to count progress in one line at the bottom of the screen —
	// "Putting 1 of 3 in Klaviyo…" — while every row sat unchanged, so the
	// owner could not tell WHICH email was travelling or which one failed.
	// One note per row, one helper for every loop, so the two batches cannot
	// drift apart in wording or placement.
	// The row's state cell, as the server draws it on the page. An email that
	// has just reached Klaviyo can be scheduled and translated, and the row has
	// to offer both AT ONCE: it used to show a bare link, and Schedule it /
	// Translate it only appeared to somebody who thought to reload the page.
	// The markup comes from the same PHP the page is built with, so there is no
	// second version of that cell to keep in step.
	function drawState($c, data) {
		var $state = $c.find('.dze-mail-state');
		if (data && data.state) { $state.html(data.state); return; }
		$state.empty().append($('<a target="_blank" rel="noopener noreferrer"/>')
			.attr('href', (data && data.url) || '#').text(i18n.open));
	}

	function rowNote($c, text, tone) {
		var $n = $c.find('.dze-mail-note');
		if (!text) { $n.remove(); $c.removeClass('is-syncing'); return; }
		if (!$n.length) { $n = $('<span class="dze-mail-note"></span>').prependTo($c.find('.dze-mail-state')); }
		$n.css('color', 'ok' === tone ? '#00794b' : ('ko' === tone ? '#b32d2e' : '#646970'))
			.text(text);
		$c.toggleClass('is-syncing', 'work' === tone);
		if ('ok' === tone) {
			window.setTimeout(function () { $n.fadeOut(400, function () { $n.remove(); }); }, 4000);
		}
	}

	// Several at a time, and not all at once.
	//
	// "Plusieurs emails devraient pouvoir etre générés en même temps et pas
	// tous un par un." One at a time was a quarter of an hour of watching a
	// button; all at once is worse — every one of these is a model call
	// holding a PHP worker, and a shop with four workers stops answering its
	// own pages. Three in flight, which halves the wait and leaves the shop
	// standing.
	var AT_ONCE = 3;

	$(document).on('click', '#dze-mail-all', function () {
		var $b = $(this), $m = $('#dze-mail-plan-msg'),
			ids = $('.dze-mail').map(function () { return $(this).data('id'); }).get(),
			next = 0, done = 0, live = 0;
		if (!ids.length) { $m.css('color', '#b26a00').removeClass('is-ko').text(cfg.i18n.nothing); return; }
		$b.prop('disabled', true);
		say();
		pump();

		function say() {
			$m.css('color', '#646970').removeClass('is-ko')
				.text(cfg.i18n.writing1
					.replace('%1$d', Math.min(done + 1, ids.length))
					.replace('%2$d', ids.length));
		}
		function pump() {
			while (live < AT_ONCE && next < ids.length) {
				live++;
				// One that failed does not stop the rest: the others are worth
				// having, and the one that failed is still there to try again
				// on its own. writeOne always resolves, so this is reached.
				writeOne(ids[next++]).always(function () {
					live--;
					done++;
					if (done >= ids.length) {
						$b.prop('disabled', false);
						$m.css('color', '#0a7040').removeClass('is-ko').text(cfg.i18n.allDone);
						return;
					}
					say();
					pump();
				});
			}
		}
	});

	// The whole promotion handed over at once, oldest first.
	//
	// Klaviyo has no campaign that goes out four times on four days: a campaign
	// is one send, with one send time. So a promotion becomes one campaign per
	// email, each named after the event and its type, each scheduled on its own
	// day, and all of them carrying the event's name as a tag — that tag is
	// what makes them read as one campaign in the account.
	//
	// Same endpoint as the single button, one request per email: a run that is
	// cut off halfway leaves the emails it already handed over in Klaviyo, and
	// the rest can be sent by clicking again.
	$(document).on('click', '#dze-mail-draftall', function () {
		var $b = $(this), $m = $('#dze-mail-plan-msg');
		commit();
		var jobs = $('.dze-mail').map(function () {
			var $c = $(this);
			return {
				id: $c.data('id'),
				when: String( $c.find('.dze-f-when').val() || '' ).trim(),
				body: String( $c.find('.dze-f-body').val() || '' ).trim()
			};
		}).get().filter(function (j) { return j.body; });
		if (!jobs.length) { $m.css('color', '#b26a00').removeClass('is-ko').text(cfg.i18n.noWritten); return; }
		// Date order, and a day nobody set goes last rather than first.
		jobs.sort(function (a, b) { return (a.when || '9999-12-31').localeCompare(b.when || '9999-12-31'); });
		$b.prop('disabled', true);
		var made = 0, failed = 0, same = 0;
		(function next(i) {
			if (i >= jobs.length) {
				$b.prop('disabled', false);
				if (failed) {
					$m.css('color', '#b26a00').removeClass('is-ko')
						.text(cfg.i18n.draftSome.replace('%1$d', made).replace('%2$d', failed));
				} else if (same) {
					// What was actually done, not "all done": the shop pressed
					// this and five identical templates went nowhere.
					$m.css('color', '#0a7040').removeClass('is-ko')
						.text((cfg.i18n.draftSkip || '%1$d / %2$d').replace('%1$d', made).replace('%2$d', same));
				} else {
					$m.css('color', '#0a7040').removeClass('is-ko').text(cfg.i18n.draftAll);
				}
				return;
			}
			$m.css('color', '#646970').removeClass('is-ko')
				.text(cfg.i18n.drafting1.replace('%1$d', i + 1).replace('%2$d', jobs.length));
			rowNote(card(jobs[i].id), cfg.i18n.rowPutting, 'work');
			$.post(cfg.ajaxUrl, {
				action: 'dze_klav_draft',
				nonce: cfg.nonce,
				rule: ruleId(),
				email: jobs[i].id,
				body: jobs[i].body
			})
				.done(function (res) {
					if (!res || !res.success || !res.data || !res.data.url) {
						failed += 1;
						rowNote(card(jobs[i].id), (res && res.data && res.data.message) || i18n.error, 'ko');
						next(i + 1);
						return;
					}
					var $c = card(jobs[i].id);
					drawState($c, res.data);
					// An email nobody has touched since it was filed is not
					// filed again: re-sending five identical templates is
					// work Klaviyo did not need and the shop did not ask
					// for. The row says which of the two happened.
					if (res.data.skipped) {
						same += 1;
						rowNote($c, cfg.i18n.rowSame, 'ok');
					} else {
						made += 1;
						rowNote($c, cfg.i18n.rowPut, 'ok');
					}
					// AND ITS LANGUAGES. "Put them all in Klaviyo > devrait
					// aussi traduire directement": a template rewritten in
					// English leaves its translations describing the email it
					// used to be, so translating is part of putting it there,
					// not a second round of clicking. Only where there is
					// something to translate into — the row offers the button
					// only then — and an email nothing changed in is left
					// alone unless it has never been translated at all.
					var owes = ! $c.find('.dze-lang.is-done').length;
					if ($c.find('.dze-mail-i18n').length && (!res.data.skipped || owes)) {
						rowNote($c, cfg.i18nBusy || 'Translating…', 'work');
						translateRow($(), $c, function (ok) {
							rowNote($c, ok ? cfg.i18n.rowPut : ((cfg.i18nFail || 'The translation did not finish.')), ok ? 'ok' : 'ko');
							next(i + 1);
						});
						return;
					}
					next(i + 1);
				})
				.fail(function () { failed += 1; rowNote(card(jobs[i].id), i18n.error, 'ko'); next(i + 1); });
		}(0));
	});

	// One email, put in Klaviyo on its own. The batch skips what has not
	// changed; this never does — asked for by name, it goes.
	$(document).on('click', '.dze-mail-push', function () {
		var $b = $(this), id = $(this).closest('.dze-mail').data('id');
		commit();
		var text = String( card(id).find('.dze-f-body').val() || '' ).trim();
		if (!text) { rowNote(card(id), cfg.i18n.noWritten, 'ko'); return; }
		$b.prop('disabled', true);
		rowNote(card(id), cfg.i18n.rowPutting, 'work');
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_draft', nonce: cfg.nonce, rule: ruleId(),
			email: id, body: text, force: 1
		})
			.done(function (res) {
				$b.prop('disabled', false);
				if (res && res.success && res.data && res.data.url) {
					drawState(card(id), res.data);
					rowNote(card(id), cfg.i18n.rowPut, 'ok');
					return;
				}
				rowNote(card(id), (res && res.data && res.data.message) || i18n.error, 'ko');
			})
			.fail(function () { $b.prop('disabled', false); rowNote(card(id), i18n.error, 'ko'); });
	});

	// An email that is already in Klaviyo, linked back to its row. Writes
	// nothing there — it only puts the id back beside the email.
	$(document).on('click', '.dze-mail-find', function () {
		var $b = $(this), $c = $b.closest('.dze-mail'), id = $c.data('id');
		$b.prop('disabled', true);
		rowNote($c, cfg.i18n.rowFinding || 'Looking in Klaviyo…', 'work');
		$.post(cfg.ajaxUrl, { action: 'dze_klav_find', nonce: cfg.nonce, rule: ruleId(), email: id })
			.done(function (res) {
				$b.prop('disabled', false);
				if (res && res.success && res.data) {
					drawState(card(id), res.data);
					rowNote(card(id), res.data.message || '', 'ok');
					return;
				}
				rowNote($c, (res && res.data && res.data.message) || i18n.error, 'ko');
			})
			.fail(function () { $b.prop('disabled', false); rowNote($c, i18n.error, 'ko'); });
	});

	// A person has read what the autopilot wrote: the mark goes, at once and
	// in the database, like everything on this screen that changes an email.
	$(document).on('click', '.dze-mail-checked', function () {
		var $c = $(this).closest('.dze-mail'), $line = $(this).closest('.dze-mail-check');
		$.post(cfg.ajaxUrl, { action: 'dze_klav_check', nonce: cfg.nonce, rule: ruleId(), email: $c.data('id') })
			.done(function (res) {
				if (res && res.success) { $line.remove(); }
			});
	});

	$(document).on('click', '.dze-mail-drop', function () {
		if (!window.confirm(cfg.i18n.dropMail)) { return; }
		var $c = $(this).closest('.dze-mail'), id = $c.data('id'), $m = $('#dze-mail-plan-msg');
		// Taken out of the database at once, not left for the event's Save.
		// Writing an email stores it immediately, so removing one has to as
		// well: a row that disappears from the screen and comes back on the
		// next reload is the same email twice, and the owner is right to call
		// that broken.
		$c.remove();
		markDupes();
		spacing();
		if (current === id) {
			current = null;
			$('#dze-mail-edit').hide();
		}
		$.post(cfg.ajaxUrl, { action: 'dze_klav_drop', nonce: cfg.nonce, rule: ruleId(), email: id })
			.done(function (res) {
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				// What happened to its campaign in Klaviyo — deleted with it,
				// left as history, or a warning that needs reading. Deleting a
				// row used to say nothing, and a draft (or worse, a SCHEDULED
				// send) lived on in the account with nothing here saying so.
				var said = res.data && res.data.message;
				if (said) {
					var ko = /WILL go out|could not be reached/.test(said);
					$m.css('color', ko ? '#b32d2e' : '#646970').toggleClass('is-ko', ko).text(said);
				}
			})
			.fail(function () { $m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error); });
	});

	// What KLAVIYO holds, read once the screen has drawn.
	//
	// The rows are drawn from what was filed here, and the account moves
	// without us: a campaign archived by hand, scheduled in Klaviyo itself,
	// deleted there. The shop was reading "Synced with Klaviyo · draft" off a
	// campaign it had archived days before. Asked AFTER the page is up, never
	// during it, and the server refuses to ask the account twice in two
	// minutes. A row whose cell has not changed is not touched.
	function verify() {
		if (!$('.dze-mail').length || !ruleId()) { return; }
		$.post(cfg.ajaxUrl, { action: 'dze_klav_state', nonce: cfg.nonce, rule: ruleId() })
			.done(function (res) {
				if (!res || !res.success || !res.data) { return; }
				var rows = res.data.rows || {}, id;
				for (id in rows) {
					if (Object.prototype.hasOwnProperty.call(rows, id)) {
						card(id).find('.dze-mail-state').html(rows[id]);
					}
				}
				if (res.data.message) {
					$('#dze-mail-plan-msg').css('color', '#b26a00').removeClass('is-ko').text(res.data.message);
				}
			});
	}

	$(function () {
		$('.dze-mail').each(function () { thumb($(this).data('id')); });
		markDupes();
		spacing();
		var $first = $('.dze-mail').first();
		if ($first.length) { open($first.data('id')); }
		verify();
	});

	// ---- Settings: the template, previewed the same way ----
	function drawShell() {
		var shell = $('#dze-klav-shell').val() || '';
		// No frame, no preview. Drawing the sample body on its own would show
		// an email that is never sent — the module refuses to write one until
		// a template has been read.
		draw($('#dze-klav-shell-frame'), shell ? readable(assemble(shell, cfg.sample)) : '');
	}

	// The header and the footer, read out of a template that already exists in
	// the account. Klaviyo renders it and says itself where the empty section
	// is — nothing to find, nothing to place by hand, here or on the next shop.
	$(document).on('click', '#dze-klav-take', function () {
		var $b = $(this), $m = $('#dze-klav-shell-msg'), head = $('#dze-klav-th').val();
		if (!head) { $m.css('color', '#b26a00').removeClass('is-ko').text(i18n.pickTpl); return; }
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.working);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_frame', nonce: cfg.nonce, header: head })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$('#dze-klav-shell').val(res.data.html);
				$('#dze-klav-fid').val(head);
				$('#dze-klav-fname').val(res.data.name || '');
				$('#dze-klav-tpl-hint').text(res.data.taken || '');
				drawShell();
				$m.css('color', '#b26a00').removeClass('is-ko').text(res.data.message);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
			});
	});
	$(function () {
		if ($('#dze-klav-shell-frame').length) { drawShell(); }
		if ($('#dze-klav-e-iframe').length) { view('view'); }
	});

	$(document).on('click', '.dze-klav-tab', function () { view($(this).data('tab')); });

	// The email is laid out by the model, which places the picture itself. So a
	// new picture SWAPS the one already in the email rather than being pasted
	// somewhere: the layout that was written stays the layout that was written.
	// What this email already holds, said on the screen where a new one is
	// made. Rewriting an email KEEPS its picture — the writing is handed the
	// URL and told to use it as it stands — and nothing said so, so the only
	// way to find out was to spend a minute and a few cents finding out.
	function drawHasPic() {
		var $p = $('#dze-klav-haspic');
		if (!$p.length) { return; }
		var url = String( picture().val() || '' ).trim();
		// The bench shows a candidate; this shows what the email holds. Once
		// they are the same photograph the bench has done its job and steps
		// aside: two identical thumbnails one above the other is the screen
		// asking a question it has already answered.
		if (url && url === tested) { $('#dze-klav-shot-out').hide(); }
		if (!url) { $p.hide(); return; }
		$p.css('display', 'flex').find('img').attr('src', url).attr('data-full', url);
	}

	// A photograph put into ONE email, named. It used to be put into the
	// editor's textarea, which meant the email had to be open — and a run of
	// four could only ever illustrate whichever one was on screen.
	function rowShot(id, url) {
		var $c = card(id), $body = $c.find('.dze-f-body'),
			old = String( $c.find('.dze-f-picture').val() || '' ).trim(),
			html = String( $body.val() || '' );
		if (!$c.length) { return; }
		$c.find('.dze-f-picture').val(url);
		if (html.indexOf(cfg.pictureMark) !== -1) {
			html = html.split(cfg.pictureMark).join(url);
		} else if (old && html.indexOf(old) !== -1) {
			html = html.split(old).join(url);
		} else if (html.indexOf(url) === -1) {
			// Nothing to swap: the writing left no place for a picture, or the
			// place it left has already been filled by another one. The
			// picture goes at the top, which is where an opening picture goes.
			html = '<p style="margin:0 0 14px;"><img src="' + url + '" width="544" alt="" ' +
				'style="display:block;width:100%;max-width:544px;height:auto;border:0;" /></p>' + html;
		}
		$body.val(html);
		thumb(id);
		if (id === current) { fill(id); }
	}

	// The <img> that was waiting for a photograph that never came, on one row.
	function rowNoShot(id) {
		var $body = card(id).find('.dze-f-body'), html = String( $body.val() || '' );
		if (!$body.length || html.indexOf(cfg.pictureMark) === -1) { return; }
		$body.val(html.replace(
			new RegExp('<p[^>]*>\\s*<img[^>]*' + cfg.pictureMark + '[^>]*>\\s*<\\/p>|<img[^>]*' + cfg.pictureMark + '[^>]*>', 'gi'),
			''
		));
		thumb(id);
		if (id === current) { fill(id); }
	}

	// Making the picture is a call of its own, and a slow one. It runs beside
	// the writing rather than inside it: two requests the server can each
	// finish, instead of one that a host cuts off at ninety seconds. The
	// description comes from the writing — there is one prompt, and it decides
	// the picture as well as the words.
	// A TEST picture: as many as it takes, on a description you can edit right
	// here, none of them touching the email. The real one is made by writeOne,
	// for a named email, through the one routine that puts a photograph into a
	// body — this used to carry a second copy of that routine, working only on
	// the email that happened to be open.
	function makePicture($b, $m, prompt) {
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.shooting);
		return $.post(cfg.ajaxUrl, {
			action: 'dze_klav_image', nonce: cfg.nonce, rule: ruleId(), email: current,
			prompt: prompt || '', test: 1
		})
			.done(function (res) {
				if (res && res.success) {
					showTest(res.data.url, res.data.full);
					hosted = res.data.warning || '';
					if (res.data.spend && res.data.spend.label) {
						$('#dze-klav-spend').text(res.data.spend.label).show();
					}
					return;
				}
				// A test that failed changes nothing in the email at all.
				$m.css('color', '#b26a00').removeClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
			})
			.fail(function () {
				$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
			});
	}

	// A test picture is looked at, not filed: it is how a description is
	// judged before an email is built on it.
	var tested = '', hosted = '';
	function showTest(url, full) {
		tested = url;
		// data-full is what the plugin's zoom opens: the button is planted on
		// the thumbnail itself, like every other image strip here.
		$('#dze-klav-shot-img').attr('src', url).attr('data-full', full || url);
		$('#dze-klav-shot-out').css('display', 'flex');
	}

	// The <img> that was waiting for a photograph that never came.
	// What the writing is TOLD, printed on demand. It is built by the very
	// function that writes the email, with no model call: what is shown here
	// is what the next Generate sends, and there is no second version of it
	// to drift. An email that comes back looking like its neighbour is either
	// one that was never shown the neighbour or one that ignored it, and this
	// is how to tell which without taking anybody's word for it.
	function briefReset() {
		$('#dze-klav-brief').prop('open', false).data('for', '');
		$('#dze-klav-brief-txt').text('');
		$('#dze-klav-picbrief').prop('open', false).data('for', '');
		$('#dze-klav-picbrief-txt').text('');
		$('#dze-klav-picbrief-refs').empty();
	}

	// <details> fires "toggle", which does not bubble — so the summary's own
	// click is what is listened to, and the state read is the one BEFORE the
	// browser flips it.
	$(document).on('click', '#dze-klav-brief > summary', function () {
		var $d = $(this).closest('details'), $out = $('#dze-klav-brief-txt');
		if ($d.prop('open') || !current || $d.data('for') === current) { return; }
		$out.text(cfg.i18n.briefing);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_brief', nonce: cfg.nonce, rule: ruleId(), email: current })
			.done(function (res) {
				if (!res || !res.success) {
					$out.text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$d.data('for', current);
				$out.text(res.data.brief || '');
			})
			.fail(function () { $out.text(i18n.error); });
	});

	// The picture's own brief, the same gesture as the writing's: opened, it
	// asks once per email and shows the exact prompt and the exact reference
	// photographs the next Generate sends.
	$(document).on('click', '#dze-klav-picbrief > summary', function () {
		var $d = $(this).closest('details'), $out = $('#dze-klav-picbrief-txt'), $refs = $('#dze-klav-picbrief-refs');
		if ($d.prop('open') || !current || $d.data('for') === current) { return; }
		$out.text(cfg.i18n.briefing);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_picbrief', nonce: cfg.nonce, rule: ruleId(), email: current })
			.done(function (res) {
				if (!res || !res.success) {
					$out.text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$d.data('for', current);
				$out.text((res.data.note ? res.data.note + '\n\n' : '') + (res.data.prompt || ''));
				$refs.empty();
				$.each(res.data.refs || [], function (i, url) {
					$refs.append($('<img/>', { src: url, alt: '' }).css({
						width: '86px', height: '64px', 'object-fit': 'cover',
						border: '1px solid #dcdcde', 'border-radius': '4px'
					}));
				});
			})
			.fail(function () { $out.text(i18n.error); });
	});

	// ONE email written, wherever the click came from, and never through the
	// editor.
	//
	// "Generate them all devrait générer les emails sans avoir besoin d'ouvrir
	// la fenetre d'aperçu en focus." It did: the run opened each email, poured
	// the model's answer into the editor's fields and copied them back onto the
	// row, so the answer travelled through whichever email happened to be on
	// screen — one at a time, with the page scrolling under the shop. The row
	// is written into directly now, and the editor is refreshed only when the
	// email that changed is the one being looked at.
	//
	// Always RESOLVES, with { ok, data }: one email that failed must not stop
	// the ones after it, and the row it failed on says so on its own line.
	function writeOne(id) {
		var $c = card(id);
		rowNote($c, cfg.i18n.rowWriting, 'work');
		return $.post(cfg.ajaxUrl, {
			action: 'dze_klav_write', nonce: cfg.nonce, rule: ruleId(), email: id,
			// The type and day AS THEY ARE ON THE ROW: a row added just now is
			// not in the database yet, and writing from what is stored gave a
			// launch email to somebody who asked for a last chance.
			kind: $c.find('.dze-f-kind').val() || '',
			when: $c.find('.dze-f-when').val() || ''
		}).then(function (res) {
			if (!res || !res.success) {
				rowNote($c, (res && res.data && res.data.message) || i18n.error, 'ko');
				return { ok: false, data: (res && res.data) || {} };
			}
			rowPut(id, res.data);
			// The PICTURE is part of writing the email, not a thing to ask
			// for: the one condition is that the writing left a place for one.
			// Made AFTER the words, so the picture prompt is given what this
			// email turned out to be.
			if (!res.data.picture) {
				rowNote($c, cfg.i18n.rowWrote, 'ok');
				return { ok: true, data: res.data };
			}
			rowNote($c, cfg.i18n.rowShot || 'Making its picture…', 'work');
			return shotFor(id).then(function () {
				rowNote($c, cfg.i18n.rowShotOk || cfg.i18n.rowWrote, 'ok');
				return { ok: true, data: res.data };
			}, function () {
				// A picture that never came back must not lose the words: the
				// email is there, and the row says which one lost its picture.
				rowNote($c, i18n.error, 'ko');
				return { ok: true, data: res.data };
			});
		}, function () {
			rowNote($c, i18n.error, 'ko');
			return { ok: false, data: {} };
		});
	}

	// The answer, onto the email that asked for it.
	function rowPut(id, data) {
		var $c = card(id);
		if (!$c.length) { return; }
		if (undefined !== data.subject) {
			$c.find('.dze-f-subject').val(data.subject || '');
			$c.find('.dze-mail-subject').text(data.subject || '');
		}
		if (data.preview) {
			$c.find('.dze-f-preview').val(data.preview);
			$c.find('.dze-mail-preview').text(data.preview);
		}
		if (undefined !== data.body) { $c.find('.dze-f-body').val(data.body || ''); }
		thumb(id);
		markDupes();
		if (id === current) { fill(id); }
	}

	// Its photograph, asked for BY NAME rather than "the one that is open".
	function shotFor(id) {
		return $.post(cfg.ajaxUrl, {
			action: 'dze_klav_image', nonce: cfg.nonce, rule: ruleId(), email: id,
			prompt: '', test: 0
		}).then(function (res) {
			if (!res || !res.success || !res.data || !res.data.url) {
				rowNoShot(id);
				return $.Deferred().reject().promise();
			}
			rowShot(id, res.data.url);
			if (res.data.spend && res.data.spend.label) {
				$('#dze-klav-spend').text(res.data.spend.label).show();
			}
			return res.data;
		}, function () {
			rowNoShot(id);
			return $.Deferred().reject().promise();
		});
	}

	function writeEmail($b, $m) {
		if (!current) { return; }
		var id = current;
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.writing);
		// The same function the run uses. A rule held in one of two places is
		// a rule that will be found broken in the other.
		writeOne(id).always(function (got) {
			$b.prop('disabled', false);
			var data = (got && got.data) || {};
			if (!got || !got.ok) {
				$m.css('color', '#b32d2e').addClass('is-ko').text(data.message || i18n.error);
				return;
			}
			// This email has changed: what the OTHERS are told about it has too.
			briefReset();
			view('view');
			// A warning is not a failure: the email is there, and something in
			// it is worth reading twice before it goes out.
			if (data.warning) {
				$m.css('color', '#b32d2e').addClass('is-ko').text(data.warning);
				return;
			}
			$m.css('color', data.picture ? '#0a7040' : '#b26a00').removeClass('is-ko')
				.text(data.picture ? i18n.shot : i18n.written);
		});
	}

	// An email opens on a picture. Asking for one separately meant it went out
	// without one, so the writing makes it first when the event has none.
	$(document).on('click', '#dze-klav-e-write', function () {
		if (!current) { return; }
		// Under the button that was pressed, not at the foot of the editor.
		writeEmail($(this), $('#dze-klav-write-msg'));
	});

	// What this list actually does with its mornings — read from the account's
	// own opens, not from a rule of thumb. It is information, not a setting:
	// Klaviyo picks the hour reader by reader unless that is switched off.
	$(document).on('click', '#dze-klav-hours', function () {
		var $b = $(this), $out = $('#dze-klav-hours-out');
		$b.prop('disabled', true).text(i18n.reading);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_hours', nonce: cfg.nonce })
			.done(function (res) {
				$b.prop('disabled', false).text(cfg.i18n.whenOpen);
				if (!res || !res.success) {
					$out.show().html($('<p style="margin:0;color:#b32d2e;"/>')
						.text((res && res.data && res.data.message) || i18n.error));
					return;
				}
				var days = res.data.days || [], names = res.data.names || [],
					top = Math.max.apply(null, days) || 1;
				var $bars = $('<div class="dze-hours"/>');
				$.each(days, function (d, n) {
					var pc = Math.round((n / top) * 100);
					$bars.append(
						$('<span class="dze-hour"/>')
							.attr('title', (names[d] || '') + ' — ' + n)
							.toggleClass('is-peak', d === res.data.peak)
							.append($('<i/>').css('height', Math.max(2, pc) + '%'))
							.append($('<b/>').text((names[d] || '').slice(0, 3)))
					);
				});
				$out.show().empty()
					.append($bars)
					.append($('<p class="description" style="margin:4px 0 0;"/>').text(res.data.message));
			})
			.fail(function () {
				$b.prop('disabled', false).text(cfg.i18n.whenOpen);
				$out.show().html($('<p style="margin:0;color:#b32d2e;"/>').text(i18n.error));
			});
	});

	// The draft, per email. What is on screen is what Klaviyo receives.
	$(document).on('click', '#dze-klav-e-draft', function () {
		var $b = $(this), $m = $('#dze-klav-e-msg');
		if (!current) { return; }
		// Sending is asked for twice: the menu, then this. Everything else on
		// this screen can be undone by doing it again — a send cannot.
		var send = $('#dze-klav-e-how').val() === 'send';
		if (send && !window.confirm(cfg.i18n.sendSure)) { return; }
		commit();
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.creating);
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_draft',
			nonce: cfg.nonce,
			rule: ruleId(),
			email: current,
			send: send ? 1 : 0,
			body: body().val() || ''
		})
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				drawState(card(current), res.data);
				$m.css('color', res.data.warning ? '#b26a00' : '#0a7040')
					.text(res.data.warning || (res.data.sent ? cfg.i18n.sentOk : i18n.made));
				window.open(res.data.url, '_blank', 'noopener');
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
			});
	});

	// The picture, made when it is asked for and not before.
	// The bench: as many test pictures as it takes, on a description you can
	// edit right here, none of them touching the email.
	$(document).on('click', '#dze-klav-e-shot', function () {
		var $b = $(this), $m = $('#dze-klav-shot-msg');
		makePicture($b, $m, '').always(function () {
			$b.prop('disabled', false);
			if (hosted) { $m.css('color', '#b26a00').removeClass('is-ko').text(hosted); return; }
			$m.css('color', '#0a7040').removeClass('is-ko').text(i18n.shotTest);
		});
	});
	// Off the email, not out of Klaviyo: the photograph stays where it is
	// hosted and paid for, and what goes is this email's claim on it. The
	// marker goes back where the URL was, so the place a picture belongs in is
	// still there and the next writing can fill it.
	$(document).on('click', '#dze-klav-e-nopic', function () {
		var url = String( picture().val() || '' ).trim();
		if (!url || !window.confirm(cfg.i18n.dropPic)) { return; }
		var el = body()[0];
		if (el && el.value.indexOf(url) !== -1) {
			el.value = el.value.split('src="' + url + '"').join('src="' + cfg.pictureMark + '"').split(url).join(cfg.pictureMark);
		}
		picture().val('');
		commit();
		render();
		drawHasPic();
		var $m = $('#dze-klav-shot-msg');
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_droppic',
			nonce: cfg.nonce,
			rule: ruleId(),
			email: current,
			body: body().val() || ''
		})
			.done(function (res) {
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$m.css('color', '#646970').removeClass('is-ko').text(cfg.i18n.pictureOff);
			})
			.fail(function () { $m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error); });
	});

	$(document).on('click', '#dze-klav-e-usepic', function () {
		if (!tested) { return; }
		rowShot(current, tested);
		$('#dze-klav-shot-msg').css('color', '#0a7040').removeClass('is-ko').text(i18n.shot);
		view('view');
	});

	// The addresses sit inside the event's own form, and Enter in a text field
	// submits a form. Typing an address and pressing Enter must send the test,
	// not save the event.
	$(document).on('keydown', '#dze-klav-e-to', function (e) {
		if (e.which === 13) { e.preventDefault(); $('#dze-klav-e-test').trigger('click'); }
	});

	// The email as an inbox will actually show it: Klaviyo renders and Klaviyo
	// sends, so nothing here can flatter the result.
	$(document).on('click', '#dze-klav-e-test', function () {
		var $b = $(this), $m = $('#dze-klav-e-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.sending);
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_test',
			nonce: cfg.nonce,
			rule: ruleId(),
			email: current,
			to: $('#dze-klav-e-to').val() || '',
			body: body().val() || ''
		})
			.done(function (res) {
				$b.prop('disabled', false);
				$m.css('color', res && res.success ? '#0a7040' : '#b32d2e')
					.toggleClass('is-ko', !( res && res.success ))
					.text((res && res.data && res.data.message) || i18n.error);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
			});
	});


}(jQuery));
