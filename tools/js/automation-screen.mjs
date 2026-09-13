/**
 * The Automation screen, READ THE WAY IT IS READ, on both jQuery builds.
 *
 * Run before every release:  node tools/js/automation-screen.mjs
 *
 * "L'écran automatisation est à revoir pour une UX optimale. C'est très
 * brutal, vulgaire, avec énormément de texte de partout. Je suis perdu et
 * désorienté quand je vois ça… Si un module est bien fait, en général, il
 * n'est pas nécessaire d'ajouter du texte partout. La simple présence d'un
 * bouton doit parler d'elle-même."
 *
 * So the thing to hold is not "does it work" — the PHP gate answers that — but
 * WHAT IT LOOKS LIKE WHEN IT OPENS, and only a browser can answer that:
 *
 *   - three tasks are three LINES, shut, not three screens of prose;
 *   - the figures are readable WITHOUT opening anything, each on one line;
 *   - opening one gives the controls, and closing it takes them away again;
 *   - the button that runs it moves the line it was pressed on, so a figure
 *     never answers for the page as it was opened.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );
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

const dumped = JSON.parse( execFileSync( 'php',
	[ join( here, '..', 'test-automation.php' ), 'dazont-ecom', '--dump-automation' ],
	{ encoding: 'utf8', cwd: root, stdio: [ 'ignore', 'pipe', 'ignore' ] } ) );

// WordPress's own admin, as much of it as this screen's shape depends on.
const wpcss = `
	body { margin: 0; font: 13px/1.4em -apple-system, sans-serif; background: #f0f0f1; }
	#wpbody-content { padding: 20px; box-sizing: border-box; }
	.button { display: inline-block; padding: 0 10px; line-height: 26px; border: 1px solid #2271b1;
		background: #f6f7f7; border-radius: 3px; white-space: nowrap; }
	.description { color: #646970; }
	.dashicons { display: inline-block; width: 20px; height: 20px; }
	.small-text { width: 60px; }
`;

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [], sent = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );

	await page.route( 'http://dze.test/ajax', route => {
		const q = new URLSearchParams( route.request().postData() || '' );
		sent.push( { action: q.get( 'action' ), task: q.get( 'task' ), nonce: q.get( 'nonce' ) } );
		return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
			queued: 1,
			task: q.get( 'task' ),
			message: 'Queued — the writing queue does it in the background.',
			state: '<p class="dze-auto-next">Next in line: Boonie hats</p>',
			// The answer carries the LINE as it now stands, not only the body.
			chips: '<span class="dze-auto-chips" data-task="' + q.get( 'task' ) + '">'
				+ '<span class="dze-auto-chip is-on"><span class="dashicons dashicons-controls-play"></span>3 a day</span>'
				+ '<span class="dze-auto-chip is-wait"><span class="dashicons dashicons-visibility"></span>4</span>'
				+ '</span>',
			log: '<ul><li>Internal linking · Boonie hats</li></ul>'
		} } ) } );
	} );
	await page.route( 'http://dze.test/screen', route => route.fulfill( { contentType: 'text/html', body:
		`<!doctype html><html><head><meta charset="utf-8"><style>${wpcss}</style><style>${css}</style>`
		+ `<script>${readFileSync( jq, 'utf8' )}</script>`
		+ `<script>window.ajaxurl='http://dze.test/ajax';</script>`
		+ `</head><body><div id="wpbody-content">${dumped.html}</div></body></html>` } ) );

	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );

	// ---- THREE TASKS ARE THREE LINES ----
	const shut = await page.evaluate( () => {
		const blocks = Array.from( document.querySelectorAll( '.dze-auto-task' ) );
		return {
			n: blocks.length,
			open: blocks.filter( b => b.open ).length,
			heights: blocks.map( b => Math.round( b.getBoundingClientRect().height ) ),
			// What the screen SAYS when it opens, with everything folded.
			words: document.body.innerText.replace( /\s+/g, ' ' ).trim().length
		};
	} );
	ok( 'three tasks, three blocks',        shut.n, 3 );
	ok( 'and every one of them shut',       shut.open, 0 );
	ok( 'each reading as one line',         shut.heights.every( h => h <= 60 ), true );
	// A SCREEN THAT OPENS ON A WALL OF TEXT is the screen he photographed. With
	// everything folded this one is names, figures and two buttons.
	ok( 'the screen opens on very little',  shut.words <= 400, true );

	// ---- THE FIGURES ARE READ WITHOUT OPENING ANYTHING ----
	// Reported, never thrown: a gate that dies on the thing it is about says
	// nothing about the checks after it.
	const chips = await page.evaluate( () => {
		const first = document.querySelector( '.dze-auto-task .dze-auto-chips' );
		if ( ! first ) { return { visible: false, kinds: [], text: [], titled: false, oneLine: false }; }
		const all = Array.from( first.querySelectorAll( '.dze-auto-chip' ) );
		return {
			visible: !! first.offsetParent,
			kinds: all.map( c => c.className.replace( 'dze-auto-chip ', '' ) ),
			text: all.map( c => c.textContent.trim() ),
			titled: all.every( c => ( c.getAttribute( 'title' ) || '' ).length > 3 ),
			oneLine: new Set( all.map( c => Math.round( c.getBoundingClientRect().top ) ) ).size === 1
		};
	} );
	ok( 'the figures are there while shut', chips.visible, true );
	ok( 'the rhythm, the waiting, the written and the next look',
		chips.kinds, [ 'is-on', 'is-wait', 'is-done', 'is-next' ] );
	ok( 'with the figures on them',         chips.text.slice( 0, 3 ), [ '3 a day', '3', '14' ] );
	ok( 'each one carrying its own word',   chips.titled, true );
	ok( 'and all of them on one line',      chips.oneLine, true );
	// A TASK THAT IS OFF SAYS ONLY THAT.
	const second = await page.evaluate( () => {
		const block = document.querySelectorAll( '.dze-auto-task' )[1];
		if ( ! block ) { return [ 'no second task on the screen' ]; }
		return Array.from( block.querySelectorAll( '.dze-auto-chip' ) )
			.map( c => c.className.replace( 'dze-auto-chip ', '' ) );
	} );
	ok( 'a task that is off says so, and nothing else', second, [ 'is-off' ] );

	// ---- OPENING ONE GIVES THE CONTROLS ----
	ok( 'the controls are out of the way',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-controls' ).isVisible().catch( () => false ), false );
	await page.click( '.dze-auto-task:first-of-type > summary', { timeout: 3000 } ).catch( () => {} );
	ok( 'opening it shows them',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-controls' ).isVisible(), true );
	ok( 'with the button that runs it',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-run' ).isVisible(), true );
	ok( 'and what it is about to take',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-next' ).isVisible(), true );
	ok( 'the way to what it left you too',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-waiting a' ).isVisible(), true );

	// ---- AND THE PRESS MOVES THE LINE IT WAS PRESSED ON ----
	const was = sent.length;
	await page.click( '.dze-auto-task:first-of-type .dze-auto-run', { timeout: 3000 } ).catch( () => {} );
	const answered = await page.waitForFunction(
		() => /4/.test( ( document.querySelector( '.dze-auto-task .dze-auto-chip.is-wait' ) || {} ).textContent || '' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	ok( 'the press asks the server',        ( sent[ was ] || {} ).action, 'dze_auto_run' );
	ok( 'naming the task it belongs to',    ( sent[ was ] || {} ).task, 'mesh_links' );
	ok( 'with its nonce',                   ( ( sent[ was ] || {} ).nonce || '' ).length > 0, true );
	ok( 'and the line says what changed',   answered, true );
	ok( 'the answer is said in words too',
		( await page.textContent( '.dze-auto-task:first-of-type .dze-auto-msg' ).catch( () => '' ) || '' ).includes( 'Queued' ), true );

	ok( 'nothing was raised reading it',    errors, [] );
	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
