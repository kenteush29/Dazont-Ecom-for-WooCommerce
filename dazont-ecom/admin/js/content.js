/* global dzeContent, jQuery, tinymce */
/**
 * AI Content toolbox. Default flow = "Automatic edition": tick what to
 * generate, Launch, review everything in an editable preview, apply. The Text
 * and Image labs stay available (Test box) for previews and prompt tuning —
 * prompts are hidden behind discreet ✎ icons everywhere.
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

	function fieldValidated(fid) {
		return !!(cfg.validated && cfg.validated[fid]);
	}
	// One source of truth for a field's validation UI, wherever the card lives
	// (Text lab and Review both hold a card for the same field).
	function syncFieldValidUi(fid) {
		var on = fieldValidated(fid);
		$('.dze-cx-field[data-field="' + fid + '"]').each(function () {
			var $c = $(this);
			$c.toggleClass('is-unvalidated', !on);
			$c.find('.dze-cx-vbtn').toggleClass('is-on', on).text(on ? '✓ ' + i18n.validated : i18n.notValid);
			$c.find('.dze-cx-apply').prop('disabled', !on).attr('title', on ? '' : i18n.fieldLocked);
		});
	}

	// ---- Build the modal once ----
	function build() {
		if ($('#dze-cx-modal').length) { return; }
		var m = mem();
		var au = m.auto || {};

		// Text-lab cards: prompt hidden behind a small ✎ icon in the header.
		var fieldCards = '';
		Object.keys(cfg.fields).forEach(function (fid) {
			var ok = fieldValidated(fid);
			fieldCards +=
				'<div class="dze-cx-field' + (ok ? '' : ' is-unvalidated') + '" data-field="' + fid + '">' +
				'<h4>' + esc(cfg.fields[fid]) +
				' <button type="button" class="dze-cx-vbtn' + (ok ? ' is-on' : '') + '" title="' + esc(i18n.validToggle) + '">' + (ok ? '✓ ' + esc(i18n.validated) : esc(i18n.notValid)) + '</button>' +
				'<button type="button" class="dze-cx-icon dze-cx-p-toggle" title="' + esc(i18n.editPrompt) + '">✎</button></h4>' +
				'<div class="dze-cx-pwrap" style="display:none;">' +
					'<textarea rows="6" class="dze-cx-p-text" title="' + esc(i18n.promptNote) + '"></textarea>' +
					'<p style="margin:4px 0 0;"><button type="button" class="button-link dze-cx-p-save">💾 ' + esc(i18n.savePrompt) + '</button>' +
					((cfg.defaults && cfg.defaults[fid]) ? ' <button type="button" class="button-link dze-cx-p-restore">↺ ' + esc(i18n.restore) + '</button>' : '') +
				'</p>' +
				'</div>' +
				'<textarea rows="4" class="dze-cx-out" placeholder="—"></textarea>' +
				'<div class="row-actions">' +
				'<button type="button" class="button button-small dze-cx-gen">' + esc(i18n.generate) + '</button>' +
				'<button type="button" class="button button-small dze-cx-apply"' + (ok ? '' : ' disabled title="' + esc(i18n.fieldLocked) + '"') + '>' + esc(i18n.apply) + '</button>' +
				'</div></div>';
		});
		var tplOpts = cfg.templates.map(function (t, i) {
			return '<option value="' + i + '"' + (m.tpl == i ? ' selected' : '') + '>' + esc(t.name) + '</option>';
		}).join('');

		// Automatic-edition checkboxes (restored from the saved setup).
		var auChecks = '';
		Object.keys(cfg.fields).forEach(function (fid) {
			var checked = au.fields ? !!au.fields[fid] : true;
			auChecks +=
				'<label class="dze-au-check"><input type="checkbox" class="dze-au-f" value="' + fid + '"' + (checked ? ' checked' : '') + ' /> ' +
				esc(cfg.fields[fid]) + (fieldValidated(fid) ? '' : ' <span class="dze-cx-badge">' + esc(i18n.notValid) + '</span>') + '</label>';
		});
		var dataUsed = (cfg.inputsUsed || []).map(esc).join(' · ');

		var html =
		'<div class="dze-cx-modal" id="dze-cx-modal"><div class="dze-cx-dialog">' +
			'<div class="dze-cx-head"><h2>' + esc(i18n.toolbox) + '</h2>' +
				'<div class="dze-cx-tabs">' +
					'<span class="dze-cx-tab" data-pane="auto">' + esc(i18n.auto) + '</span>' +
					'<span class="dze-cx-tab" data-pane="review" style="display:none;">' + esc(i18n.review) + '</span>' +
					'<span class="dze-cx-tab" data-pane="text">' + esc(i18n.text) + '</span>' +
					'<span class="dze-cx-tab" data-pane="image">' + esc(i18n.image) + '</span>' +
					'<span class="dze-cx-tab" data-pane="price">' + esc(i18n.price) + '</span>' +
				'</div>' +
				'<button type="button" class="button dze-cx-close">' + esc(i18n.close) + '</button>' +
			'</div>' +
			'<div class="dze-cx-body">' +
				// AUTOMATIC EDITION pane (default)
				'<div class="dze-cx-pane" data-pane="auto">' +
					'<div class="dze-cx-setup">' +
						'<h3>' + esc(i18n.whatToGen) + '</h3>' +
						'<div class="dze-au-fields">' + auChecks + '</div>' +
						'<label class="dze-au-check"><input type="checkbox" id="dze-au-price"' + (au.price === undefined || au.price ? ' checked' : '') + ' /> ' + esc(i18n.priceOpt) +
							' <input type="number" step="0.01" id="dze-au-cost" value="' + esc(cfg.product.price) + '" style="width:100px;" /></label>' +
						(cfg.templates.length ?
							'<label class="dze-au-check"><input type="checkbox" id="dze-au-img"' + (au.img ? ' checked' : '') + ' /> ' + esc(i18n.genImgOpt) +
							' <select id="dze-au-tpl">' + cfg.templates.map(function (t, i) {
								return '<option value="' + i + '"' + ((au.tpl !== undefined ? au.tpl : m.tpl) == i ? ' selected' : '') + '>' + esc(t.name) + (t.valid ? '' : ' — ' + esc(i18n.notValid)) + '</option>';
							}).join('') + '</select>' +
							' <select id="dze-au-imgn" title="' + esc(i18n.imgCount) + '">' +
								[1, 2, 3, 4].map(function (n) { return '<option value="' + n + '"' + ((au.imgn || 1) == n ? ' selected' : '') + '>×' + n + '</option>'; }).join('') +
							'</select></label>' : '') +
						'<label class="dze-au-check dze-cx-note"><input type="checkbox" id="dze-au-save" checked /> ' + esc(i18n.saveSetup) + '</label>' +
						'<p style="margin:14px 0 0;"><button type="button" class="button button-primary button-hero" id="dze-au-launch">' + esc(i18n.launch) + '</button> <span id="dze-au-status"></span></p>' +
					'</div>' +
					'<div class="dze-cx-testbox">' +
						'<strong>' + esc(i18n.testBox) + '</strong> <span class="dze-cx-note">' + esc(i18n.testNote) + '</span><br />' +
						'<button type="button" class="button button-small" id="dze-au-prevtext">' + esc(i18n.previewText) + '</button> ' +
						'<button type="button" class="button button-small" id="dze-au-previmg">' + esc(i18n.previewImg) + '</button>' +
					'</div>' +
				'</div>' +
				// REVIEW pane (filled after Launch)
				'<div class="dze-cx-pane" data-pane="review">' +
					'<p class="dze-cx-note" style="margin-top:0;">' + esc(i18n.reviewNote) + '</p>' +
					'<div class="dze-cx-grid" id="dze-rv-grid"></div>' +
					'<p id="dze-rv-price" class="dze-cx-note" style="display:none;"></p>' +
					'<div id="dze-rv-galslot"></div>' +
					'<p style="margin:14px 0 0;"><button type="button" class="button button-primary" id="dze-rv-apply">' + esc(i18n.applyAll) + '</button> <span id="dze-rv-status"></span></p>' +
				'</div>' +
				// TEXT lab
				'<div class="dze-cx-pane" data-pane="text">' +
					'<p class="dze-cx-topbar"><button type="button" class="button button-primary" id="dze-cx-genall">' + esc(i18n.genAll) + '</button></p>' +
					'<p class="dze-cx-datause">' + esc(i18n.dataUsed) + ': ' + dataUsed +
						' <button type="button" class="dze-cx-icon" id="dze-cx-src-toggle" title="' + esc(i18n.editData) + '">✎</button></p>' +
					'<div class="dze-cx-src" id="dze-cx-src" style="display:none;">' +
						'<label>' + esc(i18n.pTitle) + '</label><input type="text" id="dze-cx-ptitle" value="' + esc(cfg.product.title) + '" />' +
						'<label>' + esc(i18n.pDesc) + '</label><div id="dze-cx-pdesc" class="dze-cx-rich" contenteditable="true">' + (cfg.product.descHtml || esc(cfg.product.desc)) + '</div>' +
						'<label>' + esc(i18n.pAttr) + '</label><textarea id="dze-cx-pattr" rows="3">' + esc(cfg.product.attr || '') + '</textarea>' +
					'</div>' +
					'<div class="dze-cx-grid">' + fieldCards + '</div>' +
				'</div>' +
				// IMAGE lab
				'<div class="dze-cx-pane" data-pane="image">' +
					'<div class="dze-cx-imgrow">' +
						'<label>' + esc(i18n.template) + ' <select id="dze-cx-tpl">' + tplOpts + '</select></label>' +
						'<select id="dze-cx-imgn" title="' + esc(i18n.imgCount) + '">' +
							[1, 2, 3, 4].map(function (n) { return '<option value="' + n + '"' + ((m.imgn || 1) == n ? ' selected' : '') + '>×' + n + '</option>'; }).join('') +
						'</select>' +
						'<button type="button" class="button button-primary" id="dze-cx-genimg">' + esc(i18n.genImage) + '</button>' +
						'<button type="button" class="dze-cx-vbtn" id="dze-cx-tpl-validate" title="' + esc(i18n.validToggle) + '"></button>' +
						'<button type="button" class="dze-cx-icon" id="dze-cx-tpl-edit" title="' + esc(i18n.editPrompt) + '">✎</button>' +
						'<span id="dze-cx-imgstatus"></span>' +
					'</div>' +
					'<div id="dze-cx-tpl-pwrap" style="display:none;margin:8px 0;">' +
						'<textarea id="dze-cx-tpl-prompt" rows="3" style="width:100%;box-sizing:border-box;" title="' + esc(i18n.promptNote) + '"></textarea>' +
						'<p style="margin:4px 0 0;"><button type="button" class="button-link" id="dze-cx-tpl-save">💾 ' + esc(i18n.savePrompt) + '</button>' +
						' <button type="button" class="button-link" id="dze-cx-tpl-restore" style="display:none;">↺ ' + esc(i18n.restore) + '</button></p>' +
					'</div>' +
					'<div id="dze-cx-galwrap">' +
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
		showPane(pane || 'auto');
	}
	function syncTplPrompt() {
		var i = parseInt($('#dze-cx-tpl').val(), 10) || 0;
		var t = cfg.templates[i] || {};
		$('#dze-cx-tpl-prompt').val(t.prompt || '');
	}
	// Keeps the template select labels and the validation toggle in sync.
	function refreshTplUi() {
		var i = parseInt($('#dze-cx-tpl').val(), 10) || 0;
		var t = cfg.templates[i] || {};
		$('#dze-cx-tpl-validate').toggleClass('is-on', !!t.valid).text(t.valid ? '✓ ' + i18n.validated : i18n.notValid);
		$('#dze-cx-tpl-restore').toggle(!!(cfg.defaults && t.id && cfg.defaults[t.id]));
		$('#dze-au-tpl option').each(function () {
			var ix = parseInt($(this).val(), 10), tt = cfg.templates[ix];
			if (tt) { $(this).text(tt.name + (tt.valid ? '' : ' — ' + i18n.notValid)); }
		});
	}
	function showPane(p) {
		$('.dze-cx-tab').removeClass('is-active').filter('[data-pane="' + p + '"]').addClass('is-active');
		$('.dze-cx-pane').removeClass('is-active').filter('[data-pane="' + p + '"]').addClass('is-active');
		// The session gallery is ONE block shared between the Image lab and the
		// Review pane — it moves to whichever is being shown.
		if (p === 'review') { $('#dze-cx-galwrap').appendTo('#dze-rv-galslot'); }
		if (p === 'image') { $('#dze-cx-galwrap').appendTo('.dze-cx-pane[data-pane="image"]'); }
	}

	$('#dze-cx-open-auto').on('click', function () { open('auto'); });
	$('#dze-cx-open-text').on('click', function () { open('text'); });
	$('#dze-cx-open-image').on('click', function () { open('image'); });
	$(document).on('click', '.dze-cx-tab', function () { showPane($(this).data('pane')); });
	$(document).on('click', '.dze-cx-close', function () { $('#dze-cx-modal').removeClass('is-open'); });
	$(document).on('click', '#dze-cx-modal', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });
	$(document).on('click', '#dze-au-prevtext', function () { showPane('text'); });
	$(document).on('click', '#dze-au-previmg', function () { showPane('image'); });
	$(document).on('click', '#dze-cx-src-toggle', function () { $('#dze-cx-src').toggle(); });

	function productData() {
		var descEl = $('#dze-cx-pdesc')[0];
		return {
			title: $('#dze-cx-ptitle').val() || '',
			desc: descEl ? (descEl.innerText || '') : '',
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
	// Per-prompt restore: puts the shipped default back in the editor (💾 to keep it).
	$(document).on('click', '.dze-cx-p-restore', function () {
		var $card = $(this).closest('.dze-cx-field'), fid = $card.data('field');
		var d = cfg.defaults && cfg.defaults[fid];
		if (d) { $card.find('.dze-cx-p-text').val(d); }
	});
	$(document).on('click', '#dze-cx-tpl-restore', function () {
		var i = parseInt($('#dze-cx-tpl').val(), 10) || 0;
		var d = cfg.defaults && cfg.templates[i] && cfg.defaults[cfg.templates[i].id];
		if (d) { $('#dze-cx-tpl-prompt').val(d); }
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
		$('.dze-cx-pane[data-pane="text"] .dze-cx-out').val(i18n.generating);
		var overrides = {};
		$('.dze-cx-pane[data-pane="text"] .dze-cx-field').each(function () {
			var ov = cardPromptOverride($(this));
			if (ov) { overrides[$(this).data('field')] = ov; }
		});
		$.post(cfg.ajaxUrl, { action: 'dze_content_text_all', nonce: cfg.nonce, post: cfg.postId, title: pd.title, desc: pd.desc, attr: pd.attr, prompts: overrides })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) {
					window.alert((res.data && res.data.message) || i18n.error);
					$('.dze-cx-pane[data-pane="text"] .dze-cx-out').val('');
					return;
				}
				var texts = res.data.texts || {};
				$('.dze-cx-pane[data-pane="text"] .dze-cx-field').each(function () {
					var fid = $(this).data('field');
					$(this).find('.dze-cx-out').val(texts[fid] || '');
				});
			})
			.fail(function () {
				$btn.prop('disabled', false);
				window.alert(i18n.error);
				$('.dze-cx-pane[data-pane="text"] .dze-cx-out').val('');
			});
	});

	function applyField(fid, val) {
		return $.post(cfg.ajaxUrl, { action: 'dze_content_apply', nonce: cfg.nonce, post: cfg.postId, field: fid, value: val })
			.done(function (res) {
				if (res.success) {
					// Reflect into the classic editors where relevant.
					if (fid === 'description') { setEditor('content', val); }
					if (fid === 'short') { setEditor('excerpt', val); }
					if (fid === 'title') { $('#title').val(val.replace(/<[^>]+>/g, '')); }
				}
			});
	}

	$(document).on('click', '.dze-cx-apply', function () {
		var $card = $(this).closest('.dze-cx-field'), fid = $card.data('field');
		var val = $card.find('.dze-cx-out').val();
		var $btn = $(this);
		if (!fieldValidated(fid)) { window.alert(i18n.fieldLocked); return; }
		$btn.prop('disabled', true);
		applyField(fid, val)
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				if (res.data && res.data.note) { window.alert(res.data.note); }
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
	$(document).on('click', '#dze-cx-tpl-edit', function () { $('#dze-cx-tpl-pwrap').toggle(); });

	// ---- One-click validation toggles (persisted in the prompt registry) ----
	$(document).on('click', '.dze-cx-field .dze-cx-vbtn', function () {
		var $card = $(this).closest('.dze-cx-field'), fid = $card.data('field');
		var on = fieldValidated(fid) ? 0 : 1, $b = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_content_validate_prompt', nonce: cfg.nonce, ptype: 'field', field: fid, on: on })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				cfg.validated[fid] = !!on;
				syncFieldValidUi(fid);
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
				'<img class="dze-hzoom" src="' + g.url + '" data-full="' + g.url + '" alt="" />' +
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

	// Several generations can run in parallel (the ×N picker): a pending counter
	// drives the status line and re-enables the buttons on the LAST completion.
	var imgPending = 0, imgHadError = false;
	function imgTick() {
		var $st = $('#dze-cx-imgstatus');
		if (imgPending > 0) {
			$st.css('color', '#646970').html('<span class="dze-cx-spin"></span> ' + esc(i18n.imgWait) + (imgPending > 1 ? ' (' + imgPending + ')' : ''));
			return;
		}
		$('#dze-cx-genimg, #dze-cx-editgo').prop('disabled', false);
		if (!imgHadError) { $st.css('color', '#00794b').text(i18n.imgReady); }
	}
	function genImage(params) {
		if (imgPending === 0) { imgHadError = false; }
		imgPending++;
		$('#dze-cx-genimg, #dze-cx-editgo').prop('disabled', true);
		imgTick();
		var data = $.extend({ action: 'dze_content_image', nonce: cfg.nonce, post: cfg.postId, mode: 'defer' }, params);
		return $.post(cfg.ajaxUrl, data)
			.done(function (res) {
				if (!res.success) {
					imgHadError = true;
					$('#dze-cx-imgstatus').css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error);
					return;
				}
				addToGal(res.data.url);
			})
			.fail(function () {
				imgHadError = true;
				$('#dze-cx-imgstatus').css('color', '#b32d2e').text(i18n.error);
			})
			.always(function () {
				imgPending = Math.max(0, imgPending - 1);
				imgTick();
			});
	}
	// N parallel generations of the same template; resolves when ALL are done.
	function genImages(params, n) {
		var calls = [];
		for (var k = 0; k < Math.max(1, n); k++) { calls.push(genImage(params)); }
		return $.when.apply($, calls).then(null, function () { return $.Deferred().resolve(); });
	}

	$(document).on('change', '#dze-cx-imgn', function () {
		var m = mem(); m.imgn = parseInt($(this).val(), 10) || 1; saveMem(m);
	});
	$(document).on('click', '#dze-cx-genimg', function () {
		var idx = parseInt($('#dze-cx-tpl').val(), 10) || 0;
		var txt = $('#dze-cx-tpl-prompt').val() || '';
		var stored = (cfg.templates[idx] && cfg.templates[idx].prompt) || '';
		genImages(
			{ template: idx, custom_prompt: (txt !== stored && $('#dze-cx-tpl-pwrap').is(':visible') ? txt : '') },
			parseInt($('#dze-cx-imgn').val(), 10) || 1
		);
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

	// Push the selection onto the product (SEO naming happens server-side; when
	// the target is the main image, the old main moves to gallery position 1).
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

	// Bridge for sibling modules (POD): push an image into the session gallery
	// and open a toolbox pane — the ✎ variant flow then works on that image.
	window.dzeContentAddToGallery = function (url) { build(); addToGal(url); };
	window.dzeContentOpen = open;

	// ---- Price lab ----
	function runPrice(cost) {
		return $.post(cfg.ajaxUrl, { action: 'dze_content_price', nonce: cfg.nonce, post: cfg.postId, cost: cost });
	}
	$(document).on('click', '#dze-cx-recalc', function () {
		var $out = $('#dze-cx-priceout').text(i18n.generating);
		runPrice($('#dze-cx-cost').val())
			.done(function (res) {
				if (!res.success) { $out.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error); return; }
				$out.css('color', '#1d2327').text('× ' + res.data.mult + ' → ' + i18n.newPrice + ': ' + res.data.regular + (res.data.applied ? ' ✓' : ''));
				if (res.data.applied) { $('#_regular_price').val(res.data.regular); }
			})
			.fail(function () { $out.css('color', '#b32d2e').text(i18n.error); });
	});

	// ---- Automatic edition: Launch → generate everything → Review ----
	function reviewCard(fid, text) {
		var ok = fieldValidated(fid);
		return '<div class="dze-cx-field' + (ok ? '' : ' is-unvalidated') + '" data-field="' + fid + '">' +
			'<h4>' + esc(cfg.fields[fid]) +
			' <button type="button" class="dze-cx-vbtn' + (ok ? ' is-on' : '') + '" title="' + esc(i18n.validToggle) + '">' + (ok ? '✓ ' + esc(i18n.validated) : esc(i18n.notValid)) + '</button></h4>' +
			'<textarea rows="5" class="dze-cx-out dze-rv-out"></textarea>' +
			'<p class="dze-rv-state dze-cx-note" style="margin:4px 0 0;"></p>' +
			'</div>';
	}

	$(document).on('click', '#dze-au-launch', function () {
		var fields = [];
		$('.dze-au-f:checked').each(function () { fields.push($(this).val()); });
		var doPrice = $('#dze-au-price').is(':checked');
		var doImg = $('#dze-au-img').is(':checked');
		if (!fields.length && !doPrice && !doImg) { window.alert(i18n.nothingSel); return; }

		if ($('#dze-au-save').is(':checked')) {
			var m = mem(), fmap = {};
			$('.dze-au-f').each(function () { fmap[$(this).val()] = $(this).is(':checked') ? 1 : 0; });
			m.auto = { fields: fmap, price: doPrice ? 1 : 0, img: doImg ? 1 : 0, tpl: $('#dze-au-tpl').val(), imgn: parseInt($('#dze-au-imgn').val(), 10) || 1 };
			saveMem(m);
		}

		var $btn = $(this).prop('disabled', true);
		var $st = $('#dze-au-status').css('color', '#646970').html('<span class="dze-cx-spin"></span> ' + esc(i18n.working || i18n.generating));
		var out = { texts: {}, price: '' };

		var chain = $.Deferred().resolve().promise();
		if (fields.length) {
			chain = chain.then(function () {
				return $.post(cfg.ajaxUrl, { action: 'dze_content_text_all', nonce: cfg.nonce, post: cfg.postId, fields: fields })
					.then(function (res) {
						if (res && res.success) { out.texts = res.data.texts || {}; }
						else { out.textError = (res && res.data && res.data.message) || i18n.error; }
					}, function () { out.textError = i18n.error; return $.Deferred().resolve(); });
			});
		}
		if (doPrice) {
			chain = chain.then(function () {
				return runPrice($('#dze-au-cost').val()).then(function (res) {
					if (res && res.success) {
						out.price = '× ' + res.data.mult + ' → ' + i18n.newPrice + ': ' + res.data.regular + ' ✓';
						$('#_regular_price').val(res.data.regular);
					}
				}, function () { return $.Deferred().resolve(); });
			});
		}
		if (doImg) {
			chain = chain.then(function () {
				return genImages(
					{ template: parseInt($('#dze-au-tpl').val(), 10) || 0 },
					parseInt($('#dze-au-imgn').val(), 10) || 1
				);
			});
		}

		chain.then(function () {
			$btn.prop('disabled', false);
			$st.text('');
			if (out.textError && !Object.keys(out.texts).length && !doPrice && !doImg) {
				$st.css('color', '#b32d2e').text(out.textError);
				return;
			}
			// Build the review pane.
			var html = '';
			fields.forEach(function (fid) { html += reviewCard(fid); });
			$('#dze-rv-grid').html(html);
			fields.forEach(function (fid) {
				$('#dze-rv-grid .dze-cx-field[data-field="' + fid + '"] .dze-rv-out').val(out.texts[fid] || out.textError || '');
			});
			$('#dze-rv-price').toggle(!!out.price).text(out.price);
			$('.dze-cx-tab[data-pane="review"]').show();
			$('#dze-rv-status').text('');
			showPane('review');
		});
	});

	// Apply everything from the review: each validated field with its (possibly
	// edited) text, sequentially. Unvalidated fields are skipped with a note.
	$(document).on('click', '#dze-rv-apply', function () {
		var $btn = $(this).prop('disabled', true);
		var $st = $('#dze-rv-status').css('color', '#646970').html('<span class="dze-cx-spin"></span>');
		var cards = $('#dze-rv-grid .dze-cx-field').toArray();
		var chain = $.Deferred().resolve().promise();
		var okCount = 0;
		cards.forEach(function (el) {
			chain = chain.then(function () {
				var $c = $(el), fid = $c.data('field'), val = $c.find('.dze-rv-out').val();
				var $state = $c.find('.dze-rv-state');
				if (!fieldValidated(fid)) { $state.css('color', '#8a6d00').text('🔒 ' + i18n.skippedLock); return $.Deferred().resolve(); }
				if (!val) { return $.Deferred().resolve(); }
				return applyField(fid, val).then(function (res) {
					if (res && res.success) { okCount++; $state.css('color', '#0a7040').text('✓ ' + i18n.applied); }
					else { $state.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); }
				}, function () { $state.css('color', '#b32d2e').text(i18n.error); return $.Deferred().resolve(); });
			});
		});
		chain.then(function () {
			$btn.prop('disabled', false);
			$st.css('color', '#0a7040').text(okCount ? i18n.allDone : '');
		});
	});

}(jQuery));
