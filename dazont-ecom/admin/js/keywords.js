/* global dzeKw, jQuery */
/**
 * Keyword Workbench — SEMrush keyword set of the category open in the
 * Sourcing Assistant overlay. Loads the whole set once, then filters/sorts
 * entirely client-side. Import goes through a column-mapping confirmation.
 */
(function ($) {
	'use strict';

	var cfg  = dzeKw;
	var i18n = cfg.i18n;

	var PAGE = 400; // rows rendered at once — keeps a 4000-row set instant.

	var kw = {
		cat: 0,
		open: false,
		loaded: false,
		rows: [],
		sort: { key: 'volume', dir: 'desc' },
		page: 1,
		total: 0,
		metrics: {},
		sel: {},
		limit: PAGE,
		upload: null // pending {token, headers, guess, sample, total}
	};

	function esc(s) { return $('<span>').text(s == null ? '' : s).html(); }
	function cat() { return parseInt($('#dze-x-overlay').attr('data-cat'), 10) || 0; }

	var STATUSES = [
		{ v: '',          l: i18n.stNone },
		{ v: 'covered',   l: i18n.stCovered },
		{ v: 'variation', l: i18n.stVariation },
		{ v: 'gap',       l: i18n.stGap },
		{ v: 'to_source', l: i18n.stToSource },
		{ v: 'uncertain', l: i18n.stUncertain },
		{ v: 'ignored',   l: i18n.stIgnored }
	];

	// ---- Static selects (filters + bulk) ----
	(function initSelects() {
		var f = '<option value="">' + esc(i18n.anyStatus) + '</option>';
		STATUSES.slice(1).forEach(function (s) { f += '<option value="' + s.v + '">' + esc(s.l) + '</option>'; });
		f += '<option value="none">' + esc(i18n.stNone) + '</option>';
		$('#dze-x-kw-status').html(f);

		var b = '<option value="__">' + esc(i18n.bulk) + '</option>';
		STATUSES.slice(1).forEach(function (s) { b += '<option value="' + s.v + '">' + esc(s.l) + '</option>'; });
		b += '<option value="">' + esc(i18n.stNone) + '</option>';
		$('#dze-x-kw-bulk').html(b);
	}());

	// =====================================================================
	// Panel toggle + lifecycle
	// =====================================================================
	function setOpen(open) {
		kw.open = open;
		$('#dze-x-kw').toggle(open);
		$('#dze-x-grid, .dze-x-more').toggle(!open);
		$('#dze-x-subcats').toggle(!open && $('#dze-x-subcats').children().length > 0);
		$('#dze-x-kw-toggle').html(open ? '🖼 ' + esc(i18n.products) : '🔑 ' + esc(i18n.keywords));
		if (open && (!kw.loaded || kw.cat !== cat())) { loadRows(); }
	}
	$('#dze-x-kw-toggle').on('click', function () { setOpen(!kw.open); });

	// A new category was opened (row or sub-category chip) or the overlay closed: reset.
	$(document).on('click', '.dze-x-row, .dze-x-subcat, #dze-x-ov-close', function () {
		kw.open = false; kw.loaded = false; kw.rows = []; kw.sel = {}; kw.page = 1; kw.cat = 0; kw.intentsDone = false;
		$('#dze-x-kw').hide();
		$('#dze-x-grid, .dze-x-more').show();
		$('#dze-x-kw-prog').text('');
		$('#dze-x-kw-toggle').html('🔑 ' + esc(i18n.keywords));
	});

	var PER = 200;
	function params() {
		return {
			action: 'dze_kw_list', nonce: cfg.nonce, cat: kw.cat, paged: kw.page,
			q: $('#dze-x-kw-q').val() || '', vmin: $('#dze-x-kw-vmin').val() || '',
			kdmax: $('#dze-x-kw-kdmax').val() || '', status: $('#dze-x-kw-status').val() || '',
			intent: $('#dze-x-kw-intent').val() || '', sortk: kw.sort.key || 'volume', sortd: kw.sort.dir || 'desc'
		};
	}
	function loadRows() {
		kw.cat = cat() || kw.cat;
		if (!kw.cat) { return; }
		$('#dze-x-kw-table').html('<p style="padding:14px;">' + esc(i18n.loading) + '</p>');
		$.post(cfg.ajaxUrl, params())
			.done(function (res) {
				if (!res.success) { $('#dze-x-kw-table').html('<p style="padding:14px;">' + esc(i18n.error) + '</p>'); return; }
				kw.rows = res.data.rows || [];
				kw.total = res.data.total || 0;
				kw.metrics = res.data.metrics || {};
				kw.loaded = true;
				kw.sel = {};
				if (!kw.intentsDone) { buildIntentFilter(res.data.intents || []); kw.intentsDone = true; }
				render();
			})
			.fail(function () { $('#dze-x-kw-table').html('<p style="padding:14px;">' + esc(i18n.error) + '</p>'); });
	}
	function buildIntentFilter(list) {
		var html = '<option value="">' + esc(i18n.anyIntent) + '</option>', seen = {};
		list.forEach(function (v) {
			(v || '').split(',').forEach(function (piece) {
				piece = $.trim(piece);
				if (piece && !seen[piece]) { seen[piece] = true; html += '<option value="' + esc(piece) + '">' + esc(piece) + '</option>'; }
			});
		});
		$('#dze-x-kw-intent').html(html);
	}
	function stLabel(v) {
		for (var i = 0; i < STATUSES.length; i++) { if (STATUSES[i].v === v) { return STATUSES[i].l; } }
		return v || '—';
	}
	function metricsHtml() {
		var m = kw.metrics || {};
		var chips = ['<strong>' + (m.total || 0) + '</strong> kw' + (m.ignored ? ' <span class="dze-x-kw-dim">(' + m.ignored + ' ' + esc(i18n.mIgnored) + ')</span>' : '')];
		chips.push(esc(i18n.mVolume) + ' <strong>' + (m.vol || 0).toLocaleString() + '</strong>');
		if (m.cpcv > 0) { chips.push(esc(i18n.mWcpc) + ' <strong>' + (m.cpcw / m.cpcv).toFixed(2) + '</strong>'); }
		if (m.kd) { chips.push(esc(i18n.mAvgKd) + ' <strong>' + Math.round(m.kd) + '</strong>'); }
		if (m.total > 0) {
			var apct = Math.round(100 * (m.analysed || 0) / m.total);
			chips.push('<span class="dze-x-kw-anz">' + esc(i18n.mAnalysed || 'Analysed') + ' <strong>' + (m.analysed || 0).toLocaleString() + '/' + m.total.toLocaleString() + '</strong> (' + apct + '%)</span>');
		}
		if (m.prod_total > 0) { chips.push(esc(i18n.mCompletion) + ' <strong>' + Math.round(100 * (m.covered || 0) / m.prod_total) + '%</strong> (' + (m.covered || 0) + '/' + m.prod_total + ')'); }
		chips.push('<span class="dze-x-kw-gaps">' + (m.gaps || 0) + ' ' + esc(i18n.mGaps) + '</span>');
		return chips.join('<span class="dze-x-kw-sep">·</span>');
	}
	function render() {
		$('#dze-x-kw-metrics').html(metricsHtml());
		if (!kw.total && !kw.rows.length && !($('#dze-x-kw-q').val() || '').length) {
			$('#dze-x-kw-table').html('<p style="padding:14px;" class="description">' + esc(i18n.empty) + '</p>');
			return;
		}
		var arrow = function (k) { return kw.sort.key === k ? (kw.sort.dir === 'asc' ? ' ▲' : ' ▼') : ''; };
		var html = '<table class="dze-x-kw-tbl"><thead><tr>' +
			'<th class="dze-x-kw-chk"><input type="checkbox" id="dze-x-kw-all" /></th>' +
			'<th class="dze-x-kw-srt" data-k="keyword">' + esc(i18n.fKeyword) + arrow('keyword') + '</th>' +
			'<th class="dze-x-kw-srt dze-x-kw-r" data-k="volume">' + esc(i18n.fVolume) + arrow('volume') + '</th>' +
			'<th class="dze-x-kw-srt dze-x-kw-r" data-k="kd">' + esc(i18n.fKd) + arrow('kd') + '</th>' +
			'<th class="dze-x-kw-srt dze-x-kw-r" data-k="cpc">' + esc(i18n.fCpc) + arrow('cpc') + '</th>' +
			'<th class="dze-x-kw-srt" data-k="intent">' + esc(i18n.fIntent) + arrow('intent') + '</th>' +
			'<th class="dze-x-kw-srt" data-k="status">' + esc(i18n.fStatus) + arrow('status') + '</th></tr></thead><tbody>';
		if (!kw.rows.length) { html += '<tr><td colspan="7" style="padding:14px;">' + esc(i18n.noMatch) + '</td></tr>'; }
		kw.rows.forEach(function (r) {
			html += '<tr class="dze-x-kw-row st-' + (r.status || 'none') + '">' +
				'<td class="dze-x-kw-chk"><input type="checkbox" class="dze-x-kw-cb" data-id="' + r.id + '" /></td>' +
				'<td>' + esc(r.kw) + '</td>' +
				'<td class="dze-x-kw-r">' + r.vol.toLocaleString() + '</td>' +
				'<td class="dze-x-kw-r">' + (r.kd == null ? '—' : Math.round(r.kd)) + '</td>' +
				'<td class="dze-x-kw-r">' + (r.cpc == null ? '—' : r.cpc.toFixed(2)) + '</td>' +
				'<td>' + esc(r.intent || '—') + '</td>' +
				'<td><button type="button" class="dze-x-stb st-' + (r.status || 'none') + '" data-id="' + r.id + '">' + esc(stLabel(r.status)) + '</button></td></tr>';
		});
		html += '</tbody></table>';
		var pages = Math.max(1, Math.ceil(kw.total / PER));
		html += '<div class="dze-x-kw-pager">' +
			'<button type="button" class="button" id="dze-x-kw-prev"' + (kw.page <= 1 ? ' disabled' : '') + '>‹</button>' +
			'<span>' + kw.page + ' / ' + pages + ' · ' + kw.total.toLocaleString() + ' kw</span>' +
			'<button type="button" class="button" id="dze-x-kw-next"' + (kw.page >= pages ? ' disabled' : '') + '>›</button></div>';
		$('#dze-x-kw-table').html(html);
		$('#dze-x-kw-table').scrollTop(0);
	}
	$(document).on('click', '#dze-x-kw-prev', function () { if (kw.page > 1) { kw.page--; loadRows(); } });
	$(document).on('click', '#dze-x-kw-next', function () { kw.page++; loadRows(); });

	var filterTimer = null;
	function filterChanged() {
		clearTimeout(filterTimer);
		filterTimer = setTimeout(function () { kw.page = 1; loadRows(); }, 350);
	}
	$('#dze-x-kw-q, #dze-x-kw-vmin, #dze-x-kw-kdmax').on('input', filterChanged);
	$('#dze-x-kw-status, #dze-x-kw-intent').on('change', function () { kw.page = 1; loadRows(); });
	$(document).on('click', '.dze-x-kw-srt', function () {
		var k = $(this).data('k');
		if (kw.sort.key === k) { kw.sort.dir = kw.sort.dir === 'asc' ? 'desc' : 'asc'; }
		else { kw.sort = { key: k, dir: (k === 'keyword' || k === 'intent' || k === 'status') ? 'asc' : 'desc' }; }
		kw.page = 1;
		loadRows();
	});

	// ---- Selection ----
	$(document).on('change', '#dze-x-kw-all', function () {
		var on = this.checked;
		$('.dze-x-kw-cb').each(function () { this.checked = on; kw.sel[$(this).data('id')] = on; });
	});
	$(document).on('change', '.dze-x-kw-cb', function () { kw.sel[$(this).data('id')] = this.checked; });

	// ---- Status: click the badge, pick in a select created on demand ----
	function postStatus(ids, status, done) {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_status', nonce: cfg.nonce, ids: ids, status: status })
			.done(function (res) { if (res.success && done) { done(); } });
	}
	$(document).on('click', '.dze-x-stb', function (e) {
		e.stopPropagation();
		var id = parseInt($(this).data('id'), 10);
		var sel = '<select class="dze-x-kw-st" data-id="' + id + '">';
		STATUSES.forEach(function (s) { sel += '<option value="' + s.v + '">' + esc(s.l) + '</option>'; });
		var $sel = $(sel + '</select>');
		$(this).replaceWith($sel);
		$sel.focus();
	});
	$(document).on('change', '.dze-x-kw-st', function () {
		var id = parseInt($(this).data('id'), 10), status = $(this).val();
		var $sel = $(this);
		postStatus([id], status, function () {
			$sel.closest('tr').attr('class', 'dze-x-kw-row st-' + (status || 'none'));
			$sel.replaceWith('<button type="button" class="dze-x-stb st-' + (status || 'none') + '" data-id="' + id + '">' + esc(stLabel(status)) + '</button>');
			refreshBadge();
		});
	});
	$('#dze-x-kw-apply').on('click', function () {
		var status = $('#dze-x-kw-bulk').val();
		if (status === '__') { return; }
		var ids = Object.keys(kw.sel).filter(function (id) { return kw.sel[id]; }).map(Number);
		if (!ids.length) { return; }
		postStatus(ids, status, function () { loadRows(); refreshBadge(); });
	});
	$('#dze-x-kw-delsel').on('click', function () {
		var ids = Object.keys(kw.sel).filter(function (id) { return kw.sel[id]; }).map(Number);
		if (!ids.length) { return; }
		if (!window.confirm(ids.length + ' keywords — delete permanently?')) { return; }
		$.post(cfg.ajaxUrl, { action: 'dze_kw_delete', nonce: cfg.nonce, ids: ids }).done(function () { kw.sel = {}; loadRows(); refreshBadge(); });
	});
	$('#dze-x-kw-deladded').on('click', function () {
		if (!window.confirm(i18n.confirmDelAdded || 'Remove the auto-added product-title keywords?')) { return; }
		$.post(cfg.ajaxUrl, { action: 'dze_kw_delete', nonce: cfg.nonce, cat: cat(), added: 1 }).done(function (res) {
			loadRows(); refreshBadge();
			if (res && res.success) { window.alert((res.data.deleted || 0) + ' removed'); }
		});
	});

	// Keep the performance-list badge + metrics in sync (server truth).
	function refreshBadge() {
		if (!kw.cat) { return; }
		$.post(cfg.ajaxUrl, params()).done(function (res) {
			if (!res.success) { return; }
			kw.metrics = res.data.metrics || kw.metrics;
			$('#dze-x-kw-metrics').html(metricsHtml());
			var m = kw.metrics, $b = $('.dze-x-row[data-cat="' + kw.cat + '"] .dze-x-row-kwbadge');
			if (m.total) { $b.text(m.total + ' kw · ' + (m.gaps || 0) + ' ' + (i18n.opps || 'opportunities')).show(); }
		});
	}

	// =====================================================================
	// AI analysis — server-side background job + polled progress bar.
	// Once started it runs to completion even if this page is closed.
	// =====================================================================
	var poll = null;

	function progressBar(pct) {
		return '<span class="dze-x-pbar"><span class="dze-x-pbar-fill" style="width:' + pct + '%"></span></span>';
	}
	// The main toolbar sits behind the full-screen overlay, so the bar is mirrored
	// into the workbench bar (#dze-x-kw-prog) as well.
	function setProg(html) { $('#dze-x-global-prog, #dze-x-kw-prog').html(html); }
	function renderProgress(p) {
		if (p.state === 'running') {
			var pct = p.total ? Math.round(100 * p.done / p.total) : 0;
			var cat = p.termName ? '<span class="dze-x-pbar-cat">' + esc(p.termName) + '</span> ' : '';
			setProg(progressBar(pct) +
				'<span class="dze-x-pbar-txt">' + cat + esc(i18n.analysing) + ' ' + p.done.toLocaleString() + '/' + p.total.toLocaleString() + ' · ' + pct + '%</span>' +
				'<button type="button" class="button-link dze-x-pstop" title="Stop">✕</button>');
		} else if (p.state === 'done') {
			setProg(progressBar(100) + '<span class="dze-x-pbar-txt">' + esc(i18n.analyseDone) + '</span>');
			afterJob(p.scope);
			window.setTimeout(function () { setProg(''); }, 8000);
		} else if (p.state === 'error') {
			setProg('<span class="dze-x-pbar-txt" style="color:#b32d2e;">' + esc(p.message || i18n.error) + '</span>');
		} else {
			setProg('');
		}
	}
	function afterJob() {
		// Refresh every category row from server truth (kw counts, analysed %,
		// opportunities, Report button) so nothing waits for a page reload.
		refreshRows();
		if (kw.cat && kw.open) { loadRows(); }
		refreshBadge();
	}

	// Rebuild the per-row keyword badge + reveal the Report/Analyse buttons,
	// using the same rolled-up figures a page reload would show.
	function exNonce() { return (window.dzeExplorer && window.dzeExplorer.nonce) || cfg.nonce; }
	function rowBadgeHtml(c) {
		var pct = c.kw ? Math.round(100 * c.analysed / c.kw) : 0;
		return '<span class="dze-x-kwstat"><strong>' + c.kw.toLocaleString() + '</strong> kw</span>' +
			'<span class="dze-x-kwstat">' + c.analysed.toLocaleString() + '/' + c.kw.toLocaleString() + ' ' + esc(i18n.analysedWord || 'analysed') + ' (' + pct + '%)</span>' +
			'<span class="dze-x-kwstat dze-x-kwopp"><strong>' + c.gaps.toLocaleString() + '</strong> ' + esc(i18n.opps || 'opportunities') + '</span>';
	}
	function refreshRows() {
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_kw_stats', nonce: exNonce() }).done(function (res) {
			if (!res.success || !res.data.stats) { return; }
			var stats = res.data.stats;
			$('.dze-x-row').each(function () {
				var $row = $(this), id = parseInt($row.attr('data-cat'), 10);
				var c = stats[id];
				if (!c) { return; }
				$row.attr('data-kw', c.kw).attr('data-kwopp', c.gaps).attr('data-ownkw', c.own_kw);
				var $badge = $row.find('.dze-x-row-kwbadge');
				if (c.kw > 0) { $badge.html(rowBadgeHtml(c)).show(); } else { $badge.hide(); }
				// Report button: visible when there are keywords; ✓ + green when a report exists.
				var $rep = $row.find('.dze-x-opp-cat');
				$rep.toggle(c.kw > 0).toggleClass('has-report', !!c.has_report)
					.html(c.has_report ? esc(i18n.reportDone || 'Report ✓') : esc(i18n.report || 'Report'));
				// Analyse button appears once the category has its own keyword file.
				$row.find('.dze-x-an').toggle(c.own_kw > 0);
			});
		});
	}
	function pollProgress() {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_progress', nonce: cfg.nonce }).done(function (res) {
			if (!res.success) { return; }
			var p = res.data;
			if (p.state === 'idle') { stopPolling(); setProg(''); return; }
			renderProgress(p);
			if (p.state !== 'running') { stopPolling(); }
		});
	}
	function startPolling() { if (!poll) { poll = window.setInterval(pollProgress, 2500); } pollProgress(); }
	function stopPolling() { if (poll) { window.clearInterval(poll); poll = null; } }

	$(document).on('click', '.dze-x-pstop', function () {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_stop', nonce: cfg.nonce }).always(function () {
			stopPolling(); setProg('');
		});
	});

	function minVol() { var v = parseInt($('#dze-x-kw-minvol').val(), 10); return isNaN(v) ? 0 : Math.max(0, v); }

	function startJob(catId) {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_start', nonce: cfg.nonce, cat: catId, minvol: minVol() })
			.done(function (res) {
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				startPolling();
			})
			.fail(function () { window.alert(i18n.error); });
	}
	// estimate → confirm cost (or offer reset when already analysed) → start job.
	function estimateThen(catId) {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_estimate', nonce: cfg.nonce, cat: catId, minvol: minVol() })
			.done(function (res) {
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				if (res.data.empty) {
					if (!window.confirm((i18n.reanalyse || 'Re-analyse all keywords from scratch (clears current verdicts)?') + ' (' + res.data.total + ')')) { return; }
					$.post(cfg.ajaxUrl, { action: 'dze_kw_reset', nonce: cfg.nonce, cat: catId }).done(function () { estimateThen(catId); });
					return;
				}
				if (!window.confirm(res.data.message)) { return; }
				startJob(catId);
			})
			.fail(function () { window.alert(i18n.error); });
	}
	$('#dze-x-kw-ai').on('click', function () { estimateThen(cat()); });
	$(document).on('click', '.dze-x-an', function (e) { e.stopPropagation(); estimateThen(parseInt($(this).data('cat'), 10) || 0); });

	// Count categories that hold products but have no keyword file imported.
	function noFileCount() {
		var n = 0;
		$('.dze-x-row').each(function () {
			var $r = $(this);
			if ((parseInt($r.attr('data-count'), 10) || 0) > 0 && (parseInt($r.attr('data-ownkw'), 10) || 0) === 0) { n++; }
		});
		return n;
	}
	// Bulk = analyse only what is still pending across every category. Never resets:
	// starting from scratch is a per-category action (its own page/row).
	$('#dze-x-kw-bulk-ai').on('click', function () {
		$.post(cfg.ajaxUrl, { action: 'dze_kw_estimate', nonce: cfg.nonce, cat: 0, minvol: minVol() })
			.done(function (res) {
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				if (res.data.empty) { window.alert(i18n.bulkAllAnalysed); return; }
				var msg = res.data.message;
				var nf = noFileCount();
				if (nf > 0) { msg += '\n\n' + i18n.bulkNoFile; }
				if (!window.confirm(msg)) { return; }
				startJob(0);
			})
			.fail(function () { window.alert(i18n.error); });
	});

	// Resume showing a job that is already running (e.g. after reopening the page).
	$(function () { startPolling(); });

	// ---- Product card "🔑 n kw covered" popup ----
	$(document).on('click', '.dze-x-kwprod', function (e) {
		e.stopPropagation();
		var pid = $(this).data('product'), pcat = $(this).data('cat');
		// Open the modal immediately with a spinner — the lookup can take a moment.
		$('#dze-x-modal').find('.dze-gal-modal__inner').html('<div class="dze-x-ai-body"><span class="dze-x-ai-spin"></span>' + esc(i18n.loading) + '</div>');
		$('#dze-x-modal').css('display', 'flex');
		$.post(cfg.ajaxUrl, { action: 'dze_kw_for_product', nonce: cfg.nonce, cat: pcat, product: pid })
			.done(function (res) {
				if (!res.success) { $('#dze-x-modal').find('.dze-gal-modal__inner').html('<p>' + esc(i18n.error) + '</p>'); return; }
				var html = '<h2 style="margin-top:0;">' + esc(i18n.kwCovered) + ' — ' + esc(res.data.title) + '</h2>';
				if (!res.data.rows.length) {
					html += '<p>' + esc(i18n.noKw) + '</p>';
				} else {
					html += '<table class="dze-x-kw-tbl"><thead><tr><th>' + esc(i18n.fKeyword) + '</th><th class="dze-x-kw-r">' + esc(i18n.fVolume) + '</th><th>' + esc(i18n.fStatus) + '</th></tr></thead><tbody>';
					res.data.rows.forEach(function (r) {
						html += '<tr><td>' + esc(r.kw) + '</td><td class="dze-x-kw-r">' + r.vol.toLocaleString() + '</td><td>' + esc(r.s === 'variation' ? i18n.stVariation : i18n.stCovered) + '</td></tr>';
					});
					html += '</tbody></table>';
				}
				$('#dze-x-modal').find('.dze-gal-modal__inner').html(html);
				$('#dze-x-modal').css('display', 'flex');
			})
			.fail(function () { $('#dze-x-modal').find('.dze-gal-modal__inner').html('<p>' + esc(i18n.error) + '</p>'); });
	});

	// =====================================================================
	// Import (upload → mapping confirmation → import)
	// =====================================================================
	var importCat = 0; // category targeted by the current import (row 📥 or overlay button).
	$('#dze-x-kw-import').on('click', function () { importCat = cat(); $('#dze-x-kw-file').trigger('click'); });
	$(document).on('click', '.dze-x-imp', function (e) {
		e.stopPropagation();
		importCat = parseInt($(this).data('cat'), 10) || 0;
		$('#dze-x-kw-file').trigger('click');
	});
	$('#dze-x-kw-file').on('change', function () {
		var file = this.files && this.files[0];
		this.value = '';
		if (!file) { return; }
		var fd = new FormData();
		fd.append('action', 'dze_kw_upload');
		fd.append('nonce', cfg.nonce);
		fd.append('file', file);
		// Reading + parsing a large CSV takes a moment — show the modal immediately.
		$('#dze-x-modal').find('.dze-gal-modal__inner').html('<div class="dze-x-ai-body"><span class="dze-x-ai-spin"></span>' + esc(i18n.loading) + '</div>');
		$('#dze-x-modal').css('display', 'flex');
		$.ajax({ url: cfg.ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false })
			.done(function (res) {
				if (!res.success) { $('#dze-x-modal').hide(); window.alert((res.data && res.data.message) || i18n.error); return; }
				kw.upload = res.data;
				showMapping();
			})
			.fail(function () { $('#dze-x-modal').hide(); window.alert(i18n.error); });
	});

	function showMapping() {
		var u = kw.upload;
		var fields = [
			{ k: 'keyword', l: i18n.fKeyword + ' *' },
			{ k: 'volume',  l: i18n.fVolume },
			{ k: 'kd',      l: i18n.fKd },
			{ k: 'cpc',     l: i18n.fCpc },
			{ k: 'intent',  l: i18n.fIntent }
		];
		var html = '<h2 style="margin-top:0;">' + esc(i18n.mapTitle) + '</h2>' +
			'<p class="description">' + esc(i18n.mapHelp) + ' <strong>' + u.total + '</strong> ' + esc(i18n.rowsFound) + '.</p>' +
			'<table class="dze-x-kw-map">';
		fields.forEach(function (f) {
			html += '<tr><th>' + esc(f.l) + '</th><td><select data-f="' + f.k + '">';
			html += '<option value="-1">' + esc(i18n.ignore) + '</option>';
			u.headers.forEach(function (h, i) {
				html += '<option value="' + i + '"' + (u.guess[f.k] === i ? ' selected' : '') + '>' + esc(h) + '</option>';
			});
			html += '</select></td></tr>';
		});
		html += '</table>';
		// Sample preview.
		html += '<div style="overflow:auto;max-height:200px;margin-top:10px;"><table class="dze-x-kw-tbl"><thead><tr>';
		u.headers.forEach(function (h) { html += '<th>' + esc(h) + '</th>'; });
		html += '</tr></thead><tbody>';
		u.sample.forEach(function (row) {
			html += '<tr>';
			u.headers.forEach(function (h, i) { html += '<td>' + esc(row[i] == null ? '' : row[i]) + '</td>'; });
			html += '</tr>';
		});
		html += '</tbody></table></div>';
		html += '<p style="margin-bottom:0;"><button type="button" class="button button-primary" id="dze-x-kw-doimport">' + esc(i18n.import) + '</button></p>';
		$('#dze-x-modal').find('.dze-gal-modal__inner').html(html);
		$('#dze-x-modal').css('display', 'flex');
	}

	$(document).on('click', '#dze-x-kw-doimport', function () {
		var u = kw.upload;
		if (!u) { return; }
		var map = {};
		$('.dze-x-kw-map select').each(function () { map[$(this).data('f')] = parseInt($(this).val(), 10); });
		if (map.keyword < 0) { window.alert(i18n.pickKw); return; }
		var target = importCat || cat();
		var $btn = $(this).prop('disabled', true);
		$.post(cfg.ajaxUrl, { action: 'dze_kw_import', nonce: cfg.nonce, cat: target, token: u.token, map: map })
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res.success) { window.alert((res.data && res.data.message) || i18n.error); return; }
				$('#dze-x-modal').hide();
				kw.upload = null;
				window.alert(res.data.imported + ' ' + i18n.imported + (res.data.updated ? ' (' + res.data.updated + ' ' + i18n.updated + ')' : ''));
				// Reveal the Analyse button right away; refresh badges/buttons from server.
				$('.dze-x-row[data-cat="' + target + '"]').find('.dze-x-an').show();
				if (kw.open && kw.cat === target) { loadRows(); }
				refreshRows();
				setTimeout(refreshBadge, 800);
			})
			.fail(function () { $btn.prop('disabled', false); window.alert(i18n.error); });
	});

	// =====================================================================
	// Row / bulk analysis is handled by the background-job block above.
	// =====================================================================

	// =====================================================================
	// Shop-wide opportunities panel — a COMPILATION of every saved sourcing
	// report. Categories that have gaps but no report yet are listed apart,
	// with a nudge to generate their report.
	// =====================================================================
	var opps = { open: false };

	function renderAllOpps(d) {
		var html = '';
		var n = (d.opps || []).length;
		if (!n && !(d.missing || []).length) {
			$('#dze-x-opps').html('<p style="padding:14px;" class="description">' + esc(i18n.allOppsEmpty) + '</p>');
			return;
		}
		html += '<p class="description dze-x-opps-note">' + esc(i18n.allOppsCompiled.replace('%s', d.reports || 0)) + '</p>';
		if (n) {
			html += '<table class="dze-x-kw-tbl"><thead><tr>' +
				'<th>' + esc(i18n.oppProduct) + '</th>' +
				'<th>' + esc(i18n.oppQueries) + '</th>' +
				'<th class="dze-x-kw-r">' + esc(i18n.fVolume) + '</th>' +
				'<th>' + esc(i18n.oppCat) + '</th><th></th></tr></thead><tbody>';
			d.opps.forEach(function (r) {
				var q = Array.isArray(r.queries) ? r.queries.join(', ') : (r.queries || '');
				html += '<tr class="dze-x-kw-row">' +
					'<td><strong>' + esc(r.product || '—') + '</strong></td>' +
					'<td>' + esc(q) + '</td>' +
					'<td class="dze-x-kw-r">' + (r.volume ? Number(r.volume).toLocaleString() : '—') + '</td>' +
					'<td>' + esc(r.catName || '') + '</td>' +
					'<td><button type="button" class="button button-small dze-x-opp-go" data-cat="' + r.cat + '">' + esc(i18n.oppOpen) + ' →</button></td></tr>';
			});
			html += '</tbody></table>';
		}
		if ((d.missing || []).length) {
			html += '<div class="dze-x-opps-missing"><strong>' + esc(i18n.missingReportsTitle) + '</strong>' +
				'<p class="description">' + esc(i18n.missingReportsHelp) + '</p><ul>';
			d.missing.forEach(function (m) {
				html += '<li><button type="button" class="button button-small dze-x-opp-go" data-cat="' + m.cat + '">' +
					esc(m.name) + '</button> <span class="dze-x-kw-gaps">' + m.gaps + ' ' + esc(i18n.gapsWord) + '</span></li>';
			});
			html += '</ul></div>';
		}
		$('#dze-x-opps').html(html);
	}

	$('#dze-x-opps-toggle').on('click', function () {
		opps.open = !opps.open;
		$('#dze-x-opps').toggle(opps.open);
		$('#dze-x-list, .dze-x-list-head').toggle(!opps.open);
		$(this).toggleClass('button-primary', opps.open);
		if (!opps.open) { return; }
		// Always refresh — reports may have been generated since last open.
		$('#dze-x-opps').html('<div class="dze-x-ai-body"><span class="dze-x-ai-spin"></span>' + esc(i18n.loading) + '</div>');
		$.post(cfg.ajaxUrl, { action: 'dze_explorer_all_opps', nonce: exNonce() })
			.done(function (res) {
				if (!res.success) { $('#dze-x-opps').html('<p style="padding:14px;">' + esc(i18n.error) + '</p>'); return; }
				renderAllOpps(res.data);
			})
			.fail(function () { $('#dze-x-opps').html('<p style="padding:14px;">' + esc(i18n.error) + '</p>'); });
	});
	$(document).on('click', '.dze-x-opp-go', function () {
		var $row = $('.dze-x-row[data-cat="' + $(this).data('cat') + '"]');
		if ($row.length) { $row.trigger('click'); }
	});

	// =====================================================================
	// Export + delete
	// =====================================================================
	$('#dze-x-kw-export').on('click', function () {
		if (!kw.rows.length) { return; }
		var rows = kw.rows.slice();
		var csv = 'Keyword,Volume,Keyword Difficulty,CPC,Intent,Status\n';
		rows.forEach(function (r) {
			csv += '"' + r.kw.replace(/"/g, '""') + '",' + r.vol + ',' + (r.kd == null ? '' : r.kd) + ',' + (r.cpc == null ? '' : r.cpc) + ',"' + (r.intent || '').replace(/"/g, '""') + '","' + (r.status || '') + '"\n';
		});
		var a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
		a.download = 'keywords-cat-' + kw.cat + '.csv';
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(a.href);
	});

	$('#dze-x-kw-delete').on('click', function () {
		if (!kw.rows.length || !window.confirm(i18n.confirmDel)) { return; }
		$.post(cfg.ajaxUrl, { action: 'dze_kw_clear', nonce: cfg.nonce, cat: kw.cat })
			.done(function (res) {
				if (!res.success) { return; }
				kw.rows = [];
				render();
				refreshBadge();
			});
	});

}(jQuery));
