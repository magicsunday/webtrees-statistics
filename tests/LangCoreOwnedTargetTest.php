<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function bin2hex;
use function dirname;
use function getenv;
use function is_dir;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function sprintf;
use function str_contains;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;

/**
 * Pins the guards of `make lang-core-owned`. The list of core-owned texts is the
 * intersection of the core POT files over the releases named in `CORE_RELEASES`,
 * so a checkout that lacks any of them, or a release line that no longer matches
 * the webtrees constraint of `composer.json`, would silently write a list that
 * removes translations on the next `make lang`. Each guard stops the target
 * before it reaches the container, so the cases run without Docker.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class LangCoreOwnedTargetTest extends TestCase
{
    /**
     * A scratch directory of its own per test, so the repositories a case builds
     * never meet.
     */
    private string $scratch = '';

    /**
     * Create the scratch directory of the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir() . '/lang-core-owned-' . bin2hex(random_bytes(6));

        mkdir($this->scratch, 0o700, true);
    }

    /**
     * Remove the scratch directory of the test with everything the case built in it.
     */
    protected function tearDown(): void
    {
        $this->remove($this->scratch);

        parent::tearDown();
    }

    /**
     * The cases the target refuses. A case names the tags of the repository it
     * points `CORE` at (`null` passes no `CORE` at all), whether that directory is
     * a git repository, the extra make arguments and the part of the message that
     * names the guard that stopped it. A case that fails for another reason does
     * not match its message.
     *
     * @return array<string, array{list<string>|null, bool, list<string>, string}>
     */
    public static function refusedCaseProvider(): array
    {
        return [
            'no checkout given'                       => [null, false, [], 'pass CORE='],
            'a directory that is no git checkout'     => [[], false, [], 'CORE is not a git checkout'],
            'a checkout with only the last release'   => [['2.2.6'], true, [], 'lacks the tag 2.2.0'],
            'a checkout with only the first release'  => [['2.2.0'], true, [], 'lacks the tag 2.2.1'],
            'a release line that composer.json lacks' => [['2.3.0'], true, ['CORE_RELEASES=2.3.0'], 'does not match the webtrees constraint'],
        ];
    }

    /**
     * The target stops with an error and names the guard that refused the checkout.
     *
     * @param list<string>|null $tags      The tags of the repository `CORE` points at, or null for no `CORE`
     * @param bool              $git       Whether the directory is a git repository
     * @param list<string>      $arguments Extra make arguments for the case
     * @param string            $message   The part of the output that names the guard
     */
    #[Test]
    #[DataProvider('refusedCaseProvider')]
    public function theTargetRefusesAnUnsafeCheckout(?array $tags, bool $git, array $arguments, string $message): void
    {
        if ($tags !== null) {
            $core = $this->scratch . '/checkout';

            if ($git) {
                $this->initRepository($core, $tags);
            } else {
                mkdir($core, 0o700, true);
            }

            $arguments[] = 'CORE=' . $core;
        }

        [$exitCode, $output] = $this->runTarget($arguments);

        self::assertNotSame(0, $exitCode, 'The target must stop with an error');
        self::assertTrue(
            str_contains($output, $message),
            sprintf('The output does not name the guard "%s": %s', $message, $output),
        );
    }

    /**
     * Create a git repository with one commit and the given tags.
     *
     * @param string       $path The directory the repository is created in
     * @param list<string> $tags The tags to put on the commit
     */
    private function initRepository(string $path, array $tags): void
    {
        mkdir($path, 0o700, true);

        $git = ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false'];

        self::assertSame(0, $this->execute([...$git, 'init', '-q'], $path)[0]);
        self::assertSame(0, $this->execute([...$git, 'commit', '-q', '--allow-empty', '-m', 'Init'], $path)[0]);

        foreach ($tags as $tag) {
            self::assertSame(0, $this->execute([...$git, 'tag', $tag], $path)[0]);
        }
    }

    /**
     * Run the make target from the module root.
     *
     * @param list<string> $arguments The extra make arguments of the case
     *
     * @return array{int, string} The exit code and the combined output
     */
    private function runTarget(array $arguments): array
    {
        return $this->execute(['make', 'lang-core-owned', ...$arguments], dirname(__DIR__));
    }

    /**
     * Run a command without a shell and collect its exit code and output. Git is
     * kept from looking above the scratch directory, so a directory the case
     * means to be no checkout is not taken for one that lies higher up.
     *
     * @param list<string> $command   The program and its arguments
     * @param string       $directory The working directory of the command
     *
     * @return array{int, string} The exit code and the combined output
     */
    private function execute(array $command, string $directory): array
    {
        $environment = ['GIT_CEILING_DIRECTORIES' => $this->scratch, 'PATH' => (string) getenv('PATH'), 'HOME' => $this->scratch];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment);

        self::assertIsResource($process);

        $output = '';

        foreach ($pipes as $pipe) {
            $output .= (string) stream_get_contents($pipe);
        }

        return [proc_close($process), $output];
    }

    /**
     * Remove a directory tree.
     *
     * @param string $path The directory to remove with everything below it
     */
    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
