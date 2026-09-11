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
const dash   = dump( 'batch' );
const review = dump( 'review' );
const popup  = dump( 'popup' );
// The plugin's own config, with only the address the harness has to answer on
// replaced. Retyping the rest is how a gate goes green while proving nothing.
const cfg = Object.assign( {}, dash.cfg, { ajaxUrl: 'http://dze.test/ajax' } );

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [], sent = [];
	// The review list's object IS holding something; the batch list's first row
	// is not, which is what makes one say Review and the other Look.
	let holding = false;
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );
	page.on( 'dialog', d => d.accept() );

	await page.route( 'http://dze.test/ajax', async route => {
		const q = new URLSearchParams( route.request().postData() || '' );
		sent.push( {
			action: q.get( 'action' ), nonce: q.get( 'nonce' ), ref: q.get( 'ref' ),
			post: q.get( 'post' ),
			how: q.get( 'how' ), langs: q.getAll( 'langs[]' ),
			// What a decision actually puts on the wire, field by field.
			keepFr: q.get( 'keep[fr][name]' ), keepFrDesc: q.get( 'keep[fr][description]' )
		} );
		const json = d => route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: d } ) } );
		if ( 'dze_tr_batch' === q.get( 'action' ) ) {
			return json( { label: 'Balaclavas', done: [ 'fr' ], skipped: [], errors: {} } );
		}
		if ( 'dze_tr_panel' === q.get( 'action' ) && 'term:7:product_cat' === q.get( 'ref' ) && !holding ) {
			// NOTHING WAITING ON IT: the server answers with what the object
			// holds today, and the panel offers nothing to press.
			return json( {
				look: true,
				label: 'Balaclavas',
				edit: 'https://kula.test/wp-admin/term.php?tag_ID=7',
				source: { name: 'Balaclavas', description: 'Warm ones.' },
				labels: { name: 'Name', description: 'Description' },
				langs: { fr: { name: 'Français', texts: {}, current: { name: 'Cagoules' }, exists: true, mine: true, edit: '' } }
			} );
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
		if ( 'dze_tr_rebuild' === q.get( 'action' ) ) {
			return json( { rows: { fr: { lang: 'Français', before: 0, after: 3, how: 'attributes+variations' } } } );
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
		await page.locator( '.dze-tr-row' ).nth( 0 ).locator( '.dze-tr-chip' ).count(), 2 );
	ok( 'and "not translated" is not dressed as "up to date"',
		await page.locator( '.dze-tr-chip.is-missing' ).count() > 0, true );

	// ---- THE BAR, THE BILL AND "LOOK" — the shape the bulk screen wears ----
	// "Utiliser le même type de dashboard que pour les bulk content
	// generation." Same bar, same words, same order, and the same two-word
	// button on every row. Only a browser can multiply what is on the page.
	await page.click( '#dze-tr-selall' );
	ok( 'Select all takes every row',
		await page.locator( '.dze-tr-pickone:checked' ).count(), 2 );
	ok( 'and the bar says how many are ticked',
		( await page.textContent( '#dze-tr-selcount' ) || '' ).includes( '2' ), true );
	// WHAT THE PRESS IS ABOUT TO DO: rows times languages. Every figure was
	// already on the screen and none had ever been multiplied.
	ok( 'the bill multiplies the rows by the languages',
		( await page.textContent( '#dze-tr-bill' ) || '' ).includes( '4' ), true );
	await page.uncheck( '.dze-tr-lang[value="de"]' );
	ok( 'and follows a language being dropped',
		( await page.textContent( '#dze-tr-bill' ) || '' ).includes( '2' ), true );
	await page.click( '#dze-tr-selnone' );
	ok( 'Unselect all drops the lot',
		await page.locator( '.dze-tr-pickone:checked' ).count(), 0 );
	ok( 'and the bill says nothing is ticked',
		await page.textContent( '#dze-tr-bill' ), cfg.i18n.billNone );

	// ONE TICK PER BLOCK, IN ITS OWN HEADING — the same class and the same
	// handler as every other screen with blocks.
	ok( 'the languages block has a take-all in its heading',
		await page.locator( '[data-sec="langs"] .dze-sec-head .dze-sec-all' ).count(), 1 );

	// LOOK: what the object holds today, and a panel holding nothing offers
	// neither Accept nor Refuse.
	let before = sent.length;
	await page.locator( '.dze-tr-row' ).nth( 0 ).locator( '.dze-tr-open' ).click();
	const looked = await page.waitForSelector( '.dze-tr-panel .dze-tr-panelbox', { timeout: 6000 } )
		.then( () => true ).catch( () => false );
	ok( 'pressing Look opens the object', looked, true );
	const askedLook = sent.slice( before ).filter( s => 'dze_tr_panel' === s.action );
	ok( 'it asked for that object and nothing else', ( askedLook[0] || {} ).ref, 'term:7:product_cat' );
	ok( 'it prints what the object holds today',
		( await page.textContent( '.dze-tr-panel' ) || '' ).includes( 'Balaclavas' ), true );
	ok( 'and a panel holding nothing offers no Accept',
		await page.locator( '.dze-tr-panel .dze-tr-accept' ).count(), 0 );
	ok( 'nor a refusal', await page.locator( '.dze-tr-panel .dze-tr-refuse' ).count(), 0 );
	ok( 'and the page never moved to show it', new URL( page.url() ).pathname, '/dash' );
	ok( 'nothing was raised looking', errors, [] );
	await page.locator( '.dze-tr-row' ).nth( 0 ).locator( '.dze-tr-open' ).click();
	await page.check( '.dze-tr-lang[value="de"]' );

	// A press with nothing ticked says so rather than doing nothing.
	before = sent.length;
	await page.click( '#dze-tr-send' );
	ok( 'a press with nothing ticked sends nothing', sent.length, before );

	// The real gesture: tick a row, tick the languages that are already on,
	// press, and read back WHAT WENT ON THE WIRE.
	await page.locator( '.dze-tr-row' ).nth( 0 ).locator( '.dze-tr-pickone' ).check();
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
	const rowSaid = await page.locator( '.dze-tr-row' ).nth( 0 ).locator( '.dze-tr-state' ).textContent();
	ok( 'the row that was sent says what came back',
		( rowSaid || '' ).includes( cfg.i18n.rowHeld ), true );
	ok( 'and no longer says it is not translated',
		( rowSaid || '' ).includes( 'not translated' ), false );
	ok( 'the row left alone is untouched',
		( await page.locator( '.dze-tr-row' ).nth( 1 ).locator( '.dze-tr-state' ).textContent() || '' ).includes( cfg.i18n.rowHeld ), false );
	ok( 'and the way to read what came back is offered',
		await page.locator( `#dze-tr-sendstate a[href="${cfg.reviewUrl}"]` ).count(), 1 );
	ok( 'nothing was raised sending a batch', errors, [] );

	// WPML'S OWN GESTURE, ONE LANGUAGE AT A TIME. The plus makes the missing
	// translation, the arrows bring an out-of-date one back — and it runs the
	// SAME job the batch button runs, never a second engine.
	ok( 'a language that is owed is a button',
		await page.locator( '.dze-tr-row' ).nth( 1 ).locator( 'button.dze-tr-one' ).count() > 0, true );
	before = sent.length;
	await page.locator( '.dze-tr-row' ).nth( 1 ).locator( 'button.dze-tr-one[data-lang="fr"]' ).click();
	await page.waitForFunction(
		() => !document.querySelectorAll( '.dze-tr-row' )[1].querySelector( 'button.dze-tr-one[data-lang="fr"]' ),
		null, { timeout: 6000 } ).catch( () => {} );
	const one = sent.slice( before ).filter( s => 'dze_tr_batch' === s.action );
	ok( 'pressing it sends exactly one job', one.length, 1 );
	ok( 'for the object of its own row', ( one[0] || {} ).ref, 'term:8:product_cat' );
	// AND ONLY THAT LANGUAGE. The other flag on the same row was not pressed
	// and must not be paid for.
	ok( 'and only the language pressed', ( one[0] || {} ).langs, [ 'fr' ] );
	ok( 'the chip says what came back',
		( await page.locator( '.dze-tr-row' ).nth( 1 ).locator( '.dze-tr-state' ).textContent() || '' ).includes( cfg.i18n.rowHeld ), true );
	ok( 'the other language of that row is still offered',
		await page.locator( '.dze-tr-row' ).nth( 1 ).locator( 'button.dze-tr-one[data-lang="de"]' ).count(), 1 );
	ok( 'nothing was raised pressing a flag', errors, [] );


	// THE TICK AT THE TOP TAKES THE LOT — on this screen like every other.
	await page.check( '#dze-tr-all' );
	ok( 'the heading tick takes every row',
		await page.locator( '.dze-tr-pickone:checked' ).count(), 2 );

	// ---- THE PRODUCT POPUP: ATTRIBUTES AND VARIATIONS ----
	// This screen had NO browser gate at all, which is why a bridge to
	// WooCommerce Multilingual could ship calling sync_product_variations()
	// with an empty fourth argument — the axes — and nobody could tell: "les
	// attributs produits et les variations ne sont toujours pas là sur le
	// produit traduit." A button is tested by BEING PRESSED.
	const popCfg = Object.assign( {}, popup.cfg, { ajaxUrl: 'http://dze.test/ajax' } );
	await page.route( 'http://dze.test/popup', r => r.fulfill( { contentType: 'text/html',
		body: `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style>`
			+ `<script>${readFileSync( jq, 'utf8' )}</script>`
			+ `<script>window.dzeTranslate=${JSON.stringify( popCfg )};</script>`
			+ `<script>${readFileSync( join( js, 'translate.js' ), 'utf8' )}</script></head>`
			+ `<body>${popup.html}</body></html>` } ) );
	await page.goto( 'http://dze.test/popup', { waitUntil: 'domcontentloaded' } );
	ok( 'the product popup runs without an error', errors, [] );
	// The popup is shut until something opens it — the hub's own delegated
	// opener, which is pressed for real further down. Here we are testing what
	// is INSIDE it, so it is put on screen the way that opener puts it there.
	await page.locator( '#dze-tr-modal' ).evaluate( el => el.classList.add( 'is-open' ) );

	// IT SAYS WHERE THE TRANSLATION STANDS before offering anything.
	ok( 'it says where each language stands on the variations',
		( await page.textContent( '.dze-tr-attrs' ) || '' ).includes( 'variations' ), true );
	ok( 'and offers the one repair there is',
		await page.locator( '#dze-tr-rebuild' ).count(), 1 );
	ok( 'which says under the hand that it spends nothing',
		( await page.getAttribute( '#dze-tr-rebuild', 'title' ) || '' ).includes( 'nothing is spent' ), true );

	before = sent.length;
	await page.click( '#dze-tr-rebuild' );
	await page.waitForFunction(
		() => !/…$/.test( ( document.querySelector( '#dze-tr-rebuildstate' ) || {} ).textContent || '…' ),
		null, { timeout: 6000 } ).catch( () => {} );
	const fix = sent.slice( before );
	ok( 'pressing it asks the server to rebuild', ( fix[0] || {} ).action, 'dze_tr_rebuild' );
	ok( 'for this product', ( fix[0] || {} ).post, String( popCfg.postId ) );
	ok( 'with its nonce', ( fix[0] || {} ).nonce, popCfg.nonce );
	// AND NOT ONE WORD WAS SENT TO A MODEL: the repair is free, and a press
	// that quietly spent money would be the fault this plugin has paid for.
	ok( 'and nothing was translated on the way',
		fix.filter( x => 'dze_tr_preview' === x.action || 'dze_tr_batch' === x.action ).length, 0 );
	ok( 'the row is rewritten with what it now holds',
		( await page.textContent( '.dze-tr-attrs tr[data-lang="fr"] .dze-tr-varcell' ) || '' ).includes( '3' ), true );
	ok( 'and the screen says it worked',
		( await page.textContent( '#dze-tr-rebuildstate' ) || '' ).length > 0, true );
	ok( 'nothing was raised rebuilding', errors, [] );

	// ---- "TRANSLATE WITH DAZONT ECOM", INSIDE WPML'S OWN LANGUAGE BOX ----
	// "Peut être ajouter directement une option par dessus wpml sur les blocs
	// wpml de traduction… Ce serait notre marque de fabrique." WPML's markup is
	// WPML's, so the only way to know the button lands in the right place — and
	// that pressing it opens anything — is to put a language box on a page and
	// press it.
	//
	// The opener is read out of class-modules.php rather than retyped: bound
	// directly instead of delegated it opens nothing, and a gate carrying its
	// own copy would never notice.
	const hubOpener = ( readFileSync( join( root, 'dazont-ecom', 'includes', 'class-modules.php' ), 'utf8' )
		.match( /jQuery\( function \( \$ \) \{[\s\S]*?\n\t\t\} \);/ ) || [ '' ] )[0];
	ok( 'the hub opener was found to test against', hubOpener.length > 0, true );

	const editScreen = ( box ) => `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style>`
		+ `<script>${readFileSync( jq, 'utf8' )}</script>`
		+ `<script>window.dzeTrBox=${JSON.stringify( box )};</script></head>`
		+ `<body><div id="post-body"><div id="icl_div"><div class="inside">`
		+ `<p>Language of this post</p></div></div></div>`
		+ `<div id="submitdiv"><div class="inside"><button>Update</button></div></div>`
		+ `<div class="dze-cx-modal" id="dze-tr-modal"><div class="dze-cx-dialog">`
		+ `<button type="button" class="button dze-hub-close">Close</button></div></div>`
		+ `<script>${hubOpener}</script>`
		+ `<script>${readFileSync( join( js, 'translate-box.js' ), 'utf8' )}</script>`
		+ `</body></html>`;

	// ON A PRODUCT: it opens the popup that is already on the page. Never a
	// second popup, and never a page it has to travel to.
	await page.route( 'http://dze.test/edit-product', r => r.fulfill( { contentType: 'text/html',
		body: editScreen( { popup: true, url: '', label: 'Translate with Dazont Ecom', tip: 'Opens the panel' } ) } ) );
	await page.goto( 'http://dze.test/edit-product', { waitUntil: 'domcontentloaded' } );
	ok( 'the edit screen runs without an error', errors, [] );
	ok( 'the button lands INSIDE WPML\'s own language box',
		await page.locator( '#icl_div .inside #dze-tr-box a, #icl_div .inside #dze-tr-box button' ).count(), 1 );
	ok( 'and not in the Publish box beside it',
		await page.locator( '#submitdiv #dze-tr-box' ).count(), 0 );
	ok( 'it says what it is', await page.textContent( '#dze-tr-box' ), 'Translate with Dazont Ecom' );
	ok( 'and what it will do, under the hand',
		await page.getAttribute( '#dze-tr-box .button', 'title' ), 'Opens the panel' );
	ok( 'the popup is shut until it is pressed',
		await page.locator( '#dze-tr-modal.is-open' ).count(), 0 );
	await page.click( '#dze-tr-box .button' );
	ok( 'pressing it opens the popup already on the page',
		await page.locator( '#dze-tr-modal.is-open' ).count(), 1 );
	ok( 'and the page never moved', new URL( page.url() ).pathname, '/edit-product' );
	ok( 'nothing was raised opening it', errors, [] );

	// ON EVERYTHING ELSE: a link to the screen that does this work, armed on
	// this one object. A BUTTON ON ONE OBJECT OPENS THE FUNCTION — it does not
	// run one, and it does not spend anything.
	const armed = 'https://kula.test/wp-admin/admin.php?page=dazont-ecom-translations&tab=dashboard&scope=term%3Aproduct_cat&only=term%3A7%3Aproduct_cat';
	await page.route( 'http://dze.test/edit-term', r => r.fulfill( { contentType: 'text/html',
		body: editScreen( { popup: false, url: armed, label: 'Translate with Dazont Ecom', tip: 'Opens the screen' } ) } ) );
	await page.goto( 'http://dze.test/edit-term', { waitUntil: 'domcontentloaded' } );
	before = sent.length;
	ok( 'on a category it is a link to the armed screen',
		await page.getAttribute( '#icl_div #dze-tr-box a', 'href' ), armed );
	ok( 'and it sent nothing on the way', sent.length, before );
	ok( 'nothing was raised on a term screen', errors, [] );

	// ---- THE WAITING LIST, AND THE DECISION ON IT ----
	holding = true;
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
