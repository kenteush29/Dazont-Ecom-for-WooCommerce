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
	// ---- conditions: one figure is not enough for a whole catalogue -----
	// "Product price between x to y : at least x gallery images." A criterion
	// that ships with none must still be able to gain one, and the button that
	// adds it is the exact shape of thing that has shipped dead here before.
	await page.selectOption( `${$new} .dze-diag-scope`, 'product' );
	await page.waitForTimeout( 120 );
	await page.selectOption( `${$new} .dze-diag-field`, 'product.gallery' );
	await page.waitForTimeout( 120 );
	const rows = () => page.locator( `${$new} .dze-diag-bandrow:not(.dze-diag-bandproto)` ).count();
	// A criterion is ONE rule and ONE figure until somebody asks for more. The
	// switch follows the field as it is chosen, not as it was saved: drawn
	// only server-side it never appeared for a criterion switched to a count
	// in the browser — a control you only discover by saving and reloading.
	ok( 'the field menu is in order',
		await page.evaluate( () => {
			const t = Array.from( document.querySelectorAll( '[data-under-test] .dze-diag-field option' ) ).map( o => o.textContent );
			return JSON.stringify( t ) === JSON.stringify( t.slice().sort( ( a, b ) => a.localeCompare( b ) ) );
		} ), true );
	ok( 'a count offers the switch',        await page.isVisible( `${$new} .dze-diag-cond` ), true );
	ok( 'and it is off',                    await page.isChecked( `${$new} .dze-diag-condon` ), false );
	ok( 'so the screen shows nothing else', await page.isVisible( `${$new} .dze-diag-bands` ), false );
	// Ticking it must answer with something. An empty list under a box just
	// ticked is a press that did nothing.
	await page.check( `${$new} .dze-diag-condon` );
	await page.waitForTimeout( 200 );
	ok( 'ticking it opens the conditions',  await page.isVisible( `${$new} .dze-diag-bands` ), true );
	ok( 'with a first one already there',   await rows(), 1 );
	// THE CONDITIONS ARE THE RULE. A figure sitting beside them is a second
	// rule nobody wrote, and the screen cannot say which of the two a product
	// was held to.
	ok( 'and the plain figure goes',        await page.isVisible( `${$new} .dze-diag-value` ), false );
	await page.uncheck( `${$new} .dze-diag-condon` );
	await page.waitForTimeout( 150 );
	ok( 'unticking shuts them again',       await page.isVisible( `${$new} .dze-diag-bands` ), false );
	ok( 'and gives the figure back',        await page.isVisible( `${$new} .dze-diag-value` ), true );
	ok( 'without throwing the work away',   await rows(), 1 );
	await page.check( `${$new} .dze-diag-condon` );
	await page.waitForTimeout( 150 );
	// The prototype is there but must never post a condition of its own.
	ok( 'and its blank one is not submitted',
		await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-under-test] .dze-diag-bandproto [name]' )
		).every( el => el.disabled ) ), true );

	await page.click( `${$new} .dze-diag-bandadd` );
	await page.waitForTimeout( 150 );
	ok( 'Add a condition adds one',         await rows(), 2 );
	ok( 'and it can actually be submitted',
		await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-under-test] .dze-diag-bandrow:not(.dze-diag-bandproto) [name]' )
		).every( el => ! el.disabled ) ), true );
	// Its own field menu, cut to this post type — and never the criterion's own
	// field, which would place a gallery by the size of the gallery.
	ok( 'the field it measures is its own',
		await page.evaluate( () => document.querySelector(
			'[data-under-test] .dze-diag-bandrow:not(.dze-diag-bandproto) .dze-diag-bandfield'
		).value !== 'product.gallery' ), true );
	ok( 'the criterion\'s own field is not offered',
		await page.evaluate( () => document.querySelector(
			'[data-under-test] .dze-diag-bandrow:not(.dze-diag-bandproto) .dze-diag-bandfield option[value="product.gallery"]'
		).disabled ), true );
	ok( 'nor another post type\'s field',
		await page.evaluate( () => document.querySelector(
			'[data-under-test] .dze-diag-bandrow:not(.dze-diag-bandproto) .dze-diag-bandfield option[value="category.products"]'
		).disabled ), true );
	ok( 'and the price is',
		await page.evaluate( () => document.querySelector(
			'[data-under-test] .dze-diag-bandrow:not(.dze-diag-bandproto) .dze-diag-bandfield option[value="product.price"]'
		).disabled ), false );
	// And it is what a fresh condition OPENS on. Left to the first field in
	// the list it opened on "main photograph", which places nothing — the
	// menu looked, to a reader, like the thing being counted.
	ok( 'a fresh condition opens on the price',
		await page.inputValue( `${$new} .dze-diag-bandrow:not(.dze-diag-bandproto) .dze-diag-bandfield` ), 'product.price' );
	// Two fields in one sentence, and both named: the menu is what is TESTED,
	// the figure at the end is what is COUNTED.
	ok( 'the row reads as a sentence',
		( await page.textContent( `${$new} .dze-diag-bandrow:not(.dze-diag-bandproto)` ) ).includes( 'is between' ), true );
	ok( 'and says what it counts',
		( await page.textContent( `${$new} .dze-diag-bandrow:not(.dze-diag-bandproto) .dze-diag-bandunit` ) ).trim(), 'photographs' );

	ok( 'twice adds a second',              await rows(), 2 );
	// Two conditions posting under one key are one condition.
	ok( 'each posts under its own key',
		await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-under-test] .dze-diag-bandrow:not(.dze-diag-bandproto) [name*="[want]"]' )
		).map( el => ( el.name.match( /\[bands\]\[(\d+)\]/ ) || [] )[1] ) ), [ '0', '1' ] );

	// The head IS the rule, so it carries every figure as it is typed — not
	// "less than 6" while a cheap product is being judged on 3.
	const wants = page.locator( `${$new} .dze-diag-bandrow:not(.dze-diag-bandproto) [name*="[want]"]` );
	await wants.nth( 0 ).fill( '3' );
	await wants.nth( 1 ).fill( '4' );
	await page.waitForTimeout( 150 );
	// The head names what the criterion is JUDGED by, which with Conditional
	// on is the conditions and nothing else.
	ok( 'the head names the conditions',
		( await page.textContent( `${$new} .dze-diag-name` ) ).trim(),
		'Gallery photographs is less than 3/4 photographs' );

	// Removing the first must renumber what is left, or the survivor posts as
	// condition 1 with no condition 0 and the list arrives with a hole in it.
	await page.click( `${$new} .dze-diag-bandrow:not(.dze-diag-bandproto) .dze-diag-banddel` );
	await page.waitForTimeout( 150 );
	ok( 'removing one leaves the other',    await rows(), 1 );
	ok( 'renumbered from the top',
		await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-under-test] .dze-diag-bandrow:not(.dze-diag-bandproto) [name*="[want]"]' )
		).map( el => ( el.name.match( /\[bands\]\[(\d+)\]/ ) || [] )[1] ) ), [ '0' ] );
	// STANDARDISED, on every criterion that holds a figure. A description
	// judged on its length is the same question as a gallery judged on its
	// count, and tying this to the FIELD's type gave it to one and denied it
	// to the other.
	await page.selectOption( `${$new} .dze-diag-field`, 'product.description' );
	await page.waitForTimeout( 150 );
	ok( 'a word count offers them too',     await page.isVisible( `${$new} .dze-diag-cond` ), true );
	ok( 'counting what the rule counts',
		( await page.textContent( `${$new} .dze-diag-bandrow:not(.dze-diag-bandproto) .dze-diag-bandunit` ) ).trim(), 'words' );
	// A rule with no figure at all has nothing to put in tiers.
	await page.selectOption( `${$new} .dze-diag-test`, 'empty' );
	await page.waitForTimeout( 150 );
	ok( 'a rule with no figure offers none', await page.isVisible( `${$new} .dze-diag-cond` ), false );
	await page.selectOption( `${$new} .dze-diag-test`, 'lt' );
	await page.waitForTimeout( 150 );
	ok( 'and they come back with one',      await page.isVisible( `${$new} .dze-diag-cond` ), true );
	ok( 'nothing was raised on the way', errors, [] );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
