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

## The Dazont Ecom line — one shape for the whole shop

"Je veux que cette méthode soit la seule méthode standardisée sur tout le shop :
une façon de faire, avec différentes fonctions en fonction du type de post.
C'est simple, intuitif, efficace, et facile à maintenir : la mise à jour doit
être popularisée aussi sur les autres types de post."

Every screen where the plugin offers to produce something is built from the
same parts, and the parts live in ONE place: `admin/js/hub.js` for the screens
that draw themselves in the browser, `DZE_Hub` for the screens the server
prints. They produce the same markup — same classes, same order — so the one
machinery in hub.js drives all of them. `check-methods.php` fails on a second
block builder written anywhere else, because two builders is how two screens
start behaving differently while looking the same.

The shape, in the order it is read:

1. **What the thing holds today**, in one line, with the figures.
2. **"What to generate"** — one BLOCK per kind of work, each with its switch in
   its own title and its count beside it. A block's own controls live inside it
   (its prompt, its options, its choices), never in a row at the top: the
   linking prompt belongs to the linking block. What varies between post types
   is WHICH blocks there are — a product has photographs, a price and
   variations; a category has a description and its internal links — never the
   shape around them.
3. **ONE button that runs what is ticked**, in the order the work is done, and
   it says nothing is ticked rather than doing nothing.
4. **Before and after**, both printed, each with its own figures, and an empty
   after saying WHICH empty it is.
5. **Accept and refuse**, side by side. Accept is the shop's own Save where the
   screen owns its editor, and WordPress's own Update where WordPress owns it —
   never a second save path beside core's, which is how text gets lost.

A function added to one of these screens is added to the shape, so it arrives
on the others. A function that cannot be expressed in the shape is a function
whose screen has not been thought through yet.

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

- **A COUNTER IS NOT A GRAPH.** Three places counted links — a category's own
  description, an article's own content, `<a href` occurrences on a diagnostic
  row — and not one of them could say who pointed at whom, so a page could be
  written, translated and forgotten while being reachable from a menu and from
  nowhere else. `DZE_Mesh` reads the site once and writes the EDGES down, one
  row per internal link, and every figure on that screen is a question asked of
  that table. Four rules learned building it:
  - **A page built by a page builder keeps its text in post meta.** Read from
    `post_content` alone it points at nothing, tops the orphan list for ever
    and is offered as a place to write a link that would be stored and never
    appear. Its links are read from the builder's data too (`body_of()`), it
    counts as a TARGET, it is never a dead end and never a source.
  - **A threshold that no real pair can reach is a WALL.** "Two shared words"
    let the branch through and nothing else: a two-word category name can
    never share two with its neighbour, which is why "Add internal links only"
    offered two links on a shop with hundreds of pages. One shared word is a
    candidate; which of them is worth the link is a RANKING, and the pass that
    places them decides.
  - **WHICH shared word, not how many.** A tactical shop calls half its pages
    "tactical", so counting flat ranked the whole catalogue equal and left the
    product count to decide. `DZE_Mesh::vocab()`/`weigh()` weigh a word against
    the candidates themselves: one carried by a quarter of them weighs nothing,
    one carried by two weighs double.
  - **A TARGET PICKED BY HAND IS A TARGET.** The pool answers "what would this
    page link to on its own"; the Linking screen asks for the link the MESH is
    short of, and those are not the same question. A picked page the pool never
    offered is added from `DZE_Mesh::page_by_url()`, or the press answers with
    nothing. `DZE_Queue::produce()` is public for the same reason `shoot()` is
    a function: what a job SENDS is the half that goes wrong in silence.
- **A SCREEN NEVER SENDS YOU LOOKING FOR THE SCREEN YOU ARE ON.** "Content to
  review" carried a blue box saying three products were waiting somewhere else
  and offering to go there — read from the chair of somebody who came asking
  "what is waiting for me?", that is the screen describing itself instead of
  showing the work. Products are a TAB of the Content diagnostic, beside the
  others, with their own count; a tab carrying a `url` is a way out of the page
  and `tab_now()` refuses to treat it as a view. And a count belongs to ONE
  view: adding the product figure into the review tab's number while also
  announcing it in a notice was two accounts of one thing on one screen.
- **A TAB'S FIGURE AND THE LIST UNDER IT ANSWER THE SAME QUESTION.** The census
  keeps `short` (pages under the rule) beside `orphans` (pages nobody points
  at), because a badge counting one while the list shows the other is a screen
  that disagrees with itself every day.
- **A BROWSER GATE MUST BE ABLE TO SEE A RELOAD.** A page put there with
  `setContent` has no address, so `window.location.reload()` does nothing and a
  screen that reloads walks straight past the test. Serve the markup from a
  routed URL and `goto` it. And do not wait for a line to merely CHANGE — the
  busy text is already in it: wait for the answer, with a timeout, and report
  the timeout as "the screen answered where it stood" rather than dying.

- **WPML NAMES A TAXONOMY TERM `tax_product_cat`, AND INDEXES IT BY ITS TERM
  TAXONOMY ID.** `DZE_Category_Content::lang_code()` asked
  `wpml_element_language_details` for `product_cat` with a term id, got NOTHING
  back, and read nothing as "the shop's own language" — so every category in
  every language passed for an English one and the link graph reported 830
  pages on a site holding a fifth of that. The failure has no symptom until
  somebody counts: it never errors, it never shows a German word, it just
  quietly stops filtering. `DZE_Diagnostic::element_type()` had the right name
  all along, which is the point — one wrong string in one helper is enough.
  Any reader that walks the posts table with its own SQL (WPML cannot narrow
  that) has the language check as the ONLY thing between it and a count
  multiplied by the number of languages, so that check is exercised with a
  multilingual fake shop, both ways: translations dropped, and a
  single-language shop keeping everything.
- **THE READING BELONGS TO THE OBJECT, NOT TO THE SCREEN THAT OPENED THE
  POPUP.** The toolbox knew what a product was short of only when a diagnostic
  row had opened it — from the product's own page or from the products list it
  showed nothing. `DZE_Diagnostic::todo()` answers for the product, and the
  popup reads it wherever it was opened from. What it prints is a TO-DO LIST:
  one line per shortfall, the field and the figures and nothing else. It used
  to read "Gallery photographs — 3 of 5 photographs. 2 prompts are laid out
  below; change them, add another, then generate." — two sentences explaining
  a screen already in front of you, and the unit said twice. The unit is
  dropped when the field's own name carries it, the line the popup opened FOR
  is marked, and pressing any other line lays THAT one out — the same arming
  the diagnostic row hands over, generating nothing.
- **ONE TICK PER BLOCK, AND IT LIVES IN THE BLOCK'S OWN TITLE.** Images and
  price each carried a checkbox inside the body saying exactly what the
  section it sat in already said — "sur le bloc image et prix, ça n'a pas de
  sens d'avoir un double bouton". The switch is in the heading; on the text
  block the same tick means "all of them", going in-between when only some
  are on. A tick in a heading must not fold the section under the hand that
  pressed it. And a block whose work is ROWS is not counted in checkboxes:
  counting them counted the switch and read "1 / 1" whatever was laid out.
- **A CLASS THAT MEANS "PANEL" IS NOT A CLASS THAT MEANS "THIS PANEL".**
  `.dze-pr-inputs` is the wrapper every fold-away panel on a prompt card
  wears, and the counter that renames "Product data sent with it (2)" ran over
  all of them — so the panel beside it came back as a second "Product data
  sent with it (0)", printed straight underneath. Name the thing you are
  about to rewrite.

- **A BLOCK CALLED "BEFORE / AFTER" PRINTS BOTH.** It printed one document —
  the before — and put the after's figures in its heading, so on the category
  edit screen, where the new text lands in WordPress's own Description field
  further up, the panel showed the OLD text and "0 words · 0 links": "aucun
  avant/après juste un avant". `ajax_diff()` had been returning `after` all
  along and `i18n.after` was registered and never used. Both documents are
  printed now, each labelled with its own figures, and an empty after says
  "Nothing written yet" rather than a nought that reads as a broken screen.
  **And a screen that offers a decision offers the way to take it**: the
  `.dze-cc-revert` handler had existed for months with the button printed on
  neither host — "rien pour accepter les modifs, modifier les modifs, ou les
  refuser". Accept is the shop's own Save (the popup) or WooCommerce's Update
  (the edit screen), and the refusal is beside it on both.
- **A HANDLER THAT THROWS HALFWAY STOPS THE SCREEN, AND SAYS NOTHING.**
  "Before / after — hide — 0 words · 0 links. Pour le netlinking je ne
  comprends pas, je ne vois pas le texte actuel." The linked text arrived in
  the editor and every line after it in that handler died: `markPlaced()` read
  `r.href` and `linkList()` had never carried one — it builds `path`, which is
  what a row PRINTS, host stripped off. One TypeError on the first link, so the
  "already linked" marks were never put on and `showDiff()` was never reached,
  leaving the before/after block on whatever it last held. Nothing in PHP and
  nothing in `node --check` can see it; the gate that can is a browser walking
  the WHOLE loop and asserting `errors` is empty at the end of it, not only at
  the start. And the harness config is copied KEY BY KEY from
  `wp_localize_script` — named `ajax` instead of `ajaxUrl` it posts to the page
  itself, every answer comes back as HTML, and the gate proves nothing while
  looking green.
- **WHAT IS ON SCREEN IS WHAT TRAVELS — the linking job carried no text at
  all.** `runJob()` sent the term id and the picked urls, and `produce(
  'cat_links' )` read the description OUT OF THE DATABASE — never the editor,
  which saves nothing until Update is pressed. On a category written in the
  panel and not yet saved it linked an empty string and came back "0 words · 0
  links". The job carries `html` now: ABSENT means "as it stands", which is
  what an automatic pass and a row queued from the Linking screen mean; present
  means that exact text. What a press puts on the wire is only visible in a
  browser, so it is asserted there.
- **A SCREEN SERVED BY AJAX PRINTS NOTHING IN `admin_footer`.** The category
  panel is an AJAX answer, and `DZE_Prompts::button()` asks for its popup by
  hooking `admin_footer` — which never fires there. So "✎ questions" and "✎
  linking" arrived on a page holding neither the popup nor the handler that
  opens it: pressing them did nothing and said nothing, for months, while a
  grep found the buttons and `php -l` found no error. Any screen a panel can
  open on calls `DZE_Prompts::print_assets()` at page load. And this is the
  screen that had NO browser gate at all, which is why it lasted:
  `tools/js/category-panel.mjs` now presses every button on it, on both jQuery
  builds, and reads back the request AND the answer landing. In that harness
  jQuery must be served BEFORE the popup's own inline script, or the whole
  handler dies on "jQuery is not defined" and the gate proves nothing.
- **FOUR CONTROLS, ONE VISUAL LANGUAGE.** That row read "✎ ⓘ ✎ questions ✎
  linking" — a lone pencil, a lone ⓘ and two worded buttons, three ways of
  saying "look at something", and the pencil opened an INLINE editor while the
  words opened a popup. A lone icon is a symbol you have to learn. Every one
  of them carries a word now, and the inline prompt editor is gone: reading a
  prompt, changing it, saving it and putting the default back is what the
  prompt popup does everywhere else, and two surfaces for one job drift apart
  — that one also sent whatever it happened to be showing as a one-off
  override, so the same button ran two different instructions depending on a
  panel's state.
- **AN IDF THRESHOLD IS RIGHT ON A BROAD SHOP AND CATASTROPHIC ON A NARROW
  ONE.** `weigh()` discards any word carried by more than a quarter of the
  candidates — which is why "tactical" stopped ranking a tactical shop's whole
  catalogue equal. On a shop whose every page is a jute rug it discards "jute"
  and "rug", so every candidate scores nought and the screen reads "Black jute
  rugs — not obviously related" under *Jute and cotton rugs*: "ce n'est pas bon
  du tout". Counting words cannot tell those two shops apart, and no threshold
  will. So the wording SHORTLISTS and a reading JUDGES:
  `DZE_Category_Content::judge_links()` sends the numbered shortlist, the
  owner's own `cat_pick` prompt and the page's ceiling to the cheap model, and
  keeps the verdict on the category until the candidates or the prompt change.
  Three rules, each of them a way it goes wrong: a verdict that cannot be had —
  no key, a refusal, a broken answer, "none of them" — leaves the wording's own
  answer standing rather than emptying the list; every row says WHICH of the
  two chose it, in the reader's own words or "chosen on wording", and never a
  judgement nobody made (that is what "not obviously related" was); and the
  ceiling is the page's own, kept whatever comes back. The gate's fake model
  answers from the REQUEST — it reads the numbered list that was actually
  sent — so a call that sends the wrong thing cannot pass it.
- **LISTED IS NOT TICKED.** Relaxing the pool's gate to one shared word fixed
  "two links only" and broke the other end: "Add internal links only" opened
  with THIRTY pages ticked, Tactical Sunglasses and Tactical Balaclavas among
  them, because every page of a tactical shop carries the word "tactical".
  Membership and pre-selection are two questions: one shared word makes a page
  a candidate worth SHOWING; what is TICKED is the branch plus the pages whose
  shared wording actually says something (`DZE_Mesh::weigh`, so a word a
  quarter of the candidates carry weighs nothing), capped, with everything
  else listed one tick away. A screen that opens with thirty ticked boxes is a
  screen where nobody reads the boxes.
- **A SHOP'S OWN WRITING TRAVELS, AND NO KEY TRAVELS WITH IT.** Prompts and
  diagnostic criteria are what the owner spent months on and they were trapped
  on the site they were typed into. `DZE_Transfer` carries them: Settings →
  Transfer, copy one side, paste the other. Four rules, each of them a way it
  goes wrong: a bundle is READ before a single write and refused by name when
  it is not one (the read is not a step somebody does first — it is the first
  thing the write does); what it holds is said in the words each group is
  named by, before anything is replaced; a group not ticked is not touched; and
  every write goes through the module that OWNS that data
  (`DZE_Content::write_setting`, `DZE_Diagnostic::write_rows`,
  `DZE_Prompts::save_text`), filter removed and read back — never
  `update_option()` on a registered option. A product prompt is carried ONCE,
  as a registry row: its text alone would land on a shop that sends it
  nothing. **API keys are never in the bundle** — it is pasted into chat
  windows and tickets — and the gate asserts that on the real text with the
  keys set.

- **A KEPT READING MUST DIE WHEN META CHANGES, NOT ONLY WHEN THE POST DOES.**
  The problem list re-judges its rows as the page is drawn and keeps that
  verdict for five minutes, keyed on `MAX(post_modified_gmt)`. Half of what
  these criteria read is post META — a gallery, a theme's block field, a
  custom key — and `update_post_meta()` does not move `post_modified`. So
  mending a product's photographs left the key untouched and the same verdict
  came back: "j'ai mis à jour le contenu d'un produit mais il est toujours
  dans la liste Issues (252) et quand j'ouvre sa popup je vois le nouveau
  contenu." WordPress already says when a meta key was added, changed or
  removed; `DZE_Diagnostic::touch()` listens to those four hooks and to
  `save_post`, capped to one option write per second, and the key carries that
  stamp. Four hooks, not a list of writers somebody has to keep in step — the
  one forgotten is always the bug.
- **A FIGURE A SCREEN STATES AND THEN DOES NOT KEEP IS WORSE THAN NO FIGURE.**
  The category panel writes its own ceiling at the top — "Target for this
  category: 700 words, and up to 14 links (one per 50 words)" — and the link
  list under it arrived with thirty ticked. The pre-tick ceiling IS that
  figure, `size_for()['links']`, never a constant invented beside it. One link
  per fifty words is the shop's rule and it is kept where it is written.
- **A LINK ALREADY GOING ONE WAY IS THE FIRST OFFERED TO COME BACK.** Not a
  rule — nothing is owed a link back — but two pages, one of which already
  sends its readers to the other, were judged close once already by whoever
  wrote that link: "c'est logique de lier les mêmes pages entre elles
  puisqu'elles sont censées avoir un fort cocon sémantique". `shortlist()`
  ranks such a candidate up and still only offers it.
- **ONE GRAPH, ONE RANKING.** `DZE_Automation::survey()` counted a category's
  inbound links from other CATEGORY DESCRIPTIONS and nothing else, so an
  article sending its readers to an aisle counted for zero — and the automatic
  pass, which works on the least pointed-at category first, worked from a
  reading that could not see half the mesh. It reads `DZE_Mesh`'s census when
  there is one. A graph that has not been read yet answers NULL, never an
  array of zeroes: "nobody points at anything" would send the pass at the
  wrong page every day.
- **A COUNTDOWN IS NOT PART OF THE SENTENCE.** Glued on with a single space it
  read as one run-on line — "Patriot Day Sale! -15% on the entire store 3d 21h
  11m 40s". A separator and room; tabular figures, or the seconds shift the
  whole banner sideways once a second; nowrap, or the count breaks over two
  lines on a phone.

- **A FAILED LOOKUP IS NOT AN ANSWER.** The update checker cached a release
  carrying the SHOP'S OWN version whenever GitHub could not be reached — so
  for the next half hour every check read that back and said "Up to date" with
  total confidence, having never spoken to anybody. Not being able to look and
  being current must never wear the same words. The failure is remembered (so
  GitHub is not hammered) AS a failure, and the answer names the CHANNEL it
  looked at: "up to date" is true of the stable channel and says nothing about
  the development builds beside it. **And the release pipeline has two
  dispatches, not one**: three versions in a row went out as development
  pre-releases only, because `release-dazont.yml` on `Live-plugin` was never
  dispatched. Pushing the branch is not releasing it — check
  `list_releases` and see the stable tag before saying a version shipped.
- **ONE TASK FOR ONE PIECE OF WORK.** Internal linking was two automation
  tasks — one for categories, one for articles — each mending half a mesh from
  its own half-blind reading, and the shop had to switch on both and know why.
  It is `mesh_links` now: the link GRAPH says which page is short and who
  should point at it, whatever kind of page either of them is, and the row
  carries the addresses it chose so the pass writes THOSE links rather than
  whatever the page would have picked on its own. The job it queues is still
  `cat_links`/`post_links` — the pass that already writes that kind of page.
  There is no third linking engine and there must never be one.
- **WHICH PHOTOGRAPH, NOT WHETHER.** "Keep the product's own photograph as the
  subject" was a checkbox answering a question nobody had asked, while the
  real one — WHICH of its photographs — had no answer at all. It is a picker
  now, on the images block and on the one-function popup's own tiles, and
  picking one says both things: it is image 1, and anything pasted is read for
  the place, the light and the styling. The server reads a picked photograph
  as the subject (`$base_main || $src_id`), so the checkbox that used to say
  that in words is gone from both screens.
- **A DEFAULT THAT SENDS NOTHING IS NOT AN ANSWER, AND THE PICKER MUST OFFER
  EVERY ANSWER.** That picker opened on "Main photograph" and sent nothing on
  it — and a request carrying pasted photographs and no answer is read by the
  server as "the pasted one leads". So a supplier shot added for context
  became image 1 and the product came back in ITS colour: "il me donne du
  kryptek noir plutot que du desert. Avant ça fonctionnait." Two rules, one
  fault: the default POSTS what it says, and what was added from outside is an
  OPTION on the same picker — the answer the screen cannot give is the answer
  nobody can give. And the value a control posts is read off the page when the
  request is built: only a browser can see it, so it is asserted on the wire
  (`tools/js/diagnostic-fix.mjs`) and the reading of it asserted on the server
  (`tools/test-shoot.php`). `shoot()` had never read `src_id` at all — the
  toolbox posted it and only the main-image lane looked — so the picker was a
  control that did nothing on the lane it lives on.

- **NOTHING APPENDED MAY OVERRULE THE PROMPT, AND NOTHING APPENDED IS HIDDEN.**
  "Tu as encore ajouté des instructions custom par dessus le prompt ? Ça t'est
  interdit. Le prompt est le gagnant. Il est bien rédigé, et aucune autre
  instruction cachée ne devrait exister." Two things were wrong at once. With a
  scene chosen, the appended text told the model to "ignore any background
  described in words above" — the plugin disregarding the instructions it is
  appended to; that is never allowed, whatever the reason. And the whole note
  was INVISIBLE: the prompt card listed three vague bullets ("the product
  photographs, as real images") while several hundred characters went out under
  them, and he found them in a trace. What legitimately goes there is a LEGEND —
  which image is the product, which is the scene, which was pasted — because
  the prompt cannot know how many photographs the run attaches or in what
  order. Everything else is an opinion competing with his own text. So:
  `prompt_note()` returns the note and the card prints it WORD FOR WORD, read
  from the same function that sends it (`test-sources.php` asserts the two are
  the same string, or the screen and the request drift apart on the next edit).
  Adding a sentence to that note is a decision the shop takes, not one taken
  for it.
- **THE PHOTOGRAPHS WIN OVER THE WORDS, AND THAT IS SAID ON EVERY RUN.**
  "Trop de AI slop sur les images... des attaches imaginaires rajoutées devant,
  une sangle imaginaire rajoutée derrière." The shop's own trace showed why, in
  its own words: every image request carries the product's data, and that
  description read `Adjustable chest tension strap`, `Three back buckles for
  optional cape attachment`, `Extensive outside strapping`. The model drew
  exactly what the text told it the product has, on an angle where none of it
  is visible. The sentence handing the argument to the photographs was sent
  ONLY when a photograph had been pasted or picked — which is the one case
  where the text was least likely to be believed anyway. It goes on every run
  now. **When a text and a picture can disagree, something must say which one
  is the product.**
- **IDENTITY IS NOT THE PHOTOGRAPH.** Every sentence of the sources block
  asked for the same half of the job — "keep it exactly as it is", "reproduce
  it exactly", "THE PHOTOGRAPHS WIN", "nothing they do not show" — and not one
  of them ever said that a NEW photograph was being made. The cheapest way to
  obey all of it at once is to hand image 1 straight back: "maintenant des
  doublons exactement comme l'image principale". One sentence says the missing
  half, and it is NOT a fifth way of saying the first: identity is what the
  product is, the photograph is what is being made of it. It is never sent on
  an edit of one image handed in (`'' !== $src`), where giving that image back
  changed IS the job — which is the only lane allowed to look like its source,
  and the only reason `sources_instruction()` needed to be told which lane it
  is on.
- **TWO SOURCES WAS THE WRONG TRADE.** `source_cap()` sent 2 photographs of a
  five-photograph product. The fear was an edit model reconciling six angles
  into a seventh; the cost was worse — asked for a close-up of fastenings it
  has never been shown, a model paints plausible ones, and on tactical gear
  that is immediately, obviously wrong. Ten now, with the weight guard
  deciding the rest: a part it has seen is a part it does not have to invent.
- **FOUR WAYS OF SAYING ONE RULE IS NOT FOUR TIMES THE RULE.** The sources
  instruction had grown to say "read them together", "never invent",
  "reproduce every fitting" and "leave out what is not readable" — four
  sentences competing with the shop's own prompt for the model's attention.
  One rule, said once, arbiter included. Every sentence added to a prompt is
  taken from the one beside it.
- **A SCREEN SAYS WHICH WORK EATS THE BUDGET, not only what one unit costs.**
  The usage table gave "$12.40" per kind of work, which is an amount and not
  an answer: "quels travaux bouffent quel budget" is a question about SHARE.
  A share column, a row for what no pass claimed, and a total — and a cost
  that is real but under half a point reads "<1%", never "0%", which is a
  figure saying the opposite of what it means.

- **A FILTER ONLY ANSWERS WHERE ITS PLUGIN'S HOOKS ARE LOADED; A TABLE ANSWERS
  EVERYWHERE.** Naming WPML's taxonomy correctly (`tax_product_cat` + the term
  taxonomy id) fixed the categories and left the count at 780: posts and pages
  were narrowed by `wpml_element_language_details` too, and the mesh reading
  runs in an AJAX action and in cron, where WPML's hooks are NOT loaded. Every
  filter came back empty, empty fell through to "the shop's own language", and
  every translation passed for an English page. `DZE_Wpml::ids_in_language()`
  reads WPML's own table — one query per element type instead of a filter call
  per object, and it answers in every request. NULL from it means "do not
  narrow", never "narrow to nothing": a shop with one language keeps every
  page it has. This is the same trap the Klaviyo links hit with
  `wpml_object_id`; when a reading must be right outside a page load, ask the
  table.

## Release pipeline

- **Each criterion's object list is its OWN option, never autoloaded.** They
  all lived in one row, read whole to draw fifty lines of one of them, so the
  cap had to be small: a shop with 2,106 products short of one thing was shown
  a thousand and told the count was exact anyway, which is true and no help.
  One row per criterion, read on demand, and a shop whose last reading predates
  the split still reads the old row until the next scan. The prefix is declared
  in `DZE_Cleanup::map()` — an option name ending in `_` is a PREFIX there, the
  same convention transients already use.
- **A control NAMES what it is about to do, and a job in progress SAYS it is
  in progress.** "Fix" told the shop nothing about which pass would run, so
  every queue job carries a verb (`does`) beside the noun the job list uses,
  and the button on a row wears it. And a row that has just arrived in the
  writing queue shows a spinner and "Waiting its turn" rather than a bare
  cross: it read as an empty, dead line, and the owner could not tell whether
  anything had happened at all. Status words live in PHP, never in the
  JavaScript — hard-coded there they were English on every shop.
- **A CONTROL IS TESTED ON WHAT IT DOES, NEVER ON THE FACT THAT IT EXISTS.**
  This is the rule broken most expensively here, three times in one week, and
  always the same way: a test asserted the button was ON THE PAGE and nothing
  ever asked where it went. A link to "the tool" shipped twice pointing at a
  Dazont SETTINGS TAB — "Categories →" from a category criterion, the
  Automation tab from an article criterion — both reading as the thing the
  line is about and both being a preferences page that mends nothing. A "Fix"
  button shipped never sending the id of its own row. A button shipped with no
  handler bound at all.
  So, for every control that is added or changed:
  - **A link is tested on its DESTINATION.** A control on a row of objects
    opens or acts on THAT object. A settings page is never the destination of
    a row — `test-diagnostic.php` fails if any admin settings URL appears
    inside the problem list, and that check exists because grepping for the
    button passed while the button was wrong.
  - **A button is tested by BEING PRESSED, in a browser, on both jQuery
    builds** — `tools/js/diagnostic-fix.mjs` — reading back the request it
    made (its action, its nonce, the id of its own row) and then reading the
    page again to prove the answer LANDED. A request whose answer goes nowhere
    is the same broken button from the shop's chair.
  - **A table is tested on the ORDER of its columns, not only their presence.**
    The header is declared in one place and the cells in another; they were
    out of step by one, so every row printed the condition under "Price" and
    the screen looked broken. Assert a heading AND the cell that belongs to
    it.
  - **Nothing ships from a green suite that was run before the last edit.**
    See `check-lint.php` below.
- **A BUTTON ON ONE OBJECT OPENS THE FUNCTION THE OWNER WOULD HAVE OPENED
  HIMSELF — it does not run one.** "Make a photograph" on a diagnostic row
  fired the moment it was pressed, showed nothing, chose nothing, and replaced
  the row with a link to a bulk list nobody had asked to go to: "rien de
  contrôlable visible à l'appui sur le bouton", "j'aurais choisi 2 images photo
  shoot + 1 ugc si j'avais le choix". So, for a control that acts on a single
  object:
  - It opens **the popup that already exists for that object** — the product's
    own toolbox, opened from the product screen and from the products list, and
    now from the diagnostic. Never a second surface, never a redirect away from
    the list, never a second place a generated photograph waits for a decision.
  - It opens it **armed and explaining itself**: the section the criterion is
    about, the prompt rows the shortfall calls for (one per missing
    photograph, from the shop's own prompts in the order it wrote them), and
    one sentence saying why it opened that way. Everything is a suggestion to
    be edited; nothing is spent until the person presses Generate.
  - **What a product page can generate is a CATALOGUE, in one place**
    (`DZE_Content::blocks()`), keyed by what each block WRITES —
    `post_content`, `image.gallery`, `price` — never by a prompt's name, which
    the shop renames. `DZE_Diagnostic::block_for()` is one line per field
    against that catalogue: a criterion invented tomorrow arrives with its
    repair attached, and a shop with no prompt aimed at a slot gets no button
    rather than one opening on an empty section.
  - **A list hands a SELECTION to the bulk screen that already exists.** Tick
    boxes, one button, `DZE_Content::set_bulk_list()` and the same URL the
    products list redirects to. Never a second bulk engine.
  - The decision is split from the request: `bulk_pick()` returns where to go
    and `post_bulk()` does the redirect, because a handler that ends the
    request cannot be tested — the same rule `shoot()` is held to.
  `tools/js/diagnostic-fix.mjs` presses it in a real browser on both jQuery
  builds and proves the opposite of what it used to prove: that the press
  generates NOTHING, that the page does not move, and that the popup came up
  armed.

- **WHAT IS ON SCREEN IS WHAT TRAVELS, and what was sent is what the shop
  then holds.** The email editor posted its BODY with every push and read the
  subject out of the database, so an email that was "clean, tout est là" came
  back as "a campaign with no subject is not one", and a subject edited since
  the last save went to Klaviyo under the old line with nothing saying so.
  Three fields of one thing must not travel by two routes. Present-and-empty
  is a real answer (refuse); ABSENT means "as it stands" and never writes.
  After a successful push the shop stores what it sent — a database left on
  the previous version makes the row, the next translation and the next push
  all describe an email that is not the one in the account. And never let a
  request payload shadow the variable holding the content (`$body` was both).
- **A DECISION IS SIGNED.** Nothing recorded WHO accepted or refused, so a
  shop with more than one pair of hands could see that a page had been dealt
  with and never by whom — the first thing anybody asks once the work is
  handed to somebody else. The queue carries `decided_by` (schema 2), written
  at all four places a decision is taken (accept, refuse, and both in bulk),
  and the products log carries `by`. It is a NAME on screen, never an id; an
  account deleted since keeps its decision and says so; and 0 means an
  automatic pass, which has nobody to name and must not be given one.
- **ONE SUBJECT, ONE MENU ENTRY, WORDPRESS'S OWN TABS.** Reading what the shop
  is short of, doing something about it and saying yes or no to what comes
  back is ONE piece of work; it lived on three entries the owner had to
  connect himself, one of them reachable only through a redirect from a
  notice. It is **Dazont Ecom → Content**, with `nav-tab-wrapper` — core's
  idiom for a subject seen several ways, and already the plugin's own on other
  screens. The tabs are declared in one place (`DZE_Diagnostic::tabs()`) and
  each BODY belongs to the module that owns that work: `DZE_Queue::body()` is
  printed by the tab and by its own page alike, so the two can never drift.
  A tab appears only while its module is on, and a module whose host is off
  keeps a page of its own (`DZE_Queue::hosted()`), because switching one
  module off must never take another's function with it.
- **A FILTER IS BUILT FROM THE LIST, NEVER FROM THE SHOP.** "Je veux un outil
  de filtre ici... les catégories dispo pour filtration doivent contenir des
  produits dans la diagnostic avec mention (x) de la quantité." A menu offering
  every category the shop has is a menu where most choices answer with an empty
  screen. `cat_index()` reads the criterion's own list — one query for the whole
  of it, like `facts()` beside it — so an option exists only where it holds
  something and carries how many. The narrowing happens BEFORE the sort, the
  paging and either tab's count, or the figures and the rows disagree; and the
  choice is carried by every link that leaves the page for itself — a column
  heading, a tab, a page of results — because a filter thrown away by sorting
  is a filter nobody trusts. It is a plain GET form, the way WordPress narrows
  every list it has: no JavaScript to go missing, and a category that answers
  for nothing on this list gives the whole list back rather than an empty page.
- **A BODY THAT MOVES TAKES ITS ASSETS WITH IT.** The product bulk screen's
  script, editor and paste box were enqueued behind ONE page hook —
  `product_page_…`, the standalone page and nothing else. Drawn as a tab of
  Content diagnostic the hook is that page's, so `content-bulk.js` was never
  loaded: it is what builds the prompt rows and ticks the boxes, so the Images
  block came up with its column headings and no rows and Texts read 0/7 —
  "bugé, aucun prompt à choisir". `DZE_Queue::body()` already had the answer,
  and it is the rule: a body enqueues what it needs FROM INSIDE ITSELF
  (`bulk_assets()`), so no hook has to be kept in step and a screen that draws
  it next year needs to know nothing. That includes what a button drawn in
  JavaScript opens — `DZE_Prompts::print_assets()` — and the media modal.
  **And the gate must DRAW THE SCREEN, not call the helper**: the first version
  of this check called `bulk_assets()` itself, which proves the assets enqueue
  and nothing about whether the screen ever asks for them — it stayed green on
  the broken code, which is the exact fault it was written for.
- **THE READING IS ON THE ROW YOU ARE ABOUT TO TICK.** "Sur l'écran bulk tu
  vas ajouter le diagnostic qui le concerne, pour qu'on sache facilement quoi
  générer." The bulk screen listed products and said nothing about what any of
  them needed, so choosing what to generate meant reading one screen and
  ticking on another. Every row prints `DZE_Diagnostic::todo()` — the SAME
  answer the toolbox and the problem list print, so three screens can never say
  three different things about one product. Two rules it is gated on: the key
  is ABSENT when there is no reading to be had and an empty ARRAY when the
  product is short of nothing (one line of markup cannot carry both, and a
  blank row reads as a reading that never happened, so it says "Nothing
  missing"); and the whole surface is gated on the MODULE, asked once for the
  page rather than once a row, because a class file always exists.
- **A BLOCK'S SWITCH LIVES IN ITS HEADING, OR THE COUNT BESIDE IT LIES.**
  `countSec()` in photos.js reads `> .dze-sec-head .dze-sec-tick input` to
  decide how many of a block's ROWS will run. On the bulk screen the switch sat
  inside the body, so it found nothing and drew "0 / 2" over an Images block
  that was switched on with two prompts laid out under it. `sec_open()` takes
  the same tick descriptor the toolbox's `sec()` does — `all` for a take-all,
  or `id`/`on`/`disabled`/`tip` for a block's own switch — and the ids the
  screen already speaks (`dze-cb-image`, `dze-cb-price`, `dze-cb-reviews`) move
  with it, so the JavaScript that reads them needs to know nothing. One shape,
  two screens, one handler.
- **AN EMPTY ANSWER SAYS WHICH EMPTY IT IS.** The category panel says in a
  notice that a finished text is waiting in the queue, and printed "Nothing
  written yet" underneath it — the screen contradicting itself on one page.
  The empty after points at the button that brings the text here instead.
- **ONE TICK PER BLOCK — ON EVERY SCREEN THAT HAS BLOCKS.** The rule was kept
  on the product toolbox and never carried to the bulk screen beside it: seven
  text prompts, no way to take or drop the lot. "Pas de coche pour
  activer/désactiver tout en même temps. Je t'avais pourtant dit de le faire.
  Vérifie partout, ça doit être fait selon le même standard." `sec_open()`
  takes it now, and it wears `.dze-sec-all` — the SAME class, so the one
  handler in photos.js drives both screens and there is never a second one to
  keep in step. Only where there is something to take ALL of: over a block
  holding one checkbox it is a second control saying what the first already
  says, which is what was removed from the images and price blocks.
- **A SCREEN TAKEN OUT OF THE MENU MUST BE SHOWN BY WHATEVER TOOK IT OUT.**
  The product bulk screen's entry was removed whenever `DZE_Queue::owns_review()`
  — a host that never drew it — and the Content diagnostic's Products tab
  carried a `url`, so it was a DOOR out of the page to a screen with no home:
  "Products AI bulk > toujours caché, introuvable dans aucun menu. Products,
  dans Content diagnostic, redirige vers Products AI bulk. Démèles ce bordel."
  `DZE_Content::bulk_hosted()` is that one decision, in one place, and it
  answers three questions at once which must never disagree: the tab is a VIEW
  (no `url`, `render_page()` prints `bulk_body()`), every link goes to that tab
  (`bulk_url()`), and the menu entry is removed ONLY then — with nothing
  hosting it, the screen keeps its own entry rather than becoming unreachable.
  The body is ONE function printed by the tab and by its own page alike, handed
  the address of whoever is showing it so its own two tabs — Selected products,
  Done — stay where they were pressed. The old address still lands:
  `bulk_redirect()` decides and `maybe_send_to_tab()` does it on `admin_init`,
  split so the decision can be exercised.
- **A MENU BADGE MEANS "ACT ON ME", NEVER "HERE IS A NUMBER".** The diagnostic
  put its shortfall there — a red "1,205" for ever, on a menu looked at forty
  times a day, which is a bubble you learn not to see. The badge counts what
  waits for a PERSON: what has come back and wants a yes or a no, every store
  included. A figure that is merely large belongs on the tab it is about.
- **A TAB THAT IS A DIFF SAYS SO.** "Fixed" holds what the last reading listed
  and that no longer falls short, so the next reading empties it — "le compte
  Fixed revient constamment à 0" was it doing exactly what it is. It reads
  **Fixed since the reading**, and its empty state points at where the durable
  record lives. A name that implies a store, over a thing that is a diff, is a
  screen that lies once a day.
- **EVERY LIST OF THINGS WAITING FOR A DECISION IS ONE LIST.** Two menus for
  "what is waiting for me?" is two places to remember and two counts that
  disagree; the screen is **Content to review**, it lives under **Dazont Ecom** and not
  under Products (it holds categories, products and articles; a list of
  everything the plugin has written does not belong inside one of the things
  it writes, and its old address redirects so a bookmark still lands), and its
  menu badge counts every store, the product bulk screen included. The stores stay separate —
  each decision is taken where its own work is drawn — but the second screen
  takes its own entry out of the menu (`remove_submenu_page`, never a `null`
  parent, which is deprecated and prints a notice before our output) while
  the module that owns the question is on, and keeps it the moment that
  module is off: switching a module off must never hide a function that has
  nothing to do with it. `DZE_Queue::owns_review()` is that one decision, in
  one place, so it can be exercised.
- **A READER IS THE SAME PERSON FROM ONE PROMOTION TO THE NEXT.** Inside a
  promotion an email steps around what its neighbours showed; between
  promotions nothing did, and the shortlist is the same best-sellers every
  time — so the same products opened three campaigns running. `shown_recently()`
  reads the OTHER promotions' own emails (what the writing recorded, else what
  the body links to) inside a window, and both the plan pool and the material
  put those behind the fresh ones and MARK them — never remove them, or a shop
  of forty products has nothing left to sell. The nearest repetition is the
  worst one: this promotion's own outranks a past one's. The constraint is
  appended as a shop rule, never written into the owner's prompt.
- **A FIELD WRITTEN BY ONE PATH MUST BE READ BACK BY THE OTHER.** `shown` —
  which products an email actually put in — was stored by the writing and
  never returned by `emails_for()`, so `goods_of()` silently fell through to
  re-reading the body on every email, and an ordinary Save of the event
  dropped it. A key that only one half of the plugin knows about is a key that
  is not there.

- **A PAGE RELOAD IS NEVER THE ANSWER TO "DID THAT WORK?"** Applying from the
  toolbox reloaded the whole screen — throwing away a list of nine hundred
  rows, its sort, its page and its scroll, to answer a question about ONE of
  them: "La page s'est rechargée. Ça ne doit pas arriver." So a popup opened
  by another screen (`arm()` remembers it, in `OPENED_FOR`, outside `res`,
  which `reset()` empties) neither reloads nor lets closing reload: it shuts
  itself and fires `dze:applied`, and the screen that opened it answers for
  its own row. That row is then **judged again** — `ajax_judge()`, one object
  against its own criterion, with the same `fails()`/`measure()` the list used,
  so the row and the reading can never disagree. Fixed, it leaves the list and
  both tab counts follow it (each count is its own element for exactly that
  reason). Mended by HALF, it stays, says where it now stands, and its button
  is re-armed for what is left — a row that vanished on any apply would be a
  list that lies. `tools/js/diagnostic-fix.mjs` presses the whole gesture and
  asserts the page never navigated.
- **A LIST OF PROBLEMS SAYS HOW FAR OFF EACH ONE IS.** Naming the products
  and nothing else makes the reader open every one to learn what it needs —
  and how far off it is decides which to do first: "il faudrait afficher de
  cette façon très instinctive le diagnostic sur toutes les lignes". Every row
  carries `short_said()` — "4 of 5 photographs" — printed by the server, and
  the repair REWRITES THAT SAME ELEMENT rather than adding one beside it, or a
  row mended by half says two things at once. Only a rule with a figure gets a
  sentence: "is empty" has nothing to count towards, and "0 of 0 photographs"
  is worse than silence. Both directions are said as the rule means them — "at
  most 3" is a shortfall AT 3, so it needs 4; "more than 60" is too many at
  61, so it may have 60. **And the popup opened from that row says the SAME
  sentence**, from the same function: it used to lead with the criterion's own
  name — "Products · gallery photographs is less than 2/3/5 photographs" —
  which is what the shop asks of everything, not what this one product is
  holding.
- **A POPUP OPENED ON AN OBJECT OFFERS THE WAY TO THAT OBJECT.** The toolbox
  opens from three screens now, and from two of them the product is nowhere on
  the page — while part of the work belongs there: "j'ai ouvert la toolbox, et
  j'aimerais ajouter des images externes pour améliorer le contexte." A link
  in the head, in a new tab so nothing open here is lost, hidden when the
  server gave no address rather than pointing at "#".
- **A POPUP OPENED FOR A REASON IS ARMED FOR THAT REASON, AND NOTHING ELSE.**
  It remembers the ticks of the last run on the product screen, so "Make
  photographs…" from a diagnostic line opened with every text prompt ticked
  too — "très inconfortable", and one press away from rewriting a description
  nobody asked to touch. `arm()` clears every box first and ticks only what
  the criterion asks for. Two more rules from the same screen: a progress line
  ("Step 2 of 2 · 1s") belongs to the run that wrote it and is cleared when
  the popup changes product, or it describes work done to another product;
  and a section's count is what the run WILL DO — a checkbox that only changes
  HOW something runs carries `.dze-sec-opt` and is never counted, because
  "Keep the product's own photograph as the subject" made one photograph read
  "1 / 2".
- **A BUTTON DRAWN IN JAVASCRIPT NEEDS ITS POPUP PRINTED ON THAT SCREEN.**
  The toolbox draws "✎ prompt" wherever it opens, and `DZE_Prompts::
  print_assets()` was called for the product screen, the products list and the
  bulk screen — not for the diagnostic, where the toolbox now opens too. The
  button was there and the popup was not on the page at all: pressing it did
  nothing and said nothing. Any screen that loads the toolbox loads what the
  toolbox opens.
- **ONE IMAGE VIEWER, AND IT NEVER SHOWS THE WRONG PICTURE.** Thirteen screens
  open `admin/js/hzoom.js` — the product photographs, the review popup, the
  bulk screen, the paste box, the image lab, the explorer, the email pictures,
  the GMC panel, the restock list — so one fault in it is a fault on all of
  them. It set `src` on the visible `<img>` and moved the counter in the same
  breath: a browser keeps painting the OLD photograph until the new one has
  decoded, so the screen said "2 / 5" over picture 1 with nothing saying
  anything was happening — "l'image précédente reste à l'écran". The rules:
  the picture is loaded on a detached `Image()` FIRST and the visible one and
  the counter change together; a click that overtakes another wins by sequence
  number, never by answering last; a picture that cannot load says so instead
  of leaving the previous one up; neighbours are fetched once the current one
  is there, so walking back is instant. And Restock's own lightbox is gone:
  two viewers is two things to fix, and that one never got the fixes.
  `tools/js/zoom-gallery.mjs` serves the images SLOWLY on purpose — the fault
  only exists while one is in flight, which is why no PHP test and no
  `node --check` could ever see it.
- **A CONTROL'S COLUMN IS MEASURED, not assumed.** The prompt button reads
  "✎ prompt" — the same word as every prompt in the plugin, because a lone
  pencil is a symbol you have to learn — and its grid column was 30px, sized
  for the pencil alone, so the word ran under the menu beside it. Grid items
  default to a content-sized minimum, so give them `min-width: 0` and measure
  the boxes in the browser gate: no PHP test and no `node --check` can see a
  column overlap.

- **ONE OPTION, MANY WRITERS: the read happens INSIDE the lock.** Every email
  of every promotion lives in one row, and six places read it, changed a
  corner and wrote the whole thing back. Two of those overlapping — putting
  one email in Klaviyo while another is translated, which is an ordinary thing
  to do — both read the same "before" and the second write silently discards
  the first. `edit_copy()` is now the ONLY way it changes: `GET_LOCK` (never
  `add_option()` as a mutex — it checks then inserts, and two callers can both
  pass that), the object cache dropped, the option re-read under the lock, the
  change applied, the lock released. A database that will not give a lock is
  not a reason to refuse the work. The gate makes the race happen on purpose:
  the fake `$wpdb` rewrites the option at the moment the lock is taken, and
  both changes must survive.
- **EVERY FUNCTION OF A SCREEN EXISTS ON ONE ROW AND ON THE WHOLE LIST**, and
  the bulk one PRESSES the row's own path — never a second engine. Write, put
  in Klaviyo, translate, schedule: five controls in the bar, four of them the
  group form of a row button (planning has no row).
- **A SCREEN THAT REACTS TO ITS OWN WORK IS WATCHED, NOT WIRED.** The step bar
  was refreshed from the two functions that looked like "the places a row
  changes" — and the writing puts its words on the row through a third:
  "Generate them all > fait, ils sont tous là. Mais Put them in klaviyo n'est
  pas disponible." Hooking each writer is a list somebody has to keep, and the
  one forgotten is the bug. A `MutationObserver` on the list is the signal
  instead: anything that adds a row, removes one, redraws a state cell or
  flips a button says so BY DOING IT, and a function written next year needs
  to know nothing. The one exception is called out where it happens: a
  textarea's value is a property, not an attribute, and raises no mutation.
  **And the gate presses the real sequence.** The test that missed this one
  set the DOM up by hand and fired the refresh itself, which proves the
  refresh works and nothing about whether anything calls it. Press the button
  that does the work, then read the OTHER control.
- **A BAR OF CONTROLS SHOWS THE STATE OF THE WORK.** "La disponibilité des
  boutons doit être gérée en fonction de l'avancée." A step that is not yet
  possible is disabled AND says what is missing in its tooltip; a step already
  done renames itself with a `Re-` prefix (`Re-plan the campaign`,
  `Re-generate them all`, `Re-translate them all`) so nothing looks like work
  still to do. Two exceptions, both because the plugin already has a better
  word on the rows of that same screen: `Update them all in Klaviyo` and
  `Unschedule them all`. `steps()` reads the STATE OF THE SCREEN — the rows
  are drawn by the server and already say what they are — never a second
  account of what has happened, and every word of it lives in PHP.

- **A COUNT IS AN ANSWER TO A QUESTION, and the question is written down.**
  The census stores a fingerprint of each criterion's rule beside its count
  (`rule_stamp()`, and only the parts that decide an answer — a rename or a
  changed note is not a new question). Edit a rule after the reading and the
  screen says so: the rows are re-judged live by `split()`, the count is not,
  and the two disagreeing in silence is what made "is empty" and "is not
  empty" both look like they returned 2,106 products.
- **The plugin speaks WordPress and WooCommerce**, not its own words: "Post
  list", not "The list". Where core or WooCommerce already has a name for a
  thing, that is the name.
- **`php tools/test-review.php dazont-ecom` must pass.** Two halves of one
  promise, both broken once. NOTHING writes to the shop without being looked
  at: three automation tasks SHIPPED with "save without review" ticked, so a
  shop switching a task on got text written straight onto its categories
  having chosen nothing. They ship held for review, the box is still there to
  tick, and a shop that had it ticked by default is put back ONCE and the pass
  written down — overriding the shop's own answer on every admin load would be
  a setting nobody can keep. And WHAT WAS WRITTEN IS REMEMBERED: the queue's
  Clear must never delete an `applied` row. That row is the only record that a
  product was worked on, when, and that somebody said yes — wiping it is why
  nothing here could be checked after the fact. `done_map()` reads the most
  recent one per object, in ONE query for a whole page.
- **`php tools/check-lint.php dazont-ecom` must pass, and it must be run AFTER
  the last edit.** It lints every shipped file, which is not the same thing as
  linting the files that were touched. 4.296.0 went to both channels with a
  parse error in `class-modules.php` — an unescaped quote inside a
  single-quoted string — because the gates had been run before that last
  change. **A gate run before the last edit is not a gate.** Re-run the suite
  after every edit, including a one-line comment, and especially after an edit
  made while writing the release note.
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
- **`php tools/test-sources.php dazont-ecom` must pass.** What is said ABOUT
  the photographs sent with an image request — the one place a description
  full of straps and buckles is stopped from being drawn.
- **`php tools/test-updater.php dazont-ecom` must pass.** What the update
  check is allowed to say, and above all what it may not: "Up to date" is
  never the answer to a lookup that failed.
- **`php tools/test-category.php dazont-ecom`,
  `node tools/js/category-panel.mjs` and `php tools/test-transfer.php
  dazont-ecom` must pass.** The category panel rendered for real and its
  button row pressed in a browser; and a settings bundle read, refused and
  written, with no key in it.
- **`php tools/test-mesh.php dazont-ecom` and `node tools/js/mesh-linking.mjs`
  must pass.** The link graph against a fake shop — a builder page among them —
  and the Linking tab's buttons pressed in a real browser on both jQuery
  builds, proving the press OPENS a choice, that the ticks decide what
  travels, and that the page never moves.
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
- **THE BACKGROUND IS A PROPERTY OF THE PROMPT, and every prompt says which
  one it is shot on.** The scene was ONE answer for the whole shop —
  `default_scene()`, plus a "last scene used" remembered in the browser and
  shared between screens — attached to every prompt whatever it asked for. And
  the appended sources block tells the model, in capitals, that the scene image
  IS the surface, the background and the light of the final photograph. So a
  prompt asking for a customer's own snapshot was handed a studio backdrop and
  came back a white pack shot with the product floating in it: "Image ugc
  générée dans l'outil bulk. Invraisemblable. C'est à cause de tes réglages
  cachés ?!" It was. The scene is a field on the registry row now, beside
  "Writes to" and "Shape", stored by NAME (`DZE_Content::prompt_scene()` /
  `scene_index()`) — an index would move every prompt onto a different
  background the day the list is reordered, which is the silent failure. Three
  rules: a prompt written before the field existed carries no key and keeps the
  shop's default, so nothing changes for the pack shots already set up; an
  EMPTY key is an answer ("no scene") and is never overruled; and a name that
  no longer answers reads as no scene while the card says the scene is missing,
  rather than falling through to the first one. `shoot()` falls back to the
  PROMPT's scene, never to the shop's, and the menu on a row is a one-off for
  the run about to be launched.
- **WHAT IS REMEMBERED IS THE ORDER, NOT WHAT THE SCREEN FILLED IN AROUND IT.**
  The bulk row stored its destination and its scene in the browser exactly as
  they stood — including the ones the screen had derived from the prompt itself
  — and restored them marked as chosen by hand. So a destination nobody ever
  picked came back weeks later as a decision: "j'ai choisi un prompt pour bulk
  sur tous les produits et je me suis retrouvé sur le 2e produit avec une image
  principale refaite… et ça me demandait sur chaque produit que faire avec
  l'image principale." A value a screen derives is derived again every time it
  is drawn; only what somebody typed or ticked is worth remembering.
- **REFUSING IS NOT REMOVING.** Discard on the bulk screen called the REMOVE
  path: saying "not this photograph" took the product off the very screen it
  was being worked on. "Le bouton discard devrait refuser les changements et
  reset le status des produits comme si rien n'avait été généré." It throws
  away what was generated, files the refusal under Done, and leaves the product
  ON the list at "nothing generated yet" — Delete, one button along, is the
  other decision. The reset is ONE function (`resetRow`), the same one a new
  run uses on the lines it is about to redo, and the decision is split from the
  request (`DZE_Content::discard_products()`) so it can be exercised.
- **A SCREEN THAT LISTS OBJECTS OFFERS TO OPEN ONE.** The bulk screen's panel
  already held everything worth seeing — the product's photographs through the
  one viewer, the box for photographs pasted from outside, the reading of what
  it holds — and none of it was reachable: the button that opens the panel
  appeared only once something had been GENERATED. So choosing what to write
  for forty products meant opening forty products in other tabs: "ici sur cette
  page je manque d'une option pour visualiser en un clic le contenu actuel des
  produits." The button is on every row and wears the two words of what it
  opens on — **Look** for the product as it stands, **Review** once there is
  work waiting for a decision — and the panel adds the TEXT the product holds
  today, one folded line per field, in the same block shell as the generated
  ones so a single gesture opens both. Two rules with it: a panel holding
  nothing offers neither Accept nor Refuse (a control that cannot act is a
  control nobody trusts), and a panel built to look at is DRAWN AGAIN once a
  run has put something on the product, or it goes on saying the product holds
  nothing.
- **A NOTE IS FOR THE RUN IN FRONT OF YOU, NOT FOR EVER.** "Notes about this
  product" saved what was typed in it the moment you left the box and sent it
  with every image made for that product from then on. So "ne mets pas de ruban
  sur le tshirt ! répètes le même design !" went out on every shot of that shirt
  for the rest of its life, invisible on every screen but the one it was typed
  on — a hidden standing instruction by any other name, which is the one thing
  this plugin may not have. It travels with the request now (`shoot()` reads
  `note`, `note_lines()` takes it), the box opens empty, and nothing is stored;
  notes saved by earlier versions are no longer read and the key stays declared
  in `DZE_Cleanup` so it can be wiped. The per-VARIATION notes are untouched:
  "the olive one has a black zip" is a lasting fact about a colour, set on its
  own screen, and it was never what went wrong.
- **THE ANSWER TO SLOP IS NEVER ANOTHER APPENDED SENTENCE.** When a shot comes
  back with something invented on it, the fix is that the person's own words
  reach the model for THAT run — not a new line added to what the plugin
  appends. Every sentence added there is taken from the one beside it.
- **THE JOB SOMEBODY IS WATCHING IS THE JOB THAT MOVES.** A screen polling its
  own run called `DZE_Queue::work()`, which took the OLDEST job in the whole
  queue — so a run somebody was standing in front of could sit at "waiting for
  the writer… 33s" while every one of its polls stepped something else: "et
  puis c'est bugé, il ne se passe encore absolument rien." `work( int $only )`
  takes the job it is asked for; cron and the kick still take the queue in
  order. And `ajax_job()` answers `ahead` — how many runs are in front of this
  one — because a screen that has been saying "waiting" for half a minute must
  be able to say what it is waiting for.
- **A RUN SAYS WHERE IT IS, ON EVERY SCREEN.** The queue had been sending
  `step`, `total` and `progress` on every poll and the category panel printed
  none of them: it counted seconds. "Pourquoi ne pas faire comme sur les pages
  produit avec Step 1 of 2, une barre de progression et un compteur de temps ?
  On avait dit qu'on standardise." Same markup as the product popup
  (`.dze-cx-prog` + `.dze-cb-bar`), same words from PHP, and the pair under it
  is **Apply / Discard** on both hosts — WordPress's own Update is the
  acceptance where WordPress owns the editor.
- **THE RESULT GOES UNDER THE WORK THAT MADE IT.** The category panel put a
  "Before / after" block at the TOP of the screen — above the thing that
  produces it — and its after never filled: "pourquoi ne pas mettre le résultat
  de la génération en dessous ? Comme sur les générations sur page produit. Et
  ça ne fonctionne toujours pas l'avant après, je ne vois pas l'après ça ne
  charge pas." It is a FIELD ROW now, the same one every generated field in
  this plugin wears: the name, the first words of what came back, its figures,
  a **Current** button that brings back what the object holds today, the prompt
  behind it, and the editor with the new text inside. Two prompt buttons on one
  screen is right and is the product screen's own shape — the prompt you choose
  the work with, and the prompt behind the text that came back. And the row
  says WHICH empty it is when a run answers with nothing.
- **A GATE MUST WAIT FOR THE ANSWER, NOT FOR SOMETHING ALREADY THERE.** The
  bulk gate waited for the Look button to appear before reading the run's
  result — and that button is on every row from the start since the release
  before, so the wait returned instantly and every check after it read a screen
  still working. It went green on one jQuery build and red on the other, which
  is what a vacuous wait looks like. Wait for what the work CHANGES.
- **A MISSING WORD IS NOT WORTH KILLING A HANDLER FOR.** `sprintf()` in
  `category-content.js` called `.replace` straight on its argument, so a string
  the shop had not registered threw a TypeError and stopped every line after it
  — the same silent stop as `markPlaced()` reading `r.href`. It coerces now.
  And a browser harness copies the localized words KEY BY KEY: the one missing
  from it is the one that proves nothing.
- **THE LINK POOL ASKS WPML'S TABLE, LIKE EVERY OTHER READER.** "Post allemand
  vu dans les recommandations de lien. Bizarre." `page_index()` and
  `category_index()` narrowed by `wpml_element_language_details` — a FILTER, in
  a panel served by an AJAX action, where WPML's hooks are not loaded. It
  answered nothing, nothing fell through to "the shop's own language", and
  every translation was offered as a page to link to; then the whole pool was
  cached for six hours, so one unnarrowed read poisoned every category on the
  shop. Both read `DZE_Wpml::ids_in_language()` now — posts by `post_post`,
  pages by `post_page`, terms by `tax_product_cat` and their TERM TAXONOMY id —
  and each index is cached under a key carrying the language it was read in.
  This is the third time this trap has been paid for. **Any reading that must
  be right outside a page load asks the table.**
- **`node tools/js/content-bulk.mjs` must pass.** The product bulk screen had
  no browser gate at all, which is why both of the faults above lived there:
  each of them is an answer a control gives when it is PRESSED. It loads the
  screen as the plugin prints it (`tools/test-sources.php --dump-bulk`, markup
  AND the real `wp_localize_script` config), changes the prompt on a row and
  reads the destination and the scene back, presses Look on a product nothing
  has been generated for — reading back its photographs, its paste box and its
  text — and presses Discard for real, asserting what goes on the wire, that
  the product is STILL on the list, and that its row is back to waiting.
- **A MARK IS NOT A CHANGE — the translation module keeps its own register.**
  "Il suffit qu'une simple modification soit faite sur le produit ou sur la
  catégorie du produit, et le produit est de nouveau marqué Update French
  translation." WPML hashes the whole post into one signature — title, content,
  excerpt, tags, CATEGORIES, custom fields, the list of variation ids — and
  compares it on every save, so renaming a category flags every product in it.
  That is WPML working as designed and **nothing here may change how it works**:
  no filter on its signature, no touching WooCommerce Multilingual's triggers.
  Three catalogues retranslated end to end cost about forty-four dollars, so a
  wrong mark is worth a few cents and a patched WPML is worth a broken shop on
  the next update.
  What the module does instead is keep ITS OWN register — `_dze_tr_src` on the
  translation, one md5 of the source text per FIELD — and pay only for words
  that really moved. Nothing changed sends nothing and closes the mark; one
  field changed sends that field alone. Four rules it is gated on
  (`tools/test-translate.php`): the comparison is field by field, never one
  fingerprint for the whole set, or a changed title re-pays for fifteen hundred
  characters of description; the register claims only the fields a run actually
  wrote; the signature written back is **WPML's own**
  (`apply_filters( 'wpml_tm_element_md5', … )` on the ORIGINAL), because one of
  ours would drift from theirs on the next WPML release and the translation
  would never be flagged again; and a signature WPML cannot give — its
  translation-management hooks are not loaded in every request — writes
  NOTHING, since a mark left standing is a nuisance and a wrong signature is a
  translation nobody will ever be told about again.
  **A translation made elsewhere can be ADOPTED rather than paid for**: the
  source is recorded as it stands and the mark closed. That is a bet, and it is
  stated where it is offered — if the source really did move, that field stays
  stale until somebody edits the source again, and THAT time the register sees
  it. Ten thousand marks made through a spreadsheet in 2025 are cleared that
  way, for nothing.
- **A BUDGET THAT IS OFF IS NOT A GUARD, AND A PRESS THAT SPENDS SAYS WHAT IT
  WILL SPEND.** "J'ai dépensé hier 40$ en génération d'images... sur fal j'ai vu
  24 images générées pour le même produit le fsb patch." Nothing intervened
  because the only guard was the monthly budget and `over_budget()` is
  `$cap > 0` — unset, it stops nothing at all. And twenty-four is what the bulk
  screen asks for on its own: three prompt rows at ×4 attempts is twelve images
  per product, run twice. Two halves, and both are needed:
  - **CEILINGS**, not budgets: at most ten images of ONE product and sixty for
    the whole shop, per CLOCK HOUR — the only window that can be said in one
    sentence and needs no list kept anywhere. They are counted on the ATTEMPT,
    never on what came back (a run failing in a loop reaches the provider just
    as often as one succeeding), and asked at the ONE funnel every image passes
    through, `fal_generate()`, which is why it is told WHICH product it is for.
    Nought means no ceiling, never a ceiling of nought.
  - **EVERY FIGURE WAS ALREADY ON THE SCREEN** — the rows, the attempts, the
    ticked products, the price per image — and none of them had ever been
    multiplied together: the button read "Generate (30)", a count of products
    that reads like a count of the work. Both screens that spend say it before
    the press, and NAME the ceiling when the order is over it, rather than
    refusing halfway through.
  `php tools/test-spend.php dazont-ecom` must pass, and the bill is asserted in
  a browser (`node tools/js/content-bulk.mjs`) because only a browser can
  multiply what is on the page.
- **A FIGURE THAT IS STORED IS NOT A FIGURE THAT IS ANSWERED.** "On a un
  registre des appels IA filtrable par modèle ?" Every call had carried its
  model for months — `_days` holds calls, tokens and cost per model, kept
  eighteen months — and the only way to read it was to HOVER thirty day-bars
  one at a time and add up. The trace beside it shows the model of the last
  twelve calls, which is a debugging tool and not an account of a month, and
  the month totals were per PROVIDER, so a cheap model and an expensive one on
  the same key were one line. `model_report()`/`render_models()` ask the
  question instead: one line per model, cost, share, calls, tokens — a READING
  of what was already stored, so it answers for every month the shop still
  holds and nothing had to be recorded first. Three rules it is gated on: a
  model billed per picture shows a dash and never a nought in the token column
  (a nought reads as a model that answered nothing); the per-model lines must
  add up to the month, with a named row for what predates the split; and an
  empty table says WHICH empty it is — "recorded before this breakdown existed",
  with the amount, or "nothing spent". The gate DRAWS THE WHOLE SCREEN
  (`render_graph()` into a buffer) rather than calling the table, because
  calling it proves the table works and nothing about whether the screen asks
  for it. The trace stays twelve rows and unfiltered: filtering twelve lines
  answers nothing, and keeping more of them is weight in the database for a
  question the per-model table already answers.
- **WHAT MAY BE TRANSLATED IS WPML'S ANSWER, NEVER OURS — and the module has a
  desk of its own.** "Module Product translation est mauvais. Ca devrait être
  WPML Translation Module. Et il devra être affiché dans le menu du plugin...
  une liste d'attente un peu comme wpml pour relecture du contenu traduit,
  avant automatisation... Idem pour les articles de blog, pages, catégories et
  toutes les taxonomies attributs compris."
  - **The scope is read from WPML's own settings row**, not from a list of
    ours and not through `wpml_is_translated_post_type` — a filter only answers
    where its plugin's hooks are loaded, and this module reads in AJAX and in
    cron. That trap has now been paid for four times. `custom_posts_sync_option`
    and `taxonomies_sync_option` say it in every request; the filter is the
    fallback for a type WPML has never been asked about, and a `null` from it
    is never read as "no". Offering a type WPML will not link writes an orphan
    in another language with nothing anywhere saying why.
  - **There is ONE thing here: an object.** A post of a translatable type or a
    term of a translatable taxonomy — a product attribute is a taxonomy like
    any other. Every function takes one, and the product path is that same path
    with `post`/`product` in it: `targets()`, `read()`, `stale()`, `settle()`
    and `adopt()` are wrappers over the object versions, never a second reading
    beside them.
  - **A TERM IS NOT SIGNED WITH A SIGNATURE NOBODY COMPUTED.**
    `wpml_tm_element_md5` signs a POST; WPML computes a term's its own way.
    `mark_term_done()` clears status and needs_update and leaves the md5 exactly
    as WPML wrote it — a made-up one is a translation nobody is ever told about
    again, which is the failure the post version already refuses by name.
  - **A FIELD WPML COPIES IS NEVER WRITTEN HERE.** Mode 1 and 3 mean the next
    custom-field sync puts the original's value straight back over it: the words
    are lost and no screen says so.
  - **What waits lives ON THE SOURCE OBJECT**, one meta key, exactly as the
    product bulk screen keeps what it generated on the product. Not in
    `DZE_Queue`: that store is one document per row with one accept, and a
    translation is an object times N languages times M fields each with its own
    yes or no. Bending a table that works to carry a different question is how
    two screens start disagreeing.
  - **THE REGISTER IS WRITTEN AGAINST WHAT WAS SENT**, never against the source
    as it stands when somebody gets round to accepting: a batch read a week
    later would otherwise claim a field is current when the words have moved
    since, and that field never gets translated again. The words that were sent
    are kept beside the answer for exactly that.
  - **A language left undecided keeps the object on the list.** Only a clean
    sweep clears it — a row that vanished on a half decision would be a list
    that lies.
  `php tools/test-translate.php dazont-ecom` and
  `node tools/js/translate-screen.mjs` must pass: the second presses the three
  controls in a real browser on both jQuery builds and reads back what went on
  the wire — the object the request is about, the languages that were ticked
  and no others, the field that was ticked and not the one that was not.
- **A FIGURE ON A BUTTON AND THE ROWS UNDER IT ANSWER THE SAME QUESTION.**
  "Ici Discard affiche (1) et Apply (1) seulement je ne vois rien sur ces
  produits à accepter ou refuser." The bar counted every ticked product that
  had a BUCKET — and a bucket is made the moment anything touches a line,
  opening Look among them — while the row's own button required real content.
  So three products holding nothing announced work to decide on, with not one
  Review button on the screen. One test, `holding()`, answers for both.
  **And a tooltip belongs in EVERY state**: this one was emptied the instant
  the button became usable, which is exactly when somebody hovers it — "quand
  j'y laisse la souris, aucun texte ne s'affiche alors que delete lui c'est
  très clair". A count, a word and a hover that says what will happen, on every
  control that acts.
  **The refusal of generated content wears ONE word across the plugin**, and it
  is **Cancel**: "Discard (1)" beside "Delete (3)" read as two deletions.
- **THE WRITE RECORDS ITSELF, AND THERE IS ONE REGISTER.** "Ici je ne vois que
  les produits modifiés par l'écran bulk. Qu'en est-il des produits modifiés
  individuellement ? Ce serait bien d'avoir un registre commun." Two faults in
  one report.
  - The register was written by the **JavaScript of four screens**, so
    everything written anywhere else — the fast main-image lane, the reframe
    bench, the variation images — happened and left no trace at all. Hooking
    each writer is a list somebody has to keep and the one forgotten is always
    the bug: a text lands on a product in `apply_value()` and nowhere else, a
    photograph is placed in `attach_file()` and nowhere else, so THOSE are
    where it is written down. A path built next year needs to know nothing.
    The screens stopped claiming counts at the same time — left in both places,
    every run is counted twice.
  - Because each write now counts itself, the row's figures **accumulate**
    instead of being replaced by whatever the last call claimed. The row says
    what this plugin has written to that product and when it last did; a
    decision (accept, refuse) stamps it without inventing figures of its own,
    and a refusal keeps what was written beside it — both facts are true.
  - **One register, and it is a READER.** Products live in the content log,
    categories and articles in DZE_Queue's applied rows, translations in the
    translation log; `DZE_Content::register()` merges them newest first and
    each module goes on owning its own record. A second store copying them
    would be two accounts of one thing, and two accounts of one thing disagree.
    A module switched off contributes nothing rather than erroring, and the
    tab's figure counts the whole register — counting only the products, the
    badge would disagree with its own screen every day.
- **A LIST OF WHAT WAS DONE IS A LIST YOU CAN LOOK AT.** The Done tab named
  products and offered no way to see them — "j'aimerai la fonction Look comme
  sur la page Selected products, pour voir le résultat actuel sans recharger
  différentes pages" — so checking a description meant opening each product in
  another tab. It is the SAME button and the same panel, and the handler reads
  the id from the row it sits on (`closest('[data-id]')`) rather than from
  `.dze-cb-row`, which is what marks a product as selectable and is deliberately
  not on a log line.
- **`php tools/test-translate.php dazont-ecom` must pass.**
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
  **The problem list is GROUPED by the condition that placed each object**, and
  the heading is read from `band_hit()` — the same answer `want_for()` uses —
  so a section can never disagree with the figure a product was judged by.
  **The image lab is never a destination.** It is an experiment against fal.ai,
  finished and standing on its own; `tool_for()` returns nothing for a
  photograph criterion, and the per-row Open button is absent there because
  the row's own link already goes where that work is done.
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
- **`node tools/js/zoom-gallery.mjs` must pass.** It walks the one image
  viewer in a real browser with the pictures served slowly, which is the only
  condition under which its faults exist.
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
