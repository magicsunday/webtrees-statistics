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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the compact {@see MonthName::abbreviated()} column axis the period ×
 * month heatmap renders: the twelve names cut to three characters, January
 * first. The slice uses `mb_substr` so a multibyte initial (e.g. German "März"
 * → "Mär") survives intact, but the bundled test runtime ships no compiled
 * non-English catalog, so the localised cut is exercised by the integration
 * suite rather than asserted here.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MonthNameTest extends TestCase
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
     * The compact axis is the twelve English month names cut to three
     * characters, January first — the form the heatmap column header consumes.
     * A longer slice length or a reordered source would surface here.
     */
    #[Test]
    public function abbreviatedReturnsTwelveThreeLetterNamesJanuaryFirst(): void
    {
        self::assertSame(
            ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            MonthName::abbreviated(),
        );
    }
}
