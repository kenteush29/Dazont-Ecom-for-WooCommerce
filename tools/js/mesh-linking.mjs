/**
 * The Linking tab's buttons, PRESSED, in a browser, on both jQuery builds.
 *
 * Run before every release:  node tools/js/mesh-linking.mjs
 *
 * A control is tested on what it DOES, never on the fact that it exists. This
 * screen's whole promise is that a press OPENS a choice rather than running
 * one — the fault that had to be fixed on the diagnostic screen after "rien
 * de contrôlable visible à l'appui sur le bouton" — so what is proved here is:
 *
 *   - "Link to it" makes ONE request, for the row it sits on, and writes
 *     nothing anywhere;
 *   - what comes back is a choice, ticked but not sent, with the reason each
 *     page was kept;
 *   - only then does a press send, and it sends the pages that are TICKED —
 *     unticking one has to change what travels, or the boxes are decoration;
 *   - the page never navigates: a list of nine hundred rows is not thrown
 *     away to answer a question about one of them.
 *
 * The markup is the plugin's own, rendered by tools/test-mesh.php, never a
 * copy written into this file and left to drift.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );
const js   = join( root, 'dazont-ecom', 'admin', 'js' );
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

const tab = execFileSync( 'php', [ join( here, '..', 'test-mesh.php' ), 'dazont-ecom', '--dump-tab' ],
	{ encoding: 'utf8', cwd: root } );
// THE STYLESHEETS THE LAYOUT DEPENDS ON — the plugin's own and as much of
// WordPress's list table as a fixed layout is decided by. Measured without
// them, a table is content-sized and every figure below is about a table
// WordPress does not have.
const css   = readFileSync( join( root, 'dazont-ecom', 'admin', 'css', 'content.css' ), 'utf8' );
const wpcss = `
	*, *::before, *::after { box-sizing: border-box; }
	body { margin: 0; font: 13px/1.4em -apple-system, sans-serif; }
	.wrap { padding: 0 20px; }
	.widefat { border-spacing: 0; width: 100%; border-collapse: collapse; clear: both; }
	.widefat th, .widefat td { padding: 8px 10px; text-align: left; vertical-align: top; font-size: 13px; line-height: 1.4em; }
	.widefat thead th { font-weight: 600; }
	.wp-list-table.fixed { table-layout: fixed; }
	.button { display: inline-block; padding: 0 10px; line-height: 26px; border: 1px solid #2271b1; background: #f6f7f7; border-radius: 3px; white-space: nowrap; }
	.button-small { line-height: 22px; padding: 0 8px; }
	.check-column { width: 2.2em; }
	.description { color: #646970; }
	.dashicons { display: inline-block; width: 20px; height: 20px; }
`;

const i18n = {
	ajax: 'http://dze.test/ajax',
	nonce: 'n0nce',
	reading: 'Reading the site…',
	read: 'Read the site again',
	looking: 'Looking for the pages that belong next to it…',
	sending: 'Sending…',
	sent: 'Sent to the writing queue. Nothing is on the site yet: the text is written there, then waits for your yes or no.',
	reviewGo: 'Content to review ↗',
	reviewUrl: 'http://dze.test/wp-admin/admin.php?page=dazont-ecom-diagnostic&tab=review',
	nopick: 'Tick at least one page.',
	none: 'No page on this site is close enough to link to it.',
	words: 'Chosen on wording alone — the writing key is not set, so nothing read these pages.',
	failed: 'That did not go through. Try again.',
	add: 'Send them to the writing queue',
	takes: 'Takes part',
	leftout: 'Left out',
	picked: '%s ticked',
	saving: 'Saving…',
	pagesOf: 'Pages that take part in linking — %1$s of %2$s',
	unjudged: 'Chosen on wording alone — the reading did not answer this time.',
	queued: 'In the writing queue'
};
// The tab on a shop that chose no page: the line at the top and the way it
// opens the chooser exist only there.
const unchosenTab = execFileSync( 'php', [ join( here, '..', 'test-mesh.php' ), 'dazont-ecom', '--dump-unchosen' ],
	{ encoding: 'utf8', cwd: root } );

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const sent = [];
	let navigated = 0;
	let scanRefuse = 1;
	let partial = false;
	let withBusy = false;
	page.on( 'framenavigated', f => { if ( f === page.mainFrame() ) { navigated++; } } );

	// The plugin's own requests, answered here so nothing needs a shop. Every
	// one of them is kept: what a button ASKS is half of what it does.
	await page.route( '**/ajax**', async route => {
		const body = route.request().postData() || '';
		const q = new URLSearchParams( body );
		sent.push( { action: q.get( 'action' ), nonce: q.get( 'nonce' ), key: q.get( 'key' ),
			to: q.get( 'to' ), from: q.getAll( 'from[]' ),
			// A FIELD THE RECORDER DOES NOT KNOW COMES BACK NULL, and the
			// check that reads it then fails for the harness's reasons rather
			// than the plugin's.
			ids: q.getAll( 'ids[]' ), on: q.get( 'on' ) } );
		let data = { sent: 1 };
		// A READING THAT DID NOT GO THROUGH: once refused, then it works.
		if ( 'dze_mesh_scan' === q.get( 'action' ) ) {
			if ( scanRefuse > 0 ) {
				scanRefuse--;
				await route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: false, data: { message: 'A reading is already under way — it takes a minute. Reload in a moment.' } } ) } );
				return;
			}
			data = { pages: 11, links: 4, orphans: 4, ends: 3 };
		}
		// A SEND OF WHICH PART WAS REFUSED says so in the server's words.
		if ( 'dze_mesh_queue' === q.get( 'action' ) ) {
			const n = q.getAll( 'from[]' ).length;
			data = { sent: n, why: '', said: '' };
			if ( partial ) {
				data = { sent: n - 1, why: 'That page is already waiting in the queue.', said: `${n - 1} of ${n} sent — That page is already waiting in the queue.` };
			}
		}
		if ( 'dze_mesh_pick' === q.get( 'action' ) ) {
			const n = q.getAll( 'ids[]' ).length;
			const on = '1' === q.get( 'on' );
			data = { moved: n, on, chosen: on ? n : 0, pages: 3, orphans: 4,
				message: on ? `${n} pages now take part.` : `${n} pages left out.` };
		}
		if ( 'dze_mesh_pairs' === q.get( 'action' ) ) {
			data = { how: 'read', rows: [
				{ key: 'post:21', title: 'Boonie hat sizing', url: 'https://kula.test/blog/21/', kind: 'post', why: 'both about boonie hats', busy: '' },
				{ key: 'product_cat:12', title: 'Boonie hats', url: 'https://kula.test/category/boonie-hats/', kind: 'product_cat', why: 'the aisle it belongs to', busy: '' }
			] };
			// A THIRD PAGE THE QUEUE ALREADY HOLDS, and a reading that did
			// not answer: both only once the first press has been read.
			if ( withBusy ) {
				data.how = 'unjudged';
				data.rows.push( { key: 'product_cat:13', title: 'Tactical gloves', url: 'https://kula.test/category/tactical-gloves/', kind: 'product_cat', why: '', busy: 'In the writing queue' } );
			}
		}
		await route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data } ) } );
	} );

	// Served at a real address, not put there with setContent: a page with no
	// URL cannot be reloaded, so a screen that reloads would slip past this
	// gate exactly the way the toolbox's own reload slipped past the last one.
	await page.route( 'http://dze.test/other', async route => {
		await route.fulfill( { contentType: 'text/html', body: '<!doctype html><html><body>elsewhere</body></html>' } );
	} );
	await page.route( 'http://dze.test/screen', async route => {
		await route.fulfill( { contentType: 'text/html', body: `<!doctype html><html><body><div class="wrap">${tab}</div></body></html>` } );
	} );
	await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );
	await page.addStyleTag( { content: wpcss + css } );
	await page.addScriptTag( { path: jq } );
	await page.evaluate( cfg => { window.dzeMesh = cfg; }, i18n );
	await page.addScriptTag( { path: join( js, 'mesh.js' ) } );
	await page.evaluate( () => jQuery( document ).trigger( 'ready' ) );
	// Where the page stands before anything is pressed. Nothing on this screen
	// may move it: a reload to answer "did that work?" throws away nine
	// hundred rows, their sort, their page and their scroll.
	const stood = navigated;
	// A mark on the window itself. `framenavigated` never fires for a page put
	// there with setContent, so a reload would slip straight past it; a value
	// only this run put there does not survive one.
	await page.evaluate( () => { window.__dzeStood = 'here'; } );
	const still = async () => await page.evaluate( () => window.__dzeStood || 'gone' );

	const firstRow = page.locator( '#dze-mesh-needs tbody tr' ).first();
	const key = await firstRow.getAttribute( 'data-key' );
	await firstRow.locator( '.dze-mesh-pairs' ).click();
	await page.waitForSelector( '.dze-mesh-panel .dze-mesh-list' );

	ok( 'the press asks once', sent.length, 1 );
	ok( 'for the pages that should link to THIS row', sent[0].action + ' ' + sent[0].key, 'dze_mesh_pairs ' + key );
	ok( 'and it carries its nonce', sent[0].nonce, 'n0nce' );
	ok( 'nothing was written', sent.filter( s => 'dze_mesh_queue' === s.action ).length, 0 );
	ok( 'the choice is on screen', await page.locator( '.dze-mesh-panel input[type=checkbox]' ).count(), 2 );
	ok( 'each page says why it is there',
		( await page.locator( '.dze-mesh-panel' ).innerText() ).includes( 'both about boonie hats' ), true );
	ok( 'and the page did not move', [ navigated, await still() ], [ stood, 'here' ] );

	// THE TICKS DECIDE WHAT TRAVELS. A box that changes nothing is decoration,
	// and a screen full of decoration is a screen nobody trusts.
	await page.locator( '.dze-mesh-panel input[type=checkbox]' ).first().uncheck();
	await page.locator( '.dze-mesh-send' ).click();
	// Waiting for the LINE to change is waiting for nothing: "Sending…" is
	// already in it. What is being waited for is the answer landing.
	// A screen that walked away answers nothing, and waiting for an answer on
	// it is a test that dies of a timeout instead of naming what went wrong.
	const answered = await page.waitForFunction( busy => {
		const el = document.querySelector( '.dze-mesh-said' );
		return el && el.textContent.trim() && el.textContent.trim() !== busy;
	}, i18n.sending, { timeout: 5000 } ).then( () => true ).catch( () => false );
	if ( ! answered ) {
		ok( 'the screen answered where it stood', await still(), 'here' );
		await page.close();
		continue;
	}
	const queued = sent.filter( s => 'dze_mesh_queue' === s.action );
	ok( 'the press sends once', queued.length, 1 );
	ok( 'naming the page that is short', queued[0].to, key );
	ok( 'and only the pages still ticked', queued[0].from, [ 'product_cat:12' ] );
	// WHAT THE PRESS DID, IN FULL. "Que se passe-t-il quand je clique sur Place
	// the selected links ? J'aimerais voir le résultat avant qu'il soit
	// appliqué." Nothing is written on the site by this press — the row has to
	// say that, and offer the way to the text rather than naming a tab to go
	// and find.
	ok( 'the row says what happened',
		( await page.locator( '.dze-mesh-said' ).innerText() ).includes( i18n.sent ), true );
	ok( 'and that nothing is on the site yet',
		( await page.locator( '.dze-mesh-said' ).innerText() ).includes( 'Nothing is on the site yet' ), true );
	// A LINK IS TESTED ON ITS DESTINATION — and asked for with a bound, so a
	// row that offers none is REPORTED rather than killing the run on a
	// thirty-second wait for something that is not coming.
	const said = page.locator( '.dze-mesh-said a' );
	const at = async attr => said.getAttribute( attr, { timeout: 3000 } ).catch( () => 'the row offered no link' );
	ok( 'it offers the way to what was produced', await at( 'href' ), i18n.reviewUrl );
	ok( 'in a new tab, so this reading is not lost', await at( 'target' ), '_blank' );
	ok( 'the choice is gone once it is sent', await page.locator( '.dze-mesh-send' ).count(), 0 );
	ok( 'and the page still did not move', [ navigated, await still() ], [ stood, 'here' ] );

	// A PAGE ALREADY IN THE QUEUE IS MARKED, NOT OFFERED — and a reading that
	// did not answer is said as what it is, never as a missing key.
	withBusy = true;
	const second = page.locator( '#dze-mesh-needs tbody tr' ).nth( 2 );
	await second.locator( '.dze-mesh-pairs' ).click();
	await page.waitForSelector( '#dze-mesh-needs tbody tr:nth-child(4) .dze-mesh-list' );
	const panel = page.locator( '.dze-mesh-panel' ).last();
	ok( 'the busy page is listed',      await panel.locator( 'input[type=checkbox]' ).count(), 3 );
	const busyBox = panel.locator( 'input[value="product_cat:13"]' );
	ok( 'but not ticked',               await busyBox.isChecked(), false );
	ok( 'and not tickable',             await busyBox.isDisabled(), true );
	ok( 'with the queue\'s own word beside it',
		( await panel.locator( '.dze-mesh-isbusy' ).innerText() ).includes( 'In the writing queue' ), true );
	ok( 'the wording is said to have chosen, truthfully',
		( await panel.innerText() ).includes( i18n.unjudged ), true );
	ok( 'and never "the key is not set"', ( await panel.innerText() ).includes( 'key is not set' ), false );
	// A SEND OF WHICH PART WAS REFUSED SAYS WHICH.
	partial = true;
	const wasQ = sent.length;
	await panel.locator( '.dze-mesh-send' ).click();
	await page.waitForFunction( busy => {
		const els = document.querySelectorAll( '.dze-mesh-said' );
		const el = els[ els.length - 1 ];
		return el && el.textContent.trim() && el.textContent.trim() !== busy;
	}, i18n.sending, { timeout: 5000 } ).catch( () => {} );
	const partialSend = sent.slice( wasQ ).filter( r => 'dze_mesh_queue' === r.action )[0] || {};
	ok( 'only the two free pages travel', partialSend.from, [ 'post:21', 'product_cat:12' ] );
	const partialSaid = await panel.locator( '.dze-mesh-said' ).innerText();
	ok( 'the refusal comes first, in the server\'s words',
		partialSaid.startsWith( '1 of 2 sent — That page is already waiting in the queue.' ), true );
	ok( 'and then what the press did',  partialSaid.includes( i18n.sent ), true );
	partial = false;
	withBusy = false;

	// A press with nothing ticked SAYS SO rather than sending an empty ask.
	const third = page.locator( '#dze-mesh-needs tbody tr' ).nth( 5 );
	await third.locator( '.dze-mesh-pairs' ).click();
	await page.waitForSelector( '#dze-mesh-needs tbody tr:nth-child(7) .dze-mesh-list' );
	const panel3 = page.locator( '.dze-mesh-panel' ).last();
	for ( const box of await panel3.locator( 'input[type=checkbox]' ).all() ) { await box.uncheck(); }
	const before = sent.length;
	await panel3.locator( '.dze-mesh-send' ).click();
	ok( 'an empty choice sends nothing', sent.length, before );
	ok( 'and says why', await panel3.locator( '.dze-mesh-said' ).innerText(), i18n.nopick );

	// A second press on the same row shuts the panel again: it is a look at
	// what would be done, not a step that has to be undone.
	const panelsOpen = await page.locator( '.dze-mesh-panel' ).count();
	await firstRow.locator( '.dze-mesh-pairs' ).click();
	ok( 'opening again shuts it', await page.locator( '.dze-mesh-panel' ).count(), panelsOpen - 1 );

	// The other list: one press, one job, and the button is replaced by what
	// happened rather than left looking pressable.
	const end = page.locator( '#dze-mesh-ends tbody tr' ).first();
	const endKey = await end.getAttribute( 'data-key' );
	await end.locator( '.dze-mesh-out' ).click();
	await end.locator( '.dze-mesh-out' ).waitFor( { state: 'detached' } );
	const outs = sent.filter( s => 'dze_mesh_out' === s.action );
	ok( 'the linking pass is asked for that page', outs.length && outs[0].key, endKey );
	// THE SAME WORD THE SERVER PRINTS ON A QUEUED ROW, and the way to where
	// the text will wait — never the panel's five-line sentence in a cell.
	ok( 'and the row wears the queue\'s own word', await end.innerText().then( t => t.includes( i18n.queued ) ), true );
	ok( 'with the way to what it will produce', await end.locator( 'a[href="' + i18n.reviewUrl + '"]' ).count(), 1 );
	ok( 'and no full sentence in the cell', await end.innerText().then( t => t.includes( 'Nothing is on the site yet' ) ), false );
	// ---- WHICH PAGES TAKE PART: ticks, SHIFT, one bar ----
	// "Mais avec des coches, la possibilité d'utiliser la touche MAJ, et choix
	// en bulk donc." Only a browser can see a shift-click, and only a browser
	// can see what a bulk press puts on the wire.
	const box = page.locator( '.dze-mesh-pagesbox' );
	ok( 'the chooser is on the screen', await box.count(), 1 );
	ok( 'and it is folded away',        await box.evaluate( d => d.open ), false );
	await box.locator( 'summary' ).click();
	const cbs = box.locator( 'tbody .dze-mesh-cb' );
	const rows = await cbs.count();
	ok( 'a tick per page',              rows >= 3, true );
	// THE BAR REFUSES TO ACT ON NOTHING, and says nothing rather than "0".
	ok( 'the bar starts disabled',
		await box.locator( '.dze-mesh-pick' ).first().isDisabled(), true );
	ok( 'and says nothing yet',
		( await box.locator( '.dze-mesh-count' ).innerText() ).trim(), '' );

	await cbs.nth( 0 ).click();
	ok( 'one ticked, the bar wakes up',
		await box.locator( '.dze-mesh-pick' ).first().isDisabled(), false );
	ok( 'and says how many',
		( await box.locator( '.dze-mesh-count' ).innerText() ).trim(), '1 ticked' );

	// SHIFT TAKES THE RUN BETWEEN THE TWO — copied from the box just pressed,
	// never toggled, or a run comes out half on and half off.
	await cbs.nth( rows - 1 ).click( { modifiers: [ 'Shift' ] } );
	ok( 'shift takes the whole run',
		await box.locator( 'tbody .dze-mesh-cb:checked' ).count(), rows );
	ok( 'and the bar follows it',
		( await box.locator( '.dze-mesh-count' ).innerText() ).trim(), `${rows} ticked` );

	// THE PRESS. What it puts on the wire is the half that goes wrong in
	// silence.
	const wasPick = sent.length;
	const ids = await box.locator( 'tbody tr' ).evaluateAll( trs => trs.map( t => String( t.dataset.id ) ) );
	await box.locator( '.dze-mesh-pick[data-on="1"]' ).click();
	await page.waitForFunction(
		() => /take part/.test( ( document.querySelector( '.dze-mesh-msg' ) || {} ).textContent || '' ),
		null, { timeout: 6000 } ).catch( () => {} );
	const pick = sent.slice( wasPick ).filter( r => 'dze_mesh_pick' === r.action )[ 0 ] || {};
	ok( 'the press names every ticked page', pick.ids, ids );
	ok( 'and which way round it goes',       pick.on, '1' );
	ok( 'with its nonce',                    pick.nonce, i18n.nonce );
	// EVERY FIGURE THE PRESS MOVED, MOVED — the rows, and the summary above
	// them, which is where the shop reads where it stands.
	ok( 'every row says it takes part',
		await box.locator( 'tbody tr.is-in' ).count(), rows );
	ok( 'and none says otherwise',
		await box.locator( 'tbody tr.is-out' ).count(), 0 );
	ok( 'the row says it in words',
		( await box.locator( 'tbody .dze-mesh-in' ).first().innerText() ).trim(), i18n.takes );
	ok( 'the summary carries the new figure',
		( await box.locator( 'summary' ).innerText() ).trim(), `Pages that take part in linking — ${rows} of 3` );
	// AND THE TICKS ARE CLEARED, or the next press acts on a selection nobody
	// can see any more.
	ok( 'the ticks are cleared',
		await box.locator( 'tbody .dze-mesh-cb:checked' ).count(), 0 );
	ok( 'and the bar is asleep again',
		await box.locator( '.dze-mesh-pick' ).first().isDisabled(), true );

	ok( 'the page never moved', [ navigated, await still() ], [ stood, 'here' ] );

	// THE TWO LISTS, MEASURED. The id column and the visit symbol were added
	// to tables laid out FIXED, so every width is spent out of one hundred:
	// the name must stay the widest column and nothing may overflow.
	for ( const w of [ 1040, 1600 ] ) {
		await page.setViewportSize( { width: w, height: 900 } );
		const m = await page.evaluate( () => {
			const out = {};
			for ( const id of [ 'dze-mesh-needs', 'dze-mesh-ends' ] ) {
				const t = document.getElementById( id );
				const ths = [ ...t.querySelectorAll( 'thead th' ) ].map( th => Math.round( th.getBoundingClientRect().width ) );
				const row = t.querySelector( 'tbody tr' );
				const tall = row ? Math.round( row.getBoundingClientRect().height ) : 0;
				const over = t.scrollWidth > t.clientWidth + 1;
				out[ id ] = { ths, tall, over, headings: [ ...t.querySelectorAll( 'thead th' ) ].map( th => th.textContent.trim() ) };
			}
			return out;
		} );
		for ( const id of [ 'dze-mesh-needs', 'dze-mesh-ends' ] ) {
			const r = m[ id ];
			ok( `${id} @${w}: the id is the second heading`, r.headings[1], 'ID' );
			ok( `${id} @${w}: the name is the widest column (${r.ths.join( '/' )})`, Math.max( ...r.ths ) === r.ths[0], true );
			ok( `${id} @${w}: no column under 70px`, Math.min( ...r.ths ) >= 70, true );
			ok( `${id} @${w}: nothing overflows`, r.over, false );
			ok( `${id} @${w}: a row is one line (${r.tall}px)`, r.tall <= 60, true );
		}
	}
	await page.setViewportSize( { width: 1280, height: 900 } );

	// A SHOP THAT CHOSE NO PAGE IS TOLD, at the top, and the line OPENS the
	// chooser rather than sending somebody to find a folded box.
	await page.route( 'http://dze.test/unchosen', async route => {
		await route.fulfill( { contentType: 'text/html', body: `<!doctype html><html><body><div class="wrap">${unchosenTab}</div></body></html>` } );
	} );
	await page.goto( 'http://dze.test/unchosen', { waitUntil: 'domcontentloaded' } );
	await page.addScriptTag( { path: jq } );
	await page.evaluate( cfg => { window.dzeMesh = cfg; }, i18n );
	await page.addScriptTag( { path: join( js, 'mesh.js' ) } );
	await page.evaluate( () => jQuery( document ).trigger( 'ready' ) );
	await page.evaluate( () => { window.__dzeStood = 'here'; } );
	const movesBefore = navigated;
	ok( 'the line is on the screen',     await page.locator( '.dze-mesh-unchosen' ).count(), 1 );
	ok( 'the chooser starts shut',       await page.locator( '.dze-mesh-pagesbox' ).evaluate( d => d.open ), false );
	await page.locator( '.dze-mesh-choose' ).click();
	ok( 'the line opens the chooser',    await page.locator( '.dze-mesh-pagesbox' ).evaluate( d => d.open ), true );
	ok( 'without moving the page',       [ navigated, await still() ], [ movesBefore, 'here' ] );

	// THE READING PRESS. Refused once: the screen says so, the button comes
	// back, the page stays. Then it goes through, and THAT is the one press
	// on this screen allowed to reload — the reading is drawn by the server.
	const scanBtn = page.locator( '#dze-mesh-scan' );
	await scanBtn.click();
	await page.waitForFunction( busy => {
		const el = document.getElementById( 'dze-mesh-state' );
		return el && el.textContent.trim() && el.textContent.trim() !== busy;
	}, i18n.reading, { timeout: 5000 } ).catch( () => {} );
	ok( 'a refused reading is said',     ( await page.locator( '#dze-mesh-state' ).innerText() ).includes( 'already under way' ), true );
	ok( 'the button is back',            await scanBtn.isDisabled(), false );
	ok( 'and the page did not move',     [ navigated, await still() ], [ movesBefore, 'here' ] );
	await scanBtn.click();
	await page.waitForFunction( () => 'here' !== window.__dzeStood, null, { timeout: 5000 } ).catch( () => {} );
	ok( 'a reading that went through redraws the screen', await still(), 'gone' );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
