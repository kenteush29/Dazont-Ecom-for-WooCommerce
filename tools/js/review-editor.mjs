/**
 * The review popup's editor, on the document it must never touch.
 *
 * Run before every release:  node tools/js/review-editor.mjs
 *
 * Three articles on kula-tactical.com lost content in one day. The first was
 * the model's — a truncated answer — and is guarded where the text is made.
 * The other two were damaged HOURS after every production guard had passed
 * them, in this popup: it initialises TinyMCE with `wpautop: true` over
 * whatever the job holds, and a WordPress article handed to a visual editor
 * comes back with a `<p>` wrapped round every `<!-- wp: -->` delimiter. Same
 * words, same links, same blocks by name, MORE paragraphs — and every block in
 * the editor invalid, the pictures gone from the body.
 *
 * Nothing in PHP can see that: the damage happens in the browser, between
 * opening the popup and pressing Accept. So this gate presses exactly that, on
 * both jQuery builds, and reads what goes on the wire.
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

const dumped = JSON.parse( execFileSync( 'php',
	[ join( here, '..', 'test-review.php' ), 'dazont-ecom', '--dump-review' ],
	{ encoding: 'utf8', cwd: root, stdio: [ 'ignore', 'pipe', 'ignore' ] } ) );
const cfg = Object.assign( {}, dumped.cfg, { ajaxUrl: 'http://dze.test/ajax' } );

// A WordPress article as the shop actually stores one: delimiters on their own
// lines, blank lines between blocks, a picture inside an image block.
const ARTICLE = [
	'<!-- wp:paragraph -->',
	'<p>A sniper <a href="https://kula.test/how-sniper-works">waits</a> a long time.</p>',
	'<!-- /wp:paragraph -->',
	'',
	'<!-- wp:image {"id":91} -->',
	'<figure class="wp-block-image"><img src="https://kula.test/ghillie.jpg" alt="ghillie"/></figure>',
	'<!-- /wp:image -->',
	'',
	'<!-- wp:heading -->',
	'<h2>How good are snipers?</h2>',
	'<!-- /wp:heading -->'
].join( '\n' );
// And a category description, which is what the rich editor was added for.
const PLAIN = '<p>Tactical rugs, <a href="https://kula.test/jute">jute</a> and cotton.</p>';

const wpcss = `
	*, *::before, *::after { box-sizing: border-box; }
	body { margin: 0; font: 13px/1.4em -apple-system, sans-serif; }
	#wpbody-content { padding: 0 20px; }
	.wp-list-table { width: 100%; border-collapse: collapse; }
	.wp-list-table th, .wp-list-table td { padding: 8px 10px; text-align: left; vertical-align: top; }
	.button { display: inline-block; padding: 0 8px; line-height: 22px; border: 1px solid #2271b1; }
	.check-column { width: 2.2em; }
`;

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [], sent = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );
	page.on( 'dialog', d => d.accept() );

	// The row the popup opens from, and the document it is about. `holds` is
	// swapped between the two halves of this gate.
	let holds = ARTICLE;
	await page.route( 'http://dze.test/ajax', route => {
		const q = new URLSearchParams( route.request().postData() || '' );
		const action = q.get( 'action' );
		sent.push( { action, html: q.get( 'html' ), accept: q.get( 'accept' ) } );
		const json = d => route.fulfill( { contentType: 'application/json',
			body: JSON.stringify( { success: true, data: d } ) } );
		if ( 'dze_q_review' === action ) {
			return json( { id: 11, html: holds, current: '', words: [ 10, 12 ], links: [ 1, 2 ] } );
		}
		if ( 'dze_q_decide' === action ) { return json( { status: 'applied' } ); }
		// ONE ROW WAITING FOR A DECISION — the row the popup opens from.
		return json( { rows: [ {
			id: 11, label: 'The sniper role: why are they so feared?', oid: 987632358,
			kind: 'Article internal links', status: 'review', error: '', progress: '',
			who: '', from: 'Automatic', when: '14/09/2026 20:18'
		} ], counts: { review: 1 } } );
	} );

	await page.route( 'http://dze.test/screen', route => route.fulfill( { contentType: 'text/html', body:
		`<!doctype html><html><head><meta charset="utf-8"><style>${wpcss}</style><style>${css}</style>`
		+ `<script>${readFileSync( jq, 'utf8' )}</script>`
		+ `<script>window.ajaxurl='http://dze.test/ajax';window.dzeQueue=${JSON.stringify( cfg )};`
		// WORDPRESS'S OWN EDITOR, faked exactly where it hurts: it records that
		// it was asked, and it MANGLES the textarea the way TinyMCE does. So a
		// popup that hands a block document to the rich editor cannot pass this
		// gate by accident — the damage is really in the box when Accept reads
		// it, which is what happened on the shop.
		+ `window.__inits=[];window.wp={editor:{remove:function(){},initialize:function(id){`
		+ `window.__inits.push(id);var el=document.getElementById(id);`
		+ `if(el){el.value=el.value.replace(/(<!--\\s*\\/?wp:[^>]*-->)/g,'<p>$1</p>');}`
		+ `}}};</script>`
		+ `</head><body><div id="wpbody-content">${dumped.html}`
		+ `<div id="dze-q-modal"><h2 id="dze-q-title"></h2><div id="dze-q-body"></div></div></div>`
		// The rows are drawn with the shared machinery, so it has to be on the
		// page: without hub.js the first row dies on `window.dzeHub` and there
		// is nothing left to press.
		+ `<script>${readFileSync( join( js, 'hub.js' ), 'utf8' )}</script>`
		+ `<script>${readFileSync( join( js, 'queue.js' ), 'utf8' )}</script></body></html>` } ) );

	// ---- A WORDPRESS ARTICLE ----
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );
	// The rows are drawn in the browser from the queue's own answer, so the
	// button to press does not exist until that lands.
	await page.waitForSelector( '.dze-q-open', { timeout: 6000 } ).catch( () => {} );
	await page.click( '.dze-q-open' );
	const drew = await page.waitForSelector( '#dze-q-editor', { timeout: 6000 } )
		.then( () => true ).catch( () => false );
	ok( 'the popup draws its editor', drew, true );
	if ( drew ) {
		// THE WHOLE OF IT: a visual editor may never be given a block document.
		ok( 'no rich editor is put over a WordPress article',
			await page.evaluate( () => window.__inits.slice() ), [] );
		ok( 'and the article is in the box exactly as it came',
			await page.inputValue( '#dze-q-editor' ), ARTICLE );
		// AND WHAT ACCEPT PUTS ON THE WIRE IS THAT DOCUMENT, byte for byte.
		const was = sent.length;
		await page.click( '.dze-q-accept' );
		await page.waitForTimeout( 500 );
		const decided = sent.slice( was ).filter( r => 'dze_q_decide' === r.action );
		ok( 'accept sends one decision',        decided.length, 1 );
		ok( 'carrying the article untouched',   ( decided[ 0 ] || {} ).html, ARTICLE );
		ok( 'with no delimiter wrapped',
			/<p>\s*<!--\s*\/?wp:/.test( ( decided[ 0 ] || {} ).html || '' ), false );
	}

	// ---- AND A CATEGORY DESCRIPTION KEEPS THE EDITOR IT WAS GIVEN ----
	// The rich editor is not the fault and is not being taken away: a plain
	// HTML description is what it was added for.
	holds = PLAIN;
	await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.dze-q-open', { timeout: 6000 } ).catch( () => {} );
	await page.click( '.dze-q-open' );
	const drew2 = await page.waitForSelector( '#dze-q-editor', { timeout: 6000 } )
		.then( () => true ).catch( () => false );
	ok( 'the popup draws it for a description too', drew2, true );
	if ( drew2 ) {
		ok( 'and a plain description still gets the rich editor',
			await page.evaluate( () => window.__inits.slice() ), [ 'dze-q-editor' ] );
	}

	ok( 'nothing was raised doing it', errors, [] );
	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
