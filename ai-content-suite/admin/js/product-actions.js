/* global aicsPA, jQuery */
(function ($) {
	'use strict';

	var cfg  = aicsPA;
	var i18n = cfg.i18n;

	var targetProducts = [];
	var productTitles  = {};

	// ---- DOM ready: inject buttons + modals ----
	$(function () {
		// Modals: injected via the PHP-rendered HTML passed in cfg.modalHtml.
		if (!$('#aics-modal-generate').length && cfg.modalHtml) {
			$('body').append(cfg.modalHtml);
		}

		// Toolbar buttons: PHP may already have rendered them via restrict_manage_posts;
		// if not, inject them via JS.
		if (!$('#aics-pa-open-generate').length) {
			var $actions = $('.tablenav.top .actions').last();
			var btnGen = '<button type="button" class="button" id="aics-pa-open-generate" style="margin-left:6px;">' + escHtml(i18n.generate) + '</button>';
			if ($actions.length) {
				$actions.append(btnGen);
			} else {
				$('.tablenav.top').append('<div class="actions">' + btnGen + '</div>');
			}
		}
		if (cfg.wpmlActive && !$('#aics-pa-open-translate').length) {
			var btnTr = '<button type="button" class="button" id="aics-pa-open-translate" style="margin-left:6px;">' + escHtml(i18n.translate) + '</button>';
			$('#aics-pa-open-generate').after(btnTr);
		}

		// Wire up events after everything is in the DOM.
		wireEvents();
	});

	// ---- Wire all events ----
	function wireEvents() {

		// Toolbar buttons
		$(document).on('click', '#aics-pa-open-generate', function () {
			launchGenerate(getCheckedProducts());
		});
		$(document).on('click', '#aics-pa-open-translate', function () {
			launchTranslate(getCheckedProducts());
		});

		// Row actions
		$(document).on('click', '.aics-pa-row-generate', function (e) {
			e.preventDefault();
			launchGenerate([ parseInt($(this).data('id'), 10) ]);
		});
		$(document).on('click', '.aics-pa-row-translate', function (e) {
			e.preventDefault();
			launchTranslate([ parseInt($(this).data('id'), 10) ]);
		});

		// Generate modal start
		$(document).on('click', '#aics-modal-generate .aics-modal-start', function () {
			var $modal = $('#aics-modal-generate');
			var slots = [];
			$modal.find('.aics-gen-slot:checked').each(function () { slots.push($(this).val()); });
			if (!slots.length) { alert(i18n.noSlots); return; }

			var queue = [];
			targetProducts.forEach(function (pid) {
				slots.forEach(function (slot) { queue.push({ pid: pid, slot: slot }); });
			});

			var $start = $(this);
			$start.prop('disabled', true).text(i18n.working);
			$modal.find('.aics-modal-fieldset').css('opacity', '0.5');
			$modal.find('.aics-modal-progress').show();
			$modal.find('.aics-modal-log').show();

			runQueue($modal, queue, 0, function (item) {
				return { action: 'aics_pa_generate', nonce: cfg.nonce, post_id: item.pid, slot: item.slot };
			}, function (item, ok, info) {
				$modal.find('.aics-modal-log tbody').append(
					'<tr><td>' + escHtml(getProductTitle(item.pid)) + '</td>' +
					'<td>' + escHtml(cfg.slots[item.slot] || item.slot) + '</td>' +
					'<td>' + statusBadge(ok) + '</td>' +
					'<td>' + escHtml(info) + '</td></tr>'
				);
			}, $start);
		});

		// Translate modal start
		$(document).on('click', '#aics-modal-translate .aics-modal-start', function () {
			var $modal = $('#aics-modal-translate');
			var sourceLang = $modal.find('#aics-tr-source').val();
			var targetLangs = [];
			$modal.find('.aics-tr-target-lang:checked').each(function () { targetLangs.push($(this).val()); });
			var slots = [];
			$modal.find('.aics-tr-slot:checked').each(function () { slots.push($(this).val()); });

			if (!targetLangs.length) { alert(i18n.noLangs); return; }
			if (!slots.length) { alert(i18n.noSlots); return; }

			var queue = [];
			targetProducts.forEach(function (pid) {
				targetLangs.forEach(function (lang) {
					if (lang === sourceLang) { return; }
					slots.forEach(function (slot) {
						queue.push({ pid: pid, slot: slot, lang: lang });
					});
				});
			});
			if (!queue.length) { alert(i18n.sameLang); return; }

			var $start = $(this);
			$start.prop('disabled', true).text(i18n.working);
			$modal.find('.aics-modal-fieldset, .aics-lang-switch').css('opacity', '0.5');
			$modal.find('.aics-modal-progress').show();
			$modal.find('.aics-modal-log').show();

			runQueue($modal, queue, 0, function (item) {
				return {
					action: 'aics_pa_translate', nonce: cfg.nonce,
					post_id: item.pid, slot: item.slot,
					source_lang: sourceLang, target_lang: item.lang
				};
			}, function (item, ok, info) {
				$modal.find('.aics-modal-log tbody').append(
					'<tr><td>' + escHtml(getProductTitle(item.pid)) + '</td>' +
					'<td>' + escHtml(item.lang.toUpperCase()) + '</td>' +
					'<td>' + escHtml(cfg.slots[item.slot] || item.slot) + '</td>' +
					'<td>' + statusBadge(ok) + '</td>' +
					'<td>' + escHtml(info) + '</td></tr>'
				);
			}, $start);
		});

		// Close modals
		$(document).on('click', '.aics-modal-close', function () {
			closeModal($(this).closest('.aics-modal-overlay'));
		});
		$(document).on('click', '.aics-modal-overlay', function (e) {
			if (e.target === this) { closeModal($(this)); }
		});
	}

	// ---- Launch helpers ----
	function launchGenerate(products) {
		if (!products.length) { alert(i18n.noProducts); return; }
		targetProducts = products;
		var $modal = $('#aics-modal-generate');
		resetModal($modal);
		$modal.find('#aics-gen-target').text(describeTarget());
		openModal($modal);
	}

	function launchTranslate(products) {
		if (!products.length) { alert(i18n.noProducts); return; }
		targetProducts = products;
		var $modal = $('#aics-modal-translate');
		if (!$modal.length) { alert('WPML translation modal not found.'); return; }
		resetModal($modal);
		$modal.find('#aics-tr-target').text(describeTarget());
		openModal($modal);
	}

	// ---- Queue runner ----
	function runQueue($modal, queue, index, buildData, onResult, $start) {
		if (index >= queue.length) {
			$modal.find('.aics-progress-bar').css('width', '100%');
			$modal.find('.aics-progress-text').text(i18n.allDone + ' (' + queue.length + ')');
			$start.prop('disabled', false).text(i18n.start);
			return;
		}
		var item    = queue[index];
		var percent = Math.round((index / queue.length) * 100);
		$modal.find('.aics-progress-bar').css('width', percent + '%');
		$modal.find('.aics-progress-text').text((index + 1) + ' / ' + queue.length + ' — ' + getProductTitle(item.pid));

		$.post(cfg.ajaxUrl, buildData(item))
		.done(function (res) {
			if (res.success) {
				var info = (res.data && res.data.model)
					? res.data.model + ' · ' + (res.data.usage ? res.data.usage.output_tokens : 0) + ' tok'
					: '';
				onResult(item, true, info);
			} else {
				onResult(item, false, (res.data && res.data.message) || i18n.error);
			}
		})
		.fail(function () { onResult(item, false, 'Request failed'); })
		.always(function () { runQueue($modal, queue, index + 1, buildData, onResult, $start); });
	}

	// ---- Utilities ----
	function getCheckedProducts() {
		var ids = [];
		$('#the-list input[name="post[]"]:checked').each(function () {
			ids.push(parseInt($(this).val(), 10));
		});
		return ids;
	}

	function getProductTitle(id) {
		if (productTitles[id]) { return productTitles[id]; }
		var $link = $('#post-' + id + ' .row-title').first();
		var title = $link.length ? $.trim($link.text()) : ('#' + id);
		productTitles[id] = title;
		return title;
	}

	function openModal($overlay) {
		$overlay.css('display', 'flex');
		$('body').addClass('aics-modal-open');
	}

	function closeModal($overlay) {
		$overlay.hide();
		$('body').removeClass('aics-modal-open');
	}

	function resetModal($overlay) {
		$overlay.find('.aics-modal-progress').hide();
		$overlay.find('.aics-progress-bar').css('width', '0%');
		$overlay.find('.aics-progress-text').text('');
		$overlay.find('.aics-modal-log').hide().find('tbody').empty();
		$overlay.find('.aics-modal-start').prop('disabled', false).text(i18n.start);
		$overlay.find('.aics-modal-fieldset, .aics-lang-switch').css('opacity', '1');
	}

	function describeTarget() {
		return targetProducts.length === 1
			? getProductTitle(targetProducts[0])
			: targetProducts.length + ' ' + i18n.productsTargeted;
	}

	function statusBadge(ok) {
		return ok
			? '<span class="aics-status-ok">&#10003; ' + i18n.done + '</span>'
			: '<span class="aics-status-err">&#10007; ' + i18n.error + '</span>';
	}

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;')
			.replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

}(jQuery));
