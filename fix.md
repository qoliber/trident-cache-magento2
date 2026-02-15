# Qoliber_TridentCache — Open Issues

## PurgeStrategy (disabled)

`PurgeStrategy::filterTags()` filters out category listing tags (`cat_c_p_{id}`) for product
saves where only detail-level attributes changed. This avoids unnecessary category page
invalidation when e.g. only the product description was updated.

Currently disabled (`ENABLED = false`). TODO: wire to admin config flag and test with
anchored categories, flat catalog, and ElasticSuite before enabling.

## Design Notes

### Duplicate purge on entity save (accepted)

On entity save, both `FlushCacheByTagsObserver` (via `clean_cache_by_tags` event) and
`CacheTypePlugin` (via `Type::clean()` plugin) fire tag-based purge. This is intentional:

- `FlushCacheByTagsObserver` — primary path, uses `Tag\Resolver` + `PurgeStrategy`
- `CacheTypePlugin` — safety net, catches programmatic `Type::clean(tags)` calls
  that bypass the `clean_cache_by_tags` event (custom modules, third-party extensions)

The duplicate on entity save is harmless (idempotent, local HTTP call to Trident API).
This gives us MORE coverage than Magento's own Varnish integration, which only uses events.
