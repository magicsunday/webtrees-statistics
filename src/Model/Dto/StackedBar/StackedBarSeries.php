<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto\StackedBar;

use JsonSerializable;

/**
 * One stacked segment in a {@see StackedBarPayload}. `data` holds
 * the per-category counts aligned positionally with the parent
 * payload's `categories` list; `class` carries the CSS class hook
 * the renderer attaches to the segment + legend swatch.
 *
 * Serialises to `{name: string, data: list<int>, class: string}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class StackedBarSeries implements JsonSerializable
{
    /**
     * @param string    $name  Segment label shown in the legend
     * @param list<int> $data  Per-category counts aligned positionally with categories
     * @param string    $class CSS class hook attached to the segment + legend swatch
     */
    public function __construct(
        public string $name,
        public array $data,
        public string $class,
    ) {
    }

    /**
     * @return array{name: string, data: list<int>, class: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'name'  => $this->name,
            'data'  => $this->data,
            'class' => $this->class,
        ];
    }
}
