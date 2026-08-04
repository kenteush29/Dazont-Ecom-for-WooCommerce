/* global dzeReviews, jQuery */
/**
 * Review generator UI. Panel: generate → read the drafts → push them to the
 * reviews screen (pending) or discard, with a live prompt editor for tuning.
 * Bulk screen: one direct pass over the queued products, a random count each.
 * Approving what was pushed happens in WooCommerce → Reviews.
 */
(function ($) {
	'use strict';

	var cfg = dzeReviews, i18n = cfg.i18n;

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return str.replace(/%\d\$s|%s/g, function () { return args[i++]; });
	}

	// ---- Products list: panel in a popup ----
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

	// Compact draft row: rating, author, date, text — plus ✕ to drop it.
	function draftRow(r, i) {
		return '<div class="dze-rev-draft" data-i="' + i + '">' +
			'<button type="button" class="dze-rev-drop" title="' + esc(i18n.drop) + '">&times;</button>' +
			'<span class="dze-rev-stars">' + '★'.repeat(r.rating) + '<span class="dze-rev-dim">' + '★'.repeat(5 - r.rating) + '</span></span> ' +
			'<strong>' + esc(r.name) + '</strong> ' +
			'<span class="dze-rev-dim">' + esc(r.date) + '</span>' +
			(r.title ? '<br /><em>' + esc(r.title) + '</em>' : '') +
			'<div class="dze-rev-text">' + esc(r.text) + '</div>' +
			'</div>';
	}

	// Drafts live in the DOM until they are pushed or discarded.
	var drafts = {};

	function renderDrafts($box) {
		var id = $box.data('post'), list = drafts[id] || [];
		$box.find('.dze-rev-drafts').html(list.map(draftRow).join(''));
		$box.find('.dze-rev-actions').toggle(list.length > 0);
		$box.find('.dze-rev-push').text(sprintf(i18n.push, list.length));
	}

	$(document).on('click', '.dze-rev-drop', function () {
		var $box = $(this).closest('.dze-rev-box'), i = $(this).closest('.dze-rev-draft').data('i');
		var id = $box.data('post');
		drafts[id] = (drafts[id] || []).filter(function (r, k) { return k !== i; });
		renderDrafts($box);
	});

	// Prompt editor (✎): tweak, regenerate, and 💾 when it is right.
	$(document).on('click', '.dze-rev-ptoggle', function () {
		$(this).closest('.dze-rev-box').find('.dze-rev-pwrap').toggle();
	});
	$(document).on('click', '.dze-rev-prestore', function () {
		$(this).closest('.dze-rev-box').find('.dze-rev-ptext').val(i18n.defaultPrompt);
	});
	$(document).on('click', '.dze-rev-psave', function () {
		var $box = $(this).closest('.dze-rev-box'), $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_reviews_save_prompt', nonce: $box.data('nonce'), prompt: $box.find('.dze-rev-ptext').val() })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (res && res.success) { $btn.text(i18n.savedPrompt); setTimeout(function () { $btn.text('💾 ' + i18n.savePrompt); }, 1800); }
				else { window.alert((res && res.data && res.data.message) || i18n.error); }
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	function setCount(id, total) {
		$('.dze-rev-open[data-id="' + id + '"] span').first().text(total).css('color', total ? '#2271b1' : '#a7aaad');
	}

	// Generate = preview only. Nothing is written before "Push".
	$(document).on('click', '.dze-rev-gen', function () {
		var $box = $(this).closest('.dze-rev-box'), $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-rev-status').css('color', '#646970').html('<span class="dze-cx-spin"></span> ' + esc(i18n.working));
		var $p = $box.find('.dze-rev-pwrap');
		$.post(cfg.ajaxUrl, {
			action: 'dze_reviews_generate', nonce: $box.data('nonce'),
			post: $box.data('post'), count: $box.find('.dze-rev-count').val(),
			// The live prompt applies to this run when the editor is open.
			prompt: $p.is(':visible') ? ($box.find('.dze-rev-ptext').val() || '') : ''
		})
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { $st.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.text('');
				drafts[$box.data('post')] = res.data.reviews;
				renderDrafts($box);
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
	});

	$(document).on('click', '.dze-rev-push', function () {
		var $box = $(this).closest('.dze-rev-box'), id = $box.data('post');
		var list = drafts[id] || [];
		if (!list.length) { return; }
		var $btn = $(this).prop('disabled', true);
		var $st = $box.find('.dze-rev-status').css('color', '#646970').html('<span class="dze-cx-spin"></span>');
		$.post(cfg.ajaxUrl, { action: 'dze_reviews_publish', nonce: $box.data('nonce'), post: id, reviews: list })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { $st.css('color', '#b32d2e').text((res && res.data && res.data.message) || i18n.error); return; }
				$st.css('color', '#0a7040').text(res.data.created + ' ' + (res.data.held ? i18n.pending : i18n.published));
				drafts[id] = [];
				renderDrafts($box);
				setCount(id, res.data.total);
			})
			.fail(function () { $btn.prop('disabled', false); $st.css('color', '#b32d2e').text(i18n.error); });
	});

	$(document).on('click', '.dze-rev-discard', function () {
		var $box = $(this).closest('.dze-rev-box');
		drafts[$box.data('post')] = [];
		renderDrafts($box);
		$box.find('.dze-rev-status').text('');
	});

	$(document).on('click', '.dze-rev-del', function () {
		if (!window.confirm(i18n.confirmDel)) { return; }
		var $box = $(this).closest('.dze-rev-box'), $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_reviews_delete', nonce: $box.data('nonce'), post: $box.data('post') })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) { window.alert((res && res.data && res.data.message) || i18n.error); return; }
				$btn.text(i18n.deleted + ' ✓').prop('disabled', true);
				setCount($box.data('post'), res.data.total);
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	// ---- Bulk screen ----
	var stopped = false;

	$('#dze-rvb-start').on('click', function () {
		var nonce = $('.tablenav.top').data('nonce');
		var min = parseInt($('#dze-rvb-min').val(), 10) || 1;
		var max = parseInt($('#dze-rvb-max').val(), 10) || min;
		if (max < min) { max = min; }
		var $rows = $('.dze-rvb-row'), total = $rows.length, done = 0, made = 0, errors = 0;
		stopped = false;
		$(this).prop('disabled', true);
		$('#dze-rvb-stop').show();
		$('#dze-rvb-done').hide();

		(function next(i) {
			if (stopped || i >= total) {
				$('#dze-rvb-start').prop('disabled', false);
				$('#dze-rvb-stop').hide();
				$('#dze-rvb-progress').text(stopped ? i18n.stopped : sprintf(i18n.finished, made, errors));
				if (made && !stopped) { $('#dze-rvb-done').show(); }
				return;
			}
			var $row = $rows.eq(i), id = $row.data('id');
			// A different count for each product, drawn in the chosen range.
			var count = Math.floor(Math.random() * (max - min + 1)) + min;
			$row.find('.dze-rvb-status').html('<span class="dze-cx-spin"></span>');
			$('#dze-rvb-progress').text(sprintf(i18n.progress, done, total));
			$.post(cfg.ajaxUrl, { action: 'dze_reviews_generate', nonce: nonce, post: id, count: count, direct: 1 })
				.done(function (res) {
					if (!res || !res.success) {
						errors++;
						$row.find('.dze-rvb-status').html('<span style="color:#b32d2e;">' + esc((res && res.data && res.data.message) || i18n.error) + '</span>');
						return;
					}
					made += res.data.created;
					$row.find('.dze-rvb-count').text(res.data.total);
					$row.find('.dze-rvb-status').html('<span style="color:#0a7040;">+' + res.data.created + ' ' + esc(res.data.held ? i18n.pending : i18n.published) + '</span>');
				})
				.fail(function () { errors++; $row.find('.dze-rvb-status').html('<span style="color:#b32d2e;">✗</span>'); })
				.always(function () {
					done++;
					$('#dze-rvb-progress').text(sprintf(i18n.progress, done, total));
					next(i + 1);
				});
		})(0);
	});

	$('#dze-rvb-stop').on('click', function () { stopped = true; });

}(jQuery));
