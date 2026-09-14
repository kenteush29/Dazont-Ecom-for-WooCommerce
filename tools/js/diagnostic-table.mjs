/**
 * The problem list, MEASURED, on both jQuery builds.
 *
 * Run before every release:  node tools/js/diagnostic-table.mjs
 *
 * "Affichage cassé non confortable. lignes beaucoup trops grosses." Nine
 * columns were added to this table one at a time — the thumbnail, the id, the
 * price, the figure sold, the date, the condition, the button — each of them
 * right on its own, and every one of them spent out of the same width. The
 * product's NAME, the only column carrying words worth reading, was left with
 * whatever the others had not taken: about a hundred pixels, so a nine-word
 * title came back one word per line and every row was two hundred pixels tall.
 *
 * That arithmetic is invisible to every PHP gate and to `node --check` alike —
 * the markup is correct, the styles are correct, and the screen is unusable.
 * So this gate does the one thing only a browser can: it draws the REAL screen
 * at three widths and measures it.
 *
 * The rule it holds is the one Content to review was mended with: a column
 * whose content has a KNOWN width is given it in pixels, a column of WORDS is
 * left to share what remains — and what remains must be worth having.
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

// The screen as the plugin prints it. One fake shop, and the marker is what
// separates the markup from the checks that ran before it.
const out = execFileSync( 'php',
	[ join( here, '..', 'test-diagnostic.php' ), 'dazont-ecom', '--dump-table' ],
	{ encoding: 'utf8', cwd: root, stdio: [ 'ignore', 'pipe', 'ignore' ] } );
const mark = out.indexOf( '<!--DZE-TABLE-->' );
if ( mark < 0 ) { console.log( 'The dump carried no table.' ); process.exit( 1 ); }
const html = out.slice( mark + '<!--DZE-TABLE-->'.length );

// WordPress's own list table, as much of it as the layout depends on.
const wpcss = `
	*, *::before, *::after { box-sizing: border-box; }
	body { margin: 0; font: 13px/1.4em -apple-system, sans-serif; }
	#wpbody-content { padding: 0 20px; box-sizing: border-box; }
	.widefat { border-spacing: 0; width: 100%; border-collapse: collapse; clear: both; }
	.widefat th, .widefat td { padding: 8px 10px; text-align: left; vertical-align: top;
		font-size: 13px; line-height: 1.4em; }
	.widefat thead th { font-weight: 600; }
	.button, .page-title-action { display: inline-block; padding: 0 10px; line-height: 26px;
		border: 1px solid #2271b1; background: #f6f7f7; border-radius: 3px; white-space: nowrap; }
	.check-column { width: 2.2em; }
	.description { color: #646970; }
	.nav-tab-wrapper { border-bottom: 1px solid #c3c4c7; }
	.nav-tab { display: inline-block; padding: 6px 10px; border: 1px solid #c3c4c7; }
`;

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );

	// THE ROW IS MEASURED WITH ITS PICTURE IN IT. The thumbnails point at the
	// fake shop's own image host; left unserved they fail to load, the cell holds
	// a broken image of another size, and the height being measured is not the
	// height the shop has.
	await page.route( 'http://img.test/**', route => route.fulfill( {
		contentType: 'image/png',
		body: Buffer.from(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
			'base64' )
	} ) );
	await page.route( 'http://dze.test/screen', route => route.fulfill( { contentType: 'text/html', body:
		`<!doctype html><html><head><meta charset="utf-8"><style>${wpcss}</style><style>${css}</style>`
		+ `<script>${readFileSync( jq, 'utf8' )}</script>`
		+ `</head><body><div id="wpbody-content">${html}</div></body></html>` } ) );

	// THE ADMIN MENU TAKES 160px OF EVERY SCREEN, so a 1280px laptop draws
	// this table in about a thousand — which is the width it was starved at.
	for ( const width of [ 1040, 1280, 1600 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );

		const seen = await page.evaluate( () => {
			const t = document.querySelector( '#wpbody-content table.widefat' );
			const host = document.getElementById( 'wpbody-content' );
			const head = Array.from( t.querySelectorAll( 'thead tr > *' ) );
			const rows = Array.from( t.querySelectorAll( 'tbody tr' ) );
			const cells = Array.from( rows[ 0 ].children );
			const name = head.findIndex( h => /^Product/.test( h.textContent.trim() ) );
			const btn = rows[ 0 ].querySelector( '.button' );
			const btnCell = btn ? btn.closest( 'td, th' ) : null;
			return {
				table: Math.round( t.getBoundingClientRect().width ),
				host: host.clientWidth,
				doc: document.documentElement.scrollWidth,
				win: window.innerWidth,
				heads: head.map( h => h.textContent.trim().replace( /\s+/g, ' ' ) ),
				n: [ head.length, cells.length ],
				aligned: head.every( ( h, i ) => cells[ i ]
					&& Math.abs( h.getBoundingClientRect().left - cells[ i ].getBoundingClientRect().left ) < 2 ),
				widths: head.map( h => Math.round( h.getBoundingClientRect().width ) ),
				nameW: name < 0 ? 0 : Math.round( head[ name ].getBoundingClientRect().width ),
				rowH: rows.map( r => Math.round( r.getBoundingClientRect().height ) ),
				rows: rows.length,
				// A BUTTON THAT DOES NOT WRAP OVERFLOWS: a starved column
				// changes no height at all, so the thing to measure is the
				// button spilling out of its own cell.
				btnOut: ( btn && btnCell )
					? Math.round( btn.getBoundingClientRect().right
						- btnCell.getBoundingClientRect().right )
					: -999,
				// THE ID STAYS INSIDE ITS OWN COLUMN. Its cell's HEIGHT is the
				// row's height — every cell is stretched to it — so measuring
				// that measured the row twice and could never fail on the id's
				// own fault. What a narrow fixed column actually does to an
				// unbreakable run of digits is push it out sideways.
				idOut: ( () => {
					const c = rows[ 0 ].querySelector( '.dze-objid-td' );
					const p = c && c.querySelector( '.dze-objid' );
					return ( c && p )
						? Math.round( p.getBoundingClientRect().right - c.getBoundingClientRect().right )
						: -999;
				} )(),
				// THE NAME IS THE WIDEST COLUMN, at every width: it is the one
				// the list is read by, and a figure that holds on a wide
				// window and not on a narrow one is not a rule.
				widest: ( () => {
					const w = head.map( h => h.getBoundingClientRect().width );
					return w.indexOf( Math.max.apply( null, w ) );
				} )(),
				nameAt: name
			};
		} );

		// WHAT IT MEASURED, said out loud. When this gate goes red the next
		// time a column is added, the answer is in these figures and nowhere
		// else — a failing layout check with no numbers under it sends
		// somebody back to the browser to take them by hand.
		console.log( `  ·     ${width}px → table ${seen.table}, columns ${seen.widths.join( '/' )}, rows ${seen.rowH.join( '/' )}` );
		ok( `the list draws at ${width}px`, seen.rows, 3 );
		ok( `it fits the page at ${width}px`, seen.table <= seen.host, true );
		ok( `and the page does not scroll sideways at ${width}px`, seen.doc <= seen.win, true );
		ok( `head and cells count the same at ${width}px`, seen.n[0], seen.n[1] );
		ok( `and every cell sits under its heading at ${width}px`, seen.aligned, true );
		// NO COLUMN IS STARVED. 70px is about five characters — under that a
		// heading stacks one word per line and the table looks broken.
		ok( `no column is under 70px at ${width}px`,
			seen.widths.filter( w => w < 70 && w > 0 ).length === 0
				|| seen.widths.filter( ( w, i ) => w < 70 && i > 1 ).length === 0, true );
		// THE NAME IS THE COLUMN THE LIST IS READ BY. At a hundred pixels a
		// nine-word product title is nine lines, and that is what "lignes
		// beaucoup trop grosses" was.
		// 180px is about twenty-five characters: three lines for the longest
		// title the shop holds, which is a row and not a paragraph.
		ok( `the product's name has room at ${width}px`, seen.nameW >= 180, true );
		// AND THE ROWS STAY A ROW HIGH. The name and the shortfall sentence
		// under it are two lines plus the thumbnail: anything over about a
		// hundred means the title is wrapping into a paragraph.
		ok( `every row stays under 100px at ${width}px`,
			seen.rowH.filter( h => h > 100 ), [] );
		ok( `and the button stays in its own cell at ${width}px`, seen.btnOut <= 0, true );
		ok( `the id stays in its column at ${width}px`, seen.idOut <= 0, true );
		ok( `and the name is the widest column at ${width}px`, seen.widest, seen.nameAt );
	}
	ok( 'nothing was raised drawing it', errors, [] );
	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
