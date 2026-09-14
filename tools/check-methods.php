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
$arity   = [];   // class => [method => ['min'=>int,'max'=>int|null]]
$files   = [];

/**
 * Reads a parameter list, starting at the "(" — how many are REQUIRED and how
 * many may be given at all (null = variadic).
 */
function dze_params(string $src, int $open): array {
	$inside = dze_inside($src, $open);
	if ('' === trim($inside)) { return ['min' => 0, 'max' => 0]; }
	$parts = dze_split($inside);
	$min = 0; $max = 0; $var = false;
	foreach ($parts as $p) {
		if (false !== strpos($p, '...')) { $var = true; continue; }
		$max++;
		if (false === strpos($p, '=')) { $min++; }
	}
	return ['min' => $min, 'max' => $var ? null : $max];
}

/** What is between a "(" and its own ")", strings and nesting honoured. */
function dze_inside(string $src, int $open): string {
	$depth = 0; $n = strlen($src); $q = '';
	for ($i = $open; $i < $n; $i++) {
		$c = $src[$i];
		if ('' !== $q) {
			if ('\\' === $c) { $i++; continue; }
			if ($c === $q) { $q = ''; }
			continue;
		}
		if ('\'' === $c || '"' === $c) { $q = $c; continue; }
		if ('(' === $c || '[' === $c || '{' === $c) { $depth++; continue; }
		if (')' === $c || ']' === $c || '}' === $c) {
			$depth--;
			if (0 === $depth) { return substr($src, $open + 1, $i - $open - 1); }
		}
	}
	return '';
}

/** Top-level commas only: a nested call's own commas are not arguments. */
function dze_split(string $in): array {
	$out = ['']; $depth = 0; $q = ''; $n = strlen($in);
	for ($i = 0; $i < $n; $i++) {
		$c = $in[$i];
		if ('' !== $q) {
			$out[count($out) - 1] .= $c;
			if ('\\' === $c) { $out[count($out) - 1] .= $in[++$i] ?? ''; continue; }
			if ($c === $q) { $q = ''; }
			continue;
		}
		if ('\'' === $c || '"' === $c) { $q = $c; $out[count($out) - 1] .= $c; continue; }
		if ('(' === $c || '[' === $c || '{' === $c) { $depth++; }
		if (')' === $c || ']' === $c || '}' === $c) { $depth--; }
		if (',' === $c && 0 === $depth) { $out[] = ''; continue; }
		$out[count($out) - 1] .= $c;
	}
	return array_values(array_filter(array_map('trim', $out), static fn($p) => '' !== $p));
}
foreach (array_merge(glob("$dir/includes/*.php"), glob("$dir/admin/views/*.php"), [ "$dir/dazont-ecom.php" ]) as $f) {
	$src = file_get_contents($f);
	$files[$f] = $src;
	if (preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|trait)\s+(\w+)/m', $src, $m)) {
		$cls = $m[1];
		preg_match_all('/function\s+(\w+)\s*\(/', $src, $mm, PREG_OFFSET_CAPTURE);
		foreach ($mm[1] as $k => $hit) {
			$meth = $hit[0];
			$defined[$cls][$meth] = true;
			// HOW MANY ARGUMENTS IT TAKES. `done_map( array $ids )` was called
			// with two, which parses perfectly and is a fatal the moment the
			// line runs — and the gate's own stub had been shaped to the CALL,
			// so it went green on code the shop could not execute.
			$arity[$cls][$meth] = dze_params($src, $mm[0][$k][1] + strlen($mm[0][$k][0]) - 1);
		}
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

// HOW MANY ARGUMENTS A CALL HANDS OVER, read from the TOKENS.
//
// `DZE_Queue::done_map( array $ids )` was called with two arguments: it parses
// perfectly and is a fatal the moment the line runs — and the gate's own stub
// had been shaped to the CALL rather than to the function, so it went green on
// code the shop could not execute.
//
// Tokens, not text: a doc comment naming `DZE_Category_Content::state()` is not
// a call, and a comma inside a string is not an argument.
foreach ($files as $f => $src) {
	if (preg_match('/^\s*trait\s+\w+/m', $src)) { continue; }
	$own = null;
	if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $m)) { $own = $m[1]; }
	$tk = token_get_all($src);
	$n  = count($tk);
	for ($i = 0; $i < $n; $i++) {
		if (!is_array($tk[$i]) || T_DOUBLE_COLON !== $tk[$i][0]) { continue; }
		// <class> :: <method> (
		$left = $tk[$i - 1] ?? null;
		$meth = $tk[$i + 1] ?? null;
		if (!is_array($left) || !is_array($meth) || T_STRING !== $meth[0]) { continue; }
		$name = $left[1];
		$cls  = ('self' === $name || 'static' === $name) ? $own : $name;
		if (!$cls || !isset($arity[$cls][$meth[1]])) { continue; }
		// The very next thing must be "(", or it is a constant, not a call.
		$j = $i + 2;
		while ($j < $n && is_array($tk[$j]) && T_WHITESPACE === $tk[$j][0]) { $j++; }
		if (!isset($tk[$j]) || '(' !== $tk[$j]) { continue; }
		$want  = $arity[$cls][$meth[1]];
		$got   = 0;
		$depth = 0;
		$any   = false;
		for ($k = $j; $k < $n; $k++) {
			$t = $tk[$k];
			if (is_array($t)) { if (T_WHITESPACE !== $t[0] && T_COMMENT !== $t[0] && T_DOC_COMMENT !== $t[0]) { $any = true; } continue; }
			if ('(' === $t || '[' === $t || '{' === $t) { $depth++; if ($depth > 1) { $any = true; } continue; }
			if (')' === $t || ']' === $t || '}' === $t) {
				$depth--;
				if (0 === $depth) { break; }
				$any = true;
				continue;
			}
			if (',' === $t && 1 === $depth) { $got++; $any = true; continue; }
			$any = true;
		}
		if ($any) { $got++; }
		if ($got >= $want['min'] && (null === $want['max'] || $got <= $want['max'])) { continue; }
		printf(
			"ARGS     %s::%s() takes %s, given %d  — %s:%d\n",
			$cls, $meth[1],
			null === $want['max'] ? $want['min'] . '+' : ($want['min'] === $want['max'] ? (string) $want['min'] : $want['min'] . '-' . $want['max']),
			$got, basename($f), $meth[2]
		);
		$bad++;
	}
}

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

// ONE SHAPE FOR A BLOCK, BUILT IN ONE PLACE. `admin/js/hub.js` and
// `DZE_Hub::sec_open()` are the two builders — one for the screens that draw
// themselves in the browser, one for the screens the server prints — and a
// third copy written anywhere else is how two screens start behaving
// differently while looking the same. This is a shape, so it is looked for by
// its shape.
foreach (array_merge(glob("$dir/admin/js/*.js"), glob("$dir/includes/*.php")) as $f) {
	$name = basename($f);
	if ('hub.js' === $name || 'class-hub.php' === $name) { continue; }
	$src = file_get_contents($f);
	if (false === strpos($src, '<section class="dze-sec')) { continue; }
	printf("MISSING  a second block builder in %s — the shape lives in hub.js / DZE_Hub\n", $name);
	$bad++;
}

echo $bad ? "\n$bad undefined method call(s)\n" : "\nno undefined method calls\n";
exit($bad ? 1 : 0);
