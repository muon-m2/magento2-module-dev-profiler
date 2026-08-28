# Changelog

All notable changes to `Muon_DevProfiler` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning is
[SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  where `make profile` shows one, and reported 6 shadowed files where the CLI reported 3. Two tools
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
- CLI: `muon:profile:show`, `muon:profile:list`, `muon:profile:clear`, plus `make profile`,
  `make profile-list`, `make profile-clear`.
- Capture on both entry points: `App\Http` for pages and `App\StaticResource` for assets that had
  to be compiled — the latter is where LESS is resolved.

### Security

- Developer mode only, checked in code with no configuration override.
- The response body is never modified; a `X-Muon-Profile` header is the only change. This makes
  reverse-proxy cache poisoning structurally impossible rather than mitigated.
- No SQL, no PII, no session data recorded — so no masking is required at this version.
