<?php
/**
 * Every shipped file PARSES.
 *
 * Run before every release:  php tools/check-lint.php dazont-ecom
 *
 * `php -l` on the files I happened to touch is not the same thing as `php -l`
 * on the plugin, and the difference is a white screen. 4.296.0 was committed
 * and pushed to both channels with a parse error in class-modules.php — a
 * quote inside a single-quoted string — because the gates had been run BEFORE
 * that last edit. A gate that is run before the last change is not a gate.
 *
 * It is the cheapest check in the pipeline and it is the one that catches the
 * failure nothing else can: a fatal at parse time happens before any of this
 * plugin's own error handling and carries no message at all.
 */
$dir = $argv[1] ?? 'dazont-ecom';
$root = __DIR__ . '/../' . $dir;
if ( ! is_dir( $root ) ) {
	fwrite( STDERR, "No such folder: {$root}\n" );
	exit( 1 );
}
$bad = 0;
$n   = 0;
$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$n++;
	// PARSED IN THIS PROCESS, not in another one.
	//
	// This gate shelled out to `php -l`, and `exec()` is disabled on the shop's
	// host — so on the one machine the plugin is actually built on it did not
	// fail, it DIED, and a pipeline reading its exit code through a pipe was
	// reading the exit code of `tail`. A gate that cannot run where the work
	// happens is a gate nobody is held to.
	//
	// `token_get_all()` with TOKEN_PARSE runs the real parser and throws the
	// real ParseError, in-process, with no shell at all.
	$src = (string) file_get_contents( $file->getPathname() );
	try {
		token_get_all( $src, TOKEN_PARSE );
	} catch ( \ParseError $e ) {
		$bad++;
		echo 'Parse error: ' . $e->getMessage() . ' in ' . $file->getPathname()
			. ' on line ' . $e->getLine() . "\n";
	}
}
printf( "\n%d files, %d that do not parse\n", $n, $bad );
exit( $bad ? 1 : 0 );
