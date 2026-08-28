<?php
/**
 * Every self::/static::/DZE_X:: call in the plugin, checked against what is
 * actually defined.
 *
 * Run before every release:  php tools/check-methods.php dazont-ecom
 *
 * This exists because a call to a method that was never written is invisible
 * to `php -l` — the file parses perfectly and dies the moment the line runs.
 * DZE_Klaviyo::sample_body() was called from admin_enqueue_scripts on ONE
 * settings tab and nowhere else, so the tab was a white page for six versions
 * while every other screen worked and every syntax check passed. A white page
 * carries no message, and the fatal happened before any of our own error
 * handling could report it.
 *
 * It exits non-zero when it finds something, so it can gate a release.
 */
// A method that does not exist is a fatal the moment the
// line runs — and a line that only runs on one screen is a fatal nobody sees
// until somebody opens that screen.
$dir = $argv[1];
$defined = [];   // class => [method => true]
$files   = [];
foreach (array_merge(glob("$dir/includes/*.php"), glob("$dir/admin/views/*.php"), [ "$dir/dazont-ecom.php" ]) as $f) {
	$src = file_get_contents($f);
	$files[$f] = $src;
	if (preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|trait)\s+(\w+)/m', $src, $m)) {
		$cls = $m[1];
		preg_match_all('/function\s+(\w+)\s*\(/', $src, $mm);
		foreach ($mm[1] as $meth) { $defined[$cls][$meth] = true; }
		// A class using a trait inherits its methods.
		preg_match_all('/^\s*use\s+(DZE_\w+)\s*;/m', $src, $tu);
		$defined[$cls]['__traits'] = $tu[1];
	}
}
// Fold trait methods into the classes that use them.
foreach ($defined as $cls => $info) {
	foreach ((array)($info['__traits'] ?? []) as $t) {
		foreach (array_keys($defined[$t] ?? []) as $meth) { $defined[$cls][$meth] = true; }
	}
	unset($defined[$cls]['__traits']);
}
$bad = 0;
foreach ($files as $f => $src) {
	$own = null;
	// A trait's self:: resolves to whatever class uses it, not to the trait,
	// so its calls cannot be checked here — they are checked on the host.
	if (preg_match('/^\s*trait\s+\w+/m', $src)) { continue; }
	if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $m)) { $own = $m[1]; }
	$lines = explode("\n", $src);
	foreach ($lines as $i => $line) {
		if (preg_match_all('/(?:(self|static|DZE_\w+))::(\w+)\s*\(/', $line, $mm, PREG_SET_ORDER)) {
			foreach ($mm as $call) {
				$cls = ($call[1] === 'self' || $call[1] === 'static') ? $own : $call[1];
				$meth = $call[2];
				if (!$cls || !isset($defined[$cls])) { continue; }   // class not in this plugin
				if (isset($defined[$cls][$meth])) { continue; }
				printf("MISSING  %s::%s()  — %s:%d\n", $cls, $meth, basename($f), $i + 1);
				$bad++;
			}
		}
	}
}

// A hook's callback is the same fatal wearing a different hat, and a worse
// one: [ __CLASS__, 'ajax_thing' ] on an action nobody wrote answers a click
// with a 400 and no message at all. wp_ajax_dze_klav_langs pointed at a method
// that had been lost in an edit, so the Translate button died on its first
// request and said only "the translation did not finish". Nothing above sees
// it — there is no :: in a callable array — so it is looked for on its own.
// Only the forms that can ONLY be a callback. [ 'DZE_Content', 'prompt' ] is
// just as often a pair of strings — the class that owns an option and the key
// it keeps it under, which is exactly what the prompt registry stores — and a
// checker that cries wolf on those is a checker somebody stops reading.
$q  = '[\x27"]';
$re = '/(?:\[|array\s*\()\s*(__CLASS__|self::class|static::class|\$this)\s*,\s*'
	. $q . '(\w+)' . $q . '\s*(?:\]|\))/';
foreach ($files as $f => $src) {
	$own = null;
	if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $m)) { $own = $m[1]; }
	if (!preg_match_all($re, $src, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { continue; }
	foreach ($mm as $call) {
		$meth = $call[2][0];
		$cls  = $own;
		if (!$cls || !isset($defined[$cls])) { continue; }   // not a class of ours
		if (isset($defined[$cls][$meth])) { continue; }
		printf("MISSING  %s::%s()  — %s:%d  (callback)\n", $cls, $meth, basename($f),
			substr_count(substr($src, 0, $call[0][1]), "\n") + 1);
		$bad++;
	}
}

// The other half of the same failure: a button that posts an action nobody
// registered. WordPress answers those with a bare 400 and no message, so the
// screen says whatever its own "something went wrong" string is and the shop
// has nothing to go on. dze_klav_langs was one of those.
$hooked = [];
foreach ($files as $src) {
	if (preg_match_all('/wp_ajax_(?:nopriv_)?([a-z0-9_]+)/i', $src, $mm)) {
		foreach ($mm[1] as $one) { $hooked[$one] = true; }
	}
}
foreach (glob("$dir/admin/js/*.js") as $f) {
	$src   = file_get_contents($f);
	$lines = explode("\n", $src);
	foreach ($lines as $i => $line) {
		if (!preg_match_all('/action\s*:\s*[\x27"]([a-z0-9_]+)[\x27"]/i', $line, $mm)) { continue; }
		foreach ($mm[1] as $one) {
			// Only ours: WordPress and other plugins register their own.
			if (0 !== strpos($one, 'dze_') || isset($hooked[$one])) { continue; }
			printf("MISSING  wp_ajax_%s  — %s:%d  (posted, never registered)\n", $one, basename($f), $i + 1);
			$bad++;
		}
	}
}

// jQuery removed a dozen helpers in 4.0 — $.trim, $.isArray, $.proxy and the
// rest. WordPress still ships 3.7, where they work and warn; the day a shop
// installs a jQuery updater, every handler that uses one dies where it stands,
// and a dead handler looks exactly like a button that does nothing. Nothing in
// PHP, in `node --check` or in a screenshot shows it, so it is looked for here.
$gone = ['trim', 'isArray', 'isFunction', 'isNumeric', 'isWindow', 'type', 'now',
	'parseJSON', 'proxy', 'holdReady', 'unique', 'nodeName', 'camelCase', 'inArray'];
$js = array_merge(
	glob("$dir/admin/js/*.js"),
	glob("$dir/includes/*.php"),   // inline <script> lives in these
	glob("$dir/admin/views/*.php")
);
foreach ($js as $f) {
	$src   = file_get_contents($f);
	$lines = explode("\n", $src);
	foreach ($lines as $i => $line) {
		foreach ($gone as $one) {
			if (false === strpos($line, '$.' . $one . '(') && false === strpos($line, 'jQuery.' . $one . '(')) { continue; }
			printf("MISSING  $.%s()  — %s:%d  (removed in jQuery 4)\n", $one, basename($f), $i + 1);
			$bad++;
		}
	}
}

echo $bad ? "\n$bad undefined method call(s)\n" : "\nno undefined method calls\n";
exit($bad ? 1 : 0);
