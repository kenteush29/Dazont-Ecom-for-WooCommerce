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

// The email screen, cut to what these handlers actually touch.
const day = ms => new Date( Date.now() + ms ).toISOString().slice( 0, 10 );
const page_html = `
<div id="dze-klav-editor" data-rule="promo" data-when="{}" data-names="{}" data-newkind="launch" data-newday="2026-09-01">
  <div class="dze-mail-list">
    <div class="dze-mail" data-id="mail1">
      <div class="dze-mail-thumb"><iframe title=""></iframe></div>
      <div class="dze-mail-what"><strong class="dze-mail-name">Launch</strong>
        <span class="dze-mail-when"></span><span class="dze-mail-subject">Back to school</span></div>
      <div class="dze-mail-state">
        <span class="dze-mail-langs">EN only</span>
        <button type="button" class="button button-small dze-mail-i18n" data-email="mail1">Translate again</button>
      </div>
      <div class="dze-mail-act">
        <button type="button" class="button button-small dze-mail-open">Open</button>
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
  <script type="text/template" id="dze-mail-blank"><div class="dze-mail" data-id="__ID__"><div class="dze-mail-act"><button class="dze-mail-open">Open</button></div></div></script>
  <div id="dze-mail-edit" style="display:none;">
    <select id="dze-klav-e-type"><option value="launch">Launch</option></select>
    <input id="dze-klav-e-subject" /><input id="dze-klav-e-preview" />
    <input type="date" id="dze-klav-e-when" min="__TOMORROW__" />
    <span id="dze-klav-e-msg"></span><span id="dze-klav-e-kept"></span><span id="dze-klav-write-msg"></span>
    <input type="checkbox" id="dze-klav-e-want" />
    <div id="dze-klav-shot-out"></div><span id="dze-klav-shot-msg"></span>
    <div id="dze-klav-haspic"></div>
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
	        creating: 'c', made: 'm', pickedFrom: 'p',
	        notBefore: 'The earliest an email can go out is tomorrow — moved.' },
	i18nBusy: 'Translating…', i18nDoing: 'Writing %s… (%i of %n)', i18nSaving: 'Filing…',
	i18nDone: 'Translated — %d texts in %s', i18nAgain: 'Translate again',
	i18nNone: 'No languages.', i18nKept: 'were written.', i18nFail: 'The translation did not finish.'
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
	body: `<!doctype html><html><body>${page_html.replace( '__TOMORROW__', day( 86400000 ) )}</body></html>`
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

await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
