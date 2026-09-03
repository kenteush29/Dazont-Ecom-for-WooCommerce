# Dazont Ecom — project rules

WooCommerce plugin (`dazont-ecom/`) for kula-tactical.com. Admin UI in English,
owner communicates in French.

## Non-negotiable rules

- **Every new function/module MUST be registered in the module catalog**
  (`includes/class-modules.php` → `catalog()`): boot class(es), group, label,
  a short one-line description AND a detailed popup description (`more`) —
  both accurate, written from what the code actually does, no "AI" branding.
  Modules boot ONLY through `DZE_Modules::boot()`; never instantiate a module
  directly in `dazont-ecom.php`.
- **A disabled module must leave ZERO trace in the admin**: its own hooks
  vanish with boot, but every CROSS-module surface (settings tabs, dashboard
  blocks, bridge buttons) must be gated with `DZE_Modules::enabled( $id )` —
  `class_exists()` is NOT a module check (class files always exist).
- Product-page functions surface ONLY through the single "Dazont Ecom" hub
  box (`DZE_Modules::render_hub`) — plus, for a single field, a small button
  planted INSIDE the native WordPress box that field writes into (title,
  description, short description, main image), opening a one-function popup.
  Never a new meta box of our own: one button per enabled module opening a
  popup (footer-printed `.dze-cx-modal` + `.dze-hub-close`) — never a
  separate meta box per module. Thumbnails get hover zoom via
  `img.dze-hzoom` (+ `data-full`).
- One settings menu only: the Settings page (class-marketing-ai.php tabs).
  New settings go into an existing tab or a new tab there — never a separate
  submenu (fallback submenus only to avoid lock-outs).
- API keys are never committed. Constants: `DZE_ANTHROPIC_API_KEY`,
  `DZE_FAL_API_KEY`, `DZE_GMC_SERVICE_ACCOUNT`, `DZE_KLAVIYO_API_KEY`. Each key
  is only ever sent to its own provider.
- Shipped default prompts are precious (the owner's spreadsheet prompts,
  verbatim). Custom edits live in settings; empty/absent = shipped default,
  and every prompt UI offers a "Restore default" path.
- **The shop's main language is ENGLISH**: product data is always stored and
  sent in English, and every generated output must come back in the site's
  main language whatever language the prompt is written in. Resolve it with
  `DZE_Content::site_language()` (WPML default language → WP locale) and
  append it as an automatic constraint — never by rewriting the owner's
  prompts. Shipped default prompts are written in English too, since the
  model mirrors the prompt's language.
- **Server footprint comes before features.** The shop must stay fast for
  visitors AND cheap for the server. Every addition is judged on:
  - **Front-end footprint**: a shop page must run as if the plugin were not
    installed. No front hook, query, option read or asset unless the feature
    is genuinely visible to a customer. Admin work belongs to `admin_*`,
    `wp_ajax_*` and cron hooks.
  - **No blocking HTTP while somebody waits for a page** — least of all a
    loopback call to our own site (one PHP worker waiting on another is how a
    shop starts returning 408/504). Fetching happens in cron or in an explicit
    AJAX action; page rendering reads the cache and says so when it is empty.
    Short timeouts, few redirects, a lock around any scheduled fetch.
  - **Autoloaded options**: settings the front never reads are registered with
    `'autoload' => false` (autoloaded rows are read on EVERY request). Big
    payloads — prompts, keyword sets, cached page lists — never autoload.
  - **Query cost**: no query in a loop (N+1), no `posts_per_page => -1`, and
    list-table columns must stay O(1) per row — heavy work belongs to the
    AJAX panel behind the click, not to the row.
  - **Cache what is expensive** in transients with a sane TTL, keyed so a
    changed input invalidates it, plus a lock against stampedes.
  - **Weight on disk and in memory**: no library we can do without, no asset
    enqueued outside the screen that needs it, classes loaded by the
    autoloader only when used.
  When a feature cannot be built within that budget, say so instead of
  shipping it heavy.
- **No setting is ever lost to a save it had nothing to do with.** A
  sanitizer writes a key ONLY when the submitted form actually carried it
  (`array_key_exists`), never `$in['k'] ?? ''` — an absent field then writes
  an empty value over a real one, and the shop finds out weeks later. Two
  exceptions, both deliberate: a checkbox the submitted section owns (unticked
  it posts nothing), and a key field left blank, which means "keep the saved
  key". WordPress calls a sanitizer with **null** when the page did not carry
  that option at all — that is "another form was saved", so return what is
  stored, never defaults. Options edited by more than one tab or more than one
  form are where this bites: read the other forms before adding a key.
- **Every module declares its database footprint and can be wiped on its
  own.** Any new option, meta key, transient prefix, table or tagged comment
  goes into `DZE_Cleanup::map()` under its module id — a module missing from
  that map is flagged as undeclared in Settings → Modules. Three rules:
  (1) deactivating a module NEVER deletes data — switching a function off and
  throwing its data away are separate decisions, each with its own control;
  (2) each module is erased individually, and "erase everything" is only a
  loop over the same descriptors — never a second code path; (3) only keys we
  own are listed, never WooCommerce's (prices, images, real reviews).
  `uninstall.php` erases only when the opt-in box was ticked; the default is
  to leave the owner's data in place. A light database is a permanent
  requirement, not a cleanup done once.
- **An internal-link anchor NAMES the page it points to** — a reader seeing
  only the anchor knows where it goes. As close to the target's own name as
  the sentence allows, and no closer: a category keeps its name as it stands;
  an article or page is anchored on the SUBJECT of its title, never on the
  title pasted whole (2–6 words, question mark and filler dropped). The link
  is woven into a sentence that would read perfectly well without it — never
  a quoted title, never a "See X for more" bolted on at the end, never
  "here"/"this page"/"learn more", never an ambiguous destination. Applies to
  every generator that inserts links.
- **Simple, or it is not finished.** Every function and every screen is judged
  from the chair of the person using it, not from the code that produces it.
  Before shipping anything, three cuts:
  - **What can be REMOVED?** A setting the shop would never change, an
    explanation of a mechanism nobody has to know, a second control that says
    what the first already says — all of it goes. A paragraph explaining a
    checkbox usually means the checkbox is wrong.
  - **What does the owner actually need to SEE?** Usually a state and one
    action: "Translations — Activated", "Translate it". Not the reasoning that
    produced the state, not the API behind it, not our excuses for what we
    cannot read. When something cannot be checked, ONE sentence of warning is
    the whole of it.
  - **Does every action say what happened?** A button that starts work says it
    is working, says when each part is done, and says the result in words. A
    click that leaves the shop wondering whether it worked is a broken
    function, however correct the code underneath.
  This is the rule most often broken here, and breaking it is not a detail: a
  screen the owner has to be walked through is a screen he will not use.

- **This plugin is built to be HANDED OVER.** The shop is meant to be
  resellable, and the next owner will have none of this conversation. So
  nothing may depend on knowing what was said here:
  - **A function that needs something set up OUTSIDE the plugin says so, where
    the setting is made.** Klaviyo will not translate an email unless each
    profile carries a `locale`; the shop can have its languages declared, its
    blocks translated, and still send everyone English, with nothing anywhere
    saying why. That sentence belongs beside the language field — not in a
    changelog, not in a chat. Where the plugin can CHECK the outside condition,
    it offers the check behind a button rather than asserting it.
  - **A screen says what state a thing is IN, not only what it can do.** An
    email that went out in one language and an email that went out in five look
    the same until one of them says so. Every produced thing carries its own
    plain statement of what was actually done to it, read from what was stored
    when it was done — never from the setting, which says intent.
  - **Few settings, and no hidden ones** — except what is genuinely invariable
    and would only ever be got wrong (an API path, a marker, a cache TTL).
    Anything the owner could reasonably want different is on a screen with its
    consequence written next to it. Anything he cannot change is not a setting
    and is not shown as one.
  - The test is one question: could somebody who has never spoken to us open
    this admin and understand what the plugin does, what is set up, what is
    missing, and what to press? If not, the screen is unfinished.

- WPML compatibility everywhere.
- **Both channels are released in the same pass, by default.** Fixes AND
  additions to a module that already exists — a new control on an existing
  screen, a UI correction, a wording change — go straight to `Live-plugin`
  without asking, alongside `Plugin-development`. DEV-only is the exception,
  for work the owner has called unfinished or experimental, or for a module
  that does not exist yet; say so and wait for his word in that case.

- **Built to last, and built for the person using it.** Two principles that
  outrank convenience, and outrank a hurried instruction — including one of
  the owner's own:
  - **A function is judged on whether it will still be right in a year.** It
    holds when a provider changes an id, when a setting is renamed, when data
    is missing, when somebody clicks twice. It fails loudly rather than
    quietly. It has one code path, not two that must be kept in step. A clever
    thing that needs to be remembered is worse than a plain thing that does
    not.
  - **The interface is designed for the OWNER, not for the developer.** He
    must be able to forget this plugin for three months and find his way back
    without reading anything: short labels that say what the thing does, no
    paragraph where a line will do, no setting whose consequence is invisible,
    and no useful function buried where nobody will look. A screen he cannot
    use is a function that does not exist. When something must be set up
    before a module can work, the module SAYS SO where the work happens —
    it does not wait to be discovered.
  - **These principles may be argued back.** If an instruction — from the
    owner or from anybody — would produce a fragile function or a screen that
    is harder to use, say so plainly, explain why, and propose the version
    that holds. Then do what he decides. Agreeing on the spot and shipping the
    weaker thing is not obedience, it is a problem delivered later.

- **The owner wants well-built, well-finished functions — not features piled
  up.** Every addition is judged on how it lands in the environment it joins:
  before writing anything, look at what is ALREADY there on that screen and
  make the new thing work in harmony with it — same gesture, same wording,
  same place, one way of doing each thing. Never a second code path beside an
  existing one (a hand-written save next to WordPress's own, a second strip of
  images, a second popup): the two drift apart and one of them silently loses
  data. Adding a control to a screen means re-reading that screen as a whole
  and removing what the addition makes redundant. A function that works but
  leaves the screen more confusing than it found it is not finished.

## Traps learned the hard way

- Settings pages are saved by ONE mechanism: WordPress's own Save Changes,
  full submit to `options.php`. Never add a custom AJAX save endpoint for a
  settings tab, and never a background submit of the whole form: the one that
  existed hung on a slow server, fell back to an ordinary submit, and — since
  the form carries EVERY prompt as it stood when the page was opened — wrote
  old text back over prompts edited since from another screen. A per-row
  "save" that posts the whole page is not a per-row save.
- A settings form that carries rows it did not change must prove it: each
  prompt ships an `md5` of what was rendered (`pr_was`), and a row whose text
  came back untouched is left as the shop holds it, not as the page remembers
  it.
- `update_option()` on our registered options re-runs the sanitize callback
  (shaped for FORM input). Programmatic saves of canonical data must use
  `DZE_Content::write_settings_direct()`-style writes (filter removed around
  the write) + a read-back check. Never let registry()/read paths persist.
- fal.ai sources: local files go as base64 data URIs; only fal's own CDN
  hosts are accepted as remote sources (`DZE_Content::is_fal_url`).
- Every fal/Anthropic call records usage in `DZE_Ai_Usage` and respects the
  monthly budget guard.

- **A new function is TESTED before it goes online, not after.** The owner is
  not the test bench. Every new function, and every function whose behaviour
  changes, is EXERCISED before the release — with real values, through the
  real code path, asserting the real answer — and the release only happens if
  that passes. Not "the file parses", not "the class loads", not "the method
  exists": those three all passed on the day the Translate button had no
  endpoint behind it, on the day a criterion was thrown away for having no
  name, and on the day a settings tab was a white page for six versions.
  - The test lives in `tools/` and is RUN AGAIN at every release, beside
    `check-methods.php` and `check-prompts.php` — a check that ran once is a
    check that will not catch the regression.
  - It must be shown to FAIL on the bug it is about: break the fix, watch the
    test go red, put it back. A test that passes on broken code is worse than
    none, because it is believed.
  - Where a real run is genuinely impossible (a paid model call, a live
    provider write), test everything up to that line — what is built, what is
    sent, what is stored, what the screen says — and say plainly, in the
    release note, which single step was not run.

## Release pipeline

- **`php tools/check-methods.php dazont-ecom` must pass before every release.**
  `php -l` proves a file PARSES, not that it runs: a call to a method nobody
  wrote parses perfectly and dies the moment the line executes.
  It also reads the admin JavaScript for jQuery helpers removed in 4.0 —
  `$.trim`, `$.proxy`, `$.isArray` — which work and warn on WordPress's own
  jQuery and die silently the day a shop installs an updater.
  `DZE_Klaviyo::sample_body()` was called from `admin_enqueue_scripts` on one
  settings tab and nowhere else — that tab was a white page for six versions
  while every other screen worked. A fatal there happens before any of our own
  error handling, and a white page carries no message.
- **`php tools/test-diagnostic.php dazont-ecom` and
  `php tools/test-klaviyo.php dazont-ecom` must pass**, and every other
  `tools/test-*.php` beside them — `test-blocks.php` (the body → Klaviyo
  blocks splitter, fed the shapes a model actually writes: one wrapper table
  around the whole email once cost every written word of the draft) and
  `test-trace.php` (the AI trace: a real `complete()` call with only the HTTP
  transport stubbed must leave a readable row, failures included) and
  `test-links.php` (a page of the shop in the reader's language: the shop's
  own German emails linked every product to its ENGLISH page for months,
  because nothing ever filled Klaviyo's per-language href) among them. They run the code against a fake shop and
  check the ANSWERS — that a criterion on `_block_image_1` fires on a product
  where that field is empty, that a gallery is counted rather than read as
  text, that a row written against a field id we have since dropped still
  works. Add one for every new function; never delete one to make a release
  pass. A provider's own answers cannot be run from here, so the Klaviyo one
  stubs the transport and reads the REQUEST — its method, its URL, its headers,
  its body. "No valid revisions found for method" was one header on six calls.
- **`php tools/test-calendar.php dazont-ecom` and
  `node tools/js/calendar-emails.mjs` must pass.** The marketing calendar is
  drawn by an inline script: PHP hands over the promotions and the planned
  emails, and NOTHING appears until a browser runs it — so the PHP gate checks
  what is handed over (and that the front-end shortcode and a disabled module
  get none of it), and the browser gate opens the plugin's own rendering and
  reads the chips back, on the right days, linking to their promotion.
- **`php tools/test-shoot.php dazont-ecom` must pass.** Making a product
  photograph is ONE function, `DZE_Content::shoot( array $in )`, and the AJAX
  handler is a thin wrapper over it — it used to BE the handler, three hundred
  lines reading `$_POST` and ending in `wp_send_json_*`, so nothing could call
  it and any automatic pass had to copy the whole prompt assembly (sources,
  "not like this" references, scene, notes, ratio) or do without it. The gate
  runs the real function against a fake shop and reads WHAT IS SENT — the
  prompt, the sources, the ratio, where the result is filed — and holds two
  structural lines: `shoot()` never ends the request and never asks for a
  nonce, because a JSON exit or a `guard()` put back inside it makes every
  automatic pass impossible again, silently.
- **`php tools/test-copy.php dazont-ecom` must pass.** A staging site is a COPY
  of the shop — same Klaviyo key, same Google service account, same scheduled
  hooks — and nothing in the plugin used to tell the two apart: one cron tick
  on the copy wrote campaigns into the real account. `DZE_Site` records the
  address the shop was set up on; on a copy every outward WRITE is refused at
  the one place each service passes through (`DZE_Klaviyo::request()`,
  `DZE_Gmc::request()`), reading stays open, and nothing scheduled runs — a
  pass pressed by hand does. It has to be right in BOTH directions: a shop
  wrongly called a copy is a shop that has quietly stopped sending, so a shop
  updating to this version adopts itself silently and nothing changes for it.
- **`php tools/test-gmc-token.php dazont-ecom` must pass.** Google answers
  `invalid_grant` / "Token has been expired or revoked" when the authorisation
  is GONE: no retry fixes it, so it is recognised, written down once on the
  connection, and every screen says the one thing to do. The shop read
  Google's own words five times on one line — once per market feed — while
  the screen that reconnects went on showing a green "Connected".
- **`php tools/test-promo-i18n.php dazont-ecom` and
  `node tools/js/markets-button.mjs` must pass.** A promotion in five markets
  is ONE promotion: its dates and its discount go WITH the ask, and a shop
  rule appended to the owner's prompt forbids swapping its occasion for a
  local holiday — "Patriot Day Sale" came back for France as the 14 Juillet,
  a different holiday in a different month. And "Write it for my other
  markets" REWRITES every language each time it is pressed; only the
  automatic pass at save time leaves a hand-typed line alone.
- **The plugin shows UNITS SOLD, never money.** It tried: order lines are kept
  in the currency they were paid in and carry no currency of their own, so a
  reader grouped them by the order's currency and converted at the shop's
  rates. It was right and it still produced $6,792,487 against 23 units at
  $76.90 — because `wc_order_product_lookup` on this shop holds hundreds of
  rows whose order does not exist, left behind by old imports, and no amount
  of correct arithmetic survives an input like that. Three releases went on
  defending a column nobody could believe. So the column is gone, and with it
  `DZE_Money`, `DZE_Sales` and `test-money.php`.
  **Do not reintroduce a money figure read from that table.** Quantities are
  safe — four sold is four sold in any currency — and quantities are what the
  shop actually decides on. If money is ever genuinely needed, it comes from
  WooCommerce's own reports, which own the data and repair it.
- **A criterion is ONE rule and ONE figure, until somebody asks for more.**
  Everything else on that card is behind a box marked Conditional, unticked on
  a fresh install and unticked for every criterion until it is ticked. Ticking
  it opens the conditions WITH A FIRST ONE ALREADY THERE — an empty list under
  a box just ticked is a press that answered with nothing — and unticking hides
  them and stops them counting without throwing them away. The default screen
  is the simple one; the complex one is a choice.
- **A criterion can carry CONDITIONS, and only ONE place holds a count.** One
  figure for a whole catalogue is what made "Issues (981)" a number nobody
  believed: a $16.90 cap and a $90 plate carrier were both judged on "3
  photographs". A criterion carries an optional list of conditions, each a
  whole sentence that means the same thing alone as in the list — "price
  between 40 and 80 : at least 4 photographs" — read top to bottom, first fit
  wins, with the criterion's own figure for anything none of them place. Every
  line names the field it measures, so a price range and a stock range sit in
  the same list. Chained thresholds ("under 40", then "under 80") were tried
  first and rejected by the owner: a line you cannot read without reading the
  one above it is a line nobody reads. Ranges are HALF OPEN — 40 belongs to
  "40 and 80" and to nothing else — because two ranges meeting at a number is
  the one place a reader would have to guess.
  **With Conditional ON, the conditions ARE the rule.** The plain figure comes
  off the card entirely — kept in the form so unticking gives it straight back,
  but neither shown nor applied — and an object no condition covers is NOT
  JUDGED: nothing was asked of it, so it cannot fall short. A figure sitting
  beside the conditions was a second rule nobody had written, and no screen
  could say which of the two a product had been held to. The criterion's name
  follows: the conditions' figures when there are any, the plain one only when
  it is what the criterion is actually judged by.
  **The field menus are in alphabetical order**, sorted where a menu is built
  and never in `fields()` itself — that order is what a fresh criterion opens
  on.
  **The count lives on the rule and nowhere else, and "Fix it with" is gone.**
  The criterion said "at least 3 photographs" and the routine beside it said
  "2 of this prompt, 1 of that": two answers to one question. The whole repair
  surface was removed from this screen rather than patched again — it comes
  back built from the reading, not from a figure typed twice. Do not
  reintroduce a per-criterion count of anything the rule already counts.
  It is on EVERY criterion that holds a figure — a word count as much as a
  number of photographs — and the gate is the RULE's comparison, never the
  field's type. Tied to the type it gave conditions to the gallery and denied
  them to "description is less than 120 words", which is the same question.
  Five things are gated because all five were got wrong while writing this: an
  object with NOTHING to place it by (no price set) measures 0 and would land
  in the first range starting at 0 — the easiest standard given to the most
  broken products — so it falls through to the plain figure instead; the
  criterion's NAME carries every figure ("less than 3/4/6 photographs"); the
  condition block is drawn ALWAYS and shown by JavaScript, because rendered
  only for a field already saved as a count it never appeared for one switched
  in the browser; and a condition added by a press is RE-CUT to the card it
  landed on, or it keeps the blank prototype's menu and offers the very field
  being judged. `tools/js/diagnostic-card.mjs` clicks all of it in both jQuery
  builds — a disabled prototype row is what a press copies, so the markup
  lives in ONE place, and the rows are renumbered on every add and remove. A
  fresh condition opens on the PRICE and the row reads "If price is between 0
  and 40 → at least 3 photographs": opened on the first field in the list it
  said "main photograph", and without the two words the menu read as the thing
  being counted rather than the thing being tested.
  `checked()` and `selected()` in the harness return what WordPress returns:
  stubbed to `''` they hid every question they exist to answer.
- **`php tools/test-prompt-block.php dazont-ecom` must pass**, and the Klaviyo
  suite RENDERS the whole email settings tab (`render_settings()` into a
  buffer) rather than only calling its pieces. The "What this prompt is sent
  with" block was added to eight screens in one pass and nothing executed it:
  a grep proved it was CALLED, which is what `check-prompts.php` does, and a
  grep has never rendered anything. A settings tab that dies takes the whole
  page white, before any of our own error handling.
- The browser gate also MEASURES the email row (`DZE_Klaviyo::list_css()` is
  loaded into the harness): a long warning in the state column must not
  collapse the title column to one word per line, the two buttons sit
  together at the end of the cell, and nothing overflows the list. A CSS bug
  is invisible to every PHP test and to `node --check` alike.
- **`node tools/js/klaviyo-open.mjs` and `node tools/js/diagnostic-card.mjs`
  must pass.** They open the real screens in a real browser, on BOTH the
  jQuery WordPress ships today and the jQuery 4 it will ship, click the
  buttons and read back what the page did. `node --check` proves a script
  parses; it does not prove that Open opens anything. A card sitting under
  POSTS while its own menu said "Products", a field chosen fresh keeping the
  last field's figure, a handler dying halfway on `$.trim` — none of those
  exist until somebody clicks, and all of them look like a button that does
  nothing and says nothing.
- **`php tools/check-prompts.php dazont-ecom` must pass too.** Every prompt
  offered a "Make this the default" control has to be answerable by the
  prompt registry: `DZE_Prompt_Defaults::control()` draws NOTHING for an id
  it does not know, so a prompt registered in one list and not the other
  loses its star in silence — the screen still shows "Restore default", so
  nothing looks broken, and the owner simply cannot make his own text the
  default. `promo_email` and `promo_i18n` sat like that. It also checks that
  every prompt DRAWN on a screen calls `DZE_Prompts::the_data()` for that same
  id: the "What this prompt is sent with" block — its last real call, in full —
  must be on every prompt everywhere, because a screen missing it looks like a
  prompt that receives nothing.
- Lint every file AND exercise every ENTRY POINT of what changed — the render,
  the enqueue, the ajax handler, the sanitizer. A class that loads is not a
  screen that works, and the path nobody ran is the path that is broken.
- Version bump in `dazont-ecom/dazont-ecom.php` (header + `DZE_VERSION`).
- Push working branch + `Plugin-development`, then dispatch
  `release-dazont-dev.yml` (workflow_dispatch; tag pushes are blocked by the
  git proxy). Stable: `release-dazont.yml` on `Live-plugin`.
- Build the zip (`dazont-ecom/` folder) and send it to the owner each version.
