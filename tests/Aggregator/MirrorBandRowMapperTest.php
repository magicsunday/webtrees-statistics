<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Aggregator;

use Closure;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Aggregator\MirrorBandRowMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the fold from an age-band map into the rows of one side of a mirrored
 * bar chart: the band is the label, the count the value, and the tooltip names
 * the role the side stands for.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 *
 * @phpstan-type BandFold Closure(array<string, int>): list<array{label: string, value: int, tooltipLabel: string, tooltipBody: string}>
 */
final class MirrorBandRowMapperTest extends TestCase
{
    /**
     * The tooltips are translated, so the translator needs a language.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);
    }

    /**
     * Every side pluralises its own role noun, in the singular for one person
     * and in the plural with the thousands separator otherwise.
     *
     * @return array<string, array{BandFold, string, string}>
     */
    public static function roleProvider(): array
    {
        return [
            'husbands' => [MirrorBandRowMapper::husbands(...), '1 husband', '1,234 husbands'],
            'wives'    => [MirrorBandRowMapper::wives(...), '1 wife', '1,234 wives'],
            'fathers'  => [MirrorBandRowMapper::fathers(...), '1 father', '1,234 fathers'],
            'mothers'  => [MirrorBandRowMapper::mothers(...), '1 mother', '1,234 mothers'],
            'men'      => [MirrorBandRowMapper::men(...), '1 man', '1,234 men'],
            'women'    => [MirrorBandRowMapper::women(...), '1 woman', '1,234 women'],
        ];
    }

    /**
     * Every band becomes one row in input order, carrying the band as label, the
     * count as value, the band with its unit as tooltip label and the pluralised
     * role as tooltip body.
     *
     * @param BandFold $fold
     */
    #[Test]
    #[DataProvider('roleProvider')]
    public function bandsBecomeOneRowEachWithThePluralisedRole(Closure $fold, string $singular, string $plural): void
    {
        $rows = $fold(['20-24' => 1, '25-29' => 1234]);

        self::assertSame(
            [
                [
                    'label'        => '20-24',
                    'value'        => 1,
                    'tooltipLabel' => '20-24 years',
                    'tooltipBody'  => $singular,
                ],
                [
                    'label'        => '25-29',
                    'value'        => 1234,
                    'tooltipLabel' => '25-29 years',
                    'tooltipBody'  => $plural,
                ],
            ],
            $rows
        );
    }

    /**
     * The fold functions of the mapper, one per case.
     *
     * @return array<string, array{BandFold}>
     */
    public static function methodProvider(): array
    {
        return [
            'husbands' => [MirrorBandRowMapper::husbands(...)],
            'wives'    => [MirrorBandRowMapper::wives(...)],
            'fathers'  => [MirrorBandRowMapper::fathers(...)],
            'mothers'  => [MirrorBandRowMapper::mothers(...)],
            'men'      => [MirrorBandRowMapper::men(...)],
            'women'    => [MirrorBandRowMapper::women(...)],
        ];
    }

    /**
     * An empty band map yields no rows.
     *
     * @param BandFold $fold
     */
    #[Test]
    #[DataProvider('methodProvider')]
    public function emptyBandMapYieldsNoRows(Closure $fold): void
    {
        self::assertSame([], $fold([]));
    }
}
