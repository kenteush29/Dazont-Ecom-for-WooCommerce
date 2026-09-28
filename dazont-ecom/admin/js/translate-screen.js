/**
 * Dazont Ecom → WPML Translations.
 *
 * « Je veux que ce soit une copie du dashboard WPML. » So it is WPML's
 * Translation Dashboard, pressed the way WPML's is:
 *
 *   1. SELECT ITEMS FOR TRANSLATION — a tick survives paging, searching and
 *      reloading; « Select All » takes every item the filters match, in every
 *      section. Each section pages, searches and sorts on its own.
 *   2. TRANSLATE YOUR CONTENT — appears as soon as something is ticked: per
 *      language, the words it will send and what that costs, what to do with
 *      what is already translated, and what to do when it comes back.
 *   3. IN THE BACKGROUND — one press deposits the lot. The languages on their
 *      way turn on their rows, and the page asks every few seconds where they
 *      stand, so each one lands without a reload. While a wheel turns and no
 *      pass is running, the page starts one: WordPress's scheduler only wakes
 *      when somebody visits the site.
 *
 * Then the editor of one object and the To review list, unchanged.
 *
 * Every word on this screen comes from PHP: hard-coded here they were English
 * on every shop.
 */
(function ($) {
	'use strict';
	var cfg = window.dzeTrScreen || {};
	var i18n = cfg.i18n || {};

	function esc(s) {
		return $('<div/>').text(s == null ? '' : String(s)).html();
	}
	// A MISSING WORD IS NOT WORTH KILLING A HANDLER FOR: an unregistered
	// string used to throw on .replace and stop every line after it.
	function sprintf(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str == null ? '' : str).replace(/%(\d+)\$s|%s/g, function (m, n) {
			return n ? String(args[parseInt(n, 10) - 1]) : String(args[i++]);
		});
	}
	function post(action, data) {
		return $.post(cfg.ajaxUrl, $.extend({ action: action, nonce: cfg.nonce }, data || {}));
	}
	function said(r) {
		return (r && r.data && r.data.message) ? r.data.message : i18n.error;
	}
	function num(n) {
		var v = Number(n) || 0;
		try { return v.toLocaleString(); } catch (e) { return String(v); }
	}
	function money(v) {
		v = Number(v) || 0;
		if (v > 0 && v < 0.01) { return i18n.moneyTiny; }
		return sprintf(i18n.money, v.toFixed(2));
	}

	// =====================================================================
	// The dashboard
	// =====================================================================

	var $dash = $();

	// ---- What is ticked, kept across pages, sections and reloads ----------
	//
	// « Changer de page annule la sélection. Sur WPML ça change de page en
	// ajax, la sélection reste active. » It is kept by ref, in the TAB's own
	// storage — gone when the tab closes, because a selection forgotten since
	// yesterday is a sending nobody wanted any more.
	var STORE = 'dze-trd-picked';
	var picked = {};
	var pickedN = 0;
	function loadPicked() {
		picked = {};
		try {
			var raw = window.sessionStorage.getItem(STORE);
			var got = raw ? JSON.parse(raw) : {};
			if (got && typeof got === 'object') { picked = got; }
		} catch (e) { picked = {}; }
		pickedN = Object.keys(picked).length;
	}
	function savePicked() {
		pickedN = Object.keys(picked).length;
		try { window.sessionStorage.setItem(STORE, JSON.stringify(picked)); } catch (e) { /* private window: never mind */ }
	}
	function refs() { return Object.keys(picked); }
	function rowsOf(ref) { return $dash.find('.dze-trd-row[data-ref="' + String(ref) + '"]'); }
	function syncBoxes($in) {
		($in || $dash).find('.dze-trd-pick').each(function () { this.checked = !!picked[this.value]; });
		$dash.find('.dze-trd-sec').each(function () {
			var $b = $(this).find('.dze-trd-pick');
			$(this).find('.dze-trd-pickpage').prop('checked', $b.length > 0 && $b.filter(':checked').length === $b.length);
		});
	}
	function changed() {
		savePicked();
		syncBoxes();
		showBar();
		scheduleCount();
	}
	function showBar() {
		var n = pickedN;
		$('#dze-trd-selbar').prop('hidden', n === 0);
		$('#dze-trd-selcount').text(n === 1 ? i18n.oneSelected : sprintf(i18n.nSelected, num(n)));
		// STEP 2 APPEARS ONCE SOMETHING IS TICKED, as it does in WPML.
		$('#dze-trd-step2').prop('hidden', n === 0);
	}

	$(document).on('change', '.dze-trd-pick', function () {
		if (this.checked) { picked[this.value] = true; } else { delete picked[this.value]; }
		changed();
	});
	$(document).on('change', '.dze-trd-pickpage', function () {
		var on = this.checked;
		$(this).closest('.dze-trd-sec').find('.dze-trd-pick').each(function () {
			this.checked = on;
			if (on) { picked[this.value] = true; } else { delete picked[this.value]; }
		});
		changed();
	});
	// UN GESTE QUI NE DIT RIEN EST UN GESTE QUI N'A PAS MARCHÉ : the bar
	// disappears with the selection, which is the answer.
	$(document).on('click', '#dze-trd-clearsel', function () {
		picked = {};
		changed();
	});
	$(document).on('click', '#dze-trd-goto', function () {
		var el = document.getElementById('dze-trd-step2');
		if (el && el.scrollIntoView) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	});

	// ---- One section: its page, its size, its filters, its order ----------
	function sectionArgs($s) {
		var f = cfg.filters || {};
		return {
			scope: String($s.data('scope') || ''),
			paged: parseInt($s.data('paged'), 10) || 1,
			per: parseInt($s.data('per'), 10) || 10,
			search: String($s.find('.dze-trd-search').val() || ''),
			term: parseInt($s.find('.dze-trd-term').val(), 10) || 0,
			orderby: String($s.data('orderby') || 'title'),
			order: String($s.data('order') || 'asc'),
			flang: f.flang || '',
			pstatus: f.pstatus || '',
			tstatus: f.tstatus || 'todo'
		};
	}
	function loadSection($s) {
		$s.addClass('is-loading');
		return post('dze_tr_items', sectionArgs($s)).done(function (r) {
			if (!r || !r.success) { return; }
			$s.find('tbody').first().html(r.data.rows || '');
			$s.find('.tablenav-pages').first().html(r.data.pager || '');
			$s.find('.dze-trd-seccount').text('(' + (r.data.label || '0') + ')');
			syncBoxes($s);
			watch();
		}).always(function () { $s.removeClass('is-loading'); });
	}
	var searchTimer = null;
	$(document).on('input', '.dze-trd-search', function () {
		var $s = $(this).closest('.dze-trd-sec');
		window.clearTimeout(searchTimer);
		searchTimer = window.setTimeout(function () { $s.data('paged', 1); loadSection($s); }, 400);
	});
	$(document).on('keydown', '.dze-trd-search', function (e) {
		if (e.key !== 'Enter') { return; }
		e.preventDefault();
		window.clearTimeout(searchTimer);
		var $s = $(this).closest('.dze-trd-sec');
		$s.data('paged', 1);
		loadSection($s);
	});
	$(document).on('change', '.dze-trd-term', function () {
		var $s = $(this).closest('.dze-trd-sec');
		$s.data('paged', 1);
		loadSection($s);
	});
	$(document).on('change', '.dze-trd-per', function () {
		var $s = $(this).closest('.dze-trd-sec');
		$s.data('per', parseInt($(this).val(), 10) || 10);
		$s.data('paged', 1);
		loadSection($s);
	});
	$(document).on('click', '.dze-trd-page', function () {
		var $s = $(this).closest('.dze-trd-sec');
		$s.data('paged', parseInt($(this).data('page'), 10) || 1);
		loadSection($s);
	});
	$(document).on('click', '.dze-trd-sort', function () {
		var $s = $(this).closest('.dze-trd-sec');
		var by = String($(this).data('orderby'));
		var order = (String($s.data('orderby')) === by && String($s.data('order')) === 'asc') ? 'desc' : 'asc';
		$s.data('orderby', by);
		$s.data('order', order);
		$s.data('paged', 1);
		$s.find('.dze-trd-sort').removeClass('is-asc is-desc');
		$(this).addClass('is-' + order);
		loadSection($s);
	});

	// ---- Select All: every item the filters match, in every section -------
	$(document).on('click', '#dze-trd-selectall', function () {
		var $b = $(this), was = $b.text(), max = parseInt(cfg.pickMax, 10) || 2000, capped = false;
		var secs = $dash.find('.dze-trd-sec').toArray();
		$b.prop('disabled', true).text(i18n.selecting);
		(function next() {
			if (!secs.length) {
				$b.prop('disabled', false).text(was);
				changed();
				if (capped) { window.alert(sprintf(i18n.capped, num(max))); }
				return;
			}
			var $s = $(secs.shift());
			post('dze_tr_items', $.extend(sectionArgs($s), { refs: 1 })).done(function (r) {
				var list = (r && r.success && r.data && r.data.refs) || [];
				for (var i = 0; i < list.length; i++) {
					if (!picked[list[i]]) {
						if (pickedN >= max) { capped = true; break; }
						picked[list[i]] = true;
						pickedN++;
					}
				}
			}).always(next);
		}());
	});

	// ---- Step 2: the words, the cost, the choices -------------------------
	//
	// Read from the server a slice at a time and kept per item: ticking one
	// more row counts that row, not the whole selection again.
	var words = {};
	var asking = {};
	var countTimer = null;
	var sending = false;
	function scheduleCount() {
		render();
		window.clearTimeout(countTimer);
		countTimer = window.setTimeout(countWords, 250);
	}
	function countWords() {
		var need = refs().filter(function (r) { return !words[r] && !asking[r]; });
		if (!need.length) { render(); return; }
		var slices = [];
		for (var i = 0; i < need.length; i += 50) { slices.push(need.slice(i, i + 50)); }
		need.forEach(function (r) { asking[r] = true; });
		(function next() {
			if (!slices.length) { render(); return; }
			var part = slices.shift();
			post('dze_tr_words', { refs: part }).done(function (r) {
				var items = (r && r.success && r.data && r.data.items) || {};
				// AN ITEM THE SERVER DID NOT ANSWER FOR is not something this
				// site translates any more: it counts for nothing.
				part.forEach(function (ref) { words[ref] = items[ref] || {}; });
			}).always(function () {
				part.forEach(function (ref) { delete asking[ref]; });
				render();
				next();
			});
		}());
	}
	function methods() {
		var m = {};
		$('.dze-trd-method').each(function () { m[String($(this).data('lang'))] = String($(this).val()); });
		return m;
	}
	function overwrite() {
		return $('input[name="dze-trd-existing"]:checked').val() === 'overwrite';
	}
	function render() {
		if (!$dash.length) { return; }
		var list = refs(), m = methods(), over = overwrite();
		var loading = false, anyDone = false, auto = 0, work = 0, free = 0, total = 0, bits = [];
		list.forEach(function (ref) { if (!words[ref]) { loading = true; } });
		(cfg.langs || []).forEach(function (code) {
			var w = 0, c = 0, noise = 0, on = m[code] === 'auto';
			list.forEach(function (ref) {
				var x = words[ref] && words[ref][code];
				if (!x) { return; }
				if (x.s === 'done' || x.s === 'noise') { if (on) { anyDone = true; } }
				if (x.s === 'noise' && !over) { noise++; }
				w += over ? (x.a || 0) : (x.o || 0);
				c += over ? (x.ca || 0) : (x.co || 0);
			});
			var $row = $('.dze-trd-pairs tr[data-lang="' + code + '"]');
			$row.toggleClass('is-off', !on);
			$row.find('.dze-trd-words').html(loading ? '<span class="dze-trd-spin" aria-hidden="true"></span>' : esc(num(w)));
			$row.find('.dze-trd-cost').text(loading ? '' : (on ? money(c) : '–'));
			if (!on) { return; }
			auto++;
			if (w > 0) {
				work++;
				total += c;
				bits.push(sprintf(i18n.sumLang, num(w), (cfg.names || {})[code] || code));
			}
			if (noise) { work++; free += noise; }
		});
		// « Some of the content you want to translate is already translated »
		// — shown only when it is true, as in WPML.
		$('#dze-trd-existing').prop('hidden', !anyDone);
		var ok = false, text = '';
		if (!list.length) {
			text = '';
		} else if (loading) {
			text = i18n.counting;
		} else if (!auto) {
			text = i18n.pickLang;
		} else if (!work) {
			text = i18n.nothingOwed;
		} else {
			if (free) { bits.push(sprintf(i18n.sumFree, num(free))); }
			text = bits.join(' · ') + (total > 0 ? ' — ' + sprintf(i18n.sumCost, money(total)) : '');
			ok = true;
		}
		$('#dze-trd-sum').text(text);
		$('#dze-trd-send').prop('disabled', !ok || sending);
		$('#dze-trd-reviewsaid').text($('#dze-trd-review').val() === 'publish' ? i18n.publishSaid : i18n.reviewSaid);
	}

	// THE CHOICES ARE REMEMBERED, AS WPML REMEMBERS THEM — in this browser.
	// Nothing is set to translate the first time: a default that spends must
	// be a choice, never an oversight. « Je l'ai envoyé seulement en RU » —
	// five languages were ticked by default, and the shop paid five times.
	var METHODS = 'dze-trd-methods', REVIEW = 'dze-trd-review';
	function restoreChoices() {
		var kept = {};
		try { kept = JSON.parse(window.localStorage.getItem(METHODS) || '{}') || {}; } catch (e) { kept = {}; }
		var only = (cfg.filters && cfg.filters.flang) || '';
		$('.dze-trd-method').each(function () {
			var code = String($(this).data('lang'));
			// FILTERED ON ONE LANGUAGE, the screen was asked about that one.
			$(this).val(only ? (code === only ? 'auto' : 'none') : (kept[code] === 'auto' ? 'auto' : 'none'));
		});
		var rv = '';
		try { rv = window.localStorage.getItem(REVIEW) || ''; } catch (e) { rv = ''; }
		$('#dze-trd-review').val(rv === 'publish' ? 'publish' : 'review');
	}
	function keepMethods() {
		try { window.localStorage.setItem(METHODS, JSON.stringify(methods())); } catch (e) { /* never mind */ }
	}
	$(document).on('change', '.dze-trd-method', function () { keepMethods(); render(); });
	$(document).on('change', '#dze-trd-setall', function () {
		var v = String($(this).val() || '');
		if (v) { $('.dze-trd-method').val(v); keepMethods(); }
		$(this).val('');
		render();
	});
	$(document).on('change', 'input[name="dze-trd-existing"]', render);
	$(document).on('change', '#dze-trd-review', function () {
		try { window.localStorage.setItem(REVIEW, String($(this).val())); } catch (e) { /* never mind */ }
		render();
	});

	// ---- « Translate content » ---------------------------------------------
	$(document).on('click', '#dze-trd-send', function () {
		var list = refs(), m = methods();
		var langs = (cfg.langs || []).filter(function (c) { return m[c] === 'auto'; });
		if (!list.length || !langs.length || sending) { return; }
		sending = true;
		render();
		var $st = $('#dze-trd-sendsaid').removeClass('is-ko').text(i18n.sending);
		post('dze_tr_queue', {
			refs: list,
			langs: langs,
			accept: $('#dze-trd-review').val() === 'publish' ? 1 : 0,
			all: overwrite() ? 1 : 0
		}).done(function (r) {
			sending = false;
			if (!r || !r.success) { $st.addClass('is-ko').text(said(r)); render(); return; }
			// SENT, SO FORGOTTEN: keeping the selection would send the same
			// rows again on the next press.
			picked = {};
			words = {};
			changed();
			$st.text('');
			$('#dze-trd-sent').remove();
			var $n = $('<div class="notice notice-success inline" id="dze-trd-sent"><p></p></div>');
			$n.find('p').text(r.data.message || '');
			$n.insertBefore('#dze-trd-progress');
			// THE ROWS SAY IT AT ONCE: their languages start turning without
			// a reload — « vu, mais seulement après rafraîchissement ».
			refresh(Object.keys(r.data.sent || {}));
			queueSaid(r.data.queue);
			var top = document.getElementById('dze-trd-sent');
			if (top && top.scrollIntoView) { top.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
		}).fail(function () {
			sending = false;
			$st.addClass('is-ko').text(i18n.error);
			render();
		});
	});

	// ---- In the background: the wheels, and where they stand -------------
	var polling = null, kicking = false, queue = null;
	function turning() {
		var out = [];
		$dash.find('.dze-trd-row').each(function () {
			if ($(this).find('.dze-trd-spin').length) { out.push(String($(this).data('ref'))); }
		});
		return out;
	}
	function busy() { return turning().length > 0 || !!(queue && queue.n); }
	function watch() {
		if (polling || !busy()) { return; }
		polling = window.setTimeout(tick, parseInt(cfg.poll, 10) || 8000);
	}
	function land(cells) {
		var again = false;
		$.each(cells || {}, function (ref, html) {
			rowsOf(ref).find('.dze-trd-langs').html(html);
			// ITS STATE CHANGED: if it is ticked, its words are counted again.
			if (words[ref]) { delete words[ref]; again = again || !!picked[ref]; }
		});
		if (again) { scheduleCount(); }
	}
	function refresh(list) {
		var visible = (list || []).filter(function (ref) { return rowsOf(ref).length > 0; });
		return post('dze_tr_status', { refs: visible }).done(function (r) {
			if (!r || !r.success) { return; }
			land(r.data.cells);
			queueSaid(r.data.queue);
		}).always(watch);
	}
	function tick() {
		polling = null;
		refresh(turning());
	}
	function queueSaid(q) {
		if (!q) { return; }
		queue = q;
		$('#dze-trd-progress').prop('hidden', !q.n);
		$('#dze-trd-progn').text(q.n === 1 ? i18n.oneProgress : sprintf(i18n.nProgress, num(q.n)));
		$('#dze-trd-failed').prop('hidden', !q.errors);
		if (q.errors) { $('#dze-trd-failedsaid').text(sprintf(i18n.failed, num(q.errors), q.last || '')); }
		// A PASS THAT WAITS FOR NOBODY. Work is waiting and nothing runs: the
		// page starts one. The lock on the server makes a second one harmless.
		if (q.n && !q.busy && !kicking) {
			kicking = true;
			post('dze_tr_runqueue', {}).always(function () { kicking = false; });
		}
	}
	$(document).on('click', '.dze-trd-cancel', function () {
		var $b = $(this), $row = $b.closest('.dze-trd-row');
		var ref = String($row.data('ref')), lang = String($b.data('lang'));
		if (!window.confirm(i18n.cancelAsk)) { return; }
		$b.prop('disabled', true);
		post('dze_tr_cancel', { ref: ref, lang: lang }).done(function (r) {
			if (!r || !r.success) { $b.prop('disabled', false); window.alert(said(r)); return; }
			var cells = {};
			cells[ref] = r.data.cell || '';
			land(cells);
			queueSaid(r.data.queue);
		}).fail(function () {
			$b.prop('disabled', false);
			window.alert(i18n.error);
		});
	});
	$(document).on('click', '#dze-trd-cancelall', function () {
		if (!window.confirm(i18n.cancelAllAsk)) { return; }
		var $b = $(this).prop('disabled', true);
		post('dze_tr_emptyqueue', {}).always(function () {
			$b.prop('disabled', false);
			refresh(turning());
		});
	});

	$(function () {
		$dash = $('#dze-trd');
		if (!$dash.length) { return; }
		loadPicked();
		// PICKED ON A WORDPRESS LIST: that selection replaces this one, and
		// Step 2 is where the page opens.
		var handed = (cfg.picked || []).length > 0;
		if (handed) {
			picked = {};
			cfg.picked.forEach(function (ref) { picked[String(ref)] = true; });
			savePicked();
		}
		restoreChoices();
		syncBoxes();
		showBar();
		scheduleCount();
		if (handed) {
			var el = document.getElementById('dze-trd-step2');
			if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'start' }); }
		}
		// SOMETHING IS ON ITS WAY: ask where it stands straight away.
		if (turning().length || !$('#dze-trd-progress').prop('hidden')) {
			polling = window.setTimeout(tick, 1200);
		}
	});

	// =====================================================================
	// The editor of one object
	// =====================================================================

	// L ECRAN D UN OBJET, quand on en regarde un. Vide ailleurs.
	//
	// Elle avait disparu en reecrivant le bouton d envoi : quatre
	// gestionnaires l appelaient encore, dont « Discard ». Un appel a une
	// fonction qui n existe pas tue le gestionnaire AVANT sa premiere ligne.
	function editor() { return $('.dze-tr-editor'); }

	$(document).on('click', '#dze-tr-auto, #dze-tr-auto-all', function () {
		var $e = editor();
		if (!$e.length) { return; }
		var all = this.id === 'dze-tr-auto-all';
		// EVERY FIELD IS PAID FOR, so it is asked before it is spent.
		if (all && !window.confirm(i18n.confirmAll)) { return; }
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-tr-autostate').removeClass('is-ko').text(i18n.translating);
		post('dze_tr_batch', { ref: $e.data('ref'), langs: [String($e.data('lang'))], all: all ? 1 : 0 })
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text(said(r)); return; }
				var texts = (r.data.texts || {})[String($e.data('lang'))] || {};
				var n = 0;
				$.each(texts, function (fid, text) {
					var $row = $e.find('.dze-tr-field[data-field="' + fid + '"]');
					if (!$row.length) { return; }
					$row.find('.dze-tr-new').val(text);
					n++;
				});
				// NOTHING MOVED IS AN ANSWER, and it is not a failure: WPML asks
				// again whenever a category is renamed, and this is the module
				// saying it cost nothing.
				$st.text(n ? sprintf(i18n.filled, n) : i18n.nothingNewOne);
			})
			.fail(function () { $b.prop('disabled', false); $st.addClass('is-ko').text(i18n.error); });
	});

	// 2. SAVE IT. What is on screen is what travels — never what the database
	// held when the page was opened.
	$(document).on('click', '#dze-tr-publish', function () {
		var $e = editor();
		if (!$e.length) { return; }
		var lang = String($e.data('lang'));
		var keep = {};
		keep[lang] = {};
		var any = false;
		$e.find('.dze-tr-field').each(function () {
			var $r = $(this);
			var v = String($r.find('.dze-tr-new').val() || '');
			if (!v.length) { return; }
			keep[lang][String($r.data('field'))] = v;
			any = true;
		});
		var $st = $('#dze-tr-publishstate').removeClass('is-ko');
		if (!any) { $st.addClass('is-ko').text(i18n.nothingToSave); return; }
		var $b = $(this).prop('disabled', true);
		$st.text(i18n.saving);
		post('dze_tr_decide', { ref: $e.data('ref'), how: 'accept', keep: keep })
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text(said(r)); return; }
				// WHAT WAS WRITTEN, AND WHAT IS STILL WRONG WITH IT. A variable
				// product whose variations WooCommerce Multilingual has not
				// built renders as unavailable, and the screen that just said
				// "Saved" must say that too.
				var warn = [];
				$.each(r.data.warnings || {}, function (code, text) { warn.push(text); });
				$e.find('.dze-tr-warn').remove();
				if (warn.length) {
					$e.find('.dze-cb-panelbar').before(
						'<div class="notice notice-warning inline dze-tr-warn"><p>' + esc(warn.join(' ')) + '</p></div>');
				}
				// THE CHIP FOLLOWS THE SAVE. It read "not translated" until the
				// page was reloaded — a screen disagreeing with the work it had
				// just done. Same class, same icon, same word as the lists.
				var $chip = $('.dze-tr-editstate > .dze-tr-chip').first();
				if ($chip.length) {
					$chip.attr('class', 'dze-tr-chip is-done');
					$chip.find('.dashicons').attr('class', 'dashicons ' + (cfg.doneIcon || 'dashicons-edit'));
					var last = $chip.contents().last()[0];
					if (last && 3 === last.nodeType) { last.nodeValue = ' ' + (i18n.stateDone || ''); }
				}
				$e.find('.dze-tr-moved').remove();
				// What is on screen is now what the translation holds.
				$e.find('.dze-tr-new').each(function () { $(this).attr('data-was', $(this).val()); });
				// THE STATE LINE IS THE LAST THING TO CHANGE, so it is a
				// truthful signal that the press is finished: set first, a gate
				// waiting on it reads a screen still working — and passes by
				// accident of timing, which is what a vacuous wait looks like.
				$st.text(i18n.saved);
			})
			.fail(function () { $b.prop('disabled', false); $st.addClass('is-ko').text(i18n.error); });
	});

	// 3. OR THROW IT AWAY. The translation is left exactly as it is.
	$(document).on('click', '#dze-tr-drop, .dze-tr-refuse', function () {
		var $e = editor();
		var ref = $e.length ? $e.data('ref') : $(this).closest('tr[data-ref]').data('ref');
		if (!ref || !window.confirm(i18n.confirmNo)) { return; }
		var $st = $('#dze-tr-publishstate');
		post('dze_tr_decide', { ref: ref, how: 'refuse' })
			.done(function (r) {
				if (!r || !r.success) { window.alert(said(r)); return; }
				if ($e.length) {
					// "Thrown away" over fields still holding the thrown-away
					// text is a screen that lies: what the translation holds
					// today comes back into every field.
					$e.find('.dze-tr-new').each(function () { $(this).val(String($(this).attr('data-was') || '')); });
					$st.text(i18n.dropped);
					return;
				}
				$('tr[data-ref="' + ref + '"]').remove();
			});
	});

	// COPY FROM THE ORIGINAL — WPML puts this button between the two boxes and
	// it earns its place: a product code, a size table, a line that is the same
	// in every language is copied rather than retyped. It never overwrites
	// silently: a box already holding words asks first.
	$(document).on("click", ".dze-tr-copy", function () {
		var $field = $(this).closest(".dze-tr-field"),
			$box   = $field.find(".dze-tr-new"),
			src    = String($box.attr("data-src") || "");
		if (!$box.length || !src) { return; }
		if (String($box.val() || "").trim() !== "" && !window.confirm(i18n.overwrite || "Replace what is in the box?")) { return; }
		$box.val(src).trigger("change").focus();
	});

	// ONE BLOCK ON ITS OWN — « pour un calibrage plus facile il faut un bouton
	// traduire par bloc ». Judging a change to the prompt meant
	// re-sending the whole object and paying for every field of it, so it was
	// done once and never again. This sends this block and nothing else, and it
	// ALWAYS sends it: a field is re-run precisely when it has not moved, so
	// "nothing has changed" is the wrong answer here.
	$(document).on('click', '.dze-tr-block', function () {
		var $e = editor();
		if (!$e.length) { return; }
		var $row = $(this).closest('.dze-tr-field');
		var fid = String($row.data('field') || '');
		if (!fid) { return; }
		var $b = $(this).prop('disabled', true);
		var $st = $row.find('.dze-tr-blockstate').removeClass('is-ko').text(i18n.oneSending || '');
		post('dze_tr_batch', {
			ref: $e.data('ref'),
			langs: [String($e.data('lang'))],
			field: fid
		})
			.done(function (r) {
				$b.prop('disabled', false);
				if (!r || !r.success) { $st.addClass('is-ko').text(said(r)); return; }
				var texts = (r.data.texts || {})[String($e.data('lang'))] || {};
				if (!Object.prototype.hasOwnProperty.call(texts, fid)) {
					$st.addClass('is-ko').text(i18n.oneNothing || '');
					return;
				}
				// THE BOX IS FILLED, NOT THE PAGE. Nothing else on screen moves,
				// so what came back for this block can be judged against what was
				// already beside it.
				$row.find('.dze-tr-new').val(texts[fid]);
				$st.text(i18n.oneDone || '');
			})
			.fail(function () { $b.prop('disabled', false); $st.addClass('is-ko').text(i18n.error); });
	});

	// TOUT ACCEPTER — « c'est ce que j'aurais fait ici : tout accepter ».
	// Rien n'est retraduit : le serveur ecrit les textes deja revenus.
	function pickedRefs() {
		return $('.dze-tr-wrow').filter(function () {
			return $(this).find('.dze-tr-wpick').is(':checked');
		}).map(function () { return String($(this).data('ref')); }).get();
	}
	// LE NOMBRE EST SUR LE BOUTON. « Accept (x) ou Discard (x). Voila ce qu il
	// doit y avoir, rien de plus. » Un bouton qui annonce le total de la liste
	// quand on en a coche deux ment sur ce qu il va emporter.
	function syncBulk() {
		var n = pickedRefs().length;
		$('#dze-tr-acceptsel').prop('disabled', !n).text(sprintf(i18n.acceptN, n));
		$('#dze-tr-dropsel').prop('disabled', !n).text(sprintf(i18n.discardN, n));
	}
	$(document).on('change', '.dze-tr-wpick, #dze-tr-wall', function () {
		if (this.id === 'dze-tr-wall') {
			$('.dze-tr-wpick').prop('checked', $(this).is(':checked'));
		}
		syncBulk();
	});
	$(syncBulk);
	function acceptMany(refs) {
		// LA QUESTION NOMME LE NOMBRE, comme celle du refus : elle ne porte plus
		// sur  tout ce qui attend  mais sur ce qui est coche.
		if (!refs.length) { return; }
		if (!window.confirm(sprintf(i18n.acceptAsk, refs.length))) { return; }
		var $b = $('#dze-tr-acceptsel, #dze-tr-dropsel').prop('disabled', true);
		var $st = $('#dze-tr-allstate').removeClass('is-ko').text(i18n.allSending);
		post('dze_tr_accept_all', refs && refs.length ? { refs: refs } : {})
			.done(function (r) {
				if (!r || !r.success) { $b.prop('disabled', false); $st.addClass('is-ko').text(said(r)); return; }
				var d = r.data || {};
				if (!d.objects) { $b.prop('disabled', false); $st.text(i18n.allNone); return; }
				$st.text(sprintf(i18n.allDone, d.objects, d.fields));
				// CE QUI A ETE ECRIT QUITTE LA LISTE. Recharger est le seul moyen
				// honnete de la redessiner : les compteurs, les pastilles et les
				// onglets se lisent tous a l ouverture de la page.
				window.setTimeout(function () { window.location.reload(); }, 900);
			})
			.fail(function () { $b.prop('disabled', false); $st.addClass('is-ko').text(i18n.error); });
	}
	$(document).on('click', '#dze-tr-acceptsel', function () { acceptMany(pickedRefs()); });

	// REFUSER EN GROUPE. « Il manque le bouton Discard. » Accepter sept lignes
	// coutait une presse et en refuser sept en coutait sept : une liste dont
	// seul l accord est groupe pousse a tout accepter.
	//
	// UNE LIGNE APRES L AUTRE, jamais toutes ensemble : chacune est une
	// ecriture, et sept ecritures lancees de front sur un hebergement mutualise
	// sont sept chances d en voir aboutir la moitie. C est plus lent et c est
	// le seul moyen de pouvoir dire combien sont parties.
	function refuseMany(refs) {
		if (!refs.length) { return; }
		if (!window.confirm(sprintf(i18n.dropAsk, refs.length))) { return; }
		var $b = $('#dze-tr-acceptsel, #dze-tr-dropsel').prop('disabled', true);
		var $st = $('#dze-tr-allstate').removeClass('is-ko').text(i18n.dropSending);
		var left = refs.slice(), done = 0;
		(function next() {
			if (!left.length) {
				$st.text(sprintf(i18n.dropDone, done));
				// Recharger : les compteurs, les pastilles et les onglets se lisent
				// tous a l ouverture de la page.
				window.setTimeout(function () { window.location.reload(); }, 900);
				return;
			}
			var ref = left.shift();
			post('dze_tr_decide', { ref: ref, how: 'refuse' })
				.done(function (r) {
					if (r && r.success) { done++; $('tr[data-ref="' + ref + '"]').remove(); }
				})
				.always(function () { next(); });
		}());
	}
	$(document).on('click', '#dze-tr-dropsel', function () { refuseMany(pickedRefs()); });

	// LIRE UNE LIGNE SANS QUITTER LA LISTE. Une seule ligne ouverte a la fois :
	// huit tableaux deplies l un sous l autre, c'est la page qu'on fuyait.
	$(document).on('click', '.dze-tr-peek', function () {
		var $btn = $(this), $row = $btn.closest('.dze-tr-wrow');
		var $open = $row.next('.dze-tr-peekrow');
		if ($open.length) { $open.remove(); $btn.text(i18n.peek); return; }
		$('.dze-tr-peekrow').remove();
		$('.dze-tr-peek').text(i18n.peek);
		var cols = $row.children().length;
		var $cell = $('<tr class="dze-tr-peekrow"><td colspan="' + cols + '"></td></tr>');
		$cell.find('td').text(i18n.peekLoad);
		$row.after($cell);
		$btn.text(i18n.peekHide);
		post('dze_tr_peek', { ref: String($row.data('ref')) })
			.done(function (r) {
				if (!r || !r.success) { $cell.find('td').text(said(r)); return; }
				$cell.find('td').html((r.data && r.data.html) || '');
			})
			.fail(function () { $cell.find('td').text(i18n.error); });
	});
}(jQuery));
