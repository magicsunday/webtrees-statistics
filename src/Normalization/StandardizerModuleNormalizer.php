<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Normalization;

use Fisharebest\Webtrees\Module\ModuleInterface;
use Fisharebest\Webtrees\Services\ModuleService;
use Hartenthaler\Webtrees\Module\OccupationStandardizer\PublicApi\OccupationStandardizerInterface;
use Hartenthaler\Webtrees\Module\OccupationStandardizer\PublicApi\StandardizedOccupation;
use MagicSunday\Webtrees\Statistic\Normalization\Contract\OccupationNormalizerInterface;
use MagicSunday\Webtrees\Statistic\Normalization\Support\StringList;

use function array_fill_keys;

/**
 * Adapter that consumes an installed occupation-standardization module through
 * its public read-only API. It resolves the active provider once via
 * webtrees' {@see ModuleService} — matching by the published
 * {@see OccupationStandardizerInterface} rather than by module name, so it stays
 * name-independent — and maps the provider's {@see StandardizedOccupation}
 * result onto this module's own {@see NormalizedOccupation}. When no such module
 * is installed or enabled, every call resolves to null and the occupation
 * aggregations keep their raw values unchanged.
 *
 * This is the only class that references the provider's contract. The provider's
 * classes are loaded by the provider module itself at runtime; they are optional
 * dependencies, so this module never requires them and degrades gracefully when
 * they are absent.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class StandardizerModuleNormalizer implements OccupationNormalizerInterface
{
    /**
     * Whether {@see self::provider()} has already run its one-time lookup.
     */
    private bool $resolved = false;

    /**
     * The resolved provider, or null when none is installed/enabled. Only
     * meaningful once {@see self::$resolved} is true.
     */
    private ?OccupationStandardizerInterface $provider = null;

    /**
     * @param ModuleService $moduleService Registry of installed webtrees modules used to discover the provider
     */
    public function __construct(
        private readonly ModuleService $moduleService,
    ) {
    }

    /**
     * Resolve the distinct set through the installed provider in one batch and
     * map each recognized result onto this module's value object. When no
     * provider is present every input resolves to null, so the caller keeps the
     * raw value.
     *
     * @param iterable<string> $rawOccupations The distinct raw `1 OCCU` values to resolve
     * @param string|null      $language       BCP-47 language every value is written in, or null when unknown
     *
     * @return array<string, NormalizedOccupation|null> Keyed by each distinct input string; null keeps the raw value
     */
    public function normalizeMany(iterable $rawOccupations, ?string $language = null): array
    {
        $provider = $this->provider();

        if (!$provider instanceof OccupationStandardizerInterface) {
            return array_fill_keys(StringList::of($rawOccupations), null);
        }

        $normalized = [];

        foreach ($provider->standardizeMany($rawOccupations, $language) as $rawValue => $standardized) {
            $normalized[$rawValue] = $standardized instanceof StandardizedOccupation
                ? $this->toNormalizedOccupation($standardized, $language)
                : null;
        }

        return $normalized;
    }

    /**
     * Resolve the active provider once and cache the result — including a null
     * result, so the lookup does not repeat on a site without the module.
     */
    private function provider(): ?OccupationStandardizerInterface
    {
        if (!$this->resolved) {
            $module = $this->moduleService
                ->all()
                ->first(static fn (ModuleInterface $module): bool => $module instanceof OccupationStandardizerInterface);

            $this->provider = $module instanceof OccupationStandardizerInterface ? $module : null;
            $this->resolved = true;
        }

        return $this->provider;
    }

    /**
     * Map the provider's result onto this module's value object. The provider's
     * language-independent grouping key becomes the fold key, and the display
     * label is resolved for the tree language in the gender-neutral form (an
     * aggregated bucket has no single sex).
     *
     * @param StandardizedOccupation $standardized The provider's result for one raw value
     * @param string|null            $language     The tree language passed to the provider, or null when unknown
     */
    private function toNormalizedOccupation(StandardizedOccupation $standardized, ?string $language): NormalizedOccupation
    {
        return new NormalizedOccupation(
            $standardized->canonicalKey(),
            $standardized->displayLabel($language ?? ''),
            $standardized->hiscoCode(),
            $standardized->hiscamScore(),
        );
    }
}
