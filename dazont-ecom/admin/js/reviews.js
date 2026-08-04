/* global dzeReviews, jQuery */
/**
 * Review generator UI. Products list: the Reviews count opens a per-product
 * panel (generate → editable drafts → publish → delete generated). Bulk
 * screen: same flow over the queued products, with a review-first default.
 */
(function ($) {
	'use strict';

	var cfg = dzeReviews, i18n = cfg.i18n;

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return str.replace(/%\d\$s|%s/g, function () { return args[i++]; });
	}

	// One editable draft card. Everything stays local until "publish".
	function draftCard(r, i) {
		var stars = '';
		for (var s = 1; s <= 5; s++) {
			stars += '<option value="' + s + '"' + (r.rating === s ? ' selected' : '') + '>' + s + ' ★</option>';
		}
		return '<div class="dze-rev-draft" data-i="' + i + '">' +
			'<p class="dze-rev-meta">' +
				'<label>' + esc(i18n.name) + ' <input type="text" class="dze-rev-f-name" value="' + esc(r.name) + '" /></label> ' +
				'<label>' + esc(i18n.rating) + ' <select class="dze-rev-f-rating">' + stars + '</select></label> ' +
				'<label>' + esc(i18n.date) + ' <input type="date" class="dze-rev-f-date" value="' + esc(r.date || '') + '" /></label>' +
			'</p>' +
			'<input type="text" class="dze-rev-f-title" placeholder="' + esc(i18n.title) + '" value="' + esc(r.title || '') + '" />' +
			'<textarea rows="3" class="dze-rev-f-text">' + esc(r.text) + '</textarea>' +
			'</div>';
	}

	function collect($wrap) {
		var out = [];
		$wrap.find('.dze-rev-draft').each(function () {
			var $d = $(this);
			out.push({
				name: $d.find('.dze-rev-f-name').val(),
				rating: parseInt($d.find('.dze-rev-f-rating').val(), 10) || 5,
				title: $d.find('.dze-rev-f-title').val(),
				text: $d.find('.dze-rev-f-text').val(),
				date: $d.find('.dze-rev-f-date').val()
			});
		});
		return out;
	}

	// ---- Products list: popup ----
	$(document).on('click', '.dze-rev-open', function () {
		var id = $(this).data('id');
		$('#dze-rev-body').html('<p><span class="dze-cx-spin"></span></p>');
		$('#dze-rev-modal').addClass('is-open');
		$.post(cfg.ajaxUrl, { action: 'dze_reviews_panel', nonce: cfg.nonce, post: id })
			.done(function (res) {
				if (res && res.success) {
					$('#dze-rev-title').text(res.data.title);
					$('#dze-rev-body').html(res.data.html);
				} else {
					$('#dze-rev-body').text((res && res.data && res.data.message) || i18n.error);
				}
			})
			.fail(function () { $('#dze-rev-body').text(i18n.error); });
	});
	$(document).on('click', '.dze-hub-close', function () { $(this).closest('.dze-cx-modal').removeClass('is-open'); });
	$(document).on('click', '#dze-rev-modal', function (e) { if (e.target === this) { $(this).removeClass('is-open'); } });

	$(document).on('click', '.dze-rev-gen', function () {
		var $box = $(this).closest('.dze-rev-box'), $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-rev-status').css('color', '#646970').html('<span class="dze-cx-spin"></span> ' + esc(i18n.working));
		$.post(cfg.ajaxUrl, {
			action: 'dze_reviews_generate', nonce: $box.data('nonce'),
			post: $box.data('post'), count: $box.find('.dze-rev-count').val()
		})
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { $st.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#0a7040').text(i18n.ready);
				$box.find('.dze-rev-drafts').html(res.data.reviews.map(draftCard).join(''));
				$box.find('.dze-rev-actions').show();
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
	});

	$(document).on('click', '.dze-rev-publish', function () {
		var $box = $(this).closest('.dze-rev-box'), $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-rev-status').css('color', '#646970').html('<span class="dze-cx-spin"></span>');
		$.post(cfg.ajaxUrl, {
			action: 'dze_reviews_publish', nonce: $box.data('nonce'),
			post: $box.data('post'), reviews: collect($box)
		})
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { $st.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#0a7040').text(res.data.published + ' ' + i18n.published);
				$box.find('.dze-rev-drafts').empty();
				$box.find('.dze-rev-actions').hide();
				$('.dze-rev-open[data-id="' + $box.data('post') + '"] span').first().text(res.data.total).css('color', '#2271b1');
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
	});

	$(document).on('click', '.dze-rev-del', function () {
		if (!window.confirm(i18n.confirmDel)) { return; }
		var $box = $(this).closest('.dze-rev-box'), $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_reviews_delete', nonce: $box.data('nonce'), post: $box.data('post') })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { window.alert((res && res.data && res.data.message) || i18n.error); return; }
				$btn.text(i18n.deleted + ' ✓').prop('disabled', true);
				$('.dze-rev-open[data-id="' + $box.data('post') + '"] span').first().text(res.data.total).css('color', res.data.total ? '#2271b1' : '#a7aaad');
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	// ---- Bulk screen ----
	var stopped = false, okCount = 0, koCount = 0;

	$('#dze-rvb-start').on('click', function () {
		var nonce = $('.dze-cb-controls').data('nonce');
		var count = $('#dze-rvb-count').val();
		var review = $('input[name="dze-rvb-mode"]:checked').val() !== 'direct';
		var $rows = $('.dze-rvb-row');
		stopped = false; okCount = 0; koCount = 0;
		$('.dze-rvb-preview').hide().find('td').empty();
		$('#dze-rvb-publish').hide();
		$('.dze-cb-bar').show(); $('.dze-cb-fill').css('width', 0);
		$(this).prop('disabled', true);
		$('#dze-rvb-stop').show();

		var total = $rows.length, done = 0;
		(function next(i) {
			if (stopped || i >= total) {
				$('#dze-rvb-start').prop('disabled', false);
				$('#dze-rvb-stop').hide();
				$('#dze-rvb-progress').text(stopped ? i18n.stopped : sprintf(i18n.finished, okCount, koCount));
				if (review && !stopped) { $('#dze-rvb-publish').show(); }
				return;
			}
			var $row = $rows.eq(i), id = $row.data('id');
			$row.find('.dze-cb-status').html('<span class="dze-cx-spin"></span>');
			$.post(cfg.ajaxUrl, { action: 'dze_reviews_generate', nonce: nonce, post: id, count: count })
				.done(function (res) {
					if (!res || !res.success) {
						koCount++;
						$row.find('.dze-cb-status').html('<span class="ko">' + esc((res && res.data && res.data.message) || i18n.error) + '</span>');
						return;
					}
					if (review) {
						$('.dze-rvb-preview[data-id="' + id + '"]').show().find('td')
							.html('<div class="dze-rev-box" data-post="' + id + '" data-nonce="' + nonce + '"><div class="dze-rev-drafts">' +
								res.data.reviews.map(draftCard).join('') + '</div></div>');
						$row.find('.dze-cb-status').html('<span class="ok">' + res.data.reviews.length + ' ✓</span>');
					} else {
						publishRows(id, res.data.reviews, nonce, $row);
					}
				})
				.fail(function () { koCount++; $row.find('.dze-cb-status').html('<span class="ko">✗</span>'); })
				.always(function () {
					done++;
					$('.dze-cb-fill').css('width', Math.round(100 * done / total) + '%');
					$('#dze-rvb-progress').text(sprintf(i18n.progress, done, total));
					next(i + 1);
				});
		})(0);
	});

	function publishRows(id, reviews, nonce, $row) {
		return $.post(cfg.ajaxUrl, { action: 'dze_reviews_publish', nonce: nonce, post: id, reviews: reviews })
			.done(function (res) {
				if (res && res.success) {
					okCount += res.data.published;
					$row.find('.dze-rvb-count').text(res.data.total);
					$row.find('.dze-cb-status').html('<span class="ok">' + res.data.published + ' ' + esc(i18n.published) + '</span>');
				} else {
					koCount++;
					$row.find('.dze-cb-status').html('<span class="ko">✗</span>');
				}
			});
	}

	$('#dze-rvb-stop').on('click', function () { stopped = true; });

	// Publish every reviewed draft of the bulk screen, product by product.
	$('#dze-rvb-publish').on('click', function () {
		var nonce = $('.dze-cb-controls').data('nonce');
		var $btn = $(this).prop('disabled', true);
		var items = $('.dze-rvb-preview:visible').toArray();
		var i = 0;
		(function next() {
			if (i >= items.length) {
				$btn.prop('disabled', false);
				$('#dze-rvb-progress').text(sprintf(i18n.finished, okCount, koCount));
				return;
			}
			var $prev = $(items[i++]), id = $prev.data('id');
			var $row = $('.dze-rvb-row[data-id="' + id + '"]');
			publishRows(id, collect($prev), nonce, $row).always(next);
		})();
	});

}(jQuery));
