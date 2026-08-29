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
		$c.find('.dze-f-want').val($('#dze-klav-e-want').is(':checked') ? '1' : '0');
		$c.find('.dze-f-subject').val($('#dze-klav-e-subject').val() || '');
		$c.find('.dze-f-preview').val($('#dze-klav-e-preview').val() || '');
		$c.find('.dze-f-when').val($('#dze-klav-e-when').val() || '');
		$c.find('.dze-f-body').val(body().val() || '');
		$c.find('.dze-mail-subject').text($('#dze-klav-e-subject').val() || '');
		$c.find('.dze-mail-name').text(name || cfg.i18n.unnamed);
		$c.find('.dze-mail-when').contents().first().replaceWith(niceDay($('#dze-klav-e-when').val() || ''));
		markDupes();
		thumb(current);
	}
	$(document).on('input change', '#dze-klav-e-want, #dze-klav-e-type, #dze-klav-e-subject, #dze-klav-e-preview, #dze-klav-e-when', commit);

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

	function open(id) {
		var $c = card(id);
		if (!$c.length) { return; }
		current = id;
		$('.dze-mail').removeClass('is-on');
		$c.addClass('is-on');
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
		$('#dze-klav-e-msg').text('');
		$('#dze-klav-e-kept').text('');
		$('#dze-klav-write-msg').text('').removeClass('is-ko');
		// The picture bench belongs to the email that is open: whether the
		// next writing makes its picture. WHAT the picture shows is one prompt
		// for the shop, not a sentence per email.
		$('#dze-klav-e-want').prop('checked', '1' === ($c.find('.dze-f-want').val() || '0'));
		$('#dze-klav-shot-out').hide();
		$('#dze-klav-shot-msg').text('').removeClass('is-ko');
		drawHasPic();
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
			$b.data('undo', on ? '1' : '0')
				.text(on ? (cfg.i18n.unschedule || 'Unschedule') : (cfg.i18n.schedule || 'Schedule it'));
			$msg.css('color', on ? '#00794b' : '#646970').text((r.data && r.data.message) || '');
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
	$(document).on('click', '.dze-mail-i18n', function () {
		var $b = $(this),
			$row = $b.closest('.dze-mail'),
			$said = $row.find('.dze-mail-langs'),
			rule = $('#dze-klav-editor').data('rule'),
			mail = $row.data('id'),
			was = $b.text();

		$b.prop('disabled', true).text(cfg.i18nBusy || 'Translating…');

		$.post(cfg.ajaxUrl, { action: 'dze_klav_langs', nonce: cfg.nonce }).done(function (r) {
			var langs = (r && r.data && r.data.langs) || [], done = [], failed = [], msg = '';
			if (!langs.length) {
				stop('#b32d2e', cfg.i18nNone || 'No languages to translate into.');
				return;
			}

			// The languages are written AT THE SAME TIME. One after another was
			// one model call after another — minutes of somebody watching a
			// button — and they have nothing to say to each other. A few at a
			// time, not all of them: a shop with fifteen languages would ask
			// its own server for fifteen workers at once.
			var next = 0, live = 0, cap = Math.min(4, langs.length);
			tell();
			while (live < cap) { start(); }

			function start() {
				if (next >= langs.length) { return; }
				var lang = langs[next++];
				live++;
				$.post(cfg.ajaxUrl, {
					action: 'dze_klav_i18n', nonce: cfg.nonce,
					rule: rule, email: mail, lang: lang
				}).done(function (one) {
					if (one && one.success) { done.push(lang); }
					else {
						failed.push(lang);
						msg = msg || (one && one.data && one.data.message) || '';
					}
				}).fail(function (xhr) {
					failed.push(lang);
					msg = msg || ((cfg.i18nFail || 'The translation did not finish.') + why(xhr));
				}).always(function () {
					live--;
					tell();
					if (next < langs.length) { start(); } else if (!live) { save(); }
				});
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
					stop('#b32d2e', msg || (cfg.i18nFail || 'The translation did not finish.'));
					return;
				}
				$said.css('color', '').text(cfg.i18nSaving || 'Filing them in Klaviyo…');
				$.post(cfg.ajaxUrl, {
					action: 'dze_klav_i18nsave', nonce: cfg.nonce, rule: rule, email: mail
				}).done(function (r2) {
					if (!r2 || !r2.success) {
						stop('#b32d2e', (r2 && r2.data && r2.data.message) || (cfg.i18nFail || 'The translation did not finish.'));
						return;
					}
					var d = r2.data || {};
					stop('#00794b', (cfg.i18nDone || 'Translated — %d texts in %s')
						.replace('%d', d.done || 0)
						.replace('%s', (d.langs || done).join(', ').toUpperCase())
						+ (failed.length ? ' · ' + failed.join(', ').toUpperCase() + ' — ' + (msg || '') : ''));
					$b.text(cfg.i18nAgain || 'Translate again');
				}).fail(function (xhr) {
					stop('#b32d2e', (cfg.i18nFail || 'The translation did not finish.') + why(xhr));
				});
			}

			function stop(colour, text) {
				$said.css('color', colour).text(text);
				$b.prop('disabled', false);
				if ('#00794b' !== colour) { $b.text(was); }
			}
		}).fail(function (xhr) {
			$said.css('color', '#b32d2e').text((cfg.i18nFail || 'The translation did not finish.') + why(xhr));
			$b.prop('disabled', false).text(was);
		});

		function why(xhr) {
			var s = xhr && xhr.status;
			return s ? ' (' + s + (403 === s || 400 === s ? ' — reload the page and try again' : '') + ')' : '';
		}
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
	$(document).on('click', '#dze-mail-all', function () {
		var $b = $(this), $m = $('#dze-mail-plan-msg'),
			ids = $('.dze-mail').map(function () { return $(this).data('id'); }).get();
		if (!ids.length) { $m.css('color', '#b26a00').removeClass('is-ko').text(cfg.i18n.nothing); return; }
		$b.prop('disabled', true);
		(function next(i) {
			if (i >= ids.length) {
				$b.prop('disabled', false);
				$m.css('color', '#0a7040').removeClass('is-ko').text(cfg.i18n.allDone);
				return;
			}
			$m.css('color', '#646970').removeClass('is-ko').text(cfg.i18n.writing1.replace('%1$d', i + 1).replace('%2$d', ids.length));
			open(ids[i]);
			$.post(cfg.ajaxUrl, { action: 'dze_klav_write', nonce: cfg.nonce, rule: ruleId(), email: ids[i] })
				.done(function (res) {
					if (res && res.success) {
						$('#dze-klav-e-subject').val(res.data.subject || '');
						if (res.data.preview) { $('#dze-klav-e-preview').val(res.data.preview); }
						body().val(res.data.body || '');
						commit();
					}
					// One that failed does not stop the rest: the others are
					// worth having, and the one that failed is still there to
					// try again on its own.
					next(i + 1);
				})
				.fail(function () { next(i + 1); });
		}(0));
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
		var made = 0, failed = 0;
		(function next(i) {
			if (i >= jobs.length) {
				$b.prop('disabled', false);
				if (failed) {
					$m.css('color', '#b26a00').removeClass('is-ko')
						.text(cfg.i18n.draftSome.replace('%1$d', made).replace('%2$d', failed));
				} else {
					$m.css('color', '#0a7040').removeClass('is-ko').text(cfg.i18n.draftAll);
				}
				return;
			}
			$m.css('color', '#646970').removeClass('is-ko')
				.text(cfg.i18n.drafting1.replace('%1$d', i + 1).replace('%2$d', jobs.length));
			$.post(cfg.ajaxUrl, {
				action: 'dze_klav_draft',
				nonce: cfg.nonce,
				rule: ruleId(),
				email: jobs[i].id,
				body: jobs[i].body
			})
				.done(function (res) {
					if (res && res.success && res.data && res.data.url) {
						made += 1;
						card(jobs[i].id).find('.dze-mail-state').empty()
							.append($('<a target="_blank" rel="noopener noreferrer"/>')
								.attr('href', res.data.url).text(i18n.open));
					} else {
						failed += 1;
					}
					next(i + 1);
				})
				.fail(function () { failed += 1; next(i + 1); });
		}(0));
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

	$(function () {
		$('.dze-mail').each(function () { thumb($(this).data('id')); });
		markDupes();
		var $first = $('.dze-mail').first();
		if ($first.length) { open($first.data('id')); }
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

	function setPicture(url) {
		var el = body()[0], old = String( picture().val() || '' ).trim();
		picture().val(url);
		if (!el) { commit(); return; }
		if (el.value.indexOf(cfg.pictureMark) !== -1) {
			el.value = el.value.split(cfg.pictureMark).join(url);
		} else if (old && el.value.indexOf(old) !== -1) {
			el.value = el.value.split(old).join(url);
		} else if (el.value.indexOf(url) === -1) {
			// Nothing to swap: the writing left no place for a picture, or the
			// place it left has already been filled by another one. The
			// picture goes at the top, which is where an opening picture goes.
			//
			// This used to happen ONLY when the email had no image at all, and
			// a body with any image in it fell through every branch: "Use it in
			// this email" then did nothing whatsoever, silently. Guarded on the
			// URL itself instead, which is the real question — the same picture
			// must not be added twice, another one may.
			el.value = '<p style="margin:0 0 14px;"><img src="' + url + '" width="544" alt="" ' +
				'style="display:block;width:100%;max-width:544px;height:auto;border:0;" /></p>' + (el.value || '');
		}
		// AFTER the body was changed, never before: commit is what copies the
		// editor into the email's own fields, and running it first filed the
		// body as it stood a line earlier — with the marker still in it. The
		// picture was made, paid for, shown on screen, and the email saved
		// without it.
		commit();
		render();
		drawHasPic();
		// Kept with the email at once, like the writing keeps itself. It used
		// to wait for the event's Save, so a reload before that threw away the
		// photograph, the paragraph it sat in, and what it cost.
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_usepic', nonce: cfg.nonce,
			rule: ruleId(), email: current, url: url, body: el.value || ''
		}).done(function (res) {
			if (res && res.success) { return; }
			// Beside the picture it is about, like every other message on this
			// screen sits beside the button that produced it.
			$('#dze-klav-shot-msg').css('color', '#b32d2e').addClass('is-ko')
				.text((res && res.data && res.data.message) || i18n.error);
		}).fail(function (xhr) {
			// Silence here means the picture is on screen and nowhere else,
			// which is exactly the failure this call exists to prevent.
			$('#dze-klav-shot-msg').css('color', '#b32d2e').addClass('is-ko')
				.text('HTTP ' + (xhr ? xhr.status : 0));
		});
	}

	// Making the picture is a call of its own, and a slow one. It runs beside
	// the writing rather than inside it: two requests the server can each
	// finish, instead of one that a host cuts off at ninety seconds. The
	// description comes from the writing — there is one prompt, and it decides
	// the picture as well as the words.
	function makePicture($b, $m, prompt, then, test) {
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.shooting);
		return $.post(cfg.ajaxUrl, {
			action: 'dze_klav_image', nonce: cfg.nonce, rule: ruleId(), email: current,
			prompt: prompt || '', test: test ? 1 : 0
		})
			.done(function (res) {
				if (res && res.success) {
					if (test) { showTest(res.data.url, res.data.full); }
					else { setPicture(res.data.url); }
					hosted = res.data.warning || '';
					if (res.data.spend && res.data.spend.label) {
						$('#dze-klav-spend').text(res.data.spend.label).show();
					}
				} else {
					// No photograph: the email keeps its layout and loses its
					// hole, rather than shipping a broken image. A test that
					// failed changes nothing in the email at all.
					if (!test) { dropPlaceholder(); }
					$m.css('color', '#b26a00').removeClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
				}
				if (then) { then(); } else { $b.prop('disabled', false); }
			})
			.fail(function () {
				if (!test) { dropPlaceholder(); }
				$b.prop('disabled', false);
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
	function dropPlaceholder() {
		var el = body()[0];
		if (!el || el.value.indexOf(cfg.pictureMark) === -1) { return; }
		el.value = el.value.replace(
			new RegExp('<p[^>]*>\\s*<img[^>]*' + cfg.pictureMark + '[^>]*>\\s*<\\/p>|<img[^>]*' + cfg.pictureMark + '[^>]*>', 'gi'),
			''
		);
		render();
	}

	// What the writing is TOLD, printed on demand. It is built by the very
	// function that writes the email, with no model call: what is shown here
	// is what the next Generate sends, and there is no second version of it
	// to drift. An email that comes back looking like its neighbour is either
	// one that was never shown the neighbour or one that ignored it, and this
	// is how to tell which without taking anybody's word for it.
	function briefReset() {
		$('#dze-klav-brief').prop('open', false).data('for', '');
		$('#dze-klav-brief-txt').text('');
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

	function writeEmail($b, $m) {
		$b.prop('disabled', true);
		$m.css('color', '#646970').removeClass('is-ko').text(i18n.writing);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_write', nonce: cfg.nonce, rule: ruleId(), email: current })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$('#dze-klav-e-subject').val(res.data.subject);
				if (res.data.preview) { $('#dze-klav-e-preview').val(res.data.preview); }
				commit();
				body().val(res.data.body);
				commit();
				// This email has changed: what the OTHERS are told about it has too.
				briefReset();
				// A warning is not a failure: the email is there, and something
				// in it is worth reading twice before it goes out.
				var note = function () {
					$m.css('color', res.data.warning ? '#b32d2e' : '#b26a00')
						.toggleClass('is-ko', !!res.data.warning)
						.text(res.data.warning ? res.data.warning : i18n.written);
				};
				view('view');
				// The writing DESCRIBES the photograph; it does not order it.
				// Making one costs money and a minute, and firing that off on
				// every rewrite spends both on emails nobody had decided to
				// illustrate. The description is kept for the button beside
				// this one, and the screen says it is waiting.
				if (res.data.picture) {
					// The email left a place for a picture. What that picture
					// shows is the shop's own picture prompt, not a sentence
					// this writing came up with — so there is nothing to carry
					// from here but the fact that a place exists.
					if ($('#dze-klav-e-want').is(':checked')) {
						// Ready: the real picture is made in the same pass,
						// AFTER the email — so the picture prompt is given what
						// this email turned out to be, and its subject line.
						makePicture($('#dze-klav-e-shot'), $m, '', function () {
							$('#dze-klav-e-shot').prop('disabled', false);
							$m.css('color', '#0a7040').removeClass('is-ko').text(i18n.shot);
							view('view');
						});
						return;
					}
					$m.css('color', '#b26a00').removeClass('is-ko').text(res.data.warning || i18n.pictureReady);
					return;
				}
				note();
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').addClass('is-ko').text(i18n.error);
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
				var $state = card(current).find('.dze-mail-state').empty();
				$state.append($('<a target="_blank" rel="noopener noreferrer"/>')
					.attr('href', res.data.url).text(i18n.open));
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
		makePicture($b, $m, '', function () {
			$b.prop('disabled', false);
			if (hosted) { $m.css('color', '#b26a00').removeClass('is-ko').text(hosted); return; }
			$m.css('color', '#0a7040').removeClass('is-ko').text(i18n.shotTest);
		}, true);
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
		setPicture(tested);
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
