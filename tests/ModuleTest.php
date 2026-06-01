<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use MagicSunday\Webtrees\Statistic\Module;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use ReflectionNamedType;

use function array_keys;
use function assert;
use function is_array;
use function method_exists;
use function sprintf;

/**
 * Module-level smoke tests for the six-tab action surface.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(Module::class)]
final class ModuleTest extends TestCase
{
    /**
     * Action keys that {@see Module::tabCatalog()} maps onto the six tabs. Kept
     * here so the two structural tests below cannot drift apart on a future
     * rename.
     *
     * @var list<string>
     */
    private const array TAB_ACTIONS = [
        'Overview',
        'Names',
        'LifeSpan',
        'Family',
        'Places',
        'TreeHealth',
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
     * Each tab action must take a single ServerRequestInterface and return a
     * ResponseInterface. Lock the signature so a refactor that swaps the
     * parameter order, drops the request, or changes the return type is caught
     * before it ships.
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
        $paramType = $parameters[0]->getType();
        assert($paramType instanceof ReflectionNamedType);
        self::assertSame(
            ServerRequestInterface::class,
            $paramType->getName(),
            'Webtrees binds tab actions by parameter type; this contract must not drift.',
        );

        $returnType = $reflection->getReturnType();
        assert($returnType instanceof ReflectionNamedType);
        self::assertSame(
            ResponseInterface::class,
            $returnType->getName(),
            'Tab actions must return a PSR-7 response so the router can emit it.',
        );
    }

    /**
     * Invoke {@see Module::tabCatalog()} via reflection and assert the keys
     * match the documented action list in declaration order. A rename in either
     * place that's not mirrored on the other will fail here instead of in
     * production as a missing navigation entry.
     */
    #[Test]
    public function tabCatalogKeysMatchTheDocumentedActions(): void
    {
        $catalog = (new ReflectionMethod(Module::class, 'tabCatalog'))
            ->invoke(new Module());

        assert(is_array($catalog));
        self::assertSame(
            self::TAB_ACTIONS,
            array_keys($catalog),
            'tabCatalog() must expose exactly the documented action keys in declaration order.',
        );
    }

    /**
     * Issue #47: the module previously advertised empty strings for the four
     * custom-module getters because it used webtrees core's `ModuleCustomTrait`
     * directly, whose default implementations return ''. Each accessor must now
     * hand back a non-empty value so admin tooling and update managers have an
     * upgrade marker to anchor on.
     */
    #[Test]
    public function customModuleGettersReturnNonEmptyMetadata(): void
    {
        $module = new Module();

        self::assertNotSame('', $module->customModuleAuthorName());
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $module->customModuleVersion());
        self::assertStringStartsWith('https://github.com/', $module->customModuleSupportUrl());
        self::assertStringStartsWith('https://api.github.com/repos/', $module->customModuleLatestVersionUrl());
    }

    /**
     * Drives the asset handler with a real .woff2 file from the module's
     * resources/ directory and asserts the response header carries the
     * `font/woff2` MIME type. The core Mime::TYPES map has no entry for web
     * fonts; without the local override the asset would ship as
     * `application/octet-stream`, which Firefox rejects with
     * `NS_ERROR_CORRUPTED_CONTENT`.
     */
    #[Test]
    public function getAssetActionServesWoff2WithFontMimeType(): void
    {
        $module  = new Module();
        $request = (new ServerRequest('GET', '/'))
            ->withQueryParams(['asset' => 'fonts/Geist-latin.woff2']);

        $response = $module->getAssetAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('font/woff2', $response->getHeaderLine('content-type'));
        self::assertGreaterThan(0, $response->getBody()->getSize());
    }

    /**
     * A directory-traversal attempt in the `asset` query parameter must be
     * rejected before any file read happens.
     */
    #[Test]
    public function getAssetActionRejectsPathTraversal(): void
    {
        $module  = new Module();
        $request = (new ServerRequest('GET', '/'))
            ->withQueryParams(['asset' => '../../../etc/passwd']);

        $this->expectException(HttpAccessDeniedException::class);
        $module->getAssetAction($request);
    }

    /**
     * A request for an asset that does not exist on disk must surface as a
     * clean 404 rather than a TypeError from response().
     */
    #[Test]
    public function getAssetActionThrowsNotFoundForMissingAsset(): void
    {
        $module  = new Module();
        $request = (new ServerRequest('GET', '/'))
            ->withQueryParams(['asset' => 'fonts/does-not-exist.woff2']);

        $this->expectException(HttpNotFoundException::class);
        $module->getAssetAction($request);
    }
}
