/**
 * Content to review, MEASURED, on both jQuery builds.
 *
 * Run before every release:  node tools/js/review-table.mjs
 *
 * "Écran cassé." Two columns were added to this table — who started the work
 * and when it last moved — and the screen came back with its headings stacked
 * one letter per line and its buttons running off the side of the page.
 *
 * The fault is arithmetic that no PHP test and no `node --check` can see. The
 * table is laid out FIXED (WordPress's own `.fixed`) and two of its columns are
 * a fixed number of pixels — the tick box and the id — so every percentage
 * spent on the others comes out of the same width. 80% of percentages left the
 * LAST column with "the rest minus 123px", which is nearly nothing on a
 * narrower window: the buttons in it stacked, the rows grew to three lines
 * each, and the table overflowed its own page.
 *
 * So this gate does what only a browser can do: it draws the real screen at
 * several widths and MEASURES it — the table inside its page, every heading
 * over its own cell, the date on one line, the buttons beside each other.
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
// The plugin's own words, key by key: retyped here, the gate proves nothing.
const cfg = Object.assign( {}, dumped.cfg, { ajaxUrl: 'http://dze.test/ajax' } );

// WordPress's own list table, as much of it as the layout depends on. `.fixed`
// is the class that makes the table FIXED, which is the whole reason a column
// can be starved by its neighbours.
const wpcss = `
	body { margin: 0; font: 13px/1.4em -apple-system, sans-serif; }
	#wpbody-content { padding: 0 20px; box-sizing: border-box; }
	.wp-list-table { width: 100%; border-collapse: collapse; clear: both; }
	.wp-list-table.fixed { table-layout: fixed; }
	.wp-list-table th, .wp-list-table td { padding: 8px 10px; text-align: left; vertical-align: top; }
	.wp-list-table thead th { font-weight: 600; }
	.button, .button-small { display: inline-block; padding: 0 8px; line-height: 22px;
		border: 1px solid #2271b1; background: #f6f7f7; border-radius: 3px; white-space: nowrap; }
	.check-column { width: 2.2em; }
`;

// Two rows of the shape the shop actually holds: a category with a short name
// and an article with a long one, both started by the pass that runs on its
// own, both waiting for a decision — which is the row that carries the most
// buttons and is therefore the one that breaks first.
const rows = [
	{ id: 11, label: 'Tactical backpack covers', oid: 6223, kind: 'Category internal links',
	  status: 'review', error: '', progress: '', who: '', from: 'Automatic', when: '13/09/2026 18:34' },
	{ id: 12, label: 'The sniper role: why are they so feared?', oid: 987632358, kind: 'Article internal links',
	  status: 'review', error: '', progress: '', who: '', from: 'Marie Dupont-Lefevre', when: '13/09/2026 20:25' }
];

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );

	await page.route( 'http://dze.test/ajax', route => route.fulfill( {
		contentType: 'application/json',
		body: JSON.stringify( { success: true, data: { rows, counts: { review: 2 } } } )
	} ) );
	await page.route( 'http://dze.test/screen', route => route.fulfill( { contentType: 'text/html', body:
		`<!doctype html><html><head><meta charset="utf-8"><style>${wpcss}</style><style>${css}</style>`
		+ `<script>${readFileSync( jq, 'utf8' )}</script>`
		+ `<script>window.ajaxurl='http://dze.test/ajax';window.dzeQueue=${JSON.stringify( cfg )};</script>`
		+ `</head><body><div id="wpbody-content">${dumped.html}</div>`
		+ `<script>${readFileSync( join( js, 'queue.js' ), 'utf8' )}</script></body></html>` } ) );

	// A NARROW WINDOW IS WHERE IT BROKE. The admin menu takes 160px of every
	// screen, so a 1280px laptop draws this table in about a thousand — and
	// that is the width the last column was starved at.
	for ( const width of [ 1040, 1280, 1600 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );
		const drawn = await page.waitForFunction(
			() => document.querySelectorAll( '#dze-q-table tbody tr' ).length >= 2,
			null, { timeout: 6000 } ).then( () => true ).catch( () => false );
		ok( `the list draws at ${width}px`, drawn, true );
		if ( ! drawn ) { continue; }

		// THE TABLE STAYS INSIDE ITS PAGE. It ran off the side, which is what
		// "écran cassé" was: every button of every row half cut off.
		const box = await page.evaluate( () => {
			const t = document.getElementById( 'dze-q-table' );
			const host = document.getElementById( 'wpbody-content' );
			return { table: Math.round( t.getBoundingClientRect().width ),
				host: Math.round( host.clientWidth ),
				doc: document.documentElement.scrollWidth,
				win: window.innerWidth };
		} );
		ok( `it fits the page at ${width}px`,      box.table <= box.host, true );
		ok( `and the page does not scroll sideways at ${width}px`, box.doc <= box.win, true );

		// EVERY HEADING OVER ITS OWN CELL. The head is printed by PHP and the
		// cells are built in the browser: they go a column out of step without
		// raising anything, and every row then prints its values under the
		// wrong titles.
		const cols = await page.evaluate( () => {
			const head = Array.from( document.querySelectorAll( '#dze-q-table thead tr > *' ) );
			const cells = Array.from( document.querySelectorAll( '#dze-q-table tbody tr:first-child > *' ) );
			return {
				heads: head.map( h => h.textContent.trim() ),
				n: [ head.length, cells.length ],
				aligned: head.every( ( h, i ) => cells[ i ]
					&& Math.abs( h.getBoundingClientRect().left - cells[ i ].getBoundingClientRect().left ) < 2 ),
				widths: head.map( h => Math.round( h.getBoundingClientRect().width ) )
			};
		} );
		ok( `head and cells count the same at ${width}px`, cols.n[0], cols.n[1] );
		ok( `and every cell sits under its heading at ${width}px`, cols.aligned, true );
		ok( `the headings read in order at ${width}px`, cols.heads.slice( 1 ),
			[ 'Item', 'ID', 'Job', 'Started by', 'When', 'Status', 'Action' ] );
		// NO COLUMN IS STARVED. The one that was left "the rest minus 123px"
		// came out at a few pixels, which is what stacked the buttons.
		ok( `no column is crushed at ${width}px`, Math.min( ...cols.widths.slice( 1 ) ) >= 70, true );

		// THE ROW READS AS ONE LINE OF WORK. A heading one letter per line and
		// a row three buttons tall is the screen he photographed.
		const shape = await page.evaluate( () => {
			const tr = document.querySelector( '#dze-q-table tbody tr' );
			const act = tr.querySelector( '.dze-q-act' );
			const when = tr.querySelector( '.dze-q-when' );
			const btns = Array.from( act.querySelectorAll( 'button' ) );
			const tops = btns.map( b => Math.round( b.getBoundingClientRect().top ) );
			return {
				headHeight: Math.round( document.querySelector( '#dze-q-table thead tr' ).getBoundingClientRect().height ),
				buttons: btns.length,
				onOneLine: tops.length > 1 ? Math.max( ...tops ) - Math.min( ...tops ) < 6 : true,
				// DOES THE DATE FIT ITS COLUMN? It cannot wrap — it is nowrap —
				// so the only way it goes wrong is by running out of its cell,
				// and a cell's own height is the ROW's, which says nothing.
				whenFits: ( () => {
					const r = document.createRange();
					r.selectNodeContents( when );
					const text = Math.ceil( r.getBoundingClientRect().width );
					const box = when.getBoundingClientRect().width
						- parseFloat( getComputedStyle( when ).paddingLeft )
						- parseFloat( getComputedStyle( when ).paddingRight );
					return text <= Math.ceil( box ) + 1;
				} )(),
				actFits: act.scrollWidth <= act.clientWidth + 1,
				actRight: Math.round( act.getBoundingClientRect().right ),
				tableRight: Math.round( document.getElementById( 'dze-q-table' ).getBoundingClientRect().right )
			};
		} );
		ok( `the row carries its three buttons at ${width}px`, shape.buttons, 3 );
		ok( `side by side at ${width}px`,          shape.onOneLine, true );
		ok( `so does the heading at ${width}px`,   shape.headHeight <= 60, true );
		ok( `the date fits its column at ${width}px`,  shape.whenFits, true );
		ok( `and the buttons fit theirs at ${width}px`, shape.actFits, true );
		ok( `and the actions end inside the table at ${width}px`,
			shape.actRight <= shape.tableRight + 1, true );
	}
	ok( 'nothing was raised drawing it', errors, [] );
	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
