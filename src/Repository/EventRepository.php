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
use Fisharebest\Webtrees\Statistics\Google\ChartDistribution;
use Fisharebest\Webtrees\Statistics\Service\CenturyService;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use function array_slice;

/**
 * A repository providing methods for event-related statistics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class EventRepository
{
    /**
     * Event facts.
     */
    private const EVENT_BIRTH = 'BIRT';
    private const EVENT_DEATH = 'DEAT';

    /**
     * An ordered list of month names.
     */
    private const MONTH_LIST = [
        'JAN',
        'FEB',
        'MAR',
        'APR',
        'MAY',
        'JUN',
        'JUL',
        'AUG',
        'SEP',
        'OCT',
        'NOV',
        'DEC',
    ];

    /**
     * @var Tree
     */
    private Tree $tree;

    /**
     * @var CenturyService
     */
    private CenturyService $centuryService;

    /**
     * @var ChartDistribution
     */
    private ChartDistribution $chartDistribution;

    /**
     * Constructor.
     *
     * @param Tree              $tree
     * @param CenturyService    $centuryService
     * @param ChartDistribution $chartDistribution
     */
    public function __construct(
        Tree $tree,
        CenturyService $centuryService,
        ChartDistribution $chartDistribution
    ) {
        $this->tree              = $tree;
        $this->centuryService    = $centuryService;
        $this->chartDistribution = $chartDistribution;
    }

    /**
     * Labels for the X axis.
     *
     * @return array<string>
     */
    protected function translateMonthAbbreviation(): array
    {
        return [
            'JAN' => I18N::translateContext('NOMINATIVE', 'January'),
            'FEB' => I18N::translateContext('NOMINATIVE', 'February'),
            'MAR' => I18N::translateContext('NOMINATIVE', 'March'),
            'APR' => I18N::translateContext('NOMINATIVE', 'April'),
            'MAY' => I18N::translateContext('NOMINATIVE', 'May'),
            'JUN' => I18N::translateContext('NOMINATIVE', 'June'),
            'JUL' => I18N::translateContext('NOMINATIVE', 'July'),
            'AUG' => I18N::translateContext('NOMINATIVE', 'August'),
            'SEP' => I18N::translateContext('NOMINATIVE', 'September'),
            'OCT' => I18N::translateContext('NOMINATIVE', 'October'),
            'NOV' => I18N::translateContext('NOMINATIVE', 'November'),
            'DEC' => I18N::translateContext('NOMINATIVE', 'December'),
        ];
    }

    //    /**
    //     * @return int
    //     */
    //    public function getTotalBirths(): int
    //    {
    //        return $this->getEventCount(self::EVENT_BIRTH);
    //    }

    /**
     * Returns a list of all birth events grouped by month of birth.
     *
     * @return array<string, int>
     */
    public function getBirthsByMonth(): array
    {
        $birthsByMonth   = $this->getEventsGroupedByMonth(self::EVENT_BIRTH);
        $translatedMonth = $this->translateMonthAbbreviation();
        $result          = [];

        foreach ($birthsByMonth as $month => $value) {
            $result[$translatedMonth[$month]] = $value;
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByCentury(): array
    {
        return $this->getEventsGroupedByCentury(self::EVENT_BIRTH);
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByZodiacSign(): array
    {
        return $this->getEventsGroupedByZodiacSign(self::EVENT_BIRTH);
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByCountry(): array
    {
        $chartData = $this->chartDistribution->createChartData(
            $this->chartDistribution->countIndividualEventsByCountry(
                $this->tree,
                self::EVENT_BIRTH
            )
        );

        array_shift($chartData);

        $mappedChartData = [];

        foreach ($chartData as $values) {
            $mappedChartData[] = [
                'countryCode' => $values[0]['v'],
                'label' => $values[0]['f'],
                'count' => $values[1],
            ];
        }

        usort($mappedChartData, static fn($a, $b) => $b['count'] <=> $a['count']);

        return $mappedChartData;
    }

    //    /**
    //     * @return int
    //     */
    //    public function getTotalDeaths(): int
    //    {
    //        return $this->getEventCount(self::EVENT_DEATH);
    //    }

    /**
     * Returns a list of all death events grouped by month of death.
     *
     * @return array<string, int>
     */
    public function getDeathsByMonth(): array
    {
        $deathsByMonth   = $this->getEventsGroupedByMonth(self::EVENT_DEATH);
        $translatedMonth = $this->translateMonthAbbreviation();
        $result          = [];

        foreach ($deathsByMonth as $month => $value) {
            $result[$translatedMonth[$month]] = $value;
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByCentury(): array
    {
        return $this->getEventsGroupedByCentury(self::EVENT_DEATH);
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByCountry(): array
    {
        $chartData = $this->chartDistribution->createChartData(
            $this->chartDistribution->countIndividualEventsByCountry(
                $this->tree,
                self::EVENT_DEATH
            )
        );

        array_shift($chartData);

        $mappedChartData = [];

        foreach ($chartData as $values) {
            $mappedChartData[] = [
                'countryCode' => $values[0]['v'],
                'label' => $values[0]['f'],
                'count' => $values[1],
            ];
        }

        usort($mappedChartData, static fn($a, $b) => $b['count'] <=> $a['count']);

        return $mappedChartData;
    }

    /**
     * Returns the total number of events (with dates).
     *
     * @param string ...$events The list of events to count (e.g., BIRT, DEAT, ...)
     *
     * @return int
     *
     * @see \Fisharebest\Webtrees\Statistics\Repository\EventRepository::getEventCount()
     */
    protected function getEventCount(string ...$events): int
    {
        $query = DB::table('dates')
            ->where('d_file', '=', $this->tree->id());

        $ignoredTypes = [
            'HEAD',
            'CHAN',
        ];

        if ($events !== []) {
            $types = [];

            foreach ($events as $type) {
                if (strncmp($type, '!', 1) === 0) {
                    $ignoredTypes[] = substr($type, 1);
                } else {
                    $types[] = $type;
                }
            }

            if ($types !== []) {
                $query->whereIn('d_fact', $types);
            }
        }

        return $query
            ->whereNotIn('d_fact', $ignoredTypes)
            ->count();
    }

    /**
     * Returns a key => value pair list of events grouped by month with their respective count.
     *
     * @param string ...$events The list of events (e.g., BIRT, DEAT, ...)
     *
     * @return array<string, int>
     */
    protected function getEventsGroupedByMonth(string ...$events): array
    {
        $query = DB::table('dates')
            ->select('d_month')
            ->selectRaw('COUNT(d_month) AS month_count')
            ->where('d_file', '=', $this->tree->id())
            ->where('d_month', '!=', '');

        $ignoredTypes = [
            'HEAD',
            'CHAN',
        ];

        if ($events !== []) {
            $types = [];

            foreach ($events as $type) {
                if (strncmp($type, '!', 1) === 0) {
                    $ignoredTypes[] = substr($type, 1);
                } else {
                    $types[] = $type;
                }
            }

            if ($types !== []) {
                $query->whereIn('d_fact', $types);
            }
        }

        $result = $query
            ->whereNotIn('d_fact', $ignoredTypes)
            ->groupBy('d_month')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                $row->d_month => (int) $row->month_count,
            ])
            ->toArray();

        return array_merge(array_flip(self::MONTH_LIST), $result);
    }

    /**
     * Returns a key => value pair list of events grouped by century with their respective count.
     *
     * @param string ...$events The list of events (e.g., BIRT, DEAT, ...)
     *
     * @return array
     */
    protected function getEventsGroupedByCentury(string ...$events): array
    {
        $query = DB::table('dates')
            ->selectRaw('ROUND((d_year + 49) / 100, 0) AS century')
            ->selectRaw('COUNT(*) AS total')
            ->where('d_file', '=', $this->tree->id())
            ->where('d_year', '<>', 0)
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);

        $ignoredTypes = [
            'HEAD',
            'CHAN',
        ];

        if ($events !== []) {
            $types = [];

            foreach ($events as $type) {
                if (strncmp($type, '!', 1) === 0) {
                    $ignoredTypes[] = substr($type, 1);
                } else {
                    $types[] = $type;
                }
            }

            if ($types !== []) {
                $query->whereIn('d_fact', $types);
            }
        }

        return $query
            ->whereNotIn('d_fact', $ignoredTypes)
            ->groupBy('century')
            ->orderBy('century')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $this->centuryService->centuryName((int) $row->century) => (int) $row->total,
            ])
            ->toArray();
    }

    /**
     * Returns a key => value pair list of events grouped by zodiac sign with their respective count.
     *
     * @param string ...$events The list of events (e.g., BIRT, DEAT, ...)
     *
     * @return array
     *
     * @see https://www.compart.com/de/unicode/block/U+2600
     */
    protected function getEventsGroupedByZodiacSign(string ...$events): array
    {
        $zodiacSigns = [
            'Aries' => [
                'from' => [
                    3,
                    21,
                ],
                'to' => [
                    4,
                    20,
                ],
            ],
            'Taurus' => [
                'from' => [
                    4,
                    22,
                ],
                'to' => [
                    5,
                    21,
                ],
            ],
            'Gemini' => [
                'from' => [
                    5,
                    22,
                ],
                'to' => [
                    6,
                    21,
                ],
            ],
            'Cancer' => [
                'from' => [
                    6,
                    22,
                ],
                'to' => [
                    7,
                    22,
                ],
            ],
            'Leo' => [
                'from' => [
                    7,
                    23,
                ],
                'to' => [
                    8,
                    22,
                ],
            ],
            'Virgo' => [
                'from' => [
                    8,
                    23,
                ],
                'to' => [
                    9,
                    22,
                ],
            ],
            'Libra' => [
                'from' => [
                    9,
                    23,
                ],
                'to' => [
                    10,
                    22,
                ],
            ],
            'Scorpio' => [
                'from' => [
                    10,
                    23,
                ],
                'to' => [
                    11,
                    22,
                ],
            ],
            'Sagittarius' => [
                'from' => [
                    11,
                    23,
                ],
                'to' => [
                    12,
                    20,
                ],
            ],
            'Capricornus' => [
                'from' => [
                    12,
                    21,
                ],
                'to' => [
                    1,
                    19,
                ],
            ],
            'Aquarius' => [
                'from' => [
                    1,
                    20,
                ],
                'to' => [
                    2,
                    18,
                ],
            ],
            'Pisces' => [
                'from' => [
                    2,
                    19,
                ],
                'to' => [
                    3,
                    20,
                ],
            ],
        ];

        $zodiacSignSql = '';

        foreach ($zodiacSigns as $zodiacSign => $zodiacDate) {
            $zodiacSignSql .= <<<SQL
COUNT(
    CASE WHEN (
        d_day != 0
            AND d_mon != 0
            AND ((d_mon = {$zodiacDate['from'][0]} AND d_day >= {$zodiacDate['from'][1]})
                 OR (d_mon = {$zodiacDate['to'][0]} AND d_day <= {$zodiacDate['to'][1]}))
    ) THEN 1 END
) AS {$zodiacSign}
SQL;

            if ($zodiacSign !== 'Pisces') {
                $zodiacSignSql .= ',';
            }
        }

        $query = DB::table('dates')
            ->selectRaw($zodiacSignSql)
            ->where('d_file', '=', $this->tree->id())
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);

        $ignoredTypes = [
            'HEAD',
            'CHAN',
        ];

        if ($events !== []) {
            $types = [];

            foreach ($events as $type) {
                if (strncmp($type, '!', 1) === 0) {
                    $ignoredTypes[] = substr($type, 1);
                } else {
                    $types[] = $type;
                }
            }

            if ($types !== []) {
                $query->whereIn('d_fact', $types);
            }
        }

        return (array) $query
            ->whereNotIn('d_fact', $ignoredTypes)
            ->get()
            ->first();
    }

//    /**
//     * Returns a key => value pair list of events grouped by country with their respective count.
//     *
//     * @param string ...$events The list of events (e.g., BIRT, DEAT, ...)
//     *
//     * @return array
//     */
//    protected function getEventsGroupedByCountry(string ...$events): array
//    {
//        $query = DB::table('places')
//            ->where(
//                'p_file',
//                '=',
//                $this->tree->id()
//            )
//            ->where(
//                'p_parent_id',
//                '=',
//                0
//            )
//            ->join(
//                'placelinks',
//                static function (JoinClause $join): void {
//                    $join
//                        ->on(
//                            'pl_file',
//                            '=',
//                            'p_file'
//                        )
//                        ->on(
//                            'pl_p_id',
//                            '=',
//                            'p_id'
//                        );
//                }
//            )
//            ->join(
//                'individuals',
//                static function (JoinClause $join): void {
//                    $join
//                        ->on(
//                            'pl_file',
//                            '=',
//                            'i_file'
//                        )
//                        ->on(
//                            'pl_gid',
//                            '=',
//                            'i_id'
//                        );
//                }
//            )
//            ->select(
//                [
//                    'p_place AS place',
//                    'i_gedcom AS gedcom',
//                ]
//            );
//
//        if ($events !== []) {
//            $types = [];
//
//            foreach ($events as $type) {
//                if (strncmp($type, '!', 1) === 0) {
//                    $ignoredTypes[] = substr($type, 1);
//                } else {
//                    $types[] = $type;
//                }
//            }
//
//            if ($types !== []) {
//                $query->whereIn('d_fact', $types);
//            }
//        }
//
//        return $query
//            ->whereNotIn('d_fact', $ignoredTypes)
//            ->groupBy('century')
//            ->orderBy('century')
//            ->get()
//            ->mapWithKeys(fn (object $row): array => [
//                $this->countryService->mapTwoLetterToName($row->century) => (int) $row->total,
//            ])
//            ->toArray();
//    }
}
