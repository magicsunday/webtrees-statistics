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
use MagicSunday\Webtrees\Statistic\Support\Aggregator\TopNAggregator;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\ChildLinkJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GivenNameNormalizer;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function count;
use function ksort;
use function round;
use function usort;

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
final class NameRepository
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
     * Per-sex memo of the tokenised given-name fold — the expensive `name`-table
     * scan plus per-token tokenise that both the distinct-count card and the
     * Top-N card consume. Keyed by the sex selector; the threshold and limit are
     * applied on top of a shared fold, so a tab that renders the count and the
     * Top-N for the same sex scans the rows once, not once per card (GH-154).
     *
     * @var array<string, array{counts: array<string, int>, rawByFold: array<string, array<string, int>>}>
     */
    private array $givenNameFoldCache = [];

    /**
     * @param Tree $tree The tree whose names to aggregate
     */
    public function __construct(
        private readonly Tree $tree,
    ) {
    }

    /**
     * Top-N surnames as `[{label, value}]`, ready for the chart widget. A
     * single grouped query selects the most frequent whitelisted surnames
     * (descending occurrence, `n_surn` as the boundary tie-break) above the
     * threshold; the result is then sorted alphabetically for a stable display
     * order. Counting inside the GROUP BY avoids the per-surname follow-up
     * query that core's `commonSurnames()` fires for every distinct surname.
     *
     * Note: unlike the sibling {@see topGivenNames()}, the boundary tie-break
     * here stays in SQL (`ORDER BY n_surn`), so it is collation-dependent rather
     * than the engine-independent PHP byte order. This divergence is deliberate
     * — the SQL `LIMIT` returns only the surviving rows instead of transferring
     * every distinct surname into PHP to re-rank — and is tracked in #149.
     *
     * @param int $limit     Maximum number of surnames to return
     * @param int $threshold Lower bound on the occurrences a surname must reach
     *
     * @return list<array{label: string, value: int}>
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
     * win the limited slots), with equal-count ties at the boundary broken by
     * the shared {@see TopNAggregator::rankKeys()} on the fold key in PHP byte
     * order (engine-independent, unlike a SQL collation tie-break); the returned
     * list is then sorted alphabetically for a stable display order.
     *
     * @param string $sex       GEDCOM sex token — a {@see Sex} case value or {@see SEX_ALL} for every sex
     * @param int    $threshold Lower bound on the occurrences a name must reach
     * @param int    $limit     Maximum number of names to return
     *
     * @return list<array{label: string, value: int}>
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
        // Count the folded keys that clear the threshold directly off the shared
        // fold — no Top-N rank/sort is needed for a cardinality, and the fold is
        // reused by the Top-N card for the same sex (GH-154).
        $counts = $this->foldGivenNames($sex)['counts'];

        // Every fold key has at least one bearer, so the default threshold of 1
        // admits all of them — skip the filter scan and closure-per-name on the
        // hot path (the dashboard always queries with threshold 1).
        if ($threshold <= 1) {
            return count($counts);
        }

        return count(
            array_filter($counts, static fn (int $count): bool => $count >= $threshold),
        );
    }

    /**
     * Sort a Top-N `[{label, value}]` list alphabetically by label for a stable
     * display order. Selection by occurrence already happened upstream; this is
     * the shared display sort for both the surname and given-name lists.
     *
     * @param array<int, array{label: string, value: int}> $entries
     *
     * @return list<array{label: string, value: int}>
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
     * forms. Each name is split into tokens and folded to a grouping key by
     * {@see GivenNameNormalizer} (mirroring webtrees core's particle/initial
     * filter, plus case + diacritics folding), and every individual is counted
     * once per fold key — so a person carrying the same name in several forms
     * (a primary NAME plus a ROMN/FONE transliteration that folds onto it) is
     * not double-counted. Core's `n_type <> '_MARNM'` blacklist is swapped for
     * the whitelist so arbitrary custom sub-tags never reach the tokeniser.
     *
     * Reads the shared per-sex fold ({@see foldGivenNames}) and applies only the
     * Top-N rank + threshold filter on top, so the count card and the Top-N card
     * for the same sex re-use a single `name`-table scan (GH-154).
     *
     * @param string $sex       GEDCOM sex token — a {@see Sex} case value or {@see SEX_ALL} for every sex
     * @param int    $threshold Lower bound on the individuals a folded name must reach
     * @param int    $limit     Maximum number of folded names to keep, by descending count
     *
     * @return Collection<string, int>
     */
    private function aggregateGivenNames(string $sex, int $threshold, int $limit): Collection
    {
        $fold = $this->foldGivenNames($sex);

        // Order by count descending, then by fold key ascending, before the
        // limit slice — the shared {@see TopNAggregator::rankKeys()} tie-break
        // (PHP byte order, independent of the database collation), so the
        // surviving Top-N is identical across database engines. The dominant raw
        // spelling of each surviving fold key becomes its display label. The
        // threshold filter runs after the slice, matching the prior order.
        $givenNames = TopNAggregator::rank(
            $fold['counts'],
            static fn (string $key): string => GivenNameNormalizer::dominantForm($fold['rawByFold'][$key] ?? []),
            $limit,
        );

        return (new Collection($givenNames))
            ->filter(static fn (int $count): bool => $count >= $threshold);
    }

    /**
     * The expensive half of the given-name aggregation: scan the whitelisted
     * `name` rows for one sex and fold each individual's tokens into a
     * `[foldKey => individual count]` map plus a `[foldKey => raw spelling
     * frequencies]` map for the display label. Memoised per sex selector so the
     * distinct-count card and the Top-N card for the same sex share one scan
     * instead of repeating it — the threshold and limit only shape the result
     * downstream, never the scan (GH-154).
     *
     * @param string $sex GEDCOM sex token — a {@see Sex} case value or {@see SEX_ALL} for every sex
     *
     * @return array{counts: array<string, int>, rawByFold: array<string, array<string, int>>}
     */
    private function foldGivenNames(string $sex): array
    {
        if (isset($this->givenNameFoldCache[$sex])) {
            return $this->givenNameFoldCache[$sex];
        }

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

        // Select the distinct (individual, name) pairs rather than a
        // GROUP BY n_givn + COUNT(DISTINCT n_id): folding happens per token in
        // PHP, and a person carrying the same name in several forms (a primary
        // NAME plus a ROMN/FONE transliteration that Latin-folds onto it) must
        // be counted ONCE per fold key, not once per name form. Ordering by
        // n_id groups an individual's rows adjacently, so the fold keys can be
        // de-duplicated per individual in a single pass — no O(individuals)
        // membership map (only the n_id ORDER is used; the limit-slice
        // tie-break is made engine-independent in PHP below).
        // cursor() streams a SINGLE query via a database cursor: one row is
        // hydrated in PHP at a time, so the PHP-object memory stays constant
        // (the driver may still buffer the raw rows client-side). The real win
        // is avoiding lazy()'s LIMIT/OFFSET pagination, which re-runs the query
        // per chunk and re-sorts the whole DISTINCT set on each deep offset
        // (O(N²) on a large tree). A single ordered query also makes adjacency
        // trivial — ORDER BY n_id groups each individual's rows contiguously for
        // the single-pass dedup below, with no chunk seam to split them. The
        // loop issues no further DB queries, so holding the cursor open is safe.
        $rows = $query
            ->select(['n_id', 'n_givn'])
            ->distinct()
            ->orderBy('n_id')
            ->cursor();

        /** @var array<string, int> $countsByKey */
        $countsByKey = [];

        /** @var array<string, array<string, int>> $rawByFold */
        $rawByFold = [];

        $currentXref = null;

        /** @var array<string, true> $currentKeys */
        $currentKeys = [];

        foreach ($rows as $row) {
            $xref = RowCast::string($row, 'n_id');

            // Adjacent rows belong to the same individual; on the boundary,
            // flush the previous individual's distinct fold keys (one count
            // each) and reset.
            if ($xref !== $currentXref) {
                foreach (array_keys($currentKeys) as $key) {
                    $countsByKey[$key] = ($countsByKey[$key] ?? 0) + 1;
                }

                $currentXref = $xref;
                $currentKeys = [];
            }

            // Split "John Thomas" into "John" / "Thomas" and fold each token's
            // spelling variants (diacritics / case) into one group, so
            // "José"/"Jose" count once. The dominant raw spelling becomes the
            // display label.
            foreach (GivenNameNormalizer::tokens(RowCast::string($row, 'n_givn')) as $token) {
                $key                     = GivenNameNormalizer::foldKey($token);
                $currentKeys[$key]       = true;
                $rawByFold[$key][$token] = ($rawByFold[$key][$token] ?? 0) + 1;
            }
        }

        // Flush the final individual.
        foreach (array_keys($currentKeys) as $key) {
            $countsByKey[$key] = ($countsByKey[$key] ?? 0) + 1;
        }

        return $this->givenNameFoldCache[$sex] = [
            'counts'    => $countsByKey,
            'rawByFold' => $rawByFold,
        ];
    }

    /**
     * Same-sex given-name passdown rate per child's birth century. Builds two
     * series on the same X axis: father → son and mother → daughter. For every
     * same-sex parent-child pair where both individuals carry an indexed
     * primary NAME record and the child carries a dated BIRT, the widget
     * collects every given-name token from each `n_givn` column (folded on case
     * and diacritics via {@see GivenNameNormalizer}, so a father "José" matches a
     * son "Jose") and counts the pair as a match when at least one token appears
     * on both sides. Order and position do not matter — a father named "Johann
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
        // Resolve every child's lower-bound birth year once, keyed by xref, and
        // look the century up in PHP. Joining DedupedEventDates as a secondary
        // derived table on the single key `d_gid = famc.l_from` made MariaDB
        // re-evaluate the whole two-level birth-dedup aggregation per probed
        // family row (split_materialized / LATERAL DERIVED), turning a ~60 ms
        // card into a multi-second one on a real tree. One materialised lookup
        // sidesteps the trap while preserving the lower-bound dedup semantics.
        $birthYears = $this->childBirthYearsByXref();

        $fatherSon      = $this->passdownPairsByCentury(Sex::Male->spouseColumn(), Sex::Male->value, $birthYears);
        $motherDaughter = $this->passdownPairsByCentury(Sex::Female->spouseColumn(), Sex::Female->value, $birthYears);

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
            $longLabel = CenturyName::longLabel($century);

            $categories[] = CenturyName::compactLabel($century);

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
     * The child's birth century is resolved from `$birthYears` — the
     * deduplicated lower-bound year per individual built by
     * {@see childBirthYearsByXref()} — so an imprecise `BET`/`FROM` birth — two
     * stored rows — places the pair in its lower-bound century once rather than
     * counting it twice and, on a century-straddling range, inventing a phantom
     * second-century slot. A child whose xref is absent from the map has no
     * Gregorian / Julian dated birth and is skipped, mirroring the inner join on
     * the birth subquery this method used to carry.
     *
     * @param string             $parentColumn `families` column holding the parent xref (`f_husb` / `f_wife`)
     * @param string             $childSex     GEDCOM SEX token the child is filtered to (`M` / `F`)
     * @param array<string, int> $birthYears   Child xref → lower-bound birth year (deduped, never 0)
     *
     * @return array<int, array{matches: int, total: int}>
     */
    private function passdownPairsByCentury(string $parentColumn, string $childSex, array $birthYears): array
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
            ->join('name AS parent_name', static function (JoinClause $join) use ($treeId, $parentColumn): void {
                $join
                    ->on('parent_name.n_file', '=', 'fam.f_file')
                    ->on('parent_name.n_id', '=', 'fam.' . $parentColumn)
                    ->where('parent_name.n_num', '=', 0)
                    ->where('parent_name.n_file', '=', $treeId);
            })
            ->join('name AS child_name', static function (JoinClause $join) use ($treeId): void {
                $join
                    ->on('child_name.n_file', '=', 'famc.l_file')
                    ->on('child_name.n_id', '=', 'famc.l_from')
                    ->where('child_name.n_num', '=', 0)
                    ->where('child_name.n_file', '=', $treeId);
            })
            ->whereNotNull('fam.' . $parentColumn)
            ->where('fam.' . $parentColumn, '<>', '')
            ->select([
                'famc.l_from AS child_xref',
                'parent_name.n_givn AS parent_givn',
                'child_name.n_givn AS child_givn',
            ])
            ->get();

        /** @var array<int, array{matches: int, total: int}> $byCentury */
        $byCentury = [];

        foreach ($rows as $row) {
            $childXref = RowCast::string($row, 'child_xref');

            // No deduped, dated birth for this child — drop the pair, exactly as
            // the former inner join on the birth subquery did. The map never
            // carries year 0 (DedupedEventDates filters `d_year <> 0`), and BCE
            // (negative) years fold into negative centuries via
            // CenturyName::fromYear().
            if (!isset($birthYears[$childXref])) {
                continue;
            }

            $year = $birthYears[$childXref];

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
     * Resolve every individual's deduplicated lower-bound birth year, keyed by
     * xref, in a single materialised query. {@see DedupedEventDates} collapses
     * webtrees' two-row range encoding to one representative row per individual
     * and restricts to dated births with a known year; {@see GregorianDate}
     * converts a non-Gregorian/Julian birth to its Gregorian year. The map never
     * carries year 0.
     *
     * Built once per {@see sameSexNamePassdownByCentury()} call and shared by
     * both sex pairings, this replaces a per-row derived-table join that the
     * MariaDB optimiser re-evaluated for every probed family row.
     *
     * @return array<string, int> Individual xref → lower-bound Gregorian birth year
     */
    private function childBirthYearsByXref(): array
    {
        $rows = DedupedEventDates::query($this->tree, 'BIRT')
            ->select(['d_gid', 'd_type', 'd_year', 'd_julianday1'])
            ->get();

        /** @var array<string, int> $birthYears */
        $birthYears = [];

        foreach ($rows as $row) {
            $birthYears[RowCast::string($row, 'd_gid')] = GregorianDate::year(
                RowCast::string($row, 'd_type'),
                RowCast::int($row, 'd_year'),
                RowCast::int($row, 'd_julianday1'),
            );
        }

        return $birthYears;
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
     * Reduce a `n_givn` column to the folded set of given-name keys, so a
     * father and child sharing a name match regardless of spelling drift
     * (diacritics / case): "José" and "Jose" fold to the same key. Built on the
     * shared {@see GivenNameNormalizer}, the keys also drop initials and
     * particles, and the unknown-name placeholder ({@see
     * Individual::PRAENOMEN_NESCIO}) collapses to an empty set — which the
     * passdown guards then drop so it neither dilutes a cohort nor falsely
     * matches another unknown name.
     *
     * @return list<string>
     */
    private function givenNameTokens(string $givn): array
    {
        return array_map(
            GivenNameNormalizer::foldKey(...),
            GivenNameNormalizer::tokens($givn),
        );
    }
}
