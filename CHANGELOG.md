# Changelog

All notable changes to Qoliber_TridentCache will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

From v1.4.0 the module version tracks the Trident engine version it integrates
with (e.g. module 1.4.0 ↔ Trident 1.4.0).

## [1.8.0] — unreleased (pairs with Trident 1.8.0)

<!-- At tag time: replace "unreleased" with the release date, tag v1.8.0 at
     that commit, and point the engine submodule at it (F15). -->

### Upgrading from 1.5.x

Run `bin/magento setup:upgrade` (the outbox table from X02) and
`bin/magento setup:di:compile` in production mode. Code that extends or
constructs the module's classes directly (rather than through Magento's
object manager) must follow these changes to public API released in 1.5.2:

- `Model\TridentClient::__construct()` takes
  `(Qoliber\Trident\Delivery\Transport $transport, LoggerInterface $logger, Config $config, ?InstanceSelection $selection = null)`
  — it was `(Curl $curl, LoggerInterface $logger, Config $config)`. Every
  public method of 1.5.2 keeps its signature and return type.
- `Model\TridentClient::denoiserQueryPin($param, $class, $host, $pathPrefix)`
  (was `($param, ?$pathPrefix)`) and `denoiserPathPin($host, $pathPrefix,
  $status)` take the class (`noise`|`signal`) or status (`dead`|`alive`) the
  engine requires and the scope's real host; `denoiserQueryUnpin()` takes the
  host as a new last parameter (default `*`). A pin without them, or on `*`,
  throws `Qoliber\Trident\Exception\InvalidRequest`.
- `Model\TridentClient::explain()` is removed (nothing used it).
- The admin actions listed under "admin actions are POST-only" no longer
  answer a GET.
- `Model\TridentClient::instances()` (new since 1.5.2) returns
  `list<Qoliber\Trident\Delivery\Instance>`.
- `Model\PurgeAfterCommit::__construct()` takes
  `(ResourceConnection, PurgeOutboxInterface, Config, TridentClient, LoggerInterface, Clock)`.
- `Cron\DrainPurgeOutbox`, `Console\Command\PurgeDrainCommand` and
  `Console\Command\PurgeStatusCommand` take an extra `Model\Clock`.
  The cron job id (`qoliber_trident_purge_outbox_drain`) and the commands
  (`trident:purge:drain`, `trident:purge:status`) are unchanged.
- `Model\Outbox\PurgeOutboxInterface` now extends the library's
  `OutboxStore`. It, `Model\Outbox\OutboxEntry` and `Model\Instance` are
  new in this release (X02/X03, never shipped); `OutboxEntry` and `Instance`
  are replaced by the library's classes of the same name.
- Magento 2.4.7 and PHP 8.1 are no longer allowed by `composer.json`
  (untested); stores on them stay on 1.5.2.

### Changed — built on qoliber/trident-php

The module now requires [`qoliber/trident-php`](https://packagist.org/packages/qoliber/trident-php)
`^1.5`, the library every Trident platform integration shares, instead of
carrying its own copy of the same code:

- **Durable purge delivery** is the library's: the outbox implements its
  `OutboxStore` on the existing `qoliber_trident_purge_outbox` table (no
  schema change, pending rows carry over), and delivery, request packing,
  acknowledgement, backoff and the three-failure cap are its `Drainer`,
  `Packer`, `Acknowledgement` and `Backoff`. What stays in the module is
  Magento's own: recording inside the save's transaction, sending from the
  commit callback, holding config purges until the configuration reloads,
  queued full clears, and splitting rows written before X03.
- **The admin API** is the library's typed client, for every call: the screens
  get each answer as the engine sent it (`raw()`), so no field is lost; its
  request path (bearer token, JSON, one retry when the admin limiter answers
  429, a redirect or a non-JSON answer treated as an error) runs over
  Magento's Guzzle instead of `Curl`. Invalidations on several instances run
  through its `Fleet`.
- **Purges** are its `purge(PurgeRequest)` (tags with `exclude_tags`, URL, tag
  pattern) and its host, vary and URL-pattern purges, always with the store's
  soft/hard setting sent explicitly (a request without a mode would take the
  engine's `default_purge_mode`); a full clear is `clearCache()` on the
  screens and `PurgeClient::clear()` in delivery. The counts Trident reports
  are shown: the purge messages name the entries purged (a soft purge marks
  them stale; it does not remove them), a clear the entries removed, and
  `trident:purge:drain` prints the cache entries purged. On several instances
  a total is shown only when every instance reported one; otherwise the
  message says on how many it applied and how many Reflect mode deferred.
- **Instances** are parsed by the library's `Instances::parse()`, the rules
  every integration uses. An instance name in `app/etc/env.php` must be
  **1-64 characters of `A-Z a-z 0-9 . _ -`** (it is stored with each pending
  purge and compared case-sensitively), and `api_url` must be an http(s) URL
  (one without a scheme, `trident:9301`, is read as http, as before). An entry
  that breaks a rule is **skipped and reported** — by
  `bin/magento trident:purge:status` (which then exits 1) and on the
  configuration screen — while the valid ones are used.
- `composer.json` requires real version ranges — Magento 2.4.8 to 2.4.9
  (`magento/framework ~103.0.8` …, now also `magento/module-backend` and
  `magento/module-config`, which the module uses), PHP 8.2 to 8.5 — instead
  of `*`, and `guzzlehttp/guzzle ^7.5` with `guzzlehttp/psr7 ^2.4` (its PSR-17
  factories). New packages on a Magento install: `qoliber/trident-php`,
  `psr/http-server-handler`, `psr/http-server-middleware`.
- **Every request runs on libcurl** (Guzzle's curl handler; Magento requires
  ext-curl), whatever `allow_url_fopen` says. The proxy is libcurl's own
  choice, exactly as through Magento's Curl client before: lowercase
  `http_proxy`, `https_proxy`/`HTTPS_PROXY`, `all_proxy`/`ALL_PROXY`, and
  `no_proxy` (domains and CIDR ranges) — never uppercase `HTTP_PROXY`, which
  under CGI a request's `Proxy:` header can set ("httpoxy"). Guzzle is told
  to set no proxy of its own; left alone it would add one from `HTTP_PROXY`
  in the CLI (cron, the drain command).

### Added — the Trident screens show every instance (X03)

- **An instance switcher** on every Trident screen (when there are several):
  statistics, entries, the warmer, launch, reflect, bans, backends, discovery
  and live events read and act on the instance chosen there, kept in the
  admin session. Purges still go to every instance, and choosing an instance
  without an API token never switches purges off.
- **An overview of all instances** on Statistics and on Cache Management,
  when there are several: reachable or not (and why), version, licence,
  entries, hit rate and the purges pending for each. It reads with short
  timeouts (2 s), and one instance being down never blanks the others.

### Fixed

- **An error answer was shown as success.** The client returned the decoded
  body of a 4xx/5xx answer (and of a redirect or a proxy's HTML page), and
  every action treats "not null" as done — so a disabled warmer's
  `{"code":"WARMER_DISABLED"}` read "Warmer started". Such an answer is now a
  failure, with the reason in the log.
- **Live Events showed nothing.** The engine keeps an event stream open for as
  long as the client listens; Magento's `Curl` threw away what had arrived
  when its 2-second timeout fired. A poll now listens for its window on
  libcurl, then reads back what arrived from its own sink (complete events
  only) — from the selected instance.
- **Cache Coverage checked GET entries whatever method was asked**: the
  `method` is now sent (the engine's `CacheCoverageRequest.method`).
- **"Warm these URLs" warmed the configured sources instead.** The URL list
  was sent to `/admin/warmer/run`, which takes no body; it now goes to the
  warmer's queue.
- **A POST without data sent `[]`**, which the engine's request parsers refuse;
  it now sends `{}` or no body.

### Added — denoiser pins work

The engine requires a class (`noise`|`signal`) to pin a query parameter and a
status (`dead`|`alive`) to pin a path zone; the forms did not ask for them, so
every pin failed. They now do — on each learned scope and zone, and in new
"Pin a parameter" / "Pin a zone" forms for ones not learned yet. A value the
library refuses (`InvalidRequest`) is shown as a form error; nothing is sent.

Pins are made on the **real host**. Trident keys a scope or zone on the
literal `host|prefix` and looks it up by the request's host (port included,
e.g. `localhost:8380`), with no `*` fallback — a pin on `*` would never be
used. The scope and zone rows send their own host; the standalone forms
default to the store's host as Trident sees it; a pin on `*` (or no host) is
refused as a form error. Unpin still accepts `*`, to remove inert `*` scopes.

### Changed — admin actions are POST-only

Every Trident admin action that changes something (purges, bans, the warmer,
launch, reflect, backends, discovery refresh, denoiser pins/unpins/resets, the
WAF export) accepts only a POST with the form key. The "Purge Trident Cache"
button on Cache Management now posts (`deleteConfirm` with post data) instead
of navigating to the purge URL.

### Known — library gaps (qoliber/trident-php 1.5.0)

What the module still does itself, because the library cannot yet:

- `coverage()` cannot send the request `method`; Cache Coverage calls the
  endpoint through the library's `Api`.
- Narrowing the WAF export (and the learned noise) to the store's own hosts:
  the engine keys zones as `host|prefix`, and that parsing is platform-neutral
  (the WooCommerce plugin has it as `WafView`) — it moves into the library
  (1.6.0, planned). Until then the export covers every site on the instance,
  and the Denoisers screen says so.

### Fixed — a purge deferred by Reflect mode was retried as a failure

In Reflect mode Trident answers a purge with HTTP 202, `status: "deferred"`,
`state: "recorded"`: it has durably queued the purge and replays it when
reflect ends. The module counted that as not acknowledged and retried it every
few minutes, queueing duplicates until the reflect queue was full — after which
genuinely new purges were refused with 503. That answer now counts as
delivered; 503 `refused` and everything else still do not.

### Added — several Trident instances (X03)

Stores running more than one Trident server can now purge all of them.

- **Configured in `app/etc/env.php`**, which Magento layers over the admin
  setting, so it takes precedence; an instance without its own `api_token`
  uses the admin's token. A token given in env.php is plain text. Without `instances`, the admin's API URL and token
  are the one instance, exactly as before.

  ```php
  'system' => ['default' => ['system' => ['full_page_cache' => ['trident' => [
      'instances' => [
          'edge-1' => ['api_url' => 'http://10.0.0.11:9301'],
          'edge-2' => ['api_url' => 'http://10.0.0.12:9301', 'api_token' => '...'],
      ],
  ]]]]],
  ```

  After changing it, run `bin/magento app:config:import` — as for any change
  to the `system` section of env.php; until then Magento answers every
  storefront request with a 500 ("The configuration file has changed").
- **Every invalidation goes to every instance**: entity saves, cache flushes,
  and the admin's purge by tag, URL, host, vary and pattern. It counts
  as done only when every instance acknowledged it; a failure names the
  instance.
- **Delivered per instance (X02).** A purge is recorded once per instance and
  each record is acknowledged, backed off and retried on its own — an edge
  that is down keeps its own purges pending without holding back the others.
  Rows recorded before this version are owed to every instance and split on
  the next drain — a batch at a time, keeping their age, attempts and backoff. Purges owed to an instance removed from the list are kept,
  not sent, and reported: `trident:purge:status` exits 1 and says so;
  `trident:purge:drain --forget=<name>` drops them once it is gone for good
  (and refuses for an instance that is still configured). Instance names are
  matched exactly, although the column's collation is case-insensitive.
- **Dashboard reads** (stats, entries, warmer, launch, reflect …) and **bans**
  use the first instance — ban ids are per engine, so a ban created on every
  instance could be deleted from one and live on, unseen, on the others. Store configuration shows a read-only "Purges go to" list,
  with any env.php entry that was skipped and why.
- Schema: `qoliber_trident_purge_outbox.instance` (run `setup:upgrade`).

### Fixed — a purge Trident refused was lost (X02)

A purge counted as sent the moment the request left, and the pending tags
were cleared before anyone looked at the answer. Magento's Curl does not throw
on an HTTP error, so a 401 after a token rotation, a 429 from the admin
limiter, a 503, a timeout — or the PHP process dying between the commit and
the send — each lost a committed invalidation, and the edge served the old
page for its whole TTL.

- **Acknowledged or kept.** A purge is delivered only when Trident answers
  200 with its purge schema (`purged`/`mode`/`state`; `cleared: true` for a
  full clear). Anything else is logged with the status and kept.
- **Recorded in the transaction.** Every purge is first written to
  `qoliber_trident_purge_outbox` through the same connection as the entity
  save, so it commits — or rolls back — with the data. A rolled-back save
  leaves no purge behind (previously it went out with the next commit).
- **Retried.** The commit sends it at once; a new cron job
  (`qoliber_trident_purge_outbox_drain`, every minute) retries with backoff
  up to 5 minutes, and never gives up. A request-thread drain stops after 3
  consecutive failures, so a storefront save never waits out a down edge.
  Delivery is idempotent: a crash between send and removal costs one
  duplicate purge, never a lost one.
- **A full clear supersedes only what it saw.** It removes the queued tag
  purges its drain read before sending it — never a row by id range, which
  could include a change committed after the clear went out.
- **Visible.** `bin/magento trident:purge:status` prints pending count,
  oldest age and the last failure, and exits 1 when purges have waited more
  than 15 minutes (cron stopped, token wrong) — wire it to monitoring.
  `bin/magento trident:purge:drain` delivers now.
- `cache:flush` and the admin "Flush Magento Cache" go through the same
  outbox. The admin panel's purge buttons still call Trident directly and
  now report a refused purge as failed instead of as done.

**Upgrade:** run `bin/magento setup:upgrade` (creates the table) and make sure
cron runs. Until the table exists, purges are sent the old best-effort way
and an error is logged. Supported setup: one `default` database connection and
one Trident target (multi-target fan-out is 1.9).

## [1.7.0] - 2026-09-14

> **Versioning:** pairs with **Trident 1.7.0**. The jump from 1.5.2 re-syncs the
> module to the engine it integrates with; 1.6.x shipped no module changes.
>
> Every fix below was found by measuring what actually reaches the edge, on a
> dockerised Magento with Trident in front, and each one is proven by a
> before/after measurement rather than by reading the code.

### Fixed — `cache:flush` left the edge untouched

- **`bin/magento cache:clean` and `cache:flush` sent Trident nothing.** Both go
  through `Cache\Manager`, and `flush()` wipes the cache **backend** without
  going through the cache-type objects, so neither the `PageCache\Model\Cache\Type`
  plugin nor the `adminhtml_cache_flush_*` events fire — no admin controller ran.
  Measured on 2.4.x: `cache:flush` emptied every Magento cache and left **7 of 7**
  edge entries in place, so the site kept serving pages built from the templates
  and configuration a deploy had just replaced. This is the last line of most
  deploy scripts.
- `Plugin/CacheManagerPlugin` purges on `flush()` unconditionally — the backend
  is gone, so nothing the edge holds can still be vouched for — and on `clean()`
  only when `full_page` is among the types. A routine `cache:clean config` must
  not cold the edge, and it no longer does: measured 6 entries before and 6
  after, against 0 after `cache:clean full_page`.
- **Also covered by this:** a theme or template change. Design backend models
  carry no cache identities at all, so nothing tag-based can ever invalidate
  them; the flush that follows a theme switch or a static-content deploy is the
  only signal there is, and now it reaches the edge.

### Fixed — a settings change never reached the edge (robots.txt and friends)

- **A configuration value's own cache tags were never purged.** Magento's tag
  resolver returns exactly one strategy's tags, and a custom strategy wins over
  the identifier one: `Magento_Store` registers one for
  `App\Config\ValueInterface` that returns only the GraphQL store-config tags,
  so the value's own `getIdentities()` never reached the purge. For robots that
  identity is `robots_<storeId>` — exactly the tag `X-Magento-Tags` puts on
  `/robots.txt`. Measured on 2.4.x: the purge arrived and **matched nothing**,
  and the cached robots.txt survived the change for its full 24 h `max-age`.
  The observer now adds those identities back, for config values only: the
  other custom strategies (customer, address, subscriber) replace identities on
  purpose and widening this would purge the shared cache on every customer save.
- **The purge for a config value was one step too early.** A configuration save
  writes the values and only then reinitialises the configuration; a purge sent
  at save time is spent on a storefront that still answers with the old value,
  and the refresh stores it again. Measured: the edge settled **permanently one
  change behind** — save A then B, and visitors get A. Config-derived tags are
  now held until `ReinitableConfig::reinit()`, which is the first moment the new
  value can be served (`Plugin/ConfigReloadPlugin`). A shutdown flush is the
  floor for CLI paths that never reinitialise.
- Proven on the e2e stack against the admin's own `DesignConfigRepository`:
  before, the edge never updated; with the tag fix it trailed by one change;
  with both, the newest robots.txt is on the edge within 3 s of the save.

### Fixed — a saved change could be replaced by the old page for the whole TTL
- **Purges now leave after the save transaction commits, not before.** Magento
  dispatches `clean_cache_by_tags` from `AbstractModel::afterSave()`, inside the
  save transaction. The module sent the purge from there, so it reached Trident
  while the new data was still invisible to every other database connection.
  With soft purge the refresh worker re-fetched the page at once, the storefront
  rendered it from the old state, and Trident stored that old page again as
  fresh — the only purge had already been spent. The same render refilled
  Magento's own `block_html` cache with the old content, so the storefront kept
  it too.
- How it showed: whenever the refresh reached the storefront before the
  commit, a price change reached the user only after the next indexer cron run
  cleaned `block_html` and sent a second purge. With cron down, or with
  a change no indexer subscribes to, the old page stayed until the TTL. The
  Magento e2e suite caught it intermittently: `34 instead of 41.77 — entry
  invalidated, but the previous body was served`.
- Reproduced deterministically by holding the save transaction 4 s after
  `afterSave`: before this fix the edge AND the storefront kept the old price
  until an indexer run; after it the new price is on the edge 2 s after the
  commit, with no cron. The purge now arrives within 0.5 s after the commit
  rather than at `afterSave`.
- `Model/PurgeAfterCommit.php` defers the purge through Magento's commit
  callbacks (`execute_commit_callbacks` runs them on every commit that brings
  the level to zero, raw adapter commits included, and drops them on
  rollback); outside a transaction it still goes out immediately. Purges from
  one transaction are merged and deduplicated, then sent in requests of at most
  1000 tags — one merged body over the admin API's 1 MiB `max_body_size` would
  be refused with 413 and lose every purge of the commit.
  `FlushCacheByTagsObserver` and `CacheTypePlugin` both use it — a single purge
  sent before the commit is enough to store the old page again.
- Supported setup: the single `default` connection (all of Open Source). An
  entity saved through another connection — a Commerce split database, a
  module's own connection — is checked against the wrong transaction.

### Fixed — tests
- `FlushCacheByTagsObserverTest` errored on every test before reaching the
  observer (`Event::getObject()` is a magic accessor PHPUnit cannot mock); it
  now builds real `Event`/`Observer` objects.

## [1.5.2] - 2026-07-23

> **Versioning:** re-syncs the module to the current engine — it pairs with
> **Trident 1.5.2**. The jump from 1.4.0 to 1.5.2 folds in the 1.5.0
> observability admin-API surface (built earlier but never released) plus the
> client-reliability fixes below.

### Added — Trident 1.5.0 observability admin surface
- Admin screens + `TridentClient` methods for the 1.5.x admin API: **status**
  (`/admin/status`), **explain** (`/admin/explain`), **Reflect** (status/queue/
  enable/disable), **Launch**, **Cache Warmer**, **Backends** (drain/restore),
  **Bans**, **DNS discovery**, and **Denoisers** — matching Trident's OpenAPI
  spec. Integration test coverage for the client, config, observers, and plugins.

### Fixed — admin-API client reliability (`Model/TridentClient.php`)
- **Every request now applies connect + read timeouts** (5s/10s). Previously
  most methods (including the observer-triggered `purgeTags`/`purgeAll` that run
  synchronously on the storefront) issued curl calls with no timeout, so an
  unreachable or wedged Trident admin port could hang a Magento admin save or a
  storefront `Type::clean`.
- **The shared-Curl DELETE verb can no longer leak.** Every request re-pins
  `CURLOPT_CUSTOMREQUEST` via `prepareRequest()`, so a prior DELETE cannot cause
  a following GET/POST on the injected singleton Curl to be issued as a DELETE.
- **The admin API token is now required to enable purges.** With FPC on engine 3
  but no `api_token` set, the client no longer fires empty-`Bearer` 401s that
  were swallowed into permanent stale content — it disables cleanly and logs one
  distinct, debug-independent warning so the misconfiguration is visible.
- **Non-2xx admin responses are logged** (401/5xx) instead of being silently
  discarded, on every request path.

### Known follow-ups (not in this release)
- POST-gate the mutating admin controllers (`HttpPostActionInterface`) and add a
  `form_key` to each admin POST form — requires validation in a Magento
  environment; adminhtml URL secret-keys already provide CSRF protection.
- Align tag casing between the two purge trigger paths.
- Remove dead surfaces (`PurgeStrategy` is `ENABLED=false`; `ttl_static` is read
  but never applied).

## [1.4.0] - 2026-06-16

> **Versioning:** this release realigns the module version with the Trident
> engine — it pairs with **Trident 1.4.0**. The jump from 1.2.x to 1.4.0 syncs
> the two; future releases will continue to match the engine version.

### Fixed — admin API parity with Trident's OpenAPI spec

The admin parity screens added in 1.2.0 were corrected to read Trident's actual
admin-API response fields. Several referenced names that do not exist (so
columns rendered blank or wrong), and two write calls used the wrong shape:

- **DNS Discovery** — read `backend_name` / `hostname` / `addresses` /
  `stats.last_success` (were `name` / `dns_name` / `resolved_addresses` /
  `last_refresh`); "resolving" status is derived from resolved addresses.
  `TridentClient::refreshDiscovery()` now sends the required `name` query param,
  and the Refresh action re-resolves every discovered target.
- **Bans** — read `ban_type` / `active` / `affected` (were `type` /
  `expires_at`); `TridentClient::createBan()` sends `type` (was `ban_type`);
  ban types are `url` / `tag` / `pattern` — the invalid `host` type was removed.
- **Backends** — per-backend cards use `host:port` / `status` /
  `total_requests` / `total_errors` / `avg_response_ms` / `active_connections`
  (were `url` / `drained` / `weight` / `avg_latency_ms`); connection pools read
  `name` / `max` / `queued` (were `backend` / `max_connections` /
  `waiting_requests`).
- **Cache Warmer** — last-run "Finished At" reads `completed_at` (was `finished_at`).
- **Statistics** — latency request count reads `count` (was `request_count`).

This brings the 1.2.0 admin feature parity and the 1.2.1 Cache Coverage screen
(both below) to their first correctly-rendering release.

## [1.2.1] - 2026-06-07

### Added

- **Cache Coverage** (`trident/cache/coverage`) — paste a list of URLs and see which
  are currently cached (freshness/age/TTL/size) plus the overall cached percentage,
  via the new `POST /admin/cache/coverage` endpoint (`TridentClient::cacheCoverage()`).
  Useful for verifying a warm run landed. Accessible via System > Trident Cache >
  Cache Coverage.

## [1.2.0] - 2026-06-04

### Added — admin feature parity with the Trident admin panel

Brings the full Trident operator surface into the Magento backend (System > Trident Cache).
Each screen follows the existing controller/ViewModel/template/ACL pattern and talks to the
admin API through `Model\TridentClient` (extended with ~30 new methods + shared GET/POST/DELETE helpers).

- **Cache Warmer** (`trident/warmer`) — status (state, current-run progress, last-run summary),
  Run-now (optional URL list), Cancel.
- **Launch Mode** (`trident/launch`) — start (URL list, auto-complete, bypass IPs), live state +
  progress, complete (go-live), abort.
- **Reflect Mode** (`trident/reflect`) — emergency origin shield; enable (full/selective/ttl_extension
  + duration/reason), disable (replay = hard-purge, or soft-purge = mark stale for lazy
  revalidation), queued-purge view, active warning banner.
- **Denoisers** (`trident/denoisers`) — query-scope + path-zone report, per-row pin/unpin/reset, WAF export.
- **Bans** (`trident/bans`) — list active/expired soft-purge bans, create (url/tag/host), delete.
- **Backends** (`trident/backends`) — per-backend health cards (drain/restore) + connection-pool table.
- **DNS Discovery** (`trident/dns`) — discovered targets table + refresh.
- **Live Events** (`trident/events`) — poll-based console over the SSE streams (requests/cache/backends/errors).
- **Richer Statistics** — latency percentiles, recent errors, protection summary, BP-59 memory layout.
- **Expanded Purge** — purge by host and by Vary (in addition to all / tags / url / pattern).

## [1.1.0] - 2026-03-06

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
- **Configurable TTL, grace period, and static asset TTL** via admin system configuration.
- **ESI (Edge Side Includes) support** — Enable ESI processing with configurable max nesting depth. Adds `Surrogate-Control` header when enabled.
- **ConfigTypePlugin** — Maps Trident cache type (3) to Varnish (2) so core Magento FPC plugins activate without patching core code.
- **Health indicator on Cache Management page** — Green/orange status dot with Trident version and uptime display.
- **Unit and integration test suite** — PHPUnit 10.5 tests for TridentClient, Config, all plugins, observers, and PurgeStrategy.
- **Vary dimension badges** — Cache entries with the same URL but different vary values (e.g. customer groups) now show distinguishing badges.

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
