<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Aggregator;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\LabelSorter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * Unit tests for {@see LabelSorter}: locale-aware ordering of a label → count
 * map by its display label, with an optional catch-all label pinned to the end.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(LabelSorter::class)]
final class LabelSorterTest extends TestCase
{
    /**
     * Boot webtrees and initialise the locale so {@see I18N::comparator()}
     * resolves to a usable collation closure.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);
    }

    /**
     * Entries sort case-insensitively by their label, and each label keeps its
     * own count.
     */
    #[Test]
    public function sortsEntriesAlphabeticallyByLabel(): void
    {
        $sorted = LabelSorter::byLabel([
            'Charlie' => 1,
            'alpha'   => 2,
            'Bravo'   => 3,
        ]);

        self::assertSame(['alpha', 'Bravo', 'Charlie'], array_keys($sorted));
        self::assertSame(['alpha' => 2, 'Bravo' => 3, 'Charlie' => 1], $sorted);
    }

    /**
     * The pinned label is forced to the end even when collation would otherwise
     * sort it first, everything else stays alphabetical, and each label keeps
     * its count. The pin label here ("Aaa") collates ahead of every peer, so the
     * case fails if either pin branch is dropped or the two branches are swapped.
     */
    #[Test]
    public function pinsTheGivenLabelLast(): void
    {
        $sorted = LabelSorter::byLabel(
            [
                'Zulu' => 1,
                'Aaa'  => 2,
                'Mike' => 3,
            ],
            'Aaa',
        );

        self::assertSame(['Mike', 'Zulu', 'Aaa'], array_keys($sorted));
        self::assertSame(['Mike' => 3, 'Zulu' => 1, 'Aaa' => 2], $sorted);
    }

    /**
     * A pinned label that is not present in the map is a no-op; the rest still
     * sorts alphabetically.
     */
    #[Test]
    public function absentPinnedLabelLeavesTheOrderAlphabetical(): void
    {
        $sorted = LabelSorter::byLabel(
            [
                'Gamma' => 1,
                'Delta' => 2,
            ],
            'Other',
        );

        self::assertSame(['Delta', 'Gamma'], array_keys($sorted));
    }

    /**
     * An empty map sorts to an empty map.
     */
    #[Test]
    public function emptyMapReturnsEmptyMap(): void
    {
        self::assertSame([], LabelSorter::byLabel([]));
    }
}
