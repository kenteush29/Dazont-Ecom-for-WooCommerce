/**
 * The email row's own buttons, clicked in a real browser.
 *
 * Run before every release:  node tools/js/klaviyo-open.mjs
 *
 * `node --check` proves a script PARSES. It does not prove that clicking Open
 * opens anything: a call to a function nobody wrote, a selector that stopped
 * matching, an exception halfway down a handler — all of them parse, and all
 * of them leave the shop pressing a button that does nothing and says nothing.
 * So the page is built, the script is loaded into it, the button is clicked,
 * and what the page does is read back. Console errors fail the run.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );
// Both jQueries: the one WordPress ships today, and the one it will ship.
// jQuery 4 dropped $.trim, $.isArray, $.proxy and the rest of them, and a
// screen that leans on those is a screen that dies the day a shop installs an
// updater plugin — silently, because a dead handler looks like a button that
// does nothing. Pass the path to a jQuery build as the first argument, or run
// with none and both are tried.
// jQuery is not vendored into the plugin — it is WordPress's, and a copy in
// here would be one more thing to keep in step. The two builds the shop can
// actually be served are fetched once, beside this file, and never shipped.
const npm  = process.env.DZE_JQ_DIR || join( here, 'node_modules' );
if ( ! existsSync( join( npm, 'jquery/dist/jquery.min.js' ) ) ) {
	console.log( 'Fetching the two jQuery builds this test runs against…' );
	execFileSync( 'npm', [ 'install', '--silent', '--no-audit', '--no-fund',
		'jquery@4', 'jquery-3@npm:jquery@3.7.1' ], { cwd: here, stdio: 'inherit' } );
}
const jqs  = process.argv[2]
	? [ [ 'given', process.argv[2] ] ]
	: [ [ '3.7.1', join( npm, 'jquery-3/dist/jquery.min.js' ) ], [ '4.0.0', join( npm, 'jquery/dist/jquery.min.js' ) ] ];

let fails = 0, ran = 0;
function ok( what, got, want ) {
	ran++;
	if ( JSON.stringify( got ) === JSON.stringify( want ) ) { console.log( `  ok    ${what}` ); return; }
	fails++;
	console.log( `  FAIL  ${what}\n          got  ${JSON.stringify( got )}\n          want ${JSON.stringify( want )}` );
}

// The row's real stylesheet, straight out of the plugin — a layout test that
// invents its own CSS proves nothing about the screen the shop is served.
const css = execFileSync( 'php', [ '-r',
	'define("ABSPATH","/");function __($s,$d=""){return $s;}'
	+ 'require "' + join( root, 'dazont-ecom/includes/class-klaviyo.php' ) + '";'
	+ 'echo DZE_Klaviyo::list_css();' ], { encoding: 'utf8' } );

// The email screen, cut to what these handlers actually touch.
const day = ms => new Date( Date.now() + ms ).toISOString().slice( 0, 10 );
const page_html = `
<div id="dze-klav-editor" data-rule="promo" data-when='{"warm":"2026-08-24","launch":"2026-08-26","reminder":"2026-08-31","last":"2026-09-05"}' data-names='{"warm":"Warm-up","launch":"Launch","reminder":"Reminder","last":"Last chance"}' data-newkind="launch" data-newday="2026-08-26">
  <div class="dze-mail-list">
    <div class="dze-mail" data-id="mail1">
      <div class="dze-mail-thumb"><iframe title=""></iframe></div>
      <div class="dze-mail-what"><strong class="dze-mail-name">Launch</strong>
        <span class="dze-mail-when">old<span class="dze-smart">Smart</span></span><span class="dze-mail-subject">Back to school</span></div>
      <div class="dze-mail-state">
        <span class="dze-mail-langs">EN only</span>
        <button type="button" class="button button-small dze-mail-i18n" data-email="mail1">Translate again</button>
        <button type="button" class="button button-small dze-mail-sched" data-undo="0">Schedule it</button>
        <span class="dze-mail-sched-msg description"></span>
      </div>
      <div class="dze-mail-act">
        <button type="button" class="button button-small dze-mail-open">Edit</button>
        <button type="button" class="button-link dze-mail-drop">&times;</button>
      </div>
      <input type="hidden" class="dze-f-exists" value="1" />
      <input type="hidden" class="dze-f-kind" value="launch" />
      <input type="hidden" class="dze-f-picture" value="" />
      <input type="hidden" class="dze-f-want" value="0" />
      <input type="hidden" class="dze-f-subject" value="Back to school" />
      <input type="hidden" class="dze-f-preview" value="10% off" />
      <input type="hidden" class="dze-f-when" value="2026-08-28 09:00" />
      <textarea class="dze-f-body" style="display:none;">&lt;h1&gt;Sale&lt;/h1&gt;</textarea>
    </div>
  </div>
  <button type="button" id="dze-mail-new">+ Add an email</button>
  <button type="button" id="dze-mail-draftall">Put them all in Klaviyo</button>
  <span id="dze-mail-plan-msg"></span>
  <script type="text/template" id="dze-mail-blank"><div class="dze-mail" data-id="__ID__"><div class="dze-mail-thumb"><iframe title=""></iframe></div><div class="dze-mail-what"><strong class="dze-mail-name"></strong><span class="dze-mail-when"><span class="dze-smart">Smart</span></span><span class="dze-mail-subject"></span></div><div class="dze-mail-state"></div><div class="dze-mail-act"><button class="dze-mail-open">Edit</button><button class="button-link dze-mail-drop">&times;</button></div><input type="hidden" class="dze-f-exists" value="1" /><input type="hidden" class="dze-f-kind" value="" /><input type="hidden" class="dze-f-picture" value="" /><input type="hidden" class="dze-f-want" value="0" /><input type="hidden" class="dze-f-subject" value="" /><input type="hidden" class="dze-f-preview" value="" /><input type="hidden" class="dze-f-when" value="" /><textarea class="dze-f-body" style="display:none;"></textarea></div></script>
  <div id="dze-mail-edit" style="display:none;">
    <select id="dze-klav-e-type"><option value="warm">Warm-up</option><option value="launch">Launch</option><option value="reminder">Reminder</option><option value="last">Last chance</option></select>
    <input id="dze-klav-e-subject" /><input id="dze-klav-e-preview" />
    <input type="date" id="dze-klav-e-when" min="__TOMORROW__" />
    <span id="dze-klav-e-msg"></span><span id="dze-klav-e-kept"></span><span id="dze-klav-write-msg"></span>
    <input type="checkbox" id="dze-klav-e-want" />
    <button type="button" id="dze-klav-e-shot">Generate test picture</button>
    <p id="dze-klav-shot-out" style="display:none;"><img id="dze-klav-shot-img" src="" data-full="" alt="" /><button type="button" id="dze-klav-e-usepic">Use it in this email</button></p>
    <span id="dze-klav-shot-msg"></span><span id="dze-klav-spend"></span>
    <p id="dze-klav-haspic" style="display:none;"><span class="dze-zoomgroup"><span><img src="" data-full="" alt="" /></span></span><button type="button" id="dze-klav-e-nopic">Take it off</button></p>
    <button class="dze-klav-tab is-on" data-tab="view">Preview</button>
    <button class="dze-klav-tab" data-tab="sent">As sent</button>
    <button class="dze-klav-tab" data-tab="code">HTML</button>
    <textarea id="dze-klav-e-body"></textarea>
    <iframe id="dze-klav-e-iframe"></iframe>
    <div id="dze-klav-brief"></div><div id="dze-klav-brief-out"></div>
  </div>
</div>`;

const cfg = {
	ajaxUrl: 'http://dze.test/ajax', nonce: 'n', opt: 'dze_klaviyo', mark: '<!--DZE-->',
	shell: '<html><body><!--DZE--></body></html>', shopName: 'Kula',
	inactive: [],
	i18n: { loading: 'l', error: 'e', working: 'w', thenSave: 's', unsub: 'u', asSent: 'a',
	        creating: 'c', made: 'm', pickedFrom: 'p', unnamed: 'Untitled email',
	        sameType: 'Same type as another email — both will be written as that moment.',
	        rowWriting: 'Writing this email…', rowWrote: 'Written ✓',
	        rowPutting: 'Putting it in Klaviyo…', rowPut: 'In Klaviyo ✓',
	        noWritten: 'Nothing written yet.', drafting1: 'Putting %1$d of %2$d in Klaviyo…',
	        draftAll: 'All in Klaviyo.', draftSome: '%1$d in Klaviyo, %2$d failed.', open: 'Open draft ↗',
	        notBefore: 'The earliest an email can go out is tomorrow — moved.',
	        schedule: 'Schedule it', unschedule: 'Unschedule' },
	i18nBusy: 'Translating…', i18nDoing: 'Writing %s… (%i of %n)', i18nSaving: 'Filing…',
	i18nDone: 'Translated — %d texts in %s', i18nAgain: 'Translate again',
	i18nNone: 'No languages.', i18nKept: 'were written.', i18nFail: 'The translation did not finish.',
	dateFmt: 'd/m/Y',
	months: Array.from( { length: 12 }, ( _, i ) => ( { F: 'M' + ( i + 1 ), M: 'm' + ( i + 1 ) } ) )
};

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
const page = await browser.newPage();
const errors = [];
page.on( 'pageerror', e => errors.push( String( e ) ) );
page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );

// Every admin-ajax call this screen can make, answered without a server —
// and remembered, because WHICH calls a button makes is half of what it does.
const posted = [];
await page.route( 'http://dze.test/ajax*', route => {
	posted.push( new URLSearchParams( route.request().postData() || '' ).get( 'action' ) );
	route.fulfill( {
		status: 200, contentType: 'application/json',
		body: JSON.stringify( { success: true, data: { langs: [ 'fr', 'de' ], done: 12, html: '<p>ok</p>' } } )
	} );
} );

// Served from a real origin: a page with no base URL cannot POST anywhere,
// and every handler would look broken for a reason the shop does not have.
await page.route( 'http://dze.test/', route => route.fulfill( {
	status: 200, contentType: 'text/html',
	body: `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head>`
		+ `<body>${page_html.replace( '__TOMORROW__', day( 86400000 ) )}</body></html>`
} ) );
await page.goto( 'http://dze.test/' );
await page.addScriptTag( { path: jq } );
await page.addInitScript( () => {} );
await page.evaluate( c => { window.dzeKlav = c; window.ajaxurl = 'http://dze.test/ajax'; }, cfg );
await page.addScriptTag( { path: join( root, 'dazont-ecom/admin/js/klaviyo.js' ) } );

console.log( `The email screen, in a browser — jQuery ${label}` );
ok( 'the script loads without an error', errors, [] );

await page.click( '.dze-mail-open' );
await page.waitForTimeout( 250 );
ok( 'Open opens the editor', await page.isVisible( '#dze-mail-edit' ), true );
ok( 'and it opens THIS email',
	await page.inputValue( '#dze-klav-e-subject' ), 'Back to school' );
ok( 'the row is marked as the open one',
	await page.evaluate( () => document.querySelector( '.dze-mail' ).classList.contains( 'is-on' ) ), true );
ok( 'clicking Open raised nothing', errors, [] );

// The other button on that row: it must ask for the languages, write each
// one, and file them in ONE call. A handler that dies halfway leaves the
// same silent nothing as a button with no handler at all.
posted.length = 0;
await page.click( '.dze-mail-i18n' );
// Waited on the CALLS, never on the words: the row already said something
// before the click, and a test that waits for text it can already see is a
// test that passes before the button has done anything.
for ( let i = 0; i < 100 && ! posted.includes( 'dze_klav_i18nsave' ) && ! /did not finish|No languages/.test( await page.textContent( '.dze-mail-langs' ) ); i++ ) {
	await page.waitForTimeout( 100 );
}
await page.waitForTimeout( 200 );
ok( 'Translate asks for the languages', posted[0], 'dze_klav_langs' );
ok( 'writes each one',
	posted.filter( a => 'dze_klav_i18n' === a ).length, 2 );
ok( 'and files them in a single call',
	posted.filter( a => 'dze_klav_i18nsave' === a ).length, 1 );
ok( 'the row says what happened',
	/Translated/.test( await page.textContent( '.dze-mail-langs' ) ), true );
ok( 'and nothing was raised on the way', errors, [] );

// The day an email goes out: tomorrow at the earliest, and a date typed by
// hand rather than picked is corrected where it is typed.
ok( 'the picker refuses today', await page.getAttribute( '#dze-klav-e-when', 'min' ), day( 86400000 ) );
await page.fill( '#dze-klav-e-when', day( 0 ) );
await page.waitForTimeout( 150 );
ok( 'a day typed by hand is moved', await page.inputValue( '#dze-klav-e-when' ), day( 86400000 ) );
ok( 'and the screen says it moved',
	/tomorrow/.test( await page.textContent( '#dze-klav-e-kept' ) ), true );
await page.fill( '#dze-klav-e-when', day( 5 * 86400000 ) );
await page.waitForTimeout( 150 );
ok( 'a later day is left alone', await page.inputValue( '#dze-klav-e-when' ), day( 5 * 86400000 ) );

// A day the browser writes into the list must read like a day the server
// wrote: the same screen saying the same thing two ways is the bug. A
// RELATIVE day, never a literal: a date written into this file is in the
// future on the day the test is written and in the past ever after.
const in3 = day( 3 * 86400000 );
await page.fill( '#dze-klav-e-when', in3 );
await page.dispatchEvent( '#dze-klav-e-when', 'change' );
await page.waitForTimeout( 150 );
ok( "a day added here uses the shop's format",
	( await page.textContent( '.dze-mail-when' ) ).replace( 'Smart', '' ).trim(),
	in3.slice( 8, 10 ) + '/' + in3.slice( 5, 7 ) + '/' + in3.slice( 0, 4 ) );
ok( 'and the button says what it does',
	( await page.textContent( '.dze-mail-open' ) ).trim(), 'Edit' );

// Scheduling from the plugin: one click, and the button becomes its own undo.
posted.length = 0;
await page.unroute( 'http://dze.test/ajax*' );
await page.route( 'http://dze.test/ajax*', route => {
	const body = new URLSearchParams( route.request().postData() || '' );
	posted.push( { action: body.get( 'action' ), undo: body.get( 'undo' ) } );
	route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( {
		success: true,
		data: '1' === body.get( 'undo' )
			? { scheduled: 0, message: 'Back to a draft in Klaviyo.' }
			: { scheduled: 1, day: '2026-09-28', message: 'Scheduled in Klaviyo for 2026-09-28.' }
	} ) } );
} );
await page.click( '.dze-mail-sched' );
await page.waitForFunction( () => 'Unschedule' === document.querySelector( '.dze-mail-sched' ).textContent.trim(), null, { timeout: 5000 } );
ok( 'Schedule asks the right action', posted[0] && posted[0].action, 'dze_klav_schedule' );
ok( 'and does not ask to undo', posted[0] && posted[0].undo, '0' );
ok( 'the row says when it goes out',
	/Scheduled in Klaviyo for 2026-09-28/.test( await page.textContent( '.dze-mail-sched-msg' ) ), true );

await page.click( '.dze-mail-sched' );
await page.waitForFunction( () => 'Schedule it' === document.querySelector( '.dze-mail-sched' ).textContent.trim(), null, { timeout: 5000 } );
ok( 'the same button undoes it', posted[1] && posted[1].undo, '1' );
ok( 'and says it is a draft again',
	/draft/i.test( await page.textContent( '.dze-mail-sched-msg' ) ), true );
ok( 'nothing was raised scheduling', errors, [] );

// An added email is the announcement on the opening day — most promotions
// need exactly that one, and a SEQUENCE is the plan prompt's decision. The
// button briefly guessed "the next unused moment" instead, which pushed a
// four-email rhythm on promotions that wanted one email.
await page.click( '#dze-mail-new' );
await page.waitForTimeout( 150 );
ok( 'an added email is the launch',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail .dze-f-kind' )[1].value ), 'launch' );
ok( 'on the day the promotion opens',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail .dze-f-when' )[1].value ), '2026-08-26' );

// The first email of this page is a launch too — so the two rows now say so,
// where the emails are, rather than leaving it to be found in the inbox.
ok( 'two of one type is said on both rows',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail-dupe' ).length ), 2 );
ok( 'and it says what the trouble is',
	/Same type/.test( await page.textContent( '.dze-mail-dupe' ) ), true );

// Corrected on either row, the warning goes on both.
await page.selectOption( '#dze-klav-e-type', 'reminder' );
await page.waitForTimeout( 150 );
ok( 'corrected, the warning goes',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail-dupe' ).length ), 0 );
ok( 'and the day follows the chosen type',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail .dze-f-when' )[1].value ), '2026-08-31' );

// The picture bench: a candidate is looked at, and once it IS the email's
// picture the bench steps aside. It used to keep showing it beside the block
// that had just claimed it — the same photograph twice, one above the other.
await page.unroute( 'http://dze.test/ajax*' );
await page.route( 'http://dze.test/ajax*', route => route.fulfill( {
	status: 200, contentType: 'application/json',
	body: JSON.stringify( { success: true, data: { url: 'https://cdn.test/shot.jpg', full: 'https://cdn.test/shot.jpg' } } )
} ) );
// The photograph itself: Klaviyo hosts it in the shop, nothing does here, and
// a thumbnail that cannot load is a console error rather than a broken screen.
await page.route( 'https://cdn.test/**', route => route.fulfill( {
	status: 200, contentType: 'image/gif',
	body: Buffer.from( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64' )
} ) );
await page.click( '#dze-klav-e-shot' );
await page.waitForFunction( () => document.querySelector( '#dze-klav-shot-out' ).offsetParent !== null, null, { timeout: 5000 } );
ok( 'a test picture is shown on the bench', await page.isVisible( '#dze-klav-shot-out' ), true );
ok( 'and the email does not have it yet',   await page.isVisible( '#dze-klav-haspic' ), false );

await page.click( '#dze-klav-e-usepic' );
await page.waitForTimeout( 250 );
ok( 'using it files it on the email',       await page.isVisible( '#dze-klav-haspic' ), true );
ok( 'and the bench stops showing it twice', await page.isVisible( '#dze-klav-shot-out' ), false );
ok( 'the email carries that picture',
	await page.evaluate( () => document.querySelector( '.dze-mail.is-on .dze-f-picture' ).value ), 'https://cdn.test/shot.jpg' );
ok( 'nothing was raised on the bench', errors, [] );

// The batch says, ON THE ROW, what it is doing to that email — the counter at
// the bottom never said WHICH email was travelling nor which one failed.
// One email succeeds, the row it belongs to ends on its note and its link.
await page.unroute( 'http://dze.test/ajax*' );
await page.route( 'http://dze.test/ajax*', route => {
	const body = new URLSearchParams( route.request().postData() || '' );
	if ( 'dze_klav_draft' === body.get( 'action' ) ) {
		route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify(
			'mail1' === body.get( 'email' )
				? { success: true, data: {
					url: 'https://klaviyo.test/campaign/C1',
					// The state cell, as PHP renders it: what the row has just
					// earned by reaching Klaviyo.
					state: '<a href="https://klaviyo.test/campaign/C1" target="_blank" rel="noopener noreferrer">Draft in Klaviyo \u2197</a>'
						+ '<button type="button" class="button button-small dze-mail-sched" data-undo="0">Schedule it</button>'
						+ '<span class="dze-mail-sched-msg description"></span>'
						+ '<span class="dze-mail-langs">EN written, FR, DE open \u2014 not translated yet</span>'
						+ '<button type="button" class="button button-small dze-mail-i18n" data-email="mail1">Translate it</button>',
				} }
				: { success: false, data: { message: 'Klaviyo said no.' } }
		) } );
		return;
	}
	route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: {} } ) } );
} );
// A second written email that will FAIL, so both endings are read.
await page.evaluate( () => {
	const row = document.querySelector( '.dze-mail' ).cloneNode( true );
	row.setAttribute( 'data-id', 'mail9' );
	row.querySelector( '.dze-f-when' ).value = '2026-09-09';
	document.querySelector( '.dze-mail-list' ).appendChild( row );
} );
await page.click( '#dze-mail-draftall' );
await page.waitForFunction( () => /In Klaviyo|failed/.test( document.querySelector( '#dze-mail-plan-msg' ).textContent + document.body.textContent ), null, { timeout: 5000 } );
await page.waitForTimeout( 300 );
ok( 'the row that made it says so',
	( await page.evaluate( () => document.querySelector( '.dze-mail[data-id="mail1"] .dze-mail-note' )?.textContent ) ) || '', 'In Klaviyo ✓' );
ok( 'and carries its fresh draft link',
	await page.evaluate( () => document.querySelector( '.dze-mail[data-id="mail1"] .dze-mail-state a' )?.getAttribute( 'href' ) ), 'https://klaviyo.test/campaign/C1' );
// "Tout ça apparaît seulement après rafraichissement de la page": the row used
// to keep the bare link and nothing else. Everything the email has just earned
// must be there to press, without reloading anything.
ok( 'the row can now be scheduled',
	await page.evaluate( () => !! document.querySelector( '.dze-mail[data-id="mail1"] .dze-mail-sched' ) ), true );
ok( 'and translated',
	await page.evaluate( () => !! document.querySelector( '.dze-mail[data-id="mail1"] .dze-mail-i18n' ) ), true );
ok( 'and says which languages are open',
	( await page.evaluate( () => document.querySelector( '.dze-mail[data-id="mail1"] .dze-mail-langs' )?.textContent ) ) || '',
	'EN written, FR, DE open — not translated yet' );
// The note lives inside that cell: replacing the cell must not throw it away.
ok( 'the note survives the redraw',
	( await page.evaluate( () => document.querySelector( '.dze-mail[data-id="mail1"] .dze-mail-note' )?.textContent ) ) || '', 'In Klaviyo ✓' );
ok( 'the row that failed says why, in red',
	( await page.evaluate( () => document.querySelector( '.dze-mail[data-id="mail9"] .dze-mail-note' )?.textContent ) ) || '', 'Klaviyo said no.' );
ok( 'nothing was raised by the batch', errors, [] );

// The row, MEASURED. The screen the shop sent had "Written by the autopilot"
// set one word per line and a warning running across the row under the
// buttons: the state column was nowrap, so a long sentence pushed the title
// column down to nothing. A grid cannot be checked by reading CSS — the boxes
// have to be laid out and measured.
console.log( 'The row holds its shape with a long warning in it' );
await page.setViewportSize( { width: 1440, height: 900 } );
await page.evaluate( () => {
	const row = document.querySelector( '.dze-mail[data-id="mail1"]' );
	row.querySelector( '.dze-mail-state' ).innerHTML =
		'<a href="#">Draft in Klaviyo</a>'
		+ '<span class="dze-mail-langs">Translated — 42 texts in FR, PL, ES · DE still to write</span>'
		+ '<span class="dze-mail-links">Links did NOT move for FR, PL, ES — WPML gave the same address back, '
		+ 'so those readers land on the English page. Check WPML → Languages → how URLs look, and that the '
		+ 'products are translated.</span>'
		+ '<div class="dze-mail-does"><button class="button button-small dze-mail-sched">Schedule it</button>'
		+ '<button class="button button-small dze-mail-i18n">Translate again</button></div>';
	row.querySelector( '.dze-mail-what' ).insertAdjacentHTML( 'beforeend',
		'<span class="dze-mail-check">Written by the autopilot — read it before it goes anywhere.</span>' );
} );
await page.waitForTimeout( 100 );
const box = sel => page.evaluate( s => {
	const el = document.querySelector( `.dze-mail[data-id="mail1"] ${s}` );
	const r = el.getBoundingClientRect();
	return { x: Math.round( r.x ), y: Math.round( r.y ), w: Math.round( r.width ), h: Math.round( r.height ) };
}, sel );

const what = await box( '.dze-mail-what' );
ok( 'the title column keeps a real width', what.w > 300, true );
// One word per line is the failure that was reported. The name is short; if
// its column were collapsed the whole cell would be taller than it is wide.
ok( 'and is not a column of single words', what.h < what.w, true );

const state = await box( '.dze-mail-state' );
ok( 'the state column stays inside its own share', state.w <= 380, true );
ok( 'and never overlaps the title column', state.x >= what.x + what.w - 1, true );

// The two buttons are side by side, at the END of the cell.
const sched = await box( '.dze-mail-sched' );
const i18n  = await box( '.dze-mail-i18n' );
ok( 'the buttons are on one line',      Math.abs( sched.y - i18n.y ) < 4, true );
ok( 'and in that order',                sched.x < i18n.x, true );
const links = await box( '.dze-mail-links' );
ok( 'the warning sits above them',      links.y + links.h <= sched.y + 2, true );
ok( 'and wraps instead of running out', links.w <= state.w + 1, true );

// Nothing spills out of the list: a row wider than its box is a scrollbar
// across the whole screen.
ok( 'the row does not overflow the list',
	await page.evaluate( () => {
		const l = document.querySelector( '.dze-mail-list' );
		return l.scrollWidth <= l.clientWidth + 1;
	} ), true );

await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
