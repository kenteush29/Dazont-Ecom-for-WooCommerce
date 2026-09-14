/**
 * The Automation screen, READ THE WAY IT IS READ, on both jQuery builds.
 *
 * Run before every release:  node tools/js/automation-screen.mjs
 *
 * "L'écran automatisation est à revoir pour une UX optimale. C'est très
 * brutal, vulgaire, avec énormément de texte de partout. Je suis perdu et
 * désorienté quand je vois ça… Si un module est bien fait, en général, il
 * n'est pas nécessaire d'ajouter du texte partout. La simple présence d'un
 * bouton doit parler d'elle-même."
 *
 * So the thing to hold is not "does it work" — the PHP gate answers that — but
 * WHAT IT LOOKS LIKE WHEN IT OPENS, and only a browser can answer that:
 *
 *   - three tasks are three LINES, shut, not three screens of prose;
 *   - the figures are readable WITHOUT opening anything, each on one line;
 *   - opening one gives the controls, and closing it takes them away again;
 *   - the button that runs it moves the line it was pressed on, so a figure
 *     never answers for the page as it was opened;
 *   - and what it LEFT you is settled here: "ici ce serait bien de pouvoir
 *     review la task directement sans partir". The three controls are the
 *     review list's own, so the gate presses the REAL popup — dumped by
 *     test-review.php, never retyped into this harness — and proves the press
 *     opens it, sends that row's own id, and never moves the page.
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

const dumped = JSON.parse( execFileSync( 'php',
	[ join( here, '..', 'test-automation.php' ), 'dazont-ecom', '--dump-automation' ],
	{ encoding: 'utf8', cwd: root, stdio: [ 'ignore', 'pipe', 'ignore' ] } ) );
// The popup these rows open is DZE_Queue's, and so are its words: taken from
// the function that prints them on the review screen, not typed out again
// here, or the harness and the plugin drift apart on the next edit.
const review = JSON.parse( execFileSync( 'php',
	[ join( here, '..', 'test-review.php' ), 'dazont-ecom', '--dump-review' ],
	{ encoding: 'utf8', cwd: root, stdio: [ 'ignore', 'pipe', 'ignore' ] } ) );
const qcfg = Object.assign( {}, review.cfg, { ajaxUrl: 'http://dze.test/ajax' } );
const queuejs = readFileSync( join( root, 'dazont-ecom', 'admin', 'js', 'queue.js' ), 'utf8' );

// WordPress's own admin, as much of it as this screen's shape depends on.
const wpcss = `
	body { margin: 0; font: 13px/1.4em -apple-system, sans-serif; background: #f0f0f1; }
	#wpbody-content { padding: 20px; box-sizing: border-box; }
	.button { display: inline-block; padding: 0 10px; line-height: 26px; border: 1px solid #2271b1;
		background: #f6f7f7; border-radius: 3px; white-space: nowrap; }
	.description { color: #646970; }
	.dashicons { display: inline-block; width: 20px; height: 20px; }
	.small-text { width: 60px; }
`;

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	console.log( `\njQuery ${label}` );
	const page = await browser.newPage();
	const errors = [], sent = [];
	// Three pages queued, none written: where the shop stands the moment it
	// presses. The SERVER owns this, which is the whole reason a reload picks
	// the bar up where it was.
	let left = 3, done = 0;
	// THE SERVER REFUSING TO ANSWER, and a run that has stopped moving: two
	// states the screen used to have no way of showing, and neither of them
	// exists unless the harness can produce it.
	let refuse = 0, stopped = false;
	// The real `waiting_html()` as the server drew it, pulled out of the dump:
	// the poll returns `waiting` on every tick, so a harness that answers with a
	// list of its own would wipe the ticks and the bar before anything pressed
	// them.
	const decided = new Set();
	// The list as the queue would answer it now: the server's own markup with
	// the rows that have been settled taken out of it.
	const waitingNow = () => {
		let out = serverWaiting;
		for ( const id of decided ) {
			out = out.replace( new RegExp( '<li[^>]*data-id="' + id + '"[\\s\\S]*?</li>' ), '' );
		}
		return /<li/.test( out ) ? out : '<p class="description">Nothing is waiting for your yes or no.</p>';
	};
	const serverWaiting = ( () => {
		const m = dumped.html.match( /<div id="dze-auto-waiting">([\s\S]*?)<\/div>\s*<\?php|<div id="dze-auto-waiting">([\s\S]*)$/ );
		const el = dumped.html.indexOf( 'id="dze-auto-waiting"' );
		if ( el < 0 ) { return ''; }
		const from = dumped.html.indexOf( '>', el ) + 1;
		// Balance the div rather than guessing where it ends.
		let depth = 1, i = from;
		while ( i < dumped.html.length && depth > 0 ) {
			const open = dumped.html.indexOf( '<div', i );
			const close = dumped.html.indexOf( '</div>', i );
			if ( close < 0 ) { break; }
			if ( open >= 0 && open < close ) { depth++; i = open + 4; continue; }
			depth--; i = close + 6;
		}
		return dumped.html.slice( from, Math.max( from, i - 6 ) );
	} )();
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	// A 502 THIS GATE ASKED FOR IS NOT A FAULT IT FOUND. The browser logs a
	// refused request as a console error; the section below makes the server
	// refuse on purpose, and a script fault still arrives through `pageerror`.
	page.on( 'console', m => {
		if ( 'error' !== m.type() ) { return; }
		if ( /status of 502/.test( m.text() ) ) { return; }
		errors.push( m.text() );
	} );

	await page.route( 'http://dze.test/ajax', route => {
		const q = new URLSearchParams( route.request().postData() || '' );
		const act = q.get( 'action' );
		// EVERY FIELD A PRESS PUTS ON THE WIRE, read back by name. A key the
		// recorder does not know comes back null, and the check that reads it
		// fails for the harness's reasons rather than the plugin's.
		sent.push( { action: act, task: q.get( 'task' ), nonce: q.get( 'nonce' ),
			id: q.get( 'id' ), accept: q.get( 'accept' ),
			key: q.get( 'key' ), on: q.get( 'on' ), step: q.get( 'step' ) } );
		// The one review popup, answering as the server answers it.
		if ( 'dze_q_review' === act ) {
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
				id: Number( q.get( 'id' ) ), title: 'Tactical backpack covers', prompt: 'cat_links',
				html: '<p>The linked text.</p>', current: '<p>The text as it stands.</p>',
				words: [ 1094, 1094 ], links: [ 1, 5 ]
			} } ) } );
		}
		// THE CATCH-UP ANSWERS WITH ITS OWN FIGURES, and they must not be the
		// ones the next press is waited for: a gate that waits for something
		// already on the screen waits for nothing.
		if ( 'dze_auto_catchup' === act ) {
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
				queued: 312,
				task: q.get( 'task' ),
				message: '312 pages queued. There is more to do — press again once these are through.',
				state: '<p class="dze-auto-next">Next in line: Boonie hats</p>',
				chips: '<span class="dze-auto-chips" data-task="' + q.get( 'task' ) + '">'
					+ '<span class="dze-auto-chip is-on"><span class="dashicons dashicons-controls-play"></span>3 a day</span>'
					+ '<button type="button" class="dze-auto-chip is-orphan dze-auto-orph" title="Pages no other page links to in its text — menus and breadcrumbs do not count. Press to see them."><span class="dashicons dashicons-editor-unlink"></span>41</button>'
					+ '<span class="dze-auto-chip is-wait"><span class="dashicons dashicons-visibility"></span>9</span>'
					+ '</span>',
				log: '<ul><li>Internal linking · Boonie hats</li></ul>'
			} } ) } );
		}
		// The pages behind the figure, as the server answers them.
		if ( 'dze_auto_orphans' === act ) {
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
				html: '<p class="description">A menu, a breadcrumb or a shop archive is not counted.</p>'
					+ '<table class="dze-auto-orphlist"><tbody>'
					+ '<tr><td><strong>Camo patterns explained</strong> <span class="dze-auto-built">page builder</span></td>'
					+ '<td><code class="dze-objid">41</code></td><td>page</td><td>0</td>'
					+ '</tr></tbody></table>'
					+ '<p class="description dze-auto-orphnote">12 pages of this site take no part in linking, so they are not counted here.'
					+ ' Every article and every product category does. <a href="http://dze.test/wp-admin/admin.php?page=dze-content&tab=linking">Choose them</a></p>'
			} } ) } );
		}
		// THE WORK, DRAINING ONE STEP AT A TIME. The bar has to have somewhere
		// real to move to, or "it moves" is a check that cannot fail.
		if ( 'dze_auto_run_state' === act ) {
			// A REQUEST THAT NEVER COMES BACK. One 502 from a slow model call
			// used to kill the watcher for good.
			if ( refuse > 0 ) { refuse--; return route.fulfill( { status: 502, contentType: 'text/html', body: 'gateway' } ); }
			if ( stopped ) {
				const t = left + done;
				return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
					left: left, done: done, pct: 3,
					run: { mesh_links: '<div class="dze-auto-prog is-stuck">'
						+ '<p class="dze-auto-runsaid">Nothing has moved for 8 minutes. The writer may be held by a run the server stopped.</p>'
						+ '<div class="dze-auto-bar"><span style="width:3%"></span></div>'
						+ '<p class="description dze-auto-runfig">3% \u2014 ' + done + ' of ' + t + ' written</p>'
						+ '<p class="dze-auto-runact"><button type="button" class="button dze-auto-again" title="Lets the writer go, puts back what could not be written, and starts the queue again.">Start it again</button> '
						+ '<button type="button" class="button dze-auto-stop" title="Drops the pages still waiting their turn and the ones that could not be written. What is already written and waiting for your yes or no is kept.">Stop</button> '
						+ '<span class="dze-auto-restarted"></span></p></div>', cat_desc: '', events: '' },
					waiting: waitingNow(), chips: {}
				} } ) } );
			}
			if ( '1' === q.get( 'step' ) && left > 0 ) { left--; done++; }
			const total = left + done;
			const pct = total ? Math.max( left > 0 ? 3 : 0, Math.floor( done * 100 / total ) ) : 0;
			const said = left > 0
				? 'Writing \u2014 ' + left + ' pages left.'
				: 'Done \u2014 ' + done + ' pages waiting for your yes or no, below.';
			const bar = total
				? '<div class="dze-auto-prog ' + ( left > 0 ? 'is-working' : 'is-done' ) + '">'
					+ '<p class="dze-auto-runsaid">' + said + '</p>'
					+ '<div class="dze-auto-bar"><span style="width:' + pct + '%"></span></div>'
					+ '<p class="description dze-auto-runfig">' + pct + '% \u2014 ' + done + ' of ' + total + ' written</p></div>'
				: '';
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
				left: left, done: done, pct: pct,
				// A BAR PER TASK, keyed like the chips beside them: the server
				// cannot hand one lump of markup to three different places.
				run: { mesh_links: bar, cat_desc: '', events: '' },
				waiting: waitingNow(),
				chips: {}
			} } ) } );
		}
		// CALLING A RUN OFF: what waits its turn is dropped, what is written
		// and waiting for a decision is kept, and the block is redrawn.
		if ( 'dze_auto_run_stop' === act ) {
			stopped = false;
			left = 0; done = 2;
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
				run: '<div class="dze-auto-prog is-done">'
					+ '<p class="dze-auto-runsaid">Done \u2014 2 pages are written and waiting for your yes or no, below.</p>'
					+ '<div class="dze-auto-bar"><span style="width:100%"></span></div>'
					+ '<p class="description dze-auto-runfig">100% \u2014 2 of 2 written</p>'
					+ '<p class="dze-auto-runact"><span class="dze-auto-restarted"></span></p></div>',
				waiting: waitingNow(),
				message: '5 called off. Nothing written was thrown away.'
			} } ) } );
		}
		// STARTING A STOPPED RUN AGAIN: the writer let go, the failed rows put
		// back, and the block redrawn — which is the answer.
		if ( 'dze_auto_run_again' === act ) {
			stopped = false;
			left = 2; done = 0;
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
				run: '<div class="dze-auto-prog is-working">'
					+ '<p class="dze-auto-runsaid">Writing \u2014 2 pages left.</p>'
					+ '<div class="dze-auto-bar"><span style="width:3%"></span></div>'
					+ '<p class="description dze-auto-runfig">3% \u2014 0 of 2 written</p>'
					+ '<p class="dze-auto-runact"><span class="dze-auto-restarted"></span></p></div>',
				waiting: waitingNow(),
				message: '2 put back in the queue.'
			} } ) } );
		}
		if ( 'dze_q_decide' === act ) {
			decided.add( String( q.get( 'id' ) ) );
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {} } ) } );
		}
		// A DECISION MOVES THE FIGURES AND THE ROWS TOGETHER: the block is
		// re-read whole, so the row that was settled leaves and the chip
		// beside it follows it.
		if ( 'dze_auto_state' === act ) {
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
				chips: { mesh_links: '<span class="dze-auto-chips" data-task="mesh_links">'
					+ '<span class="dze-auto-chip is-on" title="Running on its own"><span class="dashicons dashicons-controls-play"></span>3 a day</span>'
					+ '<button type="button" class="dze-auto-chip is-orphan dze-auto-orph" title="Pages no other page links to in its text — menus and breadcrumbs do not count. Press to see them."><span class="dashicons dashicons-editor-unlink"></span>41</button>'
					+ '<span class="dze-auto-chip is-wait" title="Waiting for your yes or no"><span class="dashicons dashicons-visibility"></span>1</span>'
					+ '</span>' },
				waiting: waitingNow(),
				log: '<ul><li>Internal linking · Tactical backpack covers</li></ul>'
			} } ) } );
		}
		return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { success: true, data: {
			queued: 1,
			task: q.get( 'task' ),
			message: 'Queued — the writing queue does it in the background.',
			state: '<p class="dze-auto-next">Next in line: Boonie hats</p>',
			// The answer carries the LINE as it now stands, not only the body.
			chips: '<span class="dze-auto-chips" data-task="' + q.get( 'task' ) + '">'
				+ '<span class="dze-auto-chip is-on"><span class="dashicons dashicons-controls-play"></span>3 a day</span>'
				+ '<span class="dze-auto-chip is-wait"><span class="dashicons dashicons-visibility"></span>4</span>'
				+ '</span>',
			log: '<ul><li>Internal linking · Boonie hats</li></ul>'
		} } ) } );
	} );
	await page.route( 'http://dze.test/screen', route => route.fulfill( { contentType: 'text/html', body:
		`<!doctype html><html><head><meta charset="utf-8"><style>${wpcss}</style><style>${css}</style>`
		+ `<script>${readFileSync( jq, 'utf8' )}</script>`
		+ `<script>window.ajaxurl='http://dze.test/ajax';window.dzeQueue=${JSON.stringify( qcfg )};</script>`
		+ `</head><body><div id="wpbody-content">${dumped.html}</div>${review.modal}`
		+ `<script>${queuejs}</script></body></html>` } ) );
	// Accepting writes to the shop, so the screen always asks first. NAMED, so
	// a check that needs the question REFUSED can take it off for one press —
	// an anonymous handler cannot be removed, and a press that must be refused
	// then has no way to be.
	const sayYes = d => d.accept();
	page.on( 'dialog', sayYes );
	const moves = [];
	page.on( 'framenavigated', f => { if ( f === page.mainFrame() ) { moves.push( f.url() ); } } );

	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( 'http://dze.test/screen', { waitUntil: 'domcontentloaded' } );

	// ---- THREE TASKS ARE THREE LINES ----
	const shut = await page.evaluate( () => {
		const blocks = Array.from( document.querySelectorAll( '.dze-auto-task' ) );
		return {
			n: blocks.length,
			open: blocks.filter( b => b.open ).length,
			heights: blocks.map( b => Math.round( b.getBoundingClientRect().height ) ),
			// What the SETTINGS say when the screen opens, with everything
			// folded. The waiting list below is the work, not prose, and is
			// deliberately not counted here.
			words: blocks.map( b => b.innerText.replace( /\s+/g, ' ' ).trim() ).join( ' ' ).length
		};
	} );
	// ONE SUBJECT, TWO VIEWS, WORDPRESS'S OWN TABS — and the work is the one
	// that opens. "Ce bloc est inutile. Sinon crée un nouvel onglet."
	const strip = await page.evaluate( () => ( {
		tabs: Array.from( document.querySelectorAll( '.nav-tab' ) ).map( a => a.textContent.trim() ),
		here: ( document.querySelector( '.nav-tab-active' ) || {} ).textContent || '',
		diary: !! document.querySelector( '.dze-auto-log' )
	} ) );
	ok( 'the screen has its two views',     strip.tabs, [ 'Tasks', 'Past work' ] );
	ok( 'and the work is the one showing',  strip.here.trim(), 'Tasks' );
	ok( 'no diary folded under the work',   strip.diary, false );

	// ---- ONE SCREEN, ONE COLUMN ----
	// The task blocks stopped at their own width and the list under them ran
	// the full width of the window, so one page had two right-hand edges:
	// "applique la même largeur pour le bloc To review". A CSS fault, which no
	// PHP test and no `node --check` can see — it exists only once a browser
	// has laid the page out.
	const edges = await page.evaluate( () => {
		const box = el => { const r = el.getBoundingClientRect(); return [ Math.round( r.left ), Math.round( r.right ) ]; };
		const task = document.querySelector( '.dze-auto-task' );
		const list = document.querySelector( '.dze-auto-todo' );
		const head = document.querySelector( '.dze-auto-h2' );
		return {
			task: task ? box( task ) : null,
			list: list ? box( list ) : null,
			head: head ? box( head ) : null,
			// And the column is narrower than the window, or "the same width"
			// would be true of two things that both simply run to the edge.
			room: Math.round( document.getElementById( 'wpbody-content' ).getBoundingClientRect().width )
		};
	} );
	ok( 'the waiting list is there to measure', !! edges.list, true );
	ok( 'it starts where the blocks start',  edges.list && edges.list[0], edges.task && edges.task[0] );
	ok( 'and ends where they end',           edges.list && edges.list[1], edges.task && edges.task[1] );
	ok( 'its heading keeps the same column', edges.head && edges.head[1], edges.task && edges.task[1] );
	ok( 'and the column is not just the window',
		edges.task && ( edges.task[1] - edges.task[0] ) < edges.room, true );

	ok( 'three tasks, three blocks',        shut.n, 3 );
	ok( 'and every one of them shut',       shut.open, 0 );
	ok( 'each reading as one line',         shut.heights.every( h => h <= 60 ), true );
	// A SCREEN THAT OPENS ON A WALL OF TEXT is the screen he photographed. With
	// everything folded this one is names, figures and two buttons.
	ok( 'the screen opens on very little',  shut.words <= 400, true );

	// ---- THE FIGURES ARE READ WITHOUT OPENING ANYTHING ----
	// Reported, never thrown: a gate that dies on the thing it is about says
	// nothing about the checks after it.
	const chips = await page.evaluate( () => {
		const first = document.querySelector( '.dze-auto-task .dze-auto-chips' );
		if ( ! first ) { return { visible: false, kinds: [], text: [], titled: false, oneLine: false }; }
		const all = Array.from( first.querySelectorAll( '.dze-auto-chip' ) );
		return {
			visible: !! first.offsetParent,
			kinds: all.map( c => c.className.replace( 'dze-auto-chip ', '' ) ),
			text: all.map( c => c.textContent.trim() ),
			titled: all.every( c => ( c.getAttribute( 'title' ) || '' ).length > 3 ),
			titles: all.map( c => c.getAttribute( 'title' ) || '' ),
			oneLine: new Set( all.map( c => Math.round( c.getBoundingClientRect().top ) ) ).size === 1
		};
	} );
	ok( 'the figures are there while shut', chips.visible, true );
	ok( 'the rhythm, the orphans, the waiting, the written and the next look',
		chips.kinds, [ 'is-on', 'is-orphan dze-auto-orph', 'is-wait', 'is-done', 'is-next' ] );
	ok( 'with the figures on them',         chips.text.slice( 0, 4 ),
		[ '3 a day', String( dumped.orphans ), '3', '14' ] );
	// THE FIGURE FOR THE PAGES NOTHING POINTS AT sits on the task that is the
	// only thing that mends them, and says so on its own hover.
	ok( 'and the orphan figure says whose work it is',
		( chips.titles || [] )[1] || '', 'Pages no other page links to in its text — menus and breadcrumbs do not count. Press to see them.' );
	ok( 'each one carrying its own word',   chips.titled, true );
	ok( 'and all of them on one line',      chips.oneLine, true );
	// A TASK THAT IS OFF SAYS ONLY THAT.
	const second = await page.evaluate( () => {
		const block = document.querySelectorAll( '.dze-auto-task' )[1];
		if ( ! block ) { return [ 'no second task on the screen' ]; }
		return Array.from( block.querySelectorAll( '.dze-auto-chip' ) )
			.map( c => c.className.replace( 'dze-auto-chip ', '' ) );
	} );
	ok( 'a task that is off says so, and nothing else', second, [ 'is-off' ] );

	// ---- THE FIGURE OPENS THE LIST IT COUNTS ----
	// "WOW c'est énorme, littéralement impossible… il faut la possibilité de
	// voir ces pages dans une liste." A count that cannot be opened is a count
	// somebody argues with.
	const foldsWas = await page.evaluate( () => document.querySelectorAll( '.dze-auto-task[open]' ).length );
	const wasO = sent.length;
	await page.click( '.dze-auto-task:first-of-type .dze-auto-orph', { timeout: 3000 } ).catch( () => {} );
	const landed = await page.waitForFunction(
		() => /page builder/.test( ( document.getElementById( 'dze-auto-orphbody' ) || {} ).textContent || '' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	const orph = await page.evaluate( () => ( {
		open: !! document.querySelector( '#dze-auto-orphmodal.is-open' ),
		folds: document.querySelectorAll( '.dze-auto-task[open]' ).length,
		rows: document.querySelectorAll( '#dze-auto-orphbody .dze-auto-orphlist tbody tr' ).length,
		id: ( document.querySelector( '#dze-auto-orphbody .dze-objid' ) || {} ).textContent || ''
	} ) );
	const askO = sent.slice( wasO ).filter( r => 'dze_auto_orphans' === r.action )[0] || {};
	ok( 'the figure asks for its list',     askO.action, 'dze_auto_orphans' );
	ok( 'with its nonce',                   ( askO.nonce || '' ).length > 0, true );
	ok( 'the popup comes up',               orph.open, true );
	ok( 'and the answer lands in it',       landed, true );
	ok( 'a row per page, with its id',      [ orph.rows, orph.id ], [ 1, '41' ] );
	// A CHIP IN A <summary> MUST NOT FOLD THE BLOCK under the hand that
	// pressed it — the same rule the "?" is held to.
	ok( 'without folding the block',        orph.folds, foldsWas );
	// ---- WHAT THE LIST DOES NOT COUNT ----
	// The per-row "Do not link" button is gone — "c'est mal foutu, très
	// inconfortable". Articles and product categories always take part; a page
	// takes part only when the shop chose it, on one table on the Linking tab.
	// What this popup owes is a sentence and the way there.
	const note = await page.evaluate( () => {
		const p = document.querySelector( '#dze-auto-orphbody .dze-auto-orphnote' );
		return { text: p ? p.textContent : '', href: p && p.querySelector( 'a' ) ? p.querySelector( 'a' ).getAttribute( 'href' ) : '' };
	} );
	ok( 'no decision is taken on a row',
		await page.locator( '#dze-auto-orphbody .dze-auto-aside' ).count(), 0 );
	ok( 'the pages left out are named',   /no part in linking/.test( note.text ), true );
	// A SENTENCE THAT NAMES A SCREEN IS A WAY TO THAT SCREEN, and it is tested
	// on its DESTINATION — a control on a row of objects has shipped twice
	// pointing at a preferences page.
	ok( 'with the way to choose them',    /tab=linking/.test( note.href ), true );

	await page.click( '#dze-auto-orphmodal .dze-hub-close', { timeout: 3000 } ).catch( () => {} );
	ok( 'and it closes again',
		await page.locator( '#dze-auto-orphmodal.is-open' ).count(), 0 );

	// ---- OPENING ONE GIVES THE CONTROLS ----
	ok( 'the controls are out of the way',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-controls' ).isVisible().catch( () => false ), false );
	await page.click( '.dze-auto-task:first-of-type > summary', { timeout: 3000 } ).catch( () => {} );
	ok( 'opening it shows them',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-controls' ).isVisible(), true );
	ok( 'with the button that runs it',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-run' ).isVisible(), true );
	// WHAT IT IS ABOUT TO TAKE — folded, and one page a line inside.
	// "Affichage maladroit, mauvais pour UI. Peut-être plutôt revenir à la
	// ligne sur chaque post. Ou un bouton d'infos qui montre les posts à venir
	// (les cacher par défaut ?)" It used to be five titles and five
	// parenthetical explanations glued together with middle dots, wrapped over
	// four lines of prose. This check asserted that paragraph was VISIBLE,
	// which is why it went red on the mend.
	const dzeNext = '.dze-auto-task:first-of-type .dze-auto-nextwrap';
	ok( 'and what it is about to take, behind a fold',
		await page.locator( dzeNext ).isVisible(), true );
	ok( 'shut, so the block stays one line of reading',
		await page.locator( `${dzeNext} .dze-auto-nextlist` ).isVisible(), false );
	await page.click( `${dzeNext} > summary`, { timeout: 3000 } ).catch( () => {} );
	ok( 'and opening it lists them one per line',
		await page.locator( `${dzeNext} .dze-auto-nextone` ).count() > 1, true );
	// A FOLD INSIDE A FOLD MUST NOT SHUT THE ONE IT SITS IN.
	ok( 'without folding the task it sits in',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-controls' ).isVisible(), true );
	await page.click( `${dzeNext} > summary`, { timeout: 3000 } ).catch( () => {} );
	// WHAT IS WAITING IS NOT A SETTING: the fold holds what the pass is about
	// to take, and nothing about what it left.
	ok( 'and no work list inside the settings',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-job' ).count(), 0 );

	// ---- A LINE SOMEBODY CAN READ, AND THE MECHANISM ONE PRESS AWAY ----
	// "Ça j'ai rien compris… On pourrait utiliser un bouton I qui charge plus
	// d'info pour la curiosité." The "?" is the modules list's own, so it is
	// pressed here exactly as it is pressed there — and a "?" planted inside a
	// <summary> must not fold the block under the hand that pressed it.
	const shutBefore = await page.evaluate( () => document.querySelectorAll( '.dze-auto-task[open]' ).length );
	await page.click( '.dze-auto-task:nth-of-type(2) .dze-mod-more', { timeout: 3000 } ).catch( () => {} );
	const info = await page.evaluate( () => ( {
		open: !! ( document.querySelector( '#dze-mod-popup.is-open' ) ),
		title: ( document.getElementById( 'dze-mod-popup-title' ) || {} ).textContent || '',
		text: ( ( document.getElementById( 'dze-mod-popup-text' ) || {} ).textContent || '' ).length,
		folds: document.querySelectorAll( '.dze-auto-task[open]' ).length
	} ) );
	ok( 'the "?" opens the detail',         info.open, true );
	ok( 'under the task it belongs to',     info.title, 'Category descriptions' );
	ok( 'and it actually holds the detail', info.text > 200, true );
	ok( 'without folding the block open',   info.folds, shutBefore );
	await page.click( '#dze-mod-popup-close', { timeout: 3000 } ).catch( () => {} );

	// ---- AND WHAT IT LEFT YOU IS SETTLED HERE ----
	// "Ici ce serait bien de pouvoir review la task directement sans partir."
	const todo = await page.evaluate( () => {
		const rows = Array.from( document.querySelectorAll( '#dze-auto-waiting .dze-auto-job' ) );
		return {
			n: rows.length,
			ids: rows.map( r => r.getAttribute( 'data-id' ) ),
			// Three controls per row, the review list's own.
			controls: rows[0] ? Array.from( rows[0].querySelectorAll( 'button' ) )
				.map( b => b.className.split( ' ' ).filter( c => 0 === c.indexOf( 'dze-q-' ) )[0] ) : [],
			// It names the object and its id, and opens it.
			opens: rows[0] ? ( rows[0].querySelector( 'a' ) || {} ).href || '' : '',
			objid: rows[0] ? ( ( rows[0].querySelector( '.dze-objid' ) || {} ).textContent || '' ) : '',
			// A ROW IS ONE LINE OF WORK: the three answers sit together at the
			// end of it, not stacked under the name.
			oneLine: rows[0] ? ( () => {
				const b = Array.from( rows[0].querySelectorAll( 'button' ) ).map( x => Math.round( x.getBoundingClientRect().top ) );
				return Math.max( ...b ) - Math.min( ...b ) < 6;
			} )() : false,
			rest: ( document.querySelector( '#dze-auto-waiting .dze-auto-waiting a' ) || {} ).textContent || '',
			// AND IT IS NOT FOLDED AWAY: this is the work, open on the page.
			open: !! ( document.querySelector( '#dze-auto-waiting' ) || {} ).offsetParent,
			heading: ( document.querySelector( '.dze-auto-h2' ) || {} ).textContent || ''
		};
	} );
	ok( 'one list holds what was left',     todo.n, 2 );
	ok( 'open on the page, not folded',     todo.open, true );
	ok( 'under its own heading',            todo.heading, 'To review' );
	ok( 'a row per job, carrying its id',   todo.ids, [ '41', '42' ] );
	ok( 'with the review list\'s own three', todo.controls, [ 'dze-q-open', 'dze-q-yes', 'dze-q-no' ] );
	ok( 'side by side',                     todo.oneLine, true );
	ok( 'the row opens its own object',     /tag_ID=6223$/.test( todo.opens ), true );
	ok( 'and prints its id',                todo.objid, '6223' );
	ok( 'the rest points at the whole list', todo.rest, '1 more in Content to review' );

	// PRESSING REVIEW OPENS THE ONE POPUP — and sends THAT row's own id. A
	// button tested on the fact that it exists is the mistake paid for three
	// times in one week here.
	const before = sent.length;
	await page.click( '#dze-auto-waiting .dze-auto-job[data-id="41"] .dze-q-open', { timeout: 3000 } ).catch( () => {} );
	const opened = await page.waitForFunction(
		() => /old|stands|Accept/.test( ( document.getElementById( 'dze-q-body' ) || {} ).textContent || '' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	const ask = sent.slice( before ).filter( r => 'dze_q_review' === r.action )[0] || {};
	ok( 'the press asks for that job',      ask.action, 'dze_q_review' );
	ok( 'naming the row it sits on',        ask.id, '41' );
	ok( 'with the queue\'s own nonce',      ( ask.nonce || '' ).length > 0, true );
	ok( 'and the popup comes up',           opened, true );
	ok( 'on this screen, not another',
		await page.locator( '#dze-q-modal.is-open' ).isVisible().catch( () => false ), true );
	await page.click( '#dze-q-modal .dze-hub-close', { timeout: 3000 } ).catch( () => {} );

	// SAYING YES ON THE LINE settles it through the same endpoint the review
	// list uses, and the block answers for itself.
	const was2 = sent.length;
	await page.click( '#dze-auto-waiting .dze-auto-job[data-id="41"] .dze-q-yes', { timeout: 3000 } ).catch( () => {} );
	const settled = await page.waitForFunction(
		() => 1 === document.querySelectorAll( '#dze-auto-waiting .dze-auto-job' ).length,
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	const yes = sent.slice( was2 ).filter( r => 'dze_q_decide' === r.action )[0] || {};
	ok( 'the tick decides that job',        [ yes.action, yes.id, yes.accept ], [ 'dze_q_decide', '41', '1' ] );
	ok( 'the block re-reads itself',        settled, true );
	ok( 'and its figures follow the rows',
		( await page.textContent( '.dze-auto-task:first-of-type .dze-auto-chip.is-wait' ).catch( () => '' ) || '' ).trim(), '1' );

	// ---- THE WHOLE SITE IN ONE PRESS ----
	// "J'aurais même bien aimé pouvoir lancer le maillage interne de tout le
	// site en une fois, puis automatiser le maillage des nouvelles pages."
	// It belongs to the linking task and to no other, and it asks before it
	// spends: a press that puts a few hundred passes in the queue says so.
	const catchup = await page.evaluate( () => ( {
		here: !! document.querySelector( '.dze-auto-task:first-of-type .dze-auto-catchup' ),
		elsewhere: document.querySelectorAll( '.dze-auto-catchup' ).length,
		word: ( document.querySelector( '.dze-auto-catchup' ) || {} ).textContent || '',
		tip: ( ( document.querySelector( '.dze-auto-catchup' ) || {} ).getAttribute( 'title' ) || '' ).length
	} ) );
	ok( 'the linking task can catch up',    catchup.here, true );
	ok( 'and no other task offers it',      catchup.elsewhere, 1 );
	ok( 'it says what it does',             catchup.word.trim(), 'Link the whole site' );
	ok( 'with the consequence on its hover', catchup.tip > 40, true );
	let asked = '';
	page.once( 'dialog', d => { asked = d.message(); } );
	const wasC = sent.length;
	await page.click( '.dze-auto-task:first-of-type .dze-auto-catchup', { timeout: 3000 } ).catch( () => {} );
	const saidC = await page.waitForFunction(
		() => /312 pages queued/.test( ( document.querySelector( '.dze-auto-task .dze-auto-msg' ) || {} ).textContent || '' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	const askC = sent.slice( wasC ).filter( r => 'dze_auto_catchup' === r.action )[0] || {};
	ok( 'it asks the shop first',           asked.length > 40, true );
	ok( 'then asks the server',             askC.action, 'dze_auto_catchup' );
	ok( 'naming the task it belongs to',    askC.task, 'mesh_links' );
	ok( 'and says in words what happened',  saidC, true );
	// AND IT SAYS WHETHER THAT WAS THE LOT: the one thing somebody pressing
	// this needs to know, and a figure alone does not answer it.
	ok( 'including whether there is more',
		( await page.textContent( '.dze-auto-task:first-of-type .dze-auto-msg' ).catch( () => '' ) || '' ).includes( 'press again' ), true );

	// ---- AND THE PRESS MOVES THE LINE IT WAS PRESSED ON ----
	const was = sent.length;
	await page.click( '.dze-auto-task:first-of-type .dze-auto-run', { timeout: 3000 } ).catch( () => {} );
	const answered = await page.waitForFunction(
		() => /4/.test( ( document.querySelector( '.dze-auto-task .dze-auto-chip.is-wait' ) || {} ).textContent || '' ),
		null, { timeout: 6000 } ).then( () => true ).catch( () => false );
	ok( 'the press asks the server',        ( sent[ was ] || {} ).action, 'dze_auto_run' );
	ok( 'naming the task it belongs to',    ( sent[ was ] || {} ).task, 'mesh_links' );
	ok( 'with its nonce',                   ( ( sent[ was ] || {} ).nonce || '' ).length > 0, true );
	ok( 'and the line says what changed',   answered, true );
	ok( 'the answer is said in words too',
		( await page.textContent( '.dze-auto-task:first-of-type .dze-auto-msg' ).catch( () => '' ) || '' ).includes( 'Queued' ), true );
	// "CA DEVRAIT AFFICHER LA PROGRESSION DIRECTEMENT ICI." The press queues
	// the work, so the bar it starts belongs under the button that started it —
	// not in one block below all three tasks, where a figure answers for
	// somebody else's press.
	const grew = await page.waitForFunction(
		() => !! document.querySelector( '.dze-auto-task:first-of-type .dze-auto-live .dze-auto-bar' ),
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'the progress shows in that block', grew, true );
	// AND IN NO OTHER: a task that queued nothing says nothing.
	ok( 'and in no other task\'s block',
		await page.locator( '.dze-auto-live[data-task="cat_desc"] .dze-auto-bar' ).count(), 0 );

	// A PAGE RELOAD IS NEVER THE ANSWER TO "DID THAT WORK?"
	ok( 'the page never moved',             moves.length, 1 );
	// AND A SCREEN THAT HOLDS NO LIST OF JOBS NEVER DRIVES THE QUEUE: one PHP
	// worker stepping the queue for a page that shows nothing running is
	// weight nobody asked for.
	ok( 'it never polls the queue',         sent.filter( r => 'dze_q_status' === r.action ).length, 0 );
	ok( 'and never steps it',               sent.filter( r => 'dze_q_run' === r.action ).length, 0 );

	// ---- THE WORK THIS SCREEN STARTED, WATCHED AND STEPPED ----
	// "J'ai lancé run once et je suis perdu. Je fais quoi ensuite pour
	// contrôler le travail ? Rien de nouveau n'apparaît dans To review même
	// après actualisation." Only a browser can see a bar climb, and only a
	// browser can see it survive a reload.
	const barNow = () => page.evaluate( () => {
		const b = document.querySelector( '.dze-auto-live .dze-auto-bar > span' );
		const f = document.querySelector( '.dze-auto-live .dze-auto-runfig' );
		return { w: b ? b.style.width : '', fig: f ? f.textContent.trim() : '', on: !! document.querySelector( '.dze-auto-live .is-working' ) };
	} );
	// THE SERVER DREW IT. Read off the live page at this point the check would
	// pass on a bar an earlier press had put there, which proves nothing about
	// what a shop sees when it opens the screen.
	ok( 'the server draws the bar itself',   /dze-auto-bar/.test( dumped.html ), true );
	// AND DRAWS IT INSIDE THE TASK, not in one block under the lot.
	ok( 'inside the block that starts it',
		await page.locator( '.dze-auto-task:first-of-type .dze-auto-live .dze-auto-bar' ).count() > 0, true );
	ok( 'and there is no bar under the lot', await page.locator( '#dze-auto-run' ).count(), 0 );
	const first = await barNow();
	ok( 'the bar is on the screen at load',  first.w.length > 0, true );
	ok( 'and says it is working',            first.on, true );

	// IT MOVES ON ITS OWN, because the page is the engine while it is open.
	const climbed = await page.waitForFunction( was => {
		const f = document.querySelector( '.dze-auto-live .dze-auto-runfig' );
		return f && f.textContent.trim() !== was;
	}, first.fig, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'the bar moves without a press',     climbed, true );
	const stepped = sent.filter( r => 'dze_auto_run_state' === r.action && '1' === r.step );
	ok( 'and each tick takes one step',      stepped.length > 0, true );

	// IT FINISHES, and says where the work went — "je fais quoi ensuite ?".
	const ended = await page.waitForFunction(
		() => !! document.querySelector( '.dze-auto-live .is-done' ),
		null, { timeout: 15000 } ).then( () => true ).catch( () => false );
	ok( 'it finishes',                       ended, true );
	const endTxt = await page.evaluate( () => ( document.querySelector( '.dze-auto-live' ) || {} ).textContent || '' );
	ok( 'and points at what is waiting',     /waiting for your yes or no, below/.test( endTxt ), true );
	// AND IT STOPS. Polling an idle queue is a request a second for nothing.
	const afterEnd = sent.filter( r => 'dze_auto_run_state' === r.action ).length;
	await page.waitForTimeout( 2500 );
	ok( 'and stops asking once it is idle',
		sent.filter( r => 'dze_auto_run_state' === r.action ).length, afterEnd );

	// ---- A WATCHER THAT CANNOT BE KILLED, AND A RUN THAT CAN BE RESTARTED ----
	// "c'est bloqué." Two hundred pages queued, the bar at 0%, nothing written.
	// `runTick` had one handler and three ways out of it — a request that
	// failed, an answer that was not a success, an answer with no data — and
	// every one of them left the watcher dead with the bar frozen exactly where
	// it stood, saying "Leave this screen open and it keeps going". None of
	// that exists until a browser makes the server refuse.
	left = 4; done = 0; refuse = 2;
	await page.evaluate( () => { document.dispatchEvent( new Event( 'dze:queued' ) ); } );
	// Old jQuery does not see a native Event on document for a delegated
	// handler bound with .on: fire it the way the plugin's own code does.
	await page.evaluate( () => { window.jQuery( document ).trigger( 'dze:queued' ); } );
	const stumbled = await page.waitForFunction(
		() => /did not answer/.test( ( document.querySelector( '.dze-auto-runsaid' ) || {} ).textContent || '' ),
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'a refused answer is said out loud', stumbled, true );
	// AND IT KEEPS ASKING. The whole fault: it used to stop here for ever.
	const cameBack = await page.waitForFunction(
		() => /Writing/.test( ( document.querySelector( '.dze-auto-runsaid' ) || {} ).textContent || '' ),
		null, { timeout: 15000 } ).then( () => true ).catch( () => false );
	ok( 'and the watcher carries on',        cameBack, true );
	// AND THE WORK GOES ON MOVING once the server is answering again.
	const movedOn = await page.waitForFunction(
		() => /[1-9]\d* of \d+ written/.test( ( document.querySelector( '.dze-auto-runfig' ) || {} ).textContent || '' ),
		null, { timeout: 15000 } ).then( () => true ).catch( () => false );
	ok( 'and the bar moves again',           movedOn, true );

	// A RUN THAT HAS STOPPED offers the one control that can act, and pressing
	// it is the only way to know it is wired to anything.
	stopped = true; left = 5; done = 0;
	await page.evaluate( () => { window.jQuery( document ).trigger( 'dze:queued' ); } );
	const sawStuck = await page.waitForFunction(
		() => !! document.querySelector( '.dze-auto-live .dze-auto-again' ),
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'a stopped run says it is stopped',  sawStuck, true );
	ok( 'and does not claim to be working',
		/Leave this screen open/.test( await page.locator( '.dze-auto-live[data-task="mesh_links"]' ).innerText() ), false );
	const wasAgain = sent.length;
	await page.locator( '.dze-auto-again' ).click();
	const restarted = await page.waitForFunction(
		() => /put back in the queue/.test( ( document.querySelector( '.dze-auto-restarted' ) || {} ).textContent || '' ),
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'the press says what it did',        restarted, true );
	const again = sent.slice( wasAgain ).filter( r => 'dze_auto_run_again' === r.action );
	ok( 'it goes out as its own request',    again.length, 1 );
	ok( 'signed',                            !! ( again[0] || {} ).nonce, true );
	// AND THE ANSWER LANDS: the block is redrawn, and it is going again.
	ok( 'the block is redrawn working',
		await page.locator( '.dze-auto-live[data-task="mesh_links"] .is-working' ).count() > 0, true );
	// AND THE RUN PICKS UP FROM THERE rather than waiting to be pressed again.
	const rolling = await page.waitForFunction(
		() => /[1-9]\d* of \d+ written/.test( ( document.querySelector( '.dze-auto-runfig' ) || {} ).textContent || '' ),
		null, { timeout: 15000 } ).then( () => true ).catch( () => false );
	ok( 'and the work carries on',           rolling, true );
	// AND IT CAN BE CALLED OFF. "Start it again > Il faut une option aussi pour
	// annuler." A press that throws work away asks first, and only a browser
	// can see whether the question is put at all.
	stopped = true; left = 40; done = 0;
	await page.evaluate( () => { window.jQuery( document ).trigger( 'dze:queued' ); } );
	await page.waitForFunction(
		() => !! document.querySelector( '.dze-auto-live .dze-auto-stop' ),
		null, { timeout: 8000 } ).catch( () => {} );
	ok( 'a run under way can be called off',
		await page.locator( '.dze-auto-live[data-task="mesh_links"] .dze-auto-stop' ).count(), 1 );
	// IT ASKS BEFORE IT DROPS, and refused it drops nothing.
	let offAsked = '';
	page.off( 'dialog', sayYes );
	const saysNo = d => { offAsked = d.message(); d.dismiss(); };
	page.on( 'dialog', saysNo );
	const wasStop = sent.length;
	await page.locator( '.dze-auto-stop' ).click();
	await page.waitForTimeout( 400 );
	ok( 'it asks before throwing work away', offAsked.length > 0, true );
	// AND THE QUESTION SAYS WHAT IS KEPT — the half somebody hesitating needs.
	ok( 'and the question says what stays',  /waiting for your yes or no is kept/.test( offAsked ), true );
	ok( 'refused, nothing goes on the wire',
		sent.slice( wasStop ).filter( r => 'dze_auto_run_stop' === r.action ).length, 0 );
	// ACCEPTED, IT GOES — and the answer lands.
	page.off( 'dialog', saysNo );
	page.on( 'dialog', sayYes );
	await page.locator( '.dze-auto-stop' ).click();
	const calledOff = await page.waitForFunction(
		() => /called off/.test( ( document.querySelector( '.dze-auto-restarted' ) || {} ).textContent || '' ),
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'accepted, it says what it did',     calledOff, true );
	const offs = sent.filter( r => 'dze_auto_run_stop' === r.action );
	ok( 'one request, signed',               offs.length > 0 && !! offs[0].nonce, true );
	// AND THE RUN STOPS BEING WATCHED: polling a queue that was called off is a
	// request a second for nothing.
	const afterOff = sent.filter( r => 'dze_auto_run_state' === r.action ).length;
	await page.waitForTimeout( 2500 );
	ok( 'and it stops asking',
		sent.filter( r => 'dze_auto_run_state' === r.action ).length, afterOff );
	ok( 'the page never moved',              moves.length, 1 );

	// Drain what is left, so the section below reads a finished queue.
	await page.waitForFunction(
		() => !! document.querySelector( '.dze-auto-live .is-done' ),
		null, { timeout: 20000 } ).catch( () => {} );

	// ---- ACCEPT OR CANCEL A WHOLE SELECTION ----
	// "Des coches, la possibilité d'accepter ou de refuser en groupe." Only a
	// browser can see a tick wake a bar, and only a browser can see what a group
	// press puts on the wire.
	// A HARNESS SECTION THAT EMPTIED THE LIST PUTS IT BACK. An earlier press
	// settled rows through the row's own ✓, so by here the fake queue holds
	// none — and every check below would pass for the wrong reason, on an empty
	// list. Same rule as `fresh()` on the PHP side.
	decided.clear();
	await page.evaluate( html => { document.getElementById( 'dze-auto-waiting' ).innerHTML = html; }, serverWaiting );
	const bar = page.locator( '.dze-auto-bulk' );
	ok( 'the bar is on the screen',       await bar.count(), 1 );
	ok( 'and starts asleep',
		await page.locator( '.dze-auto-yes' ).isDisabled(), true );
	ok( 'saying nothing yet',
		( await page.locator( '.dze-auto-picked' ).innerText() ).trim(), '' );

	const cbs = page.locator( '.dze-auto-todo .dze-auto-cb' );
	const jobs = await cbs.count();
	ok( 'a tick per waiting row',         jobs > 1, true );
	await cbs.first().check();
	ok( 'one ticked, the bar wakes',
		await page.locator( '.dze-auto-yes' ).isDisabled(), false );
	ok( 'and says how many',
		( await page.locator( '.dze-auto-picked' ).innerText() ).trim(), '1 picked' );

	// THE TAKE-ALL TAKES THEM ALL.
	await page.locator( '.dze-auto-allcb' ).check();
	ok( 'the take-all takes the lot',
		await page.locator( '.dze-auto-todo .dze-auto-cb:checked' ).count(), jobs );
	ok( 'and the bar follows it',
		( await page.locator( '.dze-auto-picked' ).innerText() ).trim(), `${jobs} picked` );

	// THE PRESS. It presses the ROW'S OWN path — the same endpoint the single
	// ✓ uses — once per row, never a second engine on the server.
	const wasD = sent.length;
	const ids = await page.locator( '.dze-auto-todo .dze-auto-job' ).evaluateAll( ls => ls.map( l => String( l.dataset.id ) ) );
	await page.locator( '.dze-auto-yes' ).click();
	const emptied = await page.waitForFunction(
		() => 0 === document.querySelectorAll( '.dze-auto-todo .dze-auto-job' ).length,
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
	ok( 'every row is decided',           emptied, true );
	const decides = sent.slice( wasD ).filter( r => 'dze_q_decide' === r.action );
	ok( 'one press per row, on the row\'s own path', decides.length, jobs );
	ok( 'each naming its own row',        decides.map( r => String( r.id ) ).sort(), ids.slice().sort() );
	ok( 'and all of them accepting',      decides.every( r => '1' === r.accept ), true );
	ok( 'and the bar goes with them',     await page.locator( '.dze-auto-bulk' ).count(), 0 );
	ok( 'the list says which empty it is',
		/Nothing is waiting/.test( await page.locator( '#dze-auto-waiting' ).innerText() ), true );
	ok( 'and the page never moved',       moves.length, 1 );

	ok( 'nothing was raised reading it',    errors, [] );
	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
