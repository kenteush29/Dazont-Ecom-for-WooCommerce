# Handoff

Two Claude sessions work on this plugin and cannot message each other: one
codes (GitHub, no server), one has real server access (no `git push`). This
file is one half of the bridge — read it, do what it asks, write the answer
where the other half is. **Always overwritten, never appended to**: a request
that is done is gone from here, not crossed out.

## The two directions

- **Coding session → server session**: this file, on `Plugin-development`.
- **Server session → coding session**: one Artifact, at a fixed address,
  **rewritten in place** at every report (never a new one — a new URL is a
  report the other side will not find):

  https://claude.ai/code/artifact/a8cedd81-d8e6-49d4-8b61-b31452079a77

Each side writes where it is allowed to write. Neither has to be online at the
same time as the other.

## What the server session should include in every report

1. **What was run**, verbatim, and from where.
2. **Raw output**, not a summary of it — a figure retyped is a figure that can
   be retyped wrong.
3. **What the shop actually is**: WordPress, WooCommerce and plugin versions,
   HPOS on or off (WooCommerce's own answer — `OrderUtil::custom_orders_
   table_usage_is_enabled()`, never the existence of `wc_orders`), and whether
   this was the live shop or the copy.
4. **Anything that disagrees with what the code assumes.** The last report
   found 751 rows in `wc_order_product_lookup` with no order behind them —
   84% of the revenue the screen was reporting. Nothing in the plugin had ever
   suspected it. That is the kind of finding this bridge exists for.

## Settled, so nobody reopens it

**The plugin does not show money, and must not start again.** It read
`wc_order_product_lookup`, grouped by the order's currency, converted at the
shop's own rates — all correct — and still put $6,792,487 against 23 units at
$76.90, because that table holds hundreds of rows whose order does not exist.
`DZE_Money`, `DZE_Sales` and `test-money.php` are deleted. Units sold are what
the screens show. If money is ever wanted, it comes from WooCommerce's own
reports, which own that data and can repair it.

The orphan rows themselves are still in the database — WooCommerce Analytics
goes on counting them. **Regenerate order stats** (WooCommerce → Status →
Tools) is what clears them at the source; nobody has run it yet, and it is the
owner's call, not ours.

## Open request

Nothing is pending right now.

When there is something to run, it goes here — and the read-only script beside
it is kept current for it:

```
wp eval-file tools/on-site/read-shop.php
```

No WP-CLI? From the WordPress root: `php tools/on-site/read-shop.php` — it
finds `wp-load.php` on its own. It writes nothing, ever: safe on the live shop.
It reports the orphan order lines permanently, so a fresh import that brings
more of them is visible on the next run.
