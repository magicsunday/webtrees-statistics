<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Locale;

use MagicSunday\Webtrees\Statistic\Support\Locale\HistoricalEventCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Branch coverage for the pure event-matching logic ({@see
 * HistoricalEventCatalog::keysFor()}): single- and multi-country matches, the
 * inclusive year-span boundaries, the country-set intersection, the two-event
 * cap, and the empty / no-match paths. The localised event label
 * ({@see HistoricalEventCatalog::labelFor()}) needs the framework and is covered
 * by the integration test, not here.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(HistoricalEventCatalog::class)]
final class HistoricalEventCatalogTest extends TestCase
{
    /**
     * A single-country event matches when the year falls in its span and the
     * country is present.
     */
    #[Test]
    public function matchesSingleCountryEvent(): void
    {
        self::assertSame(['us-civil-war'], HistoricalEventCatalog::keysFor(1861, ['US']));
    }

    /**
     * The year span is inclusive on both ends and excludes the years just
     * outside it.
     */
    #[Test]
    public function matchesOnlyWithinTheInclusiveSpan(): void
    {
        self::assertSame(['thirty-years-war'], HistoricalEventCatalog::keysFor(1618, ['DE']));
        self::assertSame(['thirty-years-war'], HistoricalEventCatalog::keysFor(1648, ['DE']));
        self::assertSame([], HistoricalEventCatalog::keysFor(1617, ['DE']));
        self::assertSame([], HistoricalEventCatalog::keysFor(1649, ['DE']));
    }

    /**
     * A multi-country event matches any country in its set, and does not match a
     * country outside it.
     */
    #[Test]
    public function matchesByCountrySetIntersection(): void
    {
        // France is a First-World-War country.
        self::assertSame(['first-world-war'], HistoricalEventCatalog::keysFor(1916, ['FR']));
        // Sweden was neutral and carries no 1916 event.
        self::assertSame([], HistoricalEventCatalog::keysFor(1916, ['SE']));
    }

    /**
     * A year that coincides with more events than the cap keeps only the first
     * two in catalogue order. In 1919 the countries Russia and Poland match the
     * Russian Revolution, the influenza pandemic and the Polish-Soviet War —
     * three events — but only the first two are returned.
     */
    #[Test]
    public function capsToTwoEventsInCatalogueOrder(): void
    {
        self::assertSame(
            ['russian-revolution-civil-war', 'influenza-pandemic'],
            HistoricalEventCatalog::keysFor(1919, ['RU', 'PL']),
        );
    }

    /**
     * An empty country list (no death place reached the qualifying threshold)
     * matches nothing.
     */
    #[Test]
    public function emptyCountriesMatchNothing(): void
    {
        self::assertSame([], HistoricalEventCatalog::keysFor(1918, []));
    }

    /**
     * A year with no catalogued event for the supplied country matches nothing.
     */
    #[Test]
    public function yearWithoutAnEventMatchesNothing(): void
    {
        self::assertSame([], HistoricalEventCatalog::keysFor(1700, ['US']));
    }
}
