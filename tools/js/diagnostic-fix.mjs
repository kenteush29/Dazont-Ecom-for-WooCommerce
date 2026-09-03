/**
 * The Fix button on a line of the problem list, PRESSED.
 *
 * Run before every release:  node tools/js/diagnostic-fix.mjs
 *
 * A button that makes no request looks exactly like a button that made one
 * and got nothing back, and neither `node --check` nor any PHP test can tell
 * them apart: the handler is bound when a browser runs the page and not
 * before. This screen has already shipped a dead button once, and a button
 * that went to a settings page twice.
 *
 * So the plugin's own screen is rendered by its own gate, loaded into a real
 * browser on both the jQuery WordPress ships today and the jQuery 4 it will
 * ship, clicked, and the REQUEST is read back — its action, and what it
 * carries — then the answer is handed back and the page is read again.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const npm  = process.env.DZE_JQ_DIR || join( here, 'node_modules' );
if ( ! existsSync( join( npm, 'jquery/dist/jquery.min.js' ) ) ) {
	console.log( 'Fetching the two jQuery builds this test runs against…' );
	execFileSync( 'npm', [ 'install', '--silent', '--no-audit', '--no-fund',
		'jquery@4', 'jquery-3@npm:jquery@3.7.1' ], { cwd: here, stdio: 'inherit' } );
}
const jqs = [ [ '3.7.1', join( npm, 'jquery-3/dist/jquery.min.js' ) ], [ '4.0.0', join( npm, 'jquery/dist/jquery.min.js' ) ] ];

let fails = 0, ran = 0;
function ok( what, got, want ) {
	ran++;
	if ( JSON.stringify( got ) === JSON.stringify( want ) ) { console.log( `  ok    ${what}` ); return; }
	fails++;
	console.log( `  FAIL  ${what}\n          got  ${JSON.stringify( got )}\n          want ${JSON.stringify( want )}` );
}

// The screen as the plugin draws it, from the gate that already owns the fake
// shop. Never a copy of the markup written into this file.
const html = execFileSync( 'php',
	[ join( here, '..', 'test-diagnostic.php' ), 'dazont-ecom', '--dump-list' ],
	{ encoding: 'utf8', cwd: join( here, '..', '..' ) } );

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	const page = await browser.newPage();
	const errors = [];
	const posts  = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );
	page.on( 'dialog', d => d.dismiss() );

	await page.route( 'http://dze.test/**', route => {
		const url = route.request().url();
		if ( url.endsWith( '/' ) ) {
			return route.fulfill( { status: 200, contentType: 'text/html',
				body: `<!doctype html><html><head><meta charset="utf-8"><script src="/jquery.js"></script>`
					+ `<script>window.ajaxurl='http://dze.test/ajax';</script></head><body>${html}</body></html>` } );
		}
		if ( url.endsWith( '/jquery.js' ) ) {
			return route.fulfill( { status: 200, contentType: 'text/javascript', body: readFileSync( jq, 'utf8' ) } );
		}
		const sent = Object.fromEntries( new URLSearchParams( route.request().postData() || '' ) );
		posts.push( sent );
		return route.fulfill( { status: 200, contentType: 'application/json',
			body: JSON.stringify( { success: true, data: { url: 'http://dze.test/queue', message: 'Sent — it waits for you to accept it.' } } ) } );
	} );
	await page.goto( 'http://dze.test/' );

	console.log( `A line of the problem list, in a browser — jQuery ${label}` );
	ok( 'the screen runs without an error', errors, [] );
	ok( 'every row carries its button',     await page.locator( '.dze-diag-fix' ).count(), 2 );
	// The button NAMES the pass it runs. "Fix" said nothing about what was
	// about to happen, and this is the list the owner asked for first.
	ok( 'and it says what it will run',
		( await page.textContent( '.dze-diag-fix[data-id="901"]' ) ).trim(), 'Make a photograph' );

	// A CONTROL ON A ROW ACTS ON THAT ROW. Every link this screen has carried
	// to "the tool" went to a Dazont settings tab instead, twice.
	ok( 'and nothing links to a settings page',
		await page.evaluate( () => Array.from( document.querySelectorAll( 'tbody a[href]' ) )
			.some( a => /page=dazont-ecom-ai|tab=(categories|automation|lab)/.test( a.href ) ) ), false );

	await page.click( '.dze-diag-fix[data-id="901"]' );
	await page.waitForTimeout( 250 );
	ok( 'pressing it makes a request at all', posts.length, 1 );
	ok( 'to the mending endpoint',          posts[0].action, 'dze_diag_fix' );
	ok( 'carrying the criterion',           posts[0].check, 'prod_gallery' );
	// The id of the row it sits on. Left out once already, so "Fix" on one
	// product quietly sent twenty.
	ok( 'and the id of its own row',        posts[0].id, '901' );
	ok( 'with a nonce',                     ( posts[0].nonce || '' ).length > 0, true );
	// The answer LANDS. A request made whose answer goes nowhere is the same
	// broken button from the shop's chair.
	ok( 'the row now says it is waiting',
		await page.locator( 'a', { hasText: 'Waiting for you' } ).count() > 0, true );
	ok( 'and the button is gone with it',   await page.locator( '.dze-diag-fix[data-id="901"]' ).count(), 0 );
	// The other row is untouched: one press, one object.
	ok( 'the other row still offers its own', await page.locator( '.dze-diag-fix[data-id="902"]' ).count(), 1 );
	ok( 'nothing was raised on the way',    errors, [] );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
