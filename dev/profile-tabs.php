<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/*
 * Live per-tab / per-card performance harness.
 *
 * Boots webtrees against the running site's own database and times every
 * Statistic method a tab template calls, reporting wall-clock milliseconds and
 * the SQL query count per card. A card that runs few queries but burns many
 * milliseconds is doing the work in the database (or PHP), which is the
 * signature of an optimiser trap such as a LATERAL-re-evaluated derived join.
 *
 * Run it inside the PHP container that serves the site (it reads that site's
 * data/config.ini.php), e.g.:
 *
 *     php dev/profile-tabs.php <TreeName> [tab]
 *
 *   <TreeName>  required — the tree to profile (matched by gedcom name)
 *   [tab]       optional — one of: overview names life-span family places tree-health
 *
 * Override the webtrees root with WEBTREES_ROOT=/path when the layout differs
 * from the default `<root>/vendor/magicsunday/webtrees-statistics/dev`.
 *
 * Dev tooling only — never wired into the module runtime or CI.
 */

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Statistic;

// This dev script lives in the global namespace, so `use function` for global
// built-ins (count, sprintf, …) would be a no-op and trigger a PHP warning;
// the built-ins are called unqualified instead.

$root = is_string(getenv('WEBTREES_ROOT')) && (getenv('WEBTREES_ROOT') !== '')
    ? getenv('WEBTREES_ROOT')
    : dirname(__DIR__, 4);

$core = $root . '/vendor/fisharebest/webtrees';

require $root . '/vendor/autoload.php';

(new Webtrees())->bootstrap();
I18N::init('en-US', true);

$config = parse_ini_file($core . '/data/config.ini.php');

if ($config === false) {
    fwrite(STDERR, "Cannot read data/config.ini.php under {$core}.\n");
    exit(1);
}

DB::connect(
    driver: $config['dbtype'] ?? DB::MYSQL,
    host: (string) $config['dbhost'],
    port: (string) $config['dbport'],
    database: (string) $config['dbname'],
    username: (string) $config['dbuser'],
    password: (string) $config['dbpass'],
    prefix: (string) $config['tblpfx'],
    key: '',
    certificate: '',
    ca: '',
    verify_certificate: false,
);

I18N::init('en-US');
(new Gedcom())->registerTags(Registry::elementFactory(), true);

// Log in as an administrator so the profiled methods see the same privacy
// universe the viewing maintainer does.
foreach ((new UserService())->all() as $user) {
    if ($user->getPreference(UserInterface::PREF_IS_ADMINISTRATOR) === '1') {
        Auth::login($user);
        break;
    }
}

$treeName = $argv[1] ?? '';

if ($treeName === '') {
    fwrite(STDERR, "Usage: php dev/profile-tabs.php <TreeName> [tab]\n");
    exit(1);
}

$tree = null;

foreach (Registry::container()->get(TreeService::class)->all() as $candidate) {
    if ($candidate->name() === $treeName) {
        $tree = $candidate;
        break;
    }
}

if ($tree === null) {
    fwrite(STDERR, "Tree '{$treeName}' not found.\n");
    exit(1);
}

// Container-resolved repositories read the current tree from the container.
Registry::container()->set(Tree::class, $tree);

$individuals = DB::table('individuals')->where('i_file', '=', $tree->id())->count();
$families    = DB::table('families')->where('f_file', '=', $tree->id())->count();

fwrite(STDERR, sprintf("Tree: %s  individuals=%d families=%d\n", $tree->name(), $individuals, $families));

$statistic = Registry::container()->get(Statistic::class);

// One closure per Statistic call a tab template makes. Keep in lockstep with
// resources/views/modules/statistics-chart/tabs/*.phtml — a renamed method
// surfaces here as a visible error rather than silent under-coverage.
$tabs = [
    'overview' => [
        'getTotalIndividuals'          => fn () => $statistic->getTotalIndividuals(),
        'getTotalIndividualsData'      => fn () => $statistic->getTotalIndividualsData(),
        'getTotalLivingDeceasedData'   => fn () => $statistic->getTotalLivingDeceasedData(),
        'getFamilyStatusData'          => fn () => $statistic->getFamilyStatusData(),
        'getCumulativeBirthsByDecade'  => fn () => $statistic->getCumulativeBirthsByDecade(),
        'getTreeRecords'               => fn () => $statistic->getTreeRecords(),
        'getTotalOccupations'          => fn () => $statistic->getTotalOccupations(),
        'getTopOccupations(10)'        => fn () => $statistic->getTopOccupations(10),
        'getOccupationInheritance(12)' => fn () => $statistic->getOccupationInheritance(12),
        'getTotalReligions'            => fn () => $statistic->getTotalReligions(),
        'getTopReligions(10)'          => fn () => $statistic->getTopReligions(10),
    ],
    'names' => [
        'getTotalSurnames'                => fn () => $statistic->getTotalSurnames(),
        'getTotalMaleGivenNames'          => fn () => $statistic->getTotalMaleGivenNames(),
        'getTotalFemaleGivenNames'        => fn () => $statistic->getTotalFemaleGivenNames(),
        'getTopSurnames(15)'              => fn () => $statistic->getTopSurnames(15),
        'getTopMaleGivenNames(15)'        => fn () => $statistic->getTopMaleGivenNames(15),
        'getTopFemaleGivenNames(15)'      => fn () => $statistic->getTopFemaleGivenNames(15),
        'getGivenNameTrends(10)'          => fn () => $statistic->getGivenNameTrends(10),
        'getSurnameMarriageMatrix(8)'     => fn () => $statistic->getSurnameMarriageMatrix(8),
        'getSameSexNamePassdownByCentury' => fn () => $statistic->getSameSexNamePassdownByCentury(),
    ],
    'life-span' => [
        'getBirthsByDecade'                 => fn () => $statistic->getBirthsByDecade(),
        'getBirthsByCentury'                => fn () => $statistic->getBirthsByCentury(),
        'getBirthsByMonth'                  => fn () => $statistic->getBirthsByMonth(),
        'getBirthsByZodiacSign'             => fn () => $statistic->getBirthsByZodiacSign(),
        'getBirthHeatmapByPeriodMonth'      => fn () => $statistic->getBirthHeatmapByPeriodMonth(),
        'getDeathsByCentury'                => fn () => $statistic->getDeathsByCentury(),
        'getDeathsByMonth'                  => fn () => $statistic->getDeathsByMonth(),
        'getDeathHeatmapByPeriodMonth'      => fn () => $statistic->getDeathHeatmapByPeriodMonth(),
        'getDeathsByCenturyAgeBandSex'      => fn () => $statistic->getDeathsByCenturyAgeBandSex(),
        'getDeathAgeDistributionByCentury'  => fn () => $statistic->getDeathAgeDistributionByCentury(),
        'getDeathWinterPeakScore'           => fn () => $statistic->getDeathWinterPeakScore(),
        'getAgeAtDeathDistribution'         => fn () => $statistic->getAgeAtDeathDistribution(),
        'getAverageLifespanBySexAndCentury' => fn () => $statistic->getAverageLifespanBySexAndCentury(),
        'getSurvivalCurveByCentury'         => fn () => $statistic->getSurvivalCurveByCentury(),
        'getLivingByAgeBand'                => fn () => $statistic->getLivingByAgeBand(),
        'getChildMortalitySummary'          => fn () => $statistic->getChildMortalitySummary(),
        'getChildMortalityByBirthCentury'   => fn () => $statistic->getChildMortalityByBirthCentury(),
        'getMortalityAnomalies'             => fn () => $statistic->getMortalityAnomalies(),
        'getTotalDeathCauses'               => fn () => $statistic->getTotalDeathCauses(),
        'getTopDeathCauses(10)'             => fn () => $statistic->getTopDeathCauses(10),
        'getTopOldestDeceased(10)'          => fn () => $statistic->getTopOldestDeceased(10),
        'getTopOldestLiving(10)'            => fn () => $statistic->getTopOldestLiving(10),
    ],
    'family' => [
        'getAverageChildrenPerFamily'            => fn () => $statistic->getAverageChildrenPerFamily(),
        'getAverageFamilySizeByCentury'          => fn () => $statistic->getAverageFamilySizeByCentury(),
        'getChildrenPerFamilyDistribution'       => fn () => $statistic->getChildrenPerFamilyDistribution(),
        'getChildlessFamiliesDistribution'       => fn () => $statistic->getChildlessFamiliesDistribution(),
        'getFamilySizeStackedByDecade'           => fn () => $statistic->getFamilySizeStackedByDecade(),
        'getCoupleAgeGapDistribution'            => fn () => $statistic->getCoupleAgeGapDistribution(),
        'getSiblingAgeGapDistribution'           => fn () => $statistic->getSiblingAgeGapDistribution(),
        'getAgeAtFirstChildMeanByDecade'         => fn () => $statistic->getAgeAtFirstChildMeanByDecade(),
        'getMultipleBirthRateByCentury'          => fn () => $statistic->getMultipleBirthRateByCentury(),
        'getSexRatioAnomalies'                   => fn () => $statistic->getSexRatioAnomalies(),
        'getSiblingDeathClusters'                => fn () => $statistic->getSiblingDeathClusters(),
        'getWeddingsByCentury'                   => fn () => $statistic->getWeddingsByCentury(),
        'getWeddingsByMonth'                     => fn () => $statistic->getWeddingsByMonth(),
        'getFirstChildrenByMonth'                => fn () => $statistic->getFirstChildrenByMonth(),
        'getMarriageDurationDistribution'        => fn () => $statistic->getMarriageDurationDistribution(),
        'getMarriageDurationExtremes'            => fn () => $statistic->getMarriageDurationExtremes(),
        'getTrimmedAgeAtMarriageDistributions'   => fn () => $statistic->getTrimmedAgeAtMarriageDistributions(),
        'getTrimmedAgeAtFirstChildDistributions' => fn () => $statistic->getTrimmedAgeAtFirstChildDistributions(),
        'getTrimmedAgeAtDivorceDistributions'    => fn () => $statistic->getTrimmedAgeAtDivorceDistributions(),
        'getDivorcesByCentury'                   => fn () => $statistic->getDivorcesByCentury(),
        'getDivorcesByMonth'                     => fn () => $statistic->getDivorcesByMonth(),
        'getDivorceRateByMarriageCohort'         => fn () => $statistic->getDivorceRateByMarriageCohort(),
        'getRemarriageIntervalDistribution'      => fn () => $statistic->getRemarriageIntervalDistribution(),
        'getWidowhoodYearsDistribution'          => fn () => $statistic->getWidowhoodYearsDistribution(),
        'getTopLargestFamilies(10)'              => fn () => $statistic->getTopLargestFamilies(10),
        'getTopGrandchildFamilies(10)'           => fn () => $statistic->getTopGrandchildFamilies(10),
    ],
    'places' => [
        'getBirthsByCountry'               => fn () => $statistic->getBirthsByCountry(),
        'getDeathsByCountry'               => fn () => $statistic->getDeathsByCountry(),
        'getResidencesByCountry'           => fn () => $statistic->getResidencesByCountry(),
        'getMigrationDistanceDistribution' => fn () => $statistic->getMigrationDistanceDistribution(),
        'getMigrationFlows(20)'            => fn () => $statistic->getMigrationFlows(20),
        'getPlaceDispersionSummary'        => fn () => $statistic->getPlaceDispersionSummary(),
    ],
    'tree-health' => [
        'getTotalIndividuals'                  => fn () => $statistic->getTotalIndividuals(),
        'getRecordInventory'                   => fn () => $statistic->getRecordInventory(),
        'getMediaByType'                       => fn () => $statistic->getMediaByType(),
        'getSourceCitationCoverage'            => fn () => $statistic->getSourceCitationCoverage(),
        'getSourceCitationCoverageByCentury'   => fn () => $statistic->getSourceCitationCoverageByCentury(),
        'getGenerationDepthSummary'            => fn () => $statistic->getGenerationDepthSummary(),
        'getAverageGenerationLength'           => fn () => $statistic->getAverageGenerationLength(),
        'getAveragePedigreeCompleteness'       => fn () => $statistic->getAveragePedigreeCompleteness(),
        'getAncestorCountDistribution'         => fn () => $statistic->getAncestorCountDistribution(),
        'getTopAncestorsByDescendantCount(10)' => fn () => $statistic->getTopAncestorsByDescendantCount(10),
        'getConnectedComponents(10)'           => fn () => $statistic->getConnectedComponents(10),
        'getEndogamySummary'                   => fn () => $statistic->getEndogamySummary(),
        'getMissingEventGaps'                  => fn () => $statistic->getMissingEventGaps(),
    ],
];

$only  = $argv[2] ?? '';
$grand = 0.0;

foreach ($tabs as $tabName => $methods) {
    if (($only !== '') && ($only !== $tabName)) {
        continue;
    }

    fwrite(STDERR, sprintf("\n=== TAB: %s ===\n", $tabName));

    $tabMs = 0.0;

    foreach ($methods as $label => $method) {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $start = microtime(true);

        try {
            $method();
            $error = '';
        } catch (\Throwable $exception) {
            $error = ' ERR: ' . $exception->getMessage();
        }

        $milliseconds = (microtime(true) - $start) * 1000;
        $queries      = count(DB::connection()->getQueryLog());

        DB::connection()->disableQueryLog();

        $tabMs += $milliseconds;

        // Flag any card that crosses the perceptible-latency threshold so a
        // long tab stands out without reading every row.
        $flag = ($milliseconds >= 200.0) ? ' <<<' : '';

        fwrite(STDERR, sprintf("  %-42s %9.1f ms  %4d q%s%s\n", $label, $milliseconds, $queries, $flag, $error));
    }

    $grand += $tabMs;

    fwrite(STDERR, sprintf("  %-42s %9.1f ms (TAB TOTAL)\n", '', $tabMs));
}

fwrite(STDERR, sprintf("\nGRAND TOTAL: %.1f ms\n", $grand));
