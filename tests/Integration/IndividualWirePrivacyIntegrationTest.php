<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\IndividualWire;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Privacy gate of the {@see IndividualWire} flattener: the node stays in the
 * graph (so a marriage-group's topology and counts never shift by viewer), but
 * for a person the viewer cannot see every DERIVED fact is withheld — only the
 * already-privatised label and the bare xref survive. `fullName()` privatises
 * the name only, so birth year, death year, sex and the xref page-link would
 * otherwise leak about a positionally-identifiable, usually living person.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(IndividualWire::class)]
#[UsesClass(RecordName::class)]
final class IndividualWirePrivacyIntegrationTest extends AbstractIntegrationTestCase
{
    private function livingTree(): Tree
    {
        $tree = $this->importFixtureTree('record-living-holder.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        return $tree;
    }

    /**
     * As the importing admin the living holder is visible, so every derived
     * field is present — proving the fixture holder genuinely carries a birth
     * year and sex, so the visitor blanking below is the gate firing, not an
     * empty record. `url()` resolves because the base test request is bound.
     */
    #[Test]
    public function visibleIndividualKeepsEveryDerivedField(): void
    {
        $tree       = $this->livingTree();
        $individual = Registry::individualFactory()->make('I1', $tree);
        self::assertInstanceOf(Individual::class, $individual);
        self::assertTrue($individual->canShow(), 'precondition: the importing admin can see the living holder');

        $row = IndividualWire::row($individual);

        self::assertSame('I1', $row['xref']);
        self::assertStringContainsString('Living', $row['label']);
        self::assertSame('M', $row['sex']);
        self::assertSame('2010', $row['birth']);
        self::assertNotSame('', $row['url']);
    }

    /**
     * For a visitor the living holder is not visible: the node stays (its xref
     * anchors the graph edge) and its label is the "Private" placeholder, but
     * sex, birth, death and the page link are all withheld — the derived facts
     * that `fullName()` does NOT privatise.
     */
    #[Test]
    public function nonVisibleIndividualWithholdsEveryDerivedFact(): void
    {
        $tree = $this->livingTree();

        Auth::logout();

        $individual = Registry::individualFactory()->make('I1', $tree);
        self::assertInstanceOf(Individual::class, $individual);
        self::assertFalse($individual->canShow(), 'precondition: the visitor cannot see the living holder');

        $row = IndividualWire::row($individual);

        // The node survives for graph topology; only the derived facts are gone.
        self::assertSame('I1', $row['xref']);
        self::assertStringNotContainsString('Living', $row['label']);
        self::assertSame('U', $row['sex']);
        self::assertSame('', $row['birth']);
        self::assertSame('', $row['death']);
        self::assertSame('', $row['url']);
    }
}
