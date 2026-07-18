<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Architecture;

use MagicSunday\Webtrees\Statistic\Test\Architecture\ArchitectureTest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
 * This test makes the omission impossible: every sub-namespace under `Model\`
 * has to be claimed by exactly one of the two lists.
 */
#[CoversNothing]
final class ModelNamespaceCoverageTest extends TestCase
{
    /**
     * Returns the sub-namespace directories that exist under `src/Model`.
     *
     * @return list<string>
     */
    private function actualSubNamespaces(): array
    {
        $modelDirectory = dirname(__DIR__, 3) . '/src/Model';

        $directories = glob($modelDirectory . '/*', GLOB_ONLYDIR);

        self::assertIsArray($directories, 'Could not read ' . $modelDirectory);

        $names = array_map(
            basename(...),
            $directories
        );

        sort($names);

        return $names;
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
     * The two lists must stay disjoint: a sub-namespace claimed by both would
     * be subjected to contradictory rules.
     */
    #[Test]
    public function theTwoListsAreDisjoint(): void
    {
        self::assertSame(
            [],
            array_values(
                array_intersect(
                    ArchitectureTest::DTO_SUB_NAMESPACES,
                    ArchitectureTest::DOMAIN_SUB_NAMESPACES
                )
            )
        );
    }
}
