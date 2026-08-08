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
		syncRowTpl();
	}
	// A prompt you cannot use is greyed out rather than left looking active.
	function syncRowTpl() {
		$('.dze-cb-row').each(function () {
			var $r = $(this);
			$r.find('.dze-cb-row-tpl').prop('disabled', !$r.find('.dze-cb-row-img').is(':checked'));
		});
	}
	$(document).on('change', '.dze-cb-row-img', syncRowTpl);
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
	// A task shows up as ONE symbol on its row, with the whole story in its
	// tooltip. A column of sentences turned a thirty-product run into a wall
	// of text you had to read to find the two things that went wrong.
	function pending($row, label) {
		var $s = $('<span class="dze-cb-sym is-run">•</span>').attr('title', label + ' — ' + i18n.running);
		$row.find('.dze-cb-status').append($s);
		return {
			ok: function (detail) {
				$s.removeClass('is-run').addClass('is-ok').text('✓').attr('title', label + (detail ? ' — ' + detail : ''));
			},
			ko: function (detail) {
				$s.removeClass('is-run').addClass('is-ko').text('✗').attr('title', label + (detail ? ' — ' + detail : ''));
			}
		};
	}
	// One badge per piece of content actually produced, on the product line.
	function badge(id, key, label) {
		var $wrap = $('.dze-cb-row[data-id="' + id + '"]').find('.dze-cb-badges');
		var $b = $wrap.find('[data-k="' + key + '"]');
		if (!$b.length) {
			$b = $('<span class="dze-cb-badge" data-k="' + esc(key) + '"></span>').appendTo($wrap);
		}
		$b.html(esc(label) + ' <span class="dze-cb-badgecheck">✓</span>');
	}
	// Only offered when there is really something behind it: in direct mode the
	// content went straight to the product and there is no panel to open.
	function offerReview(id) {
		$('.dze-cb-row[data-id="' + id + '"]').find('.dze-cb-toggle').show();
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
		var mark = pending($row, i18n.tText);
		return $.post(cfg.ajaxUrl, data)
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				if (review) {
					storeTexts(id, fids, res.data.texts || {});
					mark.ok(i18n.toReview);
					return;
				}
				var r = res.data.results || {}, done = [];
				fids.forEach(function (fid) {
					if (r[fid] === 'applied') {
						okCount++; done.push(cfg.fields[fid] || fid);
						badge(id, fid, cfg.fields[fid] || fid);
					} else {
						koCount++;
					}
				});
				if (done.length === fids.length) { mark.ok(done.join(', ')); }
				else { mark.ko(done.length ? sprintf(i18n.partial, done.length, fids.length) : ''); }
			})
			.catch(function (msg) {
				koCount++; mark.ko(reason(msg));
				if (window.console) { console.warn('DZE bulk', msg); }
			})
			.always(function () { progress('text'); });
	}

	// ---- Review: one collapsed panel per product ----
	// Nothing opens by itself. A run leaves badges on the lines and the panel
	// waits behind "Review ▾", so thirty products stay thirty lines.
	var results = {}; // id => { texts: {fid: html}, shots: [url] }

	function bucket(id) {
		if (!results[id]) { results[id] = { texts: {}, shots: [], built: false }; }
		return results[id];
	}
	function previewCell(id) {
		$('#dze-cb-applyall').show();
		return $('.dze-cb-preview[data-id="' + id + '"]').find('td');
	}
	function editorId(id, fid) { return 'dze-cb-ed-' + id + '-' + String(fid).replace(/[^a-zA-Z0-9_-]/g, ''); }
	function isRich(fid) { return !!(cfg.rich && cfg.rich[fid]); }

	function storeTexts(id, fids, texts) {
		var b = bucket(id);
		fids.forEach(function (fid) {
			b.texts[fid] = texts[fid] || '';
			badge(id, fid, cfg.fields[fid] || fid);
		});
		offerReview(id);
	}
	function addShot(id, url) {
		if (!url) { return; }
		var b = bucket(id);
		b.shots.push(url);
		badge(id, 'img', i18n.imgBadge + ' ×' + b.shots.length);
		offerReview(id);
		if (b.built) { renderShots(id); }
	}

	// Built the first time the panel is opened: starting a WordPress editor for
	// every product of a thirty-product run up front would freeze the page.
	function buildPanel(id) {
		var b = bucket(id), $cell = previewCell(id);
		if (b.built) { return; }
		var html = '<div class="dze-cb-prev">';
		Object.keys(b.texts).forEach(function (fid) {
			var eid = editorId(id, fid);
			html += '<div class="dze-cb-prevfield" data-field="' + fid + '" data-editor="' + eid + '">' +
				'<label>' + esc(cfg.fields[fid] || fid) +
					'<span class="dze-cb-labelright">' +
						'<span class="dze-cb-prevstate"></span>' +
						'<button type="button" class="dze-cb-redo" data-field="' + fid + '" title="' + esc(i18n.redoOne) + '">↻</button>' +
					'</span></label>' +
				(isRich(fid)
					? '<textarea id="' + eid + '" class="dze-cb-ed">' + esc(b.texts[fid]) + '</textarea>'
					: '<textarea id="' + eid + '" class="dze-cb-plain" rows="3">' + esc(b.texts[fid]) + '</textarea>') +
				'</div>';
		});
		html += '</div>' +
			'<p class="dze-cb-panelbar">' +
				(Object.keys(b.texts).length
					? '<button type="button" class="button button-small dze-cb-redoall">↻ ' + esc(i18n.redoAll) + '</button> ' : '') +
				(b.shots.length || rowWantsImage(id)
					? '<button type="button" class="button button-small dze-cb-onemore">↻ ' + esc(i18n.oneMore) + '</button> ' : '') +
				'<span class="dze-cb-panelstate"></span>' +
			'</p>' +
			'<div class="dze-cb-shots-slot"></div>';
		$cell.html(html);
		b.built = true;
		renderShots(id);

		// The rich boxes become real editors — visual tab, toolbar, code view —
		// so a description is read as a description and not as raw HTML.
		Object.keys(b.texts).forEach(function (fid) {
			if (!isRich(fid) || !window.wp || !wp.editor || !wp.editor.initialize) { return; }
			var eid = editorId(id, fid);
			try { wp.editor.remove(eid); } catch (e) {}
			wp.editor.initialize(eid, {
				tinymce: { wpautop: true, toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo', height: 200 },
				quicktags: true,
				mediaButtons: false
			});
		});
	}

	function renderShots(id) {
		var b = bucket(id), $slot = previewCell(id).find('.dze-cb-shots-slot');
		if (!$slot.length || !b.shots.length) { return; }
		// Adding one more attempt redraws the strip: what was already ticked or
		// unticked, and where they were headed, survives the redraw.
		var $old = $slot.find('.dze-cb-shots');
		var dropped = {}, target = '';
		if ($old.length) {
			target = $old.find('.dze-cb-shottarget').val() || '';
			$old.find('.dze-cb-shot').not('.is-sel').each(function () { dropped[$(this).data('url')] = true; });
		}
		var $wrap = $('<div class="dze-cb-shots"><div class="dze-cb-shotgrid"></div>' +
			'<select class="dze-cb-shottarget">' +
				'<option value="gallery">' + esc(i18n.toGallery) + '</option>' +
				'<option value="main">' + esc(i18n.toMain) + '</option>' +
			'</select> <span class="dze-cb-shotstate"></span></div>');
		b.shots.forEach(function (url) {
			$wrap.find('.dze-cb-shotgrid').append(
				$('<div class="dze-cb-shot"><span class="dze-cb-shotcheck">✓</span></div>')
					.toggleClass('is-sel', !dropped[url])
					.attr('data-url', url)
					.append($('<img class="dze-hzoom" />').attr('src', url).attr('data-full', url).attr('alt', ''))
			);
		});
		if (target) { $wrap.find('.dze-cb-shottarget').val(target); }
		$slot.empty().append($wrap);
	}

	// Read a box back whichever way it is being edited.
	function editorGet(eid) {
		if (window.tinymce && tinymce.get(eid) && !tinymce.get(eid).isHidden()) {
			return tinymce.get(eid).getContent();
		}
		return $('#' + eid).val() || '';
	}

	// ---- Regenerating, from where you are reading ----
	// A text you have just read and disliked is redone in place. An edit you
	// made yourself is never thrown away without asking.
	function rowWantsImage(id) {
		return $('.dze-cb-row[data-id="' + id + '"]').find('.dze-cb-row-img').is(':checked');
	}
	function setEditorValue(id, fid, html) {
		var eid = editorId(id, fid);
		if (window.tinymce && tinymce.get(eid) && !tinymce.get(eid).isHidden()) { tinymce.get(eid).setContent(html); }
		else { $('#' + eid).val(html); }
	}
	function edited(id, fid) {
		var b = bucket(id);
		if (!b.built) { return false; }
		return editorGet(editorId(id, fid)) !== (b.texts[fid] || '');
	}

	function regenerate(id, fids, $state) {
		var b = bucket(id);
		var keep = fids.filter(function (fid) { return edited(id, fid); });
		if (keep.length && !window.confirm(sprintf(i18n.confirmRedo, keep.length))) { return; }
		$state.removeClass('is-ko').text(i18n.working);
		return $.post(cfg.ajaxUrl, { action: 'dze_content_text_all', nonce: cfg.nonce, post: id, fields: fids })
			.done(function (res) {
				if (!res || !res.success) {
					$state.addClass('is-ko').text(reason((res && res.data && res.data.message) || i18n.error));
					return;
				}
				var texts = res.data.texts || {};
				fids.forEach(function (fid) {
					b.texts[fid] = texts[fid] || '';
					setEditorValue(id, fid, b.texts[fid]);
				});
				$state.text('✓');
				window.setTimeout(function () { $state.text(''); }, 2000);
			})
			.fail(function (x) { $state.addClass('is-ko').text(reason(x)); });
	}

	$(document).on('click', '.dze-cb-redo', function () {
		var $btn = $(this), id = $btn.closest('.dze-cb-preview').data('id');
		regenerate(id, [ $btn.data('field') ], $btn.closest('label').find('.dze-cb-prevstate'));
	});
	$(document).on('click', '.dze-cb-redoall', function () {
		var $btn = $(this), id = $btn.closest('.dze-cb-preview').data('id');
		regenerate(id, Object.keys(bucket(id).texts), $btn.closest('p').find('.dze-cb-panelstate'));
	});
	// One more attempt at the image, without re-running the whole list.
	$(document).on('click', '.dze-cb-onemore', function () {
		var $btn = $(this).prop('disabled', true);
		var id = $btn.closest('.dze-cb-preview').data('id');
		var $row = $('.dze-cb-row[data-id="' + id + '"]');
		var $state = $btn.closest('p').find('.dze-cb-panelstate').removeClass('is-ko').text(i18n.working);
		var data = { action: 'dze_content_image', nonce: cfg.nonce, post: id, mode: 'defer', template: $row.find('.dze-cb-row-tpl').val() };
		var $sc = $('#dze-cb-scene');
		if ($sc.length) { data.scene = parseInt($sc.val(), 10); }
		$.post(cfg.ajaxUrl, data)
			.done(function (res) {
				$btn.prop('disabled', false);
				if (!res || !res.success) {
					$state.addClass('is-ko').text(reason((res && res.data && res.data.message) || i18n.error));
					return;
				}
				addShot(id, res.data.url);
				$state.text('');
			})
			.fail(function (x) { $btn.prop('disabled', false); $state.addClass('is-ko').text(reason(x)); });
	});

	$(document).on('click', '.dze-cb-toggle', function () {
		var $btn = $(this), id = $btn.closest('.dze-cb-row').data('id');
		var $prev = $('.dze-cb-preview[data-id="' + id + '"]');
		var open = $prev.is(':visible');
		if (!open) { buildPanel(id); }
		$prev.toggle(!open);
		$btn.attr('aria-expanded', open ? 'false' : 'true').find('.dze-cb-caret').text(open ? '▾' : '▴');
	});

	$(document).on('click', '.dze-cb-shot', function () { $(this).toggleClass('is-sel'); });

	// Commit every reviewed text, product by product, field by field.
	$('#dze-cb-applyall').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		// Everything that was generated is applied, opened panel or not: a
		// product you did not bother to review is a product you are happy with.
		// An untouched panel simply hands back what came out of the model.
		var jobs = [], shots = [];
		Object.keys(results).forEach(function (id) {
			var b = results[id], $prev = $('.dze-cb-preview[data-id="' + id + '"]');
			Object.keys(b.texts).forEach(function (fid) {
				var eid = editorId(id, fid);
				var value = b.built ? editorGet(eid) : b.texts[fid];
				jobs.push({ id: id, fid: fid, value: value, $f: $prev.find('.dze-cb-prevfield[data-field="' + fid + '"]') });
			});
			if (b.shots.length) {
				var $w = $prev.find('.dze-cb-shots');
				// Never opened: every shot is kept. Opened: only the ticked ones.
				var urls = $w.length
					? $w.find('.dze-cb-shot.is-sel').map(function () { return $(this).data('url'); }).get()
					: b.shots.slice();
				if (urls.length) {
					shots.push({
						id: id,
						urls: urls,
						target: $w.length ? $w.find('.dze-cb-shottarget').val() : 'gallery',
						$w: $w.length ? $w : $prev
					});
				}
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
				$.post(cfg.ajaxUrl, { action: 'dze_content_apply', nonce: cfg.nonce, post: j.id, field: j.fid, value: j.value })
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
		var mark = pending($row, i18n.tPrice);
		return $.post(cfg.ajaxUrl, { action: 'dze_content_price', nonce: cfg.nonce, post: id, cost: cost })
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				okCount++;
				// A variable product reports the range and how many variations
				// were repriced — one figure would be a half-truth.
				var label = res.data.regular + (res.data.variations ? ' ×' + res.data.variations : '');
				mark.ok(label);
				badge(id, 'price', i18n.tPrice + ' ' + label);
			})
			.catch(function (msg) { koCount++; mark.ko(reason(msg)); })
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
		var mark = pending($row, i18n.tImage);
		return $.post(cfg.ajaxUrl, data)
			.then(function (res) {
				if (!res.success) { throw (res.data && res.data.message) || i18n.error; }
				okCount++;
				if (review) {
					addShot(id, res.data.url);
					mark.ok(i18n.toReview);
				} else {
					mark.ok('');
					badge(id, 'img', i18n.imgBadge);
					// Better image visibility: refresh the row thumbnail.
					if (res.data.url) {
						$row.find('.dze-cb-thumb img').attr('src', res.data.url).attr('data-full', res.data.url);
					}
				}
			})
			.catch(function (msg) { koCount++; mark.ko(reason(msg)); })
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
		$('.dze-cb-badges').empty();
		$('.dze-cb-toggle').hide().attr('aria-expanded', 'false').find('.dze-cb-caret').text('▾');
		$('#dze-cb-applyall').hide();
		results = {};
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
