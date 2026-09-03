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

const PIXEL = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
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
<div id="dze-klav-editor" data-rule="promo" data-gap="3" data-calendar='[{"day":"__IN7__","rule":"patriot","label":"Patriot Day Sale — Launch"},{"day":"__IN6__","rule":"patriot","label":"Patriot Day Sale — Warm-up"}]' data-when='{"warm":"2026-08-24","launch":"2026-08-26","reminder":"2026-08-31","last":"2026-09-05"}' data-names='{"warm":"Warm-up","launch":"Launch","reminder":"Reminder","last":"Last chance"}' data-newkind="launch" data-newday="2026-08-26">
  <div class="dze-mail-list">
    <div class="dze-mail" data-id="mail1">
      <div class="dze-mail-thumb"><iframe title=""></iframe></div>
      <div class="dze-mail-what"><strong class="dze-mail-name">Launch</strong>
        <span class="dze-mail-when">old<span class="dze-smart">Smart</span></span><span class="dze-mail-subject">Back to school</span><span class="dze-mail-clash"></span></div>
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
      <input type="hidden" class="dze-f-subject" value="Back to school" />
      <input type="hidden" class="dze-f-preview" value="10% off" />
      <input type="hidden" class="dze-f-when" value="2026-08-28 09:00" />
      <textarea class="dze-f-body" style="display:none;">&lt;h1&gt;Sale&lt;/h1&gt;</textarea>
    </div>
  </div>
  <button type="button" id="dze-mail-new">+ Add an email</button>
  <button type="button" class="button button-primary" id="dze-mail-all">Generate them all</button>
  <button type="button" id="dze-mail-draftall">Put them all in Klaviyo</button>
  <span id="dze-mail-plan-msg"></span>
  <script type="text/template" id="dze-mail-blank"><div class="dze-mail" data-id="__ID__"><div class="dze-mail-thumb"><iframe title=""></iframe></div><div class="dze-mail-what"><strong class="dze-mail-name"></strong><span class="dze-mail-when"><span class="dze-smart">Smart</span></span><span class="dze-mail-subject"></span><span class="dze-mail-clash"></span></div><div class="dze-mail-state"></div><div class="dze-mail-act"><button class="dze-mail-open">Edit</button><button class="button-link dze-mail-drop">&times;</button></div><input type="hidden" class="dze-f-exists" value="1" /><input type="hidden" class="dze-f-kind" value="" /><input type="hidden" class="dze-f-picture" value="" /><input type="hidden" class="dze-f-subject" value="" /><input type="hidden" class="dze-f-preview" value="" /><input type="hidden" class="dze-f-when" value="" /><textarea class="dze-f-body" style="display:none;"></textarea></div></script>
  <div id="dze-mail-edit" style="display:none;">
    <select id="dze-klav-e-type"><option value="warm">Warm-up</option><option value="launch">Launch</option><option value="reminder">Reminder</option><option value="last">Last chance</option></select>
    <input id="dze-klav-e-subject" /><input id="dze-klav-e-preview" />
    <input type="date" id="dze-klav-e-when" min="__TOMORROW__" />
    <span id="dze-klav-e-msg"></span><span id="dze-klav-e-kept"></span><span id="dze-klav-e-clash" style="display:none;"></span><button type="button" id="dze-klav-e-free" style="display:none;"></button><span id="dze-klav-write-msg"></span>
    <button type="button" id="dze-klav-e-write">Write this email</button>
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
	        // What the batch says while it works, and what it makes.
	        writing1: 'Writing %1$d of %2$d…', allDone: 'All written.', nothing: 'Nothing to write.',
	        rowShot: 'Making its picture…', rowShotOk: 'Written, with its picture ✓',
	        shooting: 'Making the picture…', shot: 'Picture made.',
	        clashSame: 'Same day as %1$s (%2$s).', clashOne: '1 day from %1$s (%2$s).',
	        clashNear: '%1$d days from %2$s (%3$s).', clashWant: 'Leave %d days between two emails.',
	        moveTo: 'Move it to %s',
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
	const asked = new URLSearchParams( route.request().postData() || '' ).get( 'action' );
	posted.push( asked );
	// What Klaviyo really holds, asked for the moment the screen has drawn.
	// Answered as the server answers it: the rows whose cell CHANGED, and one
	// line saying what moved.
	// What each endpoint answers, as the server answers it: the writing hands
	// back the email AND whether it left a place for a picture.
	if ( 'dze_klav_write' === asked ) {
		return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( {
			success: true, data: { subject: 'Written subject', preview: 'Written preview',
				body: '<h1>Sale</h1><img src="picture" />', picture: 1 } } ) } );
	}
	if ( 'dze_klav_i18nsave' === asked ) {
		// What the server really answers: the ROW, drawn by the one function
		// that draws it on the page. The browser composes no sentence of its
		// own, so a translated email reads the same before and after F5.
		return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( {
			success: true, data: { done: 43, langs: [ 'fr', 'de' ], note: 'Links point at the FR, DE pages of the shop.',
				state: '<span class="dze-mail-synced">&#10003; Synced with Klaviyo · draft</span>'
					+ '<span class="dze-mail-langs"><span class="dze-langs">'
					+ '<span class="dze-lang is-done"><span class="dze-lang-code">FR</span></span>'
					+ '<span class="dze-lang is-done"><span class="dze-lang-code">DE</span></span>'
					+ '</span><span class="dze-why" title="Translated — 43 texts · Links point at the FR, DE pages of the shop.">i</span></span>'
					+ '<div class="dze-mail-does">'
					+ '<button type="button" class="button button-small dze-mail-i18n" data-email="mail1">Translate again</button>'
					+ '<button type="button" class="button button-small dze-mail-sched" data-undo="0">Schedule it</button>'
					+ '<span class="dze-mail-sched-msg description"></span></div>' } } ) } );
	}
	if ( 'dze_klav_image' === asked ) {
		return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( {
			// A picture the page can really show without leaving the machine:
			// a URL it would try to fetch raises a console error, and half
			// this file asserts that nothing was raised.
			success: true, data: { url: PIXEL, full: PIXEL } } ) } );
	}
	const body = 'dze_klav_state' === asked
		? { success: true, data: { asked: true, message: 'Klaviyo had moved: Launch now points at the campaign that is really there.',
			// The WHOLE cell, as the server draws it — badge and buttons
			// together. A redraw that hands back only the new sentence would
			// take the row's buttons away with it.
			rows: { mail1: '<span class="dze-mail-synced">&#10003; Synced with Klaviyo · scheduled</span>'
				+ '<span class="dze-mail-langs">EN only</span>'
				+ '<button type="button" class="button button-small dze-mail-i18n" data-email="mail1">Translate again</button>'
				+ '<button type="button" class="button button-small dze-mail-sched" data-undo="0">Schedule it</button>'
				+ '<span class="dze-mail-sched-msg description"></span>' } } }
		: { success: true, data: { langs: [ 'fr', 'de' ], done: 12, html: '<p>ok</p>' } };
	route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( body ) } );
} );

// Served from a real origin: a page with no base URL cannot POST anywhere,
// and every handler would look broken for a reason the shop does not have.
await page.route( 'http://dze.test/', route => route.fulfill( {
	status: 200, contentType: 'text/html',
	body: `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head>`
		+ `<body>${page_html.replace( '__TOMORROW__', day( 86400000 ) ).replace( '__IN6__', day( 6 * 86400000 ) ).replace( '__IN7__', day( 7 * 86400000 ) )}</body></html>`
} ) );
await page.goto( 'http://dze.test/' );
await page.addScriptTag( { path: jq } );
await page.addInitScript( () => {} );
await page.evaluate( c => { window.dzeKlav = c; window.ajaxurl = 'http://dze.test/ajax'; }, cfg );
await page.addScriptTag( { path: join( root, 'dazont-ecom/admin/js/klaviyo.js' ) } );

console.log( `The email screen, in a browser — jQuery ${label}` );
ok( 'the script loads without an error', errors, [] );

// The rows are drawn from what was filed in the shop, and the account moves
// without it: the screen asks Klaviyo what it holds as soon as it is up, and
// redraws what has changed. Nothing of this exists until the page is opened.
await page.waitForTimeout( 300 );
ok( 'the screen asks Klaviyo what it holds', posted.includes( 'dze_klav_state' ), true );
ok( 'a row that moved is redrawn',
	/Synced with Klaviyo · scheduled/.test( await page.textContent( '.dze-mail[data-id="mail1"] .dze-mail-state' ) ), true );
ok( 'and the screen says what moved',
	/Klaviyo had moved/.test( await page.textContent( '#dze-mail-plan-msg' ) ), true );
ok( 'asking raised nothing',             errors, [] );

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
ok( 'and asks no language twice when it answers',
	posted.filter( a => 'dze_klav_i18n' === a ).length, 2 );
ok( 'and files them in a single call',
	posted.filter( a => 'dze_klav_i18nsave' === a ).length, 1 );
// The end state is the SERVER'S cell, never a sentence the browser wrote:
// the row used to say "Translated — 43 texts in FR, PL, ES" in its own words
// and something else after a reload.
ok( 'the row is redrawn by the server',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail[data-id="mail1"] .dze-lang.is-done' ).length ), 2 );
ok( 'the counting is behind the i, not on the row',
	/Translated — 43 texts/.test( await page.textContent( '.dze-mail[data-id="mail1"] .dze-mail-state' ) ), false );
ok( 'and it is in the i',
	/Translated — 43 texts/.test( await page.getAttribute( '.dze-mail[data-id="mail1"] .dze-why', 'title' ) ), true );
ok( 'the languages are there without a reload',
	await page.isVisible( '.dze-mail[data-id="mail1"] .dze-langs' ), true );
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

// Two emails too close together, across PROMOTIONS. The shop's Back to School
// last chance went out on the 5th and the Patriot Day warm-up was set for the
// 6th: two promotions that know nothing about each other, one reader who gets
// both. The screen judges its own rows against the whole shop's calendar.
await page.fill( '#dze-klav-e-when', day( 5 * 86400000 ) );
await page.dispatchEvent( '#dze-klav-e-when', 'change' );
await page.waitForTimeout( 200 );
// The NEAREST of them is the one named — the account holds two Patriot Day
// emails, one two days away and one the day after.
ok( 'the row says which email is too close',
	/1 day from Patriot Day Sale — Warm-up/.test( await page.textContent( '.dze-mail-clash' ) ), true );
ok( 'the editor says it where the day is chosen',
	/Leave 3 days between two emails/.test( await page.textContent( '#dze-klav-e-clash' ) ), true );
ok( 'and offers the nearest day that is free',
	( await page.textContent( '#dze-klav-e-free' ) ).trim(),
	// The NEAREST free day, not the first one that comes to mind: three days
	// before the warm-up clears it, and it is one day from where he put it.
	'Move it to ' + day( 3 * 86400000 ).slice( 8, 10 ) + '/' + day( 3 * 86400000 ).slice( 5, 7 ) + '/' + day( 3 * 86400000 ).slice( 0, 4 ) );
await page.click( '#dze-klav-e-free' );
await page.waitForTimeout( 200 );
ok( 'pressing it moves the day', await page.inputValue( '#dze-klav-e-when' ), day( 3 * 86400000 ) );
ok( 'and the warning goes with it',
	( await page.textContent( '.dze-mail-clash' ) ).trim(), '' );
ok( 'nothing was raised judging the calendar', errors, [] );
// Two days apart is another sentence, with another set of holes. Chained
// replacements put the day where the name belongs and printed it twice —
// "2 days from 2026-09-08 (2026-09-08)" — which named nothing at all.
await page.fill( '#dze-klav-e-when', day( 4 * 86400000 ) );
await page.dispatchEvent( '#dze-klav-e-when', 'change' );
await page.waitForTimeout( 200 );
{
	const said = ( await page.textContent( '.dze-mail-clash' ) ).trim();
	const when = day( 6 * 86400000 );
	ok( 'two days apart names the other email',
		said, '2 days from Patriot Day Sale — Warm-up ('
			+ when.slice( 8, 10 ) + '/' + when.slice( 5, 7 ) + '/' + when.slice( 0, 4 ) + ').' );
	// The WHOLE date, not its first two digits: on the ninth of September
	// "09/" appears twice in 09/09/2026 and this said the sentence was broken
	// when it was perfectly right. A check that fails on a calendar is a check
	// that will be ignored on the day it is telling the truth.
	const dmy = when.slice( 8, 10 ) + '/' + when.slice( 5, 7 ) + '/' + when.slice( 0, 4 );
	ok( 'and says the day once', said.split( dmy ).length - 1, 1 );
}

// "Comment générer les images directement en cliquant sur Generate them all ?"
// It wrote the emails and stopped: the picture waited for somebody to open
// each one. The tick box beside the button sets the SAME per-email permission
// the editor's own box sets, on every row at once, and the batch then makes
// each picture straight after the email it belongs to.
// No box to tick anywhere: writing an email includes its picture.
ok( 'nothing is asked for on the row',
	await page.evaluate( () => !! document.querySelector( '.dze-f-want, #dze-mail-shots, #dze-klav-e-want' ) ), false );
posted.length = 0;
await page.click( '#dze-mail-all' );
for ( let i = 0; i < 100 && ! posted.includes( 'dze_klav_image' ); i++ ) { await page.waitForTimeout( 100 ); }
await page.waitForTimeout( 200 );
ok( 'Generate them all writes the email',   posted.filter( a => 'dze_klav_write' === a ).length, 1 );
ok( 'and makes its picture in the same run', posted.filter( a => 'dze_klav_image' === a ).length, 1 );
ok( 'the picture is made AFTER the email',
	posted.indexOf( 'dze_klav_write' ) < posted.indexOf( 'dze_klav_image' ), true );
ok( 'and the row says what it got',
	/with its picture/.test( await page.textContent( '.dze-mail[data-id="mail1"] .dze-mail-note' ) ), true );

// The single Write button follows the same rule — it is the other way into
// the same decision, and a rule held in one of two places is a rule that will
// be found broken in the other.
posted.length = 0;
await page.click( '#dze-klav-e-write' );
for ( let i = 0; i < 100 && ! posted.includes( 'dze_klav_image' ); i++ ) { await page.waitForTimeout( 100 ); }
await page.waitForTimeout( 200 );
ok( 'writing one email makes its picture too',
	posted.filter( a => 'dze_klav_image' === a ).length, 1 );

// And an email the writing left NO place for a picture in gets none: the
// shop's own setting decides that, in the writing itself, not a box here.
await page.unroute( 'http://dze.test/ajax*' );
await page.route( 'http://dze.test/ajax*', route => {
	const asked = new URLSearchParams( route.request().postData() || '' ).get( 'action' );
	posted.push( asked );
	route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify(
		'dze_klav_write' === asked
			? { success: true, data: { subject: 'No picture here', body: '<h1>Sale</h1>', picture: 0 } }
			: { success: true, data: { langs: [ 'fr', 'de' ], done: 12, html: '<p>ok</p>' } } ) } );
} );
posted.length = 0;
await page.click( '#dze-mail-all' );
for ( let i = 0; i < 100 && ! posted.includes( 'dze_klav_write' ); i++ ) { await page.waitForTimeout( 100 ); }
await page.waitForTimeout( 400 );
ok( 'an email with no place for one writes alone',
	posted.filter( a => 'dze_klav_write' === a ).length, 1 );
ok( 'and no picture is made',               posted.filter( a => 'dze_klav_image' === a ).length, 0 );

// "Generate them all devrait générer les emails sans avoir besoin d'ouvrir la
// fenetre d'aperçu en focus. C'est mal codé. Et plusieurs emails devraient
// pouvoir etre générés en même temps et pas tous un par un."
//
// The run used to OPEN each email in turn — scrolling the page to the editor,
// filling its fields, and reading the model's answer back out of them — so the
// answer travelled through whichever email happened to be on screen. Four rows,
// the editor shut, and nothing touched but the button.
await page.evaluate( () => {
	const list = document.querySelector( '.dze-mail-list' );
	const one  = list.querySelector( '.dze-mail' );
	for ( const id of [ 'mail2', 'mail3', 'mail4' ] ) {
		const copy = one.cloneNode( true );
		copy.dataset.id = id;
		copy.querySelector( '.dze-f-subject' ).value = '';
		copy.querySelector( '.dze-f-body' ).value = '';
		list.appendChild( copy );
	}
	document.getElementById( 'dze-mail-edit' ).style.display = 'none';
	document.querySelectorAll( '.dze-mail' ).forEach( c => c.classList.remove( 'is-on' ) );
} );
let live = 0, peak = 0, wrote = 0;
await page.unroute( 'http://dze.test/ajax*' );
await page.route( 'http://dze.test/ajax*', async route => {
	const asked = new URLSearchParams( route.request().postData() || '' ).get( 'action' );
	const who   = new URLSearchParams( route.request().postData() || '' ).get( 'email' );
	if ( 'dze_klav_write' === asked ) {
		live++; wrote++;
		peak = Math.max( peak, live );
		await new Promise( r => setTimeout( r, 250 ) );
		live--;
		return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify(
			{ success: true, data: { subject: 'Written for ' + who, preview: 'P ' + who,
				body: '<h1>' + who + '</h1>', picture: 0 } } ) } );
	}
	route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: {} } ) } );
} );
await page.click( '#dze-mail-all' );
for ( let i = 0; i < 200 && wrote < 4; i++ ) { await page.waitForTimeout( 100 ); }
await page.waitForTimeout( 600 );
ok( 'every email is written', wrote, 4 );
// Several at once, and not all at once: a shop's PHP has a limited number of
// workers, and four model calls landing together is how one starts refusing
// pages. Two or three in flight, never one.
ok( 'more than one runs at a time', peak > 1, true );
ok( 'and never more than three',    peak <= 3, true );
// The answer lands on the ROW that asked for it. Through the editor it landed
// on whichever email was open, which is why the run needed one.
ok( 'each row keeps its own words',
	await page.evaluate( () => [ 'mail1', 'mail2', 'mail3', 'mail4' ].map(
		id => document.querySelector( '.dze-mail[data-id="' + id + '"] .dze-f-subject' ).value ) ),
	[ 'Written for mail1', 'Written for mail2', 'Written for mail3', 'Written for mail4' ] );
ok( 'and its own body',
	await page.evaluate( () => document.querySelector( '.dze-mail[data-id="mail3"] .dze-f-body' ).value ),
	'<h1>mail3</h1>' );
// The editor was never dragged into it.
ok( 'no email was opened',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail.is-on' ).length ), 0 );
ok( 'and the editor stayed shut',
	await page.isVisible( '#dze-mail-edit' ), false );
ok( 'nothing was raised writing them all', errors, [] );
// The screen put back the way the checks after this one expect it.
await page.evaluate( () => {
	document.querySelectorAll( '.dze-mail' ).forEach( ( c, i ) => { if ( i ) { c.remove(); } } );
} );
await page.click( '.dze-mail[data-id="mail1"] .dze-mail-open' );
await page.waitForTimeout( 150 );

// A day that clashes with nothing says nothing: a warning that is always
// there is a warning nobody reads.
await page.fill( '#dze-klav-e-when', day( 20 * 86400000 ) );
await page.dispatchEvent( '#dze-klav-e-when', 'change' );
await page.waitForTimeout( 200 );
ok( 'a day far from everything is quiet',
	await page.isVisible( '#dze-klav-e-clash' ), false );
await page.fill( '#dze-klav-e-when', in3 );
await page.dispatchEvent( '#dze-klav-e-when', 'change' );
await page.waitForTimeout( 150 );

// Scheduling from the plugin: one click, and the button becomes its own undo.
posted.length = 0;
await page.unroute( 'http://dze.test/ajax*' );
await page.route( 'http://dze.test/ajax*', route => {
	const body = new URLSearchParams( route.request().postData() || '' );
	posted.push( { action: body.get( 'action' ), undo: body.get( 'undo' ) } );
	route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( {
		success: true,
		// The cell the server draws travels with the answer: scheduled, it
		// offers no rewrite; back to a draft, Translate again is on the row
		// again. Without it the row kept saying "scheduled" and the buttons
		// stayed gone until somebody reloaded.
		data: '1' === body.get( 'undo' )
			? { scheduled: 0, message: 'Back to a draft in Klaviyo.',
				state: '<button type="button" class="button dze-mail-sched" data-undo="0">Schedule it</button>'
					+ ' <button type="button" class="button dze-mail-i18n dze-back">Translate again</button>'
					+ '<span class="dze-mail-sched-msg description"></span>' }
			: { scheduled: 1, day: '2026-09-28', message: 'Scheduled in Klaviyo for 2026-09-28.',
				state: '<button type="button" class="button dze-mail-sched" data-undo="1">Unschedule</button>'
					+ '<span class="dze-mail-sched-msg description"></span>' }
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
// The whole point of undoing it: the row is writable again. A scheduled
// campaign is locked in Klaviyo, so its cell drops Update and Translate
// again — and the row went on showing neither, with "scheduled" still on it,
// until the page was reloaded.
// Asserted on the cell the SERVER sent, never on what the browser could have
// written by itself: the row already carried a Translate again button before
// the click, so "it is there afterwards" is a check that passes on the bug.
ok( 'the row the server drew is the one on screen',
	await page.isVisible( '.dze-mail-i18n.dze-back' ), true );
ok( 'and it replaced the old cell rather than joining it',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail-sched' ).length ), 1 );
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

// A language whose request never comes back is asked ONE more time, in a
// request of its own. It used to be retried inside the same request — two
// model calls of up to two minutes behind one HTTP call — and the shop's own
// server hung up on it: "The translation did not finish. (504)", which is a
// gateway, not a model, and carries nothing to read.
await page.unroute( 'http://dze.test/ajax*' );
{
	let deTries = 0;
	await page.route( 'http://dze.test/ajax*', route => {
		const body = new URLSearchParams( route.request().postData() || '' );
		const asked = body.get( 'action' );
		posted.push( asked );
		if ( 'dze_klav_langs' === asked ) {
			return route.fulfill( { status: 200, contentType: 'application/json',
				body: JSON.stringify( { success: true, data: { langs: [ 'fr', 'de' ] } } ) } );
		}
		if ( 'dze_klav_i18n' === asked && 'de' === body.get( 'lang' ) ) {
			deTries += 1;
			// The gateway, exactly as the shop met it: no JSON, no message.
			if ( 1 === deTries ) {
				return route.fulfill( { status: 504, contentType: 'text/html', body: '<html>Gateway Time-out</html>' } );
			}
		}
		if ( 'dze_klav_i18nsave' === asked ) {
			return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( {
				success: true, data: { done: 40, langs: [ 'fr', 'de' ],
					state: '<span class="dze-mail-langs"><span class="dze-langs">'
						+ '<span class="dze-lang is-done"><span class="dze-lang-code">FR</span></span>'
						+ '<span class="dze-lang is-done"><span class="dze-lang-code">DE</span></span>'
						+ '</span></span>'
						+ '<button type="button" class="button button-small dze-mail-i18n" data-email="mail1">Translate again</button>' } } ) } );
		}
		return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: {} } ) } );
	} );
	posted.length = 0;
	await page.click( '.dze-mail-i18n' );
	for ( let i = 0; i < 100 && ! posted.includes( 'dze_klav_i18nsave' ); i++ ) { await page.waitForTimeout( 100 ); }
	await page.waitForTimeout( 200 );
	ok( 'the language that failed is asked again', deTries, 2 );
	ok( 'and only once more',
		posted.filter( a => 'dze_klav_i18n' === a ).length, 3 );
	ok( 'the run still files what it has',
		posted.filter( a => 'dze_klav_i18nsave' === a ).length, 1 );
	ok( 'and the row is drawn by the server',
		await page.evaluate( () => document.querySelectorAll( '.dze-mail[data-id="mail1"] .dze-lang.is-done' ).length ), 2 );
}
// The 504 above is this test's own: it must not be read as a screen that
// raised something.
errors.length = 0;

// The batch says, ON THE ROW, what it is doing to that email — the counter at
// the bottom never said WHICH email was travelling nor which one failed.
// One email succeeds, the row it belongs to ends on its note and its link.
await page.unroute( 'http://dze.test/ajax*' );
posted.length = 0;
await page.route( 'http://dze.test/ajax*', route => {
	const body = new URLSearchParams( route.request().postData() || '' );
	posted.push( body.get( 'action' ) );
	if ( 'dze_klav_draft' === body.get( 'action' ) ) {
		route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify(
			'mail1' === body.get( 'email' )
				? { success: true, data: {
					url: 'https://klaviyo.test/campaign/C1',
					// The state cell, as PHP renders it: what the row has just
					// earned by reaching Klaviyo.
					state: '<span class="dze-mail-synced">\u2713 Synced with Klaviyo \u00b7 draft</span>'
						+ '<a href="https://klaviyo.test/campaign/C1" target="_blank" rel="noopener noreferrer">Open in Klaviyo \u2197</a>'
						+ '<button type="button" class="button button-small dze-mail-sched" data-undo="0">Schedule it</button>'
						+ '<span class="dze-mail-sched-msg description"></span>'
						+ '<span class="dze-mail-langs">EN written, FR, DE open \u2014 not translated yet</span>'
						+ '<button type="button" class="button button-small dze-mail-i18n" data-email="mail1">Translate it</button>',
				} }
				: { success: false, data: { message: 'Klaviyo said no.' } }
		) } );
		return;
	}
	// Putting a promotion in Klaviyo TRANSLATES it in the same run: a template
	// rewritten in English leaves its translations describing the email it used
	// to be. So the chain answers here as it does anywhere else.
	if ( 'dze_klav_langs' === body.get( 'action' ) ) {
		route.fulfill( { status: 200, contentType: 'application/json',
			body: JSON.stringify( { success: true, data: { langs: [ 'fr', 'de' ] } } ) } );
		return;
	}
	if ( 'dze_klav_i18nsave' === body.get( 'action' ) ) {
		route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( {
			success: true, data: { done: 40, langs: [ 'fr', 'de' ],
				state: '<span class="dze-mail-synced">\u2713 Synced with Klaviyo \u00b7 draft</span>'
					+ '<a href="https://klaviyo.test/campaign/C1" target="_blank" rel="noopener noreferrer">Open in Klaviyo \u2197</a>'
					+ '<span class="dze-mail-langs"><span class="dze-langs">'
					+ '<span class="dze-lang is-done"><span class="dze-lang-code">FR</span></span>'
					+ '<span class="dze-lang is-done"><span class="dze-lang-code">DE</span></span>'
					+ '</span></span>'
					+ '<div class="dze-mail-does">'
					+ '<button type="button" class="button button-small dze-mail-sched" data-undo="0">Schedule it</button>'
					+ '<button type="button" class="button button-small dze-mail-i18n" data-email="mail1">Translate again</button>'
					+ '<span class="dze-mail-sched-msg description"></span></div>' } } ) } );
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
ok( 'the row says it is synced, whatever its state',
	( await page.textContent( '.dze-mail[data-id="mail1"] .dze-mail-synced' ) ).includes( 'Synced with Klaviyo' ), true );
ok( 'the row can now be scheduled',
	await page.evaluate( () => !! document.querySelector( '.dze-mail[data-id="mail1"] .dze-mail-sched' ) ), true );
ok( 'and translated',
	await page.evaluate( () => !! document.querySelector( '.dze-mail[data-id="mail1"] .dze-mail-i18n' ) ), true );
// "Put them all in Klaviyo > devrait aussi traduire directement." It does, in
// the same run and on the same row, through the very function the Translate
// button uses.
ok( 'the run translated it too',
	posted.filter( a => 'dze_klav_i18nsave' === a ).length, 1 );
ok( 'and its languages are written on the row',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail[data-id="mail1"] .dze-lang.is-done' ).length ), 2 );
ok( 'the one that failed was not translated',
	posted.filter( a => 'dze_klav_i18n' === a ).length, 2 );
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
		'<span class="dze-mail-synced">\u2713 Synced with Klaviyo \u00b7 draft</span><a href="#">Open in Klaviyo</a>'
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

// "see the log ↗" goes on MESSAGES. The plugin appends it to everything
// carrying is-ko, and the mark that hides a long explanation is a badge
// sixteen pixels across: the link landed inside it and ran across the
// sentence beside it, which is the overlap the shop photographed.
await page.evaluate( () => {
	window.dzeLogLink = { url: 'https://shop.test/wp-admin/admin.php?page=dze-health', label: 'see the log ↗', title: 'The log' };
	const row = document.querySelector( '.dze-mail-state' );
	row.insertAdjacentHTML( 'beforeend',
		'<span class="dze-mail-lost">Not written in DE <span class="dze-why is-bad" title="504">i</span></span>'
		// A feed Google refused: the same badge the whole plugin draws, with
		// what Google said on its own tooltip. "FR ✗ see the log ↗" put three
		// words of ours beside two characters of state, on a row that can
		// carry five languages.
		+ '<span class="dze-lang is-ko" title="FR — Google refused it"><span class="dze-lang-code">FR</span><b>✗</b></span>'
		+ '<span class="dze-mail-note is-ko">Klaviyo said no.</span>' );
} );
await page.addScriptTag( { path: join( root, 'dazont-ecom/admin/js/log-link.js' ) } );
await page.waitForTimeout( 250 );
ok( 'the badge is left alone',
	await page.evaluate( () => document.querySelectorAll( '.dze-why .dze-logl' ).length ), 0 );
ok( 'a refused language is left alone too',
	await page.evaluate( () => document.querySelectorAll( '.dze-lang .dze-logl' ).length ), 0 );
ok( 'and it says what happened on hover',
	await page.getAttribute( '.dze-lang.is-ko', 'title' ), 'FR — Google refused it' );
ok( 'and the message still carries the link',
	await page.evaluate( () => document.querySelectorAll( '.dze-mail-note.is-ko .dze-logl' ).length ), 1 );
ok( 'the warning reads as one sentence',
	( await page.textContent( '.dze-mail-lost' ) ).trim(), 'Not written in DE i' );

await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
