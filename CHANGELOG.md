# Changelog

All notable changes to `Muon_DevProfiler` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning is
[SemVer](https://semver.org/spec/v2.0.0.html).

## [1.5.0] — 2026-08-28

Closes the Low findings from the 2026-08-28 release-readiness audit.

### Security

- **Run files are no longer world-readable.** `create()` and `writeFile()` take the directory's
  configured mode — 0777 by default, landing as 0755/0644 under the usual umask. The files hold
  request URIs and statement shapes, and anything that tars `var/` for a backup or a support bundle
  carried them off the box. Runs are now 0600, the directory 0700, and a `.htaccess` carrying
  `Require all denied` is written beside them so the store is self-protecting even where the
  document root is misconfigured to serve `var/`.

- **The ring prunes by age, not only by count.** It shrank only on write, so once profiling stopped,
  up to `ringSize` documents sat in `var/` indefinitely and the only way to remove them was to
  remember `muon:profile:clear`. Default retention is 72 hours, via a new `maxAgeHours` `di.xml`
  argument; `0` restores the old behaviour.

### Fixed

- **Runs are written atomically.** `writeFile()` truncates and then writes with no lock, so a reader
  listing the directory mid-write saw a partial file, caught the decode failure and silently skipped
  the run — `muon:profile:list --limit=20` quietly returning nineteen. Written to a `.part` name and
  renamed into place, so a run is either absent or complete.

- **`loadLastDocument()` no longer returns a static-asset run.** A static run has `is_ajax => false`,
  so it satisfied the old condition and could be answered as "the last full document" — right after
  a static rebuild that meant a LESS file's run, whose verdict is `n/a`.

- **`TemplateHints` no longer double-decorates.** Core's own plugin wraps the engine at sortOrder 10
  when `dev/debug/template_hints_storefront` is on; this one runs at 100 and wrapped the wrapper,
  rendering two nested hint frames around every block.

- **A failed origin resolution is remembered.** `StatementOrigin` returns null when all thirty
  captured frames match the skip list, and writing that null back left the `=== null` guard open —
  so a shape that is consistently slow and lives entirely inside `framework/DB` walked the stack
  again on every execution over the threshold instead of once.

- **Fallback resolutions are collapsed before they are classified.** `ShadowResolver` stats every
  candidate directory for every entry it is handed, and Magento resolves the same file more than
  once per request — reliably twice on a static run. Collapsing afterwards meant paying for those
  stat calls before discarding them: roughly 1,200–5,000 `is_file()` calls on a real run, about half
  of them repeats.

### Changed

- **`Magento_PageCache` is no longer required or sequenced.** The cacheable verdict comes from
  `Framework\View\Layout::isCacheable()`; the only mention of PageCache in the module was a prose
  comment. A false dependency edge constrains upgrades for nothing.

- **The hard-coded `version` field is gone from `composer.json`.** Releases are tagged, so the field
  was a second source of truth that drifts the moment a tag is cut without editing it.

- **`method_exists()` replaced with `instanceof HttpInterface`.** Both methods are declared on it,
  and every response reaching those call sites implements it — the string lookup gave static
  analysis nothing to narrow.

- **`.gitattributes` keeps `Test/`, `.github/` and `phpmd.xml` out of the Composer dist**, and
  `composer.json` excludes `Test/` from the production classmap. `docs/` and `CHANGELOG.md`
  deliberately still ship: this is a developer tool, the reference is the reason to keep it, and the
  README links to both.

- **The README links to the project page**, the changelog and the technical reference.

### Not changed

- **`Model\Store` keeps its name.** The audit is right that it reads as the Magento store entity
  when it means storage. Renaming it is a breaking change for anything type-hinting `RunStore` —
  including `Muon_DevProfilerBoard`'s write path — and the supported surface is now
  `Api\RunReaderInterface`, which carries no such ambiguity. The cost outweighs the clarity gained.

## [1.4.0] — 2026-08-28

Closes the Medium findings from the 2026-08-28 release-readiness audit.

### Security

- **The request URI is no longer stored with its query string intact.** `getRequestUri()` returns it
  verbatim, and Magento puts single-use credentials there: `customer/account/createPassword` carries
  `token`, `Confirm` carries `key`, and either is enough to take over an account. The path is kept
  whole; the query is filtered through `ValueMasker`, so there is one definition of "sensitive"
  rather than a second list that drifts from the first. Numeric ids survive — they are what
  separates an N+1 from a duplicate.

- **`ValueMasker` covers ordinary PII and two credential shapes it used to miss.** Added
  `firstname`, `lastname`, `company`, `city`, `region`, `country`, `address`, `birth`, `gender`,
  `coupon` and neighbours to the key list: a name is five letters that look like any other five, so
  no shape rule can catch it and the key has to decide. The token shape now admits `:` and `.`, so a
  Magento password hash (`hash:salt:version`) and a JWT are recognised; both previously failed the
  character class on one punctuation mark.

- **Template hints can no longer poison a shared cache.** `?__muon_hints=1` rewrites every block's
  HTML, and the cookie was not part of any cache identity — so the first hinted response was stored
  under the clean URL and served to every later visitor, red borders and server-side template paths
  included. The active mode now goes into `Http\Context`, which feeds `getVaryString()` and the
  `X-Magento-Vary` cookie the full-page cache hashes. The plugin also honours
  `dev/restrict/allow_ips` via `Developer\Helper\Data::isDevAllowed()`, as core's own hints do — an
  operator who restricted debugging to one address should not be worked around by a query parameter.

### Fixed

- **`Gate` no longer latches an answer borrowed from an emulated area.** `Widget\FilterEmulate`,
  PageBuilder's `DesignLoader` and `Email\Filter` all call `emulateAreaCode('frontend', …)` and
  restore afterwards. A yes derived from one was memoized, arming the collectors for the rest of an
  admin request, a `bin/magento` command or a cron process — where `RunFinalizer` never fires, so
  nothing was written, nothing freed, and the recorder grew to the end of the process.

- **Static-asset runs no longer evict the page run they were captured beside.** A cold page fires
  one `App\StaticResource` request per unmaterialised asset — routinely 150 to 400 — and each wrote
  a run and pruned the ring, rotating the 50-entry ring several times over during a single page
  load. Measured on a live install: 22 of 50 stored runs were static, carrying no queries. A static
  run is now kept only when it resolved a `.less` or `.css` source, which is the one thing
  `App\Http` cannot see and the reason the hook exists. Pass an empty `keepExtensions` to restore
  the old behaviour.

- **`LayoutVerdict` stopped re-parsing the merged layout on every profiled page.**
  `Merge::asSimplexml()` caches nothing: each call re-implodes every merged fragment and re-parses
  it, measured at 0.8ms for a 100KB layout and 8.7ms at 800KB. `generateXml()` has already built
  that tree, so the plugin now reads it back through the public `getNode()`. The cost was being paid
  inside the request whose `duration_ms` the profiler then reported — the tool was inflating the
  number it exists to measure.

### Added

- **`Api\RunReaderInterface`, marked `@api`.** The read surface a companion module can bind to,
  with a `di.xml` preference onto `RunStore`. `Muon_DevProfilerBoard` consumed six concrete classes
  across ten files under a `^1.2` constraint with nothing marked `@api` behind it, so a legal minor
  release here could have broken it. `CacheVerdict`, `QueryAnalyzer`, `ResolutionSet`,
  `ShadowResolver` and `RunFinalizer` are now marked `@api` too.

- **`ResetAfterRequestInterface` on all six stateful singletons** — `RunContext`, `Gate`,
  `QueryLogger`, `FallbackRecorder`, `TemplateHints` and `ShadowResolver`. Core's own
  `DB\Logger\LoggerProxy`, the class this module plugs, implements it for the same reason: in a
  long-lived process one request's recording would otherwise be attributed to the next.

- **`Test/Unit/Stub/generated.php`.** `DebugHintsFactory` has no source file — Magento generates it
  into `generated/code` on demand — so a test that doubles it passes on a full install and errors in
  CI with "Class or interface does not exist". The stub is declared only when the real class is
  absent, so the tests run everywhere rather than being skipped in CI, which would put them straight
  back into the category this release just took them out of.

- **Tests for everything above, plus the three classes that had none**: `SqlListRenderer` (reached by
  `--sql`, and previously never executed by any test), the three console commands, and
  `FallbackRecorder`, whose seven-argument signature is a branch per optional argument.
  175 tests, up from 119.

### Changed

- **Documentation uses `bin/magento`, not `make`.** The `make profile` targets are wrappers in the
  monorepo this module is developed in; they do not exist in any Magento install, and this is now a
  public package. That included a runtime string — `FallbackListRenderer` printed
  `make profile-clear` as advice to the operator.

- **`LayoutVerdict`'s two `around` plugins are now `after` plugins.** Neither modified arguments,
  short-circuited, or caught anything from `$proceed()`; both called it as their first statement.
  Because they sit on `Magento\Framework\View\Layout`, every other module's plugins on those two
  methods were running inside a closure chain this module owned.

- **`etc/di.xml`'s load-bearing invariant is stated correctly.** It claimed the SQL collector's
  constructor graph was "plain, unplugged and ungenerated"; on a stock 2.4.9 it is not —
  `App\State` is plugged by `Magento_NewRelicReporting`, and the plugin target resolves to
  `LoggerProxy`, which lazily builds the also-plugged `Quiet`. The real invariant is that no
  dependency may be constructed *for the first time* inside `___callPlugins()`, and it holds only
  because bootstrap builds `App\State` first — incidental, not enforced.

### Known trade-offs, accepted

- **The `DB\LoggerInterface` plugin is global and unavoidable.** It fires on every statement in
  every area because `LoggerInterface` resolves before any area exists, and no other module on a
  stock install plugs it — so the interceptor exists because of this module. With the gate settled
  the residual is roughly 3.6µs per statement, about 1.5ms on a 200-query page. Splitting the SQL
  collector into a separately-disableable package was considered and not done; an install that
  cannot accept the cost should disable the module rather than rely on developer mode, which gates
  what is recorded and not what is dispatched. Now documented in the interception table, where it
  was missing entirely.

- **"Developer mode only" remains a runtime gate, not a wiring decision.** Magento has no
  mode-scoped `di.xml`, so the plugins register unconditionally and the gate returns early. The
  1.4.0 reordering makes that early return cheap; removing the dispatch itself would require the
  package split above.

## [1.3.1] — 2026-08-28

### Fixed

- **`LICENSE.txt` now carries the full verbatim OSL-3.0 text.** It previously held a 503-word
  excerpt ending in a link, which omitted operative sections — Termination for Patent Action and
  Jurisdiction among them — and which license detectors do not recognize: GitHub reported this
  repository as `NOASSERTION` / "Other" rather than OSL-3.0.

  The declared license is unchanged; only the text shipped alongside it is now complete. The file
  is byte-identical to the one in `Muon_DevProfilerBoard`, which relicensed to OSL-3.0 in its 1.1.0.

## [1.3.0] — 2026-08-28

### Security

- **Raw SQL statement text is no longer stored.** `queries[].sample` now holds the normalised shape
  instead of the verbatim statement. `ValueMasker` only ever guarded bound parameters, but Magento
  inlines values through `quoteInto` at least as often as it binds them — every
  `Model::load($value, $field)` and every `where('col = ?', $v)` puts the value in the statement
  text — so a persistent-session key, a newsletter subscriber's email or a coupon code was written
  to `var/muon/profiler/*.json` in cleartext, past the masker entirely. Measured on a live ring
  before the fix: 397 of 1,392 statement groups carried at least one inlined literal.

  `sql_varies` still answers the same question, from a CRC32 of the first raw statement held in
  memory for the request. The raw text now never leaves the process.

### Fixed

- **`Gate` no longer throws an exception per statement in CLI processes.** `isProfiled()` asked
  whether the area had resolved before asking what mode the installation was in, and the area probe
  is a throw/catch around `State::getAreaCode()`. `Magento\Framework\Console\Cli` never sets an
  area, so every statement issued by `setup:upgrade`, `cache:*`, `config:*` or any third-party
  import command constructed and threw a `LocalizedException` whose backtrace was captured over a
  40-80 frame stack — in production mode, with profiling off. Measured 13.5 µs at stack depth 10 and
  45.6 µs at depth 50; roughly a minute of overhead per million statements.

  The mode check now runs first and its "no" is memoized permanently, which is sound because
  `State::getMode()` is fixed at bootstrap. The deliberate refusal to memoize a premature "no" is
  unchanged, and still applies to the area question that actually needs it.

- **`QueryLogger` stops re-entering the gate on every statement.** New `Gate::isDecided()`
  distinguishes a settled "no" from "not yet", so the plugin can cache the former.

### Changed

- **CI runs the unit tests.** The `unit-tests` job was present but commented out, so 119 tests were
  gated by nothing. It now runs on every push and pull request, installing `magento/*` from the
  public Mage-OS mirror — no `repo.magento.com` credentials required. The job is deliberately
  unconditional: a job that skips its own steps still reports success.

## [1.2.0] — 2026-08-14

### Added

- **`RunFinalizer::excludedActions`.** A `di.xml` array argument naming full action names whose runs
  are never recorded. Default empty, so nothing changes until a consumer contributes a name.

  This exists for a companion that reads the ring over HTTP. Such a module is itself a frontend
  request, so its collectors run and its run is written like any other — and a board that polls for
  new runs would evict the runs being inspected within seconds. The tool would destroy its own
  evidence.

  It is a constructor argument and deliberately **not** a plugin. Intercepting anything in this
  module's constructor graph makes the object manager generate an interceptor inside
  `___callPlugins()` — the documented cause of the `Undefined array key
  "Magento\Framework\App\Http"` failure that took the storefront down in 1.0.0, and invisible
  whenever `generated/` is populated.

  A request that never routed has no action name, so static-asset runs cannot be excluded by
  accident; the comparison is strict, so an empty string in the list matches nothing.

- **`Model\Analysis\ResolutionSet`.** The fallback list's two presentation rules — collapse repeat
  lookups of the same file into one row with a count, then rank shadowed first — extracted from
  `FallbackListRenderer`'s private methods so every read surface applies the same ones.

  They were private until a second surface needed them, and a second copy would have been a second
  answer: a web board rendering the raw classification showed four identical `etc/view.xml` rows
  where `bin/magento muon:profile:show` shows one, and reported 6 shadowed files where the CLI reported 3. Two tools
  disagreeing about one piece of evidence is worse than having one tool.

- **`RunStore::count()`.** How many runs the ring holds, counted without decoding any of them, so a
  caller that needs only the number does not pay to unserialize fifty documents to print one integer.

### Fixed

- **Every SQL origin pointed at this module's own logger.** `StatementOrigin`'s skip list named
  `/Muon/DevProfiler/` — the `app/code` layout — but the module is deployed by Composer to
  `vendor/muon/module-dev-profiler/`, which that fragment does not match. Nothing was skipped, so
  the first frame outside the DB plumbing was always `Plugin/Db/QueryLogger.php:154`, and the origin
  column — the whole point of capturing a backtrace — was useless on every real installation.

  This is the same failure the bundled-Zend-DB entry was added to fix in 1.1.0, reappearing because
  the module moved from `app/code` to `vendor/`. Both layouts are now named, and
  `StatementOriginTest` covers each so the next move cannot silently break it again.

## [1.1.1] — 2026-08-13

### Fixed

- **Distinct statements were reported as duplicates.** `QueryFingerprint` normalises inlined
  literals to `?` — deliberately, so one shape groups its executions. The analyzer then decided
  between N+1 and duplicate from the presence of *bound* arguments, and Magento inlines literals as
  often as it binds them. Seven lookups of seven **different** CMS blocks therefore arrived as one
  shape with seven executions and no binds, and were reported as "the same query repeated seven
  times". It sent a reader chasing a cache that would have been wrong to add.

  The collector now compares the raw statement against the group's sample — one string comparison
  per statement — and records `sql_varies`. Analysis prefers that observation over the bind-based
  guess, so a finding says `variation observed` rather than `inferred, not proven`, and a shape
  whose text varied is never called identical. Runs captured before this release have no such key
  and fall back to the old inference rather than silently reading as "did not vary".

## [1.1.0] — 2026-08-13

### Added

- **SQL collector.** Records every statement the storefront runs, grouped by normalised
  fingerprint, with per-group count, total and slowest time, masked sample binds, and the call site
  for groups that earn one. Shown with `muon:profile:show --sql`.
- Read-time classification into N+1, duplicate and slow, with thresholds as CLI flags
  (`--slow-query`, `--nplus1`, `--duplicate`) rather than configuration — one capture can be
  re-examined at a different sensitivity without re-running the page.
- `RunContext::setMetaProvider()` for facts resolved at assembly rather than written per statement,
  and `canAccept()` so a caller-maintained map respects the same cap and truncation as `push()`.

### Security

- **Bind-value masking is now mandatory** — by key name and by value shape. This is the first
  release in which the module records anything that could be personal; the 1.0.x claim that it
  handled no sensitive data no longer holds and has been corrected in the blueprint and the
  technical reference.

## [1.0.3] — 2026-08-13

### Fixed

- A full-page-cache hit reported `THEME ?`. The theme is now recovered from store configuration —
  readable without loading the design — and labelled `(store default — not observed)`, because it
  is a weaker claim than a theme the request actually used. The stored document carries
  `context.theme_source` (`observed` / `configured` / `null`).
- `HANDLES 0` was printed on cache hits and static requests, where layout never runs and the number
  describes nothing. Now shown only when layout generated.
- An empty fallback list reported "no resolution matched the filter" even when no filter had been
  given. Each empty case now names its own cause.

### Changed

- `RunRenderer` split into `RunRenderer` (run summary) and `FallbackListRenderer` (the fallback
  list). They change for different reasons, and the combined class had crossed the complexity gate.

## [1.0.2] — 2026-08-13

### Fixed

- **Knockout / UI-component `.html` templates resolved to nothing**, and because the framework had
  resolved them the miss was reported as `replay-diverged` — a false alarm claiming the analysis
  could not be trusted, on 22 of 93 files on one page. Their lookup inserts a path segment between
  the fallback rule's `web/` directories and the recorded file key, and Magento uses **both**
  spellings: 62 modules ship `web/template/`, 5 ship `web/templates/`. Both are now probed,
  singular first, at most one copy per directory. `replay-diverged` went 22 → 0, and 116 recorded
  `html_template` resolutions are analysed for the first time. Supersedes the partial fix in 1.0.1.
- `--fallback` searched only the requested file name, so `--fallback=breeze-evolution` returned
  nothing while the report plainly listed that theme. It now matches resolved and shadowed paths
  too, making "what is this page pulling out of <theme>?" answerable.

## [1.0.1] — 2026-08-13

### Fixed

- **Storefront returned 500 on every page when `generated/` was empty.** Page capture was a plugin
  on `App\Http`, a bootstrap-time class, so it had to be registered globally; instantiating it
  inside `___callPlugins()` forced interceptor generation for `StoreManagerInterface`, which
  re-enters the object manager and resets `PluginList::$_data`. Replaced with an observer on
  `controller_front_send_response_before`, registered in the frontend area only. Invisible with DI
  compiled, which is why it was not caught earlier.
- Removed all `\Proxy` arguments from `etc/di.xml`; a proxy is generated code and belongs nowhere
  in a bootstrap-time graph.
- Knockout / UI-component `.html` templates were silently unanalysed — their resolution type has no
  `RulePool` constant. Now mapped to the static rule.
- Files could appear to shadow themselves when two search directories resolved to the same path.
- A resolver probe that legitimately finds nothing is reported as `probe-miss` and counted rather
  than listed as an anomaly; an unrecognised type now reports `unsupported-type`.

### Changed

- Repeat lookups of the same file are collapsed in the report with an `xN` count. The stored
  capture is unchanged and still records every lookup.
- Fallback header now reads `N distinct files (M lookups)` instead of conflating the two.
- Shadowed files, then anomalies, now lead the report; the rest keep resolution order.
- `full_action` is `null` for requests that never routed, instead of the string `"__"`.
- A run served from the full page cache says so, and says how to capture a cold one, instead of
  printing an unexplained empty report.
- A static run's duration is labelled `(includes asset build)`.

## [1.0.0] — 2026-08-13

### Added

- Theme fallback forensics: records every resolution and classifies shadowed copies at read time
  by replaying Magento's own `RulePool` search order.
- Full-page-cache verdict with a named cause — the generated `cacheable="false"` block, or the
  call site that constructed a non-cacheable layout.
- Per-browser template hints via `?__muon_hints=1|blocks|0`, replacing the store-scoped core
  setting. No route or controller.
- Filesystem ring buffer under `var/muon/profiler/` (50 runs, `di.xml` argument). No database
  tables, no cron.
- CLI: `muon:profile:show`, `muon:profile:list`, `muon:profile:clear`.
- Capture on both entry points: `App\Http` for pages and `App\StaticResource` for assets that had
  to be compiled — the latter is where LESS is resolved.

### Security

- Developer mode only, checked in code with no configuration override.
- The response body is never modified; a `X-Muon-Profile` header is the only change. This makes
  reverse-proxy cache poisoning structurally impossible rather than mitigated.
- No SQL, no PII, no session data recorded — so no masking is required at this version.
