/**
 * Dazont Ecom → WPML Translations: the batch, and the reading before it lands.
 *
 * Two presses and nothing else. "Translate the ticked" walks the list ONE
 * OBJECT AT A TIME — a run of forty products in one request either works or
 * times out, and says nothing while it decides which — so the bar can say
 * where it is. "Review" opens the object where it stands, beside what came
 * back and beside what the translation holds today; accept writes the ticked
 * fields and refuse throws the lot away.
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

	// =====================================================================
	// The batch
	// =====================================================================

	// LA SÉLECTION SURVIT AU CHANGEMENT DE PAGE.
	//
	// « Changer de page annule la sélection. Sur WPML ça change de page en
	// ajax, la sélection reste active. »
	//
	// On garde les lignes cochées par leur référence, dans le stockage de
	// l'onglet : la page se recharge, les cases se retrouvent, et une
	// sélection commencée page une part toujours quand on l'envoie depuis la
	// page trois. Le stockage est celui de l'ONGLET — il disparaît quand on le
	// ferme — parce qu'une sélection oubliée depuis hier est un envoi qu'on ne
	// voulait plus.
	var KEPT = 'dze-tr-picked:' + (cfg.scope || '');
	function readKept() {
		try {
			var raw = window.sessionStorage.getItem(KEPT);
			return raw ? JSON.parse(raw) : {};
		} catch (e) { return {}; }
	}
	function writeKept(map) {
		try { window.sessionStorage.setItem(KEPT, JSON.stringify(map)); } catch (e) { /* privé : tant pis */ }
	}
	function remember() {
		var map = readKept();
		$('.dze-tr-row').each(function () {
			var ref = String($(this).data('ref'));
			if ($(this).find('.dze-tr-pickone').prop('checked')) { map[ref] = 1; }
			else { delete map[ref]; }
		});
		writeKept(map);
	}
	function restore() {
		var map = readKept(), n = 0;
		$('.dze-tr-row').each(function () {
			if (map[String($(this).data('ref'))]) {
				$(this).find('.dze-tr-pickone').prop('checked', true);
				n++;
			}
		});
		return n;
	}
	$(document).on('click', '#dze-tr-clearkept', function () {
		// UN GESTE QUI NE DIT RIEN EST UN GESTE QUI N'A PAS MARCHÉ.
		//
		// « Le nettoyage ne fonctionne pas. Clear the whole selection. 0
		// réaction. » Il décochait pourtant bien — mais après un envoi les
		// cases sont DÉJÀ vides, donc rien ne bougeait à l'écran. Et le mot
		// « selection » se lisait comme « la file », qu'il ne touche pas.
		var n = Object.keys(readKept()).length;
		writeKept({});
		$('.dze-tr-pickone, #dze-tr-all').prop('checked', false);
		remember();
		bill();
		$('#dze-tr-sendstate').text(sprintf(i18n.cleared, n));
	});

	$(document).on('change', '#dze-tr-all', function () {
		$('.dze-tr-pickone').prop('checked', this.checked);
		remember();
		bill();
	});
	$(document).on('click', '#dze-tr-selall', function () {
		$('.dze-tr-pickone, #dze-tr-all').prop('checked', true);
		remember();
		bill();
	});
	// #dze-tr-selnone a disparu de l'écran : deux boutons pour décocher, c'était
	// un de trop. Celui qui reste est #dze-tr-clearkept, plus haut, et il vide
	// la sélection de TOUTES les pages — qui appuie sur « tout décocher » veut
	// tout décocher.
	$(document).on('change', '.dze-tr-pickone, .dze-tr-lang', function () { remember(); bill(); });

	// WHAT THE PRESS IS ABOUT TO DO, BESIDE THE PRESS. Every figure was already
	// on the screen — the ticked rows, the ticked languages — and they had
	// never been multiplied: a button reading "Translate" over forty rows and
	// five languages is two hundred calls nobody counted.
	function bill() {
		// COMBIEN EN TOUT, pas combien sur cette page : la selection franchit
		// les pages, et un chiffre qui ne compte que ce qu on voit ferait
		// partir plus de lignes qu annonce.
		var rows = Object.keys(readKept()).length;
		var langs = $('.dze-tr-lang:checked').length;
		var $b = $('#dze-tr-bill');
		if (!$b.length) { return; }
		$('#dze-tr-selcount').text(sprintf(i18n.nSelected, rows));
		// CE QUI SERA RÉELLEMENT TRADUIT, ET PAYÉ.
		//
		// « Sur WPML, quand un objet est déjà traduit dans 3 langues mais une
		// langue manque, l'outil demande : retraduire ? Évidemment que non, je
		// choisis toujours de conserver les traductions existantes. »
		//
		// Le moteur le fait déjà : une langue à jour ne coûte pas un appel.
		// Mais le bandeau annonçait « lignes × langues » — quarante lignes et
		// cinq langues promettaient deux cents traductions là où douze étaient
		// dues. On compte donc les drapeaux qui doivent vraiment quelque
		// chose, parmi les lignes cochées et les langues cochées.
		var picked = {};
		$('.dze-tr-lang:checked').each(function () { picked[String($(this).val())] = true; });
		var owed = 0;
		$('.dze-tr-pickone:checked').closest('.dze-tr-row').find('.dze-tr-chip').each(function () {
			var $c = $(this), st = String($c.data('state') || '');
			if (!picked[String($c.data('lang') || '')]) { return; }
			if (st === 'missing' || st === 'stale' || st === 'noise') { owed++; }
		});
		$b.text(rows && langs ? sprintf(i18n.bill, rows, langs, owed) : i18n.billNone);
	}
	// À L OUVERTURE : ce qui avait été coché revient, PUIS le devis se
	// calcule dessus. Dans cet ordre, et une fois le tableau posé — appelé
	// au chargement du script, restore() cherchait des lignes qui n existaient
	// pas encore.
	// L ECRAN D UN OBJET, quand on en regarde un. Vide sur les listes.
	//
	// Elle avait disparu en reecrivant le bouton d envoi : quatre
	// gestionnaires l appelaient encore, dont « Discard ». Un appel a une
	// fonction qui n existe pas tue le gestionnaire AVANT sa premiere ligne —
	// le bouton ne fait rien, la console parle, et l ecran se tait.
	function editor() { return $('.dze-tr-editor'); }

	// LE PANNEAU DE LA FILE : la faire avancer d un cran, ou la vider.
	$(document).on('click', '#dze-tr-runqueue', function () {
		var $b = $(this).prop('disabled', true), $m = $('.dze-tr-queuemsg');
		$m.text(i18n.sending);
		post('dze_tr_runqueue', {}).done(function (r) {
			$m.text((r && r.data && r.data.message) ? r.data.message : '');
		}).fail(function () { $m.text(i18n.error); })
		.always(function () { $b.prop('disabled', false); });
	});
	$(document).on('click', '#dze-tr-emptyqueue', function () {
		var $b = $(this).prop('disabled', true), $m = $('.dze-tr-queuemsg');
		post('dze_tr_emptyqueue', {}).done(function (r) {
			$m.text((r && r.data && r.data.message) ? r.data.message : '');
		}).fail(function () { $m.text(i18n.error); })
		.always(function () { $b.prop('disabled', false); });
	});

	$(function () { restore(); bill(); });

	// WPML'S OWN GESTURE, ONE LANGUAGE AT A TIME: the plus makes the missing
	// translation, the arrows bring an out-of-date one back. It runs the SAME
	// job the batch button runs — one object, one language — because a second
	// engine beside it is how two screens start disagreeing.
	$(document).on('click', '.dze-tr-one', function () {
		var $b = $(this).prop('disabled', true);
		var $row = $b.closest('.dze-tr-row');
		var ref = $row.data('ref'), lang = String($b.data('lang') || '');
		if (!ref || !lang) { $b.prop('disabled', false); return; }
		var was = $b.html();
		$b.html(esc(i18n.sending));
		post('dze_tr_batch', { ref: ref, langs: [lang], accept: $('#dze-tr-autoaccept').prop('checked') ? 1 : 0 }).done(function (r) {
			if (r && r.success) {
				var n = (r.data.done || []).length;
				$b.replaceWith('<span class="dze-tr-chip is-' + (n ? 'held' : 'done') + '">' +
					esc(n ? i18n.rowHeld : i18n.rowNothing) + '</span>');
				if (n) { $row.find('.dze-tr-openword').text(i18n.review); }
				if (n) { $('#dze-tr-sendstate').html(esc(sprintf(i18n.sent, 1)) +
					(cfg.reviewUrl ? ' <a href="' + esc(cfg.reviewUrl) + '">' + esc(i18n.goReview) + ' &rarr;</a>' : '')); }
				return;
			}
			$b.prop('disabled', false).html(was);
			$('#dze-tr-sendstate').text(said(r));
		}).fail(function () {
			$b.prop('disabled', false).html(was);
			$('#dze-tr-sendstate').text(i18n.error);
		});
	});

	// L ENVOI EN MASSE DEPOSE ET REPART.
	//
	// « Les traductions en bulk devraient s effectuer en background, je n en
	// suis pas sur, je n ai pas ose changer de page pendant le chargement. »
	//
	// C etait un aller-retour par objet, et il fallait rester la : quarante
	// pages, quarante requetes, et une seule page lourde suffisait a faire
	// mourir celle en cours. Une requete maintenant, qui range la selection
	// et rend la main. Le travail se fait ensuite, tout seul.
	$(document).on('click', '#dze-tr-send', function () {
		var $btn = $(this);
		var langs = $('.dze-tr-lang:checked').length;
		remember();
		var refs = Object.keys(readKept());
		if (!langs) { window.alert(i18n.langFirst); return; }
		if (!refs.length) { window.alert(i18n.tickFirst); return; }
		$btn.prop('disabled', true);
		$('#dze-tr-sendstate').text(i18n.sending);
		post('dze_tr_queue', {
			refs: refs,
			// LES LANGUES COCHEES PARTENT AVEC : sans elles le moteur traduisait
			// dans les cinq langues du site, soit cinq fois le prix pour qui n en
			// voulait qu une.
			langs: $('.dze-tr-lang:checked').map(function () { return $(this).val(); }).get(),
			accept: $('#dze-tr-autoaccept').prop('checked') ? 1 : 0
		}).done(function (r) {
			$btn.prop('disabled', false);
			if (r && r.success) {
				// DEPOSEE, DONC OUBLIEE : garder la selection ferait renvoyer les
				// memes lignes au prochain clic.
				writeKept({});
				$('.dze-tr-pickone, #dze-tr-all').prop('checked', false);
				bill();
				$('#dze-tr-sendstate').html(esc(r.data.message || '') +
					(cfg.reviewUrl ? ' <a href="' + esc(cfg.reviewUrl) + '">' + esc(i18n.goReview) + ' &rarr;</a>' : ''));
				return;
			}
			$('#dze-tr-sendstate').text(said(r));
		}).fail(function () {
			$btn.prop('disabled', false);
			$('#dze-tr-sendstate').text(i18n.error);
		});
	});

	$(document).on('click', '#dze-tr-auto, #dze-tr-auto-all', function () {
		var $e = editor();
		if (!$e.length) { return; }
		var all = this.id === 'dze-tr-auto-all';
		// EVERY FIELD IS PAID FOR, so it is asked before it is spent.
		if (all && !window.confirm(i18n.confirmAll)) { return; }
		var $b = $(this).prop('disabled', true);
		var $st = $('#dze-tr-autostate').removeClass('is-ko').text(i18n.sending);
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
