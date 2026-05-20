<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use MagicSunday\Webtrees\Statistic\Module;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function file_get_contents;
use function method_exists;
use function sprintf;

/**
 * Module-level smoke tests for the stub action surface.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ModuleTest extends TestCase
{
    /**
     * Tab keys that route through stubResponse(); kept here so the two
     * structural tests below cannot drift apart on a future rename.
     *
     * @var list<string>
     */
    private const STUB_ACTIONS = ['Relationships', 'Age', 'Weddings', 'Divorces', 'Children'];

    /**
     * One row per placeholder tab the module currently wires up — extend whenever
     * a new stub action is added, and the signature lock-in covers it for free.
     *
     * @return array<string, array{0: string}>
     */
    public static function stubActionMethodProvider(): array
    {
        return [
            'Relationships' => ['getRelationshipsAction'],
            'Age'           => ['getAgeAction'],
            'Weddings'      => ['getWeddingsAction'],
            'Divorces'      => ['getDivorcesAction'],
            'Children'      => ['getChildrenAction'],
        ];
    }

    /**
     * Each stub action must take a single ServerRequestInterface and return a ResponseInterface.
     * Lock the signature so a refactor that swaps the parameter order, drops the request, or
     * changes the return type is caught before it ships.
     */
    #[Test]
    #[DataProvider('stubActionMethodProvider')]
    public function stubActionHasExpectedSignature(string $method): void
    {
        self::assertTrue(
            method_exists(Module::class, $method),
            sprintf('Module::%s() must exist for the placeholder tab to render.', $method),
        );

        $reflection = new ReflectionMethod(Module::class, $method);
        $parameters = $reflection->getParameters();

        self::assertCount(
            1,
            $parameters,
            'Stub action must accept exactly the ServerRequestInterface parameter required by the webtrees router.',
        );
        self::assertSame(
            'Psr\\Http\\Message\\ServerRequestInterface',
            $parameters[0]->getType()->getName(),
            'Webtrees binds stub actions by parameter type; this contract must not drift.',
        );
        self::assertSame(
            'Psr\\Http\\Message\\ResponseInterface',
            $reflection->getReturnType()->getName(),
            'Stub actions must return a PSR-7 response so the router can emit it.',
        );
    }

    /**
     * Every stub action passes its key to stubResponse() which looks the label up in
     * tabCatalog(). Make sure each stub action's literal matches a key in the catalog
     * source so a rename on one side that's not mirrored on the other does not surface
     * as an undefined-index 500 in production.
     */
    #[Test]
    public function tabCatalogCoversEveryStubAction(): void
    {
        $source = file_get_contents((new ReflectionMethod(Module::class, 'tabCatalog'))->getFileName());
        self::assertIsString($source);

        foreach (self::STUB_ACTIONS as $action) {
            self::assertMatchesRegularExpression(
                sprintf("/'%s'\\s*=>\\s*I18N::translate\\(/", $action),
                $source,
                sprintf('tabCatalog() must include "%s" so stubResponse() can look up its label.', $action),
            );
        }
    }
}
