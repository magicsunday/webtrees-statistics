<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\View;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;

/**
 * Guards the cross-tab placement of cards that have moved between dashboard
 * tabs, so a later edit cannot silently relocate or duplicate one. The card's
 * tab membership is part of the dashboard's information architecture but is not
 * otherwise covered by any test — the section/card composition is plain view
 * markup. Static source assertions only; no render harness is needed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class CardPlacementTest extends TestCase
{
    /**
     * The sibling-death-clusters epidemic card was moved from the Family tab to
     * the Life span tab's "Mortality anomalies and clusters" section, since it
     * is a pure mortality-clustering signal read from children's recorded death
     * years. It must render on the Life span tab and no longer on the Family
     * tab.
     */
    #[Test]
    public function siblingDeathCardLivesOnTheLifeSpanTabOnly(): void
    {
        $lifeSpan = $this->loadTab('life-span');
        $family   = $this->loadTab('family');

        self::assertStringContainsString(
            "Card::for(\$module, I18N::translate('Sibling deaths in the same year'))",
            $lifeSpan,
            'The sibling-death card must render on the Life span tab',
        );

        self::assertStringContainsString(
            "I18N::translate('Mortality anomalies and clusters')",
            $lifeSpan,
            'The Life span tab must host the "Mortality anomalies and clusters" section',
        );

        self::assertStringNotContainsString(
            'Sibling deaths in the same year',
            $family,
            'The sibling-death card must no longer render on the Family tab',
        );
    }

    /**
     * Loads a dashboard tab template as a raw string for static assertion. Fails
     * fast if the path drifts.
     *
     * @param string $name The kebab-case tab template name without extension
     *
     * @return string The template source
     */
    private function loadTab(string $name): string
    {
        $path = dirname(__DIR__, 3)
            . '/resources/views/modules/statistics-chart/tabs/' . $name . '.phtml';

        $contents = file_get_contents($path);

        self::assertNotFalse($contents, $name . '.phtml tab template must be readable');

        return $contents;
    }
}
