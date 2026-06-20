<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use MagicSunday\Webtrees\Statistic\Statistic;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function realpath;
use function view;

/**
 * Render-level test of the marriage-chain block on the Tree-Health tab. The five
 * cards (the longest-chain scalar, the largest-group scalar, the depth-vs-breadth
 * ratio, the sequence-chain widget and the network-graph widget) carry their real
 * values and widget payloads when {@see Statistic::getMarriageReachSummary()}
 * resolves a report, and they still render with the empty-state placeholder as
 * their body — never vanish — when it returns `null` (a tree with no marriage
 * chain reaching three people).
 *
 * The tab partial is rendered through `view()` exactly as the AJAX tab action
 * does, with a live {@see Statistic} the container auto-wires against the imported
 * fixture tree — so the test exercises the real VO → widget-payload mapping in the
 * PHTML, not a stand-in.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class TreeHealthMarriageReachViewTest extends IntegrationTestCase
{
    /**
     * Fixed view-namespace slug for the rendered tab. A bare `new Module()` not
     * loaded through the module service has no resolved `name()`, so the test
     * registers the module's view directory under a stable slug of its own.
     */
    private const string MODULE = 'webtrees-statistics';

    /**
     * The 41-person / 15-chain `partner-chains.ged` cluster resolves a non-null
     * report, so the three scalar cards and the two widget shells all render —
     * the scalar values (15, 41 and the "7 : 15" ratio) appear and both widget
     * partials emit their `data-widget` host carrying a JSON payload.
     */
    #[Test]
    public function marriageReachBlockRendersTheFiveCardsWhenTheSummaryIsPresent(): void
    {
        $html = $this->renderTreeHealthTab($this->importFixtureTree('partner-chains.ged'));

        // K1 — longest marriage chain = 15 people. The scalar value is wrapped
        // in the scalar-value div, so match on that container's content.
        self::assertStringContainsString('Longest marriage chain', $html);
        self::assertMatchesRegularExpression('/wt-stat-scalar-value">\s*15\s/', $html);

        // K2 — largest connected marriage group = 41 people.
        self::assertStringContainsString('Largest connected group', $html);
        self::assertMatchesRegularExpression('/wt-stat-scalar-value">\s*41\s/', $html);

        // K3 — depth : breadth ratio. The breadth component is the 15-person
        // chain; the value is rendered as "<depth> : 15".
        self::assertStringContainsString('Depth-to-breadth ratio', $html);
        self::assertMatchesRegularExpression('/wt-stat-scalar-value">\s*\d+ : 15\s/', $html);

        // V1 + V2 — both widget shells render, each carrying a non-empty
        // serialised payload (anchored to the marriage widgets so the payload
        // assertion cannot pass on an unrelated widget's data-payload).
        self::assertMatchesRegularExpression('/data-widget="sequence-chain"[^>]*data-payload="[^"]+"/', $html);
        self::assertMatchesRegularExpression('/data-widget="network-graph"[^>]*data-payload="[^"]+"/', $html);

        // BUG fix — the median life year is a YEAR and must print WITHOUT a
        // thousands separator. `I18N::number(1934)` would render "1.934" under
        // a German locale and "1,934" under the en-US test locale; the caption
        // must carry the bare four-digit year instead. The negative regex
        // matches BOTH grouping separators so it fires whichever locale runs.
        self::assertMatchesRegularExpression('/median life around \d{4}\b/', $html);
        self::assertDoesNotMatchRegularExpression('/median life around \d[.,]\d{3}/', $html);

        // Tooltip — the consumer supplies a `title` per node and per bead so the
        // chart-lib widgets render a styled tooltip. The JSON key lands in the
        // e()-escaped data-payload as `&quot;title&quot;`.
        self::assertStringContainsString('&quot;title&quot;:', $html);

        // Foot legend — the network card carries the summary strip beneath the
        // widget, naming the longest-chain length, the group size and the
        // marriage count.
        self::assertStringContainsString('wt-stat-network-graph-legend', $html);
        self::assertStringContainsString('Longest chain (', $html);
    }

    /**
     * A tree with no marriage chain reaching three people yields a `null`
     * summary, so each of the five marriage-chain cards still renders — with the
     * empty-state placeholder as its body instead of a value or widget shell.
     */
    #[Test]
    public function marriageReachCardsRenderTheEmptyStateWhenTheSummaryIsNull(): void
    {
        $html = $this->renderTreeHealthTab($this->importFixtureTree('age-at-death-dedup.ged'));

        // The card headings stay present — the section does not vanish.
        self::assertStringContainsString('Longest marriage chain', $html);
        self::assertStringContainsString('Largest connected group', $html);
        self::assertStringContainsString('Depth-to-breadth ratio', $html);
        self::assertStringContainsString('Largest connected marriage group', $html);

        // Each card body falls back to the shared empty-state placeholder copy.
        self::assertStringContainsString('chart-empty-state', $html);
        self::assertStringContainsString('No data recorded for this metric.', $html);

        // Neither widget shell is emitted — a null report carries no payload to
        // hand the sequence-chain or network-graph widget.
        self::assertStringNotContainsString('data-widget="sequence-chain"', $html);
        self::assertStringNotContainsString('data-widget="network-graph"', $html);
    }

    /**
     * Render the Tree-Health tab partial for a given tree the same way the AJAX
     * tab action does: bind the tree into the container so it auto-wires the live
     * {@see Statistic}, register the module's view namespace and render the tab.
     */
    private function renderTreeHealthTab(Tree $tree): string
    {
        Registry::container()->set(Tree::class, $tree);

        View::registerNamespace(
            self::MODULE,
            realpath(__DIR__ . '/../../resources/views/') . '/'
        );

        $statistic = Registry::container()->get(Statistic::class);
        self::assertInstanceOf(Statistic::class, $statistic);

        return view(
            self::MODULE . '::modules/statistics-chart/tabs/tree-health',
            [
                'module'    => self::MODULE,
                'statistic' => $statistic,
            ]
        );
    }
}
