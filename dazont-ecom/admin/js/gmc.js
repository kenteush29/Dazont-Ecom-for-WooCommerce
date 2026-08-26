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
		// The row's own tick is the selection: one column, one meaning.
		$('.dze-rule-cb:checked').each(function () { ids.push($(this).val()); });
		if (!ids.length) { alert('No promotion selected.'); return; }
		sync(ids, $('#dze-gmc-bulk-status'));
	});

	// ---- What Google is holding, and ending any of it ----
	$(document).on('click', '#dze-gmc-promos', function () {
		var $b = $(this), $m = $('#dze-gmc-promos-msg'), $out = $('#dze-gmc-promos-out');
		$b.prop('disabled', true);
		$m.css('color', '#646970').text(dzeGmc.i18n.reading);
		$out.empty();
		$.post(dzeGmc.ajaxUrl, { action: 'dze_gmc_promotions', nonce: dzeGmc.nonce })
			.done(function (res) {
				$b.prop('disabled', false);
				if (!res || !res.success) {
					$m.css('color', '#b32d2e').text((res && res.data && res.data.message) || dzeGmc.i18n.error);
					return;
				}
				$m.text('');
				$.each(res.data.accounts, function (_, acc) {
					var $box = $('<div style="margin:14px 0;"/>');
					$box.append($('<h3 style="margin:0 0 6px;"/>').text(acc.key + ' · ' + acc.merchant));
					if (acc.error) {
						$box.append($('<p style="color:#b32d2e;margin:0;"/>').text(acc.error));
						$out.append($box);
						return;
					}
					if (!acc.rows.length) {
						$box.append($('<p class="description" style="margin:0;"/>').text(dzeGmc.i18n.none));
						$out.append($box);
						return;
					}
					var $t = $('<table class="widefat striped" style="max-width:900px;"/>');
					$t.append('<thead><tr><th>' + dzeGmc.i18n.colTitle + '</th><th style="width:110px;">' +
						dzeGmc.i18n.colWhere + '</th><th style="width:190px;">' + dzeGmc.i18n.colEnds +
						'</th><th style="width:90px;"></th></tr></thead>');
					var $body = $('<tbody/>');
					$.each(acc.rows, function (_, row) {
						var $tr = $('<tr/>');
						$tr.append($('<td/>').text(row.title));
						$tr.append($('<td/>').text(row.language.toUpperCase() + ' / ' + row.country));
						$tr.append($('<td/>').text(row.ends ? row.ends.replace('T', ' ').replace('Z', '') : '—'));
						$tr.append($('<td/>').append(
							$('<button type="button" class="button button-small dze-gmc-end"/>')
								.text(dzeGmc.i18n.end)
								.attr({
									'data-merchant': acc.merchant,
									'data-promotion': row.id,
									'data-country': row.country,
									'data-language': row.language
								})
						));
						$body.append($tr);
					});
					$t.append($body);
					$box.append($t);
					$out.append($box);
				});
			})
			.fail(function () {
				$b.prop('disabled', false);
				$m.css('color', '#b32d2e').text(dzeGmc.i18n.error);
			});
	});

	$(document).on('click', '.dze-gmc-end', function () {
		var $b = $(this);
		if (!window.confirm(dzeGmc.i18n.sure)) { return; }
		$b.prop('disabled', true).text(dzeGmc.i18n.ending);
		$.post(dzeGmc.ajaxUrl, {
			action: 'dze_gmc_end_promo',
			nonce: dzeGmc.nonce,
			merchant: $b.data('merchant'),
			promotion: $b.data('promotion'),
			country: $b.data('country'),
			language: $b.data('language')
		})
			.done(function (res) {
				if (res && res.success) {
					$b.closest('tr').css('opacity', .5).find('td').last()
						.text(dzeGmc.i18n.ended);
					return;
				}
				$b.prop('disabled', false).text(dzeGmc.i18n.end);
				$('#dze-gmc-promos-msg').css('color', '#b32d2e')
					.text((res && res.data && res.data.message) || dzeGmc.i18n.error);
			})
			.fail(function () {
				$b.prop('disabled', false).text(dzeGmc.i18n.end);
				$('#dze-gmc-promos-msg').css('color', '#b32d2e').text(dzeGmc.i18n.error);
			});
	});

}(jQuery));
