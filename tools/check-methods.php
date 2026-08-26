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
echo $bad ? "\n$bad undefined method call(s)\n" : "\nno undefined method calls\n";
exit($bad ? 1 : 0);
