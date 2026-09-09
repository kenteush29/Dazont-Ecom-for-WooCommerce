/**
 * The Linking tab's buttons, PRESSED, in a browser, on both jQuery builds.
 *
 * Run before every release:  node tools/js/mesh-linking.mjs
 *
 * A control is tested on what it DOES, never on the fact that it exists. This
 * screen's whole promise is that a press OPENS a choice rather than running
 * one — the fault that had to be fixed on the diagnostic screen after "rien
 * de contrôlable visible à l'appui sur le bouton" — so what is proved here is:
 *
 *   - "Link to it" makes ONE request, for the row it sits on, and writes
 *     nothing anywhere;
 *   - what comes back is a choice, ticked but not sent, with the reason each
 *     page was kept;
 *   - only then does a press send, and it sends the pages that are TICKED —
 *     unticking one has to change what travels, or the boxes are decoration;
 *   - the page never navigates: a list of nine hundred rows is not thrown
 *     away to answer a question about one of them.
 *
 * The markup is the plugin's own, rendered by tools/test-mesh.php, never a
 * copy written into this file and left to drift.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );
const js   = join( root, 'dazont-ecom', 'admin', 'js' );
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

const tab = execFileSync( 'php', [ join( here, '..', 'test-mesh.php' ), 'dazont-ecom', '--dump-tab' ],
	{ encoding: 'utf8', cwd: root } );

const i18n = {
	ajax: 'http://dze.test/ajax',
	nonce: 'n0nce',
	reading: 'Reading the site…',
	read: 'Read the site again',
	looking: 'Looking for the pages that belong next to it…',
	sending: 'Sending…',
	sent: 'Sent to the writing queue. Nothing is on the site yet: the text is written there, then waits for your yes or no.',
	reviewGo: 'Content to review ↗',
	reviewUrl: 'http://dze.test/wp-admin/admin.php?page=dazont-ecom-diagnostic&tab=review',
	nopick: 'Tick at least one page.',
	none: 'No page on this site is close enough to link to it.',
	words: 'Chosen on wording alone — the writing key is not set, so nothing read these pages.',
	failed: 'That did not go through. Try again.',
	add: 'Send them to the writing queue'
};

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const sent = [];
	let navigated = 0;
	page.on( 'framenavigated', f => { if ( f === page.mainFrame() ) { navigated++; } } );

	// The plugin's own requests, answered here so nothing needs a shop. Every
	// one of them is kept: what a button ASKS is half of what it does.
	await page.route( '**/ajax**', async route => {
		const body = route.request().postData() || '';
		const q = new URLSearchParams( body );
		sent.push( { action: q.get( 'action' ), nonce: q.get( 'nonce' ), key: q.get( 'key' ), to: q.get( 'to' ), from: q.getAll( 'from[]' ) } );
		let data = { sent: 1 };
		if ( 'dze_mesh_pairs' === q.get( 'action' ) ) {
			data = { how: 'read', rows: [
				{ key: 'post:21', title: 'Boonie hat sizing', url: 'https://kula.test/blog/21/', kind: 'post', why: 'both about boonie hats' },
				{ key: 'product_cat:12', title: 'Boonie hats', url: 'https://kula.test/category/boonie-hats/', kind: 'product_cat', why: 'the aisle it belongs to' }
			] };
		}
		await route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data } ) } );
	} );

	// Served at a real address, not put there with setContent: a page with no
	// URL cannot be reloaded, so a screen that reloads would slip past this
	// gate exactly the way the toolbox's own reload slipped past the last one.
	await page.route( 'http://dze.test/other', async route => {
		await route.fulfill( { contentType: 'text/html', body: '<!doctype html><html><body>elsewhere</body></html>' } );
	} );
	await page.route( 'http://dze.test/screen', async route => {
		await route.fulfill( { contentType: 'text/html', body: `<!doctype html><html><body><div class="wrap">${tab}</div></body></html>` } );
	} );
	await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );
	await page.addScriptTag( { path: jq } );
	await page.evaluate( cfg => { window.dzeMesh = cfg; }, i18n );
	await page.addScriptTag( { path: join( js, 'mesh.js' ) } );
	await page.evaluate( () => jQuery( document ).trigger( 'ready' ) );
	// Where the page stands before anything is pressed. Nothing on this screen
	// may move it: a reload to answer "did that work?" throws away nine
	// hundred rows, their sort, their page and their scroll.
	const stood = navigated;
	// A mark on the window itself. `framenavigated` never fires for a page put
	// there with setContent, so a reload would slip straight past it; a value
	// only this run put there does not survive one.
	await page.evaluate( () => { window.__dzeStood = 'here'; } );
	const still = async () => await page.evaluate( () => window.__dzeStood || 'gone' );

	const firstRow = page.locator( '#dze-mesh-needs tbody tr' ).first();
	const key = await firstRow.getAttribute( 'data-key' );
	await firstRow.locator( '.dze-mesh-pairs' ).click();
	await page.waitForSelector( '.dze-mesh-panel .dze-mesh-list' );

	ok( 'the press asks once', sent.length, 1 );
	ok( 'for the pages that should link to THIS row', sent[0].action + ' ' + sent[0].key, 'dze_mesh_pairs ' + key );
	ok( 'and it carries its nonce', sent[0].nonce, 'n0nce' );
	ok( 'nothing was written', sent.filter( s => 'dze_mesh_queue' === s.action ).length, 0 );
	ok( 'the choice is on screen', await page.locator( '.dze-mesh-panel input[type=checkbox]' ).count(), 2 );
	ok( 'each page says why it is there',
		( await page.locator( '.dze-mesh-panel' ).innerText() ).includes( 'both about boonie hats' ), true );
	ok( 'and the page did not move', [ navigated, await still() ], [ stood, 'here' ] );

	// THE TICKS DECIDE WHAT TRAVELS. A box that changes nothing is decoration,
	// and a screen full of decoration is a screen nobody trusts.
	await page.locator( '.dze-mesh-panel input[type=checkbox]' ).first().uncheck();
	await page.locator( '.dze-mesh-send' ).click();
	// Waiting for the LINE to change is waiting for nothing: "Sending…" is
	// already in it. What is being waited for is the answer landing.
	// A screen that walked away answers nothing, and waiting for an answer on
	// it is a test that dies of a timeout instead of naming what went wrong.
	const answered = await page.waitForFunction( busy => {
		const el = document.querySelector( '.dze-mesh-said' );
		return el && el.textContent.trim() && el.textContent.trim() !== busy;
	}, i18n.sending, { timeout: 5000 } ).then( () => true ).catch( () => false );
	if ( ! answered ) {
		ok( 'the screen answered where it stood', await still(), 'here' );
		await page.close();
		continue;
	}
	const queued = sent.filter( s => 'dze_mesh_queue' === s.action );
	ok( 'the press sends once', queued.length, 1 );
	ok( 'naming the page that is short', queued[0].to, key );
	ok( 'and only the pages still ticked', queued[0].from, [ 'product_cat:12' ] );
	// WHAT THE PRESS DID, IN FULL. "Que se passe-t-il quand je clique sur Place
	// the selected links ? J'aimerais voir le résultat avant qu'il soit
	// appliqué." Nothing is written on the site by this press — the row has to
	// say that, and offer the way to the text rather than naming a tab to go
	// and find.
	ok( 'the row says what happened',
		( await page.locator( '.dze-mesh-said' ).innerText() ).includes( i18n.sent ), true );
	ok( 'and that nothing is on the site yet',
		( await page.locator( '.dze-mesh-said' ).innerText() ).includes( 'Nothing is on the site yet' ), true );
	// A LINK IS TESTED ON ITS DESTINATION — and asked for with a bound, so a
	// row that offers none is REPORTED rather than killing the run on a
	// thirty-second wait for something that is not coming.
	const said = page.locator( '.dze-mesh-said a' );
	const at = async attr => said.getAttribute( attr, { timeout: 3000 } ).catch( () => 'the row offered no link' );
	ok( 'it offers the way to what was produced', await at( 'href' ), i18n.reviewUrl );
	ok( 'in a new tab, so this reading is not lost', await at( 'target' ), '_blank' );
	ok( 'the choice is gone once it is sent', await page.locator( '.dze-mesh-send' ).count(), 0 );
	ok( 'and the page still did not move', [ navigated, await still() ], [ stood, 'here' ] );

	// A press with nothing ticked SAYS SO rather than sending an empty ask.
	const second = page.locator( '#dze-mesh-needs tbody tr' ).nth( 2 );
	await second.locator( '.dze-mesh-pairs' ).click();
	await page.waitForSelector( '#dze-mesh-needs tbody tr:nth-child(4) .dze-mesh-list' );
	const panel = page.locator( '.dze-mesh-panel' ).last();
	for ( const box of await panel.locator( 'input[type=checkbox]' ).all() ) { await box.uncheck(); }
	const before = sent.length;
	await panel.locator( '.dze-mesh-send' ).click();
	ok( 'an empty choice sends nothing', sent.length, before );
	ok( 'and says why', await panel.locator( '.dze-mesh-said' ).innerText(), i18n.nopick );

	// A second press on the same row shuts the panel again: it is a look at
	// what would be done, not a step that has to be undone.
	await firstRow.locator( '.dze-mesh-pairs' ).click();
	ok( 'opening again shuts it', await page.locator( '.dze-mesh-panel' ).count(), 1 );

	// The other list: one press, one job, and the button is replaced by what
	// happened rather than left looking pressable.
	const end = page.locator( '#dze-mesh-ends tbody tr' ).first();
	const endKey = await end.getAttribute( 'data-key' );
	await end.locator( '.dze-mesh-out' ).click();
	await end.locator( '.dze-mesh-out' ).waitFor( { state: 'detached' } );
	const outs = sent.filter( s => 'dze_mesh_out' === s.action );
	ok( 'the linking pass is asked for that page', outs.length && outs[0].key, endKey );
	ok( 'and the row says so', await end.innerText().then( t => t.includes( i18n.sent ) ), true );
	ok( 'the page never moved', [ navigated, await still() ], [ stood, 'here' ] );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
