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
 * `NetworkGraph` and `SequenceChain` widgets and writes each node's/bead's
 * genealogy sex code verbatim into a neutral `data-group` attribute; the
 * consumer's stylesheet is the only place the sex code becomes a colour and a
 * shape.
 *
 * If a future edit drops one of the `[data-group="…"]` selectors — or renames
 * an `msc-*` class chart-lib emits — the sex cue silently vanishes and every
 * bead/node renders in the neutral fallback. This test fails with the concrete
 * missing selector instead of a "the chains all look grey again" visual
 * regression.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class MarriageChainWidgetCssCoverageTest extends TestCase
{
    /**
     * Every `data-group` sex code (`F` / `M` / `U`) must be addressed on both
     * the network node circle and the sequence-chain bead, so the female / male
     * / unknown styling is wired for each widget.
     *
     * @param string $selector The selector fragment that must appear in statistics.css
     */
    #[Test]
    #[DataProvider('sexGroupSelectorProvider')]
    public function everySexGroupSelectorIsStyled(string $selector): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/resources/css/statistics.css');

        self::assertNotFalse($css, 'statistics.css must be readable');

        self::assertTrue(
            str_contains($css, $selector),
            'statistics.css must style the marriage-chain sex hook: ' . $selector,
        );
    }

    /**
     * The network node and sequence-chain bead selectors keyed by sex code.
     *
     * @return array<string, array{string}>
     */
    public static function sexGroupSelectorProvider(): array
    {
        return [
            'network female'  => ['.msc-network-graph-node[data-group="F"]'],
            'network male'    => ['.msc-network-graph-node[data-group="M"]'],
            'network unknown' => ['.msc-network-graph-node[data-group="U"]'],
            'bead female'     => ['.msc-sequence-chain-bead[data-group="F"] .msc-sequence-chain-disc'],
            'bead male'       => ['.msc-sequence-chain-bead[data-group="M"] .msc-sequence-chain-disc'],
            'bead unknown'    => ['.msc-sequence-chain-bead[data-group="U"] .msc-sequence-chain-disc'],
        ];
    }
}
