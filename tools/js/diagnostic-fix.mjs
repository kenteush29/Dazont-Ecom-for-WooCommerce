/**
 * The button on a line of the problem list, PRESSED.
 *
 * Run before every release:  node tools/js/diagnostic-fix.mjs
 *
 * A button that makes no request looks exactly like a button that made one
 * and got nothing back, and neither `node --check` nor any PHP test can tell
 * them apart: the handler is bound when a browser runs the page and not
 * before. This screen has already shipped a dead button once, a button that
 * went to a settings page twice, and — the reason this file was rewritten —
 * a button that fired a generation on the press, showed nothing, and sent the
 * shop to a bulk list it had not asked for:
 *
 *   "Je clique sur Make a photograph, quelque chose a de suite été envoyé.
 *    J'aurais choisi 2 images photo shoot + 1 ugc si j'avais le choix. Donc
 *    1 - rien de contrôlable visible à l'appui sur le bouton et 2 - putain
 *    pourquoi me rediriger vers la liste bulk alors que j'ai appuyé sur un
 *    bouton individuel ?"
 *
 * So what is proved here is the opposite of what it proved before: the press
 * OPENS the product's own popup — the one the product screen and the products
 * list open — with the work laid out and NOTHING generated, and the page is
 * still the page it was.
 *
 * Both jQuery builds: the one WordPress ships today, and the one it will.
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

// The screen as the plugin draws it, from the gate that already owns the fake
// shop. Never a copy of the markup written into this file.
const html = execFileSync( 'php',
	[ join( here, '..', 'test-diagnostic.php' ), 'dazont-ecom', '--dump-list' ],
	{ encoding: 'utf8', cwd: root } );

// The three image prompts of the fake shop, in the order it wrote them —
// the same list the PHP gate hands the criterion.
const cfg = {
	ajaxUrl: 'http://dze.test/ajax',
	nonce: 'n0nce',
	postId: 0,
	product: { title: '', price: '', variable: false },
	fields: { f_post_content: 'Description', f_seo_title: 'SEO title' },
	validated: { f_post_content: 1, f_seo_title: 1 },
	rich: { f_post_content: 1 },
	prompts: {},
	rowcfg: {},
	inputOpts: {},
	dests: {},
	metaKeys: [],
	anchors: [],
	backdrops: [],
	scenes: [],
	blockers: [],
	templates: [
		{ id: 'main1',  name: 'Main image',    target: 'main',    valid: 1 },
		{ id: 'detail', name: 'Detail shot',   target: 'gallery', valid: 1 },
		{ id: 'scene',  name: 'Scene, in use', target: 'gallery', valid: 1 }
	],
	i18n: { toolbox: 'Dazont Ecom', close: 'Close', text: 'Text', image: 'Photographs',
		price: 'Price', launch: 'Generate', discard: 'Discard', applyOne: 'Apply',
		genImgOpt: 'Make photographs', template: 'Prompt', scene: 'Scene', attempts: 'How many',
		putIt: 'Put it', addPrompt: 'Add', delPrompt: 'Remove', notValid: 'not validated',
		stepElse: 'Other photographs', noteTitle: 'Note', noteHelp: '', notePh: '',
		baseMain: 'Use the main image', baseMainTip: '', varTitle: 'Variations',
		varIntro: '', varOpen: 'Open', priceOpt: 'Recalculate', costLabel: 'Cost',
		pricePreview: 'Preview', pvEdit: 'Edit', blocked: 'Blocked', error: 'error' }
};

const browser = await chromium.launch();
for ( const [ label, jq ] of jqs ) {
	const page = await browser.newPage();
	const errors = [];
	const posts  = [];
	page.on( 'pageerror', e => errors.push( String( e ) ) );
	page.on( 'console', m => { if ( 'error' === m.type() ) { errors.push( m.text() ); } } );
	page.on( 'dialog', d => d.dismiss() );

	await page.route( 'http://dze.test/**', route => {
		const url = route.request().url();
		if ( url.endsWith( '/' ) ) {
			return route.fulfill( { status: 200, contentType: 'text/html',
				body: `<!doctype html><html><head><meta charset="utf-8">`
					+ `<script src="/jquery.js"></script>`
					+ `<script>window.ajaxurl='http://dze.test/ajax';window.dzeContent=${JSON.stringify( cfg )};`
					+ `window.dzePhotosCfg={ajaxUrl:'http://dze.test/ajax',nonce:'n',ratios:[],i18n:{}};</script>`
					+ `<script src="/paste-box.js"></script><script src="/photos.js"></script>`
					+ `<script src="/content.js"></script>`
					+ `</head><body>${html}</body></html>` } );
		}
		if ( url.endsWith( '/jquery.js' ) ) {
			return route.fulfill( { status: 200, contentType: 'text/javascript', body: readFileSync( jq, 'utf8' ) } );
		}
		for ( const one of [ 'paste-box.js', 'photos.js', 'content.js' ] ) {
			if ( url.endsWith( '/' + one ) ) {
				return route.fulfill( { status: 200, contentType: 'text/javascript',
					body: readFileSync( join( js, one ), 'utf8' ) } );
			}
		}
		const sent = Object.fromEntries( new URLSearchParams( route.request().postData() || '' ) );
		posts.push( sent );
		// What the popup asks for when it opens on a product: what that
		// product already carries. It writes nothing and costs nothing.
		return route.fulfill( { status: 200, contentType: 'application/json',
			body: JSON.stringify( { success: true, data: {
				title: 'Product 901', cost: '', spend: {}, note: '',
				images: [], texts: {}, pending: { texts: {}, shots: [] }
			} } ) } );
	} );
	await page.goto( 'http://dze.test/' );

	console.log( `A line of the problem list, in a browser — jQuery ${label}` );
	ok( 'the screen runs without an error', errors, [] );
	ok( 'every row carries its button',     await page.locator( '.dze-content-open' ).count(), 2 );
	// The button NAMES what pressing it does, and the ellipsis says it will
	// ask before doing it.
	ok( 'and it says what it will open',
		( await page.textContent( '.dze-content-open[data-id="901"]' ) ).trim(), 'Make photographs…' );

	// A CONTROL ON A ROW ACTS ON THAT ROW. Every link this screen has carried
	// to "the tool" went to a Dazont settings tab instead, twice.
	ok( 'and nothing links to a settings page',
		await page.evaluate( () => Array.from( document.querySelectorAll( 'tbody a[href]' ) )
			.some( a => /page=dazont-ecom-ai|tab=(categories|automation|lab)/.test( a.href ) ) ), false );

	await page.click( '.dze-content-open[data-id="901"]' );
	await page.waitForTimeout( 250 );

	// 1. NOTHING WAS GENERATED. The press opens a popup; it does not spend a
	//    penny, and it does not queue anything anywhere.
	ok( 'the press generates nothing',
		posts.map( p => p.action ).filter( a => 'dze_content_current' !== a ), [] );
	// 2. AND NOTHING NAVIGATED. The old button answered by replacing the row
	//    with a link to a bulk list nobody had asked to go to.
	ok( 'and the shop stays on its list',   page.url(), 'http://dze.test/' );
	// 3. The popup is open — the same one the product screen opens.
	ok( 'the product popup is open',        await page.locator( '#dze-cx-modal.is-open' ).count(), 1 );
	ok( 'on that product',                  await page.textContent( '#dze-cx-who' ), 'Product 901' );
	// 4. On the section the criterion is about, and only that one.
	ok( 'opened on the photographs',
		await page.evaluate( () => Array.from( document.querySelectorAll( '#dze-cx-modal .dze-sec' ) )
			.filter( s => s.classList.contains( 'is-open' ) ).map( s => s.getAttribute( 'data-sec' ) ) ), [ 'img' ] );
	ok( 'with the photographs ticked',      await page.isChecked( '#dze-cx-doimg' ), true );
	// 5. THE WORK LAID OUT, not run: one prompt row per photograph the product
	//    is short of, taken from the shop's own gallery prompts in turn.
	ok( 'a prompt row per missing photograph', await page.locator( '#dze-cx-tplrows .dze-tplrow' ).count(), 3 );
	ok( 'on the gallery prompts, in turn',
		await page.evaluate( () => Array.from( document.querySelectorAll( '#dze-cx-tplrows .dze-cx-tpl' ) ).map( s => s.value ) ),
		[ '1', '2', '1' ] );
	ok( 'each aimed at the gallery',
		await page.evaluate( () => Array.from( document.querySelectorAll( '#dze-cx-tplrows .dze-tpl-target' ) ).map( s => s.value ) ),
		[ 'gallery', 'gallery', 'gallery' ] );
	// 6. And the popup SAYS why it opened like that.
	ok( 'and it says how short the product is',
		/3 photographs short/.test( await page.textContent( '#dze-cx-why' ) ), true );

	// The row next door is a different product with a different shortfall, and
	// the popup is re-armed for it rather than keeping the last one's rows.
	await page.click( '.dze-cx-close' );
	await page.click( '.dze-content-open[data-id="902"]' );
	await page.waitForTimeout( 250 );
	ok( 'the next row lays out its own',    await page.locator( '#dze-cx-tplrows .dze-tplrow' ).count(), 1 );
	ok( 'and says one photograph short',
		/one photograph short/.test( await page.textContent( '#dze-cx-why' ) ), true );
	await page.click( '.dze-cx-close' );

	// A PAGE OF ROWS, handed to the bulk screen the shop already generates
	// from — the mechanism the owner asked for by name.
	ok( 'the list can be ticked',           await page.locator( '.dze-diag-one' ).count(), 2 );
	await page.click( '#dze-diag-all' );
	ok( 'the header tick takes them all',
		await page.evaluate( () => Array.from( document.querySelectorAll( '.dze-diag-one' ) ).every( c => c.checked ) ), true );
	ok( 'and the form posts to WordPress',
		await page.getAttribute( '#dze-diag-bulk', 'action' ), 'http://example.test/wp-admin/admin-post.php' );
	ok( 'naming the handler',
		await page.getAttribute( '#dze-diag-bulk input[name="action"]', 'value' ), 'dze_diag_bulk' );
	ok( 'and the criterion it came from',
		await page.getAttribute( '#dze-diag-bulk input[name="check"]', 'value' ), 'prod_gallery' );
	ok( 'nothing was raised on the way',    errors, [] );

	await page.close();
}
await browser.close();
console.log( `\n${ran} checks, ${fails} wrong` );
process.exit( fails ? 1 : 0 );
