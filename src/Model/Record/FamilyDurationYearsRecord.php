<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Record;

use Fisharebest\Webtrees\Family;
use JsonSerializable;

/**
 * Hall-of-Fame record carrying one family plus its marriage
 * duration in years. Used for the "longest marriage" record on
 * the Overview tab.
 *
 * Serialises to `{xref, durationYears}`; PHTML consumers reach
 * the live `family` via the public property.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class FamilyDurationYearsRecord implements JsonSerializable
{
    public function __construct(
        public Family $family,
        public int $durationYears,
    ) {
    }

    /**
     * @return array{xref: string, durationYears: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'xref'          => $this->family->xref(),
            'durationYears' => $this->durationYears,
        ];
    }
}
