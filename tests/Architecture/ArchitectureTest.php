<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Architecture;

use JsonSerializable;
use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Architecture rules executed by phpat through PHPStan. Each `#[TestRule]`
 * method returns one rule that pins a structural invariant of the module.
 *
 * Every layer-DEPENDENCY rule (Support/Model/Enum/DTO are leaves, nothing
 * depends on the composition root, the normalization seam never depends back on a
 * repository, raw database access is confined to the repositories and
 * `Support\Database`, …) lives in Deptrac: `deptrac.yaml` imports
 * `magicsunday/coding-standard`'s canonical layers and adds this module's own
 * layers and the `NoDatabaseAccess` overlay. What remains here are the checks
 * Deptrac's layer model cannot express, because they are properties of a class
 * rather than dependencies: the structural "must be final" / "must implement"
 * invariants.
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
     * them. Add an entry here whenever a new widget shape ships its own DTOs —
     * {@see \MagicSunday\Webtrees\Statistic\Test\Unit\Architecture\ModelNamespaceCoverageTest}
     * fails if one is forgotten.
     *
     * The DTO rules select these sub-namespaces through one literal
     * `Selector::inNamespace()` pattern each instead of building selectors from
     * this list: the phpat subject guard (`check-phpat-subjects.php`) reads rule
     * subjects statically and fails closed on a spread argument or on any constant
     * but `NAMESPACE_ROOT`. `ModelNamespaceCoverageTest` proves each rule's pattern
     * selects exactly the sub-namespaces listed here, so the two spellings cannot
     * drift.
     *
     * @var list<string>
     */
    public const array DTO_SUB_NAMESPACES = [
        'Chord',
        'Heatmap',
        'LineChart',
        'Metric',
        'Pyramid',
        'Ranking',
        'Record',
        'Sankey',
        'StackedBar',
        'StreamGraph',
        'Tree',
    ];

    /**
     * Sub-namespaces under `Model\` holding domain value objects rather than
     * wire shapes. They are deliberately outside the DTO rules — they carry no
     * `jsonSerialize()` because nothing serialises them directly. An
     * intermediate namespace that holds no class of its own belongs here too,
     * for the same reason: it contributes no wire shape.
     *
     * Listed for the same reason as the DTOs: so
     * {@see \MagicSunday\Webtrees\Statistic\Test\Unit\Architecture\ModelNamespaceCoverageTest}
     * can prove the two lists together cover every sub-namespace that exists,
     * and that each one sits in the list its classes actually justify. Without
     * that proof a new sub-namespace silently falls outside both rule sets,
     * which is how three wire DTOs came to be unguarded.
     *
     * @var list<string>
     */
    public const array DOMAIN_SUB_NAMESPACES = [
        'Family',
        'Marriage',
        'Mortality',
    ];

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
     * Every class in `Aggregator\` must be `final`, for the same reason as the
     * Support helpers it was split off from (GH-319): the row mappers, tallies and
     * resolvers pin a widget's payload shape and a static-only or immutable
     * contract, which a subclass could otherwise subvert.
     */
    #[TestRule]
    public function aggregatorClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Aggregator'))
            ->should()->beFinal()
            ->because('Aggregator classes must be final so the payload shape they pin cannot be subverted by a subclass');
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
     * Every DTO must be `final`. A subclass could add mutable state or override
     * `jsonSerialize` and silently drift the wire shape — the whole point of
     * moving repository return types from `array{…}` PHPDoc to typed DTOs is
     * that the wire shape stays pinned at a single class per payload.
     */
    #[TestRule]
    public function dtoClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace(
                    '/^MagicSunday\\\\Webtrees\\\\Statistic\\\\Model\\\\'
                    . '(Chord|Heatmap|LineChart|Metric|Pyramid|Ranking|Record|Sankey|StackedBar|StreamGraph|Tree)'
                    . '(\\\\|$)/',
                    true,
                ),
            )
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
            ->classes(
                Selector::inNamespace(
                    '/^MagicSunday\\\\Webtrees\\\\Statistic\\\\Model\\\\'
                    . '(Chord|Heatmap|LineChart|Metric|Pyramid|Ranking|Record|Sankey|StackedBar|StreamGraph|Tree)'
                    . '(\\\\|$)/',
                    true,
                ),
            )
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
