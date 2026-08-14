/* global jQuery */
/**
 * Saving a settings page without losing the page.
 *
 * Every settings form of the plugin is a plain WordPress options form; this
 * submits it in the background to options.php — the same endpoint, the same
 * nonce, the same registered sanitizers, every field of the form — and writes
 * "Saved ✓" next to the button instead of reloading.
 *
 * It deliberately does NOT post to an endpoint of our own. A second save path
 * only has to disagree with WordPress's on one field to lose it silently,
 * which is exactly what happened to the background shelf: a hand-written AJAX
 * endpoint saved some sections and dropped others, so the same button behaved
 * differently depending on the tab you were standing on.
 *
 * If anything at all goes wrong, the form is submitted normally: a full page
 * reload is a worse experience, never a lost setting.
 */
(function ($) {
	'use strict';
	if (window.dzeSettingsSaveBound) { return; }
	window.dzeSettingsSaveBound = true;

	var i18n = window.dzeSettingsSaveI18n || {};

	// The note next to the PAGE's own button. Searching the whole form for one
	// now finds the note of every prompt card too, and would write "Saving…"
	// into all of them at once.
	function note($form) {
		var $bar = $form.find('.submit, p.submit').last();
		var $host = $bar.length ? $bar : $form;
		var $n = $host.children('.dze-savednote');
		if (!$n.length) {
			$n = $('<span class="dze-savednote"></span>');
			$host.append($n);
		}
		return $n;
	}

	// Ours: a form carrying one of the plugin's option groups. Recognised by
	// the group and not by the shape of its action attribute — a form whose
	// action reads differently on some installs was skipped entirely, which
	// left the page saving the old way and every button bound to it dead.
	function isOurs($form) {
		return ($form.find('input[name="option_page"]').val() || '').indexOf('dze_') === 0;
	}

	// A single row can ask for the save too: it is the same submit, so there is
	// still ONE way this page is written. Delegated on the document, so it does
	// not depend on the form having been recognised when the page loaded.
	$(document).on('click', '.dze-save-row', function () {
		var $form = $(this).closest('form');
		if (!$form.length) { return; }
		$form.data('dze-note', $(this).closest('.dze-prb').find('.dze-savednote').first());
		$form.trigger('submit');
	});

	$(function () {
		$('form').each(function () {
			var $form = $(this);
			if (!isOurs($form)) { return; }

			$form.on('submit', function (e) {
				// TinyMCE keeps its content in the iframe until asked.
				if (window.tinymce && tinymce.triggerSave) { tinymce.triggerSave(); }
				e.preventDefault();
				var $btn = $form.find('input[type=submit], button[type=submit]').prop('disabled', true);
				var $row = $form.data('dze-note');
				$form.removeData('dze-note');
				var $n = ($row && $row.length ? $row : note($form)).css('color', '#646970').text(i18n.saving || '…');
				// Bounded, always: a request that never answers used to leave
				// "Saving…" on screen for good, which says neither saved nor
				// failed. Past the timeout the browser takes the save back the
				// ordinary way, so nothing typed is ever lost to a silence.
				$.ajax({ type: 'POST', url: this.action, data: $form.serialize(), timeout: 25000 })
					.done(function () {
						$btn.prop('disabled', false);
						$n.css('color', '#0a7040').text(i18n.saved || 'Saved ✓');
						window.setTimeout(function () { $n.text(''); }, 2500);
					})
					.fail(function (x, why) {
						$btn.prop('disabled', false);
						$n.css('color', '#b32d2e').text(
							'timeout' === why ? (i18n.slow || '') : (i18n.retry || '')
						);
						// Never swallow a save: hand it back to the browser.
						$form.off('submit');
						$form[0].submit();
					});
			});
		});
	});
}(jQuery));
