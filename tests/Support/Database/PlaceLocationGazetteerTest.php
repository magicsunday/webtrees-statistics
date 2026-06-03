<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Database;

use MagicSunday\Webtrees\Statistic\Support\Database\PlaceLocationGazetteer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the in-memory gazetteer resolution: hierarchy walk, exact-leaf
 * coordinates, and the null cases (broken chain, leaf without coordinates,
 * empty input). Builds the resolver from raw rows so no database is needed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(PlaceLocationGazetteer::class)]
final class PlaceLocationGazetteerTest extends TestCase
{
    private function gazetteer(): PlaceLocationGazetteer
    {
        return PlaceLocationGazetteer::fromRows([
            // Country roots are keyed under parent_id 0 (load() maps the
            // database's NULL parent_id to 0).
            ['id' => 1, 'parent_id' => 0, 'place' => 'Deutschland', 'lat' => null, 'lng' => null],
            ['id' => 2, 'parent_id' => 1, 'place' => 'Berlin', 'lat' => 52.52, 'lng' => 13.405],
            ['id' => 3, 'parent_id' => 1, 'place' => 'Nowhere', 'lat' => null, 'lng' => null],
            // A country geocoded at the top level (single-part place).
            ['id' => 10, 'parent_id' => 0, 'place' => 'Frankreich', 'lat' => 46.0, 'lng' => 2.0],
            // A same-named place under a different parent must not cross over.
            ['id' => 11, 'parent_id' => 10, 'place' => 'Berlin', 'lat' => 1.0, 'lng' => 1.0],
        ]);
    }

    #[Test]
    public function resolvesAFullChainToTheLeafCoordinates(): void
    {
        self::assertSame([52.52, 13.405], $this->gazetteer()->resolve('Berlin, Deutschland'));
    }

    #[Test]
    public function resolvesASingleLevelCountry(): void
    {
        self::assertSame([46.0, 2.0], $this->gazetteer()->resolve('Frankreich'));
    }

    #[Test]
    public function keepsSameNamedPlacesUnderDistinctParentsApart(): void
    {
        // "Berlin" exists under both Deutschland and Frankreich; the parent chain
        // must select the right one.
        self::assertSame([1.0, 1.0], $this->gazetteer()->resolve('Berlin, Frankreich'));
    }

    #[Test]
    public function returnsNullWhenALevelOfTheChainIsMissing(): void
    {
        self::assertNull($this->gazetteer()->resolve('Hamburg, Deutschland'));
    }

    #[Test]
    public function returnsNullWhenTheLeafHasNoCoordinates(): void
    {
        self::assertNull($this->gazetteer()->resolve('Nowhere, Deutschland'));
    }

    #[Test]
    public function returnsNullForAnEmptyPlace(): void
    {
        self::assertNull($this->gazetteer()->resolve(''));
        self::assertNull($this->gazetteer()->resolve('  ,  '));
    }
}
