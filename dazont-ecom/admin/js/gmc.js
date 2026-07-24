/* global dzeGmc, jQuery */
(function ($) {
	'use strict';

	var cfg  = dzeGmc;
	var i18n = cfg.i18n;

	// ---- Settings: test connection ----
	$('#dze-gmc-test').on('click', function () {
		var $btn = $(this), $status = $('#dze-gmc-test-status');
		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.testing);
		$.post(cfg.ajaxUrl, { action: 'dze_gmc_test', nonce: cfg.nonce })
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').text('✓ ' + res.data.message);
			} else {
				$status.css('color', '#b32d2e').text('✕ ' + ((res.data && res.data.message) || i18n.error));
			}
		})
		.fail(function () { $status.css('color', '#b32d2e').text(i18n.error); })
		.always(function () { $btn.prop('disabled', false); });
	});

	// ---- Settings: verify a specific Merchant Center account ----
	$(document).on('click', '.dze-gmc-verify', function () {
		var $btn    = $(this);
		var $status = $btn.siblings('.dze-gmc-verify-status');
		var mid     = ($('#' + $btn.data('target')).val() || '').replace(/[^0-9]/g, '');
		if (!mid) {
			$status.css('color', '#b32d2e').text('✕ ' + (i18n.error));
			return;
		}
		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.verifying);
		$.post(cfg.ajaxUrl, { action: 'dze_gmc_verify', nonce: cfg.nonce, merchant_id: mid })
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').text('✓ ' + res.data.message);
			} else {
				$status.css('color', '#b32d2e').text('✕ ' + ((res.data && res.data.message) || i18n.error));
			}
		})
		.fail(function () { $status.css('color', '#b32d2e').text(i18n.error); })
		.always(function () { $btn.prop('disabled', false); });
	});

	// ---- Settings: register the GCP project with the merchant account ----
	$(document).on('click', '.dze-gmc-register', function () {
		var $btn    = $(this);
		var $status = $btn.siblings('.dze-gmc-verify-status');
		var mid     = ($('#' + $btn.data('target')).val() || '').replace(/[^0-9]/g, '');
		if (!mid) {
			$status.css('color', '#b32d2e').text('✕ ' + (i18n.error));
			return;
		}
		$btn.prop('disabled', true);
		$status.css('color', '#666').text(i18n.registering);
		$.post(cfg.ajaxUrl, { action: 'dze_gmc_register', nonce: cfg.nonce, merchant_id: mid })
		.done(function (res) {
			if (res.success) {
				$status.css('color', '#0a7040').text('✓ ' + res.data.message);
			} else {
				$status.css('color', '#b32d2e').text('✕ ' + ((res.data && res.data.message) || i18n.error));
			}
		})
		.fail(function () { $status.css('color', '#b32d2e').text(i18n.error); })
		.always(function () { $btn.prop('disabled', false); });
	});

	// ---- Discounts list: sync one / sync selected ----
	// Flattens the { ruleId: { "lang|COUNTRY": {status,message} } } response
	// into a short, human-readable outcome so a sync is never silent.
	function summarize(results) {
		var parts = [], ok = 0, err = 0, total = 0;
		Object.keys(results || {}).forEach(function (rid) {
			var statuses = results[rid] || {};
			Object.keys(statuses).forEach(function (sk) {
				total++;
				var s = statuses[sk] || {};
				var country = sk.split('|').pop();
				if (s.status === 'synced') { ok++; parts.push(country + ': ✓'); }
				else { err++; parts.push(country + ': ' + (s.message || 'error')); }
			});
		});
		if (total === 0) {
			return { color: '#b32d2e', text: 'No sync target — check the promo has start+end dates and at least one target country configured.' };
		}
		return { color: err ? '#b32d2e' : '#0a7040', text: (err ? '✕ ' : '✓ ') + parts.join('  |  ') };
	}

	function sync(ids, $feedback) {
		if (!ids.length) { return; }
		if ($feedback) { $feedback.css('color', '#666').text(i18n.syncing); }
		$.post(cfg.ajaxUrl, { action: 'dze_gmc_sync', nonce: cfg.nonce, ids: ids })
		.done(function (res) {
			if (res.success) {
				var out = summarize(res.data && res.data.results);
				if ($feedback) { $feedback.css('color', out.color).text(out.text); }
			} else if ($feedback) {
				$feedback.css('color', '#b32d2e').text((res.data && res.data.message) || i18n.error);
			}
		})
		.fail(function () { if ($feedback) { $feedback.css('color', '#b32d2e').text(i18n.error); } });
	}

	$(document).on('click', '.dze-gmc-sync-one', function (e) {
		e.preventDefault();
		sync([ $(this).data('rule') ], $(this).closest('td').find('.dze-gmc-feedback'));
	});

	$(document).on('click', '#dze-gmc-sync-selected', function () {
		var ids = [];
		$('.dze-gmc-cb:checked').each(function () { ids.push($(this).val()); });
		if (!ids.length) { alert('No promotion selected.'); return; }
		sync(ids, $('#dze-gmc-bulk-status'));
	});

}(jQuery));
