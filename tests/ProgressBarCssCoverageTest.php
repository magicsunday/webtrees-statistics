<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_diff;
use function array_unique;
use function array_values;
use function assert;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function preg_match_all;
use function sort;
use function str_contains;
use function strpos;
use function substr;

/**
 * Locks the contract that every `progress-*` CSS class string used in a view
 * template is backed by a `.wt-statistics-chart .progress-<x>` rule in
 * `resources/css/statistics.css` that defines BOTH gradient custom properties
 * (`--bs-progress-label-gradient-start` and `-end`).
 *
 * The ProgressList partial blends the two custom properties with `color-mix(...
 * ${width}%)`. If only one or neither variable is set the blended colour is
 * `transparent` and the bar renders invisible — the visual regression has
 * shipped multiple times after a new progress-list card was added without its
 * CSS pair, including issues #18 / #20 / #38 in 2026-05.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class ProgressBarCssCoverageTest extends TestCase
{
    /**
     * Every `progress-*` class string referenced from any view template MUST
     * have a corresponding CSS rule that sets both gradient variables. Surfaces
     * missing classes by name so a future regression fails with a concrete
     * diff, not "the bars are invisible again".
     */
    #[Test]
    public function everyProgressClassReferencedInViewsHasItsCssGradientPair(): void
    {
        $repoRoot = dirname(__DIR__);
        $css      = file_get_contents($repoRoot . '/resources/css/statistics.css');

        self::assertNotFalse($css, 'statistics.css must be readable');

        $usedClasses    = $this->collectProgressClassesFromViews($repoRoot . '/resources/views');
        $definedClasses = $this->collectProgressClassesFromCss($css);

        $missingCss = array_values(array_diff($usedClasses, $definedClasses));
        sort($missingCss);

        self::assertSame(
            [],
            $missingCss,
            'Progress classes referenced from views but missing the gradient CSS rule '
            . '(both --bs-progress-label-gradient-start and --bs-progress-label-gradient-end must be set): '
            . implode(', ', $missingCss),
        );
    }

    /**
     * Walks every `.phtml` under `resources/views` and collects each `'class'
     * => 'progress-<name>'` / `"class" => "progress-<name>"` literal value.
     *
     * @param string $viewsRoot Absolute path to the views directory
     *
     * @return list<string>
     */
    private function collectProgressClassesFromViews(string $viewsRoot): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewsRoot));
        $classes  = [];

        foreach ($iterator as $entry) {
            assert($entry instanceof SplFileInfo);

            if ($entry->isFile() === false) {
                continue;
            }

            if ($entry->getExtension() !== 'phtml') {
                continue;
            }

            $contents = file_get_contents($entry->getPathname());

            if ($contents === false) {
                continue;
            }

            // Match: 'class' => 'progress-<x>' or "class" => "progress-<x>"
            // with whitespace tolerance around `=>`.
            preg_match_all(
                "/['\"]class['\"]\\s*=>\\s*['\"](progress-[A-Za-z0-9_-]+)['\"]/",
                $contents,
                $matches,
            );

            foreach ($matches[1] as $name) {
                $classes[] = $name;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Extract every `.wt-statistics-chart .progress-<name>` selector that whose
     * rule body assigns BOTH `--bs-progress-label-gradient-start` and
     * `--bs-progress-label-gradient-end`.
     *
     * @param string $css Full content of `resources/css/statistics.css`
     *
     * @return list<string>
     */
    private function collectProgressClassesFromCss(string $css): array
    {
        // Selectors may stack (`.progress-births-month, .progress-deaths-month {`),
        // so first split on `}` to isolate rule bodies, then inspect each.
        $classes = [];
        $parts   = explode('}', $css);

        foreach ($parts as $part) {
            $bracePos = strpos($part, '{');

            if ($bracePos === false) {
                continue;
            }

            $selectors = substr($part, 0, $bracePos);
            $body      = substr($part, $bracePos + 1);

            $hasStart = str_contains($body, '--bs-progress-label-gradient-start');
            $hasEnd   = str_contains($body, '--bs-progress-label-gradient-end');

            if ($hasStart === false) {
                continue;
            }

            if ($hasEnd === false) {
                continue;
            }

            preg_match_all(
                '/\.wt-statistics-chart\s+\.(progress-[A-Za-z0-9_-]+)\b/',
                $selectors,
                $matches,
            );

            foreach ($matches[1] as $name) {
                $classes[] = $name;
            }
        }

        return array_values(array_unique($classes));
    }
}
