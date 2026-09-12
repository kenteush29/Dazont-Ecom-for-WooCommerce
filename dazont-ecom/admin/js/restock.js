/* global dzeRestock, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeRestock;
	var i18n = cfg.i18n;

	// ---- Expand / collapse variations (lazy load) ----
	$(document).on('click', '.dze-toggle', function (e) {
		e.preventDefault();
		var $btn   = $(this);
		var parent = $btn.data('parent');
		var $row   = $('#restock-line-' + parent);
		var cols   = $row.children('td, th').length || 5;
		var $child = $('#restock-child-' + parent);

		if ($child.length) {
			var visible = $child.is(':visible');
			$child.toggle(!visible);
			$btn.attr('aria-expanded', String(!visible)).text(visible ? '▸' : '▾');
			return;
		}

		$btn.attr('aria-expanded', 'true').text('▾');
		$row.after(
			'<tr id="restock-child-' + parent + '" class="dze-child">' +
			'<td colspan="' + cols + '"><em>' + escHtml(i18n.loading) + '</em></td></tr>'
		);
		var $childRow = $('#restock-child-' + parent);

		$.post(cfg.ajaxUrl, {
			action    : 'dze_variations',
			nonce     : cfg.nonce,
			parent_id : parent
		})
		.done(function (res) {
			if (res.success && res.data.count > 0) {
				$childRow.find('td').html(buildSubTable(res.data.rows, parent));
			} else if (res.success) {
				$childRow.find('td').html('<em>' + escHtml(i18n.noVar) + '</em>');
			} else {
				$childRow.find('td').html('<span style="color:#c0392b;">' + escHtml(i18n.error) + '</span>');
			}
		})
		.fail(function () {
			$childRow.find('td').html('<span style="color:#c0392b;">' + escHtml(i18n.error) + '</span>');
		});
	});

	function buildSubTable(rowsHtml, parent) {
		return '<p class="dze-subnote">' + escHtml(i18n.subNote || '') + '</p>' +
			'<table class="widefat striped dze-subtable">' +
			'<thead><tr>' +
			'<th class="check-column"><input type="checkbox" class="dze-var-all" checked /></th>' +
			// ITS ID, IN ITS OWN COLUMN, in the position PHP puts the cell —
			// this table's heading is built here and its rows are built by the
			// server, which is exactly how a table ends up a column out of
			// step. The order is asserted on both sides.
			'<th>Image</th><th>Variation</th><th class="dze-objid-th">ID</th><th>SKU</th><th>Price</th><th>Sales</th>' +
			'</tr></thead>' +
			'<tbody>' + rowsHtml + '</tbody></table>' +
			'<p><button type="button" class="button button-primary dze-restock-vars" data-parent="' + parent + '">' +
			escHtml(i18n.restockSelected) + '</button> ' +
			'<span class="dze-vars-status" style="margin-left:6px;color:#666;"></span></p>';
	}

	// ---- Recalculate ----
	$('#dze-recalc').on('click', function () {
		var $btn    = $(this);
		var $status = $('#dze-recalc-status');

		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.recalc);

		$.post(cfg.ajaxUrl, { action: 'dze_recalc', nonce: cfg.nonce })
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').removeClass('is-ko').text(res.data.message + ' — ' + res.data.timestamp);
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

	// ---- Thumbnails open in THE plugin's viewer, not a second one ----
	//
	// This screen had a lightbox of its own: one image, no arrows, no counter,
	// and a blank box while the full photograph downloaded. Every other screen
	// uses admin/js/hzoom.js, which says it is working, says when a picture
	// cannot be loaded, and walks the row. Two viewers is two things to fix
	// every time one of them is wrong — and this one never got the fixes.
	$(document).on('click', '.dze-thumb, .dze-thumb-zoom', function () {
		var $row = $(this).closest('tr');
		var urls = [], me = String($(this).closest('.dze-thumb-wrap').find('.dze-thumb').data('full') || '');
		if (!me) { return; }
		// The whole ROW walks: a product and its variations are photographs of
		// one thing, and looking at one means comparing it with its neighbours.
		$row.find('.dze-thumb[data-full]').each(function () {
			var u = String($(this).data('full') || '');
			if (u && urls.indexOf(u) < 0) { urls.push(u); }
		});
		if (!window.dzeZoom) { return; }
		window.dzeZoom.open(urls.length ? urls : [ me ], Math.max(0, urls.indexOf(me)));
	});

	// ---- Main-list select-all sync (WP renders #cb-select-all-1 / -2) ----
	$(document).on('change', '#cb-select-all-1, #cb-select-all-2', function () {
		$('.dze-cb').prop('checked', $(this).prop('checked'));
	});

	// ---- Per-row restock ----
	$(document).on('click', '.dze-restock-btn', function () {
		var $btn = $(this);
		var id   = $btn.data('id');
		$btn.prop('disabled', true).text(i18n.restocking);
		restockOne(id, function (ok) {
			if (ok) {
				removeRow(id);
			} else {
				$btn.prop('disabled', false).text(i18n.restock);
			}
		});
	});

	// ---- Variable product: open the variations panel to choose ----
	$(document).on('click', '.dze-restock-expand', function () {
		var parent = $(this).data('parent');
		var $child = $('#restock-child-' + parent);
		if (!$child.length || !$child.is(':visible')) {
			$('.dze-toggle[data-parent="' + parent + '"]').trigger('click');
		}
	});

	// ---- Sub-table select-all ----
	$(document).on('change', '.dze-var-all', function () {
		$(this).closest('table').find('.dze-var-cb').prop('checked', $(this).prop('checked'));
	});

	// ---- Restock selected variations ----
	$(document).on('click', '.dze-restock-vars', function () {
		var $btn    = $(this);
		var parent  = $btn.data('parent');
		var $td     = $btn.closest('td');
		var $status = $td.find('.dze-vars-status');
		var $cbs    = $td.find('.dze-var-cb:checked');

		if (!$cbs.length) { alert(i18n.noSelection); return; }

		var ids = [];
		$cbs.each(function () { ids.push(parseInt($(this).val(), 10)); });

		var total = ids.length;
		var done  = 0;
		$btn.prop('disabled', true);

		function next() {
			if (!ids.length) {
				updateParentBadge(parent, done);
				$btn.prop('disabled', false);
				return;
			}
			var id = ids.shift();
			$status.css('color', '#666').text(i18n.restocking + ' ' + (done + 1) + '/' + total);
			restockOne(id, function (ok) {
				if (ok) {
					done++;
					$td.find('.dze-var-cb[value="' + id + '"]').closest('tr').remove();
				}
				next();
			});
		}
		next();
	});

	// Decrement the parent's "OOS x/y" badge; remove the line when it hits 0.
	function updateParentBadge(parent, restocked) {
		var $badge = $('#restock-line-' + parent + ' .dze-oos-badge');
		if (!$badge.length) { return; }
		var parts = $badge.text().split('/');
		var left  = parseInt(parts[0], 10) - restocked;
		var total = parts[1];
		if (left <= 0) {
			removeRow(parent);
		} else {
			$badge.text(left + '/' + total);
		}
	}

	// ---- Bulk restock ----
	$(document).on('click', '.dze-bulk-restock', function () {
		var ids = [];
		$('.dze-cb:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });

		if (!ids.length) { alert(i18n.noSelection); return; }
		if (!confirm(i18n.confirmBulk)) { return; }

		var $status = $('.dze-bulk-status');
		$('.dze-bulk-restock').prop('disabled', true);
		$('.dze-cb, thead .check-column input, tfoot .check-column input').prop('disabled', true);

		var total = ids.length;
		var done  = 0;

		function next() {
			if (!ids.length) {
				$status.css('color', '#0a7040').removeClass('is-ko').text(i18n.bulkDone + ': ' + done + '/' + total);
				$('.dze-bulk-restock').prop('disabled', false);
				return;
			}
			var id = ids.shift();
			done++;
			$status.css('color', '#666').text(i18n.restocking + ' ' + done + '/' + total);
			restockOne(id, function (ok) {
				if (ok) { removeRow(id); }
				next();
			});
		}
		next();
	});

	function restockOne(id, cb) {
		$.post(cfg.ajaxUrl, { action: 'dze_restock', nonce: cfg.nonce, id: id })
		.done(function (res) { cb(!!(res && res.success)); })
		.fail(function () { cb(false); });
	}

	function removeRow(id) {
		$('#restock-child-' + id).remove();
		$('#restock-line-' + id).fadeOut(300, function () { $(this).remove(); });
	}

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;')
			.replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

}(jQuery));
