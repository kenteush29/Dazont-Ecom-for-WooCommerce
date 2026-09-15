/**
 * The tour: every screen of the plugin, photographed.
 *
 *   node tools/js/tour.mjs [name ...]
 *
 * Not a gate. Nothing here passes or fails: it draws each page exactly as the
 * plugin prints it (through the harnesses' own dump modes, never a copy typed
 * here), wraps it in enough of wp-admin to read, and writes one PNG per screen
 * into the scratchpad — so somebody can look at the plugin from the owner's
 * chair, screen by screen, and write down what is wrong before touching it.
 *
 * "Tu n'as rien fait vraiment pour l'UX, n'est-ce pas ?" — grep is not a tour.
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import { execFileSync } from 'node:child_process';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const root = join( here, '..', '..' );
const css  = f => readFileSync( join( root, 'dazont-ecom', 'admin', 'css', f ), 'utf8' );
const out  = process.env.DZE_TOUR_DIR || join( process.env.CLAUDE_SCRATCHPAD || '/tmp/claude-0', 'tour' );
mkdirSync( out, { recursive: true } );

const base = readFileSync( join( here, 'tour-admin.css' ), 'utf8' );

/** One dump, run as the gate runs it. */
function php( file, ...args ) {
	return execFileSync( 'php', [ join( root, 'tools', file ), 'dazont-ecom', ...args ],
		{ encoding: 'utf8', cwd: root, stdio: [ 'ignore', 'pipe', 'pipe' ], maxBuffer: 64 * 1024 * 1024 } );
}
const json = ( file, ...args ) => JSON.parse( php( file, ...args ) );
/** A Settings tab body, under the page title and its own tab. */
const tab  = ( label, body ) => `<div class="wrap dze-wrap"><h1>Settings</h1><h2 class="nav-tab-wrapper"><a class="nav-tab nav-tab-active" href="#">${label}</a></h2>${body}</div>`;

/** Every screen, and how its markup is obtained. */
const screens = {
	// ---- Settings, every tab ----
	...Object.fromEntries( [ 'general', 'sourcing', 'discounts', 'events', 'modules' ]
		.map( t => [ 'settings-' + t, { html: () => php( 'test-trace.php', '--dump-settings=' + t ), css: [ 'content.css' ] } ] ) ),
	// The tabs the trace harness only stubs: each body from the gate that
	// loads its real class, under the same page title and tab.
	'settings-content':    { html: () => tab( 'Shop content', php( 'test-sources.php', '--dump-settings' ) ), css: [ 'content.css' ] },
	'settings-translate':  { html: () => tab( 'Translation', json( 'test-translate.php', '--dump-screen=settings' ).html ), css: [ 'content.css' ] },
	'settings-categories': { html: () => tab( 'Categories', php( 'test-category.php', '--dump-settings' ) ), css: [ 'content.css' ] },
	'settings-transfer':   { html: () => tab( 'Transfer', php( 'test-transfer.php', '--dump-tab' ) ), css: [ 'content.css' ] },
	'settings-diagnostic': { html: () => tab( 'Content rules', execFileSync( 'php', [ join( here, 'render-diagnostic.php' ) ], { encoding: 'utf8', cwd: root } ) ), css: [ 'content.css' ] },
	'settings-reviews':    { html: () => php( 'tour-dump.php', 'reviews' ), css: [ 'content.css' ] },
	'settings-lab':        { html: () => php( 'tour-dump.php', 'lab' ), css: [ 'content.css' ] },
	'settings-email':      { html: () => tab( 'Email campaigns', php( 'test-klaviyo.php', '--dump-klaviyo' ) ), css: [ 'content.css' ] },
	// ---- Logs ----
	'logs-calls':          { html: () => php( 'test-health.php', '--dump-logs=calls' ), css: [ 'content.css' ] },
	'logs-spend':          { html: () => php( 'test-health.php', '--dump-logs=spend' ), css: [ 'content.css' ] },
	'logs-connections':    { html: () => php( 'test-health.php', '--dump-logs=health' ), css: [ 'content.css' ] },
	// ---- The plugin's own pages ----
	'setup':               { html: () => php( 'test-setup.php', '--dump-setup' ), css: [ 'content.css' ] },
	'automation':          { html: () => json( 'test-automation.php', '--dump-automation' ).html, css: [ 'content.css' ] },
	'content-review':      { html: () => { const d = json( 'test-review.php', '--dump-review' ); return d.html; }, css: [ 'content.css' ] },
	'content-diagnostic':  { html: () => php( 'test-diagnostic.php', '--dump-list' ), css: [ 'content.css' ] },
	'content-table':       { html: () => { const o = php( 'test-diagnostic.php', '--dump-table' ); return o.slice( o.indexOf( '<!--DZE-TABLE-->' ) ); }, css: [ 'content.css' ] },
	'content-linking':     { html: () => php( 'test-mesh.php', '--dump-tab' ), css: [ 'content.css' ] },
	'content-products':    { html: () => json( 'test-sources.php', '--dump-bulk' ).html, css: [ 'content.css' ] },
	'category-panel':      { html: () => php( 'test-category.php', '--dump-panel' ).split( '<!--MODAL-->' )[ 0 ], css: [ 'content.css' ] },
	// ---- The pages no gate draws: the tour's own harness ----
	'dashboard':           { html: () => php( 'tour-dump.php', 'dashboard' ), css: [ 'content.css', 'admin.css' ] },
	'restock':             { html: () => php( 'tour-dump.php', 'restock' ), css: [ 'admin.css', 'zoom.css' ] },
	'sourcing':            { html: () => php( 'tour-dump.php', 'sourcing' ), css: [ 'explorer.css', 'zoom.css' ] },
	'marketing-events':    { html: () => php( 'tour-dump.php', 'marketing' ), css: [ 'content.css', 'admin.css' ] },
	'marketing-rules':     { html: () => php( 'tour-dump.php', 'rules' ), css: [ 'content.css', 'admin.css' ] },
	'marketing-gmc':       { html: () => php( 'tour-dump.php', 'gmc' ), css: [ 'content.css', 'admin.css' ] },
	'shortcodes':          { html: () => php( 'tour-dump.php', 'shortcodes' ), css: [ 'content.css' ] },
	'modules':             { html: () => php( 'tour-dump.php', 'modules' ), css: [ 'content.css', 'admin.css' ] },
	...Object.fromEntries( [ 'dashboard', 'batch', 'review', 'editor', 'popup' ]
		.map( w => [ 'translations-' + w, { html: () => { const d = json( 'test-translate.php', '--dump-screen=' + w ); return d.html || d.screen || Object.values( d ).find( v => 'string' === typeof v && v.includes( '<' ) ) || ''; }, css: [ 'content.css' ] } ] ) ),
};

const want = process.argv.slice( 2 );
const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
const done = [];
for ( const [ name, one ] of Object.entries( screens ) ) {
	if ( want.length && ! want.includes( name ) ) { continue; }
	let html = '';
	try {
		html = one.html();
	} catch ( e ) {
		console.log( `SKIP ${name}: ${String( e.message ).split( '\n' )[ 0 ].slice( 0, 160 )}` );
		continue;
	}
	const doc = `<!doctype html><html><head><meta charset="utf-8"><style>${base}</style>`
		+ one.css.map( f => `<style>${css( f )}</style>` ).join( '' )
		+ `</head><body>${html}</body></html>`;
	writeFileSync( join( out, name + '.html' ), doc );
	await page.setContent( doc, { waitUntil: 'load' } );
	await page.screenshot( { path: join( out, name + '.png' ), fullPage: true } );
	const h = await page.evaluate( () => document.documentElement.scrollHeight );
	done.push( name );
	console.log( `${name}: ${h}px` );
}
await browser.close();
console.log( `\n${done.length} screens in ${out}` );
