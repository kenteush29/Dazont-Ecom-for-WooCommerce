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
	function build() {
		if ($('#dze-cx-modal').length) { return; }
		var m = mem();
		var fieldCards = '';
		Object.keys(cfg.fields).forEach(function (fid) {
			fieldCards +=
				'<div class="dze-cx-field" data-field="' + fid + '">' +
				'<h4>' + esc(cfg.fields[fid]) + '</h4>' +
				'<textarea rows="4" class="dze-cx-out" placeholder="—"></textarea>' +
				'<div class="row-actions">' +
				'<button type="button" class="button button-small dze-cx-gen">' + esc(i18n.generate) + '</button>' +
				'<button type="button" class="button button-small dze-cx-apply">' + esc(i18n.apply) + '</button>' +
				'</div></div>';
		});
		var tplOpts = cfg.templates.map(function (t, i) {
			return '<option value="' + i + '"' + (m.tpl == i ? ' selected' : '') + '>' + esc(t.name) + ' (' + esc(t.target) + ')</option>';
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
				(cfg.validated ? '' : '<p class="dze-cx-warn">' + esc(i18n.previewOnly) + '</p>') +
				// TEXT pane
				'<div class="dze-cx-pane" data-pane="text">' +
					'<div class="dze-cx-src">' +
						'<strong>' + esc(i18n.productData) + '</strong>' +
						'<label>' + esc(i18n.pTitle) + '</label><input type="text" id="dze-cx-ptitle" value="' + esc(cfg.product.title) + '" />' +
						'<label>' + esc(i18n.pDesc) + '</label><textarea id="dze-cx-pdesc" rows="3">' + esc(cfg.product.desc) + '</textarea>' +
						'<label>' + esc(i18n.pAttr) + '</label><textarea id="dze-cx-pattr" rows="2"></textarea>' +
						'<p><button type="button" class="button button-primary" id="dze-cx-genall">' + esc(i18n.genAll) + '</button></p>' +
					'</div>' +
					'<div class="dze-cx-grid">' + fieldCards + '</div>' +
				'</div>' +
				// IMAGE pane
				'<div class="dze-cx-pane" data-pane="image">' +
					'<div class="dze-cx-imgrow">' +
						'<label>' + esc(i18n.template) + ' <select id="dze-cx-tpl">' + tplOpts + '</select></label>' +
						'<button type="button" class="button button-primary" id="dze-cx-genimg">' + esc(i18n.genImage) + '</button>' +
						'<span id="dze-cx-imgstatus"></span>' +
					'</div>' +
					'<div class="dze-cx-imgout" id="dze-cx-imgout"></div>' +
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
		$('#dze-cx-modal').addClass('is-open');
		showPane(pane || 'text');
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

	function genField($card) {
		var fid = $card.data('field'), pd = productData();
		var $ta = $card.find('.dze-cx-out'), $btn = $card.find('.dze-cx-gen').prop('disabled', true);
		$ta.val(i18n.generating);
		return $.post(cfg.ajaxUrl, { action: 'dze_content_text', nonce: cfg.nonce, field: fid, title: pd.title, desc: pd.desc, attr: pd.attr })
			.done(function (res) {
				$btn.prop('disabled', false);
				$ta.val(res.success ? res.data.text : ((res.data && res.data.message) || i18n.error));
			})
			.fail(function () { $btn.prop('disabled', false); $ta.val(i18n.error); });
	}

	$(document).on('click', '.dze-cx-gen', function () { genField($(this).closest('.dze-cx-field')); });

	$(document).on('click', '#dze-cx-genall', function () {
		var $btn = $(this).prop('disabled', true);
		var cards = $('.dze-cx-field').toArray();
		(function next(i) {
			if (i >= cards.length) { $btn.prop('disabled', false); return; }
			genField($(cards[i])).always(function () { next(i + 1); });
		})(0);
	});

	$(document).on('click', '.dze-cx-apply', function () {
		var $card = $(this).closest('.dze-cx-field'), fid = $card.data('field');
		var val = $card.find('.dze-cx-out').val();
		var $btn = $(this);
		if (!cfg.validated) { window.alert(i18n.previewOnly); return; }
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

	// ---- Image ----
	$(document).on('change', '#dze-cx-tpl', function () { var m = mem(); m.tpl = $(this).val(); saveMem(m); });
	$(document).on('click', '#dze-cx-genimg', function () {
		var $btn = $(this).prop('disabled', true);
		var tpl = $('#dze-cx-tpl').val();
		var $st = $('#dze-cx-imgstatus').css('color', '#646970').html('<span class="dze-cx-spin"></span> ' + esc(i18n.imgWait));
		$('#dze-cx-imgout').empty();
		$.post(cfg.ajaxUrl, { action: 'dze_content_image', nonce: cfg.nonce, post: cfg.postId, template: tpl })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { $st.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#00794b').text(i18n.imgAdded + ' (' + res.data.target + ')');
				if (res.data.url) { $('#dze-cx-imgout').html('<img src="' + res.data.url + '" alt="" />'); }
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
