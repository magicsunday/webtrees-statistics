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
use MagicSunday\Webtrees\Statistic\Support\Locale\DecadeName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the decade-label forms every per-decade widget renders. The CE rows
 * pin the existing `${start}s` / `Period: start–end` output; the BCE rows pin
 * the fold for negative decade-start keys (the magnitude-grouped key `-50` is
 * the "50s BCE" decade, years 50–59 BCE) and that the era marker composes LAST
 * — "50s BCE" / "Period: 59–50 BCE", never "-50s" / "Period: -50–-41".
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(DecadeName::class)]
final class DecadeNameTest extends TestCase
{
    /**
     * Short-label cases: CE decade suffixes, the degenerate "0s" key, and BCE
     * magnitude-grouped keys whose era marker must compose last.
     *
     * @return array<string, array{int, string}>
     */
    public static function shortLabelProvider(): array
    {
        return [
            'CE 1900s' => [1900, '1900s'],
            'CE 0s'    => [0, '0s'],
            'BCE 50s'  => [-50, '50s BCE'],
            'BCE 90s'  => [-90, '90s BCE'],
        ];
    }

    /**
     * The short decade-suffix label appends the BCE era marker after the
     * decade suffix for negative (magnitude-grouped) keys.
     */
    #[Test]
    #[DataProvider('shortLabelProvider')]
    public function shortLabelComposesEraMarkerLast(int $decadeStart, string $expected): void
    {
        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);

        self::assertSame($expected, DecadeName::for($decadeStart));
    }

    /**
     * Long-range cases: CE single and five-decade bins, plus BCE single and
     * five-decade bins counting years down from earliest to latest.
     *
     * @return array<string, array{int, int, string}>
     */
    public static function longLabelProvider(): array
    {
        return [
            'CE 1900s single'   => [1900, 1, 'Period: 1900–1909'],
            'CE 1900s five-bin' => [1900, 5, 'Period: 1900–1949'],
            // -50 = the 50s-BCE decade = years 50–59 BCE; the range runs from
            // the earliest (59 BCE) to the latest (50 BCE).
            'BCE 50s single' => [-50, 1, 'Period: 59–50 BCE'],
            // A five-decade bin keyed at the most-negative -90 spans 99–50 BCE.
            'BCE 90s five-bin' => [-90, 5, 'Period: 99–50 BCE'],
        ];
    }

    /**
     * The long range label likewise composes the era marker last and counts
     * BCE years down from the earliest to the latest.
     */
    #[Test]
    #[DataProvider('longLabelProvider')]
    public function longLabelComposesBceRangeWithEraMarkerLast(int $decadeStart, int $decadeCount, string $expected): void
    {
        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);

        self::assertSame($expected, DecadeName::longLabel($decadeStart, $decadeCount));
    }
}
