<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Normalization;

use MagicSunday\Webtrees\Statistic\Normalization\RawOccupationNormalizer;
use MagicSunday\Webtrees\Statistic\Normalization\Support\StringList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage of the identity {@see RawOccupationNormalizer}: it resolves
 * nothing so every call site keeps the raw value, which is what makes wiring
 * the seam a no-op for sites without a standardization provider.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(RawOccupationNormalizer::class)]
#[UsesClass(StringList::class)]
final class RawOccupationNormalizerTest extends TestCase
{
    /**
     * The batch method returns one null per distinct input, keyed by the input
     * string, so a consumer's fold map has an entry for every value.
     */
    #[Test]
    public function normalizeManyReturnsANullKeyedByEachInput(): void
    {
        $result = (new RawOccupationNormalizer())->normalizeMany(['Arzt', 'Bäcker', 'Arzt'], 'de');

        self::assertSame(['Arzt' => null, 'Bäcker' => null], $result);
    }

    /**
     * A Traversable input is accepted just like an array, so callers can pass a
     * lazily-built distinct set without materialising it first.
     */
    #[Test]
    public function normalizeManyAcceptsATraversable(): void
    {
        $generator = (static function (): iterable {
            yield 'Schmied';
            yield 'Müller';
        })();

        $result = (new RawOccupationNormalizer())->normalizeMany($generator);

        self::assertSame(['Schmied' => null, 'Müller' => null], $result);
    }
}
