# Qoliber_TridentCache — Magento 2 module for the Trident HTTP cache

Full-page caching for Magento 2.4.8–2.4.9 behind [Trident](https://trident-cache.com):
cache tags on every page, purges recorded in a transactional outbox and
delivered only on Trident's acknowledgement (retried by cron), several Trident
instances from `app/etc/env.php`, and the Trident admin screens under
**System → Trident Cache**. Built on [`qoliber/trident-php`](https://github.com/qoliber/trident-php).

```bash
composer require qoliber/trident-cache-magento2
```

**Magento 2.4.9 with native File caching:** apply the exact
[filesystem tag-index compatibility patch](patches/README.md) before continuing.
The unpatched native adapter can lose tag membership and leave old product
prices cached after a successful clean. The patch is managed manually; installing
this module does not apply it. Existing installations also need the documented
offline cache rebuild and restart of all cache users. Check the target checksum;
do not apply this patch to Magento 2.4.8 or a different native adapter.

**Measured Mage-OS 3.6.0/native File pairing:** `mage-os/framework` 3.6.0,
Magento base 2.4.9, uses `vendor/mage-os/framework` and the same checksum-verified
patch. Select that path in the linked instructions. Adobe Commerce and other
Mage-OS versions remain unqualified.

```bash
bin/magento module:enable Qoliber_TridentCache
bin/magento setup:upgrade
```

Configuration, upgrade notes and the changelog: see `CHANGELOG.md`.

## Cache tag limit

Trident does not cache a response that carries more tags than its
`[cache.tags] max_tags_per_entry` (200 by default): the page is answered
`X-Cache: PASS` with the reason "cache tag count limit" on every request. A
category or search page listing many products gets there, because
`X-Magento-Tags` carries one `cat_p_<id>` per listed product.

The module therefore bounds the header to **Max Cache Tags per Response**
(Stores → Configuration → Advanced → System → Full Page Cache → Trident
Configuration; default 200). A response within the limit is not touched. Over
the limit:

- the tags that are not listed products are kept first (the page's own entity,
  its category, CMS blocks), then as many `cat_p_<id>` tags as fit, in the
  order Magento emitted them;
- the dropped tags are replaced by an overflow tag: `trident_overflow_cat_p`
  when a `cat_p_<id>` tag was dropped, `trident_overflow` when any other tag
  was;
- every purge by tag the module sends also names the overflow tag of the tags
  it carries. A listing that dropped `cat_p_42` is purged when **any** product
  changes — more often than strictly needed, but never missed.

Keep the setting equal to Trident's `max_tags_per_entry`; if you raise one,
raise the other. `0` switches the cap off (the header is passed through
whatever its size, as before 1.8). Purges sent from outside the module — your
own scripts calling Trident's purge API with a Magento tag — do not know about
the overflow tags: add `trident_overflow_cat_p` to a purge of `cat_p_<id>`
tags, and `trident_overflow` to a purge of any other tag. A purge by tag
pattern cannot match a dropped tag either.

## Restarts and several nodes

Every Trident restart starts with an empty cache (an upgrade, a rollback, a
crash or a plain restart: Trident 1.8 exports its cache but does not restore
it). Restart one Trident node at a time and size the origin for one cold
node's share of the traffic, or refill the busiest pages with the warmer
before the node takes traffic. Purges are per node: list every Trident
instance in `app/etc/env.php`, so the outbox delivers each purge to each
node.

## Versioning

Versions follow Trident: this module 1.8.x works with Trident 1.8. MAJOR.MINOR moves
with the engine (every Trident X.Y.0 release is also a release of this package,
changed or not); the PATCH number is this package's own. The
admin screens warn when a connected Trident runs another release line.

## This repository is a mirror

The module is developed in the Trident repository and published here
automatically; every commit after the move is a "Sync from trident-cache@…"
snapshot. **Please open issues here** — pull requests against this repository
cannot be merged, because the next sync would overwrite them. Releases are the
tags of this repository.
