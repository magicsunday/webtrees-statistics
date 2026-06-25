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
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Enum\AgePairExtremum;
use MagicSunday\Webtrees\Statistic\Enum\MarriageEndReason;
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Model\Marriage\MarriageDurationExtreme;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyDurationDaysRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyDurationYearsRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualCountRecord;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventCenturyTally;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\IndividualAgeRecordResolver;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgeBuckets;
use MagicSunday\Webtrees\Statistic\Support\Calc\CalendarSpan;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_column;
use function array_fill_keys;
use function array_reverse;
use function array_slice;
use function count;
use function in_array;
use function intdiv;
use function is_numeric;
use function min;
use function strcmp;
use function usort;

/**
 * Marriage-related aggregations for the Family tab. Combines core's {@see
 * StatisticsData::statsMarrAgeQuery()} (age at marriage per sex) with local
 * queries for duration distribution and couple age-gap distribution.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MarriageRepository
{
    /**
     * Age-at-marriage histogram uses 5-year bands. Smaller than the lifespan
     * histogram because the relevant span (15–60) is also narrower.
     */
    private const int AGE_AT_MARRIAGE_BUCKET = 5;

    private const int AGE_AT_MARRIAGE_MAX = 60;

    /**
     * Marriage-duration histogram uses 10-year bands up to 60+.
     */
    private const int DURATION_BUCKET = 10;

    private const int DURATION_MAX = 60;

    /**
     * Couple-age-gap histogram bucket width in years. The absolute birth-year
     * gap is binned into the seven shared magnitude bands below, with the side
     * (husband-older / wife-older) decided by the sign of the day difference.
     */
    private const int AGE_GAP_BUCKET = 5;

    /**
     * The seven shared age-gap magnitude bands, both sides read against these.
     *
     * @var list<string>
     */
    private const array AGE_GAP_BANDS = ['0–4', '5–9', '10–14', '15–19', '20–24', '25–29', '30+'];

    /**
     * Widowhood / widower interval histogram is 5-year wide and runs up to a
     * 50+ overflow band. 50 years matches the lifespan ceiling beyond which the
     * survivor's own death is most likely a stale data import rather than a
     * real long widowhood; the overflow band still anchors the long tail.
     */
    private const int WIDOWHOOD_BUCKET = 5;

    private const int WIDOWHOOD_MAX = 50;

    /**
     * Ordered month bands for the remarriage interval after widowhood. The
     * first mourning year is split into two halves (`<6`, `6–11`) — the window
     * where social convention bites hardest — then whole years up to five, with
     * a `60+` long tail. Months, not years, are the meaningful granularity: a
     * remarriage within six months reads very differently from one after the
     * traditional year of mourning.
     */
    private const array REMARRIAGE_INTERVAL_BANDS = ['<6', '6–11', '12–23', '24–35', '36–47', '48–59', '60+'];

    /**
     * Plausibility band for the spouse-at-marriage and longest-marriage
     * records. Anything below this minimum is most likely a data-entry error
     * (BIRT and MARR swapped); anything above is either a stepparent
     * relationship or a stale BIRT. The two records reject candidates outside
     * the band so neither extreme is captured by a single bad row.
     */
    private const int MIN_PLAUSIBLE_SPOUSE_AGE = 10;

    private const int MAX_PLAUSIBLE_SPOUSE_AGE = 90;

    /**
     * Per-instance cache for the marriage-duration row plucks.
     * `longestMarriageRecord` + `shortestMarriageRecord` +
     * `durationDistribution` all walk the same rows; sharing the materialised
     * list collapses three SELECTs into one.
     *
     * @var array<int, array{marrJd: int, endJd: int, xref: string, endReason: MarriageEndReason}>|null
     */
    private ?array $marriageDurationPairsCache = null;

    /**
     * Per-instance cache for `spouseAgeAtMarriage`, keyed by the sex argument.
     * Both the youngest- and oldest-spouse records walk the same rows for a
     * given sex; sharing the materialised list halves the SELECTs the Overview
     * tab triggers.
     *
     * @var array<string, array<int, array{years: int, xref: string}>>
     */
    private array $spouseAgeAtMarriageCache = [];

    /**
     * @param Tree           $tree The tree the statistics are computed for
     * @param StatisticsData $data Core accessor that exposes statsMarrAgeQuery + countEventsByCentury
     */
    public function __construct(
        private readonly Tree $tree,
        private readonly StatisticsData $data,
    ) {
    }

    /**
     * Age-at-marriage distribution for one sex, bucketed into 5-year bands plus
     * a 60+ overflow.
     *
     * @param string $sex 'M' for husbands, 'F' for wives
     *
     * @return array<string, int>
     */
    public function ageAtMarriageDistribution(string $sex): array
    {
        $spouseColumn = Sex::from($sex)->spouseColumn();

        // Ranged MARR / spouse BIRT dates produce two `dates` rows per anchor,
        // so a FAM with a ranged MARR or ranged spouse BIRT would surface once
        // per range bound. Grouping by `families.f_id` and collapsing the MARR
        // onto its upper-bound julian day (MAX(d_julianday2), the maximum-
        // possible age idiom core's statsMarrAgeQuery used) and the BIRT onto
        // its lower-bound julian day (MIN(d_julianday1)) yields one age per
        // family.
        $rows = TreeScope::table($this->tree, 'families')
            ->join('dates AS married', static function (JoinClause $join): void {
                DateJoin::on($join, 'married', 'f_file', 'f_id', 'MARR');
            })
            ->join('dates AS birth', static function (JoinClause $join) use ($spouseColumn): void {
                DateJoin::on($join, 'birth', 'f_file', $spouseColumn, 'BIRT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->select([
                DateAggregate::max('married', 'd_julianday2', 'marr_jd'),
                DateAggregate::min('birth', 'd_julianday1', 'birth_jd'),
            ])
            ->groupBy('families.f_id')
            ->get();

        $buckets = AgeBuckets::init(0, self::AGE_AT_MARRIAGE_MAX, self::AGE_AT_MARRIAGE_BUCKET);

        foreach ($rows as $row) {
            $marrJd  = RowCast::int($row, 'marr_jd');
            $birthJd = RowCast::int($row, 'birth_jd');

            if ($marrJd <= $birthJd) {
                continue;
            }

            $years = CalendarSpan::wholeYears($birthJd, $marrJd);
            $label = AgeBuckets::label($years, self::AGE_AT_MARRIAGE_MAX, self::AGE_AT_MARRIAGE_BUCKET);

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Marriage-duration distribution: years between MARR and the earlier of the
     * two spouses' DEAT (or DIV). Bucketed into 10-year bands up to 60+.
     *
     * @return array<string, int>
     */
    public function durationDistribution(): array
    {
        $buckets = AgeBuckets::init(0, self::DURATION_MAX, self::DURATION_BUCKET);

        foreach ($this->marriageDurationPairs() as $pair) {
            $years           = CalendarSpan::wholeYears($pair['marrJd'], $pair['endJd']);
            $label           = AgeBuckets::label($years, self::DURATION_MAX, self::DURATION_BUCKET);
            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Single longest-marriage record holder: the family with the biggest (end
     * julian-day − MARR julian-day) delta, where the end is whichever happened
     * first — DIV, husband's DEAT or wife's DEAT. Returns null when no family
     * has both a MARR date AND a determinable end date. Capped at 90 years.
     */
    public function longestMarriageRecord(): ?FamilyDurationYearsRecord
    {
        $bestYears = 0;
        $bestXref  = null;

        foreach ($this->marriageDurationPairs() as $pair) {
            $years = CalendarSpan::wholeYears($pair['marrJd'], $pair['endJd']);

            // Seed on the first pair ($bestXref === null), then strict-greater,
            // with a byte-order (strcmp) tie-break on the smaller xref so an
            // equal-duration tie picks a stable, engine-independent holder (the
            // pairs query carries no ORDER BY; strcmp, not `<`, so numeric-
            // looking xrefs compare byte-wise). The seed mirrors
            // shortestMarriageRecord and makes the pick independent of the
            // $bestYears start value; the $bestYears <= 0 cap below still drops
            // a zero-year-only result.
            if (($bestXref === null) || ($years > $bestYears) || (($years === $bestYears) && (strcmp($pair['xref'], $bestXref) < 0))) {
                $bestYears = $years;
                $bestXref  = $pair['xref'];
            }
        }

        if (($bestXref === null) || ($bestYears <= 0) || ($bestYears > self::MAX_PLAUSIBLE_SPOUSE_AGE)) {
            return null;
        }

        $family = Registry::familyFactory()->make($bestXref, $this->tree);

        if (!$family instanceof Family) {
            return null;
        }

        return new FamilyDurationYearsRecord(family: $family, durationYears: $bestYears);
    }

    /**
     * Shortest recorded marriage: smallest positive `(end julian-day − MARR
     * julian-day)` delta. A divorce one day after the wedding wins; a same-day
     * end is excluded because the underlying iterator only accepts a
     * terminating event strictly after the marriage. Returns the duration in
     * days rather than years so a one-week or one-month "fastest split" stays
     * meaningful.
     */
    public function shortestMarriageRecord(): ?FamilyDurationDaysRecord
    {
        $bestDays = null;
        $bestXref = null;

        foreach ($this->marriageDurationPairs() as $pair) {
            $days = $pair['endJd'] - $pair['marrJd'];

            // Strict-less, with a byte-order (strcmp) tie-break on the smaller
            // xref so an equal-duration tie picks a stable, engine-independent
            // holder. No explicit null-guard on $bestXref: reaching the tie
            // operand means the `$bestDays === null` seed already fired, which
            // sets $bestDays and $bestXref together, so $bestXref is a string
            // here.
            if (($bestDays === null) || ($days < $bestDays) || (($days === $bestDays) && (strcmp($pair['xref'], $bestXref) < 0))) {
                $bestDays = $days;
                $bestXref = $pair['xref'];
            }
        }

        if (($bestXref === null) || ($bestDays <= 0)) {
            return null;
        }

        $family = Registry::familyFactory()->make($bestXref, $this->tree);

        if (!$family instanceof Family) {
            return null;
        }

        return new FamilyDurationDaysRecord(family: $family, durationDays: $bestDays);
    }

    /**
     * Top-N shortest and longest marriages, each carrying how it ended. Walks
     * the same duration pairs as the single-record holders, ranks them by day
     * delta, and returns the `$topN` shortest (ascending) and `$topN` longest
     * (descending). Spans beyond {@see MAX_PLAUSIBLE_SPOUSE_AGE} years are
     * dropped from both lists, matching {@see longestMarriageRecord()}. Only the
     * families that actually make a list are resolved to a label, through the
     * factory so {@see Family::fullName()} applies webtrees' own privacy
     * filtering.
     *
     * @param int $topN Number of marriages to return in each list
     *
     * @return array{shortest: list<MarriageDurationExtreme>, longest: list<MarriageDurationExtreme>}
     */
    public function getMarriageDurationExtremes(int $topN = 3): array
    {
        if ($topN < 1) {
            return ['shortest' => [], 'longest' => []];
        }

        $candidates = [];

        foreach ($this->marriageDurationPairs() as $pair) {
            $days  = $pair['endJd'] - $pair['marrJd'];
            $years = CalendarSpan::wholeYears($pair['marrJd'], $pair['endJd']);

            if ($years > self::MAX_PLAUSIBLE_SPOUSE_AGE) {
                continue;
            }

            $candidates[] = [
                'xref'      => $pair['xref'],
                'days'      => $days,
                'years'     => $years,
                'endReason' => $pair['endReason'],
            ];
        }

        // Rank by day delta once, with a stable XREF tiebreak so equal-duration
        // marriages keep a reproducible order at the list cutoff. The label is
        // resolved only for the rows that actually make a list, keeping the
        // factory cost bounded by 2 * $topN.
        usort(
            $candidates,
            static function (array $a, array $b): int {
                $byDays = $a['days'] <=> $b['days'];

                if ($byDays !== 0) {
                    return $byDays;
                }

                return strcmp($a['xref'], $b['xref']);
            }
        );

        $shortest      = array_slice($candidates, 0, $topN);
        $shortestXrefs = array_column($shortest, 'xref');

        // Build the longest list from the far end, skipping any marriage already
        // shown as a shortest one — so a tree with fewer than 2 * $topN datable
        // marriages never lists the same couple in both columns.
        $longest = [];

        foreach (array_reverse($candidates) as $candidate) {
            if (count($longest) >= $topN) {
                break;
            }

            if (in_array($candidate['xref'], $shortestXrefs, true)) {
                continue;
            }

            $longest[] = $candidate;
        }

        return [
            'shortest' => $this->toMarriageExtremes($shortest),
            'longest'  => $this->toMarriageExtremes($longest),
        ];
    }

    /**
     * Resolve each ranked duration row to a {@see MarriageDurationExtreme},
     * dropping rows whose family no longer resolves.
     *
     * @param list<array{xref: string, days: int, years: int, endReason: MarriageEndReason}> $rows
     *
     * @return list<MarriageDurationExtreme>
     */
    private function toMarriageExtremes(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $family = Registry::familyFactory()->make($row['xref'], $this->tree);

            if (!$family instanceof Family) {
                continue;
            }

            // Ranked metric and label follow the module's raw-rank convention
            // (see GenerationDepthRepository::topAncestorsByDescendantCount):
            // the podium row stays for every ranked marriage, the duration is
            // always rendered, and fullName() applies webtrees' own name
            // privacy to the couple label. The end-cause is an extra sensitive
            // attribute on top of the ranked metric, so it alone is gated on
            // the family record's visibility — suppressed to null when the
            // current user cannot see the record (AGENTS.md, Privacy).
            $out[] = new MarriageDurationExtreme(
                familyXref: $row['xref'],
                label: RecordName::plain($family->fullName()),
                durationDays: $row['days'],
                durationYears: $row['years'],
                endReason: $family->canShow() ? $row['endReason'] : null,
            );
        }

        return $out;
    }

    /**
     * Youngest spouse at marriage: smallest positive (MARR julian- day −
     * spouse-BIRT julian-day) across the tree. Restricted to one sex per call
     * ('M' = husband, 'F' = wife). Implausible young ages (below {@see
     * self::MIN_PLAUSIBLE_SPOUSE_AGE}) are dropped to filter out data-entry
     * errors where BIRT and MARR were swapped.
     *
     * @param string $sex 'M' for husbands, 'F' for wives
     */
    public function youngestSpouseAtMarriageRecord(string $sex): ?IndividualAgeRecord
    {
        return $this->spouseAtMarriageRecord($sex, AgePairExtremum::Lowest);
    }

    /**
     * Oldest spouse at marriage: mirror of {@see
     * youngestSpouseAtMarriageRecord()}.
     *
     * @param string $sex 'M' for husbands, 'F' for wives
     */
    public function oldestSpouseAtMarriageRecord(string $sex): ?IndividualAgeRecord
    {
        return $this->spouseAtMarriageRecord($sex, AgePairExtremum::Highest);
    }

    /**
     * Shared min / max walk over the spouse-age iterator with the plausibility
     * band applied before the comparison. The caller picks the direction via
     * {@see AgePairExtremum}.
     */
    private function spouseAtMarriageRecord(string $sex, AgePairExtremum $direction): ?IndividualAgeRecord
    {
        $plausible = (function () use ($sex): iterable {
            foreach ($this->spouseAgeAtMarriage($sex) as $entry) {
                if ($entry['years'] < self::MIN_PLAUSIBLE_SPOUSE_AGE) {
                    continue;
                }

                if ($entry['years'] > self::MAX_PLAUSIBLE_SPOUSE_AGE) {
                    continue;
                }

                yield $entry;
            }
        })();

        $best = $direction->pick($plausible);

        return IndividualAgeRecordResolver::resolve(
            $this->tree,
            $best['xref'] ?? null,
            $best['years'] ?? null,
        );
    }

    /**
     * Most marriages per individual: the person who participated in the largest
     * number of FAM records (as husband or wife). Recorded via the `link` table
     * — one FAMS link per marriage.
     */
    public function mostSpousesRecord(): ?IndividualCountRecord
    {
        $row = TreeScope::table($this->tree, 'link')
            ->where('l_type', '=', 'FAMS')
            ->groupBy('l_from')
            ->orderByRaw('COUNT(*) DESC')
            // Deterministic tie-break: on an equal marriage count keep the
            // smaller xref so the single record holder is stable across runs
            // and engines, not row-order-dependent.
            ->orderBy('l_from')
            ->select(['l_from AS xref', new Expression('COUNT(*) AS marriage_count')])
            ->first();

        if ($row === null) {
            return null;
        }

        $xref  = RowCast::string($row, 'xref');
        $count = RowCast::int($row, 'marriage_count');

        if (($xref === '') || ($count < 2)) {
            return null;
        }

        $individual = Registry::individualFactory()->make($xref, $this->tree);

        if (!$individual instanceof Individual) {
            return null;
        }

        return new IndividualCountRecord(individual: $individual, count: $count);
    }

    /**
     * Per-spouse age at marriage, one row per family, carrying the husband or
     * wife xref so the youngest / oldest-spouse records can resolve the
     * individual. Ranged dates are collapsed onto their lower-bound julian day
     * (MIN(d_julianday1)) so a single spouse never surfaces twice. This is
     * deliberately the lower bound, unlike {@see ageAtMarriageDistribution()}
     * which collapses the marriage onto its upper bound (MAX(d_julianday2), the
     * maximum-possible-age idiom): the record path reports the conservative
     * extreme, so the two must not be merged. Result is memoised per `$sex` so
     * the Overview tab's young/old extreme pair shares a single SELECT per sex.
     *
     * @param string $sex 'M' or 'F'
     *
     * @return array<int, array{years: int, xref: string}>
     */
    private function spouseAgeAtMarriage(string $sex): array
    {
        if (isset($this->spouseAgeAtMarriageCache[$sex])) {
            return $this->spouseAgeAtMarriageCache[$sex];
        }

        $spouseColumn = Sex::from($sex)->spouseColumn();

        $rows = TreeScope::table($this->tree, 'families', 'fam')
            ->join('dates AS marr', static function (JoinClause $join): void {
                DateJoin::on($join, 'marr', 'fam.f_file', 'fam.f_id', 'MARR', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->join('dates AS birth', static function (JoinClause $join) use ($spouseColumn): void {
                DateJoin::on($join, 'birth', 'fam.f_file', 'fam.' . $spouseColumn, 'BIRT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            // Ranged MARR / spouse BIRT dates produce two `dates` rows per
            // anchor, so a FAM with a ranged date would surface once per range
            // bound and invent a phantom extreme. Group by the family and
            // collapse both anchors onto their lower-bound julian day
            // (MIN(d_julianday1)) so each spouse contributes one age.
            ->groupBy('fam.f_id', 'fam.' . $spouseColumn)
            ->select([
                'fam.' . $spouseColumn . ' AS xref',
                DateAggregate::min('marr', 'd_julianday1', 'marr_jd'),
                DateAggregate::min('birth', 'd_julianday1', 'birth_jd'),
            ])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $marrJd  = RowCast::int($row, 'marr_jd');
            $birthJd = RowCast::int($row, 'birth_jd');
            $xref    = RowCast::string($row, 'xref');

            if ($xref === '') {
                continue;
            }

            if ($marrJd <= 0) {
                continue;
            }

            if ($birthJd <= 0) {
                continue;
            }

            if ($marrJd <= $birthJd) {
                continue;
            }

            $out[] = ['years' => CalendarSpan::wholeYears($birthJd, $marrJd), 'xref' => $xref];
        }

        $this->spouseAgeAtMarriageCache[$sex] = $out;

        return $out;
    }

    /**
     * Every family with both a parseable MARR date AND a determinable
     * marriage-end julian-day, returned as a `{marrJd, endJd, xref, endReason}`
     * row. Callers turn it into years (durationDistribution,
     * longestMarriageRecord) or days (shortestMarriageRecord). The end
     * julian-day is the earliest of DIV / husband-DEAT / wife-DEAT, so the row
     * that survives is the one webtrees considers the marriage's true terminus;
     * `endReason` names that same event (`divorce` only when the DIV day is the
     * earliest). Memoised per instance — the callers used to trigger identical
     * SELECTs.
     *
     * @return array<int, array{marrJd: int, endJd: int, xref: string, endReason: MarriageEndReason}>
     */
    private function marriageDurationPairs(): array
    {
        if ($this->marriageDurationPairsCache !== null) {
            return $this->marriageDurationPairsCache;
        }

        // Ranged MARR / DIV / DEAT dates (BET..AND / FROM..TO) each
        // produce two `dates` rows, so the four-way JOIN can return
        // up to 2^4 = 16 rows per FAM if every anchor is ranged.
        // Grouping by `f_id` and aggregating each anchor with
        // `MIN(d_julianday1)` collapses the duplicates onto the
        // lower-bound julian day — MARR at its earliest possible
        // start, the marriage-ending events (DIV / husband DEAT /
        // wife DEAT) at their earliest possible occurrence so
        // {@see earliestAfter()} downstream still picks the
        // first terminating event after the wedding.
        //
        // `families.f_id AS xref` carries the full alias because
        // strict `ONLY_FULL_GROUP_BY` MySQL parsers (e.g. issue #46
        // on Strato) demand the GROUP BY column and the SELECT
        // reference name the same qualified identifier; an
        // unprefixed `f_id` would be rejected even though both
        // resolve to the same primary-key column.
        $rows = TreeScope::table($this->tree, 'families')
            ->join('dates AS marr', static function (JoinClause $join): void {
                DateJoin::on($join, 'marr', 'f_file', 'f_id', 'MARR');
            })
            ->leftJoin('dates AS divr', static function (JoinClause $join): void {
                DateJoin::on($join, 'divr', 'f_file', 'f_id', 'DIV');
            })
            ->leftJoin('dates AS husb_d', static function (JoinClause $join): void {
                DateJoin::on($join, 'husb_d', 'f_file', 'f_husb', 'DEAT');
            })
            ->leftJoin('dates AS wife_d', static function (JoinClause $join): void {
                DateJoin::on($join, 'wife_d', 'f_file', 'f_wife', 'DEAT');
            })
            ->select([
                'families.f_id AS xref',
                DateAggregate::min('marr', 'd_julianday1', 'marr_jd'),
                DateAggregate::min('divr', 'd_julianday1', 'div_jd'),
                DateAggregate::min('husb_d', 'd_julianday1', 'husb_jd'),
                DateAggregate::min('wife_d', 'd_julianday1', 'wife_jd'),
            ])
            ->groupBy('families.f_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $marrJd = RowCast::int($row, 'marr_jd');
            $xref   = RowCast::string($row, 'xref');

            if ($marrJd <= 0) {
                continue;
            }

            if ($xref === '') {
                continue;
            }

            // The marriage's end is the earliest terminating event recorded
            // after the wedding. A divorce or death dated on or before the
            // marriage cannot end it — a data-entry inversion or an event
            // mis-attached to the family — so such a date is ignored rather
            // than letting it drop the whole (otherwise datable) marriage.
            $endJd = $this->earliestAfter($marrJd, [
                $row->div_jd ?? null,
                $row->husb_jd ?? null,
                $row->wife_jd ?? null,
            ]);

            if ($endJd === null) {
                continue;
            }

            // The marriage ended at the earliest terminating event. Label the
            // reason after that same event: a divorce wins only when the DIV
            // julian-day is the earliest one (so a spouse who died before a
            // recorded divorce still classifies as ended by death).
            $divJd     = RowCast::int($row, 'div_jd');
            $endReason = (($divJd > 0) && ($divJd === $endJd)) ? MarriageEndReason::Divorce : MarriageEndReason::Death;

            $out[] = ['marrJd' => $marrJd, 'endJd' => $endJd, 'xref' => $xref, 'endReason' => $endReason];
        }

        $this->marriageDurationPairsCache = $out;

        return $out;
    }

    /**
     * Couple age-gap distribution as a two-sided bucket histogram. The absolute
     * birth-year gap is binned into seven shared 5-year magnitude bands; each
     * band carries the count of couples where the husband is the older partner
     * (`left`) and where the wife is (`right`), so the two sides read against
     * the same band labels.
     *
     * @return array<string, array{left: int, right: int}> Band label → `{left: husband-older, right: wife-older}` counts
     */
    public function ageGapDistribution(): array
    {
        // Webtrees writes TWO rows into the `dates` table for every
        // BET..AND / FROM..TO date — one per range bound — so a JOIN
        // without grouping would surface the same FAM twice per
        // ranged BIRT. Grouping by `f_id` and aggregating each
        // spouse's BIRT with `MIN(d_julianday1)` collapses ranged
        // rows into the lower-bound julian day, yielding one entry
        // per family.
        $rows = TreeScope::table($this->tree, 'families')
            ->join('dates AS hb', static function (JoinClause $join): void {
                DateJoin::on($join, 'hb', 'f_file', 'f_husb', 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO);
            })
            ->join('dates AS wb', static function (JoinClause $join): void {
                DateJoin::on($join, 'wb', 'f_file', 'f_wife', 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO);
            })
            ->select([
                DateAggregate::min('hb', 'd_julianday1', 'hb_jd'),
                DateAggregate::min('wb', 'd_julianday1', 'wb_jd'),
            ])
            ->groupBy('families.f_id')
            ->get();

        $bands = self::AGE_GAP_BANDS;
        $dist  = [];

        foreach ($bands as $band) {
            $dist[$band] = ['left' => 0, 'right' => 0];
        }

        foreach ($rows as $row) {
            $hbJd = RowCast::int($row, 'hb_jd');
            $wbJd = RowCast::int($row, 'wb_jd');

            if ($hbJd <= 0) {
                continue;
            }

            if ($wbJd <= 0) {
                continue;
            }

            // Husband born first (smaller julian day) → husband older; the sign
            // of the raw day difference settles couples less than a year apart,
            // and an exact same-day tie is neither partner older and drops out.
            $diff = $hbJd - $wbJd;

            if ($diff === 0) {
                continue;
            }

            $years = CalendarSpan::wholeYears($hbJd, $wbJd);
            $rank  = max(0, min(count($bands) - 1, intdiv($years, self::AGE_GAP_BUCKET)));
            $band  = $bands[$rank];

            if ($diff < 0) {
                ++$dist[$band]['left'];
            } else {
                ++$dist[$band]['right'];
            }
        }

        return $dist;
    }

    /**
     * Widowhood / widower-interval histogram: for every FAM where both spouses
     * carry a recorded DEAT date, computes the number of full years the
     * survivor outlived the first-deceased partner and groups the results into
     * 5-year bands up to a 50+ overflow. Couples where both spouses died on the
     * same julian day land in the 0–4 band; rows where neither spouse outlived
     * the other (impossible by construction once both DEAT exist) are still
     * tolerated and skipped silently.
     *
     * The repository keeps the result in `[bucketLabel => count]` shape so the
     * consumer mirrors the existing duration / age-gap histograms instead of
     * carrying a new payload contract.
     *
     * @return array<string, int>
     */
    public function widowhoodYearsDistribution(): array
    {
        // Ranged DEAT dates (BET..AND / FROM..TO) produce two `dates`
        // rows per spouse — without grouping by `f_id` a single
        // FAM with one ranged DEAT would surface twice with different
        // widowhood values. Aggregating each DEAT with the upper-
        // bound `MAX(d_julianday2)` matches the webtrees-core
        // "maximum-possible-lifespan" idiom and keeps the histogram
        // bucket at one entry per family.
        $rows = TreeScope::table($this->tree, 'families')
            ->join('dates AS husb_d', static function (JoinClause $join): void {
                DateJoin::on($join, 'husb_d', 'f_file', 'f_husb', 'DEAT', DateJoin::JD_NOT_EQUAL_ZERO);
            })
            ->join('dates AS wife_d', static function (JoinClause $join): void {
                DateJoin::on($join, 'wife_d', 'f_file', 'f_wife', 'DEAT', DateJoin::JD_NOT_EQUAL_ZERO);
            })
            ->select([
                DateAggregate::max('husb_d', 'd_julianday2', 'husb_jd'),
                DateAggregate::max('wife_d', 'd_julianday2', 'wife_jd'),
            ])
            ->groupBy('families.f_id')
            ->get();

        $buckets = AgeBuckets::init(0, self::WIDOWHOOD_MAX, self::WIDOWHOOD_BUCKET);

        foreach ($rows as $row) {
            $husbJd = RowCast::int($row, 'husb_jd');
            $wifeJd = RowCast::int($row, 'wife_jd');

            if ($husbJd <= 0) {
                continue;
            }

            if ($wifeJd <= 0) {
                continue;
            }

            // The span is read as widowhood length regardless of which spouse
            // outlived the other — the histogram is about the gap between the
            // two deaths, not the sex of the survivor — so the endpoint order
            // is immaterial.
            $years = CalendarSpan::wholeYears($husbJd, $wifeJd);

            $label           = AgeBuckets::label($years, self::WIDOWHOOD_MAX, self::WIDOWHOOD_BUCKET);
            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Months between a deceased spouse's death and the survivor's next marriage,
     * mapped onto the {@see REMARRIAGE_INTERVAL_BANDS} month bands — the
     * {@see widowhoodYearsDistribution()} cousin that asks how soon, not how
     * long. A person's marriages are ordered by date; for each marriage after
     * the first, when the previous spouse had already died the gap is bucketed.
     * Unions that ended in divorce (the previous spouse is still alive at the
     * next marriage) and widows who never remarried contribute nothing.
     *
     * @return array<string, int>
     */
    public function remarriageIntervalDistribution(): array
    {
        $marriageRows = TreeScope::table($this->tree, 'families')
            ->join('dates AS marr', static function (JoinClause $join): void {
                DateJoin::on($join, 'marr', 'families.f_file', 'families.f_id', 'MARR', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->select([
                'families.f_husb AS husb',
                'families.f_wife AS wife',
                DateAggregate::max('marr', 'd_julianday2', 'marr_jd'),
            ])
            // `f_husb` / `f_wife` are non-aggregated, so they must join `f_id`
            // in the GROUP BY: MySQL / MariaDB reject a bare selected column
            // under `ONLY_FULL_GROUP_BY`. Both are functionally determined by
            // the unique `f_id`, so the grouping unit stays one row per family.
            ->groupBy('families.f_id', 'families.f_husb', 'families.f_wife')
            ->get();

        $deathByXref = [];

        $deathRows = TreeScope::table($this->tree, 'individuals')
            ->join('dates AS deat', static function (JoinClause $join): void {
                DateJoin::on($join, 'deat', 'individuals.i_file', 'individuals.i_id', 'DEAT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->select([
                'individuals.i_id AS xref',
                DateAggregate::max('deat', 'd_julianday2', 'deat_jd'),
            ])
            ->groupBy('individuals.i_id')
            ->get();

        foreach ($deathRows as $row) {
            $deathByXref[RowCast::string($row, 'xref')] = RowCast::int($row, 'deat_jd');
        }

        // Group each person's marriages so consecutive ones can be paired.
        $marriagesByPerson = [];

        foreach ($marriageRows as $row) {
            $marriageJd = RowCast::int($row, 'marr_jd');

            if ($marriageJd <= 0) {
                continue;
            }

            $husband = RowCast::string($row, 'husb');
            $wife    = RowCast::string($row, 'wife');

            if ($husband === '') {
                continue;
            }

            if ($wife === '') {
                continue;
            }

            $marriagesByPerson[$husband][] = ['jd' => $marriageJd, 'spouse' => $wife];
            $marriagesByPerson[$wife][]    = ['jd' => $marriageJd, 'spouse' => $husband];
        }

        $buckets = array_fill_keys(self::REMARRIAGE_INTERVAL_BANDS, 0);

        foreach ($marriagesByPerson as $marriages) {
            if (count($marriages) < 2) {
                continue;
            }

            usort($marriages, static fn (array $a, array $b): int => $a['jd'] <=> $b['jd']);

            for ($i = 1, $count = count($marriages); $i < $count; ++$i) {
                $previousMarriage    = $marriages[$i - 1] ?? ['jd' => 1, 'spouse' => '?'];
                $currentMarriage     = $marriages[$i] ?? ['jd' => 1, 'spouse' => '?'];
                $previousSpouseDeath = $deathByXref[$previousMarriage['spouse']] ?? 0;

                // Skip unless the previous spouse died on or before this
                // marriage — a still-living previous spouse means the earlier
                // union ended in divorce, not widowhood.
                if ($previousSpouseDeath <= 0) {
                    continue;
                }

                if ($previousSpouseDeath > $currentMarriage['jd']) {
                    continue;
                }

                $months = CalendarSpan::wholeMonths($previousSpouseDeath, $currentMarriage['jd']);

                $band           = $this->remarriageIntervalBand($months);
                $buckets[$band] = ($buckets[$band] ?? 0) + 1;
            }
        }

        return $buckets;
    }

    /**
     * Map a whole-month remarriage interval onto its
     * {@see REMARRIAGE_INTERVAL_BANDS} band: the first year split at six months,
     * then yearly up to five years, then a 60+ tail.
     */
    private function remarriageIntervalBand(int $months): string
    {
        return match (true) {
            $months < 6  => '<6',
            $months < 12 => '6–11',
            $months < 24 => '12–23',
            $months < 36 => '24–35',
            $months < 48 => '36–47',
            $months < 60 => '48–59',
            default      => '60+',
        };
    }

    /**
     * Weddings grouped by GEDCOM month abbreviation, leaning on core's
     * already-public {@see StatisticsData::countFirstMarriagesByMonth()}. Core
     * hands back the `{JAN: int, FEB: int, …}` map directly, so the repository
     * is just a thin pass-through.
     *
     * @return array<string, int>
     */
    public function weddingsByMonth(): array
    {
        return $this->data->countFirstMarriagesByMonth($this->tree, 0, 0);
    }

    /**
     * Weddings grouped by century, keyed by the signed 1-based century number
     * (negative for BCE). Output shape is `[century => count]`. Counts each
     * family once even when its MARR is a range date (which webtrees stores as
     * two `dates` rows).
     *
     * @return array<int, int>
     */
    public function weddingsByCentury(): array
    {
        return EventCenturyTally::countByCentury($this->tree, 'MARR');
    }

    /**
     * Earliest Julian-day number strictly greater than $floor from a list of
     * nullable candidates, or null when none qualify. A $floor of 0 selects the
     * earliest positive value; the marriage julian-day selects the earliest
     * terminating event after the wedding.
     *
     * @param int         $floor      Exclusive lower bound the candidate must exceed
     * @param list<mixed> $candidates Nullable, possibly non-numeric julian-day candidates
     */
    private function earliestAfter(int $floor, array $candidates): ?int
    {
        $earliest = null;

        foreach ($candidates as $candidate) {
            if (!is_numeric($candidate)) {
                continue;
            }

            $value = (int) $candidate;

            if ($value <= $floor) {
                continue;
            }

            if (($earliest === null) || ($value < $earliest)) {
                $earliest = $value;
            }
        }

        return $earliest;
    }
}
