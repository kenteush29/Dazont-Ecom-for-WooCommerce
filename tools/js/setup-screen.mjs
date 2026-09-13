/**
 * Setup, MEASURED — the one table shape that has broken this plugin before.
 *
 * Run before every release:  node tools/js/setup-screen.mjs
 *
 * "Écran cassé" was a table laid out FIXED whose columns were part pixels and
 * part percentages: every percentage is spent out of what the pixel columns
 * left, so the last one ends up with "the rest minus 164px", which is nearly
 * nothing on a narrow window. This screen is that same shape — a mark, two
 * columns of words and a button — so it is measured rather than trusted.
 *
 * And it measures the thing this screen is FOR: a shop that has just installed
 * the plugin must be able to read, at a glance and without opening anything,
 * how far it has got and what to press. So the blocks with work in them are
 * open, the finished ones are shut, and the whole screen stays short.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );
const css  = readFileSync( join( root, 'dazont-ecom', 'admin', 'css', 'content.css' ), 'utf8' );

let fails = 0, ran = 0;
function ok( what, got, want ) {
	ran++;
	if ( JSON.stringify( got ) === JSON.stringify( want ) ) { console.log( `  ok    ${what}` ); return; }
	fails++;
	console.log( `  FAIL  ${what}\n          got  ${JSON.stringify( got )}\n          want ${JSON.stringify( want )}` );
}

// THE SCREEN AS THE PLUGIN PRINTS IT, never a copy typed in here.
const html = execFileSync( 'php',
	[ join( here, '..', 'test-setup.php' ), 'dazont-ecom', '--dump-setup' ],
	{ encoding: 'utf8', cwd: root, stdio: [ 'ignore', 'pipe', 'ignore' ] } );

// WordPress's own list table, as much of it as the layout depends on. `.fixed`
// is what makes the table fixed, which is the whole reason a column can be
// starved by its neighbours.
const wpcss = `
	body { margin: 0; font: 13px/1.4em -apple-system, sans-serif; }
	#wpbody-content { padding: 0 20px; box-sizing: border-box; }
	.wp-list-table { width: 100%; border-collapse: collapse; clear: both; }
	.wp-list-table.fixed { table-layout: fixed; }
	.wp-list-table th, .wp-list-table td { padding: 8px 10px; text-align: left; vertical-align: top; }
	.button, .button-small { display: inline-block; padding: 0 8px; line-height: 22px;
		border: 1px solid #2271b1; background: #f6f7f7; border-radius: 3px; white-space: nowrap; }
	.description { color: #646970; font-size: 12px; }
	.dashicons { width: 20px; height: 20px; font-size: 20px; display: inline-block; }
	details > summary { cursor: pointer; }
`;

const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on( 'pageerror', e => errors.push( String( e ) ) );
page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );

await page.route( 'http://dze.test/setup', route => route.fulfill( { contentType: 'text/html', body:
	`<!doctype html><html><head><meta charset="utf-8"><style>${wpcss}</style><style>${css}</style>`
	+ `</head><body><div id="wpbody-content">${html}</div></body></html>` } ) );

// A NARROW WINDOW IS WHERE IT BREAKS. The admin menu takes 160px of every
// screen, so a 1280px laptop draws this in about a thousand.
for ( const width of [ 1040, 1280, 1600 ] ) {
	await page.setViewportSize( { width, height: 1000 } );
	await page.goto( 'http://dze.test/setup', { waitUntil: 'domcontentloaded' } );

	const m = await page.evaluate( () => {
		const doc = document.documentElement;
		const tbl = document.querySelector( '.dze-setup-list' );
		const row = tbl ? tbl.querySelector( 'tbody tr' ) : null;
		const cells = row ? [ ...row.children ].map( c => Math.round( c.getBoundingClientRect().width ) ) : [];
		const btn = tbl ? tbl.querySelector( '.dze-setup-act .button' ) : null;
		const cell = btn ? btn.closest( 'td' ) : null;
		const page_w = Math.round( document.getElementById( 'wpbody-content' ).getBoundingClientRect().width );
		return {
			sideways: doc.scrollWidth > doc.clientWidth,
			table: tbl ? Math.round( tbl.getBoundingClientRect().width ) : 0,
			page: page_w,
			cells,
			// A button that has wrapped is taller than its own line box, which
			// is exactly what "écran cassé" looked like.
			btnH: btn ? Math.round( btn.getBoundingClientRect().height ) : 0,
			// A BUTTON THAT DOES NOT WRAP OVERFLOWS. `white-space: nowrap`
			// keeps it on one line whatever happens, so height says nothing
			// about a starved column — what says it is the button spilling
			// out of the cell it is supposed to sit in.
			btnOut: btn && cell
				? Math.round( btn.getBoundingClientRect().width ) > Math.round( cell.getBoundingClientRect().width ) + 1
				: true,
			rowH: row ? Math.round( row.getBoundingClientRect().height ) : 0
		};
	} );

	ok( `no sideways scroll at ${width}px`, m.sideways, false );
	ok( `the table stays inside its page at ${width}px`, m.table <= m.page, true );
	// NO COLUMN STARVED. Four columns: the mark, the name, what it says, and
	// the button — and the two of words must keep room to be read.
	ok( `four columns at ${width}px`, m.cells.length, 4 );
	ok( `none of them starved at ${width}px`, m.cells.every( w => w >= 30 ), true );
	ok( `the two of words keep their room at ${width}px`,
		m.cells[1] >= 200 && m.cells[2] >= 180, true );
	// THE BUTTON SITS ON ONE LINE. A wrapped button is the first thing that
	// shows when the last column has been starved.
	ok( `the button is on one line at ${width}px`, m.btnH > 0 && m.btnH <= 30, true );
	ok( `and inside its own cell at ${width}px`, m.btnOut, false );
	ok( `and the row is not stacked at ${width}px`, m.rowH <= 90, true );
}

// ---- WHAT THE SCREEN IS FOR ----
await page.setViewportSize( { width: 1280, height: 1000 } );
await page.goto( 'http://dze.test/setup', { waitUntil: 'domcontentloaded' } );
const read = await page.evaluate( () => ( {
	// A BLOCK WITH WORK IN IT IS OPEN, so the screen opens on what is missing
	// rather than on everything at once.
	open: [ ...document.querySelectorAll( '.dze-setup-block' ) ].map( d => d.open ),
	chips: [ ...document.querySelectorAll( '.dze-setup-chip' ) ].map( c => c.textContent.trim() ),
	// The first line says how far it has got, with the figures.
	said: ( document.querySelector( '.dze-setup-said' ) || {} ).textContent || '',
	// Every row that is asking for something offers exactly one way to act.
	acts: [ ...document.querySelectorAll( '.dze-setup-row.is-todo' ) ]
		.map( r => ( r.querySelector( '.dze-setup-act .button' ) || {} ).textContent || '' ),
	primary: document.querySelectorAll( '.dze-setup-row.is-todo .button-primary' ).length,
	todoRows: document.querySelectorAll( '.dze-setup-row.is-todo' ).length
} ) );
ok( 'a block with work in it is open', read.open[0], true );
ok( 'the line says how far it has got', /\d+ of \d+ set up/.test( read.said ), true );
// A CHIP IS SILENT WHEN IT HAS NOTHING TO SAY: "0 to do" reads as a failure.
ok( 'and no chip says nought',         read.chips.some( c => /^0 /.test( c ) ), false );
// EVERY ROW THAT ASKS FOR SOMETHING OFFERS THE WAY TO DO IT — and it is the
// button the eye goes to, because that is what the screen is asking for.
ok( 'every row asking has a button',   read.acts.length, read.todoRows );
ok( 'none of them empty',              read.acts.every( t => t.trim().length > 0 ), true );
ok( 'and each one stands out',         read.primary, read.todoRows );
ok( 'nothing was raised drawing it',   errors, [] );

await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
