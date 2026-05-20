<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use MagicSunday\Webtrees\Statistic\MaritalBucket;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the four backed-enum cases and their string values so the chart
 * widget contract (bucket key names) cannot drift silently.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MaritalBucketTest extends TestCase
{
    /**
     * The enum cases must be declared in the documented precedence order
     * so that downstream consumers reading {@see MaritalBucket::cases()}
     * see the same priority order the classifier applies.
     */
    #[Test]
    public function exposesFourCasesInPrecedenceOrder(): void
    {
        self::assertSame(
            [
                MaritalBucket::Current,
                MaritalBucket::Divorced,
                MaritalBucket::Widowed,
                MaritalBucket::Single,
            ],
            MaritalBucket::cases(),
            'Enum cases must be declared in the documented precedence order',
        );
    }

    /**
     * The string values are used as array keys in the bucket-count map
     * and in chart-lib widget data shapes — any change here is a public
     * contract break and must be deliberate.
     */
    #[Test]
    public function stringValuesMatchTheTemplateBucketKeys(): void
    {
        self::assertSame('current', MaritalBucket::Current->value);
        self::assertSame('divorced', MaritalBucket::Divorced->value);
        self::assertSame('widowed', MaritalBucket::Widowed->value);
        self::assertSame('single', MaritalBucket::Single->value);
    }
}
