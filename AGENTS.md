## Overview
This repository hosts the webtrees statistics module — a six-tab dashboard of tree-wide statistics built on the chart-lib widgets, installed as a Composer package inside webtrees. The tabs are organised by data nature: Overview (population summary), Names (surnames + given names), Tree health (data-quality), Life span (births + deaths + lifespan distributions), Family (marriage / divorce / children / kinship aggregates), Places (geographical distributions).

## Setup/env
- PHP 8.3+ with extensions dom and json is required; composer installs dependencies into `.build/vendor` and binaries into `.build/bin`.
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
- Translations: `make lang` (compile .po → .mo). All locale files must have non-empty `msgstr` entries.
- Add PHPUnit attribute-based coverage (positive AND negative cases) for every class/method introduced or modified — the project follows the "test every class" standard.
- PHPStan runs at `level: max` against `src/` with no baseline. Every change fixes the underlying defect; the baseline file is intentionally absent so future drift cannot be ignored.

## Architecture

### Data flow: PHP → Partial templates → chart-lib widgets

```
Module.php (entry point, registers route + 6 tab action methods)
  → Templates/<TabName>.phtml (tab body — Overview, Names, TreeHealth, LifeSpan, Family, Places)
    → Partials/<WidgetName>.phtml (DonutChart, ProgressList, GeoMap, TagCloud)
      → @magicsunday/webtrees-chart-lib widgets render the d3 visualisation
```

Every tab action delegates to the private `renderTab(string $template)` helper, which loads `Templates/<template>.phtml` with the active `Statistic` aggregator. Adding a new tab is a three-place change: add an entry to `tabCatalog()`, add a `get<Name>Action()` method that calls `renderTab('<Name>')`, and create `Templates/<Name>.phtml`.

The `Statistic` aggregator service is resolved via the webtrees DI container (`Registry::container()->get(Statistic::class)`) and aggregates data from three repositories plus core's `StatisticsData`.

### PHP (`src/`)
- **`Module.php`** — Entry point, extends webtrees `StatisticsChartModule`, implements `ModuleAssetUrlInterface` + `ModuleCustomInterface`. Registers six tab actions (Overview, Names, TreeHealth, LifeSpan, Family, Places) that all delegate to a shared `renderTab()` helper.
- **`Statistic.php`** — `final readonly` aggregator service. Constructor-injects `StatisticsData` (core) plus `FamilyRepository`, `EventRepository`, `NameRepository`. Public methods return either scalars or `[{label, value, class?}]` shapes for chart-lib widgets. Country-grouped queries return `[]` until core exposes a public accessor (`@todo` marker in the docblock).
- **`MaritalBucket.php`** — Backed enum (`current` / `divorced` / `widowed` / `single`) used as the typed bucket-key for `FamilyRepository::classifyLivingIndividuals()`.
- **`Repository/FamilyRepository.php`** — Classifies every living individual into one marital bucket using the same per-family decision order as `\Fisharebest\Webtrees\Census\AbstractCensusColumnCondition::generate()`. The four bucket counts sum exactly to `StatisticsData::countIndividualsLiving()`. Local constants (`MARRIAGE_TAGS = ['MARR']`, `DIVORCE_TAGS = ['DIV', 'ANUL']`) deliberately differ from `Gedcom::MARRIAGE_EVENTS` / `Gedcom::DIVORCE_EVENTS` because the latter include `_NMR` (not married) and `_SEPR` (separated, not divorced) which would invert the bucket semantics.
- **`Repository/EventRepository.php`** — Single method `getBirthsByZodiacSign()` since core's `StatisticsData` does not expose zodiac grouping. Month / century / country groupings delegate to `StatisticsData` via the aggregator instead.
- **`Repository/NameRepository.php`** — Distinct primary-name counts for surnames + given names, restricted to `n_num = 0` to avoid AKA/alias inflation. Bypasses `StatisticsData::commonSurnames` / `commonGivenNames` whose `int $limit` argument feeds SQL `LIMIT` (or `Collection::slice`) and silently returns 0 when passed 0.

### Views (`resources/views/modules/statistics-chart/`)
- **`page.phtml`** — Outer six-tab navigation. Each tab anchor loads the matching tab body lazily.
- **`Templates/Overview.phtml`** — Three donut cards (sex, living/deceased, marital status).
- **`Templates/Names.phtml`** — Three tag-cloud cards (common surnames, male given names, female given names).
- **`Templates/LifeSpan.phtml`** — Five progress-list cards (births by month / zodiac / century, deaths by month / century).
- **`Templates/Places.phtml`** — Country-of-birth and country-of-death maps with companion top-10 lists.
- **`Templates/TreeHealth.phtml`** + **`Templates/Family.phtml`** — Placeholder tabs that will be filled by upcoming widget issues.
- **`Partials/<Widget>.phtml`** — Thin shells that emit the `data-wmu-widget` JSON marker and the empty target element consumed by chart-lib widgets.

### JS (`resources/js/modules/`)
Tab dispatcher reads `data-wmu-widget` on each Partial root and instantiates the matching chart-lib widget (`DonutChart`, `WorldMap`, `ProgressList`, `TagCloud`). All d3 modules are peer-dependencies pulled in via `package.json` — `d3-shape`, `d3-geo`, `d3-scale`, `d3-scale-chromatic`, `d3-array` — declared as `external` in `rollup.config.js`.

## Key patterns
- **Bucket precedence (per individual)**: current > divorced > widowed > single. Applied in `FamilyRepository::classifyOneIndividual()` so a remarried-after-widowed living person is classed as "current", not "widowed".
- **Family precedence (per family row)**: divorced > widowed > current > non-contributing. Matches the upstream Census decision tree.
- **Orphaned spouse XREF**: when a partner record is missing from the individuals table, the classifier neither marks the survivor as widowed nor as current — it abstains from that family entirely so the count is conservative.
- **Empty-string XREFs**: webtrees stores `''` (not `NULL`) when an INDI's `1 HUSB`/`1 WIFE` line is absent. `partnerIdOf()` normalises both shapes to `null`.
- **Anchored tag matching**: `hasAnyTagAnchored()` requires `\n1 <tag>` followed by space, newline, or end-of-string so that `DIV` does not match `DIVF`. Both the partner-death check and the family-event check use the same anchored helper.

## Design principles
- Priority order on conflict: **KISS > SOLID > DRY > YAGNI > GRASP > Law of Demeter > Separation of Concerns > Convention over Configuration**.
- `declare(strict_types=1)`, no `mixed`, no `empty()`, no `@deprecated`, typed class constants, `final readonly` where applicable, qualified `use function` imports, PHPDoc on every class and method, English-only comments.
- One class per file. Write tests for every class. Use PHPUnit `#[Test]` attributes (not docblock annotations).
- Prefer `array_find` / `array_any` / `array_all` over manual `foreach` for "find one" / "any match" / "all match" intents.
