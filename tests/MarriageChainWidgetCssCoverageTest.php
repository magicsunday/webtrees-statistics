<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function str_contains;

/**
 * Locks the DOMAIN styling chart-lib intentionally omits for the two
 * marriage-chain widgets. chart-lib paints only geometry for the
 * `NetworkGraph` and `SequenceChain` widgets; the consumer's stylesheet is the
 * only place the geometry becomes a colour and a shape. The two widgets carry
 * different colour semantics:
 *
 *   - The NETWORK nodes are coloured by CHAIN MEMBERSHIP via chart-lib's own
 *     `…-node--highlighted` / `…-node--hub` classes (plus the off-chain base
 *     node). Sex plays no part, so the network carries no `data-group` hook.
 *   - The SEQUENCE-CHAIN beads keep their genealogy sex cue: each bead's sex
 *     code (`F` / `M` / `U`) lands in a neutral `data-group` attribute the
 *     consumer maps to a colour and a shape.
 *
 * If a future edit drops one of these selectors — or renames an `msc-*` class
 * chart-lib emits — the cue silently vanishes and the affected widget renders
 * in the neutral fallback. This test fails with the concrete missing selector
 * instead of a "the chains all look grey again" visual regression.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class MarriageChainWidgetCssCoverageTest extends TestCase
{
    /**
     * The network's chain-membership node selectors and the sequence-chain's
     * per-sex bead selectors must all be addressed in statistics.css, so the
     * chain colouring and the female / male / unknown bead styling stay wired.
     *
     * @param string $selector The selector fragment that must appear in statistics.css
     */
    #[Test]
    #[DataProvider('marriageWidgetSelectorProvider')]
    public function everyMarriageWidgetSelectorIsStyled(string $selector): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/resources/css/statistics.css');

        self::assertNotFalse($css, 'statistics.css must be readable');

        self::assertTrue(
            str_contains($css, $selector),
            'statistics.css must style the marriage-chain hook: ' . $selector,
        );
    }

    /**
     * The network chain-membership node selectors and the sequence-chain
     * per-sex bead selectors.
     *
     * @return array<string, array{string}>
     */
    public static function marriageWidgetSelectorProvider(): array
    {
        return [
            'network off-chain node' => ['.msc-network-graph-node {'],
            'network highlighted'    => ['.msc-network-graph-node--highlighted'],
            'network hub'            => ['.msc-network-graph-node--hub'],
            // The node label needs a card-coloured halo so the text stays
            // legible over nodes/edges and where labels crowd each other —
            // the selector plus its `paint-order`/`stroke` halo pair.
            'network label'      => ['.msc-network-graph-label {'],
            'network label halo' => ['paint-order:      stroke;'],
            'bead female'        => ['.msc-sequence-chain-bead[data-group="F"] .msc-sequence-chain-disc'],
            'bead male'          => ['.msc-sequence-chain-bead[data-group="M"] .msc-sequence-chain-disc'],
            'bead unknown'       => ['.msc-sequence-chain-bead[data-group="U"] .msc-sequence-chain-disc'],
            // The scroll container must overflow horizontally (not wrap), beads
            // must keep their width, and the two scroll-position edge-fade flags
            // must drive a mask — otherwise a long chain squeezes into the card
            // instead of overflowing and scrolling.
            'scroll container'  => ['.msc-sequence-chain-scroll {'],
            'scroll overflow-x' => ['overflow-x:          auto;'],
            'scroll fade start' => ['.msc-sequence-chain-scroll[data-start]'],
            'scroll fade end'   => ['.msc-sequence-chain-scroll[data-end]'],
            'bead no-shrink'    => ['flex:            0 0 96px;'],
            // The consumer-owned foot legend beneath the chain: the count strip
            // plus the sex-shape key whose two shapes (circle = female, rounded
            // square = male) explain the discs now that sex is shape-encoded.
            'chain foot'       => ['.wt-stat-sequence-chain-foot {'],
            'chain count'      => ['.wt-stat-sequence-chain-count {'],
            'chain key female' => ['.wt-stat-sequence-chain-key[data-sex="F"]'],
            'chain key male'   => ['.wt-stat-sequence-chain-key[data-sex="M"]'],
        ];
    }
}
