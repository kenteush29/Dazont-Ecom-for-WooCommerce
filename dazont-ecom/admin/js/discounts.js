/* global jQuery */
(function ($) {
	'use strict';

	var thresholdLabels = {
		bulk : 'Minimum quantity of the same product'
	};
	var thresholdHelp = {
		bulk : 'The discount applies to a product line once its own quantity reaches this number (repeats for each qualifying product). Shown in the cart as "Bundle".'
	};

	function refreshType() {
		var type = $('#dze-type').val();
		var isSale = (type === 'sale');
		var isBulk = (type === 'bulk');
		var isBulkOrder = (type === 'bulk_order');
		var isAutoBest = (type === 'autobest');

		// Marketing Events only.
		$('.dze-field-schedule').toggle(isSale);
		$('.dze-field-banner').toggle(isSale);

		// Percent is used by sale + bulk + best-seller boost; bulk_order uses per-tier percents.
		$('.dze-field-percent').toggle(!isBulkOrder);

		// Bulk offer per item.
		$('.dze-field-threshold').toggle(isBulk);
		if (isBulk) {
			$('.dze-threshold-label').text(thresholdLabels.bulk);
			$('.dze-threshold-help').text(thresholdHelp.bulk);
		}

		// Bulk order (tiered).
		$('.dze-field-min-subtotal, .dze-field-min-qty, .dze-field-tiers').toggle(isBulkOrder);

		// Automatic product discount: its own params, and no manual scope
		// (products are auto-selected by the chosen strategy).
		$('.dze-field-strategy, .dze-field-top-n, .dze-field-lookback, .dze-field-autocount').toggle(isAutoBest);
		$('.dze-field-scope').toggle(!isAutoBest);
		if (isAutoBest) { refreshStrategyDesc(); }
	}

	function refreshStrategyDesc() {
		var s = $('#dze-strategy').val();
		$('.dze-strat-desc').each(function () {
			$(this).toggle($(this).data('strategy') === s);
		});
		// Priority only matters where the strategy isn't already sales-ranked.
		var usesPriority = (s === 'newest' || s === 'slow');
		$('.dze-field-priority').toggle(usesPriority);
	}

	// ---- Automatic-discount "count matching products" preview ----
	var autoProducts = [];

	$(document).on('click', '#dze-auto-count', function () {
		if (typeof dzeDiscounts === 'undefined') { return; }
		var d = dzeDiscounts, $out = $('#dze-auto-count-out');
		$out.css('color', '#555').text(d.i18n.counting);
		$('#dze-auto-list').hide();
		$.post(d.ajaxUrl, {
			action: 'dze_auto_count',
			nonce: d.nonce,
			strategy: $('#dze-strategy').val(),
			priority: $('#dze-priority').val(),
			top_n: $('#dze-top-n').val(),
			lookback_days: $('#dze-lookback').val()
		}).done(function (res) {
			if (!res.success) { $out.css('color', '#b32d2e').text(d.i18n.error); return; }
			var txt = d.i18n.result.replace('%1$s', res.data.total).replace('%2$s', res.data.applied);
			$out.css('color', '#0a7040').text(txt);
			autoProducts = res.data.products || [];
			if (autoProducts.length) { $('#dze-auto-list').show(); }
		}).fail(function () { $out.css('color', '#b32d2e').text(d.i18n.error); });
	});

	// Popup: the exact products that would be discounted.
	$(document).on('click', '#dze-auto-list', function () {
		if (!autoProducts.length) { return; }
		var d = dzeDiscounts;
		var html = '<h2 style="margin-top:0;">' + d.i18n.listTitle.replace('%s', autoProducts.length) + '</h2>';
		html += '<ol class="dze-auto-list">';
		autoProducts.forEach(function (p) {
			html += '<li>' + $('<span>').text(p.name).html() + ' <code>#' + p.id + '</code></li>';
		});
		html += '</ol>';
		$('#dze-auto-modal .dze-auto-modal__inner').html(html);
		$('#dze-auto-modal').css('display', 'flex');
	});
	$(document).on('click', '#dze-auto-modal', function (e) {
		if (e.target === this) { $(this).hide(); }
	});

	function refreshScope() {
		var scope = $('.dze-scope:checked').val();
		$('.dze-field-categories').toggle(scope === 'categories');
		$('.dze-field-products').toggle(scope === 'products');
	}

	function refreshBannerLocation() {
		var loc = $('.dze-banner-loc:checked').val();
		$('.dze-field-product-position').toggle(loc === 'product');
	}

	// ---- Media Library picker for hero images ----
	var frame = null;
	$(document).on('click', '.dze-hero-select', function (e) {
		e.preventDefault();
		var $cell = $(this).closest('.dze-hero-picker');

		frame = wp.media({
			title: 'Select image',
			button: { text: 'Use this image' },
			library: { type: 'image' },
			multiple: false
		});

		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
			$cell.find('input[type=hidden]').val(att.id);
			$cell.find('.dze-hero-preview').attr('src', url).show();
			$cell.find('.dze-hero-clear').show();
		});

		frame.open();
	});

	$(document).on('click', '.dze-hero-clear', function (e) {
		e.preventDefault();
		var $cell = $(this).closest('.dze-hero-picker');
		$cell.find('input[type=hidden]').val('');
		$cell.find('.dze-hero-preview').attr('src', '').hide();
		$(this).hide();
	});

	$(function () {
		refreshType();
		refreshScope();
		refreshBannerLocation();
		$('#dze-type').on('change', refreshType);
		$('#dze-strategy').on('change', refreshStrategyDesc);
		$('#dze-auto-count-out').text('');
		$(document).on('change', '.dze-scope', refreshScope);
		$(document).on('change', '.dze-banner-loc', refreshBannerLocation);
	});

}(jQuery));
