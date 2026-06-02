<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Enum\MaritalBucket;
use MagicSunday\Webtrees\Statistic\Model\Family\SexRatioAnomaly;
use MagicSunday\Webtrees\Statistic\Model\FamilyRow;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\ChunkedWhereIn;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;

/**
 * End-to-end test of the marital-status classifier against a curated GEDCOM
 * fixture loaded into an in-memory SQLite database. Mirrors what webtrees core
 * does in its own TestCase: bootstrap the schema, import the records, then
 * exercise the repository against the real tables.
 *
 * The fixture contains the four marital buckets at their boundaries: a living
 * couple (current × 2), a widow with deceased spouse (widowed × 1), a divorced
 * couple (divorced × 2), and two unpartnered individuals (single × 1 living,
 * single × 1 deceased).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(FamilyRepository::class)]
#[UsesClass(FamilyRow::class)]
#[UsesClass(SexRatioAnomaly::class)]
#[UsesClass(ChunkedWhereIn::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(RowCast::class)]
final class FamilyRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Sum of the four buckets must equal the living count, the canonical
     * invariant the classifier promises.
     */
    #[Test]
    public function classifyLivingIndividualsMatchesTheCuratedFixture(): void
    {
        $tree    = $this->importFixtureTree('marital-status.ged');
        $buckets = (new FamilyRepository($tree))->classifyLivingIndividuals();

        self::assertSame(
            [
                MaritalBucket::Current->value  => 2,
                MaritalBucket::Divorced->value => 2,
                MaritalBucket::Widowed->value  => 1,
                MaritalBucket::Single->value   => 1,
            ],
            $buckets,
            'Four bucket counts must match the curated fixture',
        );
    }

    /**
     * A remarried individual appears in two FAMS families — one divorced, one
     * current — so the individual→families join must fan out to BOTH rows for
     * that person before the classifier applies its current > divorced
     * precedence. Guards the link-table join (which replaced an O(n×m) OR-join
     * for #82): a join that collapsed the multi-FAMS fan-out to one row would
     * silently misclassify the remarried individual.
     */
    #[Test]
    public function classifyLivingIndividualsFansOutAcrossMultipleFamsFamilies(): void
    {
        $tree    = $this->importFixtureTree('marital-status-remarried.ged');
        $buckets = (new FamilyRepository($tree))->classifyLivingIndividuals();

        self::assertSame(
            [
                // I1 (remarried: divorced F1 + current F2 → current wins) and
                // I3 (current second wife) are Current; I2 (divorced first
                // wife) is Divorced; nobody is widowed or single.
                MaritalBucket::Current->value  => 2,
                MaritalBucket::Divorced->value => 1,
                MaritalBucket::Widowed->value  => 0,
                MaritalBucket::Single->value   => 0,
            ],
            $buckets,
            'The remarried individual must classify as Current via the multi-FAMS fan-out',
        );
    }

    /**
     * Map the anomaly list to `[xref, sons, daughters]` triples so the order and
     * counts can be asserted without depending on the resolved display label.
     *
     * @param list<SexRatioAnomaly> $anomalies
     *
     * @return list<array{string, int, int}>
     */
    private function triples(array $anomalies): array
    {
        return array_map(
            static fn (SexRatioAnomaly $a): array => [$a->familyXref, $a->sons, $a->daughters],
            $anomalies,
        );
    }

    /**
     * With the defaults (≥ 6 children, ≥ 80 % one sex), the fixture qualifies
     * four families: F1 (7 sons / 0), F2 (0 / 8 daughters), F3 (6 sons / 1
     * daughter → 6 of 7 ≈ 85.7 %) and F6 (5 sons / 1 daughter → 5 of 6 ≈ 83.3 %,
     * which only clears the gate because the threshold is 80 % — at 85 % a
     * six-child family could never qualify below 6 of 6). F4 (4 / 4) fails the
     * skew gate and F5 (4 / 0) the child-count gate. F3 carries an eighth child
     * with no SEX, which must be excluded — otherwise its ratio would be 6 of 8
     * = 75 % and it would drop out. The result sorts by skew descending, then by
     * child count descending: F1 and F2 tie at 100 %, so F2 (8 children) leads F1
     * (7), then F3 (85.7 %), then F6 (83.3 %).
     */
    #[Test]
    public function getSexRatioAnomaliesAppliesTheDefaultThresholdsAndSort(): void
    {
        $tree   = $this->importFixtureTree('sex-ratio-anomalies.ged');
        $result = (new FamilyRepository($tree))->getSexRatioAnomalies();

        self::assertSame(
            [
                ['F2', 0, 8],
                ['F1', 7, 0],
                ['F3', 6, 1],
                ['F6', 5, 1],
            ],
            $this->triples($result),
        );

        self::assertNotSame('', $result[0]->label, 'The display label must resolve to the couple, not an empty string');
    }

    /**
     * Lowering the minimum child count to four pulls F5 (4 sons / 0, 100 %) into
     * the list while the balanced F4 (4 / 4) still fails the skew gate. Sorted by
     * skew descending then child count descending: the three 100 % families
     * F2 (8), F1 (7) and F5 (4), then F3 (85.7 %, 7), then F6 (83.3 %, 6).
     */
    #[Test]
    public function getSexRatioAnomaliesHonoursAConfigurableMinimumChildCount(): void
    {
        $tree   = $this->importFixtureTree('sex-ratio-anomalies.ged');
        $result = (new FamilyRepository($tree))->getSexRatioAnomalies(4);

        self::assertSame(
            [
                ['F2', 0, 8],
                ['F1', 7, 0],
                ['F5', 4, 0],
                ['F3', 6, 1],
                ['F6', 5, 1],
            ],
            $this->triples($result),
        );
    }

    /**
     * The limit caps the result to the top-N most skewed families.
     */
    #[Test]
    public function getSexRatioAnomaliesCapsToTheLimit(): void
    {
        $tree   = $this->importFixtureTree('sex-ratio-anomalies.ged');
        $result = (new FamilyRepository($tree))->getSexRatioAnomalies(7, 0.85, 2);

        self::assertSame(
            [
                ['F2', 0, 8],
                ['F1', 7, 0],
            ],
            $this->triples($result),
        );
    }
}
