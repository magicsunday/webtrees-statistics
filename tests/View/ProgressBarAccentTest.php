<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\View;

use FilesystemIterator;
use MagicSunday\Webtrees\Statistic\View\Accent;
use MagicSunday\Webtrees\Statistic\View\ProgressBarAccent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClassConstant;
use SplFileInfo;

use function array_keys;
use function array_unique;
use function array_values;
use function dirname;
use function file_get_contents;
use function preg_match_all;
use function sort;

/**
 * Pins the accent map against the views that consume it.
 *
 * `ProgressBarAccent::for()` falls back to a default accent for an unknown
 * class, which is the right runtime behaviour — a missing entry must not blank
 * out a bar — but it also means a progress list can ship with a colour nobody
 * chose, and nothing says so. The CSS layer used to carry a second copy of this
 * mapping and a test that pinned it; both are gone, so the guard belongs here,
 * on the source of truth that remains.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ProgressBarAccent::class)]
final class ProgressBarAccentTest extends TestCase
{
    /**
     * Returns every `'class' => 'progress-…'` literal used in a view template.
     *
     * @return list<string>
     */
    private function progressClassesInViews(): array
    {
        $viewsRoot = dirname(__DIR__, 2) . '/resources/views';

        self::assertDirectoryExists($viewsRoot);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($viewsRoot, FilesystemIterator::SKIP_DOTS)
        );

        $classes = [];

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            if (!$entry->isFile()) {
                continue;
            }

            if ($entry->getExtension() !== 'phtml') {
                continue;
            }

            $contents = file_get_contents($entry->getPathname());

            self::assertNotFalse($contents, 'Could not read ' . $entry->getPathname());

            // Any quoted `progress-…` literal, deliberately not keyed on the
            // parameter name. The views hand the accent key over under at least
            // three shapes — `->with('class', …)`, `'progressClass' => …`, and
            // a plain `'class' => …` — and two different components consume it
            // (`progress-list` and `podium`). The predecessor of this test
            // matched one shape only, so it silently collected nothing and
            // compared two empty sets. A docblock mention is written in
            // backticks, not quotes, so it does not match here.
            preg_match_all(
                "/['\"](progress-[A-Za-z0-9_-]+)['\"]/",
                $contents,
                $matches
            );

            foreach ($matches[1] as $name) {
                $classes[] = $name;
            }
        }

        $classes = array_values(array_unique($classes));

        sort($classes);

        return $classes;
    }

    /**
     * A progress list whose class is missing from the map renders in the
     * fallback accent rather than failing, so the omission is invisible in the
     * browser. This is what makes it visible.
     */
    #[Test]
    public function everyProgressClassUsedInAViewHasItsOwnAccent(): void
    {
        $map = (new ReflectionClassConstant(ProgressBarAccent::class, 'MAP'))->getValue();

        self::assertIsArray($map);

        $mapped = array_keys($map);

        sort($mapped);

        self::assertSame(
            $mapped,
            $this->progressClassesInViews(),
            'Every progress-* class used in a view must appear in ProgressBarAccent::MAP. '
            . 'An unmapped one silently renders in the fallback accent; a mapped one that no '
            . 'view uses any more is dead configuration.'
        );
    }

    /**
     * The lookup returns the accent the map declares.
     */
    #[Test]
    public function forReturnsTheMappedAccent(): void
    {
        self::assertSame(Accent::Slate, ProgressBarAccent::for('progress-religions'));
        self::assertSame(Accent::Ochre, ProgressBarAccent::for('progress-top-ancestors'));
        self::assertSame(Accent::Deceased, ProgressBarAccent::for('progress-death-causes'));
    }

    /**
     * An unknown class must still yield a usable accent, so a progress bar
     * never renders without a colour.
     */
    #[Test]
    public function forFallsBackForAnUnknownClass(): void
    {
        self::assertSame(Accent::Wine, ProgressBarAccent::for('progress-does-not-exist'));
    }
}
