<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\View;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Enum\MarriageEndReason;
use MagicSunday\Webtrees\Statistic\Model\Marriage\MarriageDurationExtreme;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;
use function substr_count;

/**
 * Renders the `marriage-extremes` partial against the privacy-suppression
 * contract: a row whose end-cause was suppressed to null (because the family
 * record is not visible) must render its couple and duration but omit the
 * end-cause line entirely, while a visible row still shows the localised cause.
 * The repository side of the null is covered by the integration suite; this
 * locks the view's consumption of it so a dropped null-guard (which would throw
 * an UnhandledMatchError) or an inverted condition is caught.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class MarriageExtremesViewTest extends TestCase
{
    /**
     * Boot the webtrees runtime so the partial's I18N / e() helpers resolve.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);
    }

    /**
     * Both rows carry a visible end-cause: each renders its own reason line
     * with the localised death / divorce label.
     */
    #[Test]
    public function visibleRowsRenderTheirEndCause(): void
    {
        $html = $this->render([
            'shortest' => [
                new MarriageDurationExtreme('F1', 'Anton + Berta', 31, 0, MarriageEndReason::Death),
                new MarriageDurationExtreme('F2', 'Carl + Doris', 15, 0, MarriageEndReason::Divorce),
            ],
            'longest' => [],
        ]);

        self::assertStringContainsString('Anton + Berta', $html);
        self::assertStringContainsString('Carl + Doris', $html);
        self::assertSame(2, substr_count($html, 'wt-stat-marriage-extremes-reason'));
        self::assertStringContainsString('ended by death', $html);
        self::assertStringContainsString('ended by divorce', $html);
    }

    /**
     * A row whose end-cause was suppressed to null keeps its couple label and
     * its ranked duration, but emits no reason line and no cause label.
     */
    #[Test]
    public function suppressedRowOmitsTheEndCauseButKeepsCoupleAndDuration(): void
    {
        $html = $this->render([
            'shortest' => [
                new MarriageDurationExtreme('F1', 'Anton + Berta', 31, 0, MarriageEndReason::Death),
                new MarriageDurationExtreme('F2', 'Private + Private', 15, 0, null),
            ],
            'longest' => [],
        ]);

        // The suppressed row still renders — its couple and duration are shown.
        self::assertStringContainsString('Private + Private', $html);
        self::assertStringContainsString('wt-stat-marriage-extremes-num">15<', $html);

        // Exactly one reason line (the visible F1 death), none for the null row.
        self::assertSame(1, substr_count($html, 'wt-stat-marriage-extremes-reason'));
        self::assertStringContainsString('ended by death', $html);
        self::assertStringNotContainsString('ended by divorce', $html);
    }

    /**
     * Render the marriage-extremes partial and capture its HTML. The partial is
     * included inside a static closure so it runs in an isolated scope (no
     * `$this` / test internals leak in) and so `$data` is a parameter the static
     * analyser sees consumed — an inlined `include` would read `$data` only
     * through include-scope, which Rector then prunes as an unused parameter.
     *
     * @param array{shortest: list<MarriageDurationExtreme>, longest: list<MarriageDurationExtreme>} $data
     */
    private function render(array $data): string
    {
        ob_start();

        (static function (string $partial, array $data): void {
            include $partial;
        })(__DIR__ . '/../../resources/views/modules/statistics-chart/components/marriage-extremes.phtml', $data);

        return (string) ob_get_clean();
    }
}
