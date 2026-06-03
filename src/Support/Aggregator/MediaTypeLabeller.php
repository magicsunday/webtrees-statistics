<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;

/**
 * Resolves raw `source_media_type` tokens to their translated, human-readable
 * labels using webtrees' own media-type registry. The token is canonicalised and
 * looked up in the registry's value map; anything that does not resolve to a
 * non-empty label — an unknown custom type, or media with no recorded type at
 * all — folds into a single "Other" bucket. Tokens that resolve to the same
 * label are summed, and the input order (which the repository hands over already
 * sorted by frequency) is preserved.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MediaTypeLabeller
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Map a `token => count` distribution to a `label => count` distribution.
     *
     * A purely numeric type token coerces its array key to int, so the key is
     * `array-key`, not `string` — the `(string)` cast below is therefore real.
     *
     * @param array<array-key, int> $byType Source-media-type token → count, frequency-ordered
     *
     * @return array<string, int> Translated label → count, with unknown and blank tokens folded into "Other"
     */
    public static function label(array $byType): array
    {
        $element = Registry::elementFactory()->make('OBJE:FILE:FORM:TYPE');
        $values  = $element->values();

        $labelled = [];

        foreach ($byType as $token => $count) {
            $label = $values[$element->canonical((string) $token)] ?? '';

            if ($label === '') {
                $label = I18N::translate('Other');
            }

            $labelled[$label] = ($labelled[$label] ?? 0) + $count;
        }

        return $labelled;
    }
}
