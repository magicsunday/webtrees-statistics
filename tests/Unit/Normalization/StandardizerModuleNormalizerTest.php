<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Normalization;

use Fisharebest\Webtrees\Module\ModuleInterface;
use Fisharebest\Webtrees\Services\ModuleService;
use Hartenthaler\Webtrees\Module\OccupationStandardizer\PublicApi\OccupationStandardizerInterface;
use Hartenthaler\Webtrees\Module\OccupationStandardizer\PublicApi\StandardizedOccupation;
use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Normalization\NormalizedOccupation;
use MagicSunday\Webtrees\Statistic\Normalization\StandardizerModuleNormalizer;
use MagicSunday\Webtrees\Statistic\Normalization\Support\StringList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage of the standardizer adapter's two paths. Without an installed
 * occupation-standardization module the adapter must resolve every value to null
 * so the aggregations fall back to the raw string — the contract that makes
 * wiring the adapter safe on sites that have no such module. With a provider
 * present it must map each recognized {@see StandardizedOccupation} field onto
 * this module's {@see NormalizedOccupation} and keep the raw value for every
 * unrecognized entry.
 *
 * The provider's public-API classes only autoload when that optional module is
 * installed, so the provider-present tests run against the faithful stand-in in
 * `stubs/hh_occupation_standardizer.stub` — the same stub static analysis scans,
 * loaded for the test run via the PHPUnit bootstrap. This keeps the seam free of
 * a hard dependency on the provider while still exercising the mapping.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(StandardizerModuleNormalizer::class)]
#[UsesClass(StringList::class)]
#[UsesClass(NormalizedOccupation::class)]
final class StandardizerModuleNormalizerTest extends TestCase
{
    /**
     * With no module registered at all, every term resolves to null so the
     * caller keeps the raw value.
     */
    #[Test]
    public function normalizeManyReturnsAllNullWhenNoModuleIsInstalled(): void
    {
        $normalizer = new StandardizerModuleNormalizer($this->moduleServiceReturning([]));

        self::assertSame(['Arzt' => null], $normalizer->normalizeMany(['Arzt'], 'de'));
    }

    /**
     * A registered module that does not implement the provider interface is
     * ignored, and the batch method returns one null per distinct input.
     */
    #[Test]
    public function normalizeManyReturnsAllNullWhenNoProviderIsPresent(): void
    {
        $unrelatedModule = self::createStub(ModuleInterface::class);

        $normalizer = new StandardizerModuleNormalizer($this->moduleServiceReturning([$unrelatedModule]));

        self::assertSame(
            ['Arzt' => null, 'Bäcker' => null],
            $normalizer->normalizeMany(['Arzt', 'Bäcker'], 'de'),
        );
    }

    /**
     * A recognized result maps every provider field onto the value object, while
     * an unrecognized entry in the same batch keeps its null so the caller falls
     * back to the raw value.
     */
    #[Test]
    public function normalizeManyMapsEveryProviderFieldOntoTheValueObject(): void
    {
        $standardized = new StandardizedOccupation(
            canonicalKey: 'de:baecker',
            canonicalLabel: 'Bäcker',
            labelsByLanguage: ['de' => 'Bäcker'],
            hiscoCode: '75430',
            hisclass: '7',
            hiscamScore: 45.6,
        );

        $normalizer = new StandardizerModuleNormalizer(
            $this->moduleServiceWithProviderReturning(['Bäcker' => $standardized, 'Unbekannt' => null]),
        );

        $result = $normalizer->normalizeMany(['Bäcker', 'Unbekannt'], 'de');

        self::assertArrayHasKey('Unbekannt', $result);
        self::assertNull($result['Unbekannt']);

        self::assertArrayHasKey('Bäcker', $result);
        $mapped = $result['Bäcker'];
        self::assertInstanceOf(NormalizedOccupation::class, $mapped);
        self::assertSame('de:baecker', $mapped->groupingKey);
        self::assertSame('Bäcker', $mapped->displayLabel);
        self::assertSame('75430', $mapped->hiscoCode);
        self::assertSame(45.6, $mapped->hiscamScore);
    }

    /**
     * When the tree language is unknown the adapter resolves the display label
     * with the empty-string language, exercising the language-default branch.
     */
    #[Test]
    public function normalizeManyResolvesTheDisplayLabelWithEmptyLanguageWhenLanguageIsNull(): void
    {
        $standardized = new StandardizedOccupation(
            canonicalKey: 'de:baecker',
            canonicalLabel: 'Bäcker',
            labelsByLanguage: ['de' => 'Bäcker (de)', '' => 'Bäcker (neutral)'],
        );

        $normalizer = new StandardizerModuleNormalizer(
            $this->moduleServiceWithProviderReturning(['Bäcker' => $standardized]),
        );

        // No language argument leaves it at its null default — the unknown-language
        // case the adapter resolves through the empty-string display label.
        $result = $normalizer->normalizeMany(['Bäcker']);

        self::assertArrayHasKey('Bäcker', $result);
        $mapped = $result['Bäcker'];
        self::assertInstanceOf(NormalizedOccupation::class, $mapped);
        self::assertSame('Bäcker (neutral)', $mapped->displayLabel);
        self::assertNull($mapped->hiscoCode);
        self::assertNull($mapped->hiscamScore);
    }

    /**
     * Build a ModuleService whose single registered module is a provider stub
     * returning the given raw-value → result map from its batch method.
     *
     * @param array<string, StandardizedOccupation|null> $results
     */
    private function moduleServiceWithProviderReturning(array $results): ModuleService
    {
        $provider = self::createStubForIntersectionOfInterfaces(
            [ModuleInterface::class, OccupationStandardizerInterface::class],
        );
        $provider->method('standardizeMany')->willReturn($results);

        return $this->moduleServiceReturning([$provider]);
    }

    /**
     * Build a ModuleService test double whose module registry is the given list.
     *
     * @param list<ModuleInterface> $modules
     */
    private function moduleServiceReturning(array $modules): ModuleService
    {
        $moduleService = self::createStub(ModuleService::class);
        $moduleService->method('all')->willReturn(new Collection($modules));

        return $moduleService;
    }
}
