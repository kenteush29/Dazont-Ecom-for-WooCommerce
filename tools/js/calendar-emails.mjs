/**
 * The marketing calendar, drawn in a real browser.
 *
 * Run before every release:  node tools/js/calendar-emails.mjs
 *
 * The grid is built by an inline script the plugin prints: PHP hands over the
 * promotions and the emails, and nothing appears until a browser runs it. A
 * test that reads the JSON proves the data travelled; only this one proves
 * the emails are DRAWN, on the right days, linking to the promotion they
 * belong to. The page comes from the plugin's own renderer — never a copy of
 * it — so a change to the grid is a change to what is tested.
 *
 * No jQuery: this script is plain DOM, as the calendar is.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );

let fails = 0, ran = 0;
function ok( what, got, want ) {
	ran++;
	if ( JSON.stringify( got ) === JSON.stringify( want ) ) { console.log( `  ok    ${what}` ); return; }
	fails++;
	console.log( `  FAIL  ${what}\n          got  ${JSON.stringify( got )}\n          want ${JSON.stringify( want )}` );
}

// The screen as the plugin draws it, through the same bench the PHP gate uses.
const html = execFileSync( 'php', [ join( root, 'tools/test-calendar.php' ), 'dazont-ecom', 'html' ], { encoding: 'utf8' } );

const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on( 'pageerror', e => errors.push( String( e ) ) );
page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );
await page.route( 'http://dze.test/', route => route.fulfill( { status: 200, contentType: 'text/html', body: html } ) );
await page.goto( 'http://dze.test/' );
await page.waitForTimeout( 200 );

console.log( 'The marketing calendar, in a browser' );
ok( 'the month draws without an error', errors, [] );
ok( 'the grid is really there', await page.isVisible( '.dze-cal__grid' ), true );

// The two emails of the fake shop, on their own days. A promotion is a band
// across its days; an email is one day, and it is the day something reaches a
// reader.
const chips = await page.$$eval( '.dze-cal__mail', els => els.map( e => e.textContent.trim() ) );
ok( 'both planned emails are drawn', chips.length, 2 );
ok( 'named as the shop names them',
	chips.map( t => t.replace( /[✉✓]/g, '' ).trim() ), [ 'Last chance', 'Warm-up' ] );

// On the right DAY — the whole point of putting them here. The bench dates
// them to the middle of the month the calendar opens on, so this is the same
// answer whatever day of the month the release happens on.
const onDay = await page.evaluate( () => {
	const out = {};
	document.querySelectorAll( '.dze-cal__day' ).forEach( td => {
		const n = td.querySelector( '.dze-cal__num' );
		td.querySelectorAll( '.dze-cal__mail' ).forEach( a => {
			out[ n ? n.textContent.trim() : '?' ] = a.textContent.replace( /[✉✓]/g, '' ).trim();
		} );
	} );
	return out;
} );
ok( 'the last chance sits on its own day', onDay['14'], 'Last chance' );
ok( 'and the warm-up on its own',          onDay['16'], 'Warm-up' );

// A chip is a way IN: the promotion's own screen, where an email is read,
// moved or written.
ok( 'a chip opens the promotion it belongs to',
	await page.getAttribute( '.dze-cal__mail', 'href' ),
	'https://shop.test/wp-admin/admin.php?page=dazont-ecom-marketing-events&edit=bts' );
ok( 'and says where it stands in Klaviyo',
	/scheduled — in Klaviyo/.test( await page.getAttribute( '.dze-cal__mail', 'title' ) ), true );
// One filed in Klaviyo is ticked; one that is not is marked as still to do —
// which is the thing worth seeing on a calendar a week before a promotion.
ok( 'the one in Klaviyo is ticked',
	( await page.$$eval( '.dze-cal__mail', els => els.map( e => e.className ) ) ),
	[ 'dze-cal__mail', 'dze-cal__mail is-todo' ] );
ok( 'and the one that is not says what is left to do',
	/not in Klaviyo yet/.test( await page.$$eval( '.dze-cal__mail', els => els[1].getAttribute( 'title' ) ) ), true );
ok( 'nothing was raised drawing them', errors, [] );

await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
