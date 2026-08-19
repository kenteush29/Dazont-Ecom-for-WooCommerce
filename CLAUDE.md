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
  `DZE_FAL_API_KEY`, `DZE_GMC_SERVICE_ACCOUNT`. Each key is only ever sent to
  its own provider.
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
- WPML compatibility everywhere.
- **Both channels are released in the same pass, by default.** Fixes AND
  additions to a module that already exists — a new control on an existing
  screen, a UI correction, a wording change — go straight to `Live-plugin`
  without asking, alongside `Plugin-development`. DEV-only is the exception,
  for work the owner has called unfinished or experimental, or for a module
  that does not exist yet; say so and wait for his word in that case.

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

## Release pipeline

- Version bump in `dazont-ecom/dazont-ecom.php` (header + `DZE_VERSION`).
- Push working branch + `Plugin-development`, then dispatch
  `release-dazont-dev.yml` (workflow_dispatch; tag pushes are blocked by the
  git proxy). Stable: `release-dazont.yml` on `Live-plugin`.
- Build the zip (`dazont-ecom/` folder) and send it to the owner each version.
