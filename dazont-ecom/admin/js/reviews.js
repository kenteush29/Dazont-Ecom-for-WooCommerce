/* global dzeReviews, jQuery */
/**
 * Review generator UI. Panel: generate → read the drafts → push them to the
 * reviews screen (pending) or discard, with a live prompt editor for tuning.
 * A bulk action runs on the list itself: a spinner per product cell, reviews
 * written straight to the moderation queue. Approving happens in
 * WooCommerce → Reviews.
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
	// On a product screen, the Reviews box WordPress already draws gets the
	// button: reviews are worked on where the reviews are.
	$(function () {
		if (!cfg.postId) { return; }
		var $box = $('#commentsdiv > .inside, #commentsdiv .inside').first();
		if (!$box.length || $box.find('.dze-rev-open').length) { return; }
		$box.prepend('<p class="dze-one-plant"><button type="button" class="button button-small dze-rev-open" data-id="' +
			cfg.postId + '">✦ <span></span>' + esc(cfg.plantLabel || '') + '</button></p>');
	});

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

	// ---- Bulk run, straight on the products list ----
	// A bulk action queued a selection: each product's Reviews cell shows a
	// spinner while its reviews are written, then the count updates. Reviews go
	// to the moderation queue directly — no preview at this stage.
	function runQueue(ids) {
		var total = ids.length, done = 0, made = 0, errors = 0;
		var $notice = $('<div class="notice notice-info dze-rev-run"><p></p></div>');
		$('.wrap > h1').first().after($notice);
		function say(txt) { $notice.find('p').html(txt); }
		say(sprintf(i18n.queueRun, 0, total));

		(function next(i) {
			if (i >= total) {
				$notice.removeClass('notice-info').addClass('notice-success');
				say(sprintf(i18n.queueDone, made, total - errors) +
					' <a href="' + cfg.reviewsUrl + '">' + esc(i18n.openList) + '</a>');
				return;
			}
			var id = ids[i];
			var $chip = $('.dze-rev-open[data-id="' + id + '"]');
			var before = $chip.html();
			$chip.prop('disabled', true).html('<span class="dze-cx-spin"></span>');
			$.post(cfg.ajaxUrl, { action: 'dze_reviews_generate', nonce: cfg.nonce, post: id, direct: 1 })
				.done(function (res) {
					if (!res || !res.success) {
						errors++;
						$chip.html(before).prop('disabled', false).attr('title', (res && res.data && res.data.message) || i18n.error);
						return;
					}
					made += res.data.created;
					$chip.prop('disabled', false).html(
						'<span style="color:#2271b1;font-weight:600;">' + res.data.total + '</span>' +
						'<span class="dze-caret">&#9662;</span>'
					);
				})
				.fail(function () { errors++; $chip.html(before).prop('disabled', false); })
				.always(function () {
					done++;
					say(sprintf(i18n.queueRun, done, total));
					next(i + 1);
				});
		})(0);
	}

	if (cfg.queue && cfg.queue.length) {
		runQueue(cfg.queue);
	}

}(jQuery));
