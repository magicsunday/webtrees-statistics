<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Architecture;

use FilesystemIterator;
use JsonSerializable;
use MagicSunday\Webtrees\Statistic\Test\Architecture\ArchitectureTest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function array_merge;
use function class_exists;
use function dirname;
use function interface_exists;
use function sort;
use function str_replace;
use function strlen;
use function substr;
use function trait_exists;

/**
 * Guards the two hand-maintained sub-namespace lists that drive the DTO
 * architecture rules.
 *
 * The rules select DTOs by listing their sub-namespaces explicitly. A list like
 * that drifts silently: a widget shipping new DTOs simply falls outside the
 * rules, and nothing fails. That is how `Heatmap`, `Pyramid` and `Ranking` —
 * all genuine wire DTOs — ended up unguarded by `dtoClassesAreFinal` and
 * `dtoClassesAreJsonSerializable`.
 *
 * Three things are pinned here, because closing only the first would leave the
 * same drift reachable by a different route:
 *
 * 1. Every sub-namespace that exists under `Model\` — at ANY depth — is claimed
 *    by exactly one of the two lists.
 * 2. Every class sitting at the root of `Model\` is named explicitly, so a wire
 *    shape parked there (where no sub-namespace exists to list) cannot escape.
 * 3. The classification is derived from the classes themselves rather than
 *    trusted: a sub-namespace holding a payload that serialises itself must be
 *    in the DTO list, one holding none must be in the domain list. Without this
 *    the domain list would be a self-serve exemption — a contributor facing the
 *    red test from (1) could green it by declaring a wire DTO to be a domain
 *    object.
 *
 * The marker for (3) is that a class serialises ITSELF: it implements
 * `JsonSerializable` or declares `jsonSerialize()`. The derivation deliberately
 * stops there. Any class with public properties can be handed to `json_encode()`,
 * so treating that as the marker would classify every domain value object as a
 * wire shape and leave the domain list with no legitimate member at all.
 * Separating those two cases needs to know whether the class actually reaches a
 * JSON sink, which no inspection of the class alone can decide. The structural
 * answer is the `Model\Dto\` / `Model\Domain\` split tracked in #286, where the
 * rule keys on the namespace rather than on a property of the class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class ModelNamespaceCoverageTest extends TestCase
{
    /**
     * The namespace the scanned directory maps onto under PSR-4.
     */
    private const string MODEL_NAMESPACE = 'MagicSunday\\Webtrees\\Statistic\\Model';

    /**
     * Classes living directly at the root of `Model\` rather than in a widget
     * sub-namespace. They are domain value objects and therefore outside the DTO
     * rules, exactly like the sub-namespaces in
     * {@see ArchitectureTest::DOMAIN_SUB_NAMESPACES}.
     *
     * Pinned here rather than in {@see ArchitectureTest} because no phpat rule
     * consumes the list — it exists solely so this guard can prove that the root
     * level holds nothing but the classes vouched for below.
     *
     * @var list<string>
     */
    private const array ROOT_LEVEL_CLASSES = [
        'FamilyRow',
    ];

    /**
     * Returns the absolute path of the scanned `src/Model` directory.
     */
    private function modelDirectory(): string
    {
        return dirname(__DIR__, 3) . '/src/Model';
    }

    /**
     * Returns every sub-namespace that exists below `Model\`, at any depth,
     * expressed relative to `Model\` with backslash separators (`Sankey`,
     * `Sankey\Nested`).
     *
     * @return list<string>
     */
    private function actualSubNamespaces(): array
    {
        $modelDirectory = $this->modelDirectory();

        self::assertDirectoryExists($modelDirectory);

        // SELF_FIRST, because the default LEAVES_ONLY mode yields files only and
        // would silently report "no sub-namespace exists at all".
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modelDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $names = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isDir()) {
                continue;
            }

            $names[] = str_replace(
                '/',
                '\\',
                substr($file->getPathname(), strlen($modelDirectory) + 1)
            );
        }

        sort($names);

        return $names;
    }

    /**
     * Returns the short names of the classes sitting directly at the root of
     * `Model\`.
     *
     * @return list<string>
     */
    private function actualRootLevelClasses(): array
    {
        return $this->phpBasenamesIn($this->modelDirectory());
    }

    /**
     * Returns whether the given sub-namespace holds at least one class that
     * serialises itself — the mechanical marker of a wire shape.
     *
     * A file that does not resolve to the expected class fails loudly rather
     * than being skipped: skipping would let the sub-namespace count as domain,
     * which is the exemption this whole test exists to deny.
     */
    private function holdsWireShape(string $subNamespace): bool
    {
        $directory = $this->modelDirectory() . '/' . str_replace('\\', '/', $subNamespace);

        // Loud rather than a silent `return false`: falling through would file the
        // sub-namespace as domain, which is the exemption this test exists to deny.
        self::assertDirectoryExists(
            $directory,
            $subNamespace . ' was scanned as a sub-namespace but has no directory.'
        );

        $holdsWireShape = false;

        // Sorted, and without an early return: every file has to be resolution-
        // checked, or the check would cover whichever file the filesystem
        // happened to hand over first and vary between checkouts.
        foreach ($this->phpBasenamesIn($directory) as $basename) {
            $className = self::MODEL_NAMESPACE . '\\' . $subNamespace . '\\' . $basename;

            self::assertTrue(
                $this->resolves($className),
                $directory . '/' . $basename . '.php does not resolve to ' . $className . '. A file '
                . 'whose type name differs from its basename is invisible to the classification '
                . 'below, so its sub-namespace would silently count as domain and escape the DTO '
                . 'rules.'
            );

            // An interface or trait carries no wire shape of its own.
            if (!class_exists($className)) {
                continue;
            }

            if ($this->isWireShape($className)) {
                $holdsWireShape = true;
            }
        }

        return $holdsWireShape;
    }

    /**
     * Returns the sorted basenames of the PHP files directly inside the given
     * directory, so every caller iterates in a filesystem-independent order.
     *
     * @return list<string>
     */
    private function phpBasenamesIn(string $directory): array
    {
        $basenames = [];

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $basenames[] = $file->getBasename('.php');
        }

        sort($basenames);

        return $basenames;
    }

    /**
     * Returns whether the given name resolves to any declared type, so a file
     * the classification cannot see can be told apart from one that simply
     * holds no wire shape.
     */
    private function resolves(string $className): bool
    {
        // Only the first call may autoload. A file declaring a type under a name
        // that differs from its basename would otherwise be included once per
        // probe, and the second include fatals on the redeclaration instead of
        // reaching the assertion that explains the mismatch.
        return class_exists($className)
            || interface_exists($className, false)
            || trait_exists($className, false);
    }

    /**
     * Returns whether the given class serialises itself.
     *
     * A declared `jsonSerialize()` counts even without the interface: such a
     * class is a wire shape whose author forgot the `implements` clause, and
     * treating it as a domain object would file it into the list that exempts
     * it from `dtoClassesAreJsonSerializable` — the very rule that would have
     * caught the omission.
     *
     * @param class-string $className Name of the class to inspect
     */
    private function isWireShape(string $className): bool
    {
        $reflection = new ReflectionClass($className);

        if ($reflection->implementsInterface(JsonSerializable::class)) {
            return true;
        }

        return $reflection->hasMethod('jsonSerialize');
    }

    /**
     * A new sub-namespace must be classified as either a wire DTO or a domain
     * value object. Leaving it out of both lists is what let three DTOs escape
     * the architecture rules, so it now fails here instead of passing silently.
     */
    #[Test]
    public function everyModelSubNamespaceIsClaimedByExactlyOneList(): void
    {
        $claimed = array_merge(
            ArchitectureTest::DTO_SUB_NAMESPACES,
            ArchitectureTest::DOMAIN_SUB_NAMESPACES
        );

        sort($claimed);

        self::assertSame(
            $this->actualSubNamespaces(),
            $claimed,
            'Every sub-namespace under src/Model must appear in exactly one of '
            . 'ArchitectureTest::DTO_SUB_NAMESPACES or ::DOMAIN_SUB_NAMESPACES. '
            . 'An unlisted one is silently exempt from the DTO architecture rules.'
        );
    }

    /**
     * A wire shape dropped at the root of `Model\` has no sub-namespace to be
     * listed under, so the list above cannot see it. This pins the root level
     * separately.
     */
    #[Test]
    public function everyRootLevelModelClassIsPinned(): void
    {
        $pinned = self::ROOT_LEVEL_CLASSES;

        sort($pinned);

        // Filesystem first, matching the sibling assertions, so a failure diff
        // reads the same way in all of them.
        self::assertSame(
            $this->actualRootLevelClasses(),
            $pinned,
            'A class directly under src/Model is covered by no DTO rule. Either '
            . 'move it into a wire-shape sub-namespace listed in '
            . 'ArchitectureTest::DTO_SUB_NAMESPACES, or add it to '
            . 'ROOT_LEVEL_CLASSES to vouch for it being a domain value object.'
        );
    }

    /**
     * The classification is derived from the classes, not taken on trust: a
     * sub-namespace holding a `JsonSerializable` payload belongs in the DTO
     * list, one holding none in the domain list. This is what stops the domain
     * list from becoming an exemption a contributor can grant themselves.
     */
    #[Test]
    public function eachSubNamespaceSitsInTheListItsClassesJustify(): void
    {
        $subNamespaces = $this->actualSubNamespaces();

        // Without this the whole check would pass on an empty scan — exactly
        // what a regressed directory walk produces, and it reads as green.
        self::assertNotSame(
            [],
            $subNamespaces,
            'No sub-namespace was scanned at all, so this guard would pass vacuously.'
        );

        foreach ($subNamespaces as $subNamespace) {
            if ($this->holdsWireShape($subNamespace)) {
                self::assertContains(
                    $subNamespace,
                    ArchitectureTest::DTO_SUB_NAMESPACES,
                    $subNamespace . ' holds a class that serialises itself, so it is a wire shape '
                    . 'and belongs in ArchitectureTest::DTO_SUB_NAMESPACES. Listing it as a '
                    . 'domain namespace exempts it from the DTO architecture rules.'
                );

                continue;
            }

            self::assertContains(
                $subNamespace,
                ArchitectureTest::DOMAIN_SUB_NAMESPACES,
                $subNamespace . ' holds no class that serialises itself, so it is not a wire '
                . 'shape. Either it belongs in ArchitectureTest::DOMAIN_SUB_NAMESPACES, or its '
                . 'payload forgot both the JsonSerializable interface and jsonSerialize().'
            );
        }
    }

    /**
     * The root level holds domain value objects only — a class serialising
     * itself there is a wire shape that escaped the DTO rules.
     */
    #[Test]
    public function rootLevelModelClassesAreNotWireShapes(): void
    {
        $wireShapes = [];

        foreach ($this->actualRootLevelClasses() as $shortName) {
            $className = self::MODEL_NAMESPACE . '\\' . $shortName;

            self::assertTrue(
                $this->resolves($className),
                $className . ' does not resolve — src/Model does not map onto ' . self::MODEL_NAMESPACE . ' as assumed.'
            );

            // An interface or trait carries no wire shape of its own.
            if (!class_exists($className)) {
                continue;
            }

            if ($this->isWireShape($className)) {
                $wireShapes[] = $className;
            }
        }

        // Collected rather than asserted per class, so the verdict is stated even
        // when the root is empty — a legitimate end state once every value object
        // has moved into a sub-namespace, and one that must not surface as "this
        // test performed no assertions".
        self::assertSame(
            [],
            $wireShapes,
            'These classes sit at the root of src/Model and serialise themselves, so they are '
            . 'wire shapes. Each must live in a sub-namespace listed in '
            . 'ArchitectureTest::DTO_SUB_NAMESPACES, where the DTO architecture rules can reach '
            . 'it.'
        );
    }
}
