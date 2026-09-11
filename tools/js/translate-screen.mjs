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
const editor = dump( 'editor' );
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
			// What a save actually puts on the wire, field by field.
			keepTitle: q.get( 'keep[fr][title]' ), keepVar: q.get( 'keep[fr][var:701]' ),
			how: q.get( 'how' ), langs: q.getAll( 'langs[]' ),
			// What a decision actually puts on the wire, field by field.
			keepFr: q.get( 'keep[fr][name]' ), keepFrDesc: q.get( 'keep[fr][description]' )
		} );
		const json = d => route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: d } ) } );
		if ( 'dze_tr_batch' === q.get( 'action' ) ) {
			return json( { label: 'Balaclavas', done: [ 'fr' ], skipped: [], errors: {},
				texts: { fr: { title: 'Chemise de terrain', content: '<p>Une chemise.</p>', 'var:701': 'Olive, fermeture noire.' } } } );
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
		if ( 'dze_tr_decide' === q.get( 'action' ) ) {
			return json( { written: { fr: 8 }, errors: {}, left: 0, refused: 'refuse' === q.get( 'how' ),
				warnings: { fr: 'This product\'s variations are still missing on the translation.' } } );
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
	ok( 'the categories of the shop are listed',
		await page.locator( '.dze-tr-row' ).count(), 2 );
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

	// ONE SCREEN PER OBJECT, and the row is the way to it — never a panel
	// unfolding inside the list beside a popup on the product page.
	let before = sent.length;
	ok( 'the row is a link, not a panel that unfolds',
		await page.locator( '.dze-tr-row' ).nth( 0 ).locator( 'a.dze-tr-open' ).count(), 1 );
	ok( 'and it points at the one translation screen',
		( await page.locator( '.dze-tr-row' ).nth( 0 ).locator( 'a.dze-tr-open' ).getAttribute( 'href' ) || '' )
			.includes( 'ref=term%3A7%3Aproduct_cat' ), true );
	ok( 'no panel row is left in the list at all',
		await page.locator( '.dze-tr-panel' ).count(), 0 );
	ok( 'and nothing was asked of the server to say so', sent.length, before );

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

	// ---- THE ONE TRANSLATION SCREEN, PER OBJECT ----
	// "Cet écran c'est encore du custom. Je veux un seul écran pour chaque type
	// de post. Comme le fait wpml !" WPML's four steps, and this is the third:
	// translate it, read every field beside its original, save it. The popup
	// that stood here had no browser gate at all, which is exactly why it could
	// carry a block reporting its own plumbing for three releases.
	await page.route( 'http://dze.test/editor', r => r.fulfill( { contentType: 'text/html',
		body: serve( editor.html ) } ) );
	await page.goto( 'http://dze.test/editor', { waitUntil: 'domcontentloaded' } );
	ok( 'the translation screen runs without an error', errors, [] );
	ok( 'every field of the object is a row',
		await page.locator( '.dze-tr-field' ).count(), 3 );
	ok( 'and the variation is one of them',
		await page.locator( '.dze-tr-field[data-field="var:701"]' ).count(), 1 );

	// TRANSLATE IT: one press, and what comes back lands IN THE FIELDS.
	before = sent.length;
	await page.click( '#dze-tr-auto' );
	// WAIT FOR THE ANSWER, NEVER FOR THE LINE TO MERELY FILL: the busy text is
	// already in it, so "not empty" returns at once and every check after it
	// reads a screen still working — green or red by accident of timing.
	const autoDone = await page.waitForFunction(
		busy => {
			const t = ( ( document.querySelector( '#dze-tr-autostate' ) || {} ).textContent || '' ).trim();
			return t.length > 0 && t !== busy;
		},
		cfg.i18n.sending, { timeout: 6000 } ).then( () => true ).catch( () => false );
	ok( 'the automatic pass answered', autoDone, true );
	const made = sent.slice( before ).filter( x => 'dze_tr_batch' === x.action );
	ok( 'pressing Translate sends one job', made.length, 1 );
	ok( 'for this object', ( made[0] || {} ).ref, 'post:700:product' );
	ok( 'and only the language this screen is about', ( made[0] || {} ).langs, [ 'fr' ] );
	ok( 'what came back is IN the fields, not in a panel beside them',
		await page.inputValue( '.dze-tr-field[data-field="title"] .dze-tr-new' ), 'Chemise de terrain' );
	ok( 'the variation is filled in too',
		await page.inputValue( '.dze-tr-field[data-field="var:701"] .dze-tr-new' ), 'Olive, fermeture noire.' );
	ok( 'nothing was raised translating', errors, [] );

	// SAVE IT: what is ON SCREEN is what travels — including a word edited by
	// hand after the automatic pass, which is the whole point of the screen.
	await page.fill( '.dze-tr-field[data-field="title"] .dze-tr-new', 'Chemise de combat' );
	before = sent.length;
	await page.click( '#dze-tr-publish' );
	const saveDone = await page.waitForFunction(
		busy => {
			const t = ( ( document.querySelector( '#dze-tr-publishstate' ) || {} ).textContent || '' ).trim();
			return t.length > 0 && t !== busy;
		},
		cfg.i18n.saving, { timeout: 6000 } ).then( () => true ).catch( () => false );
	ok( 'the save answered', saveDone, true );
	const saved = sent.slice( before ).filter( x => 'dze_tr_decide' === x.action );
	ok( 'saving posts one decision', saved.length, 1 );
	ok( 'it is an acceptance', ( saved[0] || {} ).how, 'accept' );
	ok( 'and it carries the hand-edited word, not the machine\'s',
		( saved[0] || {} ).keepTitle, 'Chemise de combat' );
	ok( 'the variation travels with it',
		( saved[0] || {} ).keepVar, 'Olive, fermeture noire.' );
	// AND WHAT IS STILL WRONG WITH IT IS SAID — once, as the result of this
	// press, never as a permanent panel explaining our plumbing.
	ok( 'a translation left unbuyable says so after the save',
		await page.locator( '.dze-tr-warn' ).count(), 1 );
	ok( 'nothing was raised saving', errors, [] );
	ok( 'and the page never moved', new URL( page.url() ).pathname, '/editor' );

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

	// REVIEW OPENS THE OBJECT — on the one screen, and it decides nothing on
	// the way. It used to unfold a panel inside the row, which was a second
	// per-object surface beside the popup the product page carried.
	before = sent.length;
	ok( 'Review is a link to the one translation screen',
		( await page.locator( '.dze-tr-wrow a.dze-tr-open' ).getAttribute( 'href' ) || '' )
			.includes( 'ref=term%3A7%3Aproduct_cat' ), true );
	// EACH LANGUAGE IS ITS OWN WAY IN, because the screen reads one at a time.
	ok( 'and each waiting language is its own way in',
		( await page.locator( '.dze-tr-wrow a.dze-tr-chip' ).first().getAttribute( 'href' ) || '' )
			.includes( 'lang=fr' ), true );
	ok( 'no panel unfolds in the waiting list either',
		await page.locator( '.dze-tr-panel' ).count(), 0 );
	ok( 'and nothing was decided by looking', sent.length, before );
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
