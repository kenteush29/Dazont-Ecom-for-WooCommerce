/* global dzeContent, jQuery, tinymce */
/**
 * AI Content toolbox: a modal opened from the product side box. Three panes —
 * Text (per-field generate + apply, or generate-all), Image (fal.ai templates),
 * Price (cost × table → regular). Remembers the template choice across products.
 */
(function ($) {
	'use strict';

	var cfg = dzeContent, i18n = cfg.i18n;
	var MEM = 'dzeContentMem';

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
	function mem() { try { return JSON.parse(localStorage.getItem(MEM) || '{}'); } catch (e) { return {}; } }
	function saveMem(o) { try { localStorage.setItem(MEM, JSON.stringify(o)); } catch (e) {} }

	function setEditor(id, html) {
		if (window.tinymce && tinymce.get(id) && !tinymce.get(id).isHidden()) { tinymce.get(id).setContent(html); }
		else { $('#' + id).val(html); }
	}

	// ---- Build the modal once ----
	function fieldValidated(fid) {
		return !!(cfg.validated && cfg.validated[fid]);
	}
	function build() {
		if ($('#dze-cx-modal').length) { return; }
		var m = mem();
		var fieldCards = '';
		Object.keys(cfg.fields).forEach(function (fid) {
			var ok = fieldValidated(fid);
			fieldCards +=
				'<div class="dze-cx-field' + (ok ? '' : ' is-unvalidated') + '" data-field="' + fid + '">' +
				'<h4>' + esc(cfg.fields[fid]) +
				' <button type="button" class="dze-cx-vbtn' + (ok ? ' is-on' : '') + '" title="' + esc(i18n.validToggle) + '">' + (ok ? '✓ ' + esc(i18n.validated) : esc(i18n.notValid)) + '</button></h4>' +
				'<div class="dze-cx-pwrap" style="display:none;">' +
					'<textarea rows="6" class="dze-cx-p-text" title="' + esc(i18n.promptNote) + '"></textarea>' +
				'</div>' +
				'<textarea rows="4" class="dze-cx-out" placeholder="—"></textarea>' +
				'<div class="row-actions">' +
				'<button type="button" class="button button-small dze-cx-gen">' + esc(i18n.generate) + '</button>' +
				'<button type="button" class="button button-small dze-cx-apply"' + (ok ? '' : ' disabled title="' + esc(i18n.fieldLocked) + '"') + '>' + esc(i18n.apply) + '</button>' +
				'<button type="button" class="button-link dze-cx-p-toggle">✎ ' + esc(i18n.editPrompt) + '</button>' +
				'<button type="button" class="button-link dze-cx-p-save" style="display:none;">💾 ' + esc(i18n.savePrompt) + '</button>' +
				'</div></div>';
		});
		var tplOpts = cfg.templates.map(function (t, i) {
			return '<option value="' + i + '"' + (m.tpl == i ? ' selected' : '') + '>' + esc(t.name) + ' (' + esc(t.target) + ')' + (t.valid ? '' : ' — ' + esc(i18n.notValid)) + '</option>';
		}).join('');

		var html =
		'<div class="dze-cx-modal" id="dze-cx-modal"><div class="dze-cx-dialog">' +
			'<div class="dze-cx-head"><h2>' + esc(i18n.toolbox) + '</h2>' +
				'<div class="dze-cx-tabs">' +
					'<span class="dze-cx-tab" data-pane="text">' + esc(i18n.text) + '</span>' +
					'<span class="dze-cx-tab" data-pane="image">' + esc(i18n.image) + '</span>' +
					'<span class="dze-cx-tab" data-pane="price">' + esc(i18n.price) + '</span>' +
				'</div>' +
				'<button type="button" class="button dze-cx-close">' + esc(i18n.close) + '</button>' +
			'</div>' +
			'<div class="dze-cx-body">' +
				// TEXT pane
				'<div class="dze-cx-pane" data-pane="text">' +
					'<p class="dze-cx-topbar"><button type="button" class="button button-primary" id="dze-cx-genall">' + esc(i18n.genAll) + '</button></p>' +
					'<details class="dze-cx-acc dze-cx-src">' +
						'<summary>' + esc(i18n.productData) + '</summary>' +
						'<label>' + esc(i18n.pTitle) + '</label><input type="text" id="dze-cx-ptitle" value="' + esc(cfg.product.title) + '" />' +
						'<label>' + esc(i18n.pDesc) + '</label><textarea id="dze-cx-pdesc" rows="3">' + esc(cfg.product.desc) + '</textarea>' +
						'<label>' + esc(i18n.pAttr) + '</label><textarea id="dze-cx-pattr" rows="3">' + esc(cfg.product.attr || '') + '</textarea>' +
					'</details>' +
					'<div class="dze-cx-grid">' + fieldCards + '</div>' +
				'</div>' +
				// IMAGE pane
				'<div class="dze-cx-pane" data-pane="image">' +
					'<div class="dze-cx-imgrow">' +
						'<label>' + esc(i18n.template) + ' <select id="dze-cx-tpl">' + tplOpts + '</select></label>' +
						'<button type="button" class="button button-primary" id="dze-cx-genimg">' + esc(i18n.genImage) + '</button>' +
						'<button type="button" class="dze-cx-vbtn" id="dze-cx-tpl-validate" title="' + esc(i18n.validToggle) + '"></button>' +
						'<span id="dze-cx-imgstatus"></span>' +
					'</div>' +
					'<details class="dze-cx-acc">' +
						'<summary>✎ ' + esc(i18n.editPrompt) + '</summary>' +
						'<textarea id="dze-cx-tpl-prompt" rows="3" style="width:100%;box-sizing:border-box;" title="' + esc(i18n.promptNote) + '"></textarea>' +
						'<p style="margin:6px 0 0;"><button type="button" class="button-link" id="dze-cx-tpl-save">💾 ' + esc(i18n.savePrompt) + '</button></p>' +
					'</details>' +
					'<div class="dze-cx-galbar">' +
						'<label>' + esc(i18n.sendTo) + ' <select id="dze-cx-target"><option value="gallery">' + esc(i18n.toGallery) + '</option><option value="main">' + esc(i18n.toMain) + '</option></select></label>' +
						'<button type="button" class="button button-primary" id="dze-cx-attach" disabled>' + esc(i18n.addSelected) + ' (0)</button>' +
						'<span id="dze-cx-attachstatus"></span>' +
					'</div>' +
					'<div class="dze-cx-gal" id="dze-cx-gal"></div>' +
					'<div id="dze-cx-editrow" style="display:none;">' +
						'<p class="dze-cx-note" style="margin:8px 0 2px;">' + esc(i18n.variantHelp) + '</p>' +
						'<textarea id="dze-cx-editprompt" rows="2" style="width:100%;box-sizing:border-box;"></textarea>' +
						'<p style="margin:6px 0 0;">' +
							'<button type="button" class="button button-primary button-small" id="dze-cx-editgo">' + esc(i18n.genVariant) + '</button> ' +
							'<button type="button" class="button button-small" id="dze-cx-editcancel">' + esc(i18n.cancel) + '</button>' +
						'</p>' +
					'</div>' +
				'</div>' +
				// PRICE pane
				'<div class="dze-cx-pane" data-pane="price">' +
					'<p><label>' + esc(i18n.cost) + ' <input type="number" step="0.01" id="dze-cx-cost" value="' + esc(cfg.product.price) + '" style="width:120px;" /></label> ' +
					'<button type="button" class="button button-primary" id="dze-cx-recalc">' + esc(i18n.recalc) + '</button></p>' +
					'<p id="dze-cx-priceout" class="dze-cx-note"></p>' +
				'</div>' +
			'</div>' +
		'</div></div>';
		$('body').append(html);
	}

	function open(pane) {
		build();
		syncTplPrompt();
		refreshTplUi();
		$('#dze-cx-modal').addClass('is-open');
		showPane(pane || 'text');
	}
	function syncTplPrompt() {
		var i = parseInt($('#dze-cx-tpl').val(), 10) || 0;
		var t = cfg.templates[i] || {};
		$('#dze-cx-tpl-prompt').val(t.prompt || '');
	}
	function tplLabel(i) {
		var t = cfg.templates[i];
		return t.name + ' (' + t.target + ')' + (t.valid ? '' : ' — ' + i18n.notValid);
	}
	// Keeps the template select labels and the validation toggle in sync.
	function refreshTplUi() {
		$('#dze-cx-tpl option').each(function () {
			var ix = parseInt($(this).val(), 10);
			if (cfg.templates[ix]) { $(this).text(tplLabel(ix)); }
		});
		var i = parseInt($('#dze-cx-tpl').val(), 10) || 0;
		var t = cfg.templates[i] || {};
		$('#dze-cx-tpl-validate').toggleClass('is-on', !!t.valid).text(t.valid ? '✓ ' + i18n.validated : i18n.notValid);
	}
	function showPane(p) {
		$('.dze-cx-tab').removeClass('is-active').filter('[data-pane="' + p + '"]').addClass('is-active');
		$('.dze-cx-pane').removeClass('is-active').filter('[data-pane="' + p + '"]').addClass('is-active');
	}

	$('#dze-cx-open-text').on('click', function () { open('text'); });
	$('#dze-cx-open-image').on('click', function () { open('image'); });
	$(document).on('click', '.dze-cx-tab', function () { showPane($(this).data('pane')); });
	$(document).on('click', '.dze-cx-close', function () { $('#dze-cx-modal').removeClass('is-open'); });
	$(document).on('click', '#dze-cx-modal', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });

	function productData() {
		return {
			title: $('#dze-cx-ptitle').val() || '',
			desc: $('#dze-cx-pdesc').val() || '',
			attr: $('#dze-cx-pattr').val() || ''
		};
	}

	// Per-card prompt editor: opens with the current prompt; edits apply to the
	// next generation immediately; 💾 persists them to the settings.
	$(document).on('click', '.dze-cx-p-toggle', function () {
		var $card = $(this).closest('.dze-cx-field'), fid = $card.data('field');
		var $wrap = $card.find('.dze-cx-pwrap');
		if (!$wrap.data('filled')) { $wrap.find('.dze-cx-p-text').val((cfg.prompts && cfg.prompts[fid]) || ''); $wrap.data('filled', 1); }
		$wrap.toggle();
		$card.find('.dze-cx-p-save').toggle($wrap.is(':visible'));
	});
	$(document).on('click', '.dze-cx-p-save', function () {
		var $card = $(this).closest('.dze-cx-field'), fid = $card.data('field');
		var val = $card.find('.dze-cx-p-text').val(), $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_content_save_prompt', nonce: cfg.nonce, ptype: 'field', field: fid, prompt: val })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (res.success) { cfg.prompts[fid] = val; $btn.text(i18n.savedPrompt); setTimeout(function () { $btn.text('💾 ' + i18n.savePrompt); }, 1800); }
				else { window.alert((res.data && res.data.message) || i18n.error); }
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});
	// The card's live prompt when its editor is open and differs from the stored one.
	function cardPromptOverride($card) {
		var $wrap = $card.find('.dze-cx-pwrap');
		if (!$wrap.is(':visible')) { return ''; }
		var fid = $card.data('field'), val = $wrap.find('.dze-cx-p-text').val() || '';
		return (val !== '' && val !== ((cfg.prompts && cfg.prompts[fid]) || '')) ? val : '';
	}

	function genField($card) {
		var fid = $card.data('field'), pd = productData();
		var $ta = $card.find('.dze-cx-out'), $btn = $card.find('.dze-cx-gen').prop('disabled', true);
		$ta.val(i18n.generating);
		return $.post(cfg.ajaxUrl, { action: 'dze_content_text', nonce: cfg.nonce, field: fid, title: pd.title, desc: pd.desc, attr: pd.attr, prompt: cardPromptOverride($card) })
			.done(function (res) {
				$btn.prop('disabled', false);
				$ta.val(res.success ? res.data.text : ((res.data && res.data.message) || i18n.error));
			})
			.fail(function () { $btn.prop('disabled', false); $ta.val(i18n.error); });
	}

	$(document).on('click', '.dze-cx-gen', function () { genField($(this).closest('.dze-cx-field')); });

	// One single API call generates every field at once (each keeps its own prompt).
	$(document).on('click', '#dze-cx-genall', function () {
		var $btn = $(this).prop('disabled', true);
		var pd = productData();
		$('.dze-cx-field .dze-cx-out').val(i18n.generating);
		var overrides = {};
		$('.dze-cx-field').each(function () {
			var ov = cardPromptOverride($(this));
			if (ov) { overrides[$(this).data('field')] = ov; }
		});
		$.post(cfg.ajaxUrl, { action: 'dze_content_text_all', nonce: cfg.nonce, post: cfg.postId, title: pd.title, desc: pd.desc, attr: pd.attr, prompts: overrides })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) {
					window.alert((res.data && res.data.message) || i18n.error);
					$('.dze-cx-field .dze-cx-out').val('');
					return;
				}
				var texts = res.data.texts || {};
				$('.dze-cx-field').each(function () {
					var fid = $(this).data('field');
					$(this).find('.dze-cx-out').val(texts[fid] || '');
				});
			})
			.fail(function () {
				$btn.prop('disabled', false);
				window.alert(i18n.error);
				$('.dze-cx-field .dze-cx-out').val('');
			});
	});

	$(document).on('click', '.dze-cx-apply', function () {
		var $card = $(this).closest('.dze-cx-field'), fid = $card.data('field');
		var val = $card.find('.dze-cx-out').val();
		var $btn = $(this);
		if (!fieldValidated(fid)) { window.alert(i18n.fieldLocked); return; }
		$btn.prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_content_apply', nonce: cfg.nonce, post: cfg.postId, field: fid, value: val })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				if (res.data && res.data.note) { window.alert(res.data.note); }
				// Reflect into the classic editors where relevant.
				if (fid === 'description') { setEditor('content', val); }
				if (fid === 'short') { setEditor('excerpt', val); }
				if (fid === 'title') { $('#title').val(val.replace(/<[^>]+>/g, '')); }
				$btn.text(i18n.applied); setTimeout(function () { $btn.text(i18n.apply); }, 1500);
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	// ---- Image: session gallery ----
	// Every generation lands here (nothing auto-attached). The gallery lives for
	// the whole page visit, so closing/reopening the popup keeps the images.
	var gal = [];      // { url, sel, added }
	var editSrc = null; // index being edited with a manual prompt

	$(document).on('change', '#dze-cx-tpl', function () {
		var m = mem(); m.tpl = $(this).val(); saveMem(m);
		syncTplPrompt();
		refreshTplUi();
	});

	// ---- One-click validation toggles (persisted in the prompt registry) ----
	$(document).on('click', '.dze-cx-field .dze-cx-vbtn', function () {
		var $card = $(this).closest('.dze-cx-field'), fid = $card.data('field');
		var on = fieldValidated(fid) ? 0 : 1, $b = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_content_validate_prompt', nonce: cfg.nonce, ptype: 'field', field: fid, on: on })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				cfg.validated[fid] = !!on;
				$b.toggleClass('is-on', !!on).text(on ? '✓ ' + i18n.validated : i18n.notValid);
				$card.toggleClass('is-unvalidated', !on);
				$card.find('.dze-cx-apply').prop('disabled', !on).attr('title', on ? '' : i18n.fieldLocked);
			})
			.fail(function () { $b.prop('disabled', false); window.alert(i18n.error); });
	});
	$(document).on('click', '#dze-cx-tpl-validate', function () {
		var i = parseInt($('#dze-cx-tpl').val(), 10) || 0;
		var t = cfg.templates[i];
		if (!t) { return; }
		var on = t.valid ? 0 : 1, $b = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_content_validate_prompt', nonce: cfg.nonce, ptype: 'template', index: i, on: on })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				t.valid = !!on;
				refreshTplUi();
			})
			.fail(function () { $b.prop('disabled', false); window.alert(i18n.error); });
	});

	$(document).on('click', '#dze-cx-tpl-save', function () {
		var idx = parseInt($('#dze-cx-tpl').val(), 10) || 0;
		var val = $('#dze-cx-tpl-prompt').val(), $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_content_save_prompt', nonce: cfg.nonce, ptype: 'template', index: idx, prompt: val })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (res.success) { cfg.templates[idx].prompt = val; $btn.text(i18n.savedPrompt); setTimeout(function () { $btn.text('💾 ' + i18n.savePrompt); }, 1800); }
				else { window.alert((res.data && res.data.message) || i18n.error); }
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	function renderGal() {
		var html = '';
		gal.forEach(function (g, i) {
			html += '<div class="dze-cx-thumb' + (g.sel ? ' is-sel' : '') + (g.added ? ' is-added' : '') + '" data-i="' + i + '">' +
				'<img src="' + g.url + '" alt="" />' +
				'<span class="dze-cx-t-check" title="' + esc(i18n.select) + '">✓</span>' +
				'<button type="button" class="dze-cx-t-edit" title="' + esc(i18n.editImage) + '">✎</button>' +
				(g.added ? '<span class="dze-cx-t-tag">' + esc(i18n.added) + '</span>' : '') +
				'</div>';
		});
		$('#dze-cx-gal').html(html);
		updateAttachBtn();
	}
	function updateAttachBtn() {
		var n = gal.filter(function (g) { return g.sel && !g.added; }).length;
		$('#dze-cx-attach').prop('disabled', !n).text(i18n.addSelected + ' (' + n + ')');
	}
	function addToGal(url) {
		gal.push({ url: url, sel: false, added: false });
		renderGal();
	}

	function genImage(params) {
		var $btn = $('#dze-cx-genimg, #dze-cx-editgo').prop('disabled', true);
		var $st = $('#dze-cx-imgstatus').css('color', '#646970').html('<span class="dze-cx-spin"></span> ' + esc(i18n.imgWait));
		var data = $.extend({ action: 'dze_content_image', nonce: cfg.nonce, post: cfg.postId, mode: 'defer' }, params);
		$.post(cfg.ajaxUrl, data)
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { $st.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#00794b').text(i18n.imgReady);
				addToGal(res.data.url);
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
	}

	$(document).on('click', '#dze-cx-genimg', function () {
		var idx = parseInt($('#dze-cx-tpl').val(), 10) || 0;
		var txt = $('#dze-cx-tpl-prompt').val() || '';
		var stored = (cfg.templates[idx] && cfg.templates[idx].prompt) || '';
		genImage({ template: idx, custom_prompt: (txt !== stored ? txt : '') });
	});

	// Selection — native-WordPress feel: click the thumb (or its check) to toggle.
	$(document).on('click', '.dze-cx-thumb', function (e) {
		if ($(e.target).closest('.dze-cx-t-edit').length) { return; }
		var i = $(this).data('i');
		if (gal[i].added) { return; }
		gal[i].sel = !gal[i].sel;
		renderGal();
	});

	// Edit an image with a one-off manual prompt → the variant joins the gallery.
	$(document).on('click', '.dze-cx-t-edit', function (e) {
		e.stopPropagation();
		editSrc = $(this).closest('.dze-cx-thumb').data('i');
		$('#dze-cx-editrow').show();
		$('#dze-cx-editprompt').val('').trigger('focus');
	});
	$(document).on('click', '#dze-cx-editcancel', function () { $('#dze-cx-editrow').hide(); editSrc = null; });
	$(document).on('click', '#dze-cx-editgo', function () {
		var p = $('#dze-cx-editprompt').val() || '';
		if (editSrc === null || !p.trim()) { return; }
		genImage({ src_url: gal[editSrc].url, custom_prompt: p });
	});

	// Push the selection onto the product (SEO naming happens server-side).
	$(document).on('click', '#dze-cx-attach', function () {
		var urls = gal.filter(function (g) { return g.sel && !g.added; }).map(function (g) { return g.url; });
		if (!urls.length) { return; }
		var $btn = $(this).prop('disabled', true);
		var $st = $('#dze-cx-attachstatus').css('color', '#646970').html('<span class="dze-cx-spin"></span>');
		$.post(cfg.ajaxUrl, { action: 'dze_content_image_attach', nonce: cfg.nonce, post: cfg.postId, urls: urls, target: $('#dze-cx-target').val() })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { $st.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#00794b').text(res.data.attached + ' ' + i18n.attachDone);
				gal.forEach(function (g) { if (g.sel && !g.added) { g.added = true; g.sel = false; } });
				renderGal();
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
	});

	// ---- Price ----
	$(document).on('click', '#dze-cx-recalc', function () {
		var cost = $('#dze-cx-cost').val();
		var $out = $('#dze-cx-priceout').text(i18n.generating);
		$.post(cfg.ajaxUrl, { action: 'dze_content_price', nonce: cfg.nonce, post: cfg.postId, cost: cost })
			.done(function (res) {
				if (!res.success) { $out.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error); return; }
				$out.css('color', '#1d2327').text('× ' + res.data.mult + ' → ' + i18n.newPrice + ': ' + res.data.regular + (res.data.applied ? ' ✓' : ' (' + i18n.previewOnly + ')'));
				if (res.data.applied) { $('#_regular_price').val(res.data.regular); }
			})
			.fail(function () { $out.css('color', '#b32d2e').text(i18n.error); });
	});

}(jQuery));
