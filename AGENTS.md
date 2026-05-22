## Overview
This repository hosts the webtrees statistics module — a six-tab dashboard of tree-wide statistics built on the chart-lib widgets, installed as a Composer package inside webtrees. The tabs are organised by data nature: Overview (population summary), Names (surnames + given names + decade trends), Tree health (data-quality), Life span (births + deaths + age distributions), Family (marriage / divorce / children / kinship aggregates), Places (geographical distributions).

## Setup/env
- PHP 8.3+ with extensions dom, json and intl is required; composer installs dependencies into `.build/vendor` and binaries into `.build/bin`.
- Node.js tooling is used for asset builds (rollup). Install dev dependencies via `npm install` when touching frontend resources.
- Run all PHP and Node tooling inside the webtrees buildbox container — never on the host:
  ```
  make bash
  cd app/vendor/magicsunday/webtrees-statistics
  ```
- Build JS bundles from the module directory via the node container: `make install`, `make build`.
- After PHP or JS changes that should be visible in the browser, restart the PHP-FPM service.
- After JS changes, verify in the browser via Playwright before claiming success.

## Build & tests
- **`composer ci:test` MUST run before every commit** — catches Biome lint, PHPStan, PHP-CS-Fixer, Rector, PHPUnit, Jest, and jscpd issues before they reach GitHub CI.
- Individual checks: `composer ci:test:php:phpstan`, `composer ci:test:php:unit`, `composer ci:test:php:cgl`, `composer ci:test:js:lint`, `composer ci:test:js:unit`, `composer ci:test:cpd`.
- Single PHPUnit test: `composer ci:test:php:unit -- --filter TestClassName`.
- Auto-fix: `composer ci:cgl` (PHP style), `composer ci:rector` (Rector), `npm run lint:fix` + `npm run format` (Biome).
- JS bundles: `make build` (rollup), `make watch` (dev rebuild loop).
- Translations: `make lang` runs the full pipeline — extract `resources/lang/messages.pot` from `src/` + `resources/views/`, merge it into each locale's `messages.po` (seeding missing ones via `msginit`), and compile every PO to MO. Sub-targets `lang-extract`, `lang-merge`, `lang-compile` exist for partial runs. The POT itself is gitignored (regenerated on demand); the PO + MO files are committed.
- Add PHPUnit attribute-based coverage (positive AND negative cases) for every class/method introduced or modified — the project follows the "test every class" standard.
- PHPStan runs at `level: max` against `src/` with no baseline. Every change fixes the underlying defect; the baseline file is intentionally absent so future drift cannot be ignored.

## Architecture

### Data flow: PHP → data-widget partial → dispatcher → chart-lib widget

```
Module.php (entry point, registers route + 6 tab action methods)
  → Templates/<TabName>.phtml (tab body — Overview, Names, TreeHealth, LifeSpan, Family, Places)
    → Partials/<WidgetName>.phtml (DonutChart, ProgressList, GeoMap, TagCloud, StreamGraph, SankeyFlow)
      → renders <div data-widget="..." data-payload="..." data-options="...">
        → page.phtml AJAX-tab-callback fires
          WebtreesStatistic.renderWidgets(tabPane)
            → modules/index.js dispatch table:
              donut        → @magicsunday/webtrees-chart-lib::DonutChart
              world-map    → drawWorldMap (geojson fetch wrapper around chart-lib WorldMap)
              stream-graph → @magicsunday/webtrees-chart-lib::StreamGraph
              sankey-flow  → @magicsunday/webtrees-chart-lib::SankeyFlow
```

Every tab action delegates to the private `renderTab(string $template)` helper, which loads `Templates/<template>.phtml` with the active `Statistic` aggregator. Adding a new tab is a three-place change: an entry in `tabCatalog()`, a `get<Name>Action()` method that calls `renderTab('<Name>')`, and a `Templates/<Name>.phtml` body.

The `Statistic` aggregator service is resolved via the webtrees DI container (`Registry::container()->get(Statistic::class)`) and aggregates data from fifteen repositories plus core's `StatisticsData`.

### PHP (`src/`)
- **`Module.php`** — Entry point, extends webtrees `StatisticsChartModule`, implements `ModuleAssetUrlInterface` + `ModuleCustomInterface`. Registers six tab actions (Overview, Names, TreeHealth, LifeSpan, Family, Places) that all delegate to a shared `renderTab()` helper.
- **`Statistic.php`** — `final readonly` aggregator service. Constructor-injects `StatisticsData` (core) plus the fifteen repositories. Public methods return either scalars, `[{label, value, class?}]` shapes for chart-lib widgets, or `label => count` maps for the ProgressList partial.
- **`MaritalBucket.php`** — Backed enum (`current` / `divorced` / `widowed` / `single`) used as the typed bucket-key for `FamilyRepository::classifyLivingIndividuals()`.

### Repositories (`src/Repository/`)
| Repository | Responsibility |
|---|---|
| `FamilyRepository` | Marital-status classification (current / divorced / widowed / single) |
| `EventRepository` | Births-by-zodiac-sign — the one stat core doesn't expose |
| `NameRepository` | Distinct surname / given-name counts, restricted to `n_num = 0` |
| `TreeHealthRepository` | Source-citation coverage, missing-event gaps, average generation length |
| `GivenNameTrendsRepository` | Per-decade frequency of the top-N given names for the stream graph |
| `MigrationRepository` | Birth → death country flows for the Sankey diagram (bipartite split for DAG safety) |
| `CountryRepository` | Births / deaths grouped by ISO-3166-1 alpha-2 country |
| `LifeSpanRepository` | Age-at-death histogram, oldest deceased / living, age-band donut |
| `MarriageRepository` | Age at marriage M+F, duration, couple age-gap, weddings century+month |
| `DivorceRepository` | Divorces by century / month, age at divorce M+F, divorce rate per MARR cohort |
| `ChildrenRepository` | Children-per-family histogram, sibling-age-gap, top-10 largest families, childless donut, first-children-by-month |
| `KinshipRepository` | Known-ancestor distribution + average pedigree completeness (Lacy 1989) |
| `OccupationRepository` | Top-N occupations (`1 OCCU` facts), case-folded merge of spelling variants. Cached per instance — both top-N and distinct count read from one aggregation. |
| `ReligionRepository` | Top-N religions / confessions (`1 RELI` facts), case-folded. Cached as above. |
| `DeathCauseRepository` | Top-N causes of death (`2 CAUS` sub-tag inside `1 DEAT`), case-folded. Cached as above. |

### Support (`src/Support/`)
- **`GedcomScanner`** — Reusable raw-GEDCOM helpers (`hasAnyTagAnchored`, `extractEventYear`, `extractEventPlace`, `extractPrimaryName`, `extractAllTagValues`, `extractEventSubValue`) so anchored tag matching (`\n1 <tag>` followed by space / newline / EOS) lives in one place. `DIV` does not match `DIVF`; bare `2 PLAC` (no place name) is treated as no place at all. `extractPrimaryName` strips the surname-delimiter slashes from the first `1 NAME` line, collapses internal whitespace, scrubs to valid UTF-8, and falls back to `(no name)` for blank entries. `extractAllTagValues` captures every value of a `1 <tag>` line for multi-occurrence facts (OCCU, RELI, …); `extractEventSubValue` pulls the first `2 <subTag>` value from inside a `1 <eventTag>` block, scoped so a sibling event's sub-tag cannot satisfy the lookup.
- **`TopNAggregator`** — Generic Top-N counter for `(row set, extract closure, limit)` triples. Case-folded keys merge spelling variants; the first-seen original casing wins as the display label; `arsort` (stable in PHP 8.0+) breaks ties by encounter order. Consumed by `OccupationRepository`, `ReligionRepository`, `DeathCauseRepository`.
- **`IsoCountryMap`** — Free-text country name → ISO-3166-1 alpha-2 resolver. Built on PHP's intl extension (`Locale::getDisplayRegion`) across nine pre-seeded locales (English, German, French, Spanish, Italian, Dutch, Portuguese, Polish, Russian) plus the active webtrees locale, with a manual-aliases list for common GEDCOM abbreviations (USA, UK, Deutschland, …). Labels resolve against the active `I18N::languageTag()`.

### Views (`resources/views/modules/statistics-chart/`)
- **`page.phtml`** — Outer six-tab navigation. AJAX-loads each tab body lazily and runs `WebtreesStatistic.renderWidgets(pane)` against the freshly-injected pane on the `show.bs.tab` event.
- **`Templates/Overview.phtml`** — Three donut cards (sex, living/deceased, marital status) plus a conditional second row of progress-list cards (top-15 occupations, top-15 religions) rendered only when the underlying facts are present.
- **`Templates/Names.phtml`** — Three tag-cloud cards (common surnames, male given names, female given names) + given-name popularity stream graph (top-10 by decade).
- **`Templates/TreeHealth.phtml`** — Source-citation coverage, missing-event gaps, average generation length.
- **`Templates/LifeSpan.phtml`** — Births / deaths by month / zodiac / century, age-at-death histogram (10-year bands), age-band donut (life-stages), top-10 oldest deceased + living, plus a conditional top-15 causes of death progress-list rendered when the underlying `2 CAUS` facts are present.
- **`Templates/Family.phtml`** — Age at marriage M+F, marriage duration, couple age gap, weddings century + month, divorces century + month + age M+F, divorce-cohort rate, children-per-family, sibling gap, top-10 largest families, with / without children donut, ancestor count, average pedigree completeness, first children by month.
- **`Templates/Places.phtml`** — Birth-country map + companion top-10, death-country map + companion top-10, birth → death migration Sankey.
- **`Partials/<Widget>.phtml`** — Thin shells that emit the `data-widget` JSON marker and the empty target element consumed by chart-lib widgets.

### JS (`resources/js/modules/`)
- **`index.js`** — exports `renderWidgets(root)`. Scans every `[data-widget]` element, parses `data-payload` / `data-options` JSON, and dispatches to the registered draw function. The world-map dispatch is async because it fetches the GeoJSON (cached per page load) before instantiating the chart-lib widget. Also initialises Bootstrap popovers attached to chart-header info buttons after each render.
- **`dashboard-bus.js`** — Shared selection observable (see Cross-widget selection bus section below).

All d3 modules are peer-dependencies pulled in via `package.json` — `d3-array`, `d3-axis`, `d3-ease`, `d3-fetch`, `d3-geo`, `d3-interpolate`, `d3-sankey`, `d3-scale`, `d3-scale-chromatic`, `d3-selection`, `d3-shape`, `d3-transition` — declared as `external` in `rollup.config.js`.

### Translations (`resources/lang/<locale>/`)
- 11 locale baselines ship in the repo: `en-US`, `de`, `fr`, `nl`, `pl`, `da`, `ru`, `it`, `zh-Hans`, `cs`, `nb`. Locale selection follows the dev.webtrees.net installation share (top 10 by usage) plus `en-US` as the Poedit-editor reference (its msgstr mirrors the msgid). German (`de`) is the primary maintained locale and ships near-complete `msgstr` entries. The other 9 ship best-effort baselines (UI labels, common nouns, card titles, plurals); native-speaker translators are expected to refine via Poedit. A `msgstr ""` falls back to the English `msgid` automatically.
- `make lang` is the single entry point for the i18n pipeline:
    1. `lang-extract`: `xgettext` walks `src/` + `resources/views/` for `I18N::translate`, `I18N::plural`, `I18N::translateContext` calls, writes `resources/lang/messages.pot` (gitignored).
    2. `lang-merge`: `msgmerge` reconciles each locale's `messages.po` with the fresh POT, `msginit`-seeds any missing locale entry.
    3. `lang-compile`: `msgfmt` produces every `messages.mo` from its sibling `messages.po`. Webtrees core reads the MO at runtime via the module's resource loader.
- The `*.pot` file is gitignored; PO + MO are committed. Adding a new locale: append the language code to the `LOCALES :=` list in `Make/lang.mk`, then run `make lang` once.

## Key patterns
- **Bucket precedence (per individual)**: current > divorced > widowed > single. Applied in `FamilyRepository::classifyOneIndividual()` so a remarried-after-widowed living person is classed as "current", not "widowed".
- **Family precedence (per family row)**: divorced > widowed > current > non-contributing. Matches the upstream Census decision tree.
- **Orphaned spouse XREF**: when a partner record is missing from the individuals table, the classifier neither marks the survivor as widowed nor as current — it abstains from that family entirely so the count is conservative.
- **Empty-string XREFs**: webtrees stores `''` (not `NULL`) when an INDI's `1 HUSB`/`1 WIFE` line is absent. `partnerIdOf()` normalises both shapes to `null`.
- **Anchored tag matching**: `hasAnyTagAnchored()` requires `\n1 <tag>` followed by space, newline, or end-of-string so that `DIV` does not match `DIVF`. Both the partner-death check and the family-event check use the same anchored helper.
- **Core century-tuple shape**: `StatisticsData::countEventsByCentury` returns a 0-indexed list of `[centuryLabel, total]` tuples — NOT a labelled map. Repositories unpack the tuple explicitly; `$k => $v` iteration silently collapses every count to 1 because `(int) $v` on an array equals 1.
- **Bipartite Sankey nodes**: `MigrationRepository::flowsByCountry` keeps source-side and target-side nodes on disjoint index ranges, even when the same country appears on both ends. d3-sankey is a DAG layout and would otherwise throw "circular link" on counter-flows (Germany → USA combined with USA → Germany).
- **Plain-name labels for progress-bar aria-labels**: `Individual::fullName()` returns HTML markup (`<span class="NAME">…</span>`). Module-base's `NameProcessor::getFullName()` is the placeholder-stripped plain-text accessor and the only safe form for `aria-label` interpolation in the progress-list partial.
- **Single chart tooltip element on `document.body`**: every chart-lib widget that supports hover-tooltips uses `createChartTooltip()` from chart-lib — one shared `position: fixed` element across the whole page, clamped to viewport edges, flipped above-cursor / left-of-cursor when the preferred placement would overflow.

## Cross-widget selection bus

`resources/js/modules/dashboard-bus.js` exposes a `DashboardBus` class — a tiny d3-dispatch wrapper that lets one widget broadcast a selection (e.g. "show me only the 1900s century") and every subscribed widget rebroadcast / re-filter against the same predicate.

### Contract
- `bus.emit({ source: "donut.births-century", predicate: { century: 1900 } })` — broadcast.
- `bus.onSelectionChanged(callback)` — subscribe. Returns an `unsubscribe` function for clean teardown.
- A `null` predicate means "clear filter".
- Every subscriber receives every event. Callers ignore their own emissions by matching the `source` string.

### Sequence
```
+--------+         +---------------+         +-----------+   +-----------+
| Widget |--emit-->| DashboardBus  |--fanout-| Widget A  |   | Widget B  |
| (donut)|         | (selection)   |--fanout-| (sankey)  |   | (heatmap) |
+--------+         +---------------+         +-----------+   +-----------+
```

The bus carries no data shape — each widget interprets the predicate against its own dataset. This keeps the bus itself ~50 lines and pushes the schema decision to the widget pair that actually shares semantics (e.g. century filter only makes sense between widgets that bucket by century).

### Status
- The bus + 5 jest tests covering multi-subscriber broadcast, unsubscribe, null-predicate, and source-self-ignore contracts is shipped.
- Widget-side wiring (donut slice click → bus.emit, sankey re-filter on incoming selection) is tracked in issue #14 + chart-lib#10 + stats#33 — chart-lib widgets need an `onSelectionChanged` hook before the pilot wiring lands.

## Design principles
- Priority order on conflict: **KISS > SOLID > DRY > YAGNI > GRASP > Law of Demeter > Separation of Concerns > Convention over Configuration**.
- `declare(strict_types=1)`, no `mixed`, no `empty()`, no `@deprecated`, typed class constants, `final readonly` where applicable, qualified `use function` imports, PHPDoc on every class and method, English-only comments.
- One class per file. Write tests for every class with **real value-equality assertions against curated fixtures**, not just shape checks — the `assertGreaterThan(0)` style hides regressions where the iterator shape changed but the count happens to land near zero. Use PHPUnit `#[Test]` attributes (not docblock annotations).
- Prefer `array_find` / `array_any` / `array_all` over manual `foreach` for "find one" / "any match" / "all match" intents — but only on PHP 8.4+. While `composer.json` still allows PHP 8.3, manual `foreach` stays the portable form; do not introduce these calls until the floor moves to 8.4.

## Audit-loop discipline
- Every issue umsetzen: spawn ALL relevant reviewers (correctness + maintainability + testing + project-standards always; conditional ones — adversarial / kieran / reliability / security / frontend — whenever their triggers match) in parallel before committing. Iterate fix → audit until 2× zero findings AND local `composer ci:test` green.
- Keep `AGENTS.md` and the README in lockstep with code changes. If a section here references a behaviour the code no longer has, fix the doc in the same commit.
