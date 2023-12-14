[![Latest version](https://img.shields.io/github/v/release/magicsunday/webtrees-statistics?sort=semver)](https://github.com/magicsunday/webtrees-statistics/releases/latest)
[![License](https://img.shields.io/github/license/magicsunday/webtrees-statistics)](https://github.com/magicsunday/webtrees-statistics/blob/main/LICENSE)
[![CI](https://github.com/magicsunday/webtrees-statistics/actions/workflows/ci.yml/badge.svg)](https://github.com/magicsunday/webtrees-statistics/actions/workflows/ci.yml)


# !!! WIP !!!


<!-- TOC -->
* [Statistics](#statistics)
  * [Installation](#installation)
    * [Using Composer](#using-composer)
    * [Using Git](#using-git)
  * [Development](#development)
    * [Run tests](#run-tests)
<!-- TOC -->


# Statistics
This modules provides SVG based statistics for the [webtrees](https://www.webtrees.net) genealogy application.

## Installation
Requires webtrees 2.2.

### Using Composer
To install using [composer](https://getcomposer.org/), just run the following command from the command line
at the root directory of your webtrees installation.

```shell
composer require magicsunday/webtrees-statistics:* --update-no-dev
```

The module will automatically install into the ``modules_v4`` directory of your webtrees installation.

To remove the module run:
```shell
composer remove magicsunday/webtrees-statistics --update-no-dev
```

### Using Git
If you are using ``git``, you could also clone the current master branch directly into your ``modules_v4`` directory
by calling:

```shell
git clone https://github.com/magicsunday/webtrees-statistics.git modules_v4/webtrees-statistics
```


## Development
To build/update the javascript, run the following commands:

```shell
nvm install node
npm install
npm run prepare
```

### Run tests
```shell
composer update

composer ci:test
composer ci:test:php:phpstan
composer ci:test:php:lint
composer ci:test:php:rector
```
