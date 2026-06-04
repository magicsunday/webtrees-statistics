<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Support\Database\ChildLinkJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function array_intersect;
use function array_keys;
use function array_map;
use function explode;
use function ksort;
use function mb_strtolower;
use function preg_match;
use function preg_split;
use function round;
use function trim;
use function usort;

use const PHP_INT_MAX;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Top-N lists and total counts for surnames and given names. Both the lists
 * and the headline counts are computed from a single source of truth in this
 * repository so they stay in lockstep.
 *
 * The aggregation deliberately diverges from webtrees core's
 * `StatisticsData::commonGivenNames()` / `commonSurnames()` in one respect: it
 * restricts the indexed `name` rows to the primary and standardised
 * transliteration name forms in {@see NAME_TYPE_WHITELIST} (an allow-list)
 * instead of merely excluding `_MARNM` (core's deny-list). Core's deny-list
 * lets every arbitrary custom name sub-tag through, so a record like
 * `1 NAME /Ditchi/` / `2 _LAST 05 May 2001` is indexed as a separate `name`
 * row whose `n_givn` is the literal date string and tokenises into `05` /
 * `May` / `2001` — junk that then surfaces as "common given names". The
 * allow-list keeps only `NAME` plus the romanised / phonetic / Hebrew
 * transliteration variants, which carry the real name in another script. Like
 * core it excludes `_MARNM`; unlike core it also drops the `_AKA` / `_AKAN`
 * aliases, so surnames and given names stay counted by primary (birth) name
 * rather than by married names or nicknames.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class NameRepository
{
    /**
     * Per-century sample floor for {@see sameSexNamePassdownByCentury}.
     * Centuries with fewer same-sex parent-child pairs are suppressed because a
     * single match would swing the rate by more than 10 percentage points and
     * read as noise rather than signal.
     */
    private const int MIN_COHORT_SIZE = 10;

    /**
     * Script and transliteration variants of a person's actual name that count
     * towards the name statistics: the primary name plus the romanised,
     * phonetic and Hebrew writings of the *same* name. Excluded are the
     * alternate-name forms (`_MARNM` married name, `_AKA` / `_AKAN` aliases) —
     * so surnames are counted by primary (birth) name exactly as webtrees core
     * does — and every arbitrary custom `_…` tag (e.g. a misused
     * `_LAST 05 May 2001`) whose free-text value would otherwise leak into the
     * aggregation.
     *
     * @var list<string>
     */
    private const array NAME_TYPE_WHITELIST = ['NAME', 'ROMN', 'FONE', '_HEB'];

    /**
     * Sentinel sex selector for the given-name aggregations meaning "every
     * sex": skip the `individuals` join and count names regardless of the
     * recorded sex. The binary {@see Sex} enum only models the M / F spouse
     * selector, so the all-sexes case needs its own named token rather than a
     * raw `'ALL'` literal at every call site.
     */
    public const string SEX_ALL = 'ALL';

    /**
     * @param Tree $tree The tree whose names to aggregate
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Top-N surnames as `[{label, value}]`, ready for the chart widget. A
     * single grouped query selects the most frequent whitelisted surnames
     * (descending occurrence, `n_surn` as a deterministic tie-break) above the
     * threshold; the result is then sorted alphabetically for a stable display
     * order. Counting inside the GROUP BY avoids the per-surname follow-up
     * query that core's `commonSurnames()` fires for every distinct surname.
     *
     * @param int $limit     Maximum number of surnames to return
     * @param int $threshold Lower bound on the occurrences a surname must reach
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function topSurnames(int $limit, int $threshold = 1): array
    {
        $entries = $this->baseSurnameQuery()
            ->select(['n_surn', new Expression('COUNT(n_surn) AS total')])
            ->groupBy(['n_surn'])
            ->having(new Expression('COUNT(n_surn)'), '>=', $threshold)
            ->orderBy(new Expression('COUNT(n_surn)'), 'desc')
            ->orderBy('n_surn')
            ->take($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'label' => RowCast::string($row, 'n_surn'),
                'value' => RowCast::int($row, 'total'),
            ])
            ->all();

        return $this->sortEntriesByLabel($entries);
    }

    /**
     * Number of distinct surnames in the tree, computed from the same filter
     * stack as {@see topSurnames()}: restrict to whitelisted name forms, drop
     * empty / `NOMEN_NESCIO` values, then count distinct surname tokens whose
     * occurrence ≥ `$threshold`.
     *
     * Splits into two query shapes so the result stays valid under MySQL's
     * `ONLY_FULL_GROUP_BY` mode (default on MySQL 5.7+ and MariaDB 10.5+),
     * where `SELECT *` combined with `GROUP BY n_surn` is rejected because the
     * non-aggregated columns are not functionally dependent on the grouping
     * key.
     *
     * @param int $threshold Lower bound on the occurrences a surname must have
     */
    public function countDistinctSurnames(int $threshold = 1): int
    {
        $query = $this->baseSurnameQuery();

        // Fast path — no occurrence filter, so a plain DISTINCT count
        // is enough and avoids the per-surname GROUP BY scan.
        if ($threshold <= 1) {
            return $query->distinct()->count('n_surn');
        }

        // Threshold > 1: keep only surnames whose occurrence reaches
        // the floor, then count the surviving groups. Selecting just
        // the grouping column keeps the statement ONLY_FULL_GROUP_BY
        // compliant on every supported engine.
        return $query
            ->select(['n_surn'])
            ->groupBy('n_surn')
            ->having(new Expression('COUNT(n_surn)'), '>=', $threshold)
            ->get()
            ->count();
    }

    /**
     * Top-N given names for a sex as `[{label, value}]`, ready for the chart
     * widget. Selection is by descending occurrence (so the most common names
     * win the limited slots); the returned list is then sorted alphabetically
     * for a stable display order.
     *
     * @param string $sex       GEDCOM sex token — a {@see Sex} case value or {@see SEX_ALL} for every sex
     * @param int    $threshold Lower bound on the occurrences a name must reach
     * @param int    $limit     Maximum number of names to return
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function topGivenNames(string $sex, int $threshold, int $limit): array
    {
        $entries = [];

        foreach ($this->aggregateGivenNames($sex, $threshold, $limit) as $name => $count) {
            $entries[] = ['label' => $name, 'value' => $count];
        }

        return $this->sortEntriesByLabel($entries);
    }

    /**
     * Number of distinct given names for a sex, computed from the same
     * aggregation that feeds the Top-N given-name list.
     *
     * @param string $sex       GEDCOM sex token — a {@see Sex} case value or {@see SEX_ALL} for every sex
     * @param int    $threshold Lower bound on the occurrences a given name must have
     */
    public function countDistinctGivenNames(string $sex, int $threshold = 1): int
    {
        return $this->aggregateGivenNames($sex, $threshold, PHP_INT_MAX)->count();
    }

    /**
     * Sort a Top-N `[{label, value}]` list alphabetically by label for a stable
     * display order. Selection by occurrence already happened upstream; this is
     * the shared display sort for both the surname and given-name lists.
     *
     * @param array<int, array{label: string, value: int}> $entries
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function sortEntriesByLabel(array $entries): array
    {
        usort(
            $entries,
            static fn (array $x, array $y): int => $x['label'] <=> $y['label'],
        );

        return $entries;
    }

    /**
     * Shared filter stack for the surname aggregations: scope to the tree,
     * restrict to the whitelisted name forms, and drop empty / `NOMEN_NESCIO`
     * surnames.
     */
    private function baseSurnameQuery(): Builder
    {
        return DB::table('name')
            ->where('n_file', '=', $this->tree->id())
            ->whereIn('n_type', self::NAME_TYPE_WHITELIST)
            ->whereNotIn('n_surn', ['', Individual::NOMEN_NESCIO]);
    }

    /**
     * Tokenised given-name frequency map, restricted to the whitelisted name
     * forms. Mirrors webtrees core's tokenisation 1:1 — split each `n_givn` on
     * spaces, drop initials and particles via the `/^([A-Z]|[a-z]{1,3})$/`
     * filter, and accumulate the per-name `COUNT(DISTINCT n_id)` against every
     * surviving token — but swaps core's `n_type <> '_MARNM'` blacklist for the
     * whitelist so arbitrary custom sub-tags never reach the tokeniser.
     *
     * @param string $sex       GEDCOM sex token — a {@see Sex} case value or {@see SEX_ALL} for every sex
     * @param int    $threshold Lower bound on the occurrences a token must reach
     * @param int    $limit     Maximum number of tokens to keep, by descending count
     *
     * @return Collection<string, int>
     */
    private function aggregateGivenNames(string $sex, int $threshold, int $limit): Collection
    {
        $query = DB::table('name')
            ->where('n_file', '=', $this->tree->id())
            ->whereIn('n_type', self::NAME_TYPE_WHITELIST)
            ->where('n_givn', '<>', Individual::PRAENOMEN_NESCIO)
            ->where(new Expression('LENGTH(n_givn)'), '>', 1);

        if ($sex !== self::SEX_ALL) {
            $query
                ->join('individuals', static function (JoinClause $join): void {
                    $join
                        ->on('i_file', '=', 'n_file')
                        ->on('i_id', '=', 'n_id');
                })
                ->where('i_sex', '=', $sex);
        }

        $rows = $query
            ->groupBy(['n_givn'])
            ->select(['n_givn', new Expression('COUNT(DISTINCT n_id) AS total')])
            // Deterministic source order so the count-sorted slice below breaks
            // equal-count ties the same way on every engine: PHP's sort is
            // stable, so first-seen token order (driven by this ORDER BY)
            // settles which equal-count tokens survive the limit.
            ->orderBy('n_givn')
            ->get();

        /** @var array<string, int> $givenNames */
        $givenNames = [];

        foreach ($rows as $row) {
            $count = RowCast::int($row, 'total');

            // Split "John Thomas" into "John" and "Thomas" and count each.
            foreach (explode(' ', RowCast::string($row, 'n_givn')) as $token) {
                // Exclude initials and particles.
                if (preg_match('/^([A-Z]|[a-z]{1,3})$/', $token) !== 1) {
                    $givenNames[$token] ??= 0;
                    $givenNames[$token] += $count;
                }
            }
        }

        return (new Collection($givenNames))
            ->sortDesc()
            ->slice(0, $limit)
            ->filter(static fn (int $count): bool => $count >= $threshold);
    }

    /**
     * Same-sex given-name passdown rate per child's birth century. Builds two
     * series on the same X axis: father → son and mother → daughter. For every
     * same-sex parent-child pair where both individuals carry an indexed
     * primary NAME record and the child carries a dated BIRT, the widget
     * collects every given-name token from each `n_givn` column (case-folded)
     * and counts the pair as a match when at least one token appears on both
     * sides. Order and position do not matter — a father named "Johann
     * Friedrich" matches a son named "Wilhelm Friedrich" because "Friedrich"
     * appears in both names. That mirrors the historical naming-tradition
     * pattern in German-speaking regions, where the leading token was often a
     * fixed baptismal name ("Johann", "Maria") while the actual everyday name
     * lived in a later token; a strict first-token comparison would
     * systematically miss the passdown signal it tries to measure.
     *
     * Per-century cohorts with fewer than {@see MIN_COHORT_SIZE} parent-child
     * pairs are suppressed independently per series (value 0 + "no data"
     * tooltip). A century survives on the X axis as long as at least one of the
     * two series passes the floor, so the union of the two cohorts defines the
     * visible span and one sparse series cannot hide the other.
     *
     * Two SQL passes (one per parent / child sex pair). The walk is symmetric
     * between the two: father / son uses HUSB + SEX=M, mother / daughter uses
     * WIFE + SEX=F. All token work runs in PHP so collation quirks in the
     * different storage engines do not skew the result.
     */
    public function sameSexNamePassdownByCentury(): LineChartPayload
    {
        $fatherSon      = $this->passdownPairsByCentury(Sex::Male->spouseColumn(), Sex::Male->value);
        $motherDaughter = $this->passdownPairsByCentury(Sex::Female->spouseColumn(), Sex::Female->value);

        $allCenturies = $fatherSon + $motherDaughter;

        if ($allCenturies === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        ksort($allCenturies);

        $categories            = [];
        $sonValues             = [];
        $sonTooltips           = [];
        $sonTooltipLabels      = [];
        $daughterValues        = [];
        $daughterTooltips      = [];
        $daughterTooltipLabels = [];

        foreach (array_keys($allCenturies) as $century) {
            $label     = CenturyName::for($century);
            $longLabel = CenturyName::longLabel($label);

            $categories[] = CenturyName::compactLabel($label);

            [$sonValues[], $sonTooltips[]]           = $this->seriesRow($fatherSon[$century] ?? null, 'son');
            $sonTooltipLabels[]                      = $longLabel;
            [$daughterValues[], $daughterTooltips[]] = $this->seriesRow($motherDaughter[$century] ?? null, 'daughter');
            $daughterTooltipLabels[]                 = $longLabel;
        }

        return new LineChartPayload(
            categories: $categories,
            series: [
                new LineChartSeries(
                    name: I18N::translate('Father → son'),
                    values: $sonValues,
                    tooltips: $sonTooltips,
                    tooltipLabels: $sonTooltipLabels,
                    class: 'male',
                ),
                new LineChartSeries(
                    name: I18N::translate('Mother → daughter'),
                    values: $daughterValues,
                    tooltips: $daughterTooltips,
                    tooltipLabels: $daughterTooltipLabels,
                    class: 'female',
                ),
            ],
        );
    }

    /**
     * Run the per-century passdown aggregation for a single parent / child sex
     * pair. `$parentColumn` is the `families` column holding the parent xref
     * (`f_husb` for fathers, `f_wife` for mothers); `$childSex` is the GEDCOM
     * SEX token the CHIL is filtered to (`M` for sons, `F` for daughters).
     *
     * The child's birth is taken from the deduplicated lower-bound
     * representative row per individual, so an imprecise `BET`/`FROM` birth — two
     * stored rows — places the pair in its lower-bound century once rather than
     * counting it twice and, on a century-straddling range, inventing a phantom
     * second-century slot.
     *
     * @return array<int, array{matches: int, total: int}>
     */
    private function passdownPairsByCentury(string $parentColumn, string $childSex): array
    {
        $treeId = $this->tree->id();

        $rows = TreeScope::table($this->tree, 'families', 'fam')
            ->join('link AS famc', static function (JoinClause $join): void {
                ChildLinkJoin::famc($join);
            })
            ->join('individuals AS child', static function (JoinClause $join) use ($childSex): void {
                $join
                    ->on('child.i_file', '=', 'famc.l_file')
                    ->on('child.i_id', '=', 'famc.l_from')
                    ->where('child.i_sex', '=', $childSex);
            })
            // The subquery is already tree-scoped, so matching the child xref
            // alone is sufficient — d_gid is unique within a single tree.
            ->joinSub(DedupedEventDates::query($this->tree, 'BIRT'), 'child_birth', static function (JoinClause $join): void {
                $join->on('child_birth.d_gid', '=', 'famc.l_from');
            })
            ->join('name AS parent_name', static function (JoinClause $join) use ($treeId, $parentColumn): void {
                $join
                    ->on('parent_name.n_file', '=', 'fam.f_file')
                    ->on('parent_name.n_id', '=', 'fam.' . $parentColumn)
                    ->where('parent_name.n_type', '=', 'NAME')
                    ->where('parent_name.n_file', '=', $treeId);
            })
            ->join('name AS child_name', static function (JoinClause $join) use ($treeId): void {
                $join
                    ->on('child_name.n_file', '=', 'famc.l_file')
                    ->on('child_name.n_id', '=', 'famc.l_from')
                    ->where('child_name.n_type', '=', 'NAME')
                    ->where('child_name.n_file', '=', $treeId);
            })
            ->whereNotNull('fam.' . $parentColumn)
            ->where('fam.' . $parentColumn, '<>', '')
            ->select([
                'child_birth.d_year AS birth_year',
                'parent_name.n_givn AS parent_givn',
                'child_name.n_givn AS child_givn',
            ])
            ->get();

        /** @var array<int, array{matches: int, total: int}> $byCentury */
        $byCentury = [];

        foreach ($rows as $row) {
            $year = RowCast::int($row, 'birth_year');

            if ($year <= 0) {
                continue;
            }

            $parentTokens = $this->givenNameTokens(RowCast::string($row, 'parent_givn'));
            $childTokens  = $this->givenNameTokens(RowCast::string($row, 'child_givn'));

            if ($parentTokens === []) {
                continue;
            }

            if ($childTokens === []) {
                continue;
            }

            $century = CenturyName::fromYear($year);
            $byCentury[$century] ??= ['matches' => 0, 'total' => 0];
            ++$byCentury[$century]['total'];

            if (array_intersect($parentTokens, $childTokens) !== []) {
                ++$byCentury[$century]['matches'];
            }
        }

        return $byCentury;
    }

    /**
     * Render one century's `{matches, total}` cell into the `(value, tooltip)`
     * pair the LineChart series consumes. A null counts argument means the
     * century did not collect any pair for this sex pairing, which also
     * produces the "no data" placeholder so the other series can still own the
     * X-axis slot.
     *
     * @param array{matches: int, total: int}|null $counts
     * @param string                               $kind   'son' or 'daughter' — picks the tooltip wording
     *
     * @return array{0: int|float, 1: string}
     */
    private function seriesRow(?array $counts, string $kind): array
    {
        if ($counts === null) {
            return [0, I18N::translate('no data (n < %s)', I18N::number(self::MIN_COHORT_SIZE))];
        }

        if ($counts['total'] < self::MIN_COHORT_SIZE) {
            return [0, I18N::translate('no data (n < %s)', I18N::number(self::MIN_COHORT_SIZE))];
        }

        $percentage = round(($counts['matches'] / $counts['total']) * 100, 1);

        $tooltip = ($kind === 'son')
            ? I18N::translate(
                '%1$s%% — %2$s of %3$s sons share at least one given name with the father',
                I18N::number($percentage, 1),
                I18N::number($counts['matches']),
                I18N::number($counts['total']),
            )
            : I18N::translate(
                '%1$s%% — %2$s of %3$s daughters share at least one given name with the mother',
                I18N::number($percentage, 1),
                I18N::number($counts['matches']),
                I18N::number($counts['total']),
            );

        return [$percentage, $tooltip];
    }

    /**
     * Split a `n_givn` column into the case-folded set of given-name tokens,
     * dropping empty pieces. Used to detect set-overlap between a father's and
     * a son's given names regardless of order or position. Slashes and other
     * GEDCOM markers do not appear in `n_givn` (those live on `n_surn` /
     * `n_full`), so a simple whitespace split is sufficient.
     *
     * @return list<string>
     */
    private function givenNameTokens(string $givn): array
    {
        $trimmed = trim($givn);

        if ($trimmed === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        return array_map(mb_strtolower(...), $tokens);
    }
}
