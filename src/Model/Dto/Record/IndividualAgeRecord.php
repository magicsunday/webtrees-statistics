<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto\Record;

use Fisharebest\Webtrees\Individual;
use JsonSerializable;

/**
 * Hall-of-Fame record carrying one individual plus the age (in
 * years) that earned them the row. Used for "oldest deceased",
 * "oldest living", "youngest spouse at marriage", "oldest spouse
 * at marriage", "youngest father at first child", and similar
 * single-individual extreme-age records on the Overview tab.
 *
 * Serialises to `{xref, ageYears}` because a webtrees `Individual`
 * is not JSON-encodable; PHTML consumers reach the live entity
 * directly via the public `individual` property.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class IndividualAgeRecord implements JsonSerializable
{
    public function __construct(
        public Individual $individual,
        public int $ageYears,
    ) {
    }

    /**
     * @return array{xref: string, ageYears: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'xref'     => $this->individual->xref(),
            'ageYears' => $this->ageYears,
        ];
    }
}
