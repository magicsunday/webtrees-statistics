<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Normalization;

use MagicSunday\Webtrees\Statistic\Normalization\Contract\OccupationNormalizerInterface;
use MagicSunday\Webtrees\Statistic\Normalization\NormalizedOccupation;

use function is_array;
use function iterator_to_array;

/**
 * In-memory {@see OccupationNormalizerInterface} for tests. It resolves raw values from a
 * caller-supplied lookup (keyed by the exact raw string) and counts how often
 * the batch method runs, so a test can prove both the folding behaviour and the
 * "provider is initialised once" contract without a real standardization
 * module.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class StubOccupationNormalizer implements OccupationNormalizerInterface
{
    /**
     * Number of {@see self::normalizeMany()} invocations, so a batch consumer
     * can assert it resolves the whole set in one pass.
     */
    private int $batchCalls = 0;

    /**
     * The language hint passed on the most recent {@see self::normalizeMany()}
     * call, so a test can assert which content language a consumer forwards.
     */
    private ?string $lastLanguage = null;

    /**
     * @param array<string, NormalizedOccupation> $lookup Raw value => canned result; absent keys resolve to null
     */
    public function __construct(
        private readonly array $lookup,
    ) {
    }

    /**
     * How often {@see self::normalizeMany()} has been called.
     */
    public function batchCalls(): int
    {
        return $this->batchCalls;
    }

    /**
     * The language hint received on the most recent {@see self::normalizeMany()}
     * call, or null when none was supplied.
     */
    public function lastLanguage(): ?string
    {
        return $this->lastLanguage;
    }

    /**
     * Resolve each input from the canned lookup, recording that a batch pass ran
     * so the "provider is initialised once" contract can be asserted.
     *
     * @param iterable<string> $rawOccupations The distinct raw `1 OCCU` values to resolve
     * @param string|null      $language       Recorded for {@see self::lastLanguage()}; does not affect the lookup
     *
     * @return array<string, NormalizedOccupation|null> Keyed by each input; absent keys resolve to null
     */
    public function normalizeMany(iterable $rawOccupations, ?string $language = null): array
    {
        ++$this->batchCalls;
        $this->lastLanguage = $language;

        $values = is_array($rawOccupations) ? $rawOccupations : iterator_to_array($rawOccupations, false);

        $result = [];

        foreach ($values as $rawValue) {
            $result[$rawValue] = $this->lookup[$rawValue] ?? null;
        }

        return $result;
    }
}
