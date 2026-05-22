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
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules executed by phpat through PHPStan. Each
 * `#[TestRule]` method returns one rule that pins a structural
 * invariant of the module so the codebase cannot silently drift
 * past the layering the rest of the production code relies on.
 *
 * Pyramid in this module (top = depends on layers below):
 *
 *   - Module        (composition root; wires Statistic → Repositories)
 *   - Statistic     (facade exposing widget-shaped getters to views)
 *   - Repository    (DB queries + GEDCOM scans, one per metric domain)
 *   - Model\Dto     (immutable wire-shape value objects returned by repositories)
 *   - Support       (pure helpers: bucketing, GedcomScanner, ParentMap…)
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ArchitectureTest
{
    private const string NAMESPACE_ROOT = 'MagicSunday\\Webtrees\\Statistic';

    /**
     * Every helper in `Support\` must be `final` so its contract
     * (`private __construct`, static-only API) cannot be subverted
     * by a subclass. `readonly` is not required: a single helper
     * caches a static lookup table to amortise its setup cost
     * across the tree, and `readonly` would forbid that one
     * mutation site.
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
     * Support helpers are a leaf layer: they may depend on PHP
     * stdlib, the webtrees framework, and on each other, but never
     * on a Repository or on the Statistic facade. The reverse flow
     * — repositories importing helpers — is the architecture this
     * module wants; the inverse would create a cycle that turns
     * helpers into framework-coupled adapters.
     */
    #[TestRule]
    public function supportDoesNotDependOnRepositoryOrFacade(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Support'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository'),
                Selector::classname(self::NAMESPACE_ROOT . '\\Statistic'),
            )
            ->because('Support is a leaf layer; repositories and the facade live above it');
    }

    /**
     * Repositories must be `final` so the contract that the
     * `Statistic` facade composes (immutable Tree dependency,
     * single per-domain query surface) cannot be subverted by a
     * subclass. `readonly` is the standard shape across the
     * module, but three repositories cache their lazy aggregation
     * result on first call and therefore stay non-readonly by
     * necessity — `final` alone is the strongest invariant we can
     * enforce for the whole layer.
     */
    #[TestRule]
    public function repositoryClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository'))
            ->should()->beFinal()
            ->because('Repositories must be final so the Tree-DI contract cannot be subverted by a subclass');
    }

    /**
     * Repositories must not depend on the Statistic facade. The
     * direction is fixed: Statistic composes repositories, never
     * the reverse. A repository pulling the facade in would short
     * the composition graph and make individual repositories
     * impossible to test in isolation.
     */
    #[TestRule]
    public function repositoryDoesNotDependOnFacade(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Statistic'))
            ->because('Statistic composes repositories; the inverse direction would create a cycle');
    }

    /**
     * Database access via Eloquent's `DB::table()` facade is the
     * exclusive responsibility of repositories and of the dedicated
     * `Support\TreeScope` query-builder helper that factors the
     * recurring `DB::table(X)->where('X_file', …)` boilerplate out
     * of every repository call site. Letting the Statistic facade
     * or the composition root issue SQL directly would scatter
     * query-shape decisions across every layer and make it
     * impossible to reason about which class actually touches which
     * table.
     */
    #[TestRule]
    public function databaseAccessIsConfinedToRepositories(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository')),
                    Selector::Not(Selector::classname(self::NAMESPACE_ROOT . '\\Support\\TreeScope')),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Test')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(Manager::class))
            ->because('Raw database access is only allowed inside repositories or in the dedicated Support\\TreeScope query-builder helper');
    }

    /**
     * The composition root is `Module.php`. Nothing inside the
     * production namespace tree may import it — services start
     * reaching back into the wiring layer and the dependency
     * graph develops a cycle that is invisible to PHP itself but
     * lethal for testability. Tests are exempt because integration
     * tests have to instantiate the composition root.
     */
    #[TestRule]
    public function nothingDependsOnTheCompositionRoot(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT),
                    Selector::Not(Selector::classname(self::NAMESPACE_ROOT . '\\Module')),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Test')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Module'))
            ->because('Module.php is the composition root and may only be referenced from module.php');
    }

    /**
     * Every DTO must be `final`. A subclass could add mutable state
     * or override `jsonSerialize` and silently drift the wire
     * shape — the whole point of moving repository return types
     * from `array{…}` PHPDoc to typed DTOs is that the wire shape
     * stays pinned at a single class per payload.
     */
    #[TestRule]
    public function dtoClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\Dto'))
            ->should()->beFinal()
            ->because('DTOs must be final so the wire shape can never be subverted by a subclass');
    }

    /**
     * Every DTO must implement `JsonSerializable`. The whole
     * Model\Dto layer exists to be serialised to JSON for the
     * chart-lib widgets via `json_encode`; a DTO without
     * `jsonSerialize` would silently fall back to PHP's default
     * object-serialisation (mangled property names) and break the
     * widget contract on the wire.
     */
    #[TestRule]
    public function dtoClassesAreJsonSerializable(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\Dto'))
            ->should()->implement()
            ->classes(Selector::classname(JsonSerializable::class))
            ->because('DTOs ship to the wire via json_encode; without JsonSerializable the JSON shape would drift away from PHPDoc');
    }

    /**
     * DTOs are pure value objects: they may not depend on a
     * repository, the facade, the composition root, or even a
     * Support helper. The dependency arrow points the other way —
     * repositories construct DTOs from query results, the facade
     * surfaces them, and the view layer consumes them. A DTO that
     * pulled in a repository would turn into a service in
     * disguise and break the layering this module relies on.
     */
    #[TestRule]
    public function dtoDoesNotDependOnAnyOtherProductionLayer(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\Dto'))
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Support'),
                Selector::classname(self::NAMESPACE_ROOT . '\\Statistic'),
                Selector::classname(self::NAMESPACE_ROOT . '\\Module'),
            )
            ->because('DTOs are leaf value objects; only repositories/facade construct them, never the reverse');
    }

    /**
     * The `Model` namespace (sibling of `Model\Dto`) holds domain
     * enums and value objects that classify webtrees data without
     * carrying behaviour. The same layering invariant applies:
     * enums must not reach into repositories, the facade, the
     * composition root, or Support helpers — they ARE the
     * vocabulary repositories speak, not the other way around.
     */
    #[TestRule]
    public function modelDoesNotDependOnAnyOtherProductionLayer(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\Dto')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Repository'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\\Support'),
                Selector::classname(self::NAMESPACE_ROOT . '\\Statistic'),
                Selector::classname(self::NAMESPACE_ROOT . '\\Module'),
            )
            ->because('Model enums and value objects are the vocabulary the rest of the module speaks; they cannot depend back into repositories, the facade, the composition root, or Support helpers');
    }
}
