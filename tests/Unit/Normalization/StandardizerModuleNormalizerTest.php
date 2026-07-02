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
use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Normalization\StandardizerModuleNormalizer;
use MagicSunday\Webtrees\Statistic\Normalization\Support\StringList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage of the provider-absent behaviour of the standardizer adapter.
 * Without an installed occupation-standardization module the adapter must
 * resolve every value to null so the aggregations fall back to the raw string —
 * the contract that makes wiring the adapter safe on sites that have no such
 * module. The provider-present mapping cannot yet be represented as a unit
 * fixture because the provider's classes only autoload when that optional module
 * is installed; adding that automated coverage once its public API ships is
 * tracked in issue #222, and until then it is verified manually against the live
 * module.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(StandardizerModuleNormalizer::class)]
#[UsesClass(StringList::class)]
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
