/**
 * The criteria screen, used in a real browser.
 *
 * Run before every release:  node tools/js/diagnostic-card.mjs
 *
 * A card that says "On: Products" while sitting under the POSTS heading, and
 * a field chosen fresh that keeps the last field's figure — "variations with
 * no photograph of their own is less than 120" — are both bugs that no PHP
 * test can see: they only exist once a person clicks. So the screen is
 * rendered by PHP, loaded into a browser, and clicked.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );
// jQuery is not vendored into the plugin — it is WordPress's, and a copy in
// here would be one more thing to keep in step. The two builds the shop can
// actually be served are fetched once, beside this file, and never shipped.
const npm  = process.env.DZE_JQ_DIR || join( here, 'node_modules' );
if ( ! existsSync( join( npm, 'jquery/dist/jquery.min.js' ) ) ) {
	console.log( 'Fetching the two jQuery builds this test runs against…' );
	execFileSync( 'npm', [ 'install', '--silent', '--no-audit', '--no-fund',
		'jquery@4', 'jquery-3@npm:jquery@3.7.1' ], { cwd: here, stdio: 'inherit' } );
}
const jqs  = [ [ '3.7.1', join( npm, 'jquery-3/dist/jquery.min.js' ) ], [ '4.0.0', join( npm, 'jquery/dist/jquery.min.js' ) ] ];

let fails = 0, ran = 0;
function ok( what, got, want ) {
	ran++;
	if ( JSON.stringify( got ) === JSON.stringify( want ) ) { console.log( `  ok    ${what}` ); return; }
	fails++;
	console.log( `  FAIL  ${what}\n          got  ${JSON.stringify( got )}\n          want ${JSON.stringify( want )}` );
}

// The screen as the plugin itself draws it — never a copy of it.
const html = execFileSync( 'php', [ join( here, 'render-diagnostic.php' ) ], { encoding: 'utf8' } );

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	const page = await browser.newPage();
	const errors = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );
	// jQuery and ajaxurl BEFORE the screen's own script, exactly as WordPress
	// serves them — the inline script the plugin prints is the one that runs,
	// never a copy of it.
	await page.route( 'http://dze.test/**', route => route.request().url().endsWith( '/' )
		? route.fulfill( { status: 200, contentType: 'text/html',
			body: `<!doctype html><html><head><script src="/jquery.js"></script>`
				+ `<script>window.ajaxurl='http://dze.test/ajax';</script></head><body>${html}</body></html>` } )
		: route.request().url().endsWith( '/jquery.js' )
			? route.fulfill( { status: 200, contentType: 'text/javascript', body: readFileSync( jq, 'utf8' ) } )
			: route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: { keys: [] } } ) } ) );
	await page.goto( 'http://dze.test/' );

	console.log( `The criteria screen, in a browser — jQuery ${label}` );
	ok( 'the screen runs without an error', errors, [] );

	// Add a criterion, then switch it to the variations field.
	const before = await page.locator( '.dze-diag-card' ).count();
	await page.click( '#dze-diag-add' );
	await page.waitForTimeout( 150 );
	ok( 'Add makes one card', await page.locator( '.dze-diag-card' ).count(), before + 1 );

	// THE card the shop just added — marked, so the test can never end up
	// asking a different one and calling it a pass.
	await page.evaluate( () => document.querySelector( '.dze-diag-card.is-open' ).setAttribute( 'data-under-test', '1' ) );
	const $new = '[data-under-test]';
	await page.selectOption( `${$new} .dze-diag-field`, 'product.variation_images' );
	await page.waitForTimeout( 100 );

	ok( 'the field asks its own question',
		await page.inputValue( `${$new} .dze-diag-test` ), 'gt' );
	ok( 'with its own figure, not the last one',
		await page.inputValue( `${$new} .dze-diag-value` ), '0' );
	ok( 'the key box says what it wants',
		await page.getAttribute( `${$new} .dze-diag-key`, 'placeholder' ), 'which ones — attribute_pa_couleur' );
	ok( 'and the card names itself from the rule',
		( await page.textContent( `${$new} .dze-diag-name` ) ).trim(),
		'Variations with no photograph of their own is more than 0' );
	ok( 'the card says Products',
		await page.inputValue( `${$new} .dze-diag-scope` ), 'product' );
	ok( 'and it lives under Products',
		await page.evaluate( () => document.querySelector( '[data-under-test]' )
			.closest( '.dze-prlist' ).getAttribute( 'data-scope' ) ), 'product' );

	// And moving it to another post type moves the card with it.
	await page.selectOption( `${$new} .dze-diag-scope`, 'page' );
	await page.waitForTimeout( 100 );
	ok( 'changing the post type moves the card',
		await page.evaluate( () => {
			const list = document.querySelector( '[data-under-test]' ).closest( '.dze-prlist' );
			return 'dze-diag-new' === list.id
				? ( list.previousElementSibling || {} ).textContent
				: list.getAttribute( 'data-scope' );
		} ), 'Pages' );
	ok( 'nothing was raised on the way', errors, [] );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
