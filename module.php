<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees;

use Composer\Autoload\ClassLoader;
use MagicSunday\Webtrees\Statistic\Module;

// Register our namespace
$loader = new ClassLoader();
$loader->addPsr4('MagicSunday\\Webtrees\\Statistic\\', __DIR__ . '/src');
$loader->register();

// Create and return instance of the module
return app(Module::class);
