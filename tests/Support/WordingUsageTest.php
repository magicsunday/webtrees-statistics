<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function dirname;
use function file_get_contents;
use function in_array;
use function preg_match_all;

/**
 * Tests that the texts of the Wording class are not translated anywhere else. A plain
 * translation of such a text shows the wording of the webtrees core instead of the wording of the
 * module, and the compiled catalogue of the module holds no plain entry for it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class WordingUsageTest extends TestCase
{
    /**
     * Every source text of the Wording class.
     *
     * @var list<string>
     */
    private const array TEXTS = ['Media', 'Other'];

    /**
     * No file of the module translates one of the texts without the context of the module.
     */
    #[Test]
    public function translatesTheTextsOfTheWordingClassOnlyThroughIt(): void
    {
        $root = dirname(__DIR__, 2);

        $offenders = [];
        $scanned   = 0;

        foreach (['src', 'resources/views'] as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                /** @var SplFileInfo $file */
                if (!in_array($file->getExtension(), ['php', 'phtml'], true)) {
                    continue;
                }

                if ($file->getPathname() === $root . '/src/Support/Wording.php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                preg_match_all('/I18N::translate\\(\\s*([\'"])(.+?)\\1\\s*[,)]/su', $source, $matches);

                $scanned += count($matches[2]);

                foreach ($matches[2] as $text) {
                    if (in_array($text, self::TEXTS, true)) {
                        $offenders[] = $file->getPathname() . ': ' . $text;
                    }
                }
            }
        }

        self::assertGreaterThan(0, $scanned, 'The scan must find translation calls at all.');
        self::assertSame([], $offenders);
    }
}
