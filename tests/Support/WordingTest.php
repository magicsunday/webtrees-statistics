<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support;

use Closure;
use MagicSunday\Webtrees\ModuleBase\Testing\EnglishLocaleFixture;
use MagicSunday\Webtrees\Statistic\Support\ContextText;
use MagicSunday\Webtrees\Statistic\Support\Wording;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests that every text the module words differently from the webtrees core takes the wording
 * of the module, and that a language without an entry keeps the wording of the core.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(Wording::class)]
#[UsesClass(ContextText::class)]
final class WordingTest extends TestCase
{
    /**
     * Returns every text of the class with the method that returns it as a closure.
     *
     * @return array<string, array{0: Closure(): string, 1: string}>
     */
    public static function textProvider(): array
    {
        return [
            'media' => [Wording::media(...), 'Media'],
            'other' => [Wording::other(...), 'Other'],
        ];
    }

    /**
     * The entry with the context of the module wins over the translation of the core.
     *
     * @param Closure(): string $wording Returns the wording under test
     * @param string            $source  The English source text of the wording
     */
    #[Test]
    #[DataProvider('textProvider')]
    public function takesTheWordingOfTheModule(Closure $wording, string $source): void
    {
        EnglishLocaleFixture::install([
            "webtrees-statistics\x04" . $source => 'module wording',
            $source                             => 'core wording',
        ]);

        self::assertSame('module wording', $wording());
    }

    /**
     * A language without an entry for the context shows the wording of the core and not the
     * English source text.
     *
     * @param Closure(): string $wording Returns the wording under test
     * @param string            $source  The English source text of the wording
     */
    #[Test]
    #[DataProvider('textProvider')]
    public function fallsBackToTheWordingOfTheCore(Closure $wording, string $source): void
    {
        EnglishLocaleFixture::install([$source => 'core wording']);

        self::assertSame('core wording', $wording());
    }

    /**
     * Without any translation the source text stays.
     *
     * @param Closure(): string $wording Returns the wording under test
     * @param string            $source  The English source text of the wording
     */
    #[Test]
    #[DataProvider('textProvider')]
    public function keepsTheSourceTextWithoutAnyTranslation(Closure $wording, string $source): void
    {
        EnglishLocaleFixture::install();

        self::assertSame($source, $wording());
    }
}
