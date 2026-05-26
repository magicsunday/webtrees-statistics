[![Latest version](https://img.shields.io/github/v/release/magicsunday/webtrees-statistics?sort=semver)](https://github.com/magicsunday/webtrees-statistics/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/webtrees-statistics)](https://github.com/magicsunday/webtrees-statistics/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/webtrees-statistics/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/webtrees-statistics/actions/workflows/ci.yml)


# Statistics
A tab-based statistics dashboard for the [webtrees](https://www.webtrees.net) genealogy application.

Renders tree-wide statistics across six tabs (Overview, Names, Tree health, Life span, Family, Places) using donut charts, progress lists, world maps, tag clouds, line / bar / stacked / diverging-bar charts, sankey flows, chord diagrams and stream graphs. Built on the shared widget library [`@magicsunday/webtrees-chart-lib`](https://github.com/magicsunday/webtrees-chart-lib).

The dashboard ships in an Editorial layout: a dark hero strip carrying the tree title, headline metrics and a deck that names the centuries the tree spans; a sticky numbered tab nav (`01 / 02 / 03 …`); per-tab section dividers grouping cards thematically, with each card framing its widget in a serif title, eyebrow tag, accent colour and a corner illustration.


<!-- TOC -->
* [Statistics](#statistics)
  * [Requirements](#requirements)
  * [Installation](#installation)
    * [Using Composer](#using-composer)
    * [Using Git](#using-git)
  * [What renders today](#what-renders-today)
  * [Architecture](#architecture)
  * [Theming](#theming)
  * [Development](#development)
    * [Build the assets](#build-the-assets)
    * [Run tests](#run-tests)
<!-- TOC -->


## Requirements
- webtrees 2.2 or newer
- PHP 8.3 or newer

## Installation

### Using Composer
To install using [Composer](https://getcomposer.org/), run the following command from the root directory of your webtrees installation:

```shell
composer require magicsunday/webtrees-statistics:* --update-no-dev
```

The module installs into the `modules_v4` directory of your webtrees installation automatically.

To remove it:

```shell
composer remove magicsunday/webtrees-statistics --update-no-dev
```

### Using Git
If you prefer to track the current main branch directly, clone it into your `modules_v4` directory:

```shell
git clone https://github.com/magicsunday/webtrees-statistics.git modules_v4/webtrees-statistics
```


## What renders today
Above every tab a dark hero strip carries the live tree title in the eyebrow, the static "Statistics" headline, a deck that names the centuries the tree spans, and a six-stat readout (individuals, families, max generation depth, average generation length, pedigree completeness, sourced-individuals share). Below the hero, a sticky numbered tab nav (`01 Overview` … `06 Places`) frames the per-tab grids.

Each tab renders into a 12-column `wt-stat-grid` shell with Section dividers grouping cards thematically:

| Tab         | Sections                                                                                              |
|-------------|--------------------------------------------------------------------------------------------------------|
| Overview    | DEMOGRAPHICS (sex / living / marital donuts) · CHRONICLE (tree-records hall-of-fame) · SOCIO-ECONOMICS (occupations / religions) |
| Names       | FREQUENCIES (surname / male / female tag clouds) · EVOLUTION (given-name stream graph · surname × surname chord matrix) |
| Tree health | DOCUMENTATION (source-citation coverage · average generation length) · GAPS (missing-event gaps) · GROWTH (tree-growth line chart) |
| Life span   | LIFESPAN (age at death · living life-stage · lifespan by sex × century · top 10 oldest deceased + living) · MORTALITY (death causes · child mortality) · BIRTHS (century · month · zodiac) · DEATHS (century · month · winter-peak score) |
| Family      | MARRIAGE (age at marriage M+F · duration · couple age gap · weddings century + month) · PARENTHOOD (age at first child M+F · first children by month) · FAMILY SIZE (children per family · sibling gap · family-size by decade · average family size · families with/without children · top 10 largest) · DIVORCE (cohort rate · century · month · century × age band · age at divorce M+F) · STRUCTURE (known ancestors · pedigree completeness · max generation depth + chains · depth distribution · endogamy rate) |
| Places      | ORIGIN & FATE (birth / residence / death countries — each with top-10 list + world map) · MIGRATION (birth → death sankey flow) · MOBILITY (geographic dispersion · distinct places per individual) |

Every chart is wrapped in a generic `Card` partial that owns the eyebrow tag, serif title, sub-headline, accent illustration in the top-right corner, and an optional info popover that explains non-trivial metrics. The marital-status donut counts each living individual exactly once — precedence follows the same per-family decision order webtrees core uses in `\Fisharebest\Webtrees\Census\AbstractCensusColumnCondition`: an active divorce tag classes the survivor as `divorced`, a deceased partner as `widowed`, an active marriage with a living partner as `current`. Anything else falls into `single`. The four buckets sum exactly to `StatisticsData::countIndividualsLiving()` without clamping.


## Architecture
The aggregator service `MagicSunday\Webtrees\Statistic\Statistic` is resolved through the webtrees DI container and composes twenty-one per-domain repositories plus core's `StatisticsData`. The repositories split by data nature — names, events, life-span, marriages, divorces, children, parenthood, kinship, endogamy, generation depth, migration, places, marriage matrix, tree health, and three Top-N tag repositories (occupations, religions, death causes) that share an `AbstractGedcomTagTopNRepository` base.

Each tab template under `resources/views/modules/statistics-chart/Templates/` arranges its content through three layout partials — `Section.phtml` (kicker + serif title + sub), `Card.phtml` (eyebrow + title + sub + body slot + illustration + info popover), `Illustration.phtml` (22 thematic SVG icons keyed by name) — and feeds each Card body via the existing widget partials (`DonutChart`, `ProgressList`, `LineChart`, `BarChart`, `StackedBar`, `DivergingBar`, `AreaDensity`, `GeoMap`, `SankeyFlow`, `ChordDiagram`, `StreamGraph`, `NameBubbles`, `MonthRadial`, `MirrorHistogram`, `GaugeArc`, `Podium`, `Scalar`). The widget partials emit `data-widget` / `data-payload` / `data-options` JSON markers, and the shared JS dispatcher `WebtreesStatistic.renderWidgets(pane)` hands each off to the matching [`@magicsunday/webtrees-chart-lib`](https://github.com/magicsunday/webtrees-chart-lib) widget. The hero above the nav is driven by a `Hero.phtml` partial that consumes a `HeroStats` DTO returned by `Statistic::getHeroStats()`.


## Theming
The module ships a single stylesheet (`resources/css/statistics.css`) that is loaded once per page through `View::push('styles')`. The Editorial Modern palette lives in CSS custom properties on the `.wt-statistics-chart` root (`--paper`, `--card`, `--ink`, `--wine`, `--slate`, `--sage`, `--ochre`, `--rose`, plus geometry and typography tokens like `--card-radius`, `--serif`, `--sans`); a `[data-bs-theme="dark"]` override block lifts the surface and accent values into a dark-friendly range. Webtrees themes that toggle `data-bs-theme` on `<html>` switch the module's visuals automatically. The self-hosted typefaces (Instrument Serif + Geist, latin + latin-ext subsets, both SIL OFL 1.1) ship under `resources/fonts/` and load via `@font-face` declarations inlined into `page.phtml`.


## Development

### Build the assets
Install Node dependencies and build the JS bundle:

```shell
npm install
npm run prepare
```

### Run tests
The full pre-commit gate (PHPStan, PHP-CS-Fixer, Rector, PHPUnit, Biome, Jest, jscpd):

```shell
composer ci:test
```

Single PHPUnit class:

```shell
composer ci:test:php:unit -- --filter FamilyRepositoryClassifierTest
```

Auto-fix PHP style and Rector findings:

```shell
composer ci:cgl
composer ci:rector
```
