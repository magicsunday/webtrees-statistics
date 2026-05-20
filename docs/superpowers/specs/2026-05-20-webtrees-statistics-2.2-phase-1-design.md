# webtrees-statistics — Phase 1: Port to webtrees 2.2 (Design)

**Date:** 2026-05-20
**Module:** `magicsunday/webtrees-statistics`
**Goal of this phase:** Make the existing WIP module runnable on webtrees 2.2.* with the structural blueprint of the other chart modules (fan/pedigree/descendants), and extract reusable chart widgets into `magicsunday/webtrees-chart-lib`.

This is **Phase 1** of a multi-phase plan. A `1.0` release tag will only be cut later, once every tab returns real data (not in this phase).

## 1. Context

The current repository (`magicsunday/webtrees-statistics`, two commits, marked `!!! WIP !!!` in README):

- Pins `fisharebest/webtrees: ~2.1.0`. README claims 2.2 support.
- Package name is mis-spelled (`webtrees-statistic`, singular).
- Imports `Fisharebest\Webtrees\Statistics\{Repository,Google,Service}\…` types that **no longer exist in webtrees 2.2** — the Repository/Google trees were removed; their behavior was consolidated into `Fisharebest\Webtrees\StatisticsData`.
- `Module::getChartAction()` declares nine tabs but only four real action handlers exist (Overview, Places, Births, Deaths plus the chart landing); the other five (Relationships, Age, Weddings, Divorces, Children) return 404.
- `Statistic::getFamilyStatusData()` ships hardcoded German strings (`'Verheiratet'`, `'Allein lebend'`, `'Verwitwet'`, `'Geschieden'`) and placeholder integers (`38`, `0`).
- `EventRepository.php` carries ~100 lines of commented-out code.
- No PHPUnit tests, no CI workflow at parity with the other chart modules.
- `module.php` autoloads `MagicSunday\Webtrees\ModuleBase\…` but `composer.json` does not declare the dependency.
- JS uses a vendored `lib/d3.js`; does not consume the shared `magicsunday/webtrees-chart-lib`.

## 2. Goals & non-goals

### Goals (Phase 1)

1. Module loads cleanly on webtrees 2.2 (composer-resolvable, no fatal class-not-found at boot).
2. Existing five real tabs (`Overview`, `Places`, `Births`, `Deaths`, and the `Chart` landing) render correct data with no hardcoded placeholders and no untranslated strings.
3. Five currently-missing tabs (`Relationships`, `Age`, `Weddings`, `Divorces`, `Children`) render a neutral "Coming soon" placeholder instead of 404.
4. Tooling parity with `webtrees-fan-chart` / `-pedigree-chart` / `-descendants-chart`: Biome, Rollup, Jest, PHPUnit ^12||^13, phpstan ^2, Makefile, GitHub Actions CI.
5. Reusable chart widgets (`DonutChart`, `WorldMap`, `ProgressList`, `TagCloud`) move into `webtrees-chart-lib` v1.6.0 and are consumed from there.
6. Existing webtrees-2.2 core APIs are reused wherever they exist; new logic is only written for genuinely missing functionality.

### Non-goals (Phase 1)

- No new statistic types.
- No 1.0 release tag; module stays on `main` at `CUSTOM_VERSION = '1.0.0-dev'`.
- No replacement of webtrees core's `StatisticsChartModule` — admin keeps the choice via control panel.
- No admin config screen content (the interface is implemented for parity, body stays empty).

## 3. Sub-project sequencing

Two repos change, in this order:

1. **`magicsunday/webtrees-chart-lib`** → v1.6.0 (tag + `gh release` per `reference_release_no_makefile`). chart-lib is a **pure JS / npm** package (no composer.json); fan-chart consumes it via `"@magicsunday/webtrees-chart-lib": "github:magicsunday/webtrees-chart-lib#v1.5.1"` in `package.json`.
2. **`magicsunday/webtrees-statistics`** consumes the new chart-lib via npm, stays on `main` without release tag.

Step 1 must be tagged before step 2 can pin `…#v1.6.0` in `package.json`. Per memory `feedback_chart_lib_consumer_integration`, the consumer's `compose.yaml` mounts the sibling chart-lib repo and the symlink picks up new exports before CI is green.

## 4. chart-lib v1.6.0 — widget additions

### 4.1 New files

```
src/chart/widgets/
├── base-widget.js
├── donut-chart.js
├── world-map.js
├── progress-list.js
└── tag-cloud.js

tests/widgets/
├── base-widget.test.js
├── donut-chart.test.js
├── world-map.test.js
├── progress-list.test.js
└── tag-cloud.test.js
```

### 4.2 Widget API contract

All widgets share this surface:

```js
const w = new <Widget>(targetIdOrElement, options);
const svgNode = w.draw(data);
```

- `targetIdOrElement`: string id (with or without `#`) or a `HTMLElement`.
- `options`: per-widget options (see below). All options are optional and have documented defaults.
- `data`: array of plain objects. Empty array MUST render a neutral empty-state element with a translated "No data available" label — caller must not have to guard.
- Returns the created top-level node (caller may attach `aria-*`, `role`, etc.).

#### DonutChart

```js
new DonutChart(target, { holeSize?, margin?, width?, height? })
  .draw([{ label, value, class?, fill? }, …])
```

- `holeSize` default `radius - radius/10`
- segment classes are passed through verbatim (caller controls coloring via CSS)
- `<title>` per segment for native tooltip

#### WorldMap

```js
new WorldMap(target, { geojson, projection?, colorScale? })
  .draw([{ countryCode, label, count }, …])
```

- `geojson` is **required** (consumer-owned data, see §5.5)
- `projection` default `d3-geo` `geoEquirectangular()` — overridable
- `colorScale` default `d3-scale` `scaleSequential(d3-scale-chromatic.interpolateBlues)` with domain derived from data
- joins data to geojson features by ISO-3166-1 alpha-2 code matching `feature.properties.iso_a2` (case-insensitive)
- missing-data countries get neutral fill, present in legend as "No data"

#### ProgressList

```js
new ProgressList(target, { maxItems?, formatter? })
  .draw([{ label, value, total? }, …])
```

- HTML-based (`<ul>` + inline `<div>` bars), not SVG (Memory `feedback_css_nowrap_maxwidth_trap`: respects wrap+clamp on long labels)
- `total` per row is optional; falls back to max value in dataset
- `formatter` for value display (defaults to `toLocaleString()`)

#### TagCloud

```js
new TagCloud(target, { minFont?, maxFont?, rotate? })
  .draw([{ label, value }, …])
```

- own SVG layout (no `d3-cloud` dependency — adds 50KB)
- `minFont` / `maxFont` default `10` / `48` px
- `rotate` default `false` (rotated labels collide more on responsive widths)
- linear value→font-size scale via `d3-scale`

### 4.3 New `src/index.js` exports

Appended to existing exports:

```js
export { default as BaseWidget } from "./chart/widgets/base-widget.js";
export { default as DonutChart } from "./chart/widgets/donut-chart.js";
export { default as WorldMap } from "./chart/widgets/world-map.js";
export { default as ProgressList } from "./chart/widgets/progress-list.js";
export { default as TagCloud } from "./chart/widgets/tag-cloud.js";
```

### 4.4 New runtime deps in `chart-lib/package.json`

Add to existing modular d3 set (`d3-selection`, `d3-transition`, `d3-zoom`):

- `d3-array` (extent for scale domains)
- `d3-geo` (WorldMap projection + geoPath)
- `d3-scale` (linear, sequential)
- `d3-shape` (arc, pie)
- `d3-scale-chromatic` (default color interpolators)

No new `devDependencies` — Jest/Biome are already present.

### 4.5 Backwards compatibility & version

- All existing exports (Storage, Orientation, ChartExport, family-color, …) untouched.
- Pure additive change → minor bump from `v1.5.1` to `v1.6.0`.
- README §"Widgets" section added documenting new API surface.

### 4.6 Testing

- Per-widget Jest tests: instantiation, `draw([])` renders empty-state, `draw(sampleData)` produces expected DOM structure (number of paths/text/list-items), option overrides take effect.
- jsdom polyfills if needed (Memory `feedback_jsdom_polyfills`).
- No visual-regression tests in Phase 1.

### 4.7 Release

1. `npm test` + Biome green (chart-lib has no PHP).
2. Browser-verify by importing into webtrees-statistics workspace via symlink before tagging.
3. `git tag v1.6.0`, `gh release create v1.6.0 --generate-notes`.

## 5. webtrees-statistics — Phase 1 changes

### 5.1 `composer.json`

```json
{
    "name": "magicsunday/webtrees-statistics",
    "type": "webtrees-module",
    "require": {
        "php": "8.3 - 8.5",
        "ext-dom": "*",
        "ext-json": "*",
        "fisharebest/webtrees": "~2.2.0 || dev-main",
        "magicsunday/webtrees-module-base": "^2.2",
        "magicsunday/webtrees-module-installer-plugin": "^1.3"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.50",
        "overtrue/phplint": "^9.0",
        "phpstan/phpstan": "^2.0",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "phpstan/phpstan-phpunit": "^2.0",
        "phpstan/phpstan-strict-rules": "^2.0",
        "phpunit/phpunit": "^12.0 || ^13.0",
        "rector/rector": "^2.0"
    }
}
```

- Name corrected from `…-statistic` (singular) to `…-statistics`.
- All require-dev versions match `webtrees-fan-chart` exactly (Memory `feedback_modules_identical_tooling`).
- chart-lib is **not** a composer dep; see `package.json` below.

`package.json` adds:

```json
"dependencies": {
    "@magicsunday/webtrees-chart-lib": "github:magicsunday/webtrees-chart-lib#v1.6.0",
    "d3-array": "^3.2",
    "d3-fetch": "^3.0",
    "d3-geo": "^3.1",
    "d3-scale": "^4.0",
    "d3-scale-chromatic": "^3.0",
    "d3-selection": "^3.0",
    "d3-shape": "^3.2"
}
```

Existing `d3` umbrella package is dropped; widgets pull from chart-lib's modular d3 surface.

### 5.2 `module.php`

Mirrors fan-chart layout. chart-lib has **no PHP source**, so the PSR-4 loader stays at two lines:

```php
$loader->addPsr4('MagicSunday\\Webtrees\\ModuleBase\\', __DIR__ . '/vendor/magicsunday/webtrees-module-base/src');
$loader->addPsr4('MagicSunday\\Webtrees\\Statistic\\',  __DIR__ . '/src');
```

### 5.3 `src/Module.php`

Interfaces grow to match the chart-module blueprint:

```php
class Module extends StatisticsChartModule implements
    ModuleAssetUrlInterface,
    ModuleCustomInterface,
    ModuleConfigInterface
{
    use ModuleAssetUrlTrait;
    use ModuleCustomTrait;
    use ModuleChartTrait;
    use ModuleConfigTrait;
    // …
}
```

- Inheritance from `StatisticsChartModule` kept (user direction; same pattern as fan-chart extending `FanChartModule`).
- `CUSTOM_VERSION` stays `'1.0.0-dev'` until Phase N.
- Five new stub actions: `getRelationshipsAction`, `getAgeAction`, `getWeddingsAction`, `getDivorcesAction`, `getChildrenAction`. Each renders `Templates/ComingSoon` with the tab label. Tabs list in `getChartAction` unchanged.
- Asset URL line `'javascript' => $this->assetUrl('js/webtrees-statistics.js'),` switches to the minified bundle in production via `Module::isProduction()` or simply ships only `webtrees-statistics.min.js` (fan-chart pattern).

### 5.4 PHP service layer — reuse vs. local

**Inject `Fisharebest\Webtrees\StatisticsData` into our `Statistic` aggregator** (replaces the old 4-service chain).

Local repositories become **thin delegators** to `StatisticsData` for everything that core covers, plus local methods for the gaps:

| Local method                                   | webtrees 2.2 source                                                  |
|------------------------------------------------|----------------------------------------------------------------------|
| `IndividualRepository::getTotalIndividuals()`  | `StatisticsData::countIndividuals()`                                 |
| `IndividualRepository::getTotalSexMale()`      | `StatisticsData::countIndividualsBySex('M')`                         |
| `IndividualRepository::getTotalSexFemale()`    | `StatisticsData::countIndividualsBySex('F')`                         |
| `IndividualRepository::getTotalSexUnknown()`   | `StatisticsData::countIndividualsBySex('U')`                         |
| `IndividualRepository::getTotalLiving()`       | `StatisticsData::countIndividualsLiving()`                           |
| `IndividualRepository::getTotalDeceased()`     | `StatisticsData::countIndividualsDeceased()`                         |
| `FamilyRepository::getTotalMarriedMales()`     | `StatisticsData::countMarriedMales()`                                |
| `FamilyRepository::getTotalMarriedFemales()`   | `StatisticsData::countMarriedFemales()`                              |
| `FamilyRepository::getTotalWidowed()`          | **new** — query families where one spouse has DEAT, other doesn't    |
| `FamilyRepository::getTotalDivorced()`         | **new** — query families with `1 DIV` fact in `f_gedcom`             |
| `NameRepository::getTopSurnames(limit)`        | `StatisticsData::commonSurnames(limit, 1, 'count')` adapter          |
| `NameRepository::getTopMaleGivenNames(limit)`  | `StatisticsData::commonGivenNames('M', 1, limit)` adapter            |
| `EventRepository::getBirthsByMonth()`          | `StatisticsData::countEventsByMonth('BIRT', 0, 0)` + month-name xlt  |
| `EventRepository::getDeathsByMonth()`          | `StatisticsData::countEventsByMonth('DEAT', 0, 0)` + month-name xlt  |
| `EventRepository::getBirthsByCentury()`        | `StatisticsData::countEventsByCentury('BIRT')`                       |
| `EventRepository::getDeathsByCentury()`        | `StatisticsData::countEventsByCentury('DEAT')`                       |
| `EventRepository::getBirthsByCountry()`        | `StatisticsData::countIndividualEventsByCountry($tree, 'BIRT')` + mapping |
| `EventRepository::getDeathsByCountry()`        | `StatisticsData::countIndividualEventsByCountry($tree, 'DEAT')` + mapping |
| `EventRepository::getBirthsByZodiacSign()`     | **stays local** — no core equivalent                                 |

Country mapping helper: a private method `mapCountryRows(array $rows): array` converts core's chart-formatted output to `[{countryCode, label, count}]` shape needed by our `WorldMap` widget.

### 5.5 `Statistic.php`

- Constructor: `__construct(private readonly Tree $tree, private readonly StatisticsData $data, /* repos via DI */)`.
- All four hardcoded German strings → `I18N::translate('Married')`, `…('Single')` (or `…('Single (never married)')` if a core string already exists; verify with `grep` before adding), `…('Widowed')`, `…('Divorced')`.
- Hardcoded `38` and `0` removed; replaced with `$familyRepository->getTotalWidowed()` / `…->getTotalDivorced()`.
- Commented-out color-interpolation block (Z. 209–228) deleted.
- Per-method PHPDoc with capitalised descriptions (Memory `feedback_proper_phpdoc_everywhere` + `feedback_phpdoc_capital_descriptions`).

### 5.6 `EventRepository.php` — commented-out code triage

Three commented blocks reviewed individually:

1. **Z. 109–115 — `getTotalBirths()`**: only useful if some template prints a total. Audit-loop checks templates; if unused, delete. If used, re-implement as `StatisticsData::countAllEvents(['BIRT'])` one-liner.
2. **Z. 180–186 — `getTotalDeaths()`**: same as above for DEAT.
3. **Z. 564–648 — `getEventsGroupedByCountry()`**: superseded by `StatisticsData::countIndividualEventsByCountry()`. **Delete.**

Decision recorded in commit message of the cleanup commit.

### 5.7 Views

```
resources/views/modules/statistics-chart/
├── page.phtml                          # existing, escape audit
├── Templates/
│   ├── Overview.phtml                  # existing, refactored to data-widget
│   ├── Places.phtml                    # existing, refactored to data-widget
│   ├── Births.phtml                    # existing, refactored to data-widget
│   ├── Deaths.phtml                    # existing, refactored to data-widget
│   ├── Relationships.phtml             # new — wraps ComingSoon
│   ├── Age.phtml                       # new — wraps ComingSoon
│   ├── Weddings.phtml                  # new — wraps ComingSoon
│   ├── Divorces.phtml                  # new — wraps ComingSoon
│   ├── Children.phtml                  # new — wraps ComingSoon
│   └── ComingSoon.phtml                # new — neutral placeholder
└── Partials/
    ├── DonutChart.phtml                # existing, emits data-widget="donut" + JSON
    ├── GeoMap.phtml                    # existing, emits data-widget="map" + geojson-url + JSON
    ├── ProgressList.phtml              # existing, emits data-widget="list" + JSON
    └── TagCloud.phtml                  # existing, emits data-widget="cloud" + JSON
```

All escape-audited: `e()` wraps every interpolated string inside HTML attributes (Memory `feedback_html_attr_escape`).

### 5.8 JS layer

```
resources/js/modules/
├── index.js                            # entry: querySelectorAll([data-widget]) → instantiate
└── widgets/
    ├── donut.js                        # imports DonutChart from chart-lib
    ├── world-map.js                    # fetch geojson, then chart-lib WorldMap
    ├── progress-list.js
    └── tag-cloud.js
```

- No local `lib/d3.js`; chart-lib bundles modular d3.
- `world-map.geojson` location: stays at `resources/js/world-map.geojson` (fan-chart has no `resources/data/` precedent; keeping current location minimizes churn).
- Entry parses `data-widget`, `data-payload` (JSON), and widget-specific `data-*` options.
- Rollup config mirrors fan-chart's: dev + min bundles, banner without timestamp (Memory `feedback_rollup_banner_idempotent`).

### 5.9 CSS

`resources/css/webtrees-statistics.css`:

- chart-css-variable pattern (Memory `reference_chart_css_var_pattern`): `--chart-text-{primary,secondary,muted}`, `--chart-connector-stroke`, `--chart-placeholder-bg`, all with bootstrap fallback.
- Dark-mode handled via webtrees' theme variables.
- No `.family-colors` scope override (no genealogy color logic in statistics).

### 5.10 Tooling parity (Phase 1)

Copied byte-identical from fan-chart (Memory `feedback_modules_identical_tooling`), then adapted only where the module legitimately differs:

- `biome.json`
- `phpunit.xml`
- `jest.config.js`
- `rollup.config.js` (consumer entries differ: `index.js` instead of fan-chart's)
- `.editorconfig`
- `.github/workflows/ci.yml`
- `.github/workflows/release.yml` (kept for future; tag-driven release flow)
- `Makefile` (build/lang/release/link-base/link-chart-lib targets — link-chart-lib new)
- `phpstan.neon`
- `.php-cs-fixer.dist.php`
- `.phplint.yml`
- `rector.php`

### 5.11 Stubs & error handling

- `Templates/ComingSoon.phtml`: centered icon + `I18N::translate('This statistic is planned for a future release.')`. Accepts `$tabLabel` for heading.
- Each stub action returns `viewResponse('Templates/ComingSoon', ['module' => …, 'tabLabel' => …])` with `layout = 'layouts/ajax'`.
- Empty-data behavior is handled inside chart-lib widgets, not at template level.
- Repository methods return `[]` for empty datasets; never null.

### 5.12 Testing

PHPUnit (mirrors fan-chart structure):

```
tests/
├── ModuleTest.php                      # boot, title(), description(), routes registered
├── StatisticTest.php                   # service end-to-end with in-memory tree
├── Repository/
│   ├── IndividualRepositoryTest.php
│   ├── FamilyRepositoryTest.php        # incl. widowed/divorced
│   ├── EventRepositoryTest.php         # incl. zodiac sign correctness
│   └── NameRepositoryTest.php
```

- Use webtrees test-fixture tree builder (`tree --create` + `tree-import` per Memory `reference_webtrees_cli`).
- Behavioral assertions only (Memory `feedback_meaningful_tests_only`): no getter/setter tests, every test checks real output for a real input.
- Stubs: a test per stub action that asserts the response renders the ComingSoon partial.

Jest:

```
tests/js/
└── widgets/
    ├── donut.test.js
    ├── world-map.test.js               # fetch mock for geojson
    ├── progress-list.test.js
    └── tag-cloud.test.js
```

CI: `composer ci:test` aggregates phplint, phpstan, rector --dry-run, phpunit, jest, biome. Workflow runs on every PR + push to main.

### 5.13 Granular commit plan

**chart-lib v1.6.0:**

| # | Subject                                        |
|---|------------------------------------------------|
| c1 | Add BaseWidget + jest test scaffold           |
| c2 | Add DonutChart widget + tests                 |
| c3 | Add WorldMap widget + tests                   |
| c4 | Add ProgressList widget + tests               |
| c5 | Add TagCloud widget + tests                   |
| c6 | Export new widgets from index.js + d3 deps    |
| c7 | Bump version + README widgets section         |
| —  | `git tag v1.6.0` + `gh release create`        |

**webtrees-statistics phase-1:**

| #   | Subject                                                              |
|-----|----------------------------------------------------------------------|
| s1  | Bump composer.json to webtrees 2.2 + correct package name            |
| s2  | Add tooling parity (biome/phpunit/jest/rollup/CI/Makefile/editorconfig) |
| s3  | Bump package.json to chart-lib v1.6.0 + modular d3 deps              |
| s4  | Module: add ModuleAssetUrl + Config interfaces                       |
| s5  | Module: add five stub actions + ComingSoon template                  |
| s6  | Statistic: inject StatisticsData + delete dead 4-service chain       |
| s7  | Repositories: delegate to StatisticsData where available             |
| s8  | FamilyRepository: add Widowed + Divorced queries                     |
| s9  | EventRepository: replace local country code with StatisticsData; delete dead block |
| s10 | EventRepository: triage remaining commented-out totals               |
| s11 | Statistic: I18N + remove hardcoded 38/0                              |
| s12 | JS: switch to chart-lib widgets; drop vendored d3                    |
| s13 | Views: refactor Partials to data-widget pattern                      |
| s14 | CSS: chart-css-variable pattern + dark mode                          |
| s15 | PHPUnit tests (repos + service + module)                             |
| s16 | Jest tests (JS widgets entry)                                        |
| s17 | README + AGENTS.md update (drop "!!! WIP !!!")                       |
| s18 | Browser-verify all 9 tabs (screenshots in /tmp)                      |

Each commit must pass the audit-loop (§6) and `composer ci:test`.

## 6. Audit-loop workflow — applied to every commit

This is **not optional** and **not summary** — every commit follows the same loop (per user directive 2026-05-20: "vor jedem commit den audit-loop mit allen reviewern fahren").

### 6.1 Reviewer set

Always spawned in parallel:

- `compound-engineering:ce-correctness-reviewer`
- `compound-engineering:ce-maintainability-reviewer`
- `compound-engineering:ce-testing-reviewer`
- `compound-engineering:ce-project-standards-reviewer`

Conditional (added when the diff matches their trigger):

- `php-reviewer` (user-local rules) ⟵ if `*.php` changed
- `compound-engineering:ce-julik-frontend-races-reviewer` ⟵ if `*.js` with async/lifecycle changed
- `compound-engineering:ce-security-reviewer` ⟵ if input handling, routes, or escaping changed
- `compound-engineering:ce-reliability-reviewer` ⟵ if SQL, DB queries, or external IO changed
- `webtrees-frontend-reviewer` ⟵ if JS/CSS/D3/SVG/admin-UI changed
- `compound-engineering:ce-adversarial-reviewer` ⟵ if diff ≥50 lines or touches auth/payments/data-mutations
- `webtrees-test-quality-reviewer` ⟵ if `tests/**` touched

### 6.2 Iteration rules

1. Run all relevant reviewers in parallel.
2. Apply fixes for actionable findings (Memory `feedback_no_preexisting_excuse`: every defect must be fixed in-session).
3. Re-run the full reviewer set.
4. Repeat until **2× consecutive clean rounds** (Memory `feedback_per_issue_double_audit_loop`).
5. Record results via `audit-state.sh record-clean` / `record-findings` after every round (Memory `feedback_use_audit_state_actively`).
6. Run `composer ci:test` via buildbox (Memory `feedback_ci_test_workflow`); must be green.
7. For UI changes, browser-verify with Playwright (Memory `feedback_self_verify_browser`); screenshots in `/tmp/` (Memory `feedback_no_screenshots_root`).
8. Commit with capitalised verb subject; no Conventional-Commits prefix, no Co-Authored-By (Memory `feedback_commit_message_style` + `feedback_no_coauthor`).

### 6.3 Reviewer-scope safety

- Each audit round reviews the **task-level scope**, not just the latest patch diff (Memory `feedback_review_full_task_scope`).
- Audit confirms intent matches mechanics, not just that mechanics work (Memory `feedback_audit_intent_not_just_mechanics`).
- Reviewer suggestions are cross-checked against existing repo patterns before applying (Memory `feedback_cross_check_reviewer_suggestions`).

## 7. Risks & mitigations

| Risk                                                               | Mitigation                                                  |
|--------------------------------------------------------------------|-------------------------------------------------------------|
| Double "Statistics" menu entry when core module is enabled          | README documents disabling core; not enforced in code        |
| `StatisticsData::countIndividualEventsByCountry` output shape drift | Audit round explicitly inspects the mapping function         |
| chart-lib v1.6.0 not yet tagged when consumer CI runs               | Implementation order tags chart-lib **first**, then consumer |
| Stub tabs visible in production confuse users                       | Heading reads "Coming soon" + tab order unchanged; doc-noted |
| webtrees 2.2 vendor folder on WIP branch (Memory note)              | Use git worktree if upstream PR work is needed (separate)   |
| Jest+jsdom 30 missing TextEncoder/ReadableStream                   | Polyfill in setup.js (Memory `feedback_jsdom_polyfills`)    |

## 8. Out of scope (Phase 2+)

- Real implementations for Relationships, Age, Weddings, Divorces, Children tabs.
- Color interpolation logic in `getTopSurnames` (currently commented-out).
- Performance tuning (caching, query batching).
- Multi-tree comparison.
- Export (PNG/SVG download via `ChartExport`).
- `ModuleConfigInterface` admin screen body.

## 9. Verification at end of Phase 1

Phase 1 is considered done when **all** of the following hold:

1. `composer install` resolves cleanly against webtrees 2.2 demo install.
2. Control-panel shows the module; chart menu link navigates to a tab page.
3. All nine tabs return HTTP 200 (five real, five ComingSoon).
4. Five real tabs render with non-empty data on the demo tree.
5. No hardcoded German strings remain (`grep -r 'Verheiratet\|Allein\|Verwitwet\|Geschieden' src resources` clean).
6. `composer ci:test` green.
7. Jest tests green.
8. CI workflow on PR + push green.
9. Playwright screenshots of all nine tabs saved under `/tmp/statistics-phase-1/`.
10. Audit-loop double-clean recorded for every commit.

A 1.0 release tag is **not** part of Phase 1 — explicitly deferred to a later phase when Relationships/Age/Weddings/Divorces/Children carry real data.
