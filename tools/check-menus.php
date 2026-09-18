<?php
/**
 * EVERY SCREEN THIS PLUGIN REGISTERS IS IN THE CATALOGUE.
 *
 * `class-screens.php` is the one list: the menu reads its labels from it, the
 * sentences are built from it, and `test-trace.php` refuses any sentence
 * naming a screen that is not there. But nothing ever checked the other
 * direction — a page could be registered with `add_submenu_page()` and never
 * declared, and then it could not be named, linked, ordered, gated or found.
 *
 * That is exactly how four destinations came to be registered and then deleted
 * from the menu in one day, two of them holding functions that existed nowhere
 * else. This gate closes that direction: a slug handed to WordPress that the
 * catalogue does not know is a screen nobody can send anybody to.
 *
 * Usage: php tools/check-menus.php [dazont-ecom]
 */

$dir = $argv[1] ?? 'dazont-ecom';
$root = __DIR__ . '/../' . $dir;
if ( ! is_dir( $root ) ) {
	fwrite( STDERR, "no such folder: $root\n" );
	exit( 1 );
}

// 1. What the catalogue declares — every 'slug' => … in class-screens.php,
//    plus the tabs that carry a slug of their own.
$cat = (string) file_get_contents( $root . '/includes/class-screens.php' );
preg_match( "/const PARENT\s*=\s*'([^']+)'/", $cat, $p );
$parent = $p[1] ?? 'dazont-ecom';
preg_match_all( "/'slug'\s*=>\s*(?:'([^']+)'|self::PARENT)/", $cat, $m );
$known = [];
foreach ( $m[1] as $i => $one ) {
	$known[] = '' !== $one ? $one : $parent;
}
$known[] = $parent;
$known = array_values( array_unique( array_filter( $known ) ) );

// 2. What the code actually hands to WordPress. A slug written as a constant
//    is resolved back to its value, because that is the form the rules ask for.
$consts = [];
foreach ( glob( $root . '/includes/*.php' ) as $f ) {
	$s = (string) file_get_contents( $f );
	if ( preg_match( '/\bclass\s+(DZE_[A-Za-z_]+)/', $s, $c ) ) {
		preg_match_all( "/const\s+([A-Z_]+)\s*=\s*'([^']+)'/", $s, $k, PREG_SET_ORDER );
		foreach ( $k as $one ) {
			$consts[ $c[1] . '::' . $one[1] ] = $one[2];
			$consts[ 'self::' . $one[1] . '@' . basename( $f ) ] = $one[2];
		}
	}
}
$bad = [];
foreach ( glob( $root . '/includes/*.php' ) as $f ) {
	$s = (string) file_get_contents( $f );
	if ( ! preg_match_all( '/add_(?:sub)?menu_page\((.{0,600}?)\);/s', $s, $calls ) ) {
		continue;
	}
	foreach ( $calls[1] as $args ) {
		// SPLIT ON TOP-LEVEL COMMAS ONLY. Every argument here is itself a call
		// — __( 'Label', 'dazont-ecom' ) — so a naive explode() cuts inside
		// them and reads the textdomain as the slug.
		$parts = [];
		$depth = 0; $q = ''; $buf = '';
		$len = strlen( $args );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $args[ $i ];
			if ( '' !== $q ) {
				$buf .= $ch;
				if ( $ch === $q && ( $i === 0 || '\\' !== $args[ $i - 1 ] ) ) { $q = ''; }
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) { $q = $ch; $buf .= $ch; continue; }
			if ( '(' === $ch || '[' === $ch ) { $depth++; }
			if ( ')' === $ch || ']' === $ch ) { $depth--; }
			if ( ',' === $ch && 0 === $depth ) { $parts[] = trim( $buf ); $buf = ''; continue; }
			$buf .= $ch;
		}
		if ( '' !== trim( $buf ) ) { $parts[] = trim( $buf ); }
		// add_menu_page: slug is arg 4; add_submenu_page: arg 5.
		$slug = count( $parts ) >= 6 ? $parts[4] : ( $parts[3] ?? '' );
		$slug = trim( $slug, "' " );
		if ( '' === $slug || false !== strpos( $slug, '$' ) ) {
			continue; // a variable: the catalogue cannot be checked against it.
		}
		if ( 0 === strpos( $slug, 'self::' ) ) {
			// A partial included INSIDE another class body has no class of its
			// own, so its `self::` resolves in the file that includes it —
			// class-translate-screen.php lives inside DZE_Translate.
			$name = substr( $slug, 6 );
			$slug = $consts[ 'self::' . $name . '@' . basename( $f ) ] ?? $slug;
			if ( 0 === strpos( $slug, 'self::' ) ) {
				foreach ( $consts as $key => $val ) {
					if ( false !== strpos( $key, '::' . $name ) ) { $slug = $val; break; }
				}
			}
		} elseif ( false !== strpos( $slug, '::' ) ) {
			$slug = $consts[ $slug ] ?? $slug;
		}
		// A page hanging off a WordPress menu of its own is still ours.
		if ( in_array( $slug, $known, true ) ) {
			continue;
		}
		$bad[] = basename( $f ) . ": registers '" . $slug . "', which class-screens.php does not declare";
	}
}

if ( $bad ) {
	echo "\n" . count( $bad ) . " screen(s) registered but not in the catalogue:\n";
	foreach ( array_unique( $bad ) as $one ) {
		echo "  - $one\n";
	}
	echo "\nDeclare it in class-screens.php::catalog(), or stop registering it.\n";
	echo "A screen the catalogue does not know cannot be named, linked, ordered or gated.\n";
	exit( 1 );
}
echo "\nevery registered screen is in the catalogue (" . count( $known ) . " declared)\n";
