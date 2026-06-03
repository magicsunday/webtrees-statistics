<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Support\Aggregator\MediaTypeLabeller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for {@see MediaTypeLabeller} against the live webtrees media-type
 * registry: known tokens resolve to their translated labels, the storage casing
 * is normalised away, and every token that does not resolve to a non-empty label
 * — blank or unknown — folds into a single summed "Other" bucket while the input
 * frequency order is preserved.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(MediaTypeLabeller::class)]
final class MediaTypeLabellerTest extends IntegrationTestCase
{
    /**
     * Known tokens map to their registry labels regardless of the stored casing,
     * and the blank token (media with no recorded type) folds into "Other"
     * without disturbing the frequency order the repository handed over.
     */
    #[Test]
    public function resolvesKnownTokensAndFoldsTheBlankTokenIntoOther(): void
    {
        self::assertSame(
            [
                'Photo'     => 2,
                'Other'     => 1,
                'Tombstone' => 1,
            ],
            MediaTypeLabeller::label([
                'PHOTO'     => 2,
                ''          => 1,
                'TOMBSTONE' => 1,
            ]),
        );
    }

    /**
     * Blank and unrecognised tokens collapse into the same "Other" bucket and
     * their counts are summed, so an unknown custom type never leaks through as
     * its own row.
     */
    #[Test]
    public function foldsBlankAndUnknownTokensIntoOneSummedOtherBucket(): void
    {
        self::assertSame(
            [
                'Photo' => 2,
                'Other' => 4,
            ],
            MediaTypeLabeller::label([
                'PHOTO'        => 2,
                ''             => 1,
                'NOSUCHTYPEXY' => 3,
            ]),
        );
    }

    /**
     * A purely numeric media-type token (e.g. a custom GEDCOM `TYPE 0`) arrives
     * as an int array key; the `(string)` cast keeps the registry lookup
     * well-typed and the unresolved token still folds into "Other".
     */
    #[Test]
    public function castsNumericTokenKeyToStringAndFoldsItIntoOther(): void
    {
        self::assertSame(
            ['Other' => 5],
            MediaTypeLabeller::label([0 => 5]),
        );
    }

    /**
     * An empty distribution yields an empty result without touching the registry
     * for a lookup.
     */
    #[Test]
    public function emptyDistributionYieldsEmptyResult(): void
    {
        self::assertSame([], MediaTypeLabeller::label([]));
    }
}
