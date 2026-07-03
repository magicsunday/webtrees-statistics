<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Site;
use MagicSunday\Webtrees\Statistic\Normalization\NormalizedOccupation;
use MagicSunday\Webtrees\Statistic\Normalization\OccupationFolding;
use MagicSunday\Webtrees\Statistic\Normalization\RawOccupationNormalizer;
use MagicSunday\Webtrees\Statistic\Normalization\Support\ContentLanguage;
use MagicSunday\Webtrees\Statistic\Normalization\Support\StringList;
use MagicSunday\Webtrees\Statistic\Repository\OccupationRepository;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\TopNAggregator;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Test\Support\Normalization\StubOccupationNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_keys;

/**
 * End-to-end test of {@see OccupationRepository}. The shared
 * `individual-facts.ged` fixture carries seven individuals with a mix of OCCU
 * shapes: single value (Anna/Berta — case variants), different value
 * (Carl/Emil/Doris), multi-occurrence (Gerda has two OCCU lines), no value
 * (Franz). The aggregation must collapse case variants under the first-seen
 * casing and rank descending.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(OccupationRepository::class)]
#[UsesClass(TopNAggregator::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(RawOccupationNormalizer::class)]
#[UsesClass(NormalizedOccupation::class)]
#[UsesClass(OccupationFolding::class)]
#[UsesClass(StubOccupationNormalizer::class)]
#[UsesClass(StringList::class)]
#[UsesClass(ContentLanguage::class)]
final class OccupationRepositoryIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * `Blacksmith` appears three times (Anna, Berta with lowercase variant
     * `blacksmith`, and Gerda's first OCCU line). `Farmer` twice (Doris + Emil).
     * `Teacher` (Carl) and `Carpenter` (Gerda's second OCCU line) once each. The
     * lowercase variant `blacksmith` merges into the `Blacksmith` bucket via
     * case-folded keys, with the first-seen casing winning as the display label.
     *
     * The distinct occupations are encountered in the order Blacksmith, Teacher,
     * Farmer, Carpenter — deliberately NOT count-descending — so a regression
     * that dropped the frequency ordering would surface Teacher above Farmer.
     * The two count-1 occupations also exercise the alphabetical tie-break:
     * Carpenter sorts before Teacher even though Teacher was encountered first.
     */
    #[Test]
    public function topOccupationsReturnsCaseFoldedFrequencies(): void
    {
        $tree   = $this->importFixtureTree('individual-facts.ged');
        $result = (new OccupationRepository($tree, new RawOccupationNormalizer()))->top(10);

        self::assertSame(
            ['Blacksmith' => 3, 'Farmer' => 2, 'Carpenter' => 1, 'Teacher' => 1],
            $result,
        );
    }

    /**
     * A top-N limit truncates the tail without changing the order. The cap rides
     * on the frequency ranking, not first-seen order: Teacher is encountered
     * before Farmer in the fixture, so a top-2 that kept `['Blacksmith',
     * 'Farmer']` proves the frequency sort ran before the slice.
     */
    #[Test]
    public function topOccupationsRespectsTheLimit(): void
    {
        $tree   = $this->importFixtureTree('individual-facts.ged');
        $result = (new OccupationRepository($tree, new RawOccupationNormalizer()))->top(2);

        self::assertSame(['Blacksmith', 'Farmer'], array_keys($result));
    }

    /**
     * Distinct count = number of case-folded keys, independent of top-N
     * truncation.
     */
    #[Test]
    public function countDistinctOccupationsReturnsTheFullKeyCount(): void
    {
        $tree = $this->importFixtureTree('individual-facts.ged');

        self::assertSame(4, (new OccupationRepository($tree, new RawOccupationNormalizer()))->countDistinct());
    }

    /**
     * With a standardization provider the top-N counter folds on the provider's
     * grouping key, not the raw spelling: a provider that maps both `Blacksmith`
     * (three, incl. the case variant) and `Farmer` (two) onto one trade merges
     * them into a single bucket of five under the provider display label, while
     * the unmapped `Teacher` and `Carpenter` keep their raw case-fold. Contrast
     * with {@see self::topOccupationsReturnsCaseFoldedFrequencies()}, which pins
     * the four separate raw buckets — the merge is the provider's doing.
     */
    #[Test]
    public function topOccupationsFoldOnTheProviderGroupingKey(): void
    {
        $tree = $this->importFixtureTree('individual-facts.ged');

        $metal      = new NormalizedOccupation('de:Metall', 'Metall');
        $normalizer = new StubOccupationNormalizer([
            'Blacksmith' => $metal,
            'blacksmith' => $metal,
            'Farmer'     => $metal,
        ]);

        $result = (new OccupationRepository($tree, $normalizer))->top(10);

        self::assertSame(
            ['Metall' => 5, 'Carpenter' => 1, 'Teacher' => 1],
            $result,
        );
        self::assertSame(1, $normalizer->batchCalls(), 'the whole distinct occupation set is resolved in one batch');
    }

    /**
     * A purely numeric `1 OCCU` value (someone recording a code as the trade)
     * must not crash the aggregation. The repository collects the distinct set
     * as array keys, which coerces `1234` to an int; without the string
     * round-trip in the fold the fallback `mb_strtolower()` would throw a
     * TypeError under strict types and 500 the occupation chart. This exercises
     * the real caller path (`array_keys(...)` → fold) that a `map()` unit test
     * with a string literal cannot reproduce.
     */
    #[Test]
    public function topOccupationsCountNumericValuesWithoutCoercionError(): void
    {
        $tree = $this->importFixtureTree('occupation-numeric.ged');

        $result = (new OccupationRepository($tree, new RawOccupationNormalizer()))->top(10);

        self::assertSame(['1234' => 1, 'Farmer' => 1], $result);
    }

    /**
     * The Top-N occupations card is the SECOND consumer of the site-language
     * fix, so it gets its own propagation guard mirroring the inheritance card:
     * with Site `LANGUAGE='de'` the normalizer must receive `'de'`. Without this
     * the `OccupationRepository` call site could silently revert to forwarding
     * null and no test would fail.
     */
    #[Test]
    public function occupationNormalizerReceivesTheSiteContentLanguage(): void
    {
        $previous = Site::getPreference('LANGUAGE');

        try {
            Site::setPreference('LANGUAGE', 'de');

            $tree       = $this->importFixtureTree('individual-facts.ged');
            $normalizer = new StubOccupationNormalizer([]);

            (new OccupationRepository($tree, $normalizer))->top(10);

            self::assertSame('de', $normalizer->lastLanguage(), 'the site content language is forwarded to the provider');
        } finally {
            Site::setPreference('LANGUAGE', $previous);
        }
    }
}
