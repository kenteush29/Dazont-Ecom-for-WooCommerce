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
const css = readFileSync( join( root, 'dazont-ecom', 'admin', 'css', 'content.css' ), 'utf8' );
// A stand-in for what DZE_Prompts::render_modal() puts in the footer. What is
// under test here is that the screen ASKS for it and that the button reaches
// it — the modal's own contents are the Klaviyo gate's business.
const promptModal = '<div class="dze-cx-modal" id="dze-prompt-modal"><div class="dze-cx-dialog">'
	+ '<button type="button" class="button dze-hub-close">Close</button></div></div>';
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
	diagTodo: 1,
	diagNonce: 'n0nce',
	i18n: { toolbox: 'Dazont Ecom', close: 'Close', text: 'Text', image: 'Photographs',
		allTip: 'Tick every prompt in this block',
		todoTitle: 'To do on this product', todoNone: 'Nothing is missing on this product.',
		todoOpen: 'Lay this one out',
		price: 'Price', launch: 'Generate', discard: 'Discard', applyOne: 'Apply',
		genImgOpt: 'Make photographs', template: 'Prompt', scene: 'Scene', attempts: 'How many',
		putIt: 'Put it', addPrompt: 'Add', delPrompt: 'Remove', notValid: 'not validated',
		stepElse: 'Other photographs', noteTitle: 'Note', noteHelp: '', notePh: '',
		subjLabel: 'Subject', subjMainOpt: 'Main photograph', subjOne: 'Photograph',
		subjPasteOpt: 'The photograph you added', subjPasteOptN: 'The photographs you added',
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
					// THE PLUGIN'S OWN STYLESHEET. A CSS bug is invisible to
					// every PHP test and to `node --check` alike: the prompt
					// button reads "✎ prompt" and sat in a 30px column, so the
					// word ran underneath the menu beside it.
					+ `<style>${css}</style>`
					+ `<script src="/jquery.js"></script>`
					+ `<script>window.ajaxurl='http://dze.test/ajax';window.dzeContent=${JSON.stringify( cfg )};`
					+ `window.dzePhotosCfg={ajaxUrl:'http://dze.test/ajax',nonce:'n',ratios:[],i18n:{}};</script>`
					+ `<script src="/paste-box.js"></script><script src="/photos.js"></script>`
					+ `<script src="/content.js"></script>`
					// The prompt popup, as PHP prints it into the footer of any
					// screen the toolbox opens on. It was NOT printed on the
					// diagnostic, so "✎ prompt" was a button with nothing
					// behind it.
					+ `<script>window.dzePromptModal = ${JSON.stringify( promptModal )};`
					+ `jQuery(function($){ $('body').append(window.dzePromptModal);`
					+ `$(document).on('click','.dze-prompt-peek',function(){ $('#dze-prompt-modal').addClass('is-open'); });`
					+ `$(document).on('click','.dze-hub-close',function(){ $(this).closest('.dze-cx-modal').removeClass('is-open'); }); });</script>`
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
		const json = d => route.fulfill( { status: 200, contentType: 'application/json',
			body: JSON.stringify( { success: true, data: d } ) } );
		// One photograph made, so the Apply button has something to apply.
		if ( 'dze_content_image' === sent.action ) {
			// A data: URI, so the browser never goes to the network for it —
			// a picture fetched over the wire is a test that fails on a bad day.
			return json( { url: 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==',
				target: 'gallery', spend: {} } );
		}
		// WHAT THIS PRODUCT IS SHORT OF, asked by the popup wherever it was
		// opened from. It used to arrive only with the press from a diagnostic
		// line, so the same product opened from its own page showed nothing.
		if ( 'dze_diag_todo' === sent.action ) {
			return json( { rows: '901' === String( sent.post ) ? [
				{ check: 'prod_gallery', said: 'Gallery photographs — 0 of 3',
					want: { section: 'img', field: '', check: 'prod_gallery',
						shots: [ { tpl: 1, n: 1, target: 'gallery' }, { tpl: 2, n: 1, target: 'gallery' }, { tpl: 1, n: 1, target: 'gallery' } ],
						why: 'Gallery photographs — 0 of 3' } },
				{ check: 'prod_desc', said: 'Description — 84 of 120 words',
					want: { section: 'text', field: 'f_post_content', check: 'prod_desc', shots: [], why: 'Description — 84 of 120 words' } }
			] : [
				{ check: 'prod_gallery', said: 'Gallery photographs — 2 of 3',
					want: { section: 'img', field: '', check: 'prod_gallery',
						shots: [ { tpl: 1, n: 1, target: 'gallery' } ], why: 'Gallery photographs — 2 of 3' } }
			] } );
		}
		// The row, judged again after the work landed on the product. Two
		// answers, one per product: 901 is mended, 902 is mended by half.
		if ( 'dze_diag_judge' === sent.action ) {
			return json( '901' === String( sent.id )
				? { fixed: true, said: '', want: {} }
				: { fixed: false, said: '2 of 3 photographs',
					want: { section: 'img', field: '', shots: [ { tpl: 1, n: 1, target: 'gallery' } ],
						why: 'Gallery photographs — 2 of 3 photographs. One prompt is laid out below; change it, add another, then generate.' } } );
		}
		// What the popup asks for when it opens on a product: what that
		// product already carries. It writes nothing and costs nothing.
		return json( {
			// The product's own photographs, which are what the subject picker
			// offers: a main one and two more.
			images: [
				{ id: 71, main: true,  thumb: 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==', w: 1000, h: 1000 },
				{ id: 72, main: false, thumb: 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==', w: 1000, h: 1000 },
				{ id: 73, main: false, thumb: 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==', w: 1000, h: 1000 }
			],
			title: 'Product ' + ( sent.post || '?' ),
			// The way to the product itself, which the head links to.
			edit: 'http://dze.test/wp-admin/post.php?post=' + ( sent.post || '0' ) + '&action=edit',
			cost: '', spend: {}, note: '',
			texts: {}, pending: { texts: {}, shots: [] }
		} );
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

	// THE FILTER, on the page a browser actually builds. Two things only a
	// browser can answer: that the menu is really there with its figures, and
	// that its form has not SWALLOWED the bulk form printed after it — nested
	// forms are dropped by the parser, and the bulk selection would post
	// nothing while every PHP string check went on passing.
	ok( 'the list can be filtered by category',
		await page.evaluate( () => Array.from( document.querySelectorAll( '#dze-diag-cat option' ) ).map( o => o.textContent.trim() ) ),
		[ 'All categories (2)', 'Backpacks (1)', 'Tactical gear (2)' ] );
	ok( 'and the bulk form is still its own',
		await page.evaluate( () => {
			const f = document.querySelector( 'form.dze-diag-filter' );
			const b = document.querySelector( '#dze-diag-bulk' );
			return !! f && !! b && ! f.contains( b );
		} ), true );
	// A GET FORM IS TESTED ON WHERE IT GOES. Pressing Filter must come back to
	// this same criterion and this same tab, never to another list.
	ok( 'filtering comes back to this criterion',
		await page.evaluate( () => Object.fromEntries(
			Array.from( document.querySelectorAll( 'form.dze-diag-filter input[type="hidden"]' ) ).map( i => [ i.name, i.value ] ) ) ),
		{ page: 'dazont-ecom-diagnostic', check: 'prod_gallery', show: 'todo', by: 'found', dir: 'desc' } );

	// WHAT EACH ROW IS SHORT OF, said on the row itself. A list that only
	// names the products leaves the reader to open each one to learn how far
	// off it is — and how far off it is decides which to do first.
	ok( 'every row says how short it is',
		await page.locator( 'tbody .dze-diag-short' ).count(), 2 );
	ok( 'and says it in figures',
		( await page.textContent( 'tr[data-id="901"] .dze-diag-short' ) ).includes( '0 of 3 photographs' ), true );
	ok( 'each one its own',
		( await page.textContent( 'tr[data-id="902"] .dze-diag-short' ) ).includes( '2 of 3 photographs' ), true );

	await page.click( '.dze-content-open[data-id="901"]' );
	await page.waitForTimeout( 250 );

	// 1. NOTHING WAS GENERATED. The press opens a popup; it does not spend a
	//    penny, and it does not queue anything anywhere.
	// Two reads are not a generation: who the product is, and what it is short
	// of. Anything else on this press would be work nobody asked for.
	ok( 'the press generates nothing',
		posts.map( p => p.action ).filter( a => 'dze_content_current' !== a && 'dze_diag_todo' !== a ), [] );
	// 2. AND NOTHING NAVIGATED. The old button answered by replacing the row
	//    with a link to a bulk list nobody had asked to go to.
	ok( 'and the shop stays on its list',   page.url(), 'http://dze.test/' );
	// 3. The popup is open — the same one the product screen opens.
	ok( 'the product popup is open',        await page.locator( '#dze-cx-modal.is-open' ).count(), 1 );
	ok( 'on that product',                  await page.textContent( '#dze-cx-who' ), 'Product 901' );
	// AND A WAY TO THE PRODUCT ITSELF. Opened from a diagnostic line the
	// product is nowhere on screen, and some of the work belongs there:
	// "j'aimerais ajouter des images externes pour améliorer le contexte."
	ok( 'the head offers the product',      await page.isVisible( '#dze-cx-edit' ), true );
	ok( 'pointing at that very product',
		await page.getAttribute( '#dze-cx-edit', 'href' ),
		'http://dze.test/wp-admin/post.php?post=901&action=edit' );
	ok( 'in a new tab, so nothing here is lost',
		await page.getAttribute( '#dze-cx-edit', 'target' ), '_blank' );
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
	// 5b. AND EVERY COLUMN OF THAT ROW STAYS IN ITS COLUMN. "✎ Prompt >
	//     Problème d'affichage, texte mal placé": the button carries a word,
	//     the column was sized for a lone pencil, and the word ran under the
	//     menu beside it. Measured, because nothing else can see it.
	const boxes = await page.evaluate( () => {
		const row = document.querySelector( '#dze-cx-tplrows .dze-tplrow' );
		const at = sel => { const el = row.querySelector( sel ); const r = el.getBoundingClientRect(); return { l: r.left, r: r.right, w: r.width }; };
		return { peek: at( '.dze-prompt-peek' ), next: at( '.dze-tpl-n' ), tpl: at( '.dze-cx-tpl' ) };
	} );
	ok( 'the prompt button fits its own word',   boxes.peek.w >= 40, true );
	ok( 'and never runs under its neighbour',    boxes.peek.r <= boxes.next.l + 1, true );
	ok( 'nor back over the prompt menu',         boxes.peek.l >= boxes.tpl.r - 1, true );

	// 5c. EXACTLY WHAT WAS ASKED FOR, AND NOTHING ELSE. The ticks the popup
	//     remembers are the ones from the last run on the product screen, so
	//     "Make photographs…" opened with every text prompt ticked too —
	//     "très inconfortable", and one press away from rewriting a
	//     description nobody asked to touch.
	ok( 'no text prompt is ticked behind it',
		await page.locator( '.dze-cx-f:checked' ).count(), 0 );
	ok( 'nor the price',                     await page.isChecked( '#dze-cx-doprice' ), false );
	// AND THE SUBJECT IS THE PRODUCT'S OWN MAIN PHOTOGRAPH. A popup armed for
	// a criterion carries no choice made in an earlier run on another product.
	ok( 'and the subject is back to the main photograph',
		await page.inputValue( '#dze-cx-subject' ), '0' );
	// WHICH PHOTOGRAPH, NOT WHETHER. The checkbox that stood here — "keep the
	// product's own photograph as the subject" — answered a question nobody
	// had asked and left the real one with no answer at all.
	ok( 'there is no checkbox answering it instead',
		await page.locator( '#dze-cx-basemain' ).count(), 0 );
	// 5d. WHAT THE BLOCK WILL DO, in its own heading: three photographs,
	//     because three rows are laid out. It used to count the checkboxes in
	//     the body and say "1 / 1" however many were laid out under it.
	ok( 'the section counts the photographs it will make',
		( await page.textContent( '#dze-cx-modal .dze-sec[data-sec="img"] .dze-sec-count' ) ).trim(), '3 / 3' );
	// And choosing a subject is an OPTION of the run, never one of the things
	// the run does: it made the images section read "1 / 2" when there was one
	// photograph to make.
	await page.selectOption( '#dze-cx-subject', { index: 0 } );
	await page.waitForTimeout( 150 );
	ok( 'and an option does not add to it',
		( await page.textContent( '#dze-cx-modal .dze-sec[data-sec="img"] .dze-sec-count' ) ).trim(), '3 / 3' );
	// 5e. THE PROMPT BUTTON OPENS THE PROMPT. It was drawn on this screen and
	//     the popup it opens was not on the page at all, so pressing it did
	//     nothing and said nothing.
	ok( 'each prompt row offers its prompt',
		await page.locator( '#dze-cx-tplrows .dze-prompt-peek' ).count(), 3 );
	await page.click( '#dze-cx-tplrows .dze-prompt-peek' );
	await page.waitForTimeout( 150 );
	ok( 'and pressing it opens one',         await page.locator( '#dze-prompt-modal.is-open' ).count(), 1 );
	await page.click( '#dze-prompt-modal .dze-hub-close' );

	// 6. AND THE POPUP CARRIES THE PRODUCT'S WHOLE TO-DO LIST, not only the
	//    line that opened it: the reading belongs to the product, and this
	//    popup opens from three screens.
	// A list that never arrives is a popup that says nothing, so the wait is
	// bounded and reported rather than left to kill the run with a timeout.
	ok( 'the popup asks what this product is short of',
		await page.waitForSelector( '#dze-cx-todo .dze-cx-todoline', { timeout: 4000 } ).then( () => true ).catch( () => false ), true );
	ok( 'every shortfall of the product is named',
		await page.locator( '#dze-cx-todo .dze-cx-todoline' ).count(), 2 );
	ok( 'each in one line, with the figures',
		( await page.textContent( '#dze-cx-todo' ) ).includes( 'Gallery photographs — 0 of 3' ), true );
	ok( 'and the unit is not said twice',
		( await page.textContent( '#dze-cx-todo' ) ).includes( '0 of 3 photographs' ), false );
	// A PARAGRAPH IS NOT A TO-DO LIST. It read "Gallery photographs — 3 of 5
	// photographs. 2 prompts are laid out below; change them, add another,
	// then generate." — two sentences explaining the screen in front of you.
	ok( 'nothing explains the screen to itself',
		( await page.textContent( '#dze-cx-todo' ) ).includes( 'laid out below' ), false );
	// The line the popup opened FOR is marked, so an armed popup says which
	// of the shortfalls it came up for.
	ok( 'the line it opened on is marked',
		await page.locator( '#dze-cx-todo .dze-cx-todoline.is-armed' ).count(), 1 );
	ok( 'and it is the right one',
		await page.getAttribute( '#dze-cx-todo .is-armed', 'data-check' ), 'prod_gallery' );

	// 6b. A LINE LAYS ITSELF OUT, AND RUNS NOTHING. Pressing the other line
	//     re-arms the popup for that one — the same gesture as the diagnostic
	//     row's own button, with the same arming behind it.
	const sentBefore = posts.length;
	await page.click( '#dze-cx-todo .dze-cx-todoline[data-check="prod_desc"] .dze-cx-todogo' );
	await page.waitForTimeout( 150 );
	ok( 'pressing a line generates nothing', posts.length, sentBefore );
	ok( 'it opens the block that line is about',
		await page.evaluate( () => Array.from( document.querySelectorAll( '#dze-cx-modal .dze-sec' ) )
			.filter( s => s.classList.contains( 'is-open' ) ).map( s => s.getAttribute( 'data-sec' ) ) ), [ 'text' ] );
	ok( 'and ticks its prompt, and only its prompt',
		await page.evaluate( () => Array.from( document.querySelectorAll( '.dze-cx-f:checked' ) ).map( c => c.value ) ),
		[ 'f_post_content' ] );
	ok( 'the photographs are let go of',     await page.isChecked( '#dze-cx-doimg' ), false );
	ok( 'and the marked line moves with it',
		await page.getAttribute( '#dze-cx-todo .is-armed', 'data-check' ), 'prod_desc' );

	// 6c. ONE TICK PER BLOCK, IN THE BLOCK'S OWN TITLE. Images and price each
	//     carried a second checkbox inside the body saying what the block it
	//     sat in already said.
	ok( 'the photographs block has one switch, in its heading',
		await page.locator( '#dze-cx-modal .dze-sec[data-sec="img"] .dze-sec-head #dze-cx-doimg' ).count(), 1 );
	ok( 'and none inside it',
		await page.locator( '#dze-cx-modal .dze-sec[data-sec="img"] .dze-sec-body #dze-cx-doimg' ).count(), 0 );
	ok( 'the price block the same',
		await page.locator( '#dze-cx-modal .dze-sec[data-sec="price"] .dze-sec-head #dze-cx-doprice' ).count(), 1 );
	ok( 'and none inside that either',
		await page.locator( '#dze-cx-modal .dze-sec[data-sec="price"] .dze-sec-body #dze-cx-doprice' ).count(), 0 );
	// THE TICK IS A SWITCH, NOT A WAY OF OPENING THE BLOCK. Pressing it must
	// not fold the section shut under the hand that pressed it.
	const priceOpen = await page.evaluate( () => document.querySelector( '#dze-cx-modal .dze-sec[data-sec="price"]' ).classList.contains( 'is-open' ) );
	await page.check( '#dze-cx-doprice' );
	ok( 'ticking a block does not fold it',
		await page.evaluate( () => document.querySelector( '#dze-cx-modal .dze-sec[data-sec="price"]' ).classList.contains( 'is-open' ) ), priceOpen );
	ok( 'and the block is on',               await page.isChecked( '#dze-cx-doprice' ), true );
	await page.uncheck( '#dze-cx-doprice' );

	// 6d. AND THE TEXT BLOCK'S TICK MEANS "ALL OF THEM" — a list of prompts
	//     needs one gesture to take the lot.
	await page.check( '#dze-cx-modal .dze-sec[data-sec="text"] .dze-sec-all' );
	await page.waitForTimeout( 100 );
	ok( 'ticking the block ticks every prompt in it',
		await page.locator( '.dze-cx-f:checked' ).count(),
		await page.locator( '.dze-cx-f' ).count() );
	await page.uncheck( '#dze-cx-modal .dze-sec[data-sec="text"] .dze-sec-all' );
	await page.waitForTimeout( 100 );
	ok( 'and unticking it lets them all go', await page.locator( '.dze-cx-f:checked' ).count(), 0 );
	// A BLOCK HALF TICKED SAYS SO rather than showing a bare tick that is not
	// true of what is under it.
	await page.check( '.dze-cx-f[value="f_post_content"]' );
	await page.waitForTimeout( 100 );
	ok( 'a block half ticked is neither on nor off',
		await page.evaluate( () => document.querySelector( '#dze-cx-modal .dze-sec[data-sec="text"] .dze-sec-all' ).indeterminate ), true );

	// The row next door is a different product with a different shortfall, and
	// the popup is re-armed for it rather than keeping the last one's rows.
	await page.click( '.dze-cx-close' );
	await page.click( '.dze-content-open[data-id="902"]' );
	await page.waitForTimeout( 250 );
	ok( 'the next row lays out its own',    await page.locator( '#dze-cx-tplrows .dze-tplrow' ).count(), 1 );

	ok( 'and says where that one stands',
		( await page.textContent( '#dze-cx-todo' ) ).includes( 'Gallery photographs — 2 of 3' ), true );
	// THE LIST BELONGS TO THE PRODUCT IN FRONT OF YOU. It is read again on
	// every product the popup lands on, or it describes the last one.
	ok( 'and only that product\'s shortfalls',
		await page.locator( '#dze-cx-todo .dze-cx-todoline' ).count(), 1 );
	await page.click( '.dze-cx-close' );

	// ---- THE WORK GOES THROUGH, AND THE LIST ANSWERS FOR ITSELF ----
	//
	// "J'ai cliqué sur Make photographs… > généré images + appliqué. La page
	// s'est rechargée. Ça ne doit pas arriver. Une fois qu'on clique
	// appliquer, il faudrait dynamiquement fermer le popup après application,
	// et passer le post problématique dans la liste fixed."
	//
	// The whole gesture, in the browser, on the real path: open, generate,
	// apply — then the page must NOT reload, the popup must close itself, and
	// the row must leave the list with both counts following it.
	const before = { todo: await page.textContent( '.dze-diag-tab[data-tab="todo"] .dze-diag-n' ),
		fixed: await page.textContent( '.dze-diag-tab[data-tab="fixed"] .dze-diag-n' ) };
	ok( 'the list says what is left to do',  before.todo, '2' );
	let reloaded = false;
	page.on( 'framenavigated', f => { if ( f === page.mainFrame() ) { reloaded = true; } } );

	await page.click( '.dze-content-open[data-id="901"]' );
	await page.waitForTimeout( 200 );
	// WHICH PHOTOGRAPH THE RUN WORKS FROM. The picker offers the product's
	// own, main first, and what it is set to has to reach the wire — a control
	// whose value never leaves the page is a control that does nothing.
	ok( 'the picker offers every photograph of the product',
		await page.locator( '#dze-cx-subject option' ).count(), 3 );
	await page.selectOption( '#dze-cx-subject', '73' );
	const madeBefore = posts.filter( p => 'dze_content_image' === p.action ).length;
	await page.click( '#dze-cx-run' );
	await page.waitForSelector( '#dze-cx-shots .dze-cb-shot.is-sel', { timeout: 5000 } );
	const made = posts.filter( p => 'dze_content_image' === p.action ).slice( madeBefore );
	ok( 'the run went out',                  made.length > 0, true );
	ok( 'and every photograph is made from the one that was picked',
		Array.from( new Set( made.map( p => p.src_id ) ) ), [ '73' ] );
	ok( 'the photographs come back to be looked at',
		await page.locator( '#dze-cx-shots .dze-cb-shot' ).count() > 0, true );
	await page.click( '.dze-cx-applyone' );
	await page.waitForFunction( () => ! document.querySelector( '#dze-cx-modal.is-open' ), null, { timeout: 5000 } );

	// The run wrote a progress line — there has to be something to clear, or
	// the check below would pass on an empty screen and prove nothing.
	ok( 'the run said where it had got to',
		( await page.evaluate( () => ( document.getElementById( 'dze-cx-progcount' ).textContent || '' ).trim() ) ).length > 0, true );
	ok( 'the page is NEVER reloaded',        reloaded, false );
	ok( 'and the popup shuts itself',        await page.locator( '#dze-cx-modal.is-open' ).count(), 0 );
	// THE ROW IS JUDGED AGAIN — by the criterion it was listed under, not by
	// hope. The request carries the criterion and the row's own id.
	const judged = posts.filter( p => 'dze_diag_judge' === p.action );
	ok( 'the row is judged again',           judged.length, 1 );
	ok( 'against its own criterion',         judged[0].check, 'prod_gallery' );
	ok( 'and its own id',                    judged[0].id, '901' );
	ok( 'with a nonce',                      ( judged[0].nonce || '' ).length > 0, true );
	// MENDED: it leaves the list, and both figures follow it.
	await page.waitForFunction( () => ! document.querySelector( 'tr[data-id="901"]' ), null, { timeout: 3000 } );
	ok( 'the mended row leaves the list',    await page.locator( 'tr[data-id="901"]' ).count(), 0 );
	ok( 'the work left goes down',           await page.textContent( '.dze-diag-tab[data-tab="todo"] .dze-diag-n' ), '1' );
	ok( 'and what is done goes up',
		await page.textContent( '.dze-diag-tab[data-tab="fixed"] .dze-diag-n' ),
		String( ( parseInt( before.fixed, 10 ) || 0 ) + 1 ) );
	ok( 'the row next door is left alone',   await page.locator( 'tr[data-id="902"]' ).count(), 1 );

	// MENDED BY HALF is not mended: the row stays, says where it now stands,
	// and its button is re-armed for what is still missing. A row that
	// vanished on any apply would be a list that lies.
	await page.click( '.dze-content-open[data-id="902"]' );
	await page.waitForTimeout( 200 );
	await page.click( '#dze-cx-run' );
	await page.waitForSelector( '#dze-cx-shots .dze-cb-shot.is-sel', { timeout: 5000 } );
	await page.click( '.dze-cx-applyone' );
	await page.waitForFunction( () => ! document.querySelector( '#dze-cx-modal.is-open' ), null, { timeout: 5000 } );
	await page.waitForFunction( () => !! document.querySelector( 'tr[data-id="902"] .dze-diag-short' ), null, { timeout: 3000 } );
	ok( 'a half-mended row stays',           await page.locator( 'tr[data-id="902"]' ).count(), 1 );
	ok( 'and says where it now stands',
		( await page.textContent( 'tr[data-id="902"] .dze-diag-short' ) ).includes( '2 of 3 photographs' ), true );
	ok( 'the count did not move for it',     await page.textContent( '.dze-diag-tab[data-tab="todo"] .dze-diag-n' ), '1' );
	// And the button opens on what is LEFT, not on what it was short of before.
	ok( 'its button is re-armed',
		await page.evaluate( () => JSON.parse( document.querySelector( 'tr[data-id="902"] .dze-content-open' ).getAttribute( 'data-want' ) ).shots.length ), 1 );
	ok( 'still nothing was raised',          errors, [] );

	// ---- THE LAST PRODUCT'S RUN IS NOT THIS ONE'S ----
	// "Step 2 of 2 · 1s — quand je clique sur un autre produit après avoir
	// déjà édité un autre, ce texte reste là." His sequence exactly: run on
	// one product, close WITHOUT applying, open another. A progress line
	// belongs to the run that wrote it; left on the screen it describes work
	// done to a different product.
	await page.click( '.dze-content-open[data-id="902"]' );
	await page.waitForTimeout( 200 );
	await page.click( '#dze-cx-run' );
	await page.waitForSelector( '#dze-cx-shots .dze-cb-shot.is-sel', { timeout: 5000 } );
	const ran902 = await page.evaluate( () => ( document.getElementById( 'dze-cx-progcount' ).textContent || '' ).trim() );
	ok( 'a run says where it got to',        ran902.length > 0, true );
	await page.click( '.dze-cx-close' );
	// Another product, opened from its own row — nothing applied, nothing
	// reloaded.
	await page.evaluate( () => {
		const row = document.querySelector( 'tr[data-id="902"]' ).cloneNode( true );
		row.setAttribute( 'data-id', '903' );
		row.querySelector( '.dze-content-open' ).setAttribute( 'data-id', '903' );
		document.querySelector( '#dze-diag-bulk tbody' ).appendChild( row );
	} );
	await page.click( '.dze-content-open[data-id="903"]' );
	await page.waitForTimeout( 300 );
	ok( 'the last run leaves nothing behind',
		await page.evaluate( () => [ 'dze-cx-progcount', 'dze-cx-progstep', 'dze-cx-progtime' ]
			.map( id => ( document.getElementById( id ).textContent || '' ).trim() ).join( '' ) ), '' );
	ok( 'and its bar is out of sight',       await page.isVisible( '#dze-cx-prog' ), false );
	await page.click( '.dze-cx-close' );
	await page.evaluate( () => document.querySelector( 'tr[data-id="903"]' ).remove() );

	// ---- WHAT WAS ADDED FROM OUTSIDE IS NOT SILENTLY THE SUBJECT ----
	//
	// "Images generees dans une autre couleur que le produit principal. Il me
	// donne du kryptek noir plutot que du desert. Avant ca fonctionnait. J'ai
	// ajoute des images externes en copier coller en kryptek noir pour un
	// meilleur contexte."
	//
	// The picker reads "Main photograph" on its default and used to send
	// NOTHING on it — and a request carrying pasted photographs and no answer
	// is read by the server as "the pasted one leads". The screen said the
	// product and the run used the supplier's shot, colours included. Nothing
	// but a browser can see this: the value is read off the page at the moment
	// the request is built.
	await page.click( '.dze-content-open[data-id="902"]' );
	await page.waitForTimeout( 200 );
	await page.setInputFiles( '#dze-cx-else input.dze-pb-file', {
		name: 'supplier.png', mimeType: 'image/png',
		buffer: Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGNiAAAABgADNjd8qAAAAABJRU5ErkJggg==', 'base64' )
	} );
	// THE PICKER OFFERS IT. Until it did, the only way to say "that one is the
	// subject" was not on the screen at all.
	// Waited for with a bound and REPORTED: a gate that dies on the bug it is
	// about says nothing about the checks after it.
	const offered = await page.waitForSelector( '#dze-cx-subject option[value="paste"]',
		{ state: 'attached', timeout: 3000 } ).then( () => true ).catch( () => false );
	ok( 'a photograph added from outside joins the picker', offered, true );
	ok( 'and it says what it is',
		offered ? ( await page.textContent( '#dze-cx-subject option[value="paste"]' ) ).trim() : '',
		'The photograph you added' );
	// AND THE DEFAULT STILL SAYS THE PRODUCT.
	ok( 'the picker is left on the main photograph',
		await page.inputValue( '#dze-cx-subject' ), '0' );
	ok( 'which says so in words',
		( await page.textContent( '#dze-cx-subject option[value="0"]' ) ).trim(), 'Main photograph' );
	let seen = posts.filter( p => 'dze_content_image' === p.action ).length;
	await page.click( '#dze-cx-run' );
	await page.waitForSelector( '#dze-cx-shots .dze-cb-shot.is-sel', { timeout: 5000 } );
	let asked = posts.filter( p => 'dze_content_image' === p.action ).slice( seen );
	ok( 'the run went out',                  asked.length, 1 );
	ok( 'carrying the photograph that was added',
		( asked[0].pastes || asked[0]['pastes[]'] || '' ).slice( 0, 10 ), 'data:image' );
	// The whole of the fix, on the wire: the screen said the product, so the
	// request says the product.
	ok( 'and saying the PRODUCT is the subject', asked[0].base_main, '1' );
	ok( 'with no photograph of its own picked',  asked[0].src_id, undefined );

	// THE OTHER ANSWER IS ON THE SAME PICKER, and it means what pasting used
	// to mean on its own.
	if ( offered ) {
		await page.selectOption( '#dze-cx-subject', 'paste' );
		seen = posts.filter( p => 'dze_content_image' === p.action ).length;
		await page.click( '#dze-cx-run' );
		await page.waitForTimeout( 600 );
		asked = posts.filter( p => 'dze_content_image' === p.action ).slice( seen );
	} else {
		asked = [];
	}
	ok( 'choosing what was added sends it as the subject', asked.length, 1 );
	ok( 'and the product stops being it',    asked.length ? asked[0].base_main : 'never asked', undefined );
	await page.click( '.dze-cx-close' );

	// A PAGE OF ROWS, handed to the bulk screen the shop already generates
	// from — the mechanism the owner asked for by name.
	// One row left the list when it was mended, so the selection is what is
	// still on screen — a list that offers to work on a row it has removed is
	// a list that lies.
	ok( 'the list can be ticked',
		await page.locator( '.dze-diag-one' ).count(),
		await page.locator( '#dze-diag-bulk tbody tr' ).count() );
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
