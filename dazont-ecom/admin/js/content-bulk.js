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
			$('.dze-cb-field:not(:disabled)').each(function () { this.checked = m.bulkFields.indexOf($(this).val()) >= 0; });
		}
		if (typeof m.bulkPrice !== 'undefined') { $('#dze-cb-price').prop('checked', !!m.bulkPrice); }
		if (typeof m.bulkImage !== 'undefined') { $('#dze-cb-image').prop('checked', !!m.bulkImage); }
		if (typeof m.tpl !== 'undefined') { $('#dze-cb-tpl').val(m.tpl); }
		// The scene is remembered with the toolbox (same store): pick a support
		// once and every screen keeps shooting on it.
		if (typeof m.scene !== 'undefined' && $('#dze-cb-scene option[value="' + m.scene + '"]').length) {
			$('#dze-cb-scene').val(String(m.scene));
		}
		if (typeof m.imgn !== 'undefined') { $('#dze-cb-imgn').val(String(m.imgn)); }
		syncRows();
	}());

	function persist() {
		var m = mem();
		m.bulkFields = $('.dze-cb-field:checked:not(:disabled)').map(function () { return $(this).val(); }).get();
		m.bulkPrice = $('#dze-cb-price').is(':checked');
		m.bulkImage = $('#dze-cb-image').is(':checked');
		m.tpl = $('#dze-cb-tpl').val();
		if ($('#dze-cb-scene').length) { m.scene = parseInt($('#dze-cb-scene').val(), 10); }
		if ($('#dze-cb-imgn').length) { m.imgn = parseInt($('#dze-cb-imgn').val(), 10); }
		saveMem(m);
	}
	// Global image toggle/template cascade to every row (rows stay overridable).
	function syncRows() {
		var on = $('#dze-cb-image').is(':checked'), tpl = $('#dze-cb-tpl').val();
		$('.dze-cb-row-img').prop('checked', on);
		$('.dze-cb-row-tpl').val(tpl);
	}
	$('#dze-cb-image, #dze-cb-tpl').on('change', function () { persist(); syncRows(); });
	$('#dze-cb-scene, #dze-cb-imgn').on('change', persist);
	$('.dze-cb-field, #dze-cb-price').on('change', persist);

	// ---- Task queue ----
	var stopped = false, okCount = 0, koCount = 0, doneCount = 0, total = 0;

	// A failed task must say WHY on the row. Without this the screen answers
	// "1 errors" and leaves you to guess between a missing key, a budget stop,
	// a template that is not validated and a timeout at the provider.
	function reason(msg) {
		if (typeof msg === 'string' && msg) { return msg; }
		if (msg && msg.status) { return 'HTTP ' + msg.status + (msg.statusText ? ' ' + msg.statusText : ''); }
		return i18n.error;
	}
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

	// ONE call per product: every selected field is generated in a single model
	// request. In "direct" mode the server applies them straight away; in
	// "review" mode nothing is written — the texts land in an editable preview
	// row and only "Apply reviewed texts" commits them.
	function textAllTask(id, fids, $row, review) {
		var data = { action: 'dze_content_text_all', nonce: cfg.nonce, post: id, fields: fids };
		if (!review) { data.apply = 1; }
		return $.post(cfg.ajaxUrl, data)
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				if (review) {
					renderPreview(id, fids, res.data.texts || {});
					status($row, i18n.review);
					return;
				}
				var r = res.data.results || {};
				fids.forEach(function (fid) {
					if (r[fid] === 'applied') { okCount++; status($row, esc(cfg.fields[fid] || fid) + ' ✓'); }
					else { koCount++; status($row, esc(cfg.fields[fid] || fid) + ' ✗', true); }
				});
			})
			.catch(function (msg) {
				koCount++; status($row, 'text ✗ ' + esc(reason(msg)), true);
				if (window.console) { console.warn('DZE bulk', msg); }
			})
			.always(function () { progress('text'); });
	}

	// ---- Review mode: editable preview per product ----
	// The row exists for texts AND for images, so it is created on demand and
	// each kind of content appends its own block to it.
	function previewCell(id) {
		var $cell = $('.dze-cb-preview[data-id="' + id + '"]').show().find('td');
		$('#dze-cb-applyall').show();
		return $cell;
	}
	function renderPreview(id, fids, texts) {
		// Two columns of short boxes: a review screen you can take in at a
		// glance beats one you have to scroll through product by product.
		var html = '<div class="dze-cb-prev">';
		fids.forEach(function (fid) {
			html += '<div class="dze-cb-prevfield" data-field="' + fid + '">' +
				'<label>' + esc(cfg.fields[fid] || fid) + '<span class="dze-cb-prevstate"></span></label>' +
				'<textarea rows="2">' + esc(texts[fid] || '') + '</textarea>' +
				'</div>';
		});
		html += '</div>';
		var $cell = previewCell(id), $shots = $cell.find('.dze-cb-shots').detach();
		$cell.html(html).append($shots);
	}
	// A generated image waiting for a decision. Selected by default — the
	// common case is keeping it — but nothing is attached until Apply.
	function addShot(id, url) {
		if (!url) { return; }
		var $cell = previewCell(id), $wrap = $cell.find('.dze-cb-shots');
		if (!$wrap.length) {
			// One line: the shots, then where they go. Hover a shot to see it
			// full size — no need to give the strip half the screen.
			$wrap = $('<div class="dze-cb-shots">' +
				'<div class="dze-cb-shotgrid"></div>' +
				'<select class="dze-cb-shottarget">' +
					'<option value="gallery">' + esc(i18n.toGallery) + '</option>' +
					'<option value="main">' + esc(i18n.toMain) + '</option>' +
				'</select> <span class="dze-cb-shotstate"></span>' +
				'</div>');
			$cell.append($wrap);
		}
		$wrap.find('.dze-cb-shotgrid').append(
			$('<div class="dze-cb-shot is-sel"><span class="dze-cb-shotcheck">✓</span></div>')
				.attr('data-url', url)
				.append($('<img class="dze-hzoom" />').attr('src', url).attr('data-full', url).attr('alt', ''))
		);
	}
	$(document).on('click', '.dze-cb-shot', function () { $(this).toggleClass('is-sel'); });

	// Commit every reviewed text, product by product, field by field.
	$('#dze-cb-applyall').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		var jobs = [], shots = [];
		$('.dze-cb-preview:visible').each(function () {
			var $prev = $(this), id = $prev.data('id');
			$prev.find('.dze-cb-prevfield').each(function () {
				var $f = $(this);
				jobs.push({ id: id, fid: $f.data('field'), $f: $f });
			});
			var $w = $prev.find('.dze-cb-shots');
			if ($w.length) {
				var urls = $w.find('.dze-cb-shot.is-sel').map(function () { return $(this).data('url'); }).get();
				if (urls.length) { shots.push({ id: id, urls: urls, target: $w.find('.dze-cb-shottarget').val(), $w: $w }); }
			}
		});
		// Images first: attaching is what the run was for, and a text failure
		// should not leave the kept shots behind.
		function attachNext(k) {
			if (k >= shots.length) { return runTexts(); }
			var sh = shots[k];
			sh.$w.find('.dze-cb-shotstate').css('color', '#646970').text(i18n.working);
			$.post(cfg.ajaxUrl, { action: 'dze_content_image_attach', nonce: cfg.nonce, post: sh.id, urls: sh.urls, target: sh.target })
				.done(function (res) {
					if (res && res.success) {
						okCount++;
						sh.$w.find('.dze-cb-shotstate').css('color', '#0a7040').text('✓ ' + sprintf(i18n.attached, res.data.attached));
						sh.$w.find('.dze-cb-shot').removeClass('is-sel');
					} else {
						koCount++;
						sh.$w.find('.dze-cb-shotstate').css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error);
					}
				})
				.fail(function (x) { koCount++; sh.$w.find('.dze-cb-shotstate').css('color', '#b32d2e').text(reason(x)); })
				.always(function () { attachNext(k + 1); });
		}
		function runTexts() {
			var i = 0;
			(function next() {
				if (i >= jobs.length) {
					$btn.prop('disabled', false);
					$('#dze-cb-progress').text(sprintf(i18n.finished, okCount, koCount));
					return;
				}
				var j = jobs[i++];
				$.post(cfg.ajaxUrl, { action: 'dze_content_apply', nonce: cfg.nonce, post: j.id, field: j.fid, value: j.$f.find('textarea').val() })
					.done(function (res) {
						if (res && res.success) { okCount++; j.$f.find('.dze-cb-prevstate').css('color', '#0a7040').text('✓ ' + i18n.applied); }
						else { koCount++; j.$f.find('.dze-cb-prevstate').css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); }
					})
					.fail(function () { koCount++; j.$f.find('.dze-cb-prevstate').css('color', '#b32d2e').text(i18n.error); })
					.always(next);
			})();
		}
		attachNext(0);
	});

	function priceTask(id, $row) {
		var cost = $row.find('.dze-cb-cost').val();
		return $.post(cfg.ajaxUrl, { action: 'dze_content_price', nonce: cfg.nonce, post: id, cost: cost })
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				okCount++; status($row, '$' + res.data.regular + ' ✓');
			})
			.catch(function (msg) { koCount++; status($row, '$ ✗ ' + esc(reason(msg)), true); })
			.always(function () { progress('price'); });
	}

	// One attempt. In review mode nothing is attached: the result joins the
	// strip under the product and waits for a decision.
	function oneImage(id, $row, review) {
		var tpl = $row.find('.dze-cb-row-tpl').val();
		var data = { action: 'dze_content_image', nonce: cfg.nonce, post: id, template: tpl };
		if (review) { data.mode = 'defer'; }
		// One scene for the whole run — that is the point of a scene.
		var $sc = $('#dze-cb-scene');
		if ($sc.length) { data.scene = parseInt($sc.val(), 10); }
		return $.post(cfg.ajaxUrl, data)
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				okCount++;
				if (review) {
					addShot(id, res.data.url);
					status($row, 'img ' + i18n.toReview);
				} else {
					status($row, 'img ✓');
					// Better image visibility: refresh the row thumbnail.
					if (res.data.url) { $row.find('.dze-cb-thumb img').attr('src', res.data.url); }
				}
			})
			.catch(function (msg) { koCount++; status($row, 'img ✗ ' + esc(reason(msg)), true); })
			.always(function () { progress('image'); });
	}
	// N attempts on the same product, one after the other — the provider is
	// slow enough that firing four at once is how a run times out.
	function imageTask(id, $row, review, n) {
		var i = 0, d = $.Deferred();
		(function next() {
			if (stopped || i >= Math.max(1, n)) { d.resolve(); return; }
			i++;
			oneImage(id, $row, review).always(next);
		})();
		return d;
	}

	$('#dze-cb-start').on('click', function () {
		if (!cfg.validated) { return; }
		persist();
		var fields = $('.dze-cb-field:checked:not(:disabled)').map(function () { return $(this).val(); }).get();
		var doPrice = $('#dze-cb-price').is(':checked');
		var review = $('input[name="dze-cb-mode"]:checked').val() !== 'direct';
		$('.dze-cb-preview').hide().find('td').empty();
		$('#dze-cb-applyall').hide();
		if (!fields.length && !doPrice && !$('.dze-cb-row-img:checked').length) {
			window.alert(i18n.noFields);
			return;
		}

		// Build the flat task list: per product → fields, price, image.
		var tasks = [];
		$('.dze-cb-row').each(function () {
			var $row = $(this), id = $row.data('id');
			$row.find('.dze-cb-status').empty();
			if (fields.length) { tasks.push(function () { return textAllTask(id, fields, $row, review); }); }
			if (doPrice) { tasks.push(function () { return priceTask(id, $row); }); }
			if ($row.find('.dze-cb-row-img').is(':checked')) {
				var n = parseInt($('#dze-cb-imgn').val(), 10) || 1;
				tasks.push(function () { return imageTask(id, $row, review, n); });
			}
		});

		// An image task covers N attempts, each reporting its own progress.
		var imgN = parseInt($('#dze-cb-imgn').val(), 10) || 1;
		var imgRows = $('.dze-cb-row-img:checked').length;
		stopped = false; okCount = 0; koCount = 0; doneCount = 0;
		total = tasks.length + (imgRows * (imgN - 1));
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
