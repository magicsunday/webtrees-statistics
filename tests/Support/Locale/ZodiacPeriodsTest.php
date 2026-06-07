<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Locale;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Support\Locale\MonthName;
use MagicSunday\Webtrees\Statistic\Support\Locale\ZodiacPeriods;
use MagicSunday\Webtrees\Statistic\Support\ZodiacSigns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * Locks the per-sign period label {@see ZodiacPeriods::all()} prints next to
 * each zodiac sign. The bundled test runtime ships no compiled non-English
 * catalog, so the assertions pin the en-US form ("day Mon – day Mon", en-dash
 * separated); the German wording is exercised by the translation catalog. The
 * boundary values are derived from {@see ZodiacSigns} (the same source the SQL
 * tally buckets on), so a drift between bucket and printed period surfaces here.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ZodiacPeriods::class)]
#[UsesClass(ZodiacSigns::class)]
#[UsesClass(MonthName::class)]
final class ZodiacPeriodsTest extends TestCase
{
    /**
     * Boot the webtrees runtime so {@see I18N} has a translator, then pin the
     * locale the assertion expects.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);
    }

    /**
     * Every sign maps to its localised "day Mon – day Mon" period, in the same
     * Aries-first order and with the same keys the SQL tally returns, so the
     * view can zip the two without a key mismatch. The Aries/Taurus split at 20
     * vs 21 April and the year-wrapping Capricornus range (Dec → Jan) are the
     * discriminating rows.
     */
    #[Test]
    public function allReturnsTheTwelveLocalisedPeriodsKeyedByEnglishSign(): void
    {
        self::assertSame(
            [
                'Aries'       => '21 Mar – 20 Apr',
                'Taurus'      => '21 Apr – 21 May',
                'Gemini'      => '22 May – 21 Jun',
                'Cancer'      => '22 Jun – 22 Jul',
                'Leo'         => '23 Jul – 22 Aug',
                'Virgo'       => '23 Aug – 22 Sep',
                'Libra'       => '23 Sep – 22 Oct',
                'Scorpio'     => '23 Oct – 22 Nov',
                'Sagittarius' => '23 Nov – 20 Dec',
                'Capricornus' => '21 Dec – 19 Jan',
                'Aquarius'    => '20 Jan – 18 Feb',
                'Pisces'      => '19 Feb – 20 Mar',
            ],
            ZodiacPeriods::all(),
        );
    }

    /**
     * The period map keys stay in lockstep with the canonical sign keys so the
     * view's English-key bridge to translated labels never drops a sign.
     */
    #[Test]
    public function allKeepsTheCanonicalSignKeysAndOrder(): void
    {
        self::assertSame(ZodiacSigns::keys(), array_keys(ZodiacPeriods::all()));
    }
}
