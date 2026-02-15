# Changelog

All notable changes to Qoliber_TridentCache will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
