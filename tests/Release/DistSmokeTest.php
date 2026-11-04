<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Release;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Tests the dist-smoke target of Make/release.mk against small archives built for each case.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class DistSmokeTest extends TestCase
{
    private string $workDir = '';

    protected function setUp(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'dist-smoke-');
        self::assertIsString($base);

        unlink($base);

        $this->workDir = $base;

        mkdir($this->workDir . '/module', 0o777, true);
        mkdir($this->workDir . '/stage', 0o777, true);

        $root = dirname(__DIR__, 2);

        $this->execute(['cp', '-R', $root . '/Makefile', $root . '/Make', $this->workDir . '/module/'], $this->workDir);
    }

    protected function tearDown(): void
    {
        $this->execute(['rm', '-rf', $this->workDir], sys_get_temp_dir());
    }

    /**
     * A well-formed archive without developer files passes, so the failing cases below are caused by their entry.
     */
    #[Test]
    public function acceptsAnArchiveWithoutDeveloperFiles(): void
    {
        $this->buildArchive();

        [$status, $output] = $this->smoke();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('dist-smoke passed', $output);
    }

    /**
     * Each developer file in the archive fails the smoke test with its own message.
     */
    #[Test]
    #[DataProvider('developerFiles')]
    public function rejectsAnArchiveWithADeveloperFile(string $entry, string $message): void
    {
        $this->buildArchive($entry);

        [$status, $output] = $this->smoke();

        self::assertNotSame(0, $status);
        self::assertStringContainsString($message, $output);
        self::assertStringNotContainsString('dist-smoke passed', $output);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function developerFiles(): array
    {
        return [
            'git hook'          => ['.githooks/pre-commit', 'Error: .githooks/ found in zip'],
            'deptrac ruleset'   => ['deptrac.yaml', 'Error: deptrac.yaml found in zip'],
            'typescript config' => ['tsconfig.json', 'Error: tsconfig.json found in zip'],
        ];
    }

    /**
     * Builds an archive that satisfies every other check of the smoke test, plus the given extra entry.
     */
    private function buildArchive(?string $extraEntry = null): void
    {
        $scopedNamespace = 'MagicSunday\\\\StatisticsVendor\\\\Webtrees\\\\ModuleBase';

        $files = [
            'module.php'                                                      => "<?php\n\n// " . $scopedNamespace . "\n",
            'LICENSE'                                                         => "license\n",
            'resources/js/statistics-1.min.js'                                => "bundle\n",
            'vendor/magicsunday/webtrees-module-base/src/Contract/Entry.php'  => "<?php\n",
            'vendor/magicsunday/webtrees-module-base/src/Model/Entry.php'     => "<?php\n",
            'vendor/magicsunday/webtrees-module-base/src/Module/Entry.php'    => "<?php\n",
            'vendor/magicsunday/webtrees-module-base/src/Processor/Entry.php' => "<?php\n",
        ];

        if ($extraEntry !== null) {
            $files[$extraEntry] = "x\n";
        }

        foreach ($files as $path => $content) {
            $directory = dirname($this->workDir . '/stage/' . $path);

            if (!is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }

            file_put_contents($this->workDir . '/stage/' . $path, $content);
        }

        [$status, $output] = $this->execute(
            ['zip', '-qr', $this->workDir . '/module/webtrees-statistics.zip', '.'],
            $this->workDir . '/stage'
        );

        self::assertSame(0, $status, $output);
    }

    /**
     * @return array{int, string}
     */
    private function smoke(): array
    {
        return $this->execute(['make', 'dist-smoke'], $this->workDir . '/module');
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private function execute(array $command, string $workingDirectory): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin']
        );

        self::assertIsResource($process);

        $standardOutput = $pipes[1] ?? null;
        $standardError  = $pipes[2] ?? null;

        self::assertIsResource($standardOutput);
        self::assertIsResource($standardError);

        $output = stream_get_contents($standardOutput) . stream_get_contents($standardError);

        return [proc_close($process), $output];
    }
}
