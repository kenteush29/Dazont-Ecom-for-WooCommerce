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
  box (`DZE_Modules::render_hub`): one button per enabled module opening a
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
- New/unfinished features ship to the DEV channel only (`Plugin-development`
  branch → prerelease); `Live-plugin` (stable) only with explicit approval.

## Traps learned the hard way

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
