<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support;

use MagicSunday\Webtrees\Statistic\Model\Chord\ChordMatrixPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Model\Metric\ChildMortalitySummary;
use MagicSunday\Webtrees\Statistic\Model\Metric\EndogamyRate;
use MagicSunday\Webtrees\Statistic\Model\Metric\PlaceDispersionSummary;
use MagicSunday\Webtrees\Statistic\Model\Metric\RateCount;
use MagicSunday\Webtrees\Statistic\Model\Metric\WinterPeakScore;
use MagicSunday\Webtrees\Statistic\Model\Pyramid\PopulationPyramidPayload;
use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyLink;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarPayload;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarSeries;
use MagicSunday\Webtrees\Statistic\Model\Tree\HeroStats;
use MagicSunday\Webtrees\Statistic\Model\Tree\TreeRecordsReport;
use MagicSunday\Webtrees\Statistic\Support\WidgetJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the JSON wire shape of the plain payload objects that the chart partials
 * hand to {@see WidgetJson}: the property names, the property order, nulls, empty
 * arrays, nested objects, floats and non-ASCII text. A payload whose properties
 * are exactly its wire shape needs no serializer of its own, so this test is what
 * keeps the shape from drifting.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class PayloadEncodingTest extends TestCase
{
    /**
     * One payload per case with the JSON that its wire shape must produce.
     *
     * @return iterable<string, array{object, string}>
     */
    public static function payloadProvider(): iterable
    {
        yield 'chord matrix' => [new ChordMatrixPayload(['Ä "x"', 'B'], [[0, 1], [1, 0]]), '{"labels":["\\u00c4 \\"x\\"","B"],"matrix":[[0,1],[1,0]]}'];
        yield 'chord matrix empty' => [new ChordMatrixPayload([], []), '{"labels":[],"matrix":[]}'];
        yield 'line chart with a series' => [
            new LineChartPayload(['1900', '1910'], [new LineChartSeries('Ä', [1.5, 2], ['t'], ['l'], 'c')]),
            '{"categories":["1900","1910"],"series":[{"name":"\\u00c4","values":[1.5,2],"tooltips":["t"],"tooltipLabels":["l"],"class":"c"}]}',
        ];
        yield 'line chart empty' => [new LineChartPayload([], []), '{"categories":[],"series":[]}'];
        yield 'child mortality' => [new ChildMortalitySummary(10, 2, 0.2), '{"total":10,"died":2,"rate":0.2}'];
        yield 'endogamy rate' => [new EndogamyRate(8, 3, 0.375, 4), '{"total":8,"endogamous":3,"rate":0.375,"depth":4}'];
        yield 'place dispersion' => [new PlaceDispersionSummary(12.5, 3, ['a' => 1]), '{"average":12.5,"sampled":3,"distribution":{"a":1}}'];
        yield 'place dispersion empty' => [new PlaceDispersionSummary(0.0, 0, []), '{"average":0,"sampled":0,"distribution":[]}'];
        yield 'rate count' => [new RateCount(3, 9), '{"value":3,"total":9}'];
        yield 'winter peak' => [new WinterPeakScore(0.6, 2, 5), '{"score":0.6,"seasonCount":2,"total":5}'];
        yield 'population pyramid' => [new PopulationPyramidPayload(['m', 'f'], ['0-9'], [[['left' => 1, 'right' => 2]]]), '{"groups":["m","f"],"bands":["0-9"],"data":[[{"left":1,"right":2}]]}'];
        yield 'ranking entry' => [new RankingEntry('I1', 'Ä "Q"', 7), '{"xref":"I1","label":"\\u00c4 \\"Q\\"","value":7}'];
        yield 'sankey link with samples' => [new SankeyLink(0, 1, 5, [new SankeySample('Ö', 'I2')]), '{"source":0,"target":1,"value":5,"samples":[{"name":"\\u00d6","xref":"I2"}]}'];
        yield 'sankey sample' => [new SankeySample('A', 'I3'), '{"name":"A","xref":"I3"}'];
        yield 'stacked bar with a series' => [
            new StackedBarPayload(['c'], ['l'], [new StackedBarSeries('s', [1, 2], 'k')]),
            '{"categories":["c"],"tooltipLabels":["l"],"series":[{"name":"s","data":[1,2],"class":"k"}]}',
        ];
        yield 'stacked bar series' => [new StackedBarSeries('s', [], 'k'), '{"name":"s","data":[],"class":"k"}'];
        yield 'hero stats without decades' => [new HeroStats(5, 2, 3, null, 0.5, 0.25, 100), '{"individuals":5,"families":2,"maxGenerationDepth":3,"averageGenerationYears":null,"pedigreeCompleteness":0.5,"sourceCitationCoverage":0.25,"centurySpan":100,"decadeFrom":null,"decadeTo":null}'];
        yield 'hero stats with decades' => [new HeroStats(5, 2, 3, 28.5, 0.5, 0.25, 100, 1850, 1990), '{"individuals":5,"families":2,"maxGenerationDepth":3,"averageGenerationYears":28.5,"pedigreeCompleteness":0.5,"sourceCitationCoverage":0.25,"centurySpan":100,"decadeFrom":1850,"decadeTo":1990}'];
        yield 'tree records without records' => [
            new TreeRecordsReport(null, null, null, null, null, null, null, null, null, null, null, null, null, null, null),
            '{"oldestDeceased":null,"oldestLiving":null,"longestMarriage":null,"shortestMarriage":null,"youngestHusband":null,"youngestWife":null,"oldestHusband":null,"oldestWife":null,"mostSpouses":null,"largestFamily":null,"mostChildrenPerPerson":null,"youngestFatherAtFirstChild":null,"youngestMotherAtFirstChild":null,"oldestFatherAtFirstChild":null,"oldestMotherAtFirstChild":null}',
        ];
    }

    /**
     * The encoder writes exactly the wire shape that the payload documents.
     */
    #[Test]
    #[DataProvider('payloadProvider')]
    public function payloadEncodesToItsWireShape(object $payload, string $expected): void
    {
        self::assertSame($expected, WidgetJson::encode($payload));
    }
}
