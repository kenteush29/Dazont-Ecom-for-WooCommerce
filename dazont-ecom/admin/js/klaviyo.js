/* global dzeKlav, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeKlav;
	var i18n = cfg.i18n;

	// ---- Settings: read the account, fill the pickers ----
	$(document).on('click', '#dze-klav-refresh', function () {
		var $b = $(this), $m = $('#dze-klav-refresh-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.loading);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_load', nonce: cfg.nonce })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
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
				$m.css('color', '#b32d2e').text(i18n.error);
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
			var text = $.trim($s.find('option:selected').text());
			$('input[name="' + cfg.opt + '[' + key + '_name]"]').val($s.val() ? text : '');
		});
	}
	$(document).on('change', '#dze-klav-inc, #dze-klav-exc', names);

	function tools() {
		var chosen = $('#dze-klav-exc').val();
		$('#dze-klav-activate').toggle(!!chosen && $.inArray(chosen, inactive) !== -1);
	}
	$(document).on('change', '#dze-klav-exc', function () {
		$('#dze-klav-seg-msg').text('');
		tools();
	});

	$(document).on('click', '#dze-klav-activate', function () {
		var $b = $(this), $m = $('#dze-klav-seg-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.working);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_activate', nonce: cfg.nonce, segment: $('#dze-klav-exc').val() })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				fill($('#dze-klav-inc'), res.data.audiences);
				fill($('#dze-klav-exc'), res.data.audiences);
				inactive = res.data.inactive || [];
				tools();
				$m.css('color', '#0a7040').text(res.data.message);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});

	$(document).on('click', '#dze-klav-make-seg', function () {
		var $b = $(this), $m = $('#dze-klav-seg-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.working);
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_segment',
			nonce: cfg.nonce,
			weeks: $('#dze-klav-weeks').val()
		})
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				fill($('#dze-klav-inc'), res.data.audiences);
				fill($('#dze-klav-exc'), res.data.audiences);
				inactive = res.data.inactive || [];
				// Built for this field, so it lands in it — and still has to be
				// saved, like everything else on this page.
				$('#dze-klav-exc').val(res.data.id);
				tools();
				$m.css('color', '#0a7040').text(res.data.message + ' ' + i18n.thenSave);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
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
	var idea = '';

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
			var url = $.trim(src[2]);
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
		var $c = card(id), html = $.trim($c.find('.dze-f-body').val() || '');
		draw($c.find('.dze-mail-thumb iframe'), html ? assemble(cfg.shell, html) : '');
	}

	function view(which) {
		$('.dze-klav-tab').removeClass('is-on').filter('[data-tab="' + which + '"]').addClass('is-on');
		if (which === 'view') { body().hide(); frame().show(); render(); }
		else { frame().hide(); body().show(); }
	}
	$(document).on('click', '.dze-klav-tab', function () { view($(this).data('tab')); });

	// Editor → the email's own fields, on every keystroke.
	function commit() {
		if (!current) { return; }
		var $c = card(current), name = $.trim($('#dze-klav-e-name').val() || '');
		$c.find('.dze-f-name').val(name);
		$c.find('.dze-f-subject').val($('#dze-klav-e-subject').val() || '');
		$c.find('.dze-f-preview').val($('#dze-klav-e-preview').val() || '');
		$c.find('.dze-f-when').val($('#dze-klav-e-when').val() || '');
		$c.find('.dze-f-body').val(body().val() || '');
		$c.find('.dze-mail-subject').text($('#dze-klav-e-subject').val() || '');
		$c.find('.dze-mail-name').text(name || cfg.i18n.unnamed);
		$c.find('.dze-mail-when').contents().first().replaceWith($('#dze-klav-e-when').val() || '');
		$('#dze-mail-title').text($c.find('.dze-mail-name').text());
		thumb(current);
	}
	$(document).on('input change', '#dze-klav-e-name, #dze-klav-e-subject, #dze-klav-e-preview, #dze-klav-e-when', commit);

	// Choosing the moment also sets the day it falls on: warm-up two days out,
	// launch on the opening day, reminder five days in, last chance two days
	// before it closes. A day changed afterwards stays changed — nothing here
	// runs again until the menu is used a second time.
	$(document).on('change', '#dze-klav-e-name', function () {
		var map = $('#dze-klav-editor').data('when') || {}, day = map[$(this).val()];
		if (day) { $('#dze-klav-e-when').val(day); }
		commit();
	});
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
		// An email named before this menu existed — by the writing, or by hand —
		// keeps its name: the option is added rather than the name dropped.
		var $name = $('#dze-klav-e-name'), had = $c.find('.dze-f-name').val() || '';
		if (had && !$name.find('option').filter(function () { return this.value === had; }).length) {
			$name.append($('<option/>').val(had).text(had));
		}
		$name.val(had || $name.find('option').first().val());
		$('#dze-mail-title').text($.trim($c.find('.dze-mail-name').text()));
		$('#dze-klav-e-subject').val($c.find('.dze-f-subject').val() || '');
		$('#dze-klav-e-preview').val($c.find('.dze-f-preview').val() || '');
		$('#dze-klav-e-when').val($c.find('.dze-f-when').val() || '');
		body().val($c.find('.dze-f-body').val() || '');
		$('#dze-klav-e-msg').text('');
		idea = '';
		$('#dze-mail-edit').show();
		view('view');
	}

	$(document).on('click', '.dze-mail-open', function () { open($(this).closest('.dze-mail').data('id')); });

	// A promotion holds as many emails as it deserves, not four. An id is
	// minted here and never reused, so two emails added in the same minute
	// cannot collide and overwrite one another on save.
	var minted = 0;
	function mintId() {
		minted += 1;
		return 'e' + Date.now().toString(36) + minted.toString(36);
	}

	$(document).on('click', '#dze-mail-new', function () {
		var id = mintId(),
			html = $('#dze-mail-blank').html().split('__ID__').join(id),
			$c = $($.trim(html));
		// A new email opens on the day the promotion does; the moment it
		// belongs to follows from whatever day it ends up on.
		$c.find('.dze-f-when').val($('#dze-klav-editor').data('newday') || '');
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
		$m.css('color', '#646970').text(cfg.i18n.planning);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_plan', nonce: cfg.nonce, rule: ruleId() })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				// The plan is already stored; the rows are redrawn from what it
				// returned so the page shows the same thing a reload would.
				$('.dze-mail-list').empty();
				$.each(res.data.emails, function (i, mail) {
					var html = $('#dze-mail-blank').html().split('__ID__').join(mail.id),
						$c = $($.trim(html));
					$c.find('.dze-f-when').val(mail.when);
					$c.find('.dze-f-name').val(mail.name);
					$c.find('.dze-mail-name').text(mail.name || cfg.i18n.unnamed);
					$c.find('.dze-mail-when').contents().first().replaceWith(mail.when);
					$('.dze-mail-list').append($c);
				});
				current = null;
				$('#dze-mail-edit').hide();
				var $first = $('.dze-mail').first();
				if ($first.length) { open($first.data('id')); }
				$m.css('color', '#0a7040').text(res.data.message);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});

	// Writing every email, one request each, in order. The same endpoint one
	// button uses for one email: there is no second way to write one, so a
	// batch cannot drift from what a single click does.
	$(document).on('click', '#dze-mail-all', function () {
		var $b = $(this), $m = $('#dze-mail-plan-msg'),
			ids = $('.dze-mail').map(function () { return $(this).data('id'); }).get();
		if (!ids.length) { $m.css('color', '#b26a00').text(cfg.i18n.nothing); return; }
		$b.prop('disabled', true);
		(function next(i) {
			if (i >= ids.length) {
				$b.prop('disabled', false);
				$m.css('color', '#0a7040').text(cfg.i18n.allDone);
				return;
			}
			$m.css('color', '#646970').text(cfg.i18n.writing1.replace('%1$d', i + 1).replace('%2$d', ids.length));
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

	$(document).on('click', '.dze-mail-drop', function () {
		if (!window.confirm(cfg.i18n.dropMail)) { return; }
		var $c = $(this).closest('.dze-mail'), id = $c.data('id'), $m = $('#dze-mail-plan-msg');
		// Taken out of the database at once, not left for the event's Save.
		// Writing an email stores it immediately, so removing one has to as
		// well: a row that disappears from the screen and comes back on the
		// next reload is the same email twice, and the owner is right to call
		// that broken.
		$c.remove();
		if (current === id) {
			current = null;
			$('#dze-mail-edit').hide();
		}
		$.post(cfg.ajaxUrl, { action: 'dze_klav_drop', nonce: cfg.nonce, rule: ruleId(), email: id })
			.done(function (res) {
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
				}
			})
			.fail(function () { $m.css('color', '#b32d2e').text(i18n.error); });
	});

	$(function () {
		$('.dze-mail').each(function () { thumb($(this).data('id')); });
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
		if (!head) { $m.css('color', '#b26a00').text(i18n.pickTpl); return; }
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.working);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_frame', nonce: cfg.nonce, header: head })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$('#dze-klav-shell').val(res.data.html);
				$('#dze-klav-fid').val(head);
				$('#dze-klav-fname').val(res.data.name || '');
				$('#dze-klav-tpl-hint').text(res.data.taken || '');
				drawShell();
				$m.css('color', '#b26a00').text(res.data.message);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});
	$(function () {
		if ($('#dze-klav-shell-frame').length) { drawShell(); }
		if ($('#dze-klav-e-iframe').length) { view('view'); }
	});

	$(document).on('click', '.dze-klav-tab', function () { view($(this).data('tab')); });

	function picture()    { return $('#dze-klav-e-pic'); }

	// The email is laid out by the model, which places the picture itself. So a
	// new picture SWAPS the one already in the email rather than being pasted
	// somewhere: the layout that was written stays the layout that was written.
	function setPicture(url) {
		var el = body()[0], old = $.trim(picture().val() || '');
		picture().val(url);
		commit();
		if (!el) { return; }
		if (el.value.indexOf(cfg.pictureMark) !== -1) {
			el.value = el.value.split(cfg.pictureMark).join(url);
		} else if (old && el.value.indexOf(old) !== -1) {
			el.value = el.value.split(old).join(url);
		} else if (!/<img\b/i.test(el.value || '')) {
			// Only when the email has no photograph at all. Prepending one to a
			// body that already has an image is how an email ends up with two,
			// and that is exactly what happened when the marker was being eaten
			// by the sanitiser and could not be found here.
			el.value = '<p style="margin:0 0 14px;"><img src="' + url + '" width="544" alt="" ' +
				'style="display:block;width:100%;max-width:544px;height:auto;border:0;" /></p>' + (el.value || '');
		}
		render();
	}

	// Making the picture is a call of its own, and a slow one. It runs beside
	// the writing rather than inside it: two requests the server can each
	// finish, instead of one that a host cuts off at ninety seconds. The
	// description comes from the writing — there is one prompt, and it decides
	// the picture as well as the words.
	function makePicture($b, $m, prompt, then) {
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.shooting);
		return $.post(cfg.ajaxUrl, { action: 'dze_klav_image', nonce: cfg.nonce, rule: ruleId(), email: current, prompt: prompt || '' })
			.done(function (res) {
				if (res && res.success) {
					setPicture(res.data.url);
				} else {
					// No photograph: the email keeps its layout and loses its
					// hole, rather than shipping a broken image.
					dropPlaceholder();
					$m.css('color', '#b26a00').text((res && res.data && res.data.message) || i18n.error);
				}
				if (then) { then(); } else { $b.prop('disabled', false); }
			})
			.fail(function () {
				dropPlaceholder();
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
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

	function writeEmail($b, $m) {
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.writing);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_write', nonce: cfg.nonce, rule: ruleId(), email: current })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$('#dze-klav-e-subject').val(res.data.subject);
				if (res.data.preview) { $('#dze-klav-e-preview').val(res.data.preview); }
				commit();
				body().val(res.data.body);
				commit();
				// A warning is not a failure: the email is there, and something
				// in it is worth reading twice before it goes out.
				var note = function () {
					$m.css('color', res.data.warning ? '#b32d2e' : '#b26a00')
						.text(res.data.warning ? res.data.warning : i18n.written);
				};
				view('view');
				// The writing DESCRIBES the photograph; it does not order it.
				// Making one costs money and a minute, and firing that off on
				// every rewrite spends both on emails nobody had decided to
				// illustrate. The description is kept for the button beside
				// this one, and the screen says it is waiting.
				if (res.data.picture) {
					idea = res.data.picture;
					$m.css('color', '#b26a00').text(res.data.warning || i18n.pictureReady);
					return;
				}
				note();
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	}

	// An email opens on a picture. Asking for one separately meant it went out
	// without one, so the writing makes it first when the event has none.
	$(document).on('click', '#dze-klav-e-write', function () {
		if (!current) { return; }
		writeEmail($(this), $('#dze-klav-e-msg'));
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
		commit();
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.creating);
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_draft',
			nonce: cfg.nonce,
			rule: ruleId(),
			email: current,
			body: body().val() || ''
		})
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				var $state = card(current).find('.dze-mail-state').empty();
				$state.append($('<a target="_blank" rel="noopener noreferrer"/>')
					.attr('href', res.data.url).text(i18n.open));
				$m.css('color', res.data.warning ? '#b26a00' : '#0a7040')
					.text(res.data.warning || i18n.made);
				window.open(res.data.url, '_blank', 'noopener');
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});

	// The picture, made when it is asked for and not before.
	$(document).on('click', '#dze-klav-e-shot', function () {
		var $b = $(this), $m = $('#dze-klav-e-msg');
		makePicture($b, $m, idea, function () {
			$b.prop('disabled', false);
			$m.css('color', '#0a7040').text(i18n.shot);
			view('view');
		});
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
		$m.css('color', '#646970').text(i18n.sending);
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
					.text((res && res.data && res.data.message) || i18n.error);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});


}(jQuery));
