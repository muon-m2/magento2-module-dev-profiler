# Muon_DevProfiler

**[Documentation](https://muon-m2.github.io/magento2-module-dev-profiler/)** · [Changelog](CHANGELOG.md) · [Technical reference](docs/technical-reference.md) · [Muon_DevProfilerBoard](https://muon-m2.github.io/magento2-module-dev-profiler-board/) — a web board that reads the same captures

Headless storefront request profiler for Magento 2. Records **which physical file won every theme
fallback lookup** and **why a page was or was not full-page cached**, writes it to a JSON file, and
reads it back from the CLI.

Developer mode only. No storefront UI, no admin surface, no database tables, no cron. It never
writes to the response body — only a `X-Muon-Profile` header.

## Why it exists

Static analysis cannot answer "which copy of this file is live", because the answer depends on the
theme the request resolved. On a multi-theme install that question costs hours: an override written
in the wrong theme is invisible, because the site behaves exactly as if it were never written.

```
$ bin/magento muon:profile:show --shadowed-only

  css/theme/abstracts/_tokens-generated.less
    won       app/design/frontend/Muon/cosmic-custom/web/css/theme/abstracts/_tokens-generated.less
    shadowed  vendor/muon/theme-frontend-cosmic/web/css/theme/abstracts/_tokens-generated.less   <-- ignored
```

## Install

```bash
bin/magento module:enable Muon_DevProfiler
bin/magento setup:upgrade
bin/magento setup:di:compile  # recommended, not required — see note below
bin/magento cache:flush
```

The module declares no schema, so `setup:upgrade` makes no database change. It works with or
without compiled DI; 1.0.0 did not, and took the storefront down when `generated/` was empty.

**Developer mode is required.** There is no configuration flag to enable it elsewhere; that is
deliberate. If `bin/magento muon:profile:show` reports "No runs recorded yet", confirm what the *web* request runs
as — it is set by `MAGE_MODE` in the FastCGI params and may differ from what `bin/magento
deploy:mode:show` reports for the CLI.

## Finding N+1 queries

```bash
bin/magento muon:profile:show --sql                  # statement shapes, findings first
bin/magento muon:profile:show --sql --nplus1=3       # stricter
bin/magento muon:profile:show --sql --slow-query=10  # everything over 10ms
```

Statements are grouped by shape, so a loop issuing 50 lookups appears once with `x50` and the call
site that produced it. Thresholds are applied when you read, not when the page ran — the same
capture can be re-examined at a different sensitivity without reloading anything.

Bound values are masked before they are stored, and the statement itself is stored as its
normalised shape rather than verbatim — Magento inlines values into SQL text at least as often
as it binds them, so masking only the binds would have left the rest in the clear. Numeric ids
in binds are deliberately kept: they are the evidence that separates an N+1 from a plain
duplicate.

## Use

```bash
bin/magento muon:profile:show                    # the last full page request
bin/magento muon:profile:show 7f3a9c2e1b4d       # one specific run
bin/magento muon:profile:show --shadowed-only    # only files that exist in more than one place
bin/magento muon:profile:show --fallback=tokens  # only files whose name matches
bin/magento muon:profile:show --any              # include AJAX and static-asset runs
bin/magento muon:profile:list                    # recent runs, newest first
bin/magento muon:profile:clear                   # empty the ring
```

`bin/magento muon:profile:show` with no argument returns the last **full document** request, because a page fires
customer-section XHRs immediately behind it and the newest run is almost never the page you loaded.

### Template hints, for your browser only

```
https://muon.localhost/en-us/?__muon_hints=1        # template hints
https://muon.localhost/en-us/?__muon_hints=blocks   # template + block names
https://muon.localhost/en-us/?__muon_hints=0        # off
```

Magento's own switch is store-scoped, so enabling it shows hints to every visitor of that store
view. This one is a cookie, scoped to the browser that asked, and behind the same developer-mode
gate.

## Where runs are stored

`var/muon/profiler/<unixms>-<token>.json` — a ring of the last 50 (a `di.xml` argument on
`RunStore`). Files, not a table, for three reasons:

1. A table write would happen inside the request being profiled and would contaminate a future SQL
   collector with the profiler's own `INSERT`.
2. Files survive `bin/magento cache:flush` and `bin/magento setup:upgrade` — run constantly, often mid-debug.
3. The JSON is directly readable with `grep`; the CLI is a convenience, not a dependency.

## What it records

| Area | Recorded |
|---|---|
| Request | method, URL, full action name, status, duration, peak memory, AJAX flag, page vs static |
| Context | store code, store id, website id, resolved theme path |
| Layout | whether layout generated, `isCacheable()`, handles, `cacheable="false"` blocks (marked in-play or not), layouts constructed non-cacheable with origin |
| Fallback | every resolution: type, file, module, area, locale, theme, resolved path |

Shadowed-candidate classification and the cache verdict are computed **at read time**, so the
analysis can be improved and re-run against runs captured earlier.

A module that reads the ring over HTTP is itself a frontend request, and would otherwise fill the
ring with its own page loads. Such a companion names its actions in `excludedActions` — a `di.xml`
argument on `RunFinalizer` — and its runs are never recorded. See `docs/technical-reference.md`.

## What it does not do

Core Web Vitals, client-side errors, environment audit and threshold-based issue detection are
deliberately out of scope — chrome-devtools MCP, `magento2-tools:snapshot` and
`magento2-tools:perf` already cover them. SQL capture and a block render tree are v2 candidates;
see `docs/technical-reference.md`.
