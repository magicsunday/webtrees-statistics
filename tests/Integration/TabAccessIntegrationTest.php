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
use Fisharebest\Webtrees\GuestUser;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Module\AbstractModule;
use MagicSunday\Webtrees\Statistic\Module;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionProperty;

/**
 * The AJAX tab actions must enforce the chart component's access level, not
 * just the page shell. The webtrees `ModuleAction` dispatcher delegates
 * per-component access to the module, so a tab endpoint called directly must
 * still deny a viewer the component is restricted from.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(Module::class)]
final class TabAccessIntegrationTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function tabActionProvider(): array
    {
        return [
            // The module's own six tab actions.
            'Overview'   => ['getOverviewAction'],
            'Names'      => ['getNamesAction'],
            'LifeSpan'   => ['getLifeSpanAction'],
            'Family'     => ['getFamilyAction'],
            'Places'     => ['getPlacesAction'],
            'TreeHealth' => ['getTreeHealthAction'],
            // The chart actions inherited from StatisticsChartModule, which the
            // module overrides to run the same gate.
            'Individuals' => ['getIndividualsAction'],
            'Families'    => ['getFamiliesAction'],
            'Other'       => ['getOtherAction'],
            'Custom'      => ['getCustomAction'],
            'CustomChart' => ['postCustomChartAction'],
        ];
    }

    /**
     * With the chart component restricted to members, every tab action called by
     * an anonymous visitor must throw {@see HttpAccessDeniedException} — the
     * dispatcher does not gate it, so the action itself has to. Without the
     * in-action access check the call would instead fall through to the render
     * path, so the access-denied exception type is the discriminator.
     */
    #[Test]
    #[DataProvider('tabActionProvider')]
    public function tabActionDeniesAVisitorWhenTheChartIsMembersOnly(string $method): void
    {
        $tree   = $this->importFixtureTree('father-son-name-passdown.ged');
        $module = new Module();

        // Restrict the chart component to members (PRIV_USER); the default
        // permits visitors, so without this the gate has nothing to deny. Set
        // the module's fallback access level directly (no module_privacy row,
        // which would need a FK-satisfying `module` row for a bare instance).
        $accessLevel = new ReflectionProperty(AbstractModule::class, 'access_level');
        $accessLevel->setValue($module, Auth::PRIV_USER);

        Auth::logout();

        $request = (new ServerRequest('GET', '/'))
            ->withAttribute('tree', $tree)
            ->withAttribute('user', new GuestUser());

        $this->expectException(HttpAccessDeniedException::class);

        /** @var callable(ServerRequestInterface):mixed $action */
        $action = [$module, $method];
        $action($request);
    }
}
