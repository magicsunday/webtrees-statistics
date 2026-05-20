[![Latest version](https://img.shields.io/github/v/release/magicsunday/webtrees-statistics?sort=semver)](https://github.com/magicsunday/webtrees-statistics/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/webtrees-statistics)](https://github.com/magicsunday/webtrees-statistics/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/webtrees-statistics/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/webtrees-statistics/actions/workflows/ci.yml)


# Statistics
A tab-based statistics dashboard for the [webtrees](https://www.webtrees.net) genealogy application.

Renders tree-wide statistics across nine tabs (Overview, Relationships, Places, Age, Births, Deaths, Weddings, Divorces, Children) using donut charts, progress lists, world maps, and tag clouds. Built on the shared widget library [`@magicsunday/webtrees-chart-lib`](https://github.com/magicsunday/webtrees-chart-lib).


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
Phase 1 ships four fully implemented tabs and five `Coming soon` placeholders:

| Tab            | Status      | Content                                                                                                        |
|----------------|-------------|----------------------------------------------------------------------------------------------------------------|
| Overview       | Implemented | Sex / Living-deceased / Marital-status donuts, Common-surname + male / female given-name tag clouds            |
| Places         | Implemented | Country-of-birth and country-of-death world maps with companion top-10 progress lists                          |
| Births         | Implemented | Births by month, by zodiac sign, by century                                                                    |
| Deaths         | Implemented | Deaths by month, by century                                                                                    |
| Relationships  | Coming soon | Tracked in [#2](https://github.com/magicsunday/webtrees-statistics/issues/2)                                   |
| Age            | Coming soon | Tracked in [#3](https://github.com/magicsunday/webtrees-statistics/issues/3)                                   |
| Weddings       | Coming soon | Tracked in [#4](https://github.com/magicsunday/webtrees-statistics/issues/4)                                   |
| Divorces       | Coming soon | Tracked in [#5](https://github.com/magicsunday/webtrees-statistics/issues/5)                                   |
| Children       | Coming soon | Tracked in [#6](https://github.com/magicsunday/webtrees-statistics/issues/6)                                   |

The marital-status donut counts each living individual exactly once. Precedence follows the same per-family decision order webtrees core uses in `\Fisharebest\Webtrees\Census\AbstractCensusColumnCondition`: an active divorce tag classes the survivor as `divorced`, a deceased partner as `widowed`, an active marriage with a living partner as `current`. Anything else falls into `single`. The four buckets sum exactly to `StatisticsData::countIndividualsLiving()` without clamping.


## Architecture
The aggregator service `MagicSunday\Webtrees\Statistic\Statistic` is resolved through the webtrees DI container and pulls data from four sources:

- `Fisharebest\Webtrees\StatisticsData` — core data accessor for individual, family, and event counts.
- `Repository/FamilyRepository` — Census-aligned marital classification not exposed by core.
- `Repository/EventRepository` — zodiac-sign grouping not exposed by core.
- `Repository/NameRepository` — primary-name distinct counts (`n_num = 0`) so totals stay in sync with the Top-N name lists.

Each tab renders a template under `resources/views/modules/statistics-chart/Templates/` that composes Partials (`DonutChart`, `ProgressList`, `GeoMap`, `TagCloud`) and passes them aggregator output.


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
