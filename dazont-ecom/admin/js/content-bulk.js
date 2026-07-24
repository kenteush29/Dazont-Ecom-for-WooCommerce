/* global dzeContentBulk, jQuery */
/**
 * AI Content bulk runner: for each queued product, generates the selected text
 * fields (server pulls the complete product data), applies them to their mapped
 * destinations, optionally recalculates the price (cost × table) and generates
 * one image per product (per-row template + include checkbox — the judgement
 * call). Tasks run sequentially with a progress bar; choices are remembered.
 */
(function ($) {
	'use strict';

	var cfg = dzeContentBulk, i18n = cfg.i18n;
	var MEM = 'dzeContentMem';

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
	function mem() { try { return JSON.parse(localStorage.getItem(MEM) || '{}'); } catch (e) { return {}; } }
	function saveMem(o) { try { localStorage.setItem(MEM, JSON.stringify(o)); } catch (e) {} }
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return str.replace(/%\d\$s|%s/g, function () { return args[i++]; });
	}

	// ---- Restore remembered choices ----
	(function restore() {
		var m = mem();
		if (Array.isArray(m.bulkFields)) {
			$('.dze-cb-field').each(function () { this.checked = m.bulkFields.indexOf($(this).val()) >= 0; });
		}
		if (typeof m.bulkPrice !== 'undefined') { $('#dze-cb-price').prop('checked', !!m.bulkPrice); }
		if (typeof m.bulkImage !== 'undefined') { $('#dze-cb-image').prop('checked', !!m.bulkImage); }
		if (typeof m.tpl !== 'undefined') { $('#dze-cb-tpl').val(m.tpl); }
		syncRows();
	}());

	function persist() {
		var m = mem();
		m.bulkFields = $('.dze-cb-field:checked').map(function () { return $(this).val(); }).get();
		m.bulkPrice = $('#dze-cb-price').is(':checked');
		m.bulkImage = $('#dze-cb-image').is(':checked');
		m.tpl = $('#dze-cb-tpl').val();
		saveMem(m);
	}
	// Global image toggle/template cascade to every row (rows stay overridable).
	function syncRows() {
		var on = $('#dze-cb-image').is(':checked'), tpl = $('#dze-cb-tpl').val();
		$('.dze-cb-row-img').prop('checked', on);
		$('.dze-cb-row-tpl').val(tpl);
	}
	$('#dze-cb-image, #dze-cb-tpl').on('change', function () { persist(); syncRows(); });
	$('.dze-cb-field, #dze-cb-price').on('change', persist);

	// ---- Task queue ----
	var stopped = false, okCount = 0, koCount = 0, doneCount = 0, total = 0;

	function status($row, html, isErr) {
		var $s = $row.find('.dze-cb-status');
		$s.append('<span class="' + (isErr ? 'ko' : 'ok') + '">' + html + '</span> ');
	}
	function progress(label) {
		doneCount++;
		var pct = total ? Math.round(100 * doneCount / total) : 0;
		$('.dze-cb-fill').css('width', pct + '%');
		$('#dze-cb-progress').text(sprintf(i18n.progress, doneCount, total, label || ''));
	}

	function textTask(id, fid, $row) {
		return $.post(cfg.ajaxUrl, { action: 'dze_content_text', nonce: cfg.nonce, post: id, field: fid })
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				return $.post(cfg.ajaxUrl, { action: 'dze_content_apply', nonce: cfg.nonce, post: id, field: fid, value: res.data.text });
			})
			.then(function (res) {
				if (!res || !res.success) { throw (res && res.data && res.data.message) || i18n.error; }
				okCount++; status($row, esc(cfg.fields[fid] || fid) + ' ✓');
			})
			.catch(function (msg) {
				koCount++; status($row, esc(cfg.fields[fid] || fid) + ' ✗', true);
				if (window.console) { console.warn('DZE bulk', fid, msg); }
			})
			.always(function () { progress(cfg.fields[fid] || fid); });
	}

	function priceTask(id, $row) {
		var cost = $row.find('.dze-cb-cost').val();
		return $.post(cfg.ajaxUrl, { action: 'dze_content_price', nonce: cfg.nonce, post: id, cost: cost })
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				okCount++; status($row, '$' + res.data.regular + ' ✓');
			})
			.catch(function () { koCount++; status($row, '$ ✗', true); })
			.always(function () { progress('price'); });
	}

	function imageTask(id, $row) {
		var tpl = $row.find('.dze-cb-row-tpl').val();
		return $.post(cfg.ajaxUrl, { action: 'dze_content_image', nonce: cfg.nonce, post: id, template: tpl })
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				okCount++; status($row, 'img ✓');
				// Better image visibility: refresh the row thumbnail with the result.
				if (res.data.url) { $row.find('.dze-cb-thumb img').attr('src', res.data.url); }
			})
			.catch(function () { koCount++; status($row, 'img ✗', true); })
			.always(function () { progress('image'); });
	}

	$('#dze-cb-start').on('click', function () {
		if (!cfg.validated) { return; }
		persist();
		var fields = $('.dze-cb-field:checked').map(function () { return $(this).val(); }).get();
		var doPrice = $('#dze-cb-price').is(':checked');
		if (!fields.length && !doPrice && !$('.dze-cb-row-img:checked').length) {
			window.alert(i18n.noFields);
			return;
		}

		// Build the flat task list: per product → fields, price, image.
		var tasks = [];
		$('.dze-cb-row').each(function () {
			var $row = $(this), id = $row.data('id');
			$row.find('.dze-cb-status').empty();
			fields.forEach(function (fid) { tasks.push(function () { return textTask(id, fid, $row); }); });
			if (doPrice) { tasks.push(function () { return priceTask(id, $row); }); }
			if ($row.find('.dze-cb-row-img').is(':checked')) { tasks.push(function () { return imageTask(id, $row); }); }
		});

		stopped = false; okCount = 0; koCount = 0; doneCount = 0; total = tasks.length;
		$('.dze-cb-bar').show(); $('.dze-cb-fill').css('width', 0);
		$('#dze-cb-start').prop('disabled', true);
		$('#dze-cb-stop').show();
		$('#dze-cb-progress').text(i18n.working);

		(function next(i) {
			if (stopped || i >= tasks.length) {
				$('#dze-cb-start').prop('disabled', false);
				$('#dze-cb-stop').hide();
				$('#dze-cb-progress').text(stopped ? i18n.stopped : sprintf(i18n.finished, okCount, koCount));
				return;
			}
			tasks[i]().always(function () { next(i + 1); });
		})(0);
	});

	$('#dze-cb-stop').on('click', function () { stopped = true; });

}(jQuery));
