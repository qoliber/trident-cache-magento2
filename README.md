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
