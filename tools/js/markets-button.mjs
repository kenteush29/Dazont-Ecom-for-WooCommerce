/**
 * "Write it for my other markets", clicked twice.
 *
 * Run before every release:  node tools/js/markets-button.mjs
 *
 * The button filled only the EMPTY market lines. So a shop that wrote its
 * title, pressed it, then changed the title and pressed it again kept the
 * four lines written for the old title — four markets announcing a promotion
 * that no longer exists, on a screen that said "Written". No PHP test can see
 * that: it only exists on the second click.
 *
 * The automatic pass at save time is the opposite and stays so: it fills the
 * gaps and never touches a line somebody typed. Both are checked here.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );
const npm  = process.env.DZE_JQ_DIR || join( here, 'node_modules' );
if ( ! existsSync( join( npm, 'jquery/dist/jquery.min.js' ) ) ) {
	execFileSync( 'npm', [ 'install', '--silent', '--no-audit', '--no-fund',
		'jquery@4', 'jquery-3@npm:jquery@3.7.1' ], { cwd: here, stdio: 'inherit' } );
}
const jqs = [ [ '3.7.1', join( npm, 'jquery-3/dist/jquery.min.js' ) ], [ '4.0.0', join( npm, 'jquery/dist/jquery.min.js' ) ] ];

let fails = 0, ran = 0;
/** Waits for what SHOULD happen, and lets the check report it when it does not. */
async function settle( page, fn ) {
	try { await page.waitForFunction( fn, null, { timeout: 4000 } ); } catch ( e ) { /* the check below says so */ }
}
function ok( what, got, want ) {
	ran++;
	if ( JSON.stringify( got ) === JSON.stringify( want ) ) { console.log( `  ok    ${what}` ); return; }
	fails++;
	console.log( `  FAIL  ${what}\n          got  ${JSON.stringify( got )}\n          want ${JSON.stringify( want )}` );
}

// The fields as the partial draws them: the title above, one line per market.
const screen = `
<input type="text" id="dze-title" value="Back to School Sale" />
<button type="button" class="button-link" id="dze-banner-translate">Write it for my other markets</button>
<span id="dze-banner-tr-status"></span>
<details id="dze-banner-i18n">
  <input type="text" class="dze-banner-i18n-field" data-lang="fr" value="" />
  <input type="text" class="dze-banner-i18n-field" data-lang="de" value="" />
  <input type="text" class="dze-banner-i18n-field" data-lang="es" value="" />
</details>`;

const js = readFileSync( join( root, 'dazont-ecom/admin/js/discounts.js' ), 'utf8' );

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	const page = await browser.newPage();
	const errors = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );

	// What the model answers depends on the title it was sent, so a second
	// click on a changed title can be told apart from a first one.
	await page.route( 'http://dze.test/**', route => {
		const url = route.request().url();
		if ( url.endsWith( '/jquery.js' ) ) {
			return route.fulfill( { status: 200, contentType: 'text/javascript', body: readFileSync( jq, 'utf8' ) } );
		}
		if ( url.endsWith( '/discounts.js' ) ) {
			return route.fulfill( { status: 200, contentType: 'text/javascript', body: js } );
		}
		if ( url.includes( '/ajax' ) ) {
			const sent = new URLSearchParams( route.request().postData() || '' );
			const title = sent.get( 'title' ) || '';
			const mark  = title.includes( 'Winter' ) ? 'WINTER' : 'SCHOOL';
			return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( {
				success: true, data: { i18n: { fr: `FR ${mark}`, de: `DE ${mark}`, es: `ES ${mark}` } },
			} ) } );
		}
		return route.fulfill( { status: 200, contentType: 'text/html',
			body: `<!doctype html><html><head><meta charset="utf-8">`
				+ `<script src="/jquery.js"></script>`
				+ `<script>window.dzeDiscounts={ajaxUrl:'http://dze.test/ajax',maiNonce:'n',i18n:{`
				+ `titleFirst:'Write the title first.',translating:'Writing…',`
				+ `translated:'Written — check them, then save.',`
				+ `rewrote:'Rewritten in %d languages — check them, then save.',`
				+ `trFailed:'Could not write them.'}};</script>`
				+ `</head><body>${screen}<script src="/discounts.js"></script></body></html>` } );
	} );
	await page.goto( 'http://dze.test/' );

	console.log( `The markets button, in a browser — jQuery ${label}` );
	const lines = () => page.evaluate( () => Array.from( document.querySelectorAll( '.dze-banner-i18n-field' ) ).map( i => i.value ) );

	await page.click( '#dze-banner-translate' );
	await settle( page, () => document.querySelector( '.dze-banner-i18n-field' ).value !== '' );
	ok( 'the first click fills every market', await lines(), [ 'FR SCHOOL', 'DE SCHOOL', 'ES SCHOOL' ] );

	// The title changes, and the button is pressed again. THIS is the bug.
	await page.fill( '#dze-title', 'Winter Sale' );
	await page.click( '#dze-banner-translate' );
	await settle( page, () => document.querySelector( '.dze-banner-i18n-field' ).value.includes( 'WINTER' ) );
	ok( 'the second click REPLACES them all', await lines(), [ 'FR WINTER', 'DE WINTER', 'ES WINTER' ] );
	ok( 'and says how many it rewrote',
		( await page.textContent( '#dze-banner-tr-status' ) ).trim(),
		'Rewritten in 3 languages — check them, then save.' );

	// A line typed by hand is replaced too — pressing the button is asking for
	// it. What must never overwrite a typed line is the automatic pass at save
	// time, which is not this button.
	await page.fill( '.dze-banner-i18n-field[data-lang="de"]', 'Meine eigene Zeile' );
	await page.fill( '#dze-title', 'Back to School Sale' );
	await page.click( '#dze-banner-translate' );
	await settle( page, () => document.querySelector( '.dze-banner-i18n-field' ).value.includes( 'SCHOOL' ) );
	ok( 'a hand-written line gives way to the ask', await lines(), [ 'FR SCHOOL', 'DE SCHOOL', 'ES SCHOOL' ] );

	// An empty title is refused, and says why, rather than asking for nothing.
	await page.fill( '#dze-title', '' );
	await page.click( '#dze-banner-translate' );
	await page.waitForTimeout( 200 );
	ok( 'an empty title is refused',
		( await page.textContent( '#dze-banner-tr-status' ) ).trim(), 'Write the title first.' );

	ok( 'nothing was raised in the page', errors, [] );
	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
