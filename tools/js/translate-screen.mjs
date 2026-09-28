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
const editor = dump( 'editor' );
// The plugin's own config, with only the address the harness has to answer on
// replaced. Retyping the rest is how a gate goes green while proving nothing.
const cfg = Object.assign( {}, dash.cfg, { ajaxUrl: 'http://dze.test/ajax' } );

// WHAT THE FAKE SERVER ANSWERS, in the shapes the plugin's own handlers send.
const cellQueued = '<span class="dze-trd-box" data-lang="fr" data-state="stale"><a class="dze-trd-ico is-stale" href="#"><span class="dashicons dashicons-update"></span></a></span>'
	+ '<span class="dze-trd-box" data-lang="de" data-state="queued"><button type="button" class="dze-trd-ico is-queued dze-trd-cancel" data-lang="de"><span class="dze-trd-spin"></span></button></span>';
const cellBack = '<span class="dze-trd-box" data-lang="fr" data-state="stale"><a class="dze-trd-ico is-stale" href="#"><span class="dashicons dashicons-update"></span></a></span>'
	+ '<span class="dze-trd-box" data-lang="de" data-state="missing"><a class="dze-trd-ico is-missing" href="#"><span class="dashicons dashicons-info-outline"></span></a></span>';
const wordsOf = {
	'term:7:product_cat': { fr: { s: 'stale', o: 12, a: 20, co: 0.001, ca: 0.002 }, de: { s: 'missing', o: 20, a: 20, co: 0.002, ca: 0.002 } },
	'term:8:product_cat': { fr: { s: 'done', o: 0, a: 10, co: 0, ca: 0.001 }, de: { s: 'missing', o: 10, a: 10, co: 0.001, ca: 0.001 } },
	'term:9:product_cat': { fr: { s: 'missing', o: 5, a: 5, co: 0.0005, ca: 0.0005 }, de: { s: 'missing', o: 5, a: 5, co: 0.0005, ca: 0.0005 } },
};

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [], sent = [];
	// When set, the fake server refuses every batch — the way a shop with no
	// key or WPML silent answers.
	let failing = false;
	// Whether the queue still holds the language that was sent.
	let queued = false;
	// The review list's object IS holding something.
	let holding = false;
	let before = 0;
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );
	page.on( 'dialog', d => d.accept() );

	await page.route( 'http://dze.test/ajax', async route => {
		const q = new URLSearchParams( route.request().postData() || '' );
		const act = q.get( 'action' );
		sent.push( {
			action: act, nonce: q.get( 'nonce' ), ref: q.get( 'ref' ), lang: q.get( 'lang' ),
			refs: q.getAll( 'refs[]' ), langs: q.getAll( 'langs[]' ),
			accept: q.get( 'accept' ), all: q.get( 'all' ), wantRefs: q.get( 'refs' ),
			per: q.get( 'per' ), paged: q.get( 'paged' ), search: q.get( 'search' ), tstatus: q.get( 'tstatus' ),
			how: q.get( 'how' ),
			// What a save actually puts on the wire, field by field.
			keepTitle: q.get( 'keep[fr][title]' ), keepVar: q.get( 'keep[fr][var:701]' ),
		} );
		const json = d => route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: d } ) } );
		const queue = () => ( { n: queued ? 1 : 0, busy: queued, errors: 0, last: '', review: 0 } );
		if ( 'dze_tr_words' === act ) {
			const items = {};
			q.getAll( 'refs[]' ).forEach( r => { if ( wordsOf[ r ] ) { items[ r ] = wordsOf[ r ]; } } );
			return json( { items } );
		}
		if ( 'dze_tr_items' === act && q.get( 'refs' ) ) {
			return json( { refs: Object.keys( wordsOf ), found: 3 } );
		}
		if ( 'dze_tr_items' === act ) {
			return json( {
				rows: '<tr class="dze-trd-row" data-ref="term:9:product_cat"><th scope="row" class="check-column"><input type="checkbox" class="dze-trd-pick" value="term:9:product_cat" /></th><td class="dze-trd-title"><a href="#">Knee pads</a></td><td class="dze-objid-td">9</td><td class="dze-trd-langs">' + cellBack + '</td></tr>',
				pager: '<span class="displaying-num">1 item</span>', found: 1, label: '1',
			} );
		}
		if ( 'dze_tr_queue' === act && failing ) {
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: false, data: { message: 'No Anthropic key.' } } ) } );
		}
		if ( 'dze_tr_queue' === act ) {
			queued = true;
			return json( { queued: 1, sent: { 'term:7:product_cat': [ 'de' ] }, queue: queue(), message: '1 item sent to translation.' } );
		}
		if ( 'dze_tr_status' === act ) {
			const cells = {};
			q.getAll( 'refs[]' ).forEach( r => { cells[ r ] = queued && 'term:7:product_cat' === r ? cellQueued : cellBack; } );
			return json( { cells, queue: queue() } );
		}
		if ( 'dze_tr_cancel' === act ) {
			queued = false;
			return json( { cell: cellBack, queue: queue(), message: 'Taken out of the queue.' } );
		}
		if ( 'dze_tr_batch' === act ) {
			return json( { label: 'Field shirt', done: [ 'fr' ], skipped: [], errors: {},
				texts: { fr: { title: 'Chemise de terrain', content: '<p>Une chemise.</p>', 'var:701': 'Olive, fermeture noire.' } } } );
		}
		if ( 'dze_tr_decide' === act ) {
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
	const hidden = sel => page.evaluate( s => { const el = document.querySelector( s ); return el ? el.hidden : null; }, sel );
	const settle = () => page.waitForTimeout( 450 );

	// ---- STEP 1: SELECT ITEMS FOR TRANSLATION ----
	await page.route( 'http://dze.test/dash', r => r.fulfill( { contentType: 'text/html', body: serve( dash.html ) } ) );
	await page.goto( 'http://dze.test/dash', { waitUntil: 'domcontentloaded' } );
	await page.evaluate( () => { try { sessionStorage.clear(); localStorage.clear(); } catch ( e ) {} } );
	await page.goto( 'http://dze.test/dash', { waitUntil: 'domcontentloaded' } );
	ok( 'the dashboard runs without an error', errors, [] );
	ok( 'the categories that need work are listed', await page.locator( '.dze-trd-row' ).count(), 2 );
	ok( 'each row carries one icon per language',
		await page.locator( '.dze-trd-row' ).nth( 0 ).locator( '.dze-trd-langs .dze-trd-box' ).count(), 2 );
	ok( 'Step 2 waits until something is ticked', await hidden( '#dze-trd-step2' ), true );

	// ---- TICK ONE: THE BAR AND STEP 2 APPEAR, THE WORDS ARE COUNTED ----
	await page.locator( '.dze-trd-row' ).nth( 0 ).locator( '.dze-trd-pick' ).check();
	await settle();
	ok( 'ticking shows Step 2', await hidden( '#dze-trd-step2' ), false );
	ok( 'and the bar says one is selected', ( await page.textContent( '#dze-trd-selcount' ) || '' ).trim(), cfg.i18n.oneSelected );
	const asked = sent.filter( s => 'dze_tr_words' === s.action );
	ok( 'the words of the ticked row are asked for', ( asked[ asked.length - 1 ] || {} ).refs, [ 'term:7:product_cat' ] );
	ok( 'with the nonce', ( asked[ asked.length - 1 ] || {} ).nonce, cfg.nonce );
	// NOTHING IS SET TO TRANSLATE UNTIL SOMEBODY SAYS SO: « je l'ai envoyé
	// seulement en RU » — five languages were ticked by default.
	ok( 'no language is set to translate by default',
		await page.$$eval( '.dze-trd-method', els => els.map( e => e.value ) ), [ 'none', 'none' ] );
	ok( 'so the button cannot be pressed', await page.isDisabled( '#dze-trd-send' ), true );
	ok( 'and the summary says what is missing', ( await page.textContent( '#dze-trd-sum' ) || '' ).trim(), cfg.i18n.pickLang );
	ok( 'the words to translate are shown per language',
		( await page.textContent( '.dze-trd-pairs tr[data-lang="de"] .dze-trd-words' ) || '' ).trim(), '20' );

	// ---- CHOOSE GERMAN ----
	await page.selectOption( '.dze-trd-method[data-lang="de"]', 'auto' );
	ok( 'choosing a language enables the button', await page.isDisabled( '#dze-trd-send' ), false );
	ok( 'the summary names the words and the language',
		( await page.textContent( '#dze-trd-sum' ) || '' ).includes( '20' ), true );
	ok( 'and a language that does nothing costs nothing',
		( await page.textContent( '.dze-trd-pairs tr[data-lang="fr"] .dze-trd-cost' ) || '' ).trim(), '–' );
	// « Some of the content you want to translate is already translated » —
	// only when it is true.
	ok( 'the « already translated » box stays hidden when nothing is', await hidden( '#dze-trd-existing' ), true );

	// ---- TRANSLATE CONTENT ----
	await page.click( '#dze-trd-send' );
	const sentGone = await page.waitForFunction( () => document.querySelector( '#dze-trd-step2' ).hidden, null, { timeout: 6000 } )
		.then( () => true ).catch( () => false );
	const q = sent.filter( s => 'dze_tr_queue' === s.action );
	ok( 'one request deposits the lot', q.length, 1 );
	ok( 'the ticked object', ( q[0] || {} ).refs, [ 'term:7:product_cat' ] );
	ok( 'into the language chosen and no other', ( q[0] || {} ).langs, [ 'de' ] );
	ok( 'waiting for review, as it was set', ( q[0] || {} ).accept, '0' );
	ok( 'and leaving existing translations alone', ( q[0] || {} ).all, '0' );
	ok( 'the selection is forgotten once sent', sentGone, true );
	ok( 'the page says what happened', ( await page.textContent( '#dze-trd-sent' ) || '' ).includes( '1 item sent' ), true );
	// THE ROW SAYS IT AT ONCE, without a reload.
	const turned = await page.waitForFunction(
		() => !!document.querySelector( '.dze-trd-row[data-ref="term:7:product_cat"] .dze-trd-spin' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	ok( 'the language sent turns on its row at once', turned, true );
	ok( 'the background line shows', await hidden( '#dze-trd-progress' ), false );
	ok( 'nothing was raised sending', errors, [] );

	// ---- TAKE IT BACK BEFORE IT COSTS ANYTHING ----
	await page.click( '.dze-trd-row[data-ref="term:7:product_cat"] .dze-trd-cancel' );
	const back = await page.waitForFunction(
		() => !document.querySelector( '.dze-trd-row[data-ref="term:7:product_cat"] .dze-trd-spin' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	const c = sent.filter( s => 'dze_tr_cancel' === s.action );
	ok( 'the wheel takes that language back', ( c[0] || {} ).lang, 'de' );
	ok( 'of that row', ( c[0] || {} ).ref, 'term:7:product_cat' );
	ok( 'and the row stops turning', back, true );
	ok( 'the background line goes', await hidden( '#dze-trd-progress' ), true );

	// ---- SELECT ALL, EVERY SECTION, EVERY PAGE ----
	await page.click( '#dze-trd-selectall' );
	await page.waitForFunction( () => !document.querySelector( '#dze-trd-selectall' ).disabled, null, { timeout: 6000 } ).catch( () => {} );
	ok( 'Select All asks every ref the filters match',
		sent.filter( s => 'dze_tr_items' === s.action && s.wantRefs ).length, 1 );
	ok( 'and takes them all, off this page too',
		( await page.textContent( '#dze-trd-selcount' ) || '' ).includes( '3' ), true );
	await settle();
	// « Leave / Overwrite » appears because one of them is complete in French.
	await page.selectOption( '#dze-trd-setall', 'auto' );
	ok( '« Set all » sets every language', await page.$$eval( '.dze-trd-method', els => els.map( e => e.value ) ), [ 'auto', 'auto' ] );
	ok( 'the « already translated » box appears when it is true', await hidden( '#dze-trd-existing' ), false );
	const leave = ( await page.textContent( '.dze-trd-pairs tr[data-lang="fr"] .dze-trd-words' ) || '' ).trim();
	await page.check( 'input[name="dze-trd-existing"][value="overwrite"]' );
	const over = ( await page.textContent( '.dze-trd-pairs tr[data-lang="fr"] .dze-trd-words' ) || '' ).trim();
	ok( 'leaving what is translated counts only what is owed', leave, '17' );
	ok( 'overwriting counts every word', over, '35' );

	// ---- A SECTION PAGES ON ITS OWN, AND THE TICKS SURVIVE ----
	await page.selectOption( '.dze-trd-sec .dze-trd-per', '20' );
	await page.waitForFunction( () => !!document.querySelector( '.dze-trd-row[data-ref="term:9:product_cat"]' ), null, { timeout: 6000 } ).catch( () => {} );
	const it = sent.filter( s => 'dze_tr_items' === s.action && !s.wantRefs );
	ok( 'the section asks for its new page size', ( it[ it.length - 1 ] || {} ).per, '20' );
	ok( 'through the dashboard\'s own filters', ( it[ it.length - 1 ] || {} ).tstatus, cfg.filters.tstatus );
	ok( 'a row ticked elsewhere is still ticked when it comes into view',
		await page.isChecked( '.dze-trd-row[data-ref="term:9:product_cat"] .dze-trd-pick' ), true );
	// AND A RELOAD FORGETS NOTHING: the ticks and the choices come back.
	await page.goto( 'http://dze.test/dash', { waitUntil: 'domcontentloaded' } );
	await settle();
	ok( 'the selection survives a reload', ( await page.textContent( '#dze-trd-selcount' ) || '' ).includes( '3' ), true );
	ok( 'and so do the choices', await page.$$eval( '.dze-trd-method', els => els.map( e => e.value ) ), [ 'auto', 'auto' ] );
	await page.click( '#dze-trd-clearsel' );
	ok( 'Clear selection empties it', await hidden( '#dze-trd-selbar' ), true );

	// A SEND THE SERVER REFUSES SAYS WHY, and keeps the selection.
	failing = true;
	await page.locator( '.dze-trd-row' ).nth( 1 ).locator( '.dze-trd-pick' ).check();
	await settle();
	await page.click( '#dze-trd-send' );
	const refused = await page.waitForFunction(
		() => /No Anthropic key/.test( ( document.querySelector( '#dze-trd-sendsaid' ) || {} ).textContent || '' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	ok( 'a refused send says why', refused, true );
	ok( 'and keeps what was ticked', await hidden( '#dze-trd-step2' ), false );
	failing = false;
	ok( 'nothing was raised on the dashboard', errors, [] );

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
		cfg.i18n.translating, { timeout: 6000 } ).then( () => true ).catch( () => false );
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
	// THE CHIP FOLLOWS THE SAVE: it read "not translated" until the page was
	// reloaded, a screen disagreeing with the work it had just done.
	ok( 'the state chip now says what the lists say',
		( ( await page.textContent( '.dze-tr-editstate > .dze-tr-chip' ) ) || '' ).includes( cfg.i18n.stateDone ), true );
	ok( 'and wears the done class',
		( await page.getAttribute( '.dze-tr-editstate > .dze-tr-chip', 'class' ) || '' ).includes( 'is-done' ), true );
	ok( 'nothing was raised saving', errors, [] );
	ok( 'and the page never moved', new URL( page.url() ).pathname, '/editor' );

	// CANCEL PUTS BACK WHAT THE TRANSLATION HOLDS. "Thrown away" over fields
	// still holding the thrown-away text was a screen that lies.
	await page.fill( '.dze-tr-field[data-field="title"] .dze-tr-new', 'Une bêtise' );
	before = sent.length;
	await page.click( '#dze-tr-drop' );
	const dropped = await page.waitForFunction(
		word => ( ( document.querySelector( '#dze-tr-publishstate' ) || {} ).textContent || '' ).trim() === word,
		cfg.i18n.dropped, { timeout: 6000 } ).then( () => true ).catch( () => false );
	ok( 'cancel answers', dropped, true );
	ok( 'it posted a refusal', ( sent.slice( before ).filter( x => 'dze_tr_decide' === x.action )[0] || {} ).how, 'refuse' );
	ok( 'and the field holds the saved words again, not the thrown-away ones',
		await page.inputValue( '.dze-tr-field[data-field="title"] .dze-tr-new' ), 'Chemise de combat' );
	ok( 'nothing was raised cancelling', errors, [] );

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
