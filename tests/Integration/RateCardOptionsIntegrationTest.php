<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use DOMDocument;
use DOMElement;
use Fisharebest\Webtrees\GuestUser;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Module;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function implode;
use function json_decode;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The rendered rate cards publish their look in the `data-options` the chart
 * library reads. A rate or a mean is a line, and the line chart fills the area
 * under a single series unless its card switches that off, while the number of
 * series of a card can depend on the tree.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class RateCardOptionsIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rateCards(): iterable
    {
        yield 'average family size' => ['getFamilyAction', 'average-family-size-line'];
        yield 'divorce rate' => ['getFamilyAction', 'divorce-cohort-line'];
        yield 'multiple-birth rate' => ['getFamilyAction', 'multiple-birth-rate-line'];
        yield 'child mortality' => ['getLifeSpanAction', 'child-mortality-century-line'];
        yield 'survival curve' => ['getLifeSpanAction', 'survival-curve'];
        yield 'name passdown' => ['getNamesAction', 'same-sex-name-passdown-line'];
    }

    /**
     * Each rate card renders a chart host that switches the area off.
     *
     * @param string $action     Tab action of the module that renders the card
     * @param string $identifier Element id of the chart host of the card
     */
    #[Test]
    #[DataProvider('rateCards')]
    public function aRateCardDrawsNoArea(string $action, string $identifier): void
    {
        self::assertFalse($this->chartOptions($action, $identifier)['showArea']);
    }

    /**
     * The passdown card suffixes its tooltip values, which would otherwise read
     * as bare numbers.
     */
    #[Test]
    public function theNamePassdownCardLabelsItsValuesAsPercent(): void
    {
        self::assertSame(' %', $this->chartOptions('getNamesAction', 'same-sex-name-passdown-line')['yUnit']);
    }

    /**
     * Render a tab for the generated tree and read the published options of one
     * of its line charts.
     *
     * @param string $action     Tab action of the module that renders the card
     * @param string $identifier Element id of the chart host of the card
     *
     * @return array{showArea: bool|null, yUnit: string|null}
     */
    private function chartOptions(string $action, string $identifier): array
    {
        $tree = $this->importGedcomTree('rate-cards', $this->rateCardGedcom());

        // The web middleware binds the tree of the request, and the module's
        // boot registers its view namespace.
        Registry::container()->set(Tree::class, $tree);
        $module = new Module();
        $module->setName('_webtrees-statistics_');
        $module->boot();

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('tree', $tree)
            ->withAttribute('user', new GuestUser());

        /** @var callable(ServerRequestInterface):ResponseInterface $render */
        $render = [$module, $action];

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $render($request)->getBody());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $host = $document->getElementById($identifier);

        self::assertInstanceOf(DOMElement::class, $host, 'The card must render a chart host');
        self::assertSame('line-chart', $host->getAttribute('data-widget'));

        /** @var array{showArea: bool|null, yUnit: string|null} $options */
        $options = json_decode($host->getAttribute('data-options'), true, 512, JSON_THROW_ON_ERROR);

        return $options;
    }

    /**
     * A tree with enough dated families of one century to pass the cohort floor
     * of each rate card. Every family has a divorce, twins and children who
     * carry the given name of the parent of their sex.
     */
    private function rateCardGedcom(): string
    {
        $records = [];

        for ($family = 0; $family < 60; ++$family) {
            $records[] = sprintf(
                "0 @H%1\$d@ INDI\n1 NAME Hans /Muster/\n1 SEX M\n1 BIRT\n2 DATE 1 JAN 1720\n1 DEAT\n2 DATE 1 JAN 1790\n1 FAMS @F%1\$d@\n",
                $family,
            );
            $records[] = sprintf(
                "0 @W%1\$d@ INDI\n1 NAME Anna /Muster/\n1 SEX F\n1 BIRT\n2 DATE 1 JAN 1722\n1 DEAT\n2 DATE 1 JAN 1792\n1 FAMS @F%1\$d@\n",
                $family,
            );

            $children = '';

            // The first two children share a birth date and are twins.
            foreach (['2 MAR 1741', '2 MAR 1741', '5 MAY 1744', '7 JUL 1747'] as $index => $birth) {
                $male      = ($index % 2) === 0;
                $records[] = sprintf(
                    "0 @C%1\$d-%2\$d@ INDI\n1 NAME %3\$s /Muster/\n1 SEX %4\$s\n1 BIRT\n2 DATE %5\$s\n1 DEAT\n2 DATE 1 AUG %6\$d\n1 FAMC @F%1\$d@\n",
                    $family,
                    $index,
                    $male ? 'Hans' : 'Anna',
                    $male ? 'M' : 'F',
                    $birth,
                    $index === 3 ? 1748 : 1800,
                );
                $children .= sprintf("1 CHIL @C%d-%d@\n", $family, $index);
            }

            $records[] = sprintf(
                "0 @F%1\$d@ FAM\n1 HUSB @H%1\$d@\n1 WIFE @W%1\$d@\n%2\$s1 MARR\n2 DATE 1 JUN 1740\n1 DIV\n2 DATE 1 JUN 1760\n",
                $family,
                $children,
            );
        }

        return "0 HEAD\n1 CHAR UTF-8\n" . implode('', $records) . "0 TRLR\n";
    }
}
