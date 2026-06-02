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
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Enum\MaritalBucket;
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Model\Family\SexRatioAnomaly;
use MagicSunday\Webtrees\Statistic\Model\FamilyRow;
use MagicSunday\Webtrees\Statistic\Support\Database\ChunkedWhereIn;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_key_exists;
use function array_slice;
use function array_unique;
use function array_values;
use function html_entity_decode;
use function is_string;
use function max;
use function strip_tags;
use function usort;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Classifies every living individual in a tree into exactly one marital state
 * (current, widowed, divorced, single) using the same per-family decision order
 * webtrees core uses in {@see
 * \Fisharebest\Webtrees\Census\AbstractCensusColumnCondition}:
 *
 *  1. A family that has neither a marriage tag nor a divorce tag is treated
 *     as a non-marital relationship → 'single' (no contribution).
 *  2. A family carrying any divorce-end tag (DIV, ANUL) classes the survivor
 *     as 'divorced'.
 *  3. A family carrying a true marriage tag (MARR) with a deceased spouse
 *     classes the survivor as 'widowed'.
 *  4. A family carrying a true marriage tag (MARR) with a living partner
 *     classes both spouses as 'current'.
 *
 * Death tags mirror {@see Gedcom::DEATH_EVENTS} (DEAT, BURI, CREM) so the four
 * bucket counts sum exactly to {@see
 * \Fisharebest\Webtrees\StatisticsData::countIndividualsLiving()} without
 * clamping. Marriage and divorce tag sets are intentionally tighter than the
 * Gedcom event constants because {@see Gedcom::MARRIAGE_EVENTS} includes `_NMR`
 * ('not married') and {@see Gedcom::DIVORCE_EVENTS} includes `_SEPR` (separated
 * but still married); both would invert the bucket semantics if used as-is.
 *
 * Across-family precedence applied per individual (highest wins): current >
 * divorced > widowed. This matches the typical user expectation that a
 * remarried person is 'currently married' rather than carrying a status from a
 * prior family.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class FamilyRepository
{
    /**
     * GEDCOM family-event tags that denote an active marriage. Excludes `_NMR`
     * from {@see Gedcom::MARRIAGE_EVENTS} because that tag means 'not married'
     * — its presence is the opposite of a marriage signal.
     */
    private const array MARRIAGE_TAGS = ['MARR'];

    /**
     * GEDCOM family-event tags that denote an ended marriage. Excludes `_SEPR`
     * from {@see Gedcom::DIVORCE_EVENTS} because separation does not legally
     * end the marriage — webtrees Census also uses only `DIV` for this gate but
     * we additionally recognise `ANUL` (annulment) so that nullified marriages
     * are not misclassified as 'current'.
     */
    private const array DIVORCE_TAGS = ['DIV', 'ANUL'];

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Return the per-bucket count of living individuals.
     *
     * Precedence is applied per individual: current > divorced > widowed >
     * single. The four bucket counts sum to the number of living individuals
     * (no clamping).
     *
     * @return array<value-of<MaritalBucket>, int>
     */
    public function classifyLivingIndividuals(): array
    {
        $treeId        = $this->tree->id();
        $rows          = $this->loadLivingIndividualFamilies($treeId);
        $partnerStates = $this->loadPartnerDeathStates($treeId, $rows);

        $byIndividual = [];

        foreach ($rows as $row) {
            $byIndividual[$row->individualId][] = $row;
        }

        $counts = [
            MaritalBucket::Current->value  => 0,
            MaritalBucket::Divorced->value => 0,
            MaritalBucket::Widowed->value  => 0,
            MaritalBucket::Single->value   => 0,
        ];

        foreach ($byIndividual as $familyRows) {
            $bucket = $this->classifyOneIndividual($familyRows, $partnerStates);
            ++$counts[$bucket->value];
        }

        return $counts;
    }

    /**
     * Families whose recorded children are heavily skewed toward one sex.
     *
     * Counts the sons and daughters reachable through each family's CHIL links
     * (children carrying `i_sex` M or F; a child with no recorded sex is
     * excluded from both the dominant count and the total, so it cannot pull a
     * family below the threshold). A family qualifies when it has at least
     * `$minChildren` sexed children AND the dominant sex makes up at least
     * `$skewThreshold` of them. The result is ranked by skew descending, then
     * child count descending, then family XREF as a stable final tie-break, and
     * capped at `$limit`.
     *
     * Privacy follows the project convention for ranked extremes: families are
     * ranked from the raw counts and only resolved to a display label through
     * the family factory, so {@see Family::fullName()} applies webtrees' own
     * privacy filtering without this method second-guessing it.
     *
     * @param int   $minChildren   Minimum number of sexed children a family must have
     * @param float $skewThreshold Minimum share (0..1) the dominant sex must reach
     * @param int   $limit         Maximum number of families to return
     *
     * @return list<SexRatioAnomaly>
     */
    public function getSexRatioAnomalies(int $minChildren = 6, float $skewThreshold = 0.80, int $limit = 10): array
    {
        $maleToken   = Sex::Male->value;
        $femaleToken = Sex::Female->value;

        $rows = TreeScope::table($this->tree, 'families', 'fam')
            ->join('link AS famc', static function (JoinClause $join): void {
                $join
                    ->on('famc.l_file', '=', 'fam.f_file')
                    ->on('famc.l_to', '=', 'fam.f_id')
                    ->where('famc.l_type', '=', 'FAMC');
            })
            ->join('individuals AS child', static function (JoinClause $join) use ($maleToken, $femaleToken): void {
                $join
                    ->on('child.i_file', '=', 'famc.l_file')
                    ->on('child.i_id', '=', 'famc.l_from')
                    ->whereIn('child.i_sex', [$maleToken, $femaleToken]);
            })
            ->groupBy('fam.f_id', 'child.i_sex')
            ->select([
                'fam.f_id AS f_id',
                'child.i_sex AS sex',
                new Expression('COUNT(*) AS cnt'),
            ])
            ->get();

        // Tally the per-sex counts into one row per family.
        /** @var array<string, array{sons: int, daughters: int}> $byFamily */
        $byFamily = [];

        foreach ($rows as $row) {
            $familyId = RowCast::string($row, 'f_id');

            if ($familyId === '') {
                continue;
            }

            $byFamily[$familyId] ??= ['sons' => 0, 'daughters' => 0];

            if (RowCast::string($row, 'sex') === $maleToken) {
                $byFamily[$familyId]['sons'] = RowCast::int($row, 'cnt');
            } else {
                $byFamily[$familyId]['daughters'] = RowCast::int($row, 'cnt');
            }
        }

        $anomalies = [];

        foreach ($byFamily as $familyId => $counts) {
            $sons      = $counts['sons'];
            $daughters = $counts['daughters'];
            $total     = $sons + $daughters;

            if ($total < $minChildren) {
                continue;
            }

            // Threshold via multiplication rather than division — avoids a
            // divide-by-zero path and keeps the comparison exact for the counts.
            if (max($sons, $daughters) < ($skewThreshold * $total)) {
                continue;
            }

            $family = Registry::familyFactory()->make($familyId, $this->tree);

            if (!$family instanceof Family) {
                continue;
            }

            $label = html_entity_decode(strip_tags($family->fullName()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $anomalies[] = new SexRatioAnomaly(
                familyXref: $familyId,
                label: $label,
                sons: $sons,
                daughters: $daughters,
            );
        }

        usort($anomalies, static function (SexRatioAnomaly $a, SexRatioAnomaly $b): int {
            // Skew descending via cross-multiplication (max_a/total_a vs
            // max_b/total_b without floating-point division), then child count
            // descending, then XREF ascending for a deterministic final order.
            $bySkew = (max($b->sons, $b->daughters) * $a->total())
                <=> (max($a->sons, $a->daughters) * $b->total());

            if ($bySkew !== 0) {
                return $bySkew;
            }

            return [$b->total(), $a->familyXref] <=> [$a->total(), $b->familyXref];
        });

        return array_slice($anomalies, 0, $limit);
    }

    /**
     * Load every (living individual × family-membership) row for the tree.
     * Living means `i_gedcom` does not contain any of {@see
     * Gedcom::DEATH_EVENTS} at level 1. Individuals with no family produce one
     * row with NULL family columns thanks to LEFT JOIN.
     *
     * @param int $treeId Tree row ID that scopes the SELECT
     *
     * @return list<FamilyRow>
     */
    private function loadLivingIndividualFamilies(int $treeId): array
    {
        // Reach each individual's spouse-families through the indexed `link`
        // table (l_type = FAMS) rather than matching `families.f_husb = i_id OR
        // f_wife = i_id` directly. An OR across two columns in the JOIN
        // condition cannot use an index, so the direct match degrades to a
        // nested-loop scan of O(individuals × families) — minutes on a large
        // tree (issue #82). The link join keys on the indexed `link.l_from`,
        // then resolves the family by its primary key, restoring near-linear
        // scaling. The two LEFT JOINs preserve the family-less row (NULL family
        // columns) for individuals with no FAMS membership.
        $query = DB::table('individuals')
            ->leftJoin('link', static function (JoinClause $join): void {
                $join
                    ->on('link.l_file', '=', 'individuals.i_file')
                    ->on('link.l_from', '=', 'individuals.i_id')
                    ->where('link.l_type', '=', 'FAMS');
            })
            ->leftJoin('families', static function (JoinClause $join): void {
                $join
                    ->on('families.f_file', '=', 'individuals.i_file')
                    ->on('families.f_id', '=', 'link.l_to');
            })
            ->where('individuals.i_file', '=', $treeId);

        $this->applyNotLikeAny($query, 'individuals.i_gedcom', Gedcom::DEATH_EVENTS);

        $result = $query
            ->select(
                'individuals.i_id AS i_id',
                'families.f_husb AS f_husb',
                'families.f_wife AS f_wife',
                'families.f_gedcom AS f_gedcom',
            )
            ->get();

        $rows = [];

        foreach ($result as $row) {
            $rows[] = new FamilyRow(
                individualId: $this->coerceString($row->i_id) ?? '',
                husbandId: $this->coerceString($row->f_husb),
                wifeId: $this->coerceString($row->f_wife),
                familyGedcom: $this->coerceString($row->f_gedcom),
            );
        }

        return $rows;
    }

    /**
     * Fetch each distinct partner ID referenced by `$rows` and return a map
     * `partnerId => hasAnyTagAnchored(partner.i_gedcom, DEATH_EVENTS)`. A
     * partner XREF that does not resolve to an INDI row (orphan/deleted) is
     * intentionally omitted from the map so the caller can distinguish 'partner
     * alive' from 'partner unknown'.
     *
     * @param int             $treeId Tree row ID that scopes the SELECT
     * @param list<FamilyRow> $rows   Family-membership rows from {@see loadLivingIndividualFamilies()}
     *
     * @return array<string, bool>
     */
    private function loadPartnerDeathStates(int $treeId, array $rows): array
    {
        $partnerIds = [];

        foreach ($rows as $row) {
            $partnerId = $this->partnerIdOf($row);

            if ($partnerId !== null) {
                $partnerIds[] = $partnerId;
            }
        }

        $partnerIds = array_values(array_unique($partnerIds));

        if ($partnerIds === []) {
            return [];
        }

        // The partner set scales with the living individuals of the tree, so on
        // a large tree it can exceed the database's prepared-statement
        // placeholder ceiling if bound as a single `whereIn` (issue #82). {@see
        // ChunkedWhereIn} slices the id list so each round-trip stays within
        // budget.
        $query = DB::table('individuals')
            ->where('i_file', '=', $treeId)
            ->select('i_id', 'i_gedcom');

        $partners = ChunkedWhereIn::get($query, 'i_id', $partnerIds);

        $out = [];

        foreach ($partners as $partner) {
            $partnerId     = $this->coerceString($partner->i_id);
            $partnerGedcom = $this->coerceString($partner->i_gedcom);

            if ($partnerId === null) {
                continue;
            }

            $out[$partnerId] = ($partnerGedcom !== null)
                && GedcomScanner::hasAnyTagAnchored($partnerGedcom, Gedcom::DEATH_EVENTS);
        }

        return $out;
    }

    /**
     * Resolve the marital bucket for one living individual by inspecting all
     * their family-membership rows and applying the documented precedence.
     *
     * @param list<FamilyRow>     $familyRows    Rows where row.individualId is the individual to classify
     * @param array<string, bool> $partnerStates Map partner-XREF → isDead
     */
    private function classifyOneIndividual(array $familyRows, array $partnerStates): MaritalBucket
    {
        $hasCurrent  = false;
        $hasDivorced = false;
        $hasWidowed  = false;

        foreach ($familyRows as $row) {
            if ($row->familyGedcom === null) {
                continue;
            }

            $partnerId = $this->partnerIdOf($row);

            $isMarriage = GedcomScanner::hasAnyTagAnchored($row->familyGedcom, self::MARRIAGE_TAGS);
            $isDivorced = GedcomScanner::hasAnyTagAnchored($row->familyGedcom, self::DIVORCE_TAGS);

            // A family that is neither a marriage nor an ended marriage does
            // not contribute to any bucket — the individual stays in
            // whatever bucket their other families assign, or in 'single'.
            if (!$isMarriage && !$isDivorced) {
                continue;
            }

            if ($isDivorced) {
                $hasDivorced = true;

                continue;
            }

            if ($partnerId === null) {
                continue;
            }

            if (!array_key_exists($partnerId, $partnerStates)) {
                // Orphaned XREF (deleted INDI record): partner state is
                // unknown, so do not commit to either widowed or current.
                continue;
            }

            if ($partnerStates[$partnerId]) {
                $hasWidowed = true;
            } else {
                $hasCurrent = true;
            }
        }

        if ($hasCurrent) {
            return MaritalBucket::Current;
        }

        if ($hasDivorced) {
            return MaritalBucket::Divorced;
        }

        if ($hasWidowed) {
            return MaritalBucket::Widowed;
        }

        return MaritalBucket::Single;
    }

    /**
     * Resolve the partner-individual ID of a single family-membership row.
     * Treats an empty-string `f_husb`/`f_wife` (the webtrees default when a
     * HUSB/WIFE line is missing) the same as NULL, and rejects partner XREFs
     * equal to the individual themselves (data corruption).
     */
    private function partnerIdOf(FamilyRow $row): ?string
    {
        if (($row->husbandId === null) && ($row->wifeId === null)) {
            return null;
        }

        $partner = ($row->husbandId === $row->individualId) ? $row->wifeId : $row->husbandId;

        if ($partner === null) {
            return null;
        }

        return ($partner === $row->individualId) ? null : $partner;
    }

    /**
     * Apply `column NOT LIKE %\n1 <event>%` for every event tag in the list.
     *
     * @param Builder            $query  Builder to mutate in-place
     * @param string             $column Fully-qualified column reference
     * @param array<int, string> $tags   Level-1 tags to exclude
     */
    private function applyNotLikeAny(Builder $query, string $column, array $tags): void
    {
        foreach ($tags as $tag) {
            $query->where($column, 'NOT LIKE', "%\n1 " . $tag . '%');
        }
    }

    /**
     * Narrow a raw database column value (`mixed` because PDO+stdClass do not
     * expose property types) into a non-empty string, or null.
     */
    private function coerceString(mixed $value): ?string
    {
        if (!is_string($value) || ($value === '')) {
            return null;
        }

        return $value;
    }
}
