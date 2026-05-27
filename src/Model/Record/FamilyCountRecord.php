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
 * Hall-of-Fame record carrying one family plus a count (children,
 * generations, …). Used for "largest family by child count" on
 * the Overview tab.
 *
 * Serialises to `{xref, count}`; PHTML consumers reach the live
 * `family` via the public property.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class FamilyCountRecord implements JsonSerializable
{
    public function __construct(
        public Family $family,
        public int $count,
    ) {
    }

    /**
     * @return array{xref: string, count: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'xref'  => $this->family->xref(),
            'count' => $this->count,
        ];
    }
}
