<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;

use function str_pad;

use const STR_PAD_LEFT;

/**
 * Folds a value distribution into a list of progress-bar rows with pre-computed
 * display state (zero-padded rank, percentage of the row with the largest
 * value). Both the `progress-list.phtml` and the `podium.phtml` partials
 * consume the same row shape, so the computation lives in one place.
 *
 * Two entry points share that core: {@see toRows()} takes a `[label => value]`
 * map for the bar lists, whose keys are the aggregation dimension itself
 * (century, age band, surname, occupation) and are therefore inherently unique;
 * {@see fromRankingEntries()} takes {@see RankingEntry} objects for the entity
 * podiums (most descendants, largest families, oldest), where two distinct
 * individuals or families can share a display name and a label-keyed map would
 * lose a row.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ProgressBarRowMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Map a `[label => value]` distribution to a list of rows. Each row carries
     * the original label and numeric value plus two display-derived fields: a
     * zero-padded two-digit `rank` ("01", "02", …) and a `percentage` of the
     * row with the largest value (0–100). Returns an empty list when the input
     * is empty or every value is non-positive — the caller renders the empty
     * placeholder in that branch.
     *
     * @param array<array-key, int|float> $data Label → value map (display order = caller order; integer category keys such as decade or century are stringified)
     *
     * @return list<array{rank: string, label: string, value: float, percentage: float}>
     */
    public static function toRows(array $data): array
    {
        $pairs = [];

        foreach ($data as $label => $value) {
            $pairs[] = [(string) $label, (float) $value];
        }

        return self::rowsFromPairs($pairs);
    }

    /**
     * Map an ordered list of {@see RankingEntry} objects to the same row shape.
     * The entry's `label` drives the display column while its `xref` keeps the
     * identity distinct, so two same-named entities each get their own row
     * instead of colliding.
     *
     * @param list<RankingEntry> $entries Ranked entries (display order = caller order)
     *
     * @return list<array{rank: string, label: string, value: float, percentage: float}>
     */
    public static function fromRankingEntries(array $entries): array
    {
        $pairs = [];

        foreach ($entries as $entry) {
            $pairs[] = [$entry->label, (float) $entry->value];
        }

        return self::rowsFromPairs($pairs);
    }

    /**
     * Shared core: turn an ordered list of `[label, value]` pairs into display
     * rows. Returns an empty list when the input is empty or every value is
     * non-positive — the caller renders the empty placeholder in that branch.
     *
     * @param list<array{0: string, 1: float}> $pairs
     *
     * @return list<array{rank: string, label: string, value: float, percentage: float}>
     */
    private static function rowsFromPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $maxValue = 0.0;

        foreach ($pairs as [$label, $value]) {
            if ($value > $maxValue) {
                $maxValue = $value;
            }
        }

        if ($maxValue <= 0) {
            return [];
        }

        $rows = [];
        $i    = 0;

        foreach ($pairs as [$label, $value]) {
            $rows[] = [
                'rank'       => str_pad((string) (++$i), 2, '0', STR_PAD_LEFT),
                'label'      => $label,
                'value'      => $value,
                'percentage' => ($value / $maxValue) * 100,
            ];
        }

        return $rows;
    }
}
