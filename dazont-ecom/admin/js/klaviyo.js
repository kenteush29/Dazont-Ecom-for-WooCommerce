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
			datetime: $('#dze-klav-when').val()
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
	function show(html) {
		var f = frame()[0];
		if (!f) { return; }
		f.setAttribute('sandbox', 'allow-same-origin');
		f.srcdoc = html;
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

	function render() {
		var $m = $('#dze-klav-e-msg');
		$m.css('color', '#646970').text(i18n.rendering);
		$.post(cfg.ajaxUrl, {
			action: 'dze_klav_preview',
			nonce: cfg.nonce,
			rule: ruleId(),
			body: body().val() || ''
		})
			.done(function (res) {
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$m.text('');
				show(res.data.html);
			})
			.fail(function () { $m.css('color', '#b32d2e').text(i18n.error); });
	}

	$(document).on('click', '.dze-klav-tab', function () { view($(this).data('tab')); });

	// Insert at the cursor, like any editor: what you were writing stays where
	// it was instead of being appended somewhere else.
	function insert(html) {
		var el = body()[0];
		if (!el) { return; }
		var at = el.selectionStart || 0;
		var to = el.selectionEnd || at;
		el.value = el.value.slice(0, at) + html + el.value.slice(to);
		el.selectionStart = el.selectionEnd = at + html.length;
		el.focus();
	}

	$(document).on('click', '#dze-klav-e-write', function () {
		var $b = $(this), $m = $('#dze-klav-e-msg');
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
				$m.css('color', '#b26a00').text(i18n.written);
				view('view');
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(i18n.error);
			});
	});

	$(document).on('click', '#dze-klav-e-prod', function () {
		var $m = $('#dze-klav-e-msg');
		$m.css('color', '#646970').text(i18n.working);
		$.post(cfg.ajaxUrl, { action: 'dze_klav_products', nonce: cfg.nonce, rule: ruleId() })
			.done(function (res) {
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				$m.text('');
				view('code');
				insert('\n' + res.data.html + '\n');
			})
			.fail(function () { $m.css('color', '#b32d2e').text(i18n.error); });
	});

	$(document).on('click', '#dze-klav-e-img', function () {
		if (!window.wp || !wp.media) { return; }
		var f = wp.media({ title: i18n.pick, multiple: false, library: { type: 'image' } });
		f.on('select', function () {
			var img = f.state().get('selection').first().toJSON();
			var url = (img.sizes && img.sizes.large ? img.sizes.large.url : img.url);
			var alt = (img.alt || '').replace(/"/g, '&quot;');
			view('code');
			insert('\n<img src="' + url + '" width="544" alt="' + alt +
				'" style="display:block;width:100%;max-width:544px;height:auto;border:0;" />\n');
		});
		f.open();
	});

}(jQuery));
