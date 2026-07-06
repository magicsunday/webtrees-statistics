<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/*
 * PHPUnit bootstrap.
 *
 * Loads the composer autoloader, then the faithful stand-in for the optional
 * occupation-standardization provider's public API. The provider module is never
 * a dependency of this package, so its classes are not autoloadable; loading the
 * stub here lets the standardizer adapter's provider-present mapping be exercised
 * as a unit test. Static analysis discovers the same stub via phpstan's
 * `scanFiles`, keeping one source of truth for the provider contract.
 */

require_once __DIR__ . '/../.build/vendor/autoload.php';
require_once __DIR__ . '/../stubs/hh_occupation_standardizer.stub';
