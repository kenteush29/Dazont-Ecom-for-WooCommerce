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
	$out = [];
	$rc  = 0;
	exec( 'php -l ' . escapeshellarg( $file->getPathname() ) . ' 2>&1', $out, $rc );
	if ( 0 !== $rc ) {
		$bad++;
		echo implode( "\n", $out ) . "\n";
	}
}
printf( "\n%d files, %d that do not parse\n", $n, $bad );
exit( $bad ? 1 : 0 );
