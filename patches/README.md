# Magento 2.4.9 filesystem tag-index compatibility patch

This locally maintained patch targets the released Magento Open Source 2.4.9
`magento/framework` 103.0.9 filesystem tag adapter. It is not an Adobe-issued
patch. It preserves native tag matching, including the `MAGE` scope, and does
not change Trident's invalidation API or require a different cache backend.

The measured Mage-OS 3.6.0 Composer installation uses `mage-os/framework` 3.6.0
(replacing `magento/framework` 103.0.9), reports Magento base 2.4.9 and has the
identical unmodified target bytes. The three checksums below still determine
applicability. Adobe Commerce and other Mage-OS versions remain unqualified.

## Exact applicability

The target is `Cache/Frontend/Adapter/SymfonyAdapters/FilesystemTagAdapter.php`
relative to the installed framework directory:

| Installation | Framework directory |
| --- | --- |
| Official Magento source checkout | `lib/internal/Magento/Framework` |
| Magento Composer package installation | `vendor/magento/framework` |
| Measured Mage-OS 3.6.0 Composer installation | `vendor/mage-os/framework` |

The source basis is Magento commit
`755e34dd689021c5165db9d35ecff74f7dc51527`.

| File | SHA-256 |
| --- | --- |
| Unmodified target, 7,605 bytes | `6752f2178ff50f1cacf8767c1448ebf11e033f8a09dace63aab99f25cbd596a3` |
| Patched target | `f8fdb9626ea97fb579fca5c124d1665d7292e98bb180a1794b132af75b863aa1` |
| `magento-2.4.9-filesystem-tag-index.patch` | `cb8150e2adc416389f0b2df0b1a1cf0ac3d3f25641cf78b6a700dfcb1ce1aba0` |

Do not apply this patch when the original checksum differs. Review the exact
installed release or existing patches first. The version number alone is not
an applicability check.

## What changes

Native tag-index updates previously read an index, modified its IDs and locked
only the replacement write. Concurrent updates could overwrite each other's
membership. A missing `MAGE` membership can exclude a cached block from native
scoped cleaning even when its product and `BLOCK_HTML` memberships remain.

The patch holds a shared lock for each complete public index query and an
exclusive lock for each complete `onSave`, `onRemove` and `clearAllIndices`
operation. Private unlocked query helpers avoid nested locking. Opening or
acquiring the lock fails closed; the operation is never retried without a lock.
Handles are released in `finally`, and cleanup cannot replace an exception
thrown by the original operation.

All frontend pools sharing the native tag directory use the same persistent
lock: `<constructor tagDirectory>/.tag-index.lock`, normally
`var/cache/symfony/.tag-index.lock`. It is outside `tags/*`, so native index
clearing cannot unlink its identity. Native Symfony data clearing operates on
the separate data namespace. Do not remove or replace the lock file while any
cache process is running. The cache filesystem must support `flock` between
all processes sharing these indexes.

The cache item format, tag normalization, matching semantics, expiry and data
pool are unchanged. `deleteByIds` keeps its native behavior. Data publication
and tag-index publication remain separate operations; matching-ID lookup and
data deletion also remain separate. This patch does not make an entire
save/clean operation atomic.

## Apply manually

Use the file-system owner and stop all web, health-probe, cron, queue and CLI
processes that can access this cache. Every writer sharing the tag directory
must use the patched code before traffic resumes; a rolling mixture of patched
and unpatched writers is insufficient. This patches the native class before
bootstrap, including the configuration-cache path that runs before module DI
interception is available.

Adobe documents the plain command-line patch method in
[Apply patches](https://experienceleague.adobe.com/en/docs/commerce-operations/upgrade-guide/patches/apply).
No Composer patch plugin, dependency installation or automatic patch execution
is supplied by this module.

From the Magento or Mage-OS project root, select the framework directory from
the table and set the absolute path to this distributed patch asset. For the
measured Mage-OS 3.6.0 installation, use `FRAMEWORK_DIR=vendor/mage-os/framework`;
the example below retains the Magento source-checkout default. The patch asset
path and all checksum, apply and rollback commands remain the same.

```sh
set -eu
FRAMEWORK_DIR=lib/internal/Magento/Framework
PATCH_FILE="$PWD/vendor/qoliber/trident-cache-magento2/patches/magento-2.4.9-filesystem-tag-index.patch"
TARGET="$FRAMEWORK_DIR/Cache/Frontend/Adapter/SymfonyAdapters/FilesystemTagAdapter.php"

test "$(sha256sum "$TARGET" | cut -d ' ' -f 1)" = 6752f2178ff50f1cacf8767c1448ebf11e033f8a09dace63aab99f25cbd596a3
test "$(sha256sum "$PATCH_FILE" | cut -d ' ' -f 1)" = cb8150e2adc416389f0b2df0b1a1cf0ac3d3f25641cf78b6a700dfcb1ce1aba0
patch --batch --forward --dry-run -p1 -d "$FRAMEWORK_DIR" < "$PATCH_FILE"
patch --batch --forward -p1 -d "$FRAMEWORK_DIR" < "$PATCH_FILE"
test "$(sha256sum "$TARGET" | cut -d ' ' -f 1)" = f8fdb9626ea97fb579fca5c124d1665d7292e98bb180a1794b132af75b863aa1
```

Keep a record of this patch and its checksums. Recheck applicability after a
Magento upgrade or Composer reinstall, which may replace the patched class.
Restart PHP workers and other long-lived processes so they load the new code.

### Existing missing memberships

The patch does not reconstruct already missing tag memberships. Repeated
tag-scoped `cache:clean` or `cache:flush` is not a guaranteed repair: native
`TagScope` can still exclude an old item absent from the `MAGE` index. Before
reopening traffic, use the deployment's approved complete filesystem-cache
rebuild procedure while all cache users are stopped. This module does not run
such a repair automatically. Do not delete the shared lock during an online
cache cleanup.

## Roll back

With all cache users stopped, use the same variables. This is the local managed
reverse-patch procedure, not a claim that Adobe ships this patch or rollback:

```sh
set -eu
test "$(sha256sum "$TARGET" | cut -d ' ' -f 1)" = f8fdb9626ea97fb579fca5c124d1665d7292e98bb180a1794b132af75b863aa1
patch --batch --reverse --dry-run -p1 -d "$FRAMEWORK_DIR" < "$PATCH_FILE"
patch --batch --reverse -p1 -d "$FRAMEWORK_DIR" < "$PATCH_FILE"
test "$(sha256sum "$TARGET" | cut -d ' ' -f 1)" = 6752f2178ff50f1cacf8767c1448ebf11e033f8a09dace63aab99f25cbd596a3
```

Restart the affected processes after rollback. The original filesystem
tag-index concurrency behavior returns; rollback does not repair existing
cache state.
