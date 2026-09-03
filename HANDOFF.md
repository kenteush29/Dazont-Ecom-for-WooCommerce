# Handoff

Two Claude sessions work on this plugin and cannot message each other: one
codes (GitHub, no server), one has real server access (no `git push`). This
file is the bridge between them — read it, do what it asks, report back to
the owner in chat. **Always overwritten, never appended to** — if a request
below is done, it is gone from here, not crossed out.

## Open request

Run this on the real shop (either `kula-tactical.com` or its copy) and paste
the **whole** output back to the owner:

```
wp eval-file tools/on-site/read-shop.php
```

No WP-CLI? From the WordPress root: `php tools/on-site/read-shop.php` — it
finds `wp-load.php` on its own from wherever it is run. It is read-only: it
runs no write of any kind, safe on the live shop.

This answers an open question about `dazont-ecom/includes/class-money.php`
and `class-sales.php`: the shop's Revenue figures read $1,625 per average
order line on a catalogue that sells at $15–$77, in a single currency with no
mix — so it is not a rate. The script's second section prints the 20 biggest
order lines with their date and their currency FIELD exactly as stored
(including blank, which WooCommerce treats as the shop's own currency with no
conversion). That is what settles it.

## Nothing else is pending right now

If this file says only that when you read it, there is no outstanding
request — check back after the next commit that touches money or currency.
