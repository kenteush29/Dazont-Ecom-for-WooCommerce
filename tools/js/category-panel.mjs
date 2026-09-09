/**
 * The category panel's button row, PRESSED, on both jQuery builds.
 *
 * Run before every release:  node tools/js/category-panel.mjs
 *
 * This screen had NO browser gate at all, and it is the screen where "✎
 * questions" and "✎ linking" were buttons with nothing behind them for
 * months. The panel is served by AJAX; `DZE_Prompts::button()` asks for its
 * popup by hooking `admin_footer`, which never fires in an AJAX request. So
 * the buttons arrived on a page holding neither the popup nor the handler
 * that opens it: pressing them did nothing and said nothing, and no PHP test
 * and no `node --check` could ever see it — the markup was perfect.
 *
 * What is proved here is a press: the popup opens, it asks the server for THAT
 * prompt by name, and what comes back lands on the screen.
 *
 * The panel and the popup are both rendered by the plugin's own PHP
 * (tools/test-category.php --dump-panel), never copied into this file.
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

const dumped = execFileSync( 'php', [ join( here, '..', 'test-category.php' ), 'dazont-ecom', '--dump-panel' ],
	{ encoding: 'utf8', cwd: root } );
const [ panel, modal ] = dumped.split( '<!--MODAL-->' );

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [];
	const sent = [];
	// What the writing queue hands back. A second press can be answered with
	// nothing, which is how an EMPTY after is reached on a real path.
	// It links a page the picker actually OFFERS, which is what a real pass
	// does: the pass places what was ticked. A link to a page outside the pool
	// left every row untouched, so nothing on this screen could be seen to
	// lock — or to come back.
	let jobHtml = '<p>Bags for the field, and <a href="https://kula.test/category/boonie-hats/">Boonie hats</a>,'
		+ ' and <a href="https://kula.test/category/tactical-backpacks/">Tactical backpacks</a>.</p>';
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );

	await page.route( 'http://dze.test/ajax', async route => {
		const q = new URLSearchParams( route.request().postData() || '' );
		sent.push( {
			action: q.get( 'action' ), nonce: q.get( 'nonce' ), id: q.get( 'id' ),
			// The whole of the linking loop is in these three: what text the
			// press was made on, which pages were ticked, and which category.
			html: q.get( 'html' ), term: q.get( 'term' ), urls: q.getAll( 'urls[]' ), kind: q.get( 'kind' )
		} );
		const json = d => route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: d } ) } );
		// The writing queue: a job is added, then followed until it answers.
		if ( 'dze_q_add' === q.get( 'action' ) ) { return json( { added: 1, job: 5, url: '' } ); }
		if ( 'dze_q_job' === q.get( 'action' ) ) {
			return json( { status: 'review', html: jobHtml } );
		}
		// What the category holds TODAY, against what is in the editor now.
		if ( 'dze_cc_diff' === q.get( 'action' ) ) {
			const now = q.get( 'html' ) || '';
			return json( {
				before: '<p>Bags for the field.</p>',
				after: now,
				words: [ 4, now.split( /\s+/ ).filter( Boolean ).length ],
				links: [ 0, ( now.match( /<a\s/g ) || [] ).length ]
			} );
		}
		await route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
			label: 'Prompt: ' + q.get( 'id' ),
			text: 'The instructions for ' + q.get( 'id' ) + '.',
			def: 'shipped', note: '', editable: true, own: true, mine: false, url: '',
			data: [ 'The category: its name, and the queries it targets.' ]
		} } ) } );
	} );

	// THE SCREEN AS THE PLUGIN ASSEMBLES IT: the panel dropped in by AJAX, and
	// the popup the page itself printed at load — which is exactly what was
	// missing.
	// jQuery FIRST, and `ajaxurl` with it: the popup ships its own inline
	// script, which runs the moment the browser parses it. Added afterwards —
	// the way a test is tempted to add it — the whole handler dies on
	// "jQuery is not defined" and every button on the page goes quiet, which
	// is the very failure this file exists to catch.
	await page.route( 'http://dze.test/screen', async route => {
		await route.fulfill( { contentType: 'text/html', body:
			`<!doctype html><html><head><meta charset="utf-8"><style>${css}</style>`
			+ `<script>${readFileSync( jq, 'utf8' )}</script>`
			+ `<script>window.ajaxurl='http://dze.test/ajax';`
			// The panel's own script, and the config it reads at load. Without
			// it the picker's checkboxes are markup and nothing else: the
			// handlers that count them and take a range are in this file.
			// The config KEY BY KEY as the plugin localizes it: ajaxUrl, not
			// ajax. Named wrong, every request this panel makes goes to the
			// page itself and comes back as HTML — which is a screen where
			// nothing happens and nothing is said.
			+ `window.dzeCatContent={ajaxUrl:'http://dze.test/ajax',nonce:'n0nce',kwNonce:'k',home:'http://dze.test/',`
			+ `i18n:{picked:'%s selected',before:'Before',after:'After',nothingYet:'Nothing written yet',waitingYet:'A text is waiting — press "Load it here" above',wl:'%1$s words · %2$s links',hide:'hide',show:'show',`
			+ `wasEmpty:'This category had no description.',queuedShort:'Queued',linking:'Linking',working:'Writing',`
			+ `review:'Look it over',error:'error',alreadyLinked:'already linked',showLinks:'%s links',external:'external'}};</script>`
			// The shell first — the blocks and the before/after are one module
			// now, and the panel's script is built on it.
			+ `<script>${readFileSync( join( js, 'hub.js' ), 'utf8' )}</script>`
			+ `<script>${readFileSync( join( js, 'category-content.js' ), 'utf8' )}</script></head>`
			+ `<body><div class="wrap"><div id="panel"></div></div>${modal}</body></html>` } );
	} );
	await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );
	// Only now does the panel arrive, the way an AJAX answer arrives — after
	// the page that will have to handle its buttons is already standing.
	await page.evaluate( html => { document.getElementById( 'panel' ).innerHTML = html; }, panel );

	ok( 'the screen runs without an error', errors, [] );
	ok( 'the popup is on the page before the panel is',
		await page.locator( '#dze-prompt-modal' ).count(), 1 );
	ok( 'and it is shut',                   await page.locator( '#dze-prompt-modal.is-open' ).count(), 0 );

	// ONE SHAPE FOR THE WHOLE SHOP: the panel is blocks now, like the product
	// screens. Everything a person can press lives inside one, so the blocks
	// are opened the way a person opens them — by pressing their heading.
	ok( 'the panel is built of blocks',
		await page.locator( '#panel .dze-sec' ).count() >= 2, true );
	for ( const id of [ 'cc-desc', 'cc-links' ] ) {
		const head = `#panel .dze-sec[data-sec="${id}"] .dze-sec-head`;
		if ( ! await page.locator( `#panel .dze-sec[data-sec="${id}"].is-open` ).count() ) {
			await page.click( head );
		}
		ok( `the ${id} block opens`,
			await page.locator( `#panel .dze-sec[data-sec="${id}"].is-open` ).count(), 1 );
	}

	// A CONTROL IS TESTED ON WHAT IT DOES. Every one of these was on the page
	// and did nothing.
	// ONE PROMPT PER BLOCK, and both read the same way. "✎ questions" is gone
	// with cat_sift — that prompt is not a way of writing this page, it is the
	// filter deciding WHICH imported questions reach the writer, and it is
	// edited in Settings → Categories: "je ne comprends pas ce que fait ce
	// prompt ici."
	for ( const [ name, id ] of [ [ '✎ prompt (description)', 'cat_desc' ], [ '✎ prompt (linking)', 'cat_links' ] ] ) {
		const before = sent.length;
		await page.click( `#panel .dze-prompt-peek[data-prompt="${id}"]` );
		ok( `"${name}" opens the popup`,
			await page.locator( '#dze-prompt-modal.is-open' ).count(), 1 );
		const landed = await page.waitForFunction(
			want => ( document.querySelector( '#dze-prompt-text' ).value || '' ).includes( want ),
			id, { timeout: 4000 }
		).then( () => true ).catch( () => false );
		ok( 'and the answer lands on it',   landed, true );
		ok( `and asks the server for ${id}`, sent.slice( before ).map( s => s.action + ' ' + s.id ),
			[ 'dze_prompt_peek ' + id ] );
		// A REQUEST WHOSE ANSWER GOES NOWHERE IS THE SAME BROKEN BUTTON from
		// the shop's chair.
		ok( 'and what came back is on the screen',
			( await page.inputValue( '#dze-prompt-text' ) ).includes( id ), true );
		ok( 'named on the popup',           await page.textContent( '#dze-prompt-title' ), 'Prompt: ' + id );
		await page.click( '#dze-prompt-modal .dze-hub-close' );
		ok( 'and it shuts again',           await page.locator( '#dze-prompt-modal.is-open' ).count(), 0 );
	}

	// EVERY CONTROL READS THE SAME WAY. It was a lone pencil, a lone ⓘ and two
	// worded buttons — three ways of saying "look at something". They live in
	// the block each of them is about now, and every one still carries a word.
	const words = await page.evaluate( () => Array.from(
		document.querySelectorAll( '#panel .dze-prompt-peek' ) ).map( b => b.textContent.trim() ) );
	ok( 'one prompt control per block',      words.length, 2 );
	// AND NONE OF THEM IS A PROMPT BUTTON WITH NO PROMPT BEHIND IT. "ⓘ what
	// it uses" wore this very class with no id, so the popup's own handler
	// picked it up and answered "This prompt could not be read".
	ok( 'and every one names a prompt',
		await page.locator( '#panel .dze-prompt-peek:not([data-prompt])' ).count(), 0 );
	ok( 'and none of them a bare symbol',   words.filter( w => ! /\s/.test( w ) ), [] );
	// AND EACH IN THE BLOCK IT IS ABOUT: the linking prompt belongs to the
	// links block, not to a row of buttons at the top of the screen.
	ok( 'the linking prompt is in the links block',
		await page.locator( '#panel .dze-sec[data-sec="cc-links"] .dze-prompt-peek[data-prompt="cat_links"]' ).count(), 1 );
	ok( 'and the writing prompt in the description block',
		await page.locator( '#panel .dze-sec[data-sec="cc-desc"] .dze-prompt-peek[data-prompt="cat_desc"]' ).count(), 1 );
	// AND EACH SITS IN THE ROW THE PRODUCT SCREENS USE, not on a line of four
	// controls of four different kinds: "je veux un écran similaire à celui
	// utilisé pour les produits."
	ok( 'both sit on a prompt row of their own block',
		await page.locator( '#panel .dze-cc-promptrow' ).count(), 2 );
	// AND THAT ROW DOES NOT PRETEND TO BE AN ORDER OF WORK. .dze-tplrow tells
	// hub.js to count a block by its rows instead of its ticks, and this
	// block's work is the pages ticked under it: borrowing the class made the
	// heading read "0 / 1" over three ticked pages.
	ok( 'and it is not counted as one',
		await page.locator( '#panel .dze-cc-promptrow.dze-tplrow, #panel .dze-cc-promptrow .dze-tplrow' ).count(), 0 );

	// ---- SHIFT TAKES A RANGE ----
	//
	// "Sur la sélection des links je ne peux pas utiliser MAJ pour en
	// sélectionner plusieurs d'un coup." Thirty pages are offered here and
	// they were ticked one at a time. A modifier key exists only under a real
	// mouse press: no PHP test and no `node --check` can see this.
	const boxes = page.locator( '#panel .dze-cc-pick:not([disabled])' );
	const many  = await boxes.count();
	ok( 'the picker offers a list to tick',  many >= 3, true );
	// Start from nothing, so what the range does is the only thing on screen.
	await page.click( '#panel .dze-cc-picknone' );
	ok( 'and Clear empties it',              await page.locator( '#panel .dze-cc-pick:checked' ).count(), 0 );
	await boxes.nth( 0 ).click();
	await boxes.nth( many - 1 ).click( { modifiers: [ 'Shift' ] } );
	ok( 'shift takes everything between',
		await page.locator( '#panel .dze-cc-pick:checked:not([disabled])' ).count(), many );
	// AND THE COUNT FOLLOWS IT, in the BLOCK'S OWN HEADING — where every block
	// of this plugin carries what it will do. A second figure beside the ticks
	// would be two accounts of one thing on one screen.
	ok( 'and the block says how many it will place',
		( await page.textContent( '#panel .dze-sec[data-sec="cc-links"] .dze-sec-count' ) ).trim(),
		many + ' / ' + many );
	// IT UNTICKS A RUN TOO: the state of the box just pressed is the state the
	// whole range takes.
	await boxes.nth( 0 ).click();
	await boxes.nth( many - 1 ).click( { modifiers: [ 'Shift' ] } );
	ok( 'shift lets a run go as well',
		await page.locator( '#panel .dze-cc-pick:checked:not([disabled])' ).count(), 0 );
	// A ROW ALREADY LINKED IS NOT A ROW TO TICK: it is disabled, and a range
	// running over it must leave it exactly as it was — ticked, and never
	// counted among what the press will send.
	const locked = await page.locator( '#panel .dze-cc-pick[disabled]' ).count();
	await boxes.nth( 0 ).click();
	await boxes.nth( many - 1 ).click( { modifiers: [ 'Shift' ] } );
	ok( 'a row already linked keeps its own state',
		await page.locator( '#panel .dze-cc-pick[disabled]:checked' ).count(), locked );
	ok( 'and is never counted in what will be sent',
		( await page.textContent( '#panel .dze-sec[data-sec="cc-links"] .dze-sec-count' ) ).trim(),
		many + ' / ' + many );
	// AND THE GESTURE IS ON THE SCREEN. One nobody is told about is one
	// nobody has.
	ok( 'the row says the gesture exists',
		( await page.textContent( '#panel .dze-cc-pickhint' ) ).trim(), 'Shift-click takes a range.' );
	ok( 'and nothing was raised doing it',   errors, [] );

	// ---- THE WHOLE LINKING LOOP, PRESSED ----
	//
	// "Before / after — hide — 0 words · 0 links. Pour le netlinking je ne
	// comprends pas, je ne vois pas le texte actuel. Repasse toute la boucle
	// en revue, revois tout, du début à la fin."
	//
	// The job carried the term id and NOTHING ELSE, so the linking pass read
	// the description out of the database — never the text in this editor,
	// which is not saved until Update is pressed. Nothing but a browser can
	// see what a press actually puts on the wire.
	const heldNow = await page.inputValue( '#dze-cc-editor' );
	ok( 'the panel opens on what the category holds',
		heldNow.includes( 'Bags for the field' ), true );
	await page.check( '#panel .dze-cc-pick:not([disabled])' );
	// ONE BUTTON, RUNNING WHAT IS TICKED. Only the links block here, so the
	// job that goes out must be the linking pass and nothing else.
	await page.uncheck( '#dze-cc-do-desc' );
	await page.check( '#dze-cc-do-links' );
	const beforePress = sent.length;
	await page.click( '#panel .dze-cc-run' );
	// The queue is followed on a timer, so the answer is waited FOR — with a
	// bound, and reported rather than left to kill the run.
	const answered = await page.waitForFunction(
		() => ( document.querySelector( '#dze-cc-editor' ) || {} ).value?.includes( '<a href' ),
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'the press comes back with the linked text', answered, true );
	const asked = sent.slice( beforePress ).find( r => 'dze_q_add' === r.action ) || {};
	ok( 'the job is on this category',       asked.id, '10' );
	ok( 'and it is the linking pass',        asked.kind, 'cat_links' );
	// THE HALF THAT WAS MISSING.
	ok( 'and it carries the text on the screen',
		( asked.html || '' ).includes( 'Bags for the field' ), true );
	ok( 'with the page that was ticked',     asked.urls.length > 0, true );
	// AND THE SCREEN SHOWS WHAT THE CATEGORY HELD, visibly — the block was
	// there, opened, and empty.
	const shown = await page.waitForSelector( '#panel .dze-cc-diff .dze-cb-nowbody', { timeout: 4000 } )
		.then( () => true ).catch( () => false );
	ok( 'the before/after block shows the current text', shown, true );
	ok( 'and it is really on the screen',
		await page.evaluate( () => {
			const el = document.querySelector( '#panel .dze-cc-diff .dze-cb-nowbody' );
			return !! el && el.offsetHeight > 0 && ( el.textContent || '' ).trim().length > 0;
		} ), true );
	// A count that reads 0 · 0 over a text that is plainly there is the screen
	// disagreeing with itself.
	ok( 'and the count is not nought over a written page',
		( await page.textContent( '#panel .dze-cc-diffwords' ) ).trim().startsWith( '0 words' ), false );
	// BEFORE **AND** AFTER. The block was called "Before / after" and printed
	// one document: "aucun avant/après juste un avant". On the category screen
	// the new text lands in the Description field above, so the panel showed
	// the old one and a "0 words · 0 links" that read as a broken screen.
	ok( 'the block shows two documents',
		await page.locator( '#panel .dze-cc-diff .dze-cb-nowbody' ).count(), 2 );
	const labels = await page.evaluate( () => Array.from(
		document.querySelectorAll( '#panel .dze-cc-diff .dze-cb-nowlabel' ) ).map( e => e.textContent.trim() ) );
	ok( 'the first is what the category holds',  labels[0].startsWith( 'Before —' ), true );
	ok( 'the second is what was written',        labels[1].startsWith( 'After —' ), true );

	ok( 'each carrying its own figures',
		labels[0] !== labels[1] && /\d+ words/.test( labels[1] ), true );
	ok( 'and the after really holds the new text',
		( await page.textContent( '#panel .dze-cc-diff .dze-cb-nowtext:nth-of-type(2) .dze-cb-nowbody' ) ).includes( 'Boonie hats' ), true );
	// A WAY TO REFUSE. The handler has existed for months and the button was
	// printed on neither screen.
	// Reported, not fatal: a gate that dies on the button it is about says
	// nothing about the checks after it.
	const canRefuse = await page.locator( '#panel .dze-cc-revert' ).count();
	ok( 'the panel offers to put it back',       canRefuse, 1 );
	// A LINKING PASS LOCKS THE ROWS IT JUST WROTE: markPlaced() ticks them and
	// disables them as "already linked". That is right while the text stands.
	const lockedAfter = await page.locator( '#panel .dze-cc-pick[disabled]' ).count();
	ok( 'the pages it linked are marked as linked', lockedAfter > locked, true );
	const beforeRefuse = sent.length;
	if ( canRefuse ) {
		await page.click( '#panel .dze-cc-revert' );
		await page.waitForTimeout( 250 );
	}
	// It puts back what the panel was opened on — captured when the popup
	// opens, which this harness does not replay, so what is asserted here is
	// the refusal itself: what was generated is gone.
	ok( 'and pressing it drops what was written',
		( await page.inputValue( '#dze-cc-editor' ) ).includes( '<a href' ), false );
	// A REFUSAL PUTS THE WHOLE SCREEN BACK, not the half of it that is text.
	//
	// "Il semble avoir fermé les aperçus des textes. Mais c'est tout. Et le
	// bouton reste ensuite bloqué sur ce texte. Je voulais recommencer
	// l'opération de maillage interne pour tester avec mon prompt
	// personnellement optimisé, mais je ne comprends pas comment faire." He
	// could not: every row the pass had just written stayed disabled, so the
	// pages could never be chosen again.
	ok( 'the rows it locked are choosable again',
		await page.locator( '#panel .dze-cc-pick[disabled]' ).count(), locked );
	ok( 'and the run can really be laid out again',
		await page.evaluate( () => {
			const b = document.querySelector( '#panel .dze-cc-pick:not([disabled])' );
			if ( ! b ) { return false; }
			b.checked = true;
			return ! b.disabled && b.checked;
		} ), true );
	// AND THE REFUSAL REACHES THE STORE, or the panel announces the same
	// waiting text on every open with no way to be rid of it.
	ok( 'and the queue is told it was refused',
		sent.slice( beforeRefuse ).map( r => r.action ), [ 'dze_cc_refuse' ] );

	// AN EMPTY AFTER SAYS WHICH EMPTY IT IS. When a finished text is sitting
	// in the queue the panel says so in a notice, and "Nothing written yet"
	// underneath was the screen disagreeing with itself on one page.
	await page.evaluate( () => {
		// jQuery caches data(), so the panel is told the way the server tells
		// it — the attribute — and the cache is dropped with it.
		const box = document.querySelector( '#panel .dze-cc-box' );
		box.setAttribute( 'data-waiting', '1' );
		window.jQuery( box ).removeData( 'waiting' );
	} );
	// A real press again, answered with nothing: the after is empty and the
	// block is redrawn by the same path the screen uses.
	jobHtml = '';
	await page.click( '#panel .dze-cc-run' );
	await page.waitForFunction(
		() => /waiting/i.test( document.querySelector( '#panel .dze-cc-diffwords' ).textContent || '' ),
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'an empty after points at the text that is waiting',
		( await page.textContent( '#panel .dze-cc-diffwords' ) ).includes( 'A text is waiting' ), true );
	ok( 'and says it where the after would be',
		( await page.textContent( '#panel .dze-cc-diff' ) ).includes( 'Load it here' ), true );
	// AND NOTHING WAS RAISED ON THE WAY. This is the check that would have
	// caught it on the day: one TypeError on the first link killed every line
	// after it in that handler, and the screen simply stopped moving.
	ok( 'nothing was raised anywhere in the loop', errors, [] );

	// AND THE SECOND SURFACE FOR WHAT THE PROMPT POPUP ALREADY SAYS IS GONE.
	ok( '"what it uses" is not on the screen at all',
		await page.locator( '#panel .dze-cc-data' ).count(), 0 );
	// AND AN ADVISORY IS A LINE THAT OPENS. Two full boxes stood at the top of
	// this panel saying the text could still be written; what folds here is
	// whichever of them this shop is in.
	ok( 'the advisory folds rather than shouting',
		await page.locator( '#panel details.dze-cc-note' ).count() >= 1, true );
	ok( 'and it is shut when the panel opens',
		await page.locator( '#panel details.dze-cc-note[open]' ).count(), 0 );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
