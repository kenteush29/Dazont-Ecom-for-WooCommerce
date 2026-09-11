/**
 * Dazont Ecom → WPML Translations, PRESSED, on both jQuery builds.
 *
 * Run before every release:  node tools/js/translate-screen.mjs
 *
 * This screen is the whole module from the shop's chair — "une liste d'attente
 * un peu comme wpml pour relecture du contenu traduit, avant automatisation" —
 * and every one of its three controls is an answer a button gives when it is
 * PRESSED. A settings tab that dies, a button with no handler bound, a request
 * that goes out without the object it is about: none of those exist until
 * somebody clicks, and all of them look like a screen where nothing happens.
 *
 * So: the markup is the plugin's own (tools/test-translate.php --dump-screen)
 * and so is the config it reads, key by key — named `ajax` instead of
 * `ajaxUrl` every request would post to the page itself, every answer would
 * come back as HTML, and this gate would prove nothing while looking green.
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

function dump( which ) {
	return JSON.parse( execFileSync( 'php',
		[ join( here, '..', 'test-translate.php' ), 'dazont-ecom', '--dump-screen=' + which ],
		{ encoding: 'utf8', cwd: root, stdio: [ 'ignore', 'pipe', 'ignore' ] } ) );
}
const dash   = dump( 'dashboard' );
const review = dump( 'review' );
// The plugin's own config, with only the address the harness has to answer on
// replaced. Retyping the rest is how a gate goes green while proving nothing.
const cfg = Object.assign( {}, dash.cfg, { ajaxUrl: 'http://dze.test/ajax' } );

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
		sent.push( {
			action: q.get( 'action' ), nonce: q.get( 'nonce' ), ref: q.get( 'ref' ),
			how: q.get( 'how' ), langs: q.getAll( 'langs[]' ),
			// What a decision actually puts on the wire, field by field.
			keepFr: q.get( 'keep[fr][name]' ), keepFrDesc: q.get( 'keep[fr][description]' )
		} );
		const json = d => route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: d } ) } );
		if ( 'dze_tr_batch' === q.get( 'action' ) ) {
			return json( { label: 'Balaclavas', done: [ 'fr' ], skipped: [], errors: {} } );
		}
		if ( 'dze_tr_panel' === q.get( 'action' ) ) {
			return json( {
				label: 'Balaclavas',
				edit: 'https://kula.test/wp-admin/term.php?tag_ID=7',
				source: { name: 'Balaclavas', description: 'Warm ones.' },
				labels: { name: 'Name', description: 'Description' },
				langs: { fr: {
					name: 'Français',
					texts: { name: 'Cagoules', description: 'Des chaudes.' },
					current: { name: 'Cagoule', description: '' },
					exists: true, mine: true, edit: 'https://kula.test/x'
				} }
			} );
		}
		if ( 'dze_tr_decide' === q.get( 'action' ) ) {
			return json( { written: { fr: 8 }, errors: {}, left: 0, refused: 'refuse' === q.get( 'how' ) } );
		}
		return json( {} );
	} );

	const serve = ( body ) => `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style>`
		+ `<script>${readFileSync( jq, 'utf8' )}</script>`
		+ `<script>window.dzeTrScreen=${JSON.stringify( cfg )};</script>`
		+ `<script>${readFileSync( join( js, 'translate-screen.js' ), 'utf8' )}</script></head>`
		+ `<body>${body}</body></html>`;

	// ---- THE DASHBOARD, AND THE BATCH IT SENDS ----
	await page.route( 'http://dze.test/dash', r => r.fulfill( { contentType: 'text/html', body: serve( dash.html ) } ) );
	await page.goto( 'http://dze.test/dash', { waitUntil: 'domcontentloaded' } );
	ok( 'the dashboard runs without an error', errors, [] );

	// WHAT WPML SAYS IS TRANSLATABLE IS WHAT IS ON THE SCREEN, and nothing
	// else: a type WPML would refuse to link must never be offered.
	const rowNames = await page.locator( '.dze-tr-row a' ).allTextContents();
	ok( 'the categories of the shop are listed', rowNames.length, 2 );
	ok( 'each says where it stands in each language',
		await page.locator( '.dze-tr-row:first-child .dze-tr-chip' ).count(), 2 );
	ok( 'and "not translated" is not dressed as "up to date"',
		await page.locator( '.dze-tr-chip.is-missing' ).count() > 0, true );

	// A press with nothing ticked says so rather than doing nothing.
	let before = sent.length;
	await page.click( '#dze-tr-send' );
	ok( 'a press with nothing ticked sends nothing', sent.length, before );

	// The real gesture: tick a row, tick the languages that are already on,
	// press, and read back WHAT WENT ON THE WIRE.
	await page.check( '.dze-tr-row:first-child .dze-tr-pickone' );
	await page.uncheck( '.dze-tr-lang[value="de"]' ).catch( () => {} );
	await page.click( '#dze-tr-send' );
	const ranBatch = await page.waitForFunction(
		() => /\S/.test( ( document.querySelector( '#dze-tr-progcount' ) || {} ).textContent || '' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	ok( 'the batch reports where it is', ranBatch, true );
	const batch = sent.filter( s => 'dze_tr_batch' === s.action );
	ok( 'exactly the ticked object was sent', batch.length, 1 );
	// THE REQUEST CARRIES THE OBJECT IT IS ABOUT. A "Fix" button once shipped
	// never sending the id of its own row, and the screen looked fine.
	ok( 'and it names that object', ( batch[0] || {} ).ref, 'term:7:product_cat' );
	ok( 'with its nonce', ( batch[0] || {} ).nonce, cfg.nonce );
	// AND ONLY THE LANGUAGES THAT ARE TICKED. Unticking one and still paying
	// for it is money spent on a decision nobody took.
	ok( 'and only the languages ticked', ( batch[0] || {} ).langs, [ 'fr' ] );
	ok( 'the run says what it finished with',
		( await page.textContent( '#dze-tr-sendstate' ) || '' ).length > 0, true );
	// A BATCH THAT FINISHES AND LEAVES EVERY LINE AS IT WAS is a press nobody
	// can tell worked: "Rien à jour sur la page. La je ne comprends pas quoi
	// faire en fait. Comment je vérifies le contenu ?" The row that was sent
	// says what came back ON ITSELF, and the sentence at the bottom carries a
	// way to it rather than naming a tab.
	const rowSaid = await page.textContent( '.dze-tr-row:first-child .dze-tr-state' );
	ok( 'the row that was sent says what came back',
		( rowSaid || '' ).includes( cfg.i18n.rowHeld ), true );
	ok( 'and no longer says it is not translated',
		( rowSaid || '' ).includes( 'not translated' ), false );
	ok( 'the row left alone is untouched',
		( await page.textContent( '.dze-tr-row:nth-child(2) .dze-tr-state' ) || '' ).includes( cfg.i18n.rowHeld ), false );
	ok( 'and the way to read what came back is offered',
		await page.locator( `#dze-tr-sendstate a[href="${cfg.reviewUrl}"]` ).count(), 1 );
	ok( 'nothing was raised sending a batch', errors, [] );

	// THE TICK AT THE TOP TAKES THE LOT — on this screen like every other.
	await page.check( '#dze-tr-all' );
	ok( 'the heading tick takes every row',
		await page.locator( '.dze-tr-pickone:checked' ).count(), 2 );

	// ---- THE WAITING LIST, AND THE DECISION ON IT ----
	await page.route( 'http://dze.test/review', r => r.fulfill( { contentType: 'text/html', body: serve( review.html ) } ) );
	await page.goto( 'http://dze.test/review', { waitUntil: 'domcontentloaded' } );
	ok( 'the review list runs without an error', errors, [] );
	ok( 'what is waiting is listed', await page.locator( '.dze-tr-wrow' ).count(), 1 );

	// Review OPENS the object — it does not decide anything.
	before = sent.length;
	await page.click( '.dze-tr-wrow .dze-tr-open' );
	const opened = await page.waitForSelector( '.dze-tr-panel .dze-cb-fblock', { timeout: 6000 } )
		.then( () => true ).catch( () => false );
	ok( 'pressing Review opens the object', opened, true );
	ok( 'and it decided nothing on the way',
		sent.slice( before ).filter( s => 'dze_tr_decide' === s.action ).length, 0 );
	if ( opened ) {
		// BEFORE AND AFTER, BOTH PRINTED: the original, what the translation
		// holds today, and the new text in an editor — a block that prints one
		// of the three is a screen nobody can decide on.
		ok( 'the original is printed',
			( await page.textContent( '.dze-tr-panel .dze-tr-was' ) || '' ).includes( 'Balaclavas' ), true );
		ok( 'what the translation holds today is printed',
			( await page.textContent( '.dze-tr-panel .dze-tr-now' ) || '' ).includes( 'Cagoule' ), true );
		ok( 'and the new text is in an editor',
			await page.locator( '.dze-tr-panel .dze-tr-new' ).first().inputValue(), 'Cagoules' );
		ok( 'one field per thing translated',
			await page.locator( '.dze-tr-panel .dze-cb-fblock' ).count(), 2 );

		// ACCEPTING IS FIELD BY FIELD. Untick one and it must not travel.
		await page.uncheck( '.dze-tr-panel .dze-cb-fblock[data-field="description"] .dze-tr-keep' );
		before = sent.length;
		await page.click( '.dze-tr-panel .dze-tr-accept' );
		const decided = await page.waitForFunction(
			() => ( document.querySelectorAll( '.dze-tr-wrow' ).length === 0 ),
			null, { timeout: 6000 } ).then( () => true ).catch( () => false );
		const dec = sent.slice( before ).filter( s => 'dze_tr_decide' === s.action );
		ok( 'accepting posts a decision', dec.length, 1 );
		ok( 'on the object it is about', ( dec[0] || {} ).ref, 'term:7:product_cat' );
		ok( 'the ticked field travels', ( dec[0] || {} ).keepFr, 'Cagoules' );
		// A FIELD UNTICKED IS LEFT OUT — the rest is still written.
		ok( 'and the unticked one does not', ( dec[0] || {} ).keepFrDesc, null );
		// The answer LANDED: a request whose answer goes nowhere is the same
		// broken button from the shop's chair.
		ok( 'and the row leaves the list once nothing is left', decided, true );
	}
	ok( 'nothing was raised anywhere in the gesture', errors, [] );

	// REFUSING IS ITS OWN DECISION, from the row, and it asks first.
	await page.goto( 'http://dze.test/review', { waitUntil: 'domcontentloaded' } );
	before = sent.length;
	await page.click( '.dze-tr-wrow .dze-tr-refuse' );
	const gone = await page.waitForFunction(
		() => ( document.querySelectorAll( '.dze-tr-wrow' ).length === 0 ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	const ref = sent.slice( before ).filter( s => 'dze_tr_decide' === s.action );
	ok( 'refusing posts a refusal', ( ref[0] || {} ).how, 'refuse' );
	ok( 'naming the object', ( ref[0] || {} ).ref, 'term:7:product_cat' );
	ok( 'and the row goes', gone, true );
	ok( 'nothing was raised refusing', errors, [] );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
