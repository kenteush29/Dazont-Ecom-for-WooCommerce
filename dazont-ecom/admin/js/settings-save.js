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

	function note($form) {
		var $bar = $form.find('.submit, p.submit').last();
		var $n = $form.find('.dze-savednote');
		if (!$n.length) {
			$n = $('<span class="dze-savednote"></span>');
			if ($bar.length) { $bar.append($n); } else { $form.append($n); }
		}
		return $n;
	}

	$(function () {
		$('form').each(function () {
			var $form = $(this);
			// Ours only: a WordPress options form whose option group is one of
			// the plugin's. Anything else on the screen is left alone.
			if (!/options\.php(\?|$)/.test(this.action || '')) { return; }
			var group = $form.find('input[name="option_page"]').val() || '';
			if (group.indexOf('dze_') !== 0) { return; }

			// A single row can ask for the save too: it is the same submit, so
			// there is still ONE way this page is written.
			$form.on('click', '.dze-save-row', function () {
				$form.data('dze-note', $(this).closest('.dze-prb').find('.dze-savednote').first());
				$form.trigger('submit');
			});
			$form.on('submit', function (e) {
				// TinyMCE keeps its content in the iframe until asked.
				if (window.tinymce && tinymce.triggerSave) { tinymce.triggerSave(); }
				e.preventDefault();
				var $btn = $form.find('input[type=submit], button[type=submit]').prop('disabled', true);
				var $row = $form.data('dze-note');
				$form.removeData('dze-note');
				var $n = ($row && $row.length ? $row : note($form)).css('color', '#646970').text(i18n.saving || '…');
				$.post(this.action, $form.serialize())
					.done(function () {
						$btn.prop('disabled', false);
						$n.css('color', '#0a7040').text(i18n.saved || 'Saved ✓');
						window.setTimeout(function () { $n.text(''); }, 2500);
					})
					.fail(function () {
						// Never swallow a save: hand it back to the browser.
						$btn.prop('disabled', false);
						$form.off('submit');
						$form[0].submit();
					});
			});
		});
	});
}(jQuery));
