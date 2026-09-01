<?php
/**
 * Every prompt offered a "Make this the default" control must be answerable
 * by the prompt registry.
 *
 * Run before every release:  php tools/check-prompts.php dazont-ecom
 *
 * DZE_Prompt_Defaults::control() returns early and draws NOTHING when the id
 * it is given is not in the registry. So a prompt added to one list and not
 * the other loses its star quietly: the screen still shows a "Restore
 * default" button, so nothing looks broken, and the owner simply has no way
 * to make his own text the default. promo_email and promo_i18n were in that
 * state. Exits non-zero so it can gate a release.
 */
$dir = $argv[1] ?? 'dazont-ecom';
$src = '';
foreach ( array_merge( glob( "$dir/includes/*.php" ), glob( "$dir/admin/views/*.php" ) ) as $f ) {
	$src .= file_get_contents( $f ) . "\n";
}
// Every id a screen asks a control for.
preg_match_all( "/Prompt_Defaults::control\(\s*'([^']*)'/", $src, $used );
$asked = array_values( array_unique( array_filter( $used[1] ) ) );

// Every id the registry can answer for.
preg_match_all( "/^\s*'([a-z0-9_]+)'\s*=>\s*\[\s*'DZE_\w+',\s*'\w+',\s*'\w+'\s*\]/m", $src, $known );
$registry = array_values( array_unique( $known[1] ) );

$bad = 0;
foreach ( $asked as $id ) {
	// A per-field prompt is answered by its own module, not by the map.
	if ( 0 === strpos( $id, 'content_' ) || in_array( $id, [ 'feature_rules' ], true ) ) {
		continue;
	}
	if ( ! in_array( $id, $registry, true ) ) {
		echo "MISSING  '$id' has a \"Make this the default\" control but no registry row\n";
		$bad++;
	}
}
// ---------------------------------------------------------------------------
// And every prompt on a screen SAYS what it is sent with.
//
// "Attention à ce que ce soit bien présent sur chaque prompt, c'est très
// important pour du débug ou comprendre quoi améliorer." A block that exists
// on the email screen and not on Categories is worse than none, because the
// screen without it looks like a prompt that receives nothing. The block is
// one function; this is what makes a new prompt carry it — the file that
// draws the prompt must call DZE_Prompts::the_data() for that same id.
$missing = 0;
foreach ( array_merge( glob( "$dir/includes/*.php" ), glob( "$dir/admin/views/*.php" ) ) as $file ) {
	$code = (string) file_get_contents( $file );
	preg_match_all( "/Prompt_Defaults::control\(\s*([^,]+),/", $code, $here );
	foreach ( array_unique( $here[1] ) as $expr ) {
		$expr = trim( $expr );
		if ( "''" === $expr ) {
			continue; // the shared modal, which is the block's own screen.
		}
		if ( false === strpos( $code, 'the_data( ' . $expr ) ) {
			printf( "NO DATA  %s draws the prompt %s and never says what it is sent with\n", basename( $file ), $expr );
			$missing++;
		}
	}
}

printf( "\n%d prompt control(s) checked against %d registry rows — %s\n",
	count( $asked ), count( $registry ), $bad ? "$bad unanswerable" : 'all answerable' );
printf( "%s\n", $missing ? "$missing prompt(s) with no \"what it is sent with\" block" : 'every prompt on screen says what it is sent with' );
exit( ( $bad || $missing ) ? 1 : 0 );
