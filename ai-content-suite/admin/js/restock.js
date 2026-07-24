/* global aicsRestock, jQuery */
(function ($) {
	'use strict';

	var cfg  = aicsRestock;
	var i18n = cfg.i18n;

	// ---- Expand / collapse variable-product variations (lazy load) ----
	$(document).on('click', '.aics-restock-toggle', function (e) {
		e.preventDefault();
		var $btn    = $(this);
		var parent  = $btn.data('parent');
		var $row    = $('#restock-line-' + parent);
		var colspan = $row.children('td, th').length || 5;
		var $child  = $('#restock-child-' + parent);

		// Already loaded → just toggle visibility.
		if ($child.length) {
			var visible = $child.is(':visible');
			$child.toggle(!visible);
			$btn.attr('aria-expanded', String(!visible)).text(visible ? '▸' : '▾');
			return;
		}

		// First open → fetch via AJAX.
		$btn.attr('aria-expanded', 'true').text('▾');
		$row.after(
			'<tr id="restock-child-' + parent + '" class="aics-restock-child">' +
			'<td colspan="' + colspan + '"><em>' + i18n.loading + '</em></td></tr>'
		);
		var $childRow = $('#restock-child-' + parent);

		$.post(cfg.ajaxUrl, {
			action    : 'aics_restock_variations',
			nonce     : cfg.nonce,
			parent_id : parent
		})
		.done(function (res) {
			if (res.success && res.data.count > 0) {
				$childRow.find('td').html(buildSubTable(res.data.rows));
			} else if (res.success) {
				$childRow.find('td').html('<em>' + i18n.noVar + '</em>');
			} else {
				$childRow.find('td').html('<span style="color:#c0392b;">' + i18n.error + '</span>');
			}
		})
		.fail(function () {
			$childRow.find('td').html('<span style="color:#c0392b;">' + i18n.error + '</span>');
		});
	});

	function buildSubTable(rowsHtml) {
		return '<p class="aics-subtable-note">' + (i18n.subNote || '') + '</p>' +
			'<table class="widefat striped aics-restock-subtable">' +
			'<thead><tr>' +
			'<th>Variation</th><th>SKU</th><th>Price</th><th>Sales</th>' +
			'</tr></thead><tbody>' + rowsHtml + '</tbody></table>';
	}

	// ---- Recalculate sales cache ----
	$('#aics-restock-recalc').on('click', function () {
		var $btn    = $(this);
		var $status = $('#aics-restock-recalc-status');

		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.recalc);

		$.post(cfg.ajaxUrl, {
			action : 'aics_restock_recalc',
			nonce  : cfg.nonce
		})
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').text(res.data.message + ' — ' + res.data.timestamp);
				// Reload so cached sales appear in the table.
				setTimeout(function () { window.location.reload(); }, 900);
			} else {
				$status.css('color', '#c0392b').text((res.data && res.data.message) || i18n.error);
				$btn.prop('disabled', false);
			}
		})
		.fail(function () {
			$status.css('color', '#c0392b').text(i18n.error);
			$btn.prop('disabled', false);
		});
	});

}(jQuery));
