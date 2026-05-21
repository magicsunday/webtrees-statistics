<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Plausibility\Finding;
use MagicSunday\Webtrees\Statistic\Repository\PlausibilityRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_column;

/**
 * End-to-end test of the {@see PlausibilityRepository} against
 * `plausibility.ged`. The fixture stages exactly one triggering
 * record per rule plus at least one clean control:
 *
 *   I1 LongLife (BIRT 1800, DEAT 1950, 150 years) — fires
 *       lifespan-over-limit. I2 CleanLife (80 years) is the
 *       clean control.
 *
 *   F1 ClassicFather (DEAT 1910) + OldMother (BIRT 1850, DEAT
 *       1920) → LateChild (BIRT 1920) — fires both
 *       death-before-child-birth (father dead a decade before
 *       child was born) AND parent-age-out-of-range (mother was
 *       70 at LateChild's birth).
 *
 *   F2 EarlyMar (BIRT 1900) + PreMarBride (BIRT 1910), MARR
 *       1895 — fires marriage-before-birth on both spouses.
 *
 *   F3 DadA + MomA → TwinA (BIRT 01 JAN 1925) + TwinB (BIRT 15
 *       JAN 1925) — fires sibling-interval (14 days apart,
 *       below the nine-month plausibility floor).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class PlausibilityRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Every documented rule fires at least once against the
     * fixture. Confirms the registry is wired correctly and every
     * rule class is reachable from the aggregator.
     */
    #[Test]
    public function summaryFiresEveryRegisteredRule(): void
    {
        $tree   = $this->importFixtureTree('plausibility.ged');
        $result = (new PlausibilityRepository($tree))->summary();

        $ruleIds = array_column($result['perRule'], 'ruleId');

        self::assertContains('lifespan-over-limit', $ruleIds);
        self::assertContains('parent-age-out-of-range', $ruleIds);
        self::assertContains('death-before-child-birth', $ruleIds);
        self::assertContains('marriage-before-birth', $ruleIds);
        self::assertContains('sibling-interval', $ruleIds);
    }

    /**
     * Lifespan rule picks up I1 (150 years) and skips I2
     * (80 years). The clean control never appears under any
     * rule's examples — that is what "data quality widget"
     * means.
     */
    #[Test]
    public function lifespanRuleFlagsImplausibleAgeOnly(): void
    {
        $tree   = $this->importFixtureTree('plausibility.ged');
        $result = (new PlausibilityRepository($tree))->summary();

        $lifespan = $this->ruleSection($result['perRule'], 'lifespan-over-limit');
        $xrefs    = array_column($lifespan['examples'], 'xref');

        self::assertContains('I1', $xrefs, 'I1 LongLife (150y) must be flagged');
        self::assertNotContains('I2', $xrefs, 'I2 CleanLife (80y) must NOT be flagged');
    }

    /**
     * Death-before-child-birth rule fires on I5 (born ten years
     * after his father's recorded death). The grace window (300
     * days for posthumous father) is deliberately overshot by
     * the fixture so the rule cannot pass on a borderline case.
     */
    #[Test]
    public function deathBeforeChildBirthFlagsLateChild(): void
    {
        $tree   = $this->importFixtureTree('plausibility.ged');
        $result = (new PlausibilityRepository($tree))->summary();

        $section = $this->ruleSection($result['perRule'], 'death-before-child-birth');

        self::assertGreaterThan(0, $section['count']);
        self::assertContains('I5', array_column($section['examples'], 'xref'));
    }

    /**
     * Sibling-interval rule fires on the twin-pair (14 days
     * apart). The fixture deliberately stays well above the
     * 50-year max-gap threshold so the second branch of the
     * combined rule does not pollute this assertion.
     */
    #[Test]
    public function siblingIntervalRuleFlagsTooCloseSiblings(): void
    {
        $tree   = $this->importFixtureTree('plausibility.ged');
        $result = (new PlausibilityRepository($tree))->summary();

        $section = $this->ruleSection($result['perRule'], 'sibling-interval');
        $xrefs   = array_column($section['examples'], 'xref');

        self::assertContains('I9', $xrefs, 'TwinB (born 14 days after TwinA) must be flagged');
    }

    /**
     * Marriage-before-birth fires when MARR julian-day predates
     * either spouse's BIRT. The F2 marriage in 1895 predates
     * both spouses (born 1900 and 1910), so both spouse-branches
     * of the rule must trigger.
     */
    #[Test]
    public function marriageBeforeBirthFiresOnBothSpouses(): void
    {
        $tree   = $this->importFixtureTree('plausibility.ged');
        $result = (new PlausibilityRepository($tree))->summary();

        $section = $this->ruleSection($result['perRule'], 'marriage-before-birth');

        self::assertGreaterThanOrEqual(2, $section['count'], 'both husband and wife branches must fire');
    }

    /**
     * Locate one named rule section from the aggregator output.
     *
     * @param list<array{ruleId: string, count: int, examples: list<Finding>}> $perRule
     *
     * @return array{ruleId: string, count: int, examples: list<Finding>}
     */
    private function ruleSection(array $perRule, string $ruleId): array
    {
        foreach ($perRule as $section) {
            if ($section['ruleId'] === $ruleId) {
                return $section;
            }
        }

        self::fail('Rule section not found: ' . $ruleId);
    }
}
