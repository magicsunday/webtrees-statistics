<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Architecture;

use Illuminate\Database\Capsule\Manager;
use JsonSerializable;
use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_map;

/**
 * Architecture rules executed by phpat through PHPStan. Each `#[TestRule]`
 * method returns one rule that pins a structural invariant of the module.
 *
 * The layer-DEPENDENCY directions (Support/Model/Enum/DTO are leaves, nothing
 * depends on the composition root, the normalization seam never depends back on a
 * repository, …) are now enforced centrally by the shared Deptrac ruleset
 * (`deptrac.yaml` imports `magicsunday/coding-standard`'s canonical layers), so
 * they no longer live here. What remains are the checks Deptrac's namespace-layer
 * model cannot express: structural "must be final" / "must implement" invariants
 * and the confinement of raw Eloquent database access to the repository layer.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class ArchitectureTest
{
    private const string NAMESPACE_ROOT = 'MagicSunday\\Webtrees\\Statistic';

    /**
     * Per-widget DTO sub-namespaces under `Model\`. Listed explicitly so the
     * DTO structural rules below select exactly the wire-shape value objects
     * and not the root-level value objects (`FamilyRow`) which live alongside
     * them. Add an entry here whenever a new widget shape ships its own DTOs.
     *
     * @var list<string>
     */
    private const array DTO_SUB_NAMESPACES = [
        'Chord',
        'LineChart',
        'Metric',
        'Record',
        'Sankey',
        'StackedBar',
        'StreamGraph',
        'Tree',
    ];

    /**
     * Builds one `Selector::inNamespace` per DTO sub-namespace so the resulting
     * list can be splat into `->classes(...)` (which takes a varargs
     * disjunction) or wrapped in `Selector::AnyOf(...)`.
     *
     * @return list<SelectorInterface>
     */
    private function dtoSelectors(): array
    {
        return array_map(
            static fn (string $subNamespace): SelectorInterface => Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\' . $subNamespace),
            self::DTO_SUB_NAMESPACES,
        );
    }

    /**
     * Every helper in `Support\` must be `final` so its contract (`private
     * __construct`, static-only API) cannot be subverted by a subclass.
     *
     * `readonly` is deliberately not part of the rule, because it governs
     * instance state and this layer splits on exactly that: the helpers that
     * carry constructor-promoted state already declare `final readonly class`
     * themselves, while the remainder are pure static utilities with no
     * instance property at all — for those the modifier would add no invariant,
     * only a style constraint the rule has no business enforcing.
     */
    #[TestRule]
    public function supportClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Support'))
            ->should()->beFinal()
            ->because('Support helpers must be final so their static-only contract cannot be subverted by a subclass');
    }

    /**
     * Repositories must be `final` so the contract that the `Statistic` facade
     * composes (immutable Tree dependency, single per-domain query surface)
     * cannot be subverted by a subclass. `readonly` is the standard shape
     * across the module, but three repositories cache their lazy aggregation
     * result on first call and therefore stay non-readonly by necessity —
     * `final` alone is the strongest invariant we can enforce for the whole
     * layer.
     *
     * Abstract repositories are exempted: `AbstractGedcomTagTopNRepository` is
     * the shared scaffolding for the three Top-N repos (`ReligionRepository`,
     * `OccupationRepository`, `DeathCauseRepository`). Each concrete subclass
     * is still `final`, so the invariant survives transitively — the only
     * callable types the DI container resolves are the sealed leaves.
     */
    #[TestRule]
    public function repositoryClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository'))
            ->excluding(Selector::isAbstract())
            ->should()->beFinal()
            ->because('Repositories must be final so the Tree-DI contract cannot be subverted by a subclass');
    }

    /**
     * Database access via Eloquent's `DB::table()` facade is the exclusive
     * responsibility of repositories and of the dedicated `Support\Database`
     * namespace that factors the recurring `DB::table(X)->where('X_file', …)` +
     * birth/death-pair + date- table joins out of every repository call site.
     * Letting the Statistic facade or the composition root issue SQL directly
     * would scatter query-shape decisions across every layer and make it
     * impossible to reason about which class actually touches which table.
     *
     * This confinement is kept as a phpat rule because it targets a specific
     * (framework) class rather than a layer, which the Deptrac layer model does
     * not express.
     */
    #[TestRule]
    public function databaseAccessIsConfinedToRepositories(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository')),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Support\\Database')),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Test')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(Manager::class))
            ->because('Raw database access is only allowed inside repositories or in the dedicated Support\\Database namespace');
    }

    /**
     * Every DTO must be `final`. A subclass could add mutable state or override
     * `jsonSerialize` and silently drift the wire shape — the whole point of
     * moving repository return types from `array{…}` PHPDoc to typed DTOs is
     * that the wire shape stays pinned at a single class per payload.
     */
    #[TestRule]
    public function dtoClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(...$this->dtoSelectors())
            ->should()->beFinal()
            ->because('DTOs must be final so the wire shape can never be subverted by a subclass');
    }

    /**
     * Every DTO must implement `JsonSerializable`. The per-widget DTO
     * sub-namespaces under `Model\` exist to be serialised to JSON for the
     * chart-lib widgets via `json_encode`; a DTO without `jsonSerialize` would
     * silently fall back to PHP's default object-serialisation (mangled
     * property names) and break the widget contract on the wire.
     */
    #[TestRule]
    public function dtoClassesAreJsonSerializable(): Rule
    {
        return PHPat::rule()
            ->classes(...$this->dtoSelectors())
            ->should()->implement()
            ->classes(Selector::classname(JsonSerializable::class))
            ->because('DTOs ship to the wire via json_encode; without JsonSerializable the JSON shape would drift away from PHPDoc');
    }

    /**
     * Every concrete class in the occupation-normalization seam must be `final`
     * so its contract (identity default, immutable value object, single provider
     * adapter) cannot be subverted by a subclass. The `OccupationNormalizerInterface`
     * interface is excluded because `final` is meaningless for an interface — it
     * is the very extension point the concrete classes seal.
     */
    #[TestRule]
    public function normalizationConcreteClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Normalization'))
            ->excluding(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Normalization\\Contract'))
            ->should()->beFinal()
            ->because('Normalization seam classes must be final so their contract cannot be subverted by a subclass');
    }
}
