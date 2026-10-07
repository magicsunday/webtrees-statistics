<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Aggregator;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Aggregator\RecordRowMapper;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyDurationYearsRecord;
use MagicSunday\Webtrees\Statistic\View\RecordCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the wording of the marriage-duration record value. The text is a source
 * text of its own and not the `%s year` of webtrees core, which core translates
 * as an age. Chinese, for one, then reads the length of a marriage as a number
 * of years of age.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(RecordRowMapper::class)]
#[UsesClass(FamilyDurationYearsRecord::class)]
#[UsesClass(RecordCategory::class)]
final class RecordRowMapperTest extends TestCase
{
    /**
     * Boot the webtrees runtime so {@see I18N} has a translator, and pin the
     * source language the assertions read.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);
    }

    /**
     * The marriage duration reads as a length of time married, in the singular
     * and the plural form.
     */
    #[Test]
    public function familyYearsWordsTheValueAsAMarriageDuration(): void
    {
        $family = self::createStub(Family::class);
        $family->method('canShow')->willReturn(true);
        $family->method('fullName')->willReturn('A and B');
        $family->method('url')->willReturn('/family/F1');

        $one  = RecordRowMapper::familyYears(RecordCategory::Marriage, 'Longest marriage', new FamilyDurationYearsRecord($family, 1));
        $many = RecordRowMapper::familyYears(RecordCategory::Marriage, 'Longest marriage', new FamilyDurationYearsRecord($family, 52));

        self::assertSame('1 year married', $one['value'] ?? null);
        self::assertSame('52 years married', $many['value'] ?? null);
    }
}
