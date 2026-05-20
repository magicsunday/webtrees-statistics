<?php

declare(strict_types=1);

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace MagicSunday\Webtrees\Statistic\Test;

use MagicSunday\Webtrees\Statistic\Module;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function array_keys;
use function method_exists;
use function sprintf;

/**
 * Module-level smoke tests for the six-tab action surface.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ModuleTest extends TestCase
{
    /**
     * Action keys that {@see Module::tabCatalog()} maps onto the six tabs.
     * Kept here so the two structural tests below cannot drift apart on a
     * future rename.
     *
     * @var list<string>
     */
    private const array TAB_ACTIONS = [
        'Overview',
        'Names',
        'TreeHealth',
        'LifeSpan',
        'Family',
        'Places',
    ];

    /**
     * One row per tab action — extend whenever a new tab is added, and the
     * signature lock-in covers it for free.
     *
     * @return array<string, array{0: string}>
     */
    public static function tabActionMethodProvider(): array
    {
        return [
            'Overview'   => ['getOverviewAction'],
            'Names'      => ['getNamesAction'],
            'TreeHealth' => ['getTreeHealthAction'],
            'LifeSpan'   => ['getLifeSpanAction'],
            'Family'     => ['getFamilyAction'],
            'Places'     => ['getPlacesAction'],
        ];
    }

    /**
     * Each tab action must take a single ServerRequestInterface and return
     * a ResponseInterface. Lock the signature so a refactor that swaps the
     * parameter order, drops the request, or changes the return type is
     * caught before it ships.
     */
    #[Test]
    #[DataProvider('tabActionMethodProvider')]
    public function tabActionHasExpectedSignature(string $method): void
    {
        self::assertTrue(
            method_exists(Module::class, $method),
            sprintf('Module::%s() must exist for the tab to render.', $method),
        );

        $reflection = new ReflectionMethod(Module::class, $method);
        $parameters = $reflection->getParameters();

        self::assertCount(
            1,
            $parameters,
            'Tab action must accept exactly the ServerRequestInterface parameter required by the webtrees router.',
        );
        self::assertSame(
            'Psr\\Http\\Message\\ServerRequestInterface',
            $parameters[0]->getType()->getName(),
            'Webtrees binds tab actions by parameter type; this contract must not drift.',
        );
        self::assertSame(
            'Psr\\Http\\Message\\ResponseInterface',
            $reflection->getReturnType()->getName(),
            'Tab actions must return a PSR-7 response so the router can emit it.',
        );
    }

    /**
     * Invoke {@see Module::tabCatalog()} via reflection and assert the keys
     * match the documented action list in declaration order. A rename in
     * either place that's not mirrored on the other will fail here instead
     * of in production as a missing navigation entry.
     */
    #[Test]
    public function tabCatalogKeysMatchTheDocumentedActions(): void
    {
        $catalog = (new ReflectionMethod(Module::class, 'tabCatalog'))
            ->invoke(new Module());

        self::assertSame(
            self::TAB_ACTIONS,
            array_keys($catalog),
            'tabCatalog() must expose exactly the documented action keys in declaration order.',
        );
    }
}
