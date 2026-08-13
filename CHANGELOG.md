# Changelog

All notable changes to `Muon_DevProfiler` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning is
[SemVer](https://semver.org/spec/v2.0.0.html).

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
