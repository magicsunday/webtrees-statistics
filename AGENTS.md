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
- Build JS bundles from the module directory via the node container: `make install`, `make build`. Additional targets: `make watch` (dev rebuild loop), `make clean` (remove node_modules), `make lint` / `make lint-fix` (Biome on JS sources), `make test` (Jest unit tests). Run `make help` to list the full target catalog.
- For live editing against sibling dev clones, use `make link-base` / `make link-chart-lib` to symlink `webtrees-module-base` and `webtrees-chart-lib` into this module's vendor / node_modules; restore via `make unlink-base` / `make unlink-chart-lib`.
- After PHP or JS changes that should be visible in the browser, restart the PHP-FPM service.
- After JS changes, verify in the browser via Playwright before claiming success.

## Build & tests
- **`composer ci:test` MUST run before every commit** — catches Biome lint, PHPStan, PHP-CS-Fixer, Rector, PHPUnit, Jest, and jscpd issues before they reach GitHub CI.
- Individual checks: `composer ci:test:php:phpstan`, `composer ci:test:php:unit`, `composer ci:test:php:cgl`, `composer ci:test:php:rector`, `composer ci:test:php:lint`, `composer ci:test:js:lint`, `composer ci:test:js:unit`, `composer ci:test:js:format`, `composer ci:test:cpd`.
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
  → tabs/<tab-name>.phtml (tab body — overview, names, tree-health, life-span, family, places)
    → src/View/Card + Section builders compose the chrome
       → widgets/<widget-name>.phtml (donut-chart, line-chart, bar-chart, stacked-bar,
                                       diverging-bar, sankey-flow, chord-diagram,
                                       stream-graph, name-bubbles, month-radial, mirror-histogram,
                                       gauge-arc, geo-map) for the data-widget host
       → components/<name>.phtml (scalar, podium, records-grid, places-panel, hero,
                                   live-dead-card, marital-card, heat-strip, illustration,
                                   progress-list) for inline body markup
      → renders <div data-widget="..." data-payload="..." data-options="...">
        → page.phtml AJAX-tab-callback fires
          WebtreesStatistic.renderWidgets(tabPane)
            → modules/index.js dispatch table:
              donut         → @magicsunday/webtrees-chart-lib::DonutChart
              world-map     → drawWorldMap (geojson fetch wrapper around chart-lib WorldMap)
              stream-graph  → @magicsunday/webtrees-chart-lib::StreamGraph
              sankey-flow   → @magicsunday/webtrees-chart-lib::SankeyFlow
              line-chart    → @magicsunday/webtrees-chart-lib::LineChart
              bar-chart     → @magicsunday/webtrees-chart-lib::BarChart
              stacked-bar   → @magicsunday/webtrees-chart-lib::StackedBar
              diverging-bar → @magicsunday/webtrees-chart-lib::DivergingBar
              chord-diagram → @magicsunday/webtrees-chart-lib::ChordDiagram
```

Every tab action delegates to the private `renderTab(string $template)` helper, which loads `tabs/<template>.phtml` with the active `Statistic` aggregator. Adding a new tab is a three-place change: an entry in `tabCatalog()`, a `get<Name>Action()` method that calls `renderTab('<kebab-name>')`, and a `tabs/<kebab-name>.phtml` body that composes its cards via the `Card::for($module, $title)->render()` builder.

The `Statistic` aggregator service is resolved via the webtrees DI container (`Registry::container()->get(Statistic::class)`) and aggregates data from twenty-one repositories plus core's `StatisticsData`.

### PHP (`src/`)
- **`Module.php`** — Entry point, extends webtrees `StatisticsChartModule`, implements `ModuleAssetUrlInterface` + `ModuleCustomInterface`. `getChartAction()` resolves `Statistic` through the container, calls `getHeroStats()`, and publishes the result + six webfont asset URLs to `page.phtml`. Per-tab actions (Overview, Names, TreeHealth, LifeSpan, Family, Places) all delegate to a shared `renderTab()` helper that injects the same `Statistic` instance into the tab template.
- **`Statistic.php`** — `final readonly` aggregator service. Constructor-injects `StatisticsData` (core) plus the twenty-one repositories. Public methods return either scalars, typed DTOs from `Model/<Widget>/` (LineChart / StackedBar / Sankey / Chord / StreamGraph / Tree / Record / Metric / HeroStats payloads), `[{label, value, class?}]` shapes for chart-lib widgets, or `label => count` maps for the ProgressList partial. `getHeroStats()` composes the six headline metrics the hero header renders into a `HeroStats` DTO.
- **`Enum/`** — Cross-cutting domain enums that classify webtrees data without carrying behaviour. Currently:
  - `Sex` — Backed enum (`M` / `F`) used internally by the marriage / divorce / parenthood repositories to pick the correct spouse column via `spouseColumn()`. Repository public methods accept a raw `string $sex` and convert via `Sex::from(…)`; the `Statistic` facade exposes M-specific and F-specific methods (or pre-paired tuples) rather than a sex parameter, so the enum stays purely internal to the repository layer.
  - `MaritalBucket` — Backed enum (`current` / `divorced` / `widowed` / `single`) used as the typed bucket-key for `FamilyRepository::classifyLivingIndividuals()`.
  - `AgePairExtremum` — Backed enum (`lowest` / `highest`) used by `IndividualAgeRecordResolver` to pick the boundary record from a list of age tuples.
- **View-layer enums** (`View/Accent`, `View/Illustration`, `View/LegendPosition`, `View/RecordCategory`, `View/ProgressBarAccent`) live with the builder API they shape — see the "View builders" section below.
- **`Model/FamilyRow.php`** — DTO for the raw FAM row carried through the marital-classification pipeline.
- **`Model/<Widget>/`** — Wire-format DTOs grouped by chart-lib widget shape (`LineChart/`, `StackedBar/`, `Sankey/`, `Chord/`, `StreamGraph/`, `Tree/`, `Record/`, `Metric/`). Each implements `JsonSerializable` so the partial layer just JSON-encodes the value.

### Traits (`src/Traits/`)
- **`ModuleCustomTrait`** — Wires `ModuleCustomInterface` to the class-level `CUSTOM_AUTHOR` / `CUSTOM_VERSION` / `CUSTOM_SUPPORT_URL` / `CUSTOM_LATEST_VERSION` constants on `Module.php`, loads the compiled MO catalogue via `customTranslations()`, and proxies the latest-version check through `VersionInformation` (module-base). Also overrides `getAssetAction()` so the per-module asset route can publish web fonts with the correct `font/woff2` / `font/woff` MIME types — core's `Mime::TYPES` has no font entries, and Firefox refuses to load fonts served as `application/octet-stream` with `NS_ERROR_CORRUPTED_CONTENT`. The MIME-overlay table lives as `Module::ASSET_MIME_TYPES` on the class (not on the trait) so a future core trait that ever declares the same constant cannot trigger a fatal trait-constant composition conflict.
- **`ModuleChartTrait`** — Re-asserts `chartMenuClass()` so the Statistics entry in the Charts dropdown keeps its icon (webtrees core's chart trait resets the class to the empty string). Carved out as a separate trait so any future chart-menu / chart-URL / chart-title overrides land in one predictable place rather than scattered across `Module.php`.

### Repositories (`src/Repository/`)
| Repository | Responsibility |
|---|---|
| `FamilyRepository` | Marital-status classification (current / divorced / widowed / single) |
| `EventRepository` | Births-by-zodiac-sign — the one stat core doesn't expose |
| `NameRepository` | Distinct surname / given-name counts, restricted to `n_num = 0` |
| `TreeHealthRepository` | Source-citation coverage (tree-wide + per-birth-century), missing-event gaps, average generation length, per-issue stacked breakdown |
| `GivenNameTrendsRepository` | Per-decade frequency of the top-N given names for the stream graph |
| `MigrationRepository` | Birth → death country flows for the Sankey diagram (bipartite split for DAG safety) |
| `CountryRepository` | Births / deaths grouped by ISO-3166-1 alpha-2 country |
| `LifeSpanRepository` | Age-at-death histogram, oldest deceased / living, age-band donut, seasonality scoring, lifespan-by-sex×century |
| `MarriageRepository` | Age at marriage M+F, duration, couple age-gap, weddings century+month |
| `DivorceRepository` | Divorces by century / month, age at divorce M+F, divorce rate per MARR cohort |
| `ChildrenRepository` | Children-per-family histogram, sibling-age-gap, top-10 largest families, childless donut, first-children-by-month |
| `ParenthoodRepository` | Age-at-first-child distributions per sex, parent-of-first-child records |
| `EndogamyRepository` | Couple-pairs sharing a common ancestor within a configurable depth; emits a typed `EndogamyRate` |
| `KinshipRepository` | Known-ancestor distribution + average pedigree completeness (Lacy 1989) |
| `GenerationDepthRepository` | Tree-wide deepest-line summary backed by the `GenerationDepth` support helper |
| `ChildMortalityRepository` | Under-5 mortality rate per birth century |
| `PlaceDispersionRepository` | Birth-place clustering scalar (entropy across countries) |
| `MarriageMatrixRepository` | Symmetric surname-marriage chord matrix |
| `AbstractGedcomTagTopNRepository` | Shared scaffolding (`top()`, `countDistinct()`, cache, INDI-iteration template) for the three Top-N tag repos below |
| `OccupationRepository` | Top-N occupations (`1 OCCU` facts), case-folded merge of spelling variants. Extends the abstract base. |
| `ReligionRepository` | Top-N religions / confessions (`1 RELI` top-level + `2 RELI` event-bound), case-folded. Extends the abstract base. |
| `DeathCauseRepository` | Top-N causes of death (`2 CAUS` sub-tag inside `1 DEAT`), case-folded. Extends the abstract base. |
| `ParentMapRepository` | Internal helper consumed by `Kinship`, `Endogamy` and `GenerationDepth` — builds the `child → [father, mother]` and inverse maps once per request so the three downstream repos don't each pay for the scan. Not injected into `Statistic`. |

### Support (`src/Support/`)
Reorganised into five semantic subnamespaces. The flat root carries `WidgetJson` only; everything else lives under a topic folder.

- **`Support/Aggregator/`** — Pure-function helpers that fold a `[label => count]` distribution (or similar shape) into the row format consumed by a chart-lib widget, plus row-to-DTO bridges that materialise a query-result tuple into a typed record. Members: `TopNAggregator` (case-folded Top-N counter with stable tie-breaking, consumed by `AbstractGedcomTagTopNRepository`), `HistogramBucketAggregator` (generic [min..max] bucketing with overflow caps), `CenturyBarRowMapper` (births / deaths / marriages / divorces — 4 named factories pin the `I18N::plural` msgid pairs), `CoupleAgeGapRowMapper` (cleans negative-marker labels into positive mirror bands + tags `sign = -1` for husband-older), `DivorceCohortRowMapper` (cohort-rate decade label + tooltip), `IndividualAgeRecordResolver` (resolves a `{xref, ageYears}` candidate via `Registry::individualFactory()` into a typed `IndividualAgeRecord` DTO; takes an `Enum\AgePairExtremum` to pick the lowest- or highest-age boundary), `MirrorBandRowMapper` (husband / wife / father / mother / man / woman — 6 named factories), `ProgressBarRowMapper` (percentage-of-max bar payload), `RecordRowMapper` (years / familyYears / familyDays / marriages / children / familyChildren), `SiblingGapRowMapper` (sibling-age-gap histogram → LineChart payload).
- **`Support/Calc/`** — Numeric / date arithmetic with no DB or GEDCOM dependency. Members: `AgeBuckets` (5-year band classifier), `Endogamy` (couple-pair search), `GenerationDepth` (iterative DFS over `childrenOf` / `parentOf` graphs with per-individual memoisation), `HistogramTrim` (drops leading / trailing all-zero buckets in a co-trimmed M+F pair).
- **`Support/Database/`** — Eloquent-aware query helpers. Members: `TreeScope` (tree-scoped query builder, `TreeScope::table($tree, 'individuals')`, plus `individualGedcoms($tree)` for canonical `i_gedcom` iteration — every repository goes through this so the `i_file` where-clause is never forgotten), `BirthDeathPairsQuery` (shared birth+death join builder), `DateJoin` (date-table join helper).
- **`Support/Gedcom/`** — Raw-GEDCOM string scanning + row coercion. Members: `GedcomScanner` (`hasAnyTagAnchored`, `extractEventYear`, `extractEventPlace`, `extractPrimaryName`, `extractAllTagValues`, `extractAllSubTagValues`, `extractEventSubValue`, `anchoredLikePatterns` — anchored matching, `DIV` does not match `DIVF`, bare `2 PLAC` treated as no place, surname-delimiter slashes stripped from `1 NAME`, UTF-8 scrubbed, falls back to `(no name)`), `RowCast` (stdClass-row safe int/string casting).
- **`Support/Locale/`** — Locale-aware string lookups. Members: `CenturyName` (year → localised century label), `IsoCountryMap` (free-text country name → ISO-3166-1 alpha-2, built on PHP intl across nine pre-seeded locales + manual aliases for common GEDCOM abbreviations), `SpelledNumber` (low-cardinal integer → localised word, e.g. `1 → "one"` / `1 → "ein"`), `ZodiacLabels` (canonical → locale-translated zodiac sign map).
- **`Support/WidgetJson`** — UTF-8-scrubbed JSON encoder for `data-payload` + `data-options`. `WidgetJson::encode($value)` produces raw JSON via `JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE`; `WidgetJson::encodeAttribute($value)` additionally HTML-escapes via `e()` so the 26+ widget partials read as a single call instead of nested `e(json_encode(...))`.

### Views (`resources/views/modules/statistics-chart/`)

Three-folder layout mirroring the chart modules' kebab-case convention:
`tabs/` carries one PHTML per tab body, `components/` carries shared non-widget building blocks (hero, illustration, scalar, podium, …), and `widgets/` carries the thin shells that emit `data-widget` JSON markers consumed by chart-lib. The card chrome itself is built in PHP via `MagicSunday\Webtrees\Statistic\View\Card` + `Section` (`src/View/`).

- **`page.phtml`** — Outer shell. Renders the dark hero header (`components/hero.phtml`), the sticky numbered tab nav, and the AJAX-loaded six tab panes. Inlines the `@font-face` declarations for the self-hosted Editorial typefaces (Instrument Serif + Geist) so their `url()` references route through the webtrees `Module::assetUrl()` endpoint. Bootstrap's `data-bs-toggle="tab"` JavaScript still owns the tab switching; `WebtreesStatistic.renderWidgets(pane)` runs on the `show.bs.tab` event against the freshly-injected pane.
- **`tabs/overview.phtml`** — Composes four Sections: DEMOGRAPHICS (Sex / Living-or-deceased / Marital-status donut cards), CHRONICLE (tree-records hall-of-fame card driven by `TreeRecordsReport`), SOCIO-ECONOMICS (top-15 occupations + top-15 religions progress-list cards), POPULATION (cumulative tree growth over time `LineChart` — running sum of dated births across the visible decade window).
- **`tabs/names.phtml`** — Composes two Sections: FREQUENCIES (common surnames + male/female given-name `NameBubbles` cards), EVOLUTION (given-name popularity stream graph + surname × surname marriage `ChordDiagram` cards).
- **`tabs/tree-health.phtml`** — Composes three Sections: DOCUMENTATION (source-citation coverage `GaugeArc` + pedigree-completeness `GaugeArc` + average-generation-length `Scalar` + per-century sourced-coverage `BarChart`), GAPS (missing-event gaps `BarChart`), STRUCTURE (generation-depth distribution `BarChart` + ancestor-count distribution `BarChart` + endogamy `Scalar`).
- **`tabs/life-span.phtml`** — Composes four Sections: LIFESPAN (age-at-death histogram + living life-stage donut + lifespan by sex × century LineChart + top-10 oldest deceased + top-10 oldest living), MORTALITY (common death causes + child-mortality LineChart + child-mortality scalar), BIRTHS (births by century + month + zodiac), DEATHS (deaths by century + month + winter-peak `Scalar`).
- **`tabs/family.phtml`** — Composes four Sections: MARRIAGE (age at marriage M+F + marriage-duration `BarChart` + couple age gap `DivergingBar` + weddings century + month), PARENTHOOD (age at first child M+F + first children by month), FAMILY SIZE (children per family + sibling-age-gap + family-size composition `StackedBar` + average family size + childless donut + top-10 largest families), DIVORCE (divorce-cohort rate + divorces century + month + age at divorce M+F).
- **`tabs/places.phtml`** — Composes three Sections: ORIGIN & FATE (birth / residence / death countries — each card packages a top-10 ProgressList + GeoMap), MIGRATION (birth → death `SankeyFlow`), MOBILITY (geographic-dispersion `Scalar` + distinct-places-per-individual distribution).
- **`components/hero.phtml`** — Dark hero strip rendered above the nav. Eyebrow = `$tree->title()`, h1 = static "Statistics", deck = dynamic "over N centuries" copy, 6-stat readout (individuals / families / max generation depth / average generation length / pedigree completeness / sourced individuals), concentric-ring SVG ornament.
- **`components/illustration.phtml`** — 21 thematic SVG illustrations (people, candle, rings, laurel, craft, chapel, zodiac, sunrise, moon, hourglass, magnifier, family, child, bell, brokenHeart, tree, boat, globe, book, trophy, knot) keyed by name. Each anchors to the top-right of its 80×80 container via `preserveAspectRatio="xMaxYMin meet"` and uses `stroke="currentColor"` so the parent Card's accent paints the icon. The `View\Illustration` enum carries one case per icon; tab templates pass the case directly to the Card builder via `->withIllustration(Illustration::People)`.
- **`components/records-grid.phtml`** — Tree-records hall-of-fame grid extracted from `tabs/overview.phtml` so the Overview's CHRONICLE card body and any future per-individual records widget can share it.
- **`widgets/<name>.phtml`** — Thin shells that emit the `data-widget` JSON marker and the empty target element consumed by chart-lib widgets. Current set: `donut-chart`, `line-chart`, `bar-chart`, `stacked-bar`, `diverging-bar`, `sankey-flow`, `chord-diagram`, `stream-graph`, `name-bubbles`, `month-radial`, `mirror-histogram`, `gauge-arc`, `geo-map`.

### View builders (`src/View/`)
- **`Card`** — `final readonly` fluent builder for the chart-card frame. `Card::for($module, $title)->withSub(...)->withEyebrow(...)->withAccent(Accent::X)->withIllustration(Illustration::Y)->withSpan(...)->withInfo(...)->withBodyHtml(...)->render()`. Renders to a HEREDOC-composed HTML string via six private renderX helpers; HTML-escapes every user-string via a private `escapeHtml()` wrapper.
- **`Section`** — Fluent builder for the section-divider strip. `Section::create($title)->withKicker(...)->withSub(...)->render()` — one call per Section in each tab template.
- **`Widget`** — Fluent builder for the thin `data-widget` JSON-host partials under `widgets/`. One factory method per widget type (`Widget::barChart($module, $identifier)`, `Widget::lineChart`, `Widget::donutChart`, …). Typed setters for the common options (`withData`, `withAriaLabel`, `withHeight`, `withAccent(Accent)`, `withLegendPosition(LegendPosition)`) plus a generic `with(string $key, mixed $value)` escape hatch for widget-specific keys the underlying partial reads.
- **`Component`** — Mirror of `Widget` for the non-`data-widget` server-rendered fragments under `components/` (scalar, podium, progress-list, hero, records-grid, places-panel, …). Same factory-method + `with()` shape, but the partial emits plain HTML rather than a JS-dispatcher host.
- **`Accent`** — Backed enum (Wine / Slate / Sage / Ochre / Rose / Deceased) for the Heritage palette. Value is the CSS `var(--...)` literal the partials read; Card + Widget take the enum directly.
- **`Illustration`** — Backed enum with one case per icon. `Illustration::Tree->svg($module)` resolves a case to the rendered SVG markup; tab templates normally pass the case directly to `Card::for(...)->withIllustration(...)` and the Card resolves it.
- **`LegendPosition`** — Backed enum (Right / Bottom) for the donut-chart legend placement.
- **`RecordCategory`** — Backed enum (Life / Marriage / Family) for the Hall-of-Fame `records-grid` row category — drives the row's accent colour class.
- **`EmptyStatePlaceholder`** — Static helper. `EmptyStatePlaceholder::render(?string $message = null)` returns the canonical `.chart-empty-state` `<div>` markup; widget / component partials short-circuit to it on empty-data branches. `defaultMessage()` exposes the localised "No data recorded for this metric." copy so partials can wire it into `data-empty-message` attributes without re-translating.
- **`ProgressBarAccent`** — Static lookup. `ProgressBarAccent::for(string $class): Accent` maps a legacy `progress-*` CSS class key to its Accent enum case — single source of truth for the colour pinning behind `podium.phtml` + `progress-list.phtml`.

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
