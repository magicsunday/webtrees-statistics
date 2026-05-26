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
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\FamilyDurationDaysRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\FamilyDurationYearsRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\IndividualCountRecord;
use MagicSunday\Webtrees\Statistic\Sex;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgeBuckets;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgePairExtremum;
use MagicSunday\Webtrees\Statistic\Support\Calc\IndividualAgeRecordResolver;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function abs;
use function intdiv;
use function is_numeric;

/**
 * Marriage-related aggregations for the Family tab. Combines core's
 * {@see StatisticsData::statsMarrAgeQuery()} (age at marriage per
 * sex) with local queries for duration distribution and couple
 * age-gap distribution.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MarriageRepository
{
    /**
     * Age-at-marriage histogram uses 5-year bands. Smaller than the
     * lifespan histogram because the relevant span (15–60) is also
     * narrower.
     */
    private const int AGE_AT_MARRIAGE_BUCKET = 5;

    private const int AGE_AT_MARRIAGE_MAX = 60;

    /**
     * Marriage-duration histogram uses 10-year bands up to 60+.
     */
    private const int DURATION_BUCKET = 10;

    private const int DURATION_MAX = 60;

    /**
     * Couple-age-gap histogram is centred on zero and 5-year wide.
     * gap = `husbandBirthJd − wifeBirthJd`. Negative buckets mean
     * the husband was born first (smaller julian day) → husband
     * is older than wife. Positive buckets mean wife older than
     * husband (or same year).
     */
    private const int AGE_GAP_BUCKET = 5;

    private const int AGE_GAP_LIMIT_HI = 30;

    /**
     * Plausibility band for the spouse-at-marriage and longest-marriage
     * records. Anything below this minimum is most likely a data-entry
     * error (BIRT and MARR swapped); anything above is either a
     * stepparent relationship or a stale BIRT. The two records reject
     * candidates outside the band so neither extreme is captured by
     * a single bad row.
     */
    private const int MIN_PLAUSIBLE_SPOUSE_AGE = 10;

    private const int MAX_PLAUSIBLE_SPOUSE_AGE = 90;

    /**
     * Per-instance cache for the marriage-duration row plucks.
     * `longestMarriageRecord` + `shortestMarriageRecord` +
     * `durationDistribution` all walk the same rows; sharing the
     * materialised list collapses three SELECTs into one.
     *
     * @var array<int, array{marrJd: int, endJd: int, xref: string}>|null
     */
    private ?array $marriageDurationPairsCache = null;

    /**
     * Per-instance cache for `spouseAgeAtMarriage`, keyed by the
     * sex argument. Both the youngest- and oldest-spouse records
     * walk the same rows for a given sex; sharing the materialised
     * list halves the SELECTs the Overview tab triggers.
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
     * Age-at-marriage distribution for one sex, bucketed into
     * 5-year bands plus a 60+ overflow.
     *
     * @param string $sex 'M' for husbands, 'F' for wives
     *
     * @return array<string, int>
     */
    public function ageAtMarriageDistribution(string $sex): array
    {
        $rows    = $this->data->statsMarrAgeQuery($sex, 0, 0);
        $buckets = AgeBuckets::init(0, self::AGE_AT_MARRIAGE_MAX, self::AGE_AT_MARRIAGE_BUCKET);

        foreach ($rows as $row) {
            $days = $row->age ?? 0;

            if ($days <= 0) {
                continue;
            }

            $years = intdiv($days, 365);
            $label = AgeBuckets::label($years, self::AGE_AT_MARRIAGE_MAX, self::AGE_AT_MARRIAGE_BUCKET);

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Marriage-duration distribution: years between MARR and the
     * earlier of the two spouses' DEAT (or DIV). Bucketed into
     * 10-year bands up to 60+.
     *
     * @return array<string, int>
     */
    public function durationDistribution(): array
    {
        $buckets = AgeBuckets::init(0, self::DURATION_MAX, self::DURATION_BUCKET);

        foreach ($this->marriageDurationPairs() as $pair) {
            $years           = intdiv($pair['endJd'] - $pair['marrJd'], 365);
            $label           = AgeBuckets::label($years, self::DURATION_MAX, self::DURATION_BUCKET);
            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Single longest-marriage record holder: the family with the
     * biggest (end julian-day − MARR julian-day) delta, where the
     * end is whichever happened first — DIV, husband's DEAT or
     * wife's DEAT. Returns null when no family has both a MARR
     * date AND a determinable end date. Capped at 90 years.
     */
    public function longestMarriageRecord(): ?FamilyDurationYearsRecord
    {
        $bestYears = 0;
        $bestXref  = null;

        foreach ($this->marriageDurationPairs() as $pair) {
            $years = intdiv($pair['endJd'] - $pair['marrJd'], 365);

            if ($years > $bestYears) {
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
     * Shortest recorded marriage: smallest positive
     * `(end julian-day − MARR julian-day)` delta. A divorce one
     * day after the wedding wins; same-day end is excluded by the
     * `endJd > marrJd` guard on the underlying iterator. Returns
     * the duration in days rather than years so a one-week or
     * one-month "fastest split" stays meaningful.
     */
    public function shortestMarriageRecord(): ?FamilyDurationDaysRecord
    {
        $bestDays = null;
        $bestXref = null;

        foreach ($this->marriageDurationPairs() as $pair) {
            $days = $pair['endJd'] - $pair['marrJd'];

            if (($bestDays === null) || ($days < $bestDays)) {
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
     * Youngest spouse at marriage: smallest positive (MARR julian-
     * day − spouse-BIRT julian-day) across the tree. Restricted to
     * one sex per call ('M' = husband, 'F' = wife). Implausible
     * young ages (below {@see self::MIN_PLAUSIBLE_SPOUSE_AGE}) are dropped to filter out data-entry
     * errors where BIRT and MARR were swapped.
     *
     * @param string $sex 'M' for husbands, 'F' for wives
     */
    public function youngestSpouseAtMarriageRecord(string $sex): ?IndividualAgeRecord
    {
        return $this->spouseAtMarriageRecord($sex, AgePairExtremum::Lowest);
    }

    /**
     * Oldest spouse at marriage: mirror of {@see youngestSpouseAtMarriageRecord()}.
     *
     * @param string $sex 'M' for husbands, 'F' for wives
     */
    public function oldestSpouseAtMarriageRecord(string $sex): ?IndividualAgeRecord
    {
        return $this->spouseAtMarriageRecord($sex, AgePairExtremum::Highest);
    }

    /**
     * Shared min / max walk over the spouse-age iterator with the
     * plausibility band applied before the comparison. The caller
     * picks the direction via {@see AgePairExtremum}.
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
     * Most marriages per individual: the person who participated
     * in the largest number of FAM records (as husband or wife).
     * Recorded via the `link` table — one FAMS link per marriage.
     */
    public function mostSpousesRecord(): ?IndividualCountRecord
    {
        $row = TreeScope::table($this->tree, 'link')
            ->where('l_type', '=', 'FAMS')
            ->groupBy('l_from')
            ->orderByRaw('COUNT(*) DESC')
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
     * Per-spouse age at marriage. Loops the existing core query
     * once per row and looks up the parent FAM's husband or wife
     * xref so the same row contributes to both age-at-marriage
     * histograms AND the youngest/oldest-spouse records. Result
     * is memoised per `$sex` so the Overview tab's young/old
     * extreme pair shares a single SELECT per sex.
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
            ->select([
                'fam.' . $spouseColumn . ' AS xref',
                'marr.d_julianday1 AS marr_jd',
                'birth.d_julianday1 AS birth_jd',
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

            $out[] = ['years' => intdiv($marrJd - $birthJd, 365), 'xref' => $xref];
        }

        $this->spouseAgeAtMarriageCache[$sex] = $out;

        return $out;
    }

    /**
     * Every family with both a parseable MARR date AND a determinable
     * marriage-end julian-day, returned as a `{marrJd, endJd, xref}`
     * triple. Callers turn it into years (durationDistribution,
     * longestMarriageRecord) or days (shortestMarriageRecord). The
     * end julian-day is the earliest of DIV / husband-DEAT /
     * wife-DEAT, so the row that survives is the one webtrees
     * considers the marriage's true terminus. Memoised per instance
     * — the three callers used to trigger three identical SELECTs.
     *
     * @return array<int, array{marrJd: int, endJd: int, xref: string}>
     */
    private function marriageDurationPairs(): array
    {
        if ($this->marriageDurationPairsCache !== null) {
            return $this->marriageDurationPairsCache;
        }

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
                'f_id AS xref',
                'marr.d_julianday1 AS marr_jd',
                'divr.d_julianday1 AS div_jd',
                'husb_d.d_julianday1 AS husb_jd',
                'wife_d.d_julianday1 AS wife_jd',
            ])
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

            $endJd = $this->earliestPositive([
                $row->div_jd ?? null,
                $row->husb_jd ?? null,
                $row->wife_jd ?? null,
            ]);

            if ($endJd === null) {
                continue;
            }

            if ($endJd <= $marrJd) {
                continue;
            }

            $out[] = ['marrJd' => $marrJd, 'endJd' => $endJd, 'xref' => $xref];
        }

        $this->marriageDurationPairsCache = $out;

        return $out;
    }

    /**
     * Couple age-gap distribution. Husband's birth year minus
     * wife's birth year, bucketed into symmetric 5-year bands
     * centred on zero. Negative buckets read "husband older than
     * wife" (smaller julian day → husband born first); positive
     * buckets read "wife older than husband".
     *
     * @return array<string, int>
     */
    public function ageGapDistribution(): array
    {
        $rows = TreeScope::table($this->tree, 'families')
            ->join('dates AS hb', static function (JoinClause $join): void {
                DateJoin::on($join, 'hb', 'f_file', 'f_husb', 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO);
            })
            ->join('dates AS wb', static function (JoinClause $join): void {
                DateJoin::on($join, 'wb', 'f_file', 'f_wife', 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO);
            })
            ->select([
                'hb.d_julianday1 AS hb_jd',
                'wb.d_julianday1 AS wb_jd',
            ])
            ->get();

        $buckets                                                      = [];
        $buckets[$this->signedOverflowLabel(-self::AGE_GAP_LIMIT_HI)] = 0;

        for ($edge = -self::AGE_GAP_LIMIT_HI + self::AGE_GAP_BUCKET; $edge < self::AGE_GAP_LIMIT_HI; $edge += self::AGE_GAP_BUCKET) {
            $buckets[$this->signedBucketLabel($edge)] = 0;
        }

        $buckets[$this->signedOverflowLabel(self::AGE_GAP_LIMIT_HI)] = 0;

        foreach ($rows as $row) {
            $hbJd = RowCast::int($row, 'hb_jd');
            $wbJd = RowCast::int($row, 'wb_jd');

            if ($hbJd <= 0) {
                continue;
            }

            if ($wbJd <= 0) {
                continue;
            }

            $gapYears = intdiv($hbJd - $wbJd, 365);

            if (abs($gapYears) >= self::AGE_GAP_LIMIT_HI) {
                $label = $this->signedOverflowLabel($gapYears >= 0 ? self::AGE_GAP_LIMIT_HI : -self::AGE_GAP_LIMIT_HI);
            } else {
                $edge  = $this->signedBucketLowerEdge($gapYears);
                $label = $this->signedBucketLabel($edge + self::AGE_GAP_BUCKET);
            }

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Weddings grouped by GEDCOM month abbreviation, leaning on
     * core's already-public {@see StatisticsData::countFirstMarriagesByMonth()}.
     * Core hands back the `{JAN: int, FEB: int, …}` map directly,
     * so the repository is just a thin pass-through.
     *
     * @return array<string, int>
     */
    public function weddingsByMonth(): array
    {
        return $this->data->countFirstMarriagesByMonth($this->tree, 0, 0);
    }

    /**
     * Weddings grouped by century, leaning on core's already-public
     * accessor. Output shape is `[centuryLabel => count]`.
     *
     * @return array<string, int>
     */
    public function weddingsByCentury(): array
    {
        // Core's countEventsByCentury returns a 0-indexed list of
        // `[centuryLabel, total]` tuples — NOT an associative
        // `centuryLabel => total` map. Iterating with `$k => $v`
        // and casting `$v` to int would collapse every count to 1.
        $out = [];

        foreach ($this->data->countEventsByCentury('MARR') as $row) {
            $out[$row[0]] = $row[1];
        }

        return $out;
    }

    /**
     * Label for a symmetric signed bucket, e.g. "0–4", "−5 to −1",
     * "5–9".
     */
    private function signedBucketLabel(int $upperExclusive): string
    {
        $lower = $upperExclusive - self::AGE_GAP_BUCKET;

        if ($lower >= 0) {
            return $lower . '–' . ($upperExclusive - 1);
        }

        return $lower . ' to ' . ($upperExclusive - 1);
    }

    /**
     * Label for the overflow buckets at either end of the gap
     * distribution.
     */
    private function signedOverflowLabel(int $edge): string
    {
        return $edge >= 0 ? $edge . '+' : '<' . $edge;
    }

    /**
     * Lower edge of the bucket containing the given signed value.
     */
    private function signedBucketLowerEdge(int $value): int
    {
        if ($value >= 0) {
            return intdiv($value, self::AGE_GAP_BUCKET) * self::AGE_GAP_BUCKET;
        }

        return -((intdiv(-$value - 1, self::AGE_GAP_BUCKET) + 1) * self::AGE_GAP_BUCKET);
    }

    /**
     * Earliest positive Julian-day number from a list of nullable
     * candidates, or null when none are positive.
     *
     * @param list<mixed> $candidates
     */
    private function earliestPositive(array $candidates): ?int
    {
        $earliest = null;

        foreach ($candidates as $candidate) {
            if (!is_numeric($candidate)) {
                continue;
            }

            $value = (int) $candidate;

            if ($value <= 0) {
                continue;
            }

            if ($earliest === null || $value < $earliest) {
                $earliest = $value;
            }
        }

        return $earliest;
    }
}
