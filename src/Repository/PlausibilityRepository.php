<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Plausibility\FamilyRule;
use MagicSunday\Webtrees\Statistic\Plausibility\Finding;
use MagicSunday\Webtrees\Statistic\Plausibility\IndividualRule;
use MagicSunday\Webtrees\Statistic\Plausibility\Rules\DeathBeforeChildBirthRule;
use MagicSunday\Webtrees\Statistic\Plausibility\Rules\LifespanOverLimitRule;
use MagicSunday\Webtrees\Statistic\Plausibility\Rules\MarriageBeforeBirthRule;
use MagicSunday\Webtrees\Statistic\Plausibility\Rules\ParentAgeOutOfRangeRule;
use MagicSunday\Webtrees\Statistic\Plausibility\Rules\SiblingIntervalRule;
use Throwable;

use function array_slice;
use function count;
use function is_numeric;
use function is_string;

/**
 * Data-quality / plausibility-check aggregator for the TreeHealth
 * tab. Iterates the registered IndividualRule + FamilyRule sets
 * across every individual / family record, collects findings,
 * returns a summary (per-rule count + top-N example findings).
 *
 * Rules live as separate classes under `Plausibility\Rules` and
 * are registered here by static instantiation — the registry-of-
 * one-class-per-rule pattern keeps each check pure, testable in
 * isolation, and easy to extend.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class PlausibilityRepository
{
    /**
     * Top-N examples to surface per rule. The aggregator returns a
     * total count too, so the UI can render "5 of 47 examples" when
     * appropriate.
     */
    private const int MAX_EXAMPLES_PER_RULE = 5;

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Summary across all registered rules. Returns the grand total
     * + per-rule breakdowns. Each per-rule breakdown carries the
     * rule id, the count, and up to {@see MAX_EXAMPLES_PER_RULE}
     * sample {@see Finding} objects so the view can render a
     * drilldown without re-querying.
     *
     * @return array{
     *     totalCount: int,
     *     perRule: list<array{ruleId: string, count: int, examples: list<Finding>}>
     * }
     */
    public function summary(): array
    {
        $perRuleFindings = [];

        foreach ($this->runIndividualRules() as $finding) {
            $perRuleFindings[$finding->ruleId][] = $finding;
        }

        foreach ($this->runFamilyRules() as $finding) {
            $perRuleFindings[$finding->ruleId][] = $finding;
        }

        $perRule    = [];
        $totalCount = 0;

        foreach ($perRuleFindings as $ruleId => $findings) {
            $totalCount += count($findings);
            $perRule[] = [
                'ruleId'   => $ruleId,
                'count'    => count($findings),
                'examples' => $this->attachUrls(array_slice($findings, 0, self::MAX_EXAMPLES_PER_RULE)),
            ];
        }

        return [
            'totalCount' => $totalCount,
            'perRule'    => $perRule,
        ];
    }

    /**
     * Resolve the offending xref against the appropriate factory
     * and copy the live URL onto a fresh {@see Finding}. Records
     * that no longer exist (deleted between aggregation and render)
     * keep their null url and the view degrades to a plain xref
     * label.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function attachUrls(array $findings): array
    {
        $out = [];

        foreach ($findings as $finding) {
            $url = null;

            try {
                if ($finding->kind === 'individual') {
                    $individual = Registry::individualFactory()->make($finding->xref, $this->tree);

                    if ($individual instanceof Individual) {
                        $url = $individual->url();
                    }
                } elseif ($finding->kind === 'family') {
                    $family = Registry::familyFactory()->make($finding->xref, $this->tree);

                    if ($family instanceof Family) {
                        $url = $family->url();
                    }
                }
            } catch (Throwable) {
                // url() depends on a live request via webtrees' route()
                // helper; in integration-test contexts no request is
                // bound, so url-building throws. Degrade silently — the
                // view renders a plain xref span when url is null.
                $url = null;
            }

            $out[] = new Finding(
                $finding->xref,
                $finding->kind,
                $finding->ruleId,
                $finding->reason,
                $url,
            );
        }

        return $out;
    }

    /**
     * Yield findings from every registered individual-side rule.
     *
     * @return iterable<int, Finding>
     */
    private function runIndividualRules(): iterable
    {
        $rules = $this->individualRules();

        if ($rules === []) {
            return;
        }

        $rows = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->select(['i_id AS xref', 'i_gedcom AS gedcom'])
            ->get();

        foreach ($rows as $row) {
            $xref   = is_string($row->xref ?? null) ? $row->xref : '';
            $gedcom = is_string($row->gedcom ?? null) ? $row->gedcom : '';

            if ($xref === '') {
                continue;
            }

            foreach ($rules as $rule) {
                yield from $rule->check($xref, $gedcom);
            }
        }
    }

    /**
     * Yield findings from every registered family-side rule. Each
     * family row carries its husband / wife BIRT + DEAT julian-days
     * and its CHIL list with per-child BIRT julian-days so rules can
     * compute parent-age and death-before-child-birth without
     * re-querying.
     *
     * @return iterable<int, Finding>
     */
    private function runFamilyRules(): iterable
    {
        $rules = $this->familyRules();

        if ($rules === []) {
            return;
        }

        $rows = DB::table('families AS fam')
            ->where('fam.f_file', '=', $this->tree->id())
            ->leftJoin('dates AS hb', static function (JoinClause $join): void {
                $join
                    ->on('hb.d_file', '=', 'fam.f_file')
                    ->on('hb.d_gid', '=', 'fam.f_husb')
                    ->where('hb.d_fact', '=', 'BIRT')
                    ->whereIn('hb.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->leftJoin('dates AS wb', static function (JoinClause $join): void {
                $join
                    ->on('wb.d_file', '=', 'fam.f_file')
                    ->on('wb.d_gid', '=', 'fam.f_wife')
                    ->where('wb.d_fact', '=', 'BIRT')
                    ->whereIn('wb.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->leftJoin('dates AS hd', static function (JoinClause $join): void {
                $join
                    ->on('hd.d_file', '=', 'fam.f_file')
                    ->on('hd.d_gid', '=', 'fam.f_husb')
                    ->where('hd.d_fact', '=', 'DEAT')
                    ->whereIn('hd.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->leftJoin('dates AS wd', static function (JoinClause $join): void {
                $join
                    ->on('wd.d_file', '=', 'fam.f_file')
                    ->on('wd.d_gid', '=', 'fam.f_wife')
                    ->where('wd.d_fact', '=', 'DEAT')
                    ->whereIn('wd.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->select([
                'fam.f_id AS xref',
                'fam.f_gedcom AS gedcom',
                'hb.d_julianday1 AS hb_jd',
                'wb.d_julianday1 AS wb_jd',
                'hd.d_julianday1 AS hd_jd',
                'wd.d_julianday1 AS wd_jd',
            ])
            ->get();

        $childRows = DB::table('link AS cf')
            ->where('cf.l_file', '=', $this->tree->id())
            ->where('cf.l_type', '=', 'FAMC')
            ->join('dates AS cb', static function (JoinClause $join): void {
                $join
                    ->on('cb.d_file', '=', 'cf.l_file')
                    ->on('cb.d_gid', '=', 'cf.l_from')
                    ->where('cb.d_fact', '=', 'BIRT')
                    ->whereIn('cb.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('cb.d_julianday1', '>', 0);
            })
            ->select(['cf.l_to AS family', 'cf.l_from AS xref', 'cb.d_julianday1 AS birth_jd'])
            ->get();

        $childrenByFamily = [];

        foreach ($childRows as $childRow) {
            $famId = is_string($childRow->family ?? null) ? $childRow->family : '';
            $xref  = is_string($childRow->xref ?? null) ? $childRow->xref : '';
            $jd    = is_numeric($childRow->birth_jd ?? null) ? (int) $childRow->birth_jd : 0;

            if ($famId === '') {
                continue;
            }

            if ($xref === '') {
                continue;
            }

            if ($jd <= 0) {
                continue;
            }

            $childrenByFamily[$famId][] = ['xref' => $xref, 'birthJd' => $jd];
        }

        foreach ($rows as $row) {
            $xref   = is_string($row->xref ?? null) ? $row->xref : '';
            $gedcom = is_string($row->gedcom ?? null) ? $row->gedcom : '';

            if ($xref === '') {
                continue;
            }

            $context = [
                'fatherBirthJd' => is_numeric($row->hb_jd ?? null) ? (int) $row->hb_jd : null,
                'motherBirthJd' => is_numeric($row->wb_jd ?? null) ? (int) $row->wb_jd : null,
                'fatherDeathJd' => is_numeric($row->hd_jd ?? null) ? (int) $row->hd_jd : null,
                'motherDeathJd' => is_numeric($row->wd_jd ?? null) ? (int) $row->wd_jd : null,
            ];

            $children = $childrenByFamily[$xref] ?? [];

            foreach ($rules as $rule) {
                yield from $rule->check($xref, $gedcom, $children, $context);
            }
        }
    }

    /**
     * Static registry of individual-side rules. Adding a new rule
     * is one append here plus one new class under `Plausibility\Rules`.
     *
     * @return list<IndividualRule>
     */
    private function individualRules(): array
    {
        return [
            new LifespanOverLimitRule(),
        ];
    }

    /**
     * Static registry of family-side rules.
     *
     * @return list<FamilyRule>
     */
    private function familyRules(): array
    {
        return [
            new ParentAgeOutOfRangeRule(),
            new DeathBeforeChildBirthRule(),
            new MarriageBeforeBirthRule(),
            new SiblingIntervalRule(),
        ];
    }
}
