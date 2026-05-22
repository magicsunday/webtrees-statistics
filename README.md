[![Latest version](https://img.shields.io/github/v/release/magicsunday/webtrees-statistics?sort=semver)](https://github.com/magicsunday/webtrees-statistics/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/webtrees-statistics)](https://github.com/magicsunday/webtrees-statistics/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/webtrees-statistics/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/webtrees-statistics/actions/workflows/ci.yml)


# Statistics
A tab-based statistics dashboard for the [webtrees](https://www.webtrees.net) genealogy application.

Renders tree-wide statistics across six tabs (Overview, Names, Tree health, Life span, Family, Places) using donut charts, progress lists, world maps, tag clouds, line / bar / stacked / diverging-bar charts, sankey flows, chord diagrams and stream graphs. Built on the shared widget library [`@magicsunday/webtrees-chart-lib`](https://github.com/magicsunday/webtrees-chart-lib).


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
All six tabs render populated content out of the box:

| Tab          | Content                                                                                                                                                                                                  |
|--------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Overview     | Sex / Living-deceased / Marital-status donuts; top-15 occupations + top-15 religions progress lists; "Tree records" hall-of-fame table (oldest deceased / living, longest / shortest marriage, youngest / oldest spouse at marriage, most spouses, largest family, most children per person, parent-of-first-child records) |
| Names        | Common-surname + male / female given-name tag clouds, given-name popularity stream graph (top-10 by decade), surname × surname marriage chord matrix                                                     |
| Tree health  | Source-citation coverage RateList, missing-event-gaps RateList, average generation length Scalar, tree-growth LineChart (births per decade)                                                              |
| Life span    | Births by month / zodiac sign / century, deaths by month / century, winter-peak score, age-at-death histogram (10-year bands), age-band donut, child mortality, average lifespan by sex+century, top-10 oldest deceased + living, top-15 causes of death |
| Family       | Age at marriage M+F (co-trimmed), marriage duration, couple age gap DivergingBar, weddings century + month, divorces century + month + age M+F, divorces-by-century-and-age-band StackedBar, divorce-rate by cohort, age at first child M+F (co-trimmed), children-per-family, sibling-age-gap, family-size composition by decade, average family size by century, top-10 largest families, childless donut, ancestor count, average pedigree completeness, endogamy, generation-depth + distribution, first children by month |
| Places       | Birth-country world map + top-10 list, death-country world map + top-10 list, recorded-residences top-10, distinct-places-per-individual distribution, birth → death migration Sankey, place-dispersion scalar                                     |

The marital-status donut counts each living individual exactly once. Precedence follows the same per-family decision order webtrees core uses in `\Fisharebest\Webtrees\Census\AbstractCensusColumnCondition`: an active divorce tag classes the survivor as `divorced`, a deceased partner as `widowed`, an active marriage with a living partner as `current`. Anything else falls into `single`. The four buckets sum exactly to `StatisticsData::countIndividualsLiving()` without clamping.


## Architecture
The aggregator service `MagicSunday\Webtrees\Statistic\Statistic` is resolved through the webtrees DI container and composes twenty-one per-domain repositories plus core's `StatisticsData`. The repositories split by data nature — names, events, life-span, marriages, divorces, children, parenthood, kinship, endogamy, generation depth, migration, places, marriage matrix, tree health, and three Top-N tag repositories (occupations, religions, death causes) that share an `AbstractGedcomTagTopNRepository` base.

Each tab renders a template under `resources/views/modules/statistics-chart/Templates/` that composes Partials (`DonutChart`, `ProgressList`, `LineChart`, `BarChart`, `StackedBar`, `DivergingBar`, `AreaDensity`, `GeoMap`, `SankeyFlow`, `ChordDiagram`, `StreamGraph`, `TagCloud`, `RateList`, `Scalar`, …) which emit `data-widget`/`data-payload`/`data-options` JSON markers. The shared JS dispatcher `WebtreesStatistic.renderWidgets(pane)` then hands each off to the matching [`@magicsunday/webtrees-chart-lib`](https://github.com/magicsunday/webtrees-chart-lib) widget.


## Theming
The module ships a single stylesheet (`resources/css/statistics.css`) that is loaded once per page through `View::push('styles')`. All colours are defined as `--wmstats-*` custom properties on the `.wt-statistics-chart` root, with a `[data-bs-theme="dark"]` override block that lifts the donut and progress-bar palette into a dark-friendly range. Webtrees themes that toggle `data-bs-theme` on `<html>` switch the module's visuals automatically.


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
