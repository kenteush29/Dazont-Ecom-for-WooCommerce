/* global dzeCatContent, jQuery */
/**
 * Category description writer: the Word count column opens a panel holding the
 * existing description in the WordPress editor. Generate to rewrite it, edit
 * freely, save — or undo. Prompt and SEMrush import available in place.
 */
(function ($) {
	'use strict';

	var cfg = dzeCatContent, i18n = cfg.i18n;

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return str.replace(/%\d\$s|%s/g, function () { return args[i++]; });
	}

	// Two hosts for the same panel: the popup on the categories list, which
	// brings its own editor, and the category edit screen, where the result
	// goes into the Description field WordPress already shows there.
	var EDITOR = 'dze-cc-editor';
	var original = {};

	function edId($box) { return $box.data('editor') || EDITOR; }

	function editorRemove() {
		if (window.wp && wp.editor && wp.editor.remove) {
			try { wp.editor.remove(EDITOR); } catch (e) {}
		}
	}
	function editorInit() {
		if (window.wp && wp.editor && wp.editor.initialize) {
			wp.editor.initialize(EDITOR, {
				tinymce: { wpautop: true, toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo' },
				quicktags: true,
				mediaButtons: false
			});
		}
	}
	function editorGet(id) {
		if (window.tinymce && tinymce.get(id) && !tinymce.get(id).isHidden()) {
			return tinymce.get(id).getContent();
		}
		return $('#' + id).val() || '';
	}
	function editorSet(id, html) {
		if (window.tinymce && tinymce.get(id) && !tinymce.get(id).isHidden()) {
			tinymce.get(id).setContent(html);
		}
		$('#' + id).val(html);
	}



	// A long run must show it is alive and must always end: without a timeout a
	// dropped connection leaves the spinner turning for ever.
	var LONG = 300000;
	function ticker($st, label) {
		var t0 = Date.now();
		var id = window.setInterval(function () {
			var sec = Math.round((Date.now() - t0) / 1000);
			$st.html('<span class="dze-cx-spin"></span> ' + esc(label) + ' ' + sec + 's');
		}, 1000);
		return function () { window.clearInterval(id); };
	}

	// A dead request is not "something went wrong": say what the server did, so
	// a timeout on a long generation is not confused with a broken plugin.
	function why(xhr, status) {
		if (status === 'timeout') { return i18n.tooLong; }
		if (!xhr || !xhr.status) { return i18n.noAnswer; }
		if (xhr.status === 504 || xhr.status === 408 || xhr.status === 502) { return sprintf(i18n.timedOut, xhr.status); }
		if (xhr.status === 403) { return i18n.expired; }
		if (xhr.status >= 500) { return sprintf(i18n.serverError, xhr.status); }
		return sprintf(i18n.serverError, xhr.status);
	}

	// Does this anchor name that page? Either it contains the name, or it
	// shares enough of its meaningful words — a title shortened to its subject
	// still names it, "read more" never does.
	var SMALL = /^(the|and|for|with|your|our|from|that|this|are|how|what|why|do|does|to|of|in|on|a|an|les|des|une|pour|avec|dans|sur|est)$/;
	function words(s) {
		return (s || '').toLowerCase().split(/[^a-z0-9à-ÿ]+/)
			.filter(function (w) { return w.length > 2 && !SMALL.test(w); });
	}
	function names(anchor, name) {
		var a = anchor.toLowerCase(), n = name.toLowerCase();
		if (!a) { return false; }
		if (a.indexOf(n) !== -1 || n.indexOf(a) !== -1) { return true; }
		var an = words(anchor), nn = words(name);
		if (!an.length || !nn.length) { return false; }
		var hits = an.filter(function (w) { return nn.indexOf(w) !== -1; }).length;
		return hits >= Math.min(2, nn.length);
	}

	// The links the description actually contains, read from the editor so the
	// list always matches what is on screen — before and after a run.
	function linkList($box) {
		var pool = $box.data('pool') || {}, rows = [];
		// A page linking to itself is always wrong, and it is not something the
		// anchor test can catch: the anchor names the page perfectly.
		var self = String($box.data('self') || '').replace(/\/+$/, '');
		$('<div>').html(editorGet(edId($box))).find('a[href]').each(function () {
			var href = $(this).attr('href') || '';
			var key = href.replace(/\/+$/, '');
			var name = pool[key] || '';
			var anchor = ($(this).text() || '').replace(/\s+/g, ' ').trim();
			rows.push({
				anchor: anchor,
				name: name,
				path: href.replace(/^https?:\/\/[^/]+/i, '') || href,
				external: cfg.home ? (href.indexOf(cfg.home) !== 0 && /^https?:/i.test(href)) : false,
				// The anchor has to name the target — not necessarily word for
				// word (an article title gets shortened to its subject), but
				// close enough that the destination is never in doubt.
				named: !name || names(anchor, name),
				self: !!self && key === self
			});
		});
		return rows;
	}

	function refreshLinks($box) {
		var rows = linkList($box), $ul = $box.find('.dze-cc-linklist'), $btn = $box.find('.dze-cc-ltoggle');
		if (!rows.length) {
			$ul.empty().hide();
			$btn.hide();
			return;
		}
		$ul.html(rows.map(function (r) {
			var target = r.name || r.path;
			return '<li><span class="dze-cc-anchor">' + esc(r.anchor || '—') + '</span> → ' +
				'<span class="dze-cc-target' + (r.external ? ' dze-cc-out' : '') + '">' + esc(target) + '</span>' +
				(r.external ? ' <em>' + esc(i18n.external) + '</em>' : '') +
				(r.named ? '' : ' <span class="dze-cc-out" title="' + esc(i18n.notNamed) + '">&#9888;</span>') +
				(r.self ? ' <span class="dze-cc-out" title="' + esc(i18n.selfLink) + '">&#8635;</span>' : '') +
				'</li>';
		}).join(''));
		$btn.show().text(sprintf(i18n.showLinks, rows.length) + ($ul.is(':visible') ? ' ▴' : ' ▾'));
	}

	$(document).on('click', '.dze-cc-ltoggle', function () {
		var $box = $(this).closest('.dze-cc-box');
		$box.find('.dze-cc-linklist').toggle();
		refreshLinks($box);
	});

	// Embedded panel: remember the description as it was, for Undo.
	$(function () {
		$('.dze-cc-box[data-editor]').each(function () {
			var id = $(this).data('editor');
			original[id] = $('#' + id).val() || '';
			refreshLinks($(this));
		});
	});

	$(document).on('click', '.dze-cc-open', function () {
		var id = $(this).data('id');
		editorRemove(); // a previous panel may still hold an instance.
		$('#dze-cc-body').html('<p><span class="dze-cx-spin"></span></p>');
		$('#dze-cc-modal').addClass('is-open');
		$.post(cfg.ajaxUrl, { action: 'dze_cc_panel', nonce: cfg.nonce, term: id })
			.done(function (res) {
				if (res && res.success) {
					$('#dze-cc-title').text(res.data.title);
					$('#dze-cc-body').html(res.data.html);
					original[EDITOR] = $('#' + EDITOR).val() || '';
					editorInit();
					refreshLinks($('#dze-cc-body').find('.dze-cc-box'));
				} else {
					$('#dze-cc-body').text((res && res.data && res.data.message) || i18n.error);
				}
			})
			.fail(function () { $('#dze-cc-body').text(i18n.error); });
	});
	$(document).on('click', '.dze-hub-close', editorRemove);
	$(document).on('click', '.dze-hub-close', function () { $(this).closest('.dze-cx-modal').removeClass('is-open'); });
	$(document).on('click', '#dze-cc-modal', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });

	// Data / prompt / HTML panels stay collapsed until asked for.
	$(document).on('click', '.dze-cc-dtoggle', function () { $(this).closest('.dze-cc-box').find('.dze-cc-data').toggle(); });
	$(document).on('click', '.dze-cc-ptoggle', function () { $(this).closest('.dze-cc-box').find('.dze-cc-pwrap').toggle(); });
	$(document).on('click', '.dze-cc-prestore', function () {
		$(this).closest('.dze-cc-box').find('.dze-cc-ptext').val(i18n.defaultPrompt);
	});
	$(document).on('click', '.dze-cc-psave', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_cc_save_prompt', nonce: $box.data('nonce'), prompt: $box.find('.dze-cc-ptext').val() })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (res && res.success) { $btn.text(i18n.savedPrompt); setTimeout(function () { $btn.text('💾 ' + i18n.savePrompt); }, 1800); }
				else { window.alert((res && res.data && res.data.message) || i18n.error); }
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	// ---- SEMrush import, straight from this panel ----
	// Reuses the Sourcing Assistant endpoints: upload → column mapping → import.
	$(document).on('click', '.dze-cc-imtoggle', function () {
		$(this).closest('.dze-cc-box').find('.dze-cc-import').toggle();
	});

	$(document).on('change', '.dze-cc-file', function () {
		var $box = $(this).closest('.dze-cc-box');
		var file = this.files && this.files[0];
		if (!file) { return; }
		var $st = $box.find('.dze-cc-imstatus').css('color', '#646970').removeClass('is-ko').html('<span class="dze-cx-spin"></span> ' + esc(i18n.reading));
		var fd = new FormData();
		fd.append('action', 'dze_kw_upload');
		fd.append('nonce', cfg.kwNonce);
		fd.append('file', file);
		$.ajax({ url: cfg.ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false })
			.done(function (res) {
				if (!res || !res.success) { $st.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#646970').removeClass('is-ko').text(res.data.total + ' rows');
				$box.data('token', res.data.token);
				renderMapping($box, res.data.headers, res.data.guess);
			})
			.fail(function () { $st.css('color', '#b32d2e').addClass('is-ko').text(i18n.error); });
	});

	// One select per column the importer understands, pre-set to its guess.
	function renderMapping($box, headers, guess) {
		var fields = [
			['keyword', i18n.colKeyword], ['volume', i18n.colVolume],
			['kd', i18n.colKd], ['cpc', i18n.colCpc], ['intent', i18n.colIntent]
		];
		var html = '';
		fields.forEach(function (f) {
			var sel = (guess && typeof guess[f[0]] !== 'undefined') ? guess[f[0]] : -1;
			html += '<label style="margin-right:12px;">' + esc(f[1]) + ' <select class="dze-cc-col" data-field="' + f[0] + '">' +
				'<option value="-1">' + esc(i18n.colNone) + '</option>';
			headers.forEach(function (h, i) {
				html += '<option value="' + i + '"' + (sel === i ? ' selected' : '') + '>' + esc(h) + '</option>';
			});
			html += '</select></label>';
		});
		$box.find('.dze-cc-mapfields').html(html);
		$box.find('.dze-cc-map').show();
	}

	$(document).on('click', '.dze-cc-doimport', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-cc-imstatus').css('color', '#646970').removeClass('is-ko').html('<span class="dze-cx-spin"></span> ' + esc(i18n.importing));
		var map = {};
		$box.find('.dze-cc-col').each(function () { map[$(this).data('field')] = parseInt($(this).val(), 10); });
		$.post(cfg.ajaxUrl, {
			action: 'dze_kw_import', nonce: cfg.kwNonce,
			cat: $box.data('term'), token: $box.data('token'), map: map
		})
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { $st.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error); return; }
				var msg = sprintf(i18n.imported, res.data.imported, res.data.updated);
				if (res.data.read && res.data.kept && res.data.read > res.data.kept) {
					msg += ' — ' + sprintf(i18n.trimmed, res.data.read, res.data.kept);
				}
				$st.css('color', '#0a7040').removeClass('is-ko').text(msg);
				// The keywords are in: the alert no longer applies, and the next
				// run reads them server-side whatever this panel still shows.
				$box.find('.dze-cc-warn').remove();
				if ($box.data('editor')) {
					return; // embedded panel: reloading would drop unsaved edits.
				}
				// Popup: reopen it so the query pools are listed.
				$('.dze-cc-open[data-id="' + $box.data('term') + '"]').trigger('click');
			})
			.fail(function (xhr, status) { stop(); $btn.prop('disabled', false); $st.css('color', '#b32d2e').addClass('is-ko').text(why(xhr, status)); });
	});

	// Every long run goes through the writing queue: the work happens in the
	// background, so the host can never cut it off, and this panel simply
	// follows its own job until the text comes back.
	function runJob($box, kind, urls, label, done, prompt) {
		var $st = $box.find('.dze-cc-status').css('color', '#646970').removeClass('is-ko');
		var t0 = Date.now(), poll = null;
		function tick(state) {
			var sec = Math.round((Date.now() - t0) / 1000);
			$st.html('<span class="dze-cx-spin"></span> ' + esc(state) + ' ' + sec + 's');
		}
		function stopPoll() { window.clearInterval(poll); poll = null; }
		tick(i18n.queuedShort);
		$.post(cfg.ajaxUrl, {
			action: 'dze_q_add', nonce: $box.data('qnonce'),
			kind: kind, id: $box.data('term'), urls: urls || [], prompt: prompt || ''
		})
			.done(function (res) {
				if (!res || !res.success || !res.data.job) {
					$st.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					done(false);
					return;
				}
				var job = res.data.job;
				poll = window.setInterval(function () {
					$.post(cfg.ajaxUrl, { action: 'dze_q_job', nonce: $box.data('qnonce'), id: job })
						.done(function (r) {
							if (!r || !r.success) { return; }
							if (r.data.status === 'queued') { tick(i18n.queuedShort); return; }
							if (r.data.status === 'running') { tick(label + ' — ' + (r.data.progress || '')); return; }
							stopPoll();
							if (r.data.status === 'failed') {
								$st.css('color', '#b32d2e').addClass('is-ko').text(r.data.error || i18n.error);
								done(false);
								return;
							}
							$st.css('color', '#646970').removeClass('is-ko').text('');
							done(true, r.data.html);
						})
						.fail(function (xhr, status) {
							stopPoll();
							$st.css('color', '#b32d2e').addClass('is-ko').text(why(xhr, status));
							done(false);
						});
				}, 1500);
			})
			.fail(function (xhr, status) {
				$st.css('color', '#b32d2e').addClass('is-ko').text(why(xhr, status));
				done(false);
			});
	}

	// A result already written by the queue: bring it into this panel rather
	// than sending the owner to another screen for it.
	$(document).on('click', '.dze-cc-loadjob', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-cc-status').css('color', '#646970').removeClass('is-ko').html('<span class="dze-cx-spin"></span>');
		$.post(cfg.ajaxUrl, { action: 'dze_q_job', nonce: $box.data('qnonce'), id: $btn.data('job') })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success || !res.data.html) {
					$st.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error);
					return;
				}
				editorSet(edId($box), res.data.html);
				refreshLinks($box);
				showDiff($box);
				$st.css('color', '#646970').removeClass('is-ko').text(i18n.review);
			})
			.fail(function (xhr, status) { $btn.prop('disabled', false); $st.css('color', '#b32d2e').addClass('is-ko').text(why(xhr, status)); });
	});

	// Nothing is written to the category before Save.
	$(document).on('click', '.dze-cc-gen', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		var $p = $box.find('.dze-cc-pwrap');
		var prompt = $p.is(':visible') ? ($box.find('.dze-cc-ptext').val() || '') : '';
		runJob($box, 'cat_desc', [], i18n.working, function (ok, html) {
			$btn.prop('disabled', false);
			if (!ok) { return; }
			editorSet(edId($box), html);
			refreshLinks($box);
			showDiff($box);
			$box.find('.dze-cc-status').css('color', '#646970').removeClass('is-ko').text(i18n.review);
		}, prompt);
	});

	// ---- Choosing the links before they are placed ----
	function pickCount($box) {
		var n = $box.find('.dze-cc-pick:checked:not(:disabled)').length;
		$box.find('.dze-cc-pickcount').text(sprintf(i18n.picked, n));
		$box.find('.dze-cc-links').prop('disabled', n < 1);
	}
	$(document).on('click', '.dze-cc-ltoggle-pick', function () {
		var $box = $(this).closest('.dze-cc-box');
		$box.find('.dze-cc-picker').toggle();
		pickCount($box);
	});
	$(document).on('change', '.dze-cc-pick', function () { pickCount($(this).closest('.dze-cc-box')); });
	$(document).on('click', '.dze-cc-pickall', function () {
		var $box = $(this).closest('.dze-cc-box');
		$box.find('.dze-cc-pick:not(:disabled)').prop('checked', true);
		pickCount($box);
	});
	$(document).on('click', '.dze-cc-picknone', function () {
		var $box = $(this).closest('.dze-cc-box');
		$box.find('.dze-cc-pick:not(:disabled)').prop('checked', false);
		pickCount($box);
	});

	// What the category holds TODAY, shown above the new text rather than beside
	// it. Two descriptions side by side in a popup are two narrow columns of
	// soup; the products screen settled this — one is being written, the other
	// is a reference you open when you need it.
	function showDiff($box) {
		var $wrap = $box.find('.dze-cc-diffwrap').show();
		var $out = $box.find('.dze-cc-diff').html('<p><span class="dze-cx-spin"></span></p>').show();
		$box.find('.dze-cc-difftoggle').text(i18n.hide);
		$.post(cfg.ajaxUrl, {
			action: 'dze_cc_diff', nonce: $box.data('nonce'), term: $box.data('term'), html: editorGet(edId($box))
		})
			.done(function (res) {
				if (!res || !res.success) { $wrap.hide(); return; }
				var d = res.data;
				$out.html(
					'<div class="dze-cb-nowtext">' +
						'<span class="dze-cb-nowlabel">' + esc(i18n.before) + ' — ' +
							esc(sprintf(i18n.wl, d.words[0], d.links[0])) + '</span>' +
						'<div class="dze-cb-nowbody">' +
							(d.before || '<p>' + esc(i18n.wasEmpty) + '</p>') +
						'</div>' +
					'</div>'
				);
				$box.find('.dze-cc-diffwords').text(sprintf(i18n.wl, d.words[1], d.links[1]));
			})
			.fail(function () { $wrap.hide(); });
	}

	$(document).on('click', '.dze-cc-difftoggle', function () {
		var $w = $(this).closest('.dze-cc-diffwrap');
		var $d = $w.find('.dze-cc-diff');
		if ($d.is(':visible')) { $d.hide(); $(this).text(i18n.show); return; }
		showDiff($w.closest('.dze-cc-box'));
	});

	// After a run, whatever is now in the text is shown as already linked.
	function markPlaced($box) {
		var done = {};
		linkList($box).forEach(function (r) { done[r.href.replace(/\/+$/, '')] = true; });
		$box.find('.dze-cc-pick').each(function () {
			if (done[this.value.replace(/\/+$/, '')]) {
				$(this).prop({ checked: true, disabled: true })
					.closest('label').find('.dze-cc-pick-done').remove().end()
					.append(' <span class="dze-cc-pick-done">' + esc(i18n.alreadyLinked) + '</span>');
			}
		});
		pickCount($box);
	}

	// Linking-only pass: the text stays, links come in. Still nothing saved.
	$(document).on('click', '.dze-cc-links', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		var urls = $box.find('.dze-cc-pick:checked:not(:disabled)').map(function () { return this.value; }).get();
		runJob($box, 'cat_links', urls, i18n.linking, function (ok, html) {
			$btn.prop('disabled', false);
			if (!ok) { return; }
			editorSet(edId($box), html);
			refreshLinks($box);
			markPlaced($box);
			showDiff($box);
		});
	});

	$(document).on('click', '.dze-cc-apply', function () {
		var $box = $(this).closest('.dze-cc-box'), $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-cc-status').css('color', '#646970').removeClass('is-ko').html('<span class="dze-cx-spin"></span>');
		$.post(cfg.ajaxUrl, { action: 'dze_cc_apply', nonce: $box.data('nonce'), term: $box.data('term'), html: editorGet(edId($box)) })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { $st.css('color', '#b32d2e').addClass('is-ko').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#0a7040').removeClass('is-ko').text(i18n.applied);
				// Keep the list cell in sync: word count + links now in the text.
				var $chip = $('.dze-cc-open[data-id="' + $box.data('term') + '"]');
				$chip.find('span').first().text(res.data.words + ' words').css('color', '#0a7040').removeClass('is-ko');
				var $meta = $chip.find('span').eq(1);
				if ($meta.length && $meta.hasClass('dze-caret') === false) {
					$meta.text(res.data.links + ' links');
				}
			})
			.fail(function (xhr, status) { stop(); $btn.prop('disabled', false); $st.css('color', '#b32d2e').addClass('is-ko').text(why(xhr, status)); });
	});

	$(document).on('click', '.dze-cc-revert', function () {
		var $b = $(this).closest('.dze-cc-box'), id = edId($b);
		editorSet(id, original[id] || '');
		refreshLinks($b);
		$b.find('.dze-cc-diffwrap').hide();
		$(this).closest('.dze-cc-box').find('.dze-cc-status').text('');
	});

}(jQuery));
