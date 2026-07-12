<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Fisharebest\Webtrees\Webtrees;
use RuntimeException;

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

/*
 * Seed an English source catalogue when webtrees is installed from source.
 *
 * On a dev-main install, webtrees' composer dist archive ships no usable
 * English catalogue: the built messages.php catalogues were dropped upstream
 * (webtrees now generates them during its own build), and the messages.po
 * sources are export-ignored from the archive. With neither present,
 * I18N::init('en-US') reaches array_filter(false) deep in
 * fisharebest/localization and every test that initialises the translator dies
 * with a TypeError. English is the source language (msgid === msgstr), so a
 * translator backed by an empty catalogue is the correct, behaviourally
 * identical stand-in; a release checkout that already carries the real file is
 * left untouched.
 */
$enCatalogue = Webtrees::ROOT_DIR . 'resources/lang/en-US/messages.php';

if (!is_file($enCatalogue)) {
    $directory = dirname($enCatalogue);

    if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create the language directory "%s".', $directory));
    }

    if (file_put_contents($enCatalogue, "<?php\n\nreturn [];\n") === false) {
        throw new RuntimeException(sprintf('Unable to seed the English source catalogue "%s".', $enCatalogue));
    }
}
