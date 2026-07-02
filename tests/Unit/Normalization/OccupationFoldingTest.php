<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Normalization;

use MagicSunday\Webtrees\Statistic\Normalization\NormalizedOccupation;
use MagicSunday\Webtrees\Statistic\Normalization\OccupationFolding;
use MagicSunday\Webtrees\Statistic\Normalization\RawOccupationNormalizer;
use MagicSunday\Webtrees\Statistic\Normalization\Support\StringList;
use MagicSunday\Webtrees\Statistic\Test\Support\Normalization\StubOccupationNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage of {@see OccupationFolding::map()} — the batch fold that both
 * occupation aggregations share. It proves the three behaviours that matter:
 * variants of one trade collapse under a provider's grouping key, values the
 * provider cannot place fall back to the pre-normalization case-fold, and the
 * provider is consulted exactly once for the whole distinct set.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(OccupationFolding::class)]
#[UsesClass(NormalizedOccupation::class)]
#[UsesClass(RawOccupationNormalizer::class)]
#[UsesClass(StubOccupationNormalizer::class)]
#[UsesClass(StringList::class)]
final class OccupationFoldingTest extends TestCase
{
    /**
     * A provider that maps three spelling / gender / language variants of the
     * same trade to one grouping key: every variant folds onto that key and
     * shares the provider's display label, so the downstream aggregation counts
     * them as a single bucket.
     */
    #[Test]
    public function variantsCollapseUnderTheProviderGroupingKey(): void
    {
        $arzt = new NormalizedOccupation('de:Arzt', 'Arzt');

        $normalizer = new StubOccupationNormalizer([
            'Arzt'   => $arzt,
            'ärztin' => $arzt,
            'Doctor' => $arzt,
        ]);

        $folds = OccupationFolding::map(['Arzt', 'ärztin', 'Doctor'], $normalizer, 'de');

        self::assertSame(
            [
                'Arzt'   => ['de:Arzt', 'Arzt'],
                'ärztin' => ['de:Arzt', 'Arzt'],
                'Doctor' => ['de:Arzt', 'Arzt'],
            ],
            $folds,
        );
    }

    /**
     * A value the provider cannot place keeps the pre-normalization fold: the
     * key is the case-folded raw string and the label is the raw string
     * unchanged, so no occupation is ever dropped.
     */
    #[Test]
    public function unknownValuesFallBackToTheCaseFoldedRaw(): void
    {
        $normalizer = new StubOccupationNormalizer([
            'Arzt' => new NormalizedOccupation('de:Arzt', 'Arzt'),
        ]);

        $folds = OccupationFolding::map(['Arzt', 'Schäfer'], $normalizer, 'de');

        self::assertSame(
            [
                'Arzt'    => ['de:Arzt', 'Arzt'],
                'Schäfer' => ['schäfer', 'Schäfer'],
            ],
            $folds,
        );
    }

    /**
     * The identity default resolves nothing, so every value folds exactly as it
     * did before normalization existed: case-folded key, raw label.
     */
    #[Test]
    public function rawNormalizerFoldsEveryValueByCaseAlone(): void
    {
        $folds = OccupationFolding::map(['Smith', 'smith', 'BAKER'], new RawOccupationNormalizer(), null);

        self::assertSame(
            [
                'Smith' => ['smith', 'Smith'],
                'smith' => ['smith', 'smith'],
                'BAKER' => ['baker', 'BAKER'],
            ],
            $folds,
        );
    }

    /**
     * The whole distinct set is resolved in a single batch call, honouring the
     * "a provider initialises its normalization data once" contract that the
     * dashboard workflow relies on.
     */
    #[Test]
    public function providerIsConsultedOnceForTheWholeSet(): void
    {
        $normalizer = new StubOccupationNormalizer([]);

        OccupationFolding::map(['Arzt', 'Bäcker', 'Schmied'], $normalizer, 'de');

        self::assertSame(1, $normalizer->batchCalls());
    }
}
