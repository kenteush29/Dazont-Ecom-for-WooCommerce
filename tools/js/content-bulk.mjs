/**
 * The product bulk screen, PRESSED, on both jQuery builds.
 *
 * Run before every release:  node tools/js/content-bulk.mjs
 *
 * This screen had no browser gate at all, and it is where two faults lived for
 * weeks, both of them invisible to PHP and to `node --check`:
 *
 *  - "J'ai choisi un prompt pour bulk sur tous les produits et je me suis
 *    retrouvé sur le 2e produit avec une image principale refaite. Et l'ugc ne
 *    fonctionnait pas du tout. Et ça me demandait sur chaque produit que faire
 *    avec l'image principale." A destination the SCREEN had filled in by itself
 *    was written to the browser's memory as though somebody had chosen it, and
 *    the row never followed a prompt again. And the background was one answer
 *    for the whole shop, attached to every prompt whatever it asked for — which
 *    is what turned a customer-snapshot prompt into a white pack shot.
 *  - "Le bouton discard sur l'écran bulk devrait refuser les changements et
 *    reset le status des produits comme si rien n'avait été généré.
 *    Actuellement ils sont supprimés de la page bulk."
 *
 * Both are answers a control gives when it is pressed. The markup is the
 * plugin's own (tools/test-sources.php --dump-bulk), and so is the config it
 * reads — key by key, never retyped.
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
	[ join( here, '..', 'test-sources.php' ), 'dazont-ecom', '--dump-bulk' ],
	{ encoding: 'utf8', cwd: root, stdio: [ 'ignore', 'pipe', 'ignore' ] } ) );
// The plugin's own config, with only the address the harness has to answer on
// replaced. Retyping the rest is how a gate goes green while proving nothing.
const cfg = Object.assign( {}, dumped.cfg, { ajaxUrl: 'http://dze.test/ajax' } );

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [], sent = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );
	page.on( 'dialog', d => d.accept() );

	await page.route( 'http://dze.test/ajax', async route => {
		const q = new URLSearchParams( route.request().postData() || '' );
		sent.push( { action: q.get( 'action' ), do: q.get( 'do' ), ids: q.getAll( 'ids[]' ) } );
		const json = d => route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: d } ) } );
		if ( 'dze_content_text_all' === q.get( 'action' ) ) {
			return json( { results: { desc: 'applied' }, texts: { desc: '<p>Written.</p>' }, companions: {} } );
		}
		if ( 'dze_content_bulk_list' === q.get( 'action' ) ) {
			return json( { left: [ 7, 8 ], counts: { all: 2, log: 1 } } );
		}
		// WHAT THE PRODUCT HOLDS TODAY: the same answer the "Current" button on
		// a generated block already reads, so the panel can show it before
		// anything has been generated at all.
		if ( 'dze_content_current' === q.get( 'action' ) ) {
			return json( {
				texts: { desc: '<p>The description this product has today.</p>', short: '' },
				images: [ { id: 5, thumb: 'http://img.test/5.jpg', full: 'http://img.test/5.jpg', main: true, w: 900, h: 900 } ]
			} );
		}
		return json( {} );
	} );
	// The fake shop's thumbnails point at an address that does not exist. They
	// are answered rather than left to fail, so `errors` holds JavaScript
	// faults and nothing else — which is what it is asserted on.
	await page.route( '**/*', route => {
		const u = route.request().url();
		if ( u.startsWith( 'http://dze.test/' ) ) { return route.fallback(); }
		return route.fulfill( { status: 200, contentType: 'image/gif', body: '' } );
	} );
	await page.route( 'http://dze.test/screen', async route => {
		await route.fulfill( { contentType: 'text/html', body:
			`<!doctype html><html><head><meta charset="utf-8"><style>${css}</style>`
			+ `<script>${readFileSync( jq, 'utf8' )}</script>`
			+ `<script>window.ajaxurl='http://dze.test/ajax';window.dzePhotosCfg={i18n:{}};`
			+ `window.dzeContentBulk=${JSON.stringify( cfg )};</script>`
			+ `<script>${readFileSync( join( js, 'hub.js' ), 'utf8' )}</script>`
			+ `<script>${readFileSync( join( js, 'photos.js' ), 'utf8' )}</script>`
			+ `<script>${readFileSync( join( js, 'paste-box.js' ), 'utf8' )}</script>`
			+ `</head><body><div class="wrap">${dumped.html}</div>`
			+ `<script>${readFileSync( join( js, 'content-bulk.js' ), 'utf8' )}</script></body></html>` } );
	} );

	// A MEMORY FROM AN EARLIER RUN, of the shape that caused the fault: a row
	// carrying a destination and a scene the screen had filled in by itself.
	await page.addInitScript( () => {
		try {
			window.localStorage.setItem( 'dzeContentMem', JSON.stringify( {
				tpls: [ { tpl: '1', n: 1, target: 'main', scene: 0 } ]
			} ) );
		} catch ( e ) {}
	} );
	await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );
	ok( 'the screen runs without an error', errors, [] );

	// ---- THE BACKGROUND AND THE DESTINATION ARE THE PROMPT'S OWN ----
	//
	// Row 1 was remembered on prompt 1 — "Customer photo", which writes to the
	// gallery and asks for no scene. What was remembered ALONGSIDE it said
	// "main image, studio backdrop", and that is exactly what must not win.
	const row = i => `#dze-cb-tplrows .dze-tplrow:nth-child(${i})`;
	// The Images block is shut when the screen is drawn: it is opened the way a
	// person opens it, by pressing its heading.
	await page.click( '.dze-sec[data-sec="img"] .dze-sec-head' );
	const opened = await page.waitForSelector( `${row( 1 )} .dze-cb-tpl`, { state: 'visible', timeout: 5000 } )
		.then( () => true ).catch( () => false );
	ok( 'the Images block opens on its prompt rows', opened, true );
	if ( ! opened ) { await page.close(); continue; }
	ok( 'the remembered prompt is restored',
		await page.locator( `${row( 1 )} .dze-cb-tpl` ).inputValue(), '1' );
	ok( 'but its destination follows the prompt',
		await page.locator( `${row( 1 )} .dze-tpl-target` ).inputValue(), 'gallery' );
	ok( 'and so does its background',
		await page.locator( `${row( 1 )} .dze-tpl-scene` ).inputValue(), '-1' );
	// AND THE QUESTION ABOUT THE MAIN IMAGE ONLY ARISES WHEN SOMETHING IS
	// ABOUT TO TAKE ITS PLACE. It was asked on every product of every run.
	ok( "nothing asks what to do with today's main image",
		await page.locator( '#dze-cb-oldwrap' ).isVisible(), false );

	// Switch the row to the pack shot: that one DOES write the main image and
	// IS shot on the backdrop, and the row must say both.
	await page.selectOption( `${row( 1 )} .dze-cb-tpl`, '0' );
	ok( 'a pack shot prompt writes the main image',
		await page.locator( `${row( 1 )} .dze-tpl-target` ).inputValue(), 'main' );
	ok( 'and is shot on its own backdrop',
		await page.locator( `${row( 1 )} .dze-tpl-scene` ).inputValue(), '0' );
	ok( 'and THEN the main image is asked about',
		await page.locator( '#dze-cb-oldwrap' ).isVisible(), true );
	// A prompt naming a different scene answers with that one, by name — the
	// index is never guessed from the order of the menu.
	await page.selectOption( `${row( 1 )} .dze-cb-tpl`, '2' );
	ok( 'a prompt naming another scene gets that one',
		await page.locator( `${row( 1 )} .dze-tpl-scene` ).inputValue(), '1' );
	ok( 'and the main-image question goes away with it',
		await page.locator( '#dze-cb-oldwrap' ).isVisible(), false );

	// ---- WHAT THE PRESS IS ABOUT TO SPEND, BEFORE IT IS PRESSED ----
	//
	// "J'ai dépensé hier 40$ en génération d'images... sur fal j'ai vu 24
	// images générées pour le même produit le fsb patch." Twenty-four is what
	// this screen asks for on its own — three prompt rows at four attempts
	// each, on a run of two products — and every figure needed to say so was
	// already on the page. The button read "Generate (2)", a count of
	// products, which reads like a count of the work.
	//
	// Only a browser can multiply what is ON the page, so this is asserted
	// here and nowhere else.
	await page.setChecked( '#dze-cb-image', true );
	await page.check( '.dze-cb-row[data-id="7"] .dze-cb-pick' );
	await page.check( '.dze-cb-row[data-id="8"] .dze-cb-pick' );
	const spend = async () => ( await page.textContent( '#dze-cb-spend' ) || '' ).trim();
	ok( 'the press says how many photographs and what they cost',
		await spend(), '2 photographs · about $0.16' );
	await page.selectOption( `${row( 1 )} .dze-tpl-n`, '4' );
	ok( 'four attempts on two products is eight',
		await spend(), '8 photographs · about $0.64' );

	// His own run, rebuilt: three prompts at four attempts each.
	await page.click( `${row( 1 )} .dze-tpl-add` );
	await page.click( `${row( 2 )} .dze-tpl-add` );
	await page.selectOption( `${row( 2 )} .dze-tpl-n`, '4' );
	await page.selectOption( `${row( 3 )} .dze-tpl-n`, '4' );
	const said = await spend();
	ok( 'three prompts at four attempts is twenty-four', said.startsWith( '24 photographs · about $1.92' ), true );
	// AND THE CEILING IS NAMED WHERE IT WOULD BE HIT. Twelve photographs of one
	// product against a ceiling of ten is a run that stops two short on every
	// line — said before the press, not as a refusal halfway through.
	ok( 'and it names the ceiling it would run into',
		said.includes( 'over the ceiling of 10 per product and hour — 2 of each will be refused' ), true );

	// A block switched off spends nothing, and the line goes rather than
	// standing there saying a figure that is no longer true.
	await page.setChecked( '#dze-cb-image', false );
	ok( 'photographs switched off, nothing to spend',  await spend(), '' );
	ok( 'and the line is not left standing',
		await page.locator( '#dze-cb-spend' ).isVisible(), false );
	await page.setChecked( '#dze-cb-image', true );
	// Untick the second product and the bill halves: it is the SELECTION that
	// is multiplied, the same one the button counts.
	await page.uncheck( '.dze-cb-row[data-id="8"] .dze-cb-pick' );
	ok( 'one product instead of two, half the bill',
		( await spend() ).startsWith( '12 photographs · about $0.96' ), true );
	// Back to one prompt row, so the checks below run the screen they expect.
	await page.click( `${row( 3 )} .dze-tpl-del` );
	await page.click( `${row( 2 )} .dze-tpl-del` );
	await page.selectOption( `${row( 1 )} .dze-tpl-n`, '1' );
	ok( 'nothing was raised counting the bill',  errors, [] );

	// ---- ONE CLICK TO SEE WHAT A PRODUCT HOLDS TODAY ----
	//
	// "Ici sur cette page je manque d'une option pour visualiser en un clic le
	// contenu actuel des produits. Dans l'immédiat je veux voir leurs images.
	// Et j'aimerais la possibilité de leur coller des images externes sur
	// cette page... mais visualiser aussi leur contenu textuel serait bien."
	// All of it was already built — the photographs, the paste box, the "on
	// the product today" reading — and none of it was reachable: the button
	// that opens the panel only appeared once something had been GENERATED.
	// Reported, not fatal: a gate that dies on the button it is about says
	// nothing about the twelve checks after it.
	const canLook = await page.locator( '.dze-cb-row[data-id="8"] .dze-cb-toggle' ).isVisible();
	ok( 'every row offers to be looked at',  canLook, true );
	if ( canLook ) {
		ok( 'and says what it opens on',
			( await page.textContent( '.dze-cb-row[data-id="8"] .dze-cb-toggleword' ) ).trim(), 'Look' );
		await page.click( '.dze-cb-row[data-id="8"] .dze-cb-toggle' );
	}
	ok( 'it opens without anything being generated',
		await page.locator( '.dze-cb-preview[data-id="8"]' ).isVisible(), true );
	const gotPhotos = await page.waitForSelector( '.dze-cb-preview[data-id="8"] .dze-cb-nowshots img', { timeout: 6000 } )
		.then( () => true ).catch( () => false );
	ok( 'the photographs it holds are there',   gotPhotos, true );
	ok( 'the box for photographs from outside too',
		await page.locator( '.dze-cb-preview[data-id="8"] .dze-cb-elsebox' ).count(), 1 );
	// AND ITS TEXT, one folded line per field, in the same shell as the
	// generated blocks so one gesture opens both.
	ok( 'and its text, folded',
		await page.locator( '.dze-cb-preview[data-id="8"] .dze-cb-fblock.is-today' ).count() > 0, true );
	ok( 'shut until it is asked for',
		await page.locator( '.dze-cb-preview[data-id="8"] .dze-cb-fblock.is-today .dze-cb-fbody:visible' ).count(), 0 );
	const todayRows = await page.locator( '.dze-cb-preview[data-id="8"] .dze-cb-fblock.is-today' ).count();
	if ( ! todayRows ) {
		ok( 'and pressing it shows what the product says', 'no today block to press', 'a today block' );
		ok( 'an empty field says it is empty',             'no today block to press', 'a today block' );
	} else {
	await page.click( '.dze-cb-preview[data-id="8"] .dze-cb-fblock.is-today[data-field="desc"] .dze-cb-fhead' );
	ok( 'and pressing it shows what the product says',
		( await page.textContent( '.dze-cb-preview[data-id="8"] .dze-cb-fblock.is-today[data-field="desc"] .dze-cb-fbody' ) )
			.includes( 'The description this product has today' ), true );
	// AN EMPTY FIELD SAYS SO. A blank panel reads as a screen that did not
	// answer, which is the one thing it must not look like.
	await page.click( '.dze-cb-preview[data-id="8"] .dze-cb-fblock.is-today[data-field="short"] .dze-cb-fhead' );
	ok( 'an empty field says it is empty',
		( await page.textContent( '.dze-cb-preview[data-id="8"] .dze-cb-fblock.is-today[data-field="short"] .dze-cb-fbody' ) )
			.includes( 'empty' ), true );
	}
	// AND NOTHING IS OFFERED THAT CANNOT ACT. There is no decision to take on
	// a product opened only to be looked at.
	ok( 'no accept on a panel holding nothing',
		await page.locator( '.dze-cb-preview[data-id="8"] .dze-cb-applyone' ).count(), 0 );
	ok( 'and no refusal either',
		await page.locator( '.dze-cb-preview[data-id="8"] .dze-cb-drop' ).count(), 0 );
	if ( canLook ) { await page.click( '.dze-cb-row[data-id="8"] .dze-cb-toggle' ); }
	ok( 'nothing was raised looking at it',  errors, [] );

	// ---- REFUSING IS NOT REMOVING ----
	//
	// The real path: tick a product, generate a text, then press Discard on
	// its own panel and read the LIST back.
	await page.check( '.dze-cb-row[data-id="7"] .dze-cb-pick' );
	await page.uncheck( '#dze-cb-image' ).catch( () => {} );
	await page.check( '.dze-cb-field[value="desc"]' );
	await page.click( '#dze-cb-start' );
	// WAIT FOR THE ANSWER, NOT FOR SOMETHING ALREADY THERE. This waited for the
	// Look button to appear — and that button is on every row from the start
	// now, so the wait returned at once and the checks after it read a screen
	// still working. What a finished run changes is the WORD on it.
	const ready = await page.waitForFunction(
		() => /Review/.test( ( document.querySelector( '.dze-cb-row[data-id="7"] .dze-cb-toggleword' ) || {} ).textContent || '' ),
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'a run leaves the line offering a review', ready, true );
	ok( 'and a badge saying what was produced',
		await page.locator( '.dze-cb-row[data-id="7"] .dze-cb-badge' ).count() > 0, true );
	await page.click( '.dze-cb-row[data-id="7"] .dze-cb-toggle' );
	ok( 'the panel opens on the product',
		await page.locator( '.dze-cb-preview[data-id="7"]' ).isVisible(), true );

	const before = sent.length;
	await page.click( '.dze-cb-preview[data-id="7"] .dze-cb-drop' );
	const answered = await page.waitForFunction(
		() => ! document.querySelector( '.dze-cb-row[data-id="7"] .dze-cb-badge' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	ok( 'the press is answered',            answered, true );
	// WHAT WENT ON THE WIRE: a refusal, on that product, and not a removal.
	ok( 'and it asks the server to refuse, by id',
		sent.slice( before ), [ { action: 'dze_content_bulk_list', do: 'discard', ids: [ '7' ] } ] );
	// THE PRODUCT STAYS. This is the whole of it: it used to leave the screen.
	ok( 'the product is still on the list',
		await page.locator( '.dze-cb-row[data-id="7"]' ).count(), 1 );
	ok( 'and so is its neighbour',
		await page.locator( '.dze-cb-row[data-id="8"]' ).count(), 1 );
	// AND IT IS BACK WHERE IT STARTED, which is what "reset le status" means.
	ok( 'its badges are gone',
		await page.locator( '.dze-cb-row[data-id="7"] .dze-cb-badge' ).count(), 0 );
	ok( 'its state says waiting again',
		await page.locator( '.dze-cb-row[data-id="7"] .dze-cb-state.is-wait' ).count(), 1 );
	// THE LOOK BUTTON STAYS — what the product HOLDS is still worth opening,
	// and it is the only way to reach its photographs from this screen. What
	// goes is the offer to REVIEW something that no longer exists.
	ok( 'it can still be looked at',
		await page.locator( '.dze-cb-row[data-id="7"] .dze-cb-toggle' ).isVisible(), true );
	ok( 'and the button says so again',
		( await page.textContent( '.dze-cb-row[data-id="7"] .dze-cb-toggleword' ) ).trim(), 'Look' );
	ok( 'nothing is offered to be applied',
		await page.locator( '.dze-cb-row[data-id="7"] .dze-cb-apply-one' ).isVisible(), false );
	ok( 'and its panel is shut and empty',
		( await page.locator( '.dze-cb-preview[data-id="7"] td' ).innerHTML() ).trim(), '' );
	ok( 'nothing was raised anywhere in the gesture', errors, [] );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
