/**
 * The category panel's button row, PRESSED, on both jQuery builds.
 *
 * Run before every release:  node tools/js/category-panel.mjs
 *
 * This screen had NO browser gate at all, and it is the screen where "✎
 * questions" and "✎ linking" were buttons with nothing behind them for
 * months. The panel is served by AJAX; `DZE_Prompts::button()` asks for its
 * popup by hooking `admin_footer`, which never fires in an AJAX request. So
 * the buttons arrived on a page holding neither the popup nor the handler
 * that opens it: pressing them did nothing and said nothing, and no PHP test
 * and no `node --check` could ever see it — the markup was perfect.
 *
 * What is proved here is a press: the popup opens, it asks the server for THAT
 * prompt by name, and what comes back lands on the screen.
 *
 * The panel and the popup are both rendered by the plugin's own PHP
 * (tools/test-category.php --dump-panel), never copied into this file.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );
const js   = join( root, 'dazont-ecom', 'admin', 'js' );
const css  = readFileSync( join( root, 'dazont-ecom', 'admin', 'css', 'content.css' ), 'utf8' );
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

const dumped = execFileSync( 'php', [ join( here, '..', 'test-category.php' ), 'dazont-ecom', '--dump-panel' ],
	{ encoding: 'utf8', cwd: root } );
const [ panel, modal ] = dumped.split( '<!--MODAL-->' );

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [];
	const sent = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );

	await page.route( 'http://dze.test/ajax', async route => {
		const q = new URLSearchParams( route.request().postData() || '' );
		sent.push( { action: q.get( 'action' ), nonce: q.get( 'nonce' ), id: q.get( 'id' ) } );
		await route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
			label: 'Prompt: ' + q.get( 'id' ),
			text: 'The instructions for ' + q.get( 'id' ) + '.',
			def: 'shipped', note: '', editable: true, own: true, mine: false, url: '',
			data: [ 'The category: its name, and the queries it targets.' ]
		} } ) } );
	} );

	// THE SCREEN AS THE PLUGIN ASSEMBLES IT: the panel dropped in by AJAX, and
	// the popup the page itself printed at load — which is exactly what was
	// missing.
	// jQuery FIRST, and `ajaxurl` with it: the popup ships its own inline
	// script, which runs the moment the browser parses it. Added afterwards —
	// the way a test is tempted to add it — the whole handler dies on
	// "jQuery is not defined" and every button on the page goes quiet, which
	// is the very failure this file exists to catch.
	await page.route( 'http://dze.test/screen', async route => {
		await route.fulfill( { contentType: 'text/html', body:
			`<!doctype html><html><head><meta charset="utf-8"><style>${css}</style>`
			+ `<script>${readFileSync( jq, 'utf8' )}</script>`
			+ `<script>window.ajaxurl='http://dze.test/ajax';</script></head>`
			+ `<body><div class="wrap"><div id="panel"></div></div>${modal}</body></html>` } );
	} );
	await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );
	// Only now does the panel arrive, the way an AJAX answer arrives — after
	// the page that will have to handle its buttons is already standing.
	await page.evaluate( html => { document.getElementById( 'panel' ).innerHTML = html; }, panel );

	ok( 'the screen runs without an error', errors, [] );
	ok( 'the popup is on the page before the panel is',
		await page.locator( '#dze-prompt-modal' ).count(), 1 );
	ok( 'and it is shut',                   await page.locator( '#dze-prompt-modal.is-open' ).count(), 0 );

	// A CONTROL IS TESTED ON WHAT IT DOES. Every one of these was on the page
	// and did nothing.
	for ( const [ name, id ] of [ [ '✎ prompt', 'cat_desc' ], [ '✎ questions', 'cat_sift' ], [ '✎ linking', 'cat_links' ] ] ) {
		const before = sent.length;
		await page.click( `#panel .dze-prompt-peek[data-prompt="${id}"]` );
		ok( `"${name}" opens the popup`,
			await page.locator( '#dze-prompt-modal.is-open' ).count(), 1 );
		const landed = await page.waitForFunction(
			want => ( document.querySelector( '#dze-prompt-text' ).value || '' ).includes( want ),
			id, { timeout: 4000 }
		).then( () => true ).catch( () => false );
		ok( 'and the answer lands on it',   landed, true );
		ok( `and asks the server for ${id}`, sent.slice( before ).map( s => s.action + ' ' + s.id ),
			[ 'dze_prompt_peek ' + id ] );
		// A REQUEST WHOSE ANSWER GOES NOWHERE IS THE SAME BROKEN BUTTON from
		// the shop's chair.
		ok( 'and what came back is on the screen',
			( await page.inputValue( '#dze-prompt-text' ) ).includes( id ), true );
		ok( 'named on the popup',           await page.textContent( '#dze-prompt-title' ), 'Prompt: ' + id );
		await page.click( '#dze-prompt-modal .dze-hub-close' );
		ok( 'and it shuts again',           await page.locator( '#dze-prompt-modal.is-open' ).count(), 0 );
	}

	// EVERY BUTTON IN THE ROW READS THE SAME WAY. It was a lone pencil, a lone
	// ⓘ and two worded buttons — three ways of saying "look at something".
	// The button ROW, which is what the eye reads as one row — not the picker
	// panel below it, which prints its own.
	const words = await page.evaluate( () => {
		const row = document.querySelector( '#panel .dze-cc-box > p' );
		return Array.from( row.parentNode.querySelectorAll( ':scope > p .dze-prompt-peek' ) ).map( b => b.textContent.trim() );
	} );
	ok( 'four controls, each carrying a word', words.length, 4 );
	ok( 'and none of them a bare symbol',   words.filter( w => ! /\s/.test( w ) ), [] );

	// "ⓘ what it uses" is a panel of this screen, not a popup: it shows what
	// the category is written from, in place.
	ok( 'what it is written from is folded away',
		await page.locator( '#panel .dze-cc-data:visible' ).count(), 0 );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
