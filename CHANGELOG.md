# Changelog

All notable changes to Qoliber_TridentCache will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-03-06

### Added

- **Cached Pages admin page** — Paginated grid of all cached URLs with host, method, size, TTL, age, hits, and tags. Accessible via System > Trident Cache > Cached Pages.
- **Cache Tags admin page** — Paginated list of all cache tags with entry counts. Accessible via System > Trident Cache > Cache Tags.
- **Tag filtering on entries** — Filter cached entries by tag via the entries page filter form.
- **Tag prefix filtering** — Filter cache tags by name prefix on the tags page.
- **Per-entry purge** — AJAX purge button on each cache entry row for targeted invalidation.
- **Per-tag purge** — AJAX purge button on each tag row to purge all entries with that tag.
- **Clickable tag badges** — Tag badges on entries link to entries filtered by that tag.
- **"View Entries" on tags** — Link from each tag to the entries page filtered by that tag.
- **Top URLs on stats page** — Top 10 URLs by request count table on the Cache Statistics page.
- **Sorting** — Sort entries by age, size, hits, or TTL. Sort tags by count or name.
- **TridentClient API methods** — Added `getEntries()`, `getTags()`, `getTopUrls()`, `purgeUrl()`.

## [1.1.0] - 2026-03-01

### Added

- **Configurable TTL, grace period, and static asset TTL** via admin system configuration.
- **ESI (Edge Side Includes) support** — Enable ESI processing with configurable max nesting depth. Adds `Surrogate-Control` header when enabled.
- **ConfigTypePlugin** — Maps Trident cache type (3) to Varnish (2) so core Magento FPC plugins activate without patching core code.
- **Health indicator on Cache Management page** — Green/orange status dot with Trident version and uptime display.
- **Unit and integration test suite** — PHPUnit 10.5 tests for TridentClient, Config, all plugins, observers, and PurgeStrategy.

### Changed

- **ResponsePlugin rewritten** — Now uses admin-configured TTL for `s-maxage` and grace period for `stale-while-revalidate` instead of copying `max-age`.
- **Config.php** — Removed `PageCacheConfig` dependency, reads caching application type directly from `ScopeConfig`.

## [1.0.1] - 2026-02-15

### Fixed

- **`purgePattern()` wrong API endpoint** — `TridentClient::purgePattern()` was calling `/admin/purge/pattern` which does not exist. Fixed to use the correct endpoint `/admin/purge/urls`.
- **Cache tags reference table** — The admin purge page showed incorrect tag patterns (`product-{id}`, `category-{id}`) instead of Magento's actual cache tags (`cat_p_{id}`, `cat_c_{id}`, `cms_p_{id}`, `cms_b_{id}`).
- **PurgeAll controller redirect** — Changed from hardcoded redirect path to `setRefererOrBaseUrl()` so users return to the page they came from.
- **Null safety in CacheBlockPlugin** — Added null check on `getButtonList()` to prevent errors when button list is not available.

### Added

- **Cache status bar on Cache Management page** — Displays Trident cache statistics (entries, memory, hit ratio, hits, misses, purge mode) directly on Magento's System > Cache Management page.
- **"Purge Trident Cache" button on Cache Management page** — Adds a purge button to Magento's native cache management page with ACL permission check and confirmation dialog.
- **`CacheTypePlugin` tag-based purge** — Intercepts programmatic `PageCache\Type::clean(tags)` calls that bypass the `clean_cache_by_tags` event (e.g. from third-party extensions). Filters out Magento-internal `FPC` tag. This covers an invalidation path that even Magento's own Varnish module does not handle.
- **`PurgeStrategy` for smart tag filtering** — Optional optimization to filter out category listing tags (`cat_c_p_{id}`) for product saves where only detail-level attributes changed. Currently disabled, to be enabled via admin config after testing.

### Changed

- **`CacheTypePlugin` cleaned up** — Removed logger dependency, added proper PHPDoc with FQDN types, added `FPC` tag filtering to prevent sending Magento-internal tags to Trident.
- **`FlushCacheByTagsObserver` uses `PurgeStrategy`** — Tag filtering is now applied before sending purge requests to Trident.

## [1.0.0] - 2026-02-10

### Added

- Initial release of Qoliber_TridentCache Magento 2 module.
- Full Page Cache integration with Trident cache server.
- Tag-based cache invalidation via `FlushCacheByTagsObserver` using Magento's native `Tag\Resolver`.
- Full cache flush via `CacheFlushObserver` on admin cache flush events.
- `CacheTypePlugin` intercepting `PageCache\Type::clean()` for full flush.
- `ResponsePlugin` ensuring `s-maxage` header on cacheable responses.
- `ApplicationPlugin` adding Trident option to cache application dropdown.
- Admin panel with cache statistics dashboard and manual purge controls.
- ACL permissions for cache purge and statistics access.
- Configurable API URL and token via Magento admin (Stores > Configuration > System > FPC).
- Sensitive config handling (API token marked as sensitive/environment).
