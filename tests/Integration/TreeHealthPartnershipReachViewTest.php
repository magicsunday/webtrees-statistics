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
 * Render-level test of the partnership-chain block on the Tree-Health tab. The five
 * cards (the longest-chain scalar, the largest-group scalar, the depth-vs-breadth
 * ratio, the sequence-chain widget and the network-graph widget) carry their real
 * values and widget payloads when {@see Statistic::getPartnershipReachSummary()}
 * resolves a report, and they still render with the empty-state placeholder as
 * their body — never vanish — when it returns `null` (a tree with no partnership
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
final class TreeHealthPartnershipReachViewTest extends IntegrationTestCase
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
    public function partnershipReachBlockRendersTheFiveCardsWhenTheSummaryIsPresent(): void
    {
        $html = $this->renderTreeHealthTab($this->importFixtureTree('partner-chains.ged'));

        // K1 — longest partnership chain = 15 people. The scalar value is wrapped
        // in the scalar-value div, so match on that container's content.
        self::assertStringContainsString('Longest partnership chain', $html);
        self::assertMatchesRegularExpression('/wt-stat-scalar-value">\s*15\s/', $html);

        // K2 — largest connected partnership group = 41 people.
        self::assertStringContainsString('Largest connected group', $html);
        self::assertMatchesRegularExpression('/wt-stat-scalar-value">\s*41\s/', $html);

        // K3 — depth : breadth ratio. The breadth component is the 15-person
        // chain; the value is rendered as "<depth> : 15".
        self::assertStringContainsString('Depth-to-breadth ratio', $html);
        self::assertMatchesRegularExpression('/wt-stat-scalar-value">\s*\d+ : 15\s/', $html);

        // V1 + V2 — both widget shells render, each carrying a non-empty
        // serialised payload (anchored to the partnership widgets so the payload
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
        // partnership count.
        self::assertStringContainsString('wt-stat-network-graph-legend', $html);
        self::assertStringContainsString('Longest chain (', $html);

        // Chain foot legend — the longest-chain card carries its own summary
        // strip: the chain length (15 people) and its partnership count (14 — one
        // fewer than the people, never the larger whole-group edge count), plus
        // a sex-shape key explaining the disc shapes now that sex is encoded by
        // shape, not colour.
        self::assertStringContainsString('wt-stat-sequence-chain-foot', $html);
        self::assertMatchesRegularExpression(
            '#wt-stat-sequence-chain-count">15 people · 14 partnerships<#u',
            $html,
        );
        self::assertStringContainsString('wt-stat-sequence-chain-key" data-sex="F"', $html);
        self::assertStringContainsString('wt-stat-sequence-chain-key" data-sex="M"', $html);

        // Layout — the network card spans the full twelve columns (the section
        // opening that wraps the network-graph widget carries `span 12`, never
        // the former `span 8`). The negative lookahead pins the span to the
        // SAME section the network-graph host lives in, so a span-12 chain card
        // cannot satisfy it by accident.
        self::assertMatchesRegularExpression(
            '#<section class="wt-stat-card[^"]*" style="grid-column: span 12;(?:(?!<section).)*?data-widget="network-graph"#s',
            $html,
        );

        // The "Largest connected group" scalar card now renders its footer slot
        // (`withFooter()`) so its dashed-rhythm footer matches its sibling
        // scalar cards. The lookahead keeps the match inside that one card —
        // and "Largest connected group" is not a substring of "Largest
        // connected partnership group", so it cannot match the network card.
        self::assertMatchesRegularExpression(
            '#Largest connected group(?:(?!</section>).)*?wt-stat-card-foot#s',
            $html,
        );

        // The partnership-reach cards live under their own section heading, and the
        // chain WIDGET card carries a distinct title from the chain SCALAR KPI
        // ("Longest partnership chain") so the same heading no longer appears twice.
        self::assertStringContainsString('How far does the tree connect through partnerships?', $html);
        self::assertStringContainsString('The longest chain, person by person', $html);
    }

    /**
     * A tree with no partnership chain reaching three people yields a `null`
     * summary, so each of the five partnership-chain cards still renders — with the
     * empty-state placeholder as its body instead of a value or widget shell.
     */
    #[Test]
    public function partnershipReachCardsRenderTheEmptyStateWhenTheSummaryIsNull(): void
    {
        $html = $this->renderTreeHealthTab($this->importFixtureTree('age-at-death-dedup.ged'));

        // The card headings stay present — the section does not vanish.
        self::assertStringContainsString('Longest partnership chain', $html);
        self::assertStringContainsString('Largest connected group', $html);
        self::assertStringContainsString('Depth-to-breadth ratio', $html);
        self::assertStringContainsString('Largest connected partnership group', $html);

        // Each card body falls back to the shared empty-state placeholder copy.
        self::assertStringContainsString('chart-empty-state', $html);
        self::assertStringContainsString('No data recorded for this metric.', $html);

        // Neither widget shell is emitted — a null report carries no payload to
        // hand the sequence-chain or network-graph widget.
        self::assertStringNotContainsString('data-widget="sequence-chain"', $html);
        self::assertStringNotContainsString('data-widget="network-graph"', $html);

        // The chain foot legend rides with the widget, so it must vanish too
        // when the card falls back to the empty-state placeholder.
        self::assertStringNotContainsString('wt-stat-sequence-chain-foot', $html);
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
