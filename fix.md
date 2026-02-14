# Qoliber_TridentCache — Open Issues

## Duplicate purge calls on every entity save

Both `CacheTypePlugin` and `FlushCacheByTagsObserver` fire on the same native Magento flow:

```
AbstractModel::afterSave()
  → cleanModelCache()
    → dispatches 'clean_cache_by_tags'        ← FlushCacheByTagsObserver fires
    → calls PageCache\Type::clean($tags)      ← CacheTypePlugin fires
```

Every save sends **two** identical purge requests to Trident. Pick one:

- **Keep only CacheTypePlugin** — catches everything at the cache layer, including cron and programmatic flushes
- **Keep only FlushCacheByTagsObserver** — more explicit, uses TagResolver, but might miss direct `Type::clean()` calls

## PurgeStrategy optimization removed

Entity-specific observers were removed (redundant with native flow). The `PurgeStrategy` excluded category tags when only detail attributes changed on a product. If this optimization matters for performance, move the logic into `FlushCacheByTagsObserver` with an `instanceof Product` check.
