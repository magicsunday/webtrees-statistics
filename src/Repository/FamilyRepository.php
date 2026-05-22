<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Model\FamilyRow;
use MagicSunday\Webtrees\Statistic\Model\MaritalBucket;
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;

use function array_key_exists;
use function array_unique;
use function array_values;
use function is_string;

/**
 * Classifies every living individual in a tree into exactly one marital
 * state (current, widowed, divorced, single) using the same per-family
 * decision order webtrees core uses in {@see \Fisharebest\Webtrees\Census\AbstractCensusColumnCondition}:
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
 * Death tags mirror {@see Gedcom::DEATH_EVENTS} (DEAT, BURI, CREM) so the
 * four bucket counts sum exactly to
 * {@see \Fisharebest\Webtrees\StatisticsData::countIndividualsLiving()}
 * without clamping. Marriage and divorce tag sets are intentionally tighter
 * than the Gedcom event constants because {@see Gedcom::MARRIAGE_EVENTS}
 * includes `_NMR` ('not married') and {@see Gedcom::DIVORCE_EVENTS} includes
 * `_SEPR` (separated but still married); both would invert the bucket
 * semantics if used as-is.
 *
 * Across-family precedence applied per individual (highest wins):
 * current > divorced > widowed. This matches the typical user expectation
 * that a remarried person is 'currently married' rather than carrying a
 * status from a prior family.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class FamilyRepository
{
    /**
     * GEDCOM family-event tags that denote an active marriage. Excludes
     * `_NMR` from {@see Gedcom::MARRIAGE_EVENTS} because that tag means
     * 'not married' — its presence is the opposite of a marriage signal.
     */
    private const array MARRIAGE_TAGS = ['MARR'];

    /**
     * GEDCOM family-event tags that denote an ended marriage. Excludes
     * `_SEPR` from {@see Gedcom::DIVORCE_EVENTS} because separation does
     * not legally end the marriage — webtrees Census also uses only
     * `DIV` for this gate but we additionally recognise `ANUL` (annulment)
     * so that nullified marriages are not misclassified as 'current'.
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
     * single. The four bucket counts sum to the number of living
     * individuals (no clamping).
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
     * Load every (living individual × family-membership) row for the tree.
     * Living means `i_gedcom` does not contain any of {@see Gedcom::DEATH_EVENTS}
     * at level 1. Individuals with no family produce one row with NULL family
     * columns thanks to LEFT JOIN.
     *
     * @param int $treeId Tree row ID that scopes the SELECT
     *
     * @return list<FamilyRow>
     */
    private function loadLivingIndividualFamilies(int $treeId): array
    {
        $query = DB::table('individuals')
            ->leftJoin('families', static function (JoinClause $join): void {
                $join
                    ->on('families.f_file', '=', 'individuals.i_file')
                    ->where(static function (Builder $partner): void {
                        $partner
                            ->whereColumn('families.f_husb', '=', 'individuals.i_id')
                            ->orWhereColumn('families.f_wife', '=', 'individuals.i_id');
                    });
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
     * partner XREF that does not resolve to an INDI row (orphan/deleted)
     * is intentionally omitted from the map so the caller can distinguish
     * 'partner alive' from 'partner unknown'.
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

        $partners = DB::table('individuals')
            ->where('i_file', '=', $treeId)
            ->whereIn('i_id', $partnerIds)
            ->select('i_id', 'i_gedcom')
            ->get();

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
     * Treats an empty-string `f_husb`/`f_wife` (the webtrees default when
     * a HUSB/WIFE line is missing) the same as NULL, and rejects partner
     * XREFs equal to the individual themselves (data corruption).
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
     * Narrow a raw database column value (`mixed` because PDO+stdClass do
     * not expose property types) into a non-empty string, or null.
     */
    private function coerceString(mixed $value): ?string
    {
        if (!is_string($value) || ($value === '')) {
            return null;
        }

        return $value;
    }
}
