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
 * Hall-of-Fame record carrying one individual plus the count
 * (children, spouses, …) that earned them the row. Used for
 * "most children per person" and "most spouses" records.
 *
 * Serialises to `{xref, count}`; PHTML consumers reach the live
 * `individual` via the public property.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class IndividualCountRecord implements JsonSerializable
{
    public function __construct(
        public Individual $individual,
        public int $count,
    ) {
    }

    /**
     * @return array{xref: string, count: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'xref'  => $this->individual->xref(),
            'count' => $this->count,
        ];
    }
}
