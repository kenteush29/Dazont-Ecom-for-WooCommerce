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

	// ---- Events screen: one popup, opened from the row ----
	$(document).on('click', '.dze-klav-open', function (e) {
		e.preventDefault();
		var $cell = $(this).closest('.dze-klav-cell');
		$('#dze-klav-rule').val($cell.data('rule'));
		$('#dze-klav-name').val($cell.data('name') || $cell.data('title') || '');
		// Written on the event's screen, so it opens written here.
		$('#dze-klav-subject').val($cell.data('subject') || $cell.data('title') || '');
		$('#dze-klav-preview').val($cell.data('preview') || '');
		$('#dze-klav-when').val($cell.data('when') || '');
		$('#dze-klav-write-msg').text('');
		$('#dze-klav-status').text('');
		$('#dze-klav-modal').css('display', 'flex');
	});

	function close() { $('#dze-klav-modal').hide(); }
	$(document).on('click', '#dze-klav-cancel', close);
	$(document).on('click', '#dze-klav-modal', function (e) { if (e.target === this) { close(); } });

	// ---- Subject + preview text, written from the promotion ----
	$(document).on('click', '#dze-klav-write', function () {
		var $b = $(this), $m = $('#dze-klav-write-msg');
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.writing);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_write', nonce: cfg.nonce, rule: $('#dze-klav-rule').val() })
			.done(function (res) {
				$b.prop('disabled', false);
				if (res && res.success) {
					$('#dze-klav-subject').val(res.data.subject);
					if (res.data.preview) { $('#dze-klav-preview').val(res.data.preview); }
					$m.text('');
					return;
				}
				$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});

	// ---- Create the draft ----
	$(document).on('click', '#dze-klav-go', function () {
		var $b = $(this), $s = $('#dze-klav-status');
		var rule = $('#dze-klav-rule').val();
		var subject = $.trim($('#dze-klav-subject').val() || '');
		if (!subject) {
			$s.css('color', '#b32d2e').text(i18n.subject);
			$('#dze-klav-subject').trigger('focus');
			return;
		}
		$b.prop('disabled', true);
		$s.css('color', '#646970').text(i18n.creating);
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_draft',
			nonce: cfg.nonce,
			rule: rule,
			name: $('#dze-klav-name').val(),
			subject: subject,
			preview: $('#dze-klav-preview').val(),
			datetime: $('#dze-klav-when').val(),
			send: $('input[name="dze-klav-send"]:checked').val() || 'smart'
		})
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$s.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				// The row must read like the shop stands now, not like it did
				// when the page was opened.
				var $cell = $('.dze-klav-cell[data-rule="' + rule + '"]');
				$cell.find('.dze-klav-open').text(i18n.again);
				if (!$cell.find('.dze-klav-link').length) {
					$cell.prepend(
						$('<a class="dze-klav-link" target="_blank" rel="noopener noreferrer"/>')
							.attr('href', res.data.url).text(i18n.open)
					);
					$cell.find('.dze-klav-link').after(' <span style="color:#999;">|</span> ');
				} else {
					$cell.find('.dze-klav-link').attr('href', res.data.url);
				}
				$cell.find('.dze-klav-msg')
					.css('color', res.data.warning ? '#b26a00' : '#0a7040')
					.text(res.data.warning || i18n.made);
				$s.css('color', '#0a7040').text(i18n.made);
				window.open(res.data.url, '_blank', 'noopener');
				close();
			})
			.fail(function () {
				$b.prop('disabled', false);
				$s.css('color', '#b32d2e').text(i18n.error);
			});
	});

	// ---- The event's own screen: one panel, two views ----
	function body()   { return $('#dze-klav-e-body'); }
	function frame()  { return $('#dze-klav-e-iframe'); }
	function ruleId() { return $('#dze-klav-editor').data('rule'); }

	// srcdoc needs the frame to keep its own origin; an empty sandbox blocks
	// that and leaves a white rectangle, which is what "the preview does not
	// work" was. Scripts stay off — allow-scripts is not granted.
	function draw($frame, html) {
		var f = $frame[0];
		if (!f) { return; }
		f.setAttribute('sandbox', 'allow-same-origin');
		f.srcdoc = html;
	}

	// The email drawn where you are looking at it, as you type. The frame is
	// handed over once by PHP — the same frame the email is sent inside — so
	// nothing has to be asked of the server to see the result.
	function assemble(shell, inner) {
		var at = shell.indexOf(cfg.mark);
		if (at === -1) { return inner; }
		return shell.slice(0, at) + inner + shell.slice(at + cfg.mark.length);
	}

	// A template being edited still carries Klaviyo's own tags; on screen they
	// have nobody to fill them in, so they read as a person would see them.
	//
	// It runs AFTER the body has been put in, never before: {{ BODY }} is a
	// double-brace tag like any other, and clearing the tags first took the
	// marker with it — the frame then had nowhere to put the content, and the
	// preview showed the content alone, with no header and no footer.
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
	function live() {
		window.clearTimeout(pending);
		pending = window.setTimeout(render, 250);
	}

	function view(which) {
		$('.dze-klav-tab').removeClass('is-on').filter('[data-tab="' + which + '"]').addClass('is-on');
		if (which === 'view') {
			body().hide();
			frame().show();
			render();
		} else {
			frame().hide();
			body().show();
		}
	}

	$(document).on('input', '#dze-klav-e-body', live);

	// ---- Settings: the template, previewed the same way ----
	function drawShell() {
		draw($('#dze-klav-shell-frame'), readable(assemble($('#dze-klav-shell').val() || '', cfg.sample)));
	}
	function shellView(which) {
		$('.dze-klav-stab').removeClass('is-on').filter('[data-tab="' + which + '"]').addClass('is-on');
		var $ta = $('#dze-klav-shell'), $fr = $('#dze-klav-shell-frame');
		if (which === 'view') {
			$ta.hide();
			$fr.show();
			drawShell();
		} else {
			$fr.hide();
			$ta.show();
		}
	}
	$(document).on('click', '.dze-klav-stab', function () { shellView($(this).data('tab')); });

	// The header and the footer, lifted out of a template that already exists
	// in the account. The seam is found by the plugin — nothing to place by
	// hand, on this shop or the next one.
	$(document).on('click', '#dze-klav-take', function () {
		var $b = $(this), $m = $('#dze-klav-shell-msg'), head = $('#dze-klav-th').val();
		if (!head) { $m.css('color', '#b26a00').text(i18n.pickTpl); return; }
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(i18n.working);
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_frame',
			nonce: cfg.nonce,
			header: head,
			footer: $('#dze-klav-tf').val() || head
		})
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$('#dze-klav-shell').val(res.data.html);
				shellView('view');
				$m.css('color', '#b26a00').text(res.data.message);
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});
	// Redraw only. Switching the view back while somebody is typing in the
	// HTML would take the keyboard away from them mid-word.
	$(document).on('input', '#dze-klav-shell', function () {
		window.clearTimeout(pending);
		pending = window.setTimeout(drawShell, 300);
	});
	$(function () {
		if ($('#dze-klav-shell-frame').length) { shellView('view'); }
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
		if (!el) { return; }
		if (el.value.indexOf(cfg.pictureMark) !== -1) {
			el.value = el.value.split(cfg.pictureMark).join(url);
		} else if (old && el.value.indexOf(old) !== -1) {
			el.value = el.value.split(old).join(url);
		} else {
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
		return $.post(cfg.ajaxUrl, { action: 'dze_klav_image', nonce: cfg.nonce, rule: ruleId(), prompt: prompt || '' })
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
		$.post(cfg.ajaxUrl, { action: 'dze_klav_write', nonce: cfg.nonce, rule: ruleId() })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$('#dze-klav-e-subject').val(res.data.subject);
				if (res.data.preview) { $('#dze-klav-e-preview').val(res.data.preview); }
				body().val(res.data.body);
				// A warning is not a failure: the email is there, and something
				// in it is worth reading twice before it goes out.
				var note = function () {
					$m.css('color', res.data.warning ? '#b32d2e' : '#b26a00')
						.text(res.data.warning ? res.data.warning : i18n.written);
				};
				view('view');
				// The writing asked for a photograph: go and get it.
				if (res.data.picture) {
					makePicture($b, $m, res.data.picture, function () {
						$b.prop('disabled', false);
						note();
						view('view');
					});
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
		writeEmail($(this), $('#dze-klav-e-msg'));
	});

	// A different picture, when the one that is there is not the one wanted.
	$(document).on('click', '#dze-klav-e-shot', function () {
		var $b = $(this), $m = $('#dze-klav-e-msg');
		makePicture($b, $m, '', function () {
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
