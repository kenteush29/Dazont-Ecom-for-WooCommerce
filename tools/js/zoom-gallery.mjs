/**
 * The image viewer, WALKED — in a real browser, on both jQuery builds.
 *
 * Run before every release:  node tools/js/zoom-gallery.mjs
 *
 * One viewer serves the whole plugin: the product photographs, the review
 * popup, the bulk screen, the paste box, the image lab, the explorer, the
 * email pictures, the GMC panel and the restock list all open
 * admin/js/hzoom.js. So one fault in it is a fault on every one of those
 * screens, and this is the gate that stands in front of all of them.
 *
 * The fault it was written for:
 *
 *   "J'ai agrandi l'image principale, et quand je switch sur d'autres images,
 *    rien n'indique que quoi que ce soit ne charge. Ça induit en erreur.
 *    L'image précédente reste à l'écran, et parfois même le compte (1/5-2/5)
 *    ne change même pas."
 *
 * The viewer set `src` on the visible <img> and moved the counter in the same
 * breath. A browser keeps painting the OLD photograph until the new one has
 * downloaded and decoded — a second or more on a full-size product shot — so
 * the screen said "2 / 5" over picture 1 with nothing saying anything was
 * happening. Neither `node --check` nor any PHP test can see that: it only
 * exists while an image is in flight, which is why the images here are served
 * SLOWLY on purpose.
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

const js  = readFileSync( join( root, 'dazont-ecom', 'admin', 'js', 'hzoom.js' ), 'utf8' );
const css = readFileSync( join( root, 'dazont-ecom', 'admin', 'css', 'zoom.css' ), 'utf8' );

// A one-pixel GIF, and a strip of five thumbnails pointing at five "full"
// images the harness serves slowly.
const dot = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
// Real tile sizes: a strip of 1x1 images collapses to nothing and the button
// planted over it has no room, which is a harness artefact and not the screen.
const strip = [ 1, 2, 3, 4, 5 ].map(
	n => `<span style="display:inline-block;width:90px;height:90px;margin:6px;">`
		+ `<img src="${dot}" data-full="http://dze.test/full-${n}.png" alt=""`
		+ ` style="width:100%;height:100%;object-fit:cover;background:#ddd;" /></span>`
).join( '' );

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	const page = await browser.newPage();
	const errors = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );

	// A real PNG, served after a delay. The delay is the whole point: it is
	// the window in which the viewer used to lie.
	const png = Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		'base64'
	);
	await page.route( 'http://dze.test/**', async route => {
		const url = route.request().url();
		if ( url.endsWith( '/' ) ) {
			return route.fulfill( { status: 200, contentType: 'text/html',
				body: `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style>`
					+ `<script src="/jquery.js"></script>`
					+ `<script>window.dzeZoomI18n={zoom:'Zoom',close:'Close',prev:'Previous',next:'Next',`
					+ `failed:'This image could not be loaded.'};</script>`
					+ `<script src="/hzoom.js"></script></head>`
					+ `<body><div class="dze-zoomgroup">${strip}</div></body></html>` } );
		}
		if ( url.endsWith( '/jquery.js' ) ) {
			return route.fulfill( { status: 200, contentType: 'text/javascript', body: readFileSync( jq, 'utf8' ) } );
		}
		if ( url.endsWith( '/hzoom.js' ) ) {
			return route.fulfill( { status: 200, contentType: 'text/javascript', body: js } );
		}
		// The image that never arrives: the viewer must say so rather than
		// leave the previous photograph up as though nothing were wrong.
		if ( url.endsWith( '/full-4.png' ) ) {
			return route.fulfill( { status: 404, contentType: 'text/plain', body: 'gone' } );
		}
		await new Promise( r => setTimeout( r, 400 ) );
		return route.fulfill( { status: 200, contentType: 'image/png', body: png } );
	} );
	await page.goto( 'http://dze.test/' );

	console.log( `The image viewer, walked — jQuery ${label}` );
	ok( 'the screen runs without an error', errors, [] );
	// The button belongs to the GRID, not to the markup that drew it.
	await page.waitForFunction( () => document.querySelectorAll( '.dze-zoom-btn' ).length === 5, null, { timeout: 3000 } );
	ok( 'every thumbnail gets its button',  await page.locator( '.dze-zoom-btn' ).count(), 5 );

	const state = () => page.evaluate( () => {
		const b = document.querySelector( '.dze-zoom-back' );
		return {
			count: ( b.querySelector( '.dze-zoom-count' ).textContent || '' ).trim(),
			src: b.querySelector( '.dze-zoom-img' ).getAttribute( 'src' ) || '',
			loading: b.classList.contains( 'is-loading' ),
			broken: b.classList.contains( 'is-broken' ),
			spinner: !! b.querySelector( '.dze-zoom-spin' ).offsetParent,
			shown: !! b.querySelector( '.dze-zoom-img' ).offsetParent,
			fail: ( b.querySelector( '.dze-zoom-fail' ).textContent || '' ).trim()
		};
	} );

	// ---- OPENING: it says it is working before it shows anything ----
	// The button only shows on hover, exactly as a person meets it.
	await page.hover( '.dze-zoomgroup span:nth-child(1)' );
	await page.click( '.dze-zoomgroup span:nth-child(1) .dze-zoom-btn', { force: true } );
	let s = await state();
	ok( 'it says at once that it is working', s.loading, true );
	ok( 'with a spinner you can see',        s.spinner, true );
	ok( 'and no picture under it',           s.src, '' );
	ok( 'the counter is already right',      s.count, '1 / 5' );
	await page.waitForFunction( () => ! document.querySelector( '.dze-zoom-back' ).classList.contains( 'is-loading' ), null, { timeout: 5000 } );
	s = await state();
	ok( 'then the picture arrives',          s.src, 'http://dze.test/full-1.png' );
	ok( 'the spinner goes with it',          s.spinner, false );
	ok( 'and the picture is on screen',      s.shown, true );

	// ---- WALKING: THE COUNTER AND THE PICTURE MOVE TOGETHER ----
	// This is the fault. The counter said 2 / 5 while picture 1 was still up.
	await page.click( '.dze-zoom-next' );
	s = await state();
	ok( 'the next one says it is working',   s.loading, true );
	ok( 'the counter has moved',             s.count, '2 / 5' );
	// NEVER the previous photograph under the new number.
	ok( 'and the old picture is NOT left up', s.src, '' );
	await page.waitForFunction( () => ! document.querySelector( '.dze-zoom-back' ).classList.contains( 'is-loading' ), null, { timeout: 5000 } );
	s = await state();
	ok( 'the picture that lands is the one counted', s.src, 'http://dze.test/full-2.png' );

	// ---- ONE ALREADY IN HAND SHOWS AT ONCE ----
	// Walking back to a picture the browser holds must not flash a spinner.
	await page.click( '.dze-zoom-prev' );
	s = await state();
	ok( 'a picture already held needs no wait', s.loading, false );
	ok( 'and is there immediately',          s.src, 'http://dze.test/full-1.png' );
	ok( 'with its own number',               s.count, '1 / 5' );

	// ---- CLICKING FASTER THAN THEY LOAD ----
	// Several in flight at once: whichever answered last used to win, so a
	// quick walk could settle on a picture that is not the one counted.
	await page.click( '.dze-zoom-next' ); // 2, held
	await page.click( '.dze-zoom-next' ); // 3, slow
	await page.click( '.dze-zoom-next' ); // 4, refused
	await page.click( '.dze-zoom-next' ); // 5, slow
	await page.waitForFunction( () => {
		const b = document.querySelector( '.dze-zoom-back' );
		return ! b.classList.contains( 'is-loading' );
	}, null, { timeout: 6000 } );
	await page.waitForTimeout( 700 ); // long enough for the overtaken ones to answer
	s = await state();
	ok( 'the walk settles where the counter says', s.count, '5 / 5' );
	ok( 'showing that very picture',         s.src, 'http://dze.test/full-5.png' );
	ok( 'and no overtaken picture wins',     s.broken, false );

	// ---- ONE THAT NEVER ARRIVES SAYS SO ----
	await page.click( '.dze-zoom-prev' ); // back to 4, the 404
	await page.waitForFunction( () => document.querySelector( '.dze-zoom-back' ).classList.contains( 'is-broken' ), null, { timeout: 5000 } );
	s = await state();
	ok( 'a picture that cannot load says so', s.fail, 'This image could not be loaded.' );
	ok( 'in the shop\'s own words',          s.broken, true );
	ok( 'and never leaves the last one up',  s.shown, false );
	ok( 'the counter still says which it is', s.count, '4 / 5' );

	// ---- CLOSING ----
	await page.click( '.dze-zoom-close' );
	s = await page.evaluate( () => {
		const b = document.querySelector( '.dze-zoom-back' );
		return { open: b.classList.contains( 'is-open' ), src: b.querySelector( '.dze-zoom-img' ).getAttribute( 'src' ) || '' };
	} );
	ok( 'closing shuts it',                  s.open, false );
	// A picture still on its way must not appear over a shut viewer.
	ok( 'and leaves nothing behind it',      s.src, '' );
	// The 404 above is this test's own: the picture that never arrives is the
	// point of it, and the browser says so on the console. What must not be
	// there is an error raised by the SCRIPT.
	ok( 'nothing was raised on the way',
		errors.filter( e => ! /404|Failed to load resource/.test( e ) ), [] );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
